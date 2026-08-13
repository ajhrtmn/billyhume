<?php
if (!defined('ABSPATH')) exit;

/**
 * Classic PHP theme (not a block theme) — matches how the ecosystem's
 * own plugins already render (procedural PHP templates reading --bh-*
 * custom properties from wp_head), and bh-courses's archive template
 * already branches on wp_is_block_theme() expecting this to be false.
 */

function oust_setup() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('automatic-feed-links');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('customize-selective-refresh-widgets');

    /**
     * Gutenberg/block-editor support — this stays a classic PHP theme
     * (no block-templates/ or theme.json full-site-editing support, so
     * wp_is_block_theme() still returns false, matching what
     * bh-courses/templates/archive-bh_course.php already branches on),
     * but the_content() on page.php/single.php renders whatever blocks
     * an editor used, so the editor canvas and the rendered output both
     * need real support for core block markup — not just the plain
     * .oust-prose type rules.
     *
     * No custom add_theme_support('editor-color-palette') here — the
     * --bh-* tokens are dynamic (per-site, admin-configurable via
     * Design Suite), and BHY_Style::add_editor_iframe_styles()
     * (own-ur-shit/includes/class-style.php) already pipes those same
     * tokens into the block-editor iframe's own styles list, so
     * oust-editor-style.css below can reference var(--bh-*) directly
     * without this theme needing to hardcode a second, static palette.
     */
    add_theme_support('align-wide');
    add_theme_support('wp-block-styles');
    add_theme_support('responsive-embeds');
    add_theme_support('editor-styles');
    add_editor_style('assets/css/editor-style.css');

    register_nav_menus([
        'primary' => __('Primary Menu', 'own-ur-shit-theme'),
    ]);
}
add_action('after_setup_theme', 'oust_setup');

function oust_enqueue_assets() {
    $css_path = get_theme_file_path('/assets/css/theme.css');
    wp_enqueue_style('oust-theme', get_theme_file_uri('/assets/css/theme.css'), [], file_exists($css_path) ? filemtime($css_path) : '1.0.0');

    // Core block styles (wp-block-library) — needed on the front end
    // since this is a classic theme (no theme.json to auto-load them),
    // enqueued before oust-theme.css so oust-blocks.css below can win
    // the cascade on anything it customizes.
    wp_enqueue_style('wp-block-library');

    $blocks_path = get_theme_file_path('/assets/css/blocks.css');
    wp_enqueue_style('oust-blocks', get_theme_file_uri('/assets/css/blocks.css'), ['oust-theme'], file_exists($blocks_path) ? filemtime($blocks_path) : '1.0.0');

    $js_path = get_theme_file_path('/assets/js/theme.js');
    wp_enqueue_script('oust-theme', get_theme_file_uri('/assets/js/theme.js'), [], file_exists($js_path) ? filemtime($js_path) : '1.0.0', true);
}
add_action('wp_enqueue_scripts', 'oust_enqueue_assets');

function oust_body_classes($classes) {
    if (!is_active_sidebar('sidebar-1')) {
        $classes[] = 'oust-no-sidebar';
    }
    return $classes;
}
add_filter('body_class', 'oust_body_classes');

function oust_widgets_init() {
    register_sidebar([
        'name' => __('Footer', 'own-ur-shit-theme'),
        'id' => 'footer-1',
        'before_widget' => '<div class="oust-footer-widget">',
        'after_widget' => '</div>',
        'before_title' => '<h3 class="oust-footer-widget-title">',
        'after_title' => '</h3>',
    ]);
}
add_action('widgets_init', 'oust_widgets_init');

function oust_excerpt_length($length) {
    return 30;
}
add_filter('excerpt_length', 'oust_excerpt_length');

function oust_excerpt_more($more) {
    return '&hellip;';
}
add_filter('excerpt_more', 'oust_excerpt_more');

function oust_pingback_header() {
    if (is_singular() && pings_open()) {
        echo '<link rel="pingback" href="' . esc_url(get_bloginfo('pingback_url')) . '">' . "\n";
    }
}
add_action('wp_head', 'oust_pingback_header');

/**
 * oust_append_portal_link() removed (was here) — a real, latent
 * duplicate-link bug caught before it could ever surface live: this
 * theme-side filter and own-ur-shit's own OUS_MenuSync (3.10.20+,
 * class-menu-sync.php) both add an Account/Log-In link to the primary
 * menu independently, so a menu that's ever actually been synced would
 * show it TWICE the moment this theme's code deploys and starts
 * running (it hadn't yet, at the time this was caught — see
 * class-github-updates.php's new registration below). OUS_MenuSync's
 * own seeded link is the single source of truth now — real, tagged,
 * per-request dynamic ("Log In" / "Go to Portal"), theme-agnostic (any
 * theme's own no-menu-assigned fallback still gets it too, see
 * header.php's oust_default_menu()) — so this theme no longer needs
 * its own copy of the same feature.
 */

/**
 * Real, confirmed infra gap: this install's Wasmer hosting deploys
 * wp-content/plugins/ from this repo's GitHub on every push, but never
 * wp-content/themes/ — confirmed live by comparing this file's own
 * committed version against style.css's live Version: header, which
 * stayed stale across multiple real pushes. No wasmer.toml exists in
 * this repo controlling deploy scope, so this is a Wasmer-dashboard-
 * side setting outside this codebase's control, not something fixable
 * here directly.
 *
 * Rather than depend on that file-sync path at all, this theme
 * registers itself with own-ur-shit's own OUS_GithubUpdates
 * (class-github-updates.php) — the same real, self-hosted "check
 * GitHub for a newer version, install it in one click" mechanism every
 * ecosystem plugin already gets automatically. The live site PULLS a
 * fresh copy from GitHub using its own already-configured access,
 * rather than any external process pushing credentials/files at it —
 * the more robust, hosting-agnostic direction (works identically on
 * Wasmer, a different host, or no host-level git integration at all).
 */
add_action('ous_github_updates_register', function () {
    if (!class_exists('OUS_GithubUpdates')) return;
    OUS_GithubUpdates::register('own-ur-shit-theme', [
        'type' => 'theme',
        'label' => 'Own Ur Shit (theme)',
        'stylesheet' => 'own-ur-shit-theme',
        'repo' => apply_filters('ous_github_updates_default_repo', 'ajhrtmn/billyhume'),
        'branch' => apply_filters('ous_github_updates_default_branch', 'dev'),
        'path' => 'wp-content/themes/own-ur-shit-theme',
    ]);
});
