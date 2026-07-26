# bh-contest — Code-Quality Audit (2026-07)

- **Scope:** entire `bh-contest` plugin, all 27 PHP files (~7.3K lines total). Every file read in full, not sampled.
- **Version audited:** 3.7.11 (was 3.1.6 at the 07-08 audit).
- **Date:** 2026-07-25
- **Model:** claude-opus-4-8
- **Audit type:** code quality only (DRY/SOLID/naming/comments/dead code/fragile patterns). UX is a separate task.
- **Caveats:** Static analysis only — **no live PHP/MySQL/WordPress execution environment is available this session.** Every finding below was confirmed by reading the actual file/line, not just a grep hit. No runtime verification was possible.

Overall: this remains an unusually high-quality codebase. The "why not what" comment discipline, single-source-of-truth helpers, transaction/lock correctness, and output escaping are consistently strong. Findings are mostly polish; the one systemic issue is stale cross-references left behind by the admin split (Finding 1).

---

## God-class assessment: class-admin.php — RESOLVED (was the headline 07-08 finding)

Fresh line count: **class-admin.php is now 42 lines** (was 1,145 at 07-08, and its own docblock says it peaked at 1,863). It has been split ("DRY/SOLID audit Phase 3b") into five focused classes, each with a clear single responsibility and a docblock stating what it does and does NOT own:

| File | Lines | Responsibility |
|---|---|---|
| class-admin-menus.php | 346 | menu/nav, dashboard access-control, contest-page lifecycle, menu resync, search provider, revision restore |
| class-admin-list-tables.php | 121 | wp-admin list-table columns + submission filter |
| class-admin-reports.php | 393 | live results dashboard, CSV export, winner emails |
| class-admin-moderation.php | 212 | approval hook, file-swap approve/discard, reject-with-reason, round advance |
| class-admin-metaboxes.php | 822 | all edit-screen metaboxes + the single save handler |
| class-admin.php | 42 | thin facade: `REJECTION_REASONS` constant + `init()` wiring |

`BH_Admin` now only holds the `REJECTION_REASONS` constant (genuinely shared by moderation/metaboxes/portal-panel — correct not to duplicate) and an `init()` that calls each split class's `init()`. **The God-class is gone; this is a real, verified improvement, not a worsening.** The largest single file is now class-admin-metaboxes.php (822 lines), which is a legitimately dense but cohesive metabox/save class, not a mixed-responsibility God-class.

---

## API permission-shortcut pattern re-check — still consistent WITHIN BH_API, but not shared

`class-api.php:6-8` still defines the `$pub` / `$auth` / `$admin` capability shortcuts and applies them cleanly to every route in `BH_API::register_routes()`, including the newer `submissions/replace-audio` and `submissions/edit-details` endpoints (both correctly `$auth`-tier with real ownership checks inside the callback). The `$carg` sanitize-wrapper comment (lines 10-19) explaining the `sanitize_title()` arg-count trap is a model example of the house comment style. **This pattern is intact and correctly extended.** See Finding 3 for the one consistency caveat (the shortcuts are local to BH_API and not reused by the other route-registering classes).

---

## wp_mail() re-check — the synchronous sends were NEVER queued; timeout risk REMAINS (not a regression, but contradicts the task premise)

The task brief stated prior passes "found and fixed" synchronous `wp_mail()` sites to be "non-blocking/queued." **Reading the actual code, that is not what happened.** Both call sites are still fully synchronous; the only change made (v3.6.1 / v3.1.2) was adding failure *logging*, not de-blocking:

- **`class-admin-reports.php:140-155` — `email_winners()`**: still a synchronous `foreach ($placements ... wp_mail(...))` loop, called inline from `send_winner_notifications()` (an `admin_post_` request). One blocking SMTP round-trip per winner. On a large contest this is a real request-timeout risk. The v3.1.2 change only added `$failed_uids` tracking + a debug-log line afterward.
- **`class-api.php:493-497` — `notify_submission_complete()`**: still a synchronous `wp_mail()` inside `submit()` and `replace_audio()` — i.e. inside a file-upload REST request, exactly the site the brief flagged. v3.6.1 only added the "returned false" warning log.

There is genuine non-blocking discipline elsewhere (Discord uses `wp_remote_post([... 'blocking' => false])`, `class-discord.php:117-122`; `BH_Event::emit()` is fire-and-forget), so the *events* are queued — but the emails themselves are not. **Honest verdict: these are not queued and never were. The timeout concern is still live.** Recommend routing both through a real deferred/queued mail path (the code even references a planned `BH_Mail`/`OUS_Mail` interface in `class-admin-reports.php:161-165`).

