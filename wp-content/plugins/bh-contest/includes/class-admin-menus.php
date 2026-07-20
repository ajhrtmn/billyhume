<?php
if (!defined('ABSPATH')) exit;

/**
 * Split out of class-admin.php (DRY/SOLID audit Phase 3b, same playbook
 * as bh-monetization-woo's class-products.php split) — the admin
 * navigation/access-control surface: menu registration, dashboard access
 * restriction for voters, the auto-created contest page lifecycle, the
 * site-menu resync, and the search-provider hook. No metabox rendering,
 * no moderation actions, no CSV/results reporting — those live in
 * BH_AdminMetaboxes, BH_AdminModeration, and BH_AdminReports respectively.
 */
class BH_AdminMenus {
    public static function init() {
        add_action('admin_menu', [self::class, 'add_menus']);
        // A contest leaving 'publish' (trash/delete) or coming back
        // (untrash) also has to drop or restore its own menu entry —
        // BH_AdminMetaboxes::save_contest_meta() alone only fires on an
        // actual edit-screen save, not on these list-table/quick actions.
        add_action('wp_trash_post', [self::class, 'maybe_resync_menu_for_post']);
        add_action('untrash_post', [self::class, 'maybe_resync_menu_for_post']);
        add_action('before_delete_post', [self::class, 'maybe_resync_menu_for_post']);
        add_action('admin_post_bh_restore_contest_revision', [self::class, 'handle_restore_revision']);
        // OUS_Search consumer, ROADMAP-search-and-revisions.md Section 1
        // sequencing. Public-safe: a published contest's title/existence
        // is already public information (the contest page itself is a
        // real, publicly-viewable page) — this only searches published
        // contests, never bh_submission (real people's contact info/
        // audio files, correctly kept out of any public-facing lookup).
        add_filter('ous_search_providers', [self::class, 'register_search_provider']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_media']);
        add_action('admin_post_bh_quick_schedule', [self::class, 'quick_schedule']);
        add_action('admin_post_bh_create_page', [self::class, 'create_page_action']);

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
        // 'admin_init' fires for admin-post.php too, not just an actual
        // wp-admin dashboard screen load — this blanket redirect was
        // silently killing every admin-post.php action meant for a
        // regular (non-admin) or logged-out user, before the request
        // ever reached its real handler. Confirmed live: BHI_Auth's email-
        // verification link (admin-post.php?action=bhi_verify_email) was
        // bounced to the homepage on every click for any non-admin
        // account, with the verify_email_action() callback never even
        // entering — an artist could click their verification email
        // forever and never actually get verified. Only the dashboard
        // SCREEN needs to be off-limits; admin-post.php's own action
        // dispatch already gates each handler on whatever auth it needs.
        if (isset($_SERVER['SCRIPT_NAME']) && basename($_SERVER['SCRIPT_NAME']) === 'admin-post.php') return;
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
            [BH_AdminReports::class, 'render_results']
        );
    }

