<?php
if (!defined('ABSPATH')) exit;

/**
 * Test Runner suite for BHY_Color (the pure maths) and BHY_Contrast
 * (the resolver). Registered by BHY_Contrast::init() via
 * bhcore_test_suites, runs from Debug Tools -> Tests on the site's own
 * PHP — no CLI. Covers known metric vectors, OKLCH round-trips, the
 * resolver's cross-theme invariants, byte-for-byte reproduction of the
 * four colours BHY_Style derived before the resolver existed, and a
 * calibration snapshot that fails loudly if the default theme's derived
 * palette ever shifts.
 */
class OUS_ContrastTestSuite {

    /** @return array<int, array<string,mixed>> */
    public static function run(): array {
        if (!class_exists('BHY_Color') || !class_exists('BHY_Contrast')) {
            return [['name' => 'BHY_Color / BHY_Contrast', 'pass' => false, 'message' => 'Skipped — colour classes not loaded.']];
        }
        return array_merge(
            self::metric_vectors(),
            self::oklch_roundtrips(),
            self::solver_behaviour(),
            self::resolver_invariants(),
            self::legacy_reproduction(),
            self::calibration_snapshot()
        );
    }

    /** @return array<int, array<string,mixed>> */
    private static function metric_vectors(): array {
        $r = [];

        // WCAG 2.x ratio — textbook values.
        $r[] = self::near('WCAG #767676 on #fff', BHY_Color::wcag_ratio('#767676', '#ffffff'), 4.54, 0.02);
        $r[] = self::near('WCAG #000 on #fff (max)', BHY_Color::wcag_ratio('#000000', '#ffffff'), 21.0, 0.01);
        $r[] = self::near('WCAG identical colours = 1', BHY_Color::wcag_ratio('#3d5a80', '#3d5a80'), 1.0, 0.001);
        $r[] = OUS_TestRunner::assert_true(
            BHY_Color::wcag_ratio('#abc', '#aabbcc') < 1.001,
            '3-digit and 6-digit hex parse identically'
        );

        // APCA-W3 0.1.9 reference Lc values.
        $r[] = self::near('APCA #888 on #fff', BHY_Color::apca_lc('#888888', '#ffffff'), 63.1, 0.6);
        $r[] = self::near('APCA #fff on #000', BHY_Color::apca_lc('#ffffff', '#000000'), -107.9, 0.6);
        $r[] = self::near('APCA #000 on #fff', BHY_Color::apca_lc('#000000', '#ffffff'), 106.0, 0.6);
        $r[] = self::near('APCA #aaa on #123456 (reverse polarity)', BHY_Color::apca_lc('#aaaaaa', '#123456'), -50.3, 1.0);
        $r[] = OUS_TestRunner::assert_true(
            BHY_Color::apca_lc('#7d7d7d', '#808080') === 0.0,
            'APCA below the delta-Y floor clamps to exactly 0'
        );

        // sRGB mix mirrors color-mix(in srgb, ...).
        $mid = BHY_Color::mix_srgb('#000000', '#ffffff', 0.5);
        $r[] = OUS_TestRunner::assert_true(
            in_array(strtolower($mid), ['#7f7f7f', '#808080'], true),
            "50/50 srgb mix of black+white is mid-grey (got $mid)"
        );
        $r[] = OUS_TestRunner::assert_same(
            '#ff0000',
            strtolower(BHY_Color::mix_srgb('#ff0000', '#00ff00', 1.0)),
            'srgb mix weight 1.0 returns the first colour untouched'
        );

        return $r;
    }

