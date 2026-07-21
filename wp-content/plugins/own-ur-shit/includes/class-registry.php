<?php
if (!defined('ABSPATH')) exit;

/**
 * As of the v3 core merge, this only tracks the plugins that are still
 * genuinely separate: bh-contest and bh-streaming. Identity and style
 * used to be listed here too, but they're now part of this same plugin
 * — there's nothing left to install or activate for them, so tracking
 * them here would be meaningless.
 *
 * Their 'depends_on' is empty for the same reason: their one real
 * dependency is this plugin itself, and if you're looking at this
 * dashboard at all, this plugin is — by definition — already active.
 *
 * Menu consolidation deliberately does NOT touch CPT-based top-level
 * menus (Contests, Tracks) or their own CPT/taxonomy submenus
 * (Submissions, Releases, Playlists, Feed Sources, Genres) — a real,
 * demonstrated WordPress-internals fragility (a CPT's own show_in_menu
 * registration fighting against menu surgery performed after the fact)
 * lives there, and it's not worth the risk for what's already a
 * completely normal WordPress pattern anyway — WooCommerce keeps
 * "Products" as its own top-level menu right next to "WooCommerce"
 * itself, and nobody considers that "not cohesive." Only the safer,
 * genuinely custom admin pages (not CPT list-tables) get relocated,
 * each with its real callback listed explicitly — the technique that
 * relies on an empty callback "reusing" a page registered under a
 * DIFFERENT parent does not reliably work and is not used here.
 *
 * Two ways a future ecosystem plugin gets onto this dashboard WITHOUT
 * ever requiring a change to this plugin's own code:
 *
 * 1. Rich self-registration via a filter. In the new plugin's own
 *    bootstrap file:
 *
 *        add_filter('ous_registered_plugins', function ($plugins) {
 *            $plugins['bh-lyrics'] = [
 *                'label' => 'BH Lyrics',
 *                'file' => 'bh-lyrics/bh-lyrics.php',
 *                'depends_on' => [],
 *                'check_class' => 'BHL_Main',
 *                'description' => 'Synced lyrics for the streaming player.',
 *                // Optional: custom admin pages (NOT post types or
 *                // taxonomies — see the caution above) this plugin
 *                // owns, each with its real callback, so they can be
 *                // relocated as direct submenus under Own Ur Shit.
 *                'admin_menus' => [
 *                    ['slug' => 'bh-lyrics-settings', 'label' => 'Lyrics Settings',
 *                     'callback' => ['BHL_Admin', 'render'], 'old_parent' => 'edit.php?post_type=bhs_track'],
 *                ],
 *            ];
 *            return $plugins;
 *        });
 *
 *    Completely harmless to add even if this hub plugin is never
 *    installed at all — an add_filter() call on a filter nobody applies
 *    just sits there unused. That's what keeps this relationship
 *    one-directional even as new plugins opt into it.
 *
 *    The exact same filter also covers a THIRD-PARTY dependency — a
 *    plugin we don't author and shouldn't bundle or redistribute, like
 *    WooCommerce for anything money-related. Use 'wporg_slug' instead of
 *    'bundled_zip', and it installs live from WordPress.org via the same
 *    core APIs wp-admin's own "Install Now" button uses (see
 *    OUS_Dashboard::install_from_wporg()), rather than a local zip
 *    extraction:
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
 * 2. Zero-code auto-discovery via a custom plugin header. Any plugin
 *    whose main file's docblock includes a line like:
 *
 *        * Ecosystem: Own Ur Shit
 *
 *    shows up automatically in a lightweight, minimal card even if it
 *    never calls the filter above — see discover_unregistered() below.
 */
