<?php
if (!defined('ABSPATH')) exit;

/**
 * Split out of class-admin.php (DRY/SOLID audit Phase 3b) — the live
 * results dashboard, CSV export, and winner-notification send. No
 * settings/metabox rendering or submission moderation here.
 */
class BH_AdminReports {
    public static function init() {
        add_action('admin_post_bh_export', [self::class, 'export_csv']);
        add_action('admin_post_bh_send_winners', [self::class, 'send_winner_notifications']);
    }

    // A raw data export as a safety net — if a vote or a submission is
    // ever disputed, or something needs auditing outside wp-admin
    // entirely, this is the "pull everything" escape hatch rather than
    // having no way to get the underlying data out at all.
    public static function export_csv() {
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
                'meta_key' => '_bh_contest_id', 'meta_value' => $cid,
                'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'ASC',
            ]);
            foreach ($subs as $p) {
                $author = get_userdata($p->post_author);
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
                // id ASC as a tiebreaker — created_at only has 1-second
                // resolution and a real voting window can easily land
                // many votes in the same second, which makes plain
                // ORDER BY created_at ASC non-deterministic about intra-
                // second order for this audit-trail export (same class
                // of bug caught and fixed in bh-crm's notes feature).
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

    // Submission titles and artist names are submitter-controlled free
    // text (sanitize_text_field() doesn't strip a leading =/+/-/@), so
    // without this a crafted title could be read as a formula by
    // Excel/Sheets/LibreOffice the moment this export is opened — the
    // standard "CSV injection" pattern. The export action is already
    // capability+nonce gated; this closes the separate gap of the data
    // inside it not being safe for a spreadsheet to open. A leading
    // apostrophe is the standard, invisible-in-the-cell fix.
    public static function csv_safe($value) {
        $value = (string) $value;
        if ($value !== '' && strpbrk($value[0], "=+-@") !== false) {
            return "'" . $value;
        }
        return $value;
    }

    // Deliberately separate from the "Publish Results" checkbox — that
    // just makes results visible on the site; this is the loud part
    // (Discord announcement + winner emails), and an admin might want a
    // gap between the two, e.g. to publish, sanity-check the numbers
    // look right, and only then announce — or to hold the announcement
    // for a specific moment regardless of when results actually went
    // live. Tracks a sent timestamp so accidentally clicking again shows
    // a confirmation rather than silently re-notifying everyone.
    public static function send_winner_notifications() {
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

    // Sends ONE email per winning user, combining every placement they
    // earned (a category win plus an overall win, or multiple category
    // wins) into a single message rather than firing off a separate
    // email per placement — nobody wants three emails because they won
    // three categories.
    private static function email_winners($cid) {
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
        $sent = 0; $failed_uids = [];
        foreach ($placements as $uid => $wins) {
            $user = get_userdata($uid);
            if (!$user || !$user->user_email) continue;

            $lines = array_map(
                fn($w) => ($medal[$w['rank'] - 1] ?? ('#' . $w['rank'])) . ' ' . $w['label'] . ' — "' . $w['title'] . '" (' . $w['votes'] . ' votes)',
                $wins
            );
            $body = "Hi {$user->user_login},\n\nCongratulations — here's how you placed in {$contest_title}:\n\n"
                . implode("\n", $lines) . "\n\nWell done!";
            if (wp_mail($user->user_email, "You placed in {$contest_title}!", $body)) {
                $sent++;
            } else {
                $failed_uids[] = $uid;
            }
        }
        // Previously wp_mail()'s return value was ignored entirely — a
        // bulk send with some/all messages silently rejected by the mail
        // transport looked identical to a fully successful one from the
        // admin's side, with no record of WHICH winners never got
        // notified. wp_mail() itself doesn't expose a failure reason
        // (that's the well-known limitation OUS_Mail's own roadmap entry
        // exists to eventually fix — see ROADMAP-platform-evolution.md
        // Section 2's BH_Mail interface item), but a count + the
        // specific affected user IDs is still a real, actionable
        // improvement over nothing.
        if ($failed_uids && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('warning', "Winner-notification bulk send: $sent sent, " . count($failed_uids) . ' failed.', [
                'contest_id' => $cid, 'failed_user_ids' => $failed_uids,
            ], 'BH Contest');
        }
    }

    public static function render_results() {
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
                // No valid category chosen yet (first visit) — default to
                // the combined view rather than picking one arbitrarily.
                $all = true;
            }
        } else {
            $cat = ''; // single implicit category, unchanged from before categories existed
        }

        // Contest (and, if it has any, category) picker — reloads the page
        // with ?contest_id=X&bh_category=Y so every section below (stats,
        // table, live poll) targets the same combination.
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

    private static function category_name($cats, $slug) {
        foreach ($cats as $c) if ($c['slug'] === $slug) return $c['name'];
        return '';
    }

    private static function results_rows_html($rows, $cats = null) {
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

            // Every dynamic value escaped on output (defends against a crafted
            // title/artist becoming stored XSS in the dashboard).
            echo '<tr>'
               . '<td><strong>' . esc_html($p->post_title) . '</strong><br><small>' . esc_html(BH_Helpers::artist_for($p)) . '</small></td>'
               . $cat_cell
               . '<td>' . $votes . '</td><td>' . $plays . '</td>'
               . '<td><div style="background:#e0e0e0;width:100%;height:20px;border-radius:3px;">'
               . '<div style="background:#1DB954;width:' . esc_attr($pct) . '%;height:100%;border-radius:3px;"></div></div></td>'
               . '</tr>';
        }
    }
}