---

## Findings

### LOW-1 — Stale `class-admin.php` / `BH_Admin::` cross-references throughout the plugin (violates the codebase's own stated comment bar)
**Severity: Low (but systemic — it's the codebase's own quality bar being broken)**

The house standard is dense comments that cross-reference sibling classes **by name**. After the Phase 3b split, `class-admin.php` is a 42-line facade, yet many comments across the plugin still point a reader there for methods that have moved. A maintainer following these references lands on the facade and finds nothing. Confirmed instances:

- `class-api.php:190-192` — vote() comment cites "class-admin.php" for `handle_reject_submission()` → now `BH_AdminModeration`.
- `class-helpers.php:392` — `has_approved_submission()` comment "see class-admin.php" → the publish-approval logic is now in `BH_AdminModeration`/`BH_AdminMetaboxes`.
- `class-discord.php:29-40` (class docblock) — cites "class-admin.php maybe_notify_approval()", "class-admin.php quick_schedule()", "class-admin.php send_winner_notifications()" → now `BH_AdminModeration`, `BH_AdminMenus`, `BH_AdminReports` respectively.
- `class-post-types.php:35` — "see BH_Admin::add_meta_boxes()" → now `BH_AdminMetaboxes::add_meta_boxes()`.
- `class-share-cards.php:24` — "class-admin.php's maybe_create_contest_page()" → now `BH_AdminMenus::maybe_create_contest_page()`.
- `class-contest-wizard.php:26-28 and 125` — docblock + inline comment cite "BH_Admin::save_contest_meta()" → now `BH_AdminMetaboxes::save_contest_meta()` (the wizard's whole "one save path" design rests on naming this correctly, so it's the most load-bearing stale ref).
- `class-auth.php` / `class-element-surface.php` — assorted "class-admin.php" refs to `render_approval_box()` (now `BH_AdminMetaboxes`).

**Failure scenario:** a developer fixing the reject flow reads `class-api.php:190`, opens `class-admin.php`, finds a 42-line facade with no `handle_reject_submission()`, and has to grep the whole plugin to find it in `BH_AdminModeration`. The comment actively misdirects. **Fix:** search-and-replace the stale `class-admin.php` / `BH_Admin::` method references to the correct split class names.

### LOW-2 — REST permission-shortcut pattern is praised as "reusable" but is not actually shared across route-registering classes
**Severity: Low (DRY / consistency)**

The `$pub`/`$auth`/`$admin` shortcuts live only inside `BH_API::register_routes()` (`class-api.php:6-8`). Four other classes register their own routes and each re-inlines the permission callback instead of using the shared vocabulary:
- `class-judging.php:201` — `'permission_callback' => 'is_user_logged_in'`
- `class-discord.php:63` — `fn() => current_user_can('manage_options')`
- `class-reveal.php:39,43` — `'__return_true'` and `fn() => current_user_can('manage_options')`
- `class-archive.php:29` — `'__return_true'`
- `class-blocks.php:81` — `fn() => current_user_can('edit_posts')`

None of these is wrong, and the values are correct. But the pattern the 07-08 audit cited as "good, reused design" isn't reused — it's duplicated ad hoc. **Fix (optional):** promote the three shortcuts to `public static` helpers on `BH_API` (e.g. `BH_API::perm_admin()`) and have the other classes call them, so the capability policy is single-sourced.

### LOW-3 — Orphaned comment block in `save_contest_meta()`
**Severity: Low (comment placement)**

`class-admin-metaboxes.php:782-793` is a long "why get_post_meta() full dump for OUS_Revisions" comment, but it sits **above** the `_bh_show_in_menu` / `_bh_menu_label` writes (lines 794-797), while the code it actually describes — the `OUS_Revisions::snapshot()` call — is at lines 799-806. The comment reads as if it annotates the menu-meta writes. **Fix:** move the comment block down to immediately precede the `if (class_exists('OUS_Revisions'))` snapshot block.

### LOW-4 — `BH_Discord::medal_lines()` labels medals by array position, not by rank (tie edge case)
**Severity: Low (cosmetic, tie-only)**

`class-discord.php:185-191` assigns 🥇🥈🥉 by loop index `$i` (`$medals[$i]`), whereas the winner-email path (`class-admin-reports.php:145`) correctly indexes by `$w['rank'] - 1`. For a tie (e.g. two entries at rank 1), the Discord results embed would show 🥇 then 🥈 for two entries that are actually co-first, while the emails show 🥇🥇. Inconsistent tie presentation between the two announcement channels. **Fix:** index the Discord medal by `$r['rank'] - 1` to match `email_winners()` and the `competition_ranks()` convention used everywhere else.

