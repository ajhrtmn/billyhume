<?php
if (!defined('ABSPATH')) exit;

/**
 * Registry of ecosystem plugins shown on the dashboard (install/activate
 * status, dependencies, admin-menu relocation).
 *
 * WHY: only custom admin pages are relocated, each with its real callback.
 * CPT/taxonomy menus are never touched (show_in_menu fights later menu
 * surgery), and empty-callback reuse under another parent is unreliable.
 *
 * Plugins join without changes here, either via the filter:
 *
 *        add_filter('ous_registered_plugins', function ($plugins) {
 *            $plugins['bh-lyrics'] = [
 *                'label' => 'BH Lyrics',
 *                'file' => 'bh-lyrics/bh-lyrics.php',
 *                'depends_on' => [],
 *                'check_class' => 'BHL_Main',
 *                'description' => 'Synced lyrics for the streaming player.',
 *                // Optional: custom admin pages (not CPTs/taxonomies) to relocate.
 *                'admin_menus' => [
 *                    ['slug' => 'bh-lyrics-settings', 'label' => 'Lyrics Settings',
 *                     'callback' => ['BHL_Admin', 'render'], 'old_parent' => 'edit.php?post_type=bhs_track'],
 *                ],
 *            ];
 *            return $plugins;
 *        });
 *
 *    A third-party dependency uses 'wporg_slug' instead of 'bundled_zip' and
 *    installs from WordPress.org (OUS_Dashboard::install_from_wporg()):
 *
 *        add_filter('ous_registered_plugins', function ($plugins) {
 *            $plugins['woocommerce'] = [
 *                'label' => 'WooCommerce',
 *                'file' => 'woocommerce/woocommerce.php',
 *                'wporg_slug' => 'woocommerce',
 *                'check_class' => 'WooCommerce',
 *                'description' => 'Required for [feature] — payments and commerce, not reimplemented here.',
 *            ];
 *            $plugins['bh-some-plugin']['depends_on'][] = 'woocommerce';
 *            return $plugins;
 *        });
 *
 * or via a ` * Ecosystem: The Self-Hosted Self` line in the main file's header
 * (see discover_unregistered()).
 */
