<?php
if (!defined('ABSPATH')) exit;

/**
 * Pure installation mechanics — no redirects, no nonce checks, no
 * knowledge of where a request came from. Callers (OUS_Dashboard's
 * admin-post handlers, OUS_ActivationManager) own all of that; this
 * class just answers "can this plugin be installed, and did it work."
 */
class OUS_Installer {
    // Set by install_from_bundle() on the one failure mode install()'s
    // plain bool return can't distinguish from any other — callers that
    // want the SPECIFIC reason (OUS_Dashboard::handle_install(), to
    // show a real message instead of a generic "missing") read this
    // right after a failed install() call, same "last-error side
    // channel" shape several WP core APIs already use for the same
    // reason (a bool return that still needs a richer failure reason
    // available to whoever actually cares).
    private static $last_error = '';
    public static function last_error() { return self::$last_error; }

    // Two genuinely different install sources, one shared entry point —
    // every caller uses this without needing to know or care which kind
    // of plugin it's dealing with.
    //
    // 'bundled_zip': one of our own plugins, shipped as an inert zip
    // inside this plugin's own folder — pure local file extraction, no
    // network access needed.
    //
    // 'wporg_slug': a third-party dependency (WooCommerce, etc.) that
    // isn't ours to bundle or redistribute, and that updates on its own
    // schedule independent of us. Installed live from WordPress.org
    // using the exact same core APIs (plugins_api() + Plugin_Upgrader)
    // that wp-admin's own "Install Now" button uses — not a custom
    // reimplementation, and it requires the server to actually reach
    // WordPress.org, unlike the bundled path.
    public static function install($key) {
        $info = OUS_Registry::all()[$key] ?? null;
        if (!$info) return false;

        if (!empty($info['bundled_zip'])) return self::install_from_bundle($info['bundled_zip']);
        if (!empty($info['wporg_slug'])) return self::install_from_wporg($info['wporg_slug']);
        return false;
    }

    /**
     * Guards against installing a stale bundled zip over a newer copy
     * already on disk — without this, "Install"/"Reinstall" would
     * silently overwrite real fixes with an outdated bundle, no error.
     * This is the "prevent" half; OUS_Registry::regenerate_bundled_zip()
     * is the "fix" half (a one-click way to refresh a stale bundle
     * in-admin, rather than needing shell access).
     *
     * Only refuses when there's something to actually compare — a
     * plugin already present on disk with a real version header AND a
     * bundled zip whose version is a real, LOWER semver than what's
     * already there. A first-time install (nothing on disk yet) always
     * proceeds — bundling is the offline-install mechanism, and there's
     * nothing "stale" about the only copy that exists yet.
     */
    private static function install_from_bundle($zip_filename) {
        self::$last_error = '';
        $zip_path = OUS_PATH . 'bundled/' . $zip_filename;
        if (!file_exists($zip_path)) return false;

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $slug = preg_replace('/\.zip$/', '', $zip_filename);
        $main_file = $slug . '/' . $slug . '.php'; // this ecosystem's own convention — a plugin's bootstrap file is always named after its folder
        $installed_path = WP_PLUGIN_DIR . '/' . $main_file;
        if (file_exists($installed_path)) {
            $installed_version = get_plugin_data($installed_path, false, false)['Version'] ?? '';
            $bundled_version = class_exists('OUS_Registry') ? OUS_Registry::read_zip_plugin_version($zip_path) : null;
            if ($installed_version && $bundled_version && version_compare($bundled_version, $installed_version, '<')) {
                self::$last_error = 'bundle_stale';
                return false;
            }
        }

        // Same credential-request pattern WP core's own installer uses —
        // on the vast majority of hosts (anywhere PHP can already write
        // to wp-content, which includes ordinary shared hosting) this
        // resolves to the 'direct' method transparently with no prompt,
        // since that's the same access level normal plugin uploads
        // already rely on.
        $creds = request_filesystem_credentials(admin_url('admin.php?page=own-ur-shit'), '', false, false, null);
        if (!WP_Filesystem($creds)) return false;

        $result = unzip_file($zip_path, WP_PLUGIN_DIR);
        return !is_wp_error($result);
    }

    private static function install_from_wporg($slug) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
        if (is_wp_error($api) || empty($api->download_link)) return false;

        $creds = request_filesystem_credentials(admin_url('admin.php?page=own-ur-shit'), '', false, false, null);
        if (!WP_Filesystem($creds)) return false;

        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $result = $upgrader->install($api->download_link);
        return $result === true;
    }
}