class OUS_Registry {
    private const DEFAULTS = [
        'bh-crm' => [
            'label' => 'BH CRM',
            'file' => 'bh-crm/bh-crm.php',
            'depends_on' => [],
            'check_class' => 'BHCRM_People',
            'description' => 'A person list built on shared identity — profiles, notes, tags, CSV export. Other plugins can optionally enrich it.',
            'dashboard_link' => 'admin.php?page=bh-crm',
            'bundled_zip' => 'bh-crm.zip',
            // People is a plain custom admin page, not a CPT/taxonomy
            // list-table — unlike bh-contest's Contests or bh-streaming's
            // Tracks, there's no "WooCommerce Products" style reason to
            // keep it as its own top-level menu, so it gets relocated as
            // a direct submenu here instead. No 'old_parent' — BHCRM_People
            // never registers a top-level page of its own to remove.
            //
            // People's 'parent' is null rather than 'bh-crm-hub' because
            // BHCRM_Hub::add_menu() (bh-crm/includes/class-hub.php) already
            // registers 'bh-crm-hub' as a top-level page with this exact
            // same callback (['BHCRM_People', 'render']) — giving this
            // entry a 'bh-crm-hub' parent too would produce two
            // independently-visible sidebar rows rendering the same page.
            // Its slug ('bh-crm') stays unchanged so every existing
            // admin.php?page=bh-crm&... deep link keeps working. null here
            // requires class-menu-merge.php's merge() to distinguish "key
            // absent" from "key explicitly null", since a bare '??'
            // default would silently ignore this and keep it visible.
            // Project Tracker (BHCRM_Projects::render_boards()) is
            // genuinely distinct content, so it keeps its normal
            // 'bh-crm-hub' parent and stays a real, visible submenu.
            'admin_menus' => [
                ['slug' => 'bh-crm', 'label' => 'People', 'callback' => ['BHCRM_People', 'render'], 'parent' => null, 'capability' => 'bhcore_manage_crm'],
                ['slug' => 'bh-crm-projects', 'label' => 'Project Tracker', 'callback' => ['BHCRM_Projects', 'render_boards'], 'parent' => 'bh-crm-hub', 'capability' => 'bhcore_manage_crm'],
            ],
        ],
        // bh-contest deliberately has NO 'admin_menus' entry: Results and
        // Live Console are contest-specific screens (live vote tallies,
        // reveal controls), not generic ecosystem-hub functionality, so
        // they stay right where BH_Admin/BH_Console already register
        // them — nested under the Contest CPT's own top-level menu —
        // rather than being pulled out into Own Ur Shit's menu the way
        // bh-crm's People page is. This was tried the other way (both
        // relocated here) and reverted on purpose.
        'bh-contest' => [
            'label' => 'BH Contest',
            'file' => 'bh-contest/bh-contest.php',
            'depends_on' => [],
            'check_class' => 'BH_Admin',
            'description' => 'Music contest voting, live reveal, and results.',
            'dashboard_link' => 'edit.php?post_type=bh_contest',
            'bundled_zip' => 'bh-contest.zip',
        ],
        // Same reasoning as bh-contest above — no admin_menus entry.
        // App Icon generation was previously relocated here too but
        // removed entirely (see class-pwa.php) as not worth the admin
        // surface right now; nothing left to relocate.
        'bh-streaming' => [
            'label' => 'BH Streaming',
            'file' => 'bh-streaming/bh-streaming.php',
            'depends_on' => [],
            'check_class' => 'BHS_Player',
            'description' => 'The artist\'s own streaming library and aggregator — shuffle/queue, shared-listening Jam sessions, and an aggregate metrics dashboard.',
            'dashboard_link' => 'edit.php?post_type=bhs_track',
            'bundled_zip' => 'bh-streaming.zip',
        ],
        // These three used to rely ONLY on their own plugin file
        // self-registering via the 'ous_registered_plugins' filter (see
        // each plugin's own class-admin.php / bh-courses.php) — which
        // works fine for an ALREADY-active plugin, but is a real
        // chicken-and-egg gap for an install that's still sitting
        // inactive (or not yet uploaded at all): an inactive plugin's
        // PHP never runs, so its self-registration filter never fires,
        // so it can never show up here to BE installed/activated in the
        // first place. bh-crm/bh-contest/bh-streaming above never hit
        // this because they were always hardcoded here too — these three
        // are now hardcoded the same way, closing the gap. Each plugin's
        // own self-registration filter is left in place (harmless once
        // active: it just re-sets the same key to equivalent data), so
        // nothing needs to change in the plugins themselves.
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
        // WooCommerce itself — bh-monetization-woo's own bootstrap also
        // adds this (guarded by isset() so the two never fight), kept
        // here too so WooCommerce shows up as installable even before
        // bh-monetization-woo itself has ever been active.
        'woocommerce' => [
            'label' => 'WooCommerce',
            'file' => 'woocommerce/woocommerce.php',
            'wporg_slug' => 'woocommerce',
            'check_class' => 'WooCommerce',
            'description' => 'Required for BH Monetization — payments and commerce, not reimplemented here.',
        ],
        // A third-party WordPress.org plugin, not ours to author or
        // bundle — same 'wporg_slug' shape the docblock above documents
        // for WooCommerce, installed live from WordPress.org rather than
        // a local zip extraction. Exists on this dashboard purely as an
        // easy on-ramp: video (and any other media) in bh-streaming or
        // bh-courses is regular WordPress media-library content under
        // the hood, so a transparent offload-to-cloud plugin needs zero
        // code changes in either of those plugins to work — it rewrites
        // wp_get_attachment_url() and friends, which is the one API
        // surface every plugin in this ecosystem already goes through
        // for media. No check_class since this plugin's real class
        // names aren't part of this ecosystem's own contract — the
        // WordPress.org file path is enough for install/activate status.
        'advanced-media-offloader' => [
            'label' => 'Advanced Media Offloader',
            'file' => 'advanced-media-offloader/advanced-media-offloader.php',
            'wporg_slug' => 'advanced-media-offloader',
            'depends_on' => [],
            'description' => 'Optional: offload media (course videos/images, streaming audio/artwork) to Cloudflare R2 or another S3-compatible bucket instead of this server\'s own disk — zero-egress-fee delivery via R2 in particular, and zero code changes needed in bh-streaming or bh-courses since it works at the WordPress media-library layer. Needs your own cloud storage account/credentials, entered on that plugin\'s own Settings screen after install (never a wp-config.php constant this ecosystem would need to define on your behalf) — this just wires up the one-click install.',
        ],
    ];

