<?php
if (!defined('ABSPATH')) exit;

/**
 * "Check GitHub for a newer version, install in one click" for plugins and themes.
 * WHY: GitHub zips cannot be scoped to a subdirectory, so this extracts the plugin folder,
 * rebuilds a single-plugin zip and installs it with overwrite_package. Others register via ous_github_updates_register.
 */
class OUS_GithubUpdates {
    const JOB_HOOK = 'ous_github_update_check';
    // WHY: one job per source, not one job looping all of them — a sequential loop of remote
    // fetches can exceed max_execution_time and be killed uncatchably, orphaning the job.
    const JOB_HOOK_ONE = 'ous_github_update_check_one';

    /** @var array<string, array<string, mixed>> */
    private static $sources = [];

    public static function init(): void {
        add_action('init', [self::class, 'load_sources'], 30); // after OUS_Registry's data is populated
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
        add_action('admin_post_ous_github_update_now', [self::class, 'handle_update_now']);
        add_action('admin_post_ous_github_check_now', [self::class, 'handle_check_now']);
        add_action('admin_post_ous_updates_toggle_absent', [self::class, 'handle_toggle_absent']);
        add_action('admin_post_ous_github_save_token', [self::class, 'handle_save_token']);

        if (class_exists('OUS_Jobs')) {
            OUS_Jobs::register(self::JOB_HOOK, [self::class, 'run_check']);
            OUS_Jobs::register(self::JOB_HOOK_ONE, [self::class, 'run_check_one']);
            add_action('init', [self::class, 'maybe_schedule_first_run']);
        }
    }

    /** Queues one independent check job per registered source (daily check and "Check now"). */
    private static function enqueue_all_checks(): void {
        foreach (array_keys(self::$sources) as $key) {
            OUS_Jobs::enqueue(self::JOB_HOOK_ONE, ['key' => $key], 0);
        }
    }

    /** @param array<string, mixed> $args */
    public static function run_check_one($args = []): void {
        $key = (string) ($args['key'] ?? '');
        if ($key !== '') self::check_one($key);
    }

    public static function maybe_schedule_first_run(): void {
        if (get_option('ous_github_updates_job_scheduled')) return;
        update_option('ous_github_updates_job_scheduled', time());
        OUS_Jobs::enqueue(self::JOB_HOOK, [], self::interval());
    }

    private static function interval(): int {
        return (int) apply_filters('ous_github_updates_check_interval', DAY_IN_SECONDS);
    }

    /** @param array<string, mixed> $args */
    public static function run_check($args = []): void {
        // Reschedule first so a fatal mid-sweep can't kill the recurring job.
        OUS_Jobs::enqueue(self::JOB_HOOK, [], self::interval());
        self::enqueue_all_checks();
    }

