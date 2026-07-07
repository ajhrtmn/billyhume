<?php
if (!defined('ABSPATH')) exit;

/**
 * The submission review queue — a genuinely custom admin page (no CPT
 * involved at all; artists/links live in this plugin's own tables), so
 * per the ecosystem's own established pattern it's surfaced ONLY via
 * the 'admin_menus' entry in ous_registered_plugins below and relocated
 * by the core's OUS_MenuMerge — this class never calls add_submenu_page
 * itself, matching bh-crm's People page exactly (see that plugin's own
 * admin class for the reference example).
 *
 * Review here is for ABUSE HANDLING, not a required approval gate — a
 * link goes live in public browse/search the moment it's verified
 * (see BHR_Verification::maybe_activate_artist()), consistent with
 * "submission is voluntary and self-serve." What this page adds:
 * visibility into what's pending/failed, a manual re-verify trigger,
 * and the ability to reject (hide) an artist an admin has judged to be
 * spam/abuse even if its links happen to verify.
 */
class BHR_Admin {
    public static function init() {
        add_filter('ous_registered_plugins', [self::class, 'register']);
        add_action('admin_post_bhr_admin_action', [self::class, 'handle_action']);
        add_filter('bhi_report_target_label', [self::class, 'report_target_label'], 10, 3);
    }

    // Turns a bare "registry_artist #12" in own-ur-shit's shared Reports
    // queue into something an admin can actually act on without leaving
    // that page to go look the artist up first.
    public static function report_target_label($label, $type, $id) {
        if ($type !== 'registry_artist') return $label;
        global $wpdb;
        $name = $wpdb->get_var($wpdb->prepare("SELECT display_name FROM {$wpdb->prefix}bhr_artists WHERE id = %d", $id));
        return $name ? 'Registry artist: ' . $name . ' (#' . $id . ')' : 'Registry artist #' . $id . ' (not found — may already be removed)';
    }

    public static function register($plugins) {
        $plugins['bh-registry'] = [
            'label'        => 'BH Registry',
            'file'         => 'bh-registry/bh-registry.php',
            'depends_on'   => [],
            'check_class'  => 'BHR_API',
            'description'  => 'The global, decentralized artist-link registry — ActivityPub/RSS feed links, submitted voluntarily and verified by domain ownership.',
            // Was missing entirely — this plugin only ever had the
            // "Ecosystem" header's zero-config auto-discovery going for
            // it, which get_plugins() can only ever find AFTER a human
            // has already manually placed the plugin's files in
            // wp-content/plugins. With bundled_zip set (and the actual
            // zip present at own-ur-shit/bundled/bh-registry.zip), this
            // gets the same one-click "Install" button bh-crm/bh-contest/
            // bh-streaming/bh-courses already have.
            'bundled_zip'  => 'bh-registry.zip',
            'dashboard_link' => 'admin.php?page=bh-registry-review',
            'admin_menus'  => [
                ['slug' => 'bh-registry-review', 'label' => 'Registry Submissions', 'callback' => [self::class, 'render']],
            ],
        ];
        return $plugins;
    }

    /* ---------- rendering ---------- */

