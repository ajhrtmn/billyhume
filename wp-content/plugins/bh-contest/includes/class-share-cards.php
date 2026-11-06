<?php
if (!defined('ABSPATH')) exit;

/**
 * "Now entered" and "Vote now" share-card PNGs, generated per request by BH_ShareCard.
 * WHY: public (no login) so crawlers can fetch them; they expose only title, artist,
 * and contest name, never audio, notes, or contact info.
 */
class BH_ShareCards {
    public static function init(): void {
        add_action('template_redirect', [self::class, 'maybe_serve_card']);
    }

    public static function entered_card_url(int $submission_id): string {
        return add_query_arg(['bh_share_entered' => (int) $submission_id], home_url('/'));
    }

    public static function vote_card_url(int $submission_id): string {
        return add_query_arg(['bh_share_vote' => (int) $submission_id], home_url('/'));
    }

    // The link to pair with a "vote for me" card: the contest page, else home.
    public static function contest_page_url(int $contest_id): string {
        $page_id = (int) get_post_meta($contest_id, '_bh_page_id', true);
        $status = $page_id ? get_post_status($page_id) : false;
        return ($page_id && $status && $status !== 'trash') ? get_permalink($page_id) : home_url('/');
    }

    public static function maybe_serve_card(): void {
        if (isset($_GET['bh_share_entered'])) {
            self::serve(($_GET['bh_share_entered']), 'Now Entered');
        } elseif (isset($_GET['bh_share_vote'])) {
            self::serve(($_GET['bh_share_vote']), 'Vote Now');
        }
    }

    /** @param mixed $raw_id */
    private static function serve($raw_id, string $eyebrow): void {
        $submission_id = (int) $raw_id;
        $submission = get_post($submission_id);
        // WHY: pending/draft/rejected entries are private; only their author (who sees their card right
        // after submitting) or an editor may render one. Same 404 either way, so IDs can't be probed.
        $visible = $submission && $submission->post_type === 'bh_submission' && (
            $submission->post_status === 'publish'
            || (is_user_logged_in() && ((int) $submission->post_author === get_current_user_id() || current_user_can('edit_post', $submission_id)))
        );
        if (!$visible) wp_die('Submission not found.', '', ['response' => 404, 'back_link' => true]);

        $contest_id = (int) get_post_meta($submission_id, '_bh_contest_id', true);
        $contest = $contest_id ? get_post($contest_id) : null;
        $artist = (string) get_post_meta($submission_id, '_bh_artist_name', true);
        if ($artist === '') {
            $author = get_userdata((int) $submission->post_author);
            $artist = $author ? ($author->display_name ?: $author->user_login) : 'An artist';
        }

        if (!class_exists('BH_ShareCard')) wp_die('Share cards are unavailable right now.', '', ['response' => 501, 'back_link' => true]);

        $stored_style = $contest_id ? get_post_meta($contest_id, '_bh_share_card_style', true) : '';
        $style = BH_ShareCard::is_valid_style($stored_style) ? $stored_style : 'brand';
        $subtitle = $contest ? ('"' . $submission->post_title . '" — ' . $contest->post_title) : $submission->post_title;

        BH_ShareCard::output_png([
            'style' => $style,
            'eyebrow' => $eyebrow,
            'title' => $artist,
            'subtitle' => $subtitle,
            'entity_id' => $contest_id ?: null,
        ], sanitize_title($artist) . '-' . sanitize_title($eyebrow) . '.png');
    }
}
