<?php
if (!defined('ABSPATH')) exit;

/**
 * Lesson comments/Q&A via WordPress's native comments, off by default per course
 * (`_bhc_comments_enabled`). Existing comments are hidden too, using the same access rule
 * as the lesson content.
 * WHY: hooks pre_get_comments, not comments_array, because block themes' Comments block
 * queries WP_Comment_Query directly.
 */
class BHC_Comments {
    public static function init(): void {
        add_action('init', [self::class, 'register_support'], 20); // after BHC_PostTypes::register() (priority 10)
        add_filter('comments_open', [self::class, 'filter_comments_open'], 10, 2);
        add_action('pre_get_comments', [self::class, 'restrict_comment_query']);
        // WHY: get_comments_number() reads the cached wp_posts.comment_count, which the query
        // filter doesn't touch, so a hidden thread would still show a count.
        add_filter('get_comments_number', [self::class, 'filter_comments_number'], 10, 2);
    }

    public static function register_support(): void {
        add_post_type_support('bh_lesson', 'comments');
    }

    public static function course_allows_comments(int $course_id): bool {
        return $course_id && (bool) get_post_meta($course_id, '_bhc_comments_enabled', true);
    }

    /** True only if the course has comments on and the visitor can see the lesson content. */
    public static function visitor_can_see_comments(int $lesson_id): bool {
        if (get_post_type($lesson_id) !== 'bh_lesson') return true; // not ours to gate
        $course_id = BHC_PostTypes::course_for_lesson($lesson_id);
        if (!self::course_allows_comments($course_id)) return false;
        return BHC_Gate::user_can_access_lesson(get_current_user_id(), $lesson_id);
    }

    public static function filter_comments_open(bool $open, int $post_id): bool {
        if (get_post_type($post_id) !== 'bh_lesson') return $open;
        return $open && self::visitor_can_see_comments($post_id);
    }

    /**
     * Hides existing comments from anyone who can't see the lesson. `post__in = [0]` forces
     * zero results.
     */
    public static function restrict_comment_query(\WP_Comment_Query $query): void {
        $post_id = (int) ($query->query_vars['post_id'] ?? 0);
        if (!$post_id || get_post_type($post_id) !== 'bh_lesson') return;
        if (!self::visitor_can_see_comments($post_id)) {
            $query->query_vars['post__in'] = [0];
        }
    }

    /** Keeps the comment count consistent with the hidden list. */
    public static function filter_comments_number(int $count, int $post_id): int {
        if (get_post_type($post_id) !== 'bh_lesson') return $count;
        return self::visitor_can_see_comments($post_id) ? $count : 0;
    }
}
