<?php
/**
 * Plugin Name: Admin Skin — The Self-Hosted Self
 * Description: A wp-admin-only visual/UX mod — reskins the default WordPress dashboard with a calmer dark/light palette, real accessibility work (focus states, contrast, reduced-motion, larger touch targets), a genuinely mobile-friendly admin menu, and a couple of small "it just works" touches (a Cmd/Ctrl+K command palette, a light/dark toggle). Standalone and portable — works with any theme and any other plugins, never touches the front end at all.
 * Version:     0.6.1
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

// 0.6.1 — Same audit, one more real bug in the block-editor chrome:
// @wordpress/components' own <Button> (used everywhere in this
// sidebar/chrome — "Set featured image" being the first one found live)
// ships a near-black default text color (#1e1e1e) with no background of
// its own, so it was invisible-by-degrees against the now-dark sidebar
// from 0.6.0 — the same failure mode as the classic-wp-admin
// .form-table th bug, just in Gutenberg's own component library.

// 0.6.0 — Explicit direction: focus on fundamentals across custom
// plugins, WooCommerce, and Etch compatibility, not more one-off
// polish. The single biggest gap found: the block editor's OWN chrome
// (Settings sidebar, block inserter, modals/popovers) is a completely
// separate @wordpress/components UI from classic wp-admin, with its
// own class vocabulary — nothing built so far touched it at all, so it
// rendered as a stark white panel next to an otherwise fully dark
// screen on every single post/page edit. This is not a cosmetic
// afterthought for this specific ask: confirmed live that a peer
// plugin's own PluginDocumentSettingPanel ("Supporter access", from
// this ecosystem's monetization plugin) was sitting unstyled in that
// white sidebar — EVERY custom plugin that adds its own block-editor
// panel was hitting this same gap — and per
// plugins/ETCH-COMPATIBILITY-NOTES.md, this exact chrome (not the
// content canvas) is Etch's own operating surface, so this is also the
// actual "Etch compatibility" surface for a wp-admin skin (Etch's
// content-level compatibility is a front-end/data-format question,
// already solved for unrelated reasons per that doc — nothing for a
// visual skin to do there). Deliberately scoped to the CHROME only —
// .editor-styles-wrapper and its contents are untouched, preserving
// the theme's own editor-style.css WYSIWYG guarantee (front-end tokens
// in the canvas, on purpose, so what an editor sees while writing
// matches what a visitor sees). Also covers WooCommerce's newer
// Product Editor, which is block-editor-based (confirmed via the
// woocommerce-feature-enabled-product-block-editor body class seen
// auditing WooCommerce Settings earlier) — same chrome, same fix.
// NOT yet click-through-verified against a real product edit screen
// or a real peer-plugin sidebar panel beyond the one already found —
// worth a wider pass across bh-contest/bh-courses/bh-crm's own
// PluginDocumentSettingPanel registrations next.

// 0.5.0 — Direct feedback after the bug-fix pass: "it's not magical."
// Fixing contrast/layout bugs gets this to "not broken," which isn't
// the same thing as the original brief (JARVIS vibes, "it just works,"
// genuinely premium out of the box). Asked what "magical" meant
// concretely; answer was "all of the above" — motion/feel, the skin
// feeling smart/alive rather than static, and real visual
// distinctiveness instead of "dark WordPress with a blue accent." Also
// asked to use the ecosystem's own front-end (bh-courses' courses.css,
// bh-contest) as the reference rather than inventing a new visual
// language from scratch — real design language already exists there
// (Streamline-Moderne-influenced: tracked-out uppercase kickers, a
// thin gradient-fade accent rule instead of a flat bar, and a
// translateY(-4px) card-hover-lift with an accent-tinted glow shadow,
// not a plain gray shadow). Changes, all borrowing that same
// vocabulary rather than a generic "add some transitions" pass:
// - The h1 accent underline is now a gradient fade (matches bh-courses'
//   .bhc-archive-rule exactly in spirit) and draws in on page load
//   instead of just appearing.
// - Postboxes/.card get the front-end's hover treatment adapted for an
//   admin context: accent-tinted glow shadow + border tint on hover,
//   deliberately WITHOUT the front-end's translateY lift — a postbox is
//   a container full of its OWN separate buttons/inputs/links (unlike a
//   single-target course card), so lifting the whole box on any hover
//   inside it would jitter constantly while reading. Shadow-as-signal
//   only, consistent with this file's existing Eames stance.
// - Buttons (not just .button-primary, which already had this) now get
//   a real hover lift + press-down feedback.
// - The sidebar's current-item icon nudges 1px toward the label on
//   hover — the front-end's "image scales up on card hover" cue,
//   translated to a scale appropriate for a 30px nav icon.
// - The command palette existed since early this session but nothing
//   on screen said so — a real, visible, labeled trigger ("Jump to...
//   ⌘K") now lives in the admin bar next to the theme toggle, not just
//   a keyboard shortcut nobody could discover. This is the actual
//   answer to "smart & alive": the palette itself already does live
//   fuzzy search over the real menu, it just needed to be findable.
// - The theme toggle's sun/moon icon now does a real flip transition
//   (rotate out, swap glyph at the midpoint, rotate back in) instead of
//   instantly replacing the character.
// - The main content area does one quiet settle-in (fade + 4px rise) on
//   page load — never the sidebar/admin bar, which should read as
//   permanent structure, not something that "arrives."
// - Every animation added here has a prefers-reduced-motion: reduce
//   fallback, matching this file's existing --shsas-speed:0 convention.
// NOT runtime-verified beyond localhost screenshots + computed-style
// checks in this session's browser tool — genuinely worth a real
// click-through pass logged in normally before calling "magical" done.

// 0.4.13 — One more real bug from the same audit pass, scrolling
// further down WooCommerce Settings: Select2 ("enhanced select" —
// country/state, selling/shipping locations, product categories, used
// by WooCommerce and potentially any peer plugin following the same
// wc-enhanced-select convention) hides the real <select> entirely and
// renders its own widget/dropdown-panel markup, so the plain
// `.wp-admin select` rule never reached it — a fully white dropdown
// sitting next to otherwise-correctly-dark form fields. Covers the
// closed control, the open results panel (appended to <body>, outside
// the field's own DOM subtree), and the in-panel search box.

// 0.4.12 — The most serious finding of this whole systematic pass:
// `.form-table th`/label — the actual field labels on EVERY classic
// WordPress settings screen (General, Writing, Reading, Discussion,
// WooCommerce's tabs, any peer plugin's Settings-API page) — had no
// color rule at all in this file, so every field label in the entire
// ecosystem sat on WP core's own near-black default, unreadable
// against this skin's dark surfaces. Confirmed via computed style on
// both General Settings and WooCommerce Settings ("Site Title",
// "Address line 1", etc. all measured rgb(29,35,39) — WP core's
// #1d2327 — regardless of screen). This is very likely the single
// biggest contributor to "usability is garbage" of anything found in
// this pass, and 0.4.10's list-table header bug was the same failure
// mode in a different core component, not a coincidence — genuinely
// worth grepping for one MORE time before calling this pass done: any
// other core text element whose color was simply never set.

// 0.4.11 — Systematic pass continued, three more real bugs found by
// actually walking Tools, WooCommerce Settings, and re-checking Woo's
// embedded-admin pages specifically (a materially different rendering
// path than plain wp-admin screens — worth checking on its own since
// this ecosystem leans on bh-monetization-woo):
// (1) WP core's plain `.card` class (Tools screen's "Categories and
// Tags Converter", used on a few settings screens too) is a different
// class than `.postbox`/`.bhy-card` and had zero coverage — pure white
// box, same pattern as 0.4.9/0.4.10's fixes.
// (2) WooCommerce's own admin CSS sets a hardcoded light background on
// #wpcontent/#wpbody-content/#mainform on its embedded settings pages,
// beating this file's original (non-!important) global ground rule —
// the whole WooCommerce Settings screen rendered light gray with
// near-black form inputs sitting on it (the inputs themselves were
// actually fine — dark surface, correct — the PAGE around them was the
// bug, easy to misread as "black redacted boxes" at a glance).
// (3) The global body background rule itself never had !important,
// which is almost certainly the same root cause for every "just this
// one screen is wrong" report — added it, matching the established
// pattern everywhere else in this file.

// 0.4.10 — Continuing the systematic pass from 0.4.9. Two more real,
// confirmed bugs found by actually walking core screens (Dashboard,
// Posts list, post editor, Settings, Media Library, Plugins) and
// checking computed styles rather than trusting a screenshot:
// (1) EVERY .wp-list-table column header (Posts, Plugins, Media,
// Users — every list screen in wp-admin) had the right dark
// background but near-black (#2c3338, WP core's own default) text on
// top of it — a real, serious contrast failure, not just an aesthetic
// one, caused by core's own thead color rule beating ours in the
// cascade (no !important on the original rule). This was very likely
// invisible in most of the earlier per-screen spot checks because the
// header ROW itself still looked "dark and correct" at a glance; the
// text on it was just unreadable.
// (2) The Media Library's filter/view-switcher toolbar (.wp-filter) —
// a different core element than any table — ships a hardcoded white
// background untouched by anything in this file, same "orphaned white
// box" pattern as 0.4.9's welcome-panel fix.
// Also confirmed, live, with this skin's stylesheet fully disabled via
// devtools: the earlier "Screen Options overlapping Howdy" collision
// on Dashboard/Posts is a pre-existing WordPress core float-wrap quirk
// at certain mid-range viewport widths (reproduces identically with
// this plugin's CSS off) — not introduced by this skin, not touched
// here, noted for the record rather than silently dropped.

// 0.4.9 — Systematic pass (direct feedback: "like the default is almost
// better yo" — a serious signal after many rounds of "still broken").
// Root cause pattern identified: this skin themes WP core's own
// top-level containers (.postbox, notices, etc.) but several core
// widgets nest a SECOND, separately-styled inner block that ships its
// own hardcoded white background, which nothing here was touching —
// each one reads as a stray white/light box against the dark chrome
// around it. First confirmed instance: the Dashboard welcome panel's
// .welcome-panel-column-container (the three-column "Author content /
// Start Customizing / Discover" block) was pure WP-core white with no
// override at all. Fixed, and auditing the rest of core's admin
// surfaces for the same pattern rather than patching one screen at a
// time.

// 0.4.8 — Direct feedback: "weird dark boxy background". Found live: WP
// core ships its OWN hardcoded sidebar-hover indicator — an inset 4px
// solid near-black (#191b1f) left bar — completely untouched by any of
// this skin's tokens (it's not something this plugin's CSS ever set,
// core just always paints it on `li.menu-top:hover`). Against this
// skin's surfaces, especially in light mode, that read as a stray dark
// smudge rather than a themed accent. Replaced with an accent-colored
// inset bar so the hover state stays legible and on-brand in both
// themes (see #adminmenu li.menu-top:hover > a.menu-top).

// 0.4.7 — 0.4.6's custom-icon fix covered <img>-based icons but missed
// a real THIRD rendering mode, found by inspecting the actual live DOM
// (own-ur-shit's own menu item uses it): add_menu_page() passed a
// base64 SVG data URI renders as neither a font glyph nor a real
// <img> — WP core puts it on div.wp-menu-image itself as a CSS
// background-image (class="wp-menu-image svg"). Confirmed by directly
// reading the rendered element, not guessed. filter still applies to
// an element's background, so the same brightness/invert + per-item
// hue-rotate approach now covers this path too, with its own selector
// (CSS has no single selector meaning "however this icon happens to
// be implemented" — font glyph, <img>, or background-image all need
// their own rule). Also, on repeated request: sidebar icons sized up
// again (26px -> 30px container, 24px -> 28px glyph).

// 0.4.6 — Three more direct fixes:
// (1) The page h1's color/background pair already measured 15-16:1
// (well past WCAG AA/AAA) — "not enough contrast" was a real
// perceptual-weight problem, not a color one: font-weight 500 reads
// thin/quiet at 27px regardless of how much luminance separation the
// numbers say exists. True bold (700) now.
// (2) The admin bar's "W" logo (far top-left) — WP core hardcodes it
// to a fixed blue independent of the rest of the toolbar's icon
// color, so it never moved with either theme and read inconsistently.
// Matched to this skin's own accent/focus tokens like everything else.
// (3) Custom SVG/PNG menu icons — a real gap in every earlier icon
// pass: add_menu_page()'s own $icon_url argument renders as a real
// <img>, not a font glyph, so none of the :before dashicon color rules
// ever touched it — most ship as a plain dark silhouette meant for
// core's own light-gray treatment, nearly invisible against this
// skin's dark sidebar (the same class of bug already fixed on the
// wp-login.php logo). brightness(0) invert(1) forces a safe, visible
// white baseline first, then a per-item hue-rotate approximates the
// same rainbow differentiation the font-icon items get — not a pixel-
// exact hue match (a raster image can't be retargeted to an exact
// color the way a font glyph's `color` can), but real, distinct-per-
// item color instead of one flat white icon.

// 0.4.4 — Direct request: every sidebar icon its own distinct, vivid
// color instead of one uniform dim gray — real wayfinding (quick
// visual scanning by color), not decoration. Cycles through the full
// seven-hue set (six neons + the new blue) via :nth-of-type(7n+N) so
// it works for however many top-level items a given install actually
// has, rather than hardcoding specific WordPress menu items this skin
// has no way to know about ahead of time (any peer plugin can add its
// own top-level page). Each item keeps its own hue in every state —
// default, hover, AND current (a brightness/saturation lift signals
// the state change instead of swapping to the single accent color,
// which would have made every item look identical the moment it's
// hovered or active, defeating the whole point of per-item color).

// 0.4.3 — Real bug, direct feedback ("hover is awful"), caught by
// actually hovering a non-current sidebar item and looking (not
// guessed): WP core renders two genuinely different things under the
// same .wp-submenu class. The CURRENT top-level item's submenu sits
// INLINE, in normal flow under its own row — transparent background is
// correct there, it's just an indented list already inside the dark
// sidebar. Hovering any OTHER (non-current) item instead pops up a
// real absolutely-positioned FLYOVER on top of the page content — and
// this skin's transparent background rule was applying to that too,
// letting the actual page content underneath show straight through
// it: a washed-out, illegible overlap, not a submenu. Split into two
// real rules — li.wp-has-current-submenu keeps the transparent inline
// treatment, every other li's flyover now gets a real opaque surface,
// border, and shadow (the same popover treatment already used for
// folded/icon-only mode, now applied here too instead of being
// folded-mode-only).

// 0.4.2 — Real contrast audit, on request, not just a visual check:
// computed actual WCAG relative-luminance ratios (the real formula,
// not eyeballed) for every text/background pair this skin controls.
// Found and fixed two genuine failures: --shsas-text-faint was 4.48:1
// in dark mode and 3.55:1 in light mode — both below the 4.5:1 AA
// minimum for normal text (dark mode missed by a hair, light mode
// failed outright). New values, found by actually searching for the
// nearest color clearing 4.5:1 rather than guessing: #7b7e83 (dark,
// 4.56:1) and #6f6b66 (light, 4.52:1). Also found and fixed a real
// token gap while auditing: the prefers-color-scheme:light fallback
// block was missing its own --shsas-neon-blue definition entirely, so
// a visitor using OS-level light mode (never touching the manual
// toggle) would have silently gotten the dark-mode-tuned, brighter
// blue instead of the properly contrast-adjusted light-mode one.
// Also, on request: bigger/clearer badges — WP core's own admin-bar
// count bubbles (comments/updates) are a ~16px pill with 9px text,
// genuinely hard to read at a glance; now 20px/12px-bold with a real
// min-width so 2-digit counts don't squeeze back down. Same treatment
// applied to this ecosystem's own shared .bhy-badge status pills for
// one consistent scale, not two.

// 0.4.1 — Several direct, related requests in one pass:
// (1) 0.4.0's icon-size bump reconsidered with real judgment after
// being asked to think about the details, not just apply one blanket
// rule: a flat font-size bump on every .dashicons would have also
// inflated small, deliberately tight utility controls (a postbox's
// collapse arrow, a notice's dismiss "X", a column-sort arrow) that
// are correctly sized for their fixed containers today — blowing
// those up overflows/misaligns them, the opposite of the
// accessibility goal. Narrowed to where bigger glyphs genuinely help
// and have room to grow: primary nav (sidebar/admin bar, already
// sized) and icon-bearing buttons/toolbars.
// (2) Primary accent switched from pink to a real electric blue
// (--shsas-neon-blue, new token) on request — pink stays in the
// palette for danger/error only, the correct semantic (blue reads as
// "informational," not "something's wrong," in every established UI
// convention), so this is additive, not a straight swap that would
// have left errors and links using the same hue.
// (3) Colorblindness/low-vision consideration, on request: nothing in
// this skin conveys meaning through color alone — every notice type
// pairs its border color with WP core's own real text/icon content,
// the focus ring is a real outline SHAPE (not just a color change),
// and the six-neon set spans enough luminance/hue difference to stay
// distinguishable under the common deficiencies, not just to
// full-color vision.
// (4) "Cool old guy friendly" — plain-language ask for genuinely
// bigger, easier text and bigger tap targets, especially on mobile:
// running text (p/li/td/.description) bumped to 14px/1.6 line-height
// site-wide; the 782px mobile breakpoint now makes text/controls
// BIGGER, not smaller (WP core's own default actually shrinks table-
// header text at this width) — form inputs specifically held at 16px
// on mobile so iOS Safari's auto-zoom-on-focus never triggers, a
// genuinely disorienting default for exactly this audience.

// 0.4.0 — Bigger, clearer icons everywhere on request: WP core ships
// dashicons at a flat 20px across the whole admin (sidebar, admin bar,
// buttons, notices, list-table row actions, metaboxes). One real
// global rule (.dashicons, .dashicons-before:before at 22px) catches
// genuinely all of them at once, with the sidebar/admin-bar glyphs
// getting their own slightly more specific override (24px/22px) since
// those two already had their own resized container boxes to keep the
// larger glyph vertically centered rather than clipped.

// 0.3.5 — Real critique pass on the Posts list-table screen, after
// being asked to check GUIs across the admin critically rather than
// just Dashboard: every row-title link inherited the global accent
// link color, so an entire table of posts read as a solid block of
// pink — monotonous, and it drowned out the actual "what's hovered/
// selected" hierarchy the color was supposed to carry. Row content
// now stays on the neutral text color by default (same as any other
// body copy), accent only on hover/focus — scoped to table body
// content, the global link-accent rule is untouched everywhere else
// (nav, notices, real navigational links). Verified in both dark and
// light mode, not just one.

// 0.3.4 — Real regression caught immediately after 0.3.3 shipped (not
// left for later): swapping WP core's select-arrow SVG for a
// currentColor-matched one wasn't enough on its own — core's rule
// also carries background-position/-size tuned to ITS specific
// image's proportions, and without re-declaring those too, the new
// image rendered stretched across the whole control (looked like a
// strikethrough through the select's own text) instead of a small
// corner arrow. Fixed by setting background-image/-repeat/-position/
// -size and padding-right together as one real unit, not the image
// swapped in isolation. Confirmed on the Posts screen's "Bulk
// actions"/"All dates"/"All Categories" selects, both themes.

// 0.3.3 — Asked to make sure default WordPress GUI elements are
// accounted for, not just the sidebar/palette already covered: added
// real coverage for Screen Options and Help (the two tabs top-right of
// every admin screen, and the panels they open — entirely unstyled by
// default, stark white against this skin otherwise), native checkbox/
// radio theming (accent-color, the real modern low-code way to theme
// a native control's checked fill), and the admin bar's notification
// surfaces specifically (comments/updates/"New" bubble counts and
// their real dropdown panels — a separate DOM tree from the plain
// top-menu links already covered, so they needed their own rules).

// 0.3.2 — 0.3.1 fixed the WRONG cause: the truncation rules were
// correct all along, but WP core's own #adminmenu div.wp-menu-name
// rule ships a baked-in `padding: 8px 8px 8px 36px` (core reserves
// 36px on the left for its own absolutely-positioned icon — a totally
// different layout model than this skin's flex icon+gap). Never
// resetting it meant the icon indent was applied TWICE — this skin's
// own 20px icon + 10px gap, AND core's 36px padding baked into the
// text element itself — so even "Dashboard" truncated on a column
// with plenty of real room. Reset to `padding: 0 !important` so this
// skin's own flex spacing is the ONLY spacing in effect. Also dropped
// the small geometric "diamond" marker on the sidebar's current item
// and the earlier conic-gradient "art glass" mark on every h1 —
// direct, repeated feedback: no small gem/jewel shapes anywhere in
// this skin. The h1 now gets one plain, thin, single-hue accent
// underline instead.

// 0.3.1 — Real root cause found via live computed-geometry inspection
// (not another screenshot guess), after direct feedback that the whole
// skin "looks weird": 0.3.0's ellipsis fix for sidebar labels was
// itself losing a cascade fight against WP core's own .wp-menu-name
// styling (core intentionally supports wrapping for long custom-post-
// type labels) — EVERY label was still wrapping to 2 lines, including
// short ones like "Posts", confirmed by measuring a 33.5px-tall (2-
// line) box against 90px of genuinely available width. Fixed two ways:
// (1) !important on the truncation properties in admin-skin.css,
// matching this file's own established pattern for beating wp-admin's
// baked-in styles; (2) wp_enqueue_style() now declares 'wp-admin' as a
// real dependency instead of an empty array, guaranteeing this
// stylesheet prints after core's own regardless of plugin load order —
// defense in depth, not just the !important alone. Confirmed live:
// .wp-menu-name for "Posts"/"Dashboard"/"The Self-Hosted Self" all now
// report single-line box heights with a genuine ellipsis truncation on
// the long label, not a 2-line wrap.

// 0.3.0 — Real design pass, not another palette tweak: fixed a
// genuine layout bug caught live (sidebar labels were breaking
// mid-word — "Dashboard" -> "Dashb"/"oard" — because .wp-menu-name
// had no overflow handling once the flex layout gave it a
// content-driven width; now a clean single-line ellipsis truncation,
// nothing wraps mid-word). Then a real compositional pass, not just
// more color: the six-neon rainbow used to repeat as a bar under
// every h1 — now it's a single small faceted "art-glass" mark (a
// conic-gradient hexad, one per screen, at the front of the title)
// echoed by a small diamond marker on the sidebar's current item, so
// the whole skin shares one geometric "you are here" language instead
// of two unrelated idioms. Added real horizontal rhythm (a full-width
// divider under every h1/h2 — Wright's own horizontal emphasis,
// translated) and shifted postboxes toward shadow-as-the-signal
// rather than border-as-the-signal (Eames: the material should read
// as raised on its own, a border on top of a shadow is redundant
// weight) — border kept, but faint, only as a floor for displays with
// no shadow rendering. Confirmed visually on localhost, logged in,
// both the mid-word-wrap fix and the new title mark/sidebar marker.

// 0.2.0 — Real palette rework, direct feedback after the first pass:
// a genuinely "themeless" grayscale neutral ramp (warm/cool alternated
// step to step for real depth, not flat gray) instead of the first
// pass's single warm terracotta ground, with a full six-hue neon
// rainbow (pink/orange/yellow/lime/cyan/violet, ~60° apart) doing
// every accent job instead of one overworked color — links, focus
// rings, notice types, the h1 underline (now a real rainbow sweep, the
// one deliberately loud flourish on every screen). Neons are used only
// for small bounded elements (borders, underlines, icons, badges,
// focus rings) — body text stays on the neutral ramp throughout, so
// contrast never suffers. Confirmed visually on localhost's login
// screen (dark mode) — the sidebar/command-palette/toggle still need a
// real logged-in click-through, not yet done this pass.

// 0.1.0 — First pass. Pure wp-admin CSS/JS, zero dependency on any
// other plugin or theme in this ecosystem (or anywhere else) — this is
// deliberately a portable "WordPress mod," not something coupled to
// The Self-Hosted Self's own design tokens, so it behaves identically
// on a bare WordPress install.

define('SHSAS_VER', '0.6.1');
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

// wp-login.php is NOT an is_admin() screen — its own hook, and
// deliberately CSS-only there (no command palette/menu data makes
// sense on a page with no admin menu yet).
function shsas_enqueue_login_assets(): void {
    wp_enqueue_style('shsas-admin-skin', SHSAS_URL . 'assets/css/admin-skin.css', [], SHSAS_VER);
}
add_action('login_enqueue_scripts', 'shsas_enqueue_login_assets');

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
        'title' => '<span class="shsas-palette-trigger-icon" aria-hidden="true">&#9906;</span><span class="shsas-palette-trigger-label">Jump to&hellip;</span><kbd class="shsas-palette-trigger-kbd" aria-hidden="true">&#8984;K</kbd>',
        'href' => '#',
        'meta' => ['class' => 'shsas-palette-trigger', 'title' => 'Jump to any admin page (Cmd/Ctrl+K)'],
    ]);
    $wp_admin_bar->add_node([
        'id' => 'shsas-theme-toggle',
        'title' => '<span class="shsas-toggle-icon" aria-hidden="true">&#9788;</span><span class="screen-reader-text">Toggle light/dark admin theme</span>',
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
