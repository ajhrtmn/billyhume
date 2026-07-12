<?php
if (!defined('ABSPATH')) exit;

// OUS_VER 3.4.19 — register_debug_section() now sets 'group' =>
// OUS_Debug::GROUP_MONITORING (Debug Tools reorganization pass — see
// class-debug.php's own docblock), filing this under "Monitoring &
// Health" instead of the default bucket. No other change.

/**
 * The "aggregate console" the whole ecosystem was missing: one place
 * that catches PHP fatals/uncaught exceptions, WordPress's own
 * doing_it_wrong()/deprecated notices, anything any plugin explicitly
 * logs via OUS_DebugLog::log(), AND front-end/admin JS errors — all
 * landing in one table, visible on the same Debug Tools page as
 * everything else, with zero CLI and zero separate log-file hunting.
 *
 * v2 (this pass): every row now carries a real, structured stack trace
 * (file/line/column where the language/runtime exposes it — PHP gives
 * file+line reliably, column only where a Throwable's trace frames
 * happen to include it; JS gives all three from the browser's own
 * error.stack), plus request context (URL, method, user) and real
 * filters on the admin table (level/source/user/date), not just level.
 *
 * USAGE, from any plugin (core or peer, all class_exists()-guarded the
 * same as OUS_Jobs/OUS_Notifications):
 *
 *   if (class_exists('OUS_DebugLog')) {
 *       OUS_DebugLog::log('error', 'Feed sync failed', ['feed_id' => $id], 'BH Streaming');
 *       // or, with a real exception for a full trace:
 *       OUS_DebugLog::log_exception($e, 'BH Streaming');
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

        // Uncaught exceptions carry a full, real stack trace (every
        // frame's file/line, function/class/args) — a strictly richer
        // signal than the fatal-on-shutdown catch below, which only ever
        // sees error_get_last()'s single file/line with no call chain.
        // Chains to any previously-registered handler rather than
        // replacing it outright, since WordPress core or another plugin
        // may already have one installed.
        $previous_exception_handler = set_exception_handler([self::class, 'capture_uncaught_exception']);
        self::$previous_exception_handler = $previous_exception_handler;

        // Fatal errors (parse errors, E_ERROR, out-of-memory, etc.)
        // don't fire normal WordPress hooks OR the exception handler
        // above (the request is already dying) — a shutdown function is
        // the one reliable place to still catch and record one before
        // the process actually ends.
        register_shutdown_function([self::class, 'capture_fatal_on_shutdown']);
    }

    private static $previous_exception_handler = null;

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhcore_debug_log';
    }

    // A real, repeatedly-hit limitation this closes: every row landed in
    // the log as an isolated, unrelated-looking entry, even when 5
    // different log() calls across 3 different classes all happened
    // because of the SAME failing request (exactly the shape of the
    // Portal/API Docs incident this whole logging push started from —
    // "is_locked() failed" and "submenu not registered" were two
    // separate rows with nothing connecting them as the same event).
    // One short, random ID generated once per PHP request (a static
    // var — cheap, no DB/option round-trip needed) and stamped onto
    // every log() call made during that request, whether it came from
    // core, a peer plugin, JS error capture (via the AJAX log endpoint,
    // which runs in its own separate request and gets its OWN id — see
    // ajax_log_js_error()), or a shutdown-time fatal capture. Filtering
    // Console & Logs by this ID reconstructs "everything that happened
    // during this one request," not just "everything that happened
    // around this time," which is a materially different and more
    // useful question when triaging a real bug.
    private static $request_id = null;

    public static function request_id() {
        if (self::$request_id === null) {
            self::$request_id = substr(wp_generate_uuid4(), 0, 8);
        }
        return self::$request_id;
    }

    // Cached for the life of the request (a real SHOW COLUMNS query, not
    // guessed from DB_VERSION alone — an install stuck mid-migration or
    // on a manually-altered table should get the same safe degrade as
    // one that's cleanly on an old version).
    private static $has_request_id_column = null;

    private static function has_request_id_column() {
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
     * $trace, when provided, is a plain array of frames shaped like
     * ['file' => ..., 'line' => ..., 'function' => ..., 'class' => ...]
     * — the same shape debug_backtrace()/Throwable::getTrace() already
     * produce, so callers can pass either straight through. $file/$line/
     * $col are the single "where this actually happened" pointer shown
     * as the row's headline location; $trace is the full call chain,
     * shown expanded.
     */
    public static function log($level, $message, $context = [], $source = '', $file = '', $line = 0, $col = 0, $trace = null) {
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
        // request_id is a column added in a later schema version than
        // the rest of this table (see BHI_Activator::DB_VERSION/health_check()
        // below) — an install that hasn't migrated yet would fail this
        // whole insert on an unknown-column error if it were included
        // unconditionally, taking down EVERY log() call ecosystem-wide
        // over one missing column. Checking real, cached-at-request-scope
        // column presence keeps a not-yet-migrated install degrading to
        // "logs work, just without correlation IDs" instead of "logging
        // is completely broken until someone notices and migrates."
        if (!self::has_request_id_column()) unset($row['request_id']);
        $ok = $wpdb->insert(self::table(), $row);
        // A real, confirmed failure mode this responds to: $wpdb->insert()
        // returning false (schema mismatch, missing table, etc.) was
        // previously silent — every log() call site across the whole
        // ecosystem would just quietly do nothing, with no way to tell
        // "nothing has gone wrong" apart from "logging itself is broken."
        // Stashing the last failure in an option (not a transient — this
        // needs to survive and be visible even if object cache write-
        // through is itself part of what's unreliable on this install)
        // means the Console & Logs page itself can report "logging is
        // broken" even though, by definition, it can't log that fact the
        // normal way.
        if ($ok === false && $wpdb->last_error) {
            update_option('ous_debug_log_last_failure', [
                'error' => $wpdb->last_error,
                'table' => self::table(),
                'at' => current_time('mysql', true),
            ], false);
        }
        self::maybe_trim();

        // AJ's own ask, straight after this session's audit pass: "good
        // use of Query Monitor where needed" — every log() call already
        // lands in the DB table (Debug Tools' own Console & Logs
        // screen), but checking THIS request's own bugs meant leaving
        // that screen and coming back, a real workflow tax while
        // actively building something (like the bh-contest conversion
        // this buffer exists to support). A cheap in-memory buffer, kept
        // only for the life of THIS request, lets class-qm-integration.php
        // surface these same rows directly inside Query Monitor's own
        // toolbar panel — one pane of glass instead of two separate
        // tools while developing.
        self::$request_buffer[] = $row;
    }

    private static $request_buffer = [];

    /** Read-only accessor for class-qm-integration.php — never written
     * to from outside log() itself. */
    public static function request_buffer() {
        return self::$request_buffer;
    }

    // For a check that runs on every single request (is_locked(),
    // rewrite-persistence verification, etc.) — logging every evaluation
    // unthrottled would flood this table into uselessness, but logging
    // ONLY failures means a healthy "checked, still fine" state and a
    // "stopped being checked at all" state look identical from the
    // outside (empty log either way) — exactly the blind spot that made
    // BHI_Portal's rewrite-throttle bug invisible until the user
    // reported "still broken, zero log entries" and there was no way to
    // tell whether the check was running and passing, or not running at
    // all. This logs at most once per $seconds per $key, REGARDLESS of
    // level/outcome, so "no entries for this key in the last N minutes"
    // becomes a real, actionable signal on its own (the check itself
    // isn't running) rather than an ambiguous non-event.
    //
    // Deliberately reads/writes the throttle key straight from wp_options
    // via $wpdb, bypassing get_transient()/set_transient() — the same
    // fix BHI_Portal::not_recently_attempted() needed after transients
    // were found to silently wedge shut on an install with a broken
    // persistent object cache (transients live IN that cache). A
    // throttle for "should I log a diagnostic about caching being
    // broken" cannot itself depend on the cache being trustworthy.
    public static function log_throttled($level, $key, $seconds, $message, $context = [], $source = '') {
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

    // Convenience wrapper — pulls file/line/trace straight off a real
    // Throwable rather than making every catch{} block hand-assemble
    // those three arguments itself.
    public static function log_exception(\Throwable $e, $source = '', $level = 'error') {
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

    public static function capture_uncaught_exception(\Throwable $e) {
        try {
            self::log_exception($e, 'PHP (uncaught)');
        } catch (\Throwable $inner) {
            // Logging the exception must never become a second, more
            // confusing uncaught exception of its own.
        }
        if (self::$previous_exception_handler && is_callable(self::$previous_exception_handler)) {
            call_user_func(self::$previous_exception_handler, $e);
        }
    }

    private static function current_url() {
        if (empty($_SERVER['HTTP_HOST']) || empty($_SERVER['REQUEST_URI'])) return '';
        $scheme = is_ssl() ? 'https' : 'http';
        return esc_url_raw($scheme . '://' . sanitize_text_field($_SERVER['HTTP_HOST']) . sanitize_text_field($_SERVER['REQUEST_URI']));
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
        // a second, more confusing fatal of its own. error_get_last()
        // only ever gives one file/line, never a call chain — PHP simply
        // doesn't retain a trace by the time a fatal reaches shutdown,
        // which is exactly why the uncaught-exception handler above is
        // the richer signal whenever the failure is a thrown exception
        // rather than a true fatal (parse error, out-of-memory, etc.).
        try {
            self::log('error', 'PHP fatal: ' . $error['message'], [], 'PHP', $error['file'], $error['line']);
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
        // Captures three separate JS failure modes into one shared
        // sender: (1) thrown/uncaught errors via 'error', (2) unhandled
        // promise rejections via 'unhandledrejection' — a real gap in
        // the v1 capture, since a rejected fetch()/async function never
        // fires window.onerror at all, and (3) explicit console.error/
        // console.warn calls, by wrapping them rather than only reacting
        // to thrown errors — a lot of real bugs get reported via
        // console.error without ever throwing. error.stack (when present)
        // already carries file/line/column from the browser's own
        // engine — parsed here into a structured frame list rather than
        // stored as an opaque blob, so it's filterable/expandable the
        // same way a PHP trace is.
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

    public static function ajax_log_js_error() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'ous_log_js_error')) {
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
            'group' => OUS_Debug::GROUP_MONITORING,
        ];
        return $tools;
    }

    // Builds the WHERE clause + params for every filter this page
    // supports, shared between the row query and the "copy all
    // currently-filtered rows" dump so the two never drift apart.
    private static function build_filters() {
        $where = [];
        $params = [];

        $level = isset($_GET['ous_log_level']) ? sanitize_key($_GET['ous_log_level']) : '';
        if ($level) { $where[] = 'level = %s'; $params[] = $level; }

        $source = isset($_GET['ous_log_source']) ? sanitize_text_field($_GET['ous_log_source']) : '';
        if ($source) { $where[] = 'source = %s'; $params[] = $source; }

        $user_id = isset($_GET['ous_log_user']) ? absint($_GET['ous_log_user']) : 0;
        if ($user_id) { $where[] = 'user_id = %d'; $params[] = $user_id; }

        $since = isset($_GET['ous_log_since']) ? sanitize_text_field($_GET['ous_log_since']) : '';
        if ($since && preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) { $where[] = 'created_at >= %s'; $params[] = $since . ' 00:00:00'; }

        // "Show me everything that happened during THIS request" —
        // materially different from a time-window filter, since a fast
        // request's rows might all share the same created_at second
        // anyway while a slow one's could span several, and either way
        // a time window can't tell "part of the same request" apart from
        // "just happened to log around the same moment."
        $request_id = isset($_GET['ous_log_request']) ? sanitize_text_field($_GET['ous_log_request']) : '';
        if ($request_id) { $where[] = 'request_id = %s'; $params[] = $request_id; }

        return [
            'level' => $level, 'source' => $source, 'user_id' => $user_id, 'since' => $since, 'request_id' => $request_id,
            'where_sql' => $where ? ('WHERE ' . implode(' AND ', $where)) : '',
            'params' => $params,
        ];
    }

    // Answers "is logging itself actually working" directly, rather than
    // making an admin infer it from an empty table (which is completely
    // ambiguous — a healthy install with nothing to report looks
    // IDENTICAL to a broken install where every insert is silently
    // failing). Checks: does the table exist, does it have the columns
    // this code's INSERT statement actually needs (a real gap here means
    // DB_VERSION's migration didn't run — see OUS_Identity_Activator —
    // or ran against a table some other process already altered), what's
    // the stored bhi_db_version option vs the code's current constant,
    // and any last recorded insert failure from log() itself above.
    private static function health_check() {
        global $wpdb;
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
        echo '<p>Table <code>' . esc_html($table) . '</code>: ' . ($exists ? '<span style="color:#00a32a;">exists</span>' : '<span style="color:#d63638;font-weight:600;">does NOT exist — no log() call anywhere in the ecosystem can be recorded</span>') . '</p>';
        if ($exists && $missing_cols) {
            echo '<p style="color:#d63638;font-weight:600;">Missing column(s): ' . esc_html(implode(', ', $missing_cols)) . ' — the table exists but predates the current schema, so every insert() referencing these columns fails.</p>';
        }
        echo '<p>DB schema version — stored: <code>' . esc_html($db_version_stored) . '</code>, code expects: <code>' . esc_html($db_version_code) . '</code>'
           . ($db_version_stored !== $db_version_code ? ' <span style="color:#d63638;font-weight:600;">— mismatch, migration has not run on this install</span>' : ' <span style="color:#00a32a;">— up to date</span>')
           . '</p>';
        if ($last_failure && is_array($last_failure)) {
            echo '<p style="color:#d63638;"><strong>Last recorded insert failure</strong> (' . esc_html($last_failure['at'] ?? '') . '): <code>' . esc_html($last_failure['error'] ?? '') . '</code></p>';
        }
        OUS_Debug::button('bh-console', 'health_check_insert', 'Run a test log entry now');
        echo '</div>';
    }

    public static function render_debug_section() {
        global $wpdb;
        $table = self::table();
        self::health_check();
        $filters = self::build_filters();

        // Distinct sources currently in the table, for the source
        // dropdown — cheap enough at MAX_ROWS=1000 rows to run on every
        // page view rather than caching separately.
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
        echo '<button type="submit" class="button">Filter</button>';
        if ($filters['level'] || $filters['source'] || $filters['user_id'] || $filters['since'] || $filters['request_id']) {
            echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=ous-debug')) . '">Clear filters</a>';
        }
        if ($filters['request_id']) {
            echo '<p class="description" style="width:100%;margin:4px 0 0;">Showing every logged event that happened during request <code>' . esc_html($filters['request_id']) . '</code> — the full sequence, across every plugin, that led up to (or followed from) any one row.</p>';
        }
        echo '</form>';

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
            $colors = ['error' => '#d63638', 'warning' => '#dba617', 'info' => '#2271b1'];

            // Plain-text dump of exactly the rows currently visible
            // (respects every filter above) — one line per entry, newest
            // first, same order as the table, including the file/line
            // pointer and a JSON trace tail — for pasting a triage
            // session into a chat/ticket without hand-copying rows out
            // of the table one at a time.
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

            echo '<div class="bhy-table-wrap"><table class="widefat striped"><thead><tr><th style="width:90px;">Level</th><th style="width:120px;">Source</th><th>Message</th><th style="width:80px;">User</th><th style="width:140px;">When</th></tr></thead><tbody>';
            $i = 0;
            foreach ($rows as $r) {
                $i++;
                $color = $colors[$r['level']] ?? '#646970';
                $has_detail = $r['trace'] || $r['file'] || $r['url'];
                $detail_id = 'ous-log-detail-' . $i;

                echo '<tr' . ($has_detail ? ' style="cursor:pointer;" onclick="bhToggleLogDetail(\'' . esc_js($detail_id) . '\')"' : '') . '>';
                echo '<td><span style="color:#fff;background:' . esc_attr($color) . ';padding:2px 8px;border-radius:3px;font-size:11px;">' . esc_html(strtoupper($r['level'])) . '</span></td>';
                echo '<td>' . esc_html($r['source'] ?: '&#8212;') . '</td>';
                echo '<td>' . esc_html($r['message']);
                if ($r['file']) {
                    echo ' <code style="font-size:11px;color:#646970;">' . esc_html($r['file'] . ':' . $r['line'] . ($r['col'] ? ':' . $r['col'] : '')) . '</code>';
                }
                // A clickable request-ID chip on every row that has one —
                // stopPropagation so clicking it navigates to the filtered
                // view instead of just toggling this row's own detail
                // pane (the two clicks would otherwise conflict, since
                // the whole <tr> already has its own onclick). This is
                // the actual point of storing request_id at all: turning
                // "here's one isolated error" into "here's everything
                // that happened around it" with a single click.
                if (!empty($r['request_id'])) {
                    $req_url = admin_url('admin.php?page=ous-debug&ous_log_request=' . rawurlencode($r['request_id']));
                    echo ' <a href="' . esc_url($req_url) . '" onclick="event.stopPropagation();" title="Show every log entry from this same request" style="font-size:10px;font-family:monospace;color:#646970;background:#f0f0f1;padding:1px 5px;border-radius:3px;text-decoration:none;">#' . esc_html($r['request_id']) . '</a>';
                }
                if ($has_detail) echo ' <span style="color:#2271b1;font-size:11px;">[details &#9662;]</span>';
                echo '</td>';
                echo '<td>' . ($r['user_id'] ? esc_html(get_userdata($r['user_id']) ? get_userdata($r['user_id'])->user_login : ('#' . $r['user_id'])) : '&#8212;') . '</td>';
                echo '<td>' . esc_html(human_time_diff(strtotime($r['created_at']), current_time('timestamp')) . ' ago') . '</td></tr>';

                if ($has_detail) {
                    echo '<tr id="' . esc_attr($detail_id) . '" style="display:none;"><td colspan="5" style="background:#f6f7f7;">';
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

    public static function handle_debug_action($action) {
        if ($action === 'clear_log') {
            global $wpdb;
            $wpdb->query("TRUNCATE TABLE " . self::table());
            return 'Console/error log cleared.';
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

    public static function reset_debug() {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE " . self::table());
        return 'Console/error log cleared.';
    }

    // Same window.bhCopyToClipboard helper class-test-runner.php prints
    // for its own "copy failures" buttons — guarded on the JS side
    // (typeof check) so whichever section renders first on the shared
    // Debug Tools page wins and the other is a no-op, not a redefinition.
    private static function print_copy_script_once() {
        static $printed = false;
        if ($printed) return;
        $printed = true;
        ?>
        <script>
        if (typeof window.bhCopyToClipboard !== 'function') {
            window.bhCopyToClipboard = function (textareaId, btn) {
                var el = document.getElementById(textareaId);
                if (!el) return;
                var text = el.value;
                var done = function (ok) {
                    if (!btn) return;
                    var original = btn.textContent;
                    btn.textContent = ok ? 'Copied!' : 'Copy failed';
                    setTimeout(function () { btn.textContent = original; }, 1500);
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
                } else {
                    el.style.position = 'static';
                    el.select();
                    var ok = false;
                    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                    el.style.position = 'absolute';
                    el.style.left = '-9999px';
                    done(ok);
                }
            };
        }
        </script>
        <?php
    }

    // Toggles a log row's expandable detail row (trace/context/request)
    // — guarded the same way print_copy_script_once() is, one shared
    // definition regardless of section render order.
    private static function print_expand_script_once() {
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