class OUS_Registry {
    private const DEFAULTS = [
        // No 'check_class': this plugin is plain procedural PHP; 'file' is enough.
        'self-hosted-self-admin-skin' => [
            'label' => 'Admin Skin',
            'file' => 'self-hosted-self-admin-skin/self-hosted-self-admin-skin.php',
            'depends_on' => [],
            'description' => 'The wp-admin visual/UX reskin every screen in this ecosystem\'s own admin is themed by — dark/light palette, real accessibility work (focus states, contrast, reduced-motion, larger touch targets), a genuinely mobile-friendly admin menu, and a Cmd/Ctrl+K command palette. Standalone and portable; works with any theme.',
            'dashboard_link' => 'admin.php?page=ous-debug',
            'bundled_zip' => 'self-hosted-self-admin-skin.zip',
        ],
        'bh-crm' => [
            'label' => 'BH CRM',
            'file' => 'bh-crm/bh-crm.php',
            'depends_on' => [],
            'check_class' => 'BHCRM_People',
            'description' => 'A person list built on shared identity — profiles, notes, tags, CSV export. Other plugins can optionally enrich it.',
            'dashboard_link' => 'admin.php?page=bh-crm',
            'bundled_zip' => 'bh-crm.zip',
            // WHY: People's 'parent' is null because BHCRM_Hub already registers
            // 'bh-crm-hub' with this same callback; a second parent would show two
            // rows. merge() must treat an explicit null differently from absent.
            'admin_menus' => [
                ['slug' => 'bh-crm', 'label' => 'People', 'callback' => ['BHCRM_People', 'render'], 'parent' => null, 'capability' => 'bhcore_manage_crm'],
                ['slug' => 'bh-crm-projects', 'label' => 'Project Tracker', 'callback' => ['BHCRM_Projects', 'render_boards'], 'parent' => 'bh-crm-hub', 'capability' => 'bhcore_manage_crm'],
            ],
        ],
        // No 'admin_menus': Results/Live Console stay under the Contest CPT menu.
        'bh-contest' => [
            'label' => 'BH Contest',
            'file' => 'bh-contest/bh-contest.php',
            'depends_on' => [],
            'check_class' => 'BH_Admin',
            'description' => 'Music contest voting, live reveal, and results.',
            'dashboard_link' => 'edit.php?post_type=bh_contest',
            'bundled_zip' => 'bh-contest.zip',
        ],
        // No admin_menus entry.
        'bh-streaming' => [
            'label' => 'BH Streaming',
            'file' => 'bh-streaming/bh-streaming.php',
            'depends_on' => [],
            'check_class' => 'BHS_Player',
            'description' => 'The artist\'s own streaming library and aggregator — shuffle/queue, shared-listening Jam sessions, and an aggregate metrics dashboard.',
            'dashboard_link' => 'edit.php?post_type=bhs_track',
            'bundled_zip' => 'bh-streaming.zip',
        ],
        // WHY: hardcoded as well as self-registered, because an inactive plugin's
        // filter never runs and it could not otherwise be installed/activated here.
        'bh-courses' => [
            'label' => 'BH Courses',
            'file' => 'bh-courses/bh-courses.php',
            'depends_on' => [],
            'check_class' => 'BHC_PostTypes',
            'description' => 'Courses built from ordered, multistep lessons (text, images, quizzes) with progress tracking and optional supporter-tier gating.',
            'dashboard_link' => 'edit.php?post_type=bh_course',
            'bundled_zip' => 'bh-courses.zip',
        ],
        'bh-registry' => [
            'label' => 'BH Registry',
            'file' => 'bh-registry/bh-registry.php',
            'depends_on' => [],
            'check_class' => 'BHR_API',
            'description' => 'The global, decentralized artist-link registry — ActivityPub/RSS feed links, submitted voluntarily and verified by domain ownership.',
            'dashboard_link' => 'admin.php?page=bh-registry-review',
            'bundled_zip' => 'bh-registry.zip',
            'admin_menus' => [
                ['slug' => 'bh-registry-review', 'label' => 'Registry Submissions', 'callback' => ['BHR_Admin', 'render']],
            ],
        ],
        'bh-feedback' => [
            'label' => 'BH Feedback',
            'file' => 'bh-feedback/bh-feedback.php',
            'depends_on' => [],
            'check_class' => 'BHF_PostTypes',
            'description' => 'Paid feedback on a track — a fan pays with wallet credit for a quick-take or detailed written review; any account with the Reviewer job claims it from a shared queue.',
            'dashboard_link' => 'edit.php?post_type=bh_feedback_request',
            'bundled_zip' => 'bh-feedback.zip',
        ],
        'bh-monetization-woo' => [
            'label' => 'BH Monetization',
            'file' => 'bh-monetization-woo/bh-monetization-woo.php',
            'depends_on' => ['woocommerce'],
            'check_class' => 'BHM_Gate',
            'description' => 'Supporter tiers, purchases, tips, and pay-per-play for bh-streaming — backed by WooCommerce, with refund/velocity fraud-pattern flagging.',
            'dashboard_link' => 'admin.php?page=bhm-settings',
            'bundled_zip' => 'bh-monetization-woo.zip',
            'admin_menus' => [
                ['slug' => 'bhm-settings', 'label' => 'Monetization Settings', 'callback' => ['BHM_Admin', 'render']],
            ],
        ],
        // Hardcoded for the same inactive-plugin discoverability reason.
        'bh-live' => [
            'label' => 'BH Live',
            'file' => 'bh-live/bh-live.php',
            'depends_on' => [],
            'check_class' => 'BHL_PostTypes',
            'description' => 'Two-way interactive live streaming — a thin WordPress-side integration behind an engine abstraction, with a choice of a self-hosted Owncast server or Cloudflare Stream Live.',
            'dashboard_link' => 'edit.php?post_type=bhl_stream',
            'bundled_zip' => 'bh-live.zip',
        ],
        'bh-social' => [
            'label' => 'BH Social',
            'file' => 'bh-social/bh-social.php',
            'depends_on' => [],
            'check_class' => 'BHSO_Admin',
            'description' => 'Social/marketing platform integrations — organic cross-posting + stats, plus paid ad-campaign draft-capture.',
            'dashboard_link' => 'admin.php?page=bh-social',
            'bundled_zip' => 'bh-social.zip',
        ],
        'bh-video' => [
            'label' => 'BH Video',
            'file' => 'bh-video/bh-video.php',
            'depends_on' => [],
            'check_class' => 'BHV_PostTypes',
            'description' => 'A standalone video catalog and player, independent of bh-streaming\'s audio catalog.',
            'dashboard_link' => 'edit.php?post_type=bhv_video',
            'bundled_zip' => 'bh-video.zip',
        ],
        'bh-mailpoet' => [
            'label' => 'BH MailPoet',
            'file' => 'bh-mailpoet/bh-mailpoet.php',
            'depends_on' => ['mailpoet'],
            'check_class' => 'BHMP_Sync',
            'description' => 'Syncs bh-crm contacts into a MailPoet subscriber list, so MailPoet\'s own automations/newsletters have current data.',
            'dashboard_link' => 'admin.php?page=ous-debug',
            'bundled_zip' => 'bh-mailpoet.zip',
        ],
        'bh-tickets' => [
            'label' => 'BH Tickets',
            'file' => 'bh-tickets/bh-tickets.php',
            'depends_on' => [],
            'check_class' => 'BHT_Tickets',
            'description' => 'In-house support/issue ticketing built on bh-crm\'s own identity model — no third-party helpdesk dependency.',
            'dashboard_link' => 'admin.php?page=bh-tickets',
            'bundled_zip' => 'bh-tickets.zip',
        ],
        // Also added by bh-monetization-woo (isset()-guarded); listed here so it is
        // installable before that plugin has ever been active.
        'woocommerce' => [
            'label' => 'WooCommerce',
            'file' => 'woocommerce/woocommerce.php',
            'wporg_slug' => 'woocommerce',
            'check_class' => 'WooCommerce',
            'description' => 'Required for BH Monetization — payments and commerce, not reimplemented here.',
        ],
        // Third-party WordPress.org plugin, offered as an on-ramp: it rewrites
        // wp_get_attachment_url(), which all ecosystem media already goes through.
        'advanced-media-offloader' => [
            'label' => 'Advanced Media Offloader',
            'file' => 'advanced-media-offloader/advanced-media-offloader.php',
            'wporg_slug' => 'advanced-media-offloader',
            'depends_on' => [],
            'description' => 'Optional: offload media (course videos/images, streaming audio/artwork) to Cloudflare R2 or another S3-compatible bucket instead of this server\'s own disk — zero-egress-fee delivery via R2 in particular, and zero code changes needed in bh-streaming or bh-courses since it works at the WordPress media-library layer. Needs your own cloud storage account/credentials, entered on that plugin\'s own Settings screen after install (never a wp-config.php constant this ecosystem would need to define on your behalf) — this just wires up the one-click install.',
        ],
    ];

