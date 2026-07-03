<?php
if (!defined('ABSPATH')) exit;

/**
 * Private, admin-only dashboard for running a contest live. Shows every
 * submission for a chosen contest with its audio right there to play,
 * plus the real identity behind it (name, Discord/Twitch/YouTube — see
 * BH_Profiles) pulled in from the participant profile system, so an
 * admin listening through entries can immediately see who they're
 * listening to without cross-referencing anything.
 *
 * Deliberately never shown or linked anywhere near the public-facing
 * Listening Party or Results Reveal pages — this is the one place in the
 * whole plugin that's explicitly NOT safe to have visible in an OBS
 * capture, since it shows private contact info.
 */
class BH_Console {
    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
    }

    public static function add_menu() {
        add_submenu_page(
            BH_PostTypes::MENU_PARENT,
            'Live Console', 'Live Console', 'manage_options', 'bh-console',
            [self::class, 'render']
        );
    }

    public static function render() {
        $contests = BH_Helpers::all_contests();
        $cid = isset($_GET['contest_id']) ? (int) $_GET['contest_id'] : 0;
        if (!$cid && $contests) $cid = $contests[0]->ID;

        echo '<div class="wrap"><h1>Live Console</h1>';
        echo '<p class="description">Private — never link or share this page. Real names and contact info are shown here specifically so you know who you\'re listening to; none of it belongs on stream.</p>';

        if (!$contests) {
            echo '<p>No contests yet.</p></div>';
            return;
        }

        echo '<form method="get" style="margin:14px 0;"><input type="hidden" name="page" value="bh-console">';
        echo '<select name="contest_id" onchange="this.form.submit()">';
        foreach ($contests as $c) {
            echo '<option value="' . esc_attr($c->ID) . '" ' . selected($cid, $c->ID, false) . '>' . esc_html($c->post_title) . '</option>';
        }
        echo '</select></form>';

        if (!$cid) { echo '</div>'; return; }

        $phase = BH_Helpers::contest_phase_summary($cid);
        echo '<p><strong style="color:' . esc_attr($phase['color']) . ';">' . esc_html($phase['label']) . '</strong> &middot; '
           . '<a href="' . esc_url(admin_url('admin.php?page=bh-reveal&contest_id=' . $cid)) . '">Open Reveal Control</a></p>';

        $subs = get_posts([
            'post_type' => 'bh_submission', 'post_status' => 'any',
            'meta_key' => '_bh_contest_id', 'meta_value' => $cid,
            'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'ASC',
        ]);

        if (!$subs) { echo '<p>No submissions for this contest yet.</p></div>'; return; }

        global $wpdb;
        $t = BH_Helpers::table();

        echo '<table class="wp-list-table widefat striped"><thead><tr>'
           . '<th>Track</th><th>Status</th><th>Identity</th><th>Live votes</th>'
           . '</tr></thead><tbody>';

        foreach ($subs as $p) {
            $artist   = BH_Helpers::artist_for($p);
            $aid      = (int) get_post_meta($p->ID, '_bh_audio_id', true);
            $url      = $aid ? wp_get_attachment_url($aid) : '';
            $profile  = BH_Profiles::get((int) $p->post_author);
            $votes    = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $t WHERE contest_id = %d AND submission_id = %d", $cid, $p->ID
            ));

            echo '<tr>';
            echo '<td><strong>' . esc_html($p->post_title) . '</strong><br><span style="color:#787c82;">' . esc_html($artist) . '</span>';
            if ($url) echo '<br><audio controls preload="none" src="' . esc_url($url) . '" style="height:32px;margin-top:6px;max-width:240px;"></audio>';
            echo '</td>';
            echo '<td>' . ($p->post_status === 'publish' ? '<span style="color:#1DB954;">Approved</span>' : '<span style="color:#b3261e;">Pending</span>') . '</td>';
            echo '<td>' . self::identity_cell($profile) . '</td>';
            echo '<td style="font-weight:600;">' . esc_html($votes) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    private static function identity_cell($p) {
        $lines = [];
        if ($p['real_name'] !== '')     $lines[] = '<strong>' . esc_html($p['real_name']) . '</strong>';
        if ($p['discord_name'] !== '')  $lines[] = 'Discord: ' . esc_html($p['discord_name']);
        if ($p['twitch_name'] !== '')   $lines[] = 'Twitch: ' . esc_html($p['twitch_name']);
        if ($p['youtube_name'] !== '')  $lines[] = 'YouTube: ' . esc_html($p['youtube_name']);
        if (!$lines) return '<em style="color:#8a8a8a;">No profile on file</em>';
        return implode('<br>', $lines);
    }
}
