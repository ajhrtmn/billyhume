# bh-crm — Code Quality Audit (2026-07-25)

**Scope:** all 15 PHP files in `bh-crm/` (`bh-crm.php` + 14 files under `includes/`), ~4.9K lines, full read (not sampled).
**Model:** Claude Sonnet 5. **Trigger:** plugin jumped v1.3.5 → v2.4.10 since the last full audit (2026-07-08) — every prior finding fully re-verified against current code, not just re-read.
**Caveat:** no live PHP/MySQL/WordPress/browser execution environment is available. This is static analysis only — every finding below is confirmed by reading the actual file/line, not by grep pattern-matching alone, but nothing here has been runtime-verified.
**UX is out of scope** — this is DRY/SOLID/naming/comments/dead-code/fragile-pattern review only.

---

## Re-verification of prior findings

### 1. `bhi_profiles` raw-SQL encapsulation violation (class-people.php / class-export.php) — **FIXED**

Both call sites now go through `BHI_Profiles::user_ids_with_profile_data()` instead of running raw SQL against core's `bhi_profiles` table:

- `includes/class-people.php:104-113` (`active_user_ids()`) — `$with_profile = class_exists('BHI_Profiles') ? BHI_Profiles::user_ids_with_profile_data() : [];`, with an explicit comment citing the prior QA finding by name.
- `includes/class-export.php:10-15` (`handle()`) — identical fix, same comment referencing the same finding.

Both comments cross-reference each other and name `BHI_Profiles` as the class that owns the table. This is now a clean, single-owner read path — confirmed fixed, not just moved.

### 2. `class-projects.php` top-of-file status comment (STATUS.md 07-21 flagged as stale) — **the specific claim STATUS.md flagged is fixed, but the comment has gone stale again in a new way**

The line that used to claim "DESIGN ONLY, NOT BUILT" is gone. `includes/class-projects.php:13-24` now reads:

> "STATUS UPDATE (2026-07-21...): the line that used to sit here calling the whole plan 'DETAILED PLAN ONLY, NOT BUILT' is stale — class-subtasks.php since shipped a real, substantial nested sub-task system... Phases B-E (timestamped fixes, a feedback log, stall analytics, linked local audio/MIDI files, separate scenes/boards) remain genuinely unbuilt."

**That second sentence is itself now wrong.** Since that correction was written, all of the following shipped and are demonstrably present in the current codebase:

- **Phase B (timestamped fixes + feedback log)** — fully built in `includes/class-card-log.php` (`bhcrm_project_fixes`, `bhcrm_project_feedback` tables; `add_fix()`/`toggle_fix_resolved()`/`list_fixes()`, `add_feedback()`/`list_feedback()`; rendered via `BHCRM_CardLog::render()`, called from `BHCRM_Subtasks::render()` at `class-subtasks.php:204-206`).
- **Phase C (stall analytics)** — fully built, and actually lives in `class-projects.php` itself (`bhcrm_project_card_moves` table, `on_placement_saved()`, `log_card_move()`, `stalled_cards_for_board()`, `average_hours_per_column()`, `STALL_DAYS` constant, `/stalled-cards` REST route) — the very file carrying the stale comment contains the feature the comment says is missing.
- **Phase D (linked local files / "Idea Drop")** — fully built in `class-card-log.php` (`bhcrm_project_attachments` table, `add_track_link()`/`add_upload()`, bh-streaming track-linking with upload fallback, `render_attachments()`).
- **Phase E (separate scenes/boards)** — fully built: `bhcrm_projects.scene` column, `update_scene()`, `distinct_scenes()`, and `render_boards()` (`class-projects.php:407-425`) groups the Project Tracker index by scene.

