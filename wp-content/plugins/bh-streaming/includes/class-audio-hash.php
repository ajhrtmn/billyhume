<?php
if (!defined('ABSPATH')) exit;

/**
 * Duplicate-audio detection — a real, cheap first step toward the
 * bigger "does someone own what they uploaded" problem, without taking
 * on a third-party fingerprinting service. Hashes the actual audio
 * FILE bytes (sha1) for any track whose audio lives on THIS server —
 * local-import uploads and admin-added catalog tracks alike — and
 * flags when the same exact file shows up under a different account.
 *
 * Deliberately does NOT attempt this for externally-aggregated tracks
 * (`_bhs_source = 'external'`): those are a remote URL this site never
 * downloads a copy of (see class-feeds.php's own note on why — "a link
 * to featured content, not a copy of it"), and downloading a full audio
 * file on every cron sync purely to hash it would contradict that
 * design and be a real bandwidth/storage cost for no benefit this site
 * actually needs.
 *
 * This is a same-file-bytes check, not audio fingerprinting — it won't
 * catch a re-encoded/re-compressed copy of the same recording (that's
 * exactly what real fingerprinting would need to do, and exactly why
 * this is flagged as a "stepping stone," not a replacement, in the
 * roadmap doc). It WILL catch the common, cheap case: someone
 * literally re-uploading the same file another account already has.
 */
class BHS_AudioHash {
    // Called after any bhs_track's _bhs_audio_id is set/changed —
    // currently wired from class-import.php's local-import path.
    // Safe to call again later from class-admin.php's own catalog save
    // if admin-added tracks should be checked too; the check itself
    // doesn't care where the track came from, only that a real file
    // exists on this server for it.
    public static function hash_and_check($post_id, $attachment_id) {
        $path = get_attached_file($attachment_id);
        if (!$path || !file_exists($path)) return;

        $hash = sha1_file($path);
        if (!$hash) {
            // Error-handling audit gap: this silent early return meant a
            // corrupt/unreadable audio file simply never got duplicate-
            // checked, with zero record anywhere of why — an admin
            // investigating a suspicious re-upload that DIDN'T get
            // flagged had no way to tell "genuinely a new file" apart
            // from "the hash check itself silently failed."
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('warning', 'Audio duplicate-check skipped: sha1_file() failed on an existing, readable path.', [
                    'post_id' => $post_id, 'attachment_id' => $attachment_id, 'path' => $path,
                ], 'BH Streaming');
            }
            return;
        }
        update_post_meta($post_id, '_bhs_audio_hash', $hash);

        global $wpdb;
        $matches = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_bhs_audio_hash' AND m.meta_value = %s
             WHERE p.post_type = 'bhs_track' AND p.ID != %d AND p.post_status = 'publish'",
            $hash, $post_id
        ));
        if (!$matches) return;

        $this_author = (int) get_post($post_id)->post_author;
        $cross_account_matches = array_filter($matches, function ($mid) use ($this_author) {
            return (int) get_post($mid)->post_author !== $this_author;
        });
        if (!$cross_account_matches) return; // same person re-importing their own file isn't suspicious

        // Flag both sides — an admin reviewing either track should see
        // the conflict, not just whichever one happened to be uploaded
        // second.
        update_post_meta($post_id, '_bhs_duplicate_audio_flag', wp_json_encode(array_values($cross_account_matches)));
        foreach ($cross_account_matches as $other_id) {
            $existing = json_decode((string) get_post_meta($other_id, '_bhs_duplicate_audio_flag', true), true);
            $existing = is_array($existing) ? $existing : [];
            if (!in_array($post_id, $existing, true)) {
                $existing[] = $post_id;
                update_post_meta($other_id, '_bhs_duplicate_audio_flag', wp_json_encode($existing));
            }
        }

        // Same "surface it, don't auto-act" philosophy as every other
        // flag in this ecosystem — a human (an admin browsing the
        // catalog, or bh-crm's activity view if something hooks in here
        // later) decides whether this is a real problem or two people
        // who happen to both have rights to the same file.
        do_action('bhs_duplicate_audio_flagged', $post_id, $cross_account_matches);
    }

    // Small admin-list-table helper — shows a visible warning on the
    // Tracks screen rather than requiring an admin to know to look for
    // hidden postmeta.
    public static function flag_notice_html($post_id) {
        $matches = json_decode((string) get_post_meta($post_id, '_bhs_duplicate_audio_flag', true), true);
        if (!$matches) return '';
        $titles = array_map(fn($id) => get_the_title($id) ?: ('#' . $id), $matches);
        return '<span style="color:#b32d2e;font-weight:600;">⚠ Same audio file as: ' . esc_html(implode(', ', $titles)) . ' (different account) — possible re-upload of someone else\'s content.</span>';
    }
}
