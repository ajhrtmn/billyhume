<?php
if (!defined('ABSPATH')) exit;

/**
 * This plugin's contribution to BHI_Portal (own-ur-shit's `bhi_portal_panels`
 * filter — see class-portal.php over there) — "access and edit their
 * contest submissions" from AJ's own ask. Lists every bh_submission post
 * authored by the current user (across every contest, not just the
 * active one), with its status and live vote count, and a real edit link
 * for anything still in a submittable state.
 */
class BH_PortalPanel {
    public static function init() {
        add_filter('bhi_portal_panels', [self::class, 'register_panel']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
    }

    // Same "portal is a custom virtual page, not a real $post" gate
    // pattern own-ur-shit's class-public-profile.php fixed this same
    // session (Bug #2 in that pass) — only load the replace-file JS on
    // the portal itself.
    public static function maybe_enqueue() {
        if (!class_exists('BHI_Portal') || !get_query_var(BHI_Portal::QUERY_VAR)) return;
        wp_enqueue_script('bh-contest-portal-submissions', BH_URL . 'assets/js/portal-submissions.js', [], BH_VER, true);
        wp_localize_script('bh-contest-portal-submissions', 'bhContestPortalConfig', [
            'restUrl' => esc_url_raw(rest_url('bh/v1/')),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
    }

    public static function register_panel($panels) {
        $panels[] = [
            'id' => 'submissions',
            'label' => 'Contest Submissions',
            'icon' => 'dashicons-microphone',
            'render' => [self::class, 'render'],
            'priority' => 40,
        ];
        return $panels;
    }

    private static function vote_count_for($submission_id) {
        global $wpdb;
        $t = class_exists('BH_Helpers') ? BH_Helpers::table() : $wpdb->prefix . 'bh_votes';
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE submission_id = %d", $submission_id));
    }

    public static function render() {
        $user_id = get_current_user_id();
        echo '<h1>Contest Submissions</h1>';

        $submissions = get_posts([
            'post_type' => 'bh_submission',
            'author' => $user_id,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        if (!$submissions) {
            echo '<p>You haven\'t submitted anything to a contest yet.</p>';
            return;
        }

        echo '<table class="bhi-portal-table"><thead><tr><th>Submission</th><th>Contest</th><th>Status</th><th>Votes</th><th></th></tr></thead><tbody>';
        foreach ($submissions as $sub) {
            $contest_id = (int) get_post_meta($sub->ID, '_bh_contest_id', true);
            $contest = $contest_id ? get_post($contest_id) : null;
            $votes = self::vote_count_for($sub->ID);
            $window_open = $contest_id && class_exists('BH_Helpers') && BH_Helpers::is_submission_open($contest_id);
            $pending_id = (int) get_post_meta($sub->ID, '_bh_pending_audio_id', true);

            $status_label = ucfirst($sub->post_status);
            if ($sub->post_status === 'rejected') $status_label = '<span style="color:#b32d2e;font-weight:600;">Rejected</span>';
            if ($pending_id) $status_label .= ' <span style="color:#dba617;">(replacement pending review)</span>';

            echo '<tr>';
            echo '<td>' . esc_html($sub->post_title ?: '(untitled)') . '</td>';
            echo '<td>' . esc_html($contest ? $contest->post_title : '(unknown contest)') . '</td>';
            echo '<td>' . $status_label . '</td>';
            echo '<td>' . (int) $votes . '</td>';
            echo '<td>';
            if ($sub->post_status === 'publish') {
                echo '<a class="button" href="' . esc_url(get_permalink($sub->ID)) . '">View</a> ';
            }
            echo '</td>';
            echo '</tr>';

            if ($sub->post_status === 'rejected') {
                $reason_code = get_post_meta($sub->ID, '_bh_rejection_reason_code', true);
                $reason_note = get_post_meta($sub->ID, '_bh_rejection_note', true);
                $reason_label = class_exists('BH_Admin') && isset(BH_Admin::REJECTION_REASONS[$reason_code]) ? BH_Admin::REJECTION_REASONS[$reason_code] : 'No reason recorded';
                // QA fix, caught live: a hardcoded light-admin pink
                // (#fbeaea) was nearly unreadable against this portal's
                // dark theme. Uses --bh-* brand tokens instead, same as
                // the rest of the portal shell (own-ur-shit's
                // class-portal.php).
                echo '<tr><td colspan="5" style="background:var(--bh-surface-2, #fbeaea);color:var(--bh-text, inherit);">'
                   . '<strong>Why:</strong> ' . esc_html($reason_label)
                   . ($reason_note ? ' — <em>' . esc_html($reason_note) . '</em>' : '')
                   . '</td></tr>';
            }

            // Self-service "wrong file uploaded" fix — available any
            // time the contest's submission window is still open,
            // admin or contestant, per AJ's own scoping. Deliberately
            // NOT gated on post_status (works for pending, published,
            // AND rejected — resubmitting a new file after a rejection
            // puts it back in front of an admin, see BH_API::
            // replace_audio()).
            if ($window_open) {
                $artist_name = (string) get_post_meta($sub->ID, '_bh_artist_name', true);
                // Real gap this closes: previously the only self-service
                // fix available was replacing the audio FILE — a typo'd
                // song/artist title had no fix short of emailing an
                // admin. Same window-open gating as the file-replace
                // form below, same reasoning (still editable while the
                // contest is accepting submissions).
                echo '<tr><td colspan="5">';
                echo '<form class="bh-edit-details-form" data-submission-id="' . (int) $sub->ID . '" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">';
                echo '<label style="font-size:13px;">Song title: <input type="text" class="bh-edit-title" value="' . esc_attr($sub->post_title) . '" required></label>';
                echo '<label style="font-size:13px;">Artist name: <input type="text" class="bh-edit-artist" value="' . esc_attr($artist_name) . '"></label>';
                echo '<button type="submit" class="button">Save details</button>';
                echo '<span class="bh-edit-status description"></span>';
                echo '</form>';

                echo '<form class="bh-replace-audio-form" data-submission-id="' . (int) $sub->ID . '" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
                echo '<label style="font-size:13px;">' . ($pending_id ? 'Upload a different replacement:' : 'Wrong file? Upload a replacement:') . ' <input type="file" accept=".mp3,.m4a,audio/mpeg,audio/mp4" required></label>';
                echo '<button type="submit" class="button">Upload replacement</button>';
                echo '<span class="bh-replace-status description"></span>';
                echo '</form>';
                echo '</td></tr>';
            }
        }
        echo '</tbody></table>';
    }
}
