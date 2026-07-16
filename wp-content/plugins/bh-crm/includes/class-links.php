<?php
if (!defined('ABSPATH')) exit;

/**
 * BHCRM_Links — a generic, typed relationship table between entities,
 * replacing bhcrm_projects.crm_person_id's hard single-owner column.
 * AJ's own framing: "a more standard and conventional relationship...
 * more like Jira or DevOps" — a project can link to MULTIPLE people
 * with a typed relation ('owner', 'collaborator', 'watcher', or any
 * other free-text relation a future feature wants), and the same table
 * extends to any future entity pair (e.g. a course linked to an
 * instructor, a contest linked to a judge) without a new column or a
 * new table, just a new ($from_type, $to_type) pairing.
 *
 * Row shape: id, from_type, from_id, to_type, to_id, relation,
 * created_at. Direction is a modeling convenience, not a hard
 * semantic — 'project'->'person' owner and 'person'->'project' owner
 * would be the same fact from either side; this class always stores
 * project->person for that pairing (see link_project_person()) so
 * queries don't need to check both directions.
 *
 * A (from_type, from_id, to_type, to_id, relation) row is unique —
 * the same person can't be linked to the same project as 'owner'
 * twice, but CAN hold two different relations to the same project
 * ('owner' AND 'watcher' as separate rows), matching Jira/DevOps's own
 * "a person can wear more than one hat on the same item" behavior.
 */
class BHCRM_Links {
    const DB_VERSION = '1.0';
    const RELATIONS = ['owner' => 'Owner', 'collaborator' => 'Collaborator', 'watcher' => 'Watcher'];

    public static function init() {
        self::maybe_upgrade();
    }

    public static function activate() {
        if (self::create_or_update_schema()) {
            update_option('bhcrm_links_db_version', self::DB_VERSION);
            self::migrate_legacy_project_owners();
        }
    }

    public static function maybe_upgrade() {
        if (version_compare(get_option('bhcrm_links_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhcrm_links_db_version', self::DB_VERSION);
            self::migrate_legacy_project_owners();
        }
    }

    private static function create_or_update_schema() {
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

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhcrm_links';
    }

    /**
     * One-time backfill: every existing bhcrm_projects row with a
     * nonzero crm_person_id becomes a project->person 'owner' link.
     * Idempotent (the UNIQUE key makes a re-run a harmless no-op via
     * INSERT IGNORE) — safe to call from maybe_upgrade() on every
     * version bump that touches this, not just the very first install.
     */
    public static function migrate_legacy_project_owners() {
        global $wpdb;
        $projects_table = $wpdb->prefix . 'bhcrm_projects';
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
    public static function link($from_type, $from_id, $to_type, $to_id, $relation = 'related') {
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
            // Debug-log wiring pass — previously silent on failure.
            OUS_DebugLog::log('error', 'BHCRM_Links::link() insert failed.', [
                'from_type' => $from_type, 'from_id' => $from_id, 'to_type' => $to_type, 'to_id' => $to_id, 'relation' => $relation, 'db_error' => $wpdb->last_error,
            ], 'BH CRM Links');
        }
        $link_id = (int) $wpdb->insert_id;

        // Feeds the CRM's unified per-person activity timeline — only
        // meaningful when one side of the link IS a person, same as
        // the rest of this class's project<->person convenience layer.
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

    public static function unlink_by_id($link_id) {
        global $wpdb;
        return (bool) $wpdb->delete(self::table(), ['id' => (int) $link_id]);
    }

    /** Every link where the given entity is the FROM side. */
    public static function for_from($from_type, $from_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE from_type=%s AND from_id=%d ORDER BY created_at ASC, id ASC',
            sanitize_key($from_type), (int) $from_id
        ), ARRAY_A);
    }

    /** Every link where the given entity is the TO side. */
    public static function for_to($to_type, $to_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE to_type=%s AND to_id=%d ORDER BY created_at ASC, id ASC',
            sanitize_key($to_type), (int) $to_id
        ), ARRAY_A);
    }

    /* =================================================================
     * project<->person convenience wrappers — the first real consumer.
     * Direction is always stored project(from)->person(to); these
     * wrappers hide that so callers on either side don't need to know it.
     * ================================================================= */

    public static function link_project_person($project_id, $person_id, $relation = 'owner') {
        return self::link('project', $project_id, 'person', $person_id, $relation);
    }

    /** Linked people for a project, each row enriched with the WP_User (or null if the account is gone). */
    public static function people_for_project($project_id) {
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
    public static function project_ids_for_person($person_id) {
        $links = self::for_to('person', (int) $person_id);
        $ids = [];
        foreach ($links as $l) {
            if ($l['from_type'] === 'project') $ids[] = (int) $l['from_id'];
        }
        return array_values(array_unique($ids));
    }
}
