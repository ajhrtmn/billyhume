<?php
if (!defined('ABSPATH')) exit;

/**
 * Context-aware colour resolver. From the handful of SEED colours an
 * admin actually picks in the Design Suite (bg / surface / border /
 * text / accent / category swatches) it derives the full set of
 * dependent roles — inks, muted/disabled text, strong borders, quiet-UI
 * fills, focus ring, and the accent state ladder — each generated to
 * hit an explicit perceptual-contrast target rather than hand-picked
 * and hoped-for.
 *
 * Design notes:
 *   - The recipe for every derived role lives in one place: self::SPEC.
 *     resolve() interprets it here; the same array is localised to
 *     contrast-core.ts so the Design Suite live preview derives colours
 *     identically without a second hand-maintained list.
 *   - The maths is in BHY_Color (pure, no WP). This class is the
 *     WordPress-aware adapter: it reads BHY_Style settings, caches the
 *     result on save, wires the audit into Debug Tools, and registers a
 *     Test Runner suite.
 *   - APCA-style Lc drives the derivation; WCAG 2.x is still measured
 *     and shown in the audit for compliance reporting.
 *   - The four roles BHY_Style already derived before this class
 *     existed (--bh-accent-muted-bg / -contrast / -hover / -pressed)
 *     are reproduced byte-for-byte so switching BHY_Style onto the
 *     resolver changes nothing on a live site; every other role is new.
 */
class BHY_Contrast {

    /** Non-autoload option holding the derived palette for the global (no-entity) settings. */
    const CACHE_OPTION = 'bhy_contrast_palette';

