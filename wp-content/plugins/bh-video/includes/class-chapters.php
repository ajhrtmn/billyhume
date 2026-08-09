<?php
if (!defined('ABSPATH')) exit;

/**
 * Chapter markers + per-listener resume position for bhv_video, ported
 * directly from bh-streaming's own class-chapters.php (BHS_Chapters) --
 * same shape, same "barely an expansion" scope: one meta field on the
 * video, one per-user meta field for resume position, no schema change
 * beyond that. Long videos want the same "resume where I left off" +
 * chapter-jump UX audio already has (this ecosystem's own scoping call,
 * 2026-07-26 video/live plan).
 *
 * Chapters are a JSON array of {time (seconds, int), label} on
 * '_bhv_chapters'. Resume position is per-video, per-listener
 * ('_bhv_resume_' . $video_id on the user) -- logged-out listeners
 * never get resume, same posture as everything else keyed to a
 * specific viewer's own history.
 */
class BHV_Chapters {
    public static function init(): void {
        add_action('add_meta_boxes', [self::class, 'add_meta_box']);
        add_action('save_post_bhv_video', [self::class, 'save']);
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function add_meta_box(): void {
        add_meta_box('bhv_video_chapters', 'Chapters (for long-form video)', [self::class, 'render_metabox'], 'bhv_video', 'normal', 'default');
    }

    public static function render_metabox(\WP_Post $post): void {
        wp_nonce_field('bhv_save_chapters', 'bhv_chapters_nonce');
        $chapters = self::get($post->ID);
        echo '<p class="description">Optional — one chapter per line, format <code>mm:ss Label text</code>. Leave empty for a short video; only worth filling in for a long-form upload.</p>';
        $lines = array_map(fn($c) => sprintf('%02d:%02d %s', intdiv($c['time'], 60), $c['time'] % 60, $c['label']), $chapters);
        echo '<textarea name="bhv_chapters_raw" rows="6" style="width:100%;font-family:monospace;" placeholder="00:00 Introduction' . "\n" . '03:45 Chapter One">' . esc_textarea(implode("\n", $lines)) . '</textarea>';
    }

    public static function save(int $post_id): void {
        if (!isset($_POST['bhv_chapters_nonce']) || !wp_verify_nonce($_POST['bhv_chapters_nonce'], 'bhv_save_chapters')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $raw = (string) ($_POST['bhv_chapters_raw'] ?? '');
        $chapters = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!preg_match('/^(\d{1,3}):(\d{2})\s+(.+)$/', $line, $m)) continue; // silently skip a malformed line rather than reject the whole save
            $chapters[] = ['time' => ((int) $m[1]) * 60 + (int) $m[2], 'label' => sanitize_text_field($m[3])];
        }
        usort($chapters, fn($a, $b) => $a['time'] <=> $b['time']);
        update_post_meta($post_id, '_bhv_chapters', wp_json_encode($chapters));
    }

    /** @return array<int, array{time:int, label:string}> */
    public static function get(int $video_id): array {
        $raw = (string) get_post_meta($video_id, '_bhv_chapters', true);
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function resume_position(int $video_id, int $user_id): int {
        return (int) get_user_meta($user_id, '_bhv_resume_' . $video_id, true);
    }

    public static function register_routes(): void {
        register_rest_route('bhv/v1', '/videos/(?P<id>\d+)/resume', [
            'methods' => 'POST',
            'callback' => [self::class, 'save_resume_position'],
            'permission_callback' => 'is_user_logged_in',
            'args' => ['id' => ['required' => true]],
        ]);
    }

    // Fire-and-forget from the player's own timeupdate handler
    // (throttled client-side, see video-player.js) -- a convenience,
    // not an authoritative play record, so a bad $seconds value just
    // means an odd next-resume seek, not a security issue.
    /** @return \WP_REST_Response|\WP_Error */
    public static function save_resume_position(\WP_REST_Request $req) {
        $video_id = (int) $req->get_param('id');
        $seconds = max(0, (int) $req->get_param('seconds'));
        if (get_post_type($video_id) !== 'bhv_video') {
            return new WP_Error('not_found', 'Video not found.', ['status' => 404]);
        }
        update_user_meta(get_current_user_id(), '_bhv_resume_' . $video_id, $seconds);
        return new WP_REST_Response(['success' => true], 200);
    }
}
