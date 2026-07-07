<?php
if (!defined('ABSPATH')) exit;

/**
 * A shared async job queue — the WP-Cron-driven, no-external-infra
 * version of a real message queue, since this ecosystem deliberately
 * doesn't assume Redis/RabbitMQ/anything beyond plain WordPress+MySQL
 * being available on a hosting account. Same "core provides it, any
 * plugin uses it with one call, zero registration with a central
 * authority, zero awareness of who else is using it" shape as
 * OUS_Notifications.
 *
 * USAGE, from any plugin that depends on this one:
 *
 *   // once, in that plugin's own bootstrap:
 *   add_action('init', function () {
 *       if (class_exists('OUS_Jobs')) OUS_Jobs::register('bhr_recheck_one_link', ['BHR_Links', 'recheck_one']);
 *   });
 *
 *   // wherever that plugin wants the work to actually happen later,
 *   // off the current request:
 *   OUS_Jobs::enqueue('bhr_recheck_one_link', ['link_id' => $id]);
 *
 * A job hook a plugin enqueues but never registers a handler for simply
 * never runs (and eventually gets logged as failed after MAX_ATTEMPTS)
 * — same "harmless no-op" safety property every other extension point
 * in this ecosystem has, so enqueueing from a plugin whose OWN handler
 * registration didn't run for some reason (wrong load order, etc.)
 * fails quietly rather than fatal-erroring.
 */
class OUS_Jobs {
    const MAX_ATTEMPTS = 5;
    const CRON_HOOK = 'bhcore_run_due_jobs';
    private static $handlers = [];

    public static function init() {
        add_action('init', [self::class, 'maybe_schedule_cron']);
        add_action(self::CRON_HOOK, [self::class, 'run_due_jobs']);
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
    }

    // Visibility into the queue lives on the shared Debug Tools page
    // rather than a dedicated admin screen of its own — this is
    // operational visibility for whoever runs the site, not a feature
    // any end user ever sees, so it belongs with Debug Tools' existing
    // "seed/reset/inspect" role rather than a new top-level menu.
    public static function register_debug_section($tools) {
        $tools['bh-jobs'] = [
            'label' => 'Job Queue',
            'render' => [self::class, 'render_debug_section'],
            'handle' => [self::class, 'handle_debug_action'],
            'reset' => [self::class, 'reset_debug'],
        ];
        return $tools;
    }

