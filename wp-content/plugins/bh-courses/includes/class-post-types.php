<?php
if (!defined('ABSPATH')) exit;

/**
 * - bh_course: a course. Holds an ordered list of lesson IDs in
 *   `_bhc_lesson_order` (post meta, array of lesson post IDs) — same
 *   "ordered list of child IDs on the parent" shape bh-streaming uses
 *   for release->track ordering. Optionally paylocked via
 *   `_bhm_required_tier`, exactly like bh-streaming's tracks/releases —
 *   see class-gate.php.
 * - bh_lesson: one lesson, belonging to exactly one course
 *   (`_bhc_course_id`). A lesson is NOT one content blob — it's an
 *   ordered array of STEPS (see class-steps.php), each one a text
 *   block, an image block, or a quiz block. That's what makes a lesson
 *   "multistep/multipart": authoring a lesson means building a
 *   sequence, not filling in one big editor.
 */
class BHC_PostTypes {
    const MENU_PARENT = 'edit.php?post_type=bh_course';

    public static function register() {
        register_post_type('bh_course', [
            'labels' => [
                // "OUS ·" text prefix dropped — the shared icon
                // (OUS_MenuIcons::courses(), same rounded-square badge
                // frame every OUS-owned top-level menu now carries) is
                // what signals ecosystem membership now.
                'name' => 'Courses', 'menu_name' => 'Courses', 'singular_name' => 'Course',
                'add_new_item' => 'Add New Course', 'edit_item' => 'Edit Course', 'all_items' => 'All Courses',
            ],
            'public' => true, 'show_ui' => true, 'show_in_menu' => true, 'has_archive' => 'courses',
            'rewrite' => ['slug' => 'courses'],
            'menu_icon' => OUS_MenuIcons::courses(), 'supports' => ['title', 'editor', 'thumbnail', 'revisions'],
            'capability_type' => 'post',
            // show_in_rest is the actual WordPress gate for the block
            // editor (use_block_editor_for_post_type() checks this AND
            // 'editor' support, which was already declared above) —
            // bh_lesson already opts in the same way; this brings course
            // editing onto the same block-editor screen instead of the
            // classic metabox chrome, so building a course doesn't feel
            // like a different app from building its own lessons.
            'show_in_rest' => true,
        ]);

        // Category/topic — real WordPress taxonomies, same "don't
        // reinvent what WordPress already ships" call bhs_genre
        // (bh-streaming) and bhm_collection (bh-monetization-woo) already
        // made for the identical shape of problem (QUIZ-AND-CATALOG-
        // DESIGN-PLAN.md Part 2.2): the term-management admin UI, REST
        // exposure, and tax_query filtering all come for free.
        // 'rewrite' => false deliberately — this pass has no term-archive
        // page (/course-category/foo/), only filtering via the [bh_courses]
        // catalog shortcode's own query string (class-render.php). That
        // sidesteps the whole "newly registered rewrite rule is invisible
        // until flush_rewrite_rules() runs" class of bug entirely (the
        // exact thing BHM_Storefront::add_rewrite()'s versioned-flush
        // dance exists to work around) rather than needing to reproduce
        // that dance for a term-archive URL nothing uses yet. If a real
        // term-archive page is ever wanted, add 'rewrite' + the versioned
        // flush pattern then, not speculatively now.
        register_taxonomy('bhc_course_category', 'bh_course', [
            'labels' => ['name' => 'Course Categories', 'singular_name' => 'Category'],
            'public' => true, 'show_ui' => true, 'show_in_menu' => true, 'show_in_rest' => true,
            'hierarchical' => true, // like core Categories ("Music Production" > "Mixing") — a course has a home category, possibly nested
            'rewrite' => false,
        ]);
        register_taxonomy('bhc_course_topic', 'bh_course', [
            'labels' => ['name' => 'Course Topics', 'singular_name' => 'Topic'],
            'public' => true, 'show_ui' => true, 'show_in_menu' => true, 'show_in_rest' => true,
            'hierarchical' => false, // like core Tags — flat, many-per-course
            'rewrite' => false,
        ]);

        register_post_type('bh_lesson', [
            'labels' => [
                'name' => 'Lessons', 'singular_name' => 'Lesson', 'add_new_item' => 'Add New Lesson',
                'edit_item' => 'Edit Lesson', 'all_items' => 'All Lessons',
            ],
            // public so a direct lesson URL can be used for a "continue
            // where you left off" link; actual gating happens at render
            // time (class-gate.php / class-render.php), not by hiding
            // the post type itself — same approach bh-streaming's
            // tracks use (public post, gated player).
            'public' => true, 'show_ui' => true, 'show_in_menu' => self::MENU_PARENT,
            'rewrite' => ['slug' => 'lesson'],
            // 'editor' + show_in_rest added this pass — a lesson's steps
            // are now authored directly on this real block-editor screen
            // (bhc/* real Gutenberg blocks, BHC_ContentBridge) instead of
            // BH_Studio's separate canvas; see that class's own docblock.
            // Front-end rendering is untouched — BHC_Render still reads
            // the legacy _bhc_steps postmeta array exclusively, kept in
            // sync by BHC_ContentBridge's save_post_bh_lesson hook.
            //
            // 'revisions' added, ROADMAP-search-and-revisions.md's own
            // framing (AJ: "versioning is most important for anything
            // that is a post, like contests and lessons") — a lesson's
            // steps genuinely ARE post_content now, so WordPress core's
            // own native revision/restore UI already works correctly
            // for free the moment this flag exists; no OUS_Revisions
            // wiring needed for content that already lives in
            // wp_posts. Zero new code beyond this one supports entry.
            'supports' => ['title', 'editor', 'revisions'], 'show_in_rest' => true, 'capability_type' => 'post',
        ]);
    }

