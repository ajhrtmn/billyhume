<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared async job queue on WP-Cron + MySQL (no external broker), delegating to Action
 * Scheduler when it is available.
 *
 *   OUS_Jobs::register('bhr_recheck_one_link', ['BHR_Links', 'recheck_one']);   // on 'init'
 *   OUS_Jobs::enqueue('bhr_recheck_one_link', ['link_id' => $id]);
 *
 * A job with no registered handler never runs and is eventually logged as failed, never fatal.
 */
class OUS_Jobs {
    const MAX_ATTEMPTS = 5;
    const CRON_HOOK = 'bhcore_run_due_jobs';
    const AS_VERSION = '3.9.2';
    const AS_ZIP_URL = 'https://github.com/woocommerce/action-scheduler/archive/refs/tags/' . self::AS_VERSION . '.zip';
    /** @var array<string, callable> */
    private static $handlers = [];

    /**
     * Vendored Action Scheduler location. It is installed on demand from Debug Tools -> Job Queue;
     * until then every method falls back to this class's own table. The public API is the same either way.
     */
    private static function vendor_path(): string {
        return OUS_PATH . 'includes/vendor/action-scheduler/action-scheduler.php';
    }

    public static function library_available(): bool {
        return file_exists(self::vendor_path());
    }

    public static function init(): void {
        // WHY: deferred to plugins_loaded because WooCommerce bundles its own Action Scheduler and loads
        // after this plugin (folder order); requiring ours on top of it redeclares functions and fatals.
        // Only require ours when class_exists('ActionScheduler') is still false here.
        add_action('plugins_loaded', function () {
            if (!class_exists('ActionScheduler') && self::library_available()) {
                require_once self::vendor_path();
                // WHY: AS's bootstrap defers setup to a plugins_loaded priority-1 callback, which has already
                // passed by now; call initialize_latest_version() directly or the ActionScheduler class never exists.
                if (class_exists('ActionScheduler_Versions')) {
                    ActionScheduler_Versions::initialize_latest_version();
                }
                add_action('init', function () { if (class_exists('ActionScheduler')) ActionScheduler::init(self::vendor_path()); }, 1);
            }
        });

        add_action('init', [self::class, 'maybe_schedule_cron']);
        add_action(self::CRON_HOOK, [self::class, 'run_due_jobs']);
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
        add_action('admin_post_ous_jobs_install_as', [self::class, 'handle_install_action_scheduler']);
    }

