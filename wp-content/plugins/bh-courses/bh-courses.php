<?php
/**
 * Plugin Name: BH Courses
 * Description: Courses made of ordered, multistep/multipart lessons — text, images, and quizzes/progress-checks in any sequence — with per-student progress tracking and optional supporter-tier gating via BH Monetization. Depends only on Own Ur Shit's shared identity.
 * Version:     0.4.3
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

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
define('BHC_VER',  '0.4.3');
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
foreach (['post-types', 'activator', 'admin', 'steps', 'progress', 'progress-admin', 'gate', 'render', 'style-surface', 'crm-integration', 'debug', 'test-suite', 'content-bridge', 'portal-panel'] as $f) {
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
    add_action('init', ['BHC_Progress', 'init']);
    add_action('init', ['BHC_Debug', 'init']);
    add_action('init', ['BHC_StyleSurface', 'init']);
    add_action('init', ['BHC_CrmIntegration', 'init']);
    add_action('init', ['BHC_ProgressAdmin', 'init']);
    if (class_exists('OUS_TestRunner')) add_action('init', ['BHC_TestSuite', 'init']);
    if (class_exists('BH_Content')) add_action('init', ['BHC_ContentBridge', 'init']);
    add_action('init', ['BHC_PortalPanel', 'init']);
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

    add_action('wp_ajax_bhc_submit_quiz', ['BHC_Progress', 'ajax_submit_quiz']);
    add_action('wp_ajax_bhc_mark_complete', ['BHC_Progress', 'ajax_mark_complete']);
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
    ];
    return $tools;
});
