<?php
if (!defined('ABSPATH')) exit;

class BH_Admin {
    public static function init() {
        add_action('admin_menu', [self::class, 'add_menus']);
        add_action('add_meta_boxes', [self::class, 'add_meta_boxes']);
        add_action('save_post_bh_contest', [self::class, 'save_contest_meta']);

        // Contest list table: status pill, copyable shortcode, quick stats.
        add_filter('manage_bh_contest_posts_columns', [self::class, 'contest_columns']);
        add_action('manage_bh_contest_posts_custom_column', [self::class, 'contest_column_content'], 10, 2);
        add_action('admin_post_bh_quick_schedule', [self::class, 'quick_schedule']);
        add_action('admin_post_bh_create_page', [self::class, 'create_page_action']);

        // Submissions list: which contest each one belongs to, plus a filter
        // dropdown — the flat approval queue is unreadable once more than
        // one contest has submissions in it.
        add_filter('manage_bh_submission_posts_columns', [self::class, 'submission_columns']);
        add_action('manage_bh_submission_posts_custom_column', [self::class, 'submission_column_content'], 10, 2);
        add_action('restrict_manage_posts', [self::class, 'submission_contest_filter']);
        add_action('pre_get_posts', [self::class, 'apply_submission_contest_filter']);

        // Auto-created contest pages: a small box linking back to the
        // contest they belong to, shown only on pages that have one.
        add_action('add_meta_boxes_page', [self::class, 'add_page_backlink_meta_box']);

        // The core "Posts" menu is for blog posts, which this site isn't
        // using — Contests/Submissions live under their own menu, and a
        // second unrelated "Posts" item just confuses that mental model.
        add_action('admin_menu', [self::class, 'hide_posts_menu'], 999);
    }

    public static function add_menus() {
        add_submenu_page(
            BH_PostTypes::MENU_PARENT,
            'Contest Results', 'Results', 'manage_options', 'bh-results',
            [self::class, 'render_results']
        );
    }

    /* ================= Contest list table ================= */

    public static function contest_columns($cols) {
        $new = [];
        foreach ($cols as $k => $v) {
            $new[$k] = $v;
            if ($k === 'title') {
                $new['bh_status']    = 'Status';
                $new['bh_shortcode'] = 'Shortcode';
                $new['bh_page']      = 'Page';
                $new['bh_stats']     = 'Submissions / Votes';
            }
        }
        return $new;
    }

    public static function contest_column_content($col, $post_id) {
        if ($col === 'bh_status') {
            $s = BH_Helpers::contest_status($post_id);
            $map   = ['open' => '#1DB954', 'upcoming' => '#8a8a8a', 'closed' => '#b3261e', 'unscheduled' => '#8a8a8a'];
            $label = ['open' => 'Open', 'upcoming' => 'Upcoming', 'closed' => 'Closed', 'unscheduled' => 'Not scheduled'];
            echo '<span style="display:inline-block;padding:2px 10px;border-radius:999px;background:' . $map[$s] . ';color:#fff;font-size:11px;font-weight:600;">' . esc_html($label[$s]) . '</span>';

            // One-click override so switching a contest's phase doesn't
            // require opening the edit screen and hand-editing date pickers.
            // Plain links, not forms — this cell sits inside the list
            // table's own <form>, and nested <form> elements are invalid
            // HTML that browsers "fix" by hijacking the outer submit.
            $quick = function ($which, $text) use ($post_id) {
                $url = wp_nonce_url(
                    admin_url('admin-post.php?action=bh_quick_schedule&contest_id=' . (int) $post_id . '&which=' . $which),
                    'bh_quick_schedule'
                );
                echo ' <a href="' . esc_url($url) . '" style="font-size:11px;">' . esc_html($text) . '</a>';
            };
            if ($s === 'upcoming' || $s === 'unscheduled') $quick('start', 'Start now');
            if ($s === 'open') $quick('end', 'End now');
        }
        if ($col === 'bh_shortcode') {
            $sc = BH_Helpers::shortcode_for($post_id);
            echo '<code style="user-select:all;font-size:12px;cursor:text;" title="Click and copy">' . esc_html($sc) . '</code>';
        }
        if ($col === 'bh_page') {
            echo self::page_links_html($post_id);
        }
        if ($col === 'bh_stats') {
            $subs  = BH_Helpers::submission_count($post_id);
            $votes = BH_Helpers::vote_count($post_id);
            $url   = admin_url(BH_PostTypes::MENU_PARENT . '&page=bh-results&contest_id=' . $post_id);
            echo esc_html($subs) . ' subs · <a href="' . esc_url($url) . '">' . esc_html($votes) . ' votes</a>';
        }
    }

