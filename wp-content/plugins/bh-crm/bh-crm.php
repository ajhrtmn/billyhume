<?php
/**
 * Plugin Name: BH CRM
 * Description: A person list built on shared identity — profile data, freeform notes, tags, and CSV export. Any other plugin can contribute an "activity" section to a person's detail view via a filter, entirely optionally — this plugin works completely on its own with zero other feature plugins installed.
 * Version:     1.1.1
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

define('BHCRM_VER',  '1.1.1');
define('BHCRM_PATH', plugin_dir_path(__FILE__));
define('BHCRM_URL',  plugin_dir_url(__FILE__));

// 1.1.1 — class-notes.php's handle_save() now also queues a toast
// (OUS_Toast::queue(), own-ur-shit 3.4.18+) right before its existing
// admin-post redirect, so "Notes saved." shows as a real toast in
// addition to the plain-text $_GET['bhcrm_msg'] notice it already
// carried — additive only. See class-notes.php's own comment at the
// call site; degrades to a no-op on an older own-ur-shit core.

// 1.1.0 — this plugin is now also a genuine BH_Event consumer AND
// emitter (own-ur-shit's event-tracking layer, class-event.php): added
// class-event-activity.php, which contributes an "Event Tracking"
// section to bh_crm_activity_summary (reading {$wpdb->prefix}
// bhcore_events directly, bounded/prepared) alongside the pre-existing
// bh-contest/bh-streaming/bh-courses contributions; class-notes.php and
// class-tags.php now each emit a 'bhcrm/note_saved' / 'bhcrm/tags_saved'
// event at the tail of their own handle_save() (additive only — no
// change to either save path's actual behavior). Standing caveat:
// reasoning/brace-balance-checked only, no live PHP+MySQL execution in
// this pass — not runtime-verified.
foreach (['people', 'notes', 'tags', 'export', 'event-activity'] as $f) {
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

    // BH_Event registration + this plugin's own contribution to
    // bh_crm_activity_summary — both gated on the core event-tracking
    // class actually being present, same "harmless no-op otherwise"
    // convention every other BH_Event consumer in this ecosystem
    // follows (see bh-courses/includes/class-progress.php::init()).
    add_action('init', function () {
        if (class_exists('BH_Event')) {
            BH_Event::register_event_type('bhcrm/note_saved', ['user_id' => 'int']);
            BH_Event::register_event_type('bhcrm/tags_saved', ['user_id' => 'int', 'tags' => 'string[]']);
        }
        if (class_exists('BHCRM_Event_Activity')) {
            BHCRM_Event_Activity::init();
        }
    });
});
