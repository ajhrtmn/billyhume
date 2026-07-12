<?php
if (!defined('ABSPATH')) exit;

// OUS_VER 3.4.37 — the 3.4.36 "FINAL ARCHITECTURE" tree still required
// .bhel-topbar's raw Surface/Slot <select> pair (plus a hidden Context ID
// spinner and standing Save/Save-as-Prefab buttons) to pick which single
// surface/slot the tree even showed — that gap is closed entirely inside
// assets/js/element-builder.js (every registered surface+slot is now a
// real, permanent tree node, fetched automatically at boot). This file's
// only change is reparentWidgets()'s own comment, updated to describe
// the now much-smaller .bhel-topbar (status + one global "Save all
// changes" button) it still relocates unchanged — see that function's
// comment below and element-builder.js's own docblock for the real
// mechanics.
//
// OUS_VER 3.4.36 — DESIGN-SUITE-UNIFICATION-PLAN.md "FINAL ARCHITECTURE"
// build: the three parallel left-rail sections ("Global Styles" rail
// buttons, a standing "Library" mount, a standing "Pages" mount) built
// through 3.4.35 are COLLAPSED into exactly one — see render_left_rail()'s
// own updated comment. render_controls() now shows every Global Styles
// token group stacked/always-visible (the old per-group rail toggle is
// gone); the whole #bhy-controls-panel it lives in now toggles against
// #bhy-widgets-inspector-mount as ONE unit, keyed off which kind of node
// is selected in the one tree (a 'bhel:selection' CustomEvent element-
// builder.js fires — see render_script()'s own comment for the exact
// coordination). BH_Element_Builder's own .bhel-canvas (now the single
// recursive tree, not a flat card list) moved from the center canvas
// column into the left rail's new tree mount; the center canvas column is
// now just the site-wide style-preview iframes. See class-element.php
// and assets/js/element-builder.js's own updated docblocks for the
// server/client halves of this same pass.
//
// OUS_VER 3.4.35 — two fixes, both in render_shell()/render_controls()/
// render_script() below: (1) the widgets canvas/inspector mount divs
// dropped their "display:none;" default, and render_script()'s rail-
// click handler no longer toggles whole-pane visibility at all — canvas
// and inspector are simply always in the DOM and always shown now,
// closing the bug where a first-time user saw an empty canvas until
// clicking into Library/Pages; (2) render_controls() (the Global Styles
// token panel) now uses <h3> section headers styled by the same rule as
// the widget inspector's ".bhel-inspector h3" (see the new ".bhy-
// controls h3" CSS rule in render_script(), copied verbatim from
// element-builder.css rather than re-invented), and wraps the less-
// common colors + the 8 category swatches in collapsible "bhel-style-
// group" <details> disclosures — the exact class element-builder.js's
// renderStyleAdvancedSection() already uses for its own collapsible
// groups. See own-ur-shit.php's top-of-file changelog for the full note.
//
// OUS_VER 3.4.34 — the "Pages" rail note (render_left_rail()/
// render_script() below) is updated in place, no other change in this
// file: the placement list it discloses is now a real parent/child tree
// (class-element.php's parent_placement_id seam + element-builder.js's
// client-side tree build), not the flat list the old note described.
//
// OUS_VER 3.4.33 - visual/UX polish pass on the 3.4.32 unified shell:
// left-rail rows now use consistent spacing/hover/focus transitions and
// a left accent bar + tint for the selected state, rail headings and
// inspector h2 groups gained clearer typographic hierarchy, and the
// canvas/inspector borders/radii were switched to the shared --bhy-*
// tokens (own-ur-shit/includes/class-ui.php) instead of hardcoded hex -
// no structural, data-flow, or REST changes.
//
// OUS_VER 3.4.32 — DESIGN-SUITE-UNIFICATION-PLAN.md "Interaction-model
// spec" build: the 3.4.30 top-of-page tab switcher ("Site Styles" /
// "Widgets & Elements" as two whole panels) is GONE — per the user's
// explicit "I need the widgets and elements and site styles to all be
// one fucking thing," there is no page-level mode switch anymore.
// render_shell() now builds ONE three-column layout (left rail / canvas
// / inspector). The left rail has a "Global Styles" group (this file's
// own token-group fields, now wrapped in named divs so a rail click can
// show just one group at a time) plus "Library" and "Pages" mount
// points that render_script() reparents BH_Element_Builder's existing
// palette/canvas/inspector DOM nodes into at runtime — the SAME nodes
// built by the SAME unchanged element-builder.js, physically relocated
// into this page's shared columns rather than living in their own
// separate panel. See render_left_rail()/render_script() below for the
// mechanics. enqueue_media()/enqueue_widgets_assets() are unchanged.
//
// OUS_VER 3.4.25 — DESIGN-SUITE-UNIFICATION-PLAN.md Phase 1: add_menu()
// now registers 'bh-style' as a submenu of the new 'bh-design' top-level
// menu (was 'own-ur-shit'), with capability 'bhcore_design_site' (was
// 'manage_options'); enqueue_media() widened to also match the
// 'bh-design' hook. Slug/callback/render logic unchanged.

/**
 * The actual "Storybook-patterned" UI: a sidebar listing every
 * registered surface (grouped by whichever plugin registered it), a
 * live preview canvas showing the selected one, and one shared controls
 * panel — colors, fonts, spacing, theme presets — that updates whatever
 * surface is currently visible in real time as you edit.
 *
 * Not real Storybook (that's a Node build tool with its own dev server
 * — flatly incompatible with shared hosting/no-CLI/no-persistent-Node),
 * but the same interaction model, implemented in plain PHP+JS.
 *
 * A consuming plugin registers a surface entirely from its own
 * bootstrap — this file never needs to know bh-contest or bh-streaming
 * exist:
 *
 *     add_filter('bhy_style_surfaces', function ($surfaces) {
 *         $surfaces['bh-contest-player'] = [
 *             'group' => 'Contest',
 *             'label' => 'Player',
 *             'render' => function () {
 *                 return [
 *                     'css_url' => BH_URL . 'assets/css/player.css',
 *                     'html' => '<div class="bh-container">...</div>',
 *                 ];
 *             },
 *         ];
 *         return $surfaces;
 *     });
 *
 * All surfaces share the same global tokens — this isn't per-surface
 * theming, it's "how does one theme look across every part of the
 * product," which is the actual point of a shared design-token system.
 */
