<?php
if (!defined('ABSPATH')) exit;

/**
 * Social sharing cards. Two card types, both generated on demand via the shared BH_ShareCard
 * engine (own-ur-shit/includes/class-share-card.php): "entered" (a
 * submitter shares straight after entering) and "vote" (a submitter
 * shares while voting is still open, to drum up votes). Same on-demand
 * posture bh-courses' completion card and certificate use — nothing
 * stored, regenerated per request.
 *
 * Public (no login) — a submitter shares this OUTWARD, to people who
 * are never going to be logged into this site, and a platform's own
 * link-preview crawler needs to fetch it unauthenticated too. This
 * plugin's own submission/voting data is otherwise locked down
 * (bh_contest and bh_submission are both 'public' => false — see
 * class-post-types.php), but a card image containing only a title,
 * artist name, and contest name is a deliberately narrow, harmless
 * exception carved out for exactly this feature — never the
 * submission's audio, notes, or any contact info.
 *
 * No per-submission public page exists for the "vote" card to deep-link
 * to (bh_submission has no public single template) — it links to the
 * contest's own auto-created page (_bh_page_id, see class-admin.php's
 * maybe_create_contest_page()) instead, same page the contest's own
 * "Shortcode & Page" meta box already links an admin to. A viewer lands
 * on the real voting UI and finds the submission themselves, rather
 * than a deep link into a page that doesn't exist.
 */
class BH_ShareCards {
    public static function init() {
        add_action('template_redirect', [self::class, 'maybe_serve_card']);
    }

    public static function entered_card_url($submission_id) {
        return add_query_arg(['bh_share_entered' => (int) $submission_id], home_url('/'));
    }

    public static function vote_card_url($submission_id) {
        return add_query_arg(['bh_share_vote' => (int) $submission_id], home_url('/'));
    }

    // Public — the frontend submit-success JS pairs this alongside the
    // card image link (a downloadable/attachable PNG has no click
    // target of its own; this is the actual URL a "vote for me" post
    // should point people to).
    public static function contest_page_url($contest_id) {
        $page_id = (int) get_post_meta($contest_id, '_bh_page_id', true);
        $status = $page_id ? get_post_status($page_id) : false;
        return ($page_id && $status && $status !== 'trash') ? get_permalink($page_id) : home_url('/');
    }

    public static function maybe_serve_card() {
        if (isset($_GET['bh_share_entered'])) {
            self::serve(($_GET['bh_share_entered']), 'Now Entered');
        } elseif (isset($_GET['bh_share_vote'])) {
            self::serve(($_GET['bh_share_vote']), 'Vote Now');
        }
    }

    private static function serve($raw_id, $eyebrow) {
        $submission_id = (int) $raw_id;
        $submission = get_post($submission_id);
        if (!$submission || $submission->post_type !== 'bh_submission') wp_die('Submission not found.', '', ['response' => 404, 'back_link' => true]);

        $contest_id = (int) get_post_meta($submission_id, '_bh_contest_id', true);
        $contest = $contest_id ? get_post($contest_id) : null;
        $artist = (string) get_post_meta($submission_id, '_bh_artist_name', true);
        if ($artist === '') {
            $author = get_userdata($submission->post_author);
            $artist = $author ? ($author->display_name ?: $author->user_login) : 'An artist';
        }

        if (!class_exists('BH_ShareCard')) wp_die('Share cards are unavailable right now.', '', ['response' => 501, 'back_link' => true]);

        $stored_style = $contest_id ? get_post_meta($contest_id, '_bh_share_card_style', true) : '';
        $style = (class_exists('BH_ShareCard') && BH_ShareCard::is_valid_style($stored_style)) ? $stored_style : 'brand';
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