    const HEADER_FIELD = 'Ecosystem';
    const HEADER_VALUE = 'Own Ur Shit';

    // The full, merged registry — built-ins plus anything a filter
    // contributed. Every entry gets the same set of keys filled in
    // (defaulted to empty where a filter didn't provide them), so
    // rendering code never has to guard against a partial entry from a
    // plugin that only supplied the essentials.
    public static function all() {
        $plugins = apply_filters('ous_registered_plugins', self::DEFAULTS);
        foreach ($plugins as $key => &$info) {
            $info = array_merge([
                'depends_on' => [], 'check_class' => '', 'description' => '',
                'dashboard_link' => '', 'bundled_zip' => '', 'wporg_slug' => '', 'admin_menus' => [],
            ], $info);
        }
        return $plugins;
    }

    // Same list as all(), minus any plugin that wants to stay off the
    // dashboard specifically in production while it's still being
    // built out — bh-streaming today, via BHS_Env::hidden_in_production()
    // (only consulted if that class happens to be loaded; a plugin that
    // doesn't opt into this convention is simply always shown, same as
    // before). Deliberately only used for the dashboard CARD listing —
    // activation/dependency/menu-merge logic still uses all() so
    // installing or activating a hidden-in-prod plugin from another
    // route (direct URL, WP-CLI) keeps working exactly as it always has.
    public static function visible_cards() {
        $plugins = self::all();
        if (class_exists('BHS_Env') && BHS_Env::hidden_in_production()) {
            unset($plugins['bh-streaming']);
        }
        return $plugins;
    }

