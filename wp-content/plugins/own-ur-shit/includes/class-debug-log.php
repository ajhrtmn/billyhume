<?php
if (!defined('ABSPATH')) exit;

/**
 * The "aggregate console" the whole ecosystem was missing: one place
 * that catches PHP fatals, WordPress's own doing_it_wrong()/deprecated
 * notices, anything any plugin explicitly logs via OUS_DebugLog::log(),
 * AND front-end/admin JS errors — all landing in one table, visible on
 * the same Debug Tools page as everything else, with zero CLI and zero
 * separate log-file hunting.
 *
 * USAGE, from any plugin (core or peer, all class_exists()-guarded the
 * same as OUS_Jobs/OUS_Notifications):
 *
 *   if (class_exists('OUS_DebugLog')) {
 *       OUS_DebugLog::log('error', 'Feed sync failed', ['feed_id' => $id, 'error' => $e->getMessage()], 'BH Streaming');
 *   }
 *
 * Levels: 'error', 'warning', 'info' — kept to three, not a full PSR-3
 * ladder, since this is a triage console for a solo dev/small team, not
 * a production observability platform. Table is capped at
 * MAX_ROWS via opportunistic trimming on insert (no separate cron job
 * needed just to keep this small).
 */
class OUS_DebugLog {
    const MAX_ROWS = 1000;