    // Sets a contest's start or end to "right now", instantly flipping its
    // status — for "Start now"/"End now" links in the contest list.
    public static function quick_schedule() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bh_quick_schedule')) {
            wp_die('Not allowed.', '', ['back_link' => true]);
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
    // predates this feature). Public (not private, unlike its original
    // class-admin.php home) — BH_AdminListTables' contest_column_content()
    // and BH_AdminMetaboxes' shortcode metabox both call this.
    public static function page_links_html($contest_id) {
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
            wp_die('Not allowed.', '', ['back_link' => true]);
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
    // unless $force is passed (the "Create page" fallback button). Public
    // (not private, unlike its original class-admin.php home) —
    // BH_AdminMetaboxes::save_contest_meta() calls this from a different
    // class.
    public static function maybe_create_contest_page($contest_id, $force = false) {
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

    public static function register_search_provider($providers) {
        $providers['contests'] = [self::class, 'search_contests'];
        return $providers;
    }

    public static function search_contests($query, $limit) {
        $posts = get_posts([
            'post_type' => 'bh_contest', 'post_status' => 'publish',
            's' => $query, 'posts_per_page' => $limit,
        ]);
        $out = [];
        foreach ($posts as $p) {
            // A contest has no canonical URL of its own — it only ever
            // lives at whatever real page embeds its shortcode
            // (ROADMAP-discoverability.md's own finding). Skip a result
            // with nowhere real to send someone rather than link to a
            // dead/nonexistent page.
            $page_id = (int) get_post_meta($p->ID, '_bh_page_id', true);
            if (!$page_id || get_post_status($page_id) !== 'publish') continue;
            $out[] = [
                'type' => 'Contest',
                'title' => $p->post_title,
                'excerpt' => '',
                'url' => get_permalink($page_id),
                'icon' => 'dashicons-microphone',
            ];
        }
        return $out;
    }

    /**
     * Rebuilds the "Contests" submenu group (OUS_MenuSync) from
     * whatever contests currently have the menu toggle on — called
     * after any save, trash, untrash, or delete so the live menu never
     * drifts from what's actually checked. Ordered by start date so an
     * upcoming/current contest surfaces before older ones.
     */
    public static function resync_menu() {
        if (!class_exists('OUS_MenuSync')) return;

        $posts = get_posts([
            'post_type'   => 'bh_contest',
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_key'    => '_bh_show_in_menu',
            'meta_value'  => '1',
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        $items = [];
        foreach ($posts as $p) {
            $page_id = (int) get_post_meta($p->ID, '_bh_page_id', true);
            if (!$page_id || get_post_status($page_id) !== 'publish') continue;
            $label = get_post_meta($p->ID, '_bh_menu_label', true) ?: $p->post_title;
            $items[] = ['label' => $label, 'url' => get_permalink($page_id)];
        }

        OUS_MenuSync::sync_group('contests', 'Contests', $items);
    }

    public static function maybe_resync_menu_for_post($post_id) {
        if (get_post_type($post_id) === 'bh_contest') self::resync_menu();
    }

    // OUS_Revisions::render_history_panel()'s Restore button posts here.
    // Writes every stored _bh_*/_bhy_style_json meta key straight back —
    // simple direct restore rather than routing through
    // BH_AdminMetaboxes::save_contest_meta() (that method expects real
    // $_POST field names from the actual settings form, not a stored
    // meta-key-shaped snapshot; re-simulating a fake $_POST would be more
    // fragile than just writing the meta back directly, since the
    // snapshot already IS the target shape).
    public static function handle_restore_revision() {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        $post_id = (int) ($_GET['object_id'] ?? 0);
        $version = (int) ($_GET['version'] ?? 0);
        if (!isset($_GET['ous_revisions_nonce']) || !wp_verify_nonce($_GET['ous_revisions_nonce'], 'bh_restore_contest_' . $post_id)) {
            wp_die('Invalid request.');
        }
        if (!$post_id || get_post_type($post_id) !== 'bh_contest') wp_die('Not a contest.');

        $snapshot = class_exists('OUS_Revisions') ? OUS_Revisions::get_version('bh_contest', $post_id, $version) : null;
        if (!$snapshot) wp_die('That version no longer exists.');

        foreach ((array) $snapshot['data'] as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        // The restore itself is also a real save — same "undo an
        // accidental restore the same way" reasoning as BHM_Tiers'
        // own restore handler.
        if (class_exists('OUS_Revisions')) {
            $all_meta = get_post_meta($post_id);
            $flat = [];
            foreach ($all_meta as $key => $values) {
                if (strpos($key, '_bh_') === 0 || $key === '_bhy_style_json') $flat[$key] = $values[0] ?? '';
            }
            OUS_Revisions::snapshot('bh_contest', $post_id, $flat, 'Restored from version #' . $version);
        }
        if (class_exists('OUS_Toast')) {
            OUS_Toast::queue('Restored version #' . $version . '.', 'success');
        }

        wp_safe_redirect(get_edit_post_link($post_id, ''));
        exit;
    }

    // Hooked onto before_delete_post — permanent deletion only (a
    // trashed contest is still a real, restorable post, same
    // "nothing to clean up until it's actually gone" reasoning
    // bh-courses' cleanup_deleted_course() already uses for lessons).
    // Real gap this closes: this plugin had ZERO cleanup anywhere for a
    // permanently-deleted contest — every one of its submissions and
    // every vote row referencing it became a silent, permanent orphan
    // with no admin warning and no way to discover the mess later (the
    // Submissions list filters by _bh_contest_id meta pointing at a
    // post that no longer exists). Given contests hold real contestant
    // and voting data, this was a genuine silent-data-loss risk.
    // Submissions are TRASHED, not hard-deleted, here — a permanently-
    // deleted contest almost always means "this contest was a mistake/
    // duplicate," and trashing (rather than permanently deleting) its
    // submissions preserves a 30-day recovery window for exactly that
    // "wait, I didn't mean to delete the WHOLE thing" case, matching
    // how WordPress's own trash already works for everything else here.
    // Hooked directly from bh-contest.php's own bootstrap (not this
    // class's init()) — unchanged from before this split.
    public static function cleanup_deleted_contest($post_id) {
        if (get_post_type($post_id) !== 'bh_contest') return;

        $submissions = get_posts([
            'post_type' => 'bh_submission', 'numberposts' => -1, 'post_status' => 'any',
            'meta_key' => '_bh_contest_id', 'meta_value' => $post_id,
        ]);
        foreach ($submissions as $submission) {
            wp_trash_post($submission->ID);
        }

        global $wpdb;
        $wpdb->delete(BH_Helpers::table(), ['contest_id' => $post_id], ['%d']);
    }
}
