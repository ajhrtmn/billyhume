<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_PortalLayout: admin-editable portal panel order (numeric priority) and
 * visibility, with OUS_Revisions history. Stored in BHI_Portal::LAYOUT_OPTION as
 * ['order' => [id => int], 'hidden' => [id => true]] and applied on top of the
 * bhi_portal_panels filter, which still decides which panels exist.
 */
class OUS_PortalLayout {
    const OPTION = 'bhi_portal_layout';

    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_bhi_save_portal_layout', [self::class, 'handle_save']);
        add_action('admin_post_bhi_restore_portal_layout_revision', [self::class, 'handle_restore_revision']);
    }

    /** @return array<string, mixed> */
    public static function get(): array {
        $saved = get_option(self::OPTION, []);
        return [
            'order'  => is_array($saved['order'] ?? null) ? $saved['order'] : [],
            'hidden' => is_array($saved['hidden'] ?? null) ? $saved['hidden'] : [],
        ];
    }

    /**
     * Applied by BHI_Portal::get_panels() to the filter-contributed list.
     */
    /**
     * @param array<int, array<string, mixed>> $panels
     * @return array<int, array<string, mixed>>
     */
    public static function apply($panels): array {
        $layout = self::get();
        if (!$layout['order'] && !$layout['hidden']) return $panels;

        $panels = array_filter($panels, function ($p) use ($layout) {
            return empty($layout['hidden'][$p['id']]);
        });

        foreach ($panels as &$p) {
            if (isset($layout['order'][$p['id']])) {
                $p['priority'] = (int) $layout['order'][$p['id']];
            }
        }
        unset($p);

        return array_values($panels);
    }

    public static function add_menu(): void {
        add_submenu_page('ous', 'Portal Layout', 'Portal Layout', 'manage_options', 'ous-portal-layout', [self::class, 'render']);
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) return;

        // Raw filter output, so hidden panels still appear here to re-enable.
        $panels = apply_filters('bhi_portal_panels', []);
        $panels = array_filter($panels, function ($p) {
            return !empty($p['id']) && !empty($p['label']);
        });
        usort($panels, function ($a, $b) {
            return ($a['priority'] ?? 10) <=> ($b['priority'] ?? 10);
        });
        $layout = self::get();

        echo '<div class="wrap"><h1>Portal Layout</h1>';
        echo '<p class="description">Controls the order and visibility of panels in the <a href="' . esc_url(home_url('/account/')) . '">account portal</a> nav. Lower number = appears first. Panels a plugin no longer registers just disappear here on their own.</p>';

        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Portal layout saved.</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="bhi_save_portal_layout">';
        wp_nonce_field('bhi_save_portal_layout', 'bhi_portal_layout_nonce');

        echo '<p class="description">Tip: leave gaps between numbers (10, 20, 30 rather than 1, 2, 3) so you can slot a panel in between two others later without renumbering everything.</p>';
        echo '<table class="widefat striped" style="max-width:600px;"><thead><tr><th>Panel</th><th style="width:100px;">Priority</th><th style="width:80px;">Hide</th></tr></thead><tbody>';
        foreach ($panels as $p) {
            $id = $p['id'];
            $priority = $layout['order'][$id] ?? ($p['priority'] ?? 10);
            $hidden = !empty($layout['hidden'][$id]);
            echo '<tr>';
            echo '<td>' . esc_html($p['label']) . ' <code>' . esc_html($id) . '</code></td>';
            echo '<td><input type="number" name="priority[' . esc_attr($id) . ']" value="' . esc_attr($priority) . '" placeholder="e.g. 10, 20, 30" style="width:70px;"></td>';
            echo '<td><input type="checkbox" name="hidden[' . esc_attr($id) . ']" value="1"' . checked($hidden, true, false) . '></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p class="submit"><button type="submit" class="button button-primary">Save Layout</button></p>';
        echo '</form>';

        echo '<h2>Version History</h2>';
        OUS_Revisions::render_history_panel('portal_layout', 1, 'bhi_restore_portal_layout_revision', 'bhi_restore_portal_layout_revision');

        echo '</div>';
    }

    public static function handle_save(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('bhi_save_portal_layout', 'bhi_portal_layout_nonce')) {
            wp_die('Invalid request.');
        }

        $priorities = array_map('intval', (array) ($_POST['priority'] ?? []));
        $hidden_raw = (array) ($_POST['hidden'] ?? []);
        $hidden = [];
        foreach ($hidden_raw as $id => $val) {
            $hidden[sanitize_key($id)] = true;
        }
        $order = [];
        foreach ($priorities as $id => $val) {
            $order[sanitize_key($id)] = $val;
        }

        $data = ['order' => $order, 'hidden' => $hidden];
        update_option(self::OPTION, $data);
        OUS_Revisions::snapshot('portal_layout', 1, $data, 'Saved from Portal Layout admin page');

        wp_safe_redirect(add_query_arg('updated', '1', admin_url('admin.php?page=ous-portal-layout')));
        exit;
    }

    public static function handle_restore_revision(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('bhi_restore_portal_layout_revision', 'ous_revisions_nonce')) {
            wp_die('Invalid request.');
        }

        $version = isset($_GET['version']) ? (int) $_GET['version'] : 0;
        $snapshot = OUS_Revisions::restore('portal_layout', 1, $version);
        if ($snapshot) {
            update_option(self::OPTION, $snapshot);
        }

        wp_safe_redirect(add_query_arg('updated', '1', admin_url('admin.php?page=ous-portal-layout')));
        exit;
    }
}
