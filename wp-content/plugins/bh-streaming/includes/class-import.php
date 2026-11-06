<?php
if (!defined('ABSPATH')) exit;

/**
 * Listener "import my own music": uploads become bhs_track posts owned by
 * the uploader, flagged _bhs_source = 'local-import'.
 *
 * Requires 'upload_files' (Author+ by default). Sites that want every
 * listener to import must grant that capability to the listener role.
 */
class BHS_Import {
    public static function register_routes(): void {
        register_rest_route('bhs/v1', '/import', [
            'methods'  => 'POST',
            'callback' => [self::class, 'import_local_file'],
            'permission_callback' => function () {
                return is_user_logged_in() && current_user_can('upload_files');
            },
        ]);
        register_rest_route('bhs/v1', '/import/mine', [
            'methods'  => 'GET',
            'callback' => [self::class, 'get_my_imports'],
            'permission_callback' => 'is_user_logged_in',
        ]);
        register_rest_route('bhs/v1', '/import/(?P<id>\d+)', [
            'methods'  => 'DELETE',
            'callback' => [self::class, 'delete_import'],
            'permission_callback' => 'is_user_logged_in',
        ]);
    }

    // Single multipart upload ('audio') plus optional 'title'/'artist', via
    // media_handle_upload() so core file-type, size, and path checks apply.
    /** @return \WP_REST_Response|\WP_Error */
    public static function import_local_file(\WP_REST_Request $req) {
        if (empty($_FILES['audio'])) {
            return new WP_Error('missing_file', 'No audio file provided (expected multipart field "audio").', ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // media_handle_upload() runs wp_check_filetype_and_ext(), which rejects
        // non-audio files regardless of extension.
        $attachment_id = media_handle_upload('audio', 0);
        if (is_wp_error($attachment_id)) {
            return new WP_Error('upload_failed', $attachment_id->get_error_message(), ['status' => 400]);
        }
        $mime = get_post_mime_type($attachment_id);
        if (strpos((string) $mime, 'audio/') !== 0) {
            wp_delete_attachment($attachment_id, true);
            return new WP_Error('not_audio', 'That file was not recognized as an audio file.', ['status' => 400]);
        }

        $title  = sanitize_text_field((string) $req->get_param('title'))  ?: get_the_title($attachment_id);
        $artist = sanitize_text_field((string) $req->get_param('artist')) ?: '';

        $post_id = wp_insert_post([
            'post_title'  => $title ?: 'Untitled',
            'post_type'   => 'bhs_track',
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ], true);
        if (is_wp_error($post_id)) {
            wp_delete_attachment($attachment_id, true);
            return new WP_Error('save_failed', 'Could not create the track.', ['status' => 500]);
        }

        update_post_meta($post_id, '_bhs_artist', $artist);
        update_post_meta($post_id, '_bhs_audio_id', $attachment_id);
        update_post_meta($post_id, '_bhs_source', 'local-import');

        // Local imports have no ownership verification; see class-audio-hash.php.
        if (class_exists('BHS_AudioHash')) BHS_AudioHash::hash_and_check($post_id, $attachment_id);

        return new WP_REST_Response(['success' => true, 'track' => BHS_API::track_payload(get_post($post_id))], 201);
    }

    // The current user's own imports (post_author = current user).
    public static function get_my_imports(): \WP_REST_Response {
        return new WP_REST_Response(['success' => true, 'tracks' => array_map(['BHS_API', 'track_payload'], self::imports_for_user(get_current_user_id()))], 200);
    }

    // Same as get_my_imports() for any user id, returning WP_Post objects so
    // other plugins (e.g. bh-crm) can call it directly without REST.
    /** @return \WP_Post[] */
    public static function imports_for_user(int $user_id): array {
        return get_posts([
            'post_type' => 'bhs_track', 'post_status' => 'publish', 'posts_per_page' => -1,
            'author' => (int) $user_id, 'meta_key' => '_bhs_source', 'meta_value' => 'local-import',
        ]);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function delete_import(\WP_REST_Request $req) {
        $id = (int) $req->get_param('id');
        $post = get_post($id);
        if (!$post || $post->post_type !== 'bhs_track' || get_post_meta($id, '_bhs_source', true) !== 'local-import') {
            return new WP_Error('not_found', 'Import not found.', ['status' => 404]);
        }
        // Only the importer (or edit_others_posts) may remove it.
        if ((int) $post->post_author !== get_current_user_id() && !current_user_can('edit_others_posts')) {
            return new WP_Error('forbidden', 'Not your import.', ['status' => 403]);
        }

        $audio_id = (int) get_post_meta($id, '_bhs_audio_id', true);
        wp_delete_post($id, true);
        if ($audio_id) wp_delete_attachment($audio_id, true);

        return new WP_REST_Response(['success' => true], 200);
    }
}
