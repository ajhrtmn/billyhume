<?php
if (!defined('ABSPATH')) exit;

/**
 * "Anything fun for social sharing?" — AJ's own ask, this session. A
 * course-completion share card, generated on demand via the shared
 * BH_ShareCard engine (own-ur-shit/includes/class-share-card.php),
 * same on-demand-not-stored posture class-certificates.php already
 * uses for the exact same "avoid regeneration/storage-cleanup
 * complexity" reason (a course title edited later would otherwise
 * leave a stale stored image).
 *
 * Public (no login required) once a completion actually happened —
 * unlike the certificate download, which requires the viewer to BE the
 * student, a share card's whole purpose is to be posted somewhere and
 * viewed by people who are NOT that student. Gated on the completion
 * having actually happened (bhc_completions), not on who's requesting
 * it, so a social platform's own link-preview crawler (which never has
 * the student's session) can still fetch it.
 */
class BHC_ShareCards {
    public static function init() {
        add_action('template_redirect', [self::class, 'maybe_serve_card']);
    }

    public static function card_url($user_id, $course_id) {
        return add_query_arg([
            'bhc_share_card' => (int) $course_id,
            'u' => (int) $user_id,
        ], home_url('/'));
    }

    public static function maybe_serve_card() {
        if (!isset($_GET['bhc_share_card'])) return;
        $course_id = (int) $_GET['bhc_share_card'];
        $user_id = (int) ($_GET['u'] ?? 0);
        $course = get_post($course_id);

        if (!$course || $course->post_type !== 'bh_course') wp_die('Course not found.', 404);
        if (!$user_id || !class_exists('BHC_Progress') || !BHC_Progress::is_course_completed($user_id, $course_id)) {
            wp_die('Nothing to share yet — this course hasn\'t been completed by that student.', 404);
        }
        if (!class_exists('BH_ShareCard')) wp_die('Share cards are unavailable right now.', 501);

        $user = get_userdata($user_id);
        $name = $user ? ($user->display_name ?: $user->user_login) : 'A student';
        $style = get_post_meta($course_id, '_bhc_share_card_style', true) === 'poster' ? 'poster' : 'brand';

        BH_ShareCard::output_png([
            'style' => $style,
            'eyebrow' => 'Course Complete',
            'title' => $course->post_title,
            'subtitle' => 'Finished by ' . $name . ' on ' . get_bloginfo('name'),
            'entity_id' => $course_id,
        ], sanitize_title($course->post_title) . '-complete.png');
    }
}
