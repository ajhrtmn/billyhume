<?php
/**
 * Plugin Name: BH CRM
 * Description: A person list built on shared identity — profile data, freeform notes, tags, and CSV export. Any other plugin can contribute an "activity" section to a person's detail view via a filter, entirely optionally — this plugin works completely on its own with zero other feature plugins installed.
 * Version:     1.0.0
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

define('BHCRM_VER',  '1.0.0');
define('BHCRM_PATH', plugin_dir_path(__FILE__));
define('BHCRM_URL',  plugin_dir_url(__FILE__));

foreach (['people', 'notes', 'tags', 'export'] as $f) {
    require_once BHCRM_PATH . "includes/class-$f.php";
}

/**
 * Depends only on the core plugin (shared identity) — genuinely nothing
 * else. This is deliberately a PEER to bh-contest and bh-streaming, not
 * something either of them requires or that requires either of them:
 * each stands alone, and each can optionally enrich the other's view of
 * a person through a filter (see class-people.php's docblock for the
 * activity-contribution contract). Delete bh-contest entirely and this
 * plugin's person list still works — it just has one less voice
 * contributing to each person's activity section.
 */
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH CRM</strong> requires the <strong>Own Ur Shit</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    // No add_menu_page() call here — People is a plain custom admin page
    // (not a CPT list-table), so per the ecosystem's own menu convention
    // it's relocated as a direct submenu under Own Ur Shit instead of
    // getting its own top-level entry (see the 'admin_menus' entry for
    // bh-crm in the core's class-registry.php, applied by OUS_MenuMerge).
    add_action('admin_post_bhcrm_save_note', ['BHCRM_Notes', 'handle_save']);
    add_action('admin_post_bhcrm_save_tags', ['BHCRM_Tags', 'handle_save']);
    add_action('admin_post_bhcrm_export',    ['BHCRM_Export', 'handle']);
});
