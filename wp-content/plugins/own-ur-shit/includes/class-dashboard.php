<?php
if (!defined('ABSPATH')) exit;

/**
 * The dashboard page itself, plus the admin-post request handlers that
 * back its buttons. Deliberately thin: every handler here does
 * permission/nonce checking and redirect handling, then delegates the
 * actual work to OUS_Installer or OUS_ActivationManager — this class
 * doesn't contain business logic of its own, just the HTTP-facing
 * plumbing around it.
 */
class OUS_Dashboard {
    /**
     * Registers the 'dashboard' surface for BH_Element (§3.3/§5.1 of
     * ELEMENT-BUILDER-DESIGN-PLAN.md) — a singleton surface
     * (surface_context_id always 0, there's only one dashboard) with a
     * 'main' slot rendered below the existing status block. Context is
     * the current viewer's user_id, since Phase 2's demo binding
     * ('bhcore_events.count') needs a subject to count for.
     */
    public static function register_element_surface($surfaces) {
        $surfaces['dashboard'] = [
            'group'       => 'Core',
            'label'       => 'Dashboard',
            'slots'       => [
                'main' => ['label' => 'Main'],
            ],
            'context'     => ['type' => 'global', 'param' => null],
            'preview_ctx' => function () { return ['user_id' => get_current_user_id()]; },
        ];
        return $surfaces;
    }

    public static function add_menu() {
        add_menu_page('Own Ur Shit', 'Own Ur Shit', 'manage_options', 'own-ur-shit', [self::class, 'render'], 'dashicons-admin-multisite', 3);
        // WordPress auto-creates a first submenu item duplicating the
        // top-level menu's own label unless explicitly overridden —
        // this replaces that duplicate with a clearer "Dashboard" label
        // rather than literally repeating "Own Ur Shit" twice in the
        // sidebar.
        add_submenu_page('own-ur-shit', 'Own Ur Shit', 'Dashboard', 'manage_options', 'own-ur-shit', [self::class, 'render']);
    }

    public static function enqueue_assets($hook) {
        if (strpos($hook, 'own-ur-shit') === false) return;
        wp_enqueue_style('ous-admin', OUS_URL . 'assets/css/admin.css', [], OUS_VER);
    }

    /* ---------- rendering ---------- */

