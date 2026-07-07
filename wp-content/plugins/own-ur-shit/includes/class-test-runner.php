<?php
if (!defined('ABSPATH') ) exit;

/**
 * A GUI test runner living on the SAME real PHP the actual site runs on
 * — the whole reason a CLI/PHPUnit isn't needed to get real signal from
 * the test suites already written (see each plugin's tests/ directory
 * for the PHPUnit versions, kept for real CI later): the deployed
 * WordPress site already IS a working PHP environment, it just doesn't
 * have a CLI or Composer's PHPUnit binary on it. This runner reuses the
 * exact same assertions and RFC/edge-case reasoning as the PHPUnit
 * suites, just expressed as plain PHP closures any plugin can register.
 *
 * USAGE, from any plugin (its own bootstrap, class_exists()-guarded):
 *
 *   add_filter('bhcore_test_suites', function ($suites) {
 *       $suites['bh-courses'] = ['label' => 'BH Courses', 'callback' => ['BHC_TestSuite', 'run']];
 *       return $suites;
 *   });
 *
 * A suite's run() callback returns a plain array of result rows:
 *   [ 'name' => 'All correct scores 100 and passes', 'pass' => true, 'message' => '' ]
 * A failing assertion should set pass=false and put the actual-vs-expected
 * detail in message — never throw for an ordinary assertion failure
 * (self::assert() below returns a bool specifically so a suite can keep
 * running its remaining cases after one fails, the same way PHPUnit
 * keeps running remaining tests in a suite after one fails).
 *
 * A suite whose callback itself throws (a real bug in the suite, not a
 * failed assertion) is caught here and surfaced as one failing row
 * rather than taking down the whole Debug Tools page.
 */
class OUS_TestRunner {
    public static function init() {
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
    }

    /* ---------------- tiny assertion helpers any suite can use ---------------- */

    public static function assert_same($expected, $actual, $label) {
        $pass = $expected === $actual;
        return [
            'name' => $label,
            'pass' => $pass,
            'message' => $pass ? '' : ('Expected ' . self::describe($expected) . ', got ' . self::describe($actual)),
        ];
    }

    public static function assert_true($actual, $label) {
        return self::assert_same(true, (bool) $actual, $label);
    }

    public static function assert_false($actual, $label) {
        return self::assert_same(false, (bool) $actual, $label);
    }

    private static function describe($v) {
        if (is_array($v)) return wp_json_encode($v);
        if (is_bool($v)) return $v ? 'true' : 'false';
        if (is_null($v)) return 'null';
        return (string) $v;
    }

    /* ---------------- running every registered suite ---------------- */

    public static function run_all() {
        $suites = apply_filters('bhcore_test_suites', []);
        $report = [];
        foreach ($suites as $key => $suite) {
            $rows = [];
            try {
                $rows = call_user_func($suite['callback']);
                if (!is_array($rows)) $rows = [['name' => 'Suite did not return an array of results', 'pass' => false, 'message' => '']];
            } catch (\Throwable $e) {
                $rows = [['name' => 'Suite threw an exception', 'pass' => false, 'message' => $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine()]];
            }
            $report[$key] = ['label' => $suite['label'] ?? $key, 'rows' => $rows];
        }
        return $report;
    }

    /* ---------------- Debug Tools page section ---------------- */

    public static function register_debug_section($tools) {
        $tools['bh-tests'] = [
            'label' => 'Test Runner',
            'render' => [self::class, 'render_debug_section'],
            'handle' => [self::class, 'handle_debug_action'],
            'reset' => null,
            // Running pure-logic assertions touches nothing real — an
            // admin should be able to sanity-check "is the code still
            // doing what it's supposed to" on a live site too, not only
            // in a dev environment.
            'safe_in_production' => true,
        ];
        return $tools;
    }

    public static function render_debug_section() {
        $suites = apply_filters('bhcore_test_suites', []);
        if (!$suites) {
            echo '<p class="description">No plugin has registered any test suites yet.</p>';
            return;
        }

        echo '<p class="description">Runs every registered plugin\'s test suite right here, on this site\'s own PHP — no CLI, no Composer, no separate dev environment needed.</p>';
        OUS_Debug::button('bh-tests', 'run_tests', 'Run all tests');

        $report = get_transient('ous_test_report');
        if (!$report) {
            echo '<p class="description">No results yet — click "Run all tests" above.</p>';
            return;
        }

        foreach ($report as $key => $suite) {
            $total = count($suite['rows']);
            $passed = count(array_filter($suite['rows'], function ($r) { return $r['pass']; }));
            $all_pass = $passed === $total;

            echo '<h4 style="margin-top:16px;">' . esc_html($suite['label']) . ' — '
               . '<span style="color:' . ($all_pass ? '#00a32a' : '#d63638') . ';">' . (int) $passed . ' / ' . (int) $total . ' passed</span></h4>';

            echo '<div class="bhy-table-wrap"><table class="widefat striped"><thead><tr><th style="width:70px;">Result</th><th>Test</th><th>Detail</th></tr></thead><tbody>';
            foreach ($suite['rows'] as $row) {
                $badge = $row['pass']
                    ? '<span style="color:#fff;background:#00a32a;padding:2px 8px;border-radius:3px;font-size:11px;">PASS</span>'
                    : '<span style="color:#fff;background:#d63638;padding:2px 8px;border-radius:3px;font-size:11px;">FAIL</span>';
                echo '<tr><td>' . $badge . '</td><td>' . esc_html($row['name']) . '</td><td>' . esc_html($row['message']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
    }

    public static function handle_debug_action($action) {
        if ($action === 'run_tests') {
            $report = self::run_all();
            set_transient('ous_test_report', $report, HOUR_IN_SECONDS);

            $total = 0; $passed = 0;
            foreach ($report as $suite) {
                $total += count($suite['rows']);
                $passed += count(array_filter($suite['rows'], function ($r) { return $r['pass']; }));
            }
            return "Ran $total test(s) across " . count($report) . " suite(s): $passed passed, " . ($total - $passed) . ' failed. See results below.';
        }
        return 'Unknown action.';
    }
}
