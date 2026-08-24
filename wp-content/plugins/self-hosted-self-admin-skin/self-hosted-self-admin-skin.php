<?php
/**
 * Plugin Name: Admin Skin — The Self-Hosted Self
 * Description: A wp-admin-only visual/UX mod — reskins the default WordPress dashboard with a calmer dark/light palette, real accessibility work (focus states, contrast, reduced-motion, larger touch targets), a genuinely mobile-friendly admin menu, and a couple of small "it just works" touches (a Cmd/Ctrl+K command palette, a light/dark toggle). Standalone and portable — works with any theme and any other plugins, never touches the front end at all.
 * Version:     0.38.2
 * Requires PHP: 8.2
 */
if (!defined('ABSPATH')) exit;

// Version history: see this plugin's CHANGELOG.md (and git log).

define('SHSAS_VER', '0.38.2');

define('SHSAS_URL', plugin_dir_url(__FILE__));
define('SHSAS_PATH', plugin_dir_path(__FILE__));

/**
 * Deliberately admin-only — is_admin() covers wp-admin screens
 * (including AJAX-adjacent admin pages) without ever touching a
 * front-end request, so this plugin can never conflict with any
 * theme's own front-end styles regardless of what site it's on.
 */
function shsas_enqueue_assets(): void {
    // 'wp-admin' as a dependency (not just registered/loaded some other
    // way) guarantees WP core's own admin stylesheet prints BEFORE this
    // one — real bug found live: an empty deps array left load order to
    // chance, and core's own .wp-menu-name rule won a cascade tie
    // against this plugin's truncation rule on this exact install.
    // Defense in depth alongside the !important fix in the CSS itself.
    wp_enqueue_style('shsas-admin-skin', SHSAS_URL . 'assets/css/admin-skin.css', ['wp-admin'], SHSAS_VER);
    // Same two fonts as the ecosystem-wide front-end default (own-ur-
    // shit's BHY_Style::FONT_OPTIONS) — Righteous for display, Atkinson
    // Hyperlegible for body — loaded independently here rather than via
    // a class_exists() call into own-ur-shit, matching this plugin's
    // own stated "standalone and portable" scope (it must keep working
    // even if own-ur-shit isn't the active core plugin on some other
    // install). Real gap this closes: the CSS tokens below
    // (--shsas-font/--shsas-font-display) were pointing at these fonts
    // with nothing fetching the actual webfont files — the exact same
    // bug just fixed on the front end (BHY_Style::print_global_css()),
    // just never wired into wp-admin at all in the first place.
    wp_enqueue_style('shsas-fonts', 'https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&family=Atkinson+Hyperlegible:wght@400;700&display=swap', [], SHSAS_VER);
    wp_enqueue_script('shsas-admin-skin', SHSAS_URL . 'assets/js/admin-skin.js', [], SHSAS_VER, true);

    // The command palette needs a flat list of every real admin-menu
    // link WordPress core already built for THIS user (capability-
    // filtered, current site) — reading it straight out of $menu/
    // $submenu (the same globals wp-admin's own menu renderer uses)
    // rather than re-deriving access rules is the only way this stays
    // correct for a role that isn't a full admin, and the only way it
    // stays accurate for whatever mix of plugins happens to be active.
    wp_localize_script('shsas-admin-skin', 'shsasMenu', ['items' => shsas_flatten_admin_menu()]);
}
add_action('admin_enqueue_scripts', 'shsas_enqueue_assets');

/**
 * The admin bar renders on the FRONT END too, for logged-in users —
 * but this plugin only ever enqueued on 'admin_enqueue_scripts', so
 * none of its styling reached it there. Real field report ("admin bar
 * needs style attention on both front and back end"): on the public
 * site the bar kept WordPress's stock grey while the theme around it
 * is warm noir, and the layout bugs measured in wp-admin (a 151px
 * overlap between the left and right groups, "Howdy…" pushed below a
 * 33px-tall bar, third-party items sitting 7px off the shared
 * baseline) were present there identically.
 *
 * Deliberately a SEPARATE, bar-only stylesheet rather than loading all
 * of admin-skin.css publicly — that file styles wp-admin chrome, and
 * dropping it on the front end would bleed into the site's own design.
 * assets/css/admin-bar.css is self-sufficient: every color goes
 * through var(--shsas-*, <fallback>), so it themes correctly on the
 * front end (no token block present) and still defers to the real
 * tokens inside wp-admin.
 */
