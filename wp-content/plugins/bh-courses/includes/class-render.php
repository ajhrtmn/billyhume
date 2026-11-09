<?php
if (!defined('ABSPATH')) exit;

/**
 * Front end: shortcode/hook registration and asset enqueueing. Rendering lives in
 * BHC_Render_Catalog, BHC_Render_Course and BHC_Render_Lesson.
 */
class BHC_Render {
    public static function init(): void {
        add_shortcode('bh_courses', ['BHC_Render_Catalog', 'render_catalog']);
        add_shortcode('bh_course', ['BHC_Render_Course', 'render_course']);
        add_shortcode('bh_course_landing', ['BHC_Render_Landing', 'render']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
        add_filter('render_block', [self::class, 'suppress_generic_post_navigation'], 10, 2);
        add_filter('render_block', [self::class, 'suppress_broken_byline'], 10, 2);
        add_filter('render_block', [self::class, 'suppress_duplicate_course_title'], 10, 2);
        add_filter('render_block', [self::class, 'suppress_duplicate_course_cover'], 10, 2);
    }

    /**
     * Hides the theme's post-title block on bh_course, which prints its own <h1>.
     */
    /** @param array<string, mixed> $block */
    public static function suppress_duplicate_course_title(string $block_content, $block): string {
        if (($block['blockName'] ?? '') !== 'core/post-title') return $block_content;
        if (!is_singular('bh_course')) return $block_content;
        return '';
    }

    /**
     * Hides the theme's featured-image block on bh_course; the hero already shows the cover.
     */
    /** @param array<string, mixed> $block */
    public static function suppress_duplicate_course_cover(string $block_content, $block): string {
        if (($block['blockName'] ?? '') !== 'core/post-featured-image') return $block_content;
        if (!is_singular('bh_course')) return $block_content;
        return '';
    }

    /**
     * Hides the theme's "Written by {author} in {category}" byline, which renders blank
     * on courses/lessons. Matched on the literal text, since the block is a generic core/group.
     */
    /** @param array<string, mixed> $block */
    public static function suppress_broken_byline(string $block_content, $block): string {
        if (($block['blockName'] ?? '') !== 'core/group') return $block_content;
        if (!is_singular(['bh_lesson', 'bh_course'])) return $block_content;
        if (strpos($block_content, 'Written by') === false) return $block_content;
        return '';
    }

    /**
     * Hides the theme's prev/next post navigation on lessons.
     * WHY: get_adjacent_post() isn't course-scoped, so it walks into other courses' lessons;
     * the lesson view has its own course-scoped navigation.
     */
    /** @param array<string, mixed> $block */
    public static function suppress_generic_post_navigation(string $block_content, $block): string {
        if (($block['blockName'] ?? '') !== 'core/post-navigation-link') return $block_content;
        if (is_singular(['bh_lesson', 'bh_course'])) return '';
        return $block_content;
    }

    // The /courses/ catalog is a Page holding the bhc/catalog block, not a CPT archive.

    public static function maybe_enqueue(): void {
        if (!is_singular()) return;
        global $post;
        // has_block() as well as has_shortcode(), and bh_course singulars (rendered directly
        // via the_content), so assets load wherever course markup appears.
        if (!$post || !(has_shortcode($post->post_content, 'bh_courses') || has_shortcode($post->post_content, 'bh_course') || has_block('bhc/catalog', $post) || has_block('bhc/course', $post) || has_shortcode($post->post_content, 'bh_course_landing') || has_block('bhc/course-landing', $post) || $post->post_type === 'bh_lesson' || $post->post_type === 'bh_course')) return;
        self::enqueue_assets();
    }

    private static function enqueue_assets(): void {
        wp_enqueue_style('bhc-front', BHC_URL . 'assets/css/courses.css', [], BHC_VER);
        // Core's shared catalog styles, by handle; a no-op if core hasn't registered it.
        wp_enqueue_style('ous-catalog');
        if (class_exists('BHY_Style')) wp_add_inline_style('bhc-front', BHY_Style::inline_css());

        // Plyr (vendored) supplies the custom control bar that seek-bar chapter markers need.
        // iconUrl/blankVideo point at local copies, not cdn.plyr.io.
        $deps = [];
        // Videos render on lesson pages and inline in course views, so load the player
        // there too. Pure catalog listings skip it.
        global $post;
        $renders_lesson = is_singular(['bh_lesson', 'bh_course'])
            || ($post instanceof \WP_Post && (
                has_shortcode($post->post_content, 'bh_course')
                || has_block('bhc/course', $post)
            ));
        if ($renders_lesson) {
            wp_enqueue_style('bhc-plyr', BHC_URL . 'assets/js/vendor/plyr.css', [], BHC_VER);
            wp_enqueue_script('bhc-plyr', BHC_URL . 'assets/js/vendor/plyr.min.js', [], '3.8.4', true);
            wp_localize_script('bhc-plyr', 'BHCPlyrAssets', [
                'iconUrl'    => BHC_URL . 'assets/js/vendor/plyr.svg',
                'blankVideo' => BHC_URL . 'assets/js/vendor/plyr-blank.mp4',
            ]);
            // courses.js constructs `new Plyr(...)` at DOMContentLoaded.
            $deps[] = 'bhc-plyr';

            // Bunny's player.js bridge, only when a Bunny step is present.
            if (self::context_has_bunny_step()) {
                wp_enqueue_script('bhc-bunny-playerjs', 'https://assets.mediadelivery.net/playerjs/player-0.1.0.min.js', [], null, true);
                $deps[] = 'bhc-bunny-playerjs';
            }
        }

        wp_enqueue_script('bhc-front', BHC_URL . 'assets/js/courses.js', $deps, BHC_VER, true);
        wp_localize_script('bhc-front', 'BHCData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('bhc_progress'),
        ]);
    }

    /** True if any step of this lesson is a bunny_stream video step. */
    private static function lesson_has_bunny_step(int $lesson_id): bool {
        if (!$lesson_id || !class_exists('BHC_Steps')) return false;
        foreach (BHC_Steps::get($lesson_id) as $step) {
            if (($step['type'] ?? '') === 'video' && ($step['source'] ?? '') === 'bunny_stream') return true;
        }
        return false;
    }

    /**
     * Whether the page shows a Bunny video step, standalone or inline in a course view.
     */
    private static function context_has_bunny_step(): bool {
        $obj = get_queried_object();
        if ($obj instanceof \WP_Post && $obj->post_type === 'bh_lesson') {
            return self::lesson_has_bunny_step((int) $obj->ID);
        }

        $course_id = 0;
        if ($obj instanceof \WP_Post && $obj->post_type === 'bh_course') {
            $course_id = (int) $obj->ID;
        } elseif ($obj instanceof \WP_Post && class_exists('BHC_PostTypes')) {
            // A page embedding [bh_course id="N"] / the bhc/course block.
            if (preg_match('/\[bh_course[^\]]*\bid=["\']?(\d+)/', $obj->post_content, $m)) {
                $course_id = (int) $m[1];
            }
        }
        if (!$course_id || !class_exists('BHC_PostTypes')) return false;

        foreach (BHC_PostTypes::lesson_order($course_id) as $lesson_id) {
            if (self::lesson_has_bunny_step((int) $lesson_id)) return true;
        }
        return false;
    }
}
