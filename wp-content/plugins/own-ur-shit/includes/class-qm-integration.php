<?php
if (!defined('ABSPATH')) exit;

/**
 * Real Query Monitor integration — AJ's own ask, straight after this
 * session's audit pass: "good use of Query Monitor where needed" as part
 * of shoring up core debug tooling before diving into the bh-contest
 * conversion. Until now Query Monitor was just an install-check card on
 * the Dashboard (class-dashboard.php) — QM itself had zero awareness of
 * anything this ecosystem logs. This registers a real QM_Collector that
 * surfaces THIS request's own OUS_DebugLog entries (class-debug-log.php's
 * new request_buffer(), in-memory only, zero extra DB queries) directly
 * inside Query Monitor's own admin-toolbar panel — so triaging a bug
 * while actively building something doesn't mean bouncing between QM's
 * panel and Debug Tools' Console & Logs screen as two separate tools.
 *
 * Entirely optional/degrading: every hook here only fires if Query
 * Monitor's own base classes exist (checked at the point QM itself loads
 * them, via the 'qm/collectors' filter — QM never fires that filter
 * unless it's active), so an install without QM installed is completely
 * unaffected — no class_exists() guard needed at add_action() time the
 * way OUS_Jobs/OUS_Notifications guard each other, since QM's own filter
 * simply never runs.
 */
class OUS_QM_Integration {
    public static function init() {
        add_filter('qm/collectors', [self::class, 'register_collector'], 20);
        add_filter('qm/outputter/html', [self::class, 'register_output'], 20);
    }

    public static function register_collector(array $collectors) {
        if (!class_exists('QM_Collector')) return $collectors; // defensive only — QM itself guarantees this before firing the filter
        $collectors['ous'] = new OUS_QM_Collector();
        return $collectors;
    }

    public static function register_output(array $output, $collector = null) {
        // QM's own 'qm/outputter/html' filter signature varies across
        // versions (some pass the collector map only, some pass it
        // pre-keyed) — deliberately not assuming which, and instead
        // pulling our own collector back out of QM's own registry via
        // its public accessor, the same way QM's bundled outputters do
        // internally. If QM's collector registry doesn't have 'ous' for
        // any reason (e.g. this filter fired before ours registered it —
        // shouldn't happen given the priority order below, but this is a
        // real degrade, not a fatal), skip silently rather than fatal on
        // a null collector.
        if (!class_exists('QM_Collectors') || !class_exists('OUS_QM_Output')) return $output;
        $collector = QM_Collectors::get('ous');
        if (!$collector) return $output;
        $output['ous'] = new OUS_QM_Output($collector);
        return $output;
    }
}

/**
 * The collector itself does almost nothing — OUS_DebugLog::log() already
 * did the real work of capturing every error/warning/info this request
 * (PHP fatals, doing_it_wrong, deprecated, uncaught exceptions, anything
 * any plugin explicitly logged) into its own in-memory request_buffer().
 * process() just copies that buffer into QM's own ->data shape once,
 * at the point QM asks every collector to finalize.
 */
if (class_exists('QM_Collector')) {
    class OUS_QM_Collector extends QM_Collector {
        public $id = 'ous';

        public function name() {
            return 'Own Ur Shit';
        }

        public function process() {
            $this->data['rows'] = class_exists('OUS_DebugLog') ? OUS_DebugLog::request_buffer() : [];
        }
    }
}

/**
 * Minimal HTML panel — one row per log() call this request, level/
 * source/message/file:line, same fields Debug Tools' own Console & Logs
 * table already shows (see class-debug-log.php's render_table()) so
 * there's no second, differently-shaped view of the same data to learn.
 * Deliberately does NOT duplicate that table's filtering/pagination/
 * trace-expansion UI — this panel is for "what just happened on THIS
 * request, at a glance," the full table is still the right place for
 * real triage across many requests.
 */
if (class_exists('QM_Output_Html')) {
    class OUS_QM_Output extends QM_Output_Html {
        public function name() {
            return 'Own Ur Shit';
        }

        public function output() {
            $data = $this->collector->get_data();
            $rows = $data['rows'] ?? [];

            $this->before_non_tabular_output();

            if (!$rows) {
                echo '<div class="qm-notice"><p>No Own Ur Shit log entries on this request.</p></div>';
                $this->after_non_tabular_output();
                return;
            }

            echo '<table class="qm-sortable-theads"><thead><tr>';
            echo '<th>Level</th><th>Source</th><th>Message</th><th>Location</th>';
            echo '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $level = $row['level'] ?? 'info';
                $class = $level === 'error' ? 'qm-warn' : ($level === 'warning' ? 'qm-info' : '');
                echo '<tr' . ($class ? ' class="' . esc_attr($class) . '"' : '') . '>';
                echo '<td>' . esc_html(strtoupper($level)) . '</td>';
                echo '<td>' . esc_html($row['source'] ?? '') . '</td>';
                echo '<td>' . esc_html($row['message'] ?? '') . '</td>';
                echo '<td>' . esc_html(($row['file'] ?? '') ? $row['file'] . ':' . ($row['line'] ?? 0) : '') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

            $this->after_non_tabular_output();
        }

        public function admin_menu(array $menu) {
            $data = $this->collector->get_data();
            $count = count($data['rows'] ?? []);
            $has_error = false;
            foreach (($data['rows'] ?? []) as $row) {
                if (($row['level'] ?? '') === 'error') { $has_error = true; break; }
            }
            $menu[] = $this->menu([
                'title' => esc_html('Own Ur Shit' . ($count ? " ({$count})" : '')),
            ]);
            return $menu;
        }
    }
}