    // Plugins declaring the Ecosystem header (see class docblock) that
    // AREN'T already in all() via the filter or built-in defaults —
    // shown as a minimal, lower-detail card so a genuinely
    // zero-configuration future plugin still gets noticed rather than
    // silently invisible.
    public static function discover_unregistered() {
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

    // 'missing' (file not found at all), 'inactive' (file present, not
    // active), or 'active'. Checking the plugin FILE'S presence via
    // WordPress's own get_plugins() rather than just class_exists() —
    // class_exists() would report 'missing' for a plugin that's
    // installed but simply hasn't loaded yet this request for unrelated
    // reasons, which isn't the same thing as not being installed.
    public static function status($key) {
        // Guard the lookup with isset() instead of indexing directly —
        // an arbitrary/stale key (e.g. a leftover depends_on entry after
        // a registry filter change) should fail gracefully, not throw an
        // undefined-array-key warning on PHP 8.
        $registry = self::all();
        if (!isset($registry[$key])) return 'missing';
        $info = $registry[$key];
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $all = get_plugins();
        if (!isset($all[$info['file']])) return 'missing';
        return is_plugin_active($info['file']) ? 'active' : 'inactive';
    }

    public static function version($key) {
        $registry = self::all();
        if (!isset($registry[$key])) return '';
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $all = get_plugins();
        $file = $registry[$key]['file'];
        return $all[$file]['Version'] ?? '';
    }

    // Same idea as status() above, but for a plugin found only via
    // discover_unregistered() — identified by file path directly since
    // it has no registry key of its own.
    public static function status_by_file($file) {
        return is_plugin_active($file) ? 'active' : 'inactive';
    }

    /**
     * Bundled-zip staleness check. OUS_Installer::install_from_bundle()
     * extracts from own-ur-shit/bundled/*.zip, a copy shipped INSIDE this
     * plugin — genuinely separate from whatever's on disk for that peer
     * plugin right now. A build/deploy pass that updates a peer plugin's
     * files but forgets to regenerate its bundled/ copy would leave the
     * dashboard's "Install"/reinstall path silently serving old code
     * indefinitely, with no error anywhere. This reads each bundled zip's
     * plugin header directly (ZipArchive is a PHP core extension, so it
     * just opens the entry and regexes the header block the way
     * WordPress's own get_file_data() does — no extraction needed) and
     * compares it against the currently-installed version of that same
     * plugin, so a stale bundle is a visible warning instead of a silent
     * trap.
     */
    public static function bundled_zip_report() {
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
                // Only a real finding when we could read BOTH versions
                // and they genuinely differ — missing either side (not
                // installed yet, or an unreadable zip) isn't staleness,
                // just an incomplete comparison, so it's reported
                // separately rather than flagged as a mismatch.
                'stale' => ($bundled_version && $installed_version && $bundled_version !== $installed_version),
            ];
        }
        return $rows;
    }

    // Reads the plugin header (just the Version: line, same field
    // get_file_data() looks for) out of the first .php file at the
    // root of a zip's single top-level directory, without extracting
    // the whole archive to disk — this only runs on-demand from the
    // debug page, not on every request, so the small per-call overhead
    // of opening the zip is a non-issue.
    public static function read_zip_plugin_version($zip_path) {
        if (!class_exists('ZipArchive')) return null;
        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) return null;

        $version = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            // Only look at a top-level "<folder>/<same-name>.php" entry
            // (e.g. "bh-courses/bh-courses.php") — the plugin's own
            // bootstrap file is always named after its folder in this
            // ecosystem's convention, and reading only that one file
            // avoids false-matching a header-shaped comment in some
            // unrelated included class file.
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
     * The actual fix for the failure mode bundled_zip_report() only
     * detects, never corrects: this is the one-click, in-admin way to
     * rebuild a stale bundle, plus OUS_Installer::install_from_bundle()'s
     * own pre-flight staleness guard as the second half of "prevent," not
     * just "detect."
     *
     * Rebuilds `bundled/<zip>` directly from the plugin's own live
     * source directory using ZipArchive (PHP core, no shell-out) — same
     * top-level-folder-per-plugin structure every existing bundled zip
     * already uses. Writes to a temp file first and only renames over
     * the real target on full success, so a failed write never leaves
     * orphaned temp files or a corrupt bundle behind.
     */
    public static function regenerate_bundled_zip($key) {
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
            // Same exclusions the manual rebuild used this same pass —
            // OS/VCS clutter, never anything the plugin actually ships.
            if (basename($item) === '.DS_Store' || strpos($relative, '/.git/') !== false) continue;
            if ($item->isDir()) {
                $zip->addEmptyDir($relative);
            } else {
                $zip->addFile($item->getPathname(), $relative);
            }
        }
        $zip->close();

        // Confirm the rebuilt zip's own header actually matches what's
        // on disk RIGHT NOW before it replaces the old one — belt and
        // suspenders against a partial/corrupt write silently becoming
        // the new "source of truth" bundle.
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

    public static function register_debug_section($tools) {
        $tools['bundled-zips'] = [
            'label' => 'Bundled Zip Freshness',
            'render' => [self::class, 'render_debug_section'],
            'handle' => [self::class, 'handle_regenerate'],
            'reset' => null,
            // Read-only by default (the report itself changes nothing)
            // — the one write action (regenerating a bundle FROM the
            // live source already on this exact install) is gated the
            // same way any other Debug Tools action is, not exempted
            // just because the report above it is safe everywhere.
            'safe_in_production' => true,
            'group' => OUS_Debug::GROUP_MONITORING,
        ];
        return $tools;
    }

    /** Wired as this section's 'handle' callback — receives ($action, $_POST) same as every other registered Debug Tools handler (OUS_Debug::button()'s own form contract); returns a plain message string, the shared redirect-with-notice dispatcher's own contract. */
    public static function handle_regenerate($action, $post) {
        if ($action !== 'regenerate') return 'Unknown action.';
        $key = sanitize_key($post['bundle_key'] ?? '');
        $result = self::regenerate_bundled_zip($key);
        return is_wp_error($result)
            ? 'Could not regenerate: ' . $result->get_error_message()
            : "Regenerated the bundled zip for \"$key\" from the current source.";
    }

    public static function render_debug_section() {
        $rows = self::bundled_zip_report();
        if (!$rows) {
            echo '<p class="description">No bundled/*.zip files found (or ZipArchive isn\'t available on this server).</p>';
            return;
        }

        echo '<p class="description">Each of this plugin\'s own peer plugins gets reinstalled from a copy bundled inside <code>own-ur-shit/bundled/</code>, not from whatever is separately deployed elsewhere — this table exists specifically to catch a bundled copy that got left behind after a real update, before it causes a confusing "I updated it but nothing changed" report.</p>';

        echo '<div class="bhy-table-wrap"><table class="widefat striped"><thead><tr>'
           . '<th>Plugin</th><th>Bundled zip version</th><th>Currently installed version</th><th>Status</th>'
           . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            if ($row['stale']) {
                $status = '<span style="color:#fff;background:#d63638;padding:2px 8px;border-radius:3px;font-size:11px;">STALE</span>';
                // One-click fix, right next to the finding. Only rendered
                // when the row actually matched a registry key with a
                // real source directory to rebuild from.
                if ($row['key']) {
                    ob_start();
                    OUS_Debug::button('bundled-zips', 'regenerate', 'Regenerate bundled zip', '<input type="hidden" name="bundle_key" value="' . esc_attr($row['key']) . '">', '', false);
                    $status .= ob_get_clean();
                }
            } elseif (!$row['installed_version']) {
                $status = '<span style="color:#666;">not currently installed — nothing to compare</span>';
            } elseif (!$row['bundled_version']) {
                $status = '<span style="color:#666;">could not read bundled zip header</span>';
            } else {
                $status = '<span style="color:#fff;background:#00a32a;padding:2px 8px;border-radius:3px;font-size:11px;">up to date</span>';
            }
            echo '<tr><td>' . esc_html($row['label']) . ' <span class="description">(' . esc_html($row['zip']) . ')</span></td>'
               . '<td>' . esc_html($row['bundled_version'] ?? '—') . '</td>'
               . '<td>' . esc_html($row['installed_version'] ?? '—') . '</td>'
               . '<td>' . $status . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