### INFO — Known documented gap, still present (not a regression)
`class-debug.php:287-301` `player_page_url()` still scans only `post_content` via `has_shortcode()` with no `has_block()` companion, so a block-only contest page falls back to the site home. This is explicitly acknowledged as a "debug-convenience degradation, not a functional break" in `bh-contest.php:199-202`. Listed for completeness; no action required unless block-authored contest pages become the norm.

---

## Confirmed good (verified this pass)

- **God-class eliminated** — class-admin.php 1,145 → 42 lines via a clean five-way split with self-documenting "what I don't own" docblocks. (Detailed above.)
- **Voting correctness** — `class-api.php` `vote()`: toggle-off checked before the publish-status gate (the documented trapped-vote fix), `FOR UPDATE` transaction around count+insert to prevent double-vote races, `add_option()` atomic submission lock (`submit()`), DB-write failures logged instead of silently claiming success. All present and correct.
- **Orphan-file / storage-leak handling on audio replacement** (the specifically-requested check) — clean and complete: `replace_audio()` deletes a prior *pending* attachment before storing a new one (`class-api.php:580-581`); `promote_pending_audio()` deletes the *old live* attachment on swap-approval (`class-admin-moderation.php:55-64`); `handle_discard_swap()` deletes the pending attachment on discard (`:108-109`); `cleanup_deleted_contest()` trashes submissions + deletes vote rows on contest delete (`class-admin-menus.php:332-345`). The draft→pending first-attach branch correctly writes straight to `_bh_audio_id` without going through the pending-swap path. No orphan path found.
- **`BH_Element` surface integration (`bh_contest_player`)** — genuinely additive, not bolted on. `class-element-surface.php` registers real `BH_Element_Data` sources and slots; `class-auth.php` renders slots as siblings of the JS-owned mount div (never inside it) and the four "extra zone" slots are factored through a single shared `attach_extra_zone()` helper (`class-auth.php:151-157`) rather than duplicated. The docblock's explanation of why the interactive skeleton is deliberately NOT converted is exemplary.
- **`BH_Event::emit()` discipline** — every emit site (vote add/remove, submission_created, file_replaced, swap_approved, rejected) is fire-and-forget, `class_exists()`-guarded, and placed AFTER the DB write/commit, never inside a transaction. Matches the ecosystem's non-blocking-queue convention.
- **DB migration robustness** — `class-activator.php` runs the index DROP+ADD rebuild *before* `dbDelta()` (with a clear comment on the "Duplicate key name" failure mode it avoids), checks `$wpdb->last_error` at every step, and only persists `bh_db_version` on full success. Genuinely careful.
- **Security hygiene** — CSV-injection guard (`csv_safe()`), consistent output escaping in every echo path, nonce+capability gates on every admin-post/AJAX handler, server-side file-type re-check on upload, `sanitize_title()` arg-count wrapper. No SQLi (all `$wpdb->prepare()`), no missing-escape found.
- **Test coverage added** — `class-test-suite.php` now exercises the real internals (medal tier/slice via reflection, judge-score normalization + clamping, round eligibility/advancement) against tagged fixtures with cleanup. This closes the "zero automated coverage" gap the 07-08 audit noted.
- **Single-source-of-truth helpers** — `competition_ranks()`, `category_results()`, `overall_results()`, `contact_config()`, `contest_format()` are each the one canonical implementation reused by every consumer (reveal, Discord, archive, winner emails), exactly as intended.

---

## Prioritized punch-list

1. **(LOW-1) Fix stale `class-admin.php` / `BH_Admin::` cross-references** across class-api, class-helpers, class-discord, class-post-types, class-share-cards, class-contest-wizard, class-auth, class-element-surface → point them at the correct split class. *This is the one finding that breaks the codebase's own stated quality bar; do it first.*
2. **(wp_mail) Decide on the synchronous-send timeout risk** — `email_winners()` loop and `notify_submission_complete()`. Not a regression, but not queued either; route through a deferred mail path (the planned `BH_Mail`/`OUS_Mail` interface) or at minimum document that they are intentionally synchronous.
3. **(LOW-2) Optionally centralize the REST permission shortcuts** so the "reusable pattern" is actually reused by judging/discord/reveal/archive/blocks.
4. **(LOW-3) Move the orphaned OUS_Revisions comment** in `save_contest_meta()` to sit with the snapshot code it describes.
5. **(LOW-4) Make `BH_Discord::medal_lines()` rank-indexed** to match the winner-email medal logic for tie cases.
6. **(INFO) `player_page_url()` block-gap** — leave as-is unless block-only contest pages become common.
