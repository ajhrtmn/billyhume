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
            echo '<div class="bhi-portal-empty">'
               . '<span class="dashicons dashicons-microphone"></span>'
               . '<p>You haven\'t submitted anything to a contest yet.</p>';
            if (post_type_exists('bh_contest')) echo '<a class="button" href="' . esc_url(home_url('/contests/')) . '">See open contests &rarr;</a>';
            echo '</div>';
            return;
        }

        // Card grid, matching bh-courses' portal panel — was a plain
        // <table>, the one panel besides Membership & Wallet that hadn't
        // gotten the same visual treatment as My Courses.
        echo '<div class="bhi-portal-course-list bhi-portal-submission-list">';
        foreach ($submissions as $sub) {
            $contest_id = (int) get_post_meta($sub->ID, '_bh_contest_id', true);
            $contest = $contest_id ? get_post($contest_id) : null;
            $votes = self::vote_count_for($sub->ID);
            $window_open = $contest_id && class_exists('BH_Helpers') && BH_Helpers::is_submission_open($contest_id);
            $pending_id = (int) get_post_meta($sub->ID, '_bh_pending_audio_id', true);

            // A 'draft' here specifically means "reserved, audio not
            // attached yet" (contest's own "Allow submitting without
            // audio yet" setting) — not a generic WP draft, so it gets
            // its own label rather than the raw ucfirst('draft').
            $needs_audio = $sub->post_status === 'draft' && !get_post_meta($sub->ID, '_bh_audio_id', true);
            $status_class = 'bhi-submission-status-' . sanitize_html_class($sub->post_status);
            $status_label = ucfirst($sub->post_status);
            if ($needs_audio) { $status_label = 'Needs audio file'; $status_class = 'bhi-submission-status-warn'; }
            if ($sub->post_status === 'rejected') { $status_label = 'Rejected'; $status_class = 'bhi-submission-status-bad'; }

            echo '<div class="bhi-portal-course-card bhi-submission-card">';
            echo '<div class="bhi-submission-card-head"><h3>' . esc_html($sub->post_title ?: '(untitled)') . '</h3>';
            echo '<span class="bhi-submission-status ' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span></div>';
            echo '<p class="bhi-overview-dim">' . esc_html($contest ? $contest->post_title : '(unknown contest)') . '</p>';
            echo '<p class="bhi-submission-votes">' . (int) $votes . ' vote' . ($votes === 1 ? '' : 's');
            if ($pending_id) echo ' <span class="bhi-overview-dim">(replacement pending review)</span>';
            echo '</p>';

            if ($sub->post_status === 'publish') {
                echo '<p><a class="button" href="' . esc_url(get_permalink($sub->ID)) . '">View</a></p>';
            }

            if ($sub->post_status === 'rejected') {
                $reason_code = get_post_meta($sub->ID, '_bh_rejection_reason_code', true);
                $reason_note = get_post_meta($sub->ID, '_bh_rejection_note', true);
                $reason_label = class_exists('BH_Admin') && isset(BH_Admin::REJECTION_REASONS[$reason_code]) ? BH_Admin::REJECTION_REASONS[$reason_code] : 'No reason recorded';
                echo '<div class="bhi-submission-reason"><strong>Why:</strong> ' . esc_html($reason_label)
                   . ($reason_note ? ' — <em>' . esc_html($reason_note) . '</em>' : '')
                   . '</div>';
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
                echo '<div class="bhi-submission-forms">';
                echo '<form class="bh-edit-details-form" data-submission-id="' . (int) $sub->ID . '">';
                echo '<label>Song title: <input type="text" class="bh-edit-title" value="' . esc_attr($sub->post_title) . '" required></label>';
                echo '<label>Artist name: <input type="text" class="bh-edit-artist" value="' . esc_attr($artist_name) . '"></label>';
                echo '<button type="submit" class="button">Save details</button>';
                echo '<span class="bh-edit-status description"></span>';
                echo '</form>';

                echo '<form class="bh-replace-audio-form" data-submission-id="' . (int) $sub->ID . '">';
                if ($needs_audio) {
                    $label = 'Finish your entry — upload your audio file:';
                } else {
                    $label = $pending_id ? 'Upload a different replacement:' : 'Wrong file? Upload a replacement:';
                }
                echo '<label>' . esc_html($label) . ' <input type="file" accept=".mp3,.m4a,audio/mpeg,audio/mp4" required></label>';
                echo '<button type="submit" class="button' . ($needs_audio ? ' button-primary' : '') . '">' . ($needs_audio ? 'Complete submission' : 'Upload replacement') . '</button>';
                echo '<span class="bh-replace-status description"></span>';
                echo '</form>';
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
}
