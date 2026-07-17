<?php
/**
 * Plugin Name: BH Courses
 * Description: Courses made of ordered, multistep/multipart lessons — text, images, and quizzes/progress-checks in any sequence — with per-student progress tracking and optional supporter-tier gating via BH Monetization. Depends only on Own Ur Shit's shared identity.
 * Version:     0.4.26
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

// 0.4.14 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 4b: real
// video progress tracking. A course creator can now set a per-video-step
// "require N% watched" threshold (bhc/video's new watch_threshold
// attribute, Studio block RangeControl) — 0 keeps today's behavior
// (any playback + a manual click completes it) unchanged. When set, a
// <video> tag's timeupdate events (courses.js, throttled to whole-
// percent changes) ping a new bhc_update_watch_progress AJAX action
// (BHC_Progress::update_watch_progress(), new watched_percent column on
// bhc_progress) and the step auto-completes — no button click needed —
// the moment the threshold is crossed. Deliberately NOT enforceable for
// a cross-origin iframe embed (YouTube/Vimeo-style) — same-origin
// <video> only; class-render-lesson.php only ever emits the tracking
// attribute for the genuinely trackable case, iframe embeds keep the
// unchanged manual "Mark complete" button regardless of what's stored.
// RUNTIME-VERIFIED end to end on this actual install: real course/
// lesson/step, ran the DB migration (bhc_db_version 1.2 → 1.3), then
// exercised BHC_Progress::update_watch_progress() and the real AJAX
// handler directly — confirmed progress never regresses on a rewind,
// the step correctly reads incomplete through every ping below
// threshold, auto-completes exactly once the instant threshold is
// crossed, and doesn't double-fire on a later ping. This caught and
// fixed one real bug in the process: the very first progress ping
// created its bhc_progress row with passed = NULL, which
// is_step_complete()'s existing rule (NULL passed = complete, correct
// for a plain text/image row that's ONLY ever written by an explicit
// Mark-complete click) misread as "already done" after 30% watched —
// fixed by writing passed = 0 on that first ping and never touching it
// again until mark_step_complete() itself flips it to real completion.
// Test course/lesson/progress rows cleaned up afterward.

// 0.4.13 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 4a:
// certificate of completion. Studied LifterLMS's own Achievements/
// Engagements architecture first (trigger→handler dispatch table)
// before writing anything — concluded WordPress's own
// `bhc_course_completed` action (already fired exactly once per
// user/course by class-progress.php's maybe_fire_course_completed())
// already IS that extension point, so no bespoke "engine"/registry
// class was added; see class-certificates.php's own docblock for the
// full reasoning. New BHC_Certificates: an off-by-default per-course
// checkbox + optional "Signed by" text field (class-admin.php's course
// metabox, same explicit-opt-in posture as Lesson Q&A), a plain
// `?bhc_certificate=<course_id>` query-arg download link gated at
// template_redirect on BOTH the course offering it AND
// BHC_Progress::is_course_completed() for the requesting user, and
// on-demand (never pre-generated/stored) PDF rendering via own-ur-
// shit's newly-vendored FPDF library. "Download certificate" link
// added to the course page header (class-render-course.php), next to
// the existing Continue/Start/Review CTA, visible only once eligible.
// RUNTIME-VERIFIED end to end on this actual install: created a real
// course, enabled the certificate checkbox + a signature, inserted a
// real bhc_completions row for a real user, hit the real download URL
// as that logged-in user, and confirmed a genuine single-page PDF
// streamed back ("PDF document, version 1.3") with the course title,
// student name, completion date, and signature all rendered — caught
// and fixed one real bug in the process (FPDF's vendored fpdf.php was
// missing its font/*.json metric files; see own-ur-shit 3.4.84's own
// changelog). Test course, completion row, and the temporary admin
// password used to log in for this pass were all cleaned up/reverted
// afterward.

// 0.4.8 — 2026-07-12 — SOLID/SRP QA pass on class-render.php: a single
// 589-line class was rendering the catalog, the course detail page, AND
// the lesson step-walker/quiz UI — three genuinely separate concerns.
// Split into new class-render-catalog.php (BHC_Render_Catalog),
// class-render-course.php (BHC_Render_Course), and class-render-lesson.php
// (BHC_Render_Lesson) — pure moves, byte-for-byte identical logic, no
// behavior change. BHC_Render itself (class-render.php) is now a thin
// coordinator: shortcode/hook registration, asset enqueueing, and one-
// line delegating wrappers for render_catalog()/render_course()/
// render_lesson_steps()/render_quiz_review() — every existing external
// call site (class-test-suite.php, templates/archive-bh_course.php,
// this file's own the_content filter) keeps working with ZERO changes.
// render_continue_cta() (shared between the catalog card and the course
// header) now lives on BHC_Render_Course as a public method, with
// BHC_Render_Catalog calling across to it — see that file's own
// docblock for why it wasn't split into a fourth shared-helper class for
// one method. Grepped every BHC_Render:: reference in the plugin before
// and after to confirm nothing else needed updating. Brace-balance-
// checked on all four files; NOT runtime-verified — no live PHP/
// WordPress execution available this pass, needs a real page load
// (catalog, a course page, a lesson) to confirm the split behaves
// identically before trusting it fully.

// 0.4.2 — BHC_TestSuite gained real DB-backed coverage for quiz answer
// storage (mark_step_complete()/stored_answers() round-trip, latest-
// attempt-only retry semantics, the NULL-vs-0 sanitization behavior) and
// the course catalog's search/sort (real fixture posts, cleaned up after
// each run) — both previously untested. Standing caveat: written and
// brace-balance-checked, not yet executed against the live install.

// 0.4.1 — first OUS_DebugLog call anywhere in this plugin:
// BHC_Progress::mark_step_complete()'s DB write is now checked — a
// failed write previously still let the student-facing flow report
// "step complete" with the failure completely invisible. Standing
// caveat: reasoning/brace-balance-checked only.

// 0.3.0 — LMS lesson-flow authoring wired onto BH_Studio/BH_Content
// (see LMS-AUTHORING-DESIGN-PLAN.md): bhc/* block types registered with
// the Studio canvas, bhc/quiz promoted to a real container of
// bhc/quiz-question child blocks, and the legacy steps-repeater metabox
// replaced with a link into Content Studio (closing the dual-write
// hazard the design doc flagged — see class-content-bridge.php and
// class-admin.php).
//
// 0.3.1 — six queued LMS UX fixes from an honest-assessment pass, all
// additive/routine (no architectural changes): a course-level "Continue/
// Start/Review" CTA on the catalog card + course page
// (BHC_Progress::first_incomplete_lesson(), class-render.php); "Next
// Lesson →" navigation once a lesson's last step completes, instead of
// silently stranding the student (class-render.php + courses.js);
// a step-walker back button, including revisiting a passed quiz in a
// read-only review state (note: this reviews PASS/FAIL + question list
// only, not the student's exact original answer choices — bhc_progress
// never stored the submitted-answers array, and adding that is a real
// schema addition deliberately left out of this pass); per-step content
// labels replacing the type-only summary in the lesson metabox
// (BHC_Admin::describe_step()); a "Preview as student" link next to the
// Studio button; and a manual-override "mark complete" action on the
// Student Progress admin page for the ordinary support-request case
// (BHC_ProgressAdmin::maybe_handle_override()). NOT yet run against real
// WordPress+MySQL — reasoning-checked only, same standing caveat as
// every other pass this session.
// 0.4.0 — two feature pushes per QUIZ-AND-CATALOG-DESIGN-PLAN.md (Opus
// plan pass, no code, run first given the real schema/IA decisions
// involved):
//
// Part 1, quiz answer storage: bhc_progress gained an `answers` longtext
// column (DB_VERSION 1.1 -> 1.2, class-activator.php) storing a
// self-contained JSON snapshot (question text, choices, correct index,
// chosen index) of the LATEST attempt only — matches this table's
// existing upsert/latest-state semantics (see bhc_enrollments/
// bhc_completions), deliberately NOT an append-only per-attempt log like
// bhm_play_log/bhcore_events. Quiz review now shows exactly what the
// student answered vs. the correct answer (BHC_Render::render_quiz_review(),
// courses.js's renderQuestionBreakdown()), end-of-submission only (not
// per-question-as-you-go, which would let students game max_attempts one
// question at a time). Snapshots are frozen at submission time and will
// not reflect later edits to the quiz block — intended, not a bug.
// Deferred: a per-attempt gradebook table (every attempt's answers, not
// just the latest) — named as a possible future feature, not started.
//
// Part 2, real course catalog: bh_course gained two real taxonomies
// (bhc_course_category hierarchical, bhc_course_topic flat — both
// 'rewrite' => false, no term-archive URLs planned) and catalog postmeta
// (instructor as a real WP user ID, difficulty as a closed 3-value enum,
// optional duration-note override) via a new "Catalog Details" metabox
// (class-admin.php). The [bh_courses] shortcode AND the CPT's own
// /courses/ archive (bh-courses/templates/archive-bh_course.php, a
// fallback the active theme's own archive-bh_course.php always takes
// precedence over) now render a real WP_Query-backed catalog: keyword
// search, category/topic filtering, newest/alphabetical/popular sort
// (popular resolved from BHC_Progress::enrollment_counts(), since that
// signal lives in bhc_enrollments, not postmeta), and pagination
// (class-render.php). [bh_course] course pages gained a real detail
// header (cover, description, instructor, difficulty, duration, terms,
// enrollment CTA) wrapping the existing lesson list, plus a
// title-only syllabus preview for locked/unpurchased courses. Ratings/
// reviews were explicitly scoped OUT of this pass per the design doc
// (no data model exists for them yet) — named here as a deferred
// follow-up, not silently dropped.
//
// Flagged risks carried over from the design doc's own Part 3, not yet
// mitigated: no schema-version field on the answers JSON blob itself
// (a future format change has no migration hook); instructor referencing
// a since-deleted WP user degrades to null (handled, but surfaces as "no
// instructor listed" with no explicit UI note why); large quiz snapshots
// could bloat bhc_progress rows over many students/questions (no size
// cap enforced).
//
// Standing caveat, same as every pass this session: NOT run against real
// WordPress+MySQL — reasoning-checked and PHP-syntax-balance-checked
// only (no php-cli in this sandbox either), never executed.
// 0.4.3 — bundled zip regenerated to match installed version, no code change
// 0.4.4 — class-progress.php's enroll_if_needed()/mark_step_complete()/
// maybe_fire_course_completed() now additionally emit BH_Event
// 'bhc/enroll' / 'bhc/step_completed' / 'bhc/course_completed' events
// (own-ur-shit's new event-tracking layer, class-event.php) — additive
// only, alongside the existing bhc_progress/bhc_enrollments/
// bhc_completions writes and the existing bhc_course_completed action,
// which are unchanged. See EVENT-TRACKING-ARCHITECTURE-PLAN.md Section
// 6. Standing caveat: reasoning/brace-balance-checked only, no
// php-cli/live WordPress+MySQL in this pass either.
// 0.4.5 — assets/js/courses.js's mark-step-complete AJAX handler now
// calls BHCoreToast.show() directly (own-ur-shit's new toast system,
// 3.4.18+) on both success ("Step complete.") and failure, in addition
// to advancing the lesson UI as before. This is an AJAX flow with no
// redirect, so it calls the JS toast API directly rather than the
// PHP-side OUS_Toast::queue() hand-off used by this ecosystem's
// admin-post redirect flows. typeof-guarded: falls back to the
// pre-existing alert() on failure if BHCoreToast isn't loaded for any
// reason. No PHP changed, so no BHC_VER cache-busting bump is strictly
// required for correctness, but bumped anyway per this pass's own
// changelog convention, since courses.js is enqueued with BHC_VER as its
// version string (class-render.php) and this ensures the browser doesn't
// serve a stale cached copy of the edited file.
// 0.4.6 — the bh-courses ous_debug_tools registration (below) and
// class-content-bridge.php's register_debug_tool() both now set 'group'
// => OUS_Debug::GROUP_SEED_RESET, part of own-ur-shit's Debug Tools
// reorganization pass. No functional change to this plugin itself.
// Standing caveat: reasoning/brace-balance-checked only, not run against
// a real WordPress+MySQL install.
// 0.4.7 — 2026-07-12 — DESIGN-SUITE-UNIFICATION-PLAN.md: bh-courses'
// first-ever BH_Element surface, closing the gap flagged in that doc's
// latest status note ("bh-courses surveyed, zero BH_Element integration
// exists"). New class-lesson-surface.php (BHC_LessonSurface) registers
// 'bh_courses_lesson' — ONE 'root' slot (not several framework-chosen
// zones — learned directly from CRM's own 1.1.2 → 1.3.3 mistake of
// shipping three slots and having to collapse them later; lessons start
// at one). Context is per-LESSON (surface_context_id = the bh_lesson
// post ID), matching how a lesson's own step content already works.
// class-render.php's render_lesson_steps() gained one new render_slot()
// call, appended once after the existing step-walker output (not
// duplicated per step) — an optional "below the lesson" area, empty and
// invisible until AJ actually places something there via the Design
// Suite. Additive only: the pre-existing BH_Studio/BH_Content-authored
// step content (text/image/video/quiz, 0.3.0) is completely untouched —
// this is a new area, not a replacement. Standing caveat: reasoning/
// brace-balance-checked only, no live PHP/MySQL/WordPress execution
// available this session — not yet runtime-verified that the new slot
// actually renders and is editable end-to-end in the Design Suite.
//
// 0.4.9 — 2026-07-13 — real-post-editor migration for lesson authoring,
// direct response to a live-session finding (this same session, real
// WordPress+MySQL execution): BH_Studio's canvas — where lessons were
// authored — has no theme CSS (blocks render with generic WP block-
// library styles, not the real site's actual look), no --bh-* design
// tokens, and no Advanced Styles panel, none of which reach a manually-
// bootstrapped BlockEditorProvider on a custom admin page the way they
// reach the real editor screen (block_editor_settings_all/
// enqueue_block_editor_assets are core hooks tied to the real
// post/site editor bootstrap, not fired for a hand-rolled canvas).
// Lesson authoring visibly felt like a different product from every
// other page in the ecosystem — direct AJ feedback this pass. bh_lesson
// (class-post-types.php) now supports 'editor' + show_in_rest, so it
// gets a real block-editor screen for the first time.
// class-content-bridge.php: CONTEXT changed from a bespoke 'bhc_lesson'
// BH_Content context (a custom table row, reachable only via Studio's
// generic REST route) to the REAL 'post' context BH_Content already
// supports — parse_blocks()/serialize_blocks() against the lesson's own
// post_content, same as any ordinary page. bhc/text|image|video|quiz|
// quiz-question (courses-studio-blocks.js) needed ZERO changes — they
// were already real wp.blocks.registerBlockType() blocks with real
// edit()/save(), only ever enqueued in the wrong place
// (maybe_enqueue_lesson_blocks(), replacing maybe_enqueue_studio_blocks(),
// now hooks enqueue_block_editor_assets gated on the bh_lesson screen
// instead of the bh-studio admin-page hook substring). A new
// sync_legacy_steps() (save_post_bh_lesson) is now the only writer of
// `_bhc_steps` — fires on every real save of a lesson regardless of
// which screen triggered it, reading the tree straight back out of
// post_content via the existing get_tree()/tree_to_steps() (unchanged).
// This incidentally sidesteps a real, if inert, bug: Studio's generic
// REST route (own-ur-shit/class-studio.php's rest_save()) actually
// calls BH_Content::save() directly and never called the old
// save_tree() at all, despite that method's own docblock claiming it
// was "the only writer" — save_tree() (now deleted, confirmed unused
// anywhere in the ecosystem) was aspirational dead code, never actually
// wired to the route it claimed to be called from. Nothing about
// front-end rendering changes: BHC_Render/BHC_Progress/BHC_Gate/
// BHC_Steps::score_quiz() still read _bhc_steps exclusively.
// class-admin.php's "Lesson Steps" metabox is no longer an editor of
// its own (dropped the "Edit in Content Studio" link-out) — it's a
// read-only current-steps summary + "preview as student" link now,
// since authoring happens directly in the real editor above it.
// migrate_lesson()/the "populate lesson content from steps" Debug Tools
// button (relabeled from "rebuild BH_Content trees") is a one-time
// hydration path for a lesson that existed before this pass and hasn't
// been opened in the real editor yet; a lesson created after this pass
// never needs it. BH_Studio itself is untouched — it stays the right
// tool for genuinely postless contexts (bh-monetization-woo's
// storefront collection pages, keyed to a WooCommerce taxonomy term
// with no post ID), which this migration does not concern.
// RUNTIME-VERIFIED, with one real bug caught and fixed in the same
// pass: created a lesson via wp-admin, confirmed the real block editor
// loads with Lesson: Text/Image/Video/Quiz blocks in the inserter,
// added a Lesson: Text block, saved, and found `_bhc_steps` synced with
// an EMPTY `content` despite real text sitting in post_content — direct
// DB inspection, not assumed. Cause: bhc/text's attribute was
// `{ type: 'string', source: 'html', selector: 'div' }` (courses-
// studio-blocks.js) — an HTML-sourced attribute, extracted from the
// block's own rendered markup. That extraction is a client-side
// (editor) concept; parse_blocks() (PHP, what BH_Content::get('post',
// ...) actually calls) never runs it, so the JSON-comment `attrs` this
// codebase reads was always empty for that attribute. Fixed by
// dropping `source`/`selector` entirely — content is now a plain
// attribute stored directly in the JSON comment, the exact same
// pattern bhc/image's caption / bhc/video's caption / bhc/quiz-
// question's question already used (this was the one block that
// didn't match its own siblings). RichText/RichText.Content work
// identically bound to a plain attribute — only the storage shape
// changed. Re-verified after the fix: real text now round-trips
// correctly end to end (post_content JSON attrs -> _bhc_steps), all
// confirmed via direct DB inspection, not reasoned through.
// 0.4.10 — UX-AUDIT-2026-07.md: the catalog's bare "No courses found
// yet/matching your filters." replaced with the shared
// BHY_Style::empty_state_html() component (own-ur-shit 3.4.82) — a
// real icon, an owned title distinguishing zero-data from filtered-
// empty (this file's own ternary already made that distinction, it
// just had no visual weight), and a "Clear filters" link on the
// filtered variant. Falls back to the old plain-text message if
// BHY_Style isn't loaded (own-ur-shit is a hard dependency of this
// plugin, so this is belt-and-suspenders, not an expected path).
// RUNTIME-VERIFIED: confirmed both variants render correctly (icon
// sized correctly, "Clear filters" actually clears back to the
// unfiltered zero-state) on desktop and at 375px mobile width.
// 0.4.11 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 4c:
// downloadable resources per step — a 5th step type ('resource':
// attachment_id + label + optional description), following the exact
// same pattern class-steps.php's existing types already use
// (VALID_TYPES + a save() sanitization branch). New real Gutenberg
// block bhc/resource (courses-studio-blocks.js: a MediaUpload with no
// allowedTypes restriction, since a resource can be any file type,
// unlike bhc/image/bhc/video's narrowed pickers) + its BH_Content
// server-side schema/renderer (class-content-bridge.php) + a render
// branch in class-render-lesson.php (a download link + the same
// Mark-complete-and-continue pattern every other step type uses —
// deliberately non-blocking, per this doc's own scoping note: a
// resource step doesn't require the file to actually be downloaded to
// advance, same as a text step doesn't require it to be read).
// steps_to_tree()/tree_to_steps() (class-content-bridge.php) needed NO
// changes — 'resource' has flat attrs and no children, so it flows
// through the already-generic bhc/* conversion path untouched; only
// bhc/quiz's child-block promotion needed special-casing there.
// RUNTIME-VERIFIED end to end: created a real lesson, added a Lesson:
// Resource block, picked an existing media-library file (confirmed the
// label auto-fills from the file's own title on first pick), set a
// custom label + description, published, confirmed via direct DB
// inspection that both post_content (the real Gutenberg block comment)
// and _bhc_steps (the legacy sync target) reflect the exact same data,
// then loaded the real student-facing lesson page and confirmed the
// download link resolves to the actual file (HTTP 200, correct
// content-type) with the description shown alongside it.
// 0.4.12 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 4d:
// comments/Q&A on lessons, built as a real decision from day one per
// that doc's own note — new class-comments.php (BHC_Comments). Off by
// default, per COURSE, not a blanket switch (`_bhc_comments_enabled`
// on the course, a new "Lesson Q&A" checkbox in the course metabox,
// unchecked unless an author explicitly opts in). `bh_lesson` gets
// real 'comments' post-type support (add_post_type_support(), same
// primitive 'editor' support used this same day for the real-post-
// editor migration).
// Visibility, not just posting, is gated to whoever BHC_Gate::
// user_can_access_lesson() would already let see the lesson content —
// the exact same rule, not a second one to keep in sync. RUNTIME-
// VERIFIED, with one real bug caught and fixed in the same pass: the
// first attempt used the classic `comments_array` filter to hide
// existing comments, confirmed live to be a dead end — the active
// block theme's Comments block queries via WP_Comment_Query directly,
// which never calls the legacy get_comments() wrapper that filter is
// actually tied to, so a real posted comment stayed fully visible even
// after the lesson itself was locked. Fixed by moving to
// `pre_get_comments` (a lower-level hook WP_Comment_Query itself fires
// on every query, block-based or classic) plus a `get_comments_number`
// filter (the displayed count reads a separate cached
// wp_posts.comment_count field the query-level fix doesn't touch,
// which would otherwise show a stale nonzero count over an empty
// list). Re-verified end to end: posted a real comment, confirmed it
// displays and the count is accurate; set a future drip date on the
// same lesson and confirmed the ENTIRE Comments section (heading,
// count, and the comment itself) disappears completely, not just the
// reply form; removed the drip date and confirmed everything reappears
// correctly.
define('BHC_VER',  '0.4.26');

// 0.4.26 — First real contributor to own-ur-shit's new shared Metrics
// dashboard (OUS_Metrics, class-metrics.php): three widgets in
// includes/class-crm-integration.php (Enrollments, Course completions,
// Avg. quiz score), built in tandem with that dashboard per AJ's own
// "foundational infrastructure, not a bolt-on" instruction. Reads
// bhc/enroll and bhc/course_completed events already flowing — no new
// instrumentation added. class_exists()-guarded; does nothing if
// own-ur-shit's metrics class isn't present.

// 0.4.25 — Whole-course duplication ("Duplicate this course as a
// template") — a fresh audit against Teachable/Thinkific/Kajabi/
// LearnDash/LifterLMS flagged this as the most-common missing
// instructor tool: only per-lesson duplication existed before this.
// New "Duplicate" row action on the Courses list (course_row_actions()/
// handle_duplicate_course()) clones the course post, its catalog/
// gating/certificate/share-card meta, its categories/topics/featured
// image, and every one of its lessons — each lesson gets its own
// independent clone (same core copy logic handle_duplicate_lesson()
// already uses, never shared IDs between two courses), rebuilt into a
// fresh _bhc_lesson_order for the new course. Both course and lessons
// land as drafts. Verified live: a real 2-lesson course (including its
// quiz step) duplicated correctly, with the new course's stats bar
// correctly showing "2 lessons · 0 published · 2 draft · 3 total
// steps" and its share-card style setting carried over.
// 0.4.24 — Fixed a stale Test Runner assertion, flagged by AJ from a
// real failing-test report. class-test-suite.php's catalog-search test
// still checked for '<p class="bhc-empty">' in render_catalog()'s
// empty-search output, but that fallback markup hasn't actually
// rendered in a real environment since the empty state was upgraded to
// the shared BHY_Style::empty_state_html() component a while back
// (real title/description/CTA) — BHY_Style is always loaded (own-ur-
// shit is a hard dependency), so the assertion was checking for
// markup that could never appear, not catching a real regression.
// Fixed the TEST to check for the real component's class
// ('bhy-empty-state'), not by reverting working, better production
// code to satisfy a stale check. Verified live via Test Runner: 106/106
// passing across all 6 suites, including this one.
// 0.4.23 — Course-as-collection UI pass, emphasizing the "every lesson
// belongs to exactly one course" relationship AJ asked to make visible
// in the UI, not just the data model.
// Course screen (render_course_metabox()): a new stats line above the
// lesson list ("N lessons · N published · N draft · N total steps") —
// previously a bare orderable list gave no at-a-glance sense of the
// course as a collection. Each lesson row is now a real management
// surface: the title is a genuine edit link (previously static text —
// getting to a lesson meant leaving this screen and finding it in All
// Lessons), a step count, a real status pill, and a new "×" quick
// action (handle_unassign_lesson(), new admin-post handler) that
// detaches a lesson from the course without deleting the lesson
// itself.
// Lesson screen (render_lesson_metabox()): the course dropdown is now
// inside a labeled "THIS LESSON BELONGS TO" box, and when a course is
// already assigned it shows this lesson's real position ("Lesson 2 of
// 5") via the existing BHC_PostTypes::lesson_position() — previously
// only visible by going to the course screen and counting.
// Verified live: real stats bar rendering correct counts, lesson-row
// edit links/step counts/status pills, and the lesson-screen position
// line all confirmed against a real 2-lesson course.
// 0.4.22 — Production-hardening pass, from a fresh audit ahead of real
// users.
// (1) New before_delete_post cleanup (cleanup_deleted_lesson()) — the
// mirror-image gap to cleanup_deleted_course(): a permanently-deleted
// lesson left its own ID sitting in its parent course's
// _bhc_lesson_order forever. Masked (every render call site already
// filters on post_status !== 'publish'), but real: the Lessons-count
// admin column over-reported forever, and any future code trusting
// lesson_order() without that same defensive filter would silently
// include a dangling ID.
// (2) Real, confirmed live bug: courses.js rendered the literal string
// "null" in the score summary on any replayed/duplicate quiz submit
// against an already-passed quiz — class-progress.php's
// ajax_submit_quiz() deliberately returns correct: null on that path
// (it doesn't recompute a count it already knows the answer to), and
// courses.js had no guard for it. Fixed by re-deriving the correct
// count from score/total (score = round(correct/total*100), inverted)
// rather than assuming a pass means every question was right.
// (3) wp_die() calls across share-card/certificate endpoints and the
// Student Progress page now pass back_link => true; the certificate
// endpoint's "log in" dead-end now redirects to a real login URL that
// returns to the certificate link afterward instead of just saying
// "log in" with no way to; stream_pdf() now guards against a missing
// vendored FPDF file with a real 500 message instead of a raw PHP
// fatal (white-screen) error.
// 0.4.21 — LMS up-beefing pass: quiz question/answer shuffling + lesson
// duplication.
// (1) New shuffle_questions/shuffle_choices toggles on the bhc/quiz
// block (courses-studio-blocks.js), plumbed through BH_Content's schema
// (class-content-bridge.php) into _bhc_steps. class-render-lesson.php
// now walks a shuffled KEY ORDER when rendering questions/choices —
// every form field's name/value stays tied to the ORIGINAL index
// (BHC_Steps::score_quiz() and courses.js's FormData parsing both
// already read those, unchanged), so this is purely a display-order
// concern with zero scoring risk.
// REAL BUG, caught only by actually testing end-to-end (not by reading
// the code): BHC_Steps::save() re-sanitizes every step through its own
// explicit per-type field whitelist — the single ONLY writer of
// _bhc_steps. Adding the two new attrs to the block schema and the
// renderer wasn't enough; this whitelist didn't know about them yet, so
// every save silently dropped shuffle_questions/shuffle_choices and the
// front end kept rendering in fixed order no matter what the toggle
// showed. Fixed by adding both fields to the quiz branch's whitelist.
// Verified via direct DB inspection (confirmed the fields were actually
// missing from stored postmeta, not just "maybe not shuffling") and via
// six repeated curl fetches of a real quiz step post-fix, confirming
// the rendered choice order actually varies while each choice's `value`
// stays correctly tied to its real answer throughout.
// (2) New "Duplicate" row action on the Lessons list (class-admin.php:
// lesson_row_actions()/handle_duplicate_lesson()) — clones post_content
// (the real block tree), _bhc_steps, course assignment (and adds the
// clone to that course's own lesson order via the existing
// add_lesson_to_order() helper), and drip settings; always lands as a
// draft. Closes the "building a second similar lesson means rebuilding
// from scratch" gap the deep LMS audit flagged. Verified live: real
// duplicate of a real lesson, confirmed the clone's block content,
// course assignment, and course-side lesson count all came through
// correctly.
// 0.4.20 — "Anything fun for social sharing?" — AJ's own ask this
// session. New class-share-cards.php: a course-completion share-card
// endpoint (?bhc_share_card={course}&u={user}, public/no-login since a
// share card is meant to be viewed by people who are NOT the student,
// including a social platform's own link-preview crawler), gated on
// that student having actually completed the course. Generated via the
// new shared BH_ShareCard engine (own-ur-shit 3.5.2), same on-demand-
// not-stored posture class-certificates.php already uses. A "Get
// shareable image" button now appears alongside "Back to course" on
// the lesson page's course-complete block (class-render-lesson.php).
// New per-course "Brand"/"Poster" card-style radio in the Course
// Details meta box (class-admin.php), saved to _bhc_share_card_style,
// defaulting to 'brand'. Verified live: toggled a real course between
// both styles and confirmed the generated PNG actually changed
// (caught and worked around a real gotcha — the endpoint's own
// Cache-Control header meant the browser served a stale cached copy
// of the FIRST style fetched at that exact URL until a cache-busting
// query string forced a real refetch).
// 0.4.19 — Deep LMS audit follow-through: instructor aggregate stats,
// stalled-student nudges, mobile lesson/quiz UI, stepper a11y.
// (1) class-progress-admin.php's Student Progress page gets a new "At a
// glance" panel (render_summary()) above the per-student table:
// per-lesson completion rate, per-lesson average quiz score (averaged
// per-quiz-step then across quiz steps, not one flat AVG(score), so a
// lesson's harder/longer quiz can't get diluted by an easier one more
// students reached), and a stalled-student count/flag (14+ days quiet,
// not finished) — previously this page was raw per-student rows only,
// unusable at any real class size. students_for_course()/
// last_activity() moved from this file to BHC_Progress as public
// last_activity_for_course()/students_for_course() so the new
// class-nudges.php job reads off the same query instead of a second
// copy.
// (2) New class-nudges.php: a daily OUS_Jobs job (self-rescheduling,
// no new cron infra) that finds students stalled 14+ days on an
// unfinished course and sends exactly one OUS_Notifications nudge,
// throttled via usermeta so the same student isn't renudged more than
// once per 14-day window even if still stalled next run. Paired with a
// new bhc_enrolled action (class-progress.php's enroll_if_needed(),
// fired only on the real INSERT, never the repeat-visit no-op) and its
// class-crm-integration.php listener — previously enrollment was
// completely silent; course completion was the only lifecycle
// notification anywhere in this plugin.
// (3) courses.css gets its first @media breakpoint anywhere in the
// file (max-width:480px) — the lesson/quiz-taking UI specifically
// (step padding, video max-height, larger stepper-dot tap targets,
// full-width buttons, stacked breadcrumb, stacked quiz-choice review
// rows), a real gap the audit caught: the catalog page had responsive
// treatment, this file's step walker and quiz form never did. Stepper
// dots also get a real aria-label (class-render-lesson.php) — the
// type glyph (T/I/V/Q) was pure CSS ::before content, invisible to a
// screen reader on its own.
// Verified live: resized to 375px and confirmed the lesson page's
// breadcrumb stacks, the mark-complete button goes full-width, and the
// stepper dot is larger; the Student Progress page's new summary panel
// renders correct completion-rate/quiz-avg/stalled-count for the QA
// test course.
// 0.4.18 — Second half of the same course<->lesson UX integration pass:
// data-integrity guard + student-facing visual stepper.
// (1) class-admin.php's save_lesson() now validates a posted
// bhc_course_id against a real, non-trashed bh_course before writing
// it (a stale/crafted POST no longer leaves a lesson pointing at
// nothing), and auto-syncs the inverse pointer — the course's own
// _bhc_lesson_order — whenever a lesson's course assignment changes
// from the LESSON screen (add_lesson_to_order()/remove_lesson_from_order()),
// closing the "two independent pointers, nothing keeps them in sync"
// gap the audit flagged. A new before_delete_post hook
// (cleanup_deleted_course()) clears _bhc_course_id off any lesson still
// pointing at a course once it's permanently deleted (not on trash —
// a trashed course is still a real, restorable post). The bh_lesson
// list table gets a new "Course" column (lesson_columns()/
// lesson_column_content()) that surfaces "— none —" / "— orphaned
// (course deleted) —" directly instead of requiring a postmeta lookup
// to notice.
// (2) class-render-lesson.php's render_lesson_steps() now renders a
// .bhc-stepper row of per-step dots (type-tagged T/I/V/Q glyphs,
// done/current state) above the step walker, replacing the previous
// plain "Step X of Y" text as the only progress signal; dots up
// through the current step are clickable (courses.js), same
// can't-skip-ahead rule the existing per-step Back buttons already
// enforced. Every step card also gets a type-colored left border
// (courses.css) instead of every step type sharing one identical flat
// look. courses.js's showStep()/advance() and all three completion
// paths (mark-complete button, quiz submit, video watch-threshold
// auto-complete) now keep the stepper's done/current state in sync via
// a new markStepDone() helper, so it can't drift from the .bhc-step-done
// class it mirrors.
// Verified live: republished the QA test lesson with a course
// assignment change from the lesson screen and confirmed the course's
// lesson order picked it up without a manual course-screen edit; the
// stepper renders with the correct type glyph and advances/locks
// correctly through mark-complete.
// 0.4.17 — Course<->lesson UX integration pass, prompted by AJ's own
// audit of the two authoring/navigation gaps this found: (1) a student
// who deep-linked into a lesson (or just wanted out mid-lesson) had no
// way back to the course until finishing every step — the only
// "back to course" link lived in .bhc-lesson-next, gated on completing
// the LAST step. class-render-lesson.php's render_lesson_steps() now
// renders a persistent .bhc-lesson-breadcrumb (course title link +
// "Lesson X of Y", computed from the course's saved _bhc_lesson_order)
// unconditionally, before the step walker, alongside the existing
// completion-gated nav which is unchanged. New CSS in courses.css.
// (2) Admin authoring required manual re-navigation both directions:
// the course screen's lesson list linked to a blank post-new.php (the
// author re-picked the course from a dropdown every time), and the
// lesson screen never linked back to its course despite having the ID
// already in scope. class-admin.php's render_course_metabox() now
// links to post-new.php?post_type=bh_lesson&bhc_course_id={id}; the
// new render_lesson_metabox() picks that up via $_GET (only when the
// lesson doesn't already have a saved course — never overrides real
// data) to pre-select the dropdown, and renders a real "&larr; Back to
// {course title}" link once a course is chosen instead of just
// descriptive text. php -l clean on both changed files; not yet
// run against real WordPress+MySQL in this pass.
// 0.4.16 — QA fix, part of the same ecosystem-wide ordering-tiebreaker
// sweep as bh-crm 1.4.0/own-ur-shit 3.4.86/bh-monetization-woo 0.4.12/
// bh-contest 3.5.2. class-crm-integration.php's activity_summary(): the
// course-completions query (ORDER BY completed_at DESC) had no id
// tiebreaker — a bulk/legacy import of completion records could
// plausibly land several in the same second, and this list is read
// top-to-bottom in the CRM activity summary. Fixed with `, id DESC`.

// 0.4.15 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 5a: WYSIWYG
// shortcode-to-block conversion, completing the pass across all four
// plugins (bh-monetization-woo 0.4.9-0.4.11, bh-contest 3.5.0,
// bh-streaming 0.5.4). Two new blocks via wp.serverSideRender
// (class-blocks.php, assets/js/bhc-blocks.js): 'bhc/catalog'
// ([bh_courses], no attributes) and 'bhc/course' ([bh_course], an
// Inspector course picker). Unlike bh-contest's/bh-streaming's blocks
// (a JS-hydrated mount div), both of these render REAL, complete
// server-side HTML already — the catalog grid and a course's full
// detail page — so ServerSideRender shows the actual final content
// directly, not a container shell. Old shortcodes untouched.
// Same has_block()-alongside-has_shortcode() fix already applied
// preemptively to bh-streaming 0.5.4 before it shipped, applied here
// the same way — class-render.php's asset-enqueue gate now checks both.
// RUNTIME-VERIFIED end to end: confirmed both blocks registered and
// rendering the real course content via the exact REST block-renderer
// endpoint the editor calls, then built a real page with the bhc/course
// block and loaded it in a live browser — confirmed courses.css/
// courses.js correctly enqueued (has_block() fix working) and the real
// course page ("Test Block Course," "0 lessons") rendered correctly
// with zero console errors. Test course/page cleaned up afterward.
define('BHC_PATH', plugin_dir_path(__FILE__));
define('BHC_URL',  plugin_dir_url(__FILE__));

/**
 * A genuine PEER to bh-contest, bh-streaming, and bh-crm — depends only
 * on own-ur-shit (shared identity, for enrollment/progress; shared
 * style tokens, for rendering). Deliberately does NOT depend on
 * bh-streaming or bh-monetization-woo:
 *
 * - bh-monetization-woo is optional, checked via class_exists() at
 *   init time (never at file-parse time — see every other plugin in
 *   this ecosystem for why), exactly the relationship bh-streaming
 *   already has with it. If it's active, a course can be tier-gated
 *   via the exact same generic paywall (`_bhm_required_tier` +
 *   `BHM_Gate::user_has_tier_access()`) class-gate.php's own docblock
 *   said this plugin would eventually use. If it isn't active, courses
 *   are simply open — no gate, same graceful degradation bh-streaming
 *   shows without it.
 * - No relationship to bh-streaming at all. A lesson step can EMBED
 *   audio/video (plain HTML5 media, or an oEmbed URL), but never reads
 *   bh-streaming's own catalog tables directly.
 */