    /** @return array<int, array<string,mixed>> */
    private static function oklch_roundtrips(): array {
        $r = [];
        $samples = ['#ffffff', '#000000', '#808080', '#c85c48', '#170807', '#eddfcb', '#1db954', '#2b6cb0', '#7f00ff', '#00e5ff'];
        $worst = 0.0;
        foreach ($samples as $hex) {
            $c = BHY_Color::hex_to_oklch($hex);
            $back = BHY_Color::oklch_to_hex($c['L'], $c['C'], $c['h']);
            $de = BHY_Color::delta_e($hex, $back);
            $worst = max($worst, $de);
        }
        $r[] = OUS_TestRunner::assert_true($worst < 1.0, sprintf('OKLCH round-trip is lossless for in-gamut colours (worst dE %.3f)', $worst));

        // A wildly out-of-gamut request still yields a real, in-gamut hex.
        $clamped = BHY_Color::oklch_to_hex(0.6, 0.9, 30.0);
        $r[] = OUS_TestRunner::assert_true(
            (bool) preg_match('/^#[0-9a-f]{6}$/', $clamped),
            "Out-of-gamut OKLCH gamut-maps to a valid sRGB hex (got $clamped)"
        );
        $rt = BHY_Color::hex_to_oklch($clamped);
        $r[] = OUS_TestRunner::assert_true(
            BHY_Color::oklch_in_gamut($rt['L'], $rt['C'], $rt['h']),
            'The gamut-mapped result is itself in gamut'
        );

        return $r;
    }

    /** @return array<int, array<string,mixed>> */
    private static function solver_behaviour(): array {
        $r = [];

        // solve_lightness_for_apca hits (at least) the target on a normal pair.
        $ink = BHY_Color::solve_lightness_for_apca('#c85c48', '#220c0a', 75.0);
        $r[] = OUS_TestRunner::assert_true(
            BHY_Color::apca_lc_abs($ink, '#220c0a') >= 74.0,
            sprintf('solve-min reaches the APCA target (got %.1f for %s)', BHY_Color::apca_lc_abs($ink, '#220c0a'), $ink)
        );

        // approach_apca_target lands NEAR the target from a too-strong base
        // (a strong text colour pulled down to a muted level).
        $muted = BHY_Color::approach_apca_target('#eddfcb', '#220c0a', 50.0, 1.0);
        $lc = BHY_Color::apca_lc_abs($muted, '#220c0a');
        $r[] = OUS_TestRunner::assert_true(
            $lc >= 44.0 && $lc <= 58.0,
            sprintf('approach lands near a bounded target, not maxed out (got %.1f for %s)', $lc, $muted)
        );

        // shift_lightness with a negative delta genuinely darkens.
        $dark = BHY_Color::shift_lightness('#c85c48', -0.15);
        $r[] = OUS_TestRunner::assert_true(
            BHY_Color::hex_to_oklch($dark)['L'] < BHY_Color::hex_to_oklch('#c85c48')['L'],
            'shift_lightness(-0.15) lowers OKLCH lightness'
        );

        // best_ink_on prefers an earlier ink once it clears the min.
        $picked = BHY_Color::best_ink_on('#222222', ['#dddddd', '#ffffff'], 'apca', 40.0);
        $r[] = OUS_TestRunner::assert_same('#dddddd', $picked, 'best_ink_on returns the first ink that clears the min, not the strongest');

        return $r;
    }

