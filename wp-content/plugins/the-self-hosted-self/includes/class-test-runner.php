<?php
if (!defined('ABSPATH') ) exit;


/**
 * In-browser test runner on the site PHP. Plugins register suites via bhcore_test_suites
 * ([label, callback]); a suite returns rows [name, pass, message]. A suite that throws
 * is reported as one failing row.
 */
class OUS_TestRunner {
    public static function init(): void {
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
    }

    /* ---------------- tiny assertion helpers any suite can use ---------------- */

    /**
     * @param mixed $expected
     * @param mixed $actual
     * @return array<string, mixed>
     */
    public static function assert_same($expected, $actual, string $label): array {
        $pass = $expected === $actual;
        return [
            'name' => $label,
            'pass' => $pass,
            'message' => $pass ? '' : ('Expected ' . self::describe($expected) . ', got ' . self::describe($actual)),
        ];
    }

    /**
     * @param mixed $actual
     * @return array<string, mixed>
     */
    public static function assert_true($actual, string $label): array {
        return self::assert_same(true, (bool) $actual, $label);
    }

    /**
     * @param mixed $actual
     * @return array<string, mixed>
     */
    public static function assert_false($actual, string $label): array {
        return self::assert_same(false, (bool) $actual, $label);
    }

    /** @param mixed $v */
    private static function describe($v): string {
        if (is_array($v)) return wp_json_encode($v);
        if (is_bool($v)) return $v ? 'true' : 'false';
        if (is_null($v)) return 'null';
        return (string) $v;
    }

    /* ---------------- running every registered suite ---------------- */

    /** @return array<string, mixed> */
    public static function run_all(): array {
        $suites = apply_filters('bhcore_test_suites', []);
        $report = [];
        foreach ($suites as $key => $suite) {
            $rows = [];
            try {
                $rows = call_user_func($suite['callback']);
                if (!is_array($rows)) $rows = [['name' => 'Suite did not return an array of results', 'pass' => false, 'message' => '']];
            } catch (\Throwable $e) {
                $rows = [['name' => OUS_Words::get('admin.test_runner.suite_threw_exception'), 'pass' => false, 'message' => $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine()]];
            }
            $report[$key] = ['label' => $suite['label'] ?? $key, 'rows' => $rows];
        }
        return $report;
    }

    /* ---------------- Debug Tools page section ---------------- */

    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register_debug_section($tools): array {
        $tools['bh-tests'] = [
            'label' => OUS_Words::get('admin.test_runner.test_runner'),
            'render' => [self::class, 'render_debug_section'],
            'handle' => [self::class, 'handle_debug_action'],
            'reset' => null,
            // Pure-logic assertions touch nothing real, so allowed on live.
            'safe_in_production' => true,
            'group' => OUS_Debug::GROUP_MONITORING,
        ];
        return $tools;
    }

    public static function render_debug_section(): void {
        $suites = apply_filters('bhcore_test_suites', []);
        if (!$suites) {
            echo ('<p class="description">' . OUS_Words::get('admin.test_runner.no_plugin_has_registered_any') . '</p>');
            return;
        }

        echo ('<p class="description">' . OUS_Words::get('admin.test_runner.runs_every_registered_plugin_s') . '</p>');
        OUS_Debug::button('bh-tests', 'run_tests', OUS_Words::get('admin.test_runner.run_all_tests'));

        $report = self::load_report();
        if (!$report) {
            echo ('<p class="description">' . OUS_Words::get('admin.test_runner.no_results_yet_click_run') . '</p>');
            return;
        }

        self::print_copy_script_once();

        // One "copy every failure" button for pasting into a ticket.
        $all_failures_text = self::format_failures_text($report);
        $any_failures = trim($all_failures_text) !== '';
        echo '<p style="margin-top:12px;">';
        echo '<button type="button" class="button" ' . ($any_failures ? '' : 'disabled') . ' onclick="bhCopyToClipboard(\'ous-test-fails-all\', this)">'
           . ($any_failures ? OUS_Words::get('admin.test_runner.copy_all_failures') : OUS_Words::get('admin.test_runner.no_failures_copy')) . '</button>';
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
                echo '<button type="button" class="button button-small" onclick="bhCopyToClipboard(\'' . esc_js($textarea_id) . ('\', this)">' . OUS_Words::get('admin.test_runner.copy_failures')) . (int) ($total - $passed) . ')</button>';
            }
            echo '</h4>';

            echo '<textarea id="' . esc_attr($textarea_id) . '" style="position:absolute;left:-9999px;">' . esc_textarea(self::format_failures_text([$key => $suite])) . '</textarea>';

            echo ('<div class="bhy-table-wrap"><table class="widefat striped"><thead><tr><th style="width:70px;">' . OUS_Words::get('admin.test_runner.result') . '</th><th>' . OUS_Words::get('admin.test_runner.test') . '</th><th>' . OUS_Words::get('admin.test_runner.detail') . '</th></tr></thead><tbody>');
            foreach ($suite['rows'] as $row) {
                $badge = $row['pass']
                    ? ('<span style="color:#fff;background:#00a32a;padding:2px 8px;border-radius:3px;font-size:11px;">' . OUS_Words::get('admin.test_runner.pass') . '</span>')
                    : ('<span style="color:#fff;background:#d63638;padding:2px 8px;border-radius:3px;font-size:11px;">' . OUS_Words::get('admin.test_runner.fail') . '</span>');
                echo '<tr><td>' . $badge . '</td><td>' . esc_html($row['name']) . '</td><td>' . esc_html($row['message']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
    }

    /**
     * Plain-text dump of failing rows grouped by suite; shared by both copy
     * buttons so they cannot drift.
     *
     * @param array<string, mixed> $report
     */
    private static function format_failures_text(array $report): string {
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

    // Clipboard helper printed once per page, with an execCommand fallback.
    private static function print_copy_script_once(): void {
        static $printed = false;
        if ($printed) return;
        $printed = true;
        ?>
        <script>
<?php echo (string) file_get_contents(OUS_PATH . 'assets/inline/test-runner.js'); ?></script>
        <?php
    }

    public static function handle_debug_action(string $action): string {
        if ($action === 'run_tests') {
            $report = self::run_all();
            self::store_report($report);

            $total = 0; $passed = 0;
            foreach ($report as $suite) {
                $total += count($suite['rows']);
                $passed += count(array_filter($suite['rows'], function ($r) { return $r['pass']; }));
            }
            return "Ran $total test(s) across " . count($report) . " suite(s): $passed passed, " . ($total - $passed) . ' ' . OUS_Words::get('admin.test_runner.failed_see_results_below');
        }
        return OUS_Words::get('admin.debug_log.unknown_action');
    }

    // WHY: OUS_ReliableStore, not transients: this install's persistent object
    // cache has lost transient writes, so results vanished after redirect.
    /** @param array<string, mixed> $report */
    private static function store_report($report): void {
        OUS_ReliableStore::set('test_report', $report, HOUR_IN_SECONDS);
    }

    /** @return array<string, mixed>|null */
    private static function load_report() {
        return OUS_ReliableStore::get('test_report');
    }
}