function shsas_enqueue_admin_bar_assets(): void {
    if (!is_admin_bar_showing()) return;
    wp_enqueue_style('shsas-admin-bar', SHSAS_URL . 'assets/css/admin-bar.css', ['admin-bar'], SHSAS_VER);
}
add_action('wp_enqueue_scripts', 'shsas_enqueue_admin_bar_assets', 20);
add_action('admin_enqueue_scripts', 'shsas_enqueue_admin_bar_assets', 20);

// wp-login.php is NOT an is_admin() screen — its own hook, and
// deliberately CSS-only there (no command palette/menu data makes
// sense on a page with no admin menu yet).
function shsas_enqueue_login_assets(): void {
    wp_enqueue_style('shsas-admin-skin', SHSAS_URL . 'assets/css/admin-skin.css', [], SHSAS_VER);
    wp_enqueue_style('shsas-fonts', 'https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&family=Atkinson+Hyperlegible:wght@400;700&display=swap', [], SHSAS_VER);
}
add_action('login_enqueue_scripts', 'shsas_enqueue_login_assets');

/**
 * Real, high-leverage bug found auditing bh-crm's People screen (Track
 * A of the design audit): own-ur-shit's own shared admin design-token
 * system (BHY_UI::print_design_system_css(), class-ui.php) defines a
 * whole SECOND set of CSS custom properties — --bhy-surface,
 * --bhy-ink, --bhy-border, --bhy-accent, etc. — that bh-crm, bh-contest,
 * and own-ur-shit's own admin screens (Design Suite, Setup Wizard,
 * Reports, the Portal, "Layer 3" components like .bhy-card/.bhy-alert)
 * all reference directly, INCLUDING via inline `style="background:
 * var(--bhy-surface,#fff)"` attributes rendered straight from PHP.
 * Those tokens are hardcoded to WP core's stock LIGHT admin colors with
 * no dark-mode awareness at all — every one of those screens was
 * silently rendering in light mode no matter what this skin did,
 * because nothing here ever redefined the SAME variable names.
 *
 * This is a soft interop bridge, not a hard dependency: it only
 * redefines CSS custom properties by name (never calls any own-ur-shit
 * PHP/class), so this plugin stays exactly as portable as its own
 * doc comment above promises — on a bare WordPress install with no
 * own-ur-shit, these variables are simply unused. Where own-ur-shit
 * (or any plugin using this same, apparently ecosystem-wide --bhy-*
 * naming convention) IS active, every one of its own components
 * re-themes for free, instead of needing an individual CSS override
 * per component the way every other fix in this file's changelog has
 * worked so far.
 *
 * Priority 999 is load-bearing: class-ui.php's own token block is also
 * a plain :root rule with equal specificity, printed via its own
 * admin_head hook at the default priority (10) — later-in-source wins
 * a specificity tie, so this MUST fire after it, not just be present.
 */
