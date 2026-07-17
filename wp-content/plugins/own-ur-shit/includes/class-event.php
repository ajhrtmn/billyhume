<?php
if (!defined('ABSPATH')) exit;

// OUS_VER 3.4.19 — register_debug_section() now sets 'group' =>
// OUS_Debug::GROUP_MONITORING (Debug Tools reorganization pass — see
// class-debug.php's own docblock), filing this under "Monitoring &
// Health" instead of the default bucket. No other change.

/**
 * BH_Event — a versioned, namespaced per-event envelope, the first
 * per-event (not aggregate-counter) tracking table this ecosystem has
 * ever had. See EVENT-TRACKING-ARCHITECTURE-PLAN.md for the full design
 * — this class is that plan's first real implementation (previously
 * designed but never built, despite VISION.md incorrectly claiming it
 * had shipped).
 *
 * Storage: {$wpdb->prefix}bhcore_events, created by
 * BHI_Activator::create_or_update_schema() (see class-identity-
 * activator.php, DB_VERSION 1.8).
 *
 * Ingestion rides OUS_Jobs (see class-jobs.php) — emit() never writes
 * to the DB synchronously on the request that triggered it, it only
 * enqueues a cheap job (bhcore_ingest_event) that does the actual
 * insert off the live request, same reasoning as OUS_Notifications'
 * existing async-email path.
 *
 * USAGE, from any plugin that depends on this one:
 *
 *   add_action('init', function () {
 *       if (class_exists('BH_Event')) {
 *           BH_Event::register_event_type('bhs/play', ['track_id' => 'int']);
 *       }
 *   });
 *
 *   BH_Event::emit('bhs/play', [
 *       'user_id' => get_current_user_id(),
 *       'subject_type' => 'bhs_track', 'subject_id' => $track_id,
 *       'payload' => ['referrer_bucket' => $bucket],
 *   ]);
 */
class BH_Event {
    const JOB_HOOK = 'bhcore_ingest_event';
    const SCHEMA_VERSION = 1;

    /** @var array<string, array{v:int, schema:array}> */
    private static $types = [];

    public static function init() {
        add_action('init', function () {
            if (class_exists('OUS_Jobs')) {
                OUS_Jobs::register(self::JOB_HOOK, [self::class, 'handle_ingest_job']);
            }
        }, 5);
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
    }

    /* ---------------- registration (schema-registry precedent: BH_Content::register_block_type()) ---------------- */

    // $schema is optional and purely self-documenting today (surfaced
    // on the Debug Tools metrics section below) — not yet enforced/
    // coerced at ingest time; a real validate-against-schema pass is a
    // reasonable future addition, not required to make emit()/read
    // useful now.
    public static function register_event_type($type, array $schema = [], $v = 1) {
        self::$types[$type] = ['v' => (int) $v, 'schema' => $schema];
    }

    public static function registered_types() {
        return self::$types;
    }

    /* ---------------- emission ---------------- */

    // Cheap, non-blocking: enqueues a job, never writes synchronously.
    // Supported $args keys: user_id, client_id, subject_type,
    // subject_id, payload (array), context (array), occurred_at
    // (defaults to now), dedup_key (nullable — supply a deterministic
    // string like "course_completed:{$user_id}:{$course_id}" for
    // once-only events; leave null for append-only events like plays/
    // votes).
    public static function emit($type, array $args = []) {
        if (!class_exists('OUS_Jobs')) return false; // harmless no-op, same convention as every other OUS_Jobs consumer

        $registered = self::$types[$type] ?? null;

        $job_args = [
            'type'         => (string) $type,
            'v'            => $registered ? $registered['v'] : 1,
            'user_id'      => isset($args['user_id']) ? (int) $args['user_id'] : (int) get_current_user_id(),
            'client_id'    => isset($args['client_id']) ? (string) $args['client_id'] : (class_exists('BH_Identity') ? BH_Identity::client_id() : ''),
            'subject_type' => isset($args['subject_type']) ? (string) $args['subject_type'] : '',
            'subject_id'   => isset($args['subject_id']) ? (int) $args['subject_id'] : 0,
            'payload'      => is_array($args['payload'] ?? null) ? $args['payload'] : [],
            'context'      => is_array($args['context'] ?? null) ? $args['context'] : self::default_context(),
            'occurred_at'  => isset($args['occurred_at']) ? (string) $args['occurred_at'] : current_time('mysql', true),
            'dedup_key'    => isset($args['dedup_key']) && $args['dedup_key'] !== '' ? (string) $args['dedup_key'] : null,
        ];

        OUS_Jobs::enqueue(self::JOB_HOOK, $job_args);
        return true;
    }

