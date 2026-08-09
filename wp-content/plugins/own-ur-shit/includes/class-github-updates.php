<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_GithubUpdates — Phase 4 of the OSS-integration master plan: a
 * real, self-hosted "check GitHub for a newer version, install it in
 * one click" mechanism for this ecosystem's own plugins (and, since the
 * mechanism itself is generic, any plugin or theme at all) — without
 * depending on wordpress.org's own update infrastructure or a paid
 * update-service SaaS, per VISION.md's no-vendor-lock-in posture.
 *
 * Deliberately NOT built on the third-party plugin-update-checker
 * library (YahnisElsts/plugin-update-checker) — that library assumes
 * one repo per plugin, and its real "Update Now" download path fetches
 * a zipball of the ENTIRE repo (GitHub's zipball API has no way to
 * scope to a subdirectory), which would silently try to install this
 * whole monorepo as though it were a single plugin. This class instead
 * downloads the repo archive once, extracts just the configured
 * subdirectory, rebuilds a clean single-plugin/theme zip (same
 * ZipArchive-from-a-directory pattern OUS_Registry::
 * regenerate_bundled_zip() already uses locally), and hands that LOCAL
 * zip path straight to WP core's own Plugin_Upgrader/Theme_Upgrader —
 * confirmed by reading wp-admin/includes/class-wp-upgrader.php directly
 * that download_package() returns a local, already-existing file path
 * unchanged rather than treating it as a URL, and confirmed both
 * upgraders' install() supports 'overwrite_package' => true (the same
 * option WP core itself uses to let a plugin be reinstalled over its
 * own existing files) — this is real, current WP core behavior, not
 * assumed.
 *
 * NOT locked to this specific install's repo — every source is
 * {type, label, file/stylesheet, repo, branch, path}, registered
 * either automatically (every ecosystem plugin already listed in
 * OUS_Registry, defaulting repo/branch to a filterable pair —
 * 'ajhrtmn/billyhume' / 'dev' on THIS install, override via
 * 'ous_github_updates_default_repo'/'_default_branch' on any other) or
 * explicitly via OUS_GithubUpdates::register() from any plugin's OR
 * THEME's own functions.php, pointed at a completely different repo if
 * that's where its own source actually lives. Themes register the same
 * way, just with 'type' => 'theme' and 'stylesheet' instead of 'file'.
 *
 * USAGE, from any plugin or theme wanting to register itself directly
 * (the ecosystem's own peer plugins get this for free via OUS_Registry,
 * this is for anything else):
 *
 *   add_action('ous_github_updates_register', function () {
 *       if (class_exists('OUS_GithubUpdates')) {
 *           OUS_GithubUpdates::register('my-plugin', [
 *               'type' => 'plugin',
 *               'label' => 'My Plugin',
 *               'file' => 'my-plugin/my-plugin.php',
 *               'repo' => 'someone/their-own-repo',
 *               'branch' => 'main',
 *               'path' => 'my-plugin', // the subdirectory INSIDE that repo containing the plugin root
 *           ]);
 *       }
 *   });
 */
class OUS_GithubUpdates {
    const JOB_HOOK = 'ous_github_update_check';

    /** @var array<string, array> */
    private static $sources = [];

    public static function init() {
        add_action('init', [self::class, 'load_sources'], 30); // after OUS_Registry's own data is definitely populated
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
        add_action('admin_post_ous_github_update_now', [self::class, 'handle_update_now']);

        if (class_exists('OUS_Jobs')) {
            OUS_Jobs::register(self::JOB_HOOK, [self::class, 'run_check']);
            add_action('init', [self::class, 'maybe_schedule_first_run']);
        }
    }

    public static function maybe_schedule_first_run() {
        if (get_option('ous_github_updates_job_scheduled')) return;
        update_option('ous_github_updates_job_scheduled', time());
        OUS_Jobs::enqueue(self::JOB_HOOK, [], self::interval());
    }

    private static function interval() {
        return (int) apply_filters('ous_github_updates_check_interval', DAY_IN_SECONDS);
    }