    const HEADER_FIELD = 'Ecosystem';
    const HEADER_VALUE = 'The Self-Hosted Self';

    // Built-ins plus filter contributions, every entry defaulted to the full key set.
    /** @return array<string, array<string, mixed>> */
    public static function all(): array {
        $plugins = apply_filters('ous_registered_plugins', self::DEFAULTS);
        foreach ($plugins as $key => &$info) {
            $info = array_merge([
                'depends_on' => [], 'check_class' => '', 'description' => '',
                'dashboard_link' => '', 'bundled_zip' => '', 'wporg_slug' => '', 'admin_menus' => [],
            ], $info);
        }
        return $plugins;
    }

    // all() minus plugins hidden in production (BHS_Env::hidden_in_production()).
    // Cards only; activation/dependency/menu logic still uses all().
    /** @return array<string, array<string, mixed>> */
    public static function visible_cards(): array {
        $plugins = self::all();
        if (class_exists('BHS_Env') && BHS_Env::hidden_in_production()) {
            unset($plugins['bh-streaming']);
        }
        return $plugins;
    }

    // Ecosystem-header plugins not already in all(), shown as minimal cards.
    /** @return array<string, array<string, mixed>> */
    public static function discover_unregistered(): array {
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $known_files = array_column(self::all(), 'file');
        $discovered = [];

        foreach (array_keys(get_plugins()) as $file) {
            if (in_array($file, $known_files, true)) continue;
            $headers = get_file_data(WP_PLUGIN_DIR . '/' . $file, [self::HEADER_FIELD => self::HEADER_FIELD]);
            if (($headers[self::HEADER_FIELD] ?? '') !== self::HEADER_VALUE) continue;

            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $file, false, false);
            $discovered[$file] = ['label' => $plugin_data['Name'] ?: $file, 'file' => $file];
        }

