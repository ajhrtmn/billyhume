# Roadmap: instructor/student depth for BH Courses — scheduling, progress reporting, homework, grading

Research pass, not a build ticket. Scoped against what's actually in the codebase today (this session added `class-instructor-notes.php` and `class-progress-admin.php`'s Student Progress screen — both referenced below rather than re-described) plus the prior open threads in `ROADMAP-feedback-and-courses-v2.md` and `ecosystem-depth-pass-2026-07.md`. One explicit constraint carried over from those docs and honored throughout: **instructor-graded work reuses whatever reviewer-queue mechanism `bh-feedback` shipped** (`BHF_Queue`'s self-serve claim queue, gated entirely on `bhcore_review_submissions` — see `bh-feedback/includes/class-queue.php`), not a second review-queue implementation. Nothing here proposes a second one.

## What already exists (so the rest of this doc doesn't re-propose it)

- `bhc_progress` table (user_id/lesson_id/step_index, keyed) — per-step completion, score, passed, attempts, answers snapshot. `BHC_Progress` (`bh-courses/includes/class-progress.php`).
- `bhc_enrollments` / `bhc_completions` tables — enrollment timestamp and course-completion event (`bhc_course_completed` action + `bhc/course_completed` `BH_Event`).
- Admin "Student Progress" screen (`class-progress-admin.php`) — per-course table (student × lesson matrix), aggregate summary (completion rate, avg quiz score per lesson), a 14-day "stalled student" flag, and a manual-override "mark complete" tool. Gated on `bhcore_manage_students`.
- Private instructor notes — one note per LESSON (not per student), plain postmeta (`_bhc_instructor_note`), admin-only metabox (`class-instructor-notes.php`).
- Lesson Q&A/comments — WP core comments on `bh_lesson`, opt-in per course, visibility gated through the same `BHC_Gate::user_can_access_lesson()` rule as lesson content itself (`class-comments.php`).
- Achievements, certificates-adjacent completion hook (`bhc_course_completed`), leaderboard, portal panel ("My Courses" — `class-portal-panel.php`) showing enrolled courses + percent + Continue CTA.
- `BHF_Queue` (bh-feedback) — the settled reviewer-claim-queue shape: `_bhf_status` (open/claimed/completed), `_bhf_reviewer_id`, atomic claim() via a single-row conditional UPDATE, `bhcore_review_submissions` gating claim/complete/queue-visibility as one bundle.

## 1. Scheduling — 1:1 lessons, recurring sessions, calendar view

### Smallest real version
A single **availability + booking** model, not a full calendar app: an instructor publishes a small number of open time slots; a student books one against a specific course/lesson (or just "a session with this instructor," unscoped to a course). No recurrence engine, no external calendar sync (Google Calendar/iCal) in v1 — that's the single biggest scope trap here (OAuth, two-way sync, timezone edge cases) and isn't worth it before proving anyone wants slot-booking at all.

Genuinely smallest useful version: instructor enters a handful of slots (start datetime + duration) in wp-admin; student sees open slots on a portal panel and clicks to claim one (same atomic-claim idiom as `BHF_Queue::claim()` — a slot's status flips `open -> booked` via a single-row conditional UPDATE, so two students can't double-book the same slot). No recurring-series generator in v1 — an instructor who wants "every Tuesday at 4pm for 8 weeks" creates 8 slots up front (a "generate N repeating slots" convenience button is a fine v1.1 UI sugar over the same table, not a different data model).

### Data model
New table, not postmeta — this needs querying across students/instructors/date ranges the way `bhc_progress` does, and a slot's lifecycle (open → booked → completed/cancelled) is exactly the kind of small state machine this ecosystem already puts in a table (`bhf_...`-style) rather than scattered post meta.

`bhc_sessions`: `id, instructor_id, student_id (nullable until booked), course_id (nullable — a session doesn't have to be tied to a course), starts_at, duration_minutes, status ('open'|'booked'|'completed'|'cancelled'), notes, created_at`. Booking is the same one-row-conditional-UPDATE pattern as `BHF_Queue::claim()`.

### UI surface
Both. Admin: a plain "Sessions" submenu under `BHC_PostTypes::MENU_PARENT` (same sibling-of-Courses/Lessons placement `class-progress-admin.php` already uses) — instructor creates slots, sees who booked what, marks a session completed/cancelled. Portal: a new panel (`bhi_portal_panels` filter, same contract `BHC_PortalPanel::register_panel()` already uses) showing a student's own upcoming sessions and a "book a slot" list of what's open. A calendar-GRID visualization (month view) is a legitimate v1.1 on top of this table — the v1 slot list is a plain sortable table, which is honestly plenty for what's likely a handful of slots a week for a solo-instructor site.

### Open questions for AJ
- Is this scoped to Billy Hume as the one instructor booking 1:1s with students, or does it need to support multiple named instructors from day one? (Affects whether `instructor_id` needs its own selector UI or can default to "whoever has `bhcore_manage_students`.")
- Does a booked slot need a reminder email/notification (`OUS_Notifications`), or is the portal panel itself sufficient for v1?
- Should a session ever be tied to a specific `bh_course`/`bh_lesson` (e.g. "office hours for Module 3"), or is it always instructor-level, unscoped to any course?
- Cancellation/reschedule policy (can a student self-cancel? how close to start time?) — a real product decision, not an architecture one, but worth deciding before the booking UI locks in.

## 2. Progress and cohort reporting — beyond the existing admin table

### Smallest real version
`class-progress-admin.php` already does the hard part (batched matrix query, stalled-student flag, per-lesson completion rate + quiz average). What's missing for a real "who needs attention across ALL my courses" workflow, not just one course at a time:

1. **Cross-course "at a glance" dashboard** — one row per student, aggregated across every course they're enrolled in (not the current per-course-only view), surfacing the same stalled-flag logic already in `BHC_ProgressAdmin::STALLED_DAYS`/`render_summary()` but rolled up. This is genuinely the cheapest add here: it's the exact same `BHC_Progress::students_for_course()`/`course_progress_matrix()` calls, just iterated over every course an instructor has instead of one selected from a dropdown.
2. **CSV export** — a "Download as CSV" button on the existing Student Progress table (and the new cross-course view). Trivial: the render methods already build the exact rows needed; this is a content-type-switch on the same data, not new reporting logic.

### What NOT to build yet
A generic "reports builder" (pick any metric, any grouping, save a custom report) is enterprise-LMS bloat this platform doesn't need — the existing per-course table plus a cross-course rollup plus CSV export covers "at-a-glance who's behind" and "hand a report to someone" without inventing a reporting engine.

### Data model
None new — this is 100% additive UI/aggregation over `bhc_progress`/`bhc_enrollments`/`bhc_completions`, exactly the posture `course_progress_matrix()`'s own docblock already establishes (batch once, aggregate in PHP).

### UI surface
Admin only. Either a new top-level view on the existing "Student Progress" submenu (a toggle: "This course" / "All courses") or a second submenu ("All Students") — the former is less menu clutter and reuses the existing page's course-selector affordance as a natural "or view everything" escape hatch.

### Open questions for AJ
- Is a cross-course view actually needed, or does AJ only ever run one course at a time in practice (in which case this is much lower priority than it looks on paper)?
- CSV export scope: just the per-course table as it renders today, or does AJ want quiz-answer-level detail (individual question misses) in the export too?

## 3. Assigning homework

### Smallest real version
A new **assignment** step type (extends `BHC_Steps::VALID_TYPES`, following the exact same shape `resource`/`callout`/`checklist` steps already established — a step is a plain assoc array in `_bhc_steps`, sanitized in `BHC_Steps::save()`) OR a lightweight standalone `bh_assignment` concept independent of the step-array model. Recommend the **step type**, not a separate CPT: an assignment is fundamentally "a thing a student does as part of a lesson," which is exactly what the step-array model already represents for every other step type, and reusing it means the existing lesson-authoring UI, ordering, and gating machinery (`BHC_Gate`) all apply for free. A due date is just another sanitized field on that step (`due_date`, or `due_days_after_enrollment` mirroring the drip-scheduling convention `ROADMAP-feedback-and-courses-v2.md` already named for lesson availability).

Step shape: `['type' => 'assignment', 'title' => ..., 'instructions' => wp_kses_post(...), 'due_date' => 'Y-m-d' or null, 'submission_type' => 'audio'|'file'|'text']`.

### Data model
The step definition itself is postmeta (`_bhc_steps`, same as every other step type — no schema change there). The **submission** is a different concern from the assignment definition and needs its own table (see Section 4) — same split `bh-feedback` already draws between "the request/config" (postmeta on a CPT) and "the review" (a real table for cross-submission querying).

### UI surface
Admin: the existing lesson step editor (wherever `BHC_Steps`' admin form lives) gains an "Assignment" step type alongside text/image/video/quiz/etc. Portal/front end: renders in the lesson like any other step, with a due date shown and (once past due) a visual "overdue" state.

### Open questions for AJ
- Per-student assignment (different students, different assignment) vs. one assignment shared by the whole class — the step-type model above is inherently "same assignment for everyone in this lesson," which is almost certainly the right v1 scope (per-student customization is real complexity that doesn't obviously earn its keep for a small teaching practice).
- Does a missed due date do anything automatically (a nudge email via `OUS_Notifications`/`class-nudges.php`'s existing stalled-student pattern), or is it purely a visual flag for now?

## 4. Uploading homework (student submission)

### Smallest real version
Directly modeled on `BHF_Requests::handle_submit()`'s upload path: `media_handle_upload()` with the exact same "grant `upload_files` for the duration of this one call only" trust-boundary trick (a student account with no upload capability by default still needs to submit a file), gated behind `BHC_Gate::user_can_access_lesson()` (not a payment gate — this is free, unlike bh-feedback's wallet-debited submissions). Supports the three types AJ named: audio file, generic file (PDF/doc/whatever), or plain text — `submission_type` on the assignment step (Section 3) determines which input the front end shows.

### Data model
New table (not postmeta) — a submission needs the same "queried across students, needs an audit trail, has a real lifecycle" shape `bhf_...`/`bhm_wallet_ledger` already established, and critically this is the table the grading/review queue (Section 5) reads from:

`bhc_submissions`: `id, user_id, lesson_id, step_index, submission_type ('audio'|'file'|'text'), attachment_id (nullable), text_body (nullable), submitted_at, status ('submitted'|'claimed'|'graded'), reviewer_id (0 until claimed), claimed_at, grade, feedback_body, graded_at`. One row per submission attempt — a resubmission after feedback is a NEW row (not an overwrite), same "append, don't destroy history" posture `bhc_progress.answers`' snapshot-per-attempt already takes for quizzes; the assignment step then shows "your most recent submission" plus a visible history of prior attempts and the feedback each got.

Whether this reuses `bhc_progress` (one more row-shape squeezed into the existing table) or is its own table: **its own table.** `bhc_progress` is fundamentally "one row per (user, lesson, step) — the CURRENT state," enforced by its unique key; a submission needs MULTIPLE rows per (user, lesson, step) over time (resubmissions), which doesn't fit that key at all without a real schema change to the existing, working table. A `bhc_submissions` row instead just marks the step complete in `bhc_progress` (via `BHC_Progress::mark_step_complete()`, unchanged) once/if a submission is graded as passing — same "submission table feeds the existing progress table" relationship a quiz attempt already has today (`ajax_submit_quiz()` writes both `bhc_progress.answers` for the record AND drives completion).

### UI surface
Front end: the assignment step itself, rendered in the lesson (upload form or textarea depending on `submission_type`), showing past attempts + feedback inline. Portal: a "My Submissions" view is optional for v1 — the lesson itself is a fine home for "see your own submission and its feedback," no separate portal panel strictly required until proven necessary.

### Open questions for AJ
- File size / type limits — mirror bh-feedback's 50MB audio cap, or does a file-type submission (PDF, sheet music, whatever) need a different ceiling?
- Does a resubmission after feedback need an explicit "resubmit" affordance distinct from a first-time submit, or is it the same upload form re-shown?

## 5. Grading and feedback loop (the real metric/grading mechanic)

### The explicit architectural call already made
Per `ecosystem-depth-pass-2026-07.md`'s own framing ("a human grading a submitted assignment and a human giving paid feedback on a song are the same underlying 'reviewer looks at submitted work, leaves structured feedback' shape... use whatever reviewer-queue mechanism BH Feedback settles on"), this section is NOT a new review-queue build. It's `BHF_Queue`'s claim/release/complete state machine, same capability (`bhcore_review_submissions`), applied to `bhc_submissions` rows instead of `bh_feedback_request` posts.

### Smallest real version
A `BHC_Submissions` (or generically-named, shareable) claim/grade queue, structurally identical to `BHF_Queue`:
- `claim($submission_id, $reviewer_id)` — same atomic single-row conditional UPDATE (`status = 'submitted' -> 'claimed'`, only succeeds if it was still `'submitted'`).
- `release($submission_id, $reviewer_id)` — only the holding reviewer can release, same ownership check `BHF_Queue::release()` already enforces.
- `grade($submission_id, $reviewer_id, $grade, $feedback_body)` — only the holding reviewer can complete; writes `grade`/`feedback_body`/`graded_at`, flips `status -> 'graded'`, and (if the grade counts as passing — a simple threshold, or just "graded = passed" for a first cut since not every assignment needs a numeric score) calls `BHC_Progress::mark_step_complete()` to unblock the student past that step.

"Grade" itself: recommend a simple **pass/needs-revision** toggle plus free-text feedback for v1 — not a numeric rubric/points system. A real rubric (multiple weighted criteria, a running gradebook average) is the kind of "metrics for teachers" feature that sounds valuable in the abstract but is genuinely more LMS-gradebook machinery than a small self-hosted teaching practice needs on day one; pass/needs-revision + written feedback is the actual 80% case (it's a music lesson, not a semester-long graded course), and a numeric score field is cheap to bolt on later if AJ finds pass/fail too coarse in practice.

### Data model
No new table beyond `bhc_submissions` itself (Section 4) — grading is just further columns/state on that same row, exactly like `_bhf_status`/`_bhf_reviewer_id` are further meta on the same `bh_feedback_request` post rather than a separate "review" record. (bh-feedback's own `bh_feedback_reviews` table is a slightly different case because a `bh_feedback_request` is a CPT and the review is genuinely a second entity; `bhc_submissions` is already a plain table, so grading fields fit directly onto it with no second table needed.)

### UI surface
Admin: a "Submissions to grade" screen, structurally identical to `BHF_Queue`'s reviewer view in `bh-feedback/includes/class-portal-panel.php` (open queue, "claimed by me" list, a grade form). Could legitimately be one shared submenu if `bhcore_review_submissions` is meant to mean "review queue" across both plugins — see open question below. Front end: the student sees grade + feedback on their own assignment step once graded (Section 4's "past attempts + feedback inline").

### Open questions for AJ (the load-bearing ones)
- **Does `bhcore_review_submissions` mean "review bh-feedback submissions," "review bh-courses assignments," or both?** This is the single decision that determines whether bh-courses' grading queue is a genuinely separate admin screen that happens to reuse the same claim/release/complete code shape, or whether there's ONE unified "Review Queue" admin screen spanning both plugins' submission tables. Recommend: separate screens, shared capability, shared *pattern* (not shared code across plugin boundaries, since bh-courses and bh-feedback don't depend on each other) — matches how `bhcore_manage_students` already governs one thing (Student Progress) without needing to unify with anything else.
- Pass/needs-revision vs. a numeric grade — confirm this before building; it's a one-line schema difference now, a real migration later if guessed wrong.
- Does a "needs revision" grade automatically re-open the step for resubmission (i.e. does the student see an explicit "try again" state), or is that implicit in the assignment step simply still showing as incomplete?

## 6. Other genuinely useful additions noticed but not asked for (kept deliberately small)

- **Downloadable per-submission reference material already exists** (`resource` step type) — no new work needed, just worth noting an assignment step can already sit next to a `resource` step carrying the worksheet/backing track the assignment is based on.
- **A "graded" achievement/nudge hook** — once `bhc_submissions.status` flips to `graded`, firing a real event (`bhc/submission_graded`, same `BH_Event::register_event_type()` convention every other bhc action already uses) costs nothing extra and gives `class-nudges.php`/`OUS_Notifications`/CRM-integration a hook to notify the student their feedback is ready — cheap, additive, matches the existing `bhc_course_completed` hook-first-then-consumers sequencing this ecosystem already uses.
- **NOT recommended for now**: a gradebook/transcript export, rubric-weighted scoring, calendar-sync (Google/iCal), automated recurring-series generation for sessions, or a generic reports builder. All are real LMS features, none earn their build cost yet on a small self-hosted musician-teaching site with (most likely) a handful of instructors and a modest student roster — flagging them here explicitly as "seen, considered, deferred," not overlooked.

## Recommended build order (cheapest/most-proven-value first)

1. **Cross-course progress rollup + CSV export** (Section 2) — almost pure aggregation over code that already exists; the cheapest item on this list and immediately useful for any instructor running more than one course.
2. **Assignment step type, definition only, no submission yet** (Section 3) — lets AJ start assigning homework (visible in the lesson with instructions + due date) even before submission/grading exists; useful in isolation and de-risks the step-type UI before the harder submission/grading work.
3. **`bhc_submissions` table + upload/submit flow** (Section 4) — the real unlock; directly reuses bh-feedback's proven upload trust-boundary pattern, so this is mostly wiring, not new invention.
4. **Grading/claim queue on `bhc_submissions`** (Section 5) — deliberately sequenced after bh-feedback's queue is fully proven in production (per the explicit "reuse whatever bh-feedback settles on" call already made) so this isn't guessing at a pattern that might still change.
5. **`bhc/submission_graded` event + nudge hook** — trivial once 3-4 exist, same hook-then-consumer sequencing the completion event already used.
6. **Scheduling/booking (`bhc_sessions`)** (Section 1) — last, not because it's low-value, but because it's the one genuinely new content type/workflow here (nothing existing to extend, unlike 1-5 which all build on `BHC_Steps`/`BHC_Progress`/the bh-feedback queue shape) and has the most unresolved open questions (single vs. multi-instructor, calendar UI depth) that are worth AJ's own answer before any schema gets written.

Open questions from every section above are collected there rather than repeated here — the two most load-bearing ones to resolve before ANY of this gets built are: (a) whether `bhcore_review_submissions` is meant to span both plugins or stay bh-feedback-only with bh-courses getting its own separate capability, and (b) pass/needs-revision vs. numeric grading for assignments.
