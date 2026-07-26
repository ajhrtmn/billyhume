<?php
if (!defined('ABSPATH')) exit;

/**
 * Long-form audio, Phase 1 (ROADMAP-streaming-media-scope-and-
 * blockchain.md Part 5) — chapter markers and per-listener resume
 * position, deliberately scoped as the "barely an expansion" phase the
 * ROADMAP doc calls it: no schema change beyond one meta field on
 * bhs_track, and one small per-user meta field for where a specific
 * listener left off. No CPT change, no new post type.
 *
 * Chapters are stored as a JSON array of {time (seconds, int), label}
 * on '_bhs_chapters', authored by whoever can edit the track (same
 * capability as everything else on the Track Details screen).
 * Resume position is per-track, per-listener ('_bhs_resume_' . $track_id
 * on the user, so a listener's progress on one track never collides
 * with another) — logged-out listeners simply never get resume
 * (nothing to key it to), same as this ecosystem's existing posture on
 * play-count/like state for anonymous visitors.
 */
class BHS_Chapters {
    public static function init() {
        add_action('add_meta_boxes', [self::class, 'add_meta_box']);
        add_action('save_post_bhs_track', [self::class, 'save']);
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function add_meta_box() {
        add_meta_box('bhs_track_chapters', 'Chapters (for long-form audio)', [self::class, 'render_metabox'], 'bhs_track', 'normal', 'default');
    }

    public static function render_metabox($post) {
        wp_nonce_field('bhs_save_chapters', 'bhs_chapters_nonce');
        $chapters = self::get($post->ID);
        echo '<p class="description">Optional — one chapter per line, format <code>mm:ss Label text</code>. Leave empty for a normal song-length track; only worth filling in for an audiobook/long-episode-length upload.</p>';
        $lines = array_map(fn($c) => sprintf('%02d:%02d %s', intdiv($c['time'], 60), $c['time'] % 60, $c['label']), $chapters);
        echo '<textarea name="bhs_chapters_raw" rows="6" style="width:100%;font-family:monospace;" placeholder="00:00 Introduction' . "\n" . '03:45 Chapter One">' . esc_textarea(implode("\n", $lines)) . '</textarea>';
    }

    public static function save($post_id) {
        if (!isset($_POST['bhs_chapters_nonce']) || !wp_verify_nonce($_POST['bhs_chapters_nonce'], 'bhs_save_chapters')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $raw = (string) ($_POST['bhs_chapters_raw'] ?? '');
        $chapters = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!preg_match('/^(\d{1,3}):(\d{2})\s+(.+)$/', $line, $m)) continue; // silently skip a malformed line rather than reject the whole save — an artist typo shouldn't lose every other chapter they entered correctly
            $chapters[] = ['time' => ((int) $m[1]) * 60 + (int) $m[2], 'label' => sanitize_text_field($m[3])];
        }
        usort($chapters, fn($a, $b) => $a['time'] <=> $b['time']);
        update_post_meta($post_id, '_bhs_chapters', wp_json_encode($chapters));
    }

    public static function get($track_id) {
        $raw = (string) get_post_meta($track_id, '_bhs_chapters', true);
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function resume_position($track_id, $user_id) {
        return (int) get_user_meta($user_id, '_bhs_resume_' . (int) $track_id, true);
    }

    public static function register_routes() {
        register_rest_route('bhs/v1', '/tracks/(?P<id>\d+)/resume', [
            'methods' => 'POST',
            'callback' => [self::class, 'save_resume_position'],
            'permission_callback' => 'is_user_logged_in',
            'args' => ['id' => ['required' => true]],
        ]);
    }

    // Fire-and-forget from the player's own timeupdate handler
    // (throttled client-side, see player.js) — this is a convenience
    // ("pick up where I left off"), not an authoritative play record,
    // so it deliberately doesn't validate that $seconds is even
    // plausible for the track's real duration; a bad value just means a
    // resume that seeks somewhere odd next time, not a security issue.
    public static function save_resume_position($req) {
        $track_id = (int) $req->get_param('id');
        $seconds = max(0, (int) $req->get_param('seconds'));
        if (get_post_type($track_id) !== 'bhs_track') {
            return new WP_Error('not_found', 'Track not found.', ['status' => 404]);
        }
        update_user_meta(get_current_user_id(), '_bhs_resume_' . $track_id, $seconds);
        return new WP_REST_Response(['success' => true], 200);
    }
}
