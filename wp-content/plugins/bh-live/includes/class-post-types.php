<?php
if (!defined('ABSPATH')) exit;

/**
 * bhl_stream — one post per broadcast, matching the "Decided" call in
 * wondrous-mixing-forest.md: a stream becomes a VOD/replay after
 * ending, closer to bh-video's own catalog model than a pure
 * ephemeral event.
 *
 * Meta:
 *   _bhl_status            'live' | 'ended-with-replay'
 *   _bhl_started_at        datetime, set when the record is created
 *   _bhl_ended_at          datetime, empty until the broadcast ends
 *   _bhl_replay_attachment_id  nullable — a real WP attachment, same
 *                          ADVMO-eligible storage story bh-video's own
 *                          _bhv_attachment_id uses. NOT auto-imported:
 *                          Owncast's own recording-to-file feature
 *                          writes the finished file to ITS OWN server's
 *                          disk, and its public API has no endpoint to
 *                          fetch that file remotely — see
 *                          BHL_Admin::render_replay_metabox()'s own
 *                          docblock for why this stays a manual upload
 *                          step in v1 rather than a fake "automatic"
 *                          claim this API genuinely can't back up.
 *
 * The record itself (title, status, started_at/ended_at) is entirely
 * automatic — created/closed by BHL_Streams' own polling check, since
 * Owncast itself is the only source of truth for when a broadcast
 * actually starts/ends. The one thing a human ever fills in by hand is
 * the replay attachment, once ended, via the metabox in class-admin.php.
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
