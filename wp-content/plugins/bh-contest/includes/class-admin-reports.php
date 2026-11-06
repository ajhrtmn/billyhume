<?php
if (!defined('ABSPATH')) exit;

/**
 * Live results dashboard, CSV export, and winner notifications.
 */
class BH_AdminReports {
    public static function init(): void {
        add_action('admin_post_bh_export', [self::class, 'export_csv']);
        add_action('admin_post_bh_send_winners', [self::class, 'send_winner_notifications']);

        // One queued job per winner email, so a large send can't time out the request.
        if (class_exists('OUS_Jobs')) {
            OUS_Jobs::register('bh_contest_email_winner', [self::class, 'send_one_winner_email']);
        }
    }

    // Raw CSV export of submissions or votes, for audits and disputes.
    public static function export_csv(): void {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_GET['_wpnonce'] ?? '', 'bh_export')) {
            wp_die('Not allowed.', '', ['back_link' => true]);
        }
        $cid  = (int) ($_GET['contest_id'] ?? 0);
        $type = sanitize_key($_GET['type'] ?? '');
        if (!$cid || !in_array($type, ['submissions', 'votes'], true)) wp_die('Invalid export request.', '', ['back_link' => true]);

        $filename = 'bh-' . $type . '-contest-' . $cid . '-' . gmdate('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');

        if ($type === 'submissions') {
            fputcsv($out, ['Submission ID', 'Title', 'Artist', 'Author', 'Email', 'Status', 'Submitted']);
            $subs = get_posts([
                'post_type' => 'bh_submission', 'post_status' => 'any',
                'meta_key' => '_bh_contest_id', 'meta_value' => (string) $cid,
                'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'ASC',
            ]);
            foreach ($subs as $p) {
                $author = get_userdata((int) $p->post_author);
                fputcsv($out, array_map([self::class, 'csv_safe'], [
                    $p->ID, $p->post_title, BH_Helpers::artist_for($p),
                    $author ? $author->user_login : '', $author ? $author->user_email : '',
                    $p->post_status, $p->post_date,
                ]));
            }
        } else {
            global $wpdb;
            $t = BH_Helpers::table();
            $rows = $wpdb->get_results($wpdb->prepare(
                // id ASC breaks ties: created_at has only 1-second resolution.
                "SELECT user_id, category, submission_id, created_at FROM $t WHERE contest_id = %d ORDER BY created_at ASC, id ASC", $cid
            ));
            fputcsv($out, ['Voter', 'Category', 'Submission ID', 'Track Title', 'Voted At']);
            foreach ($rows as $r) {
                $voter = get_userdata($r->user_id);
                $track = get_post($r->submission_id);
                fputcsv($out, array_map([self::class, 'csv_safe'], [
                    $voter ? $voter->user_login : $r->user_id,
                    $r->category === '' ? '(default)' : $r->category,
                    $r->submission_id, $track ? $track->post_title : '(deleted)', $r->created_at,
                ]));
            }
        }

        fclose($out);
        exit;
    }

    // WHY: blocks CSV formula injection; submitter text may start with =/+/-/@,
    // so prefix an apostrophe.
    /** @param mixed $value */
    public static function csv_safe($value): string {
        $value = (string) $value;
        if ($value !== '' && strpbrk($value[0], "=+-@") !== false) {
            return "'" . $value;
        }
        return $value;
    }

    // Discord + winner emails, kept separate from "Publish Results" so an admin can
    // check numbers first. The sent timestamp makes a resend need confirmation.
    public static function send_winner_notifications(): void {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_GET['_wpnonce'] ?? '', 'bh_send_winners')) {
            wp_die('Not allowed.', '', ['back_link' => true]);
        }
        $cid = (int) ($_GET['contest_id'] ?? 0);
        if ($cid && get_post_meta($cid, '_bh_results_published', true) === '1') {
            BH_Discord::notify_results($cid);
            self::email_winners($cid);
            update_post_meta($cid, '_bh_winner_notifications_sent_at', current_time('mysql'));
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url(BH_PostTypes::MENU_PARENT));
        exit;
    }

    // One email per winning user, listing all of their placements.
    private static function email_winners(int $cid): void {
        $placements = []; // uid => [ [label, rank, votes, title], ... ]
        $medal = ['🥇', '🥈', '🥉'];

        $collect = function ($results, $label) use (&$placements) {
            foreach ($results as $r) {
                if ($r['rank'] > 3) continue;
                $post = get_post($r['id']);
                if (!$post) continue;
                $uid = (int) $post->post_author;
                $placements[$uid][] = ['label' => $label, 'rank' => $r['rank'], 'votes' => $r['votes'], 'title' => $r['title']];
            }
        };

        foreach (BH_Helpers::categories($cid) as $cat) {
            $collect(BH_API::category_results($cid, $cat['slug']), $cat['name']);
        }
        $collect(BH_Reveal::overall_results($cid), 'Overall');

        $contest_title = get_the_title($cid);

        // Queued per recipient; send_one_winner_email() logs its own failures.
        $queued = 0;
        foreach ($placements as $uid => $wins) {
            $user = get_userdata($uid);
            if (!$user || !$user->user_email) continue;

            $lines = array_map(
                fn($w) => ($medal[$w['rank'] - 1] ?? ('#' . $w['rank'])) . ' ' . $w['label'] . ' — "' . $w['title'] . '" (' . $w['votes'] . ' votes)',
                $wins
            );
            $body = "Hi {$user->user_login},\n\nCongratulations — here's how you placed in {$contest_title}:\n\n"
                . implode("\n", $lines) . "\n\nWell done!";

            if (class_exists('OUS_Jobs')) {
                OUS_Jobs::enqueue('bh_contest_email_winner', [
                    'contest_id' => $cid, 'user_id' => $uid,
                    'email' => $user->user_email, 'subject' => "You placed in {$contest_title}!", 'body' => $body,
                ]);
                $queued++;
            } else {
                // No job queue: send inline. BH_Mail logs its own failures, so only
                // the raw wp_mail() path is logged here.
                $sent = class_exists('BH_Mail')
                    ? BH_Mail::send([
                        'to' => $user->user_email, 'user_id' => $uid, 'subject' => "You placed in {$contest_title}!", 'body' => $body,
                        'source' => 'BH Contest', 'log_context' => ['contest_id' => $cid],
                    ])
                    : wp_mail($user->user_email, "You placed in {$contest_title}!", $body);
                if ($sent) {
                    $queued++;
                } elseif (!class_exists('BH_Mail') && class_exists('OUS_DebugLog')) {
                    OUS_DebugLog::log('warning', "Winner-notification email failed (no job queue fallback path).", ['contest_id' => $cid, 'user_id' => $uid], 'BH Contest');
                }
            }
        }
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('info', "Winner-notification send: $queued winner email(s) queued.", ['contest_id' => $cid], 'BH Contest');
        }
    }

    // OUS_Jobs handler registered in init(); one call per winner.
    /** @param array<string, mixed> $args */
    public static function send_one_winner_email($args): void {
        $email = $args['email'] ?? '';
        $subject = $args['subject'] ?? '';
        $body = $args['body'] ?? '';
        if (!$email || !$subject || !$body) return;

        if (class_exists('BH_Mail')) {
            BH_Mail::send([
                'to' => $email, 'user_id' => (int) ($args['user_id'] ?? 0), 'subject' => $subject, 'body' => $body,
                'source' => 'BH Contest', 'log_context' => ['contest_id' => $args['contest_id'] ?? 0],
            ]);
            return;
        }
        if (!wp_mail($email, $subject, $body) && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('warning', 'Winner-notification email failed to send.', [
                'contest_id' => $args['contest_id'] ?? 0, 'user_id' => $args['user_id'] ?? 0, 'email' => $email,
            ], 'BH Contest');
        }
    }

    public static function render_results(): void {
        $contests = BH_Helpers::all_contests();
        $cid = isset($_GET['contest_id']) ? (int) $_GET['contest_id'] : 0;
        if (!$cid || get_post_type($cid) !== 'bh_contest') $cid = BH_Helpers::active_contest();

        BHY_UI::shell_open('Contest Results <span id="bh-live-dot" class="bh-live-dot" title="Auto-refreshing"></span>');

        if (!$contests) {
            echo '<p>No contests yet. Create one under Contests → Add New.</p>';
            BHY_UI::shell_close();
            return;
        }

        $cats = BH_Helpers::categories($cid);
        $cat  = isset($_GET['bh_category']) ? sanitize_title($_GET['bh_category']) : '';
        $all  = false;
        if ($cats) {
            if (isset($_GET['bh_category']) && $_GET['bh_category'] === 'all') {
                $all = true;
            } elseif (!BH_Helpers::is_valid_category($cid, $cat)) {
                // No valid category chosen: default to the combined view.
                $all = true;
            }
        } else {
            $cat = ''; // single implicit category
        }

        // Contest/category picker; every section below uses the same selection.
        echo '<form method="get" style="margin-bottom:16px;">'
           . '<input type="hidden" name="post_type" value="bh_contest">'
           . '<input type="hidden" name="page" value="bh-results">'
           . '<label>Viewing: <select name="contest_id" onchange="this.form.submit()">';
        foreach ($contests as $c) {
            echo '<option value="' . (int) $c->ID . '" ' . selected($cid, $c->ID, false) . '>'
               . esc_html($c->post_title) . ' — ' . esc_html(ucfirst(BH_Helpers::contest_status($c->ID))) . '</option>';
        }
        echo '</select></label>';
        if ($cats) {
            echo ' &nbsp; <label>Category: <select name="bh_category" onchange="this.form.submit()">';
            echo '<option value="all" ' . selected($all, true, false) . '>All categories</option>';
            foreach ($cats as $c) {
                echo '<option value="' . esc_attr($c['slug']) . '" ' . selected(!$all && $cat === $c['slug'], true, false) . '>' . esc_html($c['name']) . '</option>';
            }
            echo '</select></label>';
        }
        echo ' <noscript><button class="button">Go</button></noscript></form>';

        global $wpdb;
        if ($all) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT submission_id, category, COUNT(id) votes FROM " . BH_Helpers::table() . "
                 WHERE contest_id = %d GROUP BY submission_id, category ORDER BY votes DESC",
                $cid
            ));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT submission_id, COUNT(id) votes FROM " . BH_Helpers::table() . "
                 WHERE contest_id = %d AND category = %s GROUP BY submission_id ORDER BY votes DESC",
                $cid, $cat
            ));
        }

        if ($all) {
            $total_votes   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . BH_Helpers::table() . " WHERE contest_id = %d", $cid));
            $unique_voters = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM " . BH_Helpers::table() . " WHERE contest_id = %d", $cid));
        } else {
            $total_votes   = array_sum(wp_list_pluck($rows, 'votes'));
            $unique_voters = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM " . BH_Helpers::table() . " WHERE contest_id = %d AND category = %s", $cid, $cat));
        }
        $voting_open = BH_Helpers::is_voting_open($cid);
        $published   = get_post_meta($cid, '_bh_results_published', true) === '1';
        $cat_label   = $cats ? ' — category: <strong>' . ($all ? 'All' : esc_html(self::category_name($cats, $cat))) . '</strong>' : '';

        echo '<p><strong>' . esc_html(get_the_title($cid)) . '</strong>' . $cat_label . ' — voting is currently <strong>' . ($voting_open ? 'open' : 'closed') . '</strong>. '
           . 'This page is private to admins; the public results page for this contest is ' . ($published ? '<strong>currently published</strong>' : 'still <strong>hidden</strong>') . ' regardless of what you see here. '
           . 'Shortcode: <code>' . esc_html(BH_Helpers::shortcode_for($cid)) . '</code></p>';

        echo '<div class="bh-stats-bar">'
           . '<div class="bh-stat"><span class="bh-stat-num" id="bh-stat-votes">' . (int) $total_votes . '</span><span class="bh-stat-label">total votes</span></div>'
           . '<div class="bh-stat"><span class="bh-stat-num" id="bh-stat-voters">' . (int) $unique_voters . '</span><span class="bh-stat-label">unique voters</span></div>'
           . '<div class="bh-stat"><span class="bh-stat-num" id="bh-stat-last">—</span><span class="bh-stat-label">last vote</span></div>'
           . '</div>';

        echo '<p><label><input type="checkbox" id="bh-autorefresh" checked> Auto-refresh every 8s</label> '
           . '&nbsp;·&nbsp; <span id="bh-updated-at" style="color:#666;">Not yet refreshed live.</span></p>';

        // Pie breakdowns always cover the whole contest, ignoring the category picker.
        if (class_exists('BH_Charts')) BH_Charts::render_section($cid, ['heading' => true]);

        $cat_col = $all ? '<th>Category</th>' : '';
        echo '<p>Click the <strong>Votes</strong> or <strong>Plays</strong> headers to sort. (Sort resets on each live refresh.)</p>';
        echo '<div class="bhy-table-wrap">';
        echo '<table class="wp-list-table widefat striped" id="bh-results-table">';
        echo '<thead><tr><th>Song &amp; Artist</th>' . $cat_col . '<th data-dir="desc">Votes</th><th data-dir="desc">Plays</th><th>Chart</th></tr></thead>';
        echo '<tbody id="bh-results-body">';
        self::results_rows_html($rows, $all ? $cats : null);
        echo '</tbody></table></div>';
        BHY_UI::shell_close();

        $rest  = esc_url_raw(rest_url('bh/v1/admin/live'));
        $nonce = wp_create_nonce('wp_rest');
        $cat_param = $all ? 'all' : $cat;

        echo "<style>
        .bh-live-dot{display:inline-block;width:10px;height:10px;border-radius:50%;background:#1DB954;margin-left:8px;vertical-align:middle;animation:bh-pulse 1.6s infinite;}
        .bh-live-dot.paused{background:#999;animation:none;}
        @keyframes bh-pulse{0%{opacity:1;}50%{opacity:.3;}100%{opacity:1;}}
        .bh-stats-bar{display:flex;gap:24px;margin:16px 0;}
        .bh-stat{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:10px 20px;text-align:center;}
        .bh-stat-num{display:block;font-size:24px;font-weight:700;}
        .bh-stat-label{display:block;font-size:12px;color:#666;text-transform:uppercase;letter-spacing:.03em;}
        </style>";

        echo "<script>
        (function(){
            const REST='" . esc_js($rest) . "', NONCE='" . esc_js($nonce) . "', CID='" . (int) $cid . "', CAT='" . esc_js($cat_param) . "', SHOW_CAT=" . ($all ? 'true' : 'false') . ";
            const dot=document.getElementById('bh-live-dot');
            const cb=document.getElementById('bh-autorefresh');
            const updatedAt=document.getElementById('bh-updated-at');
            let timer=null;

            function esc(s){const d=document.createElement('div');d.textContent=s==null?'':String(s);return d.innerHTML;}

            function renderRows(tracks){
                const tb=document.getElementById('bh-results-body');
                const cols = SHOW_CAT ? 5 : 4;
                if(!tracks.length){tb.innerHTML='<tr><td colspan=\"'+cols+'\">No votes yet.</td></tr>';return;}
                const top=Math.max(1,...tracks.map(t=>t.votes));
                tb.innerHTML=tracks.map(t=>{
                    const pct=((t.votes/top)*100).toFixed(1);
                    const catCell = SHOW_CAT ? '<td>'+esc(t.category||'—')+'</td>' : '';
                    return '<tr><td><strong>'+esc(t.title)+'</strong><br><small>'+esc(t.artist)+'</small></td>'
                        +catCell
                        +'<td>'+t.votes+'</td><td>'+t.plays+'</td>'
                        +'<td><div style=\"background:#e0e0e0;width:100%;height:20px;border-radius:3px;\">'
                        +'<div style=\"background:#1DB954;width:'+pct+'%;height:100%;border-radius:3px;\"></div></div></td></tr>';
                }).join('');
            }

            function fmtTime(iso){
                if(!iso)return '—';
                const d=new Date(iso.replace(' ','T')+'Z');
                if(isNaN(d))return '—';
                return d.toLocaleTimeString();
            }

            async function poll(){
                try{
                    const res=await fetch(REST+'?contest='+encodeURIComponent(CID)+'&category='+encodeURIComponent(CAT),{headers:{'X-WP-Nonce':NONCE}});
                    if(!res.ok)return;
                    const body=await res.json();
                    renderRows(body.tracks||[]);
                    document.getElementById('bh-stat-votes').textContent=body.total_votes||0;
                    document.getElementById('bh-stat-voters').textContent=body.unique_voters||0;
                    document.getElementById('bh-stat-last').textContent=fmtTime(body.last_vote_at);
                    updatedAt.textContent='Last refreshed '+new Date().toLocaleTimeString();
                }catch(e){ /* silent — next poll will retry */ }
            }

            function start(){ dot.classList.remove('paused'); poll(); timer=setInterval(poll,8000); }
            function stop(){ dot.classList.add('paused'); clearInterval(timer); }

            cb.addEventListener('change',()=> cb.checked ? start() : stop());
            start();

            // Existing click-to-sort still works on whatever the table currently shows.
            // Column indices shift by one when the Category column is present.
            const voteCol = SHOW_CAT ? 2 : 1, playCol = SHOW_CAT ? 3 : 2;
            document.querySelectorAll('#bh-results-table th').forEach((h,i)=>{
                if(i!==voteCol&&i!==playCol)return; h.style.cursor='pointer';
                h.addEventListener('click',()=>{
                    const tb=h.closest('table').querySelector('tbody');
                    const rows=Array.from(tb.querySelectorAll('tr'));
                    const dir=h.dataset.dir==='asc'?-1:1; h.dataset.dir=dir===1?'asc':'desc';
                    rows.sort((a,b)=>((parseInt(b.querySelectorAll('td')[i].innerText)||0)-(parseInt(a.querySelectorAll('td')[i].innerText)||0))*dir);
                    tb.append(...rows);
                });
            });
        })();
        </script>";
    }

    /** @param array<int, array<string, string>> $cats */
    private static function category_name($cats, string $slug): string {
        foreach ($cats as $c) if ($c['slug'] === $slug) return $c['name'];
        return '';
    }

    /**
     * @param array<int, object> $rows
     * @param array<int, array<string, string>>|null $cats
     */
    private static function results_rows_html($rows, $cats = null): void {
        $colspan = $cats ? 5 : 4;
        if (empty($rows)) { echo '<tr><td colspan="' . $colspan . '">No votes yet.</td></tr>'; return; }

        $cat_names = [];
        if ($cats) foreach ($cats as $c) $cat_names[$c['slug']] = $c['name'];

        $max = max(1, (int) $rows[0]->votes);
        foreach ($rows as $r) {
            $p = get_post($r->submission_id);
            if (!$p) continue;
            $votes = (int) $r->votes;
            $plays = (int) get_post_meta($p->ID, '_bh_play_count', true);
            $pct   = ($votes / $max) * 100;

            $cat_cell = '';
            if ($cats) {
                $slug = isset($r->category) ? $r->category : '';
                $name = $slug === '' ? '—' : ($cat_names[$slug] ?? $slug);
                $cat_cell = '<td>' . esc_html($name) . '</td>';
            }

            // Escape every dynamic value: titles/artists are submitter-controlled.
            echo '<tr>'
               . '<td><strong>' . esc_html($p->post_title) . '</strong><br><small>' . esc_html(BH_Helpers::artist_for($p)) . '</small></td>'
               . $cat_cell
               . '<td>' . $votes . '</td><td>' . $plays . '</td>'
               . '<td><div style="background:#e0e0e0;width:100%;height:20px;border-radius:3px;">'
               . '<div style="background:#1DB954;width:' . esc_attr((string) $pct) . '%;height:100%;border-radius:3px;"></div></div></td>'
               . '</tr>';
        }
    }
}
