<?php
if (!defined('ABSPATH')) exit;

/**
 * Registry contribution only — bh-live's actual settings UI lives
 * inside OUS_MediaWizard (own-ur-shit/includes/class-media-wizard.php),
 * not a separate admin screen, per the "one place for setup" call in
 * wondrous-mixing-forest.md: live-engine setup is one more step in the
 * same wizard that already handles storage/CDN, not a second onboarding
 * flow to discover.
 */
class BHL_Admin {
    public static function init() {
        add_filter('ous_registered_plugins', [self::class, 'register']);
        add_action('add_meta_boxes', [self::class, 'add_meta_boxes']);
        add_action('save_post_bhl_stream', [self::class, 'save_replay']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_media']);
    }

    public static function enqueue_media($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
        if (get_post_type() !== 'bhl_stream') return;
        wp_enqueue_media();
    }

    public static function add_meta_boxes() {
        add_meta_box('bhl_stream_details', 'Stream Details', [self::class, 'render_details_metabox'], 'bhl_stream', 'normal', 'high');
        add_meta_box('bhl_stream_replay', 'Replay', [self::class, 'render_replay_metabox'], 'bhl_stream', 'normal', 'default');
    }

    public static function render_details_metabox($post) {
        $status = get_post_meta($post->ID, '_bhl_status', true) ?: BHL_PostTypes::STATUS_LIVE;
        $started = get_post_meta($post->ID, '_bhl_started_at', true);
        $ended = get_post_meta($post->ID, '_bhl_ended_at', true);
        echo '<p><strong>Status:</strong> ' . ($status === BHL_PostTypes::STATUS_LIVE ? '&#128308; Live' : '&#9899; Ended (replay)') . '</p>';
        echo '<p><strong>Started:</strong> ' . esc_html($started ?: '—') . '</p>';
        if ($status !== BHL_PostTypes::STATUS_LIVE) echo '<p><strong>Ended:</strong> ' . esc_html($ended ?: '—') . '</p>';
        echo '<p class="description">This record is created and closed automatically by BHL_Streams\' own polling check — status/timestamps aren\'t editable by hand here.</p>';
    }

    /**
     * The one manual step in bh-live's whole VOD story. Owncast's own
     * recording-to-file feature (a real, documented Owncast setting)
     * writes the finished recording to THAT server's own local disk —
     * its public API has no endpoint to fetch that file remotely, so
     * there is no honest way to auto-import it into this WordPress
     * install without either direct filesystem/SFTP access to the
     * Owncast box (out of scope for a "thin integration" plugin) or a
     * custom upload step Owncast itself doesn't provide. Until that
     * changes, whoever runs the Owncast server downloads the finished
     * recording and uploads it here — same real wp.media video-type
     * picker bh-video's own admin metabox already uses, so at least
     * the WordPress-side half of this is exactly as easy as it can be.
     */
    public static function render_replay_metabox($post) {
        wp_nonce_field('bhl_save_replay', 'bhl_replay_nonce');
        $status = get_post_meta($post->ID, '_bhl_status', true);
        if ($status === BHL_PostTypes::STATUS_LIVE) {
            echo '<p class="description">Still live — a replay can be attached once this stream ends.</p>';
            return;
        }
        $vid = (int) get_post_meta($post->ID, '_bhl_replay_attachment_id', true);
        $vurl = $vid ? wp_get_attachment_url($vid) : '';
        echo '<p class="description">Owncast writes its recording to its own server — download the finished file from there and upload it here to make it the public replay.</p>';
        echo '<div id="bhl_replay_preview" style="max-width:480px;margin-bottom:8px;">' . ($vurl ? '<video controls src="' . esc_url($vurl) . '" style="width:100%;"></video>' : '<em>No replay uploaded yet.</em>') . '</div>';
        echo '<input type="hidden" id="bhl_replay_id" name="bhl_replay_id" value="' . esc_attr($vid) . '">';
        echo '<button type="button" class="button" id="bhl_replay_upload">' . ($vurl ? 'Replace replay' : 'Upload replay') . '</button></p>';
        echo '<script>
        (function () {
            var btn = document.getElementById("bhl_replay_upload");
            if (!btn || btn.dataset.bhlBound || !window.wp || !window.wp.media) return;
            btn.dataset.bhlBound = "1";
            var frame = null;
            btn.addEventListener("click", function () {
                if (frame) { frame.open(); return; }
                frame = wp.media({ title: "Choose the replay video", button: { text: "Use this video" }, multiple: false, library: { type: "video" } });
                frame.on("select", function () {
                    var att = frame.state().get("selection").first().toJSON();
                    document.getElementById("bhl_replay_id").value = att.id;
                    document.getElementById("bhl_replay_preview").innerHTML = "<video controls src=\"" + att.url + "\" style=\"width:100%;\"></video>";
                    btn.textContent = "Replace replay";
                });
                frame.open();
            });
        })();
        </script>';
    }

    public static function save_replay($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhl_replay_nonce']) || !wp_verify_nonce($_POST['bhl_replay_nonce'], 'bhl_save_replay')) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (isset($_POST['bhl_replay_id'])) {
            update_post_meta($post_id, '_bhl_replay_attachment_id', (int) $_POST['bhl_replay_id']);
        }
    }

    public static function register($plugins) {
        $plugins['bh-live'] = [
            'label'          => 'BH Live',
            'file'           => 'bh-live/bh-live.php',
            'depends_on'     => [],
            'check_class'    => 'BHL_OwncastEngine',
            'description'    => 'Two-way interactive live streaming via a self-hosted Owncast server, configured from the Media & CDN Setup wizard.',
            'dashboard_link' => 'admin.php?page=ous-media-setup',
            'bundled_zip'    => 'bh-live.zip',
        ];
        return $plugins;
    }
}
