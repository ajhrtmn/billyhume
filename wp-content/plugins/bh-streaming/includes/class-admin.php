<?php
if (!defined('ABSPATH')) exit;

/**
 * Genre selection needs no code here at all — registering bhs_genre as
 * a non-hierarchical taxonomy with show_ui true (see class-post-types.php)
 * already gives every bh_track edit screen a standard tag-style picker
 * for free. Only the things WordPress doesn't already have a UI for —
 * audio/artwork upload, the release picker — need custom metaboxes.
 */
class BHS_Admin {
    public static function init() {
        add_action('add_meta_boxes', [self::class, 'add_meta_boxes']);
        add_action('save_post_bh_track', [self::class, 'save_track']);
        add_action('save_post_bh_release', [self::class, 'save_release']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_media']);

        add_filter('manage_bh_track_posts_columns', [self::class, 'columns']);
        add_action('manage_bh_track_posts_custom_column', [self::class, 'column_content'], 10, 2);
    }

    public static function enqueue_media($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
        if (!in_array(get_post_type(), ['bh_track', 'bh_release'], true)) return;
        wp_enqueue_media();
    }

    public static function add_meta_boxes() {
        add_meta_box('bhs_track_details', 'Track Details', [self::class, 'render_track_metabox'], 'bh_track', 'normal', 'high');
        add_meta_box('bhs_release_details', 'Release Details', [self::class, 'render_release_metabox'], 'bh_release', 'normal', 'high');
    }

    /* ---------- track metabox ---------- */

    public static function render_track_metabox($post) {
        wp_nonce_field('bhs_save_track', 'bhs_track_nonce');
        $artist  = get_post_meta($post->ID, '_bhs_artist', true);
        $aid     = (int) get_post_meta($post->ID, '_bhs_audio_id', true);
        $art_id  = (int) get_post_meta($post->ID, '_bhs_artwork_id', true);
        $aurl    = $aid ? wp_get_attachment_url($aid) : '';
        $art_url = $art_id ? wp_get_attachment_image_url($art_id, 'medium') : '';
        $release_id = (int) get_post_meta($post->ID, '_bhs_release_id', true);
        $is_external = get_post_meta($post->ID, '_bhs_source', true) === 'external';

        if ($is_external) {
            echo '<p class="description">This track was imported from an external feed — see Feed Sources. Audio and artist come from that feed and aren\'t editable here.</p>';
        }

        echo '<p><label><strong>Artist</strong><br><input type="text" name="bhs_artist" value="' . esc_attr($artist) . '" style="width:100%;" placeholder="Artist name"' . ($is_external ? ' disabled' : '') . '></label></p>';

        echo '<p><strong>Release</strong> <span class="description">(optional — groups this track into an album/EP)</span></p>';
        echo '<select name="bhs_release_id"><option value="">— None —</option>';
        foreach (get_posts(['post_type' => 'bh_release', 'post_status' => 'publish', 'posts_per_page' => -1]) as $r) {
            echo '<option value="' . esc_attr($r->ID) . '" ' . selected($release_id, $r->ID, false) . '>' . esc_html($r->post_title) . '</option>';
        }
        echo '</select>';

        if (!$is_external) {
            echo '<p style="margin-top:14px;"><strong>Audio file</strong></p>';
            echo '<input type="hidden" id="bhs_audio_id" name="bhs_audio_id" value="' . esc_attr($aid) . '">';
            echo '<div id="bhs_audio_preview">' . ($aurl ? "<audio controls src='" . esc_url($aurl) . "' style='width:100%;'></audio>" : '<p><em>No audio attached.</em></p>') . '</div>';
            echo '<p><button type="button" class="button" id="bhs_audio_upload">Choose audio…</button></p>';
        }

        echo '<p><strong>Artwork</strong> <span class="description">(falls back to the release\'s artwork, or a generated placeholder)</span></p>';
        echo '<input type="hidden" id="bhs_artwork_id" name="bhs_artwork_id" value="' . esc_attr($art_id) . '">';
        echo '<div id="bhs_artwork_preview" style="width:120px;height:120px;background:#f0f0f0;border-radius:6px;overflow:hidden;">' . ($art_url ? '<img src="' . esc_url($art_url) . '" style="width:100%;height:100%;object-fit:cover;">' : '') . '</div>';
        echo '<p><button type="button" class="button" id="bhs_artwork_upload">Choose artwork…</button></p>';

        self::render_media_picker_script();
    }

    /* ---------- release metabox ---------- */

    public static function render_release_metabox($post) {
        wp_nonce_field('bhs_save_release', 'bhs_release_nonce');
        $artist = get_post_meta($post->ID, '_bhs_release_artist', true);
        $art_id = (int) get_post_meta($post->ID, '_bhs_release_artwork_id', true);
        $art_url = $art_id ? wp_get_attachment_image_url($art_id, 'medium') : '';

        echo '<p><label><strong>Artist</strong><br><input type="text" name="bhs_release_artist" value="' . esc_attr($artist) . '" style="width:100%;"></label></p>';
        echo '<p><strong>Artwork</strong></p>';
        echo '<input type="hidden" id="bhs_artwork_id" name="bhs_release_artwork_id" value="' . esc_attr($art_id) . '">';
        echo '<div id="bhs_artwork_preview" style="width:160px;height:160px;background:#f0f0f0;border-radius:6px;overflow:hidden;">' . ($art_url ? '<img src="' . esc_url($art_url) . '" style="width:100%;height:100%;object-fit:cover;">' : '') . '</div>';
        echo '<p><button type="button" class="button" id="bhs_artwork_upload">Choose artwork…</button></p>';

        self::render_media_picker_script();
    }

    private static function render_media_picker_script() {
        ?>
        <script>
        (function () {
            function pick(buttonId, hiddenId, previewId, isImage) {
                var btn = document.getElementById(buttonId);
                if (!btn || btn.dataset.bhsBound || !window.wp || !window.wp.media) return;
                btn.dataset.bhsBound = '1';
                var frame = null;
                btn.addEventListener('click', function () {
                    if (frame) { frame.open(); return; }
                    frame = wp.media({ title: 'Choose a file', button: { text: 'Use this' }, multiple: false, library: isImage ? { type: 'image' } : { type: 'audio' } });
                    frame.on('select', function () {
                        var att = frame.state().get('selection').first().toJSON();
                        document.getElementById(hiddenId).value = att.id;
                        var preview = document.getElementById(previewId);
                        if (isImage) {
                            preview.innerHTML = '<img src="' + (att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url) + '" style="width:100%;height:100%;object-fit:cover;">';
                        } else {
                            preview.innerHTML = '<audio controls src="' + att.url + '" style="width:100%;"></audio>';
                        }
                    });
                    frame.open();
                });
            }
            pick('bhs_audio_upload', 'bhs_audio_id', 'bhs_audio_preview', false);
            pick('bhs_artwork_upload', 'bhs_artwork_id', 'bhs_artwork_preview', true);
        })();
        </script>
        <?php
    }

    /* ---------- saving ---------- */

    public static function save_track($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhs_track_nonce']) || !wp_verify_nonce($_POST['bhs_track_nonce'], 'bhs_save_track')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $is_external = get_post_meta($post_id, '_bhs_source', true) === 'external';
        if (!$is_external) {
            if (isset($_POST['bhs_artist']))   update_post_meta($post_id, '_bhs_artist', sanitize_text_field($_POST['bhs_artist']));
            if (isset($_POST['bhs_audio_id'])) update_post_meta($post_id, '_bhs_audio_id', (int) $_POST['bhs_audio_id']);
        }
        if (isset($_POST['bhs_artwork_id']))  update_post_meta($post_id, '_bhs_artwork_id', (int) $_POST['bhs_artwork_id']);
        if (isset($_POST['bhs_release_id']))  update_post_meta($post_id, '_bhs_release_id', (int) $_POST['bhs_release_id']);
    }

    public static function save_release($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhs_release_nonce']) || !wp_verify_nonce($_POST['bhs_release_nonce'], 'bhs_save_release')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['bhs_release_artist']))     update_post_meta($post_id, '_bhs_release_artist', sanitize_text_field($_POST['bhs_release_artist']));
        if (isset($_POST['bhs_release_artwork_id'])) update_post_meta($post_id, '_bhs_release_artwork_id', (int) $_POST['bhs_release_artwork_id']);
    }

    /* ---------- list table ---------- */

    public static function columns($cols) {
        $new = [];
        foreach ($cols as $k => $v) {
            $new[$k] = $v;
            if ($k === 'title') { $new['bhs_artist'] = 'Artist'; $new['bhs_audio'] = 'Audio'; $new['bhs_plays'] = 'Plays'; }
        }
        return $new;
    }

    public static function column_content($col, $post_id) {
        if ($col === 'bhs_artist') echo esc_html(get_post_meta($post_id, '_bhs_artist', true));
        if ($col === 'bhs_audio') {
            $has_audio = (bool) BHS_API::audio_url_for($post_id);
            echo $has_audio ? '<span style="color:#1DB954;">&#10003; attached</span>' : '<span style="color:#b3261e;">missing</span>';
        }
        if ($col === 'bhs_plays') echo esc_html((int) get_post_meta($post_id, '_bhs_play_count', true));
    }
}
