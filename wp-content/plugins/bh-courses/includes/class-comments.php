<?php
if (!defined('ABSPATH')) exit;

/**
 * Lesson comments/Q&A — ROADMAP-ux-polish-and-feature-parity-2026-07.md
 * 4d. WordPress's native comments API, unused on `bh_lesson` until now
 * (a one-line `add_post_type_support()`, same primitive this ecosystem
 * already prefers over reinventing something core ships).
 *
 * Two real, deliberate decisions this file encodes, per that doc's own
 * note that "comments open by default under paywalled content" is the
 * wrong order to build this in:
 *
 * 1. Off by default, per COURSE, not a blanket ecosystem-wide switch.
 *    A course creator opts a specific course into comments explicitly
 *    (`_bhc_comments_enabled` on the bh_course post) — no lesson is
 *    ever silently public-comment-capable just because this plugin
 *    updated. See BHC_Admin::render_course_metabox()/save_course() for
 *    the admin UI half.
 * 2. Visibility, not just posting, is gated — a closed comments_open
 *    filter alone only hides the NEW-comment form; anyone can still
 *    read every existing comment via the normal comments template.
 *    restrict_comment_query() below hides the comments themselves from
 *    anyone BHC_Gate::user_can_access_lesson() would also turn away
 *    from the lesson content itself — the exact SAME access rule, not
 *    a second one to keep in sync. A comment thread under a supporter-
 *    tier-gated lesson is exactly the "real, different product
 *    decision" that doc flagged as needing to be handled from day one.
 *
 *    NOT runtime-verified on the first attempt — the obvious hook
 *    (`comments_array`, WordPress's classic per-post comment-list
 *    filter) turned out to be a real dead end, confirmed live on this
 *    install: the active block theme's Comments block renders via
 *    `WP_Comment_Query` directly (the modern block-based comment query
 *    loop), which never calls the legacy `get_comments()` wrapper
 *    `comments_array` is actually tied to — a comment posted while
 *    testing stayed fully visible even after the lesson itself was
 *    locked. Switched to `pre_get_comments`, the lower-level hook
 *    `WP_Comment_Query` itself fires on every query regardless of which
 *    template/block triggered it — confirmed this one actually works
 *    by re-testing the exact same locked-lesson scenario.
 */
class BHC_Comments {
    public static function init() {
        add_action('init', [self::class, 'register_support'], 20); // after BHC_PostTypes::register() (priority 10) so the post type already exists
        add_filter('comments_open', [self::class, 'filter_comments_open'], 10, 2);
        add_action('pre_get_comments', [self::class, 'restrict_comment_query']);
        // get_comments_number() reads wp_posts.comment_count directly —
        // a separate, cached number that pre_get_comments' query-level
        // block doesn't touch at all. Confirmed live, same pass as the
        // comments_array dead end above: with the query itself
        // correctly returning zero rows, the theme's Comments heading
        // still read "One response to..." from this uncached count,
        // both leaking that a comment exists AND looking broken
        // (a visibly empty list under a nonzero count).
        add_filter('get_comments_number', [self::class, 'filter_comments_number'], 10, 2);
    }

    public static function register_support() {
        add_post_type_support('bh_lesson', 'comments');
    }

    public static function course_allows_comments($course_id) {
        return $course_id && (bool) get_post_meta($course_id, '_bhc_comments_enabled', true);
    }

    /** True only if this lesson's course has comments turned on AND the current visitor can actually see the lesson content itself — same rule BHC_Gate already enforces for the steps, not a second one. */
    public static function visitor_can_see_comments($lesson_id) {
        if (get_post_type($lesson_id) !== 'bh_lesson') return true; // not ours to gate
        $course_id = BHC_PostTypes::course_for_lesson($lesson_id);
        if (!self::course_allows_comments($course_id)) return false;
        return BHC_Gate::user_can_access_lesson(get_current_user_id(), $lesson_id);
    }

    public static function filter_comments_open($open, $post_id) {
        if (get_post_type($post_id) !== 'bh_lesson') return $open;
        return $open && self::visitor_can_see_comments($post_id);
    }

    /**
     * Hides EXISTING comments (not just the new-comment form) from
     * anyone who wouldn't otherwise be able to see this lesson's
     * content. Fires on every WP_Comment_Query, classic template or
     * block-based comment loop alike — see this class's own docblock
     * for why `comments_array` alone wasn't enough. `post__in = [0]`
     * (a post ID that can never exist) is the standard "force zero
     * results" idiom for a query class with no simpler "return nothing"
     * switch — cheaper than letting the query run and filtering after.
     */
    public static function restrict_comment_query($query) {
        $post_id = (int) ($query->query_vars['post_id'] ?? 0);
        if (!$post_id || get_post_type($post_id) !== 'bh_lesson') return;
        if (!self::visitor_can_see_comments($post_id)) {
            $query->query_vars['post__in'] = [0];
        }
    }

    /** Keeps the displayed comment COUNT consistent with the (already hidden) list — same visitor_can_see_comments() check, so a locked lesson always reads as zero, never a stale nonzero number over an empty list. */
    public static function filter_comments_number($count, $post_id) {
        if (get_post_type($post_id) !== 'bh_lesson') return $count;
        return self::visitor_can_see_comments($post_id) ? $count : 0;
    }
}
