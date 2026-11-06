<?php
if (!defined('ABSPATH')) exit;

/**
 * Accountability log for admin actions: who changed WHAT on a thing that
 * may not be their own, with a before/after diff. BH_Event is the
 * per-person activity timeline; this is separate.
 * WHY: writes are synchronous so the record exists even if the next line crashes.
 * require_cap() replaces `if (!current_user_can()) wp_die()` and only logs
 * bursts of denials. Pruning is row-count bound (MAX_ROWS -> KEEP_ROWS).
 */
class OUS_Audit {
    const MAX_ROWS = 20000;
    const KEEP_ROWS = 15000;
    const DENY_WINDOW = 10 * MINUTE_IN_SECONDS;
    const DENY_THRESHOLD = 5;
    const DB_VERSION = '1.0';

    public static function init(): void {
        self::maybe_upgrade();
        add_action('set_user_role', [self::class, 'log_role_change'], 10, 3);
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
    }

    public static function activate(): void {
        if (self::create_or_update_schema()) {
            update_option('ous_audit_db_version', self::DB_VERSION);
        }
    }

    public static function maybe_upgrade(): void {
        if (version_compare(get_option('ous_audit_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('ous_audit_db_version', self::DB_VERSION);
        }
    }

    private static function create_or_update_schema(): bool {
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

    private static function table(): string {
        return OUS_Tables::audit_log();
    }

    /* =================================================================
     * Writing
     * ================================================================= */

    /**
     * Plain action log, no diff.
     *
     * @param array<string, mixed> $meta
     */
    public static function log(string $action, string $object_type = '', int $object_id = 0, array $meta = [], ?int $actor_user_id = null): void {
        self::write($action, $object_type, $object_id, null, $meta, $actor_user_id);
    }

    /**
     * Before/after diff of flat assoc arrays; only changed fields are stored
     * as [field => [old, new]].
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, mixed> $meta
     */
    public static function log_diff(string $action, string $object_type, int $object_id, array $before, array $after, array $meta = [], ?int $actor_user_id = null): void {
        $diff = [];
        foreach ($after as $key => $new_value) {
            $old_value = $before[$key] ?? null;
            if ($old_value !== $new_value) $diff[$key] = [$old_value, $new_value];
        }
        foreach ($before as $key => $old_value) {
            if (!array_key_exists($key, $after)) $diff[$key] = [$old_value, null];
        }
        self::write($action, $object_type, $object_id, $diff ?: null, $meta, $actor_user_id);
    }

    // Timestamps are UTC, matching BH_Event, so rows can be correlated.
    /**
     * @param array<string, mixed>|null $diff
     * @param array<string, mixed> $meta
     */
    private static function write(string $action, string $object_type, int $object_id, ?array $diff, array $meta, ?int $actor_user_id): void {
        global $wpdb;
        $actor_user_id = $actor_user_id === null ? get_current_user_id() : (int) $actor_user_id;
        $wpdb->insert(self::table(), [
            'actor_user_id' => $actor_user_id,
            'action'        => sanitize_key($action),
            'object_type'   => sanitize_key($object_type),
            'object_id'     => (int) $object_id,
            'diff'          => $diff ? wp_json_encode($diff) : null,
            'meta'          => $meta ? wp_json_encode($meta) : null,
            'created_at'    => current_time('mysql', true),
        ]);
        self::maybe_prune();
    }

    /**
     * Drop-in for `if (!current_user_can($cap)) wp_die($msg);` that also
     * writes an audit entry once denials cross DENY_THRESHOLD in DENY_WINDOW.
     */
    public static function require_cap(string $cap, string $msg = 'Not allowed.'): void {
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

    /**
     * Covers any role grant/revoke from the Users screen.
     *
     * @param array<int, string> $old_roles
     */
    public static function log_role_change(int $user_id, string $new_role, array $old_roles): void {
        self::log('user_role_changed', 'user', $user_id, [
            'old_roles' => array_values($old_roles), 'new_role' => $new_role,
        ]);
    }

    /* ---- Pruning ---- */
    private static bool $checked_this_request = false;

    private static function maybe_prune(): void {
        if (self::$checked_this_request) return;
        self::$checked_this_request = true;
        // COUNT(*) runs at most once per 5 minutes site-wide.
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

    /* ---- Reading: Debug Tools section ---- */

    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register_debug_section($tools): array {
        $tools['ous-audit-log'] = [
            'label'  => 'Audit Log',
            'render' => [self::class, 'render_debug_section'],
            'group'  => class_exists('OUS_Debug') ? OUS_Debug::GROUP_MONITORING : '',
        ];
        return $tools;
    }

    // Debug Tools is already manage_options-only; no second gate here.
    public static function render_debug_section(): void {
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
            // created_at is UTC, so convert before mysql2date().
            echo '<td>' . esc_html(mysql2date('M j, Y g:ia', get_date_from_gmt($r['created_at']))) . '</td>';
            echo '<td>' . ($actor ? esc_html($actor->display_name) : '<em>system</em>') . '</td>';
            echo '<td>' . esc_html($r['action']) . '</td>';
            echo '<td>' . ($r['object_type'] ? esc_html($r['object_type']) . ' #' . (int) $r['object_id'] : '—') . '</td>';
            echo '<td>' . ($diff_lines ? implode('<br>', $diff_lines) : '—') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}
