<?php
if (!defined('ABSPATH')) exit;

/**
 * BH_Storybook_Panel — a Design Suite section for the ecosystem's real
 * Storybook integration (.storybook/, @storybook/addon-a11y, fixtures
 * generated from the actual BHY_UI:: renderers — see .storybook/README.md).
 *
 * WHY here and not Debug Tools: Design Suite (BHY_Gallery::render()) is
 * already the "Storybook-patterned live preview gallery" this ecosystem's
 * own description names — the real Storybook belongs beside its own
 * hand-built analog, not filed under general dev/ops tooling.
 *
 * WHY shell_exec is safe here and nowhere else in this ecosystem: this is
 * the ONE feature in the whole codebase that intentionally runs a build
 * command from a web request, and it is deliberately locked to
 * non-production environments via the exact same OUS_Debug::is_locked()
 * check every other "does real work" Debug Tools section already uses —
 * never runs on a live site, regardless of who is logged in or what they
 * POST. Wrapping this in an entirely separate, narrowly-scoped class
 * (rather than adding a shell_exec call inside an existing section) keeps
 * the one command-execution surface in this codebase easy to find, read,
 * and audit in one place.
 *
 * Build output is written to wp-content/uploads/storybook-static/ (real
 * uploads dir, already web-accessible, never synced by deploy-ftp.yml —
 * so a local build never leaks into a git-tracked path) and shown here in
 * an iframe. On a genuinely locked (production-looking) environment, the
 * run buttons don't render at all; only the most recent build (if one
 * exists at that path) is still viewable, since VIEWING a static file is
 * not "doing work" the production lock exists to prevent.
 */
class BH_Storybook_Panel {
    const OPTION_LOG = 'bh_storybook_last_log';

    public static function init(): void {
        add_action('admin_post_bh_storybook_run', [self::class, 'handle_run']);
    }

    private static function build_dir(): string {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . 'storybook-static';
    }

    private static function build_url(): string {
        $upload = wp_upload_dir();
        return trailingslashit($upload['baseurl']) . 'storybook-static';
    }

    // ABSPATH is this install's site root, and the Storybook config lives
    // one level below that same root (.storybook/, package.json) since
    // deployment here is a plain FTP sync of wp-content/plugins, not a
    // packaged WordPress-only tree — confirmed directly (package.json
    // sits beside wp-config.php), not assumed.
    private static function site_root(): string {
        return rtrim(ABSPATH, '/\\');
    }

    // PHP-FPM (what actually runs this, not the interactive terminal a
    // developer tests commands in) spawns with a PATH stripped down to
    // almost nothing -- confirmed live by dumping $PATH from inside an
    // actual web request on this install: Local by Flywheel sets it to
    // one single ghostscript bin directory (for PDF thumbnails), nothing
    // else. Every earlier attempt failed because of that one fact wearing
    // different masks: plain `npm` -- not found (no node bin dir on
    // PATH). Bare `bash -lc` -- "bash: command not found", because
    // *finding bash itself* also needs PATH, and this one doesn't even
    // have /bin on it. HOME, checked directly, was already set correctly
    // the whole time -- never the actual problem.
    //
    // The fix is the one piece that doesn't depend on PATH at all:
    // invoke bash by its own absolute, universal path (/bin/bash exists
    // on essentially every Unix/macOS install, unlike a developer's own
    // nvm version string). Once bash itself is running, `-l` makes it
    // source ~/.bash_profile, which is where nvm adds its bin directory
    // to PATH for everything the command actually needs (npm, npx).
    // Verified directly: /bin/bash -lc "which npm" resolves correctly
    // from inside a real web request; plain `bash -lc` from the same
    // request could not find bash at all to even try.
    private static function in_login_shell(string $cmd): string {
        return '/bin/bash -lc ' . escapeshellarg($cmd);
    }

