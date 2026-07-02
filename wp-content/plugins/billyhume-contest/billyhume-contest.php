<?php
/**
 * Plugin Name: BillyHume Contest
 * Description: Music contest voting platform with a sleek, native-feeling player.
 * Version:     1.14.2
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

define('BH_VER',        '1.14.2');
define('BH_PATH',       plugin_dir_path(__FILE__));
define('BH_URL',        plugin_dir_url(__FILE__));
define('BH_VOTE_BASE',  1);                 // votes every user gets
define('BH_VOTE_BONUS', 1);                 // extra votes earned by submitting a track
define('BH_MAX_BYTES',  20 * 1024 * 1024);  // max upload size
define('BH_REG_THROTTLE', 3);               // max registrations per IP per hour

foreach (['activator', 'post-types', 'helpers', 'profiles', 'settings', 'auth', 'api', 'admin', 'debug', 'participants'] as $f) {
    require_once BH_PATH . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['BH_Activator', 'activate']);
add_action('plugins_loaded', ['BH_Activator', 'maybe_upgrade']);
add_action('init',          ['BH_PostTypes', 'register']);
add_action('init',          ['BH_Auth', 'init']);
add_action('rest_api_init', ['BH_API', 'register_routes']);
add_action('rest_api_init', ['BH_Auth', 'register_routes']);
add_action('init',          ['BH_Admin', 'init']);
add_action('init',          ['BH_Participants', 'init']);
add_action('init',          ['BH_Settings', 'init']);

// Debug data-seeding tool: "Debug Tools" under Contests, visible to any
// admin regardless of environment. The seeding/reset ACTIONS refuse to run
// on what looks like a production environment (see BH_Debug::is_locked()) —
// so the tool is always easy to find, but hard to fire by accident on a
// live site.
add_action('init', ['BH_Debug', 'init']);

// Load assets only on pages that actually use the player, and hand the
// front end everything it needs up front (REST base, a fresh nonce, auth
// state) so there is no extra round trip before first paint.
add_action('wp_enqueue_scripts', function () {
    if (!is_singular()) return;
    global $post;
    if (!$post || !has_shortcode($post->post_content, 'bh_contest_player')) return;

    $font_url = BH_Settings::google_fonts_url();
    if ($font_url) wp_enqueue_style('bh-fonts', $font_url, [], null);
    wp_enqueue_script('howler', 'https://cdnjs.cloudflare.com/ajax/libs/howler/2.2.4/howler.min.js', [], '2.2.4', true);
    wp_enqueue_style('bh-player', BH_URL . 'assets/css/player.css', $font_url ? ['bh-fonts'] : [], BH_VER);
    // Site-specific palette wins the cascade over the stylesheet's own
    // :root defaults — see BH_Settings::inline_css(). The stylesheet
    // itself never needs editing per site.
    wp_add_inline_style('bh-player', BH_Settings::inline_css());
    wp_enqueue_script('bh-player', BH_URL . 'assets/js/player.js', ['howler'], BH_VER, true);
    $brand = BH_Settings::get();
    wp_localize_script('bh-player', 'BHData', [
        'rest'     => esc_url_raw(rest_url('bh/v1/')),
        'nonce'    => wp_create_nonce('wp_rest'),
        'loggedIn' => is_user_logged_in(),
        'maxBytes' => BH_MAX_BYTES,
        'brand'    => ['part1' => $brand['brand_part1'], 'part2' => $brand['brand_part2']],
    ]);
});
