<?php
if (!defined('ABSPATH')) exit;

/**
 * Course-completion share card generated on demand via BH_ShareCard.
 * WHY: public once the completion exists, not session-gated, so link-preview crawlers can fetch it.
 */
class BHC_ShareCards {
    public static function init(): void {
        add_action('template_redirect', [self::class, 'maybe_serve_card']);
    }

    public static function card_url(int $user_id, int $course_id): string {
        return add_query_arg([
            'bhc_share_card' => (int) $course_id,
            'u' => (int) $user_id,
        ], home_url('/'));
    }

    public static function maybe_serve_card(): void {
        if (!isset($_GET['bhc_share_card'])) return;
        $course_id = (int) $_GET['bhc_share_card'];
        $user_id = (int) ($_GET['u'] ?? 0);
        $course = get_post($course_id);

        if (!$course || $course->post_type !== 'bh_course') wp_die('Course not found.', '', ['response' => 404, 'back_link' => true]);
        if (!$user_id || !class_exists('BHC_Progress') || !BHC_Progress::is_course_completed($user_id, $course_id)) {
            wp_die('Nothing to share yet — this course hasn\'t been completed by that student.', '', ['response' => 404, 'back_link' => true]);
        }
        if (!class_exists('BH_ShareCard')) wp_die('Share cards are unavailable right now.', '', ['response' => 501, 'back_link' => true]);

        $user = get_userdata($user_id);
        $name = $user ? ($user->display_name ?: $user->user_login) : 'A student';
        $stored_style = get_post_meta($course_id, '_bhc_share_card_style', true);
        $style = BH_ShareCard::is_valid_style($stored_style) ? $stored_style : 'brand';

        BH_ShareCard::output_png([
            'style' => $style,
            'eyebrow' => 'Course Complete',
            'title' => $course->post_title,
            'subtitle' => 'Finished by ' . $name . ' on ' . get_bloginfo('name'),
            'entity_id' => $course_id,
        ], sanitize_title($course->post_title) . '-complete.png');
    }
}
