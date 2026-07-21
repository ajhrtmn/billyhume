<?php
if (!defined('ABSPATH')) exit;

/**
 * ROADMAP-ux-polish-and-feature-parity-2026-07.md Section 3: "Saved
 * smart lists/segments — the single clearest real feature gap: today,
 * filtering is 'one tag at a time via a URL query arg,' with no saved
 * multi-condition segment ('tagged X AND registered in last 30 days AND
 * has an active project')." Read Groundhogg's (GPLv2) segment-builder
 * UX as a reference per the roadmap doc's own instruction — the shape
 * borrowed from it is deliberately the SIMPLE end of what Groundhogg
 * offers: a flat, AND-only list of conditions, not Groundhogg's full
 * nested AND/OR condition-group tree. That's a scoping choice, not an
 * oversight — this CRM's whole person list is a few hundred people at
 * most, not a marketing-automation platform's tens of thousands, and
 * "tagged X AND registered after Y AND has a project" (the roadmap
 * doc's own example) is fully expressible with flat AND already. A
 * nested group tree is real, additional complexity with no concrete use
 * case driving it yet — add it later if a real need for OR-logic
 * between groups ever actually shows up.
 *
 * Stored as a real table (not postmeta/option) since a segment is
 * genuinely site-wide shared data (any admin can see/use any segment),
 * matching the same "a table when the shape doesn't fit a post/meta"
 * convention BHCRM_Projects/BHCRM_Notes already established for this
 * plugin's other two tables.
 */
class BHCRM_Segments {
    const DB_VERSION = '1.0';

    // Every condition type this evaluates — deliberately a short,
    // closed list (not an open field-mapping system) matching the
    // roadmap doc's own example conditions exactly, plus the one this
    // plugin's own existing single-tag filter already covered (tag).
    // Extend this list, and matches_condition() below, if a real need
    // for another condition type shows up later.
    const FIELDS = [
        'tag' => 'Has tag',
        'registered_after' => 'Registered after',
        'registered_before' => 'Registered before',
        'has_project' => 'Has an active project',
    ];

    public static function init() {
        self::maybe_upgrade();
        add_action('admin_post_bhcrm_save_segment', [self::class, 'handle_save']);
        add_action('admin_post_bhcrm_delete_segment', [self::class, 'handle_delete']);
        add_action('wp_ajax_bhcrm_preview_segment', [self::class, 'ajax_preview']);
    }

    /**
     * Wizard-opportunity survey's own finding: the segment builder had
     * zero feedback while you built a list — conditions went in blind,
     * and the only way to see who actually matched was to save first,
     * then open the resulting list. This is the concrete fix: a live
     * "N people match" count as each condition changes, computed with
     * the EXACT same sanitize_conditions()/apply() pair the real save
     * path uses (never a second, parallel filtering implementation
     * that could quietly drift from what actually gets saved).
     */
    public static function ajax_preview() {
        check_ajax_referer('bhcrm_preview_segment', 'nonce');
        if (!current_user_can('bhcore_manage_crm')) wp_send_json_error(['message' => 'Not allowed.'], 403);

        $conditions = self::sanitize_conditions(wp_unslash($_POST['conditions'] ?? []));
        $ids = apply_filters('bh_crm_active_user_ids', []);
        $matched = $conditions ? self::apply(array_values(array_unique($ids)), $conditions) : [];

        wp_send_json_success(['count' => count($matched), 'total' => count(array_unique($ids))]);
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhcrm_segments';
    }

    public static function activate() {
        if (self::create_or_update_schema()) {
            update_option('bhcrm_segments_db_version', self::DB_VERSION);
        }
    }