    public static function render() {
        echo '<div class="wrap ous-dashboard">';
        echo '<h1>Own Ur Shit</h1>';
        echo '<p class="description">One dashboard for the whole ecosystem. Activate pieces in order below — dependencies get activated automatically when you activate something that needs them.</p>';

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

        echo '<div class="ous-cards">';
        foreach ($registry as $key => $info) {
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

        self::render_status_block();
        self::render_element_slot();

        echo '</div>';
    }

    // Small operational-status block: Job Queue health (Action
    // Scheduler vs. the fallback table, plus live pending/running/done/
    // failed counts, reusing OUS_Jobs::counts_by_status() rather than
    // re-querying bhcore_jobs here) and a Query Monitor pointer. Neither
    // is a "card" in the activation-flow sense above — nothing to
    // install/activate through THIS dashboard for Job Queue (it's
    // core, always active) and Query Monitor is deliberately left as an
    // optional, user-chosen third-party dev tool (not bundled, not
    // auto-installed) rather than folded into OUS_Registry.
    private static function render_status_block() {
        echo '<h2 style="margin-top:32px;">System status</h2>';
        echo '<div class="ous-cards">';

        // Job Queue
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

        // Query Monitor
        echo '<div class="ous-card">';
        echo '<div class="ous-card-header"><strong>Query Monitor</strong></div>';
        if (!function_exists('is_plugin_active')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if (is_plugin_active('query-monitor/query-monitor.php')) {
            echo '<p class="ous-card-desc">&#9989; Query Monitor is active — its panel lives in the admin toolbar (top of every admin page).</p>';
        } else {
            echo '<p class="ous-card-desc">Not installed. A recommended, free, self-hosted diagnostic tool (queries, hooks, HTTP requests, PHP errors) — optional, not bundled with this ecosystem.</p>';
            echo '<a class="button" href="' . esc_url(admin_url('plugin-install.php?s=query-monitor&tab=search&type=term')) . '">Install Query Monitor</a>';
        }
        echo '</div>';

        echo '</div>';
    }

    // The Phase 1/2 element-builder proof-of-concept slice
    // (ELEMENT-BUILDER-DESIGN-PLAN.md §5.1, §6 Phases 1-2): renders
    // whatever's been placed into the 'dashboard' surface's 'main' slot
    // via BH_Element::render_slot(). Additive only — every existing
    // dashboard card/status block above is untouched; this renders
    // BELOW them and is empty (no heading, no markup at all) when no
    // placements exist yet, so a fresh install looks identical to
    // before this pass.
    private static function render_element_slot() {
        if (!class_exists('BH_Element')) return; // element-builder files didn't load for some reason — degrade silently, same posture as every other class_exists() guard in this ecosystem

        $ctx = ['user_id' => get_current_user_id()];
        $html = BH_Element::render_slot('dashboard', 0, 'main', $ctx);
        if ($html === '') return; // nothing placed yet — render nothing, not an empty heading

        echo '<h2 style="margin-top:32px;">Dashboard elements</h2>';
        echo '<p class="description">Placed via <a href="' . esc_url(admin_url('admin.php?page=bh-element-builder')) . '">Design Suite &rarr; Element Builder</a>.</p>';
        echo $html; // BH_Element::render_slot()'s own output is already escaped per-attribute by BH_Element::render_placement()/each type's own 'render' callable — see class-element.php and class-element-data.php's docblocks for the escaping contract this depends on.
    }

    private static function render_card($key, $info) {
        $status = OUS_Registry::status($key);
        $badge = ['missing' => 'Not installed', 'inactive' => 'Installed, inactive', 'active' => 'Active'][$status];
        $badge_class = ['missing' => 'ous-badge-missing', 'inactive' => 'ous-badge-inactive', 'active' => 'ous-badge-active'][$status];

        echo '<div class="ous-card">';
        echo '<div class="ous-card-header"><strong>' . esc_html($info['label']) . '</strong> <span class="ous-badge ' . $badge_class . '">' . esc_html($badge) . '</span></div>';
        echo '<p class="ous-card-desc">' . esc_html($info['description']) . '</p>';

        if ($status === 'active') {
            $version = OUS_Registry::version($key);
            if ($version) echo '<p class="ous-card-meta">v' . esc_html($version) . '</p>';
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

    private static function render_minimal_card($file, $info) {
        $status = OUS_Registry::status_by_file($file);
        echo '<div class="ous-card">';
        echo '<div class="ous-card-header"><strong>' . esc_html($info['label']) . '</strong> <span class="ous-badge ' . ($status === 'active' ? 'ous-badge-active' : 'ous-badge-inactive') . '">' . ($status === 'active' ? 'Active' : 'Installed, inactive') . '</span></div>';
        if ($status === 'inactive') {
            $url = wp_nonce_url(admin_url('admin-post.php?action=ous_activate_file&file=' . urlencode($file)), 'ous_activate_file_' . $file);
            echo '<a class="button button-primary" href="' . esc_url($url) . '">Activate</a>';
        }
        echo '</div>';
    }

    private static function error_message($code) {
        $messages = [
            'missing' => 'That plugin isn\'t installed yet — upload it to Plugins first.',
            'not_allowed' => 'You don\'t have permission to do that.',
            'activation_failed' => 'WordPress could not activate that plugin — check the Plugins screen for a more specific error.',
        ];
        return $messages[$code] ?? 'Something went wrong.';
    }

    /* ---------- admin-post handlers — permission/nonce/redirect only, delegate the real work ---------- */

    public static function handle_install() {
        $key = sanitize_key($_GET['plugin'] ?? '');
        if (!current_user_can('install_plugins') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'ous_install_' . $key)) {
            wp_safe_redirect(admin_url('admin.php?page=own-ur-shit&ous_error=not_allowed'));
            exit;
        }

        $installed = OUS_Installer::install($key);
        wp_safe_redirect($installed
            ? admin_url('admin.php?page=own-ur-shit&ous_installed=1')
            : admin_url('admin.php?page=own-ur-shit&ous_error=missing'));
        exit;
    }

    public static function handle_activate() {
        if (!current_user_can('activate_plugins') || !current_user_can('install_plugins')
            || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'ous_activate_' . ($_GET['plugin'] ?? ''))) {
            wp_safe_redirect(admin_url('admin.php?page=own-ur-shit&ous_error=not_allowed'));
            exit;
        }

        $key = sanitize_key($_GET['plugin'] ?? '');
        $ok = OUS_ActivationManager::activate_with_dependencies($key);
        wp_safe_redirect($ok
            ? admin_url('admin.php?page=own-ur-shit&ous_activated=1')
            : admin_url('admin.php?page=own-ur-shit&ous_error=activation_failed'));
        exit;
    }

    // The "Install & Activate Everything" button — runs the exact same
    // per-plugin logic as a single Activate click, just looped across
    // every registered plugin that isn't already active.
    public static function handle_activate_all() {
        if (!current_user_can('activate_plugins') || !current_user_can('install_plugins')
            || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'ous_activate_all')) {
            wp_safe_redirect(admin_url('admin.php?page=own-ur-shit&ous_error=not_allowed'));
            exit;
        }

        $all_ok = true;
        foreach (array_keys(OUS_Registry::all()) as $key) {
            if (OUS_Registry::status($key) !== 'active') {
                if (!OUS_ActivationManager::activate_with_dependencies($key)) $all_ok = false;
            }
        }
        wp_safe_redirect($all_ok
            ? admin_url('admin.php?page=own-ur-shit&ous_activated=1')
            : admin_url('admin.php?page=own-ur-shit&ous_error=activation_failed'));
        exit;
    }

    public static function handle_activate_file() {
        $file = sanitize_text_field(wp_unslash($_GET['file'] ?? ''));
        if (!current_user_can('activate_plugins') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'ous_activate_file_' . $file)) {
            wp_safe_redirect(admin_url('admin.php?page=own-ur-shit&ous_error=not_allowed'));
            exit;
        }
        $result = activate_plugin($file);
        wp_safe_redirect(is_wp_error($result)
            ? admin_url('admin.php?page=own-ur-shit&ous_error=activation_failed')
            : admin_url('admin.php?page=own-ur-shit&ous_activated=1'));
        exit;
    }
}
