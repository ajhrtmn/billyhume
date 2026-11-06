<?php
if (!defined('ABSPATH')) exit;

/**
 * BH_Storybook_Panel: Design Suite section for the Storybook build and a11y
 * audit (.storybook/). Output goes to wp-content/uploads/storybook-static/.
 *
 * WHY: this is the codebase's one shell_exec surface, isolated here for audit
 * and locked by OUS_Debug::is_locked(); on a locked site only viewing an
 * existing build remains.
 */
class BH_Storybook_Panel {
    const OPTION_LOG = 'bh_storybook_last_log';

    // Workflow the GitHub Actions trigger dispatches.
    const WORKFLOW_FILE = 'storybook-audit.yml';

    public static function init(): void {
        add_action('admin_post_bh_storybook_run', [self::class, 'handle_run']);
        add_action('admin_post_bh_storybook_gha_trigger', [self::class, 'handle_gha_trigger']);
    }

    private static function repo(): string {
        return apply_filters('ous_github_updates_default_repo', 'ajhrtmn/billyhume');
    }

    // Ref the dispatched run checks out; the workflow and audited code live on master.
    private static function default_ref(): string {
        return apply_filters('bh_storybook_gha_ref', 'master');
    }

    private static function build_dir(): string {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . 'storybook-static';
    }

    private static function build_url(): string {
        $upload = wp_upload_dir();
        return trailingslashit($upload['baseurl']) . 'storybook-static';
    }

    // .storybook/ and package.json sit at the site root beside wp-config.php.
    private static function site_root(): string {
        return rtrim(ABSPATH, '/\\');
    }

    // WHY: PHP-FPM's PATH lacks node and even /bin, so bash is invoked by absolute
    // path and `-l` sources ~/.bash_profile, where nvm puts npm/npx on PATH.
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

        self::render_gha_section();

        echo '</div>';
    }

    // Same build + audit via GitHub Actions, meant to work from production.
    private static function render_gha_section(): void {
        $token_set = defined('OUS_GITHUB_ACTIONS_TOKEN') && OUS_GITHUB_ACTIONS_TOKEN !== '';
        $repo = self::repo();
        $run_url = 'https://github.com/' . $repo . '/actions/workflows/' . self::WORKFLOW_FILE;

        echo '<hr style="margin:20px 0;border:none;border-top:1px solid var(--shsas-border, var(--bhy-border, #dcdcde));">';
        echo '<h3 style="margin-top:0;">Run via GitHub Actions</h3>';
        echo '<p class="description">The same Storybook build and UX audit, run on GitHub\'s infrastructure instead of this server — works from anywhere, including production, since it never needs Node installed here. '
           . 'Results land as downloadable artifacts on the workflow run itself: <a href="' . esc_url($run_url) . '" target="_blank" rel="noopener">' . esc_html($repo) . ' → Actions → Storybook Build &amp; UX Audit</a>.</p>';

        if (!$token_set) {
            echo '<div class="bhy-alert bhy-alert-info">';
            echo '<strong>One-time setup needed.</strong> Add a fine-grained GitHub personal access token with this repo\'s <code>Actions: write</code> permission, then in <code>wp-config.php</code>: <code>define(\'OUS_GITHUB_ACTIONS_TOKEN\', \'&hellip;\');</code>. '
               . 'Generate one at <a href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener">github.com/settings/personal-access-tokens/new</a> — this plugin never sees or stores the token anywhere but that constant, and only ever sends it to <code>api.github.com</code>.';
            echo '</div>';
            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="bh_storybook_gha_trigger">';
        wp_nonce_field('bh_storybook_gha_trigger');
        echo '<button type="submit" class="button button-primary">Trigger via GitHub Actions</button>';
        echo '</form>';
    }

    public static function handle_run(): void {
        check_admin_referer('bh_storybook_run');
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.', '', ['response' => 403]);

        if (class_exists('OUS_Debug') && OUS_Debug::is_locked()) {
            self::redirect('Blocked: this looks like a production environment.');
        }

        $action = sanitize_key($_POST['bh_storybook_action'] ?? '');
        $root = self::site_root();

        // Runs past max_execution_time; safe because is_locked() keeps it off live.
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

    // POSTs to GitHub's workflow_dispatch endpoint. Not gated on is_locked():
    // it does no local work, and the fine-grained token is the access control.
    public static function handle_gha_trigger(): void {
        check_admin_referer('bh_storybook_gha_trigger');
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.', '', ['response' => 403]);

        if (!defined('OUS_GITHUB_ACTIONS_TOKEN') || OUS_GITHUB_ACTIONS_TOKEN === '') {
            self::redirect('No GitHub Actions token configured — see the setup note below.');
        }

        $url = 'https://api.github.com/repos/' . self::repo() . '/actions/workflows/' . self::WORKFLOW_FILE . '/dispatches';
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . OUS_GITHUB_ACTIONS_TOKEN,
                'Accept'        => 'application/vnd.github+json',
                'User-Agent'    => 'the-self-hosted-self/' . (defined('OUS_VER') ? OUS_VER : '0'),
            ],
            'body' => wp_json_encode(['ref' => self::default_ref()]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            self::redirect('GitHub Actions trigger failed: ' . $response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        // 204 No Content is GitHub's success response; no run ID is returned.
        if ($code === 204) {
            self::redirect('Triggered — check the Actions tab in a few seconds for the run.');
        }
        self::redirect('GitHub responded with HTTP ' . $code . ' — ' . wp_remote_retrieve_body($response));
    }

    // `never` tells PHPStan this always exits, so guards that call it stop execution.
    private static function redirect(string $msg): never {
        if (class_exists('OUS_Toast')) OUS_Toast::queue($msg, 'info');
        wp_safe_redirect(add_query_arg(['page' => 'bh-design', 'bh_storybook_msg' => rawurlencode($msg)], admin_url('admin.php')));
        exit;
    }
}