    /**
     * Ordered recipe map: derived CSS custom property => how to build it.
     * Order matters — a role may reference an earlier derived role
     * (e.g. --bh-on-ui needs --bh-ui). `on-cat-N` rows are appended by
     * spec() so this literal stays readable.
     *
     * derive:
     *   'mix'      from + with + weight (fraction of `from`); emit
     *              'color-mix' (CSS string, keeps runtime parity with the
     *              old code) or 'hex' (pre-computed).
     *   'ink-on'   on + inks[] + metric ('apca'|'wcag'); picks the
     *              strongest-reading ink.
     *   'solve-min' base + target_lc; moves base's OKLCH lightness until
     *              it clears target_lc against `against` (the hardest one
     *              if `against` is a list).
     *   'approach' base + target_lc + chroma_mul; lands NEAR target_lc
     *              (bounded contrast — the muted / disabled move).
     *
     * against: the surface a role is measured on, for the audit and for
     *          the solvers. null = not a foreground role (state fills).
     * target:  audit PASS threshold {apca, wcag}. ceiling: audit warns
     *          if contrast is ABOVE this (a "disabled" that stopped
     *          looking disabled).
     */
    const SPEC = [
        // ---- reproduced from pre-resolver BHY_Style, unchanged ----
        '--bh-accent-muted-bg' => [
            'label' => 'Accent muted background', 'intent' => 'safe tint behind body text',
            'against' => '--bh-text', 'derive' => 'mix',
            'from' => '--bh-accent', 'with' => '--bh-surface', 'weight' => 0.18, 'emit' => 'color-mix',
            'target' => ['apca' => 55, 'wcag' => 4.5],
        ],
        '--bh-accent-contrast' => [
            'label' => 'Ink on accent fill (legacy)', 'intent' => 'reproduces the pre-resolver WCAG-derived value',
            'against' => '--bh-accent', 'derive' => 'ink-on',
            'on' => '--bh-accent', 'inks' => ['--bh-text', '--bh-bg'], 'metric' => 'wcag', 'emit' => 'hex',
            'target' => ['apca' => 25, 'wcag' => 3.0],
        ],
        '--bh-accent-hover' => [
            'label' => 'Accent fill hover', 'intent' => 'accent fill on hover (deepens under light ink)',
            'against' => null, 'derive' => 'mix',
            'from' => '--bh-accent', 'with' => 'black', 'weight' => 0.85, 'emit' => 'color-mix',
        ],
        '--bh-accent-pressed' => [
            'label' => 'Accent fill pressed', 'intent' => 'accent fill, active',
            'against' => null, 'derive' => 'mix',
            'from' => '--bh-accent', 'with' => 'black', 'weight' => 0.70, 'emit' => 'color-mix',
        ],

        // ---- new derived roles ----
        '--bh-on-accent' => [
            'label' => 'On accent', 'intent' => 'perceptually-chosen ink for a filled accent surface',
            'against' => '--bh-accent', 'derive' => 'ink-on',
            'on' => '--bh-accent', 'inks' => ['--bh-text', '--bh-bg', '#0b0b0b', '#ffffff'], 'emit' => 'hex',
            'target' => ['apca' => 45, 'wcag' => 0],
        ],
        '--bh-on-surface' => [
            'label' => 'On surface', 'intent' => 'body ink on the primary surface',
            'against' => '--bh-surface', 'derive' => 'ink-on',
            'on' => '--bh-surface', 'inks' => ['--bh-text', '--bh-bg', '#0b0b0b', '#ffffff'], 'emit' => 'hex',
            'target' => ['apca' => 72, 'wcag' => 4.5],
        ],
        '--bh-on-surface-2' => [
            'label' => 'On raised surface', 'intent' => 'body ink on surface-2',
            'against' => '--bh-surface-2', 'derive' => 'ink-on',
            'on' => '--bh-surface-2', 'inks' => ['--bh-text', '--bh-bg', '#0b0b0b', '#ffffff'], 'emit' => 'hex',
            'target' => ['apca' => 72, 'wcag' => 4.5],
        ],
        '--bh-text-muted' => [
            'label' => 'Muted text', 'intent' => 'secondary text: legible but recessive',
            'against' => '--bh-surface', 'derive' => 'approach',
            'base' => '--bh-text-dim', 'target_lc' => 56, 'chroma_mul' => 1.0, 'emit' => 'hex',
            'target' => ['apca' => 52, 'wcag' => 3.0], 'ceiling' => ['apca' => 82],
        ],
        '--bh-text-disabled' => [
            'label' => 'Disabled text', 'intent' => 'present, deliberately not for you',
            'against' => '--bh-surface', 'derive' => 'approach',
            'base' => '--bh-text', 'target_lc' => 28, 'chroma_mul' => 0.12, 'emit' => 'hex',
            'target' => ['apca' => 20, 'wcag' => 1.6], 'ceiling' => ['apca' => 45],
        ],
        '--bh-border-strong' => [
            'label' => 'Strong border', 'intent' => 'visible divider / input outline (non-text: APCA only)',
            'against' => '--bh-surface', 'derive' => 'solve-min',
            'base' => '--bh-border', 'target_lc' => 34, 'emit' => 'hex',
            'target' => ['apca' => 30, 'wcag' => 0],
        ],
        '--bh-ui' => [
            'label' => 'Quiet UI fill', 'intent' => 'chip / input / quiet button fill',
            'against' => '--bh-surface', 'derive' => 'mix',
            'from' => '--bh-surface', 'with' => '--bh-text', 'weight' => 0.94, 'emit' => 'hex',
        ],
        '--bh-ui-hover' => [
            'label' => 'Quiet UI fill hover', 'intent' => 'chip / input hover',
            'against' => '--bh-surface', 'derive' => 'mix',
            'from' => '--bh-surface', 'with' => '--bh-text', 'weight' => 0.88, 'emit' => 'hex',
        ],
        '--bh-ui-active' => [
            'label' => 'Quiet UI fill active', 'intent' => 'chip / input pressed',
            'against' => '--bh-surface', 'derive' => 'mix',
            'from' => '--bh-surface', 'with' => '--bh-text', 'weight' => 0.82, 'emit' => 'hex',
        ],
        '--bh-on-ui' => [
            'label' => 'On quiet UI fill', 'intent' => 'ink on a chip / quiet button',
            'against' => '--bh-ui', 'derive' => 'ink-on',
            'on' => '--bh-ui', 'inks' => ['--bh-text', '--bh-bg', '#0b0b0b', '#ffffff'], 'emit' => 'hex',
            'target' => ['apca' => 60, 'wcag' => 4.5],
        ],
        '--bh-focus' => [
            'label' => 'Focus ring', 'intent' => 'keyboard focus outline, visible on surface and page',
            'against' => ['--bh-surface', '--bh-bg'], 'derive' => 'solve-min',
            'base' => '--bh-accent', 'target_lc' => 45, 'emit' => 'hex',
            'target' => ['apca' => 42, 'wcag' => 0],
        ],
    ];

