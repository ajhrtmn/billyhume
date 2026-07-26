# bh-courses — MAGICAL UX Audit

- **Scope:** `bh-courses` user-facing surfaces only — course catalog (search/filter/sort), the course-taking flow (enrollment orientation, lesson step-walker incl. the new `callout`/`checklist`/`chord-chart`/`audio-compare` types, quiz/retry, completion screen), achievements/leaderboard display, and the My Courses portal panel. UX only — code-quality was a separate task.
- **Date:** 2026-07-25
- **Model:** claude-opus-4-8
- **Method / caveat:** Code-level read only. No live WordPress install or browser was available, so this critiques the templates, render methods, JS interaction affordances, and CSS that *produce* each screen — not a rendered walkthrough. Findings about visual weight, spacing, and "how it feels" are inferred from markup + CSS and should be spot-checked live before acting on the cosmetic ones. Every finding cites the file:line that emits the surface.

---

## EMPTY-STATE RE-CHECK (07-13 flagged issue) — VERDICT: mostly fixed, one gap remains

**Fixed.** The catalog empty state is no longer a bare one-liner. `class-render-catalog.php:107-121` now branches on a real `$is_filtered` distinction and renders the shared `BHY_Style::empty_state_html()` component (`own-ur-shit/includes/class-style.php:1023`) — icon, distinct title/description per reason, and a **Clear filters** action on the filtered side (`clear_url`, line 120). This is a proper shared component, not a one-off, and it degrades gracefully to the old `<p>` when `BHY_Style` is absent. The systemic three-plugin pattern is genuinely addressed here.

**Remaining gap:** the *zero* case ("No courses published yet") passes **no `cta_label`/`cta_url`** (lines 116-121), so an admin looking at a truly empty catalog still gets no "Create your first course" prompt — the exact secondary point the 07-13 audit raised. The component fully supports a CTA (`class-style.php:1063-1068`); the catalog just doesn't feed it one. Low-effort, high-polish fix: when `current_user_can('edit_posts')` and reason is `zero`, pass `cta_label => 'Create your first course'`, `cta_url => admin_url('post-new.php?post_type=bh_course')`.

---

## Course catalog (`class-render-catalog.php`, `templates/archive-bh_course.php`)

**Good**
- Course cards are genuinely rich, not just title+link: thumbnail, difficulty badge, lesson count, star rating with count, instructor avatar+name, excerpt, and — for enrolled users — a live progress bar + a context-aware Start/Continue CTA (`:143-164`). That's a strong catalog card.
- The continue CTA is the *shared* `BHC_Render_Course::render_continue_cta()` (`:163`), so the card's "where do I go next" decision can't drift from the course page's.
- Filters degrade to a zero-JS GET form and preserve unrelated query args as hidden fields (`:180-186`) — solid progressive enhancement.
- Sensible sorts (Newest / A–Z / Most popular / Highest rated) with courses that have no reviews/enrollments sorting last rather than vanishing (`:45-64`).

**Rough**
- **Pagination is prev/next only** (`render_pagination`, `:215-229`) — "Page 2 of 7" with no numbered jumps. Fine for a handful of courses; starts to feel thin the moment the catalog is deep enough to actually need sorting by "most popular." Not urgent.
- The **Filter submit button** (`:210`) is the fallback path; the "magic" (auto-submit on change) lives in `courses.js`. Verify live that with JS on, the Filter button is hidden/redundant — a visible submit button next to selects that already auto-submit is a small "which do I trust" wart.

---

## Enrollment orientation screen (`class-render-course.php:484-511`)

**Good**
- The right idea: a one-time "You're in / Here's what's ahead" moment with eyebrow, title, course description excerpt, the real syllabus, and a drip-aware **Start →** that uses `first_incomplete_lesson()` so it never links to a not-yet-open lesson on day one (`:505-507`). This is exactly the Disneyland-queue framing.