    public static function init() {
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
        add_action('admin_enqueue_scripts', [self::class, 'maybe_enqueue_js_capture']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue_js_capture']);
        add_action('wp_ajax_ous_log_js_error', [self::class, 'ajax_log_js_error']);

        // WordPress's own two "something's wrong" signals — catching
        // these here means an old/incompatible add_action() signature
        // or a since-removed function call anywhere in the ecosystem
        // shows up on this page instead of silently in a PHP error log
        // nobody's tailing.
        add_action('doing_it_wrong_run', function ($function_name, $message) {
            self::log('warning', "doing_it_wrong: $function_name — $message", [], 'WordPress');
        }, 10, 2);
        add_action('deprecated_function_run', function ($function_name, $replacement) {
            self::log('warning', "Deprecated function: $function_name" . ($replacement ? " (use $replacement instead)" : ''), [], 'WordPress');
        }, 10, 2);

        // Fatal errors don't fire normal WordPress hooks (the request is
        // already dying) — a shutdown function is the one reliable place
        // to still catch and record one before the process actually
        // ends.
        register_shutdown_function([self::class, 'capture_fatal_on_shutdown']);
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhcore_debug_log';
    }

    public static function log($level, $message, $context = [], $source = '') {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'level' => sanitize_key($level) ?: 'info',
            'source' => sanitize_text_field($source),
            'message' => (string) $message,
            'context' => $context ? wp_json_encode($context) : '',
            'created_at' => current_time('mysql', true),
        ]);
        self::maybe_trim();
    }

    // Opportunistic, not scheduled — every ~50th write pays the tiny
    // cost of a COUNT+DELETE so this table never needs its own cron job
    // just to stay bounded.
    private static function maybe_trim() {
        if (wp_rand(1, 50) !== 1) return;
        global $wpdb;
        $table = self::table();
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        if ($count > self::MAX_ROWS) {
            $excess = $count - self::MAX_ROWS;
            $wpdb->query($wpdb->prepare("DELETE FROM $table ORDER BY id ASC LIMIT %d", $excess));
        }
    }

    public static function capture_fatal_on_shutdown() {
        $error = error_get_last();
        if (!$error) return;
        $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($error['type'], $fatal_types, true)) return;

        // wpdb may or may not still be usable this late in shutdown —
        // guard defensively so a failed fatal-log attempt never becomes
        // a second, more confusing fatal of its own.
        try {
            self::log('error', 'PHP fatal: ' . $error['message'], ['file' => $error['file'], 'line' => $error['line']], 'PHP');
        } catch (\Throwable $e) {
            // Nothing more we can do here — the original fatal is the
            // one that matters, and we're already in shutdown.
        }
    }

    /* ---------------- front-end/admin JS error capture ---------------- */

    // Only for logged-in users who can see Debug Tools anyway — a
    // real site visitor's browser quirks aren't this console's job, and
    // this avoids adding any JS payload or AJAX traffic to anonymous
    // front-end requests.
    public static function maybe_enqueue_js_capture() {
        if (!is_user_logged_in() || !current_user_can('manage_options')) return;
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('ous_log_js_error');
        $js = "window.addEventListener('error', function(e){"
            . "try{var d=new FormData();d.append('action','ous_log_js_error');d.append('_wpnonce','" . esc_js($nonce) . "');"
            . "d.append('message',e.message||'');d.append('source',(e.filename||'')+':'+(e.lineno||''));"
            . "navigator.sendBeacon ? navigator.sendBeacon('" . esc_js($ajax_url) . "', d) : fetch('" . esc_js($ajax_url) . "',{method:'POST',body:d,credentials:'same-origin'});"
            . "}catch(err){}"
            . "});";
        wp_register_script('ous-debug-log-js-capture', false, [], OUS_VER, true);
        wp_enqueue_script('ous-debug-log-js-capture');
        wp_add_inline_script('ous-debug-log-js-capture', $js);
    }

    public static function ajax_log_js_error() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'ous_log_js_error')) {
            wp_send_json_error('', 403);
        }
        self::log(
            'error',
            sanitize_text_field($_POST['message'] ?? '(no message)'),
            ['location' => sanitize_text_field($_POST['source'] ?? '')],
            'JavaScript'
        );
        wp_send_json_success();
    }

    /* ---------------- Debug Tools page section ---------------- */

    public static function register_debug_section($tools) {
        $tools['bh-console'] = [
            'label' => 'Console & Logs',
            'render' => [self::class, 'render_debug_section'],
            'handle' => [self::class, 'handle_debug_action'],
            'reset' => [self::class, 'reset_debug'],
            // Clearing a log table is nothing like seeding fake data on
            // a live site — an admin troubleshooting a real production
            // issue needs this to work there, not just in dev.
            'safe_in_production' => true,
        ];
        return $tools;
    }

    public static function render_debug_section() {
        $level_filter = isset($_GET['ous_log_level']) ? sanitize_key($_GET['ous_log_level']) : '';
        global $wpdb;
        $table = self::table();

        echo '<form method="get" style="margin-bottom:12px;">';
        foreach (['page' => 'ous-debug'] as $k => $v) echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        echo '<select name="ous_log_level" onchange="this.form.submit()">';
        echo '<option value="">All levels</option>';
        foreach (['error', 'warning', 'info'] as $lvl) {
            echo '<option value="' . esc_attr($lvl) . '"' . selected($level_filter, $lvl, false) . '>' . esc_html(ucfirst($lvl)) . '</option>';
        }
        echo '</select></form>';

        if ($level_filter) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE level = %s ORDER BY id DESC LIMIT 100", $level_filter), ARRAY_A);
        } else {
            $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 100", ARRAY_A);
        }

        if (!$rows) {
            echo '<p class="description">No log entries yet' . ($level_filter ? " at level \"$level_filter\"" : '') . '. Nothing to triage — that\'s a good sign.</p>';
        } else {
            $colors = ['error' => '#d63638', 'warning' => '#dba617', 'info' => '#2271b1'];
            echo '<div class="bhy-table-wrap"><table class="widefat striped"><thead><tr><th style="width:90px;">Level</th><th style="width:120px;">Source</th><th>Message</th><th style="width:140px;">When</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $color = $colors[$r['level']] ?? '#646970';
                echo '<tr><td><span style="color:#fff;background:' . esc_attr($color) . ';padding:2px 8px;border-radius:3px;font-size:11px;">' . esc_html(strtoupper($r['level'])) . '</span></td>';
                echo '<td>' . esc_html($r['source'] ?: '&#8212;') . '</td>';
                echo '<td>' . esc_html($r['message']) . ($r['context'] ? ' <code style="font-size:11px;color:#646970;">' . esc_html($r['context']) . '</code>' : '') . '</td>';
                echo '<td>' . esc_html(human_time_diff(strtotime($r['created_at']), current_time('timestamp')) . ' ago') . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '<p style="margin-top:8px;">';
        OUS_Debug::button('bh-console', 'clear_log', 'Clear log', '', 'Clear all logged console/error entries? This cannot be undone.', false);
        echo '</p>';
    }

    public static function handle_debug_action($action) {
        if ($action === 'clear_log') {
            global $wpdb;
            $wpdb->query("TRUNCATE TABLE " . self::table());
            return 'Console/error log cleared.';
        }
        return 'Unknown action.';
    }

    public static function reset_debug() {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE " . self::table());
        return 'Console/error log cleared.';
    }
}
