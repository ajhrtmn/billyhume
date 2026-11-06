<?php
/**
 * Plugin Name: Admin Skin — The Self-Hosted Self (EXPERIMENTAL)
 * Description: EXPERIMENTAL / on the back burner as of 2026-08-25 — deactivated by default, not part of the shipping ecosystem. Matching WP core's own native chrome convincingly turned out to need far more than color matching (font, sizing, row metrics, active-item treatment), and the effort was better spent elsewhere; the design effort belongs on this ecosystem's OWN pages, not on re-skinning wp-admin. A wp-admin-only visual/UX mod — reskins the default WordPress dashboard with a calmer dark/light palette, real accessibility work (focus states, contrast, reduced-motion, larger touch targets), a genuinely mobile-friendly admin menu, and a couple of small "it just works" touches (a Cmd/Ctrl+K command palette, a light/dark toggle). Standalone and portable — works with any theme and any other plugins, never touches the front end at all.
 * Version:     0.66.0
 * Requires PHP: 8.2
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).

define('SHSAS_VER', '0.66.0');

define('SHSAS_URL', plugin_dir_url(__FILE__));
define('SHSAS_PATH', plugin_dir_path(__FILE__));

/**
 * Native by default: unowned screens never load this plugin's CSS/fonts, so
 * there is nothing to reset. The shsas_full_skin option skins every screen.
 */
function shsas_full_skin_enabled(): bool {
    return (bool) get_option('shsas_full_skin', false);
}

function shsas_enqueue_assets(): void {
    if (!shsas_full_skin_enabled() && !shsas_screen_is_owned()) {
        return;
    }
    // WHY 'wp-admin' dependency: core's admin CSS must print first so this
    // stylesheet wins cascade ties.
    wp_enqueue_style('shsas-admin-skin', SHSAS_URL . 'assets/css/admin-skin.css', ['wp-admin'], SHSAS_VER);
    // Jost + Atkinson Hyperlegible, loaded directly (not via
    // the-self-hosted-self) so this plugin stays standalone.
    wp_enqueue_style('shsas-fonts', 'https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&family=Atkinson+Hyperlegible:wght@400;700&display=swap', [], SHSAS_VER);
    wp_enqueue_script('shsas-admin-skin', SHSAS_URL . 'assets/js/admin-skin.js', [], SHSAS_VER, true);

    // Command palette entries come from core's already capability-filtered
    // $menu/$submenu, so access rules are never re-derived.
    wp_localize_script('shsas-admin-skin', 'shsasMenu', ['items' => shsas_flatten_admin_menu()]);
}
add_action('admin_enqueue_scripts', 'shsas_enqueue_assets');

// The admin bar and sidebar stay at core's stock appearance; the skin is
// scoped to page content on owned screens. assets/css/admin-bar.css is not
// enqueued anywhere.

// wp-login.php isn't an is_admin() screen; CSS only there.
function shsas_enqueue_login_assets(): void {
    wp_enqueue_style('shsas-admin-skin', SHSAS_URL . 'assets/css/admin-skin.css', [], SHSAS_VER);
    wp_enqueue_style('shsas-fonts', 'https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&family=Atkinson+Hyperlegible:wght@400;700&display=swap', [], SHSAS_VER);
}
add_action('login_enqueue_scripts', 'shsas_enqueue_login_assets');

/**
 * Remaps BHY_UI's --bhy-* admin tokens (class-ui.php) onto this skin's
 * tokens. CSS variables only, no PHP dependency; unused without the core.
 *
 * WHY priority 999: class-ui.php prints an equal-specificity :root block at
 * priority 10, so this must print after it.
 */
function shsas_bridge_bhy_tokens(): void {
    echo '<style id="shsas-bhy-token-bridge">:root{'
        . '--bhy-ink:var(--shsas-text);--bhy-ink-dim:var(--shsas-text-dim);'
        . '--bhy-border:var(--shsas-border);--bhy-surface:var(--shsas-surface);'
        . '--bhy-subtle:var(--shsas-surface-2);--bhy-accent:var(--shsas-accent);'
        // Ink that sits ON a filled accent/neon chip; without it chips keep
        // a hardcoded #fff that fails on the light periwinkle accent.
        . '--bhy-accent-text:var(--shsas-accent-text);'
        . '--bhy-success:var(--shsas-success);--bhy-success-bg:color-mix(in srgb,var(--shsas-success) 16%,var(--shsas-surface));'
        . '--bhy-warning:var(--shsas-warning);--bhy-warning-bg:color-mix(in srgb,var(--shsas-warning) 16%,var(--shsas-surface));'
        . '--bhy-danger:var(--shsas-danger);--bhy-danger-bg:color-mix(in srgb,var(--shsas-danger) 16%,var(--shsas-surface));'
        . '--bhy-hover-tint:var(--shsas-surface-2);'
        . '--bhy-selected-tint:color-mix(in srgb,var(--shsas-accent) 16%,var(--shsas-surface));'
        . '--bhy-focus-ring:0 0 0 2px color-mix(in srgb,var(--shsas-accent) 25%,transparent);'
        . '--bhy-radius:var(--shsas-radius);--bhy-radius-sm:var(--shsas-radius-sm);'
        . '}</style>';
}
add_action('admin_head', 'shsas_bridge_bhy_tokens', 999);

