<?php
if (!defined('ABSPATH')) exit;

class BH_Admin {
    public static function init() {
        add_action('admin_menu', [self::class, 'add_menus']);
        add_action('add_meta_boxes', [self::class, 'add_meta_boxes']);
        // The branding/style override box is an opt-in, per-contest
        // feature most contests never touch (the site-wide theme from
        // Settings & Style already applies by default) — starting it
        // collapsed when a contest hasn't turned the override on keeps
        // it out of the way without hiding or removing it.
        add_filter('postbox_classes_bh_contest_bh_contest_style', [self::class, 'maybe_collapse_style_box']);
        add_action('save_post_bh_contest', [self::class, 'save_contest_meta']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_media']);
        add_action('transition_post_status', [self::class, 'maybe_notify_approval'], 10, 3);

        // Contest list table: status pill, copyable shortcode, quick stats.
        add_filter('manage_bh_contest_posts_columns', [self::class, 'contest_columns']);
        add_action('manage_bh_contest_posts_custom_column', [self::class, 'contest_column_content'], 10, 2);
        add_action('admin_post_bh_quick_schedule', [self::class, 'quick_schedule']);
        add_action('admin_post_bh_create_page', [self::class, 'create_page_action']);
        add_action('admin_post_bh_export', [self::class, 'export_csv']);
        add_action('admin_post_bh_send_winners', [self::class, 'send_winner_notifications']);
        add_action('wp_ajax_bh_advance_round', [self::class, 'ajax_advance_round']);

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

        // "Add New" sidebar links under our menu are clutter for both CPTs:
        // Submissions is a dead end in wp-admin (audio upload, artist name,
        // etc. aren't editable from the bare post editor — submissions can
        // only come in properly through the front-end flow), and Contests
        // already has its own "Add New" button at the top of the Contests
        // list page itself, so the sidebar link is a redundant second copy.
        // Creating a contest still works exactly the same either way —
        // this only removes the shortcut, not the capability. Nothing
        // about WordPress's own Posts menu is touched.
        add_action('admin_menu', [self::class, 'remove_add_new_links'], 999);

        // Voters register as ordinary subscriber accounts (that's what
        // gives us name/email/history for free via WP's own user system),
        // but a subscriber can still browse into wp-admin by default and
        // see a near-empty dashboard, which is confusing for a site
        // that's really just a voting page. Keep wp-admin for admins only.
        add_action('admin_init', [self::class, 'restrict_dashboard_access']);
        add_filter('show_admin_bar', [self::class, 'hide_admin_bar_for_voters']);
    }

    public static function restrict_dashboard_access() {
        if (!apply_filters('bh_restrict_admin_access', true)) return;
        if (wp_doing_ajax() || current_user_can('manage_options')) return;
        wp_safe_redirect(home_url('/'));
        exit;
    }

    public static function hide_admin_bar_for_voters($show) {
        if (!apply_filters('bh_restrict_admin_access', true)) return $show;
        return current_user_can('manage_options') ? $show : false;
    }

    public static function enqueue_media($hook) {
        global $post_type;
        if (in_array($hook, ['post.php', 'post-new.php'], true) && $post_type === 'bh_contest') {
            wp_enqueue_media();
        }
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
            // Submissions pill — "unscheduled" here genuinely means
            // "always open" (see BH_Helpers::is_submission_open), so it's
            // shown as green "Open", not the gray/ambiguous label voting
            // uses for the same raw status string.
            $sub = BH_Helpers::submission_status($post_id);
            $sub_map   = ['open' => '#1DB954', 'unscheduled' => '#1DB954', 'upcoming' => '#8a8a8a', 'closed' => '#b3261e'];
            $sub_label = ['open' => 'Open', 'unscheduled' => 'Open', 'upcoming' => 'Upcoming', 'closed' => 'Closed'];
            echo '<div style="margin-bottom:4px;"><span style="display:inline-block;width:62px;font-size:10px;color:#787c82;text-transform:uppercase;">Submit</span> '
               . '<span style="display:inline-block;padding:2px 10px;border-radius:999px;background:' . $sub_map[$sub] . ';color:#fff;font-size:11px;font-weight:600;">' . esc_html($sub_label[$sub]) . '</span></div>';

            $s = BH_Helpers::contest_status($post_id);
            $map   = ['open' => '#1DB954', 'upcoming' => '#8a8a8a', 'closed' => '#b3261e', 'unscheduled' => '#8a8a8a'];
            $label = ['open' => 'Open', 'upcoming' => 'Upcoming', 'closed' => 'Closed', 'unscheduled' => 'Not scheduled'];
            echo '<div><span style="display:inline-block;width:62px;font-size:10px;color:#787c82;text-transform:uppercase;">Vote</span> '
               . '<span style="display:inline-block;padding:2px 10px;border-radius:999px;background:' . $map[$s] . ';color:#fff;font-size:11px;font-weight:600;">' . esc_html($label[$s]) . '</span>';

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
            echo '</div>';
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
    // A raw data export as a safety net — if a vote or a submission is
    // ever disputed, or something needs auditing outside wp-admin
    // entirely, this is the "pull everything" escape hatch rather than
    // having no way to get the underlying data out at all.
    public static function export_csv() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bh_export')) {
            wp_die('Not allowed.');
        }
        $cid  = (int) ($_GET['contest_id'] ?? 0);
        $type = sanitize_key($_GET['type'] ?? '');
        if (!$cid || !in_array($type, ['submissions', 'votes'], true)) wp_die('Invalid export request.');

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
                "SELECT user_id, category, submission_id, created_at FROM $t WHERE contest_id = %d ORDER BY created_at ASC", $cid
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
            if ($which === 'start') {
                BH_Discord::notify_voting_open($cid);
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

        add_meta_box('bh_page_backlink', 'BH Contest', function () use ($cid) {
            echo '<p>This page hosts the contest:</p>';
            echo '<p><strong>' . esc_html(get_the_title($cid)) . '</strong></p>';
            echo '<p><a href="' . esc_url(get_edit_post_link($cid)) . '" class="button">Edit Contest</a></p>';
        }, 'page', 'side', 'high');
    }

    public static function remove_add_new_links() {
        remove_submenu_page(BH_PostTypes::MENU_PARENT, 'post-new.php?post_type=bh_submission');
        remove_submenu_page(BH_PostTypes::MENU_PARENT, 'post-new.php?post_type=bh_contest');
    }

    /* ================= Submissions list table ================= */

    // Deliberately separate from the "Publish Results" checkbox — that
    // just makes results visible on the site; this is the loud part
    // (Discord announcement + winner emails), and an admin might want a
    // gap between the two, e.g. to publish, sanity-check the numbers
    // look right, and only then announce — or to hold the announcement
    // for a specific moment regardless of when results actually went
    // live. Tracks a sent timestamp so accidentally clicking again shows
    // a confirmation rather than silently re-notifying everyone.
    public static function send_winner_notifications() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bh_send_winners')) {
            wp_die('Not allowed.');
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

    // Fires the public "new entry approved" Discord notification at the
    // moment an admin actually approves a submission (changes its status
    // to Published), not when it was first submitted — this webhook is
    // public-facing, so announcing something before anyone's reviewed it
    // would mean the whole channel sees every submission, including any
    // that get rejected. Guarded to the actual off-to-on transition so
    // re-saving an already-published submission (editing its title,
    // fixing a typo, etc.) doesn't re-announce it every time.
    public static function maybe_notify_approval($new_status, $old_status, $post) {
        if ($post->post_type !== 'bh_submission') return;
        if ($new_status !== 'publish' || $old_status === 'publish') return;

        $cid = (int) get_post_meta($post->ID, '_bh_contest_id', true);
        if (!$cid) return;

        $artist = get_post_meta($post->ID, '_bh_artist_name', true);
        $aid    = (int) get_post_meta($post->ID, '_bh_audio_id', true);
        BH_Discord::notify_submission($cid, $post->post_title, $artist, $aid ? wp_get_attachment_url($aid) : '');
    }

    public static function submission_columns($cols) {
        $new = [];
        foreach ($cols as $k => $v) {
            $new[$k] = $v;
            if ($k === 'title') {
                $new['bh_contest'] = 'Contest';
                $new['bh_notes']   = 'Notes';
            }
        }
        return $new;
    }

    public static function submission_column_content($col, $post_id) {
        if ($col === 'bh_contest') {
            $cid = (int) get_post_meta($post_id, '_bh_contest_id', true);
            if (!$cid || !get_post($cid)) { echo '<em>—</em>'; return; }
            $url = get_edit_post_link($cid);
            echo $url ? '<a href="' . esc_url($url) . '">' . esc_html(get_the_title($cid)) . '</a>' : esc_html(get_the_title($cid));
        }
        if ($col === 'bh_notes') {
            $note = get_post_meta($post_id, '_bh_admin_note', true);
            if ($note === '') { echo '<em>—</em>'; return; }
            // Full text on hover via title attribute — no need to open the
            // submission just to read a note during a live contest stream.
            echo '<span title="' . esc_attr($note) . '">' . esc_html(wp_html_excerpt($note, 60, '…')) . '</span>';
        }
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

    /* ================= Meta boxes ================= */

    // Adds WordPress's own "closed" postbox class when this contest's
    // style override isn't enabled yet — the box is still fully present
    // and expandable, just not competing for attention by default the
    // way it would if it always opened expanded.
    public static function maybe_collapse_style_box($classes) {
        global $post;
        if ($post && !get_post_meta($post->ID, '_bhy_style_override', true)) $classes[] = 'closed';
        return $classes;
    }

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
            $sub_start = self::dt_for_input(get_post_meta($post->ID, '_bh_sub_start', true));
            $sub_end   = self::dt_for_input(get_post_meta($post->ID, '_bh_sub_end', true));
            $start = self::dt_for_input(get_post_meta($post->ID, '_bh_start', true));
            $end   = self::dt_for_input(get_post_meta($post->ID, '_bh_end', true));
            $pub   = get_post_meta($post->ID, '_bh_results_published', true);
            $base  = get_post_meta($post->ID, '_bh_vote_base', true);
            $bonus = get_post_meta($post->ID, '_bh_vote_bonus', true);

            // A brand-new contest (nothing saved yet) naturally has both
            // fields blank, so this defaults to checked — "submissions
            // open the moment I publish" is the sensible out-of-the-box
            // behavior, not something that has to be configured first.
            $sub_always_open = ($sub_start === '' && $sub_end === '');

            $phase = BH_Helpers::contest_phase_summary($post->ID);
            echo '<div style="padding:8px 10px;border-radius:4px;background:' . esc_attr($phase['color']) . '1a;border:1px solid ' . esc_attr($phase['color']) . ';margin-bottom:14px;">'
               . '<strong style="color:' . esc_attr($phase['color']) . ';font-size:12px;">' . esc_html($phase['label']) . '</strong></div>';

            echo '<p style="display:flex;align-items:center;justify-content:space-between;"><strong>Submissions</strong> <span id="bh_sub_dot"></span></p>';
            echo '<p><label><input type="checkbox" id="bh_sub_always_open" name="bh_sub_always_open" value="1" ' . checked($sub_always_open, true, false) . '> Open submissions the moment this contest is published</label></p>';
            echo '<div id="bh_sub_dates" style="' . ($sub_always_open ? 'display:none;' : '') . '">';
            echo "<p>Opens: <input type='datetime-local' id='bh_sub_start' name='bh_sub_start' value='" . esc_attr($sub_start) . "'></p>";
            echo "<p>Closes: &nbsp;<input type='datetime-local' id='bh_sub_end' name='bh_sub_end' value='" . esc_attr($sub_end) . "'></p>";
            echo '</div>';

            $contact_cfg = BH_Helpers::contact_config($post->ID);
            $field_labels = [
                'real_name' => 'Real name', 'discord_name' => 'Discord', 'twitch_name' => 'Twitch',
                'youtube_name' => 'YouTube', 'typical_platform' => 'Typical platform (dropdown)', 'phone' => 'Phone',
            ];
            echo '<hr><p><strong>Contact info collected at submission</strong></p>';
            echo '<p class="description">Choose what this contest asks submitters for. Leave everything as-is for the default (all fields shown, real name + at least one handle required, phone optional).</p>';
            foreach ($field_labels as $key => $label) {
                $shown = in_array($key, $contact_cfg['show'], true);
                echo '<label style="display:block;margin:2px 0;"><input type="checkbox" class="bh-contact-show" data-field="' . esc_attr($key) . '" name="bh_contact_show[]" value="' . esc_attr($key) . '" ' . checked($shown, true, false) . '> ' . esc_html($label) . '</label>';
            }
            echo '<p style="margin-top:10px;"><strong>Required</strong></p>';
            echo '<label style="display:block;margin:2px 0;"><input type="checkbox" name="bh_require_real_name" value="1" ' . checked(!empty($contact_cfg['require_real_name']), true, false) . ' class="bh-contact-require" data-requires="real_name"> Real name</label>';
            echo '<label style="display:block;margin:2px 0;"><input type="checkbox" name="bh_require_handle" value="1" ' . checked(!empty($contact_cfg['require_handle']), true, false) . '> At least one platform handle (Discord/Twitch/YouTube)</label>';
            echo '<label style="display:block;margin:2px 0;"><input type="checkbox" name="bh_require_phone" value="1" ' . checked(!empty($contact_cfg['require_phone']), true, false) . ' class="bh-contact-require" data-requires="phone"> Phone</label>';
            echo '<p class="description">A field can only be required if it\'s also shown above — unchecking "shown" for a required field will un-require it automatically.</p>';

            echo '<hr><p style="display:flex;align-items:center;justify-content:space-between;"><strong>Voting</strong> <span id="bh_vote_dot"></span></p>';
            echo "<p>Opens: <input type='datetime-local' id='bh_start' name='bh_start' value='" . esc_attr($start) . "'> <button type=\"button\" class=\"button button-small\" id=\"bh_vote_start_now\">When submissions close</button></p>";
            echo "<p>Closes: &nbsp;<input type='datetime-local' id='bh_end' name='bh_end' value='" . esc_attr($end) . "'></p>";

            echo '<hr><p>Votes per category: '
               . '<input type="number" name="bh_vote_base" min="0" max="20" style="width:56px;" value="' . esc_attr($base !== '' ? $base : BH_VOTE_BASE) . '"> base'
               . ' + <input type="number" name="bh_vote_bonus" min="0" max="20" style="width:56px;" value="' . esc_attr($bonus !== '' ? $bonus : BH_VOTE_BONUS) . '"> bonus for submitting</p>';
            echo '<p class="description">Applies to every category on this contest independently (voting in 3 categories with 1+1 votes = up to 6 total). Leave blank for the site default. Bonus only counts once a submission is approved.</p>';
            echo '<hr><p><label><input type="checkbox" name="bh_results_published" value="1" ' . checked($pub, '1', false) . '> <strong>Publish Results to Public</strong></label></p>';
            echo '<p><em>Check this only after the contest ends and you have audited the votes.</em></p>';

            if ($pub === '1') {
                $sent_at = get_post_meta($post->ID, '_bh_winner_notifications_sent_at', true);
                $send_url = wp_nonce_url(admin_url('admin-post.php?action=bh_send_winners&contest_id=' . $post->ID), 'bh_send_winners');
                echo '<p>';
                if ($sent_at) {
                    echo '<span class="description">Winner notifications sent ' . esc_html(mysql2date('M j, g:ia', $sent_at)) . '.</span> ';
                    echo '<a href="' . esc_url($send_url) . '" onclick="return confirm(\'Resend winner notifications? This posts to Discord and emails every winner again.\');">Resend</a>';
                } else {
                    echo '<a href="' . esc_url($send_url) . '" class="button button-primary" onclick="return confirm(\'Send winner notifications now? This posts to Discord and emails every winner immediately — make sure the results above are actually final.\');">Send Winner Notifications</a>';
                }
                echo '</p>';
            }

            $webhook = get_post_meta($post->ID, '_bh_discord_webhook', true);
            echo '<hr><p><strong>Discord notifications</strong> <span class="description">(optional)</span></p>';
            echo '<p><input type="url" name="bh_discord_webhook" value="' . esc_attr($webhook) . '" placeholder="https://discord.com/api/webhooks/..." style="width:100%;"></p>';
            echo '<p class="description">Automatically posts when a track is submitted or voting starts. The results announcement is sent separately, on demand — see "Send Winner Notifications" above. Get a webhook URL from a Discord channel\'s Settings &rarr; Integrations &rarr; Webhooks. Leave blank for no notifications.</p>';

            if ($webhook) {
                $reveal_url = ($rp = (int) get_option('bh_reveal_page_id')) ? get_permalink($rp) : '';
                echo '<p><strong>Announce to Discord</strong> <span class="description">— sends right away, independent of Save/Update</span></p>';
                echo '<textarea id="bh_discord_message" rows="2" style="width:100%;" placeholder="e.g. Going live for the results reveal in 5 minutes!"></textarea>';
                echo '<p style="margin:6px 0;">';
                if ($reveal_url) echo '<button type="button" class="button button-small" id="bh_discord_preset_reveal">Fill: Going live for reveal</button> ';
                echo '</p>';
                echo '<p><button type="button" class="button" id="bh_discord_send">Send to Discord</button> <span id="bh_discord_status" style="margin-left:8px;font-size:12px;"></span></p>';
            }
            ?>
            <script>
            (function () {
                var revealUrl = <?php echo wp_json_encode($reveal_url ?? ''); ?>;
                var msgField = document.getElementById('bh_discord_message');
                var presetReveal = document.getElementById('bh_discord_preset_reveal');
                if (presetReveal) presetReveal.addEventListener('click', function () {
                    msgField.value = '📺 Going live for the results reveal — come watch: ' + revealUrl;
                });

                var sendBtn = document.getElementById('bh_discord_send');
                if (sendBtn) sendBtn.addEventListener('click', function () {
                    var msg = msgField.value.trim();
                    var status = document.getElementById('bh_discord_status');
                    if (!msg) { status.textContent = 'Type a message first.'; status.style.color = '#b3261e'; return; }
                    sendBtn.disabled = true;
                    status.textContent = 'Sending…'; status.style.color = '#787c82';
                    fetch(<?php echo wp_json_encode(esc_url_raw(rest_url('bh/v1/discord/announce'))); ?>, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?> },
                        body: JSON.stringify({ contest: <?php echo (int) $post->ID; ?>, message: msg }),
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            sendBtn.disabled = false;
                            if (data.sent) { status.textContent = 'Sent!'; status.style.color = '#1DB954'; msgField.value = ''; }
                            else { status.textContent = 'Could not send — check the webhook URL.'; status.style.color = '#b3261e'; }
                        })
                        .catch(function () {
                            sendBtn.disabled = false;
                            status.textContent = 'Network error — try again.'; status.style.color = '#b3261e';
                        });
                });
            })();
            </script>
            <script>
            (function () {
                function dot(status) {
                    var live = status === 'open';
                    var label = status === 'open' ? 'Live now' : (status === 'upcoming' ? 'Not started' : (status === 'closed' ? 'Closed' : 'Not scheduled'));
                    return '<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:' + (live ? '#1DB954' : '#b3261e') + ';">'
                        + '<span style="width:7px;height:7px;border-radius:50%;background:' + (live ? '#1DB954' : '#b3261e') + ';"></span>' + label + '</span>';
                }
                function computeStatus(startEl, endEl, alwaysOpenIfBlank) {
                    var sv = startEl ? startEl.value : '', ev = endEl ? endEl.value : '';
                    if (!sv || !ev) return alwaysOpenIfBlank ? 'open' : 'unscheduled';
                    var now = new Date(), start = new Date(sv), end = new Date(ev);
                    if (now < start) return 'upcoming';
                    if (now > end) return 'closed';
                    return 'open';
                }

                var alwaysCb = document.getElementById('bh_sub_always_open');
                var subDates = document.getElementById('bh_sub_dates');
                var subStart = document.getElementById('bh_sub_start');
                var subEnd = document.getElementById('bh_sub_end');
                var subDot = document.getElementById('bh_sub_dot');
                var voteStart = document.getElementById('bh_start');
                var voteEnd = document.getElementById('bh_end');
                var voteDot = document.getElementById('bh_vote_dot');

                // A "required" checkbox only makes sense if its field is
                // also shown — unchecking "shown" un-checks "required"
                // live, matching the same rule contact_config() enforces
                // server-side, so the correction is visible immediately
                // rather than only discovered after saving.
                var handleFields = ['discord_name', 'twitch_name', 'youtube_name'];
                document.querySelectorAll('.bh-contact-show').forEach(function (showCb) {
                    showCb.addEventListener('change', function () {
                        if (showCb.checked) return;
                        var requireCb = document.querySelector('.bh-contact-require[data-requires="' + showCb.dataset.field + '"]');
                        if (requireCb) requireCb.checked = false;

                        // The composite "at least one handle" rule needs
                        // ANY of the three to remain shown, not this one
                        // specifically — only un-check it once all three
                        // are gone.
                        if (handleFields.indexOf(showCb.dataset.field) !== -1) {
                            var anyHandleShown = handleFields.some(function (f) {
                                var cb = document.querySelector('.bh-contact-show[data-field="' + f + '"]');
                                return cb && cb.checked;
                            });
                            if (!anyHandleShown) {
                                var requireHandleCb = document.querySelector('input[name="bh_require_handle"]');
                                if (requireHandleCb) requireHandleCb.checked = false;
                            }
                        }
                    });
                });

                function refreshSubDot() {
                    subDot.innerHTML = dot(alwaysCb.checked ? 'open' : computeStatus(subStart, subEnd, false));
                }
                function refreshVoteDot() {
                    voteDot.innerHTML = dot(computeStatus(voteStart, voteEnd, false));
                }

                if (alwaysCb) alwaysCb.addEventListener('change', function () {
                    subDates.style.display = alwaysCb.checked ? 'none' : '';
                    refreshSubDot();
                });
                [subStart, subEnd].forEach(function (el) { if (el) el.addEventListener('input', refreshSubDot); });
                [voteStart, voteEnd].forEach(function (el) { if (el) el.addEventListener('input', refreshVoteDot); });

                var startNowBtn = document.getElementById('bh_vote_start_now');
                if (startNowBtn) startNowBtn.addEventListener('click', function () {
                    // "When submissions close" — copies the submission end
                    // date/time straight into the voting start field, since
                    // that's the overwhelmingly common intent (no gap
                    // between the two phases) and otherwise means manually
                    // re-typing a date you already entered two fields above.
                    if (subEnd && subEnd.value) { voteStart.value = subEnd.value; refreshVoteDot(); }
                    else if (subStart) { alert('Set a submissions close date first, or enter the voting start time directly.'); }
                });

                refreshSubDot();
                refreshVoteDot();
            })();
            </script>
            <?php
        }, 'bh_contest', 'side', 'default');

        add_meta_box('bh_contest_categories', 'Voting Categories', function ($post) {
            wp_nonce_field('bh_save_contest', 'bh_contest_nonce');
            $cats = BH_Helpers::categories($post->ID);
            $text = implode("\n", array_map(fn($c) => $c['name'], $cats));
            echo '<p class="description">One category per line, e.g. "Best Vocals". Leave empty for a single, ordinary vote — this is optional.</p>';
            echo '<textarea name="bh_categories" rows="5" style="width:100%;font-family:inherit;">' . esc_textarea($text) . '</textarea>';
            echo '<p class="description">Voters get their normal 1 (or 2, if they submitted) vote in <em>each</em> category independently. All submissions are eligible in every category — there\'s no per-track assignment.</p>';
        }, 'bh_contest', 'normal', 'default');

        add_meta_box('bh_contest_judging', 'Judging Format', function ($post) {
            wp_nonce_field('bh_save_contest', 'bh_contest_nonce');
            $format = BH_Helpers::contest_format($post->ID);
            $rubric = BH_Judging::rubric($post->ID);
            $rubric_text = implode("\n", array_map(fn($c) => $c['name'] . ':' . $c['max'], $rubric));
            $judge_ids = BH_Judging::judge_ids($post->ID);
            $judge_names = [];
            foreach ($judge_ids as $jid) {
                $u = get_userdata($jid);
                if ($u) $judge_names[] = $u->user_login;
            }

            echo '<p><label><strong>Format</strong><br><select name="bh_contest_format">';
            echo '<option value="public"' . selected($format, 'public', false) . '>Public voting (default, unchanged)</option>';
            echo '<option value="judges"' . selected($format, 'judges', false) . '>Judges only — a rubric score replaces public voting</option>';
            echo '<option value="hybrid"' . selected($format, 'hybrid', false) . '>Hybrid — both run, shown as two separate leaderboards (Judges\' Pick / People\'s Choice)</option>';
            echo '</select></label></p>';

            echo '<p class="description">Rubric criteria, one per line — "Originality" (defaults to a max of 10) or "Originality:20" for a custom max. Only used when Format is Judges or Hybrid.</p>';
            echo '<textarea name="bh_rubric" rows="4" style="width:100%;font-family:inherit;">' . esc_textarea($rubric_text) . '</textarea>';

            echo '<p class="description">Judges, one WordPress username per line. Each must already have an account on this site — judges score from the front-end <code>[bh_judge_panel]</code> shortcode, not wp-admin.</p>';
            echo '<textarea name="bh_judges" rows="3" style="width:100%;font-family:inherit;">' . esc_textarea(implode("\n", $judge_names)) . '</textarea>';

            if ($post->post_status === 'publish' && $judge_ids) {
                echo '<p class="description" style="margin-top:8px;">Judge panel shortcode: <code>[bh_judge_panel contest="' . esc_html($post->post_name) . '"]</code></p>';
            }
        }, 'bh_contest', 'normal', 'default');

        add_meta_box('bh_contest_rounds', 'Rounds (elimination format)', function ($post) {
            wp_nonce_field('bh_save_contest', 'bh_contest_nonce');
            $rounds = BH_Rounds::rounds($post->ID);
            $count = max(1, count($rounds));
            $active = BH_Rounds::active_round_index($post->ID);

            echo '<p class="description">Leave at 1 round for a normal single-round contest (unchanged default behavior). 2+ rounds turns this into an elimination format — round 1 is the normal submission+voting window; round 2+ only re-votes/re-scores whoever survived the previous cut (leave a round\'s own submission dates blank unless you specifically want it to accept new entries too).</p>';
            echo '<p><label><strong>Number of rounds</strong> <select id="bh_round_count" name="bh_round_count">';
            for ($i = 1; $i <= 4; $i++) echo '<option value="' . $i . '"' . selected($count, $i, false) . '>' . $i . '</option>';
            echo '</select></label></p>';

            if ($post->post_status === 'publish' && count($rounds) > 1) {
                echo '<p><span class="bhy-badge bhy-badge-dot">Active round: ' . ((int) $active + 1) . ' of ' . count($rounds) . '</span></p>';
                if (isset($rounds[$active + 1])) {
                    echo '<p><button type="button" class="button button-primary" id="bh_advance_round" data-contest="' . (int) $post->ID . '" data-nonce="' . esc_attr(wp_create_nonce('bh_advance_round_' . $post->ID)) . '">Close round ' . ((int) $active + 1) . ' &amp; advance to round ' . ((int) $active + 2) . '</button> <span id="bh_advance_round_result" style="margin-left:8px;"></span></p>';
                    echo '<p class="description">Tallies the active round\'s votes/judge scores now, keeps the configured cut count, and opens the next round for the survivors. Cannot be undone from here.</p>';
                } else {
                    echo '<p class="description">This is the final round — nothing left to advance to.</p>';
                }
            }

            for ($i = 0; $i < 4; $i++) {
                $r = $rounds[$i] ?? [];
                $display = $i < $count ? '' : 'display:none;';
                echo '<div class="bh-round-block" data-round-index="' . $i . '" style="' . $display . 'border:1px solid #dcdcde;border-radius:4px;padding:10px;margin-bottom:10px;">';
                echo '<p><strong>Round ' . ($i + 1) . '</strong></p>';
                echo '<p><label>Name<br><input type="text" name="bh_round_name[]" value="' . esc_attr($r['name'] ?? ('Round ' . ($i + 1))) . '" style="width:100%;"></label></p>';
                echo '<p><label>Submission opens (blank = no new entries this round)<br><input type="datetime-local" name="bh_round_sub_start[]" value="' . esc_attr(self::dt_for_input($r['sub_start'] ?? '')) . '"></label> ';
                echo '<label>closes<br><input type="datetime-local" name="bh_round_sub_end[]" value="' . esc_attr(self::dt_for_input($r['sub_end'] ?? '')) . '"></label></p>';
                echo '<p><label>Voting opens<br><input type="datetime-local" name="bh_round_vote_start[]" value="' . esc_attr(self::dt_for_input($r['vote_start'] ?? '')) . '"></label> ';
                echo '<label>closes<br><input type="datetime-local" name="bh_round_vote_end[]" value="' . esc_attr(self::dt_for_input($r['vote_end'] ?? '')) . '"></label></p>';
                echo '<p><label>Cut to (how many advance out of this round)<br><input type="number" min="1" name="bh_round_cut[]" value="' . esc_attr((string) ($r['cut_count'] ?? 8)) . '" style="width:80px;"></label></p>';
                echo '</div>';
            }
            ?>
            <script>
            (function () {
                var sel = document.getElementById('bh_round_count');
                var blocks = document.querySelectorAll('.bh-round-block');
                if (sel) sel.addEventListener('change', function () {
                    var n = parseInt(sel.value, 10);
                    blocks.forEach(function (b) { b.style.display = parseInt(b.dataset.roundIndex, 10) < n ? '' : 'none'; });
                });
                var btn = document.getElementById('bh_advance_round');
                if (btn) btn.addEventListener('click', function () {
                    if (!confirm('Close the active round and advance survivors now? This cannot be undone from here.')) return;
                    btn.disabled = true;
                    var body = new URLSearchParams({ action: 'bh_advance_round', contest_id: btn.dataset.contest, nonce: btn.dataset.nonce });
                    fetch(ajaxurl, { method: 'POST', body: body })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            var out = document.getElementById('bh_advance_round_result');
                            if (res.success) { out.textContent = 'Advanced — reload to see the new round.'; location.reload(); }
                            else { out.textContent = (res.data && res.data.message) || 'Could not advance.'; btn.disabled = false; }
                        });
                });
            })();
            </script>
            <?php
        }, 'bh_contest', 'normal', 'default');

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

        add_meta_box('bh_contest_style', 'Contest Branding & Style', function ($post) {
            wp_nonce_field('bh_save_contest', 'bh_contest_nonce');
            $on = get_post_meta($post->ID, '_bhy_style_override', true);
            $data = json_decode((string) get_post_meta($post->ID, '_bhy_style_json', true), true);
            if (!is_array($data)) $data = [];
            $g = fn($k, $d = '') => $data[$k] ?? $d;
            $defaults = BHY_Style::get(); // site-wide values, shown as placeholders

            echo '<style>' . BHY_UI::swatch_css() . '
                .bh-cat-swatch-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 8px; }
            </style>';

            echo '<p class="description">Off by default — this contest just uses the site-wide look from Settings &amp; Style. Turn this on to give '
               . 'this one contest its own logo/brand text and accent colors (e.g. a sponsor or seasonal skin) without changing anything else site-wide.</p>';
            echo '<p><label><input type="checkbox" id="bh_style_override" name="bh_style_override" value="1" ' . checked($on, '1', false) . '> <strong>Override site styling for this contest</strong></label></p>';

            echo '<div id="bh_style_fields" style="' . ($on ? '' : 'display:none;') . ' margin-top:12px;">';

            echo '<div style="display:flex;align-items:center;gap:14px;margin-bottom:16px;">';
            $logo_id  = (int) $g('brand_logo_id', 0);
            $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';
            echo '<div id="bh_contest_logo_preview" style="width:64px;height:64px;border:1px solid #dcdcde;border-radius:6px;background:#f6f7f7;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:0 0 auto;">';
            echo '<img id="bh_contest_logo_img" src="' . esc_url($logo_url) . '" style="max-width:100%;max-height:100%;object-fit:contain;' . ($logo_url ? '' : 'display:none;') . '">';
            echo '<span id="bh_contest_logo_empty" style="font-size:11px;color:#888;' . ($logo_url ? 'display:none;' : '') . '">No logo</span>';
            echo '</div>';
            echo '<div>';
            echo '<input type="hidden" id="bh_contest_logo_id" name="bh_style_logo_id" value="' . esc_attr($logo_id) . '">';
            echo '<button type="button" class="button" id="bh_contest_logo_upload">Upload logo…</button> ';
            echo '<button type="button" class="button" id="bh_contest_logo_remove" style="' . ($logo_url ? '' : 'display:none;') . '">Remove</button>';
            echo '</div></div>';

            // Quick pick — same THEME_GROUPS as Settings & Style, filtered
            // to just the fields a contest is allowed to override. Fills
            // every field below in one click; each stays editable
            // afterward for fine-tuning.
            echo '<p><label for="bh_style_theme_pick"><strong>Quick pick from a theme</strong></label><br>';
            echo '<select id="bh_style_theme_pick" style="max-width:280px;">';
            echo '<option value="">Choose a theme…</option>';
            foreach (BHY_Style::THEME_GROUPS as $group_label => $themes) {
                echo '<optgroup label="' . esc_attr($group_label) . '">';
                foreach ($themes as $name => $colors) {
                    $subset = array_intersect_key($colors, array_flip(BHY_Style::OVERRIDABLE_FIELDS));
                    echo '<option value="' . esc_attr($name) . '" data-set=\'' . esc_attr(wp_json_encode($subset)) . '\'>' . esc_html($name) . '</option>';
                }
                echo '</optgroup>';
            }
            echo '</select></p>';

            echo '<p style="margin-top:14px;"><strong>Brand text</strong> <span class="description">— leave either blank to use the site-wide text</span></p>';
            echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">';
            echo '<label style="display:flex;flex-direction:column;gap:4px;font-size:11px;font-weight:600;">First part<input type="text" name="bh_style_brand1" value="' . esc_attr($g('brand_part1')) . '" placeholder="' . esc_attr($defaults['brand_part1']) . '" style="width:120px;"></label>';
            echo '<label style="display:flex;flex-direction:column;gap:4px;font-size:11px;font-weight:600;">Accent part<input type="text" name="bh_style_brand2" value="' . esc_attr($g('brand_part2')) . '" placeholder="' . esc_attr($defaults['brand_part2']) . '" style="width:120px;"></label>';
            echo '</div>';

            echo '<p><strong>Base &amp; surfaces</strong></p>';
            echo '<div class="bh-cat-swatch-grid" style="margin-bottom:14px;">';
            BHY_UI::swatch_field('bh_style_bg', 'bh_style_bg', 'Background', $g('color_bg'), $defaults['color_bg']);
            BHY_UI::swatch_field('bh_style_surface', 'bh_style_surface', 'Surface', $g('color_surface'), $defaults['color_surface']);
            BHY_UI::swatch_field('bh_style_surface_2', 'bh_style_surface_2', 'Surface (raised)', $g('color_surface_2'), $defaults['color_surface_2']);
            BHY_UI::swatch_field('bh_style_border', 'bh_style_border', 'Border', $g('color_border'), $defaults['color_border']);
            BHY_UI::swatch_field('bh_style_text', 'bh_style_text', 'Text', $g('color_text'), $defaults['color_text']);
            BHY_UI::swatch_field('bh_style_text_dim', 'bh_style_text_dim', 'Text (dim)', $g('color_text_dim'), $defaults['color_text_dim']);
            echo '</div>';

            echo '<p><strong>Accent</strong></p>';
            echo '<div class="bh-cat-swatch-grid" style="margin-bottom:14px;">';
            BHY_UI::swatch_field('bh_style_accent', 'bh_style_accent', 'Accent', $g('color_accent'), $defaults['color_accent']);
            BHY_UI::swatch_field('bh_style_accent_soft', 'bh_style_accent_soft', 'Accent (soft)', $g('color_accent_soft'), $defaults['color_accent_soft']);
            BHY_UI::swatch_field('bh_style_overlay', 'bh_style_overlay', 'Modal backdrop', $g('color_overlay'), $defaults['color_overlay']);
            echo '</div>';

            echo '<p><strong>Category colors</strong> <span class="description">— blank falls through to site-wide</span></p>';
            echo '<div class="bh-cat-swatch-grid">';
            for ($i = 1; $i <= 8; $i++) {
                BHY_UI::swatch_field('bh_style_cat_' . $i, 'bh_style_cat_' . $i, 'Category ' . $i, $g('cat_color_' . $i), $defaults['cat_color_' . $i]);
            }
            echo '</div>';
            echo '</div>';
            ?>
            <script>
            <?php echo BHY_UI::swatch_js(); ?>
            (function () {
                var cb = document.getElementById('bh_style_override');
                var fields = document.getElementById('bh_style_fields');
                if (cb) cb.addEventListener('change', function () { fields.style.display = cb.checked ? '' : 'none'; });

                var uploadBtn = document.getElementById('bh_contest_logo_upload');
                var removeBtn = document.getElementById('bh_contest_logo_remove');
                var idField = document.getElementById('bh_contest_logo_id');
                var img = document.getElementById('bh_contest_logo_img');
                var empty = document.getElementById('bh_contest_logo_empty');
                var frame = null;
                if (uploadBtn && window.wp && window.wp.media) {
                    uploadBtn.addEventListener('click', function () {
                        if (frame) { frame.open(); return; }
                        frame = wp.media({ title: 'Choose a logo', button: { text: 'Use this image' }, library: { type: 'image' }, multiple: false });
                        frame.on('select', function () {
                            var att = frame.state().get('selection').first().toJSON();
                            idField.value = att.id;
                            img.src = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
                            img.style.display = ''; empty.style.display = 'none'; removeBtn.style.display = '';
                        });
                        frame.open();
                    });
                }
                if (removeBtn) removeBtn.addEventListener('click', function () {
                    idField.value = ''; img.src = ''; img.style.display = 'none'; empty.style.display = ''; removeBtn.style.display = 'none';
                });

                var themePick = document.getElementById('bh_style_theme_pick');
                if (themePick) {
                    themePick.addEventListener('change', function () {
                        var opt = themePick.options[themePick.selectedIndex];
                        if (!opt || !opt.dataset.set) return;
                        var data = JSON.parse(opt.dataset.set);
                        Object.keys(data).forEach(function (key) {
                            var fieldId = 'bh_style_' + (key.indexOf('cat_color_') === 0 ? 'cat_' + key.replace('cat_color_', '') : key.replace('color_', ''));
                            var input = document.getElementById(fieldId);
                            if (!input) return;
                            input.value = data[key];
                            input.dispatchEvent(new Event('input', { bubbles: true }));
                        });
                    });
                }
            })();
            </script>
            <?php
        }, 'bh_contest', 'normal', 'default');
    }

    // ROADMAP-ux-polish-and-feature-parity-2026-07.md 2b — the admin
    // action that actually closes out a round: capability-gated
    // (edit_post on the contest) and per-contest nonce'd, since this is
    // a real, one-way state change (eliminated entries don't come back
    // from this screen).
    public static function ajax_advance_round() {
        $cid = (int) ($_POST['contest_id'] ?? 0);
        if (!$cid || !current_user_can('edit_post', $cid)) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bh_advance_round_' . $cid)) {
            wp_send_json_error(['message' => 'Security check failed — reload and try again.'], 403);
        }
        $result = BH_Rounds::advance_round($cid);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }
        wp_send_json_success($result);
    }

    public static function save_contest_meta($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bh_contest_nonce']) || !wp_verify_nonce($_POST['bh_contest_nonce'], 'bh_save_contest')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (!empty($_POST['bh_sub_always_open'])) {
            // Toggle checked — always-open, regardless of whatever might
            // still be sitting in the (hidden, but still submitted)
            // date fields from a previous explicit schedule.
            update_post_meta($post_id, '_bh_sub_start', '');
            update_post_meta($post_id, '_bh_sub_end', '');
        } else {
            if (isset($_POST['bh_sub_start'])) update_post_meta($post_id, '_bh_sub_start', sanitize_text_field($_POST['bh_sub_start']));
            if (isset($_POST['bh_sub_end']))   update_post_meta($post_id, '_bh_sub_end', sanitize_text_field($_POST['bh_sub_end']));
        }
        if (isset($_POST['bh_start'])) update_post_meta($post_id, '_bh_start', sanitize_text_field($_POST['bh_start']));
        if (isset($_POST['bh_end']))   update_post_meta($post_id, '_bh_end', sanitize_text_field($_POST['bh_end']));
        update_post_meta($post_id, '_bh_results_published', isset($_POST['bh_results_published']) ? '1' : '0');
        if (isset($_POST['bh_vote_base']))  update_post_meta($post_id, '_bh_vote_base', max(0, (int) $_POST['bh_vote_base']));
        if (isset($_POST['bh_vote_bonus'])) update_post_meta($post_id, '_bh_vote_bonus', max(0, (int) $_POST['bh_vote_bonus']));

        // Sanitized against the known field list rather than trusted
        // as-is — a stray/unexpected value in bh_contact_show[] should
        // never end up persisted just because it showed up in $_POST.
        $shown = array_values(array_intersect(
            (array) ($_POST['bh_contact_show'] ?? []),
            BH_Helpers::CONTACT_FIELDS
        ));
        $contact_config = [
            'show' => $shown,
            'require_real_name' => !empty($_POST['bh_require_real_name']) && in_array('real_name', $shown, true),
            'require_handle'    => !empty($_POST['bh_require_handle']),
            'require_phone'     => !empty($_POST['bh_require_phone']) && in_array('phone', $shown, true),
        ];
        update_post_meta($post_id, '_bh_contact_config', wp_json_encode($contact_config));

        if (isset($_POST['bh_discord_webhook'])) {
            $webhook = esc_url_raw(trim($_POST['bh_discord_webhook']));
            update_post_meta($post_id, '_bh_discord_webhook', $webhook);
        }

        if (isset($_POST['bh_categories'])) {
            $cats = BH_Helpers::parse_categories_input(wp_unslash($_POST['bh_categories']));
            update_post_meta($post_id, '_bh_categories', $cats ? wp_json_encode($cats) : '');
        }

        if (isset($_POST['bh_contest_format'])) {
            $format = in_array($_POST['bh_contest_format'], ['judges', 'hybrid'], true) ? $_POST['bh_contest_format'] : 'public';
            update_post_meta($post_id, '_bh_contest_format', $format);
        }
        if (isset($_POST['bh_rubric'])) {
            $rubric = BH_Judging::parse_rubric_input(wp_unslash($_POST['bh_rubric']));
            update_post_meta($post_id, '_bh_rubric', $rubric ? wp_json_encode($rubric) : '');
        }
        if (isset($_POST['bh_round_name'])) {
            $names = (array) $_POST['bh_round_name'];
            $count = max(1, min(4, (int) ($_POST['bh_round_count'] ?? 1)));
            $sub_starts = (array) ($_POST['bh_round_sub_start'] ?? []);
            $sub_ends   = (array) ($_POST['bh_round_sub_end'] ?? []);
            $vote_starts = (array) ($_POST['bh_round_vote_start'] ?? []);
            $vote_ends   = (array) ($_POST['bh_round_vote_end'] ?? []);
            $cuts = (array) ($_POST['bh_round_cut'] ?? []);

            $rounds = [];
            for ($i = 0; $i < $count; $i++) {
                $name = sanitize_text_field($names[$i] ?? ('Round ' . ($i + 1)));
                $rounds[] = [
                    'name' => $name !== '' ? $name : ('Round ' . ($i + 1)),
                    'sub_start' => sanitize_text_field($sub_starts[$i] ?? ''),
                    'sub_end' => sanitize_text_field($sub_ends[$i] ?? ''),
                    'vote_start' => sanitize_text_field($vote_starts[$i] ?? ''),
                    'vote_end' => sanitize_text_field($vote_ends[$i] ?? ''),
                    'cut_count' => max(1, (int) ($cuts[$i] ?? 8)),
                ];
            }
            // Exactly 1 round stored as an EMPTY meta value, not a
            // single-item array — is_multi_round()'s count() > 1 check
            // (class-rounds.php) means this is functionally identical
            // either way, but storing '' for the common single-round
            // case keeps get_post_meta() cheap-and-empty for every
            // contest that never touches this feature, matching
            // _bh_categories'/_bh_rubric's own "blank means off" convention.
            update_post_meta($post_id, '_bh_rounds', $count > 1 ? wp_json_encode($rounds) : '');
        }

        if (isset($_POST['bh_judges'])) {
            // Usernames -> IDs, resolved (not trusted) on the way in — a
            // typo'd or since-deleted username just silently drops from
            // the list rather than storing a dangling/invalid entry.
            $ids = [];
            foreach (preg_split('/[\r\n]+/', wp_unslash($_POST['bh_judges'])) as $line) {
                $login = trim($line);
                if ($login === '') continue;
                $u = get_user_by('login', $login);
                if ($u) $ids[] = $u->ID;
            }
            update_post_meta($post_id, '_bh_judges', $ids ? wp_json_encode(array_values(array_unique($ids))) : '');
        }

        update_post_meta($post_id, '_bhy_style_override', isset($_POST['bh_style_override']) ? '1' : '');

        // Only fields the admin actually filled in get stored — a blank
        // field means "use the site-wide value", not "override with an
        // empty string". See BHY_Style::entity_overrides().
        $style = [];
        if (!empty($_POST['bh_style_logo_id']))     $style['brand_logo_id']    = (int) $_POST['bh_style_logo_id'];
        if (!empty($_POST['bh_style_brand1']))      $style['brand_part1']      = sanitize_text_field($_POST['bh_style_brand1']);
        if (!empty($_POST['bh_style_brand2']))      $style['brand_part2']      = sanitize_text_field($_POST['bh_style_brand2']);
        if (!empty($_POST['bh_style_bg']))          $style['color_bg']          = sanitize_text_field($_POST['bh_style_bg']);
        if (!empty($_POST['bh_style_surface']))     $style['color_surface']     = sanitize_text_field($_POST['bh_style_surface']);
        if (!empty($_POST['bh_style_surface_2']))   $style['color_surface_2']   = sanitize_text_field($_POST['bh_style_surface_2']);
        if (!empty($_POST['bh_style_border']))      $style['color_border']      = sanitize_text_field($_POST['bh_style_border']);
        if (!empty($_POST['bh_style_text']))        $style['color_text']        = sanitize_text_field($_POST['bh_style_text']);
        if (!empty($_POST['bh_style_text_dim']))    $style['color_text_dim']    = sanitize_text_field($_POST['bh_style_text_dim']);
        if (!empty($_POST['bh_style_accent']))      $style['color_accent']      = sanitize_text_field($_POST['bh_style_accent']);
        if (!empty($_POST['bh_style_accent_soft'])) $style['color_accent_soft'] = sanitize_text_field($_POST['bh_style_accent_soft']);
        if (!empty($_POST['bh_style_overlay']))     $style['color_overlay']     = sanitize_text_field($_POST['bh_style_overlay']);
        for ($i = 1; $i <= 8; $i++) {
            if (!empty($_POST['bh_style_cat_' . $i])) $style['cat_color_' . $i] = sanitize_text_field($_POST['bh_style_cat_' . $i]);
        }
        update_post_meta($post_id, '_bhy_style_json', $style ? wp_json_encode($style) : '');

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