    /**
     * @param string $key
     * @param array $args {
     *   @type string $type       'plugin' or 'theme'. Required.
     *   @type string $label      Human name.
     *   @type string $file       Plugin only — 'slug/slug.php', same shape as OUS_Registry.
     *   @type string $stylesheet Theme only — the theme's directory name.
     *   @type string $repo       'owner/repo-name'.
     *   @type string $branch     Branch to track (e.g. 'dev', 'main').
     *   @type string $path       Subdirectory INSIDE that repo containing this plugin/theme's own root (e.g. 'wp-content/plugins/bh-crm'). Empty string if the repo root IS the plugin/theme root.
     * }
     * @param array<string, mixed> $args
     */
    public static function register(string $key, array $args): void {
        $key = sanitize_key($key);
        if (empty($args['type']) || empty($args['repo'])) return;
        $existing = self::$sources[$key] ?? ['label' => $key, 'branch' => 'main', 'path' => ''];
        self::$sources[$key] = array_merge($existing, $args);
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array {
        return self::$sources;
    }

    /**
     * Auto-registers this site's own plugins (OUS_Registry entries marked 'github'), then fires
     * 'ous_github_updates_register' for the rest.
     */
    public static function load_sources(): void {
        $default_repo = apply_filters('ous_github_updates_default_repo', 'ajhrtmn/billyhume');
        $default_branch = apply_filters('ous_github_updates_default_branch', 'dev');

        if (class_exists('OUS_Registry')) {
            foreach (OUS_Registry::all() as $key => $info) {
                // WHY: 'bundled_zip' is how older peer versions still mark themselves until updated.
                if ((empty($info['github']) && empty($info['bundled_zip'])) || empty($info['file'])) continue;
                self::register($key, [
                    'type' => 'plugin',
                    'label' => $info['label'],
                    'file' => $info['file'],
                    'repo' => $default_repo,
                    'branch' => $default_branch,
                    'path' => 'wp-content/plugins/' . dirname($info['file']),
                ]);
            }
        }

        // Core has no OUS_Registry entry, so register it explicitly.
        self::register('the-self-hosted-self', [
            'type' => 'plugin',
            'label' => OUS_Words::get('admin.github_updates.self_hosted_self_core'),
            'file' => 'the-self-hosted-self/the-self-hosted-self.php',
            'repo' => $default_repo,
            'branch' => $default_branch,
            'path' => 'wp-content/plugins/the-self-hosted-self',
        ]);

        // WHY: deploys only sync plugins, so the theme is registered from here too, from
        // whichever directory (new or legacy) is installed, always updating from the new path.
        $theme_current = wp_get_theme('the-self-hosted-self-theme');
        $theme_legacy  = wp_get_theme('own-ur-shit-theme');
        if ($theme_current->exists() || $theme_legacy->exists()) {
            self::register('the-self-hosted-self-theme', [
                'type' => 'theme',
                'label' => OUS_Words::get('admin.github_updates.self_hosted_self_theme'),
                'stylesheet' => $theme_current->exists() ? 'the-self-hosted-self-theme' : 'own-ur-shit-theme',
                'repo' => $default_repo,
                'branch' => $default_branch,
                'path' => 'wp-content/themes/the-self-hosted-self-theme',
            ]);
        }

        do_action('ous_github_updates_register');
    }

    /* ---------------- checking ---------------- */

    public static function check_all(): void {
        foreach (array_keys(self::$sources) as $key) {
            self::check_one($key);
        }
    }

    /**
     * Cheap single raw-file fetch comparing the remote 'Version' header with the installed one;
     * results for all sources live in one option.
     *
     * @return array<string, mixed>|null
     */
    public static function check_one(string $key): ?array {
        $source = self::$sources[$key] ?? null;
        if (!$source) return null;

        $remote_version = self::fetch_remote_version($source);
        $local_version = self::local_version($source);

        $status = get_option('ous_github_update_status', []);
        $status[$key] = [
            'label' => $source['label'],
            'local_version' => $local_version,
            'remote_version' => $remote_version,
            'checked_at' => time(),
            // a null remote (fetch failed) never reports "newer"
            'update_available' => ($remote_version && $local_version && version_compare($remote_version, $local_version, '>')),
        ];
        update_option('ous_github_update_status', $status);
        return $status[$key];
    }

    /** @param array<string, mixed> $source */
    private static function local_version($source): ?string {
        if ($source['type'] === 'theme') {
            $theme = wp_get_theme($source['stylesheet'] ?? '');
            return $theme->exists() ? $theme->get('Version') : null;
        }
        if (!function_exists('get_plugin_data')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $path = WP_PLUGIN_DIR . '/' . ($source['file'] ?? '');
        if (!file_exists($path)) return null;
        $data = get_plugin_data($path, false, false);
        return $data['Version'];
    }

    /** @param array<string, mixed> $source */
    private static function remote_main_file_path($source): string {
        $subdir = trim($source['path'] ?? '', '/');
        if ($source['type'] === 'theme') {
            $basename = 'style.css';
        } else {
            $basename = basename($source['file'] ?? '');
        }
        return ($subdir ? $subdir . '/' : '') . $basename;
    }

    /** @param array<string, mixed> $source */
    private static function fetch_remote_version($source): ?string {
        $path = self::remote_main_file_path($source);
        $token = self::token();
        // WHY: a private repo is only readable through the API with a token; the public raw
        // CDN caches for minutes, so a unique query string plus no-cache headers bypass it.
        $url = $token
            ? sprintf('https://api.github.com/repos/%s/contents/%s?ref=%s', $source['repo'], $path, rawurlencode($source['branch']))
            : sprintf('https://raw.githubusercontent.com/%s/%s/%s', $source['repo'], rawurlencode($source['branch']), $path);
        $headers = ['Cache-Control' => 'no-cache, no-store, max-age=0', 'Pragma' => 'no-cache'];
        if ($token) $headers += self::auth_headers($token) + ['Accept' => 'application/vnd.github.raw'];
        $response = wp_remote_get(add_query_arg('_ts', (string) time(), $url), ['timeout' => 10, 'headers' => $headers]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('warning', 'Could not fetch remote version for a GitHub update source.', [
                    'source_url' => $url,
                    'error' => is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response),
                ], 'OUS_GithubUpdates');
            }
            return null;
        }
        $body = wp_remote_retrieve_body($response);
        if (preg_match('/^[ \t\/*#@]*Version:\s*(.+)$/mi', $body, $m)) return trim($m[1]);
        return null;
    }