/**
 * Whether this skin owns the current admin screen.
 *
 * WHY: third-party CSS is written against core's white canvas; repainting
 * it dark puts their hardcoded dark text on a dark ground. Unowned screens
 * keep core's surface. Adopt a plugin via the `shsas_owned_screen` filter.
 */
function shsas_screen_is_owned(): bool {
    if (shsas_full_skin_enabled()) return true;

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen) return true;

    $id = (string) $screen->id;

    // Our own ecosystem prefixes. A screen id for a plugin page looks like
    // "toplevel_page_<slug>" or "<parent>_page_<slug>".
    $ours = ['ous', 'bh-', 'bh_', 'bhs-', 'bhl-', 'bhv-', 'bhf-', 'bhc-', 'bhm-', 'bhr-'];

    if (strpos($id, '_page_') !== false) {
        $slug = substr($id, strpos($id, '_page_') + 6);
        foreach ($ours as $prefix) {
            if (strpos($slug, $prefix) === 0) return true;
        }
        // A plugin page that is not ours.
        return (bool) apply_filters('shsas_owned_screen', false, $id, $screen);
    }

    // Core screens are unowned; only this ecosystem's own CPT screens
    // (post types starting with "bh") stay owned. Relies on
    // shsas_enqueue_assets() skipping the CSS entirely on unowned screens.
    $post_type = !empty($screen->post_type) ? (string) $screen->post_type : '';
    if ($post_type !== '' && strpos($post_type, 'bh') === 0) {
        return true;
    }
    return (bool) apply_filters('shsas_owned_screen', false, $id, $screen);
}

function shsas_admin_body_class(string $classes): string {
    $classes .= shsas_screen_is_owned() ? ' shsas-owned' : ' shsas-unowned';
    if (shsas_full_skin_enabled()) {
        $classes .= ' shsas-full-skin';
    }
    return $classes;
}
add_filter('admin_body_class', 'shsas_admin_body_class');

/**
 * Stamps nav direction/depth for admin-skin.css's view-transition keyframes.
 *
 * WHY inline in <head> at priority 1: 'pagereveal' fires before the
 * footer-loaded admin-skin.js runs, so its listener must be registered early.
 */
function shsas_print_nav_depth_script(): void {
    ?>
<script>
window.addEventListener('pagereveal', function () {
    if (!('navigation' in window)) return;
    var activation = window.navigation.activation;
    if (!activation) return;
    var root = document.documentElement;
    var direction = activation.navigationType === 'traverse' ? 'back' : 'forward';
    root.setAttribute('data-shsas-nav-direction', direction);
    var fromUrl = activation.from && activation.from.url;
    var toUrl = activation.entry && activation.entry.url;
    if (!fromUrl || !toUrl) { root.setAttribute('data-shsas-nav-depth', 'jump'); return; }
    function sectionOf(u) {
        try {
            var url = new URL(u);
            var page = url.searchParams.get('page');
            if (page) return page.split('-')[0];
            return url.pathname.split('/').pop();
        } catch (e) { return null; }
    }
    var sameSection = sectionOf(fromUrl) !== null && sectionOf(fromUrl) === sectionOf(toUrl);
    root.setAttribute('data-shsas-nav-depth', sameSection ? 'lateral' : 'jump');
    setTimeout(function () {
        root.removeAttribute('data-shsas-nav-direction');
        root.removeAttribute('data-shsas-nav-depth');
    }, 700);
});
</script>
    <?php
}
add_action('admin_head', 'shsas_print_nav_depth_script', 1);

/**
 * Makes the admin-bar site-name item navigate on the first tap below 782px.
 *
 * Core treats the first tap on a .menupop as "open submenu". WHY no
 * DOMContentLoaded wait: the 'admin-bar' script prints after the toolbar
 * markup, by which point that event has already fired.
 */
