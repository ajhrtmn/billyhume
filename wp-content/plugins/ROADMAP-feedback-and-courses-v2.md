# Roadmap: paid song feedback, and deepening BH Courses

Two threads, both building on rails this ecosystem already has: a new paid "song feedback" plugin, and the honest gap list from BH Courses v1 (progress overview, certificates, drip scheduling, retry limits) plus other ideas for a richer teaching/learning experience. Nothing here is built yet — this is the plan to work from next.

## 1. BH Feedback — paid song feedback, tiered by depth

### The idea, concretely

An artist submits a track (upload or point at an existing `bh_track`/local file); a reviewer (the site owner, or someone they designate) gives feedback at whichever depth the submitter paid for. Tiers of depth is the actual product here, not just "feedback, yes/no":

- **Quick take** (cheapest): a few sentences, maybe a 1-10 score on a couple of dimensions (mix, songwriting, performance).
- **Detailed written**: a structured form — strengths, weaknesses, section-by-section notes (verse/chorus/bridge), concrete next steps.
- **Timestamped audio annotations** (priciest): comments pinned to specific timestamps in the track, the way a real mix-review session works — "0:42, the vocal sits behind the snare here."

### How it fits the existing architecture

This is genuinely a new peer plugin (`bh-feedback`), not an extension of bh-courses or bh-streaming, for the same reason bh-crm/bh-registry/bh-courses are all separate: it has its own real content type (a submission + a review) and shouldn't force anyone who wants courses to also install feedback or vice versa.

