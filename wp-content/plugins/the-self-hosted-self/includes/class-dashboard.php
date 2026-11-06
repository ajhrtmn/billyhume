<?php
if (!defined('ABSPATH')) exit;

/**
 * Dashboard page plus its admin-post handlers. Handlers only check
 * permissions/nonces and redirect; the work is in OUS_Installer and
 * OUS_ActivationManager.
 */
class OUS_Dashboard {
    public static function add_menu(): void {
        add_menu_page('The Self-Hosted Self', 'The Self-Hosted Self', 'manage_options', 'ous', [self::class, 'render'], OUS_MenuIcons::hub(), 3);
        // Relabels WP's auto-created duplicate first submenu item.
        add_submenu_page('ous', 'The Self-Hosted Self', 'Dashboard', 'manage_options', 'ous', [self::class, 'render']);
    }

    public static function enqueue_assets(string $hook): void {
        if (strpos($hook, 'ous') === false) return;
        wp_enqueue_style('ous-admin', OUS_URL . 'assets/css/admin.css', [], OUS_VER);
    }

    private static function ecosystem_fully_active_for_banner(): bool {
        foreach (array_keys(OUS_Registry::all()) as $key) {
            if (OUS_Registry::status($key) !== 'active') return false;
        }
        return true;
    }

    /* ---------- rendering ---------- */

