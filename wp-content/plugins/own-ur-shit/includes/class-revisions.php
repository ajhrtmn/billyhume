<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_Revisions — in-admin version history for admin-built content that
 * doesn't already get it for free from `wp_posts` (WordPress core's own
 * post-revisions feature already covers real posts/pages). Scoped in
 * ROADMAP-search-and-revisions.md Section 2. In-wp-admin, NOT git-based,
 * NOT developer-facing — the same restore-a-prior-save UX WordPress
 * core already gives a post, generalized to objects that don't use
 * `wp_posts` (or use it inconsistently).
 *
 * Deliberately a DIFFERENT thing from `OUS_Audit` (class-audit.php),
 * not a duplicate: Audit stores a field-level DIFF only ([old, new] per
 * changed key) and is pruned once it crosses a row-count bound — a
 * genuinely different tool ("who changed what," an accountability log),
 * not something that can reconstruct a prior full version on its own.
 * This table stores the FULL object state as JSON, one snapshot per
 * save, and is not pruned by default — a revision history that silently
 * loses old versions defeats its own point.
 *
 * Same "one shared service, zero central registration needed by
 * consumers" shape as OUS_Jobs/OUS_Notifications: a bare static class in
 * core, self-managed schema, a small set of static verb methods any
 * plugin calls directly. Deliberately does NOT know how to write any
 * specific object's own live table — restore() hands the snapshot back
 * via a fired action rather than mutating anything itself, so this
 * class stays genuinely object-agnostic (the same posture BH_Content's
 * renderer registry already takes about what a "block" is).
 */
class OUS_Revisions {
    const DB_VERSION = '1.0';

    public static function init() {
        self::maybe_upgrade();
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
    }

    public static function activate() {
        if (self::create_or_update_schema()) {
            update_option('ous_revisions_db_version', self::DB_VERSION);
        }
    }

    public static function maybe_upgrade() {
        if (version_compare(get_option('ous_revisions_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('ous_revisions_db_version', self::DB_VERSION);
        }
    }

    private static function create_or_update_schema() {
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

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhcore_revisions';
    }

    /* =================================================================
     * Writing
     * ================================================================= */

    /**
     * Stores the WHOLE current state of an object as one snapshot.
     * $full_state is consumer-defined — whatever "the complete thing"
     * means for that object (a CRM person's notes/tags array, a tier's
     * full field set, a lesson's block tree). Auto-increments `version`
     * per (object_type, object_id). Returns the new version number.
     */
    public static function snapshot($object_type, $object_id, array $full_state, $label = null, $user_id = null) {
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

    /** Past versions (newest first) for a "Version History" panel — metadata only, not the full stored data (see get_version() for that). */
    public static function history($object_type, $object_id, $limit = 20) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, version, label, user_id, created_at FROM " . self::table() . "
             WHERE object_type = %s AND object_id = %d ORDER BY version DESC LIMIT %d",
            sanitize_key($object_type), (int) $object_id, max(1, (int) $limit)
        ), ARRAY_A);
        return $rows ?: [];
    }

    /** The full stored snapshot for one specific version, decoded back into an array. Null if that version doesn't exist. */
    public static function get_version($object_type, $object_id, $version) {
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
     * Doesn't write anything itself — hands the snapshot data back and
     * fires an action the CONSUMER listens for, since only the consumer
     * knows how its own object is actually saved/persisted. Returns the
     * snapshot array (or null if the version doesn't exist) so a caller
     * that already knows its own save path can also just call this
     * directly without needing the action.
     */
    public static function restore($object_type, $object_id, $version) {
        $snapshot = self::get_version($object_type, $object_id, $version);
        if ($snapshot === null) return null;
        do_action('ous_revision_restore_requested', $object_type, $object_id, $snapshot['data'], $version);
        return $snapshot['data'];
    }

    /* =================================================================
     * Shared admin UI fragment — a consumer drops this into its own
     * metabox/panel rather than building its own version-history table
     * markup from scratch every time.
     * ================================================================= */

    /**
     * Renders a real "Version History" list with Restore links.
     * $restore_action is the admin-post action name the Restore link
     * points at — the CONSUMER registers that handler and calls
     * restore()/applies the snapshot however its own save path works;
     * this fragment only renders the list.
     *
     * Uses a plain GET link with a nonce, not a <form>: this fragment
     * always renders inside a metabox, which is itself already inside
     * wp-admin's page-wide `<form id="post">` — nested forms aren't
     * supported by browsers, so a real <form> here would get its fields
     * merged into the outer one and hijack the page's real "Update"/
     * "Publish" submission. Same pattern as this ecosystem's "Move to
     * Trash" link.
     *
     * Layout is a real CSS masonry flow (native `columns`, no JS): this
     * fragment renders in both wide "normal" metaboxes (BHM_Tiers) and
     * WordPress's narrow "side" column metaboxes (bh-contest's Version
     * History box, ~280px), where a fixed Version/When/By/Label/Restore
     * table doesn't fit the narrow case. `columns` auto-collapses to a
     * single column below `column-width` and packs cards side-by-side
     * when there's room, with no JS or fixed breakpoints needed for
     * either metabox context.
     */
    public static function render_history_panel($object_type, $object_id, $restore_action, $nonce_action) {
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
            echo '<div style="break-inside:avoid;margin-bottom:10px;padding:10px;border:1px solid #dcdcde;border-radius:4px;background:#fff;">';
            echo '<div style="font-weight:600;">Version #' . (int) $r['version'] . '</div>';
            echo '<div class="description" style="margin:2px 0;">' . esc_html(mysql2date('M j, Y g:ia', $r['created_at'])) . '</div>';
            echo '<div class="description" style="margin:0 0 8px;">' . ($user ? esc_html($user->display_name) : 'system') . ($r['label'] ? ' — ' . esc_html($r['label']) : '') . '</div>';
            echo '<a class="button button-small" href="' . esc_url($restore_url) . '" onclick="return confirm(\'Restore this version? Your current version stays in history too.\');">Restore</a>';
            echo '</div>';
        }
        echo '</div>';
    }

    /* =================================================================
     * Debug Tools — lets the mechanism itself be tested without a
     * consumer wired up yet, same convention every other shared service
     * in this ecosystem follows.
     * ================================================================= */

    public static function register_debug_section($tools) {
        $tools['ous-revisions'] = [
            'label'  => 'Version History (revisions)',
            'render' => [self::class, 'render_debug_section'],
            'group'  => class_exists('OUS_Debug') ? OUS_Debug::GROUP_MONITORING : '',
        ];
        return $tools;
    }

    public static function render_debug_section() {
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