    /** SPEC + the eight programmatic on-cat-N rows. */
    public static function spec(): array {
        $spec = self::SPEC;
        for ($i = 1; $i <= 8; $i++) {
            $spec['--bh-on-cat-' . $i] = [
                'label' => 'On category ' . $i, 'intent' => 'ink on the category-' . $i . ' swatch (decorative / label)',
                'against' => '--bh-cat-' . $i, 'derive' => 'ink-on',
                'on' => '--bh-cat-' . $i, 'inks' => ['--bh-text', '--bh-bg', '#0b0b0b', '#ffffff'], 'emit' => 'hex',
                'target' => ['apca' => 50, 'wcag' => 0],
            ];
        }
        return $spec;
    }

    // ---- seeds -------------------------------------------------------

    /** BHY_Style field name => the --bh-* seed var it feeds. */
    const SEED_FIELDS = [
        'color_bg' => '--bh-bg', 'color_surface' => '--bh-surface', 'color_surface_2' => '--bh-surface-2',
        'color_border' => '--bh-border', 'color_text' => '--bh-text', 'color_text_dim' => '--bh-text-dim',
        'color_accent' => '--bh-accent', 'color_accent_soft' => '--bh-accent-soft',
    ];

    /**
     * Pull the seed hexes out of a BHY_Style settings array. WP-free and
     * self-sanitising (a non-hex value falls back to black) so resolve()
     * can stay pure maths on clean input.
     *
     * @param array<string, mixed> $settings
     * @return array<string, string>
     */
    public static function seeds_from_settings(array $settings): array {
        $seeds = [];
        foreach (self::SEED_FIELDS as $field => $var) {
            $seeds[$var] = self::hex_or_black($settings[$field] ?? '');
        }
        for ($i = 1; $i <= 8; $i++) {
            $seeds['--bh-cat-' . $i] = self::hex_or_black($settings['cat_color_' . $i] ?? '');
        }
        return $seeds;
    }

