<?php
if (!defined('ABSPATH')) exit;

/**
 * A configurable max direct-upload size for lesson video steps. Rather
 * than guessing at what any given host allows, this is an admin-set
 * policy (default 0 = no limit, unchanged behavior) that steers an
 * author toward the video block's existing "URL (oEmbed)" source
 * (YouTube/Vimeo) once a file exceeds it — the right answer for long
 * video anyway (self-hosting large files has no adaptive bitrate/CDN
 * and eats the host's own storage/bandwidth regardless of PHP's
 * upload ceiling).
 *
 * Enforced in two places: the block editor warns before an author
 * finishes picking a file (immediate feedback, non-blocking), and
 * BHC_ContentBridge::sync_legacy_steps() re-checks the real attached
 * file size on every save (authoritative — a REST/programmatic save
 * never touches the JS).
 */
class BHC_VideoSettings {
    const OPTION = 'bhc_max_direct_video_mb';

    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_bhc_save_video_settings', [self::class, 'save']);
        add_action('enqueue_block_editor_assets', [self::class, 'localize_limit']);
    }

    public static function max_mb() {
        return max(0, (int) get_option(self::OPTION, 0));
    }

    public static function add_menu() {
        add_submenu_page(
            BHC_PostTypes::MENU_PARENT, 'Video Settings', 'Video Settings',
            'manage_options', 'bhc-video-settings', [self::class, 'render']
        );
    }

    public static function localize_limit() {
        if (get_current_screen() === null || get_current_screen()->post_type !== 'bh_lesson') return;
        wp_add_inline_script('wp-blocks', 'window.BHCMaxVideoMB = ' . self::max_mb() . ';', 'before');
    }

    public static function render() {
        if (!current_user_can('manage_options')) wp_die('Not allowed.', '', ['back_link' => true]);
        settings_errors('bhc_video_settings');
        $mb = self::max_mb();

        echo '<div class="wrap"><h1>Video Settings</h1>';
        echo '<p class="description">Controls the "Lesson: Video" block\'s <strong>Uploaded file</strong> source. Leave at 0 for no limit — the default, unchanged behavior. Neither this site\'s own PHP upload limit nor real hosting (Bluehost, a Wasmer deploy target, etc.) is assumed here; set this to whatever ceiling actually makes sense for wherever this ends up running.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bhc_save_video_settings', 'bhc_video_settings_nonce');
        echo '<input type="hidden" name="action" value="bhc_save_video_settings">';
        echo '<table class="form-table"><tr><th scope="row"><label for="bhc_max_video_mb">Max direct upload size</label></th><td>';
        echo '<input type="number" min="0" step="1" id="bhc_max_video_mb" name="bhc_max_video_mb" value="' . esc_attr($mb) . '" style="width:100px;"> MB';
        echo '<p class="description">Above this size, a lesson video must use the block\'s <strong>URL (oEmbed)</strong> source (YouTube/Vimeo) instead of uploading the file directly.</p>';
        echo '</td></tr></table>';
        echo '<p class="submit"><button type="submit" class="button button-primary">Save</button></p>';
        echo '</form></div>';
    }

    public static function save() {
        if (!current_user_can('manage_options') || !check_admin_referer('bhc_save_video_settings', 'bhc_video_settings_nonce')) {
            wp_die('Invalid request.');
        }
        update_option(self::OPTION, max(0, (int) ($_POST['bhc_max_video_mb'] ?? 0)));
        add_settings_error('bhc_video_settings', 'saved', 'Saved.', 'success');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(add_query_arg('settings-updated', 'true', admin_url('edit.php?post_type=bh_course&page=bhc-video-settings')));
        exit;
    }

    /**
     * Authoritative, server-side check — walks a lesson's real block
     * tree for any bhc/video step using the 'upload' source, and flags
     * (via a transient admin notice, never blocking the save outright —
     * a false positive here shouldn't trap an author) any whose actual
     * attached file exceeds the configured limit. Called from
     * BHC_ContentBridge::sync_legacy_steps(), which already parses this
     * exact tree on every save.
     */
    public static function check_tree($post_id, $tree) {
        $max_mb = self::max_mb();
        if (!$max_mb) return;

        $over = [];
        foreach ($tree as $block) {
            if ($block['blockName'] !== 'bhc/video') continue;
            $attrs = $block['attrs'] ?? [];
            if (($attrs['source'] ?? 'upload') !== 'upload' || empty($attrs['attachment_id'])) continue;
            $file = get_attached_file((int) $attrs['attachment_id']);
            if (!$file || !file_exists($file)) continue;
            $mb = filesize($file) / 1048576;
            if ($mb > $max_mb) $over[] = round($mb, 1);
        }
        if ($over) {
            set_transient('bhc_video_size_notice_' . $post_id, $over, 60);
        }
    }

    public static function maybe_show_notice() {
        $post_id = get_the_ID();
        if (!$post_id) return;
        $over = get_transient('bhc_video_size_notice_' . $post_id);
        if (!$over) return;
        delete_transient('bhc_video_size_notice_' . $post_id);
        $max_mb = self::max_mb();
        echo '<div class="notice notice-warning"><p>This lesson has a video step (' . esc_html(implode(', ', array_map(fn($mb) => $mb . 'MB', $over))) . ') larger than the ' . (int) $max_mb . 'MB direct-upload limit set in <a href="' . esc_url(admin_url('edit.php?post_type=bh_course&page=bhc-video-settings')) . '">Video Settings</a>. Consider switching that step to the URL (oEmbed) source instead — it was still saved as-is.</p></div>';
    }
}
