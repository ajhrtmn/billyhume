<?php
if (!defined('ABSPATH')) exit;

/**
 * Duplicate-audio detection: sha1 of locally stored file bytes, flagged when
 * the same file appears under another account. External tracks are skipped
 * (never downloaded). Not fingerprinting: re-encoded copies won't match.
 */
class BHS_AudioHash {
    // Called after a track's audio attachment is set (currently from class-import.php).
    public static function hash_and_check(int $post_id, int $attachment_id): void {
        $path = get_attached_file($attachment_id);
        if (!$path || !file_exists($path)) return;

        $hash = sha1_file($path);
        if (!$hash) {
            // Log unreadable files so a missed duplicate check isn't silent.
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('warning', 'Audio duplicate-check skipped: sha1_file() failed on an existing, readable path.', [
                    'post_id' => $post_id, 'attachment_id' => $attachment_id, 'path' => $path,
                ], 'BH Streaming');
            }
            return;
        }
        update_post_meta($post_id, '_bhs_audio_hash', $hash);

        global $wpdb;
        // Includes bhs_video: the hash check is medium-agnostic.
        $matches = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_bhs_audio_hash' AND m.meta_value = %s
             WHERE p.post_type IN ('bhs_track', 'bhs_video') AND p.ID != %d AND p.post_status = 'publish'",
            $hash, $post_id
        ));
        if (!$matches) return;

        $this_author = (int) get_post($post_id)->post_author;
        $cross_account_matches = array_filter($matches, function ($mid) use ($this_author) {
            return (int) get_post($mid)->post_author !== $this_author;
        });
        if (!$cross_account_matches) return; // re-importing your own file isn't suspicious

        // Flag both tracks so either one shows the conflict.
        update_post_meta($post_id, '_bhs_duplicate_audio_flag', wp_json_encode(array_values($cross_account_matches)));
        foreach ($cross_account_matches as $other_id) {
            $existing = json_decode((string) get_post_meta($other_id, '_bhs_duplicate_audio_flag', true), true);
            $existing = is_array($existing) ? $existing : [];
            if (!in_array($post_id, $existing, true)) {
                $existing[] = $post_id;
                update_post_meta($other_id, '_bhs_duplicate_audio_flag', wp_json_encode($existing));
            }
        }

        // Surface only; a human decides whether it's a real problem.
        do_action('bhs_duplicate_audio_flagged', $post_id, $cross_account_matches);
    }

    // Shows the duplicate warning on the Tracks list screen.
    public static function flag_notice_html(int $post_id): string {
        $matches = json_decode((string) get_post_meta($post_id, '_bhs_duplicate_audio_flag', true), true);
        if (!$matches) return '';
        $titles = array_map(fn($id) => get_the_title($id) ?: ('#' . $id), $matches);
        return '<span style="color:#b32d2e;font-weight:600;">⚠ Same audio file as: ' . esc_html(implode(', ', $titles)) . ' (different account) — possible re-upload of someone else\'s content.</span>';
    }
}