foreach (['post-types', 'activator', 'admin', 'steps', 'progress', 'progress-admin', 'nudges', 'gate', 'render-catalog', 'render-course', 'render-lesson', 'render', 'style-surface', 'lesson-surface', 'crm-integration', 'debug', 'test-suite', 'content-bridge', 'portal-panel', 'comments', 'certificates', 'share-cards', 'blocks'] as $f) {
    require_once BHC_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHC_Activator', 'activate']);
add_action('plugins_loaded', ['BHC_Activator', 'maybe_upgrade']);

add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Courses</strong> requires the <strong>Own Ur Shit</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    add_action('init', ['BHC_PostTypes', 'register']);
    add_action('init', ['BHC_Render', 'init']);
    // QA fix, caught live via WP_DEBUG_LOG: same fix as bh-contest's
    // BH_Blocks/bh-streaming's BHS_Blocks — hooked normally at 'init'
    // instead of called directly at plugins_loaded time.
    add_action('init',          ['BHC_Blocks', 'init']);
    add_action('init', ['BHC_Progress', 'init']);
    add_action('init', ['BHC_Debug', 'init']);
    add_action('init', ['BHC_StyleSurface', 'init']);
    // DESIGN-SUITE-UNIFICATION-PLAN.md — the "1" in AJ's "Do 3, then 2,
    // then 1" ordering (3 = data-binding v1, 2 = Gutenberg block, both
    // already shipped in own-ur-shit 3.4.46/3.4.47). First real
    // BH_Element surface this plugin has ever registered — see class-
    // lesson-surface.php's own docblock for the full reasoning. Same
    // "harmless no-op otherwise" guard every other optional integration
    // in this bootstrap uses.
    if (class_exists('BH_Element')) {
        add_filter('bh_element_surfaces', ['BHC_LessonSurface', 'register_element_surface']);
    }
    add_action('init', ['BHC_CrmIntegration', 'init']);
    add_action('init', ['BHC_ProgressAdmin', 'init']);
    add_action('init', ['BHC_Nudges', 'init']);
    if (class_exists('OUS_TestRunner')) add_action('init', ['BHC_TestSuite', 'init']);
    if (class_exists('BH_Content')) add_action('init', ['BHC_ContentBridge', 'init']);
    add_action('init', ['BHC_PortalPanel', 'init']);
    add_action('init', ['BHC_Comments', 'init']);
    add_action('init', ['BHC_Certificates', 'init']);
    add_action('init', ['BHC_ShareCards', 'init']);
    add_filter('the_content', function ($content) {
        if (get_post_type() === 'bh_lesson' && is_singular('bh_lesson') && in_the_loop() && is_main_query()) {
            return $content . BHC_Render::render_lesson_steps(get_the_ID());
        }
        return $content;
    });

    add_action('add_meta_boxes', ['BHC_Admin', 'add_meta_boxes']);
    add_action('save_post_bh_course', ['BHC_Admin', 'save_course']);
    add_action('save_post_bh_course', ['BHC_Admin', 'save_catalog_details']);
    add_action('save_post_bh_lesson', ['BHC_Admin', 'save_lesson']);
    add_action('admin_enqueue_scripts', ['BHC_Admin', 'enqueue_admin_assets']);
    add_filter('manage_bh_course_posts_columns', ['BHC_Admin', 'course_columns']);
    add_action('manage_bh_course_posts_custom_column', ['BHC_Admin', 'course_column_content'], 10, 2);
    add_filter('manage_bh_lesson_posts_columns', ['BHC_Admin', 'lesson_columns']);
    add_filter('post_row_actions', ['BHC_Admin', 'lesson_row_actions'], 10, 2);
    add_filter('post_row_actions', ['BHC_Admin', 'course_row_actions'], 10, 2);
    add_action('admin_post_bhc_duplicate_lesson', ['BHC_Admin', 'handle_duplicate_lesson']);
    add_action('admin_post_bhc_unassign_lesson', ['BHC_Admin', 'handle_unassign_lesson']);
    add_action('admin_post_bhc_duplicate_course', ['BHC_Admin', 'handle_duplicate_course']);
    add_action('manage_bh_lesson_posts_custom_column', ['BHC_Admin', 'lesson_column_content'], 10, 2);
    add_action('before_delete_post', ['BHC_Admin', 'cleanup_deleted_course']);
    add_action('before_delete_post', ['BHC_Admin', 'cleanup_deleted_lesson']);

    add_action('wp_ajax_bhc_submit_quiz', ['BHC_Progress', 'ajax_submit_quiz']);
    add_action('wp_ajax_bhc_mark_complete', ['BHC_Progress', 'ajax_mark_complete']);
    add_action('wp_ajax_bhc_update_watch_progress', ['BHC_Progress', 'ajax_update_watch_progress']);
});

