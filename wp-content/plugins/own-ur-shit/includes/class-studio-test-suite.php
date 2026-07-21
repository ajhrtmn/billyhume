<?php
if (!defined('ABSPATH')) exit;

/**
 * Test Runner coverage for BH_Studio's default block types (registered
 * against BH_Content — see class-studio.php) and BH_Content's own
 * validate()/render() round trip through them. Pure logic, no database
 * required — every block type tested here has a stateless schema +
 * renderer, so this suite exercises the same code path a real
 * BH_Studio save/render cycle uses without needing a live install.
 */
class OUS_StudioTestSuite {
    public static function init() {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    public static function register($suites) {
        $suites['bh-studio'] = ['label' => 'BH_Studio / BH_Content', 'callback' => [self::class, 'run']];
        return $suites;
    }

    public static function run() {
        if (!class_exists('OUS_TestRunner')) return [];
        if (!class_exists('BH_Content') || !class_exists('BH_Studio')) {
            return [['name' => 'BH_Content/BH_Studio not loaded', 'pass' => false, 'message' => 'Skipped — required classes not found.']];
        }
        $rows = [];

        /* ---------- default block types are actually registered ---------- */

        $registered = BH_Content::get_registered_types();
        foreach (array_keys(BH_Studio::block_types()) as $type) {
            $rows[] = OUS_TestRunner::assert_true(
                in_array($type, $registered, true),
                "$type is registered with BH_Content (BH_Studio::init() ran)"
            );
        }

        /* ---------- validate() coerces and drops unknown types ---------- */

        $tree = [
            ['type' => 'bh/heading', 'attrs' => ['content' => 'Hello', 'level' => '3'], 'children' => []],
            ['type' => 'bh/nonexistent-type', 'attrs' => [], 'children' => []],
        ];
        $clean = BH_Content::validate($tree);
        $rows[] = OUS_TestRunner::assert_same(1, count($clean), 'Unknown block type is silently dropped, not fataled');
        $rows[] = OUS_TestRunner::assert_same(3, $clean[0]['attrs']['level'] ?? null, 'String "3" coerces to int 3 for a schema-typed int attribute');

        /* ---------- nested container round-trips through render() ---------- */

        $tree = [[
            'type' => 'bh/container',
            'attrs' => ['className' => 'test-wrap'],
            'children' => [
                ['type' => 'bh/heading', 'attrs' => ['content' => 'Title', 'level' => 2], 'children' => []],
                ['type' => 'bh/text', 'attrs' => ['content' => 'Body copy'], 'children' => []],
                ['type' => 'bh/button', 'attrs' => ['text' => 'Click me', 'url' => 'https://example.com'], 'children' => []],
            ],
        ]];
        $html = BH_Content::render(BH_Content::validate($tree));
        $rows[] = OUS_TestRunner::assert_true(strpos($html, '<section class="test-wrap">') !== false, 'Container renders as a real <section>, carries its className');
        $rows[] = OUS_TestRunner::assert_true(strpos($html, '<h2>Title</h2>') !== false, 'Heading level attribute controls the actual rendered tag');
        $rows[] = OUS_TestRunner::assert_true(strpos($html, '<p>Body copy</p>') !== false, 'Text block renders as a real <p>');
        $rows[] = OUS_TestRunner::assert_true(strpos($html, 'href="https://example.com"') !== false, 'Button block carries its URL through to the rendered anchor');

        /* ---------- image block: no URL means no broken <img> ---------- */

        $empty_image = BH_Content::render(BH_Content::validate([['type' => 'bh/image', 'attrs' => [], 'children' => []]]));
        $rows[] = OUS_TestRunner::assert_same('', $empty_image, 'Image block with no url renders nothing, never a broken <img src="">');

        /* ---------- output never contains an inline "position:absolute" ----------
         * The concrete enforcement point for "expressive control, never
         * absolute-positioned div soup" (see studio.js's own COMMON_SUPPORTS
         * comment) — checked here on the actual rendered HTML string, not
         * just asserted in a code comment. */
        $rows[] = OUS_TestRunner::assert_false(
            strpos(strtolower($html), 'position:absolute') !== false || strpos(strtolower($html), 'position: absolute') !== false,
            'Rendered output contains no inline position:absolute anywhere'
        );

        return $rows;
    }
}
