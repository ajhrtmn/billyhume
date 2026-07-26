# bh-courses — Code-Quality Audit (2026-07-25)

**Scope:** Entire `bh-courses` plugin — all 34 PHP files (~8.2K lines: `bh-courses.php`, 29 `includes/class-*.php`, `templates/archive-bh_course.php`, `tests/*.php`). Every file read in full (a couple of the largest, `class-debug.php` and `class-test-suite.php`, read partially + grep-confirmed for the parts relevant to findings).
**Model:** claude-opus-4-8.
**Audit type:** CODE QUALITY only (DRY/SOLID/naming/comments/dead code/fragile patterns + the primary internal-consistency check). UX is a separate task and was not done here.
**Primary job (per task brief):** determine whether the large recent LMS "depth-of-magic" surge (Phases 1–4, plus later 0.4.38–0.4.51 work) is internally consistent with the plugin's older code, or introduced new patterns/conventions that now conflict with what's already there.

**Caveats (non-negotiable):** No live PHP / MySQL / WordPress execution environment was available — this is **static analysis only**. Every finding below was confirmed by reading the actual file/line (grep was used only to locate leads, then each was opened and read). Where a cross-plugin fact was load-bearing (BH_Content tree shape, bh-contest's rank helper) the sibling file was opened and confirmed too.

---

## BHC_VER canary check — PASS

The exact regression caught & fixed 2026-07-21 (plugin-header `Version:` drifting out of sync with `BHC_VER`, silently serving stale JS/CSS) has **NOT recurred**:

- `bh-courses.php:5` — `Version:     0.4.51`
- `bh-courses.php:112` — `define('BHC_VER',  '0.4.51');`

Both read `0.4.51` and are in sync. (Plugin has advanced past the 0.4.37 noted in STATUS.md; the header/constant discipline held across every bump since the fix.) No STATUS.md exists inside the plugin dir itself; version confirmed from the live file.

---

## HIGH — correctness (a shipped feature silently never runs)

### H1. `BHC_VideoSettings::check_tree()` reads the wrong array key — server-side video-size enforcement is dead code
**File:** `includes/class-video-settings.php:89** (`if ($block['blockName'] !== 'bhc/video') continue;`)

`check_tree()` is documented (its own docblock, lines 74–82) as the **authoritative** server-side enforcement of the max-direct-upload-size policy, called from `BHC_ContentBridge::sync_legacy_steps()` on every lesson save. But the tree it is handed comes from `BHC_ContentBridge::get_tree()` → `BH_Content::get()`, whose nodes are keyed **`'type'`**, not `'blockName'`.

Confirmed cross-file:
- `class-content-bridge.php:448–451` — `tree_to_steps()` (the proven-working conversion in the same plugin) reads `$block['type']`.
- `own-ur-shit/includes/class-content.php:13,95` — `BH_Content::get()` returns/`documents `['type' => ..., 'attrs' => ..., 'children' => ...]`. `blockName` is only the *raw* `parse_blocks()` key, which `BH_Content` translates away at line 207.

**Failure scenario:** an admin sets a max direct-upload size in Video Settings; an author saves a lesson whose uploaded video exceeds it. `$block['blockName']` is undefined (emits a PHP notice), `null !== 'bhc/video'` is always true, so **every** block is `continue`'d, `$over` stays empty, the transient is never set, and `maybe_show_notice()` never fires. The entire feature is inert, while its docblock and admin UI claim it works. Same class of "shipped feature that silently doesn't run" as the original BHC_VER stale-cache bug.

**Fix:** `if (($block['type'] ?? '') !== 'bhc/video') continue;` (and read children if nested video steps are ever possible — currently they aren't). Add a Test-Runner assertion feeding a real `BH_Content` tree through `check_tree()` so this can't silently rot again.

---

## MEDIUM — internal consistency (new-vs-old pattern conflicts) — *the primary job*

These are the concrete answers to "did the feature surge conflict with older code?" Two real omissions where a **newer feature added its own meta key but the older duplication code (0.4.25) was never taught about it**:

### M1. Whole-course duplication silently drops the leaderboard opt-in
**File:** `includes/class-admin.php:751–758** (`handle_duplicate_course()` meta-copy list)

The flat copy list is `_bhc_instructor_id, _bhc_difficulty, _bhc_duration_note, _bhc_comments_enabled, _bhc_certificate_enabled, _bhc_certificate_signature, _bhc_share_card_style, _bhm_required_tier, _bhm_required_benefit`. It **omits `_bhc_leaderboard_enabled`** — the Phase 4 (0.4.37) per-course opt-in written by `save_course()` at `class-admin.php:384` and read by `BHC_Leaderboard::is_enabled()`.

**Failure scenario:** instructor enables "Top quiz scorers" on a course, then uses "Duplicate this course as a template." The clone loses the leaderboard setting with no warning — exactly the kind of course-level SETTING the copy list's own comment (lines 747–750: "every one of these is a course-level SETTING… copying all of them is correct") says should carry over. The list simply predates the feature.

**Fix:** add `_bhc_leaderboard_enabled` to the copy array. (Consider also whether `_bhc_certificate_signature` copying the empty string via `if ($val !== '')` is intended — it is; no change needed there.)

### M2. Both duplication paths drop the module/section grouping
**Files:** `includes/class-admin.php:787–790` (course-dup lesson clone loop) and `includes/class-admin.php:855–858` (`handle_duplicate_lesson()`)

Both loops copy only `_bhc_available_after_days` and `_bhc_available_on_date` per lesson. Neither copies **`_bhc_module_title`** — the module/section grouping meta (read by `BHC_PostTypes::module_title()`/`grouped_lesson_order()`, written by `save_lesson()` at `class-admin.php:628–633`), a newer per-lesson feature added after the duplication code.

**Failure scenario:** a course organized into "Module 1 / Module 2" collapsible sections is duplicated (whole course, or a single lesson). The clone's lessons come back **ungrouped** — all module headings gone — because the copy loops never learned about the field. Same root cause as M1: per-lesson feature bolted on after the duplication logic was written.

**Fix:** add `_bhc_module_title` to the per-lesson meta copy in both handlers.

---

## The leaderboard "without sharing code" claim — checked, defensible (not a defect)

The 0.4.37 header comment says `BHC_Leaderboard` mirrors bh-contest's reveal display "without sharing code with it." Verified:

- `BHC_Leaderboard::top_scorers()` (`class-leaderboard.php:54–62`) reimplements standard competition ranking (1,1,3) locally. bh-contest already exposes a **public** helper for exactly this: `BH_Helpers::competition_ranks()` (`bh-contest/includes/class-helpers.php:486`). So there IS duplicated ranking logic.
- Medal glyphs are duplicated too: `class-leaderboard.php:82` uses PHP HTML entities `🥇🥈🥉`; `bh-contest/assets/js/reveal.js:13` `medalIcon()` uses the same emoji in JS.

**Verdict:** this is **acknowledged, deliberate duplication**, and the claim is truthful. bh-courses depends only on own-ur-shit (see `bh-courses.php:180–198`) and explicitly NOT on bh-contest, which may not be installed — calling into `BH_Helpers` would create a dependency the plugin's whole architecture avoids. The comments at `class-leaderboard.php:49–53` and `78–81` state this reasoning. Flagging for visibility only: **~10 lines of ranking + a 3-entry medal map are now maintained in two plugins.** If a shared home ever appears (e.g. an own-ur-shit `OUS_*` helper both can depend on), consolidate then. No change recommended now.

---

## LOW — minor consistency / dead-ish code / defensiveness

- **L1. `BHC_Admin::describe_step()` switch is stuck on the v1 step types.** `class-admin.php:572–593` gives content snippets for `text/image/video/quiz` but every newer type (`resource/callout/checklist/chord-chart/audio-compare`) falls to `default: return ucfirst($type)` — a bare "Callout" / "Checklist" label with no content preview, while old types get real snippets. The admin "current steps" summary is inconsistent across old vs. new step types. Low impact (the `default` is safe), but the newer types were added to `VALID_TYPES`/rendering/content-bridge without extending this one summary helper.

- **L2. Debug seeder still advertises "every step type" but seeds only text/image/quiz.** `class-debug.php:15,28,31,37` — the seed course and its copy say "every step type" but predate video/resource/callout/checklist/chord-chart/audio-compare. Purely a demo-data staleness; the edge-case preset seeder (lines 39+) is more current. Low priority.

- **L3. Test coverage gap for the new step-type sanitization branches.** `BHC_Steps::save()` gained real "drop the dead step" guards for callout/checklist/chord-chart/audio-compare (`class-steps.php:180–216`), but neither the Test Runner suite nor `tests/StepsSanitizationTest.php` asserts on them (they still only cover text/image/video/quiz). Leaderboard and achievements, by contrast, DO have real DB-backed Test-Runner coverage (`class-test-suite.php:567–734`) — so the surge's coverage is uneven: the new *classes* were tested, the new *step types* were not.

- **L4. Redundant/over-defensive guard in portal panel.** `class-portal-panel.php:113` — `class_exists('BHC_Progress') && method_exists('BHC_Progress','is_course_completed') ? ... : ($percent >= 100)`. This method already early-returns at line 90 if `!class_exists('BHC_Progress')`, and `is_course_completed()` always exists on the class, so the whole ternary fallback is dead. Harmless, but reads as cargo-culted defensiveness inconsistent with the crisp guards elsewhere.

- **L5. Cosmetic ordering in content-bridge block registration.** `class-content-bridge.php:293–326` — the comment block (293–305) describes `resource` then `callout`, but the `bhc/callout` `register_block_type()` call (306) physically precedes the `bhc/resource` one (314). Reads confusingly; no functional effect.

---

## Confirmed good (worth recording)

The recent surge is, overall, **strongly consistent** with the older code — the two real conflicts (M1/M2) are both the same narrow "duplication copy-list didn't learn a new meta key" class, not a divergence in architecture or conventions. Specifically verified good:

- **Comment bar is met.** Dense "why-not-what" comments explaining the failure mode each piece prevents, cross-referencing siblings by name, are present throughout the new code (achievements, leaderboard, completion screen, drip nudges, comments' `pre_get_comments` dead-end story) — same standard as the cited exemplars.
- **`0 = open/global default` convention** is applied uniformly across old and new: `_bhm_required_tier`, quiz `max_attempts`, `watch_threshold`, and the new `bhc_achievements.course_id` sentinel (`class-activator.php:165–184`, `class-achievements.php`) all follow it, with the NULL-vs-0 UNIQUE-KEY reasoning correctly documented.
- **`null`-means-"nothing to show" vs `0`** is consistent: `course_quiz_average()` returns `null`, and every consumer (completion screen, certificate distinction, leaderboard, mastery label, achievements) checks `!== null` before showing a number. No "0%" placeholders leak.
- **New step types integrate through the existing generic pipeline** (`steps_to_tree`/`tree_to_steps` handle any `bhc/*` block generically; only `bhc/quiz`'s child-block promotion is special-cased), and the hyphenated-type reasoning (`class-steps.php:60–73`) is sound.
- **Achievements/certificate share one distinction threshold** via the `bhc_certificate_distinction_threshold` filter (`class-achievements.php:134`, `class-certificates.php:109`, `class-render-course.php:425`) — no drifting duplicate bar.
- **Completion screen is shared, not duplicated**, between the lesson final-step reveal and the course page (`render_completion_screen()` with the well-reasoned `$assume_complete` flag, `class-render-course.php:394–474`).
- **The SRP split (0.4.8)** into Render/Catalog/Course/Lesson holds; `BHC_Render` is a clean delegating shell.
- **Dual-write hazard stays closed:** `BHC_ContentBridge::sync_legacy_steps()` remains the sole writer of `_bhc_steps`; the old repeater write path is gone (`class-admin.php:652–661`).
- **Security hygiene is consistent:** nonce + capability checks on every admin-post/AJAX handler; `esc_*` on output; `$wpdb->prepare()` everywhere including the dynamic `IN (...)` placeholder-build pattern; INSERT IGNORE / ON DUPLICATE KEY for all dedup.
- **Reviews / comments / gate / drip / nudges / metrics / CRM** integrations all follow the `class_exists()`-guarded optional-peer convention correctly.

---

## Prioritized punch-list

1. **[HIGH] `class-video-settings.php:89`** — change `$block['blockName']` → `$block['type']`; the entire server-side video-size enforcement is currently dead. Add a Test-Runner case feeding a real BH_Content tree.
2. **[MED] `class-admin.php:751–758`** — add `_bhc_leaderboard_enabled` to `handle_duplicate_course()`'s meta-copy list (course duplication drops the leaderboard opt-in).
3. **[MED] `class-admin.php:787–790` and `:855–858`** — add `_bhc_module_title` to the per-lesson meta copy in both duplication handlers (duplication drops module/section grouping).
4. **[LOW] `class-admin.php:572–593`** — extend `describe_step()` to give the newer step types a content snippet, matching old types.
5. **[LOW] `tests/StepsSanitizationTest.php` + `class-test-suite.php`** — add sanitization assertions for callout/checklist/chord-chart/audio-compare "drop dead step" branches.
6. **[LOW] `class-debug.php:15,28,31,37`** — refresh seed copy/data to include newer step types (or reword the "every step type" claim).
7. **[LOW] `class-portal-panel.php:113`** — drop the redundant `class_exists`/`method_exists` fallback ternary.
8. **[LOW] `class-content-bridge.php:293–326`** — reorder callout/resource registration to match their describing comment.
9. **[INFO] `class-leaderboard.php:54–62,82`** — duplicated competition-ranking + medal logic vs. bh-contest's `BH_Helpers::competition_ranks()`; deliberate and defensible under the no-dependency rule. No action unless a shared own-ur-shit home appears.
