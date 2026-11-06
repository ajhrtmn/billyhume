<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin navigation and access: menus, voter dashboard restriction, the
 * auto-created contest page, site-menu resync, and the search provider.
 */
class BH_AdminMenus {
    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menus']);
        add_filter('views_edit-bh_contest', [self::class, 'add_catalog_view_link']);
        // Trash/untrash/delete skip save_contest_meta(), so they resync the menu here.
        add_action('wp_trash_post', [self::class, 'maybe_resync_menu_for_post']);
        add_action('untrash_post', [self::class, 'maybe_resync_menu_for_post']);
        add_action('before_delete_post', [self::class, 'maybe_resync_menu_for_post']);
        add_action('admin_post_bh_restore_contest_revision', [self::class, 'handle_restore_revision']);
        // Public search covers published contests only, never bh_submission (personal data).
        add_filter('ous_search_providers', [self::class, 'register_search_provider']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_media']);
        add_action('admin_post_bh_quick_schedule', [self::class, 'quick_schedule']);
        add_action('admin_post_bh_create_page', [self::class, 'create_page_action']);

        add_action('add_meta_boxes_page', [self::class, 'add_page_backlink_meta_box']);

        // Submissions only arrive via the front end, and Contests has its own
        // Add New button, so the sidebar "Add New" links are removed.
        add_action('admin_menu', [self::class, 'remove_add_new_links'], 999);

        // Voters are plain subscribers; keep wp-admin and the admin bar for admins only.
        add_action('admin_init', [self::class, 'restrict_dashboard_access']);
        add_filter('show_admin_bar', [self::class, 'hide_admin_bar_for_voters']);
    }

    public static function restrict_dashboard_access(): void {
        if (!apply_filters('bh_restrict_admin_access', true)) return;
        if (wp_doing_ajax() || current_user_can('manage_options')) return;
        // WHY: admin_init also fires for admin-post.php, whose handlers (e.g. email
        // verification) must reach non-admins. Each handler gates its own auth.
        if (isset($_SERVER['SCRIPT_NAME']) && basename($_SERVER['SCRIPT_NAME']) === 'admin-post.php') return;
        wp_safe_redirect(home_url('/'));
        exit;
    }

    public static function hide_admin_bar_for_voters(bool $show): bool {
        if (!apply_filters('bh_restrict_admin_access', true)) return $show;
        return current_user_can('manage_options') ? $show : false;
    }

    public static function enqueue_media(string $hook): void {
        global $post_type;
        if (in_array($hook, ['post.php', 'post-new.php'], true) && $post_type === 'bh_contest') {
            wp_enqueue_media();
        }
    }

    public static function add_menus(): void {
        add_submenu_page(
            BH_PostTypes::MENU_PARENT,
            'Contest Results', 'Results', 'manage_options', 'bh-results',
            [BH_AdminReports::class, 'render_results']
        );
    }

