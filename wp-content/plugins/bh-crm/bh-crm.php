<?php
/**
 * Plugin Name: BH CRM
 * Description: A person list built on shared identity — profile data, freeform notes, tags, and CSV export. Any other plugin can contribute an "activity" section to a person's detail view via a filter, entirely optionally — this plugin works completely on its own with zero other feature plugins installed.
 * Version:     2.4.25
 * Requires PHP: 8.2
 * Requires Plugins: the-self-hosted-self
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).

define('BHCRM_VER',  '2.4.25');

define('BHCRM_PATH', plugin_dir_path(__FILE__));
define('BHCRM_URL',  plugin_dir_url(__FILE__));

foreach (['tables', 'people', 'notes', 'tags', 'segments', 'export', 'event-activity', 'links', 'projects', 'subtasks', 'card-log', 'debug', 'hub', 'test-suite'] as $f) {
    require_once BHCRM_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BHCRM_Links', 'activate']);
register_activation_hook(__FILE__, ['BHCRM_Projects', 'activate']);
register_activation_hook(__FILE__, ['BHCRM_Notes', 'activate']);
register_activation_hook(__FILE__, ['BHCRM_Segments', 'activate']);
register_activation_hook(__FILE__, ['BHCRM_CardLog', 'activate']);

/** Depends only on core; other plugins enrich the person view via filters (see class-people.php). */
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH CRM</strong> requires <strong>The Self-Hosted Self</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    // WHY: registered in plugins_loaded so this parent menu exists before OUS_MenuMerge relocates submenus under it (priority 999).
    BHCRM_Hub::init();

    add_action('admin_post_bhcrm_save_note', ['BHCRM_Notes', 'handle_save']);
    add_action('admin_post_bhcrm_save_tags', ['BHCRM_Tags', 'handle_save']);
    add_action('admin_post_bhcrm_bulk_tag',  ['BHCRM_Tags', 'handle_bulk_tag']);
    add_action('admin_post_bhcrm_export',    ['BHCRM_Export', 'handle']);

    BHCRM_Links::init(); // must run before BHCRM_Projects::init() — projects write links on create()
    BHCRM_Projects::init();
    BHCRM_Subtasks::init();
    BHCRM_CardLog::init();
    BHCRM_Notes::init();
    BHCRM_Tags::init();
    BHCRM_Segments::init();
    // Saved CRM lists become OUS_Campaigns audiences.
    add_filter('bhcore_campaign_segments', ['BHCRM_Segments', 'register_campaign_segments']);
    BHCRM_Debug::init();
    if (class_exists('OUS_TestRunner')) BHCRM_TestSuite::init();

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
