<?php
if (!defined('ABSPATH')) exit;

/**
 * Pure installation mechanics: no redirects or nonce checks. Callers own request handling.
 */
class OUS_Installer {
    // Specific reason for the last failed install(), for callers that want more than the bool.
    private static string $last_error = '';
    public static function last_error(): string { return self::$last_error; }

    // 'bundled_zip': our own plugin shipped as a zip in this folder (local, no network).
    // 'wporg_slug': a third-party dependency installed from WordPress.org via the same core
    // APIs as wp-admin's "Install Now" (plugins_api() + Plugin_Upgrader); needs network.
    public static function install(string $key): bool {
        $info = OUS_Registry::all()[$key] ?? null;
        if (!$info) return false;

        if (!empty($info['bundled_zip'])) return self::install_from_bundle($info['bundled_zip']);
        if (!empty($info['wporg_slug'])) return self::install_from_wporg($info['wporg_slug']);
        return false;
    }

    /**
     * Refuses to install a bundled zip whose version is lower than a copy already on disk,
     * so Install/Reinstall can't silently overwrite newer fixes. First-time installs always proceed.
     */
    private static function install_from_bundle(string $zip_filename): bool {
        self::$last_error = '';
        $zip_path = OUS_PATH . 'bundled/' . $zip_filename;
        if (!file_exists($zip_path)) return false;

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $slug = preg_replace('/\.zip$/', '', $zip_filename);
        $main_file = $slug . '/' . $slug . '.php'; // bootstrap file is always named after its folder
        $installed_path = WP_PLUGIN_DIR . '/' . $main_file;
        if (file_exists($installed_path)) {
            $installed_version = get_plugin_data($installed_path, false, false)['Version'];
            $bundled_version = class_exists('OUS_Registry') ? OUS_Registry::read_zip_plugin_version($zip_path) : null;
            if ($installed_version && $bundled_version && version_compare($bundled_version, $installed_version, '<')) {
                self::$last_error = 'bundle_stale';
                return false;
            }
        }

        // Same credential-request pattern as WP core's installer; resolves to 'direct' on most hosts.
        $creds = request_filesystem_credentials(admin_url('admin.php?page=ous'), '', false, '', null);
        if (!WP_Filesystem($creds)) return false;

        $result = unzip_file($zip_path, WP_PLUGIN_DIR);
        return !is_wp_error($result);
    }

    private static function install_from_wporg(string $slug): bool {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
        if (is_wp_error($api) || empty($api->download_link)) return false;

        $creds = request_filesystem_credentials(admin_url('admin.php?page=ous'), '', false, '', null);
        if (!WP_Filesystem($creds)) return false;

        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $result = $upgrader->install($api->download_link);
        return $result === true;
    }
}
