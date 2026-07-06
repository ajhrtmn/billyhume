<?php
if (!defined('ABSPATH')) exit;

/**
 * Optionally enriches the BH CRM plugin's person view with this
 * plugin's own activity data — likes and playlists. Entirely
 * one-directional and entirely optional, mirroring bh-contest's own CRM
 * integration exactly: if BH CRM isn't installed, these add_filter()
 * calls just sit unused.
 */
class BHS_CRMIntegration {
    public static function init() {
        add_filter('bh_crm_active_user_ids', [self::class, 'active_user_ids']);
        add_filter('bh_crm_activity_summary', [self::class, 'activity_summary'], 10, 2);
    }

    public static function active_user_ids($ids) {
        global $wpdb;
        $likers = $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->prefix}bhs_likes");
        $playlist_ids = get_posts(['post_type' => 'bh_playlist', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']);
        $playlist_owners = array_map(fn($pid) => (int) get_post_field('post_author', $pid), $playlist_ids);
        return array_merge($ids, $likers, $playlist_owners);
    }

    public static function activity_summary($sections, $user_id) {
        global $wpdb;
        $like_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}bhs_likes WHERE user_id = %d", $user_id));
        $playlists = get_posts(['post_type' => 'bh_playlist', 'author' => $user_id, 'post_status' => 'publish', 'posts_per_page' => -1]);
        if (!$like_count && !$playlists) return $sections;

        $sections[] = [
            'plugin'  => 'BH Streaming',
            'summary' => "$like_count liked track" . ($like_count === 1 ? '' : 's') . ', ' . count($playlists) . ' playlist' . (count($playlists) === 1 ? '' : 's'),
            'render'  => fn() => self::render_detail($playlists),
        ];
        return $sections;
    }

    private static function render_detail($playlists) {
        if (!$playlists) return;
        echo '<div style="overflow-x:auto;">';
        echo '<table class="wp-list-table widefat striped"><thead><tr><th>Playlist</th><th>Tracks</th></tr></thead><tbody>';
        foreach ($playlists as $p) {
            $ids = json_decode((string) get_post_meta($p->ID, '_bhs_track_ids', true), true);
            $count = is_array($ids) ? count($ids) : 0;
            echo '<tr><td>' . esc_html($p->post_title) . '</td><td>' . $count . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
