<?php
if (!defined('ABSPATH')) exit;

/**
 * Classic PHP theme, not a block theme: bh-courses' archive template
 * branches on wp_is_block_theme() being false.
 */

function oust_setup() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('automatic-feed-links');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('customize-selective-refresh-widgets');

    /**
     * Block support for the_content() output. No editor-color-palette: the
     * --bh-* tokens are per-site and BHY_Style::add_editor_iframe_styles()
     * already pipes them into the editor iframe.
     */
    add_theme_support('align-wide');
    add_theme_support('wp-block-styles');
    add_theme_support('responsive-embeds');
    add_theme_support('editor-styles');
    add_editor_style('assets/css/editor-style.css');

    register_nav_menus([
        'primary' => __('Primary Menu', 'the-self-hosted-self-theme'),
    ]);
}
add_action('after_setup_theme', 'oust_setup');

function oust_enqueue_assets() {
    $css_path = get_theme_file_path('/assets/css/theme.css');
    wp_enqueue_style('oust-theme', get_theme_file_uri('/assets/css/theme.css'), [], file_exists($css_path) ? filemtime($css_path) : '1.0.0');

    // Core block styles: a classic theme has no theme.json to auto-load
    // them. Enqueued first so oust-blocks.css wins the cascade.
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
        'name' => __('Footer', 'the-self-hosted-self-theme'),
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
 * Fallback BH_SEO page data for plain Pages/Posts; other CPTs set their own.
 * WHY template_redirect: it must run before wp_head.
 */
function oust_set_seo_data() {
    if (!class_exists('BH_SEO') || !is_singular(['page', 'post'])) return;
    // Fallback only: bail if a more specific hook already set page data.
    if (BH_SEO::has_page_data()) return;
    $post_id = get_queried_object_id();
    if (!$post_id) return;
    // get_the_excerpt(), not wp_strip_all_tags(): it strips shortcodes too.
    $excerpt = get_the_excerpt($post_id);
    BH_SEO::set_page_data([
        'title' => get_the_title($post_id) . ' — ' . get_bloginfo('name'),
        'description' => $excerpt ?: get_bloginfo('description'),
        'url' => get_permalink($post_id),
        'image' => has_post_thumbnail($post_id) ? get_the_post_thumbnail_url($post_id, 'large') : null,
        'type' => is_singular('post') ? 'article' : 'website',
    ]);
}
// Priority 20 so the fallback runs after more specific hooks.
add_action('template_redirect', 'oust_set_seo_data', 20);

/**
 * Front-end nav direction/depth for theme.css's view-transition keyframes.
 * WHY inline in wp_head at priority 1: 'pagereveal' fires before footer
 * scripts run. "Section" is the first path segment.
 */
function oust_print_nav_depth_script(): void {
    ?>
<script>
window.addEventListener('pagereveal', function () {
    if (!('navigation' in window)) return;
    var activation = window.navigation.activation;
    if (!activation) return;
    var root = document.documentElement;
    var direction = activation.navigationType === 'traverse' ? 'back' : 'forward';
    root.setAttribute('data-oust-nav-direction', direction);
    var fromUrl = activation.from && activation.from.url;
    var toUrl = activation.entry && activation.entry.url;
    if (!fromUrl || !toUrl) { root.setAttribute('data-oust-nav-depth', 'jump'); return; }
    function sectionOf(u) {
        try {
            var path = new URL(u).pathname.replace(/^\/|\/$/g, '');
            return path.split('/')[0] || '(home)';
        } catch (e) { return null; }
    }
    var sameSection = sectionOf(fromUrl) !== null && sectionOf(fromUrl) === sectionOf(toUrl);
    root.setAttribute('data-oust-nav-depth', sameSection ? 'lateral' : 'jump');
    setTimeout(function () {
        root.removeAttribute('data-oust-nav-direction');
        root.removeAttribute('data-oust-nav-depth');
    }, 700);
});
</script>
    <?php
}
add_action('wp_head', 'oust_print_nav_depth_script', 1);

// The Account/Log-In menu link comes from the-self-hosted-self's OUS_MenuSync.

/**
 * Register this theme with OUS_GithubUpdates so the live site pulls theme
 * updates from GitHub; the host's deploy syncs plugins but not themes.
 */
add_action('ous_github_updates_register', function () {
    if (!class_exists('OUS_GithubUpdates')) return;
    OUS_GithubUpdates::register('the-self-hosted-self-theme', [
        'type' => 'theme',
        'label' => 'The Self-Hosted Self (theme)',
        'stylesheet' => 'the-self-hosted-self-theme',
        'repo' => apply_filters('ous_github_updates_default_repo', 'ajhrtmn/billyhume'),
        'branch' => apply_filters('ous_github_updates_default_branch', 'dev'),
        'path' => 'wp-content/themes/the-self-hosted-self-theme',
    ]);
});
