<?php
if (!defined('ABSPATH')) exit;

/**
 * Data-binding resolver for BH_Element. Sources are registered in code (register_source() on
 * 'init'), never stored: a placement's config only names a source slug plus plain args.
 * WHY: no PHP callable is ever persisted to the database and later invoked.
 *
 * Binding descriptor, per attribute in config.attrs.{key}:
 *
 *   { "bind": { "source": "bhcore_events.count",
 *                "args": { "type": "bhs/play", "since": "P30D" },
 *                "subject": "context.user_id" } }
 *   { "literal": "Plays this month" }
 *
 * Any arg value of the exact form "context.<key>" is replaced with $ctx[<key>].
 *
 * FAILURE CONTRACT for resolve():
 *   - Unregistered source slug            -> literal fallback, no error, no log noise.
 *   - Source registered without 'resolve' -> registration rejected and logged.
 *   - resolve() throws or returns WP_Error -> literal fallback, logged via OUS_DebugLog.
 *   - resolve() returns null              -> literal fallback ("no data for this context").
 *   - Any other value                     -> returned UNESCAPED; the caller escapes per output context.
 */
class BH_Element_Data {
    /** @var array<string, array<string, mixed>> slug => manifest (including the 'resolve' callable) */
    private static $sources = [];

    /**
     * Output formatters: pure (mixed $value) -> mixed, applied inside resolve() after a bind
     * resolves (never to literals or defaults).
     * @var array<string, callable>
     */
    private static $formatters = [];

    const VALID_KINDS = ['scalar', 'list', 'richtext', 'url', 'series'];

    public static function init(): void {
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
        self::register_default_sources();
        self::register_default_formatters();
    }

    /* ---------------- formatters ---------------- */

    /**
     * @param string   $slug e.g. 'compact_number', 'relative_time', 'currency'.
     * @param callable $fn   function ($value) { return $transformed; } — a throwing formatter
     *                        degrades to the unformatted value inside resolve().
     * @return bool true if accepted, false if rejected (empty slug or non-callable); never throws.
     */
    public static function register_formatter(string $slug, callable $fn): bool {
        $slug = trim((string) $slug);
        if ($slug === '' || !is_callable($fn)) return false;
        self::$formatters[$slug] = $fn;
        return true;
    }

    /**
     * Registered formatter slugs.
     *
     * @return array<int, string>
     */
    public static function registered_formatters(): array {
        return array_keys(self::$formatters);
    }

    /** First-party formatters: only 'compact_number' (used by 'bh/stat-card'). */
    private static function register_default_formatters(): void {
        self::register_formatter('compact_number', function ($value) {
            if (!is_numeric($value)) return $value; // not a number: pass through unchanged
            $n = (float) $value;
            $abs = abs($n);
            if ($abs >= 1000000) return self::trim_trailing_zero(round($n / 1000000, 1)) . 'm';
            if ($abs >= 1000)    return self::trim_trailing_zero(round($n / 1000, 1)) . 'k';
            return (string) (is_float($n) && floor($n) === $n ? (int) $n : $n);
        });
    }