**Rough — the headline finding for this surface**
- **The orientation screen and the ordinary lesson list render on the same page, one directly above the other, showing the same syllabus twice.** In `render_course()` the just-enrolled path emits the orientation screen (`:176`) and then falls straight through to the progress bar + `render_grouped_lesson_list()` (`:192-202`). The comment at `:172-176` claims orientation shows "*instead of* dropping straight into the ordinary view" — but the code renders it *in addition to* that view. A first-time enrollee sees: orientation syllabus (`bhc-orientation-syllabus`) → 0%-complete progress bar → the identical lesson list again. The duplicate syllabus undercuts the "special moment" feeling. Either suppress the ordinary progress/lesson-list block on the `$just_enrolled` request, or drop the syllabus `<ol>` from the orientation card and let the real list below own it.

---

## Lesson step-walker (`class-render-lesson.php`, `courses.js`)

**Good**
- Persistent breadcrumb back to the course + "Lesson N of M" rendered unconditionally (`:73-80`) so a student can bail mid-lesson — a real prior gap closed.
- Persistent lesson sidebar with course-level progress inside the lesson (`:94-96`), so no more round-trip to the course page to jump lessons.
- The visual stepper (`:114-130`) type-tags every step with a glyph, marks done/current, and only enables dots up to the reachable step — with matching `aria-label`/`aria-current`/`title`. Accessibility here is unusually careful (aria-live step counter `:105`, programmatic focus moves on step change `courses.js:133`).
- **The "transition beat" is real and well-judged** (`courses.js:175-179`): a 450ms pause after `markStepDone()`'s dot pulse before `advance()` swaps content, explicitly skipped under `prefers-reduced-motion`. This is the kind of "let the acknowledgment be felt" touch the pass was going for, and it respects motion prefs.
- Confetti on completion (`courses.js:80-105`) is `prefers-reduced-motion`-gated and fires on both the live last-step moment and a page-load-onto-completion revisit.
- Resume-where-you-left-off (`:44-49`) and a last-step reveal of Next Lesson / completion instead of stranding the student (`courses.js:181-208`).

**Rough**
- **`chord-chart`** (`:407-413`) reads correctly: `esc_html()` inside `<pre>` with CSS `white-space: pre; overflow-x: auto` (`courses.css:430-432`), so alignment is preserved and a wide chart scrolls rather than breaking the layout. Confirmed good — no issue. One nit: the stepper glyph for it is the literal letter `C` (`courses.css:287`), which reads as "the C chord" more than "chord chart" — a tiny semantic mismatch, harmless.
- **`audio-compare` interaction discoverability is weak** (`:414-424`). It renders as two independent stacked native `wp_audio_shortcode` players with a label each (`courses.css:437-440`, `audio { width:100% }`). There is **no synchronized A/B toggle, no "listen to both and compare" instructional prompt**, and the only signal that these two clips are meant to be *compared* rather than just two attached files is the pair of labels. The `caption` field is optional and author-supplied, so the comparison framing isn't guaranteed to appear at all. For an A/B ear-training moment (very on-brand for a music LMS) this is the least "magical" of the new types — a student can play A, then B, but can't quickly switch mid-playback. Consider at minimum a fixed helper line ("Play each and compare") baked into the template, and ideally a single toggle that swaps source on one player at the same timestamp.
- **`callout` warning variant** uses a hardcoded `#b32d2e` / `rgba(179,45,46,...)` red (`courses.css:414`) rather than a design-system token like the tip/note variants (which use `--bh-accent`/`--bh-text-dim`). Minor design-system deviation worth normalizing to a token.
- The **video "watch X% to complete"** note is good, but the manual mark-complete button is `display:none` for threshold videos (`:308-309`) — if the timeupdate listener never attaches (e.g. an edge-case media type), a student could be stuck with no way to advance. Low probability, but there's no manual escape hatch on that one path.

---

## Quiz / retry flow (`class-render-lesson.php:313-372`, `render_quiz_review` :441-464)