function shsas_admin_bar_tap_to_dashboard(): void {
    if (!is_admin_bar_showing()) return;
    wp_add_inline_script('admin-bar', <<<'JS'
(function () {
    if (!window.matchMedia || !window.matchMedia('(max-width: 782px)').matches) return;
    var item = document.querySelector('#wp-admin-bar-site-name > .ab-item');
    if (!item || !item.getAttribute('href')) return;
    // Capture phase: core's own handler calls preventDefault on this tap
    // to open the submenu, so a bubble-phase listener would arrive after
    // the navigation had already been cancelled.
    item.addEventListener('click', function (e) {
        e.stopPropagation();
        window.location.href = item.getAttribute('href');
    }, true);
}());
JS
    );
}
add_action('wp_enqueue_scripts', 'shsas_admin_bar_tap_to_dashboard', 20);
add_action('admin_enqueue_scripts', 'shsas_admin_bar_tap_to_dashboard', 20);

function shsas_admin_bar_toggle(WP_Admin_Bar $wp_admin_bar): void {
    if (!is_admin()) return;
    // Visible trigger for the Cmd/Ctrl+K command palette, plus the
    // light/dark toggle (admin-skin.js handles both).
    $wp_admin_bar->add_node([
        'id' => 'shsas-palette-trigger',
        // Icon artwork is a CSS mask on the empty span.
        'title' => '<span class="shsas-palette-trigger-icon" aria-hidden="true"></span><span class="shsas-palette-trigger-label">Jump to&hellip;</span><kbd class="shsas-palette-trigger-kbd" aria-hidden="true">&#8984;K</kbd>',
        'href' => '#',
        'meta' => ['class' => 'shsas-palette-trigger', 'title' => 'Jump to any admin page (Cmd/Ctrl+K)'],
    ]);
    $wp_admin_bar->add_node([
        'id' => 'shsas-theme-toggle',
        // Sun/moon mask keyed off :root[data-shsas-theme].
        'title' => '<span class="shsas-toggle-icon" aria-hidden="true"></span><span class="screen-reader-text">Toggle light/dark admin theme</span>',
        'href' => '#',
        'meta' => ['class' => 'shsas-theme-toggle', 'title' => 'Toggle light/dark admin theme'],
    ]);
}
add_action('admin_bar_menu', 'shsas_admin_bar_toggle', 999);

/**
 * @return array<int, array{label: string, url: string, parent: string}>
 */
function shsas_flatten_admin_menu(): array {
    global $menu, $submenu;
    $items = [];

    foreach ((array) $menu as $item) {
        // $menu rows: [0]=label (may include a count badge), [2]=slug,
        // [4]=classes; skip separators.
        if (empty($item[0]) || (isset($item[4]) && strpos((string) $item[4], 'wp-menu-separator') !== false)) continue;
        $label = trim(wp_strip_all_tags((string) $item[0]));
        if ($label === '') continue;
        $slug = (string) ($item[2] ?? '');
        $items[] = ['label' => $label, 'url' => shsas_menu_url($slug), 'parent' => ''];

        foreach ((array) ($submenu[$slug] ?? []) as $sub) {
            $sub_label = trim(wp_strip_all_tags((string) ($sub[0] ?? '')));
            if ($sub_label === '') continue;
            $items[] = ['label' => $sub_label, 'url' => shsas_menu_url((string) ($sub[2] ?? '')), 'parent' => $label];
        }
    }

    return $items;
}

function shsas_menu_url(string $slug): string {
    if ($slug === '') return '';
    // A bare page slug maps to admin.php?page=<slug> (as menu_page_url()
    // does); anything with .php or a query is already a relative admin URL.
    if (strpos($slug, '.php') === false && strpos($slug, '?') === false) {
        return admin_url('admin.php?page=' . $slug);
    }
    return admin_url($slug);
}

/**
 * Full-skin opt-in as a checkbox on Settings > General.
 * WHY not its own admin page: see CLAUDE.md on add_submenu_page() failures here.
 */
function shsas_register_full_skin_setting(): void {
    register_setting('general', 'shsas_full_skin', [
        'type' => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
        'default' => false,
    ]);
    add_settings_field(
        'shsas_full_skin',
        'Admin Skin',
        'shsas_render_full_skin_field',
        'general'
    );
}
add_action('admin_init', 'shsas_register_full_skin_setting');

function shsas_render_full_skin_field(): void {
    ?>
    <label>
        <input type="checkbox" name="shsas_full_skin" value="1" <?php checked(shsas_full_skin_enabled()); ?>>
        Theme the entire admin (bar, menu, every screen) instead of just this ecosystem's own pages
    </label>
    <p class="description">Off by default: the admin bar and sidebar menu stay WordPress's own stock appearance, and only this ecosystem's own plugin pages get the distinctive look — the same scoping model WooCommerce/MailPoet use for themselves. Turn this on to restore the full, everywhere skin instead.</p>
    <?php
}
