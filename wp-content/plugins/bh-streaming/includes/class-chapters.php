<?php
if (!defined('ABSPATH')) exit;

/**
 * Chapter markers and per-listener resume position for long-form audio.
 * Chapters: JSON [{time, label}] in '_bhs_chapters'. Resume: user meta
 * '_bhs_resume_' . $track_id; logged-out listeners get none.
 */
class BHS_Chapters {
    public static function init(): void {
        add_action('add_meta_boxes', [self::class, 'add_meta_box']);
        add_action('save_post_bhs_track', [self::class, 'save']);
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function add_meta_box(): void {
        add_meta_box('bhs_track_chapters', 'Chapters (for long-form audio)', [self::class, 'render_metabox'], 'bhs_track', 'normal', 'default');
    }

    public static function render_metabox(\WP_Post $post): void {
        wp_nonce_field('bhs_save_chapters', 'bhs_chapters_nonce');
        $chapters = self::get($post->ID);
        echo '<p class="description">Optional — one chapter per line, format <code>mm:ss Label text</code>. Leave empty for a normal song-length track; only worth filling in for an audiobook/long-episode-length upload.</p>';
        $lines = array_map(fn($c) => sprintf('%02d:%02d %s', intdiv($c['time'], 60), $c['time'] % 60, $c['label']), $chapters);
        echo '<textarea name="bhs_chapters_raw" rows="6" style="width:100%;font-family:monospace;" placeholder="00:00 Introduction' . "\n" . '03:45 Chapter One">' . esc_textarea(implode("\n", $lines)) . '</textarea>';
    }

    public static function save(int $post_id): void {
        if (!isset($_POST['bhs_chapters_nonce']) || !wp_verify_nonce($_POST['bhs_chapters_nonce'], 'bhs_save_chapters')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $raw = (string) ($_POST['bhs_chapters_raw'] ?? '');
        $chapters = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!preg_match('/^(\d{1,3}):(\d{2})\s+(.+)$/', $line, $m)) continue; // skip a malformed line rather than lose every other chapter
            $chapters[] = ['time' => ((int) $m[1]) * 60 + (int) $m[2], 'label' => sanitize_text_field($m[3])];
        }
        usort($chapters, fn($a, $b) => $a['time'] <=> $b['time']);
        update_post_meta($post_id, '_bhs_chapters', wp_json_encode($chapters));
    }

    /** @return array<int, array{time:int, label:string}> */
    public static function get(int $track_id): array {
        $raw = (string) get_post_meta($track_id, '_bhs_chapters', true);
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function resume_position(int $track_id, int $user_id): int {
        return (int) get_user_meta($user_id, '_bhs_resume_' . (int) $track_id, true);
    }

    public static function register_routes(): void {
        register_rest_route('bhs/v1', '/tracks/(?P<id>\d+)/resume', [
            'methods' => 'POST',
            'callback' => [self::class, 'save_resume_position'],
            'permission_callback' => 'is_user_logged_in',
            'args' => ['id' => ['required' => true]],
        ]);
    }

    // Called throttled from the player's timeupdate. A convenience, not a
    // play record, so $seconds isn't validated against duration.
    /** @return \WP_REST_Response|\WP_Error */
    public static function save_resume_position(\WP_REST_Request $req) {
        $track_id = (int) $req->get_param('id');
        $seconds = max(0, (int) $req->get_param('seconds'));
        if (get_post_type($track_id) !== 'bhs_track') {
            return new WP_Error('not_found', 'Track not found.', ['status' => 404]);
        }
        update_user_meta(get_current_user_id(), '_bhs_resume_' . $track_id, $seconds);
        return new WP_REST_Response(['success' => true], 200);
    }
}