    public static function render(): void {
        $locked = class_exists('OUS_Debug') && OUS_Debug::is_locked();
        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown';
        $has_build = file_exists(self::build_dir() . '/index.html');
        $log = get_option(self::OPTION_LOG, '');

        echo '<div class="bhy-card bh-storybook-panel">';
        echo '<h2>Storybook</h2>';
        echo '<p class="description">The real Storybook integration (<code>.storybook/</code>, axe-core a11y auditing per story, fixtures generated from the actual <code>BHY_UI::</code> renderers — never hand-copied markup). '
           . 'Detected environment: <code>' . esc_html($env) . '</code>.</p>';

        if ($locked) {
            echo '<div class="bhy-alert bhy-alert-warning"><strong>Read-only here.</strong> This looks like a production environment, so building or auditing from a web request is blocked — same rule Debug Tools\' own "do real work" sections follow. Run <code>npm run storybook</code> locally instead.</div>';
        } else {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px;">';
            echo '<input type="hidden" name="action" value="bh_storybook_run">';
            echo '<input type="hidden" name="bh_storybook_action" value="build">';
            wp_nonce_field('bh_storybook_run');
            echo '<button type="submit" class="button button-primary">Rebuild Storybook</button>';
            echo '</form>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">';
            echo '<input type="hidden" name="action" value="bh_storybook_run">';
            echo '<input type="hidden" name="bh_storybook_action" value="audit">';
            wp_nonce_field('bh_storybook_run');
            echo '<button type="submit" class="button">Run UX audit (logged-out front end)</button>';
            echo '</form>';
            echo '<p class="description">The audit button covers the logged-out front end only (<code>tests/ux/public.spec.ts</code>) — the admin-screen audit needs <code>WP_ADMIN_USER</code>/<code>WP_ADMIN_PASS</code> set in this environment\'s own shell, which a web request can\'t set on your behalf.</p>';
        }

        if (isset($_GET['bh_storybook_msg'])) {
            echo '<div class="notice notice-info inline"><p>' . esc_html(wp_unslash($_GET['bh_storybook_msg'])) . '</p></div>';
        }

        if ($log) {
            echo '<details style="margin-top:8px;"><summary>Last run output</summary><pre style="max-height:320px;overflow:auto;background:var(--shsas-surface-2, var(--bhy-subtle, #f6f7f7));padding:12px;border-radius:6px;white-space:pre-wrap;">' . esc_html($log) . '</pre></details>';
        }

        if ($has_build) {
            $mtime = filemtime(self::build_dir() . '/index.html');
            echo '<p style="margin-top:12px;"><strong>Last build:</strong> ' . esc_html(human_time_diff($mtime) . ' ago') . ' — <a href="' . esc_url(self::build_url() . '/index.html') . '" target="_blank" rel="noopener">open in a new tab</a> (Storybook sets frame-busting headers, so it cannot render in an inline iframe here).</p>';
        } else {
            echo '<p class="description" style="margin-top:12px;">No build yet.</p>';
        }

        echo '</div>';
    }

    public static function handle_run(): void {
        check_admin_referer('bh_storybook_run');
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.', 403);

        if (class_exists('OUS_Debug') && OUS_Debug::is_locked()) {
            self::redirect('Blocked: this looks like a production environment.');
        }

        $action = sanitize_key($_POST['bh_storybook_action'] ?? '');
        $root = self::site_root();

        // A build/audit run can genuinely take longer than PHP's default
        // max_execution_time (30s) — six widths, two themes, a real
        // Chromium launch. This IS the request; there is no background
        // job to hand it off to without duplicating a whole queue system
        // for a local-only dev button. set_time_limit(0) is safe here
        // specifically because is_locked() above already guarantees this
        // never runs against real traffic.
        set_time_limit(0);

        if ($action === 'build') {
            $out_dir = self::build_dir();
            wp_mkdir_p($out_dir);
            $cmd = self::in_login_shell(
                'cd ' . escapeshellarg($root)
                . ' && npm run storybook:fixtures 2>&1'
                . ' && npx storybook build -o ' . escapeshellarg($out_dir) . ' 2>&1'
            );
            $output = shell_exec($cmd);
            update_option(self::OPTION_LOG, (string) $output, false);
            self::redirect(file_exists($out_dir . '/index.html') ? 'Storybook build finished.' : 'Storybook build failed — see the log below.');
        }

        if ($action === 'audit') {
            $cmd = self::in_login_shell('cd ' . escapeshellarg($root) . ' && npx playwright test --project=public 2>&1');
            $output = shell_exec($cmd);
            update_option(self::OPTION_LOG, (string) $output, false);
            self::redirect('UX audit finished — see the log below.');
        }

        self::redirect('Unknown action.');
    }

    private static function redirect(string $msg): void {
        if (class_exists('OUS_Toast')) OUS_Toast::queue($msg, 'info');
        wp_safe_redirect(add_query_arg(['page' => 'bh-design', 'bh_storybook_msg' => rawurlencode($msg)], admin_url('admin.php')));
        exit;
    }
}
