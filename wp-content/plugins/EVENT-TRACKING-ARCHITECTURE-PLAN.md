# Event-tracking architecture: `BH_Event` and `BH_Identity`

A self-hosted, GA/Segment-equivalent event-tracking layer for the ecosystem, designed to ride entirely on infrastructure the ecosystem already owns (`OUS_Jobs`) rather than any new mechanism or third-party service — consistent with VISION.md's "no quiet dependency on a paid third-party service where an owned equivalent will do" standing rule.

## 1. Why this exists

Every plugin currently tracks its own narrow slice of activity as aggregate counters (`bhs_daily_stats`'s play/skip tally, contest vote tallies) — never as a raw, per-event, timestamped, identity-joinable record. There was no way to answer "what did this specific person do, across every plugin, before and after they signed up" anywhere in the ecosystem. `BH_Event`/`BH_Identity` is the first per-event table this ecosystem has ever had.

## 2. `BH_Event` — the envelope schema

Table: `bhcore_events`. Mirrors `BH_Content`'s existing "namespaced type string + schema-registered attribute map" shape, adding the one thing `BH_Content` itself is missing: a version field.

Fields:
- `id` — bigint unsigned, primary key, auto-increment.
- `type` — varchar(100). Namespaced `plugin-prefix/name` string (`bhs/play`, `bh/vote`, `bhc/course_completed`, `bhcrm/interaction`) — same namespacing convention `BH_Content` block types and `OUS_Jobs` hook names already use, chosen deliberately for one naming discipline across all three extension points.
- `v` — smallint unsigned, default 1. The schema version registered for that `type`. Lets a type's shape evolve (e.g. `bhs/play` v1 → v2 adding a `quality` field) without corrupting the meaning of already-stored v1 rows — a reader always keys on `(type, v)` together.
- `user_id` — bigint unsigned, default 0. 0 = not yet known; backfilled via `BH_Identity` once resolved.
- `client_id` — varchar(64), default ''. The anonymous, stable, first-party-cookie-issued identifier present on every event, known or anonymous — the actual join key that survives the pre-signup → post-signup transition (`user_id` is derived from it, not the reverse).
- `subject_type` — varchar(60). What the event is about, loosely typed at the DB layer on purpose (not a foreign key), same pattern as `bhi_reports.target_type`. Values like `bhs_track`, `bh_submission`, `bhc_lesson`, `bhc_course`.
- `subject_id` — bigint unsigned, default 0. Paired with `subject_type`.
- `payload` — longtext, JSON. Type-specific attributes, validated/coerced against the schema registered for `(type, v)` at ingest time.
- `context` — text, JSON. Request context captured once, centrally, by the ingest job itself: `url`, `referrer_bucket`, `country_guess`, `request_method` — reuses `BHS_Stats`' existing referrer-classification/Accept-Language country-guess logic rather than inventing new capture logic or reaching for GeoIP.
- `occurred_at` — datetime. When the thing actually happened (client-supplied for beacon events, server time for PHP-side emits).
- `created_at` — datetime, default CURRENT_TIMESTAMP. When the row was actually written (after the queue processed it) — kept separate from `occurred_at` so queue latency never corrupts "when did this happen" data.
- `dedup_key` — varchar(191), default ''. For events that must only ever be recorded once per some natural key (e.g. course completion: exactly one `bhc/course_completed` row per user+course, no matter how many times a progress recheck lands on 100% again). The caller supplies a deterministic string (`"course_completed:{user_id}:{course_id}"`); a UNIQUE index on this column makes the database itself enforce "only once" — same trick `bhc_completions` already uses today. Non-deduplicated events (plays, votes) either use NULL (MySQL treats multiple NULLs as non-colliding under a UNIQUE key) or a unique per-row token — an implementation-time choice, not forced prematurely.

Indexes: `KEY user (user_id)`, `KEY client (client_id)`, `KEY type_time (type, occurred_at)`, `KEY subject (subject_type, subject_id)`, `UNIQUE KEY dedup (dedup_key)`.

**Registration:** `BH_Event::register_event_type($type, $v, $schema)`, called by each plugin on its own `init` — a static method call, matching `BH_Content::register_block_type()`'s existing schema-registry precedent, deliberately chosen over the more common `add_filter()` list-registry pattern (`ous_debug_tools`, `bhi_portal_panels`) since those are for aggregating a list something iterates over, while a schema registry is a different kind of thing.

**Versioning gap noted for later:** `BH_Content` itself has no version field today — a stored block tree from an old schema is indistinguishable from a new one at read time. Worth retrofitting `(type, v)` onto `BH_Content` later so both of the ecosystem's shared-shape systems version identically — not required to ship this.

## 3. `BH_Identity` — anonymous-to-known stitching

Resolves an anonymous `client_id` to a `user_id` once someone logs in or registers, via a job hooked to `wp_login`/`user_register`, so pre-signup activity isn't lost the moment someone becomes an account. Backfills `user_id` on already-stored `bhcore_events` rows matching that `client_id`.

## 4. Ingestion path

Everything rides `OUS_Jobs` — no parallel queue mechanism. A play/vote/beacon call must never block the visitor's request on a synchronous DB write. Client-side emission goes through `window.BHEvents.emit(...)`, a `sendBeacon`-based entry point, so the browser can fire-and-forget even as the page unloads.

## 5. Call-site audit — what changed and why

Read `bh-contest`, `bh-streaming`, `bh-crm`, and the existing `OUS_Jobs` handlers directly rather than describing this generically.

**Moved onto the queue:**
1. `bh-contest/includes/class-admin.php:381` — a `wp_mail()` call inside a `foreach ($placements as $uid => $wins)` loop publishing contest results — one synchronous email per placing entrant, inside a single admin request. Worst finding in the audit: a real page-timeout risk on shared hosting with many placements. Fixed by routing each send through `OUS_Notifications::notify($uid, 'contest_result', ...)`, which already queues email via `OUS_Jobs` and also gets entrants an in-app notification for free.
2. `bh-contest/includes/class-api.php:277` — a synchronous `wp_mail()` submission-received confirmation, inside the `submit()` REST request itself (during a file upload). Lower volume than #1 but still sitting in a user's critical request path. Same fix: routed through `OUS_Notifications::notify()`.
3. `bh-streaming/includes/class-api.php:281`, calling `BHS_Stats::record_play()` → `class-stats.php`'s `bump()` — a synchronous `INSERT ... ON DUPLICATE KEY UPDATE` against `bhs_daily_stats` on every play event, inline in the play request. Fixed: `record_play()` now calls `BH_Event::emit('bhs/play', ...)` (an enqueue, not a write); the daily-rollup write moves to run as a queue consumer, off the live request. The artist-facing dashboard doesn't change; only where the write happens changes.

**Explicitly left alone, with reasoning:**
4. Contest vote insert, `bh-contest/includes/class-api.php:182` — sits inside a `START TRANSACTION ... FOR UPDATE`, enforcing a per-category vote limit and returning `votes_left` synchronously. Must NOT move to the queue — transactional, result needed immediately by the caller. Instead, `BH_Event::emit('bh/vote', ...)` fires as an additional, fire-and-forget call after the transaction commits, purely for the activity-stream/CRM side, without touching the vote-tallying path.
5. `own-ur-shit/includes/class-auth.php:144` — the email-verification link sent during registration. Deliberate exception: the user is actively waiting on this email, and queuing it introduces up to a full WP-Cron tick of delay at exactly the moment that delay is most costly to conversion. Left synchronous on purpose.

**Idempotency/retry review of existing jobs** (checked whether current handlers are safe under WP-Cron's traffic-dependent timing):
- `bhr_recheck_one_link` (bh-registry) — confirmed idempotent; re-verifying a link has no accumulating side effect on retry.
- `bhs_sync_one_feed` (bh-streaming) — confirmed idempotent; the importer dedups by feed-item GUID before inserting.
- `bhcore_send_notification_email` (core, existing) — flagged as NOT idempotent: if the send succeeds but the status update to "done" is lost (e.g. an overlapping cron tick), a retry would resend the same email. Called out as low-stakes (a duplicate email, not data corruption), but worth knowing since this pass routes more mail through this exact path.
- The new `bhcore_ingest_event` handler is idempotent by construction via `dedup_key` for once-only event types, and tolerates rare duplicate rows for append-only types (plays, votes) the same way `record_play()`'s existing docblock already tolerates refresh-spam duplicates.

**Negative finding:** grepped all plugins plus core for stray `error_log()` calls that should route through `OUS_DebugLog` instead, per standing convention — found zero. Logging discipline across the ecosystem was already clean.

## 6. Real consumers as of this pass

- **bh-streaming** — `bhs/play`, `bhs/skip`, replacing the synchronous per-play DB write with a queued one.
- **bh-contest** — `bh/vote`, fired after (never inside) the vote's own transactional tally logic.
- **bh-courses** — `bhc/enroll`, `bhc/step_completed`, `bhc/course_completed`, off hooks added to `class-progress.php`.
- **bh-crm** — reads `bhcore_events` into its existing `bh_crm_activity_summary` filter contract, so a contact's Activity section now includes pre-signup history.

## 7. Self-hosted posture check

Confirmed against VISION.md's standing review criteria: zero new third-party dependencies, no assumed Redis/Memcached/external queue (rides `OUS_Jobs`'s existing WP-Cron model), all data stored in the ecosystem's own MySQL database. Consistent with the "self-hosted, no vendor lock-in, no quiet paid-third-party dependency" framing applied to every design decision in this ecosystem.
