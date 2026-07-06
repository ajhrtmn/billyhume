<?php
if (!defined('ABSPATH')) exit;

/**
 * Pure installation mechanics — no redirects, no nonce checks, no
 * knowledge of where a request came from. Callers (OUS_Dashboard's
 * admin-post handlers, OUS_ActivationManager) own all of that; this
 * class just answers "can this plugin be installed, and did it work."
 */
class OUS_Installer {
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

    private static function install_from_bundle($zip_filename) {
        $zip_path = OUS_PATH . 'bundled/' . $zip_filename;
        if (!file_exists($zip_path)) return false;

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

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
