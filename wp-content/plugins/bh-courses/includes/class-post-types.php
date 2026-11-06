<?php
if (!defined('ABSPATH')) exit;

/**
 * - bh_course: holds an ordered list of lesson IDs in `_bhc_lesson_order`; optionally
 *   tier-gated via `_bhm_required_tier` (class-gate.php).
 * - bh_lesson: belongs to one course (`_bhc_course_id`); its body is an ordered array of
 *   steps (class-steps.php).
 */
class BHC_PostTypes {
    const MENU_PARENT = 'edit.php?post_type=bh_course';

    public static function register(): void {
        register_post_type('bh_course', [
            'labels' => [
                'name' => 'Courses', 'menu_name' => 'Courses', 'singular_name' => 'Course',
                'add_new_item' => 'Add New Course', 'edit_item' => 'Edit Course', 'all_items' => 'All Courses',
            ],
            // No archive: /courses/ is a real Page with the bhc/catalog block.
            'public' => true, 'show_ui' => true, 'show_in_menu' => true, 'has_archive' => false,
            'rewrite' => ['slug' => 'courses'],
            'menu_icon' => OUS_MenuIcons::courses(), 'supports' => ['title', 'editor', 'thumbnail', 'revisions'],
            'capability_type' => 'post',
            // REST stays on, but the block editor is off for courses (bh-courses.php):
            // post_content is only the description; the Course Details metabox is the builder.
            'show_in_rest' => true,
        ]);

        // Real taxonomies. 'rewrite' => false: no term archives, only catalog filtering, which
        // avoids needing a versioned rewrite flush.
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
            // Public so lesson URLs work; gating happens at render time.
            'public' => true, 'show_ui' => true, 'show_in_menu' => self::MENU_PARENT,
            'rewrite' => ['slug' => 'lesson'],
            // 'editor' + show_in_rest: steps are authored as bhc/* blocks (BHC_ContentBridge).
            // 'revisions': steps are post_content, so native revisions cover them.
            // 'custom-fields': required for REST to expose `meta` to the Lesson sidebar panel.
            'supports' => ['title', 'editor', 'revisions', 'custom-fields'], 'show_in_rest' => true, 'capability_type' => 'post',
        ]);
    }

    /* ---------------- helpers shared across admin + front end ---------------- */

    /** @return array<int, int> */
    public static function lesson_order(int $course_id): array {
        $ids = get_post_meta($course_id, '_bhc_lesson_order', true);
        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    public static function course_for_lesson(int $lesson_id): int {
        return (int) get_post_meta($lesson_id, '_bhc_course_id', true);
    }

    public static function module_title(int $lesson_id): string {
        return (string) get_post_meta($lesson_id, '_bhc_module_title', true);
    }

    // Buckets the ordered lessons into ['title' => string, 'lesson_ids' => int[]] groups:
    // consecutive lessons sharing a module title form one group; blank titles stand alone.
    /** @return array<int, array{title: string, lesson_ids: array<int, int>}> */
    public static function grouped_lesson_order(int $course_id): array {
        $groups = [];
        foreach (self::lesson_order($course_id) as $lesson_id) {
            $title = self::module_title($lesson_id);
            $last = end($groups);
            if ($title !== '' && $last && $last['title'] === $title) {
                $groups[count($groups) - 1]['lesson_ids'][] = $lesson_id;
            } else {
                $groups[] = ['title' => $title, 'lesson_ids' => [$lesson_id]];
            }
        }
        return $groups;
    }

    // 0-based position of $lesson_id in its course's order, or null if not listed.
    public static function lesson_position(int $lesson_id): ?int {
        $course_id = self::course_for_lesson($lesson_id);
        if (!$course_id) return null;
        $order = self::lesson_order($course_id);
        $pos = array_search((int) $lesson_id, $order, true);
        return $pos === false ? null : $pos;
    }

    /* ---------------- catalog metadata ---------------- */

    // Closed set of difficulty keys; filterable only so the scale has one place to change.
    /** @return array<string, string> */
    public static function difficulty_registry(): array {
        return apply_filters('bhc_difficulty_registry', [
            'beginner' => 'Beginner',
            'intermediate' => 'Intermediate',
            'advanced' => 'Advanced',
        ]);
    }

    public static function difficulty(int $course_id): string {
        $key = get_post_meta($course_id, '_bhc_difficulty', true);
        $registry = self::difficulty_registry();
        return isset($registry[$key]) ? $key : '';
    }

    public static function difficulty_label(int $course_id): string {
        $key = self::difficulty($course_id);
        $registry = self::difficulty_registry();
        return $key ? $registry[$key] : '';
    }

    // Instructor user, falling back to the post author; null if that user was deleted.
    public static function instructor(int $course_id): ?\WP_User {
        $instructor_id = (int) get_post_meta($course_id, '_bhc_instructor_id', true);
        if (!$instructor_id) {
            $post = get_post($course_id);
            $instructor_id = $post ? (int) $post->post_author : 0;
        }
        if (!$instructor_id) return null;
        $user = get_userdata($instructor_id);
        return $user ?: null;
    }

    // Computed from the lesson order, never author-entered.
    public static function lesson_count(int $course_id): int {
        return count(self::lesson_order($course_id));
    }

    // Optional free-text duration shown alongside the computed lesson count.
    public static function duration_note(int $course_id): string {
        return (string) get_post_meta($course_id, '_bhc_duration_note', true);
    }
}