function shsas_bridge_bhy_tokens(): void {
    echo '<style id="shsas-bhy-token-bridge">:root{'
        . '--bhy-ink:var(--shsas-text);--bhy-ink-dim:var(--shsas-text-dim);'
        . '--bhy-border:var(--shsas-border);--bhy-surface:var(--shsas-surface);'
        . '--bhy-subtle:var(--shsas-surface-2);--bhy-accent:var(--shsas-accent);'
        // The ink that sits ON a filled accent/neon chip, not next to
        // it. Its absence was a real, measured bug: the bridge mapped
        // every FILL colour but never the foreground meant to sit on
        // one, so own-ur-shit's log-level pills kept the hardcoded
        // `#fff` that is correct against their bare-WordPress fallback
        // (#2271b1, dark) and wrong against the periwinkle this skin
        // bridges in (#8FA6E8, light) — white on light periwinkle
        // measures 2.39:1. --shsas-accent-text already flips per theme
        // for exactly this job (#1a1710 dark / #f5f9ff light), and
        // because every neon in this palette is light in dark mode and
        // deepened in light mode, the same ink is correct on ALL of
        // them — danger and warning chips included, not just accent.
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
 * Real bug caught by verifying live, not by trusting the plan on
 * paper: the depth-aware cross-document navigation transition
 * (admin-skin.css's data-shsas-nav-depth/-direction-scoped keyframes)
 * needs its 'pagereveal' listener (admin-skin.js) registered BEFORE
 * that event fires — but admin-skin.js is enqueued with in_footer=true
 * (correct for everything else it does: the theme toggle, the command
 * palette, the Wallet-stack default all have no reason to block
 * rendering). 'pagereveal' fires very early, essentially as soon as
 * the browser has resolved the page's `@view-transition` opt-in from
 * <head> — confirmed live: by the time footer-loaded admin-skin.js
 * ran, navigation.activation already held the right data, but the
 * listener attached too late to ever see the event that would have
 * used it.
 *
 * Fix: this ONE listener gets its own tiny, synchronous, INLINE script
 * printed as early in <head> as this plugin can manage (priority 1,
 * lower number than every other admin_head hook here), completely
 * separate from the main admin-skin.js file. Everything else this
 * plugin does stays exactly where it was — this is a narrow exception
 * for the one piece of logic that has a genuine "must run before a
 * specific early browser event" constraint, not a wholesale move of
 * admin-skin.js into <head>.
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
 * A real, working light/dark toggle living in the admin bar (visible
 * on every admin screen) rather than a buried settings-page checkbox —
 * the whole point of a system preference is that you change your mind
 * about it in the moment, not that you go find a settings screen to
 * change it. admin-skin.js does the actual toggling (reads/writes
 * data-shsas-theme + localStorage); this just gives it somewhere real
 * to live and a11y-correct markup (a real <button>, not a div with a
 * click handler).
 */
function shsas_admin_bar_toggle($wp_admin_bar): void {
    if (!is_admin()) return;
    // Direct feedback: the Cmd/Ctrl+K command palette existed but
    // nothing on screen told anyone it was there — a keyboard shortcut
    // nobody knows about isn't a feature, it's a secret. A real, visible
    // trigger button makes the "smart" part of this skin discoverable
    // instead of hidden, same a11y-correct <button>-via-add_node pattern
    // as the theme toggle right next to it.
    $wp_admin_bar->add_node([
        'id' => 'shsas-palette-trigger',
        // Empty span, not a unicode glyph — the artwork is a CSS mask
        // (Lucide search icon) so it matches every other icon in the UI
        // rather than being whatever the system font renders for U+26B2.
        'title' => '<span class="shsas-palette-trigger-icon" aria-hidden="true"></span><span class="shsas-palette-trigger-label">Jump to&hellip;</span><kbd class="shsas-palette-trigger-kbd" aria-hidden="true">&#8984;K</kbd>',
        'href' => '#',
        'meta' => ['class' => 'shsas-palette-trigger', 'title' => 'Jump to any admin page (Cmd/Ctrl+K)'],
    ]);
    $wp_admin_bar->add_node([
        'id' => 'shsas-theme-toggle',
        // Empty span for the same reason as the palette trigger above —
        // the sun/moon artwork is a CSS mask keyed off
        // :root[data-shsas-theme], so it swaps with the theme
        // automatically and matches the rest of the icon set.
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
        // Core's own $menu rows: [0]=label (may carry a <span> update-count
        // badge), [2]=slug/url, [4]=css classes — a separator row's [4]
        // contains 'wp-menu-separator' and has no real label, skip it.
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
    // A real top-level page slug (no '.php', no query string) resolves
    // to admin.php?page=<slug> — the same fallback menu_page_url()
    // itself uses internally; anything else (edit.php?post_type=..., a
    // plain *.php core screen) is already a real, relative admin URL.
    if (strpos($slug, '.php') === false && strpos($slug, '?') === false) {
        return admin_url('admin.php?page=' . $slug);
    }
    return admin_url($slug);
}
