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
     * True if this looks like a production install. wp_get_environment_type()
     * defaults to 'production' unless WP_ENVIRONMENT_TYPE is set, so this
     * fails safe: unknown = blocked. Override with
     * define('OUS_DEBUG_TOOLS_FORCE', true) in wp-config.php if a live
     * site genuinely needs to seed data.
     */
    public static function is_locked() {
        if (defined('OUS_DEBUG_TOOLS_FORCE') && OUS_DEBUG_TOOLS_FORCE) return false;
        return !function_exists('wp_get_environment_type') || wp_get_environment_type() === 'production';
    }

    public static function add_menu() {
        // No point showing a menu item whose every action is already
        // blocked by is_locked() — that's just clutter on a live site.
        if (self::is_locked()) return;
        add_submenu_page('own-ur-shit', 'Debug Tools', '🛠 Debug Tools', 'manage_options', 'ous-debug', [self::class, 'render']);
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

        if (self::is_locked()) {
            self::redirect('Blocked: this looks like a production environment. Add define(\'OUS_DEBUG_TOOLS_FORCE\', true) to wp-config.php to override.');
        }

        $action = sanitize_key($_POST['ous_debug_action'] ?? '');
        $tools = apply_filters('ous_debug_tools', []);

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
