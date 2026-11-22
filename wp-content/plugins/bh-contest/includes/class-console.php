<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin-only Live Console: every submission with playable audio and the
 * participant's identity (BHI_Profiles), plus the reveal controls.
 * PRIVACY: shows private contact info, so never link it from or show it on stream.
 */
class BH_Console {
    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu']);
    }

    public static function add_menu(): void {
        add_submenu_page(
            BH_PostTypes::MENU_PARENT,
            'Live Console', 'Live Console', 'manage_options', 'bh-console',
            [self::class, 'render']
        );
    }

    public static function render(): void {
        $contests = BH_Helpers::all_contests();
        $cid = isset($_GET['contest_id']) ? (int) $_GET['contest_id'] : BH_Reveal::default_contest();

        BHY_UI::shell_open(BHCON_Words::get('admin.console.live_console'), 'Private — never link or share this page. Real names and contact info are shown here specifically so you know who you\'re listening to; none of it belongs on stream.');

        if (!$contests) {
            echo ('<p>' . BHCON_Words::get('admin.console.no_contests_yet') . '</p>');
            BHY_UI::shell_close();
            return;
        }

        // WHY: a GET form drops the query string, so carry post_type explicitly or WP
        // can't resolve the submenu ("Sorry, you are not allowed to access this page").
        echo '<form method="get" style="margin:0 0 var(--bhy-space-4);"><input type="hidden" name="post_type" value="bh_contest"><input type="hidden" name="page" value="bh-console">';
        echo '<select name="contest_id" onchange="this.form.submit()">';
        foreach ($contests as $c) {
            echo '<option value="' . esc_attr((string) $c->ID) . '" ' . selected($cid, $c->ID, false) . '>' . esc_html($c->post_title) . '</option>';
        }
        echo '</select></form>';

        if (!$cid) { BHY_UI::shell_close(); return; }

        $phase = BH_Helpers::contest_phase_summary($cid);
        echo '<p><span class="bhy-badge bhy-badge-dot" style="background:' . esc_attr($phase['color']) . '1a;color:' . esc_attr($phase['color']) . ';">' . esc_html($phase['label']) . '</span> &middot; ';
        $export_base = admin_url('admin-post.php?action=bh_export&contest_id=' . $cid);
        echo '<a href="' . esc_url(wp_nonce_url($export_base . '&type=submissions', 'bh_export')) . ('">' . BHCON_Words::get('admin.console.export_submissions_csv') . '</a> &middot; ');
        echo '<a href="' . esc_url(wp_nonce_url($export_base . '&type=votes', 'bh_export')) . ('">' . BHCON_Words::get('admin.console.export_votes_csv') . '</a> &middot; ');
        echo '<a href="' . esc_url(admin_url('edit.php?post_type=bh_contest&page=bh-results&contest_id=' . $cid)) . '">Full results</a> &middot; ';
        echo '<a href="' . esc_url(admin_url('edit.php?post_type=bh_submission&bh_contest_filter=' . $cid)) . ('">' . BHCON_Words::get('admin.console.all_submissions') . '</a></p>');

        // At-a-glance counts: approved, pending review, votes, voters.
        $approved = BH_Helpers::submission_count($cid, 'publish');
        $pending  = BH_Helpers::submission_count($cid, 'pending');
        $votes    = BH_Helpers::vote_count($cid);
        $voters   = BH_Helpers::voter_count($cid);
        echo '<div class="bhc-console-stats">';
        echo '<div class="bhc-console-stat"><span class="bhc-console-stat-num">' . (int) $approved . ('</span><span class="bhc-console-stat-label">' . BHCON_Words::get('admin.console.approved') . '</span></div>');
        if ($pending > 0) {
            $pending_url = esc_url(admin_url('edit.php?post_type=bh_submission&post_status=pending&bh_contest_filter=' . $cid));
            echo '<div class="bhc-console-stat bhc-console-stat-attn"><a href="' . $pending_url . '"><span class="bhc-console-stat-num">' . (int) $pending . ('</span><span class="bhc-console-stat-label">' . BHCON_Words::get('admin.console.pending_review') . '</span></a></div>');
        } else {
            echo ('<div class="bhc-console-stat"><span class="bhc-console-stat-num">0</span><span class="bhc-console-stat-label">' . BHCON_Words::get('admin.console.pending_review') . '</span></div>');
        }
        echo '<div class="bhc-console-stat"><span class="bhc-console-stat-num">' . (int) $votes . ('</span><span class="bhc-console-stat-label">' . BHCON_Words::get('admin.admin_reports.votes') . '</span></div>');
        echo '<div class="bhc-console-stat"><span class="bhc-console-stat-num">' . (int) $voters . ('</span><span class="bhc-console-stat-label">' . BHCON_Words::get('admin.console.voters') . '</span></div>');
        echo '</div>';
        echo '<style>
            .bhc-console-stats { display: flex; gap: 12px; margin: 0 0 16px; flex-wrap: wrap; }
            .bhc-console-stat { background: var(--bhy-surface, #fff); border: 1px solid var(--bhy-border, #dcdcde); border-radius: var(--bhy-radius-sm, 6px); padding: 8px 16px; text-align: center; min-width: 90px; }
            .bhc-console-stat a { text-decoration: none; color: inherit; display: block; }
            .bhc-console-stat-num { display: block; font-size: 20px; font-weight: 700; line-height: 1.2; }
            .bhc-console-stat-label { font-size: 11px; text-transform: uppercase; letter-spacing: .03em; color: var(--bhy-ink-dim, #646970); }
            .bhc-console-stat-attn { border-color: #b3261e; }
            .bhc-console-stat-attn .bhc-console-stat-num { color: #b3261e; }
        </style>';

        $suspicious = BH_Helpers::suspicious_voters($cid);
        if ($suspicious) {
            echo '<div class="bhy-alert bhy-alert-warning">';
            echo ('<strong>' . BHCON_Words::get('admin.console.rapid_voting_detected') . '</strong> ' . BHCON_Words::get('admin.console.worth_look_not_necessarily_problem'));
            echo '<ul style="margin:var(--bhy-space-2) 0 0 18px;">';
            foreach ($suspicious as $s) {
                $u = get_userdata($s->user_id);
                echo '<li>' . esc_html($u ? $u->user_login : BHCON_Words::get('admin.console.user') . $s->user_id) . ': ' . esc_html($s->vote_count) . ' votes in ' . esc_html($s->span_seconds) . 's</li>';
            }
            echo '</ul></div>';
        }

        // Second fraud signal (shared IP); like the one above, for review only, never blocking.
        $ip_clusters = BH_Helpers::suspicious_ip_clusters($cid);
        if ($ip_clusters) {
            echo '<div class="bhy-alert bhy-alert-warning">';
            echo ('<strong>' . BHCON_Words::get('admin.console.multiple_accounts_voting_from_same') . '</strong> ' . BHCON_Words::get('admin.console.worth_look_not_necessarily_problem_2'));
            echo '<ul style="margin:var(--bhy-space-2) 0 0 18px;">';
            foreach ($ip_clusters as $c) {
                $names = array_map(function ($uid) {
                    $u = get_userdata($uid);
                    return $u ? $u->user_login : "User #$uid";
                }, $c->user_ids);
                echo '<li>' . esc_html($c->ip_address) . ': ' . esc_html(implode(', ', $names)) . ' (' . esc_html($c->vote_count) . ' votes in ' . esc_html($c->span_seconds) . 's)'
                   . ($c->same_fingerprint ? (' <strong style="color:#b42318;">' . BHCON_Words::get('admin.console.same_browser_fingerprint') . '</strong>') : '') . '</li>';
            }
            echo '</ul></div>';
        }

        echo '<div class="bhy-card" style="max-width:640px;">';
        echo ('<h2>' . BHCON_Words::get('admin.console.reveal_controls') . '</h2>');
        BH_Reveal::render_controls_widget($cid);
        echo '</div>';

        $subs = get_posts([
            'post_type' => 'bh_submission', 'post_status' => 'any',
            'meta_key' => '_bh_contest_id', 'meta_value' => (string) $cid,
            'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'ASC',
        ]);

        if (!$subs) { echo ('<p>' . BHCON_Words::get('admin.console.no_submissions_contest_yet') . '</p>'); BHY_UI::shell_close(); return; }

        global $wpdb;
        $t = BH_Helpers::table();

        echo '<div class="bhy-table-wrap">';
        echo '<table class="wp-list-table widefat striped"><thead><tr>'
           . ('<th>' . BHCON_Words::get('admin.console.track') . '</th><th>' . BHCON_Words::get('admin.console.status') . '</th><th>' . BHCON_Words::get('admin.console.identity') . '</th><th>Live votes</th><th>' . BHCON_Words::get('admin.console.actions') . '</th>')
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
            echo '<td><strong>' . esc_html($p->post_title) . '</strong><br><span style="color:var(--bhy-ink-dim);">' . esc_html($artist) . '</span>';
            if ($url) echo '<br><audio controls preload="none" src="' . esc_url($url) . '" style="height:32px;margin-top:6px;max-width:240px;"></audio>';
            echo '</td>';
            $status_badge = [
                'publish'  => ('<span class="bhy-badge bhy-badge-success">' . BHCON_Words::get('admin.console.approved') . '</span>'),
                'rejected' => ('<span class="bhy-badge bhy-badge-neutral">' . BHCON_Words::get('admin.admin_metaboxes.rejected') . '</span>'),
            ][$p->post_status] ?? ('<span class="bhy-badge bhy-badge-warning">' . BHCON_Words::get('admin.console.pending') . '</span>');
            echo '<td>' . $status_badge . '</td>';
            echo '<td>' . self::identity_cell($profile) . '</td>';
            echo '<td style="font-weight:600;">' . esc_html((string) $votes) . '</td>';
            echo '<td>';
            if ($p->post_status !== 'publish') {
                $approve_url = wp_nonce_url(
                    admin_url('admin-post.php?action=bh_quick_approve&submission_id=' . $p->ID),
                    'bh_quick_approve_' . $p->ID
                );
                echo '<a href="' . esc_url($approve_url) . ('">' . BHCON_Words::get('admin.admin_moderation.approve') . '</a> &middot; ');
            }
            echo '<a href="' . esc_url((string) get_edit_post_link($p->ID, '')) . ('">' . BHCON_Words::get('admin.admin_menus.edit')) . ($p->post_status !== 'publish' ? ' / Reject' : '') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        BHY_UI::shell_close();
    }

    // "OK on stream" lists only fields the participant marked public; the full
    // private reference goes underneath. Artist name is shown in the Track column.
    /** @param array<string, mixed> $p */
    private static function identity_cell($p): string {
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
        // Phone is never public (prize contact only).
        if ($p['phone'] !== '') $all[] = 'Phone: ' . esc_html($p['phone']);

        if (!$all) return ('<em style="color:var(--bhy-ink-dim);">' . BHCON_Words::get('admin.console.no_profile_file') . '</em>');

        $html = ('<div style="margin-bottom:4px;"><span class="bhy-badge bhy-badge-success">' . BHCON_Words::get('admin.console.ok_stream') . '</span> ')
              . ($public ? implode(', ', $public) : ('<em style="color:var(--bhy-ink-dim);">' . BHCON_Words::get('admin.console.nothing_don_t_say_any') . '</em>'))
              . '</div>';
        $html .= '<div style="color:var(--bhy-ink-dim);font-size:var(--bhy-text-xs);">' . implode('<br>', $all) . '</div>';
        return $html;
    }
}