    public static function render() {
        global $wpdb;
        $artists_t = $wpdb->prefix . 'bhr_artists';
        $links_t   = $wpdb->prefix . 'bhr_links';

        $artists = $wpdb->get_results("SELECT * FROM $artists_t ORDER BY created_at DESC LIMIT 200");
        echo '<div class="wrap"><h1>Registry Submissions</h1>';
        echo '<p class="description">A link goes live in public browse/search automatically once verified — this page is for reviewing status and handling abuse, not approving every submission by hand.</p>';

        // Search + sortable columns — see BHY_UI::print_design_system_js().
        echo '<input type="text" class="bhy-table-search" data-target="#bhr-submissions-table" placeholder="Filter by artist or status&hellip;">';

        // --tall: the whole page IS this submissions queue.
        echo '<div class="bhy-table-wrap bhy-table-wrap--tall">';
        echo '<table id="bhr-submissions-table" class="wp-list-table widefat striped bhy-sortable"><thead><tr>';
        echo '<th data-sort>Artist</th><th data-sort>Status</th><th>Links</th><th>Actions</th>';
        echo '</tr></thead><tbody>';

        foreach ($artists as $artist) {
            $links = $wpdb->get_results($wpdb->prepare("SELECT * FROM $links_t WHERE artist_id = %d ORDER BY created_at DESC", $artist->id));
            echo '<tr><td><strong>' . esc_html($artist->display_name) . '</strong>';
            if ($artist->contact_email) echo '<br><span class="description">' . esc_html($artist->contact_email) . '</span>';
            echo '</td>';
            echo '<td>' . self::status_badge($artist->status) . '</td>';
            echo '<td>';
            foreach ($links as $link) {
                echo esc_html($link->protocol) . ': <a href="' . esc_url($link->url) . '" target="_blank" rel="noopener">' . esc_html($link->url) . '</a> '
                   . self::status_badge($link->verification_status);
                echo ' ' . self::action_link($artist->id, 'reverify_link', 'Re-check now', $link->id);
                echo '<br>';
            }
            echo '</td>';
            echo '<td>';
            if ($artist->status !== 'rejected') echo self::action_link($artist->id, 'reject', 'Reject (hide)') . ' ';
            if ($artist->status === 'rejected') echo self::action_link($artist->id, 'unreject', 'Restore') . ' ';
            echo self::action_link($artist->id, 'delete', 'Delete', null, 'Delete this artist and all its links permanently?');
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function status_badge($status) {
        $colors = ['active' => '#1DB954', 'verified' => '#1DB954', 'pending' => '#B99584', 'failed' => '#b3261e', 'rejected' => '#b3261e'];
        $color = $colors[$status] ?? '#666';
        return '<span style="color:' . esc_attr($color) . ';font-weight:600;">' . esc_html($status) . '</span>';
    }

    private static function action_link($artist_id, $action, $label, $link_id = null, $confirm = '') {
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=bhr_admin_action&do=' . $action . '&artist_id=' . $artist_id . ($link_id ? '&link_id=' . $link_id : '')),
            'bhr_admin_action'
        );
        $onclick = $confirm ? " onclick=\"return confirm('" . esc_js($confirm) . "')\"" : '';
        return '<a href="' . esc_url($url) . '"' . $onclick . '>' . esc_html($label) . '</a>';
    }

    /* ---------- actions ---------- */

    public static function handle_action() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bhr_admin_action')) {
            wp_die('Not allowed.');
        }

        global $wpdb;
        $artist_id = (int) ($_GET['artist_id'] ?? 0);
        $link_id   = (int) ($_GET['link_id'] ?? 0);
        $do        = sanitize_text_field($_GET['do'] ?? '');

        switch ($do) {
            case 'reject':
                $wpdb->update($wpdb->prefix . 'bhr_artists', ['status' => 'rejected', 'updated_at' => current_time('mysql')], ['id' => $artist_id]);
                break;
            case 'unreject':
                // Back to 'pending' rather than straight to 'active' —
                // let the normal verified-link check decide, same as any
                // other artist; restoring shouldn't bypass that.
                $wpdb->update($wpdb->prefix . 'bhr_artists', ['status' => 'pending', 'updated_at' => current_time('mysql')], ['id' => $artist_id]);
                BHR_Verification::recheck_artist($artist_id);
                break;
            case 'delete':
                $wpdb->delete($wpdb->prefix . 'bhr_links', ['artist_id' => $artist_id]);
                $wpdb->delete($wpdb->prefix . 'bhr_artists', ['id' => $artist_id]);
                break;
            case 'reverify_link':
                if ($link_id) {
                    $link = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bhr_links WHERE id = %d", $link_id));
                    if ($link) BHR_Verification::verify_link($link);
                }
                break;
        }

        wp_safe_redirect(admin_url('admin.php?page=bh-registry-review'));
        exit;
    }
}