So the "remain genuinely unbuilt" claim is factually false as of this audit — every phase it lists has shipped, with test coverage for each (`class-test-suite.php`'s `run_card_log_tests()`, `run_idea_drop_tests()`, `run_stall_analytics_tests()`, `run_project_scene_tests()`). This is the **same doc-hygiene failure mode** the 07-21 STATUS.md correction was itself written to fix — a top-of-file status comment that doesn't get updated when the next round of features lands. Given this has now recurred once already on this exact file, it's worth a standing habit (or a lint/checklist step) rather than a one-off fix.

### 3. Does the Activity section show a contact's full pre-signup event timeline? — **Yes, definitively, for anything with a `user_id`**

`includes/class-event-activity.php` (`BHCRM_Event_Activity`) reads `{$wpdb->prefix}bhcore_events` directly, filtered by `WHERE user_id = %d` (`total_count()` line 47-53, `recent_events()` line 58-68), and contributes an "Event Tracking" section to `bh_crm_activity_summary` (`contribute_summary()`, line 106-119) showing every event type any plugin has emitted (contest votes, course enrollments, wallet credits/debits, emails sent, CRM note/tag saves, project links, track plays/skips — full label map at lines 79-93). The docblock (lines 12-22) explains why it deliberately does NOT widen the query via `BH_Identity::client_ids_for_user()`: there's no separate identity-stitching table, so every event that could ever match already carries `user_id` via `BH_Event::backfill_user_id()`'s one-shot UPDATE — widening would only add redundant OR clauses, not new rows.

So: this is a genuine full-ecosystem event timeline (not just wallet/tier data from bh-monetization-woo), and it is separate from and additive to the filter-based `bh_crm_activity_summary` contributions from other plugins (contest, streaming, monetization) that STATUS.md 07-21 was referring to. The one caveat, disclosed in the code itself: it only shows events that already carry `user_id` on the row — a genuinely pre-*account* action (before the person ever had a WP user id at all, with nothing ever backfilled to it) would not appear, but that's a data-availability limit of the underlying `bhcore_events` table, not a gap in this class's own query. The detail table caps at the 25 most recent (`DETAIL_LIMIT`), with a "showing N most recent" note when the total exceeds that.

---

## New findings (code that landed since the version jump)

### N1. `class-segments.php`'s `has_project` condition still reads the legacy `crm_person_id` column, unlike everything else post-1.8.0

`includes/class-segments.php:171-175` (`matches_condition()`, field `has_project`):

```php
case 'has_project':
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'bhcrm_projects WHERE crm_person_id = %d', $user_id
    ));
```

Since bh-crm 1.8.0, `crm_person_id` was demoted to "legacy fallback, still written on `create()` but no longer read as source of truth anywhere" (per `bh-crm.php:68-77` and `class-projects.php:319-325`'s own QA-fix comment) — `list_for_person()`/`people_for_project()` both moved fully onto `BHCRM_Links`. This segment condition is the one place in the plugin that was missed: `grep -rn crm_person_id includes/` confirms it's the only live query left reading that column for anything other than the legacy-migration path (`BHCRM_Links::migrate_legacy_project_owners()`) or display fallback (`render_boards()`'s "no one linked" case).

**Concrete failure case:** a project created with `crm_person_id = 0` (e.g. from the top-level Project Tracker "Create project" form with no person selected — this is an explicitly supported flow per `handle_create()`, `class-projects.php:876`) and later linked to a person only via `BHCRM_Links::link_project_person()` (the "Link person" panel on the board, `render_people_panel()`) will never match a saved "has_project" segment condition for that person — `crm_person_id` stays 0 forever on that row, even though `list_for_person()` on the person's own detail page correctly shows the project. A staff member building a "has an active project" smart list will silently under-count anyone whose project was linked this way rather than created-with-owner. Fix: route this condition through `BHCRM_Links::project_ids_for_person($user_id)` (non-empty) the same way `list_for_person()` already does, matching the class's own stated single-source-of-truth policy.

### N2. Duplicated progress-bar markup between `class-projects.php` and `class-subtasks.php` — disclosed, but still a real duplication worth a follow-up

`class-projects.php`'s `bh/sticky-card` render callback (lines 719-737) hand-builds the same "track + fill + N/M done (X%)" bar markup that `BHCRM_Subtasks::render_progress_bar()` (`class-subtasks.php:220-228`) already implements as a reusable method. The docblock at `class-projects.php:724-731` explicitly discloses this is deliberate ("this renderer is a separate, server-only code path... so the bar markup is duplicated inline here rather than shared") because the interactive kanban board draws its own card DOM client-side and never calls this PHP renderer. That's a legitimate reason the two code paths exist, but it doesn't require the *markup* itself to be duplicated — `render_progress_bar()` (or a shared static helper both classes call) could be reused here directly since both are plain PHP methods on plugin classes, not tied to render context. Low priority since it's small, self-contained, and already honestly commented, but a genuine DRY opportunity, not just documented debt.

### N3. `class-hub.php` double-registration under 'bh-crm-hub' — intentional, correctly documented, not a finding

Flagging only to record it was checked: `add_menu()` (`class-hub.php:52-74`) registers the real `BHCRM_People::render()` callback twice, once as the top-level page and once as a relabeled first submenu under the same slug. This looks like a copy-paste duplication at first glance but the class docblock (lines 16-24) explains it mirrors `OUS_Dashboard::add_menu()`'s established convention and is required by `DESIGN-SUITE-UNIFICATION-PLAN.md §1.4`'s standalone-page mitigation rules on this install (a documented WordPress-core hook-resolution bug affecting standalone pages). No action needed — confirmed intentional and consistent with the ecosystem's own established pattern.

---

## Confirmed good

- **Comment density/quality bar is fully maintained** across all 15 files at the v1.3.5-era standard — dense "why not what" comments explaining failure modes prevented, consistently cross-referencing sibling classes by name (`class-people.php` ↔ `class-export.php`, `class-projects.php` ↔ `class-subtasks.php` ↔ `class-card-log.php`, `class-links.php` ↔ both). No regression in comment discipline despite the large version jump.
- **`class-subtasks.php`'s reference-returning tree helpers** (`find_node()`, `children_at()`) are correctly implemented PHP reference semantics for in-place mutation of a nested array before a single whole-document re-save — genuinely tricky to get right (missing `&` on a `foreach` or a return path is an easy silent-copy bug) and it's exercised by real unit tests via `ReflectionMethod` in `class-test-suite.php` (`run_subtasks_tree_tests()`), not just asserted by comment.
- **`class-export.php::csv_safe()`** — correct, tested CSV-injection guard (leading `=+-@` gets a defusing apostrophe), with its own dedicated test coverage.
- **Capability/nonce gating is consistent and specific** across every admin-post handler — `bhcore_manage_crm` for non-destructive actions, `manage_options` (or `OUS_Audit::require_cap()`) for real deletes, matching the documented 1.9.1 permissions-audit pass. Spot-checked every handler in `class-projects.php`, `class-subtasks.php`, `class-card-log.php`, `class-segments.php`, `class-tags.php`, `class-notes.php` — no gaps found.
- **`BHCRM_Links`** is a clean, genuinely reusable generic relationship table (not over-engineered for its one current use, not under-built for the stated future reuse) with a real idempotent legacy migration (`migrate_legacy_project_owners()`, safe to re-run via the `UNIQUE` key).
- **Test coverage is real and growing appropriately** — `class-test-suite.php` covers segment sanitization, CSV safety, subtask tree helpers, stall analytics (including a self-caught test-design bug around chronological backdating, honestly documented at lines 206-215), card-log fixes/feedback, scene grouping, and Idea Drop track-linking/rejection — this is a plugin that had zero coverage before 1.x and now has meaningful regression protection on its trickiest logic (recursive tree ops, time-window math).
- **No dead code found** — every method read is reachable from either a hook registration in `bh-crm.php`/`init()`, a render call site, or a test.

---

## Prioritized punch-list

1. **[Doc hygiene, recurring]** Update `class-projects.php`'s top-of-file status comment (lines 13-24) — Phases B, C, D, E are all built (C lives in this very file). This is the second time this exact comment has gone stale; worth a standing check ("does this status line still match what shipped?") whenever a Phase lands, not just a one-off correction.
2. **[Correctness bug]** `class-segments.php:171-175` — `has_project` segment condition still queries the legacy `crm_person_id` column; route it through `BHCRM_Links::project_ids_for_person()` like every other post-1.8.0 read path, or a person linked only via the Links-based "Link person" panel will never match a saved "has an active project" smart list.
3. **[Minor DRY]** Extract the progress-bar markup duplicated between `class-projects.php`'s `bh/sticky-card` render callback and `BHCRM_Subtasks::render_progress_bar()` into one shared helper both call — the two call sites having separate reasons to exist doesn't require the markup itself to be copy-pasted.
4. No action needed on `class-hub.php`'s dual menu registration — confirmed intentional, correctly documented, matches established ecosystem convention.
