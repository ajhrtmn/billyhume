<?php
if (!defined('ABSPATH')) exit;

/**
 * Test Runner coverage for BH_Studio's default block types and BH_Content's
 * validate()/render() round trip. Pure logic, no database.
 */
class OUS_StudioTestSuite {
    public static function init(): void {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $suites
     * @return array<string, mixed>
     */
    public static function register($suites): array {
        $suites['bh-studio'] = ['label' => 'BH_Studio / BH_Content', 'callback' => [self::class, 'run']];
        return $suites;
    }

    /** @return array<int, mixed> */
    public static function run(): array {
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

        /* ---------- output never contains an inline "position:absolute" ---------- */
        $rows[] = OUS_TestRunner::assert_false(
            strpos(strtolower($html), 'position:absolute') !== false || strpos(strtolower($html), 'position: absolute') !== false,
            'Rendered output contains no inline position:absolute anywhere'
        );

        return $rows;
    }
}
