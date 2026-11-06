<?php
if (!defined('ABSPATH')) exit;

/**
 * Aggregate console: PHP fatals/uncaught exceptions, WordPress
 * doing_it_wrong/deprecated notices, explicit log() calls, and admin JS
 * errors land in one capped table shown on the Debug Tools page.
 *
 *   if (class_exists('OUS_DebugLog')) {
 *       OUS_DebugLog::log('error', 'Feed sync failed', ['feed_id' => $id], 'BH Streaming');
 *       OUS_DebugLog::log_exception($e, 'BH Streaming');
 *   }
 *
 * Levels: 'error', 'warning', 'info'.
 */
class OUS_DebugLog {
    const MAX_ROWS = 1000;

    public static function init(): void {
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
        add_action('admin_enqueue_scripts', [self::class, 'maybe_enqueue_js_capture']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue_js_capture']);
        add_action('wp_ajax_ous_log_js_error', [self::class, 'ajax_log_js_error']);

        add_action('doing_it_wrong_run', function ($function_name, $message) {
            self::log('warning', "doing_it_wrong: $function_name — $message", [], 'WordPress');
        }, 10, 2);
        add_action('deprecated_function_run', function ($function_name, $replacement) {
            self::log('warning', "Deprecated function: $function_name" . ($replacement ? " (use $replacement instead)" : ''), [], 'WordPress');
        }, 10, 2);

        // Chains to any previously installed handler rather than replacing it.
        $previous_exception_handler = set_exception_handler([self::class, 'capture_uncaught_exception']);
        self::$previous_exception_handler = $previous_exception_handler;

        // Fatals bypass hooks and the exception handler; shutdown still runs.
        register_shutdown_function([self::class, 'capture_fatal_on_shutdown']);
    }

    /** @var callable|null */
    private static $previous_exception_handler = null;

    private static function table(): string {
        return OUS_Tables::debug_log();
    }

    // Short random ID stamped on every row logged during one PHP request,
    // so Console & Logs can filter to "everything from this request".
    /** @var string|null */
    private static $request_id = null;

    public static function request_id(): string {
        if (self::$request_id === null) {
            self::$request_id = substr(wp_generate_uuid4(), 0, 8);
        }
        return self::$request_id;
    }

    // Real SHOW COLUMNS check, cached per request.
    /** @var bool|null */
    private static $has_request_id_column = null;

    private static function has_request_id_column(): bool {
        if (self::$has_request_id_column === null) {
            global $wpdb;
            $table = self::table();
            $col = $wpdb->get_var($wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'request_id' LIMIT 1",
                DB_NAME, $table
            ));
            self::$has_request_id_column = ($col === 'request_id');
        }
        return self::$has_request_id_column;
    }

    /**
     * $trace uses the debug_backtrace()/Throwable::getTrace() frame shape.
     * $file/$line/$col is the row's headline location.
     */
    /**
     * @param mixed $message
     * @param array<mixed> $context
     * @param array<mixed>|null $trace
     */
    public static function log(string $level, $message, array $context = [], string $source = '', string $file = '', int $line = 0, int $col = 0, ?array $trace = null): void {
        global $wpdb;
        $row = [
            'level' => sanitize_key($level) ?: 'info',
            'source' => sanitize_text_field($source),
            'message' => (string) $message,
            'context' => $context ? wp_json_encode($context) : '',
            'file' => sanitize_text_field($file),
            'line' => (int) $line,
            'col' => (int) $col,
            'trace' => $trace ? wp_json_encode($trace) : '',
            'url' => self::current_url(),
            'request_method' => isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field($_SERVER['REQUEST_METHOD']) : '',
            'user_id' => get_current_user_id(),
            'request_id' => self::request_id(),
            'created_at' => current_time('mysql', true),
        ];
        // WHY: request_id came in a later schema version; omitting it keeps
        // logging alive on a not-yet-migrated install.
        if (!self::has_request_id_column()) unset($row['request_id']);
        $ok = $wpdb->insert(self::table(), $row);
        // Insert failures are recorded in an option so the health check can
        // report broken logging, which can't be logged the normal way.
        if ($ok === false && $wpdb->last_error) {
            update_option('ous_debug_log_last_failure', [
                'error' => $wpdb->last_error,
                'table' => self::table(),
                'at' => current_time('mysql', true),
            ], false);
        }
        self::maybe_trim();

        // Per-request buffer surfaced by class-qm-integration.php in Query Monitor.
        self::$request_buffer[] = $row;
    }

