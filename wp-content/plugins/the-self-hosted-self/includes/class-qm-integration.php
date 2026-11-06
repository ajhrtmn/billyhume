<?php
if (!defined('ABSPATH')) exit;

/**
 * Query Monitor integration: shows this request's OUS_DebugLog entries
 * (in-memory request_buffer()) in QM's panel. Hooked via 'qm/collectors',
 * which QM only fires when active, so no class_exists() guard is needed.
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
        // The filter signature varies across QM versions, so fetch our collector
        // from QM's registry and skip silently if it is missing.
        if (!class_exists('QM_Collectors') || !class_exists('OUS_QM_Output')) return $output;
        $collector = QM_Collectors::get('ous');
        if (!$collector) return $output;
        $output['ous'] = new OUS_QM_Output($collector);
        return $output;
    }
}

/**
 * Copies OUS_DebugLog's request buffer into QM's ->data on process().
 */
if (class_exists('QM_Collector')) {
    class OUS_QM_Collector extends QM_Collector {
        public $id = 'ous';

        public function name() {
            return 'The Self-Hosted Self';
        }

        public function process() {
            $this->data['rows'] = class_exists('OUS_DebugLog') ? OUS_DebugLog::request_buffer() : [];
        }
    }
}

/**
 * Minimal panel: one row per log() call this request (level/source/message/
 * file:line). Full triage stays in Console & Logs.
 */
if (class_exists('QM_Output_Html')) {
    class OUS_QM_Output extends QM_Output_Html {
        public function name() {
            return 'The Self-Hosted Self';
        }

        public function output() {
            $data = $this->collector->get_data();
            $rows = $data['rows'] ?? [];

            $this->before_non_tabular_output();

            if (!$rows) {
                echo '<div class="qm-notice"><p>No The Self-Hosted Self log entries on this request.</p></div>';
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
                'title' => esc_html('The Self-Hosted Self' . ($count ? " ({$count})" : '')),
            ]);
            return $menu;
        }
    }
}
