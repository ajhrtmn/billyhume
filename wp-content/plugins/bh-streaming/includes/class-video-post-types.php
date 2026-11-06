<?php
if (!defined('ABSPATH')) exit;

/**
 * bhs_video post type, separate from bhs_track (audio-only fields like ISRC
 * have no video meaning). A release can optionally link one video. Video
 * attachments are ordinary WP attachments; no custom storage handling.
 */
class BHS_VideoPostTypes {
    // WHY: called directly from the plugin's 'init' hook; a nested
    // add_action('init', ...) inside a running 'init' callback never fires.
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

    // wp.media picker (pick existing or upload) scoped to the video library.
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
<?php echo (string) file_get_contents(BHS_PATH . 'assets/inline/video-post-types.js'); ?></script>
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

        // Reuse an existing bhs_video for the same attachment rather than duplicate.
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

    /* ---------- local upload (mirrors BHS_Import, MIME-gated to video) ---------- */

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

        // Same sha1 duplicate detection as audio uploads.
        if (class_exists('BHS_AudioHash')) BHS_AudioHash::hash_and_check($post_id, $attachment_id);

        return new WP_REST_Response(['success' => true, 'video' => ['id' => $post_id, 'title' => get_the_title($post_id), 'url' => wp_get_attachment_url($attachment_id)]], 201);
    }
}