    /**
     * The resolver's contract, checked across a spread of themes: dark,
     * light, near-white, low-chroma, neon, and a deliberately awkward
     * near-clash accent.
     *
     * @return array<int, array<string,mixed>>
     */
    private static function resolver_invariants(): array {
        $r = [];
        $themes = self::sample_themes();

        foreach ($themes as $name => $seeds) {
            $css = BHY_Contrast::resolve($seeds);
            $hex = BHY_Contrast::resolve_concrete($seeds);

            // Every SPEC role is produced.
            $missing = [];
            foreach (array_keys(BHY_Contrast::spec()) as $role) {
                if (!isset($css[$role])) $missing[] = $role;
            }
            $r[] = OUS_TestRunner::assert_same('', implode(',', $missing), "[$name] every derived role is produced");

            // Every concrete value is a real hex.
            $bad = [];
            foreach ($hex as $k => $v) {
                if (!preg_match('/^#[0-9a-f]{6}$/', $v)) $bad[] = "$k=$v";
            }
            $r[] = OUS_TestRunner::assert_same('', implode(',', $bad), "[$name] every concrete role value is a 6-digit hex");

            // Accent state ladder is monotonic: base -> hover -> pressed
            // each step no lighter than the last (a value shift, never a
            // reversal) — the property that keeps "hover" reading as hover.
            $l_base = BHY_Color::hex_to_oklch($hex['--bh-accent'])['L'];
            $l_hover = BHY_Color::hex_to_oklch($hex['--bh-accent-hover'])['L'];
            $l_press = BHY_Color::hex_to_oklch($hex['--bh-accent-pressed'])['L'];
            $r[] = OUS_TestRunner::assert_true(
                $l_hover <= $l_base + 0.001 && $l_press <= $l_hover + 0.001,
                sprintf('[%s] accent ladder darkens monotonically (L %.3f -> %.3f -> %.3f)', $name, $l_base, $l_hover, $l_press)
            );

            // Disabled text sits BELOW muted text sits BELOW body text,
            // measured on the surface — the intent ordering.
            $s = $seeds['--bh-surface'];
            $c_body = BHY_Color::apca_lc_abs($seeds['--bh-text'], $s);
            $c_muted = BHY_Color::apca_lc_abs($hex['--bh-text-muted'], $s);
            $c_disabled = BHY_Color::apca_lc_abs($hex['--bh-text-disabled'], $s);
            $r[] = OUS_TestRunner::assert_true(
                $c_disabled < $c_muted && $c_muted < $c_body,
                sprintf('[%s] contrast ladder body(%.0f) > muted(%.0f) > disabled(%.0f)', $name, $c_body, $c_muted, $c_disabled)
            );

            // Disabled text keeps a floor — visible, just not inviting.
            $r[] = OUS_TestRunner::assert_true(
                $c_disabled >= 12.0,
                sprintf('[%s] disabled text stays above the visibility floor (%.1f)', $name, $c_disabled)
            );

            // Strong border genuinely beats the seed border.
            $b_seed = BHY_Color::apca_lc_abs($seeds['--bh-border'], $s);
            $b_strong = BHY_Color::apca_lc_abs($hex['--bh-border-strong'], $s);
            $r[] = OUS_TestRunner::assert_true(
                $b_strong > $b_seed,
                sprintf('[%s] --bh-border-strong (%.0f) out-contrasts the seed border (%.0f)', $name, $b_strong, $b_seed)
            );
        }

        // Idempotence-ish: resolving, then feeding the concrete palette
        // back in as seeds, must not send roles drifting.
        $seeds = $themes['dark'];
        $once = BHY_Contrast::resolve_concrete($seeds);
        $reseed = $seeds;
        foreach (['--bh-text-muted' => '--bh-text-dim'] as $srckey => $dstkey) {
            $reseed[$dstkey] = $once[$srckey];
        }
        $twice = BHY_Contrast::resolve_concrete($reseed);
        $drift = BHY_Color::delta_e($once['--bh-text-muted'], $twice['--bh-text-muted']);
        $r[] = OUS_TestRunner::assert_true($drift < 12.0, sprintf('resolver is roughly idempotent under re-seeding (muted dE %.1f)', $drift));

        return $r;
    }

    /**
     * The four roles BHY_Style derived before this class: their resolved
     * value must equal the old formula's output exactly, so switching
     * BHY_Style onto the resolver is a no-op on any live site.
     *
     * @return array<int, array<string,mixed>>
     */
    private static function legacy_reproduction(): array {
        $r = [];
        $seeds = self::defaults_seeds();
        $css = BHY_Contrast::resolve($seeds);

        $accent = $seeds['--bh-accent'];
        $surface = $seeds['--bh-surface'];

        $r[] = OUS_TestRunner::assert_same(
            "color-mix(in srgb, $accent 18%, $surface)",
            $css['--bh-accent-muted-bg'],
            '--bh-accent-muted-bg reproduces the old color-mix() exactly'
        );
        $r[] = OUS_TestRunner::assert_same(
            "color-mix(in srgb, $accent 85%, black)",
            $css['--bh-accent-hover'],
            '--bh-accent-hover reproduces the old color-mix() exactly'
        );
        $r[] = OUS_TestRunner::assert_same(
            "color-mix(in srgb, $accent 70%, black)",
            $css['--bh-accent-pressed'],
            '--bh-accent-pressed reproduces the old color-mix() exactly'
        );

        // Old --bh-accent-contrast = WCAG ink_on(accent, text, bg).
        $old = BHY_Color::wcag_ratio($seeds['--bh-text'], $accent) >= BHY_Color::wcag_ratio($seeds['--bh-bg'], $accent)
            ? $seeds['--bh-text'] : $seeds['--bh-bg'];
        $r[] = OUS_TestRunner::assert_same($old, $css['--bh-accent-contrast'], '--bh-accent-contrast reproduces the old WCAG ink pick');

        return $r;
    }

