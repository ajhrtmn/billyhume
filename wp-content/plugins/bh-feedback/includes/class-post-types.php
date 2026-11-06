<?php
if (!defined('ABSPATH')) exit;

/**
 * bh_feedback_request: one post per paid request, not public on its own
 * (accessed through the portal panels).
 *
 * post_author = the submitter. Meta:
 *   _bhf_tier            'quick_take' | 'detailed' (see class-pricing.php)
 *   _bhf_price_cents     amount charged, frozen at submit time
 *   _bhf_attachment_id   the uploaded audio attachment
 *   _bhf_status          'open' | 'claimed' | 'completed'
 *   _bhf_reviewer_id     0 until claimed
 *   _bhf_claimed_at      datetime, empty until claimed
 *   _bhf_duplicate_of    prior request ID with the same file hash, 0 if none
 */
class BHF_PostTypes {
    const STATUS_OPEN = 'open';
    const STATUS_CLAIMED = 'claimed';
    const STATUS_COMPLETED = 'completed';

    public static function register(): void {
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
