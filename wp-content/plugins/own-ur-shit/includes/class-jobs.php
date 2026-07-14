<?php
if (!defined('ABSPATH')) exit;

// OUS_VER 3.4.19 — register_debug_section() now sets 'group' =>
// OUS_Debug::GROUP_MONITORING (Debug Tools reorganization pass — see
// class-debug.php's own docblock), filing this under "Monitoring &
// Health" instead of the default bucket. No other change.
//
// OUS_VER 3.4.18 — redirect_with_notice() (the Action Scheduler
// installer's success/failure hand-off) now also queues a BHCoreToast via
// OUS_Toast::queue(), type inferred from whether the message text starts
// with a known failure phrase — see that method's own comment for why
// this is a text-sniff rather than a real status flag. Additive only:
// the existing $_GET['ous_jobs_msg'] notice this already rendered is
// unchanged.

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
    const AS_VERSION = '3.9.2';
    const AS_ZIP_URL = 'https://github.com/woocommerce/action-scheduler/archive/refs/tags/' . self::AS_VERSION . '.zip';
    private static $handlers = [];

    /**
     * Action Scheduler (Apache-2.0, github.com/woocommerce/action-scheduler)
     * is the real, battle-tested version of what this class hand-rolls —
     * the exact same library WooCommerce itself bundles for its own
     * background jobs, proven at 10,000+ jobs/hour on live sites. We
     * vendor it the same way (its PHP dropped directly into this
     * plugin's own includes/vendor/ folder, no Composer, no separate
     * plugin to activate) rather than reinventing it forever.
     *
     * The sandbox this codebase was written in has NO outbound network
     * access at all (confirmed, same wall documented elsewhere in this
     * ecosystem's own VISION.md) — so the actual library files could not
     * be fetched and vendored directly during this pass. Fabricating
     * placeholder code under a well-known open-source project's name
     * would be actively dishonest, so instead: a real one-click
     * installer below, using the exact same download_url()/unzip_file()
     * mechanism OUS_Registry's wporg_slug installer already uses for
     * WooCommerce (see class-registry.php) — it runs on the LIVE site,
     * which has real internet access, and pulls the actual official
     * release straight from GitHub the moment someone clicks the button
     * on Debug Tools → Job Queue.
     *
     * Until that button is clicked, every method below transparently
     * falls back to this class's own original wpdb-table implementation
     * — nothing about the existing job-queue behavior changes or breaks
     * for any plugin already calling register()/enqueue() today. Once
     * Action Scheduler IS present, register()/enqueue() delegate to it
     * instead, using its native add_action()/as_enqueue_async_action()
     * primitives, with zero call-site changes required anywhere in the
     * ecosystem — the public API of this class is unchanged either way.
     */
    private static function vendor_path() {
        return OUS_PATH . 'includes/vendor/action-scheduler/action-scheduler.php';
    }

    public static function library_available() {
        return file_exists(self::vendor_path());
    }

    public static function init() {
        // Must run before 'init' itself so Action Scheduler's own store/
        // migration classes are registered in time — this is the exact
        // hook timing Action Scheduler's own documentation specifies.
        //
        // QA fix, 3.4.85: real fatal caught the moment the OUS_Jobs::
        // init() nested-'init'-hook bug (see own-ur-shit.php's own
        // comment at this class's bootstrap call) was fixed and this
        // code path actually ran for the first time — WooCommerce
        // bundles its OWN copy of Action Scheduler
        // (woocommerce/packages/action-scheduler/), and requiring this
        // vendored copy on top of an already-loaded one redeclares the
        // same global functions (as_enqueue_async_action() etc.) and
        // fatals outright. class_exists('ActionScheduler') is the
        // guard: if Action Scheduler is ALREADY loaded — by WooCommerce,
        // or by any other plugin that bundles it — there is nothing to
        // require and nothing to boot here; register()/enqueue() below
        // already just call its native primitives once it's present,
        // regardless of who loaded it, so this plugin transparently
        // rides whichever copy got there first instead of insisting on
        // its own.
        //
        // Second QA fix on top of the first: the class_exists('ActionScheduler')
        // check above still isn't reliable if it runs at FILE-PARSE time
        // (own-ur-shit.php's own bootstrap now calls OUS_Jobs::init()
        // directly, before 'plugins_loaded' — see that fix's own
        // comment) — WordPress loads active plugins' main files in
        // folder-name order, and "own-ur-shit" sorts before
        // "woocommerce", so WooCommerce's copy of Action Scheduler
        // genuinely isn't loaded yet at that point, regardless of
        // whether WooCommerce is active. This is the SAME "don't trust
        // class_exists() at file-parse time, only after plugins_loaded"
        // principle already documented elsewhere in this ecosystem
        // (see bh-contest.php's own bootstrap docblock) — caught here
        // by actually booting WordPress with WP_DEBUG_LOG on and hitting
        // the real fatal, not by re-reading that principle and assuming
        // it applied. Deferred to 'plugins_loaded' (which is guaranteed
        // to fire only after every active plugin's main file has been
        // read) so the class_exists() check reflects reality regardless
        // of plugin folder-name ordering.
        add_action('plugins_loaded', function () {
            if (!class_exists('ActionScheduler') && self::library_available()) {
                require_once self::vendor_path();
                add_action('init', function () { ActionScheduler::init(self::vendor_path()); }, 1);
            }
        });

        add_action('init', [self::class, 'maybe_schedule_cron']);
        add_action(self::CRON_HOOK, [self::class, 'run_due_jobs']);
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
        add_action('admin_post_ous_jobs_install_as', [self::class, 'handle_install_action_scheduler']);
    }

    /**
     * The one-click installer, same shape/safety checks as
     * OUS_Registry::handle_install() (capability check, nonce, WP's own
     * download_url()/unzip_file(), which both handle temp-file cleanup
     * and use WP_Filesystem rather than raw fopen/curl). Downloads the
     * real, official tagged release archive, then moves just the
     * extracted folder into place — no code is written by this method,
     * only real bytes fetched from GitHub's own release archive.
     */
    public static function handle_install_action_scheduler() {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('ous_jobs_install_as');

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';

        // QA fix: WP_Filesystem()'s return value was never checked —
        // when direct filesystem access isn't available (credentials
        // needed, permissions, etc.) every $wp_filesystem->move()/
        // delete() call below either silently no-ops or fatals, and the
        // user just sees "nothing happened" when the button is clicked.
        // Initialize (and verify) the filesystem transport FIRST, before
        // any network work, so a failure here is reported immediately
        // and honestly instead of after an already-wasted download.
        global $wp_filesystem;
        if (!WP_Filesystem()) {
            self::redirect_with_notice("Could not get filesystem access (WP_Filesystem() failed) - this host likely requires FTP/SSH credentials for direct file writes. Check wp-config.php's FS_METHOD setting or your hosting file-permission setup, then try again.");
            return;
        }

        $tmp = download_url(self::AS_ZIP_URL);
        if (is_wp_error($tmp)) {
            // QA fix: surface the FULL WP_Error message, not a
            // paraphrase — download_url() failures on this sandboxed/
            // Local-by-Flywheel install are often SSL-cert-related, and
            // the real message is the only way to actually diagnose it.
            self::redirect_with_notice('Download failed: ' . $tmp->get_error_message());
            return;
        }

        $vendor_dir = OUS_PATH . 'includes/vendor';
        if (!is_dir($vendor_dir)) {
            // QA fix: wp_mkdir_p()'s return value was unchecked — if
            // includes/ isn't writable, every subsequent step failed as
            // a downstream side effect instead of a clear error here.
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

        // GitHub's tag archive extracts into "{repo}-{tag}/" — for this
        // numeric, no-"v"-prefix tag (self::AS_VERSION, e.g. "3.9.2")
        // that's literally "action-scheduler-3.9.2/", which this glob
        // matches directly. Normalized to a stable "action-scheduler/"
        // folder name so vendor_path() above never needs to know the
        // version string.
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

        // Never claim success unless the vendor entry file genuinely
        // exists on disk now — the one check that actually proves the
        // install worked, independent of every step above reporting no
        // error.
        if (!file_exists(self::vendor_path())) {
            self::redirect_with_notice('Install did not complete — the library file was not found at ' . self::vendor_path() . ' after moving. Nothing changed; the job queue is still on the fallback runner.');
            return;
        }

        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('info', 'Action Scheduler library installed via one-click installer.', ['version' => self::AS_VERSION], 'OUS_Jobs');
        }
        self::redirect_with_notice('Action Scheduler ' . self::AS_VERSION . ' installed — the job queue now runs on it automatically. Any jobs already pending in the old table will still be processed by the fallback runner until they drain.');
    }

    // Every failure path above passes a message containing one of these
    // phrases; the one success path ("Action Scheduler X.X.X installed —
    // ...") does not. A real status flag threaded through every early
    // return would be cleaner, but rewriting handle_install_action_scheduler()'s
    // control flow for that is out of scope for adding toast feedback —
    // this text-sniff is good enough to pick success vs. error for a
    // purely supplementary toast, and a false positive here just means a
    // toast shows the wrong color, not the wrong message.
    private static function notice_looks_like_failure($msg) {
        foreach (['failed', 'Could not', 'did not', 'not allowed'] as $needle) {
            if (stripos($msg, $needle) !== false) return true;
        }
        return false;
    }

    private static function redirect_with_notice($msg) {
        if (class_exists('OUS_Toast')) {
            OUS_Toast::queue($msg, self::notice_looks_like_failure($msg) ? 'error' : 'success');
        }
        wp_safe_redirect(add_query_arg(['page' => 'ous-debug', 'ous_jobs_msg' => rawurlencode($msg)], admin_url('admin.php')) . '#ous-section-bh-jobs');
        exit;
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
            'group' => OUS_Debug::GROUP_MONITORING,
        ];
        return $tools;
    }

    public static function render_debug_section() {
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

        // Action Scheduler runs a due action via a plain do_action($hook,
        // ...$args) — a real WordPress hook, nothing bespoke. Our own
        // callback signature is call_user_func($callback, $args) (ONE
        // array argument), so this thin shim adapts AS's native
        // call shape to ours without changing what any existing plugin
        // passes to register() today.
        if (self::library_available()) {
            add_action(sanitize_key($hook), function (...$as_args) use ($callback) {
                call_user_func($callback, $as_args[0] ?? []);
            });
        }
    }

    public static function enqueue($hook, $args = [], $delay_seconds = 0) {
        $hook = sanitize_key($hook);
        $delay_seconds = max(0, (int) $delay_seconds);

        if (self::library_available()) {
            // Group everything under one namespace so Action Scheduler's
            // own Tools -> Scheduled Actions screen can filter to just
            // this ecosystem's jobs alongside whatever WooCommerce or
            // other plugins also schedule through the same shared library.
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

    // Pulls a bounded batch of due, pending jobs and runs each — called
    // from WP-Cron every minute. Only relevant to the fallback queue;
    // once Action Scheduler is installed it runs its OWN cron-driven
    // batch runner internally, so this method (and the cron event that
    // calls it) simply has nothing to do — left registered rather than
    // conditionally unhooked, since an empty-table SELECT every minute
    // is negligible cost and this keeps the fallback path always ready
    // to resume seamlessly if Action Scheduler were ever removed.
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
