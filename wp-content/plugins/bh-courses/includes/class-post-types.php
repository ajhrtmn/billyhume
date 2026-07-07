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
                'name' => 'Courses', 'menu_name' => 'OUS · Courses', 'singular_name' => 'Course',
                'add_new_item' => 'Add New Course', 'edit_item' => 'Edit Course', 'all_items' => 'All Courses',
            ],
            'public' => true, 'show_ui' => true, 'show_in_menu' => true, 'has_archive' => 'courses',
            'rewrite' => ['slug' => 'courses'],
            'menu_icon' => 'dashicons-welcome-learn-more', 'supports' => ['title', 'editor', 'thumbnail'],
            'capability_type' => 'post',
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
            'supports' => ['title'], 'capability_type' => 'post',
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
}