    /**
     * One-click Action Scheduler installer: capability + nonce checks, WP download_url()/unzip_file()
     * of the official tagged release, then moves the extracted folder into includes/vendor/.
     */
    public static function handle_install_action_scheduler(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('ous_jobs_install_as');

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';

        // Initialize and verify WP_Filesystem before any network work; move()/delete() fail without it.
        global $wp_filesystem;
        if (!WP_Filesystem()) {
            self::redirect_with_notice("Could not get filesystem access (WP_Filesystem() failed) - this host likely requires FTP/SSH credentials for direct file writes. Check wp-config.php's FS_METHOD setting or your hosting file-permission setup, then try again.");
            return;
        }

        $tmp = download_url(self::AS_ZIP_URL);
        if (is_wp_error($tmp)) {
            // Full WP_Error message: download failures are often SSL-related and need the real text.
            self::redirect_with_notice('Download failed: ' . $tmp->get_error_message());
            return;
        }

        $vendor_dir = OUS_PATH . 'includes/vendor';
        if (!is_dir($vendor_dir)) {
            if (!wp_mkdir_p($vendor_dir)) {
                @unlink($tmp);
                self::redirect_with_notice('Could not create the vendor directory (' . $vendor_dir . ') — check that includes/ is writable by the web server.');
                return;
            }
        }

        $unzip_to = $vendor_dir . '/action-scheduler-extract-tmp';
        $result = unzip_file($tmp, $unzip_to);
        @unlink($tmp);

        if (is_wp_error($result)) {
            self::redirect_with_notice('Extraction failed: ' . $result->get_error_message());
            return;
        }

        // GitHub tag archives extract to "action-scheduler-{tag}/"; normalized to "action-scheduler/".
        $extracted = glob($unzip_to . '/action-scheduler-*', GLOB_ONLYDIR);
        $final_dir = $vendor_dir . '/action-scheduler';

        if (!$extracted) {
            $wp_filesystem->delete($unzip_to, true);
            self::redirect_with_notice('Downloaded archive did not contain the expected "action-scheduler-' . self::AS_VERSION . '/" folder — GitHub\'s archive layout may have changed. Nothing was installed.');
            return;
        }

        if (is_dir($final_dir)) $wp_filesystem->delete($final_dir, true);
        $moved = $wp_filesystem->move($extracted[0], $final_dir, true);
        $wp_filesystem->delete($unzip_to, true);

        if (!$moved) {
            self::redirect_with_notice('Could not move the extracted library into place (' . $final_dir . ') — check file permissions. Nothing was installed.');
            return;
        }

        // Only report success if the vendor entry file really exists now.
        if (!file_exists(self::vendor_path())) {
            self::redirect_with_notice('Install did not complete — the library file was not found at ' . self::vendor_path() . ' after moving. Nothing changed; the job queue is still on the fallback runner.');
            return;
        }

        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('info', 'Action Scheduler library installed via one-click installer.', ['version' => self::AS_VERSION], 'OUS_Jobs');
        }
        self::redirect_with_notice('Action Scheduler ' . self::AS_VERSION . ' installed — the job queue now runs on it automatically. Any jobs already pending in the old table will still be processed by the fallback runner until they drain.');
    }

    // Every failure message contains one of these phrases; success does not. Only picks the toast color.
    private static function notice_looks_like_failure(string $msg): bool {
        foreach (['failed', 'Could not', 'did not', 'not allowed'] as $needle) {
            if (stripos($msg, $needle) !== false) return true;
        }
        return false;
    }

    private static function redirect_with_notice(string $msg): void {
        $is_failure = self::notice_looks_like_failure($msg);
        if (class_exists('OUS_Toast')) {
            OUS_Toast::queue($msg, $is_failure ? 'error' : 'success');
        }
        if ($is_failure && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('warning', $msg, [], 'OUS_Jobs');
        }
        wp_safe_redirect(add_query_arg(['page' => 'ous-debug', 'ous_jobs_msg' => rawurlencode($msg)], admin_url('admin.php')) . '#ous-section-bh-jobs');
        exit;
    }

    // Queue visibility lives in a Debug Tools section.
    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register_debug_section($tools): array {
        $tools['bh-jobs'] = [
            'label' => 'Job Queue',
            'render' => [self::class, 'render_debug_section'],
            'handle' => [self::class, 'handle_debug_action'],
            'reset' => [self::class, 'reset_debug'],
            'group' => OUS_Debug::GROUP_MONITORING,
        ];
        return $tools;
    }

    public static function render_debug_section(): void {
        if (self::library_available()) {
            echo '<p>&#9989; Running on <strong>Action Scheduler ' . esc_html(self::AS_VERSION) . '</strong> (vendored, real library — not the fallback table). Its own queue view: <a href="' . esc_url(admin_url('tools.php?page=action-scheduler')) . '">Tools → Scheduled Actions</a>.</p>';
        } else {
            echo '<p>&#9888; Running on the built-in fallback queue (a plain wpdb table). <a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=ous_jobs_install_as'), 'ous_jobs_install_as')) . '" class="button button-primary" onclick="return confirm(\'Download and install Action Scheduler ' . esc_js(self::AS_VERSION) . ' from GitHub now?\');">Install Action Scheduler ' . esc_html(self::AS_VERSION) . '</a> — one click, downloads the real official release directly from GitHub onto this site, no separate plugin to activate.</p>';
        }
        if (isset($_GET['ous_jobs_msg'])) {
            echo '<div class="notice notice-info inline"><p>' . esc_html(wp_unslash($_GET['ous_jobs_msg'])) . '</p></div>';
        }

        $counts = self::counts_by_status();
        echo '<p>Pending: <strong>' . $counts['pending'] . '</strong> &nbsp; Running: <strong>' . $counts['running'] . '</strong> &nbsp; Done: <strong>' . $counts['done'] . '</strong> &nbsp; Failed: <strong>' . $counts['failed'] . '</strong></p>';
        OUS_Debug::button('bh-jobs', 'run_now', 'Run due jobs now (don\'t wait for cron)');

        $failed = self::recent_failed(10);
        if ($failed) {
            echo '<h4>Recently failed</h4><div class="bhy-table-wrap"><table class="widefat striped"><thead><tr><th>Hook</th><th>Attempts</th><th>Last error</th><th>Updated</th></tr></thead><tbody>';
            foreach ($failed as $f) {
                echo '<tr><td>' . esc_html($f['hook']) . '</td><td>' . (int) $f['attempts'] . '</td><td>' . esc_html($f['last_error']) . '</td><td>' . esc_html($f['updated_at']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
    }

    public static function handle_debug_action(string $action): string {
        if ($action === 'run_now') {
            self::run_due_jobs(100);
            return 'Ran up to 100 due jobs.';
        }
        return 'Unknown action.';
    }

    public static function reset_debug(): string {
        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table() . " WHERE status IN ('done','failed')");
        $wpdb->query("DELETE FROM " . self::table() . " WHERE status IN ('done','failed')");
        return "Cleared $count finished/failed job row(s). Pending/running jobs are left alone.";
    }

    public static function maybe_schedule_cron(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'bhcore_every_minute', self::CRON_HOOK);
        }
    }

    // Registered via add_filter independently of init() so cron_schedules is never registered too late.
    /**
     * @param array<string, mixed> $schedules
     * @return array<string, mixed>
     */
    public static function register_cron_schedule($schedules): array {
        $schedules['bhcore_every_minute'] = ['interval' => 60, 'display' => 'Every Minute (BH Jobs)'];
        return $schedules;
    }

    private static function table(): string {
        return OUS_Tables::jobs();
    }

    // Fallback-queue size cap: trims only 'done'/'failed' rows, on roughly 1 in 50 completions.
    const MAX_FINISHED_ROWS = 5000;

    private static function maybe_trim_finished(): void {
        if (self::library_available()) return; // Action Scheduler owns its own table/cleanup when active.
        if (wp_rand(1, 50) !== 1) return;
        global $wpdb;
        $table = self::table();
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status IN ('done','failed')");
        if ($count > self::MAX_FINISHED_ROWS) {
            $excess = $count - self::MAX_FINISHED_ROWS;
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table WHERE status IN ('done','failed') ORDER BY updated_at ASC LIMIT %d", $excess
            ));
        }
    }

    // Register a handler once (typically on init). Last registration for a hook wins, so namespace
    // hook names by plugin (bhr_, bhc_, ...).
    public static function register(string $hook, callable $callback): void {
        self::$handlers[$hook] = $callback;

        // Action Scheduler calls do_action($hook, ...$args); adapt to our single-array-argument callback.
        if (self::library_available()) {
            add_action(sanitize_key($hook), function (...$as_args) use ($callback) {
                call_user_func($callback, $as_args[0] ?? []);
            });
        }
    }

    /** @param array<mixed> $args */
    public static function enqueue(string $hook, array $args = [], int $delay_seconds = 0): int {
        $hook = sanitize_key($hook);
        $delay_seconds = max(0, (int) $delay_seconds);

        if (self::library_available()) {
            // One group so AS's Scheduled Actions screen can filter to this ecosystem's jobs.
            return $delay_seconds > 0
                ? as_schedule_single_action(time() + $delay_seconds, $hook, [$args], 'bhcore')
                : as_enqueue_async_action($hook, [$args], 'bhcore');
        }

        global $wpdb;
        $wpdb->insert(self::table(), [
            'hook' => $hook,
            'args' => wp_json_encode($args),
            'run_after' => gmdate('Y-m-d H:i:s', time() + $delay_seconds),
        ]);
        return (int) $wpdb->insert_id;
    }

    // Runs a bounded batch of due fallback-queue jobs from WP-Cron every minute. Left hooked even
    // with Action Scheduler active (empty SELECT is cheap) so the fallback is always ready.
    public static function run_due_jobs(int $batch_size = 20): void {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE status = 'pending' AND run_after <= %s ORDER BY run_after ASC LIMIT %d",
            current_time('mysql', true), $batch_size
        ), ARRAY_A);

        foreach ($rows as $row) {
            self::run_one($row);
        }
    }

    /** @param array<string, mixed> $row */
    private static function run_one(array $row): void {
        global $wpdb;
        $id = (int) $row['id'];

        // Atomic pending -> running claim so overlapping cron ticks can't both run a row.
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status = 'running' WHERE id = %d AND status = 'pending'", $id
        ));
        if (!$claimed) return;

        $hook = $row['hook'];
        $args = json_decode($row['args'], true) ?: [];

        if (!isset(self::$handlers[$hook])) {
            // Missing handler may be a load-order fluke, so it counts as a retried attempt, not a permanent failure.
            self::mark_failed($id, $row['attempts'], 'No handler registered for hook: ' . $hook, $hook);
            return;
        }

        try {
            call_user_func(self::$handlers[$hook], $args);
            $wpdb->update(self::table(), ['status' => 'done'], ['id' => $id]);
            self::maybe_trim_finished();
        } catch (\Throwable $e) {
            self::mark_failed($id, $row['attempts'], $e->getMessage(), $hook);
        }
    }

    /** @param mixed $attempts_so_far */
    private static function mark_failed(int $id, $attempts_so_far, string $error, string $hook = ''): void {
        global $wpdb;
        $attempts = (int) $attempts_so_far + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $wpdb->update(self::table(), ['status' => 'failed', 'attempts' => $attempts, 'last_error' => $error], ['id' => $id]);
            self::maybe_trim_finished();
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('warning', "Job permanently failed after $attempts attempts: $hook — $error", ['job_id' => $id, 'hook' => $hook], 'OUS_Jobs');
            }
            return;
        }

        // Exponential backoff (1, 2, 4, 8 minutes...).
        $backoff_minutes = pow(2, $attempts - 1);
        $wpdb->update(self::table(), [
            'status' => 'pending', 'attempts' => $attempts, 'last_error' => $error,
            'run_after' => gmdate('Y-m-d H:i:s', time() + $backoff_minutes * MINUTE_IN_SECONDS),
        ], ['id' => $id]);
    }

    /* ---------------- admin visibility ---------------- */

    /** @return array<string, int> */
    public static function counts_by_status(): array {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT status, COUNT(*) as c FROM " . self::table() . " GROUP BY status", ARRAY_A);
        $out = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
        foreach ($rows as $r) $out[$r['status']] = (int) $r['c'];
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public static function recent_failed(int $limit = 20): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE status = 'failed' ORDER BY updated_at DESC LIMIT %d", $limit
        ), ARRAY_A);
    }
}