        return $discovered;
    }

    // 'missing', 'inactive', or 'active'. Uses get_plugins() for file presence,
    // since class_exists() is false for an installed plugin not loaded yet.
    public static function status(string $key): string {
        // isset() guard: a stale key must not raise an undefined-key warning.
        $registry = self::all();
        if (!isset($registry[$key])) return 'missing';
        $info = $registry[$key];
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $all = get_plugins();
        if (!isset($all[$info['file']])) return 'missing';
        return is_plugin_active($info['file']) ? 'active' : 'inactive';
    }

    public static function version(string $key): string {
        $registry = self::all();
        if (!isset($registry[$key])) return '';
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $all = get_plugins();
        $file = $registry[$key]['file'];
        return $all[$file]['Version'] ?? '';
    }

    // status() for a plugin found only by discover_unregistered(), keyed by file.
    public static function status_by_file(string $file): string {
        return is_plugin_active($file) ? 'active' : 'inactive';
    }

    /**
     * Compares each bundled/*.zip plugin header against the installed version,
     * so a bundle left stale after an update is a visible warning instead of
     * silently reinstalling old code.
     */
    /** @return array<int, array<string, mixed>> */
    public static function bundled_zip_report(): array {
        $bundled_dir = OUS_PATH . 'bundled/';
        $rows = [];
        if (!is_dir($bundled_dir)) return $rows;

        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $installed = get_plugins();

        $registry = self::all();
        // Map bundled_zip filename -> [key, file] so we know which
        // installed plugin (if any) a given bundle corresponds to.
        $by_zip = [];
        foreach ($registry as $key => $info) {
            if (!empty($info['bundled_zip'])) $by_zip[$info['bundled_zip']] = ['key' => $key, 'file' => $info['file'] ?? ''];
        }

        foreach (glob($bundled_dir . '*.zip') ?: [] as $zip_path) {
            $zip_filename = basename($zip_path);
            $bundled_version = self::read_zip_plugin_version($zip_path);

            $match = $by_zip[$zip_filename] ?? null;
            $installed_version = null;
            if ($match && isset($installed[$match['file']])) {
                $installed_version = $installed[$match['file']]['Version'] ?? null;
            }

            $rows[] = [
                'zip' => $zip_filename,
                'key' => $match['key'] ?? null, // registry key, for the regenerate button below — null when this zip has no matching registry entry to rebuild from
                'label' => $match['key'] ?? $zip_filename,
                'bundled_version' => $bundled_version,
                'installed_version' => $installed_version,
                // Missing either version is an incomplete comparison, not staleness.
                'stale' => ($bundled_version && $installed_version && $bundled_version !== $installed_version),
            ];
        }
        return $rows;
    }

    // Reads the Version: header from the zip's "<folder>/<folder>.php" entry
    // without extracting. Only runs on demand from the debug page.
    public static function read_zip_plugin_version(string $zip_path): ?string {
        if (!class_exists('ZipArchive')) return null;
        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) return null;

        $version = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            // Only the bootstrap file named after its folder, to avoid matching
            // header-shaped comments in other files.
            if (preg_match('#^([^/]+)/\1\.php$#', $name)) {
                $contents = $zip->getFromIndex($i);
                if ($contents && preg_match('/^[ \t\/*#@]*Version:\s*(.+)$/mi', $contents, $m)) {
                    $version = trim($m[1]);
                }
                break;
            }
        }
        $zip->close();
        return $version;
    }

    /**
     * Rebuilds bundled/<zip> from the plugin's live source with ZipArchive.
     * Writes to a temp file and renames only on success, so a failed write
     * never leaves a corrupt bundle.
     */
    /** @return true|\WP_Error */
    public static function regenerate_bundled_zip(string $key) {
        if (!class_exists('ZipArchive')) return new WP_Error('no_ziparchive', 'ZipArchive isn\'t available on this server.');

        $info = self::all()[$key] ?? null;
        if (!$info || empty($info['bundled_zip']) || empty($info['file'])) {
            return new WP_Error('unknown_plugin', 'Not a bundled plugin.');
        }

        $slug = dirname($info['file']); // e.g. 'bh-courses/bh-courses.php' -> 'bh-courses'
        $source_dir = WP_PLUGIN_DIR . '/' . $slug;
        if (!is_dir($source_dir)) return new WP_Error('source_missing', "$slug isn't installed here — nothing to bundle from.");

        $final_path = OUS_PATH . 'bundled/' . $info['bundled_zip'];
        $tmp_path = $final_path . '.tmp-' . wp_generate_password(8, false);

        $zip = new ZipArchive();
        if ($zip->open($tmp_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_Error('open_failed', 'Could not open a temp file for writing in bundled/.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = $slug . '/' . substr($item->getPathname(), strlen($source_dir) + 1);
            // Skip OS/VCS clutter and dev-only trees (node_modules/, vendor/) that
            // exist only in a working checkout, never on the FTP-deployed site.
            if (basename($item) === '.DS_Store') continue;
            foreach (['/.git/', '/node_modules/', '/vendor/'] as $d) {
                if (strpos($relative, $d) !== false) continue 2;
            }
            if ($item->isDir()) {
                $zip->addEmptyDir($relative);
            } else {
                $zip->addFile($item->getPathname(), $relative);
            }
        }
        $zip->close();

        // Verify the rebuilt zip's header before it replaces the old bundle.
        $rebuilt_version = self::read_zip_plugin_version($tmp_path);
        $live_data = get_file_data($source_dir . '/' . basename($info['file']), ['Version' => 'Version']);
        if (!$rebuilt_version || $rebuilt_version !== ($live_data['Version'] ?? null)) {
            @unlink($tmp_path);
            return new WP_Error('verify_failed', 'Rebuilt zip failed its own version check — nothing was replaced.');
        }

        if (!@rename($tmp_path, $final_path)) {
            @unlink($tmp_path);
            return new WP_Error('rename_failed', 'Could not replace the old bundled zip (a file-permissions issue on this host).');
        }
        return true;
    }

    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register_debug_section($tools): array {
        $tools['bundled-zips'] = [
            'label' => 'Bundled Zip Freshness',
            'render' => [self::class, 'render_debug_section'],
            'handle' => [self::class, 'handle_regenerate'],
            'reset' => null,
            // The report is read-only; the regenerate action is gated like any other.
            'safe_in_production' => true,
            'group' => OUS_Debug::GROUP_MONITORING,
        ];
        return $tools;
    }

    /** Debug Tools 'handle' callback; returns the notice message string. */
    /** @param array<string, mixed> $post */
    public static function handle_regenerate(string $action, $post): string {
        if ($action !== 'regenerate') return 'Unknown action.';
        $key = sanitize_key($post['bundle_key'] ?? '');
        $result = self::regenerate_bundled_zip($key);
        return is_wp_error($result)
            ? 'Could not regenerate: ' . $result->get_error_message()
            : "Regenerated the bundled zip for \"$key\" from the current source.";
    }

    public static function render_debug_section(): void {
        $rows = self::bundled_zip_report();
        if (!$rows) {
            echo '<p class="description">No bundled/*.zip files found (or ZipArchive isn\'t available on this server).</p>';
            return;
        }

        echo '<p class="description">Each of this plugin\'s own peer plugins gets reinstalled from a copy bundled inside <code>the-self-hosted-self/bundled/</code>, not from whatever is separately deployed elsewhere — this table exists specifically to catch a bundled copy that got left behind after a real update, before it causes a confusing "I updated it but nothing changed" report.</p>';

        // Same "hide not-installed rows" toggle as the GitHub Updates panel.
        $show_absent = class_exists('OUS_GithubUpdates') && OUS_GithubUpdates::show_absent_rows();
        $hidden = 0;
        if (!$show_absent) {
            foreach ($rows as $r) { if (empty($r['installed_version'])) $hidden++; }
        }
        if (!$show_absent && $hidden) {
            echo '<p class="description">' . (int) $hidden . ' plugin' . ($hidden === 1 ? '' : 's') . ' not installed here — hidden (toggle in <a href="#ous-section-ous-github-updates">GitHub Updates</a>).</p>';
        }

        echo '<div class="bhy-table-wrap"><table class="widefat striped"><thead><tr>'
           . '<th>Plugin</th><th>Bundled zip version</th><th>Currently installed version</th><th>Status</th>'
           . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            if (!$show_absent && empty($row['installed_version'])) continue;
            if ($row['stale']) {
                // flex-wrap gives the button a break point beside the nowrap badge,
                // so a narrow screen does not force the whole table wider.
                $status = '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;white-space:normal;">';
                $status .= '<span class="bhy-badge bhy-badge-danger">STALE</span>';
                // Only when the row maps to a registry key with a source directory.
                if ($row['key']) {
                    ob_start();
                    OUS_Debug::button('bundled-zips', 'regenerate', 'Regenerate bundled zip', '<input type="hidden" name="bundle_key" value="' . esc_attr($row['key']) . '">', '', false);
                    $status .= ob_get_clean();
                }
                $status .= '</div>';
            } elseif (!$row['installed_version']) {
                $status = '<span class="bhy-badge bhy-badge-neutral">not currently installed — nothing to compare</span>';
            } elseif (!$row['bundled_version']) {
                $status = '<span class="bhy-badge bhy-badge-neutral">could not read bundled zip header</span>';
            } else {
                $status = '<span class="bhy-badge bhy-badge-success">up to date</span>';
            }
            echo '<tr><td>' . esc_html($row['label']) . ' <span class="description">(' . esc_html($row['zip']) . ')</span></td>'
               . '<td>' . esc_html($row['bundled_version'] ?? '—') . '</td>'
               . '<td>' . esc_html($row['installed_version'] ?? '—') . '</td>'
               . '<td>' . $status . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
