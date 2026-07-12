<?php
if (!defined('ABSPATH')) exit;

/**
 * The Test Runner (see class-test-runner.php) version of BH_Element
 * coverage — same "runnable from Debug Tools, no CLI/PHPUnit needed"
 * pattern every other *_TestSuite class in this ecosystem uses
 * (BHC_TestSuite, OUS_CoreTestSuite, etc.).
 *
 * Direct response to AJ's own "let's be smart about tests" ask, right
 * after a run of THREE real bugs in this exact layer (BH_Element/the
 * Design Suite canvas) were only caught by live screenshots tonight,
 * one after another: (1) render_slot() returning '' with no wrapper div
 * at all for an empty slot, silently breaking the new live-preview
 * insert-anchor; (2) a doubled/404ing REST path
 * ('/elements/preview' vs the correct bare 'preview'); (3) two
 * different surfaces registered under two different keys for the same
 * real page, causing duplicate/mismatched canvas stories. Every one of
 * these was a class of bug a cheap, deterministic assertion would have
 * caught immediately, with no live browser needed — this suite is that
 * safety net for the ones that don't require a live REST/AJAX round
 * trip (JS-side bugs like #2 and DOM-sync bugs like the tree/canvas
 * mismatch aren't testable from PHP-side assertions at all; see this
 * file's own "NOT covered here" note at the bottom for what still needs
 * a real browser pass).
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
     * Regression test for the 3.4.49 fix: a slot with zero saved
     * placements used to return '' from render_slot() with no wrapper
     * div at all — the single most common real-world case there is (any
     * fresh page's first-ever edit) — which silently broke the live-
     * preview JS's insertion anchor. Uses a surface/context id combo
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
     * BH_Element::render_surface_preview() (3.4.48, the Live Views
     * auto-story generator) must degrade gracefully — never fatal, never
     * warning-spam — for a surface slug nobody registered. This is
     * exactly the shape of mistake the canvas-sync bugs this session
     * kept producing: a slug typo or a stale key silently producing
     * broken output instead of an obvious empty result.
     */
    private static function run_surface_preview_tests() {
        $rows = [];
        $html = BH_Element::render_surface_preview('bh-element-test-suite-nonexistent-surface');
        $rows[] = OUS_TestRunner::assert_same('', $html, 'render_surface_preview() on an unregistered surface slug returns an empty string, not a fatal or a broken partial render');
        return $rows;
    }

    /**
     * Regression test for the 3.4.50 color-swatch fix:
     * BHY_Style::style_schema_for_js()'s 'colorTokens' map used to just
     * echo each token's own name back as its value
     * (`$color_tokens[$field] = $field`) — nothing read those values
     * until the new color-token dropdown (buildColorTokenPopup(),
     * element-builder.js) started building `var(--bh-accent)` swatches
     * directly from them. If this ever regresses back to bare token
     * names, every swatch silently renders as a blank/transparent box
     * with no error anywhere — exactly the kind of "looks fine until you
     * actually look at it" bug this session had multiple live-screenshot
     * rounds catching. Asserts every value is a real CSS custom-property
     * reference (starts with '--'), not just any string.
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