    private static function hex_or_black($val): string {
        $val = trim((string) $val);
        // color-mix / rgb() seeds can't feed OKLCH maths; the resolver
        // only consumes plain hex, everything else fails safe to black.
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $val)) return strtolower($val);
        if (preg_match('/^#[0-9a-fA-F]{3}$/', $val)) {
            return strtolower('#' . $val[1] . $val[1] . $val[2] . $val[2] . $val[3] . $val[3]);
        }
        if (preg_match('/^#[0-9a-fA-F]{8}$/', $val)) return strtolower(substr($val, 0, 7));
        return '#000000';
    }

    // ---- the resolver ---------------------------------------------

    /**
     * Derive every role from the seed hexes. Returns ONLY the derived
     * `--bh-* => value` map (values may be `color-mix()` strings); the
     * caller still emits the raw seed vars itself, unchanged.
     *
     * @param array<string, string> $seeds  --bh-* => #hex
     * @return array<string, string>
     */
    public static function resolve(array $seeds): array {
        [$css] = self::resolve_both($seeds);
        return $css;
    }

    /**
     * Every role — seeds included — as a concrete #hex, with any
     * `color-mix()` recipe pre-computed. For the audit and the tests.
     *
     * @param array<string, string> $seeds
     * @return array<string, string>
     */
    public static function resolve_concrete(array $seeds): array {
        [, $concrete] = self::resolve_both($seeds);
        return $concrete;
    }

    /**
     * @param array<string, string> $seeds
     * @return array{0: array<string,string>, 1: array<string,string>}  [cssValues, concreteHex]
     */
    private static function resolve_both(array $seeds): array {
        $concrete = [];
        foreach ($seeds as $k => $v) $concrete[$k] = self::hex_or_black($v);

        $css = [];
        foreach (self::spec() as $name => $r) {
            $ref = function (string $key) use ($concrete): ?string {
                if ($key === 'black') return '#000000';
                if ($key === 'white') return '#ffffff';
                if ($key !== '' && $key[0] === '#') return $key;
                return $concrete[$key] ?? null;
            };

            $value = null;   // css output
            $hex = null;      // concrete output

            switch ($r['derive']) {
                case 'mix':
                    $a = $ref($r['from']);
                    $b = $ref($r['with']);
                    if ($a === null || $b === null) break;
                    $pct = self::fmt_pct($r['weight'] * 100);
                    $b_css = ($r['with'] === 'black' || $r['with'] === 'white') ? $r['with'] : $b;
                    $hex = BHY_Color::mix_srgb($a, $b, (float) $r['weight']);
                    $value = ($r['emit'] ?? 'hex') === 'color-mix'
                        ? "color-mix(in srgb, {$a} {$pct}%, {$b_css})"
                        : $hex;
                    break;

                case 'ink-on':
                    $bg = $ref($r['on']);
                    if ($bg === null) break;
                    $inks = [];
                    foreach ($r['inks'] as $ink) {
                        $resolved = $ref($ink);
                        if ($resolved !== null) $inks[] = $resolved;
                    }
                    if (!$inks) break;
                    $metric = $r['metric'] ?? 'apca';
                    // Prefer the theme's own ink when it already clears
                    // the role target; only fall through to black/white
                    // when it genuinely doesn't. WCAG-metric legacy roles
                    // keep exact "max ratio" behaviour (min = 0).
                    $min = $metric === 'apca' ? (float) ($r['target']['apca'] ?? 0) : 0.0;
                    $hex = BHY_Color::best_ink_on($bg, $inks, $metric, $min);
                    $value = $hex;
                    break;

                case 'solve-min':
                    $base = $ref($r['base']);
                    if ($base === null) break;
                    $hex = self::solve_min($base, self::against_list($r, $ref), (float) $r['target_lc']);
                    $value = $hex;
                    break;

                case 'approach':
                    $base = $ref($r['base']);
                    $against = $ref(is_array($r['against']) ? $r['against'][0] : $r['against']);
                    if ($base === null || $against === null) break;
                    $hex = BHY_Color::approach_apca_target(
                        $base, $against, (float) $r['target_lc'], (float) ($r['chroma_mul'] ?? 1.0)
                    );
                    $value = $hex;
                    break;
            }

            if ($value !== null && $hex !== null) {
                $css[$name] = $value;
                $concrete[$name] = $hex;
            }
        }

        return [$css, $concrete];
    }

    /**
     * Solve base's lightness to clear $target_lc against the HARDEST of
     * one-or-more backgrounds: try each, keep whichever candidate has
     * the highest worst-case Lc across all of them.
     *
     * @param array<string> $againsts
     */
    private static function solve_min(string $base, array $againsts, float $target_lc): string {
        $best = null;
        $best_worst = -1.0;
        foreach ($againsts as $against) {
            $cand = BHY_Color::solve_lightness_for_apca($base, $against, $target_lc, 0);
            $worst = INF;
            foreach ($againsts as $a) $worst = min($worst, BHY_Color::apca_lc_abs($cand, $a));
            if ($worst > $best_worst) {
                $best_worst = $worst;
                $best = $cand;
            }
        }
        return $best ?? $base;
    }

    /** @param callable $ref  @return array<string> */
    private static function against_list(array $r, callable $ref): array {
        $raw = $r['against'] ?? [];
        $raw = is_array($raw) ? $raw : [$raw];
        $out = [];
        foreach ($raw as $key) {
            $v = $ref($key);
            if ($v !== null) $out[] = $v;
        }
        return $out ?: ['#000000'];
    }

    private static function fmt_pct(float $n): string {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    // ---- WP adapter: settings -> palette, with caching -------------

    /**
     * The derived `--bh-* => value` map for a BHY_Style settings array.
     * inline_css() calls this.
     *
     * @param array<string, mixed> $settings
     * @return array<string, string>
     */
    public static function for_settings(array $settings): array {
        return self::resolve(self::seeds_from_settings($settings));
    }

    /**
     * Cached derived palette for the GLOBAL settings (the common,
     * no-entity path). Falls back to a live resolve on a cache miss or a
     * seed change the save hook hasn't caught yet — a miss just costs a
     * recompute, never a wrong colour, so a plain option is the right
     * store (not OUS_ReliableStore).
     *
     * @return array<string, string>
     */
    public static function global_palette(): array {
        $settings = BHY_Style::get();
        $seeds = self::seeds_from_settings($settings);
        $sig = md5(implode('|', $seeds));

        $cached = get_option(self::CACHE_OPTION);
        if (is_array($cached) && ($cached['sig'] ?? null) === $sig && is_array($cached['vars'] ?? null)) {
            return $cached['vars'];
        }

        // Cache miss / stale (fresh install, or a save the hook hasn't
        // processed yet): compute live, do NOT write from the render
        // path. refresh_cache() on the next settings save repopulates.
        return self::resolve($seeds);
    }

    private static function store_palette(string $sig, array $vars): void {
        // Autoload 'no': read only by inline_css(), which already loads
        // this class; no reason to carry it on every page's option bulk.
        update_option(self::CACHE_OPTION, ['sig' => $sig, 'vars' => $vars], false);
    }

    // ---- hooks ----------------------------------------------------

    public static function init(): void {
        // One seam catches all three BHY_Style write paths (gallery save,
        // revision restore, REST token save) without editing any of them.
        add_action('update_option_' . BHY_Style::OPTION, [self::class, 'refresh_cache'], 10, 0);
        add_action('add_option_' . BHY_Style::OPTION, [self::class, 'refresh_cache'], 10, 0);

        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
        add_filter('bhcore_test_suites', [self::class, 'register_test_suite']);
    }

    public static function refresh_cache(): void {
        $settings = BHY_Style::get();
        $seeds = self::seeds_from_settings($settings);
        $vars = self::resolve($seeds);
        self::store_palette(md5(implode('|', $seeds)), $vars);
        if (class_exists('OUS_Revisions')) {
            OUS_Revisions::snapshot('bhy_contrast', 1, ['seeds' => $seeds, 'vars' => $vars]);
        }
    }

    public static function register_test_suite($suites) {
        if (class_exists('OUS_ContrastTestSuite')) {
            $suites['bhy-contrast'] = ['label' => 'BHY Contrast (colour resolver)', 'callback' => ['OUS_ContrastTestSuite', 'run']];
        }
        return $suites;
    }

    // ---- audit --------------------------------------------------

    /**
     * One measured row per checked pairing. Covers the derived roles
     * (against their own target) AND the raw seed pairings admins rely
     * on but the old system never checked (text on surface, dim text,
     * accent-as-text on the ground) — the pairs that quietly fail when
     * someone picks a new theme.
     *
     * @param array<string, string> $seeds
     * @return list<array<string, mixed>>
     */
    public static function audit_rows(array $seeds): array {
        $c = self::resolve_concrete($seeds);
        $rows = [];

        $measure = function (string $label, string $intent, string $fg_key, string $bg_key, array $target, ?array $ceiling = null) use ($c): array {
            $fg = $c[$fg_key] ?? $fg_key;
            $bg = $c[$bg_key] ?? $bg_key;
            $apca = BHY_Color::apca_lc_abs($fg, $bg);
            $wcag = BHY_Color::wcag_ratio($fg, $bg);
            $pass = $apca >= ($target['apca'] ?? 0) && $wcag >= ($target['wcag'] ?? 0);
            $note = '';
            if ($pass && $ceiling && isset($ceiling['apca']) && $apca > $ceiling['apca']) {
                $pass = false;
                $note = 'too strong — lost its "not for you" read';
            }
            return [
                'label' => $label, 'intent' => $intent,
                'fg' => $fg, 'bg' => $bg,
                'apca' => $apca, 'wcag' => $wcag,
                'target_apca' => $target['apca'] ?? 0, 'target_wcag' => $target['wcag'] ?? 0,
                'pass' => $pass, 'note' => $note,
            ];
        };

        // Raw seed pairings — the hand-picked-and-hoped-for set the old
        // system never checked. Strict targets live HERE; these are the
        // rows that catch a bad theme choice.
        $rows[] = $measure('Body text on surface', 'primary reading text', '--bh-text', '--bh-surface', ['apca' => 72, 'wcag' => 4.5]);
        $rows[] = $measure('Body text on background', 'primary reading text', '--bh-text', '--bh-bg', ['apca' => 72, 'wcag' => 4.5]);
        $rows[] = $measure('Dim text on surface', 'secondary text (seed field --bh-text-dim)', '--bh-text-dim', '--bh-surface', ['apca' => 45, 'wcag' => 3.0]);
        $rows[] = $measure('Accent as text on surface', 'accent used directly as a text colour', '--bh-accent', '--bh-surface', ['apca' => 55, 'wcag' => 4.5]);
        $rows[] = $measure('Accent as text on background', 'accent used directly as a text colour', '--bh-accent', '--bh-bg', ['apca' => 55, 'wcag' => 4.5]);
        $rows[] = $measure('Border on surface', 'seed --bh-border divider visibility', '--bh-border', '--bh-surface', ['apca' => 12, 'wcag' => 1.3]);

        // Derived roles against their own targets.
        foreach (self::spec() as $name => $r) {
            if (empty($r['target'])) continue;
            $against = is_array($r['against']) ? $r['against'] : [$r['against']];
            foreach ($against as $a) {
                if ($a === null) continue;
                $suffix = count($against) > 1 ? ' (on ' . ltrim($a, '-') . ')' : '';
                $rows[] = $measure(
                    ($r['label'] ?? $name) . $suffix,
                    $r['intent'] ?? '',
                    $name, $a,
                    $r['target'],
                    $r['ceiling'] ?? null
                );
            }
        }

        return $rows;
    }

    public static function register_debug_section($tools) {
        $tools['bhy-contrast'] = [
            'label' => 'Contrast Audit',
            'render' => [self::class, 'render_debug_section'],
            'handle' => null,
            'reset' => null,
            'safe_in_production' => true,   // read-only maths, nothing mutated
            'group' => class_exists('OUS_Debug') ? OUS_Debug::GROUP_MONITORING : null,
        ];
        return $tools;
    }

    public static function render_debug_section(string $key = ''): void {
        $current = self::seeds_from_settings(BHY_Style::get());

        echo '<p>Every colour the resolver derives, plus the raw seed pairings the Design Suite never used to check, measured against the theme you have now. APCA drives the decision; WCAG&nbsp;2 is shown for compliance.</p>';

        self::render_audit_table('Current theme — ' . esc_html(self::current_theme_label()), self::audit_rows($current), true);

        // Preset matrix: worst failing role per built-in theme preset, so
        // "switch to that theme and text breaks" is visible up front.
        echo '<h3 style="margin-top:2em">Built-in theme presets</h3>';
        echo '<p>Worst-failing role if you switched the whole site to each preset.</p>';
        $matrix = [];
        if (defined('BHY_Style::THEME_GROUPS') || property_exists('BHY_Style', 'THEME_GROUPS') || true) {
            foreach (self::preset_seed_sets() as $preset => $seeds) {
                $fails = array_filter(self::audit_rows($seeds), fn($r) => !$r['pass']);
                usort($fails, fn($a, $b) => ($a['apca'] - $a['target_apca']) <=> ($b['apca'] - $b['target_apca']));
                $matrix[$preset] = $fails;
            }
        }
        echo '<div class="bhy-table-wrap"><table class="widefat striped"><thead><tr>'
            . '<th>Preset</th><th># failing</th><th>Worst role</th><th>APCA / target</th></tr></thead><tbody>';
        foreach ($matrix as $preset => $fails) {
            $n = count($fails);
            $worst = $fails[0] ?? null;
            $badge = $n === 0
                ? '<span class="bhy-badge bhy-badge-success">clean</span>'
                : '<span class="bhy-badge bhy-badge-danger">' . $n . '</span>';
            echo '<tr><td>' . esc_html($preset) . '</td><td>' . $badge . '</td>'
                . '<td>' . ($worst ? esc_html($worst['label']) : '&mdash;') . '</td>'
                . '<td>' . ($worst ? sprintf('%.0f / %.0f', $worst['apca'], $worst['target_apca']) : '&mdash;') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function render_audit_table(string $title, array $rows, bool $with_copy = false): void {
        $fail_lines = [];
        echo '<h3>' . esc_html($title) . '</h3>';
        echo '<div class="bhy-table-wrap"><table class="widefat striped" id="bhy-contrast-audit"><thead><tr>'
            . '<th>Role</th><th>Intent</th><th>Colours</th><th>APCA Lc</th><th>WCAG</th><th>Target</th><th>Status</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $status = $r['pass']
                ? '<span class="bhy-badge bhy-badge-success">pass</span>'
                : '<span class="bhy-badge bhy-badge-danger">fail</span>';
            if (!$r['pass']) {
                $fail_lines[] = sprintf(
                    '%s — APCA %.1f (need %.0f), WCAG %.2f (need %.1f) — %s on %s%s',
                    $r['label'], $r['apca'], $r['target_apca'], $r['wcag'], $r['target_wcag'],
                    $r['fg'], $r['bg'], $r['note'] ? ' — ' . $r['note'] : ''
                );
            }
            $swatch = '<span style="display:inline-block;width:2.4em;height:1.1em;border-radius:3px;vertical-align:middle;background:' . esc_attr($r['bg']) . ';color:' . esc_attr($r['fg']) . ';text-align:center;font-size:11px;line-height:1.1em">Aa</span>';
            echo '<tr>'
                . '<td>' . esc_html($r['label']) . '</td>'
                . '<td>' . esc_html($r['intent']) . '</td>'
                . '<td>' . $swatch . ' <code>' . esc_html($r['fg']) . '</code> / <code>' . esc_html($r['bg']) . '</code></td>'
                . '<td>' . sprintf('%.1f', $r['apca']) . '</td>'
                . '<td>' . sprintf('%.2f', $r['wcag']) . '</td>'
                . '<td>' . sprintf('%.0f / %.1f', $r['target_apca'], $r['target_wcag']) . '</td>'
                . '<td>' . $status . ($r['note'] ? ' <em>' . esc_html($r['note']) . '</em>' : '') . '</td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';

        if ($with_copy) {
            $blob = $fail_lines ? implode("\n", $fail_lines) : 'No contrast failures in the current theme.';
            echo '<p><textarea id="bhy-contrast-fails" readonly rows="4" style="width:100%;font-family:monospace;font-size:12px">' . esc_textarea($blob) . '</textarea></p>';
            echo '<button type="button" class="button bhy-copy-btn" data-copy-target="#bhy-contrast-fails">Copy failures</button>';
        }
    }

    private static function current_theme_label(): string {
        $s = BHY_Style::get();
        foreach (BHY_Style::THEME_GROUPS as $group) {
            foreach ($group as $name => $palette) {
                if (($palette['color_accent'] ?? null) === ($s['color_accent'] ?? '_')
                    && ($palette['color_bg'] ?? null) === ($s['color_bg'] ?? '_')) {
                    return $name;
                }
            }
        }
        return 'custom';
    }

    /**
     * Every built-in preset expanded to a full seed map (preset values
     * layered over DEFAULTS, same as picking it in the gallery would).
     *
     * @return array<string, array<string, string>>
     */
    public static function preset_seed_sets(): array {
        $out = [];
        foreach (BHY_Style::THEME_GROUPS as $group_name => $group) {
            foreach ($group as $name => $palette) {
                $settings = array_merge(BHY_Style::DEFAULTS, $palette);
                $out[$name] = self::seeds_from_settings($settings);
            }
        }
        return $out;
    }
}
