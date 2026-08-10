<?php
if (!defined('ABSPATH')) exit;

/**
 * Video, Phase 2 of ROADMAP-streaming-media-scope-and-blockchain.md
 * Part 5 — a genuinely separate CPT from bhs_track, not a generalized
 * "any media" post type. Per that doc's own reasoning: forcing
 * bhs_track's audio-specific fields (_bhs_audio_hash, ISRC — a
 * sound-RECORDING identifier standard with no video meaning at all) to
 * pretend to be medium-agnostic would be exactly the premature
 * abstraction this ecosystem's docs warn against elsewhere.
 *
 * v1 is deliberately tied to bhs_release (a release can optionally
 * have one video), not a standalone video catalog — the lower-risk,
 * more-reversible default the ROADMAP doc called for absent a real
 * answer yet on whether standalone video content is wanted.
 *
 * Explicitly NOT built here (per the ROADMAP doc, flagged as
 * known gaps rather than silently missing): a video identifier
 * placeholder (ISAN, the video analog of ISRC — skip until a real need
 * surfaces, same "don't build ahead of a real need" discipline as
 * BHS_ISRC's own mock-pattern docblock), feed import/export of video,
 * and any custom storage handling — video attachments are ordinary WP
 * attachments, so the already-installed advanced-media-offloader
 * plugin picks them up the same as any other upload with zero code
 * here.
 */
