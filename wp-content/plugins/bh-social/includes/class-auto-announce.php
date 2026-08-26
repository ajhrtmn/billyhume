<?php
if (!defined('ABSPATH')) exit;

/**
 * Item 18 scoping conclusion (see bh-social/CHANGELOG.md): cross_post()
 * across the four organic platforms is NOT one generic action.
 * YouTube/Meta/TikTok's cross_post() each upload/attach a real media
 * file (attachment_id, video_url, image_url) — there is no coherent
 * "new course/contest/release" media file to hand them automatically.
 * Twitch's cross_post() is the one platform whose payload is plain
 * text (a chat announcement) — the only one a generic "something new
 * just published" event can drive without inventing fake media.
 *
 * So: this class wires an opt-in (default OFF), Twitch-only auto-
 * announce for first-publish of bh_course / bh_contest / bhs_release,
 * reacting to core WordPress's own transition_post_status — no
 * class_exists() dependency on bh-courses/bh-contest/bh-streaming is
 * needed, since a post_type string that doesn't exist on this install
 * just never matches and this hook never fires for it (same
 * "every peer plugin is optional" posture, one layer more literal:
 * reacting to data shape, not calling another plugin's code at all).
 * YouTube/Meta/TikTok stay manual-only, as they already were.
 */
class BHSO_AutoAnnounce {
    const OPTION = 'bhso_auto_announce_settings';

    /** @var array<string, string> post_type => human label, in display order */
    const TYPES = [
        'bh_course'   => 'New course published',
        'bh_contest'  => 'New contest opened',
        'bhs_release' => 'New release published',
    ];

    public static function init(): void {
        add_action('transition_post_status', [self::class, 'maybe_announce'], 20, 3);
        add_action('admin_post_bhso_save_auto_announce_settings', [self::class, 'handle_save_settings']);
    }

    /** @return array<string, bool> */
    public static function settings(): array {
        $stored = get_option(self::OPTION, []);
        $out = [];
        foreach (self::TYPES as $type => $label) {
            $out[$type] = !empty($stored[$type]);
        }
        return $out;
    }

    public static function handle_save_settings(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bhso_save_auto_announce_settings');

        $update = [];
        foreach (self::TYPES as $type => $label) {
            $update[$type] = !empty($_POST[$type]);
        }
        update_option(self::OPTION, $update);

        wp_safe_redirect(admin_url('admin.php?page=bh-social&updated=1'));
        exit;
    }

    public static function maybe_announce(string $new_status, string $old_status, \WP_Post $post): void {
        if ($new_status !== 'publish' || $old_status === 'publish') return; // first publish only, same test bh-contest's own moderation hook already uses
        if (!isset(self::TYPES[$post->post_type])) return;

        $settings = self::settings();
        if (empty($settings[$post->post_type])) return; // opt-in, default off

        if (!class_exists('BHSO_PlatformRegistry')) return;
        $twitch = BHSO_PlatformRegistry::get('twitch');
        if (!$twitch || !$twitch->is_configured() || $twitch->get_status() !== 'connected') return;

        $message = substr(self::TYPES[$post->post_type] . ': ' . $post->post_title . ' — ' . get_permalink($post), 0, 500);
        $result = $twitch->cross_post(['message' => $message, 'color' => 'primary']);

        if (is_wp_error($result) && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('warning', 'Auto-announce cross-post failed for ' . $post->post_type . ' #' . $post->ID . ': ' . $result->get_error_message(), [], 'bh-social');
        }
    }

    /** Rendered from BHSO_Admin::render(), same section-per-concern layout as the platform sections above it. */
    public static function render_settings_section(): void {
        $settings = self::settings();

        echo '<h3>Auto-announce to Twitch <span class="description" style="font-weight:normal;">(opt-in, default off)</span></h3>';
        echo '<p class="description">When a course/contest/release is published for the first time, post a plain-text chat announcement to your connected Twitch channel. YouTube/Meta/TikTok aren\'t offered here — their cross-post is a real media upload (video/image), and there\'s no matching media file to send automatically.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bhso_save_auto_announce_settings');
        echo '<input type="hidden" name="action" value="bhso_save_auto_announce_settings">';
        foreach (self::TYPES as $type => $label) {
            echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="' . esc_attr($type) . '" value="1" ' . checked(!empty($settings[$type]), true, false) . '> ' . esc_html($label) . '</label>';
        }
        submit_button('Save Auto-Announce Settings');
        echo '</form>';
    }
}
