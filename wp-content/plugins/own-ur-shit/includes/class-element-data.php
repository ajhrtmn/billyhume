<?php
if (!defined('ABSPATH')) exit;

/**
 * BH_Element_Data — the data-binding resolver for BH_Element
 * (ELEMENT-BUILDER-DESIGN-PLAN.md Section 3.2 / Section 1 judgment call
 * 1.2). Validated registrations, graceful (never fatal) degrade on a
 * missing/invalid/erroring source, and fail-safe fallback to whatever
 * static literal the caller supplied.
 *
 * Registration mirrors BH_Event::register_event_type() /
 * BH_Content::register_block_type() — zero central authority, any
 * plugin calls register_source() on its own 'init', guarded by
 * class_exists('BH_Element_Data'). Sources are registered IN CODE,
 * never stored — a binding descriptor stored in a placement's `config`
 * JSON only ever names a source by slug plus plain args, exactly like
 * BH_Content separates stored `attrs` from a registered `renderer`.
 * This is a deliberate security/portability boundary: no PHP callable
 * is ever persisted to the database and later invoked.
 *
 * BINDING DESCRIPTOR SHAPE (design doc Section 1.2), one per bindable
 * attribute inside a placement's config.attrs.{key}:
 *
 *   { "bind": { "source": "bhcore_events.count",
 *                "args": { "type": "bhs/play", "since": "P30D" },
 *                "subject": "context.user_id" } }
 *   { "literal": "Plays this month" }
 *
 * `subject` (and any other value under a binding's args that starts
 * with "context.") is resolved against the render context array passed
 * into resolve() — e.g. "context.user_id" pulls $ctx['user_id']. This
 * lets one registered source ("this user's play count") serve every
 * viewer without the placement's stored config hard-coding a user ID.
 *
 * FAILURE CONTRACT — read this before calling resolve() or registering
 * a source:
 *   - Unregistered source slug            -> literal fallback, no error, no log noise.
 *   - Source registered without 'resolve' -> literal fallback (a genuinely malformed registration; logged once via OUS_DebugLog if available).
 *   - resolve() throws or returns WP_Error -> literal fallback, logged via OUS_DebugLog if available (this IS an operational problem worth knowing about, unlike a plain missing source).
 *   - resolve() returns null              -> literal fallback (a source is allowed to say "no data for this context" by returning null rather than throwing).
 *   - Any other returned value            -> returned as-is; BH_Element::render_placement() is responsible for output-escaping it per the attribute's declared kind, resolve() itself never escapes (it doesn't know the render context — HTML text node vs. href vs. src differ).
 *
 * NOT runtime-verified: this class has been reasoned through and is
 * internally consistent with BH_Event's existing shape, but no live
 * PHP/MySQL execution is available in this environment. Please smoke
 * test register_source()+resolve() against a real bhcore_events row
 * before relying on it in production.
 */
class BH_Element_Data {
    /** @var array<string, array> slug => manifest (including the 'resolve' callable) */
    private static $sources = [];

    /**
     * DESIGN-SUITE-UNIFICATION-PLAN.md §3.2 v1 — output formatters. Zero-
     * central registration mirroring register_source() exactly: any
     * plugin calls register_formatter() on its own 'init', guarded by
     * class_exists('BH_Element_Data'). A formatter is a pure function
     * (mixed $value) -> mixed — no $args/$ctx, deliberately: a formatter
     * transforms a value AFTER resolution, it doesn't participate in
     * resolving it, so it never needs the resolver's context. Applied
     * inside resolve() itself (see that method), never a separate call
     * site — this keeps "what a bound attribute actually renders as"
     * fully owned by one function.
     * @var array<string, callable>
     */
    private static $formatters = [];

    const VALID_KINDS = ['scalar', 'list', 'richtext', 'url', 'series'];

    public static function init() {
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
        self::register_default_sources();
        self::register_default_formatters();
    }

    /* ---------------- v1 formatters (§3.2) ---------------- */

    /**
     * @param string   $slug e.g. 'compact_number', 'relative_time', 'currency'.
     * @param callable $fn   function ($value) { return $transformed; } — never throws by
     *                        contract (a throwing formatter degrades to the unformatted
     *                        value inside resolve(), one more rung on the existing
     *                        literal/default fallback ladder, never fatal).
     * @return bool true if accepted, false if rejected (empty slug or non-callable) —
     *              same "rejections never throw" posture as register_source().
     */
    public static function register_formatter($slug, callable $fn) {
        $slug = trim((string) $slug);
        if ($slug === '' || !is_callable($fn)) return false;
        self::$formatters[$slug] = $fn;
        return true;
    }

