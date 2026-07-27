<?php
if (!defined('ABSPATH')) exit;

/**
 * The one job that actually creates/closes bhl_stream records — polls
 * BHL_StreamEngine::get_status() (BHL_OwncastEngine's real
 * wp_remote_get against Owncast's /api/status) and reconciles it
 * against whatever's currently marked 'live' in the catalog. Owncast
 * has no outbound webhook wired up in this scaffold (a real future
 * improvement — Owncast does support webhooks, just not built here
 * yet), so polling is the honest v1 mechanism, same "ordinary shared
 * hosting" polling posture bh-streaming's own Jam sessions and feed-
 * health checks already use.
 *
 * Reuses the shared 'bhcore_every_minute' cron schedule OUS_Jobs
 * already registers (own-ur-shit/includes/class-jobs.php) rather than
 * adding a second, near-identical interval registration.
 */
class BHL_Streams {
    const CRON_HOOK = 'bhl_poll_status';

    public static function init() {
        add_action(self::CRON_HOOK, [self::class, 'poll_status']);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'bhcore_every_minute', self::CRON_HOOK);
        }
    }

    public static function current_live_stream() {
        $posts = get_posts([
            'post_type' => 'bhl_stream', 'post_status' => 'publish', 'posts_per_page' => 1,
            'meta_query' => [['key' => '_bhl_status', 'value' => BHL_PostTypes::STATUS_LIVE]],
        ]);
        return $posts ? $posts[0] : null;
    }

    public static function poll_status() {
        if (!class_exists('BHL_EngineRegistry')) return;
        $engine = BHL_EngineRegistry::active();
        if (!$engine || !$engine->is_configured()) return;

        $status = $engine->get_status();
        if (is_wp_error($status)) return; // a transient network hiccup shouldn't flip a stream's state

        $current = self::current_live_stream();

        if ($status['online'] && !$current) {
            // Owncast just went live and nothing here reflects that yet.
            wp_insert_post([
                'post_type' => 'bhl_stream', 'post_status' => 'publish',
                'post_title' => $status['stream_title'] ?: ('Live stream — ' . current_time('Y-m-d H:i')),
                'meta_input' => [
                    '_bhl_status' => BHL_PostTypes::STATUS_LIVE,
                    '_bhl_started_at' => current_time('mysql'),
                ],
            ]);
        } elseif (!$status['online'] && $current) {
            // Owncast just went offline — close out the record it
            // reflects. Nothing here can know whether a replay file
            // will ever actually be attached (see class-post-types.php's
            // own docblock); the status change is all this job asserts.
            update_post_meta($current->ID, '_bhl_status', BHL_PostTypes::STATUS_ENDED);
            update_post_meta($current->ID, '_bhl_ended_at', current_time('mysql'));
        }
        // Both flags true or both false: nothing changed since the last poll.
    }
}