    // WHY: enqueues the check job instead of running it inline — a synchronous sweep of remote
    // fetches can exceed this host's hard request timeout and take the whole site down.
    public static function handle_check_now(): void {
        if (!current_user_can('update_plugins') && !current_user_can('update_themes')) wp_die('Not allowed.');
        check_admin_referer('ous_github_check_now');

        $redirect_args = ['page' => 'ous-debug'];

        if (class_exists('OUS_Jobs')) {
            self::enqueue_all_checks();
            $message = OUS_Words::get('admin.github_updates.queued_github_check_running_now');
            // Auto-submits the Job Queue's "Run due jobs now" button on the next page load (a separate
            // request), since WP-Cron may not fire reliably. Don't merge into this request.
            $redirect_args['ous_run_jobs'] = '1';
        } else {
            // OUS_Jobs unavailable: fall back to inline checking rather than a dead button.
            self::check_all();
            $message = OUS_Words::get('admin.github_updates.checked_github_updates');
        }

        if (class_exists('OUS_Toast')) OUS_Toast::queue($message, 'success');
        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    /**
     * Shown on the GitHub Updates panel: when off (default), hide rows for ecosystem
     * plugins/themes not installed on this site.
     */
    public static function show_absent_rows(): bool {
        return (bool) get_option('ous_updates_show_absent', false);
    }

    public static function handle_toggle_absent(): void {
        if (!current_user_can('update_plugins') && !current_user_can('update_themes')) wp_die('Not allowed.');
        check_admin_referer('ous_updates_toggle_absent');
        update_option('ous_updates_show_absent', !empty($_POST['show_absent']), false);
        // Back to whichever admin screen sent this (Debug Tools or page=ous share the toggle).
        $back = wp_get_referer();
        if (!$back || strpos($back, admin_url()) !== 0) {
            $back = add_query_arg(['page' => 'ous-debug'], admin_url('admin.php')) . '#ous-section-ous-github-updates';
        }
        wp_safe_redirect($back);
        exit;
    }

    /* ---------------- access token for a private repository ---------------- */

    const TOKEN_OPTION = 'ous_github_updates_token';

    /** The GitHub token: BH_GITHUB_TOKEN in wp-config.php wins over the one saved in Debug Tools. */
    public static function token(): string {
        if (defined('BH_GITHUB_TOKEN') && is_string(BH_GITHUB_TOKEN) && BH_GITHUB_TOKEN !== '') return BH_GITHUB_TOKEN;
        return (string) get_option(self::TOKEN_OPTION, '');
    }

    /** @return array<string, string> */
    private static function auth_headers(string $token): array {
        return ['Authorization' => 'Bearer ' . $token, 'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'billyhume-site-updates'];
    }

    /**
     * @param array<string, mixed> $source
     * @return string|\WP_Error path to the downloaded zip
     */
    private static function download_private_archive($source, string $token) {
        $tmp = wp_tempnam('ous-github-archive');
        $response = wp_remote_get(
            sprintf('https://api.github.com/repos/%s/zipball/refs/heads/%s', $source['repo'], rawurlencode($source['branch'])),
            ['timeout' => 60, 'stream' => true, 'filename' => $tmp, 'headers' => self::auth_headers($token)]
        );
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            @unlink($tmp);
            return is_wp_error($response) ? $response : new WP_Error('github_download', OUS_Words::get('admin.github_updates.github_refused_download_http') . ' ' . $code . OUS_Words::get('admin.github_updates.check_access_token_debug_tools'));
        }
        return $tmp;
    }

    public static function handle_save_token(): void {
        if (!current_user_can('update_plugins')) wp_die('Not allowed.', '', ['response' => 403]);
        check_admin_referer('ous_github_save_token');
        if (!empty($_POST['clear_token'])) {
            delete_option(self::TOKEN_OPTION);
        } else {
            $token = trim(sanitize_text_field(wp_unslash($_POST['github_token'] ?? '')));
            if ($token !== '') update_option(self::TOKEN_OPTION, $token, false);
        }
        wp_safe_redirect(add_query_arg(['page' => 'ous-debug'], admin_url('admin.php')) . '#ous-section-ous-github-updates');
        exit;
    }

    /** Token form: shows whether one is set, never the token itself. */
    private static function render_token_form(): void {
        $from_config = defined('BH_GITHUB_TOKEN') && BH_GITHUB_TOKEN !== '';
        $has = self::token() !== '';
        echo ('<details style="margin:0 0 12px;"><summary><strong>' . OUS_Words::get('admin.github_updates.private_repository_access') . '</strong> — ')
            . ($has ? '<span class="bhy-badge bhy-badge-success">token set' . ($from_config ? ' ' . OUS_Words::get('admin.github_updates.wp_config_php') : '') . '</span>' : ('<span class="bhy-badge bhy-badge-neutral">' . OUS_Words::get('admin.github_updates.no_token_public_repo_only') . '</span>'))
            . '</summary>';
        echo ('<p class="description">' . OUS_Words::get('admin.github_updates.needed_only_if_github_repository') . '</p>');
        if (!$from_config) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="ous_github_save_token">';
            wp_nonce_field('ous_github_save_token');
            echo '<input type="password" name="github_token" autocomplete="off" style="width:min(100%,420px);" placeholder="' . esc_attr($has ? 'Saved — paste a new one to replace it' : 'github_pat_…') . '"> ';
            echo ('<button class="button">' . OUS_Words::get('admin.github_updates.save_token') . '</button>');
            if ($has) echo (' <button class="button-link" name="clear_token" value="1">' . OUS_Words::get('admin.github_updates.remove_token') . '</button>');
            echo '</form>';
        }
        echo '</details>';
    }

