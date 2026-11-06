<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared Debug Tools page. Any plugin adds a section via the `ous_debug_tools`
 * filter (or OUS_Debug::register()) with keys: label, render, handle, reset,
 * and optional group / safe_in_production. A 'handle' callback returns a
 * message string, or may redirect and exit itself.
 *
 * WHY: the production lock (is_locked()) is enforced here centrally, so
 * registered handle/reset callbacks never need to re-check it.
 */
class OUS_Debug {
    // Display order for the optional 'group' key; unknown group names sort
    // alphabetically just before the catch-all default bucket.
    const GROUP_MONITORING = 'Monitoring & Health';
    const GROUP_REFERENCE  = 'Reference & Docs';
    const GROUP_SEED_RESET = 'Seed & Reset Tools';
    const GROUP_DEFAULT    = 'Diagnostics & Tools';
    const GROUP_ORDER = [self::GROUP_MONITORING, self::GROUP_REFERENCE, self::GROUP_SEED_RESET, self::GROUP_DEFAULT];

    // Per-user (not global) toggle: with it off, only GROUP_MONITORING is shown.
    const DEV_MODE_META = 'ous_debug_dev_mode';

    public static function is_dev_mode(): bool {
        return (bool) get_user_meta(get_current_user_id(), self::DEV_MODE_META, true);
    }

    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_ous_debug_action', [self::class, 'handle']);
        add_action('admin_post_ous_debug_toggle_dev_mode', [self::class, 'handle_toggle_dev_mode']);
    }

    public static function handle_toggle_dev_mode(): void {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_GET['_wpnonce'] ?? '', 'ous_debug_toggle_dev_mode')) {
            wp_die('Security check failed.', '', ['response' => 403, 'back_link' => true]);
        }
        update_user_meta(get_current_user_id(), self::DEV_MODE_META, self::is_dev_mode() ? 0 : 1);
        wp_safe_redirect(admin_url('admin.php?page=ous-debug'));
        exit;
    }

    // Buckets tools by 'group' (default GROUP_DEFAULT), ordered per GROUP_ORDER.
    /**
     * @param array<string, mixed> $tools
     * @return array<string, array<string, mixed>>
     */
    private static function group_tools(array $tools): array {
        $buckets = [];
        foreach ($tools as $key => $tool) {
            $group = !empty($tool['group']) ? $tool['group'] : self::GROUP_DEFAULT;
            $buckets[$group][$key] = $tool;
        }
        $known = self::GROUP_ORDER;
        $extra = array_values(array_diff(array_keys($buckets), $known));
        sort($extra);
        $default_pos = array_search(self::GROUP_DEFAULT, $known, true);
        $order = array_merge(array_slice($known, 0, $default_pos), $extra, array_slice($known, $default_pos));

        $ordered = [];
        foreach ($order as $group) {
            if (!empty($buckets[$group])) $ordered[$group] = $buckets[$group];
        }
        return $ordered;
    }

    /**
     * Convenience wrapper around add_filter('ous_debug_tools', ...).
     * $group: one of the GROUP_* constants or a custom name; null = GROUP_DEFAULT.
     */
    public static function register(string $key, string $label, callable $render, ?callable $handle, ?callable $reset = null, bool $safe_in_production = false, ?string $group = null): void {
        add_filter('ous_debug_tools', function ($tools) use ($key, $label, $render, $handle, $reset, $safe_in_production, $group) {
            $tools[$key] = [
                'label' => $label, 'render' => $render, 'handle' => $handle, 'reset' => $reset,
                'safe_in_production' => $safe_in_production,
                'group' => $group,
            ];
            return $tools;
        });
    }

    /**
     * True if this looks like production (fails safe: unknown = locked).
     * Override with define('OUS_DEBUG_TOOLS_FORCE', true) in wp-config.php.
     */
    public static function is_locked(): bool {
        if (defined('OUS_DEBUG_TOOLS_FORCE') && OUS_DEBUG_TOOLS_FORCE) return false;
        if (!function_exists('wp_get_environment_type') || wp_get_environment_type() === 'production') {
            // WP_ENVIRONMENT_TYPE is rarely set by local dev tools, so a
            // local-only hostname is accepted as a second signal.
            // WHY: home_url() can be served stale by a persistent object cache,
            // so the raw Host header and a direct DB read are checked too.
            $raw_host = isset($_SERVER['HTTP_HOST']) ? wp_parse_url('http://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST) : '';
            $option_host = wp_parse_url(home_url(), PHP_URL_HOST) ?: '';
            global $wpdb;
            $db_home = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'home'));
            $db_host = $db_home ? (wp_parse_url($db_home, PHP_URL_HOST) ?: '') : '';
            $local_pattern = '/(^localhost$|^127\.0\.0\.1$|\.(test|local|localhost)$)/i';
            $looks_local = (bool) preg_match($local_pattern, $raw_host)
                || (bool) preg_match($local_pattern, $option_host)
                || (bool) preg_match($local_pattern, $db_host);
            if (!$looks_local) {
                if (class_exists('OUS_DebugLog')) {
                    OUS_DebugLog::log('info', 'is_locked() returned TRUE — none of raw HTTP_HOST, cached home_url(), or a direct DB read of the home option looked local.', [
                        'raw_host' => $raw_host, 'option_host' => $option_host, 'db_host' => $db_host,
                    ], 'OUS_Debug::is_locked()');
                }
                return true;
            }
            // Throttled trace of a passing check, so an empty log means "not running".
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log_throttled('info', 'is_locked_pass', 300,
                    'is_locked() evaluated and returned FALSE (unlocked) this request.',
                    ['raw_host' => $raw_host, 'option_host' => $option_host, 'db_host' => $db_host],
                    'OUS_Debug::is_locked()'
                );
            }
        }
        return false;
    }

    // Separate top-level menu for dev tooling. The same-slug submenu call
    // relabels WP's auto-created first item to "Debug Tools".
    public static function add_menu(): void {
        // manage_options gates the page; seed/reset actions stay blocked in
        // production per section via is_locked() in handle().
        add_menu_page('Debug Tools', 'OUS Debug', 'manage_options', 'ous-debug', [self::class, 'render'], 'dashicons-admin-tools', 99);
        add_submenu_page('ous-debug', 'Debug Tools', 'Debug Tools', 'manage_options', 'ous-debug', [self::class, 'render']);
    }

    /* ---------------- shared UI helpers every registered plugin's render() can use ---------------- */

    // One shared button/form (nonce + admin-post action) for every plugin's
    // debug actions. $extra_html carries extra fields.
    public static function button(string $plugin_key, string $action, string $label, string $extra_html = '', string $confirm = '', bool $primary = true): void {
        $nonce = wp_create_nonce('ous_debug_' . $plugin_key);
        $onclick = $confirm ? " onclick=\"return confirm('" . esc_js($confirm) . "')\"" : '';
        echo "<form method='post' action='" . esc_url(admin_url('admin-post.php')) . "' style='display:inline-block;margin:4px 8px 4px 0;'$onclick>";
        echo "<input type='hidden' name='action' value='ous_debug_action'>";
        echo "<input type='hidden' name='ous_plugin' value='" . esc_attr($plugin_key) . "'>";
        echo "<input type='hidden' name='ous_debug_action' value='" . esc_attr($action) . "'>";
        echo "<input type='hidden' name='_wpnonce' value='" . esc_attr($nonce) . "'>";
        echo $extra_html;
        echo "<button class='button" . ($primary ? ' button-primary' : ' button-secondary') . "'>" . esc_html($label) . "</button>";
        echo "</form>";
    }

    // Tags fake accounts with bhcore_is_test user meta so Reset Everything
    // can find every plugin's test users.
    public static function get_or_create_test_user(string $tag, bool $reuse_odds = true): int {
        $pool = get_users(['meta_key' => 'bhcore_is_test', 'meta_value' => $tag, 'fields' => 'ID', 'number' => 20]);
        if ($pool && $reuse_odds && wp_rand(0, 1)) return $pool[array_rand($pool)];

        $n = wp_rand(1000, 999999);
        $id = wp_insert_user([
            'user_login' => "test_{$tag}_{$n}",
            'user_email' => "test_{$tag}_{$n}@example.test",
            'user_pass'  => wp_generate_password(20),
            'role'       => 'subscriber',
        ]);
        if (is_wp_error($id)) return get_current_user_id(); // fallback, shouldn't happen
        update_user_meta($id, 'bhcore_is_test', $tag);
        return $id;
    }

    /* ---------------- the page ---------------- */

    public static function render(): void {
        $notice = isset($_GET['ous_msg']) ? sanitize_text_field(wp_unslash($_GET['ous_msg'])) : '';
        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown';

        BHY_UI::shell_open('Debug Tools');
        if (self::is_locked()) {
            echo '<div class="bhy-alert bhy-alert-danger"><strong>Locked.</strong> Detected environment: <code>' . esc_html($env) . '</code>. '
               . 'Seeding/reset actions are blocked because this looks like production. '
               . 'To unlock, add <code>define(\'OUS_DEBUG_TOOLS_FORCE\', true);</code> to wp-config.php, or set <code>WP_ENVIRONMENT_TYPE</code> to <code>local</code>/<code>development</code>/<code>staging</code>.</div>';
        } else {
            echo '<div class="bhy-alert bhy-alert-success"><strong>Unlocked.</strong> Detected environment: <code>' . esc_html($env) . '</code>. Safe to seed test data.</div>';
        }
        if ($notice) echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';

        $dev_mode = self::is_dev_mode();
        $toggle_url = wp_nonce_url(admin_url('admin-post.php?action=ous_debug_toggle_dev_mode'), 'ous_debug_toggle_dev_mode');
        if ($dev_mode) {
            echo '<p class="description">Developer mode is on — every registered section is shown. <a href="' . esc_url($toggle_url) . '">Hide developer tools</a> (just for your account; this doesn\'t affect other admins).</p>';
        } else {
            echo '<div class="bhy-alert bhy-alert-info">'
               . 'Showing monitoring &amp; health tools only. Reference docs and seed/reset tools are hidden by default — '
               . '<a href="' . esc_url($toggle_url) . '"><strong>show developer tools</strong></a> to see everything (just for your account).</div>';
        }

        // WHY: links to the in-page sections; the standalone API Docs /
        // Codebase Docs admin pages have been unreliably blocked by WP.
        if ($dev_mode) {
            echo '<p>'
               . '<a class="button" href="#ous-section-api-docs">API Docs</a> '
               . '<a class="button" href="#ous-section-codebase-docs">Codebase Docs</a>'
               . '</p>';
        }

        $tools = apply_filters('ous_debug_tools', []);
        if (!$tools) {
            echo '<p class="description">No plugin has registered any debug tools yet.</p>';
            BHY_UI::shell_close();
            return;
        }

        // scroll-margin-top keeps anchor targets from landing behind the WP
        // admin bar and the sticky quicknav.
        $grouped = self::group_tools($tools);
        // With dev mode off, only Monitoring & Health is rendered.
        if (!$dev_mode) {
            $grouped = array_intersect_key($grouped, [self::GROUP_MONITORING => true]);
        }

        echo '<style>
' . (string) file_get_contents(OUS_PATH . 'assets/css/debug-tools.css') . '</style>';

        echo '<div class="ous-debug-quicknav" style="position:sticky;top:var(--bhy-admin-bar-offset,32px);z-index:10;background:var(--bhy-surface,#fff);border:1px solid var(--bhy-border,#dcdcde);border-radius:4px;padding:8px 12px;margin-bottom:16px;display:flex;flex-direction:column;gap:6px;font-size:12px;">';
        foreach ($grouped as $group_label => $group_tools) {
            echo '<span class="ous-debug-quicknav-group"><strong>' . esc_html($group_label) . ':</strong>';
            foreach ($group_tools as $key => $tool) {
                echo ' <a href="#ous-section-' . esc_attr($key) . '">' . esc_html($tool['label']) . '</a>';
            }
            echo '</span>';
        }
        if ($dev_mode) echo '<span class="ous-debug-quicknav-group"><a href="#ous-section-reset-all">Reset everything</a></span>';
        echo '</div>';

        // Sections default closed; open/closed state is remembered per
        // browser in localStorage (script below).
        foreach ($grouped as $group_label => $group_tools) {
            $group_id = 'ous-group-' . sanitize_title($group_label);
            // Groups default open so a first visit doesn't look empty.
            echo '<details class="ous-debug-group" id="' . esc_attr($group_id) . '" open>';
            echo '<summary class="ous-debug-group-heading">' . esc_html($group_label) . '</summary>';
            echo '<div class="ous-debug-group-body">';
            foreach ($group_tools as $key => $tool) {
                echo '<details class="bhy-card ous-debug-section" id="ous-section-' . esc_attr($key) . '">';
                echo '<summary>' . esc_html($tool['label']) . '</summary>';
                echo '<div class="ous-debug-section-body">';
                if (!empty($tool['render'])) call_user_func($tool['render'], $key);
                echo '</div>';
                echo '</details>';
            }
            echo '</div>';
            echo '</details>';
        }

        if ($dev_mode) {
            echo '<details class="bhy-card" id="ous-section-reset-all">';
            echo '<summary>Reset everything</summary>';
            echo '<div class="ous-debug-section-body"><p>Wipes every registered plugin\'s own tagged test data in one pass. Real data is untouched.</p>';
            self::button('__all__', 'reset_all', 'Wipe all test data (every plugin)', '', 'Delete ALL test data from every plugin? This cannot be undone.', false);
            echo '</div></details>';
        }

        // Restores remembered open/closed state, then force-opens and
        // highlights the section a redirect anchor points at.
        echo '<script>
' . (string) file_get_contents(OUS_PATH . 'assets/inline/debug-tools.js') . '</script>';
        BHY_UI::shell_close();
    }

    /* ---------------- dispatch ---------------- */

    public static function handle(): void {
        $plugin_key = sanitize_key($_POST['ous_plugin'] ?? '');
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_POST['_wpnonce'] ?? '', 'ous_debug_' . $plugin_key)) {
            wp_die('Not allowed.');
        }

        $action = sanitize_key($_POST['ous_debug_action'] ?? '');
        $tools = apply_filters('ous_debug_tools', []);

        // The production lock applies per section: a section opts out with
        // 'safe_in_production' => true. Redirects carry an anchor back to
        // the section that produced them.
        $anchor = ($plugin_key && $plugin_key !== '__all__') ? 'ous-section-' . $plugin_key : '';

        $safe = ($plugin_key !== '__all__') && !empty($tools[$plugin_key]['safe_in_production']);
        if (self::is_locked() && !$safe) {
            self::redirect('Blocked: this looks like a production environment. Add define(\'OUS_DEBUG_TOOLS_FORCE\', true) to wp-config.php to override.', $anchor);
        }

        if ($plugin_key === '__all__' && $action === 'reset_all') {
            $messages = [];
            foreach ($tools as $tool) {
                if (!empty($tool['reset'])) $messages[] = call_user_func($tool['reset']);
            }
            self::redirect($messages ? implode(' ', $messages) : 'Nothing to reset.', 'ous-section-reset-all');
        }

        if (!isset($tools[$plugin_key]) || empty($tools[$plugin_key]['handle'])) {
            self::redirect('Unknown debug action.', $anchor);
        }

        $msg = call_user_func($tools[$plugin_key]['handle'], $action, $_POST);
        $final_msg = $msg ?: 'Done.';

        // Also shown as a toast; the dispatcher has no status flag, so 'info'.
        if (class_exists('OUS_Toast')) OUS_Toast::queue($final_msg, 'info');

        self::redirect($final_msg, $anchor);
    }

    private static function redirect(string $msg, string $anchor = ''): void {
        $url = add_query_arg('ous_msg', rawurlencode($msg), admin_url('admin.php?page=ous-debug'));
        if ($anchor) $url .= '#' . $anchor;
        wp_safe_redirect($url);
        exit;
    }
}
