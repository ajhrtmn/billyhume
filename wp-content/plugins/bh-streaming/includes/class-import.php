<?php
if (!defined('ABSPATH')) exit;

/**
 * A real "import my own music" path, distinct from class-feeds.php's
 * feed-based aggregator import. Where a feed import pulls tracks from a
 * remote source an admin has curated, THIS is a listener uploading
 * their own local files into their own personal library — the track
 * becomes a real bhs_track post, owned by that user (post_author),
 * flagged _bhs_source = 'local-import' so the rest of the catalog/
 * player code treats it exactly like any other track (same code path
 * bh-streaming already established for 'external' vs. local — see
 * class-post-types.php), while class-admin.php and class-api.php both
 * already know to treat a track's OWN owner as the only one who can
 * edit/remove it.
 *
 * Requires 'upload_files' — the standard WordPress capability for
 * "can add media" (granted by default to Author and above, NOT
 * Subscriber). A site that wants every logged-in visitor to be able to
 * import their own local files needs to grant that one capability to
 * whatever role its listeners hold — a one-line `$role->add_cap(...)`
 * in a site-specific mu-plugin, not something this plugin should force
 * on every install by changing WordPress's own role defaults.
 */
class BHS_Import {
    public static function register_routes() {
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

    // Accepts a single multipart file upload (field name 'audio') plus
    // optional 'title'/'artist' fields. Uses WordPress's own
    // media_handle_upload() — the same validated, capability-checked
    // upload pipeline the block editor and classic media uploader use —
    // rather than touching $_FILES directly, so file-type sniffing,
    // upload-size limits, and the uploads-directory sandboxing all stay
    // exactly what a real WordPress install already enforces.
    public static function import_local_file($req) {
        if (empty($_FILES['audio'])) {
            return new WP_Error('missing_file', 'No audio file provided (expected multipart field "audio").', ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // media_handle_upload() itself calls wp_check_filetype_and_ext(),
        // which is what actually rejects anything that isn't a real,
        // allowed audio mime type — not a manual extension check here
        // that a renamed file could slip past.
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

        // Local-import is the exact upload path that has no ownership
        // verification of any kind — see class-audio-hash.php's own
        // docblock for what this does and doesn't catch.
        if (class_exists('BHS_AudioHash')) BHS_AudioHash::hash_and_check($post_id, $attachment_id);

        return new WP_REST_Response(['success' => true, 'track' => BHS_API::track_payload(get_post($post_id))], 201);
    }

    // Every logged-in user's own imported tracks — deliberately scoped
    // to post_author = current user, same ownership model as playlists.
    public static function get_my_imports() {
        $posts = get_posts([
            'post_type' => 'bhs_track', 'post_status' => 'publish', 'posts_per_page' => -1,
            'author' => get_current_user_id(), 'meta_key' => '_bhs_source', 'meta_value' => 'local-import',
        ]);
        return new WP_REST_Response(['success' => true, 'tracks' => array_map(['BHS_API', 'track_payload'], $posts)], 200);
    }

    public static function delete_import($req) {
        $id = (int) $req->get_param('id');
        $post = get_post($id);
        if (!$post || $post->post_type !== 'bhs_track' || get_post_meta($id, '_bhs_source', true) !== 'local-import') {
            return new WP_Error('not_found', 'Import not found.', ['status' => 404]);
        }
        // Only the user who imported it (or someone with edit_others_posts,
        // i.e. an admin/editor) may remove it — never anyone else's.
        if ((int) $post->post_author !== get_current_user_id() && !current_user_can('edit_others_posts')) {
            return new WP_Error('forbidden', 'Not your import.', ['status' => 403]);
        }

        $audio_id = (int) get_post_meta($id, '_bhs_audio_id', true);
        wp_delete_post($id, true);
        if ($audio_id) wp_delete_attachment($audio_id, true);

        return new WP_REST_Response(['success' => true], 200);
    }
}
