<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_TestRunner suite for bh-crm — same convention as the rest of the
 * ecosystem's own class-test-suite.php files. This plugin had ZERO test
 * coverage before this pass. Covers BHCRM_Segments::sanitize_conditions()
 * (the server-side validation that stands between a raw $_POST array and
 * what actually gets run as a user filter), BHCRM_Export::csv_safe()
 * (the CSV-injection guard), and BHCRM_Subtasks' pure tree-manipulation
 * helpers (find_node()/children_at()/total_node_count()).
 */
class BHCRM_TestSuite {
    public static function init() {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    public static function register($suites) {
        $suites['bh-crm'] = ['label' => 'BH CRM', 'callback' => [self::class, 'run']];
        return $suites;
    }

    public static function run() {
        if (!class_exists('OUS_TestRunner') || !class_exists('BHCRM_Segments')) {
            return [['name' => 'BHCRM_Segments not loaded', 'pass' => false, 'message' => 'Skipped — required classes not found.']];
        }
        $rows = [];
        $rows = array_merge($rows, self::run_segment_condition_tests());
        $rows = array_merge($rows, self::run_csv_safe_tests());
        $rows = array_merge($rows, self::run_subtasks_tree_tests());
        return $rows;
    }

    /* ---------- BHCRM_Segments::sanitize_conditions() ---------- */

    private static function run_segment_condition_tests() {
        $rows = [];

        $clean = BHCRM_Segments::sanitize_conditions([
            ['field' => 'tag', 'value' => 'vip'],
        ]);
        $rows[] = OUS_TestRunner::assert_same(
            [['field' => 'tag', 'value' => 'vip']], $clean,
            'sanitize_conditions(): a well-formed condition passes through unchanged'
        );

        $rows[] = OUS_TestRunner::assert_same(
            [], BHCRM_Segments::sanitize_conditions([['field' => 'not_a_real_field', 'value' => 'x']]),
            'sanitize_conditions(): an unknown field is dropped entirely, never persisted as a filterable condition'
        );

        $rows[] = OUS_TestRunner::assert_same(
            [], BHCRM_Segments::sanitize_conditions([['field' => 'tag', 'value' => '']]),
            'sanitize_conditions(): tag with an empty value is dropped (every field except has_project requires a real value)'
        );

        $rows[] = OUS_TestRunner::assert_same(
            [['field' => 'has_project', 'value' => '']], BHCRM_Segments::sanitize_conditions([['field' => 'has_project', 'value' => '']]),
            'sanitize_conditions(): has_project with NO value is correctly kept — it is the one field that needs no value (a regression treating it like every other field would silently drop every has_project condition)'
        );

        $rows[] = OUS_TestRunner::assert_same(
            [], BHCRM_Segments::sanitize_conditions([['value' => 'vip']]),
            'sanitize_conditions(): a condition missing "field" entirely is dropped, not treated as an empty-string field match'
        );

        $rows[] = OUS_TestRunner::assert_same(
            [], BHCRM_Segments::sanitize_conditions('not-an-array'),
            'sanitize_conditions(): a completely malformed (non-array) input degrades to an empty condition set rather than throwing'
        );

        return $rows;
    }

    /* ---------- BHCRM_Export::csv_safe() ---------- */

    private static function run_csv_safe_tests() {
        if (!class_exists('BHCRM_Export')) return [];
        $rows = [];
        foreach (['=cmd', '+1', '-1', '@SUM(A1)'] as $formula) {
            $rows[] = OUS_TestRunner::assert_same(
                "'" . $formula, BHCRM_Export::csv_safe($formula),
                "csv_safe(): a value starting with '" . $formula[0] . "' gets a defusing leading apostrophe (CSV-injection guard)"
            );
        }
        $rows[] = OUS_TestRunner::assert_same(
            'Ordinary Name', BHCRM_Export::csv_safe('Ordinary Name'),
            'csv_safe(): an ordinary value is passed through unchanged'
        );
        $rows[] = OUS_TestRunner::assert_same(
            '', BHCRM_Export::csv_safe(''),
            'csv_safe(): an empty string does not throw on the $value[0] access and returns empty'
        );
        return $rows;
    }

    /* ---------- BHCRM_Subtasks tree helpers — private, via Reflection ---------- */

    private static function run_subtasks_tree_tests() {
        if (!class_exists('BHCRM_Subtasks')) return [];
        $rows = [];

        $find_node = new ReflectionMethod('BHCRM_Subtasks', 'find_node');
        $children_at = new ReflectionMethod('BHCRM_Subtasks', 'children_at');
        $total_count = new ReflectionMethod('BHCRM_Subtasks', 'total_node_count');

        $tree = [
            ['attrs' => ['uid' => 'a'], 'children' => [
                ['attrs' => ['uid' => 'a1'], 'children' => []],
                ['attrs' => ['uid' => 'a2'], 'children' => [
                    ['attrs' => ['uid' => 'a2i'], 'children' => []],
                ]],
            ]],
            ['attrs' => ['uid' => 'b'], 'children' => []],
        ];

        $found = &$find_node->invokeArgs(null, [&$tree, ['a', 'a2', 'a2i']]);
        $rows[] = OUS_TestRunner::assert_same(
            'a2i', $found['attrs']['uid'] ?? null,
            'find_node(): locates a node 3 levels deep by its exact uid path'
        );

        $missing = &$find_node->invokeArgs(null, [&$tree, ['a', 'nonexistent']]);
        $rows[] = OUS_TestRunner::assert_true(
            $missing === null,
            'find_node(): a path referencing a uid that does not exist (e.g. a deleted card) returns null rather than a wrong/partial node'
        );

        $children = &$children_at->invokeArgs(null, [&$tree, ['a']]);
        $rows[] = OUS_TestRunner::assert_same(
            2, count($children),
            'children_at(): returns the real children array (by reference) for an existing path'
        );

        $empty_children = &$children_at->invokeArgs(null, [&$tree, ['b', 'nonexistent']]);
        $rows[] = OUS_TestRunner::assert_same(
            0, count($empty_children),
            'children_at(): a path through a non-existent intermediate node returns an empty array, not a fatal error or a reference into the wrong node'
        );

        $rows[] = OUS_TestRunner::assert_same(
            5, $total_count->invoke(null, $tree),
            'total_node_count(): counts every node across every nesting level (a=1 + a1=1 + a2=1 + a2i=1 + b=1 = 5), catching an off-by-one or a level skipped during recursion'
        );

        $rows[] = OUS_TestRunner::assert_same(
            0, $total_count->invoke(null, []),
            'total_node_count(): an empty tree counts as 0, not an error'
        );

        return $rows;
    }
}
