<?php
if (!defined('ABSPATH')) exit;

/**
 * Test Runner suite for BH_Element: empty-slot wrapper, delete_context(), unregistered
 * surface previews, and color-token schema. No DB fixtures needed.
 */
class BH_Element_TestSuite {
    public static function init(): void {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $suites
     * @return array<string, mixed>
     */
    public static function register($suites): array {
        $suites['the-self-hosted-self-elements'] = ['label' => 'The Self-Hosted Self (Design Suite / elements)', 'callback' => [self::class, 'run']];
        return $suites;
    }

    /** @return array<int, mixed> */
    public static function run(): array {
        if (!class_exists('OUS_TestRunner') || !class_exists('BH_Element')) return [];
        $rows = [];

        $rows = array_merge($rows, self::run_empty_slot_tests());
        $rows = array_merge($rows, self::run_surface_preview_tests());
        $rows = array_merge($rows, self::run_color_token_schema_tests());
        $rows = array_merge($rows, self::run_delete_context_tests());

        return $rows;
    }

    /* ---------- delete_context() ---------- */

    /** @return array<int, mixed> */
    private static function run_delete_context_tests(): array {
        $rows = [];
        $surface = 'bh_element_suite_delete_context_test';
        $context_id = 999003;

        $id_a = BH_Element::save_placement([
            'surface' => $surface, 'surface_context_id' => $context_id, 'slot' => 'root',
            'position' => 0, 'element_type' => 'bh/note', 'config' => ['attrs' => ['text' => 'A']],
        ]);
        $id_b = BH_Element::save_placement([
            'surface' => $surface, 'surface_context_id' => $context_id, 'slot' => 'root',
            'position' => 1, 'element_type' => 'bh/note', 'config' => ['attrs' => ['text' => 'B']],
        ]);
        // A different context under the SAME surface — must survive.
        $id_other_context = BH_Element::save_placement([
            'surface' => $surface, 'surface_context_id' => $context_id + 1, 'slot' => 'root',
            'position' => 0, 'element_type' => 'bh/note', 'config' => ['attrs' => ['text' => 'C']],
        ]);

        $rows[] = OUS_TestRunner::assert_true($id_a > 0 && $id_b > 0 && $id_other_context > 0, 'Three real placements are created across two contexts for this test');

        $deleted = BH_Element::delete_context($surface, $context_id);
        $rows[] = OUS_TestRunner::assert_true($deleted, 'delete_context() succeeds');

        $remaining = BH_Element::get_placements($surface, $context_id, 'root');
        $rows[] = OUS_TestRunner::assert_same(0, count($remaining), 'Every placement under the deleted context is gone');

        $other_still_there = BH_Element::get_placements($surface, $context_id + 1, 'root');
        $rows[] = OUS_TestRunner::assert_same(1, count($other_still_there), 'A DIFFERENT context under the same surface is completely untouched');

        BH_Element::delete_context($surface, $context_id + 1);

        return $rows;
    }

    /**
     * An empty slot must still render its wrapper div (the live-preview insertion anchor).
     * Uses an unregistered surface slug, which is guaranteed to have zero placements.
     *
     * @return array<int, mixed>
     */
    private static function run_empty_slot_tests(): array {
        $rows = [];
        $surface = 'bh-element-test-suite-nonexistent-surface';
        $context_id = 999999001; // random high id, never a real placement's context
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
     * render_surface_preview() must degrade to an empty result, not a fatal or warnings,
     * for an unregistered surface slug.
     *
     * @return array<int, mixed>
     */
    private static function run_surface_preview_tests(): array {
        $rows = [];
        $html = BH_Element::render_surface_preview('bh-element-test-suite-nonexistent-surface');
        $rows[] = OUS_TestRunner::assert_same('', $html, 'render_surface_preview() on an unregistered surface slug returns an empty string, not a fatal or a broken partial render');
        return $rows;
    }

    /**
     * style_schema_for_js()'s 'colorTokens' values must be var() references, not bare token
     * names, or every swatch in the color-token picker renders blank.
     *
     * @return array<int, mixed>
     */
    private static function run_color_token_schema_tests(): array {
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

        // Spot-check one known mapping to catch a renamed var that still starts with '--'.
        $rows[] = OUS_TestRunner::assert_same(
            '--bh-accent', $tokens['color_accent'] ?? null,
            "'color_accent' maps to the exact '--bh-accent' CSS var BHY_Style::inline_css() actually defines"
        );

        return $rows;
    }
}

/**
 * NOT covered here (needs a real browser): client-side REST path construction, the
 * tree/canvas selection sync, and actual visual rendering.
 */
