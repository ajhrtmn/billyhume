<?php
if (!defined('ABSPATH')) exit;

/**
 * bhl_stream: one post per broadcast, becoming a replay after it ends.
 * Created/closed automatically by BHL_Streams; only the replay attachment is
 * set by hand (Owncast's API cannot fetch its recordings).
 *
 * Meta:
 *   _bhl_status                'live' | 'ended-with-replay'
 *   _bhl_started_at            datetime, set when the record is created
 *   _bhl_ended_at              datetime, empty until the broadcast ends
 *   _bhl_replay_attachment_id  nullable WP attachment ID
 */
class BHL_PostTypes {
    const STATUS_LIVE = 'live';
    const STATUS_ENDED = 'ended-with-replay';

    public static function register(): void {
        register_post_type('bhl_stream', [
            'labels' => [
                'name' => 'Streams', 'menu_name' => 'Live', 'singular_name' => 'Stream',
                'add_new_item' => 'Add New Stream', 'edit_item' => 'Edit Stream', 'all_items' => 'All Streams',
            ],
            'public' => false, 'show_ui' => true, 'show_in_menu' => true,
            'menu_icon' => 'dashicons-video-alt2', 'supports' => ['title'], 'capability_type' => 'post',
        ]);
    }
}
