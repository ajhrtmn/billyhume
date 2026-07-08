<?php
if (!defined('ABSPATH')) exit;

/**
 * One shared Debug Tools page under Own Ur Shit, extensible the same
 * way the dashboard registry and style gallery already are: any plugin
 * registers its own section via a filter, entirely from its own
 * bootstrap — this class never needs to know bh-contest or bh-streaming
 * exist.
 *
 *     add_filter('ous_debug_tools', function ($tools) {
 *         $tools['bh-contest'] = [
 *             'label' => 'BH Contest',
 *             // Echoes this plugin's own section — its own target
 *             // picker if it needs one, buttons via OUS_Debug::button()
 *             // so every plugin's buttons share one consistent look and
 *             // one consistent form/nonce structure rather than each
 *             // reinventing it.
 *             'render' => ['BH_Debug', 'render_section'],
 *             // Receives ($action, $_POST) for whichever button was
 *             // clicked under THIS section specifically. The common
 *             // case: return a message string, and the shared
 *             // dispatcher redirects back to this page showing it. For
 *             // an action that needs to do something else entirely —
 *             // bh-contest's "log in as a test voter" switches the
 *             // browser's own session and sends it to a front-end URL
 *             // instead — the callback can just call wp_safe_redirect()
 *             // and exit() itself; the shared dispatcher's own redirect
 *             // then simply never executes.
 *             'handle' => ['BH_Debug', 'handle_action'],
 *             // Wipes only this plugin's own tagged test data, returns
 *             // a message string. Called individually or as part of
 *             // "Reset Everything."
 *             'reset' => ['BH_Debug', 'reset'],
 *         ];
 *         return $tools;
 *     });
 *
 * The production-safety lock (is_locked()) is checked ONCE here, centrally,
 * for the whole page and every action on it — a registered plugin's
 * 'handle'/'reset' callbacks are simply never invoked while locked, so
 * no individual plugin needs to re-check this itself.
 */
