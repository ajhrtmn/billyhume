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
     * (the-self-hosted-self/includes/class-style.php) already pipes those same
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
        'primary' => __('Primary Menu', 'the-self-hosted-self-theme'),
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
 * Real gap, found live during a production-readiness audit:
 * BH_SEO::set_page_data() (the-self-hosted-self/includes/class-seo.php) is a
 * real, working shared renderer — confirmed live on bh-course/
 * bh-contest/bh-registry/bh-streaming pages, all of which call it
 * themselves — but nothing has ever called it for a plain WordPress
 * Page or Post, the theme's own default content types. Confirmed:
 * both the homepage and a real Page had zero meta description, zero
 * OG tags, zero JSON-LD. is_singular(['page','post']) deliberately
 * excludes every other CPT (bh_course etc.), which already set their
 * own richer page data (schema.org Course/Person/etc.) elsewhere —
 * this is only the fallback for the two built-in types nothing else
 * covers. Hooked on template_redirect (fires before wp_head) — see
 * BHC_Render_Course's own set_seo_data() fix this same session for
 * why that timing matters (the_content-time calls are too late).
 */
function oust_set_seo_data() {
    if (!class_exists('BH_SEO') || !is_singular(['page', 'post'])) return;
    // Real gap caught right after the initial fix shipped: a page
    // whose content is a plugin shortcode (e.g. [bh_contest_player])
    // can have BOTH this generic fallback AND that plugin's own,
    // more-specific template_redirect hook fire for the same request.
    // Without this check, whichever hook happens to register last
    // would silently overwrite the other's data — worked correctly by
    // registration-order luck when first tested, not by design. This
    // is only ever a FALLBACK — bail immediately if anything more
    // specific already claimed the page.
    if (BH_SEO::has_page_data()) return;
    $post_id = get_queried_object_id();
    if (!$post_id) return;
    // Real bug caught live: wp_strip_all_tags() strips HTML but not
    // shortcodes, so a post whose content is just a bare shortcode
    // embed (e.g. "[bh_contest_player contest=\"24\"]") leaked the
    // literal shortcode syntax into the meta description. get_the_excerpt()
    // handles both cases correctly on its own (a real manual excerpt if
    // set, otherwise wp_trim_excerpt()'s own auto-summary of post_content
    // with strip_shortcodes() already applied) — no need to branch on
    // has_excerpt() at all.
    $excerpt = get_the_excerpt($post_id);
    BH_SEO::set_page_data([
        'title' => get_the_title($post_id) . ' — ' . get_bloginfo('name'),
        'description' => $excerpt ?: get_bloginfo('description'),
        'url' => get_permalink($post_id),
        'image' => has_post_thumbnail($post_id) ? get_the_post_thumbnail_url($post_id, 'large') : null,
        'type' => is_singular('post') ? 'article' : 'website',
    ]);
}
// Priority 20 (not the default 10): belt-and-suspenders alongside the
// has_page_data() check above — makes the "fallback runs after
// anything more specific" intent explicit in the hook registration
// itself, not just enforced by the runtime check.
add_action('template_redirect', 'oust_set_seo_data', 20);

/**
 * Front-end half of the depth-aware cross-document navigation built
 * for wp-admin (self-hosted-self-admin-skin) — direct feedback:
 * "Frontend and admin." Same real bug already found and fixed on the
 * admin side, applied here from the start rather than re-discovering
 * it: the 'pagereveal' event (part of the Cross-Document View
 * Transitions spec, theme.css's own `@view-transition { navigation:
 * auto; }` opt-in) fires very early in a new page's load — before a
 * normally-enqueued, footer-loaded script would ever get the chance to
 * attach a listener for it. This listener is printed as a tiny
 * synchronous inline <script> in wp_head (priority 1, as early as
 * this theme can manage), mirroring admin_head priority 1 on the admin
 * side exactly.
 *
 * "Section" on the front end has no equivalent to wp-admin's own
 * ?page=<slug> convention, so this reads the first real path segment
 * instead (/courses/... vs /account/... vs /shop/... vs a bare post
 * permalink) — a real, if rough, proxy for "which part of the site
 * this is," same posture as the admin version's own slug-prefix
 * heuristic.
 *
 * STATUS: confirmed working (2026-08-16), correcting an earlier false
 * "never fires" finding. That earlier conclusion came from a genuinely
 * flawed test: a listener attached via injected devtools-style JS on
 * the OUTGOING page, after that page had already fully loaded, then
 * checked after navigating away — but 'pagereveal' fires on the
 * INCOMING document's own window, which is a completely separate
 * JS realm from the outgoing page; a listener on page A's window can
 * never observe an event on page B's window regardless of whether the
 * feature works. That flawed methodology was applied identically on
 * the admin side during a supposed "re-check," which also came back
 * negative — the giveaway that the TEST was broken, not either
 * feature, since the admin side was previously proven working by a
 * real click-through test.
 *
 * Re-verified properly this time: temporary sessionStorage markers
 * written directly inside this exact function (both "script executed"
 * and "pagereveal fired", timestamped) survive real navigation because
 * they're printed fresh into every page's own <head> — not injected
 * from a previous page. A real mouse click on the theme's own nav
 * links (Home -> Sample Page -> Contest Test Page) fired 'pagereveal'
 * reliably, ~20-45ms after the script itself ran, on every hop tested.
 * Debug instrumentation removed after confirming; the mechanism below
 * is unchanged from before this investigation — it was never broken.
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

/**
 * oust_append_portal_link() removed (was here) — a real, latent
 * duplicate-link bug caught before it could ever surface live: this
 * theme-side filter and the-self-hosted-self's own OUS_MenuSync (3.10.20+,
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
 * registers itself with the-self-hosted-self's own OUS_GithubUpdates
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
    OUS_GithubUpdates::register('the-self-hosted-self-theme', [
        'type' => 'theme',
        'label' => 'The Self-Hosted Self (theme)',
        'stylesheet' => 'the-self-hosted-self-theme',
        'repo' => apply_filters('ous_github_updates_default_repo', 'ajhrtmn/billyhume'),
        'branch' => apply_filters('ous_github_updates_default_branch', 'dev'),
        'path' => 'wp-content/themes/the-self-hosted-self-theme',
    ]);
});
