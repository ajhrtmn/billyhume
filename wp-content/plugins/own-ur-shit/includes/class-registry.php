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
 *                     'callback' => ['BHL_Admin', 'render'], 'old_parent' => 'edit.php?post_type=bh_track'],
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
            // keep it as its own top-level menu, so (unlike those two) it
            // gets relocated as a direct submenu here instead. No
            // 'old_parent' — BHCRM_People never registers a top-level
            // page of its own for this to remove.
            'admin_menus' => [
                ['slug' => 'bh-crm', 'label' => 'People/CRM', 'callback' => ['BHCRM_People', 'render']],
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
            'description' => 'The artist\'s own streaming library and aggregator.',
            'dashboard_link' => 'edit.php?post_type=bh_track',
            'bundled_zip' => 'bh-streaming.zip',
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
        $info = self::all()[$key];
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $all = get_plugins();
        if (!isset($all[$info['file']])) return 'missing';
        return is_plugin_active($info['file']) ? 'active' : 'inactive';
    }

    public static function version($key) {
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $all = get_plugins();
        $file = self::all()[$key]['file'];
        return $all[$file]['Version'] ?? '';
    }

    // Same idea as status() above, but for a plugin found only via
    // discover_unregistered() — identified by file path directly since
    // it has no registry key of its own.
    public static function status_by_file($file) {
        return is_plugin_active($file) ? 'active' : 'inactive';
    }
}
