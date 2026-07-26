# Code-Quality Audit — Task 2/16: Core Cross-Cutting Infrastructure

**Scope:** `own-ur-shit/includes/class-jobs.php` (`OUS_Jobs`), `class-notifications.php` (`OUS_Notifications`), `class-debug-log.php` (`OUS_DebugLog`)
**Date:** 2026-07-25 | **Model:** Claude Sonnet 5 | **Audit type:** code quality (DRY/SOLID/naming/comments/dead code/fragile patterns) — not UX
**Caveat:** No live PHP/MySQL execution environment available in this session. Every finding below is static analysis: read against the actual file/line, not inferred from names or greps. No runtime behavior was verified by execution.

---

## HIGH

### H1. `OUS_Notifications` table has no bounded-growth story at all
**File:** `class-notifications.php`, `table()` (58-61) + `notify()` (71-91), whole file
**Finding:** `bhcore_notifications` rows are inserted on every `notify()` call and never pruned — no `maybe_trim()`-equivalent, no cron cleanup, no admin "clear old" action (unlike `OUS_Jobs::reset_debug()` and `OUS_DebugLog`'s `MAX_ROWS`/`maybe_trim()`). VISION.md flags this exact class of gap ("every growing table needs a bounded-growth story from day one") and `OUS_DebugLog::maybe_trim()` is the documented reference pattern (self-capping at MAX_ROWS on ~1/50 writes, no separate cron). This table has neither the opportunistic trim nor a manual reset path.
**Failure scenario:** Any actively-used install (course completions, contest events, CRM reminders — all routed through `notify()`) accumulates rows unbounded, forever. `for_user()` and `admin_bar()` limit their own SELECTs (`LIMIT 20/8`), so nothing breaks visibly, but the table grows without limit and `distinct_types_for_user()` (127-132) does a full `DISTINCT type` scan over the user's entire row history on every notifications-panel render — this gets slower, not faster, the longer a site runs, with no compensating cleanup ever occurring.
**Fix:** Add `OUS_Notifications::maybe_trim()` mirroring `OUS_DebugLog`'s pattern (a `MAX_ROWS`-per-install or `MAX_ROWS`-per-user cap, checked opportunistically inside `notify()`), or at minimum a scheduled prune of read notifications older than N days.

### H2. `OUS_Jobs` fallback table also has no bounded-growth story — only a manual admin button
**File:** `class-jobs.php`, `table()` (327-330), `reset_debug()` (306-311)
**Finding:** `done`/`failed` rows only get deleted when an admin manually clicks "Reset" on the Debug Tools page (`reset_debug()`). There is no automatic equivalent of `OUS_DebugLog::maybe_trim()`. `run_due_jobs()` (386-396) runs every minute via cron forever, and on a fallback-queue install (Action Scheduler not installed — the default state until someone clicks the one-click installer) every completed/failed job is a permanent row.
**Failure scenario:** A site that never installs Action Scheduler (the comment at 274-279 frames it as opt-in, not automatic) and generates jobs at any real volume (e.g. `bhr_recheck_one_link` per-link background checks) accumulates `done` rows indefinitely until an admin remembers to visit Debug Tools and click Reset — nothing prompts them to. Same VISION.md gap as H1, on the other reference-implementation class that's supposed to model this pattern correctly.
**Fix:** Auto-prune `done` rows older than N days (a `%1` chance of trim on `run_due_jobs()` calls, same style as `OUS_DebugLog::maybe_trim()`), leaving `failed` around longer for triage but still capped.

---

## MEDIUM

### M1. `OUS_Notifications::notify()` never checks `$wpdb->insert()`'s return value
**File:** `class-notifications.php`, lines 73-90
**Finding:** `$wpdb->insert(self::table(), [...])` is called with no failure check, unlike `OUS_DebugLog::log()` (167-184), which explicitly checks `$ok === false && $wpdb->last_error` and persists the failure to `ous_debug_log_last_failure` specifically so a broken logging pipeline is still diagnosable. `OUS_Notifications::notify()` sets `$id = (int) $wpdb->insert_id` unconditionally — on a failed insert this is `0`, and execution proceeds into `if ($email && self::user_wants_email(...))` and enqueues `OUS_Jobs::enqueue('bhcore_send_notification_email', ['notification_id' => 0, ...])` regardless.
**Failure scenario:** Schema drift on `bhcore_notifications` (a missing column after an unapplied migration, the same class of issue `OUS_DebugLog::health_check()` exists specifically to catch for its own table) silently drops every notification insert. Nothing surfaces this — no admin-visible signal, no DebugLog entry — even though `OUS_DebugLog` is `class_exists()`-available right there in the same request. The queued email still fires with `notification_id => 0`, which then bypasses the dedup claim in `send_queued_email()` (149-150, `if ($notification_id)`) since 0 is falsy, so the email sends unconditionally with no correlation to a (nonexistent) inbox row.
**Fix:** Check `$wpdb->insert()`'s return value; on failure, log via `OUS_DebugLog::log('error', ...)` (already `class_exists()`-guarded elsewhere in this same file) and bail before enqueueing the email job.

### M2. `OUS_Jobs::run_one()` failures never reach the shared `OUS_DebugLog` console
**File:** `class-jobs.php`, lines 424-430 (`run_one`) and 432-450 (`mark_failed`)
**Finding:** The `catch (\Throwable $e)` in `run_one()` routes the error only into the job row's own `last_error` column, visible solely on the Job Queue debug section. It never calls `OUS_DebugLog::log()`/`log_exception()`, even though `OUS_Jobs` already conditionally references `OUS_DebugLog` elsewhere in the same file (line 229-231, on successful Action Scheduler install). This is the inverse of `class-notifications.php::send_email_now()` (176-190), which explicitly routes its own failure into `OUS_DebugLog::log('warning', ...)` specifically so failures aren't only visible in one narrow, feature-specific admin view. `OUS_DebugLog`'s own docblock (9-38) frames it as "the aggregate console... catches anything any plugin explicitly logs" — job failures are exactly the kind of "any plugin" event this is meant to aggregate, and currently don't.
**Failure scenario:** A handler registered via `OUS_Jobs::register()` throws repeatedly (5 attempts, exponential backoff — `mark_failed`, 432-450) and is marked `failed`. An admin triaging via Console & Logs (the ecosystem's supposed single pane of glass, per that class's own docblock) sees nothing — they'd have to separately know to check the Job Queue section instead. Two of the three classes in this audit's scope disagree on whether job/queue failures belong in the shared console.
**Fix:** In `run_one()`'s catch block, add `if (class_exists('OUS_DebugLog')) OUS_DebugLog::log_exception($e, 'OUS_Jobs: ' . $hook);` before/alongside `mark_failed()` — ideally only on the final attempt (when `mark_failed` transitions to `failed`) to avoid flooding the console with transient-retry noise.

### M3. `notice_looks_like_failure()` is an acknowledged text-sniffing hack with no test coverage
**File:** `class-jobs.php`, lines 235-248
**Finding:** The method itself is candidly documented as a known shortcut ("A real status flag threaded through... is out of scope... this text-sniff is good enough"). That's an honest, deliberate tradeoff, not a hidden defect — but it's the only piece of user-facing status logic in either `OUS_Jobs` or `OUS_Notifications` with zero automated coverage (contrast `OUS_Notifications::run_tests()`, 465-491, which pins down its own comparably-fiddly branching logic). A future edit to any of the four success/failure message strings in `handle_install_action_scheduler()` (161-232) that doesn't also happen to contain one of `['failed','Could not','did not','not allowed']` — or the success message picking up one of those words — silently flips a toast's color with no test to catch it.
**Fix:** Not urgent given the documented tradeoff, but worth a one-line `OUS_TestRunner` assertion (`OUS_Jobs` currently registers no test suite at all, unlike `OUS_Notifications`) pinning `notice_looks_like_failure()`'s behavior against the four literal messages actually used at each `redirect_with_notice()` call site, so a future message edit that breaks the color heuristic fails loudly instead of silently.

---

## LOW

### L1. `handle_install_action_scheduler()` failure paths never reach `OUS_DebugLog`
**File:** `class-jobs.php`, lines 145-233
**Finding:** Six distinct failure branches (`WP_Filesystem()` failure, download failure, mkdir failure, extraction failure, archive-layout failure, move failure, post-move verification failure) each call `self::redirect_with_notice($msg)`, which only surfaces the message via `OUS_Toast`/a GET param — none of them call `OUS_DebugLog::log()`. Only the success path does (229-231). This is a one-off admin action (not a recurring background failure like M2), so severity is low, but it's the same inconsistency pattern as M2: this class logs successes to the shared console but not failures.
**Fix:** Optional — add an `OUS_DebugLog::log('warning', ...)` call in `redirect_with_notice()` itself when `notice_looks_like_failure($msg)` is true, covering all six branches with one change.

### L2. `OUS_DebugLog::muted_signatures()` option has no size cap
**File:** `class-debug-log.php`, lines 441-455
**Finding:** `ous_debug_log_muted` is a single autoloaded-by-default (`update_option(..., false)` — actually explicitly non-autoloaded, good) option holding an unbounded array, comment-documented as "a handful of entries expected, not table-scale." No enforcement of that assumption. Genuinely low risk given the realistic ceiling (a solo dev manually muting recurring log lines), noted only for completeness since this audit's brief is specifically to check bounded-growth stories across these three classes.

---

## CONFIRMED GOOD

Verified by reading the actual code, not assumed from naming:

- **`OUS_DebugLog::maybe_trim()` (280-289):** genuinely matches its own docblock claim — opportunistic `wp_rand(1,50)===1` gate, real `COUNT`+bounded `DELETE ... ORDER BY id ASC LIMIT %d`. This is the one class of the three that actually does what VISION.md asks.
- **`OUS_Jobs::run_one()` atomic claim (398-430):** the `UPDATE ... SET status='running' WHERE id=%d AND status='pending'` conditional-update pattern is a real TOCTOU guard, correctly checked via `$wpdb->query()`'s affected-rows return before proceeding.
- **`OUS_Notifications::send_queued_email()` dedup (148-159):** same atomic-claim pattern (`UPDATE ... SET email_sent=1 WHERE id=%d AND email_sent=0`), correctly guards against a job firing twice (manual test + real cron overlap) actually double-emailing.
- **`OUS_Jobs::mark_failed()` exponential backoff (432-450):** `pow(2, $attempts-1)` minutes, correctly re-derived from `$attempts` each call, correctly terminal at `MAX_ATTEMPTS`.
- **`OUS_DebugLog` request correlation (`request_id()`, 104-109; `has_request_id_column()`, 117-128):** the schema-version guard genuinely protects un-migrated installs — verified the `unset($row['request_id'])` (165) actually runs before the `$wpdb->insert()` call, not after.
- **`OUS_DebugLog::log()` insert-failure self-reporting (166-184):** verified `$ok === false && $wpdb->last_error` is checked and persisted via `update_option()`, and that `health_check()` (522-553) actually reads it back and displays it — a real, closed loop, not just a write nobody reads.
- **`OUS_Jobs::init()`'s `plugins_loaded`-then-`init` Action Scheduler bootstrap (73-134):** the reasoning documented in the comment (folder-name load order, hook-priority-1-already-passed) matches the actual code structure — `initialize_latest_version()` is called synchronously rather than trusting a hook that would never fire in time, and the guard is a real `class_exists('ActionScheduler_Versions')` check, not decorative.
- **Comment density/quality bar:** all three files meet the established "why not what" bar (cross-referencing `BHM_Wallet::debit()`, `BHI_Portal::not_recently_attempted()`, `bh-contest.php`'s bootstrap docblock, etc. by name for prior-art failure modes) — consistent with class-portal.php/class-people.php/class-wallet.php/class-jam.php's documented standard.
- **`capture_fatal_on_shutdown()`'s own defensive catch (291-311):** per this audit's brief, confirmed intentional/documented, not flagged as an anti-pattern.

---

## PUNCH LIST (priority order)

1. **H1** — Add bounded-growth trimming to `OUS_Notifications`'s `bhcore_notifications` table (no cap exists today).
2. **H2** — Add automatic (not manual-only) bounded-growth trimming to `OUS_Jobs`'s fallback queue table.
3. **M1** — `OUS_Notifications::notify()`: check `$wpdb->insert()`'s return value; don't enqueue an email keyed to a notification row that doesn't exist.
4. **M2** — `OUS_Jobs::run_one()`: route final-attempt job failures into `OUS_DebugLog`, matching the pattern `OUS_Notifications::send_email_now()` already sets.
5. **L1** — Optional: fold `handle_install_action_scheduler()`'s failure branches into `OUS_DebugLog` via `redirect_with_notice()`.
6. **M3** — Optional: one `OUS_TestRunner` assertion pinning `notice_looks_like_failure()`'s literal-string matching, so a future message edit can't silently break it.