    /* ---------------- helpers shared across admin + front end ---------------- */

    public static function lesson_order($course_id) {
        $ids = get_post_meta($course_id, '_bhc_lesson_order', true);
        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    public static function course_for_lesson($lesson_id) {
        return (int) get_post_meta($lesson_id, '_bhc_course_id', true);
    }

    // Position (0-based) of $lesson_id within its own course's order,
    // or null if it isn't (yet) listed — a lesson can exist as a draft
    // before an author adds it to the course's order.
    public static function lesson_position($lesson_id) {
        $course_id = self::course_for_lesson($lesson_id);
        if (!$course_id) return null;
        $order = self::lesson_order($course_id);
        $pos = array_search((int) $lesson_id, $order, true);
        return $pos === false ? null : $pos;
    }

    /* ---------------- catalog metadata (QUIZ-AND-CATALOG-DESIGN-PLAN.md Part 2) ---------------- */

    // A closed, fixed 3-value set an author picks FROM, never extends —
    // a scalar enum in postmeta, not a taxonomy (see class-admin.php's
    // save_course() for the sanitization whitelist this registry feeds,
    // same in_array()-against-known-keys guard _bhm_required_benefit
    // already uses). Filterable only so a future difficulty scale change
    // has one place to make it, not because this is meant to be an
    // open, author-extensible list like the category/topic taxonomies
    // above.
    public static function difficulty_registry() {
        return apply_filters('bhc_difficulty_registry', [
            'beginner' => 'Beginner',
            'intermediate' => 'Intermediate',
            'advanced' => 'Advanced',
        ]);
    }

    public static function difficulty($course_id) {
        $key = get_post_meta($course_id, '_bhc_difficulty', true);
        $registry = self::difficulty_registry();
        return isset($registry[$key]) ? $key : '';
    }

    public static function difficulty_label($course_id) {
        $key = self::difficulty($course_id);
        $registry = self::difficulty_registry();
        return $key ? $registry[$key] : '';
    }

    // Real WP user reference, falling back to the post author — see
    // QUIZ-AND-CATALOG-DESIGN-PLAN.md Part 2.2 for why this is a user ID
    // rather than free text (unlike bhs_track's artist field, every
    // course here is locally authored by someone with a real account).
    // Returns null (never a fatal) if the referenced user was since
    // deleted — get_userdata() legitimately returns false for that, and
    // callers must not assume a truthy result.
    public static function instructor($course_id) {
        $instructor_id = (int) get_post_meta($course_id, '_bhc_instructor_id', true);
        if (!$instructor_id) {
            $post = get_post($course_id);
            $instructor_id = $post ? (int) $post->post_author : 0;
        }
        if (!$instructor_id) return null;
        $user = get_userdata($instructor_id);
        return $user ?: null;
    }

    // Computed, not author-entered — see Part 2.2 for why (an
    // author-typed "3 hours" silently lies the moment the course grows;
    // a computed lesson count never does). Walks the same lesson_order()/
    // BHC_Steps::count() data course_percent() already reads, so this
    // adds no new data source, just a different aggregation of it.
    public static function lesson_count($course_id) {
        return count(self::lesson_order($course_id));
    }

    public static function step_count($course_id) {
        $total = 0;
        foreach (self::lesson_order($course_id) as $lesson_id) {
            $total += class_exists('BHC_Steps') ? BHC_Steps::count($lesson_id) : 0;
        }
        return $total;
    }

    // The optional free-text override (e.g. "~4 hours of video") — see
    // Part 2.2: computed-first, override-optional, never the other way
    // around, so a course with no override still shows something honest.
    public static function duration_note($course_id) {
        return (string) get_post_meta($course_id, '_bhc_duration_note', true);
    }
}
