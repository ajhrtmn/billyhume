<?php
/**
 * Plugin Name: BH Courses
 * Description: Courses made of ordered, multistep/multipart lessons — text, images, and quizzes/progress-checks in any sequence — with per-student progress tracking and optional supporter-tier gating via BH Monetization. Depends only on The Self-Hosted Self's shared identity.
 * Version:     0.16.23
 * Requires PHP: 8.2
 * Requires Plugins: the-self-hosted-self
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).

define('BHC_VER',  '0.16.23');

define('BHC_PATH', plugin_dir_path(__FILE__));
define('BHC_URL',  plugin_dir_url(__FILE__));

/**
 * A genuine PEER to bh-contest, bh-streaming, and bh-crm — depends only
 * on the-self-hosted-self (shared identity, for enrollment/progress; shared
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
foreach (['tables', 'post-types', 'activator', 'admin', 'steps', 'progress', 'achievements', 'leaderboard', 'progress-admin', 'instructor-notes', 'video-settings', 'nudges', 'drip-nudges', 'gate', 'render-catalog', 'render-course', 'render-lesson', 'render', 'style-surface', 'lesson-surface', 'crm-integration', 'debug', 'test-suite', 'content-bridge', 'portal-panel', 'comments', 'certificates', 'share-cards', 'blocks', 'bunny', 'reviews', 'privacy', 'sessions', 'sessions-admin', 'sessions-portal'] as $f) {
    require_once BHC_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHC_Activator', 'activate']);
register_activation_hook(__FILE__, ['BHC_Sessions', 'activate']);
add_action('plugins_loaded', ['BHC_Activator', 'maybe_upgrade']);
add_action('plugins_loaded', ['BHC_Activator', 'maybe_migrate_content']);
add_action('plugins_loaded', ['BHC_Sessions', 'maybe_upgrade']);

add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Courses</strong> requires <strong>The Self-Hosted Self</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    add_action('init', ['BHC_PostTypes', 'register']);
    add_action('init', ['BHC_Render', 'init']);

    // Opt the course surfaces into the ecosystem's standalone chrome
    // (theme nav/footer hidden, ecosystem's own slim bar shown) — see
    // OUS_MenuSync. A catalog page, or any single course / lesson.
    add_filter('bh_standalone_surface', function ($is) {
        if ($is) return true;
        $catalog = (int) get_option('bhc_catalog_page_id', 0);
        return ($catalog && is_page($catalog)) || is_singular(['bh_course', 'bh_lesson']);
    });
    // QA fix, caught live via WP_DEBUG_LOG: same fix as bh-contest's
    // BH_Blocks/bh-streaming's BHS_Blocks — hooked normally at 'init'
    // instead of called directly at plugins_loaded time.
    add_action('init',          ['BHC_Blocks', 'init']);
    add_action('init',          ['BHC_Bunny', 'init']);
    add_action('init', ['BHC_Progress', 'init']);
    add_action('init', ['BHC_Achievements', 'init']);
    add_action('init', ['BHC_Privacy', 'init']);
    add_action('init', ['BHC_Debug', 'init']);
    add_action('init', ['BHC_StyleSurface', 'init']);
    // DESIGN-SUITE-UNIFICATION-PLAN.md — the "1" in AJ's "Do 3, then 2,
    // then 1" ordering (3 = data-binding v1, 2 = Gutenberg block, both
    // already shipped in the-self-hosted-self 3.4.46/3.4.47). First real
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
    // Priority PHP_INT_MAX: append the step-walker markup AFTER every
    // other the_content filter has run — a builder theme (Etch on the
    // live site) that re-parses the_content output through its own DOM
    // representation was stripping class="bhc-step …" off the outer step
    // wrappers (data-* survived), which broke courses.js's step
    // show/hide and left the container invisible. Running last means the
    // builder's pass never sees this markup to normalise it.
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
    }, PHP_INT_MAX);

    // Real bug, production-readiness sweep 2026-08-16: BH_SEO's tags
    // are echoed at wp_head (priority 1), which fires before the_content()
    // ever runs — the `the_content` filter above (and render_course()
    // itself) called BH_SEO::set_page_data() far too late to matter for
    // a course's own single-view page, confirmed live (zero meta/OG
    // tags on a real course detail page). template_redirect fires
    // before headers, so this actually wins the race.
    add_action('template_redirect', function () {
        if (is_singular('bh_course') && class_exists('BHC_Render_Course')) {
            BHC_Render_Course::set_seo_data(get_queried_object_id());
        }
    });

    // A lesson/course view must never be full-page cached. Two reasons,
    // both hit live: (1) it's per-student — progress state, the enroll
    // gate, "Log in to view this lesson" vs the real content; a cache
    // that serves one student's page to another (or the logged-out
    // shell to someone who just logged in) is broken. (2) a video step
    // embeds a SIGNED media URL (BHY_MediaToken::sign_bunny / sign_r2)
    // that expires in ~4h — an 8h host page-cache then serves an
    // expired token to every later visitor, which Bunny answers with a
    // 403 inside the player. DONOTCACHEPAGE covers Rocket / W3TC / WP
    // Super Cache / LiteSpeed / the Bluehost/Endurance cache; the
    // Cache-Control header stops shared proxies and the browser too.
    // Same pattern as BHI_Portal::never_cache_portal().
    add_action('template_redirect', function () {
        if (!is_singular(['bh_lesson', 'bh_course'])) return;
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if (!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT', true);
        nocache_headers();
    });

    add_action('add_meta_boxes', ['BHC_Admin', 'add_meta_boxes']);
    // Courses edit on the classic screen (see class-post-types.php's
    // note on bh_course): post_content is only the catalog description,
    // and the block canvas was an empty dark void offering core
    // Video/Image/Embed blocks that make no sense for a course — worst
    // of all on mobile. The Course Details metabox is the real builder.
    add_filter('use_block_editor_for_post_type', function ($use, $post_type) {
        return $post_type === 'bh_course' ? false : $use;
    }, 10, 2);
    add_action('init', ['BHC_Admin', 'register_lesson_meta']);
    add_action('rest_after_insert_bh_lesson', function ($post) { BHC_Admin::reconcile_lesson_placement($post->ID); });
    add_filter('views_edit-bh_course', ['BHC_Admin', 'add_catalog_view_link']);
    add_action('admin_init', ['BHC_Activator', 'ensure_catalog_page']);
    add_action('admin_init', ['BHC_Activator', 'maybe_flush_after_archive_removal'], 11);
    add_action('admin_init', ['BHC_Activator', 'maybe_backfill_lesson_course_ids'], 12);
    add_action('restrict_manage_posts', ['BHC_Admin', 'lesson_course_filter']);
    add_action('pre_get_posts', ['BHC_Admin', 'apply_lesson_course_filter']);
    add_filter('post_row_actions', ['BHC_Admin', 'course_lessons_row_action'], 10, 2);
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
    // helper (the-self-hosted-self/includes/class-list-table.php) — same column
    // set/position/render logic as the previous hand-rolled columns()/
    // custom_column() pairs.
    OUS_ListTable::register('bh_course', ['bhc_lessons' => 'Lessons', 'bhc_gate' => 'Access'], ['BHC_Admin', 'course_column_content']);
    OUS_ListTable::register('bh_lesson', ['bhc_course' => 'Course'], ['BHC_Admin', 'lesson_column_content']);
    add_filter('post_row_actions', ['BHC_Admin', 'lesson_row_actions'], 10, 2);
    add_filter('post_row_actions', ['BHC_Admin', 'course_row_actions'], 10, 2);
    add_action('admin_post_bhc_duplicate_lesson', ['BHC_Admin', 'handle_duplicate_lesson']);
    add_action('admin_post_bhc_unassign_lesson', ['BHC_Admin', 'handle_unassign_lesson']);
    add_action('admin_post_bhc_duplicate_course', ['BHC_Admin', 'handle_duplicate_course']);
    add_action('admin_post_bhc_restore_course_revision', ['BHC_Admin', 'handle_restore_course_revision']);
    add_action('before_delete_post', ['BHC_Admin', 'cleanup_deleted_course']);
    add_action('before_delete_post', ['BHC_Admin', 'cleanup_deleted_lesson']);

    add_action('wp_ajax_bhc_submit_quiz', ['BHC_Progress', 'ajax_submit_quiz']);
    add_action('wp_ajax_bhc_mark_complete', ['BHC_Progress', 'ajax_mark_complete']);
    add_action('wp_ajax_bhc_update_watch_progress', ['BHC_Progress', 'ajax_update_watch_progress']);
    add_action('wp_ajax_bhc_mark_annotation', ['BHC_Progress', 'ajax_mark_annotation']);
    add_action('wp_ajax_bhc_submit_review', ['BHC_Reviews', 'ajax_submit_review']);
});

// Self-registration into the Self-Hosted Self dashboard — zero changes
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