    /** @var array<int, array<string, mixed>> */
    private static $request_buffer = [];

    /** Read-only accessor for class-qm-integration.php. */
    /** @return array<int, array<string, mixed>> */
    public static function request_buffer(): array {
        return self::$request_buffer;
    }

    // Logs at most once per $seconds per $key, so per-request checks leave a
    // trace without flooding the table.
    // WHY: throttle key goes straight to wp_options via $wpdb, not
    // transients, which can wedge on a broken persistent object cache.
    /** @param array<mixed> $context */
    public static function log_throttled(string $level, string $key, int $seconds, string $message, array $context = [], string $source = ''): void {
        global $wpdb;
        $option_name = 'ous_log_throttle_' . sanitize_key($key);
        $last = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option_name));
        if ($last && (time() - (int) $last) < $seconds) return;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            $option_name, (string) time()
        ));
        wp_cache_delete($option_name, 'options');
        wp_cache_delete('alloptions', 'options');
        self::log($level, $message, $context, $source);
    }

    public static function log_exception(\Throwable $e, string $source = '', string $level = 'error'): void {
        self::log(
            $level,
            get_class($e) . ': ' . $e->getMessage(),
            [],
            $source,
            $e->getFile(),
            $e->getLine(),
            0, // PHP's own trace frames don't carry column info — file+line is the real granularity PHP exposes
            $e->getTrace()
        );
    }

    public static function capture_uncaught_exception(\Throwable $e): void {
        try {
            self::log_exception($e, 'PHP (uncaught)');
        } catch (\Throwable $inner) {
            // Logging must never become a second uncaught exception.
        }
        if (is_callable(self::$previous_exception_handler)) {
            call_user_func(self::$previous_exception_handler, $e);
        }
    }

    private static function current_url(): string {
        if (empty($_SERVER['HTTP_HOST']) || empty($_SERVER['REQUEST_URI'])) return '';
        $scheme = is_ssl() ? 'https' : 'http';
        return esc_url_raw($scheme . '://' . sanitize_text_field($_SERVER['HTTP_HOST']) . sanitize_text_field($_SERVER['REQUEST_URI']));
    }

    // Opportunistic trim on ~1 in 50 writes; no cron needed.
    private static function maybe_trim(): void {
        if (wp_rand(1, 50) !== 1) return;
        global $wpdb;
        $table = self::table();
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        if ($count > self::MAX_ROWS) {
            $excess = $count - self::MAX_ROWS;
            $wpdb->query($wpdb->prepare("DELETE FROM $table ORDER BY id ASC LIMIT %d", $excess));
        }
    }

    public static function capture_fatal_on_shutdown(): void {
        $error = error_get_last();
        if (!$error) return;
        $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($error['type'], $fatal_types, true)) return;

        // WHY: wpdb may be unusable this late; guard so this never becomes a
        // second fatal. error_get_last() gives file/line only, no trace.
        try {
            self::log('error', 'PHP fatal: ' . $error['message'], [], 'PHP', $error['file'], $error['line']);
        } catch (\Throwable $e) {
        }
    }

    /* ---------------- front-end/admin JS error capture ---------------- */

    // Admins only; no JS payload or AJAX traffic for anonymous visitors.
    public static function maybe_enqueue_js_capture(): void {
        if (!is_user_logged_in() || !current_user_can('manage_options')) return;
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('ous_log_js_error');
        // Captures uncaught errors, unhandled promise rejections, and
        // console.error/warn calls, with error.stack parsed into frames.
        $js = "(function(){"
            . "var AJAX_URL='" . esc_js($ajax_url) . "', NONCE='" . esc_js($nonce) . "';"
            . "function parseStack(stack){"
            . "  if(!stack) return [];"
            . "  return stack.split('\\n').slice(1).map(function(line){"
            . "    var m=line.match(/at (?:(.*?) \\()?(?:(.+?):(\\d+):(\\d+))\\)?\$/);"
            . "    if(!m) return {raw: line.trim()};"
            . "    return {function: m[1]||'(anonymous)', file: m[2]||'', line: m[3]?parseInt(m[3],10):0, col: m[4]?parseInt(m[4],10):0};"
            . "  }).filter(function(f){return f.file || f.raw;});"
            . "}"
            . "function send(payload){"
            . "  try{"
            . "    var d=new FormData();"
            . "    d.append('action','ous_log_js_error');d.append('_wpnonce',NONCE);"
            . "    d.append('message',payload.message||'');"
            . "    d.append('file',payload.file||'');d.append('line',payload.line||0);d.append('col',payload.col||0);"
            . "    d.append('trace',JSON.stringify(payload.trace||[]));"
            . "    d.append('level',payload.level||'error');"
            . "    navigator.sendBeacon ? navigator.sendBeacon(AJAX_URL, d) : fetch(AJAX_URL,{method:'POST',body:d,credentials:'same-origin'});"
            . "  }catch(err){}"
            . "}"
            . "window.addEventListener('error', function(e){"
            . "  var err=e.error;"
            . "  send({message: e.message||'', file: e.filename||(err&&err.fileName)||'', line: e.lineno||0, col: e.colno||0, trace: err&&err.stack?parseStack(err.stack):[], level:'error'});"
            . "});"
            . "window.addEventListener('unhandledrejection', function(e){"
            . "  var reason=e.reason;"
            . "  var msg = (reason && (reason.message||reason.toString&&reason.toString())) || 'Unhandled promise rejection';"
            . "  send({message:'Unhandled rejection: '+msg, trace: reason&&reason.stack?parseStack(reason.stack):[], level:'error'});"
            . "});"
            . "['error','warn'].forEach(function(method){"
            . "  var original = console[method];"
            . "  console[method] = function(){"
            . "    try{"
            . "      var args=Array.prototype.slice.call(arguments);"
            . "      var msg=args.map(function(a){try{return typeof a==='string'?a:JSON.stringify(a);}catch(e){return String(a);}}).join(' ');"
            . "      send({message: msg, trace: parseStack((new Error()).stack), level: method==='error'?'error':'warning'});"
            . "    }catch(err){}"
            . "    return original.apply(console, arguments);"
            . "  };"
            . "});"
            . "})();";
        wp_register_script('ous-debug-log-js-capture', false, [], OUS_VER, true);
        wp_enqueue_script('ous-debug-log-js-capture');
        wp_add_inline_script('ous-debug-log-js-capture', $js);
    }

    public static function ajax_log_js_error(): void {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_POST['_wpnonce'] ?? '', 'ous_log_js_error')) {
            wp_send_json_error('', 403);
        }
        $level = sanitize_key($_POST['level'] ?? 'error');
        if (!in_array($level, ['error', 'warning', 'info'], true)) $level = 'error';

        $trace_raw = $_POST['trace'] ?? '';
        $trace = null;
        if ($trace_raw) {
            $decoded = json_decode(wp_unslash($trace_raw), true);
            if (is_array($decoded)) $trace = $decoded;
        }

        self::log(
            $level,
            sanitize_text_field($_POST['message'] ?? '(no message)'),
            [],
            'JavaScript',
            sanitize_text_field($_POST['file'] ?? ''),
            (int) ($_POST['line'] ?? 0),
            (int) ($_POST['col'] ?? 0),
            $trace
        );
        wp_send_json_success();
    }

    /* ---------------- Debug Tools page section ---------------- */

    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register_debug_section($tools): array {
        $tools['bh-console'] = [
            'label' => 'Console & Logs',
            'render' => [self::class, 'render_debug_section'],
            'handle' => [self::class, 'handle_debug_action'],
            'reset' => [self::class, 'reset_debug'],
            // Clearing a log is safe on a live site.
            'safe_in_production' => true,
            'group' => OUS_Debug::GROUP_MONITORING,
        ];
        return $tools;
    }

    // Mutes by exact (source, message) pair, keyed by md5. Muted rows are
    // still written; they are only hidden from the default view.
    /** @return array<string, mixed> */
    private static function muted_signatures(): array {
        $muted = get_option('ous_debug_log_muted', []);
        return is_array($muted) ? $muted : [];
    }

    private static function mute_signature(string $source, string $message): void {
        $muted = self::muted_signatures();
        $key = md5($source . '|' . $message);
        $muted[$key] = [
            'source' => $source, 'message' => $message,
            'muted_at' => current_time('mysql'),
            'muted_by' => wp_get_current_user()->user_login ?? '',
        ];
        update_option('ous_debug_log_muted', $muted, false);
    }

    private static function unmute_signature(string $key): void {
        $muted = self::muted_signatures();
        unset($muted[$key]);
        update_option('ous_debug_log_muted', $muted, false);
    }

    // Shared by the row query and the copy dump so they never drift apart.
    /** @return array<string, mixed> */
    private static function build_filters(): array {
        $where = [];
        $params = [];

        // ous_log_show_muted=1 bypasses the mute filter.
        $show_muted = isset($_GET['ous_log_show_muted']) && $_GET['ous_log_show_muted'] === '1';
        if (!$show_muted) {
            foreach (self::muted_signatures() as $m) {
                $where[] = '(source != %s OR message != %s)';
                $params[] = $m['source'];
                $params[] = $m['message'];
            }
        }

        $level = isset($_GET['ous_log_level']) ? sanitize_key($_GET['ous_log_level']) : '';
        if ($level) { $where[] = 'level = %s'; $params[] = $level; }

        $source = isset($_GET['ous_log_source']) ? sanitize_text_field($_GET['ous_log_source']) : '';
        if ($source) { $where[] = 'source = %s'; $params[] = $source; }

        $user_id = isset($_GET['ous_log_user']) ? absint($_GET['ous_log_user']) : 0;
        if ($user_id) { $where[] = 'user_id = %d'; $params[] = $user_id; }

        $since = isset($_GET['ous_log_since']) ? sanitize_text_field($_GET['ous_log_since']) : '';
        if ($since && preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) { $where[] = 'created_at >= %s'; $params[] = $since . ' 00:00:00'; }

        $request_id = isset($_GET['ous_log_request']) ? sanitize_text_field($_GET['ous_log_request']) : '';
        if ($request_id) { $where[] = 'request_id = %s'; $params[] = $request_id; }

        return [
            'level' => $level, 'source' => $source, 'user_id' => $user_id, 'since' => $since, 'request_id' => $request_id,
            'show_muted' => $show_muted,
            'where_sql' => $where ? ('WHERE ' . implode(' AND ', $where)) : '',
            'params' => $params,
        ];
    }

    // Answers "is logging itself working": table exists, required columns
    // present, schema version matches, and last recorded insert failure.
    private static function health_check(): void {
        global $wpdb;
        self::print_log_styles_once();
        $table = self::table();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;

        $required_cols = ['id', 'level', 'source', 'message', 'context', 'file', 'line', 'col', 'trace', 'url', 'request_method', 'user_id', 'request_id', 'created_at'];
        $missing_cols = [];
        if ($exists) {
            $existing_cols = $wpdb->get_col("SHOW COLUMNS FROM $table", 0);
            $missing_cols = array_diff($required_cols, $existing_cols);
        }

        $db_version_stored = get_option('bhi_db_version', '(not set)');
        $db_version_code = class_exists('BHI_Activator') && defined('BHI_Activator::DB_VERSION') ? BHI_Activator::DB_VERSION : '(unknown)';

        $last_failure = get_option('ous_debug_log_last_failure', null);

        echo '<div class="bhy-card" style="margin-bottom:16px;">';
        echo '<h4 style="margin-top:0;">Logging health check</h4>';
        echo '<p>Table <code>' . esc_html($table) . '</code>: ' . ($exists ? '<span class="ous-log-ok">exists</span>' : '<span class="ous-log-bad-strong">does NOT exist — no log() call anywhere in the ecosystem can be recorded</span>') . '</p>';
        if ($exists && $missing_cols) {
            echo '<p class="ous-log-bad-strong">Missing column(s): ' . esc_html(implode(', ', $missing_cols)) . ' — the table exists but predates the current schema, so every insert() referencing these columns fails.</p>';
        }
        echo '<p>DB schema version — stored: <code>' . esc_html($db_version_stored) . '</code>, code expects: <code>' . esc_html($db_version_code) . '</code>'
           . ($db_version_stored !== $db_version_code ? ' <span class="ous-log-bad-strong">— mismatch, migration has not run on this install</span>' : ' <span class="ous-log-ok">— up to date</span>')
           . '</p>';
        if ($last_failure && is_array($last_failure)) {
            echo '<p class="ous-log-bad"><strong>Last recorded insert failure</strong> (' . esc_html($last_failure['at'] ?? '') . '): <code>' . esc_html($last_failure['error'] ?? '') . '</code></p>';
        }
        OUS_Debug::button('bh-console', 'health_check_insert', 'Run a test log entry now');
        echo '</div>';
    }

    public static function render_debug_section(): void {
        global $wpdb;
        $table = self::table();
        self::health_check();
        $filters = self::build_filters();

        $known_sources = $wpdb->get_col("SELECT DISTINCT source FROM $table WHERE source != '' ORDER BY source ASC");

        echo '<form method="get" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">';
        echo '<input type="hidden" name="page" value="ous-debug">';

        echo '<select name="ous_log_level" onchange="this.form.submit()">';
        echo '<option value="">All levels</option>';
        foreach (['error', 'warning', 'info'] as $lvl) {
            echo '<option value="' . esc_attr($lvl) . '"' . selected($filters['level'], $lvl, false) . '>' . esc_html(ucfirst($lvl)) . '</option>';
        }
        echo '</select>';

        echo '<select name="ous_log_source" onchange="this.form.submit()">';
        echo '<option value="">All sources</option>';
        foreach ($known_sources as $src) {
            echo '<option value="' . esc_attr($src) . '"' . selected($filters['source'], $src, false) . '>' . esc_html($src) . '</option>';
        }
        echo '</select>';

        echo '<input type="number" min="1" name="ous_log_user" placeholder="User ID" value="' . esc_attr($filters['user_id'] ?: '') . '" style="width:100px;">';
        echo '<input type="date" name="ous_log_since" value="' . esc_attr($filters['since']) . '">';
        echo '<input type="text" name="ous_log_request" placeholder="Request ID" value="' . esc_attr($filters['request_id']) . '" style="width:110px;font-family:monospace;">';
        if ($filters['show_muted']) echo '<input type="hidden" name="ous_log_show_muted" value="1">';
        echo '<button type="submit" class="button">Filter</button>';
        if ($filters['level'] || $filters['source'] || $filters['user_id'] || $filters['since'] || $filters['request_id']) {
            echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=ous-debug' . ($filters['show_muted'] ? '&ous_log_show_muted=1' : ''))) . '">Clear filters</a>';
        }
        if ($filters['request_id']) {
            echo '<p class="description" style="width:100%;margin:4px 0 0;">Showing every logged event that happened during request <code>' . esc_html($filters['request_id']) . '</code> — the full sequence, across every plugin, that led up to (or followed from) any one row.</p>';
        }
        echo '</form>';

        // Muted list with a toggle to show muted rows again.
        $muted = self::muted_signatures();
        if ($muted) {
            $toggle_url = admin_url('admin.php?page=ous-debug' . ($filters['show_muted'] ? '' : '&ous_log_show_muted=1'));
            echo '<details class="bhy-rail-section" style="margin-bottom:12px;"><summary style="cursor:pointer;">Muted (' . count($muted) . ') — '
               . '<a href="' . esc_url($toggle_url) . '" onclick="event.stopPropagation();">' . ($filters['show_muted'] ? 'hide muted rows in the table below' : 'show muted rows in the table below') . '</a></summary>';
            echo '<table class="widefat striped" style="margin-top:8px;"><thead><tr><th>Source</th><th>Message</th><th style="width:140px;">Muted</th><th style="width:80px;"></th></tr></thead><tbody>';
            foreach ($muted as $key => $m) {
                echo '<tr><td>' . esc_html($m['source'] ?: '&#8212;') . '</td><td>' . esc_html($m['message']) . '</td>';
                echo '<td>' . esc_html(human_time_diff(strtotime($m['muted_at']), current_time('timestamp')) . ' ago by ' . ($m['muted_by'] ?: 'unknown')) . '</td>';
                echo '<td>';
                OUS_Debug::button('bh-console', 'unmute_log_signature', 'Unmute', "<input type='hidden' name='ous_log_signature' value='" . esc_attr($key) . "'>", '', false);
                echo '</td></tr>';
            }
            echo '</tbody></table></details>';
        }

        $rows = $wpdb->get_results(
            $filters['params']
                ? $wpdb->prepare("SELECT * FROM $table {$filters['where_sql']} ORDER BY id DESC LIMIT 100", $filters['params'])
                : "SELECT * FROM $table {$filters['where_sql']} ORDER BY id DESC LIMIT 100",
            ARRAY_A
        );

        self::print_copy_script_once();
        self::print_expand_script_once();

        if (!$rows) {
            echo '<p class="description">No log entries match these filters. Nothing to triage — that\'s a good sign.</p>';
        } else {
            // Plain-text dump of the visible rows, for pasting into a ticket.
            $dump_lines = [];
            foreach ($rows as $r) {
                $location = $r['file'] ? (' (' . $r['file'] . ':' . $r['line'] . ($r['col'] ? ':' . $r['col'] : '') . ')') : '';
                $line = '[' . strtoupper($r['level']) . ']' . (!empty($r['request_id']) ? '[req:' . $r['request_id'] . ']' : '') . ' ' . ($r['source'] ?: '(no source)') . ' — ' . $r['message'] . $location;
                if ($r['context']) $line .= ' ' . $r['context'];
                if ($r['url']) $line .= ' [' . $r['request_method'] . ' ' . $r['url'] . ']';
                if ($r['trace']) $line .= ' trace=' . $r['trace'];
                $line .= ' (' . $r['created_at'] . ')';
                $dump_lines[] = $line;
            }
            echo '<p><button type="button" class="button button-small" onclick="bhCopyToClipboard(\'ous-log-dump\', this)">Copy ' . count($rows) . ' log ' . (count($rows) === 1 ? 'entry' : 'entries') . '</button></p>';
            echo '<textarea id="ous-log-dump" style="position:absolute;left:-9999px;">' . esc_textarea(implode("\n", $dump_lines)) . '</textarea>';

            echo '<div class="bhy-table-wrap"><table class="widefat striped"><thead><tr><th style="width:90px;">Level</th><th style="width:120px;">Source</th><th>Message</th><th style="width:80px;">User</th><th style="width:140px;">When</th><th style="width:130px;">Actions</th></tr></thead><tbody>';
            $i = 0;
            foreach ($rows as $r) {
                $i++;
                $has_detail = $r['trace'] || $r['file'] || $r['url'];
                $detail_id = 'ous-log-detail-' . $i;

                echo '<tr' . ($has_detail ? ' style="cursor:pointer;" onclick="bhToggleLogDetail(\'' . esc_js($detail_id) . '\')"' : '') . '>';
                echo '<td><span class="ous-log-level-pill" data-level="' . esc_attr($r['level']) . '">' . esc_html(strtoupper($r['level'])) . '</span></td>';
                echo '<td>' . esc_html($r['source'] ?: '&#8212;') . '</td>';
                echo '<td>' . esc_html($r['message']);
                if ($r['file']) {
                    echo ' <code class="ous-log-meta">' . esc_html($r['file'] . ':' . $r['line'] . ($r['col'] ? ':' . $r['col'] : '')) . '</code>';
                }
                // stopPropagation: the <tr> has its own toggle onclick.
                if (!empty($r['request_id'])) {
                    $req_url = admin_url('admin.php?page=ous-debug&ous_log_request=' . rawurlencode($r['request_id']));
                    echo ' <a href="' . esc_url($req_url) . '" onclick="event.stopPropagation();" title="Show every log entry from this same request" class="ous-log-req-chip">#' . esc_html($r['request_id']) . '</a>';
                }
                if ($has_detail) echo ' <span class="ous-log-details-hint">[details &#9662;]</span>';
                echo '</td>';
                echo '<td>' . ($r['user_id'] ? esc_html(get_userdata($r['user_id']) ? get_userdata($r['user_id'])->user_login : ('#' . $r['user_id'])) : '&#8212;') . '</td>';
                echo '<td>' . esc_html(human_time_diff(strtotime($r['created_at']), current_time('timestamp')) . ' ago') . '</td>';
                // stopPropagation: keeps button clicks from toggling the detail row.
                echo '<td onclick="event.stopPropagation();" style="white-space:nowrap;">';
                OUS_Debug::button('bh-console', 'delete_log_row', 'Delete', "<input type='hidden' name='ous_log_row_id' value='" . esc_attr($r['id']) . "'>", 'Delete this one log entry? This cannot be undone.', false);
                OUS_Debug::button('bh-console', 'mute_log_signature', 'Mute', "<input type='hidden' name='ous_log_row_id' value='" . esc_attr($r['id']) . "'>", '', false);
                echo '</td></tr>';

                if ($has_detail) {
                    echo '<tr id="' . esc_attr($detail_id) . '" class="ous-log-detail-row" style="display:none;"><td colspan="6">';
                    if ($r['url']) echo '<p style="margin:4px 0;"><strong>Request:</strong> ' . esc_html($r['request_method']) . ' <code>' . esc_html($r['url']) . '</code></p>';
                    if ($r['context']) echo '<p style="margin:4px 0;"><strong>Context:</strong> <code>' . esc_html($r['context']) . '</code></p>';
                    if ($r['trace']) {
                        $trace = json_decode($r['trace'], true);
                        echo '<p style="margin:4px 0;"><strong>Stack trace:</strong></p><ol style="margin:0 0 0 20px;font-family:monospace;font-size:12px;">';
                        if (is_array($trace)) {
                            foreach ($trace as $frame) {
                                $fn = $frame['function'] ?? '';
                                $cls = $frame['class'] ?? '';
                                $file = $frame['file'] ?? ($frame['raw'] ?? '');
                                $fl = isset($frame['line']) ? $frame['line'] : '';
                                $fc = isset($frame['col']) ? $frame['col'] : '';
                                $loc = $file ? ($file . ($fl !== '' ? ':' . $fl : '') . ($fc ? ':' . $fc : '')) : '';
                                echo '<li>' . esc_html(($cls ? $cls . '::' : '') . ($fn ?: '(anonymous)')) . ($loc ? ' &mdash; <code>' . esc_html($loc) . '</code>' : '') . '</li>';
                            }
                        }
                        echo '</ol>';
                    }
                    echo '</td></tr>';
                }
            }
            echo '</tbody></table></div>';
        }

        echo '<p style="margin-top:8px;">';
        OUS_Debug::button('bh-console', 'clear_log', 'Clear log', '', 'Clear all logged console/error entries? This cannot be undone.', false);
        echo '</p>';
    }

    public static function handle_debug_action(string $action): string {
        if ($action === 'clear_log') {
            global $wpdb;
            $wpdb->query("TRUNCATE TABLE " . self::table());
            return 'Console/error log cleared.';
        }
        // Permanent single-row delete; muting is the pattern-level option.
        if ($action === 'delete_log_row') {
            global $wpdb;
            $id = absint($_POST['ous_log_row_id'] ?? 0);
            if (!$id) return 'No log entry specified.';
            $deleted = $wpdb->delete(self::table(), ['id' => $id], ['%d']);
            return $deleted ? 'Log entry deleted.' : 'That log entry was already gone.';
        }
        // WHY: source/message is read from the row server-side, never trusted
        // from a POSTed field.
        if ($action === 'mute_log_signature') {
            global $wpdb;
            $id = absint($_POST['ous_log_row_id'] ?? 0);
            if (!$id) return 'No log entry specified.';
            $row = $wpdb->get_row($wpdb->prepare("SELECT source, message FROM " . self::table() . " WHERE id = %d", $id), ARRAY_A);
            if (!$row) return 'That log entry no longer exists.';
            self::mute_signature($row['source'], $row['message']);
            return 'Muted — future entries matching this exact source/message will be hidden by default (still logged, not deleted; use "Show muted" to bring them back into view).';
        }
        if ($action === 'unmute_log_signature') {
            $key = sanitize_text_field($_POST['ous_log_signature'] ?? '');
            if (!$key) return 'No muted entry specified.';
            self::unmute_signature($key);
            return 'Unmuted.';
        }
        if ($action === 'health_check_insert') {
            global $wpdb;
            delete_option('ous_debug_log_last_failure');
            self::log('info', 'Manual health-check test entry from Debug Tools.', ['triggered_by' => wp_get_current_user()->user_login ?? ''], 'Debug Tools Health Check');
            $failure = get_option('ous_debug_log_last_failure', null);
            if ($failure) {
                return 'Test insert FAILED: ' . $failure['error'];
            }
            $found = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::table() . " WHERE source = %s AND created_at >= %s",
                'Debug Tools Health Check', gmdate('Y-m-d H:i:s', time() - 60)
            ));
            return $found ? 'Test insert succeeded and was verified readable — logging is working.' : 'Insert reported no error, but the row could not be read back — investigate further.';
        }
        return 'Unknown action.';
    }

    public static function reset_debug(): string {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE " . self::table());
        return 'Console/error log cleared.';
    }

    // Real classes reading --bhy-* tokens instead of inline styles, which
    // the admin skin could not override.
    private static function print_log_styles_once(): void {
        static $printed = false;
        if ($printed) return;
        $printed = true;
        ?>
        <style>
<?php echo (string) file_get_contents(OUS_PATH . 'assets/css/debug-log.css'); ?></style>
        <?php
    }

    // Same window.bhCopyToClipboard helper class-test-runner.php prints;
    // the JS typeof guard makes the second definition a no-op.
    private static function print_copy_script_once(): void {
        static $printed = false;
        if ($printed) return;
        $printed = true;
        ?>
        <script>
<?php echo (string) file_get_contents(OUS_PATH . 'assets/inline/debug-log-console.js'); ?></script>
        <?php
    }

    // Toggles a log row's detail row; guarded like print_copy_script_once().
    private static function print_expand_script_once(): void {
        static $printed = false;
        if ($printed) return;
        $printed = true;
        ?>
        <script>
        if (typeof window.bhToggleLogDetail !== 'function') {
            window.bhToggleLogDetail = function (id) {
                var el = document.getElementById(id);
                if (!el) return;
                el.style.display = (el.style.display === 'none' || !el.style.display) ? 'table-row' : 'none';
            };
        }
        </script>
        <?php
    }
}