    /**
     * Pins the default theme's derived palette. If a maths or SPEC change
     * moves any of these more than a hair, this fails and forces a
     * conscious re-baseline — the guard against silently restyling the
     * live site.
     *
     * @return array<int, array<string,mixed>>
     */
    private static function calibration_snapshot(): array {
        // Pinned from resolve_concrete(BHY_Style::DEFAULTS) — re-baseline
        // deliberately (and note it in CHANGELOG) if a change moves these.
        $expected = [
            '--bh-on-accent'      => '#eddfcb',
            '--bh-on-surface'     => '#eddfcb',
            '--bh-on-surface-2'   => '#eddfcb',
            '--bh-text-muted'     => '#c8a492',
            '--bh-text-disabled'  => '#747270',
            '--bh-border-strong'  => '#a0766c',
            '--bh-ui'             => '#2e1916',
            '--bh-ui-hover'       => '#3a2521',
            '--bh-ui-active'      => '#47322d',
            '--bh-focus'          => '#e47660',
        ];
        $hex = BHY_Contrast::resolve_concrete(self::defaults_seeds());
        $r = [];
        foreach ($expected as $role => $want) {
            $got = $hex[$role] ?? '(missing)';
            $de = $got === '(missing)' ? 999 : BHY_Color::delta_e($want, $got);
            $r[] = OUS_TestRunner::assert_true(
                $de < 2.0,
                sprintf('snapshot %s ~= %s (got %s, dE %.2f)', $role, $want, $got, $de)
            );
        }
        return $r;
    }

    // ---- fixtures ----------------------------------------------------

    /** @return array<string, string> */
    private static function defaults_seeds(): array {
        return BHY_Contrast::seeds_from_settings(BHY_Style::DEFAULTS);
    }

    /** @return array<string, array<string,string>> */
    private static function sample_themes(): array {
        $base = BHY_Style::DEFAULTS;
        $mk = fn(array $p) => BHY_Contrast::seeds_from_settings(array_merge($base, $p));
        return [
            'dark'       => $mk([]),
            'light'      => $mk(['color_bg' => '#F4E9DC', 'color_surface' => '#EADCC8', 'color_surface_2' => '#E0CFB5', 'color_border' => '#C9B096', 'color_text' => '#2B120C', 'color_text_dim' => '#6B4A3A', 'color_accent' => '#A83D1A', 'color_accent_soft' => '#E8B49A']),
            'near-white' => $mk(['color_bg' => '#ffffff', 'color_surface' => '#f5f5f5', 'color_surface_2' => '#ececec', 'color_border' => '#dddddd', 'color_text' => '#1a1a1a', 'color_text_dim' => '#6b6b6b', 'color_accent' => '#2b6cb0', 'color_accent_soft' => '#90cdf4']),
            'low-chroma' => $mk(['color_bg' => '#1a1a1a', 'color_surface' => '#242424', 'color_surface_2' => '#2e2e2e', 'color_border' => '#3a3a3a', 'color_text' => '#e8e8e8', 'color_text_dim' => '#9a9a9a', 'color_accent' => '#5b7fb9', 'color_accent_soft' => '#9fb6d9']),
            'neon'       => $mk(['color_bg' => '#05060a', 'color_surface' => '#0d0f18', 'color_surface_2' => '#151826', 'color_border' => '#252a3d', 'color_text' => '#e6f0ff', 'color_text_dim' => '#7f8db3', 'color_accent' => '#00e5ff', 'color_accent_soft' => '#7af2ff']),
            'near-clash' => $mk(['color_accent' => '#7a6b5c', 'color_text_dim' => '#8a7a6a']),
        ];
    }

    private static function near(string $name, float $actual, float $expected, float $tol): array {
        return OUS_TestRunner::assert_true(
            abs($actual - $expected) <= $tol,
            sprintf('%s ~= %.2f (got %.2f, tol %.2f)', $name, $expected, $actual, $tol)
        );
    }
}
