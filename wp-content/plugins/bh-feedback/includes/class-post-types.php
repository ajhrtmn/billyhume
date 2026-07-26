<?php
if (!defined('ABSPATH')) exit;

/**
 * bh_feedback_request — one post per paid feedback request. Not
 * public-facing on its own (no single template, no archive) — always
 * accessed through the portal's own submitter/reviewer views
 * (class-portal-panel.php), same "private-by-default CPT, real UI lives
 * elsewhere" shape as bh-contest's bh_submission.
 *
 * post_author = the submitter (the fan who paid). Meta:
 *   _bhf_tier            'quick_take' | 'detailed' — see class-pricing.php
 *   _bhf_price_cents     what was actually charged (frozen at submit
 *                        time — a later price change never rewrites
 *                        history)
 *   _bhf_attachment_id   the uploaded audio file (a WP attachment)
 *   _bhf_status          'open' | 'claimed' | 'completed'
 *   _bhf_reviewer_id     0 until claimed
 *   _bhf_claimed_at      datetime, empty until claimed
 *   _bhf_duplicate_of    a prior bh_feedback_request post ID this looks
 *                        like a resubmission of (BHS_AudioHash match),
 *                        0 if none/unknown
 */
class BHF_PostTypes {
    const STATUS_OPEN = 'open';
    const STATUS_CLAIMED = 'claimed';
    const STATUS_COMPLETED = 'completed';

    public static function register() {
        register_post_type('bh_feedback_request', [
            'labels' => [
                'name' => 'Feedback Requests',
                'singular_name' => 'Feedback Request',
                'menu_name' => 'Feedback Requests',
                'edit_item' => 'View Feedback Request',
                'add_new_item' => 'Add New Feedback Request',
                'view_item' => 'View Feedback Request',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-format-chat',
            'supports' => ['title', 'author'],
        ]);
    }
}
