<?php
/**
 * Plugin Name: BH Courses
 * Description: Courses made of ordered, multistep/multipart lessons — text, images, and quizzes/progress-checks in any sequence — with per-student progress tracking and optional supporter-tier gating via BH Monetization. Depends only on The Self-Hosted Self's shared identity.
 * Version:     0.16.46
 * Requires PHP: 8.2
 * Requires Plugins: the-self-hosted-self
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).

define('BHC_VER',  '0.16.46');

define('BHC_PATH', plugin_dir_path(__FILE__));
define('BHC_URL',  plugin_dir_url(__FILE__));

/**
 * A peer plugin depending only on the-self-hosted-self. bh-monetization-woo is optional
 * (class_exists() at init) and enables tier-gating; without it courses are open.
 * No relationship to bh-streaming.
 */
foreach (['tables', 'post-types', 'activator', 'admin', 'steps', 'progress', 'achievements', 'leaderboard', 'progress-admin', 'instructor-notes', 'video-settings', 'nudges', 'drip-nudges', 'gate', 'render-catalog', 'render-course', 'render-lesson', 'render-landing', 'render', 'style-surface', 'crm-integration', 'debug', 'test-suite', 'content-bridge', 'portal-panel', 'comments', 'certificates', 'share-cards', 'blocks', 'bunny', 'reviews', 'downloads', 'feedback', 'notes', 'privacy', 'sessions', 'sessions-admin', 'sessions-portal'] as $f) {
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

    // Opt course surfaces into the ecosystem's standalone chrome (OUS_MenuSync).
    add_filter('bh_standalone_surface', function ($is) {
        if ($is) return true;
        $catalog = (int) get_option('bhc_catalog_page_id', 0);
        return ($catalog && is_page($catalog)) || is_singular(['bh_course', 'bh_lesson']);
    });
    // Hooked at 'init' rather than called at plugins_loaded.
    add_action('init',          ['BHC_Blocks', 'init']);
    add_action('init',          ['BHC_Bunny', 'init']);
    add_action('init', ['BHC_Progress', 'init']);
    add_action('init', ['BHC_Achievements', 'init']);
    add_action('init', ['BHC_Privacy', 'init']);
    add_action('init', ['BHC_Feedback', 'init']);
    add_action('init', ['BHC_Notes', 'init']);
    add_action('init', ['BHC_Render_Landing', 'init']);
    add_action('init', ['BHC_Debug', 'init']);
    add_action('init', ['BHC_StyleSurface', 'init']);
    // Design Suite tokens for the lesson screen, via BHY_Style's extension points.
    // Consumed in courses.css as var(--bh-custom-<key>[, fallback]).
    if (class_exists('BHY_Style')) {
        add_filter('bhy_style_custom_sliders', function ($sliders) {
            $sliders['course_sidebar_width'] = ['label' => 'Lesson sidebar width', 'min' => 200, 'max' => 380, 'step' => 10, 'unit' => 'px', 'default' => 240];
            $sliders['course_video_width']   = ['label' => 'Lesson video max width', 'min' => 480, 'max' => 1200, 'step' => 10, 'unit' => 'px', 'default' => 820];
            $sliders['course_caption_size']  = ['label' => 'Lesson caption text size', 'min' => 12, 'max' => 22, 'step' => 1, 'unit' => 'px', 'default' => 14];
            return $sliders;
        });
        add_filter('bhy_style_custom_fonts', function ($fonts) {
            $fonts['course_caption'] = ['label' => 'Lesson caption font', 'default' => 'Inter', 'fallback' => 'sans-serif'];
            return $fonts;
        });
        // WHY: only title font tokens here. Card radius/padding come from core's global
        // sliders, since catalog.css's .ous-catalog-card loads later and wins.
        add_filter('bhy_style_component_tokens', function ($components) {
            $components['course_card'] = [
                'label' => 'Course card',
                'selector' => '.bhc-course-card',
                'tokens' => [
                    'title_font_size'   => ['label' => 'Title text size', 'type' => 'size', 'min' => 13, 'max' => 24, 'step' => 1, 'unit' => 'px', 'default' => 17],
                    'title_font'        => ['label' => 'Title font', 'type' => 'font', 'default' => 'Inter', 'fallback' => 'sans-serif'],
                    // Toggle: checked = hidden.
                    'hide_author'       => ['label' => 'Hide instructor byline', 'type' => 'toggle', 'on' => 'none', 'off' => 'flex', 'default' => false],
                ],
            ];
            // Lesson sidebar shell and the shared progress bar.
            $components['course_sidebar'] = [
                'label' => 'Lesson sidebar',
                'selector' => '.bhc-course-sidebar',
                'tokens' => [
                    'radius'         => ['label' => 'Corner radius', 'type' => 'size', 'min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px', 'default' => 10],
                    'padding'        => ['label' => 'Padding',       'type' => 'size', 'min' => 0, 'max' => 32, 'step' => 1, 'unit' => 'px', 'default' => 16],
                    'item_font_size' => ['label' => 'Lesson row text size', 'type' => 'size', 'min' => 11, 'max' => 18, 'step' => 1, 'unit' => 'px', 'default' => 13],
                ],
            ];
            $components['progress_bar'] = [
                'label' => 'Progress bar',
                'selector' => '.bhc-progress-bar',
                'tokens' => [
                    'height' => ['label' => 'Bar height', 'type' => 'size', 'min' => 4, 'max' => 16, 'step' => 1, 'unit' => 'px', 'default' => 6],
                    'radius' => ['label' => 'Bar corner radius', 'type' => 'size', 'min' => 0, 'max' => 999, 'step' => 1, 'unit' => 'px', 'default' => 999],
                ],
            ];
            // The single-course page's "Taught by" row (separate from the card byline).
            $components['course_page'] = [
                'label' => 'Course page',
                'selector' => '.bhc-course-instructor-row',
                'tokens' => [
                    'hide_author' => ['label' => 'Hide "Taught by" instructor row', 'type' => 'toggle', 'on' => 'none', 'off' => 'flex', 'default' => false],
                ],
            ];
            return $components;
        });
        // Default "Open Live Editor" destination: the course catalog.
        add_filter('bhy_customizer_default_preview_url', function ($url) {
            $catalog = (int) get_option('bhc_catalog_page_id', 0);
            return $catalog ? get_permalink($catalog) : $url;
        });
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
    // WHY: PHP_INT_MAX so the step-walker markup is appended after the theme's (Etch's)
    // own the_content pass, which otherwise strips the step wrappers' classes.
    add_filter('the_content', function ($content) {
        if (get_post_type() === 'bh_lesson' && is_singular('bh_lesson') && in_the_loop() && is_main_query()) {
            return $content . BHC_Render_Lesson::render_lesson_steps(get_the_ID());
        }
        // Course permalink renders the full course view. WHY: static reentrancy guard,
        // since render_course_header() applies the_content to the description.
        static $rendering_course = false;
        if (!$rendering_course && get_post_type() === 'bh_course' && is_singular('bh_course') && in_the_loop() && is_main_query()) {
            $rendering_course = true;
            $out = BHC_Render_Course::render_course(['id' => get_the_ID()]);
            $rendering_course = false;
            return $out;
        }
        return $content;
    }, PHP_INT_MAX);

    // WHY: BH_SEO echoes at wp_head, before the_content, so set course SEO here.
    add_action('template_redirect', function () {
        if (is_singular('bh_course') && class_exists('BHC_Render_Course')) {
            BHC_Render_Course::set_seo_data(get_queried_object_id());
        }
    });

    // Never page-cache lesson/course views: they're per-student, and video steps embed
    // signed URLs that expire (~4h) before a typical page cache does.
    add_action('template_redirect', function () {
        if (!is_singular(['bh_lesson', 'bh_course'])) return;
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if (!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT', true);
        nocache_headers();
    });

    add_action('add_meta_boxes', ['BHC_Admin', 'add_meta_boxes']);
    // Courses use the classic editor: post_content is only the description, and the
    // Course Details metabox is the real builder.
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
    add_action('wp_ajax_bhc_save_video_position', ['BHC_Progress', 'ajax_save_video_position']);
    add_action('wp_ajax_bhc_mark_annotation', ['BHC_Progress', 'ajax_mark_annotation']);
    add_action('wp_ajax_bhc_submit_review', ['BHC_Reviews', 'ajax_submit_review']);
});

// Self-registration into the Self-Hosted Self dashboard (class-registry.php contract).
add_filter('ous_registered_plugins', function ($plugins) {
    $plugins['bh-courses'] = [
        'label' => 'BH Courses',
        'file' => 'bh-courses/bh-courses.php',
        'depends_on' => [],
        'check_class' => 'BHC_PostTypes',
        'description' => 'Courses built from ordered, multistep lessons (text, images, quizzes) with progress tracking and optional supporter-tier gating.',
        'dashboard_link' => 'edit.php?post_type=bh_course',
        'bundled_zip' => 'bh-courses.zip',
        // No 'admin_menus': Courses/Lessons keep their own CPT top-level menus.
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
