<?php
if (!defined('ABSPATH')) exit;

/**
 * Versioned, namespaced per-event envelope stored in OUS_Tables::events(). emit() only
 * enqueues an OUS_Jobs ingest job; the insert happens off the live request.
 *
 *   BH_Event::register_event_type('bhs/play', ['track_id' => 'int']);   // on 'init'
 *
 *   BH_Event::emit('bhs/play', [
 *       'user_id' => get_current_user_id(),
 *       'subject_type' => 'bhs_track', 'subject_id' => $track_id,
 *       'payload' => ['referrer_bucket' => $bucket],
 *   ]);
 *
 * emit() also fires do_action('bh_event_emitted', $type, $job_args, $args) synchronously.
 */
class BH_Event {
    const JOB_HOOK = 'bhcore_ingest_event';

    /** @var array<string, array{v:int, schema:array<string, mixed>}> */
    private static $types = [];

    public static function init(): void {
        add_action('init', function () {
            if (class_exists('OUS_Jobs')) {
                OUS_Jobs::register(self::JOB_HOOK, [self::class, 'handle_ingest_job']);
            }
        }, 5);
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
    }

    /* ---------------- registration ---------------- */

    /**
     * $schema is self-documenting only (shown in Debug Tools), not enforced at ingest.
     *
     * @param array<string, mixed> $schema
     */
    public static function register_event_type(string $type, array $schema = [], int $v = 1): void {
        self::$types[$type] = ['v' => (int) $v, 'schema' => $schema];
    }

    /** @return array<string, array{v:int, schema:array<string, mixed>}> */
    public static function registered_types(): array {
        return self::$types;
    }

    /* ---------------- emission ---------------- */

    /**
     * Non-blocking: enqueues a job, never writes synchronously. $args keys: user_id, client_id,
     * subject_type, subject_id, payload, context, occurred_at (default now), dedup_key (set a
     * deterministic string for once-only events; null for append-only ones like plays).
     *
     * @param array<string, mixed> $args
     */
    public static function emit(string $type, array $args = []): bool {
        if (!class_exists('OUS_Jobs')) return false; // harmless no-op without the queue

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

        // Synchronous hook for listeners that can't wait for the async ingest job.
        do_action('bh_event_emitted', $type, $job_args, $args);

        return true;
    }

    /**
     * Rough request context (referrer/UA only). Not reusing BHS_Stats: must not depend on bh-streaming.
     *
     * @return array<string, string>
     */
    private static function default_context(): array {
        return [
            'url'      => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '',
            'referrer' => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '',
            'ua'       => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
        ];
    }

    /* ---------------- the actual write, off the live request ---------------- */

    // Public so BH_Identity reuses it; this class owns the table schema.
    public static function table(): string {
        return OUS_Tables::events();
    }

    /**
     * OUS_Jobs handler. A dedup_key collision is expected (racing once-only emits) and ignored quietly.
     *
     * @param array<string, mixed> $args
     */
    public static function handle_ingest_job($args): void {
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

        // WHY: $wpdb->prepare() turns a null %s into '' (not SQL NULL), and dedup_key is UNIQUE, so a
        // null key is written as a literal NULL (plain INSERT) while a real key uses INSERT IGNORE.
        $columns = '(type, v, user_id, client_id, subject_type, subject_id, payload, context, occurred_at, dedup_key)';
        $args = [
            $data['type'], $data['v'], $data['user_id'], $data['client_id'],
            $data['subject_type'], $data['subject_id'], $data['payload'], $data['context'],
            $data['occurred_at'],
        ];
        if ($data['dedup_key'] === null) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO " . self::table() . " $columns VALUES (%s, %d, %d, %s, %s, %d, %s, %s, %s, NULL)",
                ...$args
            ));
        } else {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO " . self::table() . " $columns VALUES (%s, %d, %d, %s, %s, %d, %s, %s, %s, %s)",
                ...array_merge($args, [$data['dedup_key']])
            ));
        }
    }

    /* ---------------- backfill support for BH_Identity ---------------- */

    // Called by BH_Identity on login/registration so pre-signup activity joins to the account.
    public static function backfill_user_id(string $client_id, int $user_id): void {
        global $wpdb;
        if (!$client_id || !$user_id) return;
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET user_id = %d WHERE client_id = %s AND user_id = 0",
            (int) $user_id, (string) $client_id
        ));
    }

    /* ---------------- Debug Tools: metrics view ---------------- */

    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register_debug_section($tools): array {
        $tools['bh-events'] = [
            'label'  => 'Event Tracking',
            'render' => [self::class, 'render_debug_section'],
            'group'  => OUS_Debug::GROUP_MONITORING,
        ];
        return $tools;
    }

    public static function render_debug_section(): void {
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

    // Per-type-per-day pivot over the fixed 7-day window, built in PHP.
    private static function render_debug_daily_breakdown(string $since): void {
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

    // Top 10 users by event count in the window (excludes anonymous user_id = 0).
    private static function render_debug_top_users(string $since): void {
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