    // Cheap request-context capture — referrer/UA only, no GeoIP, same
    // "honest about being a rough signal" posture BHS_Stats already
    // established for its own referrer/country-guess logic. Kept
    // minimal here rather than reusing BHS_Stats' methods directly
    // since this class must not hard-depend on bh-streaming being
    // active.
    private static function default_context() {
        return [
            'url'      => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '',
            'referrer' => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '',
            'ua'       => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
        ];
    }

    /* ---------------- the actual write, off the live request ---------------- */

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhcore_events';
    }

    // OUS_Jobs handler — runs on the cron/queue worker, not the
    // original request. Tolerates a dedup_key collision quietly (two
    // near-simultaneous emits racing for the same once-only key is
    // expected, not an error) — same posture record_play()'s own
    // docblock already documents for refresh-spam duplicates.
    public static function handle_ingest_job($args) {
        global $wpdb;

        $data = [
            'type'         => sanitize_text_field($args['type'] ?? ''),
            'v'             => (int) ($args['v'] ?? 1),
            'user_id'      => (int) ($args['user_id'] ?? 0),
            'client_id'    => sanitize_text_field($args['client_id'] ?? ''),
            'subject_type' => sanitize_text_field($args['subject_type'] ?? ''),
            'subject_id'   => (int) ($args['subject_id'] ?? 0),
            'payload'      => wp_json_encode($args['payload'] ?? []),
            'context'      => wp_json_encode($args['context'] ?? []),
            'occurred_at'  => $args['occurred_at'] ?? current_time('mysql', true),
            'dedup_key'    => (isset($args['dedup_key']) && $args['dedup_key'] !== '') ? sanitize_text_field($args['dedup_key']) : null,
        ];

        if (!$data['type']) return; // malformed job, nothing sane to insert

        // BUG FIX, caught live while verifying a new emit() call site:
        // $wpdb->prepare()'s %s placeholder silently casts a PHP null
        // to an empty string, NOT SQL NULL — confirmed directly against
        // this table's real data (dedup_key stored as '' rather than
        // NULL). dedup_key has a UNIQUE key, so EVERY event emitted
        // without an explicit dedup_key (the common, "append-only"
        // case — plays, votes, notes, links, wallet activity, etc.)
        // was colliding with the very first such row ever inserted and
        // being silently dropped by INSERT IGNORE ever since this
        // table existed. Only events that supplied a real dedup_key
        // (e.g. bhc/enroll) were ever landing correctly. This was
        // silent data loss across the whole event-tracking system, not
        // a cosmetic issue — surfaced now because bh-crm's per-person
        // activity timeline (BHCRM_Event_Activity) reads straight from
        // this table and was visibly missing rows in a live test.
        //
        // Fix: branch the SQL so a real dedup_key uses INSERT IGNORE +
        // a bound %s (dedup collisions on a genuine repeat key still
        // silently no-op, as designed), while a null dedup_key is
        // written as a literal SQL NULL — never touching the unique
        // index at all, since NULL is never equal to NULL there.
        if ($data['dedup_key'] === null) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO " . self::table() . "
                 (type, v, user_id, client_id, subject_type, subject_id, payload, context, occurred_at, dedup_key)
                 VALUES (%s, %d, %d, %s, %s, %d, %s, %s, %s, NULL)",
                $data['type'], $data['v'], $data['user_id'], $data['client_id'],
                $data['subject_type'], $data['subject_id'], $data['payload'], $data['context'],
                $data['occurred_at']
            ));
        } else {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO " . self::table() . "
                 (type, v, user_id, client_id, subject_type, subject_id, payload, context, occurred_at, dedup_key)
                 VALUES (%s, %d, %d, %s, %s, %d, %s, %s, %s, %s)",
                $data['type'], $data['v'], $data['user_id'], $data['client_id'],
                $data['subject_type'], $data['subject_id'], $data['payload'], $data['context'],
                $data['occurred_at'], $data['dedup_key']
            ));
        }
    }

    /* ---------------- backfill support for BH_Identity ---------------- */

    // Called by BH_Identity once a client_id is resolved to a user_id
    // (login/registration) — backfills user_id onto already-stored rows
    // for that client_id so pre-signup activity joins to the account
    // retroactively, per EVENT-TRACKING-ARCHITECTURE-PLAN.md Section 3.
    public static function backfill_user_id($client_id, $user_id) {
        global $wpdb;
        if (!$client_id || !$user_id) return;
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET user_id = %d WHERE client_id = %s AND user_id = 0",
            (int) $user_id, (string) $client_id
        ));
    }

    /* ---------------- per-user reads (CRM unified activity timeline) ---------------- */

    /**
     * Every event recorded against a given user_id, newest first —
     * the read side backing bh-crm's per-person activity timeline
     * (BHCRM_People::render_timeline()). Deliberately a thin,
     * type-agnostic read (no filtering by type here) — the caller
     * decides how to label/group rows; this just returns the raw
     * history. payload/context are returned already json_decode()'d
     * for convenience.
     */
    public static function for_user($user_id, $limit = 50) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY occurred_at DESC, id DESC LIMIT %d',
            (int) $user_id, (int) $limit
        ), ARRAY_A);
        if (!$rows) return [];
        foreach ($rows as &$row) {
            $row['payload'] = json_decode((string) $row['payload'], true) ?: [];
            $row['context'] = json_decode((string) $row['context'], true) ?: [];
        }
        unset($row);
        return $rows;
    }

    /* ---------------- Debug Tools: minimal metrics view (MVP dashboard) ---------------- */

    public static function register_debug_section($tools) {
        $tools['bh-events'] = [
            'label'  => 'Event Tracking',
            'render' => [self::class, 'render_debug_section'],
            'group'  => OUS_Debug::GROUP_MONITORING,
        ];
        return $tools;
    }

    public static function render_debug_section() {
        global $wpdb;
        echo '<p>Recent event activity, last 7 days — see <code>' . esc_html(self::table()) . '</code>. Registered types this request: <code>' . esc_html(implode(', ', array_keys(self::$types)) ?: '(none registered yet)') . '</code></p>';

        $since = gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT type, COUNT(*) as c FROM " . self::table() . " WHERE occurred_at > %s GROUP BY type ORDER BY c DESC",
            $since
        ), ARRAY_A);

        if (!$rows) {
            echo '<p class="description">No events recorded in the last 7 days yet.</p>';
            return;
        }

        echo '<h4>By type</h4>';
        echo '<table class="widefat striped"><thead><tr><th>Type</th><th>Count (7d)</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td>' . esc_html($r['type']) . '</td><td>' . (int) $r['c'] . '</td></tr>';
        }
        echo '</tbody></table>';

        self::render_debug_daily_breakdown($since);
        self::render_debug_top_users($since);
    }

    // Per-type-per-day breakdown for the same 7-day window as the
    // summary table above — a plain pivot (day rows, type columns)
    // built in PHP rather than a MySQL PIVOT (which doesn't exist)
    // or dynamic SQL column list. Bounded to a fixed 7-day window and
    // whatever distinct types actually appear in it, so this stays a
    // small admin debug table, not something that needs pagination.
    private static function render_debug_daily_breakdown($since) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(occurred_at) as d, type, COUNT(*) as c FROM " . self::table() . "
             WHERE occurred_at > %s GROUP BY DATE(occurred_at), type ORDER BY d ASC",
            $since
        ), ARRAY_A);

        if (!$rows) return;

        $types = [];
        $by_day = [];
        foreach ($rows as $r) {
            $types[$r['type']] = true;
            $by_day[$r['d']][$r['type']] = (int) $r['c'];
        }
        $types = array_keys($types);
        sort($types);

        echo '<h4>By type, per day (7d)</h4>';
        echo '<div class="bhy-table-wrap"><table class="widefat striped"><thead><tr><th>Date</th>';
        foreach ($types as $t) echo '<th>' . esc_html($t) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($by_day as $day => $counts) {
            echo '<tr><td>' . esc_html($day) . '</td>';
            foreach ($types as $t) echo '<td>' . (int) ($counts[$t] ?? 0) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    // Top 10 users by raw event count in the window — a rough "who's
    // most active" signal for spotting a single account generating an
    // outsized share of traffic (bot, script, or a genuinely engaged
    // user), not a real engagement-scoring model. Excludes user_id = 0
    // (anonymous/unstitched rows) since "most active anonymous visitor"
    // isn't a meaningful thing to rank by client_id here.
    private static function render_debug_top_users($since) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, COUNT(*) as c FROM " . self::table() . "
             WHERE occurred_at > %s AND user_id != 0
             GROUP BY user_id ORDER BY c DESC LIMIT 10",
            $since
        ), ARRAY_A);

        if (!$rows) return;

        echo '<h4>Top active users (7d)</h4>';
        echo '<table class="widefat striped"><thead><tr><th>User</th><th>Event count (7d)</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $user = get_userdata((int) $r['user_id']);
            $label = $user ? $user->display_name . ' (#' . (int) $r['user_id'] . ')' : '#' . (int) $r['user_id'];
            echo '<tr><td>' . esc_html($label) . '</td><td>' . (int) $r['c'] . '</td></tr>';
        }
        echo '</tbody></table>';
    }
}