**Good**
- Retry framing is handled with dignity: "N of M attempts remaining," an explicit "✓ You already passed this quiz" note, and inputs disabled once passed/exhausted (`:357-364`). Result region is `role=status`/`aria-live` so screen-reader users hear the score (`:365-370`).
- Revisiting a passed quiz renders the **real stored snapshot** breakdown (`:327-330`), marking the student's answer and the correct one, with a graceful pre-migration fallback. The end-of-submission (not per-question) review model is the right anti-gaming choice.
- Optional question/choice shuffle is display-only, keeping scoring indices stable (`:343-350`).

**Rough**
- The task brief mentioned retry copy "reframed as checkpoints." I don't see the word "checkpoint" anywhere in the rendered quiz copy — it still says "quiz," "attempts," "Submit answers," "You passed this quiz" (`:358-461`). Either that reframing didn't land in this file, or it lives only in admin/help copy. If the intent was student-facing "checkpoint" language to lower stakes, it's **not present on the actual quiz screen** — verify where it was supposed to go.
- No "why this was wrong" explanation field surfaces in the review — only correct/incorrect marking (`:454-457`). Fine for v1, but an optional per-question rationale would be the magical version.

---

## Course-completion screen (`class-render-course.php:414-474`)

**Good**
- This is the strongest single surface in the pass. Trophy, "Course complete — with distinction!" conditional title, a subtitle naming the course, and a real **stats row** (lessons completed, quizzes passed, days start-to-finish) built entirely from already-tracked data (`:439-448`).
- Certificate + share-image promoted to primary/secondary buttons, and — the genuinely good call — **the share card is embedded inline** (`:463-464`), not hidden behind a link, because a share card's whole point is to be seen.
- Distinction tier is one shared threshold filter (`:425-426`) reused by certificate + achievements, so the three can't drift.
- Single source of truth: the lesson-final-step reveal delegates to this same method with `$assume_complete=true` (`class-render-lesson.php:173`), so the live moment and the revisit are identical content. Good architecture-as-UX.

**Rough**
- **The "Get share image" link opens a raw PNG in a new tab** (`:456`, `target=_blank`) with no `download` attribute and no copy-link affordance. On desktop the student lands on a bare image and must right-click→save or manually copy the URL. The inline preview softens this, but the *action* button is a weak payoff for the "share your achievement" moment — consider a `download` attribute and/or a "Copy link" helper.
- Completion "next steps" is a single text row ("Browse more courses · Leave a review", `:467-471`) sitting under a celebratory card — visually underweight relative to the moment. Not wrong, just a little quiet.
- The share-card URL embeds the raw `user_id` as a query param (`class-share-cards.php:25-30`); by design the card is public, so this is acceptable, but worth being aware it's a guessable enumeration surface (out of scope for UX, flagging for the record).

---

## Achievements + leaderboard display

**Good**
- **Leaderboard** (`class-leaderboard.php:69-93`): opt-in per course, obvious-or-gone (never an empty "Top Scorers" heading), proper competition ranking (1,1,3), medal emoji for top 3, and ranked by the *same* `course_quiz_average()` shown everywhere else so a student sees one consistent number. Deleted accounts drop off. Clean.
- **Achievements** are a small fixed set with human labels + descriptions (`class-achievements.php:21-25`), awarded off already-existing events, and surfaced both on the portal panel and via the ecosystem `bhi_profile_badges` filter.

**Rough**
- **The achievements badge row on the portal panel has no label/heading** (`class-portal-panel.php:62-76`). It renders a bare `<div class="bhi-portal-achievements">` of pills directly under the `<h1>My Courses</h1>` and above the course list (`:88-95`). A first-time viewer sees a floating cluster of pills with no "Achievements" / "Your badges" caption — the meaning lives only in per-badge `title` tooltips (`:71`), which are invisible on touch devices. The pills *are* styled by the host (`own-ur-shit/includes/class-portal.php:722-724`), so this is purely a missing one-line heading. Easy magical upgrade: add an "Achievements" sub-heading and, if you want it to shine, the count.
- Leaderboard shows *quiz* standing only. A student who's enrolled but hasn't hit a quiz simply isn't listed (correct, obvious-or-gone) — but there's no "attempt a quiz to appear here" nudge for the viewer looking at a board they're absent from. Minor.