    // Sets a contest's start or end to "right now", instantly flipping its
    // status — for "Start now"/"End now" links in the contest list.
    public static function quick_schedule(): void {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_GET['_wpnonce'] ?? '', 'bh_quick_schedule')) {
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

    // View/Edit links for a contest's page, or a "Create page" link if it has none.
    public static function page_links_html(int $contest_id): string {
        $page_id = (int) get_post_meta($contest_id, '_bh_page_id', true);
        $status  = $page_id ? get_post_status($page_id) : false;

        if ($page_id && $status && $status !== 'trash') {
            return '<a href="' . esc_url(get_permalink($page_id)) . '">View</a> · <a href="' . esc_url(get_edit_post_link($page_id)) . '">Edit</a>';
        }

        // WHY: a link, not a form; this renders inside the post-edit <form>.
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=bh_create_page&contest_id=' . (int) $contest_id),
            'bh_create_page'
        );
        return '<a href="' . esc_url($url) . '">Create page</a>';
    }

    public static function create_page_action(): void {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_GET['_wpnonce'] ?? '', 'bh_create_page')) {
            wp_die('Not allowed.', '', ['back_link' => true]);
        }
        $cid = (int) ($_GET['contest_id'] ?? 0);
        if ($cid && get_post_type($cid) === 'bh_contest') self::maybe_create_contest_page($cid, true);
        wp_safe_redirect(wp_get_referer() ?: admin_url(BH_PostTypes::MENU_PARENT));
        exit;
    }

    // Creates and cross-links a page holding this contest's shortcode on first publish.
    // Uses the numeric ID so slug changes don't break it; never duplicates a live page.
    public static function maybe_create_contest_page(int $contest_id, bool $force = false): void {
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

    // Backlink box to the contest, on its auto-created page's edit screen.
    public static function add_page_backlink_meta_box(\WP_Post $post): void {
        $cid = (int) get_post_meta($post->ID, '_bh_contest_ref', true);
        if (!$cid || !get_post($cid)) return;

        add_meta_box('bh_page_backlink', 'BH Contest', function () use ($cid) {
            echo '<p>This page hosts the contest:</p>';
            echo '<p><strong>' . esc_html(get_the_title($cid)) . '</strong></p>';
            echo '<p><a href="' . esc_url(get_edit_post_link($cid)) . '" class="button">Edit Contest</a></p>';
        }, 'page', 'side', 'high');
    }

    public static function remove_add_new_links(): void {
        remove_submenu_page(BH_PostTypes::MENU_PARENT, 'post-new.php?post_type=bh_submission');
        remove_submenu_page(BH_PostTypes::MENU_PARENT, 'post-new.php?post_type=bh_contest');
    }

    /**
     * @param array<string, mixed> $providers
     * @return array<string, mixed>
     */
    public static function register_search_provider($providers): array {
        $providers['contests'] = [self::class, 'search_contests'];
        return $providers;
    }

    /** @return array<int, array<string, string>> */
    public static function search_contests(string $query, int $limit): array {
        $posts = get_posts([
            'post_type' => 'bh_contest', 'post_status' => 'publish',
            's' => $query, 'posts_per_page' => $limit,
        ]);
        $out = [];
        foreach ($posts as $p) {
            // A contest has no URL of its own; skip it unless its page is published.
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
     * Rebuilds the "Contests" menu group from contests with the menu toggle on,
     * newest first. Called after any save, trash, untrash, or delete.
     */
    public static function resync_menu(): void {
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

        OUS_MenuSync::sync_group('contests', 'Contests', $items, self::archive_page_url());
    }

    // URL for the "Contests" group label: the first published page using [bh_archive], else '#'.
    private static function archive_page_url(): string {
        $pages = get_posts([
            'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 1,
            's' => '[bh_archive', 'orderby' => 'ID', 'order' => 'ASC',
        ]);
        $url = $pages ? get_permalink($pages[0]->ID) : false;
        return $url ?: '#';
    }

    /** @param mixed $post_id */
    public static function maybe_resync_menu_for_post($post_id): void {
        if (get_post_type($post_id) === 'bh_contest') self::resync_menu();
    }

    // Restore button target: writes the snapshot's meta keys straight back, since
    // the snapshot is already meta-shaped (save_contest_meta() expects $_POST).
    public static function handle_restore_revision(): void {
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

        // Snapshot the restore too, so it can itself be undone.
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

    // On permanent contest deletion (before_delete_post, hooked in bh-contest.php):
    // trash its submissions (keeping the recovery window) and delete its vote rows.
    /** @param mixed $post_id */
    public static function cleanup_deleted_contest($post_id): void {
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

    /**
     * "View live archive" link in the list table's status row, omitted when no
     * archive page exists. A views link avoids a fragile standalone admin page.
     *
     * @param array<string, string> $views
     * @return array<string, string>
     */
    public static function add_catalog_view_link(array $views): array {
        if (!method_exists('OUS_Pages', 'url')) return $views;
        $url = OUS_Pages::url('bh_archive', 'bh_archive_page_id');
        if (!$url) return $views;
        $views['bh-live-catalog'] = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">'
            . esc_html__('View live archive', 'bh-contest') . ' <span aria-hidden="true">&#8599;</span></a>';
        return $views;
    }
}