class BHY_Gallery {
    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_bhy_save_settings', [self::class, 'save']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_media']);
        // 3.4.30 (still true as of 3.4.32's tab-removal pass) — this page
        // also mounts BH_Element_Builder::render_shell() (now reparented
        // into the unified rail/canvas/inspector, not a separate tab —
        // see render_shell()'s own comment), so its JS/CSS need to load
        // here too. Reuses BH_Element_Builder::enqueue_assets() verbatim —
        // no duplicated enqueue logic, this class just decides WHEN it
        // fires (same hook match as enqueue_media() below).
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_widgets_assets']);
    }

    public static function enqueue_media($hook) {
        // Widened (DESIGN-SUITE-UNIFICATION-PLAN.md Phase 1) to also match
        // the 'bh-design' top-level hook — BH_Design_Suite::add_menu()
        // reuses this same render() callback as the Design Suite landing
        // page, under a different slug/hook, so the media picker needs to
        // load there too, not just on the standalone 'bh-style' submenu.
        if (strpos($hook, 'bh-style') === false && strpos($hook, 'bh-design') === false) return;
        wp_enqueue_media();

        // 3.4.49 follow-up — AJ's own ask: font <option>s should preview
        // in their real typeface. BHY_UI::font_field()'s <option> tags
        // now carry an inline font-family per option (class-ui.php), but
        // that's cosmetically useless without the actual webfont files
        // loaded on THIS page — previously this stylesheet was only ever
        // enqueued INSIDE the canvas iframes (preview_doc(), line ~436),
        // never on the real admin page the <select> itself lives on.
        $font_url = class_exists('BHY_Style') ? BHY_Style::preview_all_fonts_url() : '';
        if ($font_url) wp_enqueue_style('bhy-font-preview', $font_url, [], null);
    }

    // 3.4.30, unchanged in shape by 3.4.32's tab-removal — render_shell()
    // mounts BH_Element_Builder::render_shell() inline on THIS page (as a
    // hidden source the unified rail/canvas/inspector reparents from —
    // see render_shell()'s own comment), so its JS/CSS (assets/js/element-
    // builder.js, assets/css/element-builder.css) and localized REST
    // config still need to load on the same hook as everything else here.
    // class_exists() guard is defensive only — BH_Element_Builder is
    // always required by own-ur-shit.php before this fires.
    public static function enqueue_widgets_assets($hook) {
        if (strpos($hook, 'bh-style') === false && strpos($hook, 'bh-design') === false) return;
        if (!class_exists('BH_Element_Builder')) return;
        BH_Element_Builder::enqueue_assets();
    }

    // DESIGN-SUITE-UNIFICATION-PLAN.md Phase 1 — relocated from a
    // submenu of 'own-ur-shit' to a submenu of the new top-level
    // 'bh-design' ("Design Suite") menu. Slug ('bh-style') and callback
    // are UNCHANGED, so every existing admin.php?page=bh-style deep link
    // keeps working. Capability changed from 'manage_options' to the new
    // 'bhcore_design_site' (class-roles.php, granted to administrator +
    // editor) so a non-admin employee can actually reach this page, not
    // just admins — the whole point of this phase's capability work.
    //
    // 3.4.30 — menu label relabeled "Designer" (was "Site Styles") since
    // this single page is now the whole Design Suite (tokens + widgets),
    // not just the style-token half of it. Slug stays 'bh-style'
    // deliberately (changing it is unnecessary risk for zero benefit —
    // every deep link, bookmark, and cross-reference in this codebase
    // already points at bh-style) — same reasoning §1.4's own slug-
    // stability rule already established for this file.
    //
    // OUS_VER 3.4.31 — real duplication fix (see DESIGN-SUITE-UNIFICATION-
    // PLAN.md's changelog note). Until now this call registered 'bh-style'
    // as a SECOND, independently-visible submenu under 'bh-design',
    // alongside BH_Design_Suite::add_menu()'s own top-level entry AND its
    // relabeled first-submenu (both slug 'bh-design') — three sidebar rows
    // all landing on the byte-identical output of this same render()
    // method, which is exactly the accidental-duplication shape flagged by
    // the QA walkthrough. The parent argument is now null instead of
    // 'bh-design': the exact same "hidden page, reachable by direct link
    // only" pattern class-studio.php's add_menu() already established for
    // 'bh-studio' in this codebase. 'bh-style' keeps working as a URL
    // (BHY_Gallery::save()'s redirect above still targets page=bh-style,
    // and any existing bookmark/deep link is unaffected), it just no
    // longer shows up as its own sidebar row next to "Design Suite" — the
    // top-level 'bh-design' entry (BH_Design_Suite::add_menu(), whose
    // callback is this exact same ['BHY_Gallery', 'render']) is now the
    // ONE visible destination.
    public static function add_menu() {
        $hook = add_submenu_page(null, 'Designer', 'Designer', 'bhcore_design_site', 'bh-style', [self::class, 'render']);
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log_throttled('info', 'style_gallery_menu', 60,
                'add_submenu_page() for Designer (bh-style, hidden/null parent — reachable by direct link only, see this method\'s own comment) returned: ' . ($hook === false ? 'FALSE (registration failed)' : "'$hook'"),
                ['hook_suffix' => $hook], 'BHY_Gallery::add_menu()'
            );
        }
    }

    /* ---------- saving (unchanged shape from the original settings page) ---------- */

    public static function save() {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bhy_save_settings');

        $data = [];
        $data['brand_part1'] = sanitize_text_field($_POST['brand_part1'] ?? BHY_Style::DEFAULTS['brand_part1']);
        $data['brand_part2'] = sanitize_text_field($_POST['brand_part2'] ?? BHY_Style::DEFAULTS['brand_part2']);
        $data['brand_logo_id'] = isset($_POST['brand_logo_id']) ? (int) $_POST['brand_logo_id'] : 0;
        foreach (BHY_Style::DEFAULTS as $key => $default) {
            if (strpos($key, 'color_') !== 0 && strpos($key, 'cat_color_') !== 0) continue;
            $val = isset($_POST[$key]) ? sanitize_text_field($_POST[$key]) : $default;
            $data[$key] = BHY_Style::safe_color($val);
        }
        foreach (['font_display', 'font_body'] as $key) {
            $picked = sanitize_text_field($_POST[$key] ?? BHY_Style::DEFAULTS[$key]);
            $data[$key] = (array_key_exists($picked, BHY_Style::FONT_OPTIONS) || $picked === 'Custom') ? $picked : BHY_Style::DEFAULTS[$key];
            $data[$key . '_custom'] = sanitize_text_field($_POST[$key . '_custom'] ?? '');
        }
        $data['font_scale']  = BHY_Style::safe_number($_POST['font_scale']  ?? null, 0.75, 1.6, 1);
        $data['space_scale'] = BHY_Style::safe_number($_POST['space_scale'] ?? null, 0.6, 1.8, 1);
        $data['radius']      = BHY_Style::safe_number($_POST['radius']      ?? null, 0, 32, 12);
        $data['radius_sm']   = BHY_Style::safe_number($_POST['radius_sm']   ?? null, 0, 24, 8);
        $data['bar_height']  = BHY_Style::safe_number($_POST['bar_height']  ?? null, 56, 140, 84);

        update_option(BHY_Style::OPTION, $data);
        wp_safe_redirect(add_query_arg(['page' => 'bh-style', 'saved' => '1'], admin_url('admin.php')));
        exit;
    }

    /* ---------- the gallery page ---------- */

    // OUS_VER 3.4.31 — render() now just delegates to render_shell()
    // below with the default config. WordPress's registered callback for
    // both real mount points ('bh-design' top-level, via BH_Design_Suite,
    // and the hidden 'bh-style' page above) is still this exact method —
    // that hasn't changed. What's new is that the actual markup-building
    // body is now its own portable method (render_shell($args)) that any
    // future admin page callback can call directly with its own small
    // config, instead of the logic being welded to this one callback.
    public static function render() {
        self::render_shell();
    }

    // The real, reusable shell body. $args currently recognizes one key —
    // 'default_site_group' ('brand'|'colors'|'typography'|'scale',
    // defaults to 'brand') — which "Global Styles" rail item is active on
    // first paint. There is no tab/mode argument anymore: every mount
    // point lands on the ONE unified layout below, just optionally
    // pre-selecting a different rail item.
    public static function render_shell($args = []) {
        $args = wp_parse_args($args, ['default_site_group' => 'brand']);
        $default_group = in_array($args['default_site_group'], ['brand', 'colors', 'typography', 'scale'], true)
            ? $args['default_site_group'] : 'brand';

        $s = BHY_Style::get();
        $surfaces = apply_filters('bhy_style_surfaces', []);

        // Real, live-confirmed bug fix: every registered BH_Element
        // surface (bh_element_surfaces filter) now gets a canvas story
        // AUTOMATICALLY, keyed by its real surface slug — see
        // BH_Element::render_surface_preview()'s own docblock for the
        // full diagnosis (element-builder.js's tree-selection sync fires
        // the real slug, but the only stories that ever existed were
        // hand-registered under a DIFFERENT key per plugin, so clicking
        // any tree node whose plugin hadn't separately mirrored its own
        // surface into bhy_style_surfaces — bhcrm_project_board,
        // bh_courses_lesson, the dashboard/portal surfaces — silently
        // left the canvas showing whatever was already active). Added
        // AFTER the hand-authored $surfaces above so a plugin's own
        // curated mockup (bh-contest, bh-streaming, bh-courses' catalog/
        // lesson-step previews) still wins its slot in the Preview list;
        // this only fills in real surfaces that don't already have an
        // entry, using their own group/label straight from the surface
        // registry so no plugin needs a second registration just for
        // this to work.
        if (class_exists('BH_Element')) {
            foreach (BH_Element::registered_surfaces() as $bh_slug => $bh_surface) {
                if (isset($surfaces[$bh_slug])) continue; // don't clobber a hand-authored story already using this exact key
                $surfaces[$bh_slug] = [
                    'group'  => $bh_surface['group'] ?? 'Other',
                    'label'  => ($bh_surface['label'] ?? $bh_slug) . ' (live)',
                    'render' => function () use ($bh_slug) {
                        return ['css_url' => '', 'html' => BH_Element::render_surface_preview($bh_slug, 0)];
                    },
                ];
            }
        }

        $grouped = [];
        foreach ($surfaces as $key => $surface) $grouped[$surface['group']][$key] = $surface;

        echo '<div class="wrap bhy-gallery">';
        echo '<h1>Design Suite</h1>';
        if (isset($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';

        // 3.4.36 — FINAL ARCHITECTURE: ONE left rail (just "Preview
        // surface" + the one unified tree — see render_left_rail()'s own
        // updated comment), one shared canvas column (now JUST the site-
        // wide style-preview iframes — the tree that used to also live
        // here through 3.4.35 moved into the left rail, where a Godot-
        // style scene tree belongs), one shared inspector column holding
        // BOTH #bhy-controls-panel (Global Styles, shown for the Site
        // node) and #bhy-widgets-inspector-mount (the placement
        // inspector, shown for any real node) — see render_script()'s
        // updated comment for the visibility-toggle mechanism between
        // those two.
        echo '<div class="bhy-unified">';
        self::render_left_rail($grouped, $default_group);

        echo '<div class="bhy-canvas-col">';
        self::render_canvas($surfaces, $s);
        // 3.4.36 — the old #bhy-widgets-canvas-mount div (which used to
        // receive BH_Element_Builder's .bhel-canvas tree, per 3.4.32-
        // 3.4.35's center-column placement of it) is REMOVED — that tree
        // now reparents into the LEFT rail's #bhy-rail-tree-mount instead
        // (render_script()'s reparentWidgets()). This column is just the
        // site-wide style-preview iframes now.
        echo '</div>';

        echo '<div class="bhy-inspector-col">';
        self::render_controls($s, $default_group);
        echo '<div id="bhy-widgets-inspector-mount" class="bhy-widgets-inspector-mount"></div>';
        echo '</div>';

        echo '</div>'; // .bhy-unified

        // BH_Element_Builder's own shell — its markup/JS are completely
        // unchanged (same #bhel-app skeleton, same fetch-driven palette/
        // canvas/inspector build). It's mounted hidden here; render_script()
        // below physically moves its .bhel-palette/.bhel-topbar/.bhel-canvas/
        // .bhel-inspector nodes into the rail/canvas/inspector mount points
        // above once they exist, then discards the now-empty #bhel-app shell.
        echo '<div id="bhel-app-source" style="display:none;">';
        if (class_exists('BH_Element_Builder')) {
            BH_Element_Builder::render_shell();
        } else {
            echo '<p class="description">Element Builder is unavailable.</p>';
        }
        echo '</div>';

        echo '</div>'; // .wrap

        self::render_script($surfaces, $s);
    }

    // 3.4.36 — DESIGN-SUITE-UNIFICATION-PLAN.md "FINAL ARCHITECTURE": the
    // left rail is now EXACTLY ONE section — "Site" — holding ONE mount
    // point that element-builder.js fills with its own single recursive
    // tree, rooted at a synthetic "Site" node (see that file's updated
    // docblock). The three parallel rail sections this method used to
    // render through 3.4.35 ("Global Styles" rail buttons, a standing
    // "Library" palette mount, a standing "Pages" tree mount) are GONE —
    // Global Styles is now what the ONE inspector shows when the tree's
    // Site node is selected (element-builder.js's own Site-mode branch of
    // renderInspector(), coordinated with this file's existing
    // #bhy-controls-panel — see render_script()'s selection-listener
    // comment below for the exact mechanism), and the Library palette is
    // now a CONTEXTUAL add-child popup opened from a node's own "+"/
    // right-click action (element-builder.js's openAddChildPicker()),
    // never a standing rail section.
    //
    // The "Preview surface" picker below is DELIBERATELY UNCHANGED and
    // NOT part of this collapse — it is a genuinely separate concern
    // (§2.2 of the earlier design doc's plugin-separation rule): which
    // `bhy_style_surfaces`-registered surface's live CSS-token preview
    // shows in the center canvas, independent of which TREE NODE
    // (bh_element_surfaces / placements) is currently selected for
    // editing. Conflating the two registries would violate that rule.
    // 3.4.39 — the tree ("what you're building") and the "Preview
    // surface" story picker ("which registered demo page the canvas
    // shows right now") are two genuinely different concerns that used
    // to sit stacked in the same rail with no visual separation — read
    // as one confusing wall. Split into two real rail tabs: "Structure"
    // (the tree, default/active) and "Preview" (the story picker). Both
    // panes stay in the DOM at all times (only CSS display toggles) so
    // neither element-builder.js's reparented tree/topbar nor the story
    // picker's own click handlers (render_script(), further down this
    // file) need any change — this is a pure layout/grouping fix.
    private static function render_left_rail($grouped, $default_group) {
        echo '<div class="bhy-left-rail">';

        echo '<div class="bhy-rail-tabs">';
        echo '<button type="button" class="bhy-rail-tab active" data-rail-tab="structure">Structure</button>';
        // Renamed from "Preview" — AJ's own feedback: a bad name for a
        // tab that's really "jump straight to a specific page/surface's
        // canvas view" (both real, editable Design Suite surfaces AND
        // a few plugins' own hand-authored demo mockups mixed together —
        // see this method's own updated docblock further down for the
        // full merge logic). "Live Views" says what it actually does.
        echo '<button type="button" class="bhy-rail-tab" data-rail-tab="preview">Live Views</button>';
        echo '</div>';

        // Filled by render_script() with BH_Element_Builder's own tree
        // renderer output (assets/js/element-builder.js's renderCanvas(),
        // now the ONE tree — see this file's own updated docblock note
        // and that file's updated file docblock for the full mechanics).
        echo '<div class="bhy-rail-pane bhy-rail-pane-structure active" data-rail-pane="structure">';
        // AJ's own direct follow-up: this section used to sit BELOW the
        // real Site tree, meaning selecting a Live View meant scrolling
        // past the whole (often long) real tree just to reach the thing
        // you actually wanted to click. Moved ABOVE it instead — the
        // real Site tree is a permanent fixture always in the DOM,
        // whichever tree you actually need to use right now shouldn't
        // require scrolling past the other one first. Given its own
        // "bhy-rail-demo-outline-section" accent styling (render_script()
        // CSS below) so it reads as a distinct, purposeful block instead
        // of an unstyled leftover — clicking a row here fires the exact
        // same highlight()+style-panel code as the inspector's copy
        // (element-builder.js's renderDemoOutline(), one shared tree-
        // builder function, not two parallel copies of the same logic).
        // Hidden by default; only a real BH_Element surface or a
        // demo-mockup Live View selection shows it (renderDemoOutline()
        // toggles this section's visibility itself).
        // AJ's own further follow-up: "its not folded into the other
        // thing yet either" — this was a fixed, always-open box while
        // every other grouped section in this app (.bhel-style-group in
        // the inspector, and this exact rail's own Global Styles token
        // groups) is a real <details>/<summary> disclosure. Switched to
        // match — open by default (same as before, so nothing about
        // when it's actually shown changes, see renderDemoOutline()'s
        // own visibility toggle), but now genuinely foldable/collapsible
        // the same way everything else here already is, not a one-off.
        echo '<details class="bhy-rail-section bhy-rail-demo-outline-section" id="bhy-rail-demo-outline-section" style="display:none;" open>';
        echo '<summary class="bhy-rail-heading bhy-rail-heading-accent">Live view markup</summary>';
        echo '<div id="bhy-rail-demo-outline-mount" class="bhy-rail-mount"></div>';
        echo '</details>';
        // AJ's own direct follow-up: "the 'surfaces' heading should be
        // collapsable too" — same <details>/<summary> treatment the
        // Live View section just got above, for the exact same "match
        // the rest of this app's fold convention" reason. id carried
        // through (bhy-rail-tree-section-fold below) so fold STATE
        // itself can be persisted/restored the same way — see this
        // file's new persistUiState()/restoreUiState() (bottom of
        // render_script()) for the "pick up where you left off" half of
        // AJ's ask.
        echo '<details class="bhy-rail-section bhy-rail-tree-section" id="bhy-rail-tree-section" open>';
        echo '<summary class="bhy-rail-heading">Site</summary>';
        echo '<div id="bhy-rail-tree-mount" class="bhy-rail-mount"><p class="description">Loading…</p></div>';
        echo '</details>';
        echo '</div>';

        echo '<div class="bhy-rail-pane bhy-rail-pane-preview" data-rail-pane="preview">';
        echo '<div class="bhy-rail-section">';
        echo '<p class="description">These are just live demo pages for checking a style change at a glance — not part of what you\'re building. Pick one to preview it in the canvas.</p>';
        if (!$grouped) {
            echo '<p class="description">No surfaces registered yet — a plugin registers one via the <code>bhy_style_surfaces</code> filter.</p>';
        }
        $first = true;
        foreach ($grouped as $group_label => $items) {
            echo '<div class="bhy-rail-subheading">' . esc_html($group_label) . '</div>';
            foreach ($items as $key => $surface) {
                echo '<button type="button" class="bhy-story-btn' . ($first ? ' active' : '') . '" data-surface="' . esc_attr($key) . '">' . esc_html($surface['label']) . '</button>';
                $first = false;
            }
        }
        echo '</div>';
        echo '</div>';

        echo '</div>'; // .bhy-left-rail
    }

    // AJ's own call, straight after the iframe-architecture question:
    // "never use iframes unless we have to or it really is the ideal...
    // just do the build." Iframes are gone. Each story is now a plain
    // <div class="bhy-story-frame">, same-document, same-DOM — but with
    // its content attached under a real `attachShadow({mode:'open'})`
    // shadow root (built client-side in render_script() below) instead
    // of innerHTML'd straight in. Shadow DOM gives the exact same two
    // things the iframe boundary was actually bought for — a surface's
    // own stylesheet/reset never bleeds onto the surrounding wp-admin
    // chrome, and wp-admin's own CSS never bleeds into the surface — for
    // free, with zero CSS-selector-rewriting/scoping hacks needed, and
    // without the cross-document plumbing (contentDocument, message
    // passing, cross-frame CustomEvents) that caused most of this
    // session's sync bugs. The one real tradeoff, same as the user's own
    // "no dedicated Node/parser for pre-scoping" framing: any <script>
    // tag inside a surface's markup would NOT run inside a shadow root
    // the way it does in a real document/iframe — a non-issue here since
    // every registered `bhy_style_surfaces`/`bh_element_surfaces` render
    // callable returns static server-rendered HTML with no inline
    // scripts (confirmed by reading every current registration; if that
    // ever changes, this comment is the flag to revisit).
    //
    // The full HTML document string (preview_doc()) is UNCHANGED in
    // shape — same head/style/body markup a real document would want —
    // it's just handed to the browser as parseable text via a data
    // attribute (base64, so no HTML-attribute-escaping edge cases)
    // instead of an iframe's srcdoc. render_script() below parses it
    // with DOMParser and moves the resulting <head> style/link tags and
    // <body> children into that div's own shadow root.
    private static function render_canvas($surfaces, $s) {
        echo '<div class="bhy-canvas">';
        $first = true;
        foreach ($surfaces as $key => $surface) {
            $payload = call_user_func($surface['render']);
            echo '<div class="bhy-story-frame' . ($first ? ' active' : '') . '" data-surface="' . esc_attr($key) . '" data-doc="' . esc_attr(base64_encode(self::preview_doc($payload, $s))) . '"></div>';
            $first = false;
        }
        if (!$surfaces) echo '<div class="bhy-empty">Nothing to preview yet.</div>';
        echo '</div>';
    }

    // One real HTML document per surface — the surface's own stylesheet,
    // the current tokens as CSS vars (with a stable id so the live-edit
    // JS can rewrite just that tag), and the surface's real markup.
    private static function preview_doc($payload, $s) {
        $font_url = BHY_Style::preview_all_fonts_url();
        return '<!doctype html><html><head><meta charset="utf-8">'
            . ($font_url ? '<link rel="stylesheet" href="' . esc_url($font_url) . '">' : '')
            . (!empty($payload['css_url']) ? '<link rel="stylesheet" href="' . esc_url($payload['css_url']) . '">' : '')
            . '<style id="bhy-vars">' . BHY_Style::inline_css() . '</style>'
            // No-iframes build — this used to target the real <body> a
            // real iframe document always has. Now only body's CHILDREN
            // get moved into the shadow root (render_script()'s
            // shadow-attach code), never a <body> element itself, so a
            // `body{...}` selector matches nothing. `:host` is the
            // shadow-DOM equivalent of "the box everything sits inside" —
            // it targets the .bhy-story-frame div itself, which every
            // moved child then fills exactly like a real <body> would.
            . '<style>:host{display:block;margin:0;background:var(--bh-bg);color:var(--bh-text);font-family:var(--bh-font-body);}</style>'
            // element-builder.js's renderDemoOutline() highlight() adds
            // this class to flash-scroll an element into view when its
            // outline row is clicked — needs to exist INSIDE the iframe's
            // own document (this page's own CSS never reaches in here,
            // by design — see this file's iframe-isolation reasoning).
            . '<style>.bhel-outline-highlight{outline:3px solid #2271b1 !important;outline-offset:2px;}</style>'
            . '</head><body>' . $payload['html'] . '</body></html>';
    }

    // A small, always-visible strip of sample chips that directly apply
    // every scale/shape token (radius, radius_sm, bar_height, font_scale,
    // space_scale) to real elements right here in the controls panel.
    // Exists because no single registered preview surface is guaranteed
    // to visibly use every token at once (e.g. the default Player surface
    // never shows --bh-radius without opening a modal) — this gives every
    // slider instant, surface-independent feedback instead.
    private static function render_token_preview($s) {
        echo '<div class="bhy-token-preview" id="bhy-token-preview">';
        echo '<div class="bhy-token-chip bhy-token-chip-radius">Card <span>radius</span></div>';
        echo '<div class="bhy-token-chip bhy-token-chip-radius-sm">Chip <span>radius_sm</span></div>';
        echo '<button type="button" class="bhy-token-pill">Pill button</button>';
        echo '<div class="bhy-token-bar" title="bar_height"><span>Now-playing bar height</span></div>';
        echo '<div class="bhy-token-text"><strong>Aa</strong> font_scale &amp; space_scale</div>';
        echo '</div>';
    }

    // 3.4.35 — Global Styles' section headers and grouping now use the
    // EXACT SAME markup pattern as the widget inspector (assets/js/
    // element-builder.js's renderInspector()/renderStyleAdvancedSection()
    // and assets/css/element-builder.css): plain "<h2>Section</h2>" is
    // now "<h3>SECTION</h3>" (the ".bhel-inspector h3" CSS rule — small
    // caps, underline, --bhy-ink-dim — is mirrored below for
    // ".bhy-controls h3" so both screens share one visual rule, not two
    // copies that happen to match), and anything that isn't a small,
    // always-relevant core set is now a collapsible "<details
    // class='bhel-style-group'>" disclosure — literally the same class
    // the inspector's "STYLE — ADVANCED" ▸ BACKGROUND / ▸ BORDER groups
    // use (assets/css/element-builder.css's .bhel-style-group rules are
    // global, not scoped to the inspector, so they apply here verbatim
    // with zero new CSS). Color swatches still render through
    // BHY_UI::swatch_field() — the SAME .bhy-swatch-card markup
    // renderStyleField()'s isColor branch in element-builder.js builds
    // client-side for a per-placement color override — so both screens'
    // color pickers are one component, not two independently-coded ones
    // that happen to look alike. See DESIGN-SUITE-UNIFICATION-PLAN.md's
    // status note for why this stays PHP-rendered here (server owns the
    // site-wide option row) rather than trying to make the per-placement
    // inspector server-rendered instead.
    //
    // ".bhy-token-group" divs are unchanged in shape, but (3.4.36) no
    // longer individually shown/hidden — the WHOLE #bhy-controls-panel
    // now toggles as one unit against the placement inspector, see this
    // method's own updated comment below. The live token preview strip
    // and the form/nonce/submit wrapper stay outside any single group —
    // they're relevant no matter what.
    // 3.4.36 — $default_group is now UNUSED for show/hide (kept as a
    // param only so callers/BH_Design_Suite's existing call sites don't
    // need to change): the old per-group "Global Styles" rail buttons
    // that used to toggle these `.bhy-token-group` divs one-at-a-time are
    // GONE (see render_left_rail()'s own updated comment) — every group
    // renders stacked and always-visible now, same "no tabs inside one
    // node's inspector" posture the placement inspector's Content/Style/
    // Attrs/Data sections already use. The WHOLE #bhy-controls-panel
    // wrapping this content is what toggles now — shown only when the
    // tree's Site node is selected — see render_script()'s selection-
    // listener comment for that mechanism.
    private static function render_controls($s, $default_group = 'brand') {
        echo '<div class="bhy-controls" id="bhy-controls-panel">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="bhy-form">';
        wp_nonce_field('bhy_save_settings');
        echo '<input type="hidden" name="action" value="bhy_save_settings">';

        echo '<h3>Live token preview</h3>';
        self::render_token_preview($s);

        echo '<div class="bhy-token-group" data-token-group="brand">';
        echo '<h3>Brand</h3>';
        echo '<div class="bhel-field-row"><label>Wordmark</label><p><input type="text" id="brand_part1" name="brand_part1" class="bhy-brand-input" value="' . esc_attr($s['brand_part1']) . '" placeholder="First part"> <input type="text" id="brand_part2" name="brand_part2" class="bhy-brand-input" value="' . esc_attr($s['brand_part2']) . '" placeholder="Accent part"></p></div>';
        echo '</div>';

        echo '<div class="bhy-token-group" data-token-group="colors">';
        echo '<div class="bhel-field-row"><label for="bhy-theme-select">Quick theme</label>';
        echo '<select id="bhy-theme-select"><option value="">Choose a theme…</option>';
        foreach (BHY_Style::THEME_GROUPS as $group_label => $themes) {
            echo '<optgroup label="' . esc_attr($group_label) . '">';
            foreach ($themes as $name => $colors) {
                echo '<option value="' . esc_attr($name) . '" data-set=\'' . esc_attr(wp_json_encode($colors)) . '\'>' . esc_html($name) . '</option>';
            }
            echo '</optgroup>';
        }
        echo '</select></div>';

        // Core colors — always visible, same "the common controls stay
        // in the open flow" rule the inspector's plain "Style" section
        // (type.style, not the collapsible Advanced groups) follows.
        echo '<h3>Colors</h3><div class="bhy-swatch-grid">';
        $color_labels = [
            'color_bg' => 'Background', 'color_surface' => 'Surface', 'color_surface_2' => 'Surface (raised)',
            'color_border' => 'Border', 'color_text' => 'Text', 'color_accent' => 'Accent',
        ];
        foreach ($color_labels as $key => $label) {
            BHY_UI::swatch_field($key, $key, $label, $s[$key]);
        }
        echo '</div>';

        // Less-common colors + the 8 category swatches — collapsible
        // "bhel-style-group" disclosures, same shape as the inspector's
        // "STYLE — ADVANCED" ▸ BACKGROUND / ▸ BORDER groups, instead of
        // one long always-expanded wall of fields.
        echo '<details class="bhel-style-group"><summary class="bhel-style-group-title">Advanced colors</summary><div class="bhel-style-group-body bhy-swatch-grid">';
        $advanced_color_labels = [
            'color_text_dim' => 'Text (dim)', 'color_accent_soft' => 'Accent (soft)', 'color_overlay' => 'Modal backdrop',
        ];
        foreach ($advanced_color_labels as $key => $label) {
            BHY_UI::swatch_field($key, $key, $label, $s[$key]);
        }
        echo '</div></details>';

        echo '<details class="bhel-style-group"><summary class="bhel-style-group-title">Category colors</summary><div class="bhel-style-group-body bhy-swatch-grid">';
        for ($i = 1; $i <= 8; $i++) {
            BHY_UI::swatch_field('cat_color_' . $i, 'cat_color_' . $i, 'Category ' . $i, $s['cat_color_' . $i]);
        }
        echo '</div></details>';
        echo '</div>'; // data-token-group="colors"

        echo '<div class="bhy-token-group" data-token-group="typography">';
        echo '<h3>Typography</h3>';
        BHY_UI::font_field('font_display', 'Display font', $s);
        BHY_UI::font_field('font_body', 'Body font', $s);
        echo '</div>';

        echo '<div class="bhy-token-group" data-token-group="scale">';
        echo '<h3>Scale</h3>';
        BHY_UI::slider_row('font_scale', 'Text size', $s, 0.75, 1.6, 0.05, '×');
        BHY_UI::slider_row('space_scale', 'Spacing', $s, 0.6, 1.8, 0.05, '×');
        BHY_UI::slider_row('radius', 'Corner radius', $s, 0, 32, 1, 'px');
        BHY_UI::slider_row('radius_sm', 'Corner radius (small)', $s, 0, 24, 1, 'px');
        BHY_UI::slider_row('bar_height', 'Now-playing bar height', $s, 56, 140, 2, 'px');
        echo '</div>';

        echo '<p class="submit"><button type="submit" class="button button-primary">Save</button></p>';
        echo '</form></div>';
    }

    private static function render_script($surfaces, $s) {
        ?>
        <style><?php echo BHY_UI::admin_page_css(); ?></style>
        <style>
        /* 3.4.33 — visual/UX polish pass over the 3.4.32 unified shell.
           Structure/columns unchanged; this only refines spacing rhythm,
           hierarchy, and hover/active/focus states, all drawn from the
           --bhy-* tokens BHY_UI::design_system_css() already prints
           globally on this screen (own-ur-shit/includes/class-ui.php) —
           no new/parallel color or spacing system introduced here. */
        .bhy-unified {
            display: grid;
            grid-template-columns: 330px 1fr 340px;
            gap: var(--bhy-space-4, 16px);
            align-items: start;
            margin-top: var(--bhy-space-4, 14px);
        }
        .bhy-left-rail {
            background: var(--bhy-surface, #fff); border: 1px solid var(--bhy-border, #dcdcde); border-radius: var(--bhy-radius, 8px);
            padding: var(--bhy-space-2, 8px) 0; max-height: 80vh; overflow-y: auto; overflow-x: hidden;
            box-sizing: border-box;
        }
        /* 3.4.41 — tightened row rhythm now that the rail has more room
           (260px -> 300px): slightly less vertical padding per card/row
           so more of the tree is visible without scrolling, without
           making anything harder to tap. */
        .bhel-card { padding: 6px 8px 6px 6px; }
        .bhel-slot-content-row { padding: 6px 8px; }
        /* 3.4.41 — basic mobile fallback: the grid was fixed at three
           columns with no breakpoint at all, meaning on a narrow phone
           screen the whole page would either overflow horizontally or
           crush all three columns unreadably. Below 900px this stacks
           rail -> canvas -> inspector as one column, full width, each
           its own natural height instead of a forced 80vh scroll box —
           NOT a full mobile redesign (AJ: "may need to rethink things a
           bit for that eventually, but we can cross that bridge") — just
           making sure nothing actively breaks/overflows in the meantime. */
        @media (max-width: 900px) {
            .bhy-unified { grid-template-columns: 1fr; }
            .bhy-left-rail { max-height: 50vh; }
        }
        /* 3.4.40 — nothing in the rail should ever force horizontal
           scroll; every row-level element wraps its own text instead of
           overflowing. */
        .bhy-left-rail * { box-sizing: border-box; max-width: 100%; }
        .bhy-story-btn, .bhy-rail-item { white-space: normal; word-break: break-word; }
        /* 3.4.39 — Structure/Preview rail tabs (render_left_rail()'s own
           updated comment explains the split). Plain two-button tab bar,
           same visual language as the inspector's own h3 section rule
           (small caps, underline) so it reads as "this rail" chrome, not
           a third unrelated pattern. */
        .bhy-rail-tabs { display: flex; border-bottom: 1px solid var(--bhy-border, #dcdcde); padding: 0 var(--bhy-space-2, 6px); gap: 2px; }
        .bhy-rail-tab {
            flex: 1; background: none; border: none; border-bottom: 2px solid transparent;
            padding: var(--bhy-space-3, 10px) var(--bhy-space-2, 6px); font-size: var(--bhy-text-xs, 11px);
            font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--bhy-ink-dim, #787c82);
            cursor: pointer; transition: color var(--bhy-transition, 150ms ease), border-color var(--bhy-transition, 150ms ease);
        }
        .bhy-rail-tab:hover { color: var(--bhy-ink, #1d2327); }
        .bhy-rail-tab.active { color: var(--bhy-accent, #2271b1); border-bottom-color: var(--bhy-accent, #2271b1); }
        .bhy-rail-pane { display: none; }
        .bhy-rail-pane.active { display: block; }
        .bhy-rail-section { border-bottom: 1px solid var(--bhy-subtle, #f0f0f1); padding: var(--bhy-space-2, 6px) 0 var(--bhy-space-3, 10px); }
        .bhy-rail-section:last-child { border-bottom: none; }
        .bhy-rail-heading {
            font-size: var(--bhy-text-xs, 11px); font-weight: 700; text-transform: uppercase;
            letter-spacing: .06em; color: var(--bhy-ink-dim, #787c82); padding: var(--bhy-space-2, 6px) var(--bhy-space-4, 14px) var(--bhy-space-1, 4px);
        }
        .bhy-rail-subheading {
            font-size: var(--bhy-text-xs, 11px); font-weight: 600; color: var(--bhy-ink-dim, #a7aaad);
            padding: var(--bhy-space-2, 6px) var(--bhy-space-4, 14px) 2px; opacity: .8;
        }
        /* Rail rows: icon-friendly (Library/Pages mounts render dashicons
           inline), a left accent bar for the selected state rather than
           relying on bold text alone, and a fast, consistent hover/focus
           transition matching every other interactive control in the
           Design Suite shell. */
        .bhy-rail-item, .bhy-story-btn {
            display: block; width: 100%; text-align: left; background: none;
            border: none; border-left: 3px solid transparent; padding: 7px var(--bhy-space-4, 14px);
            font-size: var(--bhy-text-base, 13px); color: var(--bhy-ink, #1d2327);
            cursor: pointer; border-radius: 0;
            transition: background var(--bhy-transition, 150ms ease), border-color var(--bhy-transition, 150ms ease);
        }
        .bhy-rail-item:hover, .bhy-story-btn:hover { background: var(--bhy-hover-tint, #f6f7f7); }
        .bhy-rail-item:focus-visible, .bhy-story-btn:focus-visible { outline: none; box-shadow: inset var(--bhy-focus-ring, 0 0 0 2px rgba(34,113,177,.25)); }
        .bhy-rail-item.active, .bhy-story-btn.active {
            background: var(--bhy-selected-tint, #f0f6fc); border-left-color: var(--bhy-accent, #2271b1); font-weight: 600;
        }
        .bhy-rail-mount { padding: 0 0 4px; }
        .bhy-canvas-col, .bhy-inspector-col { min-width: 0; }
        .bhy-widgets-inspector-mount { min-height: 200px; } /* 3.4.36 — .bhy-widgets-canvas-mount's own div no longer exists, its selector removed alongside it (see render_shell()'s comment) */
        .bhy-rail-tree-section .bhy-rail-mount { padding: 0; }
        /* 3.4.44 — direct response to "no real good margin/gap/padding
           around them and the left bar's edges" — the reparented
           .bhel-canvas (element-builder.css) kept its own generic
           padding, but nothing in THIS rail context gave it any extra
           breathing room against the rail's own border, so cards read
           as touching the rail edge. A little extra horizontal padding
           here, scoped to only the rail's embedded copy of the canvas
           (not the standalone/Debug Tools context, which is unchanged),
           fixes that without touching element-builder.css's own shared
           rule. */
        .bhy-rail-tree-section #bhy-rail-tree-mount .bhel-canvas { padding: 10px 10px 10px 12px; border: none; min-height: 0; }
        /* 3.4.57 — the Live View outline section used to be bare
           .bhy-rail-section chrome identical to (and easily lost among)
           the real Site tree section right below it — no visual signal
           that it's a genuinely different kind of thing (read-only demo
           markup vs. your real editable tree). A tinted background +
           left accent bar (same accent-bar language .bhy-rail-item.active
           already uses for "this is the selected thing") plus its own
           rounded card make it read as a distinct, purposeful block
           instead of an unstyled leftover — while staying built from the
           exact same --bhy-* tokens as everything else on this screen,
           not a new one-off color. */
        .bhy-rail-demo-outline-section {
            margin: var(--bhy-space-2, 8px) var(--bhy-space-2, 8px) var(--bhy-space-3, 10px);
            background: var(--bhy-selected-tint, #f0f6fc);
            border: 1px solid var(--bhy-border, #dcdcde);
            border-left: 3px solid var(--bhy-accent, #2271b1);
            border-radius: var(--bhy-radius-sm, 6px);
            padding: var(--bhy-space-2, 8px) var(--bhy-space-3, 10px) var(--bhy-space-3, 10px);
        }
        .bhy-rail-heading-accent { color: var(--bhy-accent, #2271b1); padding-left: 0; }
        /* 3.4.60/3.4.61 — both rail sections (Live View AND, per AJ's own
           follow-up, "the 'surfaces' heading should be collapsable too")
           are real <details>/<summary> disclosures now (matches
           .bhel-style-group's own fold pattern elsewhere in this app —
           see render_left_rail()'s own updated comment). One shared
           selector for both — .bhy-rail-section covers
           .bhy-rail-demo-outline-section AND .bhy-rail-tree-section,
           since both carry that base class. <summary> gets list-style/
           marker reset and a pointer cursor same as every other
           clickable rail heading, rather than the browser's default
           triangle-and-serif-arrow treatment. */
        .bhy-rail-section > summary { cursor: pointer; list-style: none; }
        .bhy-rail-section > summary::-webkit-details-marker { display: none; }
        .bhy-rail-section > summary::before {
            content: '▸'; display: inline-block; margin-right: 4px; font-size: 10px;
            transition: transform var(--bhy-transition, 150ms ease);
        }
        .bhy-rail-section[open] > summary::before { transform: rotate(90deg); }
        .bhy-rail-demo-outline-section #bhy-rail-demo-outline-mount { max-height: 280px; overflow-y: auto; }
        /* 3.4.35 — Global Styles section headers now use the exact same
           rule as the widget inspector's ".bhel-inspector h3"
           (assets/css/element-builder.css): small-caps uppercase label +
           underline, drawn from the same --bhy-* tokens. Kept as its own
           selector (".bhy-controls h3") rather than adding the
           ".bhel-inspector" class to ".bhy-controls" wholesale, since
           that class also carries mobile bottom-sheet transform rules
           this screen doesn't use — this is the one rule actually worth
           sharing, copied verbatim rather than duplicated with drift. */
        .bhy-controls h3 {
            font-size: var(--bhy-text-xs, 11px); font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
            color: var(--bhy-ink-dim, #787c82); margin: var(--bhy-space-5, 18px) 0 var(--bhy-space-2, 8px);
            padding-bottom: var(--bhy-space-1, 4px); border-bottom: 1px solid var(--bhy-border, #dcdcde);
        }
        .bhy-controls h3:first-child { margin-top: 0; }
        </style>
        <style id="bhy-preview-vars"><?php echo str_replace(':root', '.bhy-token-preview', BHY_Style::inline_css()); ?></style>
        <script>
        <?php echo BHY_UI::swatch_js("refreshAllFrames();"); ?>
        // AJ's own ask: "the whole thing should save your state and pick
        // up where you left off when you come back." element-builder.js
        // now persists the tree/placement SELECTION itself (its own
        // SELECTION_STORAGE_KEY) — this is the other half, specific to
        // this file's own DOM: which rail tab was active (Structure vs
        // Live Views), whether the two foldable rail sections were open
        // or closed, and which Live View story was last picked. One
        // small shared helper rather than one-off localStorage calls
        // scattered per feature — declared here, OUTSIDE either IIFE
        // below, so both can reach it (this <script> block's two
        // `(function(){...})()` blocks are independent closures, not
        // nested in a third wrapper, so a plain `var`/`function` at this
        // level is genuinely shared between them).
        var BHY_UI_STATE_KEY = 'bhyDesignSuiteUiState';
        function bhyReadUiState() {
            try {
                var raw = localStorage.getItem(BHY_UI_STATE_KEY);
                return raw ? JSON.parse(raw) : {};
            } catch (e) { return {}; }
        }
        function bhyWriteUiState(patch) {
            try {
                var current = bhyReadUiState();
                Object.keys(patch).forEach(function (k) { current[k] = patch[k]; });
                localStorage.setItem(BHY_UI_STATE_KEY, JSON.stringify(current));
            } catch (e) { /* private browsing / storage disabled — just don't persist */ }
        }
        // Also used by the inspector's own collapsible groups (Style —
        // Advanced, Custom class/CSS, Custom JS — element-builder.js's
        // renderStyleAdvancedSection()/renderActionsSection()) via the
        // same key namespace, so "pick up where you left off" covers
        // inspector fold state too, not just this file's own rail
        // sections — see that file's own bhyPersistDetails() for the
        // other half of this same mechanism.
        function bhyPersistDetails(detailsEl, key) {
            if (!detailsEl) return;
            var state = bhyReadUiState();
            var saved = state.detailsOpen && state.detailsOpen[key];
            if (saved !== undefined) detailsEl.open = !!saved;
            detailsEl.addEventListener('toggle', function () {
                var s = bhyReadUiState();
                s.detailsOpen = s.detailsOpen || {};
                s.detailsOpen[key] = detailsEl.open;
                bhyWriteUiState({ detailsOpen: s.detailsOpen });
            });
        }
        (function () {
            // 3.4.36 — FINAL ARCHITECTURE. The old "Global Styles" rail
            // buttons / showSiteGroup() one-group-at-a-time toggle and the
            // old separate "Library"/"Pages" mounts are GONE (see
            // render_left_rail()'s own updated comment) — replaced by:
            //   - ONE tree mount (#bhy-rail-tree-mount, left rail) that
            //     BH_Element_Builder's own .bhel-canvas (now the single
            //     recursive tree, Site node included — see element-
            //     builder.js's own updated docblock) is reparented into.
            //   - ONE inspector column holding TWO panels that toggle
            //     visibility against each other based on the tree's
            //     current selection: #bhy-controls-panel (this file's
            //     existing Global Styles form, UNCHANGED markup/logic,
            //     every group now stacked and always-visible within it —
            //     see render_controls()'s own updated comment) for the
            //     Site node, and #bhy-widgets-inspector-mount (BH_Element_
            //     Builder's existing placement inspector, also
            //     unchanged) for any real placement node. The toggle is a
            //     thin coordination listener on a 'bhel:selection'
            //     CustomEvent that element-builder.js dispatches every
            //     time the tree selection changes — this file never
            //     reaches into element-builder.js's own state, and
            //     element-builder.js never reaches into this file's DOM
            //     beyond firing that one event, keeping the "two distinct
            //     registration surfaces" boundary (the earlier design
            //     doc's §2.2) intact at the coordination layer too.
            var widgetsInspectorMount = document.getElementById('bhy-widgets-inspector-mount');
            var controlsPanel = document.getElementById('bhy-controls-panel');
            var treeMount = document.getElementById('bhy-rail-tree-mount');

            function showSitePanel(isSite) {
                if (controlsPanel) controlsPanel.style.display = isSite ? '' : 'none';
                if (widgetsInspectorMount) widgetsInspectorMount.style.display = isSite ? 'none' : '';
            }
            // Default paint, before element-builder.js's first fetch
            // resolves and fires its own event: Site selected (the
            // tree's permanent root), matching element-builder.js's own
            // initial state.selectedIndex = 'site' default.
            showSitePanel(true);
            document.addEventListener('bhel:selection', function (ev) {
                showSitePanel(!!(ev.detail && ev.detail.type === 'site'));
            });

            // 3.4.38 — the center canvas's "Preview surface" story picker
            // (the .bhy-story-btn/.bhy-story-frame pair further down this
            // script) used to be a fully independent selection system from
            // the tree — clicking a CRM/Dashboard/etc. tree node never
            // changed which surface's live iframe was showing, confirmed
            // as a real gap via live screenshot. This listener is the
            // other half of element-builder.js's new detail.surface field
            // (see that file's fireSelectionEvent()): whenever the tree
            // selection carries a surface slug, click that surface's own
            // .bhy-story-btn programmatically so the two selection
            // systems stay in sync. Guarded so it's a no-op for the Site
            // node (no surface) and for any surface slug with no matching
            // registered preview story (not every registered surface is
            // guaranteed to have a preview payload).
            document.addEventListener('bhel:selection', function (ev) {
                var surface = ev.detail && ev.detail.surface;
                if (!surface) return;
                var btn = document.querySelector('.bhy-story-btn[data-surface="' + surface + '"]');
                if (btn && !btn.classList.contains('active')) btn.click();
            });

            // 3.4.39 — Structure/Preview rail tabs (render_left_rail()'s
            // own updated comment explains why these were split out).
            // Plain show/hide, no framework needed — both panes stay in
            // the DOM so nothing that already targets #bhy-rail-tree-mount
            // or .bhy-story-btn has to change.
            var railTabs = document.querySelectorAll('.bhy-rail-tab');
            var railPanes = document.querySelectorAll('.bhy-rail-pane');
            railTabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    railTabs.forEach(function (t) { t.classList.remove('active'); });
                    railPanes.forEach(function (p) { p.classList.remove('active'); });
                    tab.classList.add('active');
                    var pane = document.querySelector('.bhy-rail-pane[data-rail-pane="' + tab.dataset.railTab + '"]');
                    if (pane) pane.classList.add('active');
                });
            });
            // Jumping back to Structure after picking a Live View means
            // you're never left stranded on that tab with no way back to
            // the tree. Direct, live-confirmed feedback on the ORIGINAL
            // version of this: jumping back to Structure looked right,
            // but the tree/inspector selection underneath it never
            // actually followed — clicking a Live View updated the
            // canvas but left the inspector showing whatever placement
            // was selected before, completely unrelated to what's now on
            // screen (a real, confirmed inconsistency, not the intended
            // "helpful jump back" behavior). The reverse of element-
            // builder.js's own 'bhel:selection' listener above (which
            // syncs canvas FROM tree selection) was simply never built —
            // this closes that gap by dispatching a NEW event,
            // 'bhel:select-surface', that element-builder.js now listens
            // for (see that file's own updated docblock) and uses to
            // select the matching Surface tree node for real, whenever
            // one exists. A hand-authored demo-only story (bh-contest,
            // bh-streaming, this plugin's own catalog/lesson-step
            // mockups — never a real registered BH_Element surface) has
            // no tree node to select at all; for those, this is a
            // deliberate, disclosed no-op — the inspector simply keeps
            // showing whatever was last selected, since there is
            // genuinely nothing in the tree to point it at.
            // 3.4.60 — real, live-confirmed bug: this used to be TWO
            // separate click listeners on the same .bhy-story-btn
            // buttons — this one (dispatching 'bhel:select-surface',
            // registered FIRST) and a second one further down (toggling
            // which .bhy-story-frame carries the 'active' class,
            // registered SECOND). Listeners on the same element/event
            // fire in registration order, so clicking a Live View
            // dispatched the sync event and triggered element-builder.js's
            // renderDemoOutline() BEFORE the active class had actually
            // moved to the new frame — that function reads
            // '.bhy-story-frame.active' directly, so it was always
            // building its outline against the PREVIOUSLY active
            // surface, one click behind whatever the canvas just
            // switched to. Merged into one handler, active-class-toggle
            // FIRST, dispatch second — the second listener block that
            // used to also do the toggle is removed a few lines below
            // this comment (grep '3.4.60' there).
            document.querySelectorAll('.bhy-story-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    // Deliberately re-queried HERE rather than closing
                    // over the 'frames'/'buttons' vars the OTHER
                    // render_script() IIFE further down declares — this
                    // handler lives in a separate closure (this file's
                    // top-level script has two independent top-level
                    // (function(){...})() blocks), so those variables
                    // are simply out of scope here, not just stale.
                    document.querySelectorAll('.bhy-story-btn').forEach(function (b) { b.classList.remove('active'); });
                    document.querySelectorAll('.bhy-story-frame').forEach(function (f) { f.classList.remove('active'); });
                    btn.classList.add('active');
                    var matchingFrame = document.querySelector('.bhy-story-frame[data-surface="' + btn.dataset.surface + '"]');
                    if (matchingFrame) matchingFrame.classList.add('active');

                    var structureTab = document.querySelector('.bhy-rail-tab[data-rail-tab="structure"]');
                    if (structureTab) structureTab.click();
                    document.dispatchEvent(new CustomEvent('bhel:select-surface', { detail: { surface: btn.dataset.surface, label: btn.textContent } }));
                });
            });

            // Relocates BH_Element_Builder's existing DOM (built
            // synchronously — fetch()-filled asynchronously — by
            // assets/js/element-builder.js when that enqueued script
            // executes) out of the hidden #bhel-app shell and into this
            // page's shared rail/inspector mount points. Nothing about
            // element-builder.js's own event listeners changes — this
            // only moves the already-built nodes (tree-node selection,
            // popup-picker clicks, inspector field handlers all stay
            // bound). 3.4.37 — .bhel-topbar is now just a status line and
            // one "Save all changes" button (no Surface/Slot <select>,
            // no Context ID field, no per-slot Save/Save-as-Prefab
            // buttons — every registered surface/slot is now a real tree
            // node inside .bhel-canvas itself, see element-builder.js's
            // own updated docblock) — it still moves into the tree mount
            // together with the tree, unchanged mechanically, just much
            // smaller now. The contextual add-child popup lives INSIDE
            // .bhel-canvas's own DOM subtree (see element-builder.js's
            // openAddChildPicker()), so it never needed its own mount
            // point the way the old standing "Library" section did.
            function reparentWidgets() {
                var bhelApp = document.getElementById('bhel-app');
                var topbar = bhelApp && bhelApp.querySelector('.bhel-topbar');
                var canvas = bhelApp && bhelApp.querySelector('.bhel-canvas');
                var inspector = bhelApp && bhelApp.querySelector('.bhel-inspector');
                if (!topbar || !canvas || !inspector || !treeMount || !widgetsInspectorMount) {
                    setTimeout(reparentWidgets, 50);
                    return;
                }
                treeMount.innerHTML = '';
                treeMount.appendChild(topbar);
                treeMount.appendChild(canvas);

                widgetsInspectorMount.appendChild(inspector);

                var source = document.getElementById('bhel-app-source');
                if (source) source.remove();
            }
            reparentWidgets();
        })();
        (function () {
            var frames = document.querySelectorAll('.bhy-story-frame');
            var buttons = document.querySelectorAll('.bhy-story-btn');

            // No-iframes build (see render_canvas()'s own comment for the
            // full reasoning): each .bhy-story-frame div's real content
            // lives under its own attachShadow({mode:'open'}) root,
            // parsed once here from the data-doc payload PHP encoded.
            // Everything downstream that used to read `frame.
            // contentDocument` now reads `frame.shadowRoot` instead — the
            // shadow root exposes the exact same getElementById()/
            // querySelectorAll() API a real Document does, so this is a
            // near-zero-diff swap everywhere else in this file and in
            // element-builder.js's own preview/outline code.
            // 3.4.55 — real bug, caught immediately from AJ's own
            // screenshot: "styles are not doing their thing." Cause: every
            // token/color variable this whole gallery depends on is
            // printed as `:root{--bh-bg:...}` (BHY_Style::inline_css()) —
            // correct for a REAL iframe document (its own <html> is a
            // genuine :root), but inside a shadow root there is no root
            // element at all, so `:root` matches nothing and every single
            // `var(--bh-*)` in every surface's own CSS silently resolved
            // to nothing — exactly the unstyled black/white mess in the
            // screenshot. The shadow-DOM equivalent of "the document root"
            // is `:host` (the frame div itself, which vars then inherit
            // down through the whole shadow tree same as before). Rewrite
            // `:root` -> `:host` on the #bhy-vars tag right after parsing;
            // refreshAllFrames()'s own live-edit writer below gets the
            // same rewrite so later token edits don't regress this.
            frames.forEach(function (frame) {
                var raw = frame.dataset.doc;
                if (!raw) return;
                var html;
                try { html = atob(raw); } catch (e) { return; }
                var parsed = new DOMParser().parseFromString(html, 'text/html');
                var root = frame.attachShadow({ mode: 'open' });
                Array.prototype.slice.call(parsed.head.children).forEach(function (node) { root.appendChild(node); });
                Array.prototype.slice.call(parsed.body.children).forEach(function (node) { root.appendChild(node); });
                var varsTag = root.getElementById('bhy-vars');
                if (varsTag) varsTag.textContent = varsTag.textContent.replace(':root', ':host');
            });

            // 3.4.60 — this used to be a SECOND, separate click listener
            // duplicating the active-class toggle already done above (in
            // the OTHER top-level IIFE, alongside the 'bhel:select-surface'
            // dispatch) — removed. Having two independent listeners
            // toggle the same classes on the same buttons/frames wasn't
            // just redundant, it was the actual bug: this one ran
            // SECOND, after the other IIFE's dispatch had already fired
            // element-builder.js's renderDemoOutline() against the
            // STILL-previous active frame. See the other handler's own
            // 3.4.60 comment for the full fix.

            // Live-edits apply to EVERY registered surface at once, not
            // just the one currently visible — switching stories after
            // adjusting a color shouldn't show the old value on the
            // surface you hadn't looked at yet. This rebuilds the FULL
            // token set every time (colors, fonts, scale, radius, bar
            // height) rather than just colors — writing a partial :root
            // block into #bhy-vars would blow away whatever tokens
            // aren't included, since this replaces that tag's entire
            // textContent rather than patching individual declarations.
            window.refreshAllFrames = function () {
                var css = buildCssText();
                var brand1 = document.getElementById('brand_part1');
                var brand2 = document.getElementById('brand_part2');
                frames.forEach(function (f) {
                    var doc = f.shadowRoot;
                    if (!doc) return;
                    var tag = doc.getElementById('bhy-vars');
                    // :host, not :root — see the shadow-attach code above
                    // this IIFE for why (no root element inside a shadow
                    // tree for :root to match).
                    if (tag) tag.textContent = css.replace(':root', ':host');
                    // Best-effort: surfaces that render the brand wordmark
                    // with these specific ids (e.g. bh-contest's player
                    // header) get it updated live too. Surfaces without
                    // these ids simply no-op here.
                    if (brand1) { var b1 = doc.getElementById('bh-brand-1'); if (b1) b1.textContent = brand1.value.trim() || brand1.placeholder; }
                    if (brand2) { var b2 = doc.getElementById('bh-brand-2'); if (b2) b2.textContent = brand2.value.trim() || brand2.placeholder; }
                });
                // The always-visible token preview strip lives in the main
                // document (not an iframe), so it gets the same rebuilt
                // token text, just scoped to .bhy-token-preview instead of
                // :root — every slider stays visible regardless of which
                // registered surface happens (or doesn't) to use that token.
                var previewTag = document.getElementById('bhy-preview-vars');
                if (previewTag) previewTag.textContent = css.replace(':root', '.bhy-token-preview');
            };

            // Mirrors BHY_Style::font_family() — if a select is set to
            // "Custom", use its paired text field (falling back to the
            // same defaults BHY_Style::DEFAULTS uses if that's empty
            // too); otherwise use the picked font name directly.
            function pickedFontFamily(slot, fallback) {
                var select = document.getElementById('font_' + slot);
                if (!select) return fallback;
                if (select.value === 'Custom') {
                    var custom = document.getElementById('font_' + slot + '_custom');
                    var val = custom ? custom.value.trim() : '';
                    return val !== '' ? val : fallback;
                }
                return select.value;
            }

            // Builds the exact same set of CSS custom properties
            // BHY_Style::inline_css() computes server-side — colors,
            // font families, and every slider-controlled token — so the
            // live preview never drifts from what a save would actually
            // produce.
            function buildCssText() {
                var vars = {};
                document.querySelectorAll('.bhy-swatch-controls input[type=text]').forEach(function (input) {
                    var cssVarMap = {
                        color_bg: '--bh-bg', color_surface: '--bh-surface', color_surface_2: '--bh-surface-2',
                        color_border: '--bh-border', color_text: '--bh-text', color_text_dim: '--bh-text-dim',
                        color_accent: '--bh-accent', color_accent_soft: '--bh-accent-soft', color_overlay: '--bh-overlay',
                        cat_color_1: '--bh-cat-1', cat_color_2: '--bh-cat-2', cat_color_3: '--bh-cat-3', cat_color_4: '--bh-cat-4',
                        cat_color_5: '--bh-cat-5', cat_color_6: '--bh-cat-6', cat_color_7: '--bh-cat-7', cat_color_8: '--bh-cat-8',
                    };
                    var cssVar = cssVarMap[input.dataset.key];
                    if (cssVar) vars[cssVar] = input.value.trim() || input.placeholder;
                });

                var displayFamily = pickedFontFamily('display', 'Space Grotesk').replace(/["{};]/g, '').trim();
                var bodyFamily = pickedFontFamily('body', 'Inter').replace(/["{};]/g, '').trim();
                vars['--bh-font-display'] = '"' + displayFamily + '", sans-serif';
                vars['--bh-font-body'] = '"' + bodyFamily + '", sans-serif';

                var sliderVarMap = {
                    font_scale: ['--bh-font-scale', ''], space_scale: ['--bh-space-scale', ''],
                    radius: ['--bh-radius', 'px'], radius_sm: ['--bh-radius-sm', 'px'], bar_height: ['--bh-bar-height', 'px'],
                };
                Object.keys(sliderVarMap).forEach(function (key) {
                    var input = document.getElementById(key);
                    if (!input) return;
                    vars[sliderVarMap[key][0]] = input.value + sliderVarMap[key][1];
                });

                var out = ':root{';
                Object.keys(vars).forEach(function (k) { out += k + ':' + vars[k] + ';'; });
                out += '}';
                return out;
            }

            // Range sliders: update their own value label and push the
            // change to every preview frame. Previously these had no JS
            // at all — moving one did nothing.
            document.querySelectorAll('.bhy-slider-row input[type=range]').forEach(function (input) {
                var valSpan = document.getElementById(input.id + '_val');
                input.addEventListener('input', function () {
                    if (valSpan) valSpan.textContent = input.value + (input.dataset.unit || '');
                    refreshAllFrames();
                });
            });

            // Font selects: toggle the paired "Custom…" text field via
            // its data-custom-target attribute (previously rendered but
            // never read by anything) and refresh the preview.
            document.querySelectorAll('.bhy-font-field select[data-custom-target]').forEach(function (select) {
                var target = document.getElementById(select.dataset.customTarget);
                select.addEventListener('change', function () {
                    if (target) target.style.display = select.value === 'Custom' ? '' : 'none';
                    refreshAllFrames();
                });
            });

            // Custom-font text fields.
            document.querySelectorAll('.bhy-font-field input[type=text]').forEach(function (input) {
                input.addEventListener('input', refreshAllFrames);
            });

            // Brand wordmark fields.
            document.querySelectorAll('.bhy-brand-input').forEach(function (input) {
                input.addEventListener('input', refreshAllFrames);
            });

            var themeSelect = document.getElementById('bhy-theme-select');
            if (themeSelect) themeSelect.addEventListener('change', function () {
                var opt = themeSelect.options[themeSelect.selectedIndex];
                if (!opt || !opt.dataset.set) return;
                var data = JSON.parse(opt.dataset.set);
                Object.keys(data).forEach(function (key) {
                    var input = document.getElementById(key);
                    if (!input) return;
                    input.value = data[key];
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                });
            });
        })();
        </script>
        <?php
    }
}