- **Depends only on own-ur-shit** (shared identity — who submitted, who's the reviewer). Same peer relationship every other plugin here has.
- **Payment**: rides on bh-monetization-woo exactly the way pay-per-play does today, not the tier/subscription mechanism — this is a one-time, per-submission purchase, not ongoing access. `BHM_Wallet` already has the right shape for this (`debit()`/`credit()`, atomic ledger, no TOCTOU race) — a feedback submission becomes a wallet debit or a WooCommerce one-time-purchase order at submission time, same pattern `bhm_play_log` uses for pay-per-play, generalized from "cost per play" to "cost per submission at whichever depth tier was picked." `BHM_Products`' existing "external track can't be monetized" guard is the right model for a parallel guard here too, if submissions can ever reference externally-aggregated tracks.
- **If bh-monetization-woo isn't active**: submissions still work, they're just free/ungated — same graceful degradation every optional integration in this codebase already shows. A site that only wants free community feedback (no money involved) should be able to run this plugin alone.
- **Optional bh-streaming integration**: a submission CAN point at an existing `bh_track` post instead of a fresh upload (checked via `class_exists('BHS_Player')`, never a hard dependency) — convenient for an artist already using the streaming library, but a plain file upload works with zero other plugins active, same as bh-registry's relationship to bh-streaming.
- **Optional bh-crm integration**: a submission's status/feedback becomes an activity-section contribution on that artist's CRM detail page, via the exact `bh_crm_activity_summary` filter contract bh-crm's own docblock already documents for third parties — zero changes needed to bh-crm itself.

### Rough content model

- `bh_feedback_request` CPT: the submission — audio (upload or `bh_track` reference), submitter, chosen depth tier, status (`pending` / `in_review` / `delivered`).
- `bh_feedback_tiers` — not a CPT, just a small set of price/depth-per-type config (quick / detailed / annotated), similar shape to `bhm_tier` but scoped to this plugin, not reusing `bhm_tier` itself (those are recurring supporter tiers; this is a one-time per-submission price, a genuinely different concept that shouldn't be confused with the Patreon-lite tiers).
- A `bh_feedback_reviews` table: submission_id, reviewer_id, body (or structured JSON for the section-by-section form), timestamp-annotations (JSON array of `{time, note}` for the annotated tier), delivered_at. Table, not CPT/postmeta — this is the "queried across submissions, needs a real audit trail" shape `bhm_entitlements`/`bhm_wallet_ledger` already established as this ecosystem's convention for that kind of data.
- Reviewer-side queue: an admin page (bh-crm's People-page pattern — plain custom page, not a CPT list-table, relocated as a direct submenu under Own Ur Shit) listing pending submissions, oldest/highest-tier first.

### Honest open questions before building

- **Who reviews?** Just the site owner/admin, or can a submission be assigned to a specific named reviewer (relevant if this ever supports multiple reviewers)? Affects whether there's a "reviewer" role/capability to add.
- **Turnaround expectations**: does a submission have an SLA/deadline that should be surfaced (e.g. "delivered within 5 business days")? Purely a product/communication question, not an architecture one — flagging it now so it isn't a build-time surprise.
- **Timestamped audio annotations UI**: the most technically interesting piece — needs a waveform-or-scrubber component to click-and-pin a note at a timestamp. bh-streaming's player already has real audio playback wiring (Media Session, seek bar); reusing pieces of that (not the whole player) is worth a real look once this gets built, rather than writing timestamp-audio UI from scratch.

## 2. Fleshing out BH Courses v1's thin spots

From the honest gap list called out after the initial build:

### Admin progress overview (highest-value gap)
A real "all students, this course" admin view — not just Debug Tools' one seeded student. A table: student, percent complete, last activity date, per-lesson breakdown on click. Pure read from the existing `bhc_progress` table plus `BHI_Profiles` for display names — no new data needed, just a new admin screen. This is probably the single most valuable addition for an actual teaching workflow, since right now there's no way to see how a real class of students is doing.

### Completion certificates / events
Fire a real `bhc_course_completed` action when `BHC_Progress::course_percent()` hits 100 for a user (checked at the point a step is marked complete, not a cron poll) — gives a hook point for anything downstream: a certificate PDF, an email, a CRM activity entry, a supporter-tier upsell. Ship the hook before ship any specific certificate UI — the hook is cheap and unlocks several ideas at once; the certificate renderer itself (probably a simple downloadable PDF or a public "verify this certificate" URL) is a fine v2.1 on top of it.

### Drip scheduling
An optional "available starting X days after enrollment" (or a fixed calendar date) on a lesson, checked in `BHC_Gate` alongside the existing tier check — a lesson can be tier-unlocked but still not yet "open" on the calendar. Enrollment date needs its own concept though (right now "access" is purely tier-based, there's no explicit enrollment timestamp) — the smallest version of this is: first time a user's progress table gets a row for any lesson in a course, treat that as their enrollment date for that course.

### Quiz retry policy
Right now retries are unlimited with no cooldown — fine for a low-stakes progress-check, less fine if Billy wants an actual "you get 3 attempts" assessment. Make it configurable per quiz step (`max_attempts`, default 0 = unlimited) rather than a global policy, since a mid-lesson comprehension check and a real graded exam plausibly want different rules within the same course.

### Other "rich learning experience" ideas worth naming (not yet scoped)
- **Discussion/Q&A per lesson** — a simple comment thread scoped to a lesson (could piggyback on WordPress core comments with `bh_lesson` support, which needs zero new infrastructure) so a student can ask a question in context rather than needing a separate CRM note.
- **Downloadable resources per lesson** — a step type (or per-lesson attachment list) for worksheets/practice files/tab sheets, reusing the same media-uploader pattern the image step already has.
- **Cohort/scheduled-release courses** — a course that opens to everyone at once on a fixed date rather than per-lesson drip, useful for a "live cohort" teaching model vs. self-paced.
- **Instructor-graded (not auto-scored) steps** — a step type where a student submits something (audio, text, a file) and a human reviewer marks it pass/fail rather than the system auto-scoring it. Notably, this is architecturally close to BH Feedback above — worth a real look at whether "submit something for human review" ends up being one shared mechanism both plugins call into, rather than reinventing review-queue logic twice. Flagging the overlap now rather than after both are half-built independently.

## Suggested order

1. BH Courses admin progress overview (cheap, highest immediate teaching value, no new architecture).
2. `bhc_course_completed` hook + a first real consumer (even just a CRM activity-contribution, before any certificate UI).
3. Quiz `max_attempts`.
4. BH Feedback v1: quick-take + detailed-written tiers only (skip timestamped-audio-annotation tier for v1 — it's the most build effort and the least proven demand until the simpler tiers are live).
5. Revisit drip scheduling and the annotated-audio tier together, since both need a real "when did this become available to whom" concept that's worth designing once rather than twice.

Let me know if you want to reorder this (e.g. pull BH Feedback ahead of the courses gap-filling, or the reverse) before anything gets built.
