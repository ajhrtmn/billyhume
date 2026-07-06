<?php
if (!defined('ABSPATH')) exit;

/**
 * Private, admin-only dashboard for running a contest live. Shows every
 * submission for a chosen contest with its audio right there to play,
 * plus the real identity behind it (name, Discord/Twitch/YouTube — see
 * BHI_Profiles) pulled in from the participant profile system, so an
 * admin listening through entries can immediately see who they're
 * listening to without cross-referencing anything.
 *
 * Also embeds the Results Reveal controls (see BH_Reveal::
 * render_controls_widget()) directly on this page — everything needed to
 * actually run the show, both the participant reference info and the
 * buttons that drive what's on stream, colocated on one screen instead
 * of split across two separate admin pages.
 *
 * Deliberately never shown or linked anywhere near the public-facing
 * Results Reveal page — this is the one place in the whole plugin
 * that's explicitly NOT safe to have visible in an OBS capture, since
 * it shows private contact info.
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
        $cid = isset($_GET['contest_id']) ? (int) $_GET['contest_id'] : BH_Reveal::default_contest();

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
        echo '<p><strong style="color:' . esc_attr($phase['color']) . ';">' . esc_html($phase['label']) . '</strong> &middot; ';
        $export_base = admin_url('admin-post.php?action=bh_export&contest_id=' . $cid);
        echo '<a href="' . esc_url(wp_nonce_url($export_base . '&type=submissions', 'bh_export')) . '">Export submissions (CSV)</a> &middot; ';
        echo '<a href="' . esc_url(wp_nonce_url($export_base . '&type=votes', 'bh_export')) . '">Export votes (CSV)</a></p>';

        $suspicious = BH_Helpers::suspicious_voters($cid);
        if ($suspicious) {
            echo '<div style="background:#fef3e2;border:1px solid #e0a020;border-radius:8px;padding:12px 16px;margin-bottom:16px;max-width:640px;">';
            echo '<strong style="color:#8a5a00;">⚠️ Rapid voting detected</strong> — worth a look, not necessarily a problem:';
            echo '<ul style="margin:6px 0 0 18px;">';
            foreach ($suspicious as $s) {
                $u = get_userdata($s->user_id);
                echo '<li>' . esc_html($u ? $u->user_login : 'User #' . $s->user_id) . ': ' . esc_html($s->vote_count) . ' votes in ' . esc_html($s->span_seconds) . 's</li>';
            }
            echo '</ul></div>';
        }

        // Reveal controls live right here, not on a separate admin page —
        // everything needed to actually run the show (who's who, plus
        // the buttons that drive what's on stream) stays on one screen.
        echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px;margin-bottom:20px;max-width:640px;">';
        echo '<h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.04em;">Reveal Controls</h2>';
        BH_Reveal::render_controls_widget($cid);
        echo '</div>';

        $subs = get_posts([
            'post_type' => 'bh_submission', 'post_status' => 'any',
            'meta_key' => '_bh_contest_id', 'meta_value' => $cid,
            'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'ASC',
        ]);

        if (!$subs) { echo '<p>No submissions for this contest yet.</p></div>'; return; }

        global $wpdb;
        $t = BH_Helpers::table();

        echo '<div style="overflow-x:auto;">';
        echo '<table class="wp-list-table widefat striped"><thead><tr>'
           . '<th>Track</th><th>Status</th><th>Identity</th><th>Live votes</th>'
           . '</tr></thead><tbody>';

        foreach ($subs as $p) {
            $artist   = BH_Helpers::artist_for($p);
            $aid      = (int) get_post_meta($p->ID, '_bh_audio_id', true);
            $url      = $aid ? wp_get_attachment_url($aid) : '';
            $profile  = BHI_Profiles::get((int) $p->post_author);
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

        echo '</tbody></table></div></div>';
    }

    // Splits into what's actually safe to say out loud on stream (only
    // fields the participant explicitly marked public — see BHI_Profiles)
    // versus the full private reference underneath. Artist name is
    // deliberately not part of this — that's already shown in the Track
    // column and is always public by nature of being submitted; this is
    // specifically about which of their *personal* names/handles they
    // consented to have shared, which is a separate question entirely.
    private static function identity_cell($p) {
        $fields = [
            ['real_name', 'real_name_public', ''],
            ['discord_name', 'discord_public', 'Discord: '],
            ['twitch_name', 'twitch_public', 'Twitch: '],
            ['youtube_name', 'youtube_public', 'YouTube: '],
        ];

        $public = [];
        $all = [];
        foreach ($fields as [$name_key, $public_key, $prefix]) {
            if ($p[$name_key] === '') continue;
            $line = $prefix . esc_html($p[$name_key]);
            $all[] = $line;
            if ((int) $p[$public_key] === 1) $public[] = $line;
        }
        // Phone has no public/private toggle at all — it's never a
        // candidate for the "OK on stream" list above, only the private
        // reference below (prize-contact purposes only).
        if ($p['phone'] !== '') $all[] = 'Phone: ' . esc_html($p['phone']);

        if (!$all) return '<em style="color:#8a8a8a;">No profile on file</em>';

        $html = '<div style="margin-bottom:4px;"><strong style="color:#1DB954;">OK on stream:</strong> '
              . ($public ? implode(', ', $public) : '<em style="color:#8a8a8a;">nothing — don\'t say any name for them</em>')
              . '</div>';
        $html .= '<div style="color:#787c82;font-size:11px;">' . implode('<br>', $all) . '</div>';
        return $html;
    }
}