    public static function maybe_upgrade() {
        if (version_compare(get_option('bhcrm_segments_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhcrm_segments_db_version', self::DB_VERSION);
        }
    }

    private static function create_or_update_schema() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            conditions longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset;");

        if ($wpdb->last_error) return false;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function all() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table() . ' ORDER BY name ASC', ARRAY_A);
        foreach ($rows as &$r) {
            $decoded = json_decode($r['conditions'], true);
            $r['conditions'] = is_array($decoded) ? $decoded : [];
        }
        return $rows;
    }

    public static function get($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A);
        if (!$row) return null;
        $decoded = json_decode($row['conditions'], true);
        $row['conditions'] = is_array($decoded) ? $decoded : [];
        return $row;
    }

    // Sanitizes a raw posted condition list down to only well-formed,
    // known-field rows — same "never trust what's about to end up in
    // the DB and get run against a user filter" posture every other
    // save handler in this plugin already takes.
    public static function sanitize_conditions($raw) {
        $clean = [];
        foreach ((array) $raw as $c) {
            $field = sanitize_key($c['field'] ?? '');
            if (!isset(self::FIELDS[$field])) continue;
            $value = sanitize_text_field($c['value'] ?? '');
            if ($field !== 'has_project' && $value === '') continue; // has_project needs no value, everything else does
            $clean[] = ['field' => $field, 'value' => $value];
        }
        return $clean;
    }

    // Applies one segment's conditions (AND-combined) against the base
    // active-person id set — same base set BHCRM_People::render_list()
    // already computes, passed in rather than re-derived here, so this
    // never has its own opinion about who's "active" that could drift
    // from the list page's.
    public static function apply($ids, array $conditions) {
        foreach ($conditions as $c) {
            $ids = array_values(array_filter($ids, fn($id) => self::matches_condition($id, $c)));
            if (!$ids) break; // no one left to check further conditions against
        }
        return $ids;
    }

    private static function matches_condition($user_id, array $condition) {
        switch ($condition['field']) {
            case 'tag':
                return in_array($condition['value'], BHCRM_Tags::get($user_id), true);

            case 'registered_after':
                $ts = strtotime($condition['value']);
                $user = get_userdata($user_id);
                return $ts && $user && strtotime($user->user_registered) >= $ts;

            case 'registered_before':
                $ts = strtotime($condition['value']);
                $user = get_userdata($user_id);
                return $ts && $user && strtotime($user->user_registered) <= $ts;

            case 'has_project':
                global $wpdb;
                return (bool) $wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'bhcrm_projects WHERE crm_person_id = %d', $user_id
                ));

            default:
                return false;
        }
    }

    public static function handle_save() {
        if (!current_user_can('bhcore_manage_crm') || !check_admin_referer('bhcrm_save_segment')) wp_die('Not allowed.'); // QA fix: matches the CRM menu's own bhcore_manage_crm gate

        $name = sanitize_text_field(wp_unslash($_POST['segment_name'] ?? ''));
        $conditions = self::sanitize_conditions(wp_unslash($_POST['conditions'] ?? []));

        $msg = '';
        if ($name === '') {
            $msg = 'Name your list before saving.';
        } elseif (!$conditions) {
            $msg = 'Add at least one real condition before saving.';
        } else {
            global $wpdb;
            $wpdb->insert(self::table(), [
                'name' => $name,
                'conditions' => wp_json_encode($conditions),
            ], ['%s', '%s']);
            $msg = "Saved list \"$name\".";
        }

        if (class_exists('OUS_Toast')) OUS_Toast::queue($msg, $conditions && $name !== '' ? 'success' : 'error');
        wp_safe_redirect(add_query_arg(['page' => 'bh-crm', 'bhcrm_msg' => rawurlencode($msg)], admin_url('admin.php')));
        exit;
    }

    public static function handle_delete() {
        if (!check_admin_referer('bhcrm_delete_segment')) wp_die('Bad nonce.');
        if (class_exists('OUS_Audit')) {
            OUS_Audit::require_cap('manage_options');
        } elseif (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        global $wpdb;
        $id = (int) ($_GET['segment_id'] ?? 0);
        // Audit log — capture what it actually was before it's gone.
        $segment = self::get($id);
        if ($segment && class_exists('OUS_Audit')) {
            OUS_Audit::log('segment_deleted', 'bhcrm_segment', $id, ['name' => $segment['name']]);
        }
        $wpdb->delete(self::table(), ['id' => $id], ['%d']);
        wp_safe_redirect(add_query_arg(['page' => 'bh-crm', 'bhcrm_msg' => rawurlencode('List deleted.')], admin_url('admin.php')));
        exit;
    }
}
