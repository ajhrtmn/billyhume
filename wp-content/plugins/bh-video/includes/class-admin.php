<?php
if (!defined('ABSPATH')) exit;

/**
 * Genre selection needs no code here — registering bhv_genre with
 * show_ui true (class-post-types.php) already gives a standard
 * tag-style picker for free. Only the video-file upload and the
 * optional bhs_track link need a custom metabox, same division of
 * labor bh-streaming's own class-admin.php uses.
 */
class BHV_Admin {
    public static function init(): void {
        add_action('add_meta_boxes', [self::class, 'add_meta_boxes']);
        add_action('save_post_bhv_video', [self::class, 'save_video']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_media']);
        add_filter('ous_registered_plugins', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $plugins
     * @return array<string, mixed>
     */
    public static function register(array $plugins): array {
        $plugins['bh-video'] = [
            'label'          => 'BH Video',
            'file'           => 'bh-video/bh-video.php',
            'depends_on'     => [],
            'check_class'    => 'BHV_PostTypes',
            'description'    => 'A standalone video catalog and player, independent of bh-streaming\'s audio catalog.',
            'dashboard_link' => 'edit.php?post_type=bhv_video',
            // See bh-feedback's own class-admin.php for why this must be
            // listed here (this array fully replaces, not merges into,
            // class-registry.php's hardcoded DEFAULTS entry).
            'bundled_zip'    => 'bh-video.zip',
        ];
        return $plugins;
    }

    public static function enqueue_media(string $hook): void {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
        if (get_post_type() !== 'bhv_video') return;
        wp_enqueue_media();
    }

    public static function add_meta_boxes(): void {
        add_meta_box('bhv_video_details', 'Video Details', [self::class, 'render_video_metabox'], 'bhv_video', 'normal', 'high');
    }

    public static function render_video_metabox(\WP_Post $post): void {
        wp_nonce_field('bhv_save_video', 'bhv_video_nonce');
        $vid    = (int) get_post_meta($post->ID, '_bhv_attachment_id', true);
        $vurl   = $vid ? wp_get_attachment_url($vid) : '';
        $track_id = (int) get_post_meta($post->ID, '_bhv_track_id', true);

        echo '<p><strong>Video file</strong></p>';
        echo '<div id="bhv_video_preview" style="max-width:480px;margin-bottom:8px;">' . ($vurl ? '<video controls src="' . esc_url($vurl) . '" style="width:100%;"></video>' : '<em>No video uploaded yet.</em>') . '</div>';
        echo '<input type="hidden" id="bhv_video_id" name="bhv_video_id" value="' . esc_attr((string) $vid) . '">';
        echo '<button type="button" class="button" id="bhv_video_upload">' . ($vurl ? 'Replace video' : 'Upload video') . '</button></p>';

        // Optional link back to a bhs_track — nullable by design (AJ's
        // own call, scoping pass 2026-07-26): most videos WILL be tied
        // to a song, but non-music video content isn't forced into a
        // fake track relationship. Soft dependency: this whole field is
        // simply absent if bh-streaming isn't active.
        if (post_type_exists('bhs_track')) {
            $tracks = get_posts(['post_type' => 'bhs_track', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']);
            echo '<p><label><strong>Official video for track</strong> <span class="description">(optional)</span><br>';
            echo '<select name="bhv_track_id" style="width:100%;max-width:480px;"><option value="0">— none —</option>';
            foreach ($tracks as $t) {
                echo '<option value="' . (int) $t->ID . '"' . selected($track_id, $t->ID, false) . '>' . esc_html($t->post_title) . '</option>';
            }
            echo '</select></label></p>';
        }

        self::render_media_picker_script();
    }

    private static function render_media_picker_script(): void {
        ?>
        <script>
        (function () {
            var btn = document.getElementById('bhv_video_upload');
            if (!btn || btn.dataset.bhvBound || !window.wp || !window.wp.media) return;
            btn.dataset.bhvBound = '1';
            var frame = null;
            btn.addEventListener('click', function () {
                if (frame) { frame.open(); return; }
                frame = wp.media({ title: 'Choose a video', button: { text: 'Use this video' }, multiple: false, library: { type: 'video' } });
                frame.on('select', function () {
                    var att = frame.state().get('selection').first().toJSON();
                    document.getElementById('bhv_video_id').value = att.id;
                    document.getElementById('bhv_video_preview').innerHTML = '<video controls src="' + att.url + '" style="width:100%;"></video>';
                    btn.textContent = 'Replace video';
                });
                frame.open();
            });
        })();
        </script>
        <?php
    }

    public static function save_video(int $post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhv_video_nonce']) || !wp_verify_nonce($_POST['bhv_video_nonce'], 'bhv_save_video')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['bhv_video_id'])) {
            update_post_meta($post_id, '_bhv_attachment_id', (int) $_POST['bhv_video_id']);
        }
        if (isset($_POST['bhv_track_id'])) {
            update_post_meta($post_id, '_bhv_track_id', (int) $_POST['bhv_track_id']);
        }
    }
}