    /* ---------------- the real update: download, re-zip, install ---------------- */

    public static function handle_update_now(): void {
        if (!current_user_can('update_plugins') && !current_user_can('update_themes')) wp_die('Not allowed.');
        check_admin_referer('ous_github_update_now');

        $key = sanitize_key($_POST['source_key'] ?? '');
        $result = self::update($key);

        $message = is_wp_error($result) ? OUS_Words::get('admin.github_updates.update_failed') . ' ' . $result->get_error_message() : OUS_Words::get('admin.github_updates.updated_successfully_from_github');
        if (class_exists('OUS_Toast')) OUS_Toast::queue($message, is_wp_error($result) ? 'error' : 'success');

        wp_safe_redirect(add_query_arg(['page' => 'ous-debug'], admin_url('admin.php')));
        exit;
    }

    /**
     * Downloads the repo archive at the branch, extracts the configured subdirectory, rebuilds a
     * clean <slug>/... zip, and installs it via WP's upgrader with 'overwrite_package' => true.
     *
     * @return true|\WP_Error
     */
    public static function update(string $key) {
        $source = self::$sources[$key] ?? null;
        if (!$source) return new WP_Error('unknown_source', OUS_Words::get('admin.github_updates.not_registered_update_source'));
        if (!class_exists('ZipArchive')) return new WP_Error('no_ziparchive', OUS_Words::get('admin.github_updates.ziparchive_isn_t_available_server'));

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';

        // 1. Download the whole-repo archive at the target ref.
        $token = self::token();
        if ($token) {
            $archive_path = self::download_private_archive($source, $token);
        } else {
            $archive_path = download_url(sprintf('https://codeload.github.com/%s/zip/refs/heads/%s', $source['repo'], rawurlencode($source['branch'])), 60);
        }
        if (is_wp_error($archive_path)) return $archive_path;

        // 2. Extract to a scratch directory (unzip_file() needs WP_Filesystem).
        WP_Filesystem();
        $extract_to = get_temp_dir() . 'ous-github-update-' . wp_generate_password(8, false) . '/';
        $unzipped = unzip_file($archive_path, $extract_to);
        @unlink($archive_path);
        if (is_wp_error($unzipped)) return $unzipped;

        // 3. The archive has exactly one top-level folder ("owner-repo-sha/"); descend into the configured path.
        $top_level = glob($extract_to . '*', GLOB_ONLYDIR);
        if (!$top_level) {
            self::cleanup_dir($extract_to);
            return new WP_Error('bad_archive', OUS_Words::get('admin.github_updates.downloaded_archive_had_no_top'));
        }
        $subdir = trim($source['path'] ?? '', '/');
        $source_dir = rtrim($top_level[0], '/') . ($subdir ? '/' . $subdir : '');
        if (!is_dir($source_dir)) {
            self::cleanup_dir($extract_to);
            return new WP_Error('path_not_found', 'The configured path ("' . $subdir . '") wasn\'t found in the downloaded archive.');
        }

        // 4. Rebuild a clean single-plugin/theme zip from just that subdirectory.
        $slug = $source['type'] === 'theme' ? ($source['stylesheet'] ?? basename($source_dir)) : dirname($source['file'] ?? basename($source_dir));
        $rebuilt_zip = get_temp_dir() . 'ous-github-update-' . $slug . '-' . wp_generate_password(8, false) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($rebuilt_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            self::cleanup_dir($extract_to);
            return new WP_Error('zip_open_failed', OUS_Words::get('admin.github_updates.could_not_open_temp_file'));
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = $slug . '/' . substr($item->getPathname(), strlen($source_dir) + 1);
            if (basename($item) === '.DS_Store' || strpos($relative, '/.git/') !== false) continue;
            $item->isDir() ? $zip->addEmptyDir($relative) : $zip->addFile($item->getPathname(), $relative);
        }
        $zip->close();
        self::cleanup_dir($extract_to);

        // 4b. WHY: manual backup — install() with 'overwrite_package' deletes the live directory first
        // and, unlike upgrade(), gets no core 'temp_backup' rollback. A failed extract would otherwise
        // leave a half-deleted plugin/theme.
        $install_dir = $source['type'] === 'theme'
            ? rtrim(get_theme_root(), '/') . '/' . ($source['stylesheet'] ?? '')
            : WP_PLUGIN_DIR . '/' . dirname($source['file'] ?? '.');
        $backup_dir = get_temp_dir() . 'ous-github-update-backup-' . $slug . '-' . wp_generate_password(8, false) . '/';
        $backed_up = is_dir($install_dir) && copy_dir($install_dir, $backup_dir);
        if (is_wp_error($backed_up)) $backed_up = false; // copy_dir() can return WP_Error; treat exactly like a plain false

        // 5. Hand the LOCAL zip path to the upgrader; download_package() returns local paths unchanged.
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = $source['type'] === 'theme' ? new Theme_Upgrader($skin) : new Plugin_Upgrader($skin);
        $result = $upgrader->install($rebuilt_zip, ['overwrite_package' => true]);
        @unlink($rebuilt_zip);

        // WP_Upgrader::run() returns the WP_Error as $result; the skin has no get_errors().
        $failed = is_wp_error($result) || !$result;

        if ($failed && $backed_up) {
            // Best-effort restore. If it fails, still return the original error and leave the backup on disk.
            self::cleanup_dir($install_dir);
            $restored = copy_dir($backup_dir, $install_dir);
            if (!is_wp_error($restored)) self::cleanup_dir($backup_dir);
        } elseif ($backed_up) {
            self::cleanup_dir($backup_dir); // success: backup not needed
        }

        if (is_wp_error($result)) return $result;
        if (!$result) return new WP_Error('install_failed', OUS_Words::get('admin.github_updates.upgrader_reported_failure_with_no'));

        self::check_one($key); // refresh status so the UI doesn't still show "update available"
        return true;
    }