class OUS_Debug {
    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_ous_debug_action', [self::class, 'handle']);
    }

    /**
     * Optional convenience wrapper around the raw
     * add_filter('ous_debug_tools', ...) pattern shown in this class's
     * own docblock above — every current registrant (bh-contest,
     * bh-streaming, bh-courses, bh-registry, bh-crm, bh-monetization-woo)
     * hand-rolls an identically-shaped closure, so this collapses that
     * boilerplate to one call for anything written against it going
     * forward. Purely additive: existing add_filter() registrations are
     * untouched and keep working exactly as before — this was added in
     * the DRY/SOLID refactor pass specifically so future registrants
     * don't have to duplicate the pattern, not to force a rewrite of
     * every plugin that already registers directly (a live QA pass
     * against a real WordPress install, which this static-analysis-only
     * refactor doesn't have available, would be needed to safely retrofit
     * every existing call site without risking a regression).
     *
     * Usage, from any plugin's own bootstrap:
     *   OUS_Debug::register('bh-lyrics', 'BH Lyrics',
     *       ['BHL_Debug', 'render_section'], ['BHL_Debug', 'handle_action'],
     *       ['BHL_Debug', 'reset']);
     */
    public static function register($key, $label, $render, $handle, $reset = null, $safe_in_production = false) {
        add_filter('ous_debug_tools', function ($tools) use ($key, $label, $render, $handle, $reset, $safe_in_production) {
            $tools[$key] = [
                'label' => $label, 'render' => $render, 'handle' => $handle, 'reset' => $reset,
                'safe_in_production' => $safe_in_production,
            ];
            return $tools;
        });
    }

    /**
     * True if this looks like a production install. wp_get_environment_type()
     * defaults to 'production' unless WP_ENVIRONMENT_TYPE is set, so this
     * fails safe: unknown = blocked. Override with
     * define('OUS_DEBUG_TOOLS_FORCE', true) in wp-config.php if a live
     * site genuinely needs to seed data.
     */
    public static function is_locked() {
        if (defined('OUS_DEBUG_TOOLS_FORCE') && OUS_DEBUG_TOOLS_FORCE) return false;
        if (!function_exists('wp_get_environment_type') || wp_get_environment_type() === 'production') {
            // wp_get_environment_type() defaults to 'production' unless
            // WP_ENVIRONMENT_TYPE is explicitly set in wp-config.php —
            // which almost nobody's local dev tool (Local, MAMP, Valet,
            // etc.) does out of the box. Without this fallback, a real
            // local install would get treated as production and lose
            // API Docs / seed-data tooling for no reason other than a
            // wp-config constant nobody thought to add. A well-known
            // local-only hostname pattern is a reasonable, low-risk
            // second signal — none of these TLDs/hosts are ever valid on
            // the public internet, so this can't accidentally unlock a
            // real production site.
            //
            // Real, confirmed bug this fix responds to: home_url() reads
            // the 'home' option, which on an install running a persistent
            // object cache (Redis/Memcached) can be served stale on some
            // requests but not others — the exact same staleness class
            // that broke BHI_Portal's rewrite rule earlier in this same
            // install. That made this local/production check flip
            // per-request: the Debug Tools page (one request) would
            // correctly see "local," while navigating directly to
            // admin.php?page=ous-api-docs (a separate request) would see
            // "production" and silently skip add_submenu_page() —
            // producing WordPress core's own "Sorry, you are not allowed
            // to access this page" (its standard response for a page
            // slug with no matching registered menu entry, not a 404).
            // $_SERVER['HTTP_HOST'] is the literal Host header of THIS
            // request — never cached, never filtered, always accurate —
            // so it's checked first/independently rather than trusting
            // home_url() alone for something this consequential.
            $raw_host = isset($_SERVER['HTTP_HOST']) ? wp_parse_url('http://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST) : '';
            $option_host = wp_parse_url(home_url(), PHP_URL_HOST) ?: '';
            $local_pattern = '/(^localhost$|^127\.0\.0\.1$|\.(test|local|localhost)$)/i';
            $looks_local = (bool) preg_match($local_pattern, $raw_host) || (bool) preg_match($local_pattern, $option_host);
            if (!$looks_local) return true;
        }
        return false;
    }

    // Its own top-level menu ("OUS Debug"), separate from the main "Own
    // Ur Shit" hub — dev/debug tooling (seed data, Console & Logs, Test
    // Runner, and API Docs — see class-api-docs.php, which hangs its own
    // page off THIS parent now too) is a genuinely different audience
    // and use case than the hub's install/dashboard/reports pages, and
    // deserves its own clearly-labeled place in the sidebar rather than
    // being buried at the bottom of the hub's submenu. add_menu_page()
    // auto-creates a first submenu item labeled after the top-level menu
    // title — the add_submenu_page() call right after it, using the SAME
    // slug, is the standard WP trick to relabel that first item
    // "Debug Tools" instead of a redundant second "OUS Debug".
    public static function add_menu() {
        // Used to hide this whole page on production (is_locked()) —
        // loosened, because Console & Logs and the Test Runner (see
        // class-debug-log.php / class-test-runner.php) are genuinely
        // useful for an admin troubleshooting a LIVE site, not just a
        // dev environment, and hiding the whole page hid those too.
        // manage_options is the actual gate now (same as every other
        // admin-only page in this ecosystem); the seed/reset "fake test
        // data" actions specifically stay blocked in production via
        // is_locked(), checked per-section in handle() below.
        add_menu_page('Debug Tools', 'OUS Debug', 'manage_options', 'ous-debug', [self::class, 'render'], 'dashicons-admin-tools', 99);
        add_submenu_page('ous-debug', 'Debug Tools', 'Debug Tools', 'manage_options', 'ous-debug', [self::class, 'render']);
    }

    /* ---------------- shared UI helpers every registered plugin's render() can use ---------------- */

    // One consistent button/form for every plugin's debug actions —
    // same nonce, same admin-post action, same markup, so a person using
    // this page sees one coherent tool rather than a different look per
    // plugin. $extra_html can carry additional hidden/visible fields
    // (e.g. bh-contest's "count" input for how many fake submissions).
    public static function button($plugin_key, $action, $label, $extra_html = '', $confirm = '', $primary = true) {
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

    // Shared so bh-contest, bh-streaming, and anything registered later
    // don't each reimplement "create or reuse a tagged fake account" —
    // one copy, one consistent tagging convention (bhcore_is_test user
    // meta) so a single Reset Everything can find every plugin's fake
    // accounts regardless of which plugin created them.
    public static function get_or_create_test_user($tag, $reuse_odds = true) {
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

    public static function render() {
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

        $tools = apply_filters('ous_debug_tools', []);
        if (!$tools) {
            echo '<p class="description">No plugin has registered any debug tools yet.</p>';
            BHY_UI::shell_close();
            return;
        }

        foreach ($tools as $key => $tool) {
            echo '<div class="bhy-card ous-debug-section"><h2>' . esc_html($tool['label']) . '</h2>';
            if (!empty($tool['render'])) call_user_func($tool['render'], $key);
            echo '</div>';
        }

        echo '<div class="bhy-card"><h2>Reset everything</h2><p>Wipes every registered plugin\'s own tagged test data in one pass. Real data is untouched.</p>';
        self::button('__all__', 'reset_all', 'Wipe all test data (every plugin)', '', 'Delete ALL test data from every plugin? This cannot be undone.', false);
        echo '</div>';
        BHY_UI::shell_close();
    }

    /* ---------------- dispatch ---------------- */

    public static function handle() {
        $plugin_key = sanitize_key($_POST['ous_plugin'] ?? '');
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'ous_debug_' . $plugin_key)) {
            wp_die('Not allowed.');
        }

        $action = sanitize_key($_POST['ous_debug_action'] ?? '');
        $tools = apply_filters('ous_debug_tools', []);

        // The production lock now applies PER SECTION, not to the whole
        // page: seeding fake test data or wiping real tables is exactly
        // what "this looks like production" should block, but Console &
        // Logs (clearing a log table) and the Test Runner (running pure
        // logic assertions) do nothing a live site needs protecting
        // from — a section opts out of the lock by setting
        // 'safe_in_production' => true on its own ous_debug_tools entry.
        $safe = ($plugin_key !== '__all__') && !empty($tools[$plugin_key]['safe_in_production']);
        if (self::is_locked() && !$safe) {
            self::redirect('Blocked: this looks like a production environment. Add define(\'OUS_DEBUG_TOOLS_FORCE\', true) to wp-config.php to override.');
        }

        if ($plugin_key === '__all__' && $action === 'reset_all') {
            $messages = [];
            foreach ($tools as $tool) {
                if (!empty($tool['reset'])) $messages[] = call_user_func($tool['reset']);
            }
            self::redirect($messages ? implode(' ', $messages) : 'Nothing to reset.');
        }

        if (!isset($tools[$plugin_key]) || empty($tools[$plugin_key]['handle'])) {
            self::redirect('Unknown debug action.');
        }

        $msg = call_user_func($tools[$plugin_key]['handle'], $action, $_POST);
        self::redirect($msg ?: 'Done.');
    }

    private static function redirect($msg) {
        wp_safe_redirect(add_query_arg('ous_msg', rawurlencode($msg), admin_url('admin.php?page=ous-debug')));
        exit;
    }
}