    /** Slugs only — this is what the inspector's future "format" dropdown (§3.3) would list; no per-formatter metadata exists yet since none has been needed by a real consumer. */
    public static function registered_formatters() {
        return array_keys(self::$formatters);
    }

    /**
     * First-party formatters (§3.2's "a small allowlisted set like
     * compact_number/relative_time/currency"). This pass ships only
     * 'compact_number' — the one the stat-card live demo (class-
     * element.php's 'bh/stat-card') actually uses — for the same
     * "quality over breadth, no speculative unused surface area" reason
     * register_default_sources() gives for shipping only one source.
     */
    private static function register_default_formatters() {
        self::register_formatter('compact_number', function ($value) {
            if (!is_numeric($value)) return $value; // not a number — nothing this formatter can do, pass through unchanged rather than erroring
            $n = (float) $value;
            $abs = abs($n);
            if ($abs >= 1000000) return self::trim_trailing_zero(round($n / 1000000, 1)) . 'm';
            if ($abs >= 1000)    return self::trim_trailing_zero(round($n / 1000, 1)) . 'k';
            return (string) (is_float($n) && floor($n) === $n ? (int) $n : $n);
        });
    }

    private static function trim_trailing_zero($n) {
        // 1.0 -> "1", 1.2 -> "1.2" — rtrim on a string avoids float-
        // formatting surprises (e.g. sprintf('%.1f', 1.0) === '1.0').
        $s = rtrim(rtrim(number_format((float) $n, 1, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }

    /**
     * First-party data sources own-ur-shit itself registers (design doc
     * §3.2's "First-party sources own-ur-shit registers" list) — this
     * pass ships only 'bhcore_events.count' (the one the Phase 2 demo
     * slice on the dashboard actually binds to); 'bhcore_events.recent',
     * 'bhcrm.field', 'bhcrm.activity_summary' are named in the design
     * doc but not built this pass (no genuine dashboard/CRM consumer
     * for them yet in this scope — adding unused sources would be
     * speculative surface area, not the "quality over breadth" ask).
     *
     * Reads bhcore_events directly via $wpdb rather than through a
     * BH_Event public query method, because BH_Event (class-event.php,
     * read at implementation time) exposes no such method today — only
     * private $wpdb queries inside its own debug-section renderer. This
     * source is deliberately still registered here (not added as a new
     * BH_Event method) to keep this pass's footprint to the element-
     * builder files named in the task; a future pass could promote this
     * into a real BH_Event::count_for_user() helper other callers reuse.
     */
    private static function register_default_sources() {
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
                if ($user_id <= 0) return null; // no subject to count for — real "no data" case, not an error, per the resolve() contract

                $since_spec = (string) ($args['since'] ?? 'P30D');
                try {
                    $interval = new \DateInterval($since_spec);
                } catch (\Throwable $e) {
                    $interval = new \DateInterval('P30D'); // malformed 'since' arg — fall back to the schema default rather than failing the whole binding
                }
                $since = (new \DateTime('now', new \DateTimeZone('UTC')))->sub($interval)->format('Y-m-d H:i:s');

                $table = $wpdb->prefix . 'bhcore_events';
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

                // $wpdb->get_var() returns null on a genuine query error
                // (not "zero rows", which returns '0') — surface that
                // distinction as null so resolve()'s caller falls back to
                // the literal default rather than rendering a misleading
                // "0" when the query itself actually failed (e.g. the
                // table doesn't exist yet on a pre-1.8-DB_VERSION site).
                if ($count === null) return null;
                return (int) $count;
            },
        ]);
    }

    /* ---------------- registration ---------------- */

