# Quiz answer storage & course-catalog information architecture

A design pass over two separable `bh-courses` gaps that both surfaced from the same recent front-end work: the quiz-review UX that can only ever say pass/fail (`class-render.php` lines 276-283 flag this in a comment as a deliberate deferred schema gap), and the course catalog that today renders as an unfiltered, unsortable `get_posts()` dump (`class-render.php::render_catalog()`, lines 34-56). Grounded in the code as it stands, cited by file/class/line, in the same call-site-first spirit as `LMS-AUTHORING-DESIGN-PLAN.md` and `EVENT-TRACKING-ARCHITECTURE-PLAN.md`. Read those two first — this doc reuses their conventions (latest-state vs. audit-trail retention reasoning; "don't build what already exists"; reuse the ecosystem's own design system rather than inventing one) rather than re-deriving them.

The two problems have no hard dependency on each other and can proceed in parallel (Section 7 confirms this against the one place they might have collided — the catalog's "popular" sort — and shows they don't). They are documented together only because they are the same person's same "make the student-facing surfaces real" ask.

---

# Part 1 — Quiz answer storage

## 1.1 The finding that decides the storage shape

The instinct on "store quiz answers" is to reach for a per-attempt or per-answer audit table. **Reading `bhc_progress` as it actually behaves, that instinct is wrong for this table — and the table itself is the precedent.**

`bhc_progress` is not, and has never been, a history table. Its `CREATE TABLE` (`class-activator.php` lines 46-57) carries a `UNIQUE KEY user_lesson_step (user_id, lesson_id, step_index)`, and every write goes through `BHC_Progress::mark_step_complete()` as an `INSERT ... ON DUPLICATE KEY UPDATE` (`class-progress.php` lines 90-95) that **overwrites** `score`/`passed` and **increments** `attempts`. There is exactly one row per (user, lesson, step), forever. The second attempt's score replaces the first's; the first attempt's score is already gone today. The table deliberately keeps *latest state*, not *per-attempt history* — `attempts` is a counter, not a foreign key to a set of attempt rows.

This ecosystem draws the audit-vs-latest-state line explicitly and consistently, and `bhc_progress` sits on the "latest state" side on purpose:

- **Audit/append-only side:** `bhm_play_log` records "a row EVERY time a play is allowed... so there's one authoritative, server-authored history to build reporting or a future payout engine on top of" (`bh-monetization-woo/includes/class-products.php` lines 320-324). `EVENT-TRACKING-ARCHITECTURE-PLAN.md`'s `bhcore_events` is the same shape — one immutable row per event, `occurred_at` distinct from `created_at` so queue latency never corrupts "when did this happen." These exist because *money and cross-plugin analytics need an audit trail*.
- **Latest-state / deduped side:** `bhc_progress` (upsert, one row per step), `bhc_enrollments` (`INSERT IGNORE`, one row per user+course, `class-progress.php` lines 149-152), `bhc_completions` (`INSERT IGNORE` + `UNIQUE KEY`, fire-once, lines 177-180). These exist because *the only question anyone asks is "what is this student's current status."*

The quiz-review feature the request describes — "you picked B, the right answer was C" — is a **current-status** question, not an audit question. It asks "what did you answer on the attempt that counts," not "reconstruct every attempt this student ever made." Nobody in the current product asks the second question; there is no gradebook, no per-attempt reporting screen, no payout engine keyed on quiz history. Building a per-attempt answers table now would introduce an audit-trail concept `bhc_progress` deliberately does not have, to serve a feature that doesn't need it — the exact "building for a case nothing exercises yet" the storefront scope-note (`class-storefront.php` lines 35-48) warns against.

## 1.2 Recommendation: a JSON `answers` column on `bhc_progress`, latest-attempt only

Add one nullable column to the existing table rather than a new table:

```sql
answers longtext DEFAULT NULL   -- JSON; NULL for non-quiz steps and pre-migration quiz rows
```

- **Same row, same upsert, same lifecycle.** The answers snapshot is written by the same `mark_step_complete()` upsert that already writes `score`/`passed`/`attempts`, so it inherits the table's "latest attempt wins, once-passed-stays-frozen" semantics for free. On a failing attempt it's overwritten with that attempt's answers; once `passed = 1`, `ajax_submit_quiz()`'s existing early-return (`class-progress.php` lines 249-257) never rescores, so the passing attempt's answers are what stays. That is exactly the set a "review your quiz" screen wants to show.
- **NULL for non-quiz steps**, matching how `score`/`passed` are already NULL for text/image steps — and note the `$wpdb->prepare()` NULL-passthrough bug the table already had to fix once (lines 73-83): the same conditional-placeholder pattern (`$answers_sql = $answers === null ? 'NULL' : '%s'`) must be used, not a bound `null` cast to `''`.
- **Cost:** one `ALTER TABLE ADD COLUMN` via `dbDelta()` (which handles add-column idempotently), a `DB_VERSION` bump to `'1.2'` in `class-activator.php` line 11, and its changelog note. `dbDelta` on the existing `CREATE TABLE` string with the new column line added is the whole migration — the same "runs on every load via a cheap early-return" pattern `maybe_upgrade()` (lines 21-26) already implements.

**Why not a separate table even for latest-only:** a one-row-per-passing-attempt side table keyed on the same `(user, lesson, step)` unique key would be a second table that has to be joined on every review render and kept in sync with `bhc_progress`'s own lifecycle (the once-passed-freeze, the failed-attempt overwrite) — pure duplicated bookkeeping for zero capability the column doesn't already give. The column *is* the join, for free.

**When a real per-attempt table WOULD be right, flagged not built:** the day this product grows a real gradebook/audit requirement — an instructor dashboard showing "every attempt every student made," an academic-integrity trail, exportable transcripts — that is a genuinely different feature with a genuinely different retention answer, and it should get its own append-only `bhc_quiz_attempts` table modeled on `bhm_play_log`/`bhcore_events` (one immutable row per submission, `submitted_at`, the answer set, the score). That is deferred as an explicit future feature, not half-built here. The column recommended above does not block it and is not thrown away by it — a future attempts table would be an additive audit log alongside the latest-state column, the same way `bhcore_events` rides alongside (never replaces) `bhc_progress`'s own status rows.

## 1.3 The exact shape of a stored answer record — and why it must snapshot

The minimum the request names is `question index -> chosen choice index`. That minimum is **not sufficient here**, for a concrete reason rooted in `LMS-AUTHORING-DESIGN-PLAN.md`: quiz questions are now editable `bhc/quiz-question` child blocks (`class-content-bridge.php` lines 143-156, promoted from the old attribute-array per that doc's Section 3.2). An author can edit a question's wording, reorder choices, add/remove a choice, or change `correct_index` *after* a student has taken and passed the quiz. A stored `{0: 1, 1: 2}` (question-index -> choice-index) replayed against the *current* `_bhc_steps` would then show the student "you picked B" pointing at a choice that has since been renamed, moved, or deleted — actively misleading review, worse than no review.

So the stored record must **snapshot the question text, the choice list, and the correct index at submission time**, not just the chosen indices. Concrete shape (`answers` column, JSON):

```json
{
  "score": 67,
  "passed": false,
  "passing_score": 70,
  "questions": [
    { "q": "What is a ...", "choices": ["A text", "B text", "C text"], "correct_index": 2, "chosen_index": 1 },
    { "q": "Which of ...",  "choices": ["X", "Y"],                     "correct_index": 0, "chosen_index": 0 }
  ]
}
```

This is self-contained: the review UI renders entirely from this blob and never re-reads `_bhc_steps`, so a later edit to the quiz can't corrupt a past attempt's review. `chosen_index` of `-1`/absent encodes "left blank." `score`/`passed`/`passing_score` are duplicated into the blob so the review renders standalone even though they also live in their own columns — cheap, and it keeps the review payload one read.

Storage size is bounded and small (a handful of questions, a few short strings each — the same order of magnitude as the `_bhc_steps` quiz step itself), well within `longtext` and never queried *into* (it's read whole, by primary-key row lookup, never `WHERE answers LIKE`), so it needs no index and imposes no query cost on the hot progress-read paths (`completed_steps()`, `step_status()`).

## 1.4 Where the write happens — the change to `score_quiz()` / `ajax_submit_quiz()`

`BHC_Steps::score_quiz()` (`class-steps.php` lines 143-157) already iterates `$questions` with the submitted `$answers` and computes correctness per question — it has every value the snapshot needs, it just currently throws them away after counting `$correct`. Two small, contained changes:

1. **`score_quiz()` returns the per-question detail it already computes.** Extend its return array (today `['score','passed','total','correct']`) with a `'questions'` array built inside the existing `foreach ($questions as $i => $q)` loop: for each, push `['q' => $q['question'], 'choices' => $q['choices'], 'correct_index' => $q['correct_index'], 'chosen_index' => $answers[$i] ?? -1]`. No new iteration, no new data source — it's the loop that already exists, keeping one more thing.
2. **`ajax_submit_quiz()` persists it.** Right where it already calls `mark_step_complete($user_id, $lesson_id, $step_index, $result['score'], $result['passed'])` (`class-progress.php` line 266), pass the snapshot through so `mark_step_complete()` writes the new column in the same upsert. Assemble the blob from `$result` (score/passed/passing_score + the new `questions` detail) and `json_encode` it. `mark_step_complete()` gains one optional `$answers_json = null` parameter, written via the same conditional-`NULL`-placeholder technique the method already uses for `score`/`passed`.

Nothing else moves. `mark_step_complete()`'s course-completion trigger, the once-passed early-return, the attempts-exhausted guard (lines 235-240), and `is_step_complete()`/`completed_steps()`' `passed === null` logic all keep working untouched — the column is additive and read only by the new review path.

## 1.5 The richer quiz UX — and the scoring-integrity call

The request asks for "immediate per-question feedback" and "better visual states." "Immediate" is genuinely ambiguous and the two readings have very different integrity properties:

- **(A) Per-question-as-you-go** — reveal correct/incorrect the instant a student picks each radio, before the whole quiz is submitted.
- **(B) Rich per-question breakdown at submission** — the student answers the whole quiz, submits once, and then sees a per-question correct/incorrect breakdown all together.

**Recommendation: (B), the end-of-submission breakdown. Do not build (A).** The honest reason is a scoring-integrity conflict with the retry model that already exists. This quiz is scored as *one unit* against `passing_score` with a per-step `max_attempts` budget (`class-steps.php` lines 115-125; enforced in `ajax_submit_quiz()` lines 230-240). If correctness is revealed per-question before submission, a student on a multi-question quiz with more than one attempt can learn the right answer to question 1 on attempt 1, back out, and re-enter with that knowledge — the `max_attempts` budget stops meaning "how many times may you be tested" and starts meaning "how many free hints do you get." (A) quietly guts the integrity of the very `max_attempts`/`passing_score` feature the last pass added. (B) has no such hole: the student commits all answers, spends one attempt, and only then sees the breakdown — the attempt is already scored and counted before any correctness is shown.

(B) is also exactly what the snapshot in 1.3 already produces. The submission response `ajax_submit_quiz()` returns already flows to `courses.js` (lines 79-112); today it only renders the aggregate line "Score: X% (n/m correct)" into `.bhc-quiz-result` (line 104). The enhancement: return the `questions` detail (already computed in 1.4) in the AJAX success payload and have `courses.js` render, per question, a correct/incorrect state — mark the chosen choice, mark the correct one when the student got it wrong. This is the "better visual states" ask and the "per-question feedback" ask satisfied by the same payload, with none of (A)'s gaming surface.

**Visual states**, reusing the existing token-driven CSS in `courses.css` (which already keys everything off `BHY_Style`'s `--bh-*` vars, lines 21-23, 80-83): add `.bhc-quiz-choice.bhc-correct` / `.bhc-choice-incorrect` states painted with the existing accent/border tokens (a green-lean for correct, a muted/`--bh-border` for the student's wrong pick, a subtle correct-answer highlight). No new color system — the same `.bhc-quiz-result.bhc-pass`/`.bhc-fail` pattern already there, extended one level down to the choice.

**Review path (the passed-quiz revisit).** The comment at `class-render.php` lines 276-283 explicitly notes that revisiting a passed quiz via the back button currently can't show the chosen answers because they were never stored. With the `answers` column, `render_step()`'s quiz branch (lines 266-304) can, when `$already_passed` (or any row with a stored `answers` blob) is true, render the stored snapshot as a static breakdown instead of an interactive form — closing the exact gap that comment flags, from stored data, immune to later quiz edits.

---

# Part 2 — Course-catalog information architecture

## 2.1 What a `bh_course` has today — confirmed

Confirmed by reading `class-post-types.php` lines 22-31: `bh_course` registers `'supports' => ['title', 'editor', 'thumbnail']` and nothing else. So:

- **Cover image — already have it.** `thumbnail` support; `render_catalog()` already renders it via `get_the_post_thumbnail()` (`class-render.php` line 45). No new field.
- **Description — already have it.** `editor` support (the post body) plus `get_the_excerpt()` already used on the card (line 47). No new field. The detail page uses the full body; the card uses the excerpt.

Everything else the request names is net-new. The design principle throughout, per this ecosystem's standing conventions: use WordPress's own primitives (taxonomies, `WP_Query`) wherever they already do the job, add postmeta only for the genuinely course-specific scalars, invent no new table.

## 2.2 New fields — each decided concretely

**Instructor — a real WP user reference (`_bhc_instructor_id`, user ID), not free text.** `bh-streaming` keeps `bhs_track`'s artist as plain text *for a stated reason that does not apply here*: a track from an aggregated external feed "has an artist name that's just descriptive text, not necessarily anyone with a local account at all" (`class-post-types.php` lines 5-8). Courses have no aggregation path — every course is locally authored by a real person who has (or should have) a real account, and this ecosystem has real user accounts plus CRM integration (`bh-crm`). A user reference buys the instructor's real display name, avatar, and a future "all courses by this instructor" link for free, and interoperates with `bh-crm`. Store as an integer postmeta `_bhc_instructor_id`; render via `get_userdata()`. Graceful fallback: if unset, fall back to the post author (`post_author`), which every post already has — so this is enhancing an existing signal, not requiring a new mandatory one.

**Difficulty — a fixed enum in postmeta (`_bhc_difficulty`), not a taxonomy.** The distinction the ecosystem already draws: `bhs_genre` and `bhm_collection` are *taxonomies* because they are open, author-managed, many-to-many groupings whose term set grows over time (`class-post-types.php` lines 13-17; `class-storefront.php` lines 18-25). Difficulty is a *closed, fixed set of three* (`beginner`/`intermediate`/`advanced`) that authors pick from, never extend, and a course has exactly one of. That is a scalar enum, not a folksonomy — postmeta with a `<select>` in the metabox, sanitized against a hardcoded whitelist (the same `in_array($key, $known_keys, true)` guard `save_course()` already uses for the benefit key, `class-admin.php` lines 104-106). A taxonomy here would be over-machinery for a three-value dropdown.

**Duration — computed, not author-entered (with an optional override).** The data already exists: `BHC_Steps::count()` and `BHC_PostTypes::lesson_order()` already let `course_percent()` walk every step of every lesson (`class-progress.php` lines 104-117). "Estimated length" derived from lesson-count (and/or step-count, and/or a per-step-type minute heuristic) is computable with the code already present, and a computed value never goes stale when an author adds a lesson — an author-entered "3 hours" silently lies the moment the course grows. Recommend: display a computed "N lessons" (dead simple, always accurate) as the primary duration signal, with an **optional** `_bhc_duration_note` free-text override postmeta for an author who wants to state "~4 hours of video" explicitly. Computed-first, override-optional — the same "compute the honest default, let the author override deliberately" posture used elsewhere.

**Category / tags — real WordPress taxonomies, following `bhs_genre`/`bhm_collection` precedent.** Register `bhc_course_category` (hierarchical, like WordPress core categories — "Music Production" > "Mixing") and optionally `bhc_course_topic` (flat tags). This is the *identical* decision `bh-streaming` made for `bhs_genre` ("a plain WP taxonomy... deliberately just the built-in taxonomy system, not a custom admin UI, since WordPress already has a perfectly good tag-style manager for this," `class-post-types.php` lines 13-17) and `bh-monetization-woo` made for `bhm_collection` ("Deliberately a taxonomy, not a new CPT/table... reusing WordPress's existing, well-understood taxonomy machinery costs nothing and interoperates for free," `class-storefront.php` lines 18-25). The term-management admin UI, the `tax_query` filtering, the term archives, and REST exposure all come for free. Register with `'show_in_rest' => true` matching both precedents. This is the single clearest "don't reinvent" call in the catalog work — WordPress ships exactly this.

## 2.3 "Popular" sort — count `bhc_enrollments`, invent no counter

The popularity signal already exists as a real table. `bhc_enrollments` gets one `INSERT IGNORE` row the first time a student gains real access to a course (`class-progress.php` lines 146-153; schema `class-activator.php` lines 70-78, `UNIQUE KEY user_course`). `COUNT(*) ... GROUP BY course_id` over that table is a real, deduped, non-gameable enrollment count per course — exactly the "authoritative server-authored count, never a gameable client counter" posture `bhm_play_log`'s docblock draws against `_bhs_play_count` (`class-products.php` lines 322-324).

**Recommend enrollment count as the "popular" signal**, not completion count. Enrollment is the standard meaning of catalog "popularity" (how many people started), it's a larger and earlier signal (a brand-new-but-buzzy course has enrollments before anyone finishes), and `bhc_completions` (also present, lines 89-98) is better reserved for a future "most-completed"/"highest finish-rate" facet if ever wanted. Both tables already exist; neither needs a new column. Implementation: a small `BHC_Progress::enrollment_counts()` (or a `$wpdb` `GROUP BY` in the catalog query builder) returning `course_id => count`, used to order the catalog list. Do **not** add a denormalized `_bhc_enrollment_count` postmeta counter — that reintroduces exactly the "two sources of truth that can drift" hazard the ecosystem avoids; the `COUNT` is cheap at this scale and always correct.

## 2.4 Search — `WP_Query`'s own `s` param is enough for v1

`render_catalog()` today is a bare `get_posts(['post_type' => 'bh_course', ...])` (`class-render.php` line 35). Turning it into a `WP_Query` and threading through the request's `s` (keyword), `tax_query` (category/topic filter), and `orderby` (see 2.5) covers keyword search, filtering, and sorting with the query API WordPress already ships. `WP_Query`'s `s` searches title and content, which for a course catalog (titles + descriptions) is precisely the surface a v1 keyword search should hit.

Building anything heavier — a custom search index, relevance ranking, fuzzy matching — is unwarranted here and cuts against the ecosystem's standing "don't build what already exists" rule (the same rule that kept `bhs_genre` on core taxonomy and `BH_Studio` on Gutenberg primitives rather than GrapesJS). The course corpus is small (a single artist's course library, not a marketplace), and `WP_Query s` is the honest right-sized tool. Flag the ceiling explicitly so it's a known boundary, not an accident: if the catalog ever grows to hundreds of courses where `s`'s `LIKE`-based matching feels weak, *that* is when to reach for something more — and the natural more, consistent with `EVENT-TRACKING-ARCHITECTURE-PLAN.md`'s "own equivalent" posture, is still not a paid third-party search service. Not a v1 concern.

## 2.5 Sort — newest / alphabetical / popular

- **Newest** — `orderby => 'date'`, `order => 'DESC'` (native `WP_Query`).
- **Alphabetical** — `orderby => 'title'`, `order => 'ASC'` (native).
- **Popular** — cannot be expressed as a `WP_Query` `orderby` because the signal lives in `bhc_enrollments`, not postmeta. Two honest options: (a) a `posts_clauses`/`posts_join` filter joining the enrollments count into the main query's `ORDER BY`, or (b) query the enrollment counts first (2.3) and pass the resulting ordered ID list as `post__in` + `orderby => 'post__in'`. Recommend **(b)** for a small catalog — it's a plain two-step (`GROUP BY` for the order, then a normal `WP_Query`), far easier to read and reason about than a raw-SQL `JOIN` filter hook, and the catalog size makes the two-query cost irrelevant. (a) is the answer only if the catalog ever gets large enough that materializing the full ID list first is wasteful — noted, not needed now.

## 2.6 Ratings / reviews — deferred, explicitly, as a flagged follow-up

**Out of scope for this pass. Do not half-build it.** Ratings/reviews are a genuinely separate feature with their own real surface area: a new storage table (one row per user per course review — an append/edit model, closer to `bhcore_events`/`bhm_play_log` audit shape than to the latest-state tables), a submission UI gated on enrollment/completion, moderation (this ecosystem already has a moderation/reports pattern — `bhi_reports`, referenced in `EVENT-TRACKING-ARCHITECTURE-PLAN.md` line 19), aggregate-rating computation and display, and the spam/abuse considerations any user-generated-content feature carries. Shipping a star widget without the moderation and storage behind it would be exactly the "build half of it" the request warns against.

Flag it as a clean, named follow-up: **"Course ratings & reviews"**, a future pass, depending on nothing in this one (it would read `bhc_enrollments`/`bhc_completions` to gate who may review, both of which already exist). The catalog and detail-page work below should leave an obvious seam for it (the detail page's layout below has a natural slot beneath the description) but render nothing there until that pass happens.

## 2.7 The detail page — enhance the existing single-course view, wrapping `render_course()`

There are two distinct jobs and the code already cleanly separates them, so the detail page should **coexist with and wrap** the existing lesson-list rendering, not replace it:

- **Browse-and-decide** — the catalog grid (`render_catalog()`) and a course's public landing/detail view: cover, full description, instructor, difficulty, duration, syllabus preview, enrollment CTA. This is for someone deciding *whether* to take the course.
- **Actually-taking-the-course** — the lesson list with per-lesson progress/drip/lock state, the Continue CTA (`render_course()`, `class-render.php` lines 85-131). This is for an enrolled student *working through* it.

`render_course()` already is the second job, done well, and it's already hooked as the `[bh_course]` shortcode and used on the singular `bh_course` view. The detail page is not a new page and not a replacement — it's the **single-course view enhanced with a header region above the existing `render_course()` output**. Concretely, a `render_course_detail()` (or an enhancement to `render_course()`'s top) that prints: the cover, the full post body (not the excerpt), instructor (name/avatar via `_bhc_instructor_id`), difficulty badge, computed duration, category/topic terms, an enrollment/"Start" CTA — and then calls the existing lesson-list rendering beneath it as the syllabus/progress section. The existing `render_continue_cta()` (lines 63-81) already computes the right primary CTA ("Start"/"Continue"/"Review") and is reused verbatim. One page serves both audiences: a not-yet-enrolled visitor sees the pitch header + a locked/preview syllabus + "Start"; an enrolled student sees the same header + their live progress list + "Continue." `render_course()` is wrapped/extended, never duplicated — the lesson-list logic, gating, drip notices, and enrollment-on-view side effect (line 98) all stay in one place.

**Syllabus preview for locked/unenrolled visitors:** the lesson list already degrades correctly for a locked course (shows the paywall notice via `render_paywall_notice()`, line 103). For the browse-and-decide case a visitor should still see the *lesson titles* (the syllabus) even when locked, as a preview — a small addition to the locked branch rendering titles as plain non-links, which the existing loop already does for drip-locked lessons (lines 120-122).

## 2.8 Reusing the shared design system — the specific classes, and one real caveat

**Front-end catalog** reuses what `courses.css` and `BHY_Style` already establish, *not* `BHY_UI`'s classes — and this distinction is load-bearing, so name it precisely:

- `BHY_UI`'s design-system CSS/JS (`.bhy-card`, `.bhy-table-wrap`, `.bhy-badge`, `.bhy-table-search`, `.bhy-sortable`, `own-ur-shit/includes/class-ui.php`) is printed **only on ecosystem admin screens** — both `print_design_system_css()` and `print_design_system_js()` early-return unless the current `get_current_screen()->id` is a bh/ous admin screen (lines 245-257, 322-338). It is an *admin* design system. The public catalog is front-end; those classes are simply not present there.
- The front-end catalog's shared-design-system reuse is therefore **`BHY_Style`'s design tokens** — the `--bh-*` CSS variables already injected on the front end via `wp_add_inline_style('bhc-front', BHY_Style::inline_css())` (`class-render.php` line 24) — plus the existing `courses.css` card conventions (`.bhc-course-card`, `.bhc-progress-bar`, `.bhc-btn`, the theme-isolation reset, lines 26-89). New catalog UI (filter bar, sort dropdown, search input, difficulty badge, instructor row) should be built as new `.bhc-*` classes styled entirely off the `--bh-*` tokens, exactly as everything in `courses.css` already is — a difficulty badge reuses the token palette the same way `.bhc-quiz-result.bhc-pass` does; a filter/sort control bar reuses `--bh-surface`/`--bh-border`/`--bh-accent` like `.bhc-course-card`. This is "reuse the ecosystem's design-system conventions, don't invent new ones" applied honestly to a front-end surface: the shared thing on the front end is the *token layer*, not the admin component classes.
- **Front-end search/filter/sort interactivity** extends the existing vanilla-JS `courses.js` pattern (lines 1-114, no jQuery, no build step) — a plain `change`/`input` handler that re-submits the catalog query (or filters the rendered grid client-side for the small-catalog case), matching `courses.js`'s existing plain-DOM idiom, not `BHY_UI`'s admin `bhy-table-search`.

**Admin course management**, by contrast, *is* where `BHY_UI` applies. If this pass adds any admin catalog-management surface (e.g. richer columns on the `bh_course` list table — `course_columns()`/`course_column_content()` already exist, `class-admin.php` lines 266-283 — or an admin browse/filter view), that reuses `BHY_UI`'s `.bhy-table-wrap`, `.bhy-sortable` + `<th data-sort>`, and `.bhy-table-search[data-target]` (the zero-JS-to-write table behaviors documented at `class-ui.php` lines 226-244), and `.bhy-badge` for the difficulty chip in a column. Name the right system for the right surface: `BHY_Style` tokens on the front end, `BHY_UI` classes in admin.

---

# Part 3 — Risks, open questions, deferred items

Named honestly, in the "left alone, with reasoning" spirit of `EVENT-TRACKING-ARCHITECTURE-PLAN.md` and `LMS-AUTHORING-DESIGN-PLAN.md`'s own risk sections.

- **The `answers` snapshot bloats the row for large quizzes.** A 40-question quiz snapshot is 40 question texts + choice lists in one `longtext` cell on the same row read by the hot progress paths. Mitigated because those paths (`completed_steps()`, `is_step_complete()`, `step_status()`) either `SELECT step_index, passed` explicitly (line 55) or read a single row by unique key — none scan `answers`, and none run in a loop large enough for the cell size to matter. But `step_status()` does `SELECT *` (line 30-32), so any caller of it now pulls the blob whether it needs it or not. Low-stakes (single-row lookup), but if a hot path is ever found calling `step_status()` in a loop, it should switch to an explicit column list. Flagged, not blocking.
- **Snapshot vs. live-quiz divergence is the intended behavior, and must be documented as such.** After the answers column ships, a past attempt's review shows the quiz *as it was when taken*, which can differ from the current quiz an author has since edited. This is correct (Section 1.3) but counterintuitive to an author who edits a question and wonders why old reviews "still show the old wording." Note it in the column's own docblock the way this codebase documents every such deliberate-surprise (e.g. the once-passed-freeze comment).
- **`bhc_progress` still has no per-attempt history — by design, and that's a ceiling.** The recommended column keeps only the latest/passing attempt. The moment a real gradebook/audit requirement appears, it needs the separate append-only `bhc_quiz_attempts` table (Section 1.2), which is a new feature, not an extension of this column. Called out so "we can already review answers" is never mistaken for "we have an attempt history."
- **No quiz-schema version field.** Same gap `LMS-AUTHORING-DESIGN-PLAN.md` (Section 6) and `EVENT-TRACKING-ARCHITECTURE-PLAN.md` (lines 30-31) already flag on `BH_Content`: a stored answers snapshot has no `(type, v)` to key on if the `bhc/quiz-question` schema later grows true/false or short-answer question types. The snapshot's self-contained shape (storing the actual choices, not a type discriminator) softens this — a multiple-choice snapshot renders fine regardless — but a future question *type* would want a `"qtype"` field per question in the blob. Retrofit when the second question type ships, not now.
- **Taxonomy registration needs a rewrite flush.** Registering `bhc_course_category`/`bhc_course_topic` (and any archive rewrite) is invisible until rewrite rules flush — the exact 404-class bug `BHM_Storefront::add_rewrite()` (lines 95-117) and `BHI_Portal` already hit and solved with a versioned one-time flush. Reuse that pattern verbatim (a `bhc_taxonomy_rewrite_flushed` option keyed to a version constant); do not flush on every `init`.
- **Instructor as a user reference has an orphan case.** If `_bhc_instructor_id` points at a user who is later deleted, `get_userdata()` returns false — the render must fall back (to `post_author`, or hide the row) rather than fatal. Trivial, but a real null-guard the render code must carry.
- **Ratings/reviews deliberately deferred** (Section 2.6) — a named future pass, half-built by nobody.
- **Popular-sort at scale** (Section 2.5) — the `post__in` approach is right for a small catalog and explicitly not for a large one; the `posts_clauses` JOIN is the documented upgrade path if that day comes.

---

# Part 4 — Suggested sequencing

The two features are **independent and can proceed in parallel.** The one place they could have collided is the catalog's "popular" sort possibly needing something from the quiz work — it does not: popularity reads `bhc_enrollments` (Section 2.3), which already exists and has nothing to do with quiz answers. The detail page reads `render_course()` and the new catalog fields, also nothing from quiz storage. The only shared file either touches is `class-activator.php`, and only Part 1 touches it (a `DB_VERSION` bump for the `answers` column); Part 2 adds taxonomies + postmeta and needs no schema change, so there's no `DB_VERSION` contention. They can be built, reviewed, and shipped in either order or simultaneously.

**Part 1 (quiz answers) internal order:**
1. Add the `answers` column: `DB_VERSION` -> `'1.2'`, the `dbDelta` column line, changelog note (`class-activator.php`).
2. Extend `score_quiz()` to return per-question detail from its existing loop; extend `mark_step_complete()` with the optional `$answers_json` param (conditional-NULL placeholder); persist from `ajax_submit_quiz()` (`class-steps.php`, `class-progress.php`).
3. Return the per-question detail in the AJAX submit payload; render the end-of-submission per-question breakdown and correct/incorrect visual states in `courses.js` + `courses.css`.
4. Render the stored snapshot as a static review on passed-quiz revisit in `render_step()` — closing the gap the `class-render.php` lines 276-283 comment flags.

**Part 2 (catalog) internal order:**
1. Register `bhc_course_category`/`bhc_course_topic` taxonomies + versioned rewrite flush (`class-post-types.php`).
2. Add instructor/difficulty/duration-override metabox fields + sanitized save (`class-admin.php`).
3. Rebuild `render_catalog()` as a `WP_Query` with `s` search, `tax_query` filter, and newest/alpha/popular sort (popular via `bhc_enrollments` count + `post__in`); add the front-end filter/sort/search bar as `.bhc-*` classes on `--bh-*` tokens + a `courses.js` handler (`class-render.php`, `courses.css`, `courses.js`).
4. Enhance the single-course view into the detail page: header region (cover/full description/instructor/difficulty/duration/terms/CTA) wrapping the existing `render_course()` lesson list, with a locked-course syllabus-title preview (`class-render.php`).
5. (Optional, admin) Enrich the `bh_course` list table with a difficulty `.bhy-badge` and `BHY_UI` sortable/searchable columns (`class-admin.php`).

Ratings/reviews is a separate future pass after both (Section 2.6), and the per-attempt gradebook table is a separate future pass after Part 1 (Section 1.2) — both explicitly deferred, neither started.

---

# Critical files

- `bh-courses/includes/class-activator.php` — the `bhc_progress` schema and `DB_VERSION`/`dbDelta` migration the `answers` column is added to (Part 1); the enrollment/completions tables the "popular" sort reads (Part 2).
- `bh-courses/includes/class-progress.php` — `mark_step_complete()`/`ajax_submit_quiz()` (persist the answer snapshot, once-passed-freeze) and `bhc_enrollments` (the popularity signal).
- `bh-courses/includes/class-steps.php` — `score_quiz()`, where the per-question snapshot is assembled from the loop that already computes correctness.
- `bh-courses/includes/class-render.php` — `render_catalog()` (search/filter/sort rebuild), `render_course()` (the detail-page wrap), `render_step()` (the passed-quiz review), and the front-end enqueue of `BHY_Style` tokens.
- `bh-courses/includes/class-post-types.php` + `class-admin.php` — where the `bhc_course_category`/`bhc_course_topic` taxonomies register and the instructor/difficulty/duration metabox fields live; `bhs_genre`/`bhm_collection` are the taxonomy precedent.
- `own-ur-shit/includes/class-ui.php` (`BHY_UI`) and `BHY_Style` — the shared design systems to reuse: `BHY_Style` `--bh-*` tokens on the front-end catalog, `BHY_UI` `.bhy-table-wrap`/`.bhy-sortable`/`.bhy-badge` for any admin course-management surface.
