<?php
if (!defined('ABSPATH')) exit;

/**
 * Saved smart lists: a flat, AND-only list of conditions over the CRM person set.
 * Stored in a site-wide table, shared by all admins.
 */
class BHCRM_Segments {
    const DB_VERSION = '1.0';

    // Closed list of condition types; extend together with matches_condition().
    const FIELDS = [
        'tag' => 'Has tag',
        'registered_after' => 'Registered after',
        'registered_before' => 'Registered before',
        'has_project' => 'Has an active project',
    ];

    public static function init(): void {
        self::maybe_upgrade();
        add_action('admin_post_bhcrm_save_segment', [self::class, 'handle_save']);
        add_action('admin_post_bhcrm_delete_segment', [self::class, 'handle_delete']);
        add_action('wp_ajax_bhcrm_preview_segment', [self::class, 'ajax_preview']);
    }

    /**
     * Live "N of M people match" count, using the same sanitize_conditions()/apply() as save.
     * Responds with Datastar patch-signals when OUS_Hypermedia exists, else JSON for segment-builder.js.
     */
    public static function ajax_preview(): void {
        check_ajax_referer('bhcrm_preview_segment', 'nonce');
        if (!current_user_can('bhcore_manage_crm')) wp_send_json_error(['message' => 'Not allowed.'], 403);

        $conditions = self::sanitize_conditions(wp_unslash($_POST['conditions'] ?? []));
        $ids = apply_filters('bh_crm_active_user_ids', []);
        $matched = $conditions ? self::apply(array_values(array_unique($ids)), $conditions) : [];
        $count = count($matched);
        $total = count(array_unique($ids));

        if (class_exists('OUS_Hypermedia')) {
            OUS_Hypermedia::sse_headers();
            OUS_Hypermedia::patch_signals(['previewCount' => $count, 'previewTotal' => $total]);
            exit;
        }

        wp_send_json_success(['count' => $count, 'total' => $total]);
    }

    private static function table(): string {
        return BHCRM_Tables::segments();
    }

    public static function activate(): void {
        if (self::create_or_update_schema()) {
            update_option('bhcrm_segments_db_version', self::DB_VERSION);
        }
    }

    public static function maybe_upgrade(): void {
        if (version_compare(get_option('bhcrm_segments_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhcrm_segments_db_version', self::DB_VERSION);
        }
    }

    private static function create_or_update_schema(): bool {
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

    /** @return array<int, array<string, mixed>> */
    public static function all(): array {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table() . ' ORDER BY name ASC', ARRAY_A);
        foreach ($rows as &$r) {
            $decoded = json_decode($r['conditions'], true);
            $r['conditions'] = is_array($decoded) ? $decoded : [];
        }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public static function get(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A);
        if (!$row) return null;
        $decoded = json_decode($row['conditions'], true);
        $row['conditions'] = is_array($decoded) ? $decoded : [];
        return $row;
    }

    // Keeps only well-formed rows with a known field.
    /**
     * @param mixed $raw
     * @return array<int, array<string, string>>
     */
    public static function sanitize_conditions($raw): array {
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

    // AND-combines conditions over a caller-supplied base id set.
    /**
     * @param array<int, int> $ids
     * @param array<int, array<string, string>> $conditions
     * @return array<int, int>
     */
    public static function apply($ids, array $conditions): array {
        foreach ($conditions as $c) {
            $ids = array_values(array_filter($ids, fn($id) => self::matches_condition($id, $c)));
            if (!$ids) break; // no one left to check further conditions against
        }
        return $ids;
    }

    /** @param array<string, string> $condition */
    private static function matches_condition(int $user_id, array $condition): bool {
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
                // Any BHCRM_Links relation counts, not just the legacy crm_person_id owner.
                return class_exists('BHCRM_Links') && (bool) BHCRM_Links::project_ids_for_person($user_id);

            default:
                return false;
        }
    }

    public static function handle_save(): void {
        if (!current_user_can('bhcore_manage_crm') || !check_admin_referer('bhcrm_save_segment')) wp_die('Not allowed.');

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

    /** Exposes every saved list as an OUS_Campaigns audience ('bhcore_campaign_segments'). */
    /**
     * @param array<string, mixed> $segments
     * @return array<string, mixed>
     */
    public static function register_campaign_segments($segments): array {
        if (class_exists('BHCRM_People')) {
            $segments['bhcrm_active'] = [
                'label' => 'CRM: everyone active in the CRM',
                'user_ids' => ['BHCRM_People', 'active_user_ids'],
            ];
        }

        foreach (self::all() as $row) {
            $conditions = $row['conditions'];
            $segments['bhcrm_segment_' . $row['id']] = [
                'label' => 'CRM list: ' . $row['name'],
                'user_ids' => function () use ($conditions) {
                    $base = class_exists('BHCRM_People') ? BHCRM_People::active_user_ids() : [];
                    return self::apply($base, $conditions);
                },
            ];
        }
        return $segments;
    }

    public static function handle_delete(): void {
        if (!check_admin_referer('bhcrm_delete_segment')) wp_die('Bad nonce.');
        if (class_exists('OUS_Audit')) {
            OUS_Audit::require_cap('manage_options');
        } elseif (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        global $wpdb;
        $id = (int) ($_GET['segment_id'] ?? 0);
        $segment = self::get($id);
        if ($segment && class_exists('OUS_Audit')) {
            OUS_Audit::log('segment_deleted', 'bhcrm_segment', $id, ['name' => $segment['name']]);
        }
        $wpdb->delete(self::table(), ['id' => $id], ['%d']);
        wp_safe_redirect(add_query_arg(['page' => 'bh-crm', 'bhcrm_msg' => rawurlencode('List deleted.')], admin_url('admin.php')));
        exit;
    }
}
