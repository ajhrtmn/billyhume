<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_Audit — accountability log for admin/manager actions, separate
 * from BH_Event's per-person ACTIVITY timeline (class-event.php). The
 * distinction matters: BH_Event answers "what did this person do"
 * (their own votes, plays, notes) — this answers "who changed WHAT, to
 * WHAT, on a thing that isn't necessarily their own" (a tier's price, a
 * segment someone else built, another user's role) — a before/after
 * diff question the activity timeline was never shaped to answer (it
 * stores one event payload, not a structured old/new diff).
 *
 * Storage: {$wpdb->prefix}bhcore_audit_log (actor_user_id, action,
 * object_type, object_id, diff JSON, meta JSON, created_at). Synchronous
 * writes (unlike BH_Event, which defers through OUS_Jobs) — an audit
 * record needs to exist even if the very next line of code is what
 * crashes, and audit volume is always far lower than raw activity
 * events, so the extra synchronous insert per admin action is cheap.
 *
 * Scope is deliberately curated to "important things and anything they
 * touch": tier create/update/delete, segment delete, project delete,
 * project link/unlink, submission reject, WordPress role changes
 * (set_user_role — covers granting/revoking the new Studio Manager
 * role from the Users screen for free, no bespoke UI needed), and CRM
 * sensitive-capability-relevant actions. Not exhaustive of every
 * possible admin click in this ecosystem — extensible via
 * log()/log_diff() from anywhere else that turns out to matter.
 *
 * DENIED/FAILED ACTIONS: require_cap() is a drop-in replacement for the
 * `if (!current_user_can($cap)) wp_die(...)` pattern used all over this
 * ecosystem's admin-post handlers. It does NOT log every single denial
 * (a normal permission mismatch is not inherently a security event, and
 * logging every one would be pure noise) — it tracks a rolling per-user
 * denial count and only writes a real audit entry once that count
 * crosses a concerning threshold in a short window.
 *
 * PRUNING: maybe_prune() is a cheap row-count check run
 * opportunistically on write (throttled to once per request via a
 * static flag, and further throttled via a transient so it isn't a
 * real query on every single write) that deletes the oldest rows once
 * the table crosses MAX_ROWS, keeping the newest KEEP_ROWS. Time-based
 * alone was rejected — an admin-only tool used rarely could go a year
 * with only a handful of entries (nothing to prune) or, on an active
 * multi-manager site, accumulate thousands in a month (bloat well
 * before a fixed calendar cutoff) — row-count is the more honest
 * bound for debugging bloat.
 */
class OUS_Audit {
    const MAX_ROWS = 20000;
    const KEEP_ROWS = 15000;
    const DENY_WINDOW = 10 * MINUTE_IN_SECONDS;
    const DENY_THRESHOLD = 5;
    const DB_VERSION = '1.0';

    public static function init() {
        self::maybe_upgrade();
        add_action('set_user_role', [self::class, 'log_role_change'], 10, 3);
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
    }

    public static function activate() {
        if (self::create_or_update_schema()) {
            update_option('ous_audit_db_version', self::DB_VERSION);
        }
    }

    public static function maybe_upgrade() {
        if (version_compare(get_option('ous_audit_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('ous_audit_db_version', self::DB_VERSION);
        }
    }

    private static function create_or_update_schema() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action varchar(60) NOT NULL,
            object_type varchar(60) NOT NULL DEFAULT '',
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            diff longtext,
            meta longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY actor (actor_user_id),
            KEY object (object_type, object_id),
            KEY action_time (action, created_at)
        ) $charset;");

        if ($wpdb->last_error) return false;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhcore_audit_log';
    }

    /* =================================================================
     * Writing
     * ================================================================= */

    /** Plain action log, no diff — "X happened," e.g. a deletion or a reject. */
    public static function log($action, $object_type = '', $object_id = 0, array $meta = [], $actor_user_id = null) {
        global $wpdb;
        $actor_user_id = $actor_user_id === null ? get_current_user_id() : (int) $actor_user_id;

        $wpdb->insert(self::table(), [
            'actor_user_id' => $actor_user_id,
            'action'        => sanitize_key($action),
            'object_type'   => sanitize_key($object_type),
            'object_id'     => (int) $object_id,
            'diff'          => null,
            'meta'          => $meta ? wp_json_encode($meta) : null,
            'created_at'    => current_time('mysql'),
        ]);
        self::maybe_prune();
    }

    /**
     * Granular before/after diff. $before/$after are
     * flat assoc arrays (field => value); only fields that actually
     * changed are stored, as [field => [old, new]], so a no-op save
     * (nothing actually different) still creates a record but with an
     * empty diff rather than a wall of unchanged fields.
     */
    public static function log_diff($action, $object_type, $object_id, array $before, array $after, array $meta = [], $actor_user_id = null) {
        $diff = [];
        foreach ($after as $key => $new_value) {
            $old_value = $before[$key] ?? null;
            if ($old_value !== $new_value) $diff[$key] = [$old_value, $new_value];
        }
        foreach ($before as $key => $old_value) {
            if (!array_key_exists($key, $after)) $diff[$key] = [$old_value, null];
        }

        global $wpdb;
        $actor_user_id = $actor_user_id === null ? get_current_user_id() : (int) $actor_user_id;
        $wpdb->insert(self::table(), [
            'actor_user_id' => $actor_user_id,
            'action'        => sanitize_key($action),
            'object_type'   => sanitize_key($object_type),
            'object_id'     => (int) $object_id,
            'diff'          => $diff ? wp_json_encode($diff) : null,
            'meta'          => $meta ? wp_json_encode($meta) : null,
            'created_at'    => current_time('mysql'),
        ]);
        self::maybe_prune();
    }