    private static function cleanup_dir(string $dir): void {
        if (!is_dir($dir)) return;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /* ---------------- Debug Tools ---------------- */

    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register_debug_section($tools): array {
        // No 'handle': the action uses its own admin-post hook with update_plugins/update_themes checks.
        $tools['ous-github-updates'] = [
            'label' => OUS_Words::get('admin.github_updates.github_updates'),
            'render' => [self::class, 'render_debug_section'],
            'safe_in_production' => true,
            'group' => OUS_Debug::GROUP_MONITORING,
        ];
        return $tools;
    }

    public static function render_debug_section(): void {
        if (!self::$sources) {
            echo ('<p class="description">' . OUS_Words::get('admin.github_updates.no_github_update_sources_registered') . '</p>');
            return;
        }

        echo '<p class="description">Self-hosted update checking against each source\'s own GitHub repo/branch — no wordpress.org dependency. Checked automatically once a day; "Check now" refreshes this table immediately instead of waiting for the next scheduled pass. "Update now" downloads and installs directly, overwriting the currently-installed copy.</p>';

        self::render_token_form();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:12px;">';
        echo '<input type="hidden" name="action" value="ous_github_check_now">';
        wp_nonce_field('ous_github_check_now');
        echo ('<button class="button">' . OUS_Words::get('admin.github_updates.check_now') . '</button>');
        echo '</form>';

        // Follow-up to "Check now": auto-submit the Job Queue's "Run due jobs now" button (found by its
        // hidden-input signature) as a separate request; replaceState strips the flag so refresh won't loop.
        if (isset($_GET['ous_run_jobs'])) {
            ?>
<script>
(function () {
    var forms = document.querySelectorAll('form');
    for (var i = 0; i < forms.length; i++) {
        var plugin = forms[i].querySelector('input[name="ous_plugin"][value="bh-jobs"]');
        var action = forms[i].querySelector('input[name="ous_debug_action"][value="run_now"]');
        if (plugin && action) { forms[i].submit(); break; }
    }
    if (window.history && window.history.replaceState) {
        var url = new URL(window.location.href);
        url.searchParams.delete('ous_run_jobs');
        window.history.replaceState({}, '', url);
    }
})();
</script>
            <?php
        }

        $status = get_option('ous_github_update_status', []);

        // Hide rows for plugins not installed here by default.
        $show_absent = self::show_absent_rows();
        $absent = static function ($row): bool {
            return !$row || (empty($row['local_version']) && empty($row['update_available']));
        };
        $hidden_count = 0;
        if (!$show_absent) {
            foreach (self::$sources as $k => $s) {
                if ($absent($status[$k] ?? null)) $hidden_count++;
            }
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:10px;">';
        echo '<input type="hidden" name="action" value="ous_updates_toggle_absent">';
        wp_nonce_field('ous_updates_toggle_absent');
        echo '<label style="cursor:pointer;"><input type="checkbox" name="show_absent" value="1"' . checked($show_absent, true, false) . ' onchange="this.form.submit()"> ';
        echo OUS_Words::get('admin.github_updates.show_plugins_themes_not_installed');
        if (!$show_absent && $hidden_count) echo ' <span class="description">(' . (int) $hidden_count . ' hidden)</span>';
        echo '</label></form>';

        echo '<div class="bhy-table-wrap"><table class="widefat striped"><thead><tr>'
           . ('<th>Plugin/theme</th><th>' . OUS_Words::get('admin.github_updates.installed') . '</th><th>' . OUS_Words::get('admin.github_updates.github') . '</th><th>' . OUS_Words::get('admin.campaigns.status') . '</th><th></th>')
           . '</tr></thead><tbody>';

        foreach (self::$sources as $key => $source) {
            $row = $status[$key] ?? null;
            if (!$show_absent && $absent($row)) continue;
            echo '<tr>';
            echo '<td><strong>' . esc_html($source['label']) . '</strong><br><span class="description">' . esc_html($source['repo']) . ' @ ' . esc_html($source['branch']) . '</span></td>';
            echo '<td>' . esc_html($row['local_version'] ?? '—') . '</td>';
            echo '<td>' . esc_html($row['remote_version'] ?? 'not checked yet') . '</td>';

            if ($row && $row['update_available']) {
                echo ('<td><span class="bhy-badge bhy-badge-danger">' . OUS_Words::get('admin.github_updates.update_available') . '</span></td>');
                echo '<td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="ous_github_update_now">';
                echo '<input type="hidden" name="source_key" value="' . esc_attr($key) . '">';
                wp_nonce_field('ous_github_update_now');
                echo '<button class="button button-primary" onclick="return confirm(\'Download and install the latest ' . esc_js($source['label']) . (' from GitHub now? This overwrites the installed copy.\');">' . OUS_Words::get('admin.github_updates.update_now') . '</button>');
                echo '</form></td>';
            } elseif ($row && empty($row['remote_version'])) {
                // A check ran but couldn't read a remote version; must not fall through to "Up to date".
                echo ('<td><span class="bhy-badge bhy-badge-warning">' . OUS_Words::get('admin.github_updates.could_not_reach_github') . '</span></td>');
                echo '<td><span class="description">See Console &amp; Logs for the reason.</span></td>';
            } elseif ($row && empty($row['local_version'])) {
                // Remote version known but local version unknown; must not fall through to "Up to date" either.
                echo ('<td><span class="bhy-badge bhy-badge-warning">' . OUS_Words::get('admin.github_updates.can_t_detect_installed_version') . '</span></td>');
                echo ('<td><span class="description">' . OUS_Words::get('admin.github_updates.not_found_where_checker_expects') . '</span></td>');
            } elseif ($row) {
                echo ('<td><span class="bhy-badge bhy-badge-success">' . OUS_Words::get('admin.github_updates.up_date') . '</span></td><td></td>');
            } else {
                echo ('<td><span class="bhy-badge bhy-badge-neutral">' . OUS_Words::get('admin.github_updates.not_checked_yet') . '</span></td><td></td>');
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}
