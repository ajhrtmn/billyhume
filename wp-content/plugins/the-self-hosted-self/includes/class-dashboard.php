<?php
if (!defined('ABSPATH')) exit;

/**
 * Site Tools: the top-level admin page. One card per site plugin (status, version,
 * GitHub update state, a link to its screen) plus system status.
 */
class OUS_Dashboard {
    public static function add_menu(): void {
        add_menu_page('Site Tools', 'Site Tools', 'manage_options', 'ous', [self::class, 'render'], OUS_MenuIcons::hub(), 3);
        // Relabels WP's auto-created duplicate first submenu item.
        add_submenu_page('ous', 'Site Tools', 'Overview', 'manage_options', 'ous', [self::class, 'render']);
    }

    public static function enqueue_assets(string $hook): void {
        if (strpos($hook, 'ous') === false) return;
        wp_enqueue_style('ous-admin', OUS_URL . 'assets/css/admin.css', [], OUS_VER);
    }

    public static function render(): void {
        echo '<div class="wrap ous-dashboard">';
        echo '<h1>Site Tools</h1>';
        echo ('<p class="description">' . OUS_Words::get('admin.dashboard.plugins_run_site_colors_fonts') . ' <a href="') . esc_url(admin_url('customize.php')) . '">Appearance &rarr; Customize &rarr; Design Suite</a>; testing and error logs under <a href="' . esc_url(admin_url('admin.php?page=ous-debug')) . ('">' . OUS_Words::get('admin.dashboard.debug_tools') . '</a>.</p>');

        if (isset($_GET['ous_activated'])) {
            echo ('<div class="notice notice-success is-dismissible"><p>' . OUS_Words::get('admin.dashboard.activated') . '</p></div>');
        }
        if (isset($_GET['ous_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(self::error_message(sanitize_key($_GET['ous_error']))) . '</p></div>';
        }

        echo '<div class="ous-cards">';
        foreach (OUS_Registry::all() as $key => $info) {
            self::render_card($key, $info);
        }
        echo '</div>';

        if (class_exists('BHI_Auth')) BHI_Auth::render_settings_card();
        self::render_status_block();

        echo '</div>';
    }

    // Job Queue health/counts and a Query Monitor pointer (QM is optional).
    private static function render_status_block(): void {
        echo ('<h2 style="margin-top:32px;">' . OUS_Words::get('admin.dashboard.system_status') . '</h2>');
        echo '<div class="ous-cards">';

        echo '<div class="ous-card">';
        echo ('<div class="ous-card-header"><strong>' . OUS_Words::get('admin.dashboard.job_queue') . '</strong></div>');
        if (OUS_Jobs::library_available()) {
            echo '<p class="ous-card-desc">&#9989; Running on Action Scheduler.</p>';
        } else {
            echo '<p class="ous-card-desc">&#9888; Running on the built-in fallback queue.</p>';
        }
        $counts = OUS_Jobs::counts_by_status();
        echo ('<p class="ous-card-meta">' . OUS_Words::get('admin.dashboard.pending') . ' <strong>') . (int) $counts['pending'] . '</strong> &nbsp; Running: <strong>' . (int) $counts['running'] . '</strong> &nbsp; Done: <strong>' . (int) $counts['done'] . '</strong> &nbsp; Failed: <strong>' . (int) $counts['failed'] . '</strong></p>';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=ous-debug#ous-section-bh-jobs')) . '">Open Job Queue &rarr;</a>';
        echo '</div>';

        echo '<div class="ous-card">';
        echo ('<div class="ous-card-header"><strong>' . OUS_Words::get('admin.dashboard.query_monitor') . '</strong></div>');
        if (!function_exists('is_plugin_active')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if (is_plugin_active('query-monitor/query-monitor.php')) {
            echo '<p class="ous-card-desc">&#9989; Active. Its panel is in the admin toolbar at the top of every page.</p>';
        } else {
            echo ('<p class="ous-card-desc">' . OUS_Words::get('admin.dashboard.not_installed_free_tool_shows') . '</p>');
            echo '<a class="button" href="' . esc_url(admin_url('plugin-install.php?s=query-monitor&tab=search&type=term')) . ('">' . OUS_Words::get('admin.dashboard.install_query_monitor') . '</a>');
        }
        echo '</div>';

        echo '</div>';
    }

    /** @param array<string, mixed> $info */
    private static function render_card(string $key, $info): void {
        $status = OUS_Registry::status($key);
        $badge = ['missing' => OUS_Words::get('admin.dashboard.not_installed'), 'inactive' => OUS_Words::get('admin.dashboard.installed_inactive'), 'active' => 'Active'][$status];
        $badge_class = ['missing' => 'ous-badge-missing', 'inactive' => 'ous-badge-inactive', 'active' => 'ous-badge-active'][$status];

        echo '<div class="ous-card">';
        echo '<div class="ous-card-header"><strong>' . esc_html($info['label']) . '</strong> <span class="ous-badge ' . $badge_class . '">' . esc_html($badge) . '</span></div>';
        echo '<p class="ous-card-desc">' . esc_html($info['description']) . '</p>';

        if ($status === 'active') {
            $version = OUS_Registry::version($key);
            if ($version) echo '<p class="ous-card-meta">v' . esc_html($version) . '</p>';
            if (!empty($info['github'])) self::render_github_status($key);
            if (!empty($info['dashboard_link'])) {
                echo '<a class="button" href="' . esc_url(admin_url($info['dashboard_link'])) . '">Open &rarr;</a>';
            }
        } elseif ($status === 'inactive') {
            $url = wp_nonce_url(admin_url('admin-post.php?action=ous_activate&plugin=' . $key), 'ous_activate_' . $key);
            echo '<a class="button button-primary" href="' . esc_url($url) . ('">' . OUS_Words::get('admin.dashboard.activate') . '</a>');
        } else {
            echo ('<p class="ous-card-meta">' . OUS_Words::get('admin.dashboard.install_from_plugins_screen_github') . '</p>');
        }

        echo '</div>';
    }

    // Reads OUS_GithubUpdates' cached status option, never a live API call per card.
    private static function render_github_status(string $key): void {
        if (!class_exists('OUS_GithubUpdates')) return;
        $entry = get_option('ous_github_update_status', [])[$key] ?? null;
        if ($entry === null) {
            echo ('<p class="ous-card-meta ous-card-meta-muted">' . OUS_Words::get('admin.dashboard.github_not_checked_yet') . '</p>');
        } elseif (!empty($entry['update_available'])) {
            echo ('<p class="ous-card-meta ous-card-github-update"><span class="dashicons dashicons-update"></span> ' . OUS_Words::get('admin.dashboard.update_available')) . esc_html((string) $entry['remote_version']) . ')</p>';
        } elseif ($entry['remote_version'] === null) {
            echo ('<p class="ous-card-meta ous-card-meta-muted">' . OUS_Words::get('admin.dashboard.github_check_failed') . '</p>');
        } else {
            echo ('<p class="ous-card-meta ous-card-meta-muted">' . OUS_Words::get('admin.dashboard.github_up_date') . '</p>');
        }
    }

    private static function error_message(string $code): string {
        $messages = [
            'not_allowed' => OUS_Words::get('admin.dashboard.don_t_have_permission_do'),
            'activation_failed' => OUS_Words::get('admin.dashboard.wordpress_could_not_activate_plugin'),
        ];
        return $messages[$code] ?? OUS_Words::get('admin.dashboard.something_went_wrong');
    }

    /** Activates a registered plugin after the plugins it needs. */
    public static function handle_activate(): void {
        $key = sanitize_key($_GET['plugin'] ?? '');
        if (!OUS_AdminGuard::verify_nonce_and_cap('activate_plugins', $_GET['_wpnonce'] ?? '', 'ous_activate_' . $key)) {
            wp_safe_redirect(admin_url('admin.php?page=ous&ous_error=not_allowed'));
            exit;
        }
        $registry = OUS_Registry::all();
        $ok = isset($registry[$key]);
        foreach ($ok ? array_merge($registry[$key]['depends_on'], [$key]) : [] as $k) {
            $status = OUS_Registry::status($k);
            if ($status === 'missing' || ($status === 'inactive' && is_wp_error(activate_plugin($registry[$k]['file'] ?? '')))) {
                $ok = false;
                break;
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=ous&' . ($ok ? 'ous_activated=1' : 'ous_error=activation_failed')));
        exit;
    }
}