    public static function run_check($args = []) {
        // Reschedule first — same "a fatal mid-sweep shouldn't kill the
        // recurring job forever" reasoning as every other self-
        // rescheduling OUS_Jobs consumer in this ecosystem.
        OUS_Jobs::enqueue(self::JOB_HOOK, [], self::interval());
        self::check_all();
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
     */
    public static function register($key, array $args) {
        $key = sanitize_key($key);
        if (empty($args['type']) || empty($args['repo'])) return;
        $existing = self::$sources[$key] ?? ['label' => $key, 'branch' => 'main', 'path' => ''];
        self::$sources[$key] = array_merge($existing, $args);
    }

    public static function all() {
        return self::$sources;
    }

    /**
     * Auto-registers every ecosystem plugin OUS_Registry already knows
     * about — only the ones this project actually authors (identified
     * the same way OUS_Registry's own bundled-zip-freshness report
     * does: has a 'bundled_zip' entry), never a third-party/wporg_slug
     * plugin like WooCommerce or MailPoet, which have their own real
     * update channel (wordpress.org) already. Then opens the door for
     * anything else (a peer plugin's own repo, a theme) via a plain
     * action hook — zero-central-registration, same shape as
     * ous_registered_plugins/ous_debug_tools.
     */
    public static function load_sources() {
        if (class_exists('OUS_Registry')) {
            $default_repo = apply_filters('ous_github_updates_default_repo', 'ajhrtmn/billyhume');
            $default_branch = apply_filters('ous_github_updates_default_branch', 'dev');

            foreach (OUS_Registry::all() as $key => $info) {
                if (empty($info['bundled_zip']) || empty($info['file'])) continue;
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
        do_action('ous_github_updates_register');
    }

    /* ---------------- checking ---------------- */

    public static function check_all() {
        foreach (array_keys(self::$sources) as $key) {
            self::check_one($key);
        }
    }

    /**
     * Cheap — a single raw-file fetch, no zip download. Compares the
     * 'Version' header in the remote file against the locally installed
     * version, stores the result in one option (all sources together;
     * this ecosystem has a small, bounded number of them, no need for
     * per-source options).
     */
    public static function check_one($key) {
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
            // version_compare() tolerates non-semver-ish strings reasonably;
            // a null remote (fetch failed) never reports "newer".
            'update_available' => ($remote_version && $local_version && version_compare($remote_version, $local_version, '>')),
        ];
        update_option('ous_github_update_status', $status);
        return $status[$key];
    }

    private static function local_version($source) {
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

    private static function remote_main_file_path($source) {
        $subdir = trim($source['path'] ?? '', '/');
        if ($source['type'] === 'theme') {
            $basename = 'style.css';
        } else {
            $basename = basename($source['file'] ?? '');
        }
        return ($subdir ? $subdir . '/' : '') . $basename;
    }

    private static function fetch_remote_version($source) {
        $path = self::remote_main_file_path($source);
        $url = sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s',
            $source['repo'], rawurlencode($source['branch']), $path
        );
        $response = wp_remote_get($url, ['timeout' => 10]);
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

    /* ---------------- the real update: download, re-zip, install ---------------- */

    public static function handle_update_now() {
        if (!current_user_can('update_plugins') && !current_user_can('update_themes')) wp_die('Not allowed.');
        check_admin_referer('ous_github_update_now');

        $key = sanitize_key($_POST['source_key'] ?? '');
        $result = self::update($key);

        $message = is_wp_error($result) ? 'Update failed: ' . $result->get_error_message() : 'Updated successfully from GitHub.';
        if (class_exists('OUS_Toast')) OUS_Toast::queue($message, is_wp_error($result) ? 'error' : 'success');

        wp_safe_redirect(add_query_arg(['page' => 'ous-debug'], admin_url('admin.php')));
        exit;
    }

    /**
     * The real one-click action. Downloads the repo's archive at the
     * configured branch, extracts just the configured subdirectory,
     * rebuilds a clean <slug>/... zip (mirrors OUS_Registry::
     * regenerate_bundled_zip()'s own ZipArchive-from-a-directory
     * approach), then installs it via WP core's own upgrader with
     * 'overwrite_package' => true — the same mechanism core itself uses
     * to let a plugin/theme be reinstalled over its own existing files.
     *
     * @return true|WP_Error
     */
    public static function update($key) {
        $source = self::$sources[$key] ?? null;
        if (!$source) return new WP_Error('unknown_source', 'Not a registered update source.');
        if (!class_exists('ZipArchive')) return new WP_Error('no_ziparchive', 'ZipArchive isn\'t available on this server.');

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';

        // 1. Download the whole-repo archive at the target ref.
        $archive_url = sprintf(
            'https://codeload.github.com/%s/zip/refs/heads/%s',
            $source['repo'], rawurlencode($source['branch'])
        );
        $archive_path = download_url($archive_url, 60);
        if (is_wp_error($archive_path)) return $archive_path;

        // 2. Extract the whole thing to a scratch directory — WP's own
        // unzip_file() needs WP_Filesystem, same as any core upgrade.
        WP_Filesystem();
        $extract_to = get_temp_dir() . 'ous-github-update-' . wp_generate_password(8, false) . '/';
        $unzipped = unzip_file($archive_path, $extract_to);
        @unlink($archive_path);
        if (is_wp_error($unzipped)) return $unzipped;

        // 3. GitHub's archive has exactly one top-level folder
        // ("owner-repo-shortsha/") — find it, then descend into the
        // configured subdirectory inside it.
        $top_level = glob($extract_to . '*', GLOB_ONLYDIR);
        if (!$top_level) {
            self::cleanup_dir($extract_to);
            return new WP_Error('bad_archive', 'The downloaded archive had no top-level folder — unexpected GitHub response shape.');
        }
        $subdir = trim($source['path'] ?? '', '/');
        $source_dir = rtrim($top_level[0], '/') . ($subdir ? '/' . $subdir : '');
        if (!is_dir($source_dir)) {
            self::cleanup_dir($extract_to);
            return new WP_Error('path_not_found', 'The configured path ("' . $subdir . '") wasn\'t found in the downloaded archive.');
        }

        // 4. Rebuild a clean single-plugin/theme zip from just that
        // subdirectory — same approach as OUS_Registry::
        // regenerate_bundled_zip(), just sourced from the freshly
        // downloaded copy instead of this server's own disk.
        $slug = $source['type'] === 'theme' ? ($source['stylesheet'] ?? basename($source_dir)) : dirname($source['file'] ?? basename($source_dir));
        $rebuilt_zip = get_temp_dir() . 'ous-github-update-' . $slug . '-' . wp_generate_password(8, false) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($rebuilt_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            self::cleanup_dir($extract_to);
            return new WP_Error('zip_open_failed', 'Could not open a temp file for the rebuilt zip.');
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

        // 5. Hand the LOCAL zip path to WP core's own upgrader.
        // download_package() (wp-admin/includes/class-wp-upgrader.php)
        // returns a local, already-existing file path unchanged rather
        // than treating it as a URL — confirmed by reading that method
        // directly, not assumed — so this works exactly like WP core's
        // own "Upload Plugin"/"Upload Theme" flow, just fed a zip this
        // class built instead of one a user picked from their computer.
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = $source['type'] === 'theme' ? new Theme_Upgrader($skin) : new Plugin_Upgrader($skin);
        $result = $upgrader->install($rebuilt_zip, ['overwrite_package' => true]);
        @unlink($rebuilt_zip);

        // Real bug caught by a real PHPStan run: Automatic_Upgrader_Skin
        // (and its parent WP_Upgrader_Skin) has no get_errors() method or
        // $errors property at all — that call would have fataled with
        // "Call to undefined method" on every single real update attempt,
        // success or failure, confirmed by reading both classes' actual
        // source directly (wp-admin/includes/class-{wp-upgrader-skin,
        // automatic-upgrader-skin}.php). WP_Upgrader::run() (which
        // install() calls internally) already returns the real WP_Error
        // directly as $result on failure — that's the actual, correct
        // source of truth this needed, not a nonexistent skin method.
        if (is_wp_error($result)) return $result;
        if (!$result) return new WP_Error('install_failed', 'The upgrader reported failure with no specific error — check debug.log.');

        self::check_one($key); // refresh status immediately so the UI doesn't still show "update available" right after a successful update
        return true;
    }

    private static function cleanup_dir($dir) {
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

    public static function register_debug_section($tools) {
        // No 'handle' key — the real action rides its own admin-post.php
        // hook (handle_update_now), not the shared Debug Tools button
        // dispatcher, since it needs update_plugins/update_themes
        // capability checks specifically, not the generic bhcore_manage_*
        // gate every other Debug Tools action uses. Same "'handle' is
        // optional, omit it entirely rather than setting null" shape
        // BH_Event::register_debug_section() already uses.
        $tools['ous-github-updates'] = [
            'label' => 'GitHub Updates',
            'render' => [self::class, 'render_debug_section'],
            'safe_in_production' => true,
            'group' => OUS_Debug::GROUP_MONITORING,
        ];
        return $tools;
    }

    public static function render_debug_section() {
        if (!self::$sources) {
            echo '<p class="description">No GitHub update sources registered.</p>';
            return;
        }

        echo '<p class="description">Self-hosted update checking against each source\'s own GitHub repo/branch — no wordpress.org dependency. Checked automatically once a day (a manual "check now" pass isn\'t wired up yet — this table shows the most recent scheduled check\'s result); "Update now" downloads and installs directly, overwriting the currently-installed copy.</p>';

        $status = get_option('ous_github_update_status', []);

        echo '<div class="bhy-table-wrap"><table class="widefat striped"><thead><tr>'
           . '<th>Plugin/theme</th><th>Installed</th><th>On GitHub</th><th>Status</th><th></th>'
           . '</tr></thead><tbody>';

        foreach (self::$sources as $key => $source) {
            $row = $status[$key] ?? null;
            echo '<tr>';
            echo '<td><strong>' . esc_html($source['label']) . '</strong><br><span class="description">' . esc_html($source['repo']) . ' @ ' . esc_html($source['branch']) . '</span></td>';
            echo '<td>' . esc_html($row['local_version'] ?? '—') . '</td>';
            echo '<td>' . esc_html($row['remote_version'] ?? 'not checked yet') . '</td>';

            if ($row && $row['update_available']) {
                echo '<td><span class="bhy-badge bhy-badge-danger">Update available</span></td>';
                echo '<td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="ous_github_update_now">';
                echo '<input type="hidden" name="source_key" value="' . esc_attr($key) . '">';
                wp_nonce_field('ous_github_update_now');
                echo '<button class="button button-primary" onclick="return confirm(\'Download and install the latest ' . esc_js($source['label']) . ' from GitHub now? This overwrites the installed copy.\');">Update now</button>';
                echo '</form></td>';
            } elseif ($row) {
                echo '<td><span class="bhy-badge bhy-badge-success">Up to date</span></td><td></td>';
            } else {
                echo '<td><span class="bhy-badge bhy-badge-neutral">Not checked yet</span></td><td></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}
