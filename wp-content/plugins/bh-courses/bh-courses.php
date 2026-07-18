<?php
/**
 * Plugin Name: BH Courses
 * Description: Courses made of ordered, multistep/multipart lessons — text, images, and quizzes/progress-checks in any sequence — with per-student progress tracking and optional supporter-tier gating via BH Monetization. Depends only on Own Ur Shit's shared identity.
 * Version:     0.4.32
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

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
// untested. Standing caveat: written and brace-balance-checked, not yet executed
// against the live install.

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
define('BHC_VER',  '0.4.32');

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
foreach (['post-types', 'activator', 'admin', 'steps', 'progress', 'progress-admin', 'video-settings', 'nudges', 'gate', 'render-catalog', 'render-course', 'render-lesson', 'render', 'style-surface', 'lesson-surface', 'crm-integration', 'debug', 'test-suite', 'content-bridge', 'portal-panel', 'comments', 'certificates', 'share-cards', 'blocks'] as $f) {
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
    add_action('init', ['BHC_VideoSettings', 'init']);
    add_action('admin_notices', ['BHC_VideoSettings', 'maybe_show_notice']);
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
