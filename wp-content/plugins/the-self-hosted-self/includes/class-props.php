<?php
if (!defined('ABSPATH')) exit;

/**
 * Properties: every value that can be changed without editing code. Code files are the
 * definition and the defaults (each plugin's words.php is a "provider"); changes are stored
 * as overrides and made only through these commands, which WP-CLI (`wp bh`), REST and a
 * future admin GUI all call. A property id is "<provider>:<key>", e.g. words.courses:lesson.next_lesson.
 */
class BH_Props {
    const OPTION = 'bh_props_overrides';
    const MAX_LENGTH = 5000;

    /** @var array<string, array<string, mixed>> */
    private static array $providers = [];
    private static bool $registered = false;
    private static bool $quiet = false; // tests: no audit entries

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
        add_filter('bhcore_test_suites', [self::class, 'register_test_suite']);
        if (defined('WP_CLI') && WP_CLI && class_exists('BH_Props_CLI')) BH_Props_CLI::register();
    }

    /* ---------------- providers ---------------- */

    /**
     * Called from a bh_props_register callback.
     * @param array<string, mixed> $args label, defaults (callable returning key => text), file (optional code file for bake)
     */
    public static function register_provider(string $id, array $args): void {
        self::$providers[$id] = array_merge(['label' => $id, 'defaults' => null, 'file' => ''], $args);
    }

    /** @return array<string, array<string, mixed>> */
    public static function providers(): array {
        if (!self::$registered) {
            self::$registered = true;
            do_action('bh_props_register');
        }
        ksort(self::$providers);
        return self::$providers;
    }

    /** @return array<string, string> the provider's code-file defaults */
    public static function defaults(string $provider): array {
        $p = self::providers()[$provider] ?? null;
        if (!$p || !is_callable($p['defaults'])) return [];
        $d = call_user_func($p['defaults']);
        return is_array($d) ? $d : [];
    }

    /* ---------------- reading ---------------- */

    /** @return array<string, array<string, array{value: string, by: int, at: int}>> */
    public static function all_overrides(): array {
        $o = get_option(self::OPTION, []);
        return is_array($o) ? $o : [];
    }

    /** @return array<string, array{value: string, by: int, at: int}> */
    public static function overrides(string $provider): array {
        return self::all_overrides()[$provider] ?? [];
    }

    /**
     * Defaults with this provider's overrides applied (unknown keys are ignored, so a key
     * removed from the code file can't linger).
     * @param array<string, string> $defaults
     * @return array<string, string>
     */
    public static function apply(string $provider, array $defaults): array {
        foreach (self::overrides($provider) as $key => $o) {
            if (array_key_exists($key, $defaults) && isset($o['value'])) $defaults[$key] = (string) $o['value'];
        }
        return $defaults;
    }

    /** @return array{0: string, 1: string}|\WP_Error */
    public static function parse(string $id) {
        $pos = strpos($id, ':');
        if ($pos === false) return new WP_Error('bh_props_id', 'A property id looks like "provider:key", e.g. words.courses:lesson.next_lesson.');
        $provider = substr($id, 0, $pos);
        $key = substr($id, $pos + 1);
        if (!isset(self::providers()[$provider])) return new WP_Error('bh_props_provider', 'Unknown provider "' . $provider . '". See `wp bh providers`.');
        if (!array_key_exists($key, self::defaults($provider))) return new WP_Error('bh_props_key', 'No property "' . $key . '" in ' . $provider . '.');
        return [$provider, $key];
    }

    /** @return array<string, mixed>|\WP_Error one property: id, provider, key, value, default, changed, by, at */
    public static function get(string $id) {
        $parsed = self::parse($id);
        if (is_wp_error($parsed)) return $parsed;
        [$provider, $key] = $parsed;
        return self::row($provider, $key, self::defaults($provider)[$key], self::overrides($provider)[$key] ?? null);
    }

    /**
     * @param array{value: string, by: int, at: int}|null $o
     * @return array<string, mixed>
     */
    private static function row(string $provider, string $key, string $default, ?array $o): array {
        return [
            'id' => $provider . ':' . $key, 'provider' => $provider, 'key' => $key,
            'value' => $o ? (string) $o['value'] : $default, 'default' => $default, 'changed' => (bool) $o,
            'by' => $o ? (int) $o['by'] : 0, 'at' => $o ? (int) $o['at'] : 0,
        ];
    }

    /** @return array<int, array<string, mixed>> every property of one provider (or all), optionally filtered */
    public static function rows(string $provider = '', string $search = '', bool $changed_only = false): array {
        $out = [];
        foreach (array_keys(self::providers()) as $p) {
            if ($provider !== '' && $p !== $provider) continue;
            $overrides = self::overrides($p);
            foreach (self::defaults($p) as $key => $default) {
                $row = self::row($p, (string) $key, (string) $default, $overrides[$key] ?? null);
                if ($changed_only && !$row['changed']) continue;
                if ($search !== '' && stripos($row['id'] . ' ' . $row['value'] . ' ' . $row['default'], $search) === false) continue;
                $out[] = $row;
            }
        }
        return $out;
    }

    /* ---------------- commands (the only way values change) ---------------- */

    /** Sets a property. $force skips the placeholder check. @return true|\WP_Error */
    public static function set(string $id, string $value, bool $force = false) {
        $parsed = self::parse($id);
        if (is_wp_error($parsed)) return $parsed;
        [$provider, $key] = $parsed;
        $default = self::defaults($provider)[$key];
        $value = self::sanitize($value);
        if (strlen($value) > self::MAX_LENGTH) return new WP_Error('bh_props_length', 'That text is too long (max ' . self::MAX_LENGTH . ' characters).');
        if (!$force && ($missing = self::placeholder_problem($default, $value))) return new WP_Error('bh_props_placeholders', $missing);

        $all = self::all_overrides();
        $before = $all[$provider][$key]['value'] ?? $default;
        if ($value === $default) {
            unset($all[$provider][$key]);
        } else {
            $all[$provider][$key] = ['value' => $value, 'by' => get_current_user_id(), 'at' => time()];
        }
        self::save($all, $provider, $key, $before, $value);
        return true;
    }

    /** Back to the code-file default. @return true|\WP_Error */
    public static function reset(string $id) {
        $parsed = self::parse($id);
        if (is_wp_error($parsed)) return $parsed;
        [$provider, $key] = $parsed;
        $all = self::all_overrides();
        if (!isset($all[$provider][$key])) return true;
        $before = (string) $all[$provider][$key]['value'];
        unset($all[$provider][$key]);
        self::save($all, $provider, $key, $before, self::defaults($provider)[$key]);
        return true;
    }

    /** Clears every override of one provider; returns how many were cleared. */
    public static function reset_provider(string $provider): int {
        $all = self::all_overrides();
        $n = count($all[$provider] ?? []);
        if ($n) {
            unset($all[$provider]);
            self::save($all, $provider, '*', $n . ' overrides', 'defaults');
        }
        return $n;
    }

    /**
     * Writes a provider's overrides into its code file (so they can be committed) and clears
     * them. Only one-entry-per-line 'key' => 'text', files are supported.
     * @return array{baked: string[], missing: string[]}|\WP_Error
     */
    public static function bake(string $provider) {
        $p = self::providers()[$provider] ?? null;
        if (!$p) return new WP_Error('bh_props_provider', 'Unknown provider "' . $provider . '".');
        $file = (string) $p['file'];
        if ($file === '' || !is_writable($file)) return new WP_Error('bh_props_file', 'This provider has no writable code file.');
        $src = (string) file_get_contents($file);
        $baked = $missing = [];
        foreach (self::overrides($provider) as $key => $o) {
            $re = '/^(\s*' . preg_quote(self::php_literal((string) $key), '/') . '\s*=>\s*)\'(?:[^\'\\\\]|\\\\.)*\'(\s*,)/m';
            $count = 0;
            $src = (string) preg_replace_callback($re, fn($m) => $m[1] . self::php_literal((string) $o['value']) . $m[2], $src, 1, $count);
            if ($count) $baked[] = (string) $key; else $missing[] = (string) $key;
        }
        if ($baked) {
            file_put_contents($file, $src);
            $all = self::all_overrides();
            foreach ($baked as $key) unset($all[$provider][$key]);
            self::save($all, $provider, '*', 'overrides', 'baked into ' . basename($file));
        }
        return ['baked' => $baked, 'missing' => $missing];
    }

    /** @param array<string, array<string, array{value: string, by: int, at: int}>> $all */
    private static function save(array $all, string $provider, string $key, string $before, string $after): void {
        $all = array_filter($all);
        update_option(self::OPTION, $all, true);
        if (!self::$quiet && class_exists('OUS_Audit')) {
            OUS_Audit::log_diff('prop_changed', 'property', 0, [$provider . ':' . $key => $before], [$provider . ':' . $key => $after], ['via' => (defined('WP_CLI') && WP_CLI) ? 'cli' : 'web']);
        }
        do_action('bh_props_changed', $provider, $key);
    }

    private static function sanitize(string $value): string {
        // Words may carry small inline HTML (<code>, <strong>, links); nothing that runs.
        return wp_kses_post($value);
    }

    /** A message when the new text drops or adds a {placeholder} the default has. */
    public static function placeholder_problem(string $default, string $value): string {
        preg_match_all('/\{([a-z_]+)\}/', $default, $d);
        preg_match_all('/\{([a-z_]+)\}/', $value, $v);
        $missing = array_diff(array_unique($d[1]), $v[1]);
        $extra = array_diff(array_unique($v[1]), $d[1]);
        if (!$missing && !$extra) return '';
        $msg = [];
        if ($missing) $msg[] = 'keep ' . implode(', ', array_map(fn($n) => '{' . $n . '}', $missing));
        if ($extra) $msg[] = 'remove ' . implode(', ', array_map(fn($n) => '{' . $n . '}', $extra)) . ' (nothing fills it in)';
        return 'Placeholders don\'t match the original: ' . implode('; ', $msg) . '. Use --force to save anyway.';
    }

    public static function php_literal(string $s): string {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $s) . "'";
    }

    /* ---------------- REST: the same commands for a future admin screen ---------------- */

    public static function register_routes(): void {
        $can = fn() => current_user_can('manage_options');
        register_rest_route('bh/v1', '/props', [
            ['methods' => 'GET', 'permission_callback' => $can, 'callback' => fn(\WP_REST_Request $r) => rest_ensure_response(
                self::rows((string) $r->get_param('provider'), (string) $r->get_param('search'), (bool) $r->get_param('changed'))
            )],
            ['methods' => 'POST', 'permission_callback' => $can, 'callback' => function (\WP_REST_Request $r) {
                $res = self::set((string) $r->get_param('id'), (string) $r->get_param('value'), (bool) $r->get_param('force'));
                return is_wp_error($res) ? $res : rest_ensure_response(self::get((string) $r->get_param('id')));
            }],
            ['methods' => 'DELETE', 'permission_callback' => $can, 'callback' => function (\WP_REST_Request $r) {
                $res = self::reset((string) $r->get_param('id'));
                return is_wp_error($res) ? $res : rest_ensure_response(self::get((string) $r->get_param('id')));
            }],
        ]);
        register_rest_route('bh/v1', '/props/providers', [
            'methods' => 'GET', 'permission_callback' => $can,
            'callback' => fn() => rest_ensure_response(array_map(fn($id, $p) => [
                'id' => $id, 'label' => $p['label'], 'count' => count(self::defaults($id)), 'changed' => count(self::overrides($id)),
            ], array_keys(self::providers()), self::providers())),
        ]);
    }

    /* ---------------- inspector (read-only for now) ---------------- */

    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register_debug_section($tools): array {
        $tools['bh-props'] = [
            'label' => 'Properties (wording)', 'render' => [self::class, 'render_inspector'],
            'handle' => null, 'reset' => null, 'safe_in_production' => true, 'group' => OUS_Debug::GROUP_REFERENCE,
        ];
        return $tools;
    }

    public static function render_inspector(): void {
        $providers = self::providers();
        $current = isset($_GET['bh_props']) ? sanitize_text_field(wp_unslash($_GET['bh_props'])) : (string) array_key_first($providers);
        $changed_only = !empty($_GET['bh_props_changed']);
        echo '<p class="description">Every value that can change without editing code. The code files hold the defaults; changes are saved as overrides. Change them from the command line (<code>wp bh set &lt;id&gt; "text"</code>, see <code>wp help bh</code>) — an editing screen will use the same commands.</p>';
        echo '<form method="get" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0;">';
        echo '<input type="hidden" name="page" value="ous-debug">';
        echo '<select name="bh_props">';
        foreach ($providers as $id => $p) {
            $n = count(self::overrides($id));
            echo '<option value="' . esc_attr($id) . '"' . selected($current, $id, false) . '>' . esc_html($p['label'] . ' (' . count(self::defaults($id)) . ($n ? ', ' . $n . ' changed' : '') . ')') . '</option>';
        }
        echo '</select> <label><input type="checkbox" name="bh_props_changed" value="1"' . checked($changed_only, true, false) . '> Changed only</label> ';
        echo '<button class="button">Show</button> ';
        echo '<input type="search" class="bhy-table-search" data-target="#bh-props-table" placeholder="Filter…" style="min-width:220px;">';
        echo '</form>';

        $rows = self::rows($current, '', $changed_only);
        if (!$rows) { echo '<p class="description">Nothing to show.</p>'; return; }
        echo '<div class="bhy-table-wrap bhy-table-wrap--tall"><table class="widefat striped" id="bh-props-table"><thead><tr><th>Property</th><th>Value</th><th>Changed</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td><code>' . esc_html($r['id']) . '</code></td><td>' . esc_html($r['value']);
            if ($r['changed']) echo '<br><span class="description">Default: ' . esc_html($r['default']) . '</span>';
            echo '</td><td>';
            if ($r['changed']) {
                $who = $r['by'] ? get_userdata($r['by']) : null;
                echo '<span class="bhy-badge bhy-badge-warning">changed</span><br><span class="description">'
                    . esc_html(($who ? $who->display_name : 'command line') . ', ' . human_time_diff($r['at']) . ' ago') . '</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /* ---------------- tests ---------------- */

    /**
     * @param array<string, mixed> $suites
     * @return array<string, mixed>
     */
    public static function register_test_suite($suites): array {
        $suites['bh-props'] = ['label' => 'Properties (BH_Props)', 'callback' => [self::class, 'run_tests']];
        return $suites;
    }

    /** @return array<int, array<string, mixed>> */
    public static function run_tests(): array {
        $saved = get_option(self::OPTION, null);
        self::$quiet = true;
        $p = 'test.props_suite';
        self::register_provider($p, ['label' => 'Test', 'defaults' => fn() => ['greet' => 'Hello {name}', 'plain' => 'Plain text']]);
        $r = [];
        try {
            $r[] = OUS_TestRunner::assert_same('Hello {name}', self::get("$p:greet")['value'], 'get() returns the code-file default');
            $r[] = OUS_TestRunner::assert_true(is_wp_error(self::get('nope')), 'An id without "provider:" is rejected');
            $r[] = OUS_TestRunner::assert_true(is_wp_error(self::get("$p:missing")), 'An unknown key is rejected');
            $r[] = OUS_TestRunner::assert_true(self::set("$p:greet", 'Hi {name}!') === true, 'set() accepts text that keeps its placeholders');
            $r[] = OUS_TestRunner::assert_same('Hi {name}!', self::apply($p, ['greet' => 'Hello {name}'])['greet'], 'apply() puts the override over the default');
            $r[] = OUS_TestRunner::assert_true(self::get("$p:greet")['changed'], 'The property reports itself as changed');
            $r[] = OUS_TestRunner::assert_true(is_wp_error(self::set("$p:greet", 'Hi there')), 'Dropping a {placeholder} is refused');
            $r[] = OUS_TestRunner::assert_true(self::set("$p:greet", 'Hi there', true) === true, '--force saves it anyway');
            self::set("$p:plain", 'Bold <script>alert(1)</script><strong>ok</strong>');
            $r[] = OUS_TestRunner::assert_same('Bold alert(1)<strong>ok</strong>', self::get("$p:plain")['value'], 'Scripts are stripped; inline HTML is kept');
            $r[] = OUS_TestRunner::assert_same(['other' => 'Y'], self::apply($p, ['other' => 'Y']), 'apply() ignores overrides for keys the code no longer has');
            self::set("$p:plain", 'Plain text');
            $r[] = OUS_TestRunner::assert_false(isset(self::overrides($p)['plain']), 'Setting a value back to its default removes the override');
            self::reset("$p:greet");
            $r[] = OUS_TestRunner::assert_same('Hello {name}', self::get("$p:greet")['value'], 'reset() restores the default');
            $r[] = OUS_TestRunner::assert_same(1, count(array_filter(self::rows($p), fn($row) => $row['key'] === 'greet')), 'rows() lists the provider properties');
        } finally {
            if ($saved === null) delete_option(self::OPTION); else update_option(self::OPTION, $saved, true);
            unset(self::$providers[$p]);
            self::$quiet = false;
        }
        return $r;
    }
}
