<?php
if (!defined('ABSPATH')) exit;

/**
 * Properties: every value that can be changed without editing code. Code files are the
 * definition and the defaults (each plugin's words.php, the Design Suite tokens); changes are
 * made only through these commands, which WP-CLI (`wp bh`), REST and the Debug Tools inspector
 * all call. A property id is "<provider>:<key>", e.g. words.courses:lesson.next_lesson.
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
     * Text providers store changes in bh_props_overrides. A provider that already has its own
     * storage passes current + write (+ validate, meta) instead, so there is still one copy.
     * @param array<string, mixed> $args label; defaults: callable returning key => string; file: code file
     *   for bake; current: callable returning key => current string; write: callable(key, ?string)
     *   where null means back to default; validate: callable(key, value) => clean string|WP_Error;
     *   meta: callable(key) => [type text|color|number|toggle|select, label, min, max, step, unit, options]
     */
    public static function register_provider(string $id, array $args): void {
        self::$providers[$id] = array_merge(['label' => $id, 'defaults' => null, 'file' => '', 'current' => null, 'write' => null, 'validate' => null, 'meta' => null], $args);
    }

    /** True when the provider keeps its own values (current + write) instead of bh_props_overrides. */
    private static function self_stored(string $provider): bool {
        $p = self::providers()[$provider] ?? [];
        return is_callable($p['current'] ?? null) && is_callable($p['write'] ?? null);
    }

    /** @return array<string, mixed> how to edit one property (type, label, min, max, unit, options) */
    public static function meta(string $provider, string $key): array {
        $p = self::providers()[$provider] ?? [];
        $m = is_callable($p['meta'] ?? null) ? call_user_func($p['meta'], $key) : [];
        return array_merge(['type' => 'text', 'label' => ''], is_array($m) ? $m : []);
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
        if (!self::self_stored($provider)) return self::all_overrides()[$provider] ?? [];
        // Self-stored: "changed" means the stored value differs from the code default.
        $out = [];
        $current = (array) call_user_func(self::providers()[$provider]['current']);
        foreach (self::defaults($provider) as $key => $default) {
            if (array_key_exists($key, $current) && (string) $current[$key] !== (string) $default) {
                $out[$key] = ['value' => (string) $current[$key], 'by' => 0, 'at' => 0];
            }
        }
        return $out;
    }

    /**
     * Defaults with this provider's overrides applied (unknown keys are ignored, so a key
     * removed from the code file can't linger).
     * @param array<string, string> $defaults
     * @return array<string, string>
     */
    public static function apply(string $provider, array $defaults): array {
        foreach (self::overrides($provider) as $key => $o) {
            if (array_key_exists($key, $defaults)) $defaults[$key] = (string) $o['value'];
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

    /**
     * Sets a property. $force skips the placeholder check.
     * @return true|\WP_Error
     */
    public static function set(string $id, string $value, bool $force = false) {
        $parsed = self::parse($id);
        if (is_wp_error($parsed)) return $parsed;
        [$provider, $key] = $parsed;
        $default = (string) self::defaults($provider)[$key];
        $validate = self::providers()[$provider]['validate'];
        if (is_callable($validate)) {
            $value = call_user_func($validate, $key, $value);
            if (is_wp_error($value)) return $value;
            $value = (string) $value;
        } else {
            $value = self::sanitize($value);
            if (strlen($value) > self::MAX_LENGTH) return new WP_Error('bh_props_length', 'That text is too long (max ' . self::MAX_LENGTH . ' characters).');
            if (!$force && ($missing = self::placeholder_problem($default, $value))) return new WP_Error('bh_props_placeholders', $missing);
        }

        if (self::self_stored($provider)) {
            $before = self::get($id)['value'];
            call_user_func(self::providers()[$provider]['write'], $key, $value === $default ? null : $value);
            self::log_and_notify($provider, $key, (string) $before, $value);
            return true;
        }
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

    /**
     * Back to the code-file default.
     * @return true|\WP_Error
     */
    public static function reset(string $id) {
        $parsed = self::parse($id);
        if (is_wp_error($parsed)) return $parsed;
        [$provider, $key] = $parsed;
        if (self::self_stored($provider)) {
            $row = self::get($id);
            if (!$row['changed']) return true;
            call_user_func(self::providers()[$provider]['write'], $key, null);
            self::log_and_notify($provider, $key, (string) $row['value'], (string) $row['default']);
            return true;
        }
        $all = self::all_overrides();
        if (!isset($all[$provider][$key])) return true;
        $before = (string) $all[$provider][$key]['value'];
        unset($all[$provider][$key]);
        self::save($all, $provider, $key, $before, self::defaults($provider)[$key]);
        return true;
    }

    /** Clears every override of one provider; returns how many were cleared. */
    public static function reset_provider(string $provider): int {
        if (self::self_stored($provider)) {
            $keys = array_keys(self::overrides($provider));
            foreach ($keys as $key) self::reset($provider . ':' . $key);
            return count($keys);
        }
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
        self::log_and_notify($provider, $key, $before, $after);
    }

    private static function log_and_notify(string $provider, string $key, string $before, string $after): void {
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

    /* ---------------- inspector: the same commands, from Debug Tools ---------------- */

    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register_debug_section($tools): array {
        $tools['bh-props'] = [
            'label' => 'Properties (wording & style)', 'render' => [self::class, 'render_inspector'],
            'handle' => [self::class, 'handle_inspector'], 'reset' => null, 'safe_in_production' => true, 'group' => OUS_Debug::GROUP_REFERENCE,
        ];
        return $tools;
    }

    /** Debug Tools link back to this section, keeping the chosen provider (and an optional property to edit). */
    private static function inspector_url(string $provider, string $edit = '', string $msg = ''): string {
        $args = ['page' => 'ous-debug', 'bh_props' => $provider];
        if ($edit !== '') $args['bh_props_edit'] = $edit;
        if ($msg !== '') $args['ous_msg'] = rawurlencode($msg);
        return add_query_arg($args, admin_url('admin.php')) . '#ous-section-bh-props';
    }

    /**
     * Save / Reset from the inspector's edit form (Debug Tools already checked nonce + manage_options).
     * @param array<string, mixed> $post
     */
    public static function handle_inspector(string $action, array $post): string {
        $id = sanitize_text_field(wp_unslash((string) ($post['bh_props_id'] ?? '')));
        $parsed = self::parse($id);
        $failed = true;
        if (is_wp_error($parsed)) {
            $provider = '';
            $msg = $parsed->get_error_message();
        } elseif ($action === 'prop_reset') {
            $provider = $parsed[0];
            $res = self::reset($id);
            $failed = is_wp_error($res);
            $msg = is_wp_error($res) ? $res->get_error_message() : 'Back to the default: ' . $id;
        } else {
            $provider = $parsed[0];
            // An unchecked toggle sends nothing. set() cleans the text, so only unslash here.
            $value = isset($post['bh_props_value']) ? (string) wp_unslash($post['bh_props_value']) : '0';
            $res = self::set($id, $value, !empty($post['bh_props_force']));
            $failed = is_wp_error($res);
            $msg = is_wp_error($res) ? 'Not saved: ' . $res->get_error_message() : 'Saved ' . $id . '.';
        }
        if (class_exists('OUS_Toast')) OUS_Toast::queue($msg, 'info');
        wp_safe_redirect(self::inspector_url($provider, $failed ? $id : '', $msg));
        exit;
    }

    public static function render_inspector(): void {
        $providers = self::providers();
        $current = isset($_GET['bh_props']) ? sanitize_text_field(wp_unslash($_GET['bh_props'])) : (string) array_key_first($providers);
        if (!isset($providers[$current])) $current = (string) array_key_first($providers);
        $changed_only = !empty($_GET['bh_props_changed']);
        echo '<p class="description">Every value that can change without editing code: wording and Design Suite styles. The code files hold the defaults. Click a property to change it here, or use the command line (<code>wp bh set &lt;id&gt; "value"</code>, see <code>wp help bh</code>); both go through the same checks.</p>';

        $edit = isset($_GET['bh_props_edit']) ? sanitize_text_field(wp_unslash($_GET['bh_props_edit'])) : '';
        if ($edit !== '') self::render_edit_form($edit);

        echo '<form method="get" class="bh-props-filter">';
        echo '<input type="hidden" name="page" value="ous-debug">';
        echo '<select name="bh_props">';
        foreach ($providers as $id => $p) {
            $n = count(self::overrides($id));
            echo '<option value="' . esc_attr($id) . '"' . selected($current, $id, false) . '>' . esc_html($p['label'] . ' (' . count(self::defaults($id)) . ($n ? ', ' . $n . ' changed' : '') . ')') . '</option>';
        }
        echo '</select> <label><input type="checkbox" name="bh_props_changed" value="1"' . checked($changed_only, true, false) . '> Changed only</label> ';
        echo '<button class="button">Show</button> ';
        echo '<input type="search" class="bhy-table-search bh-props-search" data-target="#bh-props-table" placeholder="Filter…">';
        echo '</form>';

        $rows = self::rows($current, '', $changed_only);
        if (!$rows) { echo '<p class="description">Nothing to show.</p>'; return; }
        echo '<div class="bhy-table-wrap bhy-table-wrap--tall"><table class="widefat striped" id="bh-props-table"><thead><tr><th>Property</th><th>Value</th><th>Changed</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $meta = self::meta($r['provider'], $r['key']);
            echo '<tr><td><a href="' . esc_url(self::inspector_url($current, $r['id'])) . '"><code>' . esc_html($r['id']) . '</code></a>';
            if ($meta['label'] !== '') echo '<br><span class="description">' . esc_html((string) $meta['label']) . '</span>';
            echo '</td><td>' . self::value_html((string) $r['value'], $meta);
            if ($r['changed']) echo '<br><span class="description">Default: ' . esc_html((string) $r['default']) . '</span>';
            echo '</td><td>';
            if ($r['changed']) {
                $who = $r['by'] ? get_userdata($r['by']) : null;
                echo '<span class="bhy-badge bhy-badge-warning">changed</span>';
                if ($r['at']) echo '<br><span class="description">' . esc_html(($who ? $who->display_name : 'command line') . ', ' . human_time_diff($r['at']) . ' ago') . '</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /** @param array<string, mixed> $meta */
    private static function value_html(string $value, array $meta): string {
        if ($meta['type'] === 'toggle') return $value === '1' ? 'on' : 'off';
        $text = esc_html($value . ($meta['type'] === 'number' ? (string) ($meta['unit'] ?? '') : ''));
        // WHY: an SVG fill attribute shows the color without inline CSS or JS.
        if ($meta['type'] === 'color') return '<svg class="bh-props-swatch" width="14" height="14" aria-hidden="true"><rect width="14" height="14" rx="3" fill="' . esc_attr($value) . '" stroke="#8c8f94"/></svg> ' . $text;
        return $text;
    }

    private static function render_edit_form(string $id): void {
        $row = self::get($id);
        if (is_wp_error($row)) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($row->get_error_message()) . '</p></div>';
            return;
        }
        $meta = self::meta((string) $row['provider'], (string) $row['key']);
        $name = 'bh_props_value';
        $value = (string) $row['value'];
        switch ($meta['type']) {
            case 'color':
                $input = '<input type="text" name="' . $name . '" value="' . esc_attr($value) . '" class="regular-text code"> <span class="description">#hex, rgb(), rgba() or transparent</span>';
                break;
            case 'number':
                $input = '<input type="number" name="' . $name . '" value="' . esc_attr($value) . '"'
                    . (isset($meta['min']) ? ' min="' . esc_attr((string) $meta['min']) . '"' : '')
                    . (isset($meta['max']) ? ' max="' . esc_attr((string) $meta['max']) . '"' : '')
                    . ' step="' . esc_attr((string) ($meta['step'] ?? 'any')) . '"> ' . esc_html((string) ($meta['unit'] ?? ''));
                break;
            case 'toggle':
                $input = '<label><input type="checkbox" name="' . $name . '" value="1"' . checked($value, '1', false) . '> On</label>';
                break;
            case 'select':
                $input = '<select name="' . $name . '">';
                foreach ((array) ($meta['options'] ?? []) as $opt) {
                    $input .= '<option value="' . esc_attr((string) $opt) . '"' . selected($value, (string) $opt, false) . '>' . esc_html((string) $opt) . '</option>';
                }
                $input .= '</select>';
                break;
            default:
                $input = '<textarea name="' . $name . '" rows="3" class="large-text">' . esc_textarea($value) . '</textarea>';
                if (strpos((string) $row['default'], '{') !== false) {
                    $input .= '<br><label><input type="checkbox" name="bh_props_force" value="1"> Save even if the {placeholders} don&#8217;t match</label>';
                }
        }
        echo '<div class="bh-props-edit"><h4><code>' . esc_html($id) . '</code>' . ($meta['label'] !== '' ? ' — ' . esc_html((string) $meta['label']) : '') . '</h4>';
        echo '<p class="description">Default: ' . esc_html((string) $row['default']) . '</p>';
        $hidden = '<input type="hidden" name="bh_props_id" value="' . esc_attr($id) . '">';
        OUS_Debug::button('bh-props', 'prop_save', 'Save', $hidden . $input . '<br>');
        if ($row['changed']) OUS_Debug::button('bh-props', 'prop_reset', 'Back to default', $hidden, '', false);
        echo ' <a href="' . esc_url(self::inspector_url((string) $row['provider'])) . '">Cancel</a></div>';
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

            // A provider with its own storage (like the Design Suite) goes through the same commands.
            $store = [];
            self::register_provider("$p.own", [
                'defaults' => fn() => ['size' => '10'],
                'current' => function () use (&$store) { return $store + ['size' => '10']; },
                'write' => function ($key, $value) use (&$store) { if ($value === null) unset($store[$key]); else $store[$key] = $value; },
                'validate' => fn($key, $value) => is_numeric($value) ? $value : new WP_Error('nan', 'Not a number'),
            ]);
            $r[] = OUS_TestRunner::assert_true(is_wp_error(self::set("$p.own:size", 'big')), 'A self-stored provider validates with its own rules');
            self::set("$p.own:size", '14');
            $r[] = OUS_TestRunner::assert_same(['size' => '14'], $store, 'set() writes to the provider\'s own storage');
            $r[] = OUS_TestRunner::assert_true(self::get("$p.own:size")['changed'], 'A self-stored value that differs from the default reports as changed');
            self::reset("$p.own:size");
            $r[] = OUS_TestRunner::assert_same([], $store, 'reset() clears it from the provider\'s storage');

            if (class_exists('BHY_Style_Props')) {
                $r[] = OUS_TestRunner::assert_true(is_wp_error(BHY_Style_Props::validate('color_accent', 'banana')), 'Style: a non-color is refused');
                $r[] = OUS_TestRunner::assert_true(is_wp_error(BHY_Style_Props::validate('radius', '99')), 'Style: a size outside its limits is refused');
                $r[] = OUS_TestRunner::assert_same('20', BHY_Style_Props::validate('radius', '20px'), 'Style: "20px" is read as 20');
                $r[] = OUS_TestRunner::assert_same('1', BHY_Style_Props::validate('components.badge.radius', '1'), 'Style: component tokens are properties too');
            }
        } finally {
            if ($saved === null) delete_option(self::OPTION); else update_option(self::OPTION, $saved, true);
            unset(self::$providers[$p], self::$providers["$p.own"]);
            self::$quiet = false;
        }
        return $r;
    }
}