    public static function render(): void {
        echo '<div class="wrap ous-dashboard">';
        echo '<h1>The Self-Hosted Self</h1>';
        echo '<p class="description">One dashboard for the whole ecosystem. Activate pieces in order below — dependencies get activated automatically when you activate something that needs them.</p>';

        // First-run nudge toward Guided Setup; hides once everything is active.
        if (class_exists('OUS_SetupWizard') && !self::ecosystem_fully_active_for_banner()) {
            echo '<div class="bhy-alert bhy-alert-info"><p><strong>New here?</strong> The <a href="' . esc_url(admin_url('admin.php?page=ous-setup-wizard')) . '">Guided Setup</a> walks you through activating everything and setting your brand basics, in order.</p></div>';
        }

        if (isset($_GET['ous_activated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Activated.</p></div>';
        }
        if (isset($_GET['ous_installed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Installed — click Activate below when you\'re ready.</p></div>';
        }
        if (isset($_GET['ous_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(self::error_message($_GET['ous_error'])) . '</p></div>';
        }

        $registry = OUS_Registry::visible_cards();
        $any_actionable = array_filter(array_keys($registry), fn($key) => OUS_Registry::status($key) !== 'active');
        if (count($any_actionable) > 1) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=ous_activate_all'), 'ous_activate_all');
            echo '<p><a class="button button-hero" href="' . esc_url($url) . '">Install &amp; Activate Everything</a></p>';
        }

        // Hide "Not installed" cards by default on a partially used site.
        $show_absent = class_exists('OUS_GithubUpdates') && OUS_GithubUpdates::show_absent_rows();
        $missing = $present = 0;
        foreach ($registry as $k => $i) {
            if (OUS_Registry::status($k) === 'missing') $missing++; else $present++;
        }
        // A fresh install keeps the full install list visible.
        $collapse_missing = !$show_absent && $missing > 0 && $present > 0;
        $hidden = $collapse_missing ? $missing : 0;
        if ($hidden || $show_absent) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:4px 0 14px;">';
            echo '<input type="hidden" name="action" value="ous_updates_toggle_absent">';
            wp_nonce_field('ous_updates_toggle_absent');
            echo '<label style="cursor:pointer;"><input type="checkbox" name="show_absent" value="1"' . checked($show_absent, true, false) . ' onchange="this.form.submit()"> ';
            echo 'Show ecosystem plugins that aren\'t installed here';
            if (!$show_absent && $hidden) echo ' <span class="description">(' . (int) $hidden . ' hidden)</span>';
            echo '</label></form>';
        }

        echo '<div class="ous-cards">';
        foreach ($registry as $key => $info) {
            if ($collapse_missing && OUS_Registry::status($key) === 'missing') continue;
            self::render_card($key, $info);
        }
        echo '</div>';

        $discovered = OUS_Registry::discover_unregistered();
        if ($discovered) {
            echo '<h2 style="margin-top:32px;">Other detected plugins</h2>';
            echo '<p class="description">These declared themselves part of this ecosystem but haven\'t been fully integrated into this dashboard yet — still activatable, just with less detail shown here.</p>';
            echo '<div class="ous-cards">';
            foreach ($discovered as $file => $info) {
                self::render_minimal_card($file, $info);
            }
            echo '</div>';
        }

        if (class_exists('BHI_Auth')) BHI_Auth::render_settings_card();
        self::render_status_block();

        echo '</div>';
    }

    // Operational status: Job Queue health/counts and a Query Monitor pointer
    // (QM is optional and deliberately not bundled).
    private static function render_status_block(): void {
        echo '<h2 style="margin-top:32px;">System status</h2>';
        echo '<div class="ous-cards">';

        echo '<div class="ous-card">';
        echo '<div class="ous-card-header"><strong>Job Queue</strong></div>';
        if (class_exists('OUS_Jobs')) {
            if (OUS_Jobs::library_available()) {
                echo '<p class="ous-card-desc">&#9989; Running on Action Scheduler (vendored real library).</p>';
            } else {
                echo '<p class="ous-card-desc">&#9888; Running on the built-in fallback queue (plain wpdb table).</p>';
            }
            $counts = OUS_Jobs::counts_by_status();
            echo '<p class="ous-card-meta">Pending: <strong>' . (int) $counts['pending'] . '</strong> &nbsp; Running: <strong>' . (int) $counts['running'] . '</strong> &nbsp; Done: <strong>' . (int) $counts['done'] . '</strong> &nbsp; Failed: <strong>' . (int) $counts['failed'] . '</strong></p>';
            echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=ous-debug#ous-section-bh-jobs')) . '">Open Job Queue &rarr;</a>';
        } else {
            echo '<p class="ous-card-desc">OUS_Jobs isn\'t loaded.</p>';
        }
        echo '</div>';

        echo '<div class="ous-card">';
        echo '<div class="ous-card-header"><strong>Query Monitor</strong></div>';
        if (!function_exists('is_plugin_active')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if (is_plugin_active('query-monitor/query-monitor.php')) {
            echo '<p class="ous-card-desc">&#9989; Query Monitor is active — its panel lives in the admin toolbar (top of every admin page), including an "The Self-Hosted Self" tab showing this request\'s own log entries.</p>';
        } else {
            echo '<p class="ous-card-desc">Not installed. A recommended, free, self-hosted diagnostic tool (queries, hooks, HTTP requests, PHP errors) — optional, not bundled with this ecosystem.</p>';
            echo '<a class="button" href="' . esc_url(admin_url('plugin-install.php?s=query-monitor&tab=search&type=term')) . '">Install Query Monitor</a>';
        }
        echo '</div>';

        echo '</div>';
    }

    /** @param array<string, mixed> $info */
    private static function render_card(string $key, $info): void {
        $status = OUS_Registry::status($key);
        $badge = ['missing' => 'Not installed', 'inactive' => 'Installed, inactive', 'active' => 'Active'][$status];
        $badge_class = ['missing' => 'ous-badge-missing', 'inactive' => 'ous-badge-inactive', 'active' => 'ous-badge-active'][$status];

        echo '<div class="ous-card">';
        echo '<div class="ous-card-header"><strong>' . esc_html($info['label']) . '</strong> <span class="ous-badge ' . $badge_class . '">' . esc_html($badge) . '</span></div>';
        echo '<p class="ous-card-desc">' . esc_html($info['description']) . '</p>';

        if ($status === 'active') {
            $version = OUS_Registry::version($key);
            if ($version) echo '<p class="ous-card-meta">v' . esc_html($version) . '</p>';
            self::render_github_status($key);
            if (!empty($info['dashboard_link'])) {
                echo '<a class="button" href="' . esc_url(admin_url($info['dashboard_link'])) . '">Open &rarr;</a>';
            }
        } elseif ($status === 'inactive') {
            $needs = array_filter($info['depends_on'], fn($dep) => OUS_Registry::status($dep) !== 'active');
            if ($needs) {
                $labels = array_map(fn($dep) => OUS_Registry::all()[$dep]['label'], $needs);
                echo '<p class="ous-card-meta">Will also activate: ' . esc_html(implode(', ', $labels)) . '</p>';
            }
            $url = wp_nonce_url(admin_url('admin-post.php?action=ous_activate&plugin=' . $key), 'ous_activate_' . $key);
            echo '<a class="button button-primary" href="' . esc_url($url) . '">Activate</a>';
        } else {
            $url = wp_nonce_url(admin_url('admin-post.php?action=ous_install&plugin=' . $key), 'ous_install_' . $key);
            $label = !empty($info['wporg_slug']) ? 'Install from WordPress.org' : 'Install';
            echo '<a class="button button-primary" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
            if (!empty($info['wporg_slug'])) {
                echo '<p class="ous-card-meta">Downloads directly from WordPress.org — needs the server to reach the internet.</p>';
            }
        }

        echo '</div>';
    }

    // Reads OUS_GithubUpdates' cached status option, never a live API call
    // per card. "Not checked yet" is a normal state after activation.
    private static function render_github_status(string $key): void {
        if (!class_exists('OUS_GithubUpdates')) return;
        $status = get_option('ous_github_update_status', []);
        $entry = $status[$key] ?? null;
        if ($entry === null) {
            echo '<p class="ous-card-meta ous-card-meta-muted">GitHub: not checked yet</p>';
            return;
        }
        if (!empty($entry['update_available'])) {
            echo '<p class="ous-card-meta ous-card-github-update"><span class="dashicons dashicons-update"></span> Update available (' . esc_html((string) $entry['remote_version']) . ')</p>';
        } elseif ($entry['remote_version'] === null) {
            echo '<p class="ous-card-meta ous-card-meta-muted">GitHub: check failed</p>';
        } else {
            echo '<p class="ous-card-meta ous-card-meta-muted">GitHub: up to date</p>';
        }
    }

    /** @param array<string, mixed> $info */
    private static function render_minimal_card(string $file, $info): void {
        $status = OUS_Registry::status_by_file($file);
        echo '<div class="ous-card">';
        echo '<div class="ous-card-header"><strong>' . esc_html($info['label']) . '</strong> <span class="ous-badge ' . ($status === 'active' ? 'ous-badge-active' : 'ous-badge-inactive') . '">' . ($status === 'active' ? 'Active' : 'Installed, inactive') . '</span></div>';
        if ($status === 'inactive') {
            $url = wp_nonce_url(admin_url('admin-post.php?action=ous_activate_file&file=' . urlencode($file)), 'ous_activate_file_' . $file);
            echo '<a class="button button-primary" href="' . esc_url($url) . '">Activate</a>';
        }
        echo '</div>';
    }

    private static function error_message(string $code): string {
        $messages = [
            'missing' => 'That plugin isn\'t installed yet — upload it to Plugins first.',
            'not_allowed' => 'You don\'t have permission to do that.',
            'activation_failed' => 'WordPress could not activate that plugin — check the Plugins screen for a more specific error.',
            'bundle_stale' => 'Refused to install — the bundled copy is older than what\'s already on this site (that would have silently reverted real changes). Go to Debug Tools → Bundled Zip Freshness and regenerate it first.',
        ];
        return $messages[$code] ?? 'Something went wrong.';
    }

    /* ---------- admin-post handlers — permission/nonce/redirect only, delegate the real work ---------- */

    public static function handle_install(): void {
        $key = sanitize_key($_GET['plugin'] ?? '');
        if (!OUS_AdminGuard::verify_nonce_and_cap('install_plugins', $_GET['_wpnonce'] ?? '', 'ous_install_' . $key)) {
            wp_safe_redirect(admin_url('admin.php?page=ous&ous_error=not_allowed'));
            exit;
        }

        $installed = OUS_Installer::install($key);
        if ($installed) {
            wp_safe_redirect(admin_url('admin.php?page=ous&ous_installed=1'));
            exit;
        }
        // last_error() carries a specific reason (e.g. bundle_stale) when there is one.
        $code = (class_exists('OUS_Installer') && OUS_Installer::last_error()) ? OUS_Installer::last_error() : 'missing';
        wp_safe_redirect(admin_url('admin.php?page=ous&ous_error=' . $code));
        exit;
    }

    public static function handle_activate(): void {
        if (!current_user_can('activate_plugins') || !current_user_can('install_plugins')
            || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'ous_activate_' . ($_GET['plugin'] ?? ''))) {
            wp_safe_redirect(admin_url('admin.php?page=ous&ous_error=not_allowed'));
            exit;
        }

        $key = sanitize_key($_GET['plugin'] ?? '');
        $ok = OUS_ActivationManager::activate_with_dependencies($key);
        wp_safe_redirect($ok
            ? admin_url('admin.php?page=ous&ous_activated=1')
            : admin_url('admin.php?page=ous&ous_error=activation_failed'));
        exit;
    }

    public static function handle_activate_all(): void {
        if (!current_user_can('activate_plugins') || !current_user_can('install_plugins')
            || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'ous_activate_all')) {
            wp_safe_redirect(admin_url('admin.php?page=ous&ous_error=not_allowed'));
            exit;
        }

        $all_ok = true;
        foreach (array_keys(OUS_Registry::all()) as $key) {
            if (OUS_Registry::status($key) !== 'active') {
                if (!OUS_ActivationManager::activate_with_dependencies($key)) $all_ok = false;
            }
        }
        wp_safe_redirect($all_ok
            ? admin_url('admin.php?page=ous&ous_activated=1')
            : admin_url('admin.php?page=ous&ous_error=activation_failed'));
        exit;
    }

    public static function handle_activate_file(): void {
        $file = sanitize_text_field(wp_unslash($_GET['file'] ?? ''));
        if (!OUS_AdminGuard::verify_nonce_and_cap('activate_plugins', $_GET['_wpnonce'] ?? '', 'ous_activate_file_' . $file)) {
            wp_safe_redirect(admin_url('admin.php?page=ous&ous_error=not_allowed'));
            exit;
        }
        $result = activate_plugin($file);
        wp_safe_redirect(is_wp_error($result)
            ? admin_url('admin.php?page=ous&ous_error=activation_failed')
            : admin_url('admin.php?page=ous&ous_activated=1'));
        exit;
    }
}
