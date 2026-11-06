<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_Revisions: full-state version history (JSON snapshot per save, not
 * pruned) for admin-built content outside wp_posts. Unlike OUS_Audit, which
 * keeps field-level diffs only. Object-agnostic: restore() hands the snapshot
 * back via an action; the consumer writes it.
 */
class OUS_Revisions {
    const DB_VERSION = '1.0';

    public static function init(): void {
        self::maybe_upgrade();
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
    }

    public static function activate(): void {
        if (self::create_or_update_schema()) {
            update_option('ous_revisions_db_version', self::DB_VERSION);
        }
    }

    public static function maybe_upgrade(): void {
        if (version_compare(get_option('ous_revisions_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('ous_revisions_db_version', self::DB_VERSION);
        }
    }

    private static function create_or_update_schema(): bool {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            object_type varchar(60) NOT NULL,
            object_id bigint(20) unsigned NOT NULL,
            version int(10) unsigned NOT NULL,
            data longtext NOT NULL,
            label varchar(120) DEFAULT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY object (object_type, object_id, version)
        ) $charset;");

        if ($wpdb->last_error) return false;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private static function table(): string {
        return OUS_Tables::revisions();
    }

    /* =================================================================
     * Writing
     * ================================================================= */

    /**
     * Stores the whole consumer-defined state of an object as one snapshot.
     * Auto-increments `version` per (object_type, object_id); returns it.
     */
    /** @param array<string, mixed> $full_state */
    public static function snapshot(string $object_type, int $object_id, array $full_state, ?string $label = null, ?int $user_id = null): int {
        global $wpdb;
        $object_type = sanitize_key($object_type);
        $object_id = (int) $object_id;
        $user_id = $user_id === null ? get_current_user_id() : (int) $user_id;

        $last_version = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(version) FROM " . self::table() . " WHERE object_type = %s AND object_id = %d",
            $object_type, $object_id
        ));
        $version = $last_version + 1;

        $wpdb->insert(self::table(), [
            'object_type' => $object_type,
            'object_id'   => $object_id,
            'version'     => $version,
            'data'        => wp_json_encode($full_state),
            'label'       => $label ? sanitize_text_field($label) : null,
            'user_id'     => $user_id,
            'created_at'  => current_time('mysql'),
        ]);

        return $version;
    }

    /* =================================================================
     * Reading
     * ================================================================= */

    /** Past versions, newest first; metadata only (see get_version()). */
    /** @return array<int, array<string, mixed>> */
    public static function history(string $object_type, int $object_id, int $limit = 20): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, version, label, user_id, created_at FROM " . self::table() . "
             WHERE object_type = %s AND object_id = %d ORDER BY version DESC LIMIT %d",
            sanitize_key($object_type), (int) $object_id, max(1, (int) $limit)
        ), ARRAY_A);
        return $rows ?: [];
    }

    /** Full snapshot for one version, or null if it does not exist. */
    /** @return array<string, mixed>|null */
    public static function get_version(string $object_type, int $object_id, int $version): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE object_type = %s AND object_id = %d AND version = %d",
            sanitize_key($object_type), (int) $object_id, (int) $version
        ), ARRAY_A);
        if (!$row) return null;
        $row['data'] = json_decode($row['data'], true);
        return $row;
    }

    /**
     * Writes nothing: fires an action for the consumer to apply the snapshot,
     * and returns it (or null) for callers that apply it directly.
     */
    /** @return mixed */
    public static function restore(string $object_type, int $object_id, int $version) {
        $snapshot = self::get_version($object_type, $object_id, $version);
        if ($snapshot === null) return null;
        do_action('ous_revision_restore_requested', $object_type, $object_id, $snapshot['data'], $version);
        return $snapshot['data'];
    }

    /* =================================================================
     * Shared admin UI fragment
     * ================================================================= */

    /**
     * Version History list with Restore links to $restore_action (an admin-post
     * handler the consumer registers).
     * WHY: GET links with a nonce, not a <form>: it renders inside metaboxes,
     * already inside <form id="post">, and nested forms hijack the page's submit.
     * CSS `columns` masonry so it fits both wide and narrow side metaboxes.
     */
    public static function render_history_panel(string $object_type, int $object_id, string $restore_action, string $nonce_action): void {
        $rows = self::history($object_type, $object_id);
        if (!$rows) {
            echo '<p class="description">No earlier versions yet — one gets saved automatically each time this is saved.</p>';
            return;
        }

        echo '<div class="ous-revisions-masonry" style="column-width:220px;column-gap:12px;">';
        foreach ($rows as $r) {
            $user = (int) $r['user_id'] ? get_userdata((int) $r['user_id']) : null;
            $restore_url = wp_nonce_url(
                add_query_arg(['action' => $restore_action, 'object_id' => (int) $object_id, 'version' => (int) $r['version']], admin_url('admin-post.php')),
                $nonce_action, 'ous_revisions_nonce'
            );
            // WHY: .bhy-card, not inline colors: admin-skin.css themes cards by
            // class name, so inline styles would stay light-mode-only.
            echo '<div class="bhy-card" style="break-inside:avoid;">';
            echo '<div style="font-weight:600;">Version #' . (int) $r['version'] . '</div>';
            echo '<div class="description" style="margin:2px 0;">' . esc_html(mysql2date('M j, Y g:ia', $r['created_at'])) . '</div>';
            echo '<div class="description" style="margin:0 0 8px;">' . ($user ? esc_html($user->display_name) : 'system') . ($r['label'] ? ' — ' . esc_html($r['label']) : '') . '</div>';
            echo '<a class="button button-small" href="' . esc_url($restore_url) . '" onclick="return confirm(\'Restore this version? Your current version stays in history too.\');">Restore</a>';
            echo '</div>';
        }
        echo '</div>';
    }

    /* =================================================================
     * Debug Tools: exercise the mechanism without a consumer.
     * ================================================================= */

    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register_debug_section($tools): array {
        $tools['ous-revisions'] = [
            'label'  => 'Version History (revisions)',
            'render' => [self::class, 'render_debug_section'],
            'group'  => class_exists('OUS_Debug') ? OUS_Debug::GROUP_MONITORING : '',
        ];
        return $tools;
    }

    public static function render_debug_section(): void {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT object_type, object_id, COUNT(*) as versions, MAX(created_at) as last_saved FROM ' . self::table() . ' GROUP BY object_type, object_id ORDER BY last_saved DESC LIMIT 50', ARRAY_A);

        echo '<p class="description">Shared version-history service (OUS_Revisions) — every object with at least one snapshot. Admin-only.</p>';
        if (!$rows) {
            echo '<p>No revisions recorded yet.</p>';
            return;
        }

        echo '<div class="bhy-table-wrap"><table class="widefat striped"><thead><tr><th>Object</th><th>Versions</th><th>Last saved</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td>' . esc_html($r['object_type']) . ' #' . (int) $r['object_id'] . '</td>';
            echo '<td>' . (int) $r['versions'] . '</td>';
            echo '<td>' . esc_html(mysql2date('M j, Y g:ia', $r['last_saved'])) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}
