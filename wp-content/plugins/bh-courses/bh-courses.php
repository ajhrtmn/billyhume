<?php
/**
 * Plugin Name: BH Courses
 * Description: Courses made of ordered, multistep/multipart lessons — text, images, and quizzes/progress-checks in any sequence — with per-student progress tracking and optional supporter-tier gating via BH Monetization. Depends only on Own Ur Shit's shared identity.
 * Version:     0.1.0
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

define('BHC_VER',  '0.1.0');
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
foreach (['post-types', 'activator', 'admin', 'steps', 'progress', 'progress-admin', 'gate', 'render', 'style-surface', 'crm-integration', 'debug', 'test-suite'] as $f) {
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
    add_filter('the_content', function ($content) {
        if (get_post_type() === 'bh_lesson' && is_singular('bh_lesson') && in_the_loop() && is_main_query()) {
            return $content . BHC_Render::render_lesson_steps(get_the_ID());
        }
        return $content;
    });

    add_action('add_meta_boxes', ['BHC_Admin', 'add_meta_boxes']);
    add_action('save_post_bh_course', ['BHC_Admin', 'save_course']);
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
