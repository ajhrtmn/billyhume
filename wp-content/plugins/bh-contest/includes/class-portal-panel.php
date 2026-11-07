<?php
if (!defined('ABSPATH')) exit;

/**
 * "Contest Submissions" account-portal panel (bhi_portal_panels): the user's
 * submissions across all contests, with status, votes, and edit/replace forms.
 */
class BH_PortalPanel {
    public static function init(): void {
        add_filter('bhi_portal_panels', [self::class, 'register_panel']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
    }

    // The portal is a virtual page (no real $post), so gate on its query var.
    public static function maybe_enqueue(): void {
        if (!class_exists('BHI_Portal') || !get_query_var(BHI_Portal::QUERY_VAR)) return;
        wp_enqueue_script('bh-contest-portal-submissions', BH_URL . 'assets/js/portal-submissions.js', [], BH_VER, true);
        wp_localize_script('bh-contest-portal-submissions', 'bhContestPortalConfig', [
            'restUrl' => esc_url_raw(rest_url('bh/v1/')),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $panels
     * @return array<int, array<string, mixed>>
     */
    public static function register_panel($panels): array {
        // Entrants only. WHY: admin keeps it so Portal Layout can still order/hide the tab.
        if (!is_admin() && !self::has_submissions(get_current_user_id())) return $panels;
        $panels[] = [
            'id' => 'submissions',
            'label' => 'Contest Submissions',
            'icon' => 'dashicons-microphone',
            'render' => [self::class, 'render'],
            'priority' => 40,
        ];
        return $panels;
    }

    private static function has_submissions(int $user_id): bool {
        if (!$user_id) return false;
        return (bool) get_posts([
            'post_type' => 'bh_submission',
            'author' => $user_id,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]);
    }

    private static function vote_count_for(int $submission_id): int {
        global $wpdb;
        $t = class_exists('BH_Helpers') ? BH_Helpers::table() : BHCON_Tables::votes();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE submission_id = %d", $submission_id));
    }

    public static function render(): void {
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
            // Resolve the archive page via OUS_Pages, never a hardcoded slug; omit if none.
            if (method_exists('OUS_Pages', 'url')) {
                $archive_url = OUS_Pages::url('bh_archive', 'bh_archive_page_id');
                if ($archive_url) {
                    echo '<a class="button" href="' . esc_url($archive_url) . '">See open contests &rarr;</a>';
                }
            }
            echo '</div>';
            return;
        }

        echo '<div class="bhi-portal-course-list bhi-portal-submission-list">';
        foreach ($submissions as $sub) {
            $contest_id = (int) get_post_meta($sub->ID, '_bh_contest_id', true);
            $contest = $contest_id ? get_post($contest_id) : null;
            $votes = self::vote_count_for($sub->ID);
            $window_open = $contest_id && class_exists('BH_Helpers') && BH_Helpers::is_submission_open($contest_id);
            $pending_id = (int) get_post_meta($sub->ID, '_bh_pending_audio_id', true);

            // 'draft' here means "reserved, audio not attached yet".
            $needs_audio = $sub->post_status === 'draft' && !get_post_meta($sub->ID, '_bh_audio_id', true);
            $status_class = 'bhi-submission-status-' . sanitize_html_class($sub->post_status);
            $status_label = ucfirst($sub->post_status);
            if ($needs_audio) { $status_label = 'Needs audio file'; $status_class = 'bhi-submission-status-warn'; }
            if ($sub->post_status === 'rejected') { $status_label = 'Rejected'; $status_class = 'bhi-submission-status-bad'; }

            echo '<div class="bhi-portal-course-card bhi-submission-card">';
            echo '<div class="bhi-submission-card-head"><h3>' . esc_html($sub->post_title ?: '(untitled)') . '</h3>';
            echo '<span class="bhi-submission-status ' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span></div>';
            echo '<p class="bhi-overview-dim">' . esc_html($contest ? $contest->post_title : '(unknown contest)') . '</p>';
            echo '<div class="bhi-datum bhi-submission-votes">'
                . '<span class="bhi-datum-value">' . (int) $votes . '</span>'
                . '<span class="bhi-datum-label">vote' . ($votes === 1 ? '' : 's')
                . ($pending_id ? ' &middot; replacement pending review' : '') . '</span></div>';

            // WHY: bh_submission isn't public (its permalink 404s), so link the
            // contest's _bh_page_id page.
            if ($sub->post_status === 'publish' && $contest_id) {
                $contest_page_id = (int) get_post_meta($contest_id, '_bh_page_id', true);
                $contest_page_status = $contest_page_id ? get_post_status($contest_page_id) : false;
                if ($contest_page_id && $contest_page_status && $contest_page_status !== 'trash') {
                    echo '<p><a class="button" href="' . esc_url((string) get_permalink($contest_page_id)) . '">View</a></p>';
                }
            }

            if ($sub->post_status === 'rejected') {
                $reason_code = get_post_meta($sub->ID, '_bh_rejection_reason_code', true);
                $reason_note = get_post_meta($sub->ID, '_bh_rejection_note', true);
                $reason_label = class_exists('BH_Admin') && isset(BH_Admin::REJECTION_REASONS[$reason_code]) ? BH_Admin::REJECTION_REASONS[$reason_code] : 'No reason recorded';
                echo '<div class="bhi-submission-reason"><strong>Why:</strong> ' . esc_html($reason_label)
                   . ($reason_note ? ' — <em>' . esc_html($reason_note) . '</em>' : '')
                   . '</div>';
            }

            // Edit/replace while submissions are open, at any status (a new file
            // sends a rejected entry back to review).
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
