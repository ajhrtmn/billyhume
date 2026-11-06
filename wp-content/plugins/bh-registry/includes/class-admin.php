<?php
if (!defined('ABSPATH')) exit;

/**
 * Submission review queue, for abuse handling rather than approval (verified links go live on their own).
 * Registered only via the 'admin_menus' entry below (OUS_MenuMerge), never add_submenu_page().
 */
class BHR_Admin {
    public static function init(): void {
        add_filter('ous_registered_plugins', [self::class, 'register']);
        add_action('admin_post_bhr_admin_action', [self::class, 'handle_action']);
        add_filter('bhi_report_target_label', [self::class, 'report_target_label'], 10, 3);
    }

    // Labels "registry_artist #12" entries in the core Reports queue.
    public static function report_target_label(string $label, string $type, int $id): string {
        if ($type !== 'registry_artist') return $label;
        global $wpdb;
        $name = $wpdb->get_var($wpdb->prepare("SELECT display_name FROM " . BHR_Tables::artists() . " WHERE id = %d", $id));
        return $name ? 'Registry artist: ' . $name . ' (#' . $id . ')' : 'Registry artist #' . $id . ' (not found — may already be removed)';
    }

    /**
     * @param array<string, mixed> $plugins
     * @return array<string, mixed>
     */
    public static function register(array $plugins): array {
        $plugins['bh-registry'] = [
            'label'        => 'BH Registry',
            'file'         => 'bh-registry/bh-registry.php',
            'depends_on'   => [],
            'check_class'  => 'BHR_API',
            'description'  => 'The global, decentralized artist-link registry — ActivityPub/RSS feed links, submitted voluntarily and verified by domain ownership.',
            // Enables the one-click Install button (needs the-self-hosted-self/bundled/bh-registry.zip).
            'bundled_zip'  => 'bh-registry.zip',
            'dashboard_link' => 'admin.php?page=bh-registry-review',
            'admin_menus'  => [
                ['slug' => 'bh-registry-review', 'label' => 'Registry Submissions', 'callback' => [self::class, 'render']],
                ['slug' => 'bh-registry-peers', 'label' => 'Registry Peers', 'callback' => ['BHR_Peers', 'render']],
            ],
        ];
        return $plugins;
    }

    /* ---------- rendering ---------- */

    public static function render(): void {
        global $wpdb;
        $artists_t = BHR_Tables::artists();
        $links_t   = BHR_Tables::links();

        $artists = $wpdb->get_results("SELECT * FROM $artists_t ORDER BY created_at DESC LIMIT 200");
        echo '<div class="wrap"><h1>Registry Submissions</h1>';
        echo '<p class="description">A link goes live in public browse/search automatically once verified — this page is for reviewing status and handling abuse, not approving every submission by hand.</p>';

        self::render_identity_box();

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
            if ($artist->status !== 'rejected') echo self::action_link($artist->id, 'reject', 'Reject (hide)', null, 'Reject and hide this artist from public browse/search?') . ' ';
            if ($artist->status === 'rejected') echo self::action_link($artist->id, 'unreject', 'Restore') . ' ';
            echo self::action_link($artist->id, 'delete', 'Delete', null, 'Delete this artist and all its links permanently?');
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    // This site's own OUTBOUND token (served by BHR_WellKnown); every other token here is inbound.
    private static function render_identity_box(): void {
        if (!class_exists('BHR_WellKnown')) return;
        $token = BHR_WellKnown::token();
        $url   = BHR_WellKnown::challenge_url();
        echo '<div class="bhy-card" style="margin-bottom:16px;max-width:640px;">';
        echo '<h2 style="margin-top:0;">This Site\'s Identity</h2>';
        echo '<p class="description">When submitting THIS site\'s own feed to another site\'s registry, that site will fetch <code>' . esc_html($url) . '</code> and expect it to contain exactly this token. Self-served automatically — no filesystem access needed.</p>';
        // Ellipsis marks truncation at narrow widths; select() still copies the full value.
        echo '<p><input type="text" readonly value="' . esc_attr($token) . '" style="width:100%;max-width:420px;font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" onclick="this.select();"></p>';
        echo '<p>' . self::action_link(0, 'regenerate_wellknown_token', 'Regenerate token', null, 'Regenerate this site\'s outbound identity token? Any registry that already verified this site using the old token will need it re-verified.') . '</p>';
        echo '</div>';
    }

    private static function status_badge(string $status): string {
        $variants = ['active' => 'success', 'verified' => 'success', 'pending' => 'warning', 'failed' => 'danger', 'rejected' => 'danger'];
        $variant = $variants[$status] ?? 'neutral';
        return '<span class="bhy-badge bhy-badge-' . esc_attr($variant) . '">' . esc_html($status) . '</span>';
    }

    private static function action_link(int $artist_id, string $action, string $label, ?int $link_id = null, string $confirm = ''): string {
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=bhr_admin_action&do=' . $action . '&artist_id=' . $artist_id . ($link_id ? '&link_id=' . $link_id : '')),
            'bhr_admin_action'
        );
        $onclick = $confirm ? " onclick=\"return confirm('" . esc_js($confirm) . "')\"" : '';
        return '<a href="' . esc_url($url) . '"' . $onclick . '>' . esc_html($label) . '</a>';
    }

    /* ---------- actions ---------- */

    public static function handle_action(): void {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_GET['_wpnonce'] ?? '', 'bhr_admin_action')) {
            wp_die('Not allowed.');
        }

        global $wpdb;
        $artist_id = (int) ($_GET['artist_id'] ?? 0);
        $link_id   = (int) ($_GET['link_id'] ?? 0);
        $do        = sanitize_text_field($_GET['do'] ?? '');

        switch ($do) {
            case 'reject':
                $wpdb->update(BHR_Tables::artists(), ['status' => 'rejected', 'updated_at' => current_time('mysql')], ['id' => $artist_id]);
                break;
            case 'unreject':
                // Restore to 'pending' so the verified-link check decides, not the admin.
                $wpdb->update(BHR_Tables::artists(), ['status' => 'pending', 'updated_at' => current_time('mysql')], ['id' => $artist_id]);
                BHR_Verification::recheck_artist($artist_id);
                break;
            case 'delete':
                $wpdb->delete(BHR_Tables::links(), ['artist_id' => $artist_id]);
                $wpdb->delete(BHR_Tables::artists(), ['id' => $artist_id]);
                break;
            case 'reverify_link':
                if ($link_id) {
                    $link = BHR_Links::find($link_id);
                    if ($link) BHR_Verification::verify_link($link);
                }
                break;
            case 'regenerate_wellknown_token':
                if (class_exists('BHR_WellKnown')) BHR_WellKnown::regenerate_token();
                break;
        }

        wp_safe_redirect(admin_url('admin.php?page=bh-registry-review'));
        exit;
    }
}