// Self-registration into the Own Ur Shit dashboard — zero changes
// needed to the core, same filter contract documented in the core's
// own class-registry.php.
add_filter('ous_registered_plugins', function ($plugins) {
    $plugins['bh-courses'] = [
        'label' => 'BH Courses',
        'file' => 'bh-courses/bh-courses.php',
        'depends_on' => [],
        'check_class' => 'BHC_PostTypes',
        'description' => 'Courses built from ordered, multistep lessons (text, images, quizzes) with progress tracking and optional supporter-tier gating.',
        'dashboard_link' => 'edit.php?post_type=bh_course',
        'bundled_zip' => 'bh-courses.zip',
        // No 'admin_menus' entry — Courses/Lessons are CPT list-tables
        // (like bh-contest's Contests, bh-streaming's Tracks), which the
        // ecosystem's own convention keeps as their own top-level menu
        // rather than relocating (see class-registry.php's docblock).
    ];
    return $plugins;
});

// Debug Tools section — same shared page every other plugin uses.
add_filter('ous_debug_tools', function ($tools) {
    $tools['bh-courses'] = [
        'label'  => 'BH Courses',
        'render' => ['BHC_Debug', 'render_section'],
        'handle' => ['BHC_Debug', 'handle_action'],
        'reset'  => ['BHC_Debug', 'reset'],
        'group'  => OUS_Debug::GROUP_SEED_RESET,
    ];
    return $tools;
});