    /**
     * @param string $slug Namespaced source, e.g. 'bhcore_events.count', 'bhcrm.field'.
     * @param array  $args {
     *   'label'      => 'Event count' (string, required-ish — falls back to $slug),
     *   'kind'       => 'scalar'|'list'|'richtext'|'url'|'series' (required; invalid/missing -> registration is rejected, logged, source unusable),
     *   'requires'   => ['user_id'],  // which context.* tokens this resolver expects — advisory, surfaced to the GUI/debug view, NOT enforced here (a source is free to handle a missing token itself, e.g. "sitewide count when no user_id")
     *   'arg_schema' => ['type' => ['type' => 'string'], 'since' => ['type' => 'string', 'default' => 'P30D']],
     *   'resolve'    => function (array $args, array $ctx) { return 1204; },  // required callable; array-callable and closures both fine
     * }
     * @return bool true if the registration was accepted, false if rejected (malformed — missing resolve/kind). Rejections never throw: a plugin author gets a debug-log entry and a false return, not a fatal, matching this ecosystem's class_exists()-guarded-degrade posture applied one level deeper (bad registration degrades the same way an absent registration does).
     */
    public static function register_source($slug, array $args) {
        // Deliberately NOT run through sanitize_key() (which strips '.') —
        // slugs here are namespaced with dots ('bhcore_events.count') the
        // same way BH_Event/BH_Content namespace with a slash, and are
        // never used as HTML attribute names or query args directly, only
        // as PHP array keys, so sanitize_key()'s HTML-attribute-safety
        // guarantee isn't the relevant property; trim() is enough.
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

    public static function is_registered($slug) {
        return isset(self::$sources[(string) $slug]);
    }

    /** slug => manifest MINUS the callable — safe to hand to the GUI/REST layer (§3.4 `GET elements/sources`). */
    public static function registered_sources() {
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

    /** Sources whose declared 'kind' matches a target attribute's kind — powers the inspector's source dropdown (§4). */
    public static function sources_for_kind($kind) {
        return array_filter(self::registered_sources(), function ($m) use ($kind) {
            return $m['kind'] === $kind;
        });
    }

    /* ---------------- resolution — the contract this whole class exists for ---------------- */

    /**
     * Resolve ONE attribute value against a render context.
     *
     * @param array $attr_value  Either { "bind": {...} } or { "literal": ... } — the
     *                            per-attribute shape stored in a placement's config.attrs.{key}.
     *                            Anything else (missing both keys, wrong type) is treated as
     *                            "no value" and falls through to $default.
     * @param array $ctx         Render context — user_id, post_id, entity_id, viewer_id, plus
     *                            whatever a surface's 'preview_ctx'/'context' contract (§3.3)
     *                            supplies. Never trust this array's contents as pre-sanitized;
     *                            resolve() only reads scalars out of it for context.* substitution.
     * @param mixed $default     What to return if there's no literal AND no usable binding —
     *                            lets a caller supply a schema-level default distinct from an
     *                            explicit empty literal.
     *
     * @return mixed The resolved value, UNESCAPED. Callers (BH_Element::render_placement())
     *               MUST escape per the attribute's declared output context before printing —
     *               this method has no idea whether the value lands in a text node, an href,
     *               or a data-attribute, so escaping here would either be wrong for some call
     *               sites or would double-escape for others.
     */
    public static function resolve(array $attr_value, array $ctx = [], $default = '') {
        // Explicit literal always wins if present, even if a 'bind' key also
        // exists alongside it (malformed dual-set config) — a literal is
        // never itself a failure mode, so prefer the safe branch.
        if (array_key_exists('literal', $attr_value)) {
            return $attr_value['literal'];
        }

        $binding = $attr_value['bind'] ?? null;
        if (!is_array($binding) || empty($binding['source'])) {
            return $default; // no binding, no literal — nothing to resolve, fail safe to caller's default
        }

        $slug = (string) $binding['source'];

        // LIBRARY-STRUCTURE-HYBRID-DESIGN-PLAN.md Phase 2 — fixture mode.
        // When rendering a Library item against a named state (element-
        // builder.js's isolated preview, or the Component/type preview
        // routes with a &state= param), $ctx carries a '__fixtures' map
        // of source slug => mock value, built from that state's own
        // authored data (class-element-state.php). If this exact binding
        // has a fixture, it wins OUTRIGHT — the real resolver below is
        // never called, so a Library preview can never read live DB data
        // even by accident. This is the ONLY place fixture mode plugs
        // into the resolution ladder; the binding descriptor itself
        // ({"bind":{"source":...}}) is completely unchanged and portable
        // — the exact same placement, unmodified, resolves against real
        // data the moment $ctx has no '__fixtures' key (every Structure-
        // tab / live-site render, unconditionally — this key is never
        // set there), which is what makes "binding ubiquitous across both
        // tabs" true by construction rather than by parallel effort.
        if (!empty($ctx['__fixtures']) && is_array($ctx['__fixtures']) && array_key_exists($slug, $ctx['__fixtures'])) {
            $value = $ctx['__fixtures'][$slug];
            $format_slug = isset($binding['format']) ? (string) $binding['format'] : '';
            if ($format_slug !== '' && isset(self::$formatters[$format_slug])) {
                try {
                    $formatted = call_user_func(self::$formatters[$format_slug], $value);
                    if ($formatted !== null) $value = $formatted;
                } catch (\Throwable $e) {
                    // fall through with the unformatted fixture value — same
                    // never-fatal posture the real resolver's own formatter
                    // step below already uses.
                }
            }
            return $value;
        }

        if (!isset(self::$sources[$slug])) {
            // Unregistered source — expected in normal operation whenever the
            // owning plugin is deactivated (same graceful-degrade posture as
            // BH_Content::render() skipping an unregistered block type). Not
            // logged: this is routine, not an operational problem.
            return $default;
        }

        $manifest = self::$sources[$slug];
        $args = self::resolve_args(is_array($binding['args'] ?? null) ? $binding['args'] : [], $ctx);

        // Legacy/simple 'subject' key (design doc example) is sugar for a
        // single extra arg named 'subject' resolved the same context.* way —
        // folded into $args so every resolver only has one place to look.
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
            // A source is explicitly allowed to say "no data here" by
            // returning null (e.g. a user with zero events) — that's not a
            // failure, it's real, meaningful information under this
            // context, so we fall back to the caller's default the same as
            // any other empty case rather than treating it as an error.
            return $default;
        }

        // §3.2 v1 — output formatters. Only reachable once a bind has
        // actually resolved to a real value (never applied to the
        // literal branch above, or to a default/fallback — a formatter
        // transforms genuine source output, matching the descriptor
        // shape's format key living INSIDE the 'bind' object). A named-
        // but-unregistered formatter, or one that throws, degrades to
        // the unformatted $value — one more rung on this method's
        // existing never-fatal fallback ladder, not a new failure mode.
        $format_slug = isset($binding['format']) ? (string) $binding['format'] : '';
        if ($format_slug !== '' && isset(self::$formatters[$format_slug])) {
            try {
                $formatted = call_user_func(self::$formatters[$format_slug], $value);
                if ($formatted !== null) $value = $formatted;
            } catch (\Throwable $e) {
                self::log_resolution_error($slug, "formatter '$format_slug' failed: " . $e->getMessage());
                // fall through with the unformatted $value — do not return $default here, the resolve itself succeeded
            }
        }

        return $value;
    }

    // Walks a binding's static args array, substituting any string value of
    // the exact form "context.<key>" with $ctx[<key>] ?? '' — this is the
    // ONLY templating this resolver does; arg values that merely contain
    // "context." as a substring elsewhere are left alone (not partial-
    // interpolated) to keep the contract simple and predictable for
    // future element authors.
    private static function resolve_args(array $args, array $ctx) {
        $out = [];
        foreach ($args as $key => $value) {
            $out[$key] = is_string($value) ? self::resolve_token($value, $ctx) : $value;
        }
        return $out;
    }

    private static function resolve_token($value, array $ctx) {
        if (is_string($value) && strpos($value, 'context.') === 0) {
            $key = substr($value, strlen('context.'));
            return $ctx[$key] ?? '';
        }
        return $value;
    }

    /* ---------------- quiet operational logging (never fatal, never surfaced to the visitor) ---------------- */

    private static function log_registration_problem($slug, $message) {
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('warning', "register_source($slug): $message", [], 'bh-element-data');
        }
    }

    private static function log_resolution_error($slug, $message) {
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('error', "resolve() failed for source '$slug': $message", [], 'bh-element-data');
        }
    }

    /* ---------------- Debug Tools: registered-sources inspector ---------------- */

    public static function register_debug_section($tools) {
        $tools['bh-element-data'] = [
            'label'  => 'Element Data Sources',
            'render' => [self::class, 'render_debug_section'],
            'group'  => OUS_Debug::GROUP_REFERENCE,
        ];
        return $tools;
    }

    public static function render_debug_section() {
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

        // §3.2 v1 — formatters, same table treatment as sources above.
        $formatters = self::registered_formatters();
        echo '<p class="description" style="margin-top:16px;">Registered output formatters (<code>BH_Element_Data::register_formatter()</code>) — an optional <code>"format"</code> key inside a binding descriptor names one of these to transform the resolved value before it renders (e.g. <code>1204</code> &rarr; <code>1.2k</code>).</p>';
        if (!$formatters) {
            echo '<p class="description">No formatters registered yet.</p>';
        } else {
            echo '<p><code>' . esc_html(implode('</code>, <code>', $formatters)) . '</code></p>';
        }
    }
}