    /** @param mixed $n */
    private static function trim_trailing_zero($n): string {
        // rtrim on a string avoids float formatting surprises ('1.0' -> '1', '1.2' stays).
        $s = rtrim(rtrim(number_format((float) $n, 1, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }

    /**
     * First-party sources: only 'bhcore_events.count', reading bhcore_events directly via $wpdb
     * (BH_Event has no public query method).
     */
    private static function register_default_sources(): void {
        self::register_source('bhcore_events.count', [
            'label'    => 'Event count (bhcore_events)',
            'kind'     => 'scalar',
            'requires' => ['user_id'],
            'arg_schema' => [
                'type'  => ['type' => 'string', 'default' => ''],     // optional event type filter, e.g. 'bhs/play'; '' = all types
                'since' => ['type' => 'string', 'default' => 'P30D'], // ISO-8601 duration, DateInterval-parseable
            ],
            'resolve' => function (array $args, array $ctx) {
                global $wpdb;

                $user_id = (int) ($args['subject'] ?? $ctx['user_id'] ?? 0);
                if ($user_id <= 0) return null; // no subject to count for: "no data", not an error

                $since_spec = (string) ($args['since'] ?? 'P30D');
                try {
                    $interval = new \DateInterval($since_spec);
                } catch (\Throwable $e) {
                    $interval = new \DateInterval('P30D'); // malformed 'since': fall back to the schema default
                }
                $since = (new \DateTime('now', new \DateTimeZone('UTC')))->sub($interval)->format('Y-m-d H:i:s');

                $table = OUS_Tables::events();
                $type = trim((string) ($args['type'] ?? ''));

                if ($type !== '') {
                    $count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $table WHERE user_id = %d AND type = %s AND occurred_at > %s",
                        $user_id, $type, $since
                    ));
                } else {
                    $count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $table WHERE user_id = %d AND occurred_at > %s",
                        $user_id, $since
                    ));
                }

                // get_var() returns null on a query error (not '0'), so the caller falls back to the
                // literal instead of rendering a misleading 0 (e.g. table missing on an old DB_VERSION).
                if ($count === null) return null;
                return (int) $count;
            },
        ]);
    }

    /* ---------------- registration ---------------- */

    /**
     * @param string $slug Namespaced source, e.g. 'bhcore_events.count', 'bhcrm.field'.
     * @param array  $args {
     *   'label'      => 'Event count' (string; falls back to $slug),
     *   'kind'       => 'scalar'|'list'|'richtext'|'url'|'series' (required; invalid/missing -> rejected and logged),
     *   'requires'   => ['user_id'],  // advisory list of context.* tokens, not enforced
     *   'arg_schema' => ['type' => ['type' => 'string'], 'since' => ['type' => 'string', 'default' => 'P30D']],
     *   'resolve'    => function (array $args, array $ctx) { return 1204; },  // required callable
     * }
     * @return bool true if accepted, false if rejected (missing resolve/kind). Never throws; logs instead.
     * @param array<string, mixed> $args
     */
    public static function register_source(string $slug, array $args): bool {
        // WHY: not sanitize_key(), which strips '.'; slugs are dot-namespaced and only used as array keys.
        $slug = trim((string) $slug);
        if ($slug === '') return false;

        $kind = $args['kind'] ?? '';
        if (!in_array($kind, self::VALID_KINDS, true)) {
            self::log_registration_problem($slug, "registered with missing/invalid 'kind' (" . var_export($kind, true) . ") — source will not be usable until fixed");
            return false;
        }

        if (empty($args['resolve']) || !is_callable($args['resolve'])) {
            self::log_registration_problem($slug, "registered without a callable 'resolve' — source will not be usable until fixed");
            return false;
        }

        self::$sources[$slug] = [
            'label'      => (string) ($args['label'] ?? $slug),
            'kind'       => $kind,
            'requires'   => is_array($args['requires'] ?? null) ? array_map('strval', $args['requires']) : [],
            'arg_schema' => is_array($args['arg_schema'] ?? null) ? $args['arg_schema'] : [],
            'resolve'    => $args['resolve'],
        ];
        return true;
    }

    public static function is_registered(string $slug): bool {
        return isset(self::$sources[(string) $slug]);
    }

    /**
     * slug => manifest without the callable, safe for the GUI/REST layer.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function registered_sources(): array {
        $out = [];
        foreach (self::$sources as $slug => $manifest) {
            $out[$slug] = [
                'label'      => $manifest['label'],
                'kind'       => $manifest['kind'],
                'requires'   => $manifest['requires'],
                'arg_schema' => $manifest['arg_schema'],
            ];
        }
        return $out;
    }

    /**
     * Sources whose declared 'kind' matches a target attribute's kind.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function sources_for_kind(string $kind): array {
        return array_filter(self::registered_sources(), function ($m) use ($kind) {
            return $m['kind'] === $kind;
        });
    }

    /* ---------------- resolution ---------------- */

    /**
     * Resolve ONE attribute value against a render context.
     *
     * @param array $attr_value  Either { "bind": {...} } or { "literal": ... }; anything else
     *                            falls through to $default.
     * @param array $ctx         Render context (user_id, post_id, ...). Not pre-sanitized; only
     *                            scalars are read for context.* substitution.
     * @param mixed $default     Returned when there is no literal and no usable binding.
     * @return mixed The resolved value, UNESCAPED. Callers MUST escape per output context.
     * @param array<string, mixed> $attr_value
     * @param array<string, mixed> $ctx
     * @param mixed $default
     * @return mixed
     */
    public static function resolve(array $attr_value, array $ctx = [], $default = '') {
        // A literal wins even if a 'bind' key is also present: it can never fail.
        if (array_key_exists('literal', $attr_value)) {
            return $attr_value['literal'];
        }

        $binding = $attr_value['bind'] ?? null;
        if (!is_array($binding) || empty($binding['source'])) {
            return $default; // no binding, no literal: caller's default
        }

        $slug = (string) $binding['source'];

        // Fixture mode: a '__fixtures' map (source slug => mock value) in $ctx wins outright, so a
        // Library preview never reads live data. Live renders never set this key.
        if (!empty($ctx['__fixtures']) && is_array($ctx['__fixtures']) && array_key_exists($slug, $ctx['__fixtures'])) {
            $value = $ctx['__fixtures'][$slug];
            $format_slug = isset($binding['format']) ? (string) $binding['format'] : '';
            if ($format_slug !== '' && isset(self::$formatters[$format_slug])) {
                try {
                    $formatted = call_user_func(self::$formatters[$format_slug], $value);
                    if ($formatted !== null) $value = $formatted;
                } catch (\Throwable $e) {
                    // fall through with the unformatted fixture value
                }
            }
            return $value;
        }

        if (!isset(self::$sources[$slug])) {
            // Unregistered source is routine (owning plugin deactivated); not logged.
            return $default;
        }

        $manifest = self::$sources[$slug];
        $args = self::resolve_args(is_array($binding['args'] ?? null) ? $binding['args'] : [], $ctx);

        // 'subject' is sugar for an extra arg named 'subject', resolved the same context.* way.
        if (isset($binding['subject'])) {
            $args['subject'] = self::resolve_token($binding['subject'], $ctx);
        }

        try {
            $value = call_user_func($manifest['resolve'], $args, $ctx);
        } catch (\Throwable $e) {
            self::log_resolution_error($slug, $e->getMessage());
            return $default;
        }

        if (is_wp_error($value)) {
            self::log_resolution_error($slug, $value->get_error_message());
            return $default;
        }

        if ($value === null) {
            // null means "no data here", not a failure.
            return $default;
        }

        // Formatters apply only to a successfully resolved bind value; unknown or throwing ones
        // degrade to the unformatted value.
        $format_slug = isset($binding['format']) ? (string) $binding['format'] : '';
        if ($format_slug !== '' && isset(self::$formatters[$format_slug])) {
            try {
                $formatted = call_user_func(self::$formatters[$format_slug], $value);
                if ($formatted !== null) $value = $formatted;
            } catch (\Throwable $e) {
                self::log_resolution_error($slug, "formatter '$format_slug' failed: " . $e->getMessage());
                // fall through with the unformatted $value; the resolve itself succeeded
            }
        }

        return $value;
    }

    // Replaces string values of the exact form "context.<key>" with $ctx[<key>] ?? ''.
    // Substrings are never interpolated.
    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private static function resolve_args(array $args, array $ctx): array {
        $out = [];
        foreach ($args as $key => $value) {
            $out[$key] = is_string($value) ? self::resolve_token($value, $ctx) : $value;
        }
        return $out;
    }

    /**
     * @param mixed $value
     * @param array<string, mixed> $ctx
     * @return mixed
     */
    private static function resolve_token($value, array $ctx) {
        if (is_string($value) && strpos($value, 'context.') === 0) {
            $key = substr($value, strlen('context.'));
            return $ctx[$key] ?? '';
        }
        return $value;
    }

    /* ---------------- quiet operational logging ---------------- */

    private static function log_registration_problem(string $slug, string $message): void {
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('warning', "register_source($slug): $message", [], 'bh-element-data');
        }
    }

    private static function log_resolution_error(string $slug, string $message): void {
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('error', "resolve() failed for source '$slug': $message", [], 'bh-element-data');
        }
    }

    /* ---------------- Debug Tools: registered-sources inspector ---------------- */

    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register_debug_section($tools): array {
        $tools['bh-element-data'] = [
            'label'  => 'Element Data Sources',
            'render' => [self::class, 'render_debug_section'],
            'group'  => OUS_Debug::GROUP_REFERENCE,
        ];
        return $tools;
    }

    public static function render_debug_section(): void {
        echo '<p class="description">Registered data-binding sources (<code>BH_Element_Data::register_source()</code>) this request — what a bound element attribute can point at, and the "kind" the GUI\'s inspector matches against an attribute\'s own declared kind.</p>';

        $sources = self::registered_sources();
        if (!$sources) {
            echo '<p class="description">No data sources registered yet.</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>Slug</th><th>Label</th><th>Kind</th><th>Requires (advisory)</th></tr></thead><tbody>';
        foreach ($sources as $slug => $m) {
            echo '<tr><td><code>' . esc_html($slug) . '</code></td><td>' . esc_html($m['label']) . '</td><td>' . esc_html($m['kind']) . '</td><td>' . esc_html($m['requires'] ? implode(', ', $m['requires']) : '—') . '</td></tr>';
        }
        echo '</tbody></table>';

        $formatters = self::registered_formatters();
        echo '<p class="description" style="margin-top:16px;">Registered output formatters (<code>BH_Element_Data::register_formatter()</code>) — an optional <code>"format"</code> key inside a binding descriptor names one of these to transform the resolved value before it renders (e.g. <code>1204</code> &rarr; <code>1.2k</code>).</p>';
        if (!$formatters) {
            echo '<p class="description">No formatters registered yet.</p>';
        } else {
            echo '<p><code>' . implode('</code>, <code>', array_map('esc_html', $formatters)) . '</code></p>';
        }
    }
}
