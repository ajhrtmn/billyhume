<?php
if (!defined('ABSPATH')) exit;

/**
 * Front end: shortcode/hook registration + the shared enqueue/routing
 * plumbing. Kept deliberately simple/server-rendered (one step visible
 * at a time, plain form posts to AJAX for quiz submit / mark-complete)
 * — matches this ecosystem's general "plain PHP + a little JS," not a
 * SPA rebuild of bh-streaming's player.
 *
 * SRP QA pass, 0.4.8 — this used to be one 589-line class doing catalog
 * rendering, the course detail page, AND the lesson step-walker/quiz UI
 * all at once, three genuinely separate concerns with almost no overlap
 * (render_continue_cta() being the one real exception — see
 * class-render-course.php's own docblock for where that landed and
 * why). Split into three focused classes — BHC_Render_Catalog,
 * BHC_Render_Course, BHC_Render_Lesson (each new file, same includes/
 * directory) — with this class now doing exactly what its name says:
 * registering the shortcodes/hooks and enqueueing assets, then
 * delegating the actual rendering.
 *
 * Every method below is a PURE MOVE, not a rewrite — the real logic is
 * byte-for-byte identical to what used to live directly in this file,
 * just relocated. render_catalog()/render_course()/render_lesson_steps()/
 * render_quiz_review() are kept here too, as one-line delegates, so
 * every EXISTING external call site (class-test-suite.php,
 * templates/archive-bh_course.php, bh-courses.php's the_content filter)
 * keeps working with ZERO changes — this class's own public API surface
 * is unchanged, only its internals moved out.
 */
class BHC_Render {
    public static function init() {
        add_shortcode('bh_courses', [self::class, 'render_catalog']);
        add_shortcode('bh_course', [self::class, 'render_course']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
        add_filter('template_include', [self::class, 'maybe_use_archive_template']);
    }

    // A real, themeable /courses/ archive ('has_archive' => 'courses',
    // class-post-types.php) rather than the catalog only ever being
    // reachable via the [bh_courses] shortcode — the same "public CPT
    // with its own view" instinct BHC_Render_Lesson::render_lesson_steps()'s
    // own docblock already states for lessons. Respects a theme's own
    // archive-bh_course.php if one exists (WordPress's normal template
    // hierarchy already resolves that BEFORE template_include fires —
    // this filter only supplies the fallback the theme didn't provide),
    // same "degrade to a sane default, never fight a real override"
    // posture as everything else in this ecosystem.
    public static function maybe_use_archive_template($template) {
        if (!is_post_type_archive('bh_course')) return $template;
        if ($template && strpos(basename($template), 'archive-bh_course') !== false) return $template;
        return BHC_PATH . 'templates/archive-bh_course.php';
    }

    public static function maybe_enqueue() {
        // Extended to also cover the bh_course post-type ARCHIVE
        // ('has_archive' => 'courses', class-post-types.php) — the
        // catalog rebuild (BHC_Render_Catalog) is a real enough surface
        // (search/filter/sort) that it needs its assets there too, not
        // just on a page carrying the [bh_courses] shortcode explicitly.
        // The is_singular() branch (shortcode-embedded catalog, a
        // course/lesson page) is unchanged.
        if (is_post_type_archive('bh_course')) {
            self::enqueue_assets();
            return;
        }
        if (!is_singular()) return;
        global $post;
        // has_block() alongside each has_shortcode() — ROADMAP-ux-polish-
        // and-feature-parity-2026-07.md 5a's WYSIWYG block conversion
        // (class-blocks.php, 'bhc/catalog'/'bhc/course') means a page can
        // embed either without any literal [bh_courses]/[bh_course]
        // bracket text in post_content. Same class of regression already
        // caught and fixed in bh-contest 3.5.0 and bh-streaming 0.5.4 —
        // applied here preemptively before shipping, not after.
        if (!$post || !(has_shortcode($post->post_content, 'bh_courses') || has_shortcode($post->post_content, 'bh_course') || has_block('bhc/catalog', $post) || has_block('bhc/course', $post) || $post->post_type === 'bh_lesson')) return;
        self::enqueue_assets();
    }

    private static function enqueue_assets() {
        wp_enqueue_style('bhc-front', BHC_URL . 'assets/css/courses.css', [], BHC_VER);
        if (class_exists('BHY_Style')) wp_add_inline_style('bhc-front', BHY_Style::inline_css());
        wp_enqueue_script('bhc-front', BHC_URL . 'assets/js/courses.js', [], BHC_VER, true);
        wp_localize_script('bhc-front', 'BHCData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('bhc_progress'),
        ]);
    }

    /* ---------------- delegates — real logic lives in the three classes above ---------------- */

    public static function render_catalog() {
        return BHC_Render_Catalog::render_catalog();
    }

    public static function render_course($atts) {
        return BHC_Render_Course::render_course($atts);
    }

    public static function render_lesson_steps($lesson_id) {
        return BHC_Render_Lesson::render_lesson_steps($lesson_id);
    }

    // Not called from anywhere in this codebase today (grepped before
    // this split — only ever invoked internally by BHC_Render_Lesson's
    // own render_step()), but it was public on the original class, so
    // it stays public/delegated here too rather than silently narrowing
    // this class's API surface.
    public static function render_quiz_review($snapshot) {
        return BHC_Render_Lesson::render_quiz_review($snapshot);
    }
}
