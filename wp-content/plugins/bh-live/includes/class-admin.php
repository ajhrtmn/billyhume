<?php
if (!defined('ABSPATH')) exit;

/**
 * Registry contribution only — bh-live's actual settings UI lives
 * inside OUS_MediaWizard (own-ur-shit/includes/class-media-wizard.php),
 * not a separate admin screen, per the "one place for setup" call in
 * wondrous-mixing-forest.md: live-engine setup is one more step in the
 * same wizard that already handles storage/CDN, not a second onboarding
 * flow to discover.
 */
class BHL_Admin {
    public static function init() {
        add_filter('ous_registered_plugins', [self::class, 'register']);
    }

    public static function register($plugins) {
        $plugins['bh-live'] = [
            'label'          => 'BH Live',
            'file'           => 'bh-live/bh-live.php',
            'depends_on'     => [],
            'check_class'    => 'BHL_OwncastEngine',
            'description'    => 'Two-way interactive live streaming via a self-hosted Owncast server, configured from the Media & CDN Setup wizard.',
            'dashboard_link' => 'admin.php?page=ous-media-setup',
            'bundled_zip'    => 'bh-live.zip',
        ];
        return $plugins;
    }
}