---

## My Courses portal panel (`class-portal-panel.php`)

**Good**
- Real empty state with icon, message, and a "Browse courses →" CTA (`:98-104`) — not a dead end.
- Lists only actually-enrolled courses with a live percent, Completed marker, and a Continue/Review button per card (`:107-123`).
- The user-bar quick-link is obvious-or-gone: one link to the single most-relevant in-progress course, nothing when there's nothing to resume (`:26-45`).

**Rough**
- Course cards here show percent + Continue but **no thumbnail, difficulty, or "next lesson" name** — thinner than the public catalog card, and the "Continue" button links to the course *page* (`:120`), not directly to the next lesson (unlike the user-bar link at `:38-39`, which does jump straight to `first_incomplete_lesson()`). Inconsistent: the quick-link is smarter than the panel it lives beside. Point the panel's Continue button at the next lesson too.

---

## Confirmed good (no action needed)

- Catalog empty state is a real shared component with filtered/zero distinction + Clear-filters (`class-render-catalog.php:107-121`).
- `chord-chart` alignment + horizontal scroll (`class-render-lesson.php:412`, `courses.css:430-432`).
- Transition beat respects `prefers-reduced-motion`; confetti does too (`courses.js:175-179`, `:83`).
- Step-walker accessibility: aria-live counter, programmatic focus on step change, reachable-only stepper dots, aria-current/label (`class-render-lesson.php:105-128`, `courses.js:133`).
- Quiz retry dignity + stored-snapshot review + aria-live result (`class-render-lesson.php:327-370`, `:441-464`).
- Completion screen as single source of truth shared with the lesson last-step reveal (`class-render-course.php:414`, `class-render-lesson.php:173`).
- Leaderboard obvious-or-gone + consistent scoring + competition ranking (`class-leaderboard.php:69-93`).
- Drip-aware Start/Continue never links to a locked lesson (`render_continue_cta` :374-392, orientation :505).

---

## Prioritized punch-list

| # | Pri | Surface | Issue | Fix | Ref |
|---|-----|---------|-------|-----|-----|
| 1 | High | Orientation | First-enroll page renders orientation syllabus AND the ordinary lesson list — same syllabus shown twice | Suppress the ordinary progress/list on `$just_enrolled`, or drop the syllabus `<ol>` from the orientation card | `class-render-course.php:176,192-202,494-500` |
| 2 | Med | audio-compare | No compare affordance/prompt — just two stacked players; the "A/B" intent isn't discoverable | Bake in a fixed "Play each and compare" line; ideally a single same-timestamp A/B toggle | `class-render-lesson.php:414-424` |
| 3 | Med | Catalog empty (zero) | Truly-empty catalog gives admins no "Create your first course" CTA (component supports it, unused) | Pass `cta_label`/`cta_url` for `edit_posts` users on the zero branch | `class-render-catalog.php:116-121` |
| 4 | Med | Quiz | Brief mentioned "checkpoint" reframing; not present in student-facing quiz copy | Confirm where it was meant to land; apply if intended | `class-render-lesson.php:358-461` |
| 5 | Med | Portal panel | Achievement badge row has no heading; meaning hidden in tooltips (invisible on touch) | Add an "Achievements" sub-heading | `class-portal-panel.php:62-76` |
| 6 | Low | Portal panel | Continue button links to course page, not next lesson (quick-link already does the smarter thing) | Point at `first_incomplete_lesson()` | `class-portal-panel.php:120` |
| 7 | Low | Completion | "Get share image" opens raw PNG new tab, no download/copy affordance | Add `download` attr and/or Copy-link helper | `class-render-course.php:456` |
| 8 | Low | callout | Warning variant hardcodes `#b32d2e` instead of a token | Normalize to a design-system color var | `courses.css:414` |
| 9 | Low | Catalog | Prev/next-only pagination; redundant Filter button alongside auto-submit | Numbered pages when deep; hide submit when JS auto-submits | `class-render-catalog.php:210,215-229` |