    public static function render_debug_section() {
        $counts = self::counts_by_status();
        echo '<p>Pending: <strong>' . $counts['pending'] . '</strong> &nbsp; Running: <strong>' . $counts['running'] . '</strong> &nbsp; Done: <strong>' . $counts['done'] . '</strong> &nbsp; Failed: <strong>' . $counts['failed'] . '</strong></p>';
        echo OUS_Debug::button('bh-jobs', 'run_now', 'Run due jobs now (don\'t wait for cron)');

        $failed = self::recent_failed(10);
        if ($failed) {
            echo '<h4>Recently failed</h4><div class="bhy-table-wrap"><table class="widefat striped"><thead><tr><th>Hook</th><th>Attempts</th><th>Last error</th><th>Updated</th></tr></thead><tbody>';
            foreach ($failed as $f) {
                echo '<tr><td>' . esc_html($f['hook']) . '</td><td>' . (int) $f['attempts'] . '</td><td>' . esc_html($f['last_error']) . '</td><td>' . esc_html($f['updated_at']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
    }

    public static function handle_debug_action($action) {
        if ($action === 'run_now') {
            self::run_due_jobs(100);
            return 'Ran up to 100 due jobs.';
        }
        return 'Unknown action.';
    }

    public static function reset_debug() {
        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table() . " WHERE status IN ('done','failed')");
        $wpdb->query("DELETE FROM " . self::table() . " WHERE status IN ('done','failed')");
        return "Cleared $count finished/failed job row(s). Pending/running jobs are left alone.";
    }

    public static function maybe_schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'bhcore_every_minute', self::CRON_HOOK);
        }
    }

    // Registered separately from init() (add_filter runs regardless of
    // action-hook ordering) so "did I register cron_schedules before
    // WordPress needed it" isn't a load-order footgun.
    public static function register_cron_schedule($schedules) {
        $schedules['bhcore_every_minute'] = ['interval' => 60, 'display' => 'Every Minute (BH Jobs)'];
        return $schedules;
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhcore_jobs';
    }

    // Any plugin calls this once (typically on init) to say "when a job
    // with this hook name comes up, call my callback with its args."
    // Multiple plugins registering the SAME hook name would only ever
    // run the last one registered in a given request — hook names
    // should be namespaced by the registering plugin (bhr_, bhc_, etc.)
    // the same way WordPress action/filter names already are by
    // convention, so this is a non-issue in practice.
    public static function register($hook, $callback) {
        self::$handlers[$hook] = $callback;
    }

    public static function enqueue($hook, $args = [], $delay_seconds = 0) {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'hook' => sanitize_key($hook),
            'args' => wp_json_encode($args),
            'run_after' => gmdate('Y-m-d H:i:s', time() + max(0, (int) $delay_seconds)),
        ]);
        return (int) $wpdb->insert_id;
    }

    // Pulls a bounded batch of due, pending jobs and runs each — called
    // from WP-Cron every minute. Bounded (not "all due jobs") so one
    // cron tick on a site with a huge backlog can't run indefinitely and
    // start overlapping with the next scheduled tick.
    public static function run_due_jobs($batch_size = 20) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE status = 'pending' AND run_after <= %s ORDER BY run_after ASC LIMIT %d",
            current_time('mysql', true), $batch_size
        ), ARRAY_A);

        foreach ($rows as $row) {
            self::run_one($row);
        }
    }

    private static function run_one($row) {
        global $wpdb;
        $id = (int) $row['id'];

        // Claim it first (pending -> running) via an atomic conditional
        // UPDATE, same TOCTOU-avoidance reasoning as BHM_Wallet::debit()
        // — if two cron ticks somehow overlap, only one can actually
        // claim a given row.
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status = 'running' WHERE id = %d AND status = 'pending'", $id
        ));
        if (!$claimed) return;

        $hook = $row['hook'];
        $args = json_decode($row['args'], true) ?: [];

        if (!isset(self::$handlers[$hook])) {
            // No registered handler this request — could be a load-order
            // fluke (plugin that would register it hasn't run its init()
            // yet) rather than a permanently-orphaned job, so this counts
            // as a failed ATTEMPT (with backoff) rather than an instant
            // permanent failure.
            self::mark_failed($id, $row['attempts'], 'No handler registered for hook: ' . $hook);
            return;
        }

        try {
            call_user_func(self::$handlers[$hook], $args);
            $wpdb->update(self::table(), ['status' => 'done'], ['id' => $id]);
        } catch (\Throwable $e) {
            self::mark_failed($id, $row['attempts'], $e->getMessage());
        }
    }

    private static function mark_failed($id, $attempts_so_far, $error) {
        global $wpdb;
        $attempts = (int) $attempts_so_far + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $wpdb->update(self::table(), ['status' => 'failed', 'attempts' => $attempts, 'last_error' => $error], ['id' => $id]);
            return;
        }

        // Simple exponential backoff (1, 2, 4, 8 minutes...) rather than
        // an immediate retry next tick — a transient failure (an
        // external feed timing out, say) gets a real chance to clear up
        // before hammering it again every 60 seconds.
        $backoff_minutes = pow(2, $attempts - 1);
        $wpdb->update(self::table(), [
            'status' => 'pending', 'attempts' => $attempts, 'last_error' => $error,
            'run_after' => gmdate('Y-m-d H:i:s', time() + $backoff_minutes * MINUTE_IN_SECONDS),
        ], ['id' => $id]);
    }

    /* ---------------- admin visibility ---------------- */

    public static function counts_by_status() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT status, COUNT(*) as c FROM " . self::table() . " GROUP BY status", ARRAY_A);
        $out = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
        foreach ($rows as $r) $out[$r['status']] = (int) $r['c'];
        return $out;
    }

    public static function recent_failed($limit = 20) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE status = 'failed' ORDER BY updated_at DESC LIMIT %d", $limit
        ), ARRAY_A);
    }
}
