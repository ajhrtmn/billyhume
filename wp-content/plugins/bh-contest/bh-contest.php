<?php
/**
 * Plugin Name: BH Contest
 * Description: Music contest voting platform with a sleek, native-feeling player.
 * Version:     3.11.3
 * Requires PHP: 8.2
 * Requires Plugins: the-self-hosted-self
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).

define('BH_VER',        '3.11.3');

define('BH_PATH',       plugin_dir_path(__FILE__));
define('BH_URL',        plugin_dir_url(__FILE__));
define('BH_VOTE_BASE',  1);                 // votes every user gets
define('BH_VOTE_BONUS', 1);                 // extra votes earned by submitting a track
define('BH_MAX_BYTES',  20 * 1024 * 1024);  // max upload size
define('BH_REG_THROTTLE', 3);               // max registrations per IP per hour
define('BH_LOGIN_MAX_FAILS', 5);            // failed logins (per username+IP) before a 15-minute lockout

foreach (['tables', 'activator', 'post-types', 'helpers', 'auth', 'api', 'admin-menus', 'admin-list-tables', 'admin-reports', 'admin-moderation', 'admin-metaboxes', 'admin', 'contest-wizard', 'debug', 'crm-integration', 'console', 'reveal', 'discord', 'archive', 'style-surfaces', 'element-surface', 'portal-panel', 'judging', 'rounds', 'share-cards', 'blocks', 'test-suite'] as $f) {
    require_once BH_PATH . "includes/class-$f.php";
}

// Safe to register unconditionally — activation only touches this plugin's
// own table/default pages, not the identity/style classes it depends on.
register_activation_hook(__FILE__, ['BH_Activator', 'activate']);

/**
 * Gated behind plugins_loaded rather than checked directly here: WordPress
 * loads active plugins' files in alphabetical folder order, so a direct
 * class_exists() check at file-parse time could run before the dependency's
 * file has been read yet. plugins_loaded always fires after every active
 * plugin's main file has loaded, regardless of folder name order.
 */
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Contest</strong> requires <strong>The Self-Hosted Self</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    // One-time migration of profile data into the core plugin's identity
    // table (schemas are identical; INSERT IGNORE makes this safe to re-run).
    if (get_option('bh_identity_migration_done') !== '1') {
        global $wpdb;
        $old = BHCON_Tables::participant_profiles();
        $new = OUS_Tables::profiles();
        $old_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $old)) === $old;
        $new_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $new)) === $new;

        if (!$old_exists) {
            update_option('bh_identity_migration_done', '1');
        } elseif ($new_exists) {
            $wpdb->query(
                "INSERT IGNORE INTO $new
                    (user_id, real_name, discord_name, twitch_name, youtube_name, phone, typical_platform, real_name_public, discord_public, twitch_public, youtube_public, updated_at)
                 SELECT user_id, real_name, discord_name, twitch_name, youtube_name, phone, typical_platform, real_name_public, discord_public, twitch_public, youtube_public, updated_at
                 FROM $old"
            );
            if (!$wpdb->last_error) update_option('bh_identity_migration_done', '1');
        }
        // If old exists but new doesn't yet, leave the flag unset so this
        // retries on a later request instead of giving up silently.
    }

    BH_Activator::maybe_upgrade();
    BH_Activator::maybe_migrate_style_meta_keys();

    add_action('admin_init',    ['BH_Activator', 'maybe_create_default_pages']);
    add_action('init',          ['BH_PostTypes', 'register']);
    // Registers the 'bh/vote' event type; class-api.php's vote handler emits it.
    add_action('init', function () {
        if (class_exists('BH_Event')) {
            BH_Event::register_event_type('bh/vote', ['contest_id' => 'int', 'category' => 'string', 'submission_id' => 'int', 'action' => 'string']);
        }
    });
    add_action('init',          ['BH_Auth', 'init']);
    add_action('init',          ['BH_API', 'init']);
    add_action('rest_api_init', ['BH_API', 'register_routes']);
    add_action('init',          ['BH_Admin', 'init']);
    add_action('init',          ['BH_ContestWizard', 'init']);
    add_action('before_delete_post', ['BH_AdminMenus', 'cleanup_deleted_contest']);
    add_action('init',          ['BH_CRMIntegration', 'init']);
    add_action('init',          ['BH_StyleSurfaces', 'init']);
    add_action('init',          ['BH_ElementSurface', 'init']);
    add_action('init',          ['BH_Console', 'init']);
    add_action('init',          ['BH_Reveal', 'init']);
    add_action('init',          ['BH_Judging', 'init']);
    add_action('init',          ['BH_Blocks', 'init']);
    add_action('init',          ['BH_Discord', 'init']);
    add_action('init',          ['BH_Archive', 'init']);
    add_action('init',          ['BH_ShareCards', 'init']);

    // Registers this plugin's seeding/reset actions into the shared Debug
    // Tools page; production-safety checks are centralized in OUS_Debug.
    add_action('init', ['BH_Debug', 'init']);
    add_action('init', ['BH_PortalPanel', 'init']);
    if (class_exists('OUS_TestRunner')) add_action('init', ['BH_TestSuite', 'init']);

    // Load assets only on pages that actually use the player.
    add_action('wp_enqueue_scripts', function () {
        if (!is_singular()) return;
        global $post;
        if (!$post) return;
        // has_block() checks are needed alongside has_shortcode(): a
        // block-authored page has none of the literal bracket text, so
        // without this the mount div would render but never get its JS.
        $has_player   = has_shortcode($post->post_content, 'bh_contest_player') || has_block('bh/contest-player', $post);
        $has_reveal   = has_shortcode($post->post_content, 'bh_results_reveal') || has_block('bh/results-reveal', $post);
        $has_archive  = has_shortcode($post->post_content, 'bh_archive') || has_block('bh/archive', $post);
        if (!$has_player && !$has_reveal && !$has_archive) return;

        // Shared across all three shortcodes so Reveal/Archive pages match
        // the player's look automatically, including per-contest overrides.
        $font_url = BHY_Style::google_fonts_url();
        if ($font_url) wp_enqueue_style('bh-fonts', $font_url, [], null);
        wp_enqueue_style('bh-player', BH_URL . 'assets/css/player.css', $font_url ? ['bh-fonts'] : [], BH_VER);
        wp_add_inline_style('bh-player', BHY_Style::inline_css());

        if ($has_player) {
            wp_enqueue_script('howler', 'https://cdnjs.cloudflare.com/ajax/libs/howler/2.2.4/howler.min.js', [], '2.2.4', true);
            wp_enqueue_script('bh-player', BH_URL . 'assets/js/player.js', ['howler'], BH_VER, true);
            $brand = BHY_Style::get();
            wp_localize_script('bh-player', 'BHData', [
                'rest'     => esc_url_raw(rest_url('bh/v1/')),
                'identity' => esc_url_raw(rest_url('bhi/v1/')),
                'nonce'    => wp_create_nonce('wp_rest'),
                'loggedIn' => is_user_logged_in(),
                'maxBytes' => BH_MAX_BYTES,
                'brand'    => ['part1' => $brand['brand_part1'], 'part2' => $brand['brand_part2'], 'logoUrl' => BHY_Style::logo_url($brand)],
            ]);
        }

        if ($has_reveal) {
            wp_enqueue_script('bh-common', BH_URL . 'assets/js/bh-common.js', [], BH_VER, true);
            wp_enqueue_script('bh-anime', BH_URL . 'assets/js/vendor/anime.min.js', [], '4.5.0', true);
            wp_enqueue_script('bh-reveal', BH_URL . 'assets/js/reveal.js', ['bh-common', 'bh-anime'], BH_VER, true);
            wp_localize_script('bh-reveal', 'BHData', [
                'rest' => esc_url_raw(rest_url('bh/v1/')),
            ]);
        }

        if ($has_archive) {
            wp_enqueue_script('bh-common', BH_URL . 'assets/js/bh-common.js', [], BH_VER, true);
            wp_enqueue_script('bh-archive', BH_URL . 'assets/js/archive.js', ['bh-common'], BH_VER, true);
            wp_localize_script('bh-archive', 'BHData', [
                'rest' => esc_url_raw(rest_url('bh/v1/')),
            ]);
        }
    });
});