    /**
     * Drop-in for `if (!current_user_can($cap)) wp_die($msg);` — same
     * call shape, same wp_die() behavior on denial, but ALSO tracks a
     * rolling per-user denial count and writes a real audit entry only
     * once that count crosses DENY_THRESHOLD within DENY_WINDOW. A
     * single denied click (wrong link, stale bookmark, a manager
     * probing what they can't do) is not, on its own, worth an audit
     * row — a burst of them is.
     */
    public static function require_cap($cap, $msg = 'Not allowed.') {
        if (current_user_can($cap)) return;

        $uid = get_current_user_id();
        $key = 'ous_audit_denies_' . $uid;
        $log = get_transient($key);
        $log = is_array($log) ? $log : [];
        $cutoff = time() - self::DENY_WINDOW;
        $log = array_values(array_filter($log, fn($ts) => $ts > $cutoff));
        $log[] = time();
        set_transient($key, $log, self::DENY_WINDOW);

        if (count($log) >= self::DENY_THRESHOLD) {
            self::log('permission_denied_burst', 'capability', 0, [
                'capability' => $cap, 'denial_count' => count($log), 'window_seconds' => self::DENY_WINDOW,
            ], $uid);
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log_throttled('warning', 'ous_audit_deny_burst_' . $uid, self::DENY_WINDOW,
                    'A user hit ' . count($log) . ' access-denied walls for capability "' . $cap . '" within ' . (int) (self::DENY_WINDOW / 60) . ' minutes — worth a look.',
                    ['user_id' => $uid, 'capability' => $cap], 'OUS Audit'
                );
            }
        }

        wp_die($msg);
    }

    /** WordPress's own role-change hook — covers granting/revoking any role (including the new Studio Manager) from the Users screen for free. */
    public static function log_role_change($user_id, $new_role, $old_roles) {
        self::log('user_role_changed', 'user', $user_id, [
            'old_roles' => array_values($old_roles), 'new_role' => $new_role,
        ]);
    }

    /* =================================================================
     * Pruning — row-count bound, not time bound (see class docblock)
     * ================================================================= */
    private static $checked_this_request = false;

    private static function maybe_prune() {
        if (self::$checked_this_request) return;
        self::$checked_this_request = true;
        // Cheap additional throttle: only actually run the COUNT(*) at
        // most once per 5 minutes site-wide, not on every single write.
        if (get_transient('ous_audit_prune_check')) return;
        set_transient('ous_audit_prune_check', 1, 5 * MINUTE_IN_SECONDS);

        global $wpdb;
        $table = self::table();
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        if ($count <= self::MAX_ROWS) return;

        $to_delete = $count - self::KEEP_ROWS;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table ORDER BY id ASC LIMIT %d", $to_delete
        ));
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('info', 'Audit log pruned — kept the newest ' . self::KEEP_ROWS . ' of ' . $count . ' rows.', [], 'OUS Audit');
        }
    }

    /* =================================================================
     * Reading — admin-only Debug Tools section
     * ================================================================= */

    public static function register_debug_section($tools) {
        $tools['ous-audit-log'] = [
            'label'  => 'Audit Log',
            'render' => [self::class, 'render_debug_section'],
            'group'  => class_exists('OUS_Debug') ? OUS_Debug::GROUP_MONITORING : '',
        ];
        return $tools;
    }

    // Debug Tools itself is already manage_options-only (own-ur-shit's
    // class-debug.php add_menu_page() gate) — this section rides on
    // that same admin-only surface rather than adding a second gate.
    public static function render_debug_section() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT 100', ARRAY_A);

        echo '<p class="description">Accountability log — who changed what, and denied-access bursts. Admin-only. Newest 100 shown.</p>';
        if (!$rows) {
            echo '<p>No audit entries yet.</p>';
            return;
        }

        echo '<div class="bhy-table-wrap"><table class="widefat striped"><thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Object</th><th>Diff</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $actor = (int) $r['actor_user_id'] ? get_userdata((int) $r['actor_user_id']) : null;
            $diff = $r['diff'] ? json_decode($r['diff'], true) : [];
            $meta = $r['meta'] ? json_decode($r['meta'], true) : [];
            $diff_lines = [];
            foreach ((array) $diff as $field => $pair) {
                $diff_lines[] = esc_html($field) . ': ' . esc_html(wp_json_encode($pair[0])) . ' &rarr; ' . esc_html(wp_json_encode($pair[1]));
            }
            foreach ((array) $meta as $k => $v) {
                if (is_scalar($v)) $diff_lines[] = esc_html($k) . ': ' . esc_html((string) $v);
            }

            echo '<tr>';
            echo '<td>' . esc_html(mysql2date('M j, Y g:ia', $r['created_at'])) . '</td>';
            echo '<td>' . ($actor ? esc_html($actor->display_name) : '<em>system</em>') . '</td>';
            echo '<td>' . esc_html($r['action']) . '</td>';
            echo '<td>' . ($r['object_type'] ? esc_html($r['object_type']) . ' #' . (int) $r['object_id'] : '—') . '</td>';
            echo '<td>' . ($diff_lines ? implode('<br>', $diff_lines) : '—') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}
