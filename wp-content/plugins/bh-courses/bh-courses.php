<?php
/**
 * Plugin Name: BH Courses
 * Description: Courses made of ordered, multistep/multipart lessons — text, images, and quizzes/progress-checks in any sequence — with per-student progress tracking and optional supporter-tier gating via BH Monetization. Depends only on Own Ur Shit's shared identity.
 * Version:     0.4.79
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

// 0.4.79 — Real product decision, caught live: a course with no
// required tier was fully viewable by a logged-OUT visitor — clicking
// "Mark complete" or a quiz submit button just failed with a confusing
// generic error, because the tier gate (BHM_Gate::user_has_tier_access)
// only asks "is the tier requirement satisfied," which is vacuously
// true when no tier is set at all. Login and tier are different
// questions this plugin was conflating.
//
// BHC_Render_Course::render_course() and BHC_Render_Lesson::
// render_lesson_steps() now check own-ur-shit's new OUS_Visibility
// (3.10.22) FIRST, separately from BHC_Gate's tier check — a course
// defaults to requiring a logged-in account to view at all, same as
// anything ordinarily meant for an audience rather than an anonymous
// visitor. A new "Public — anyone can view without logging in"
// checkbox on the course's own Login requirement metabox section is
// the explicit per-course opt-out (class-admin.php,
// OUS_Visibility::checkbox_field()/save_from_request()). Deliberately
// NOT applied to bh-contest — a contest's whole design depends on
// being publicly viewable/shareable; that's a separate, explicit
// product decision left for later, not a side effect of this fix.
//
// Also fixed, found while double-checking the "Mark complete and
// continue" flow specifically wasn't ALSO broken in some other way:
// courses.ts's bhc_mark_complete/bhc_submit_quiz response handlers only
// special-cased a bare "-1" (a stale-nonce wp_die()) with a clear "log
// in" message — admin-ajax.php's actual response for a logged-out
// visitor hitting an action with no wp_ajax_nopriv_* handler is a bare
// "0", which fell through to a generic "Something went wrong." Now
// treated the same as "-1". Mostly defense-in-depth now that the lesson
// itself requires login to reach at all, but still a real gap for a
// session that expires mid-lesson. Recompiled via `npx tsc`.
//
// php -l clean, scoped PHPStan level 6 clean. NOT runtime-verified
// against a live install by this commit alone — verify by viewing an
// ungated course/lesson while logged out (should show a login prompt,
// not the real content), then toggling "Public" on and confirming it
// opens back up.

// 0.4.78 — Added a help tooltip (BHY_UI::tip(), own-ur-shit 3.10.15) to
// the "Gate by tier price rank" select on a course's Supporter access
// metabox, clarifying the price-rank rule: selecting a tier here grants
// access to that tier AND every higher-priced tier, not just the exact
// one picked — not obvious from the select alone. Part of this
// session's first pass at in-context tooltips, not a full sweep.

// 0.4.77 — Fixed the course seed's placeholder video URL. Found live on
// billyhume.wasmer.app while verifying the video step end-to-end:
// Google's old public sample bucket
// (commondatastorage.googleapis.com/gtv-videos-bucket) now returns 403,
// so the seeded video step rendered a real <video> element with real
// controls (confirmed the actual bh-courses video-step renderer and
// player chrome are correct) but the source itself failed to load.
// Swapped to w3schools.com/html/mov_bbb.mp4, confirmed loadable
// (loadedmetadata fires, duration=10.03s) directly from the deployed
// site before committing. Not this plugin's bug — a dead third-party
// URL — but worth fixing since a demo course with a broken video looks
// like a real defect to anyone clicking through it.

// 0.4.76 — Follow-up to 0.4.75's fleshed-out course seed: two display
// strings in BHC_Debug (the seed button's label and its post-click
// confirmation message) still hardcoded "2 lessons" from before that
// change, even though seed_course() itself was already correctly
// building all 5. Caught live on billyhume.wasmer.app right after
// deploying 0.4.75 — the seeded course itself was correct (verified:
// all 5 real lessons existed), only the two UI strings were stale.
// NOT a functional bug, cosmetic only.

// 0.4.75 — Fleshed out the Debug Tools course seed (BHC_Debug::
// seed_course()) from a thin 2-lesson/4-step demo into a real 5-lesson
// showcase course exercising most of the step-type vocabulary: text,
// image, a direct-URL video step (real public sample MP4 — this was
// the only seeded content that actually exercised the video step
// renderer end-to-end before now), quiz, callout (all three variants),
// checklist, and a chord-chart. Deliberately did NOT seed 'resource' or
// 'audio-compare' steps — both require a real, non-zero attachment_id
// (BHC_Steps::save() silently drops the whole step otherwise, by
// design, since a resource/comparison with no real file "has nothing
// to offer"), and this seed tool has no real media library asset to
// attach without also faking an upload. Left as a known gap rather
// than seeding a placeholder id that would just get silently dropped.
// NOT runtime-verified against a live install by this commit alone —
// verify by clicking "Seed a complete test course" on a real site and
// confirming all 5 lessons/every step type renders.

// 0.4.74 — Dead-code sweep (Phase 4, shipmonk/dead-code-detector v0.5.1
// against the full ecosystem, manually triaged finding-by-finding
// before deleting anything). Removed BHC_PostTypes::step_count() — a
// genuinely uncalled aggregation helper (lesson_count() nearby is real
// and used; step_count() had no caller anywhere, only a comment
// reference in class-progress-admin.php). Confirmed a look-alike
// candidate, BHC_Render::render_quiz_review(), is NOT dead despite no
// current caller — its own docblock explicitly documents it as a
// deliberate one-line backward-compat delegate preserving this class's
// public API surface post-refactor (0.4.8), kept on purpose. NOT
// runtime-verified against a live install; this is a pure removal of
// unreferenced code, same risk shape as the rest of this sweep.

// 0.4.73 — Real bug fix surfaced by own-ur-shit's own final PHPStan
// level 6 brick (typing OUS_Debug::button() with a real `: void`
// return): class-debug.php here was calling it as `echo
// OUS_Debug::button(...)` at 5 call sites, double-printing every debug-
// tools button on this plugin's own Debug Tools section — button()
// already echoes its own markup internally, the wrapping `echo` was
// pure extraneous output. Fixed by dropping the `echo`. Also fixed:
// class-content-bridge.php's migrate_lesson() was declared `: bool`
// but returned BH_Content::save()'s real array result unchanged (a
// dangling type mismatch from the bh-courses PHPStan brick, 0.4.72,
// only surfaced once own-ur-shit's BH_Content::save() itself got a
// precise array-shape return type) — cast to `(bool)` at the return,
// matching its one caller's actual ignored-return-value usage. NOT
// runtime-verified against a live install; smoke-test the Debug Tools
// page to confirm buttons render once, not twice.

// 0.4.72 — Ecosystem quality Phase 2, brick 12/13: bh-courses is now
// clean at PHPStan level 6 (native return/parameter types + precise
// array-shape PHPDoc throughout every file in includes/, no shortcuts).
// 32 files, ~584 findings. Covers class-progress.php (the largest single
// file in this brick — enrollment, per-step completion, quiz scoring/
// answer snapshots, course-completion detection), class-admin.php
// (course/lesson authoring, duplication, list-table columns), class-
// render-course.php, class-gate.php (tier gating + drip scheduling),
// class-sessions.php (instructor availability/booking), class-
// reviews.php, class-render.php, class-post-types.php, class-
// achievements.php, class-progress-admin.php (batched Student Progress
// N+1 fix), class-content-bridge.php (the BH_Content block-tree bridge
// for lesson authoring), class-debug.php, class-steps.php (step
// sanitization/quiz scoring), class-render-catalog.php, class-test-
// suite.php, class-comments.php, class-render-lesson.php, class-
// privacy.php, class-certificates.php, class-video-settings.php,
// class-portal-panel.php, class-crm-integration.php, class-blocks.php,
// class-sessions-admin.php, class-leaderboard.php, class-instructor-
// notes.php, class-sessions-portal.php, class-style-surface.php,
// class-share-cards.php, class-nudges.php, class-drip-nudges.php,
// class-activator.php, class-lesson-surface.php. No behavior changes —
// a handful of esc_html()/get_posts() call sites needed an explicit
// (string) cast once their param picked up a native type, and one dead-
// code simplification (a redundant `count($steps) > 0` check where
// $steps was already provably non-empty). Scoped bh-courses PHPStan
// level 6 check and the full 12-plugin level 5 ecosystem check both
// come back clean.
// NOT runtime-verified against a live WordPress+MySQL install.
// 0.4.71 — TypeScript pilot: converted the two remaining large/risky
// files that were deliberately deferred in the previous round —
// courses-studio-blocks.js (Gutenberg block registration for the
// lesson-authoring block types; `wp` typed loosely as `any` given the
// size of the wp.components/blockEditor surface it touches, real types
// everywhere else) and courses.js (the full lesson stepper: step
// navigation, video watch-progress, interactive video annotations,
// quiz submission — real types throughout, no @ts-nocheck escape
// hatch, since a build-time check that doesn't actually check
// anything defeats the point of doing this). Added "DOM.Iterable" to
// this plugin's tsconfig.json lib list (needed for `for...of
// formData.entries()`, a real gap the previous tsconfig didn't need
// until this file). Every compiled .js diff was reviewed line-by-line
// against the original — the only behavioral deltas are type-safety
// shims (`?? fallback` on dataset reads, explicit String() coercion
// into URLSearchParams, which already stringified those values at
// runtime either way) — no logic changed.
// NOT runtime-verified against a live browser this session.
// 0.4.70 — TypeScript pilot: this plugin's FIRST pass (no assets/ts/
// existed before this pass) — added tsconfig.json (identical shape to
// every other plugin's) and build:bh-courses/watch:bh-courses npm
// scripts in the repo-root package.json, then converted admin.ts
// (lesson-order drag-reorder — also deleted ~220 lines of dead legacy
// multistep lesson-builder code that self-guarded on a container that's
// been absent since lesson authoring moved to the real Gutenberg block
// editor), sessions-admin.ts (FullCalendar month-view render), and
// bhc-blocks.ts (bhc/catalog, bhc/course block registration). Same
// posture as every other plugin's TS pilot entry this session: plain
// `tsc`, no bundler, compiled .js committed, run
// `npm run build:bh-courses` after editing any .ts file.
// courses.js (755 lines) and courses-studio-blocks.js (708 lines)
// deliberately NOT converted this pass — flagged for a dedicated future
// pass with real browser verification, not attempted blind.
// NOT runtime-verified against a live browser this session.
// 0.4.69 — PHPStan round 2 (this plugin went from 38 errors to 0). 37 of
// the 38 were the same one cause: FPDF (own-ur-shit/vendor/fpdf/fpdf.php,
// used by class-certificates.php for certificate-of-completion PDFs) is
// a real, vendored library, just not composer-installed, so PHPStan
// couldn't resolve it at all — added to phpstan.neon's scanFiles so it's
// now actually type-checked instead of reported as one giant unknown-
// class block. The other two: a redundant `?? []` on WP_Query::$posts
// (non-nullable per the stub) in class-render-catalog.php, and the same
// redundant class_exists('BH_ShareCard') re-check pattern already fixed
// in bh-contest this same pass — an earlier wp_die() a few lines above
// already guarantees the class exists.
// NOT runtime-verified against a live install — confirmed via a real
// `vendor/bin/phpstan analyse` run. `php -l` clean.

// 0.4.68 — Real bugs found by a proper `composer install && vendor/bin/
// phpstan analyse` run (repo-root phpstan.neon, level 5 — this codebase's
// PHPStan/TS pilot bootstrap was written in a sandbox with no GitHub
// access to actually run it; this is the first real run). (1) class-
// content-bridge.php's Debug Tools "rebuild lesson content" action called
// `check_admin_referer($action, $query_arg, false)` — that function only
// takes 2 params and, unlike check_ajax_referer(), has no non-dying mode:
// an invalid nonce always hard wp_die()s regardless of the (silently
// ignored) third argument. Switched to wp_verify_nonce() so an invalid/
// missing nonce is a graceful no-op instead of a hard site error. (2)
// class-debug.php's three seed helpers (seed_course/seed_lesson and the
// tier-seeding branch) checked `is_wp_error($id)` on wp_insert_post()'s
// return value — wp_insert_post() only returns WP_Error when called with
// $wp_error=true (4th arg), which none of these calls do; it actually
// returns 0 on failure, so the error branch could never fire. Changed to
// a falsy check. `php -l` clean on both files. Runtime-verified live
// against localhost:10008: the Debug Tools "populate lesson content
// from steps" action now shows "Rebuilt 7 lesson(s)" instead of a hard
// nonce-failure die.

// 0.4.67 — OSS-integration master plan Phase 6 follow-up: Cloudflare
// Stream wired into the video step as a real third source alongside
// upload/url (class-steps.php's own comments had already named this as
// the intended use case for the 'url' branch — this gives it a real,
// separate source value instead, since a Stream video UID and a raw
// embed URL are different enough shapes to validate/render distinctly).
// A step gains 'cloudflare_stream'/'stream_uid' (class-steps.php,
// validated as a real 32-char hex UID, never trusted free text);
// class-render-lesson.php renders Cloudflare Stream's own iframe embed
// (the simple, zero-extra-JS first cut — an hls.js-backed <video> via
// OUS_MediaWizard::enqueue_hls_js() can follow once this is proven);
// courses-studio-blocks.js's Source picker only offers the option when
// Tier B is actually enabled (OUS_MediaWizard::tier_b_enabled(),
// localized via class-content-bridge.php's new wp_localize_script()
// call as window.bhcMediaTierB) — an install that never opted into
// Tier B never sees it. class-content-bridge.php's bhc/video schema
// gained the matching 'stream_uid' key so it round-trips through the
// block-tree<->legacy-steps conversion.
// Explicitly NOT built this pass: an in-plugin "upload straight to
// Cloudflare Stream" flow — v1 requires pasting back a UID from a
// manual upload via Cloudflare's own dashboard/API. A real upload flow
// (Stream's TUS-resumable-upload protocol, progress UI) is a separate,
// bigger piece, flagged honestly rather than attempted here.
// bh-video and bh-streaming remain explicitly out of scope for this
// pass too (see ROADMAP-hyperpress-migration.md's sibling plan doc /
// this session's own research: bh-courses was the only plugin with an
// existing source-discriminator concept to extend; the other two would
// each need that introduced from scratch).
// NOT runtime-verified against a live WordPress+MySQL install this
// session, and specifically never tested against a real Cloudflare
// Stream account/UID. `php -l` clean; `node -c` clean on
// courses-studio-blocks.js.

// 0.4.66 — Phase 5 of the OSS-integration master plan: 1:1 session
// scheduling, the "smallest real version" from ROADMAP-lms-instructor-
// student-depth.md §1 — an instructor publishes open time slots
// (class-sessions-admin.php, new "Sessions" submenu under Courses), a
// student books one from a new "Sessions" portal panel (class-sessions-
// portal.php). New bhc_sessions table (class-sessions.php,
// BHC_Sessions::activate()/maybe_upgrade()) — a slot's lifecycle
// (open -> booked -> completed/cancelled) is its own small state
// machine, same "a table when it doesn't fit post/meta" convention
// bh-crm's bhcrm_notes/bhcrm_projects already established. Booking uses
// the exact same one-row-conditional-UPDATE claim idiom as bh-feedback's
// BHF_Queue::claim() — status flips open -> booked only if it's STILL
// open right then, so two students can't double-book the same slot.
// Decisions locked in this session (AJ): single-instructor v1
// (instructor_id defaults to whoever holds bhcore_manage_students, no
// picker UI); real OUS_Notifications on booking AND cancellation; a
// slot CAN be tied to a course (optional picker in the admin create-
// slot form, per the roadmap doc's data model); student self-cancel is
// allowed but blocked within a configurable cutoff (default 24h,
// 'bhc_session_cancel_cutoff_hours' filter) — staff cancellation has no
// such restriction.
// New vendored dependency: FullCalendar v7.0.2 (MIT, real bytes from its
// official GitHub release, assets/js/vendor/fullcalendar.global.js) —
// the free Standard tier's all-in-one global bundle only, deliberately
// no resource/timeline views (those need a paid Premium license, and
// aren't needed for a single-instructor calendar). Renders a read-only
// month view on the Sessions admin screen from server-rendered JSON —
// plain vanilla JS (assets/js/sessions-admin.js), not Datastar, since
// there's no live server round-trip involved in that render.
// NOT runtime-verified against a live WordPress+MySQL install this
// session. `php -l` clean on every touched/new PHP file; the vendored
// FullCalendar bundle's JS syntax was checked with `node -c`.

// 0.4.38 — ecosystem depth-pass Tier 1c: BHC_PortalPanel registers the
// first real bhi_user_bar_links contributor (own-ur-shit's new
// class-user-bar.php) — "Continue: <course title>" with a live percent
// micro-state, only when there's an actual in-progress enrolled course
// to continue, never a placeholder.

// 0.4.37 — LMS depth-of-magic Phase 4 (final phase): ecosystem-wide
// achievement surfacing. BHC_Achievements now feeds the real
// bhi_profile_badges filter (own-ur-shit's public-profile page), and a
// new opt-in BHC_Leaderboard shows a course's top quiz scorers —
// rank/name/score rows with emoji medals for the top 3, mirroring
// bh-contest's own reveal display without sharing code with it. Off by
// default per course, same posture as Lesson Q&A/certificates.

// 0.4.36 — LMS depth-of-magic Phase 2c: three new step types (checklist,
// chord/tab chart, audio A/B compare), scoped directly from AJ's own
// answer on what's actually missing for THIS content (music production/
// songwriting courses), not a generic "add more block types" guess. All
// three non-blocking, same Mark-complete-and-continue pattern as every
// other non-quiz step.

// 0.4.35 — LMS depth-of-magic Phase 3: cross-course mastery. A new
// bhc_achievements table (BHC_Activator 1.5) and BHC_Achievements class
// award a small, fixed set of real, persistent badges — first quiz
// aced, completed a course with distinction, 3 courses mastered —
// hooked off events that already exist (mark_step_complete()'s quiz-
// score path, the bhc_course_completed action), surfaced on the My
// Courses portal panel. First genuinely new schema this plugin's
// depth-of-magic pass has needed.

// 0.4.34 — LMS depth-of-magic Phase 2b: a real hero treatment for a
// course's own landing page. A cover image now earns a full-width banner
// with the title overlaid on a gradient scrim (only when a cover is
// actually set — obvious-or-gone, no hero styling forced on a plain
// title); the instructor moved out of the flat meta line into its own
// pulled-forward row with a larger avatar. Also fixes a real, caught-live
// duplicate: the theme's own core/post-featured-image block was still
// printing the same cover image, undecorated, directly above this new
// hero.

// 0.4.33 — LMS depth-of-magic Phase 2a: a new `bhc/callout` step type for
// visual density within a lesson (a "here's the key idea" / "watch out for
// this" moment), three fixed variants (tip/note/warning) rather than a
// free-text style field, same non-blocking Mark-complete-and-continue
// pattern as every other non-quiz step.

// 0.4.14 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 4b: real video
// progress tracking. A course creator can now set a per-video-step "require N%
// watched" threshold (bhc/video's new watch_threshold attribute, Studio block
// RangeControl) — 0 keeps today's behavior (any playback + a manual click
// completes it) unchanged.

// 0.4.13 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 4a: certificate of
// completion. Studied LifterLMS's own Achievements/ Engagements architecture
// first (trigger→handler dispatch table) before writing anything — concluded
// WordPress's own `bhc_course_completed` action (already fired exactly once per
// user/course by class-progress.php's maybe_fire_course_completed()) already IS
// that extension point, so no bespoke "engine"/registry class was added; see
// class-certificates.php's own docblock for the full reasoning.

// 0.4.8 — 2026-07-12 — SOLID/SRP QA pass on class-render.php: a single 589-line
// class was rendering the catalog, the course detail page, AND the lesson step-
// walker/quiz UI — three genuinely separate concerns. Split into new class-
// render-catalog.php (BHC_Render_Catalog), class-render-course.php
// (BHC_Render_Course), and class-render-lesson.php (BHC_Render_Lesson) — pure
// moves, byte-for-byte identical logic, no behavior change.

// 0.4.2 — BHC_TestSuite gained real DB-backed coverage for quiz answer storage
// (mark_step_complete()/stored_answers() round-trip, latest- attempt-only retry
// semantics, the NULL-vs-0 sanitization behavior) and the course catalog's
// search/sort (real fixture posts, cleaned up after each run) — both previously
// untested. Verified 2026-07-19: all 36 assertions across this suite (the two
// pure-logic tests/ files plus these DB-backed ones) pass against a real
// install — the earlier "not yet executed" caveat is resolved.

// 0.4.1 — first OUS_DebugLog call anywhere in this plugin:
// BHC_Progress::mark_step_complete()'s DB write is now checked — a failed write
// previously still let the student-facing flow report "step complete" with the
// failure completely invisible. Standing caveat: reasoning/brace-balance-checked
// only.

// 0.3.0 — LMS lesson-flow authoring wired onto BH_Studio/BH_Content (see LMS-
// AUTHORING-DESIGN-PLAN.md): bhc/* block types registered with the Studio
// canvas, bhc/quiz promoted to a real container of bhc/quiz-question child
// blocks, and the legacy steps-repeater metabox replaced with a link into
// Content Studio (closing the dual-write hazard the design doc flagged — see
// class-content-bridge.php and class-admin.php). 0.3.1 — six queued LMS UX fixes
// from an honest-assessment pass, all additive/routine (no architectural
// changes): a course-level "Continue/ Start/Review" CTA on the catalog card +
// course page (BHC_Progress::first_incomplete_lesson(), class-render.php); "Next
// Lesson →" navigation once a lesson's last step completes, instead of silently
// stranding the student (class-render.php + courses.js); a step-walker back
// button, including revisiting a passed quiz in a read-only review state (note:
// this reviews PASS/FAIL + question list only, not the student's exact original
// answer choices — bhc_progress never stored the submitted-answers array, and
// adding that is a real schema addition deliberately left out of this pass);
// per-step content labels replacing the type-only summary in the lesson metabox
// (BHC_Admin::describe_step()); a "Preview as student" link next to the Studio
// button; and a manual-override "mark complete" action on the Student Progress
// admin page for the ordinary support-request case
// (BHC_ProgressAdmin::maybe_handle_override()).
define('BHC_VER',  '0.4.79');
// QA fix (2026-07-21, caught live during Phase 1 LMS-v3 video-overlay
// verification): this constant is what actually cache-busts every
// enqueued JS/CSS file (wp_enqueue_script/style's $ver arg) — the
// "Version:" header comment at the top of this file is a SEPARATE
// string that WordPress reads for the plugin list/updates, not this.
// The two drifted across this entire session's LMS depth-of-magic
// pass: the header comment was bumped at every phase (0.4.33-0.4.37),
// this constant was not, so every JS/CSS change since 0.4.32 was
// silently served stale from any browser that had already cached the
// old file — confirmed live (a shipped courses.js feature simply
// didn't run, traced to the enqueued <script> tag still reading
// ?ver=0.4.32). bh-contest's BH_VER/own-ur-shit's OUS_VER don't have
// this problem only because they happened to stay in sync by
// discipline, not because either is derived from the header
// automatically — same manual-duplicate-constant convention, same
// risk. Bump this constant in the SAME edit as the header from now on,
// not as an afterthought.

// 0.4.28 — retry-audit pass, AJ's own standing ask (assets/js/courses.js): (1)
// "Mark complete" step-completion now has real retry-with-backoff (matching own-
// ur-shit's class-reports.php reference pattern) — previously had NO .catch() at
// all, so a dropped connection silently failed with zero feedback. Safe to
// retry: the server side is an upsert on lesson_id+step_index, not an insert-
// only log. (2) Quiz submission gets the OPPOSITE fix — the submit button is now
// disabled the instant the form submits (re-enabled only on a real failure),
// since a quiz submission burns a real attempt server-side per call and was
// previously vulnerable to a double-submit (double-click, or a slow connection)
// silently costing a student an attempt.

// 0.4.27 — ROADMAP-discoverability.md Section 3's own per-content-type
// schema.org plan: BHC_Render_Course::render_course() now calls
// BH_SEO::set_page_data() with a real Course/CourseInstance JSON-LD block (name,
// description, image, provider, instructor) — the second real BH_SEO consumer
// after BHI_PublicProfile's Person block, and the first for actual content
// rather than an identity page. class_exists()- guarded; does nothing if own-ur-
// shit's BH_SEO isn't present. Verified live: a real published course rendered
// exactly one JSON-LD Course block and one canonical tag (no duplicate-canonical
// regression).

// 0.4.26 — First real contributor to own-ur-shit's new shared Metrics dashboard
// (OUS_Metrics, class-metrics.php): three widgets in includes/class-crm-
// integration.php (Enrollments, Course completions, Avg. quiz score), built in
// tandem with that dashboard per AJ's own "foundational infrastructure, not a
// bolt-on" instruction. Reads bhc/enroll and bhc/course_completed events already
// flowing — no new instrumentation added. class_exists()-guarded; does nothing
// if own-ur-shit's metrics class isn't present.

// 0.4.25 — Whole-course duplication ("Duplicate this course as a template") — a
// fresh audit against Teachable/Thinkific/Kajabi/ LearnDash/LifterLMS flagged
// this as the most-common missing instructor tool: only per-lesson duplication
// existed before this. New "Duplicate" row action on the Courses list
// (course_row_actions()/ handle_duplicate_course()) clones the course post, its
// catalog/ gating/certificate/share-card meta, its categories/topics/featured
// image, and every one of its lessons — each lesson gets its own independent
// clone (same core copy logic handle_duplicate_lesson() already uses, never
// shared IDs between two courses), rebuilt into a fresh _bhc_lesson_order for
// the new course.

// 0.4.15 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 5a: WYSIWYG
// shortcode-to-block conversion, completing the pass across all four plugins
// (bh-monetization-woo 0.4.9-0.4.11, bh-contest 3.5.0, bh-streaming 0.5.4). Two
// new blocks via wp.serverSideRender (class-blocks.php, assets/js/bhc-
// blocks.js): 'bhc/catalog' ([bh_courses], no attributes) and 'bhc/course'
// ([bh_course], an Inspector course picker).
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
foreach (['post-types', 'activator', 'admin', 'steps', 'progress', 'achievements', 'leaderboard', 'progress-admin', 'instructor-notes', 'video-settings', 'nudges', 'drip-nudges', 'gate', 'render-catalog', 'render-course', 'render-lesson', 'render', 'style-surface', 'lesson-surface', 'crm-integration', 'debug', 'test-suite', 'content-bridge', 'portal-panel', 'comments', 'certificates', 'share-cards', 'blocks', 'reviews', 'privacy', 'sessions', 'sessions-admin', 'sessions-portal'] as $f) {
    require_once BHC_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHC_Activator', 'activate']);
register_activation_hook(__FILE__, ['BHC_Sessions', 'activate']);
add_action('plugins_loaded', ['BHC_Activator', 'maybe_upgrade']);
add_action('plugins_loaded', ['BHC_Sessions', 'maybe_upgrade']);

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
    add_action('init', ['BHC_Achievements', 'init']);
    add_action('init', ['BHC_Privacy', 'init']);
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
    add_action('init', ['BHC_InstructorNotes', 'init']);
    add_action('init', ['BHC_VideoSettings', 'init']);
    add_action('admin_notices', ['BHC_VideoSettings', 'maybe_show_notice']);
    add_action('init', ['BHC_Nudges', 'init']);
    add_action('init', ['BHC_DripNudges', 'init']);
    if (class_exists('OUS_TestRunner')) add_action('init', ['BHC_TestSuite', 'init']);
    if (class_exists('BH_Content')) add_action('init', ['BHC_ContentBridge', 'init']);
    add_action('init', ['BHC_PortalPanel', 'init']);
    add_action('init', ['BHC_Comments', 'init']);
    add_action('init', ['BHC_Certificates', 'init']);
    add_action('init', ['BHC_ShareCards', 'init']);
    add_action('init', ['BHC_Reviews', 'init']);
    add_action('init', ['BHC_Gate', 'init']);
    add_action('init', ['BHC_SessionsAdmin', 'init']);
    add_action('init', ['BHC_SessionsPortal', 'init']);
    add_action('init', function () {
        if (class_exists('BH_Event')) {
            BH_Event::register_event_type('bhc/session_booked', ['starts_at' => 'string', 'instructor_id' => 'int']);
        }
    }, 20);
    add_filter('the_content', function ($content) {
        if (get_post_type() === 'bh_lesson' && is_singular('bh_lesson') && in_the_loop() && is_main_query()) {
            return $content . BHC_Render::render_lesson_steps(get_the_ID());
        }
        // Real gap: a course's own permalink (bh_course singular) never
        // rendered anything but the theme's generic title/excerpt — no
        // lesson list, no progress bar, no enroll CTA. render_course()
        // already builds all of that (used by the [bh_course] shortcode
        // and the Gutenberg block), it just was never wired to the CPT's
        // own single view the way bh_lesson is above. A static reentrancy
        // guard is required here: render_course_header() itself calls
        // apply_filters('the_content', ...) on the post's raw content to
        // render the description, which would otherwise re-enter this
        // same callback and recurse.
        static $rendering_course = false;
        if (!$rendering_course && get_post_type() === 'bh_course' && is_singular('bh_course') && in_the_loop() && is_main_query()) {
            $rendering_course = true;
            $out = BHC_Render::render_course(['id' => get_the_ID()]);
            $rendering_course = false;
            return $out;
        }
        return $content;
    });

    add_action('add_meta_boxes', ['BHC_Admin', 'add_meta_boxes']);
    add_action('add_meta_boxes_page', ['BHC_Admin', 'add_page_backlink_meta_box']);
    add_action('save_post_bh_course', ['BHC_Admin', 'save_course']);
    add_action('save_post_bh_course', ['BHC_Admin', 'save_catalog_details']);
    add_action('save_post_bh_course', ['BHC_Admin', 'save_site_menu_settings']);
    add_action('admin_post_bhc_create_page', ['BHC_Admin', 'create_course_page_action']);
    add_action('wp_trash_post', ['BHC_Admin', 'maybe_resync_menu_for_post']);
    add_action('untrash_post', ['BHC_Admin', 'maybe_resync_menu_for_post']);
    add_action('before_delete_post', ['BHC_Admin', 'maybe_resync_menu_for_post']);
    add_action('save_post_bh_lesson', ['BHC_Admin', 'save_lesson']);
    add_action('admin_enqueue_scripts', ['BHC_Admin', 'enqueue_admin_assets']);
    // DRY/SOLID audit Phase 4: migrated to the shared OUS_ListTable
    // helper (own-ur-shit/includes/class-list-table.php) — same column
    // set/position/render logic as the previous hand-rolled columns()/
    // custom_column() pairs.
    OUS_ListTable::register('bh_course', ['bhc_lessons' => 'Lessons', 'bhc_gate' => 'Access'], ['BHC_Admin', 'course_column_content']);
    OUS_ListTable::register('bh_lesson', ['bhc_course' => 'Course'], ['BHC_Admin', 'lesson_column_content']);
    add_filter('post_row_actions', ['BHC_Admin', 'lesson_row_actions'], 10, 2);
    add_filter('post_row_actions', ['BHC_Admin', 'course_row_actions'], 10, 2);
    add_action('admin_post_bhc_duplicate_lesson', ['BHC_Admin', 'handle_duplicate_lesson']);
    add_action('admin_post_bhc_unassign_lesson', ['BHC_Admin', 'handle_unassign_lesson']);
    add_action('admin_post_bhc_duplicate_course', ['BHC_Admin', 'handle_duplicate_course']);
    add_action('before_delete_post', ['BHC_Admin', 'cleanup_deleted_course']);
    add_action('before_delete_post', ['BHC_Admin', 'cleanup_deleted_lesson']);

    add_action('wp_ajax_bhc_submit_quiz', ['BHC_Progress', 'ajax_submit_quiz']);
    add_action('wp_ajax_bhc_mark_complete', ['BHC_Progress', 'ajax_mark_complete']);
    add_action('wp_ajax_bhc_update_watch_progress', ['BHC_Progress', 'ajax_update_watch_progress']);
    add_action('wp_ajax_bhc_submit_review', ['BHC_Reviews', 'ajax_submit_review']);
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
