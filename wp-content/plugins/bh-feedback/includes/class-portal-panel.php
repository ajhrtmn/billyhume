<?php
if (!defined('ABSPATH')) exit;

/**
 * This plugin's contribution to BHI_Portal (own-ur-shit's
 * `bhi_portal_panels` filter). One panel, two sections: "My requests"
 * (every logged-in user — the submitter side, always shown) and
 * "Reviewer queue" (only rendered at all for an account holding
 * `bhcore_review_submissions` — obvious-or-gone, same as every other
 * capability-gated section in this ecosystem's portal).
 */
class BHF_PortalPanel {
    public static function init(): void {
        add_filter('bhi_portal_panels', [self::class, 'register_panel']);
    }

    /**
     * @param array<int, array<string, mixed>> $panels
     * @return array<int, array<string, mixed>>
     */
    public static function register_panel(array $panels): array {
        $panels[] = [
            'id' => 'feedback',
            'label' => 'Feedback',
            'icon' => 'dashicons-format-chat',
            'render' => [self::class, 'render'],
            'priority' => 40,
        ];
        return $panels;
    }

    public static function render(): void {
        echo '<h1>Feedback</h1>';

        if (isset($_GET['bhf_error'])) {
            echo '<p class="bhf-notice bhf-notice--error">' . esc_html(sanitize_text_field(wp_unslash($_GET['bhf_error']))) . '</p>';
        }

        self::render_my_requests();

        echo '<div class="bhi-portal-section"><h2>Submit a track</h2>';
        echo do_shortcode('[bhf_submit]');
        echo '</div>';

        if (current_user_can('bhcore_review_submissions')) {
            self::render_reviewer_queue();
        }
    }

    private static function render_my_requests(): void {
        $user_id = get_current_user_id();
        $requests = get_posts(['post_type' => 'bh_feedback_request', 'author' => $user_id, 'posts_per_page' => 20, 'post_status' => 'publish']);

        echo '<div class="bhi-portal-section"><h2>My requests</h2>';
        if (!$requests) {
            echo '<div class="bhi-portal-empty"><span class="dashicons dashicons-format-chat"></span><p>You haven\'t submitted anything for feedback yet.</p></div>';
        } else {
            foreach ($requests as $request) {
                $status = get_post_meta($request->ID, '_bhf_status', true);
                $tier = get_post_meta($request->ID, '_bhf_tier', true);
                echo '<div class="bhf-request-card">';
                echo '<h3>' . esc_html($request->post_title) . '</h3>';
                echo '<p><span class="bh-badge bhf-badge bhf-badge-' . esc_attr($status) . '">' . esc_html(ucfirst($status)) . '</span> — ' . esc_html(BHF_Pricing::label_for($tier)) . '</p>';
                // Audit fix (2026-07-25): the claim/release/complete state
                // machine already tracks _bhf_claimed_at, but a submitter
                // never saw it — the "claimed" badge looked identical in
                // meaning to "open" beyond its text label alone. Surface it.
                if ($status === BHF_PostTypes::STATUS_CLAIMED) {
                    $claimed_at = get_post_meta($request->ID, '_bhf_claimed_at', true);
                    if ($claimed_at) {
                        echo '<p class="bhf-claimed-note description">Claimed ' . esc_html(human_time_diff(strtotime($claimed_at), current_time('timestamp'))) . ' ago — a reviewer is working on this.</p>';
                    }
                }
                if ($status === BHF_PostTypes::STATUS_COMPLETED) {
                    $review = BHF_Queue::review_for($request->ID);
                    if ($review) {
                        echo '<div class="bhf-review-body">' . wp_kses_post(wpautop($review['body'])) . '</div>';
                    }
                }
                echo '</div>';
            }
        }
        echo '</div>';
    }

    private static function render_reviewer_queue(): void {
        $reviewer_id = get_current_user_id();
        $open = BHF_Queue::open_requests();
        $mine = BHF_Queue::claimed_by($reviewer_id);

        echo '<div class="bhi-portal-section"><h2>Reviewer queue</h2>';

        echo '<h3>Claimed by you</h3>';
        if (!$mine) {
            echo '<p>Nothing claimed right now.</p>';
        } else {
            foreach ($mine as $request) {
                echo '<div class="bhf-request-card">';
                echo '<h4>' . esc_html($request->post_title) . '</h4>';
                $attachment_id = (int) get_post_meta($request->ID, '_bhf_attachment_id', true);
                if ($attachment_id) {
                    echo '<audio controls src="' . esc_url(wp_get_attachment_url($attachment_id)) . '"></audio>';
                }
                $dup = (int) get_post_meta($request->ID, '_bhf_duplicate_of', true);
                if ($dup) {
                    echo '<p class="bhf-duplicate-flag">&#9888; Same audio file as a prior request (' . esc_html(get_the_title($dup) ?: ('#' . $dup)) . ') — possible resubmission.</p>';
                }
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="bhf_complete_review" />';
                echo '<input type="hidden" name="request_id" value="' . (int) $request->ID . '" />';
                wp_nonce_field('bhf_queue_action', 'bhf_queue_nonce');
                echo '<textarea name="review_body" rows="5" class="bhf-review-textarea" required placeholder="Write your feedback..."></textarea>';
                echo '<button type="submit" class="bhf-btn bhf-btn--primary">Submit review</button>';
                echo '</form>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bhf-release-form">';
                echo '<input type="hidden" name="action" value="bhf_release_request" />';
                echo '<input type="hidden" name="request_id" value="' . (int) $request->ID . '" />';
                wp_nonce_field('bhf_queue_action', 'bhf_queue_nonce');
                echo '<button type="submit" class="bhf-btn bhf-btn--secondary">Release</button>';
                echo '</form>';
                echo '</div>';
            }
        }

        echo '<h3>Open requests</h3>';
        if (!$open) {
            echo '<p>Nothing waiting right now.</p>';
        } else {
            foreach ($open as $request) {
                echo '<div class="bhf-request-card">';
                echo '<h4>' . esc_html($request->post_title) . '</h4>';
                echo '<p>' . esc_html(BHF_Pricing::label_for(get_post_meta($request->ID, '_bhf_tier', true))) . '</p>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="bhf_claim_request" />';
                echo '<input type="hidden" name="request_id" value="' . (int) $request->ID . '" />';
                wp_nonce_field('bhf_queue_action', 'bhf_queue_nonce');
                echo '<button type="submit" class="bhf-btn bhf-btn--primary">Claim</button>';
                echo '</form>';
                echo '</div>';
            }
        }
        echo '</div>';
    }
}
