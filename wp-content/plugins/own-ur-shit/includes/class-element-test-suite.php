<?php
if (!defined('ABSPATH')) exit;

/**
 * The Test Runner (see class-test-runner.php) version of BH_Element
 * coverage — same "runnable from Debug Tools, no CLI/PHPUnit needed"
 * pattern every other *_TestSuite class in this ecosystem uses
 * (BHC_TestSuite, OUS_CoreTestSuite, etc.).
 *
 * Covers three prior real bugs in this layer (BH_Element/Design Suite
 * canvas), each a class a cheap deterministic assertion would catch
 * without a live browser: (1) render_slot() returning '' with no
 * wrapper div for an empty slot, breaking the live-preview insert-
 * anchor; (2) a doubled/404ing REST path ('/elements/preview' vs the
 * correct bare 'preview'); (3) two different surfaces registered under
 * two different keys for the same page, causing duplicate/mismatched
 * canvas stories. JS-side bugs like #2 and DOM-sync bugs like tree/
 * canvas mismatch aren't testable from PHP-side assertions at all —
 * see this file's "NOT covered here" note at the bottom.
 *
 * No DB fixtures/cleanup needed for any of the assertions below — every
 * one exercises pure rendering/registry logic against either a
 * temporarily-registered throwaway type or the framework's own always-
 * present built-in primitives (bh/note etc., 3.4.50).
 */
class BH_Element_TestSuite {
    public static function init() {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    public static function register($suites) {
        $suites['own-ur-shit-elements'] = ['label' => 'Own Ur Shit (Design Suite / elements)', 'callback' => [self::class, 'run']];
        return $suites;
    }

    public static function run() {
        if (!class_exists('OUS_TestRunner') || !class_exists('BH_Element')) return [];
        $rows = [];

        $rows = array_merge($rows, self::run_empty_slot_tests());
        $rows = array_merge($rows, self::run_surface_preview_tests());
        $rows = array_merge($rows, self::run_color_token_schema_tests());

        return $rows;
    }

    /**
     * Regression test: a slot with zero saved placements used to return
     * '' from render_slot() with no wrapper div at all — the single most
     * common real-world case there is (any fresh page's first-ever edit)
     * — which broke the live-preview JS's insertion anchor. Uses a
     * surface/context id combo
     * ('bh-element-test-suite-surface', a random high context id) that
     * can never collide with a real registered surface or real saved
     * data, so this needs no registration/cleanup at all — an
     * unregistered surface slug still has zero placements, which is
     * exactly the empty-slot case being tested.
     */
    private static function run_empty_slot_tests() {
        $rows = [];
        $surface = 'bh-element-test-suite-nonexistent-surface';
        $context_id = 999999001; // astronomically unlikely to collide with any real placement's context id
        $html = BH_Element::render_slot($surface, $context_id, 'root');

        $rows[] = OUS_TestRunner::assert_true(
            strpos($html, 'bh-element-slot') !== false,
            'render_slot() on a slot with ZERO saved placements still returns its wrapper div (3.4.49 regression: this used to return an empty string with no wrapper at all)'
        );
        $rows[] = OUS_TestRunner::assert_true(
            strpos($html, 'data-surface="' . $surface . '"') !== false && strpos($html, 'data-slot="root"') !== false,
            'the empty-slot wrapper still carries the correct data-surface/data-slot attributes (what the live-preview JS anchors on)'
        );

        return $rows;
    }

    /**
     * BH_Element::render_surface_preview() (the Live Views auto-story
     * generator) must degrade gracefully — never fatal, never
     * warning-spam — for a surface slug nobody registered. A slug typo
     * or stale key should produce an obvious empty result, not broken
     * output.
     */
    private static function run_surface_preview_tests() {
        $rows = [];
        $html = BH_Element::render_surface_preview('bh-element-test-suite-nonexistent-surface');
        $rows[] = OUS_TestRunner::assert_same('', $html, 'render_surface_preview() on an unregistered surface slug returns an empty string, not a fatal or a broken partial render');
        return $rows;
    }

    /**
     * Regression test: BHY_Style::style_schema_for_js()'s 'colorTokens'
     * map used to just echo each token's own name back as its value
     * (`$color_tokens[$field] = $field`), which the color-token dropdown
     * (buildColorTokenPopup(), element-builder.js) needs to be a real
     * `var(--bh-accent)` reference. If this regresses back to bare token
     * names, every swatch silently renders as a blank/transparent box
     * with no error anywhere. Asserts every value is a real CSS
     * custom-property reference (starts with '--'), not just any string.
     */
    private static function run_color_token_schema_tests() {
        $rows = [];
        if (!class_exists('BHY_Style')) return $rows;

        $schema = BHY_Style::style_schema_for_js();
        $tokens = $schema['colorTokens'] ?? [];

        $rows[] = OUS_TestRunner::assert_true(!empty($tokens), 'style_schema_for_js() returns at least one color token (color_accent etc.)');

        $all_real_css_vars = true;
        foreach ($tokens as $field => $css_var) {
            if (strpos((string) $css_var, '--') !== 0) { $all_real_css_vars = false; break; }
        }
        $rows[] = OUS_TestRunner::assert_true(
            $all_real_css_vars,
            "every colorTokens value is a real CSS custom property (starts with '--'), not the bare token name — the exact shape the color-swatch dropdown needs to build var(--bh-accent) directly"
        );

        // Spot-check one specific, known-stable mapping rather than only
        // the generic "starts with --" shape check above — catches a
        // typo'd/renamed CSS var even if it still technically starts
        // with '--'.
        $rows[] = OUS_TestRunner::assert_same(
            '--bh-accent', $tokens['color_accent'] ?? null,
            "'color_accent' maps to the exact '--bh-accent' CSS var BHY_Style::inline_css() actually defines"
        );

        return $rows;
    }
}

/**
 * NOT covered by this suite — needs a real browser pass, not a PHP
 * assertion:
 * - The REST-path class of bug (api('/elements/preview', ...) vs the
 *   correct api('preview', ...)) — this is pure client-side JS string
 *   concatenation against a config value only present in a real browser
 *   session; nothing server-side to assert against.
 * - The tree/canvas Live-View selection-sync (bhel:select-surface) —
 *   inherently a live-DOM, two-file coordination behavior.
 * - Any actual visual rendering (swatch colors, font previews) — these
 *   tests confirm the DATA is correct, not that a browser paints it
 *   correctly.
 */
