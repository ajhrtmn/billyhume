<?php
if (!defined('ABSPATH') ) exit;

// OUS_VER 3.4.19 — register_debug_section() now sets 'group' =>
// OUS_Debug::GROUP_MONITORING (Debug Tools reorganization pass — see
// class-debug.php's own docblock), filing this under "Monitoring &
// Health" instead of the default bucket. No other change.

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
            'group' => OUS_Debug::GROUP_MONITORING,
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

        $report = self::load_report();
        if (!$report) {
            echo '<p class="description">No results yet — click "Run all tests" above.</p>';
            return;
        }

        self::print_copy_script_once();

        // One "copy every failure, across every suite" button up top —
        // the actual ask this whole feature exists for: pasting a
        // full failure dump into a chat/ticket without hand-picking
        // rows out of a big pass/fail table one at a time.
        $all_failures_text = self::format_failures_text($report, null);
        $any_failures = trim($all_failures_text) !== '';
        echo '<p style="margin-top:12px;">';
        echo '<button type="button" class="button" ' . ($any_failures ? '' : 'disabled') . ' onclick="bhCopyToClipboard(\'ous-test-fails-all\', this)">'
           . ($any_failures ? 'Copy ALL failures' : 'No failures to copy') . '</button>';
        echo '</p>';
        echo '<textarea id="ous-test-fails-all" style="position:absolute;left:-9999px;">' . esc_textarea($all_failures_text) . '</textarea>';

        foreach ($report as $key => $suite) {
            $total = count($suite['rows']);
            $passed = count(array_filter($suite['rows'], function ($r) { return $r['pass']; }));
            $all_pass = $passed === $total;
            $textarea_id = 'ous-test-fails-' . sanitize_key($key);

            echo '<h4 style="margin-top:16px;display:flex;align-items:center;gap:10px;">'
               . '<span>' . esc_html($suite['label']) . ' — '
               . '<span style="color:' . ($all_pass ? '#00a32a' : '#d63638') . ';">' . (int) $passed . ' / ' . (int) $total . ' passed</span></span>';
            if (!$all_pass) {
                echo '<button type="button" class="button button-small" onclick="bhCopyToClipboard(\'' . esc_js($textarea_id) . '\', this)">Copy failures (' . (int) ($total - $passed) . ')</button>';
            }
            echo '</h4>';

            echo '<textarea id="' . esc_attr($textarea_id) . '" style="position:absolute;left:-9999px;">' . esc_textarea(self::format_failures_text([$key => $suite], null)) . '</textarea>';

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

    /**
     * Plain-text dump of only the FAILING rows across the given report
     * (or a single suite's slice of it, passed in pre-filtered by the
     * caller) — grouped by suite label, one line per failure plus its
     * detail message. This is the text that actually lands on the
     * clipboard; kept as one shared formatter so the "copy all" button
     * and each suite's own "copy failures" button can't drift out of
     * sync with each other.
     */
    private static function format_failures_text(array $report, $unused = null) {
        $lines = [];
        foreach ($report as $suite) {
            $fails = array_values(array_filter($suite['rows'], function ($r) { return empty($r['pass']); }));
            if (!$fails) continue;
            $lines[] = '=== ' . $suite['label'] . ' — ' . count($fails) . ' failing ===';
            foreach ($fails as $row) {
                $lines[] = 'FAIL: ' . $row['name'];
                if (!empty($row['message'])) $lines[] = '  ' . $row['message'];
            }
            $lines[] = '';
        }
        return trim(implode("\n", $lines));
    }

    // Printed once per page load regardless of how many copy buttons
    // exist — navigator.clipboard.writeText() with a textarea
    // select()/execCommand('copy') fallback for browsers/contexts (e.g.
    // non-HTTPS admin) where the async Clipboard API isn't available.
    private static function print_copy_script_once() {
        static $printed = false;
        if ($printed) return;
        $printed = true;
        ?>
        <script>
        // Guarded against double-definition: this same shared helper is
        // also printed by class-debug-log.php's own copy button (both
        // sections can render on the same Debug Tools page load), so
        // whichever one renders first wins and the second is a no-op.
        if (typeof window.bhCopyToClipboard !== 'function') {
            window.bhCopyToClipboard = function (textareaId, btn) {
                var el = document.getElementById(textareaId);
                if (!el) return;
                var text = el.value;
                var done = function (ok) {
                    if (!btn) return;
                    var original = btn.textContent;
                    btn.textContent = ok ? 'Copied!' : 'Copy failed';
                    setTimeout(function () { btn.textContent = original; }, 1500);
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
                } else {
                    el.style.position = 'static';
                    el.select();
                    var ok = false;
                    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                    el.style.position = 'absolute';
                    el.style.left = '-9999px';
                    done(ok);
                }
            };
        }
        </script>
        <?php
    }

    public static function handle_debug_action($action) {
        if ($action === 'run_tests') {
            $report = self::run_all();
            self::store_report($report);

            $total = 0; $passed = 0;
            foreach ($report as $suite) {
                $total += count($suite['rows']);
                $passed += count(array_filter($suite['rows'], function ($r) { return $r['pass']; }));
            }
            return "Ran $total test(s) across " . count($report) . " suite(s): $passed passed, " . ($total - $passed) . ' failed. See results below.';
        }
        return 'Unknown action.';
    }

    // Real, reported bug this replaces: set_transient()/get_transient()
    // silently produced NOTHING on this specific install — "Run all
    // tests" reported success (the redirect message said "Ran N tests"),
    // but the results section itself showed nothing at all after
    // landing back on the page. Root cause is the same class of issue
    // this whole session kept hitting elsewhere (BHI_Portal's rewrite
    // rule, OUS_Debug::is_locked()): on an install with a persistent
    // object cache active, WordPress transients are stored ENTIRELY in
    // that cache, not the options table — a stuck/broken cache means the
    // write can report success while the very next request's read never
    // sees it. Now goes through OUS_ReliableStore (class-reliable-store.php),
    // the consolidated version of the same direct-DB read/write pattern
    // this fix originally hand-rolled here — see that class's own
    // docblock for the full explanation of why and when to use it.
    private static function store_report($report) {
        OUS_ReliableStore::set('test_report', $report, HOUR_IN_SECONDS);
    }

    private static function load_report() {
        return OUS_ReliableStore::get('test_report');
    }
}