class BHS_VideoPostTypes {
    // Called directly from the plugin's own 'init' hook (see
    // bh-streaming.php), same as BHS_PostTypes::register() — CPT
    // registration happens NOW, not via a nested add_action('init', ...)
    // registered from inside an already-executing 'init' callback,
    // which (per class-storefront.php's own documented bug precedent)
    // never fires in that request.
    public static function init(): void {
        self::register();
        add_action('add_meta_boxes', [self::class, 'add_meta_box']);
        add_action('save_post_bhs_release', [self::class, 'save_release_video']);
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register(): void {
        $visible = !BHS_Env::hidden_in_production();
        register_post_type('bhs_video', [
            'labels' => [
                'name' => 'Videos', 'singular_name' => 'Video', 'add_new_item' => 'Add New Video',
                'edit_item' => 'Edit Video', 'all_items' => 'All Videos',
            ],
            'public' => false, 'show_ui' => $visible, 'show_in_menu' => $visible ? BHS_PostTypes::MENU_PARENT : false,
            'supports' => ['title'], 'capability_type' => 'post',
        ]);
    }

    /* ---------- linking a video to a release ---------- */

    public static function add_meta_box(): void {
        add_meta_box('bhs_release_video', 'Video', [self::class, 'render_release_video_metabox'], 'bhs_release', 'normal', 'default');
    }

    // Audit fix (2026-07-25): this used to be the only upload path in the
    // whole plugin with zero first-party admin UI — an admin had to
    // manually cURL the REST import endpoint and paste the resulting
    // post ID back in by hand. Now a real wp.media picker (same pattern
    // class-admin.php's Quality Encodes upload already uses), scoped to
    // the video library, which WordPress's own media modal already lets
    // you either pick an existing attachment OR upload a new one from —
    // no separate REST call needed from the browser at all.
    public static function render_release_video_metabox(\WP_Post $post): void {
        wp_nonce_field('bhs_save_release_video', 'bhs_release_video_nonce');
        $video_id = (int) get_post_meta($post->ID, '_bhs_release_video_id', true);
        $video = $video_id ? get_post($video_id) : null;
        $attachment_id = $video_id ? (int) get_post_meta($video_id, '_bhs_video_id', true) : 0;
        $attachment_url = $attachment_id ? wp_get_attachment_url($attachment_id) : '';
        echo '<p class="description">Optional — a music video for this release.</p>';
        echo '<input type="hidden" id="bhs_release_video_attachment_id" name="bhs_release_video_attachment_id" value="' . esc_attr($attachment_id ?: '') . '">';
        echo '<div id="bhs_release_video_preview">' . ($attachment_url ? '<video controls src="' . esc_url($attachment_url) . '" style="max-width:100%;"></video><p class="description">' . esc_html($video->post_title) . '</p>' : '<p><em>No video attached yet.</em></p>') . '</div>';
        echo '<p><button type="button" class="button" id="bhs-release-video-upload">Choose video…</button> ';
        echo '<button type="button" class="button-link" id="bhs-release-video-clear" style="color:#b3261e;' . ($attachment_id ? '' : 'display:none;') . '">Remove</button></p>';
        ?>
        <script>
        (function () {
            // wp.media lives in media-editor.min.js, a footer-enqueued script that
            // hasn't run yet when this inline block executes at body-parse time —
            // wait for DOMContentLoaded (fires after all enqueued scripts,
            // including footer ones) instead of checking window.wp.media here.
            document.addEventListener('DOMContentLoaded', function () {
            if (!window.wp || !window.wp.media) return;
            var frame = null;
            var btn = document.getElementById('bhs-release-video-upload');
            if (btn) btn.addEventListener('click', function () {
                if (frame) { frame.open(); return; }
                frame = wp.media({ title: 'Choose a video', button: { text: 'Use this' }, multiple: false, library: { type: 'video' } });
                frame.on('select', function () {
                    var att = frame.state().get('selection').first().toJSON();
                    document.getElementById('bhs_release_video_attachment_id').value = att.id;
                    document.getElementById('bhs_release_video_preview').innerHTML = '<video controls src="' + att.url + '" style="max-width:100%;"></video>';
                    document.getElementById('bhs-release-video-clear').style.display = '';
                });
                frame.open();
            });
            var clearBtn = document.getElementById('bhs-release-video-clear');
            if (clearBtn) clearBtn.addEventListener('click', function (e) {
                e.preventDefault();
                document.getElementById('bhs_release_video_attachment_id').value = '';
                document.getElementById('bhs_release_video_preview').innerHTML = '<p><em>No video attached yet.</em></p>';
                clearBtn.style.display = 'none';
            });
            });
        })();
        </script>
        <?php
    }

    public static function save_release_video(int $post_id): void {
        if (!isset($_POST['bhs_release_video_nonce']) || !wp_verify_nonce($_POST['bhs_release_video_nonce'], 'bhs_save_release_video')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $attachment_id = (int) ($_POST['bhs_release_video_attachment_id'] ?? 0);
        if (!$attachment_id) {
            delete_post_meta($post_id, '_bhs_release_video_id');
            return;
        }
        if (strpos((string) get_post_mime_type($attachment_id), 'video/') !== 0) return;

        // Reuse an existing bhs_video wrapping this same attachment if one
        // already exists (e.g. re-picking the same file after a previous
        // save) rather than creating a duplicate CPT post every save.
        $existing = get_posts([
            'post_type' => 'bhs_video', 'posts_per_page' => 1, 'fields' => 'ids',
            'meta_key' => '_bhs_video_id', 'meta_value' => (string) $attachment_id,
        ]);
        $video_id = $existing ? (int) $existing[0] : 0;

        if (!$video_id) {
            $video_id = wp_insert_post([
                'post_title'  => get_the_title($attachment_id) ?: 'Untitled video',
                'post_type'   => 'bhs_video',
                'post_status' => 'publish',
                'post_author' => get_current_user_id(),
            ], true);
            if (is_wp_error($video_id)) return;
            update_post_meta($video_id, '_bhs_video_id', $attachment_id);
            // Same sha1 duplicate-detection the REST import path already runs.
            if (class_exists('BHS_AudioHash')) BHS_AudioHash::hash_and_check($video_id, $attachment_id);
        }

        update_post_meta($post_id, '_bhs_release_video_id', $video_id);
    }

    /* ---------- local upload (mirrors BHS_Import's audio path, MIME-gated to video) ---------- */

    public static function register_routes(): void {
        register_rest_route('bhs/v1', '/video-import', [
            'methods' => 'POST',
            'callback' => [self::class, 'import_local_video'],
            'permission_callback' => function () {
                return is_user_logged_in() && current_user_can('upload_files');
            },
        ]);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function import_local_video(\WP_REST_Request $req) {
        if (empty($_FILES['video'])) {
            return new WP_Error('missing_file', 'No video file provided (expected multipart field "video").', ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload('video', 0);
        if (is_wp_error($attachment_id)) {
            return new WP_Error('upload_failed', $attachment_id->get_error_message(), ['status' => 400]);
        }
        $mime = get_post_mime_type($attachment_id);
        if (strpos((string) $mime, 'video/') !== 0) {
            wp_delete_attachment($attachment_id, true);
            return new WP_Error('not_video', 'That file was not recognized as a video file.', ['status' => 400]);
        }

        $title = sanitize_text_field((string) $req->get_param('title')) ?: get_the_title($attachment_id);
        $post_id = wp_insert_post([
            'post_title'  => $title ?: 'Untitled video',
            'post_type'   => 'bhs_video',
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ], true);
        if (is_wp_error($post_id)) {
            wp_delete_attachment($attachment_id, true);
            return new WP_Error('save_failed', 'Could not create the video.', ['status' => 500]);
        }

        update_post_meta($post_id, '_bhs_video_id', $attachment_id);

        // Same sha1 file-bytes duplicate-detection bh-streaming already
        // runs on audio uploads — the check itself is medium-agnostic
        // (it just hashes whatever file is attached), so it works
        // unchanged for video with no modification to that class.
        if (class_exists('BHS_AudioHash')) BHS_AudioHash::hash_and_check($post_id, $attachment_id);

        return new WP_REST_Response(['success' => true, 'video' => ['id' => $post_id, 'title' => get_the_title($post_id), 'url' => wp_get_attachment_url($attachment_id)]], 201);
    }
}