    // Sets a contest's start or end to "right now", instantly flipping its
    // status — for "Start now"/"End now" links in the contest list.
    public static function quick_schedule() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bh_quick_schedule')) {
            wp_die('Not allowed.');
        }
        $cid   = (int) ($_GET['contest_id'] ?? 0);
        $which = sanitize_key($_GET['which'] ?? '');

        if ($cid && get_post_type($cid) === 'bh_contest' && in_array($which, ['start', 'end'], true)) {
            $now = current_time('mysql'); // already the exact format contest_status() expects
            update_post_meta($cid, $which === 'start' ? '_bh_start' : '_bh_end', $now);
            // Starting a contest with no end date yet gives it one week —
            // otherwise it would land on "unscheduled" instead of "open".
            if ($which === 'start' && !get_post_meta($cid, '_bh_end', true)) {
                update_post_meta($cid, '_bh_end', gmdate('Y-m-d H:i:s', current_time('timestamp') + 7 * DAY_IN_SECONDS));
            }
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url(BH_PostTypes::MENU_PARENT));
        exit;
    }

    // View/Edit links for a contest's auto-created page, or a one-click
    // "Create page" fallback if none exists (trashed, or the contest
    // predates this feature).
    private static function page_links_html($contest_id) {
        $page_id = (int) get_post_meta($contest_id, '_bh_page_id', true);
        $status  = $page_id ? get_post_status($page_id) : false;

        if ($page_id && $status && $status !== 'trash') {
            return '<a href="' . esc_url(get_permalink($page_id)) . '">View</a> · <a href="' . esc_url(get_edit_post_link($page_id)) . '">Edit</a>';
        }

        // Link, not a form — this can render inside the contest edit
        // screen's meta box, which is itself inside WordPress's post-edit
        // <form>. A nested form there breaks the real "Update" submit.
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=bh_create_page&contest_id=' . (int) $contest_id),
            'bh_create_page'
        );
        return '<a href="' . esc_url($url) . '">Create page</a>';
    }

    public static function create_page_action() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bh_create_page')) {
            wp_die('Not allowed.');
        }
        $cid = (int) ($_GET['contest_id'] ?? 0);
        if ($cid && get_post_type($cid) === 'bh_contest') self::maybe_create_contest_page($cid, true);
        wp_safe_redirect(wp_get_referer() ?: admin_url(BH_PostTypes::MENU_PARENT));
        exit;
    }

    // Creates a simple page containing this contest's shortcode the first
    // time the contest is published, and cross-links the two. Uses the
    // numeric contest ID (not the slug) in the shortcode so the link keeps
    // working even if the contest's title/slug changes later. Won't
    // duplicate: skipped if a live (non-trashed) page is already linked,
    // unless $force is passed (the "Create page" fallback button).
    private static function maybe_create_contest_page($contest_id, $force = false) {
        if (!$force && get_post_status($contest_id) !== 'publish') return;

        $page_id = (int) get_post_meta($contest_id, '_bh_page_id', true);
        $status  = $page_id ? get_post_status($page_id) : false;
        if ($page_id && $status && $status !== 'trash') return;

        $new_id = wp_insert_post([
            'post_title'   => get_the_title($contest_id) ?: 'Contest',
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => '[bh_contest_player contest="' . (int) $contest_id . '"]',
        ], true);
        if (is_wp_error($new_id)) return;

        update_post_meta($contest_id, '_bh_page_id', $new_id);
        update_post_meta($new_id, '_bh_contest_ref', $contest_id);
    }

    // Small backlink box on the auto-created page's own edit screen, so an
    // admin who lands there first can jump back to the contest settings.
    // Only added for pages that actually have the contest-ref meta.
    public static function add_page_backlink_meta_box($post) {
        $cid = (int) get_post_meta($post->ID, '_bh_contest_ref', true);
        if (!$cid || !get_post($cid)) return;

        add_meta_box('bh_page_backlink', 'BillyHume Contest', function () use ($cid) {
            echo '<p>This page hosts the contest:</p>';
            echo '<p><strong>' . esc_html(get_the_title($cid)) . '</strong></p>';
            echo '<p><a href="' . esc_url(get_edit_post_link($cid)) . '" class="button">Edit Contest</a></p>';
        }, 'page', 'side', 'high');
    }

    public static function hide_posts_menu() {
        if (apply_filters('bh_hide_posts_menu', true)) {
            remove_menu_page('edit.php');
        }
    }

    /* ================= Submissions list table ================= */

    public static function submission_columns($cols) {
        $new = [];
        foreach ($cols as $k => $v) {
            $new[$k] = $v;
            if ($k === 'title') $new['bh_contest'] = 'Contest';
        }
        return $new;
    }

    public static function submission_column_content($col, $post_id) {
        if ($col !== 'bh_contest') return;
        $cid = (int) get_post_meta($post_id, '_bh_contest_id', true);
        if (!$cid || !get_post($cid)) { echo '<em>—</em>'; return; }
        $url = get_edit_post_link($cid);
        echo $url ? '<a href="' . esc_url($url) . '">' . esc_html(get_the_title($cid)) . '</a>' : esc_html(get_the_title($cid));
    }

    public static function submission_contest_filter($post_type) {
        if ($post_type !== 'bh_submission') return;
        $contests = BH_Helpers::all_contests();
        if (!$contests) return;
        $current = isset($_GET['bh_contest_filter']) ? (int) $_GET['bh_contest_filter'] : 0;
        echo '<select name="bh_contest_filter"><option value="">All contests</option>';
        foreach ($contests as $c) {
            echo '<option value="' . (int) $c->ID . '" ' . selected($current, $c->ID, false) . '>' . esc_html($c->post_title) . '</option>';
        }
        echo '</select>';
    }

    public static function apply_submission_contest_filter($query) {
        if (!is_admin() || !$query->is_main_query()) return;
        if ($query->get('post_type') !== 'bh_submission') return;
        if (empty($_GET['bh_contest_filter'])) return;

        $query->set('meta_query', [[
            'key'   => '_bh_contest_id',
            'value' => (int) $_GET['bh_contest_filter'],
        ]]);
    }

    /* ================= Results / live dashboard ================= */

    public static function render_results() {
        $contests = BH_Helpers::all_contests();
        $cid = isset($_GET['contest_id']) ? (int) $_GET['contest_id'] : 0;
        if (!$cid || get_post_type($cid) !== 'bh_contest') $cid = BH_Helpers::active_contest();

        echo '<div class="wrap"><h1>Contest Results <span id="bh-live-dot" class="bh-live-dot" title="Auto-refreshing"></span></h1>';

        if (!$contests) {
            echo '<p>No contests yet. Create one under Contests → Add New.</p></div>';
            return;
        }

        // Contest picker — reloads the page with ?contest_id=X so every
        // section below (stats, table, live poll) targets the same contest.
        echo '<form method="get" style="margin-bottom:16px;">'
           . '<input type="hidden" name="post_type" value="bh_contest">'
           . '<input type="hidden" name="page" value="bh-results">'
           . '<label>Viewing: <select name="contest_id" onchange="this.form.submit()">';
        foreach ($contests as $c) {
            echo '<option value="' . (int) $c->ID . '" ' . selected($cid, $c->ID, false) . '>'
               . esc_html($c->post_title) . ' — ' . esc_html(ucfirst(BH_Helpers::contest_status($c->ID))) . '</option>';
        }
        echo '</select></label> <noscript><button class="button">Go</button></noscript></form>';

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT submission_id, COUNT(id) votes FROM " . BH_Helpers::table() . "
             WHERE contest_id = %d GROUP BY submission_id ORDER BY votes DESC",
            $cid
        ));

        $total_votes   = array_sum(wp_list_pluck($rows, 'votes'));
        $unique_voters = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM " . BH_Helpers::table() . " WHERE contest_id = %d", $cid));
        $voting_open   = BH_Helpers::is_voting_open($cid);
        $published     = get_post_meta($cid, '_bh_results_published', true) === '1';

        echo '<p><strong>' . esc_html(get_the_title($cid)) . '</strong> — voting is currently <strong>' . ($voting_open ? 'open' : 'closed') . '</strong>. '
           . 'This page is private to admins; the public results page for this contest is ' . ($published ? '<strong>currently published</strong>' : 'still <strong>hidden</strong>') . ' regardless of what you see here. '
           . 'Shortcode: <code>' . esc_html(BH_Helpers::shortcode_for($cid)) . '</code></p>';

        echo '<div class="bh-stats-bar">'
           . '<div class="bh-stat"><span class="bh-stat-num" id="bh-stat-votes">' . (int) $total_votes . '</span><span class="bh-stat-label">total votes</span></div>'
           . '<div class="bh-stat"><span class="bh-stat-num" id="bh-stat-voters">' . (int) $unique_voters . '</span><span class="bh-stat-label">unique voters</span></div>'
           . '<div class="bh-stat"><span class="bh-stat-num" id="bh-stat-last">—</span><span class="bh-stat-label">last vote</span></div>'
           . '</div>';

        echo '<p><label><input type="checkbox" id="bh-autorefresh" checked> Auto-refresh every 8s</label> '
           . '&nbsp;·&nbsp; <span id="bh-updated-at" style="color:#666;">Not yet refreshed live.</span></p>';

        echo '<p>Click the <strong>Votes</strong> or <strong>Plays</strong> headers to sort. (Sort resets on each live refresh.)</p>';
        echo '<table class="wp-list-table widefat striped" id="bh-results-table">';
        echo '<thead><tr><th>Song &amp; Artist</th><th data-dir="desc">Votes</th><th data-dir="desc">Plays</th><th>Chart</th></tr></thead>';
        echo '<tbody id="bh-results-body">';
        self::results_rows_html($rows);
        echo '</tbody></table></div>';

        $rest  = esc_url_raw(rest_url('bh/v1/admin/live'));
        $nonce = wp_create_nonce('wp_rest');

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
            const REST='" . esc_js($rest) . "', NONCE='" . esc_js($nonce) . "', CID='" . (int) $cid . "';
            const dot=document.getElementById('bh-live-dot');
            const cb=document.getElementById('bh-autorefresh');
            const updatedAt=document.getElementById('bh-updated-at');
            let timer=null;

            function esc(s){const d=document.createElement('div');d.textContent=s==null?'':String(s);return d.innerHTML;}

            function renderRows(tracks){
                const tb=document.getElementById('bh-results-body');
                if(!tracks.length){tb.innerHTML='<tr><td colspan=\"4\">No votes yet.</td></tr>';return;}
                const top=Math.max(1,...tracks.map(t=>t.votes));
                tb.innerHTML=tracks.map(t=>{
                    const pct=((t.votes/top)*100).toFixed(1);
                    return '<tr><td><strong>'+esc(t.title)+'</strong><br><small>'+esc(t.artist)+'</small></td>'
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
                    const res=await fetch(REST+'?contest='+encodeURIComponent(CID),{headers:{'X-WP-Nonce':NONCE}});
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
            document.querySelectorAll('#bh-results-table th').forEach((h,i)=>{
                if(i!==1&&i!==2)return; h.style.cursor='pointer';
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

    private static function results_rows_html($rows) {
        if (empty($rows)) { echo '<tr><td colspan="4">No votes yet.</td></tr>'; return; }
        $max = max(1, (int) $rows[0]->votes);
        foreach ($rows as $r) {
            $p = get_post($r->submission_id);
            if (!$p) continue;
            $votes = (int) $r->votes;
            $plays = (int) get_post_meta($p->ID, '_bh_play_count', true);
            $pct   = ($votes / $max) * 100;

            // Every dynamic value escaped on output (defends against a crafted
            // title/artist becoming stored XSS in the dashboard).
            echo '<tr>'
               . '<td><strong>' . esc_html($p->post_title) . '</strong><br><small>' . esc_html(BH_Helpers::artist_for($p)) . '</small></td>'
               . '<td>' . $votes . '</td><td>' . $plays . '</td>'
               . '<td><div style="background:#e0e0e0;width:100%;height:20px;border-radius:3px;">'
               . '<div style="background:#1DB954;width:' . esc_attr($pct) . '%;height:100%;border-radius:3px;"></div></div></td>'
               . '</tr>';
        }
    }

    /* ================= Meta boxes ================= */

    public static function add_meta_boxes() {
        add_meta_box('bh_approval', 'Submission Details & Approval', function ($post) {
            $note = get_post_meta($post->ID, '_bh_admin_note', true);
            $url  = wp_get_attachment_url(get_post_meta($post->ID, '_bh_audio_id', true));
            $cid  = (int) get_post_meta($post->ID, '_bh_contest_id', true);

            if ($cid && get_post($cid)) {
                echo '<p><strong>Contest:</strong> <a href="' . esc_url(get_edit_post_link($cid)) . '">' . esc_html(get_the_title($cid)) . '</a></p>';
            }
            echo '<h4>Artist Name: ' . esc_html(get_post_meta($post->ID, '_bh_artist_name', true)) . '</h4>';
            if ($note) echo '<p><strong>Note to Admin:</strong><br><em>' . esc_html($note) . '</em></p>';
            echo $url
                ? "<audio controls src='" . esc_url($url) . "' style='width:100%;margin:15px 0;'></audio>"
                : '<p>No audio attached.</p>';
            echo '<hr><p><strong>To Approve:</strong> set Status to <em>Published</em> and click Update.</p>';
        }, 'bh_submission', 'normal', 'high');

        add_meta_box('bh_contest_settings', 'Contest Rules & Results', function ($post) {
            wp_nonce_field('bh_save_contest', 'bh_contest_nonce');
            $start = self::dt_for_input(get_post_meta($post->ID, '_bh_start', true));
            $end   = self::dt_for_input(get_post_meta($post->ID, '_bh_end', true));
            $pub   = get_post_meta($post->ID, '_bh_results_published', true);

            echo "<p>Voting Start: <input type='datetime-local' name='bh_start' value='" . esc_attr($start) . "'></p>";
            echo "<p>Voting End: &nbsp;&nbsp;<input type='datetime-local' name='bh_end' value='" . esc_attr($end) . "'></p>";
            echo '<hr><p><label><input type="checkbox" name="bh_results_published" value="1" ' . checked($pub, '1', false) . '> <strong>Publish Results to Public</strong></label></p>';
            echo '<p><em>Check this only after the contest ends and you have audited the votes.</em></p>';
        }, 'bh_contest', 'side', 'default');

        add_meta_box('bh_contest_shortcode', 'Shortcode & Page', function ($post) {
            if ($post->post_status !== 'publish') {
                echo '<p class="description">Publish this contest to get its shortcode and page.</p>';
                return;
            }
            $sc = BH_Helpers::shortcode_for($post->ID);
            echo '<input type="text" readonly value="' . esc_attr($sc) . '" onclick="this.select();" style="width:100%;font-family:monospace;font-size:12px;padding:6px;">';
            echo '<p class="description">Paste into any page or post to embed this specific contest. Leaving out the <code>contest</code> attribute always falls back to whichever contest was published most recently — fine for one contest at a time, ambiguous once you\'re running more than one.</p>';
            echo '<hr><p><strong>Page:</strong> ' . self::page_links_html($post->ID) . '</p>';
            echo '<p class="description">A simple page with this shortcode was created automatically when you published. If you deleted it, "Create page" makes a new one.</p>';
        }, 'bh_contest', 'side', 'default');
    }

    public static function save_contest_meta($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bh_contest_nonce']) || !wp_verify_nonce($_POST['bh_contest_nonce'], 'bh_save_contest')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['bh_start'])) update_post_meta($post_id, '_bh_start', sanitize_text_field($_POST['bh_start']));
        if (isset($_POST['bh_end']))   update_post_meta($post_id, '_bh_end', sanitize_text_field($_POST['bh_end']));
        update_post_meta($post_id, '_bh_results_published', isset($_POST['bh_results_published']) ? '1' : '0');

        self::maybe_create_contest_page($post_id);
    }

    // <input type="datetime-local"> requires "YYYY-MM-DDTHH:MM" (a literal
    // T, optionally with seconds). Values written by "Start now"/"End now"
    // come from current_time('mysql') ("YYYY-MM-DD HH:MM:SS", a space) —
    // convert back so the field re-populates instead of showing blank.
    private static function dt_for_input($v) {
        if (!$v) return '';
        $v = str_replace(' ', 'T', trim($v));
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}):\d{2}$/', $v, $m)) $v = $m[1];
        return $v;
    }
}
