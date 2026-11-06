<?php
if (!defined('ABSPATH')) exit;

/**
 * Generic typed relationship table between entities (from_type/from_id -> to_type/to_id, relation).
 * Project<->person links are always stored project(from)->person(to).
 * (from, to, relation) is unique; one person may hold several relations to the same item.
 */
class BHCRM_Links {
    const DB_VERSION = '1.0';
    const RELATIONS = ['owner' => 'Owner', 'collaborator' => 'Collaborator', 'watcher' => 'Watcher'];

    public static function init(): void {
        self::maybe_upgrade();
    }

    public static function activate(): void {
        if (self::create_or_update_schema()) {
            update_option('bhcrm_links_db_version', self::DB_VERSION);
            self::migrate_legacy_project_owners();
        }
    }

    public static function maybe_upgrade(): void {
        if (version_compare(get_option('bhcrm_links_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhcrm_links_db_version', self::DB_VERSION);
            self::migrate_legacy_project_owners();
        }
    }

    private static function create_or_update_schema(): bool {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            from_type varchar(40) NOT NULL,
            from_id bigint(20) unsigned NOT NULL,
            to_type varchar(40) NOT NULL,
            to_id bigint(20) unsigned NOT NULL,
            relation varchar(40) NOT NULL DEFAULT 'related',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY bhcrm_link_unique (from_type,from_id,to_type,to_id,relation),
            KEY from_lookup (from_type,from_id),
            KEY to_lookup (to_type,to_id)
        ) $charset;");

        if ($wpdb->last_error) return false;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private static function table(): string {
        return BHCRM_Tables::links();
    }

    /** Backfills crm_person_id owners as 'owner' links; idempotent via the UNIQUE key + INSERT IGNORE. */
    public static function migrate_legacy_project_owners(): void {
        global $wpdb;
        $projects_table = BHCRM_Tables::projects();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $projects_table)) !== $projects_table) return;

        $rows = $wpdb->get_results("SELECT id, crm_person_id FROM $projects_table WHERE crm_person_id > 0", ARRAY_A);
        foreach ($rows as $row) {
            self::link('project', (int) $row['id'], 'person', (int) $row['crm_person_id'], 'owner');
        }
    }

    /* =================================================================
     * Generic CRUD
     * ================================================================= */

    /** Creates the link if it doesn't already exist (same 4-tuple + relation). Returns the link id either way. */
    public static function link(string $from_type, int $from_id, string $to_type, int $to_id, string $relation = 'related'): int {
        global $wpdb;
        $from_type = sanitize_key($from_type);
        $to_type = sanitize_key($to_type);
        $relation = sanitize_key($relation) ?: 'related';

        $existing = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table() . ' WHERE from_type=%s AND from_id=%d AND to_type=%s AND to_id=%d AND relation=%s',
            $from_type, (int) $from_id, $to_type, (int) $to_id, $relation
        ));
        if ($existing) return (int) $existing;

        $ok = $wpdb->insert(self::table(), [
            'from_type' => $from_type, 'from_id' => (int) $from_id,
            'to_type'   => $to_type,   'to_id'   => (int) $to_id,
            'relation'  => $relation,
            'created_at' => current_time('mysql'),
        ]);
        if ($ok === false && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('error', 'BHCRM_Links::link() insert failed.', [
                'from_type' => $from_type, 'from_id' => $from_id, 'to_type' => $to_type, 'to_id' => $to_id, 'relation' => $relation, 'db_error' => $wpdb->last_error,
            ], 'BH CRM Links');
        }
        $link_id = (int) $wpdb->insert_id;

        // Activity-timeline event, only when one side is a person.
        if ($link_id && class_exists('BH_Event')) {
            $person_id = $to_type === 'person' ? (int) $to_id : ($from_type === 'person' ? (int) $from_id : 0);
            if ($person_id) {
                BH_Event::emit('bhcrm/link_created', [
                    'user_id' => $person_id, 'subject_type' => 'bhcrm_link', 'subject_id' => $link_id,
                    'payload' => ['from_type' => $from_type, 'from_id' => (int) $from_id, 'to_type' => $to_type, 'to_id' => (int) $to_id, 'relation' => $relation],
                ]);
            }
        }
        return $link_id;
    }

    public static function unlink_by_id(int $link_id): bool {
        global $wpdb;
        return (bool) $wpdb->delete(self::table(), ['id' => (int) $link_id]);
    }

    /** Every link where the given entity is the FROM side. */
    /** @return array<int, array<string, mixed>> */
    public static function for_from(string $from_type, int $from_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE from_type=%s AND from_id=%d ORDER BY created_at ASC, id ASC',
            sanitize_key($from_type), (int) $from_id
        ), ARRAY_A);
    }

    /** Every link where the given entity is the TO side. */
    /** @return array<int, array<string, mixed>> */
    public static function for_to(string $to_type, int $to_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE to_type=%s AND to_id=%d ORDER BY created_at ASC, id ASC',
            sanitize_key($to_type), (int) $to_id
        ), ARRAY_A);
    }

    /* =================================================================
     * project<->person wrappers (hide the stored project->person direction)
     * ================================================================= */

    public static function link_project_person(int $project_id, int $person_id, string $relation = 'owner'): int {
        return self::link('project', $project_id, 'person', $person_id, $relation);
    }

    /** Linked people for a project, each row enriched with the WP_User (or null if the account is gone). */
    /** @return array<int, array<string, mixed>> */
    public static function people_for_project(int $project_id): array {
        $links = self::for_from('project', (int) $project_id);
        $out = [];
        foreach ($links as $l) {
            if ($l['to_type'] !== 'person') continue;
            $out[] = [
                'link_id'  => (int) $l['id'],
                'relation' => $l['relation'],
                'user'     => get_userdata((int) $l['to_id']),
                'user_id'  => (int) $l['to_id'],
            ];
        }
        return $out;
    }

    /** Every project id a person is linked to, regardless of relation. */
    /** @return array<int, int> */
    public static function project_ids_for_person(int $person_id): array {
        $links = self::for_to('person', (int) $person_id);
        $ids = [];
        foreach ($links as $l) {
            if ($l['from_type'] === 'project') $ids[] = (int) $l['from_id'];
        }
        return array_values(array_unique($ids));
    }
}
