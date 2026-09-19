<?php
if (!defined('ABSPATH')) exit;

/**
 * BHY_Gallery — the actual "Storybook-patterned" UI: a sidebar listing
 * every registered surface (grouped by whichever plugin registered it),
 * a live preview canvas showing the selected one, and one shared
 * controls panel — colors, fonts, spacing, theme presets — that updates
 * whatever surface is currently visible in real time as you edit.
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
 *
 * PAGE-BUILDER-DELETE-KEEP-AUDIT.md (2026-07-13) — THIS FILE'S OWN
 * CLEANUP HISTORY, worth reading before touching it again: across an
 * earlier arc of this same project, this file grew a second, unrelated
 * job bolted on top of the one above — a hand-rolled Structure/Library
 * rail/canvas/inspector shell (render_shell(), render_left_rail(), the
 * Library canvas/inspector panes, ~1,400 lines of embedded JS) meant to
 * be a general-purpose visual page builder. That whole layer, and the
 * files it depended on (assets/js/element-builder.js, class-element-
 * builder.php, class-element-prefab.php, class-element-state.php,
 * class-component-studio.php and its own JS/CSS), has been DELETED —
 * not simplified, not deprecated, actually removed — after a real,
 * honest assessment concluded a custom page builder was solving a
 * problem WordPress's own block editor already solves, and every
 * genuinely custom piece of value (the BH_Element_Data data-binding
 * resolver, the Surface/Slot render_slot() engine real pages actually
 * use) lives elsewhere and was untouched by this cleanup. This file is
 * back to doing exactly the one job its own original docblock (above)
 * describes — nothing else should be added here that isn't "site-wide
 * design tokens with a live preview."
 *
 * render_script()'s live-preview JS keeps one real improvement from the
 * builder-era code that predates this cleanup and is worth keeping: each
 * `.bhy-story-frame` attaches its preview document under a real
 * `attachShadow({mode:'open'})` root instead of a same-origin `<iframe>`
 * (a live-confirmed fix, 3.4.55 — a real `<iframe>`'s `:root` doesn't
 * exist inside a shadow tree, so token CSS vars need `:host` instead;
 * see that block's own comment). Everything else below is the original,
 * pre-builder-era shape.
 */
class BHY_Gallery {
    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_bhy_save_settings', [self::class, 'save']);
        add_action('admin_post_bhy_restore_style_revision', [self::class, 'handle_restore_revision']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_media']);
    }

    public static function enqueue_media(string $hook): void {
        // Widened (DESIGN-SUITE-UNIFICATION-PLAN.md Phase 1) to also match
        // the 'bh-design' top-level hook — BH_Design_Suite::add_menu()
        // reuses this same render() callback as the Design Suite landing
        // page, under a different slug/hook, so the media picker needs to
        // load there too, not just on the standalone 'bh-style' submenu.
        if (strpos($hook, 'bh-style') === false && strpos($hook, 'bh-design') === false) return;
        wp_enqueue_media();

        // Font <option>s should preview in their real typeface.
        // BHY_UI::font_field()'s <option> tags carry an inline
        // font-family per option (class-ui.php), but that's cosmetically
        // useless without the actual webfont files loaded on THIS page —
        // this stylesheet used to only ever be enqueued INSIDE the
        // canvas preview docs (preview_doc() below), never on the real
        // admin page the <select> itself lives on.
        $font_url = class_exists('BHY_Style') ? BHY_Style::preview_all_fonts_url() : '';
        if ($font_url) wp_enqueue_style('bhy-font-preview', $font_url, [], null);

        // The shared colour resolver, so the live preview's buildCssText()
        // derives --bh-on-* / --bh-text-muted / --bh-focus / … from the
        // same recipe table (BHY_Contrast::spec()) a save runs through —
        // one spec, two engines (PHP + this JS), no third hand-kept list.
        if (class_exists('BHY_Contrast')) {
            wp_enqueue_script('bhy-contrast-core', OUS_URL . 'assets/js/contrast-core.js', [], OUS_VER, false);
            wp_localize_script('bhy-contrast-core', 'bhyContrastSpec', BHY_Contrast::spec());
        }
    }

    // DESIGN-SUITE-UNIFICATION-PLAN.md Phase 1 — relocated from a
    // submenu of 'the-self-hosted-self' to a submenu of the top-level 'bh-design'
    // ("Design Suite") menu. Slug ('bh-style') and callback are
    // UNCHANGED, so every existing admin.php?page=bh-style deep link
    // keeps working. Capability is 'bhcore_design_site' (class-roles.php,
    // granted to administrator + editor), not 'manage_options', so a
    // non-admin employee can reach this page.
    //
    // OUS_VER 3.4.31 — real duplication fix, still true after this
    // cleanup: parent is null ("hidden, reachable by direct link only"),
    // the SAME pattern class-studio.php's own add_menu() uses — adding a
    // real second submenu under 'bh-design' corrupts WordPress's own
    // pairing of the bare 'admin.php?page=bh-design' request with its
    // intended callback (BH_Design_Suite::add_menu() is the one real,
    // visible top-level entry; this hidden page is what it actually
    // renders).
    public static function add_menu(): void {
        $hook = add_submenu_page(null, 'Designer', 'Designer', 'bhcore_design_site', 'bh-style', [self::class, 'render']);
        // Only the failure case is worth a log row — this used to fire
        // an INFO row for every successful registration too, throttled
        // only to once per 60 seconds, on every admin page load.
        if ($hook === false && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('error',
                'add_submenu_page() for Designer (bh-style, hidden/null parent) FAILED (returned false).',
                [], 'BHY_Gallery::add_menu()'
            );
        }
    }

    /* ---------- saving (unchanged shape from the original settings page) ---------- */

    public static function save(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bhy_save_settings');

        // BHY_Style::save_from_input() is the single authority for this
        // sanitize pass — see its docblock for why (this used to be
        // hand-copied here and in BH_Element::rest_save_site_tokens(),
        // and the two copies had already drifted).
        $data = BHY_Style::save_from_input($_POST);

        update_option(BHY_Style::OPTION, $data);

        if (class_exists('OUS_Revisions')) {
            OUS_Revisions::snapshot('bhy_style', 1, $data);
        }

        wp_safe_redirect(add_query_arg(['page' => 'bh-style', 'saved' => '1'], admin_url('admin.php')));
        exit;
    }

    // OUS_Revisions::render_history_panel()'s Restore link points here.
    // Writes the stored snapshot straight back to the option — same
    // direct-restore reasoning bh_contest's own restore handler uses
    // (the snapshot IS already the target shape, no need to re-simulate
    // a fake $_POST through save() itself).
    public static function handle_restore_revision(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        $version = (int) ($_GET['version'] ?? 0);
        if (!isset($_GET['ous_revisions_nonce']) || !wp_verify_nonce($_GET['ous_revisions_nonce'], 'bhy_restore_style')) {
            wp_die('Invalid request.');
        }

        $snapshot = class_exists('OUS_Revisions') ? OUS_Revisions::get_version('bhy_style', 1, $version) : null;
        if (!$snapshot) wp_die('That version no longer exists.');

        update_option(BHY_Style::OPTION, $snapshot['data']);

        if (class_exists('OUS_Revisions')) {
            OUS_Revisions::snapshot('bhy_style', 1, $snapshot['data'], 'Restored from version #' . $version);
        }
        if (class_exists('OUS_Toast')) {
            OUS_Toast::queue('Restored version #' . $version . '.', 'success');
        }

        wp_safe_redirect(add_query_arg(['page' => 'bh-style'], admin_url('admin.php')));
        exit;
    }

    /* ---------- the gallery page ---------- */

    public static function render(): void {
        $s = BHY_Style::get();
        // Flatten registered custom-slider values onto $s under
        // 'custom_<key>' so render_controls() can hand them straight to
        // BHY_UI::slider_row() exactly like a built-in field — no
        // separate code path for "a plugin's slider" vs. "our slider".
        foreach (BHY_Style::custom_sliders() as $key => $def) {
            $s['custom_' . $key] = $s['custom'][$key] ?? ($def['default'] ?? 0);
        }
        // Same flattening for the font analogue (custom_fonts()) — a
        // distinct 'customfont_' prefix so a font field's id/name can
        // never collide with a numeric slider field of the same key.
        foreach (BHY_Style::custom_fonts() as $key => $def) {
            $s['customfont_' . $key] = $s['custom_fonts'][$key] ?? ($def['default'] ?? '');
        }
        // Same flattening for grouped component tokens — 'comp_<group>_<key>'
        // so each one hands straight to slider_row()/swatch_field() exactly
        // like a built-in field.
        foreach (BHY_Style::component_tokens() as $group => $def) {
            foreach (($def['tokens'] ?? []) as $key => $tdef) {
                $s['comp_' . $group . '_' . $key] = $s['components'][$group][$key] ?? ($tdef['default'] ?? '');
            }
        }
        $surfaces = apply_filters('bhy_style_surfaces', []);
        $grouped = [];
        foreach ($surfaces as $key => $surface) $grouped[$surface['group']][$key] = $surface;

        echo '<div class="wrap bhy-gallery">';
        echo '<h1>Design Suite</h1>';
        if (isset($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';

        // Phase 3 — the "Components" section's live counterpart: the same
        // granular tokens, edited from inside the native WP Customizer
        // while looking at the real rendered page (BHY_Customizer). The
        // Customizer's own `return` param is what sends its native Close
        // (X) button back HERE — no bespoke "switch back" UI needed, just
        // wiring the URL WordPress already understands.
        if (class_exists('BHY_Customizer')) {
            $customize_url = add_query_arg([
                'url'                    => rawurlencode(BHY_Customizer::default_preview_url()),
                'autofocus[panel]'       => 'bhy_live_design',
                'return'                 => rawurlencode(admin_url('admin.php?page=bh-style')),
            ], admin_url('customize.php'));
            echo '<p class="bhy-live-editor-cta"><a href="' . esc_url($customize_url) . '" class="button button-primary">Open Live Editor — edit Components on the real page</a></p>';
            echo '<p class="description">Same Component tokens as below, previewed against the actual site — and its own Custom CSS section (with the exact same rule builder as the one on this page, further down) so you never have to leave it. Inside it: <strong>click</strong> any styled element (a card, badge, button…) to jump straight to its controls; <strong>right-click</strong> for a menu of every matching component at that point (useful when one is nested inside another), plus "Add custom rule for this element" and "Copy CSS selector". Its own Close (X) button brings you back here.</p>';
        }

        // Hidden for now (AJ, 2026-09-19) as part of pruning Design Suite
        // down to front-end/user-facing surfaces — this is a dev-tool
        // panel (Node build + UX audit runner), same category as the
        // admin wizards already pruned. Set to false to bring it back;
        // the panel/class itself is untouched.
        $show_storybook_panel = false;
        if ($show_storybook_panel && class_exists('BH_Storybook_Panel')) BH_Storybook_Panel::render();

        echo '<div class="bhy-layout">';
        self::render_sidebar($grouped);
        self::render_canvas($surfaces, $s);
        self::render_controls($s);
        echo '</div></div>';

        self::render_script($surfaces, $s);
    }

    /** @param array<string, array<string, mixed>> $grouped */
    private static function render_sidebar($grouped): void {
        echo '<div class="bhy-sidebar">';
        if (!$grouped) {
            echo BHY_Style::empty_state_html([
                'title' => 'No surfaces registered yet',
                'description' => 'A plugin registers one via the bhy_style_surfaces filter — once it does, it shows up here to preview and theme.',
            ]);
        }
        $first = true;
        foreach ($grouped as $group_label => $items) {
            echo '<div class="bhy-sidebar-group">' . esc_html($group_label) . '</div>';
            foreach ($items as $key => $surface) {
                echo '<button type="button" class="bhy-story-btn' . ($first ? ' active' : '') . '" data-surface="' . esc_attr($key) . '">' . esc_html($surface['label']) . '</button>';
                $first = false;
            }
        }
        echo '</div>';
    }

    // No-iframes build — content is attached under a real
    // attachShadow({mode:'open'}) root (render_script()'s own job) rather
    // than a real same-origin <iframe>, so this emits a plain <div> with
    // the whole preview document base64-encoded into a data attribute,
    // not an <iframe src="...">.
    /**
     * @param array<string, mixed> $surfaces
     * @param array<string, mixed> $s
     */
    private static function render_canvas($surfaces, $s): void {
        echo '<div class="bhy-canvas">';
        $first = true;
        foreach ($surfaces as $key => $surface) {
            $payload = call_user_func($surface['render']);
            echo '<div class="bhy-story-frame' . ($first ? ' active' : '') . '" data-surface="' . esc_attr($key) . '" data-doc="' . esc_attr(base64_encode(self::preview_doc($payload, $s))) . '"></div>';
            $first = false;
        }
        if (!$surfaces) echo BHY_Style::empty_state_html([
            'title' => 'Nothing to preview yet',
            'description' => 'Once a plugin registers a surface, its live preview renders right here.',
        ]);
        echo '</div>';
    }

    // One real HTML document per surface — the surface's own stylesheet,
    // the current tokens as CSS vars (with a stable id so the live-edit
    // JS can rewrite just that tag), and the surface's real markup.
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $s
     */
    private static function preview_doc($payload, $s): string {
        $font_url = BHY_Style::preview_all_fonts_url();
        return '<!doctype html><html><head><meta charset="utf-8">'
            . ($font_url ? '<link rel="stylesheet" href="' . esc_url($font_url) . '">' : '')
            . (!empty($payload['css_url']) ? '<link rel="stylesheet" href="' . esc_url($payload['css_url']) . '">' : '')
            . '<style id="bhy-vars">' . BHY_Style::inline_css() . '</style>'
            // Shadow-DOM equivalent of "the box everything sits inside" —
            // there is no real <body> once this is attached under a
            // shadow root (render_script() moves only the children over),
            // so a `body{...}` selector would match nothing; `:host`
            // targets the .bhy-story-frame div itself, which every moved
            // child then fills exactly like a real <body> would.
            . '<style>:host{display:block;margin:0;background:var(--bh-bg);color:var(--bh-text);font-family:var(--bh-font-body);}</style>'
            . '</head><body>' . $payload['html'] . '</body></html>';
    }

    // A small, always-visible strip of sample chips that directly apply
    // every scale/shape token (radius, radius_sm, bar_height, font_scale,
    // space_scale) to real elements right here in the controls panel.
    // Exists because no single registered preview surface is guaranteed
    // to visibly use every token at once (e.g. the default Player surface
    // never shows --bh-radius without opening a modal) — this gives every
    // slider instant, surface-independent feedback instead.
    /** @param array<string, mixed> $s */
    private static function render_token_preview($s): void {
        echo '<div class="bhy-token-preview" id="bhy-token-preview">';
        echo '<div class="bhy-token-chip bhy-token-chip-radius">Card <span>radius</span></div>';
        echo '<div class="bhy-token-chip bhy-token-chip-radius-sm">Chip <span>radius_sm</span></div>';
        echo '<button type="button" class="bhy-token-pill">Pill button</button>';
        echo '<div class="bhy-token-bar" title="bar_height"><span>Now-playing bar height</span></div>';
        echo '<div class="bhy-token-text"><strong>Aa</strong> font_scale &amp; space_scale</div>';
        echo '</div>';
    }

    // Section headers use small-caps/underline styling (.bhy-controls h3,
    // class-ui.php); anything that isn't a small, always-relevant core
    // set is a collapsible "<details class='bhel-style-group'>"
    // disclosure (CSS ported into class-ui.php's shared admin_page_css()
    // as part of this file's builder-era cleanup — see this file's own
    // top docblock). Color swatches render through BHY_UI::swatch_field().
    /** @param array<string, mixed> $s */
    private static function render_controls($s, string $default_group = 'brand'): void {
        echo '<div class="bhy-controls" id="bhy-controls-panel">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="bhy-form">';
        wp_nonce_field('bhy_save_settings');
        echo '<input type="hidden" name="action" value="bhy_save_settings">';

        // This panel used to say "Live token preview" with zero
        // connection to whichever surface is actually selected in the
        // canvas, reading as if IT were the live view. Renamed to be
        // honest about what this widget is (a fixed reference strip for
        // shape/scale tokens that many surfaces never exercise visibly,
        // e.g. Player never shows --bh-radius without opening a modal)
        // and added a "Previewing: X" label that updates live from the
        // canvas selection (render_script()'s surface-switch handler).
        echo '<p class="description" style="margin:0 0 4px;">Editing global styles — applies everywhere. Previewing: <strong id="bhy-current-surface-label">&hellip;</strong></p>';
        echo '<h3>Shape &amp; scale reference <span class="description" style="text-transform:none;font-weight:400;">(always the same, not tied to the surface above)</span></h3>';
        self::render_token_preview($s);

        echo '<div class="bhy-token-group" data-token-group="brand">';
        echo '<h3>Brand</h3>';
        echo '<div class="bhel-field-row"><label>Wordmark</label><p style="display:flex;gap:8px;margin:6px 0 0;"><input type="text" id="brand_part1" name="brand_part1" class="bhy-brand-input" value="' . esc_attr($s['brand_part1']) . '" placeholder="First part" style="flex:1;"> <input type="text" id="brand_part2" name="brand_part2" class="bhy-brand-input" value="' . esc_attr($s['brand_part2']) . '" placeholder="Accent part" style="flex:1;"></p></div>';

        // BHY_Style::logo_url()/'brand_logo_id' are part of the data
        // model and save() handler, and the player's own header already
        // renders a logo when one is set (BHY_Style::get_brand_payload(),
        // 'logoUrl') — but the inspector had no upload control for it,
        // so brand_logo_id could never actually be set from this screen.
        // wp.media() is already enqueued on this page (enqueue_media()),
        // so this needed no new asset, just the missing control — same
        // upload-button/preview shape bh-streaming's artwork picker uses
        // (class-admin.php's pick() helper).
        $logo_id = (int) ($s['brand_logo_id'] ?? 0);
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';
        echo '<div class="bhel-field-row" style="margin-top:14px;">';
        echo '<label>Logo <span class="description">(optional — shown instead of the wordmark text above wherever a surface renders one)</span></label>';
        echo '<div style="display:flex;align-items:center;gap:12px;margin-top:6px;">';
        echo '<div id="bhy-logo-preview" style="width:64px;height:64px;border:1px solid var(--bhy-border,#dcdcde);border-radius:6px;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:0 0 auto;">' . ($logo_url ? '<img src="' . esc_url($logo_url) . '" style="width:100%;height:100%;object-fit:contain;">' : '<span class="description" style="font-size:11px;">None</span>') . '</div>';
        echo '<input type="hidden" id="brand_logo_id" name="brand_logo_id" value="' . esc_attr((string) $logo_id) . '">';
        echo '<span><button type="button" class="button" id="bhy-logo-upload">' . ($logo_id ? 'Change logo' : 'Upload logo') . '</button> <button type="button" class="button-link" id="bhy-logo-clear" style="' . ($logo_id ? '' : 'display:none;') . 'color:#b32d2e;margin-left:6px;">Remove</button></span>';
        echo '</div></div>';
        echo '</div>';

        echo '<div class="bhy-token-group" data-token-group="colors">';
        // Real gap this closes: a preset's whole selling point is "apply
        // this instantly," but a plain <select><option> can't show what
        // any of them actually LOOK like before picking one — the single
        // highest-leverage "instant delight" moment on this page was
        // hidden behind the most boring possible control. Each swatch
        // renders 4 real dots straight from the preset's own color
        // values (bg/surface/accent/text), so this is never a second,
        // drifting copy of the palette — it's the same data
        // render_script()'s click handler below reads via data-set.
        echo '<div class="bhel-field-row"><label>Quick theme</label>';
        echo '<div class="bhy-theme-swatch-groups">';
        foreach (BHY_Style::THEME_GROUPS as $group_label => $themes) {
            echo '<div class="bhy-theme-swatch-group-label">' . esc_html($group_label) . '</div>';
            echo '<div class="bhy-theme-swatch-row">';
            foreach ($themes as $name => $colors) {
                echo '<button type="button" class="bhy-theme-swatch" data-set=\'' . esc_attr(wp_json_encode($colors)) . '\' title="' . esc_attr($name) . '">';
                echo '<span class="bhy-theme-swatch-preview" style="background:' . esc_attr($colors['color_bg']) . ';">';
                echo '<span style="background:' . esc_attr($colors['color_surface']) . ';"></span>';
                echo '<span style="background:' . esc_attr($colors['color_accent']) . ';"></span>';
                echo '<span style="background:' . esc_attr($colors['color_text']) . ';"></span>';
                echo '</span>';
                echo '<span class="bhy-theme-swatch-name">' . esc_html($name) . '</span>';
                echo '</button>';
            }
            echo '</div>';
        }
        echo '</div></div>';

        // Core colors — always visible, not tucked behind a disclosure.
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
        // disclosures instead of one long always-expanded wall of fields.
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

        // Plugin-registered custom sliders — rendered with the exact
        // same BHY_UI::slider_row() the built-ins above use, in the same
        // group style as "Scale", so a peer plugin's own token shows up
        // looking like a first-class part of this page, not a bolted-on
        // extra. See class-style.php's custom_sliders() docblock for the
        // registration filter a plugin calls from its own bootstrap.
        $custom_sliders = BHY_Style::custom_sliders();
        if ($custom_sliders) {
            echo '<div class="bhy-token-group" data-token-group="custom">';
            echo '<h3>Plugin adjustments</h3>';
            foreach ($custom_sliders as $key => $def) {
                BHY_UI::slider_row('custom_' . $key, $def['label'] ?? $key, $s, $def['min'] ?? 0, $def['max'] ?? 100, $def['step'] ?? 1, $def['unit'] ?? '');
            }
            echo '</div>';
        }

        // Plugin-registered custom fonts — same "shows up for free"
        // treatment as the sliders above, one plain text field per
        // registration (see BHY_Style::custom_fonts()'s docblock).
        $custom_fonts = BHY_Style::custom_fonts();
        if ($custom_fonts) {
            echo '<div class="bhy-token-group" data-token-group="custom-fonts">';
            echo '<h3>Plugin fonts</h3>';
            foreach ($custom_fonts as $key => $def) {
                $field_id = 'customfont_' . $key;
                echo '<div class="bhel-field-row"><label for="' . esc_attr($field_id) . '">' . esc_html($def['label'] ?? $key) . '</label> ';
                echo '<input type="text" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id) . '" value="' . esc_attr($s[$field_id] ?? '') . '" class="regular-text" placeholder="' . esc_attr($def['default'] ?? '') . '"></div>';
            }
            echo '</div>';
        }

        // Grouped component tokens (BHY_Style::component_tokens()) — the
        // Phase-1 "real granular control over every little thing" surface:
        // one collapsible <details> per registered COMPONENT (a card, a
        // badge, a button — not one per property), so a dozen-plus small
        // sizing/color/font properties for one real UI piece read as a
        // single named, scannable section rather than flooding "Plugin
        // adjustments" with a hundred ungrouped sliders. Collapsed by
        // default, same posture as "Advanced colors"/"Category colors"
        // above — most visits don't need to open most of these.
        $components = BHY_Style::component_tokens();
        if ($components) {
            echo '<div class="bhy-token-group" data-token-group="components">';
            echo '<h3>Components <span class="description" style="text-transform:none;font-weight:400;">(granular control per real UI piece — card, badge, button, etc.)</span></h3>';
            foreach ($components as $group => $def) {
                echo '<details class="bhel-style-group"><summary class="bhel-style-group-title">' . esc_html($def['label'] ?? $group) . '</summary><div class="bhel-style-group-body">';
                $color_tokens = [];
                foreach (($def['tokens'] ?? []) as $key => $tdef) {
                    $field_id = 'comp_' . $group . '_' . $key;
                    $type = $tdef['type'] ?? 'size';
                    if ($type === 'color') {
                        $color_tokens[$key] = $tdef; // batched into one swatch grid below, same as the built-in color sections
                        continue;
                    }
                    if ($type === 'font') {
                        echo '<div class="bhel-field-row"><label for="' . esc_attr($field_id) . '">' . esc_html($tdef['label'] ?? $key) . '</label> ';
                        echo '<input type="text" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id) . '" value="' . esc_attr($s[$field_id] ?? '') . '" class="regular-text bhy-comp-font" placeholder="' . esc_attr($tdef['default'] ?? '') . '"></div>';
                        continue;
                    }
                    if ($type === 'toggle') {
                        echo '<div class="bhel-field-row"><label><input type="checkbox" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id) . '" value="1" class="bhy-comp-toggle"' . checked(!empty($s[$field_id]), true, false) . '> ' . esc_html($tdef['label'] ?? $key) . '</label></div>';
                        continue;
                    }
                    BHY_UI::slider_row($field_id, $tdef['label'] ?? $key, $s, $tdef['min'] ?? 0, $tdef['max'] ?? 100, $tdef['step'] ?? 1, $tdef['unit'] ?? 'px');
                }
                if ($color_tokens) {
                    echo '<div class="bhy-swatch-grid">';
                    foreach ($color_tokens as $key => $tdef) {
                        $field_id = 'comp_' . $group . '_' . $key;
                        BHY_UI::swatch_field($field_id, $field_id, $tdef['label'] ?? $key, (string) ($s[$field_id] ?? ''), (string) ($tdef['default'] ?? ''));
                    }
                    echo '</div>';
                }
                echo '</div></details>';
            }
            echo '</div>';
        }

        // The granular escape hatch beyond the registered Components
        // above — a VISUAL rule editor (AJ, 2026-09-19: "actual controls
        // for editing the css properties, not just a custom CSS text
        // box"): pick a selector (filled in from the Customizer's
        // click-to-select right-click menu — either "Copy CSS selector"
        // pasted here, or the newer "Add custom rule for this element"
        // which deep-links straight into a prefilled new rule below, see
        // BHY_Customizer/customizer-preview.ts), an optional state
        // (hover/focus/etc — css_state_options()), then add one or more
        // real properties from css_property_registry() — each renders
        // its OWN correctly-typed control (color picker, ranged
        // slider+unit, a closed dropdown for keyword properties, a font
        // field), not a text field to hand-type a value into. Declared
        // rules are stored structured (BHY_Style::save_from_input()) and
        // turned into real CSS text at emission time
        // (BHY_Style::inline_css()). The raw textarea underneath remains
        // for anything even this doesn't cover.
        echo '<div class="bhy-token-group" data-token-group="custom-css" id="custom-css">';
        echo '<h3>Custom CSS <span class="description" style="text-transform:none;font-weight:400;">(for anything the Components above don\'t cover)</span></h3>';
        echo '<p class="description">This is the exact same rule builder as the <a href="' . esc_url(add_query_arg(['url' => rawurlencode(class_exists('BHY_Customizer') ? BHY_Customizer::default_preview_url() : home_url('/'))], admin_url('customize.php'))) . '">Live Editor</a>\'s own Custom CSS section — right-clicking an element there adds a rule directly in the Customizer without leaving it, but you can build rules here just as well, or come back to fine-tune one later. <strong>How to get a selector without opening the Live Editor:</strong> not really practical by hand — right-click is the way. Preview: reload the real page after saving (rules here don\'t live-preview in the canvas above, since those previews are sandboxed per-surface and a real page selector wouldn\'t match anything in them anyway).</p>';

        $rules = is_array($s['custom_css_rules'] ?? null) ? $s['custom_css_rules'] : [];
        // A rule started via the Customizer's "Add custom rule for this
        // element" deep-link (?add_selector=...) lands as one extra,
        // still-empty rule pre-filled with that selector, appended after
        // whatever's already saved — never silently discarded, and
        // never auto-saved until the admin actually presses Save.
        $prefill_selector = isset($_GET['add_selector']) ? BHY_Style::sanitize_css_selector(wp_unslash($_GET['add_selector'])) : '';
        if ($prefill_selector !== '') $rules[] = ['selector' => $prefill_selector, 'state' => '', 'declarations' => []];

        echo '<div id="bhy-css-rules">';
        foreach (array_values($rules) as $i => $rule) {
            self::render_css_rule_row($i, is_array($rule) ? $rule : []);
        }
        echo '</div>';
        echo '<button type="button" id="bhy-add-rule" class="button">+ Add rule</button>';

        echo '<h4 style="margin-top:24px;">Advanced / raw CSS</h4>';
        echo '<p class="description">Real CSS text, for anything the rule editor above can\'t express.</p>';
        echo '<textarea name="custom_css" id="custom_css" rows="6" class="large-text code" placeholder="' . esc_attr('.some-selector {' . "\n" . '    /* ... */' . "\n" . '}') . '">' . esc_textarea((string) ($s['custom_css'] ?? '')) . '</textarea>';
        echo '</div>';

        echo '<p class="submit"><button type="submit" class="button button-primary">Save</button></p>';

        // OUS_Revisions consumer, ROADMAP-search-and-revisions.md
        // Section 2's last named candidate. A single, site-wide config
        // (BHY_Style::OPTION is one option, not a per-post object) —
        // object_id is a constant 1 rather than a real post/entity ID,
        // since there's only ever one of these on the whole site.
        // render_history_panel() itself is just links, not a <form>
        // (fixed earlier this same pass), so it's safe to nest inside
        // this page's own big <form id="bhy-form"> without repeating
        // the nested-form bug that broke BHM_Tiers/bh_contest saves.
        if (class_exists('OUS_Revisions')) {
            echo '<div class="bhy-token-group" data-token-group="revisions">';
            echo '<h3>Version History</h3>';
            OUS_Revisions::render_history_panel('bhy_style', 1, 'bhy_restore_style_revision', 'bhy_restore_style');
            echo '</div>';
        }

        echo '</form></div>';
    }

    /**
     * One row of the visual Custom CSS rule editor — a selector, an
     * optional state, and its own list of typed property controls. Also
     * called from JS's mirrored buildRuleRow() when "+ Add rule" is
     * clicked, so the markup/name conventions here (custom_css_rules[i]
     * [selector|state|props[j][property|value]]) MUST match that JS
     * exactly, or a newly-added rule would silently fail to save.
     *
     * @param array<string, mixed> $rule
     */
    private static function render_css_rule_row(int $i, array $rule): void {
        $selector = (string) ($rule['selector'] ?? '');
        $state = (string) ($rule['state'] ?? '');
        $declarations = is_array($rule['declarations'] ?? null) ? $rule['declarations'] : [];
        echo '<div class="bhy-css-rule" data-rule-index="' . esc_attr((string) $i) . '">';
        echo '<div class="bhy-css-rule-header">';
        echo '<input type="text" class="regular-text code" name="custom_css_rules[' . esc_attr((string) $i) . '][selector]" value="' . esc_attr($selector) . '" placeholder=".some-selector">';
        echo '<select name="custom_css_rules[' . esc_attr((string) $i) . '][state]">';
        foreach (BHY_Style::css_state_options() as $val => $label) {
            echo '<option value="' . esc_attr($val) . '"' . selected($state, $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<button type="button" class="button-link-delete bhy-remove-rule">Remove rule</button>';
        echo '</div>';
        echo '<div class="bhy-css-rule-props">';
        $j = 0;
        foreach ($declarations as $property => $value) {
            self::render_css_prop_row($i, $j, (string) $property, (string) $value);
            $j++;
        }
        echo '</div>';
        echo '<button type="button" class="button bhy-add-prop">+ Add property</button>';
        echo '</div>';
    }

    /** One property row inside a rule — a property picker plus whatever control that property's registered type needs. */
    private static function render_css_prop_row(int $i, int $j, string $property, string $value): void {
        $registry = BHY_Style::css_property_registry();
        echo '<div class="bhy-css-prop-row" data-prop-index="' . esc_attr((string) $j) . '">';
        echo '<select class="bhy-prop-select" name="custom_css_rules[' . esc_attr((string) $i) . '][props][' . esc_attr((string) $j) . '][property]">';
        echo '<option value="">Choose a property…</option>';
        foreach ($registry as $key => $def) {
            echo '<option value="' . esc_attr($key) . '"' . selected($property, $key, false) . '>' . esc_html($def['label'] ?? $key) . '</option>';
        }
        echo '</select>';
        echo '<span class="bhy-prop-control-slot">';
        if ($property !== '' && isset($registry[$property])) {
            self::render_css_prop_control($i, $j, $property, $registry[$property], $value);
        }
        echo '</span>';
        echo '<button type="button" class="button-link-delete bhy-remove-prop">&times;</button>';
        echo '</div>';
    }

    /**
     * The actual typed control for one property — mirrors JS's
     * buildPropControl() in render_script() exactly (same field name,
     * same control shape per type) so a control rendered here on page
     * load and one built by JS after picking a property from the
     * dropdown are indistinguishable to the save handler.
     *
     * @param array<string, mixed> $def
     */
    private static function render_css_prop_control(int $i, int $j, string $property, array $def, string $value): void {
        $name = 'custom_css_rules[' . $i . '][props][' . $j . '][value]';
        $type = $def['type'] ?? 'text';
        if ($type === 'color') {
            $val = $value !== '' ? $value : (string) ($def['default'] ?? '#000000');
            echo '<input type="color" name="' . esc_attr($name) . '" value="' . esc_attr($val) . '">';
        } elseif ($type === 'keyword') {
            $options = $def['options'] ?? [];
            echo '<select name="' . esc_attr($name) . '">';
            foreach ($options as $opt_val => $opt_label) {
                echo '<option value="' . esc_attr($opt_val) . '"' . selected($value, $opt_val, false) . '>' . esc_html($opt_label) . '</option>';
            }
            echo '</select>';
        } elseif ($type === 'font') {
            // Stored value already carries the fallback ("Inter", sans-
            // serif) — strip it back down to just the font name for the
            // editable field, matching how component-token font fields
            // already round-trip.
            $display = preg_replace('/^"?([^",]+)"?.*$/', '$1', $value);
            echo '<input type="text" class="regular-text" name="' . esc_attr($name) . '" value="' . esc_attr($display) . '" placeholder="' . esc_attr($def['default'] ?? 'Inter') . '">';
        } else { // 'size'
            $num = is_numeric($value) ? $value : preg_replace('/[a-z%]+$/i', '', $value);
            $num = $num !== '' && is_numeric($num) ? $num : ($def['default'] ?? 0);
            echo '<input type="number" step="' . esc_attr((string) ($def['step'] ?? 1)) . '" min="' . esc_attr((string) ($def['min'] ?? '')) . '" max="' . esc_attr((string) ($def['max'] ?? '')) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $num) . '" style="width:90px;"> <span class="description">' . esc_html($def['unit'] ?? '') . '</span>';
        }
    }

    /**
     * @param array<string, mixed> $surfaces
     * @param array<string, mixed> $s
     */
    private static function render_script($surfaces, $s): void {
        ?>
        <style><?php echo BHY_UI::admin_page_css(); ?></style>
        <style>
        /* Visual Custom CSS rule editor — kept out of BHY_UI::admin_page_css()'s
           own giant single-quoted string on purpose (a stray apostrophe in a
           comment there once took the whole file down; see CLAUDE.md).
           Every row is flex-wrap + min-width:0 on its text inputs/selects —
           the controls column here is only ~380px, and a plain flex row of
           a text input + a dropdown + a button will happily force itself
           wider than that and clip/scroll unless the input is explicitly
           allowed to shrink below its intrinsic content width. */
        .bhy-css-rule { border: 1px solid var(--bhy-border, #dcdcde); border-radius: var(--bhy-radius-sm, 6px); padding: 12px; margin-bottom: 10px; background: var(--bhy-surface, #fff); transition: border-color var(--bhy-transition, 150ms ease); }
        .bhy-css-rule:focus-within { border-color: var(--bhy-accent, #2271b1); }
        .bhy-css-rule-header { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 10px; max-width: 100%; }
        .bhy-css-rule-header input[type="text"] { flex: 1 1 160px; min-width: 0; }
        .bhy-css-rule-header select { flex: 0 1 auto; min-width: 0; max-width: 100%; }
        .bhy-css-rule-header .bhy-remove-rule { flex: 0 0 auto; white-space: nowrap; }
        .bhy-css-rule-props { display: flex; flex-direction: column; gap: 8px; margin-bottom: 10px; }
        .bhy-css-prop-row { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; max-width: 100%; padding-bottom: 8px; border-bottom: 1px solid var(--bhy-border, #f0f0f1); }
        .bhy-css-prop-row:last-child { border-bottom: none; padding-bottom: 0; }
        .bhy-css-prop-row .bhy-prop-select { flex: 1 1 140px; min-width: 0; max-width: 100%; }
        .bhy-css-prop-control-slot, .bhy-prop-control-slot { display: inline-flex; align-items: center; gap: 4px; flex: 1 1 120px; min-width: 0; max-width: 100%; }
        .bhy-prop-control-slot input[type="text"], .bhy-prop-control-slot input[type="number"] { min-width: 0; max-width: 100%; }
        .bhy-css-prop-row .bhy-remove-prop { flex: 0 0 auto; }
        #bhy-css-rules:empty::before { content: "No custom rules yet — add one below, or right-click an element in the Live Editor."; display: block; color: var(--bhy-ink-dim, #787c82); font-style: italic; font-size: 13px; padding: 4px 0 12px; }
        </style>
        <style id="bhy-preview-vars"><?php echo str_replace(':root', '.bhy-token-preview', BHY_Style::inline_css(null, false)); ?></style>
        <script>
        <?php echo BHY_UI::swatch_js("refreshAllFrames();"); ?>
        // Schema for grouped component tokens (BHY_Style::component_tokens())
        // — read directly by buildCssText() below rather than parsed back
        // out of a field id, since a group or key can itself contain an
        // underscore and "comp_<group>_<key>" isn't otherwise unambiguous.
        var bhyComponentTokens = <?php echo wp_json_encode(BHY_Style::component_tokens()); ?>;
        (function () {
            var frames = document.querySelectorAll('.bhy-story-frame');
            var buttons = document.querySelectorAll('.bhy-story-btn');
            var currentLabel = document.getElementById('bhy-current-surface-label');

            // Real usability fix: the inspector's controls are genuine
            // GLOBAL tokens (one theme, applied everywhere —
            // this was never per-surface theming, see this file's own
            // top docblock), so there's nothing surface-specific for
            // them to show when you switch surfaces. What WAS missing
            // is any visible link between "the surface I just clicked"
            // and "the controls I'm about to touch" — this just keeps
            // that one label in sync with the real canvas selection.
            function setCurrentLabel(btn) {
                if (currentLabel && btn) currentLabel.textContent = btn.textContent;
            }

            buttons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    buttons.forEach(function (b) { b.classList.remove('active'); });
                    frames.forEach(function (f) { f.classList.remove('active'); });
                    btn.classList.add('active');
                    var match = document.querySelector('.bhy-story-frame[data-surface="' + btn.dataset.surface + '"]');
                    if (match) match.classList.add('active');
                    setCurrentLabel(btn);
                });
            });
            setCurrentLabel(document.querySelector('.bhy-story-btn.active') || buttons[0]);

            // Logo upload — wp.media() is already enqueued on this page
            // (BHY_Gallery::enqueue_media()), reused here rather than a
            // new asset. Same upload-button/preview/clear shape
            // bh-streaming's own artwork picker uses.
            (function () {
                var uploadBtn = document.getElementById('bhy-logo-upload');
                var clearBtn = document.getElementById('bhy-logo-clear');
                var hidden = document.getElementById('brand_logo_id');
                var preview = document.getElementById('bhy-logo-preview');
                if (!uploadBtn) return;
                // Real bug: this script prints inline as part of the
                // page's own content, before wp_footer runs — and
                // wp.media()'s own scripts (enqueued via wp_enqueue_media())
                // load in the footer, so `window.wp.media` doesn't exist
                // yet at THIS script's execution time even though it's
                // fully available by the time a user actually clicks.
                // Bailing here at setup time (the original bug) meant the
                // click listener never got attached at all, so the button
                // silently did nothing forever. Checking wp.media lazily,
                // inside the handler, is the real fix.
                var frame = null;
                uploadBtn.addEventListener('click', function () {
                    if (!window.wp || !window.wp.media) return;
                    if (frame) { frame.open(); return; }
                    frame = wp.media({ title: 'Choose a logo', button: { text: 'Use this' }, multiple: false, library: { type: 'image' } });
                    frame.on('select', function () {
                        var att = frame.state().get('selection').first().toJSON();
                        hidden.value = att.id;
                        var thumbUrl = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
                        preview.innerHTML = '<img src="' + thumbUrl + '" style="width:100%;height:100%;object-fit:contain;">';
                        uploadBtn.textContent = 'Change logo';
                        clearBtn.style.display = '';
                        if (window.refreshAllFrames) window.refreshAllFrames();
                    });
                    frame.open();
                });
                clearBtn.addEventListener('click', function () {
                    hidden.value = '';
                    preview.innerHTML = '<span class="description" style="font-size:11px;">None</span>';
                    uploadBtn.textContent = 'Upload logo';
                    clearBtn.style.display = 'none';
                    if (window.refreshAllFrames) window.refreshAllFrames();
                });
            })();

            // Each .bhy-story-frame div's real content lives under its
            // own attachShadow({mode:'open'}) root, parsed once here from
            // the data-doc payload PHP encoded — a real fix carried
            // forward from this file's builder-era code (3.4.55): every
            // token/color variable this gallery depends on is printed as
            // `:root{--bh-bg:...}` (BHY_Style::inline_css()), correct for
            // a real document but meaningless inside a shadow root (no
            // root element for `:root` to match) — rewritten to `:host`
            // right after parsing, and again in refreshAllFrames() below
            // so later live edits don't regress this.
            frames.forEach(function (frame) {
                var raw = frame.dataset.doc;
                if (!raw) return;
                var html;
                // Real "wonky character" bug: atob() decodes base64 into
                // a binary string where every JS character is
                // ONE BYTE, not a proper UTF-8-decoded string. Any
                // multi-byte character in a surface's preview text (an
                // em-dash, a curly quote) came through as 2-3 separate
                // mis-rendered characters once DOMParser parsed that raw
                // byte string as if it were already-decoded text. PHP's
                // base64_encode() (see self::preview_doc()'s own caller)
                // was never the problem — it correctly encodes whatever
                // UTF-8 bytes it's given; the decode side just wasn't
                // undoing that correctly. TextDecoder('utf-8') is the
                // real fix, not a format change on the PHP side.
                try {
                    var bytes = Uint8Array.from(atob(raw), function (c) { return c.charCodeAt(0); });
                    html = new TextDecoder('utf-8').decode(bytes);
                } catch (e) { return; }
                var parsed = new DOMParser().parseFromString(html, 'text/html');
                var root = frame.attachShadow({ mode: 'open' });
                Array.prototype.slice.call(parsed.head.children).forEach(function (node) { root.appendChild(node); });
                Array.prototype.slice.call(parsed.body.children).forEach(function (node) { root.appendChild(node); });
                var varsTag = root.getElementById('bhy-vars');
                if (varsTag) varsTag.textContent = varsTag.textContent.replace(':root', ':host');
            });

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
                // Real gap: this only ever wrote the wordmark TEXT into
                // #bh-brand-1/#bh-brand-2 — a logo, once uploaded,
                // never appeared here at all, even though the real front-end
                // (bh-contest/assets/js/player.js's own brand.logoUrl check)
                // correctly swaps to an <img> when one's set. Mirrors that
                // same logoUrl-present check here, reusing whatever src the
                // logo preview box already resolved to (same attachment,
                // no extra request needed).
                var logoIdEl = document.getElementById('brand_logo_id');
                var logoImgEl = document.querySelector('#bhy-logo-preview img');
                var logoUrl = (logoIdEl && logoIdEl.value && logoImgEl) ? logoImgEl.src : '';
                frames.forEach(function (f) {
                    var doc = f.shadowRoot;
                    if (!doc) return;
                    var tag = doc.getElementById('bhy-vars');
                    if (tag) tag.textContent = css.replace(':root', ':host');
                    // Best-effort: surfaces that render the brand wordmark
                    // with these specific ids (e.g. bh-contest's player
                    // header) get it updated live too. Surfaces without
                    // these ids simply no-op here.
                    var brandEl = doc.getElementById('bh-brand');
                    if (!brandEl) return;
                    if (logoUrl) {
                        brandEl.innerHTML = '<img class="bh-brand-logo" src="' + logoUrl + '" alt="" style="max-height:32px;max-width:140px;object-fit:contain;">';
                        return;
                    }
                    // No logo set — make sure the text spans exist (they
                    // won't if a logo was previously shown this session)
                    // before writing the wordmark text into them.
                    if (!doc.getElementById('bh-brand-1')) {
                        brandEl.innerHTML = '<span id="bh-brand-1"></span><span id="bh-brand-2"></span>';
                    }
                    var b1 = doc.getElementById('bh-brand-1'); if (b1 && brand1) b1.textContent = brand1.value.trim() || brand1.placeholder;
                    var b2 = doc.getElementById('bh-brand-2'); if (b2 && brand2) b2.textContent = brand2.value.trim() || brand2.placeholder;
                });
                // The always-visible token preview strip lives in the main
                // document (not a preview frame), so it gets the same
                // rebuilt token text, just scoped to .bhy-token-preview
                // instead of :root — every slider stays visible regardless
                // of which registered surface happens (or doesn't) to use
                // that token.
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

                // Plugin-registered custom sliders (render_controls()'s
                // "Plugin adjustments" group) — every <input id="custom_*">
                // maps to --bh-custom-<key>, mirroring BHY_Style::
                // inline_css()'s server-side naming exactly (sanitize_key()
                // there, the same underscore-to-dash-safe id here since
                // PHP's sanitize_key() already only allows [a-z0-9_-]).
                document.querySelectorAll('input[id^="custom_"]').forEach(function (input) {
                    var varName = '--bh-custom-' + input.id.slice('custom_'.length);
                    vars[varName] = input.value + (input.dataset.unit || '');
                });

                // Plugin-registered custom fonts (render_controls()'s
                // "Plugin fonts" group) — every <input id="customfont_*">
                // maps to --bh-custom-font-<key>, mirroring inline_css()'s
                // server-side naming. Live preview always falls back to
                // sans-serif (the real fallback family isn't localized
                // here); the saved render uses the registered def's own.
                document.querySelectorAll('input[id^="customfont_"]').forEach(function (input) {
                    var varName = '--bh-custom-font-' + input.id.slice('customfont_'.length);
                    var val = (input.value || input.placeholder || '').replace(/["{};]/g, '').trim();
                    if (val) vars[varName] = '"' + val + '", sans-serif';
                });
                // (listener that triggers this rebuild on keystroke is
                // registered further down, alongside the other input
                // groups' listeners — this block only feeds buildCssText())

                // Grouped component tokens — read straight from the
                // localized schema (bhyComponentTokens) rather than
                // parsed back out of a field id (see its declaration
                // above for why). size/color/font fields already exist
                // as real DOM inputs via slider_row()/swatch_field()/the
                // plain text control render_controls() renders for each.
                Object.keys(bhyComponentTokens || {}).forEach(function (group) {
                    var tokens = (bhyComponentTokens[group] || {}).tokens || {};
                    Object.keys(tokens).forEach(function (key) {
                        var tdef = tokens[key];
                        var fieldId = 'comp_' + group + '_' + key;
                        var input = document.getElementById(fieldId);
                        if (!input) return;
                        var varName = '--bh-comp-' + group + '-' + key;
                        if (tdef.type === 'color') {
                            vars[varName] = input.value.trim() || input.placeholder || tdef.default;
                        } else if (tdef.type === 'font') {
                            var val = (input.value || input.placeholder || '').replace(/["{};]/g, '').trim();
                            if (val) vars[varName] = '"' + val + '", sans-serif';
                        } else if (tdef.type === 'toggle') {
                            vars[varName] = input.checked ? (tdef.on || 'none') : (tdef.off || 'flex');
                        } else {
                            vars[varName] = input.value + (tdef.unit || 'px');
                        }
                    });
                });

                // Derived roles (--bh-accent-contrast / -hover / -pressed,
                // --bh-on-*, --bh-text-muted / -disabled, --bh-border-strong,
                // --bh-ui*, --bh-focus) — same resolver a save runs, fed the
                // seed values just collected. Degrades to seeds-only if the
                // script somehow isn't present.
                if (window.BhyContrast && window.bhyContrastSpec) {
                    var derived = window.BhyContrast.resolve(vars, window.bhyContrastSpec);
                    Object.keys(derived).forEach(function (k) { vars[k] = derived[k]; });
                }

                var out = ':root{';
                Object.keys(vars).forEach(function (k) { out += k + ':' + vars[k] + ';'; });
                out += '}';
                return out;
            }

            // Range sliders: update their own value label and push the
            // change to every preview frame.
            document.querySelectorAll('.bhy-slider-row input[type=range]').forEach(function (input) {
                var valSpan = document.getElementById(input.id + '_val');
                input.addEventListener('input', function () {
                    if (valSpan) valSpan.textContent = input.value + (input.dataset.unit || '');
                    refreshAllFrames();
                });
            });

            // Font selects: toggle the paired "Custom…" text field via
            // its data-custom-target attribute, and refresh the preview.
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

            // Plugin-registered custom font fields ("Plugin fonts" group).
            document.querySelectorAll('input[id^="customfont_"]').forEach(function (input) {
                input.addEventListener('input', refreshAllFrames);
            });

            // Component-token font fields ("Components" group) — size
            // sliders and color swatches in that same section already
            // get their listener for free (.bhy-slider-row input[type=
            // range] / .bhy-swatch-controls input[type=text] above).
            document.querySelectorAll('.bhy-comp-font').forEach(function (input) {
                input.addEventListener('input', refreshAllFrames);
            });
            document.querySelectorAll('.bhy-comp-toggle').forEach(function (input) {
                input.addEventListener('change', refreshAllFrames);
            });

            var themeSwatches = document.querySelectorAll('.bhy-theme-swatch');
            themeSwatches.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!btn.dataset.set) return;
                    themeSwatches.forEach(function (b) { b.classList.remove('active'); });
                    btn.classList.add('active');
                    var data = JSON.parse(btn.dataset.set);
                    Object.keys(data).forEach(function (key) {
                        var input = document.getElementById(key);
                        if (!input) return;
                        input.value = data[key];
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    });
                    // A whole theme just changed everything at once — the
                    // canvas gets a brief highlight flash so that "instant
                    // delight" moment actually registers as an event,
                    // instead of every swatch/frame just silently
                    // repainting with no visual acknowledgment at all.
                    var canvas = document.querySelector('.bhy-canvas');
                    if (canvas) {
                        canvas.classList.remove('bhy-canvas-flash');
                        void canvas.offsetWidth;
                        canvas.classList.add('bhy-canvas-flash');
                    }
                });
            });

            // Real gap: refreshAllFrames() was only ever called in
            // response to an edit — ANY logo already saved from a
            // previous visit never got drawn into a freshly loaded page's
            // frames at all, since nothing had "changed" yet to trigger it.
            refreshAllFrames();
        })();
        </script>
        <script>
        // The visual Custom CSS rule editor (BHY_Gallery::render_css_rule_
        // row()/render_css_prop_row()/render_css_prop_control() render the
        // exact same markup server-side on page load; this handles adding/
        // removing rules and properties, and swapping a property row's
        // control when its dropdown selection changes — MUST build the
        // exact same field names/control shapes those PHP methods do, or
        // a row added here would silently fail to save.
        var bhyCssPropertyRegistry = <?php echo wp_json_encode(BHY_Style::css_property_registry()); ?>;
        var bhyCssStateOptions = <?php echo wp_json_encode(BHY_Style::css_state_options()); ?>;
        (function () {
            var rulesWrap = document.getElementById('bhy-css-rules');
            var addRuleBtn = document.getElementById('bhy-add-rule');
            if (!rulesWrap || !addRuleBtn) return;

            function escapeHtml(str) {
                var div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }

            function buildPropControl(name, property, value) {
                var def = bhyCssPropertyRegistry[property];
                if (!def) return '';
                if (def.type === 'color') {
                    return '<input type="color" name="' + name + '" value="' + escapeHtml(value || def.default || '#000000') + '">';
                }
                if (def.type === 'keyword') {
                    var html = '<select name="' + name + '">';
                    Object.keys(def.options || {}).forEach(function (optVal) {
                        var sel = optVal === value ? ' selected' : '';
                        html += '<option value="' + escapeHtml(optVal) + '"' + sel + '>' + escapeHtml(def.options[optVal]) + '</option>';
                    });
                    return html + '</select>';
                }
                if (def.type === 'font') {
                    var display = (value || '').replace(/^"?([^",]+)"?.*$/, '$1');
                    return '<input type="text" class="regular-text" name="' + name + '" value="' + escapeHtml(display) + '" placeholder="' + escapeHtml(def.default || 'Inter') + '">';
                }
                // 'size'
                var num = value && !isNaN(parseFloat(value)) ? parseFloat(value) : (def.default || 0);
                return '<input type="number" step="' + (def.step || 1) + '" min="' + (def.min ?? '') + '" max="' + (def.max ?? '') + '" name="' + name + '" value="' + num + '" style="width:90px;"> <span class="description">' + escapeHtml(def.unit || '') + '</span>';
            }

            function buildPropRow(ruleIndex, propIndex) {
                var row = document.createElement('div');
                row.className = 'bhy-css-prop-row';
                row.dataset.propIndex = String(propIndex);
                var namePrefix = 'custom_css_rules[' + ruleIndex + '][props][' + propIndex + ']';
                var options = '<option value="">Choose a property…</option>';
                Object.keys(bhyCssPropertyRegistry).forEach(function (key) {
                    options += '<option value="' + escapeHtml(key) + '">' + escapeHtml(bhyCssPropertyRegistry[key].label) + '</option>';
                });
                row.innerHTML = '<select class="bhy-prop-select" name="' + namePrefix + '[property]">' + options + '</select>'
                    + '<span class="bhy-prop-control-slot"></span>'
                    + '<button type="button" class="button-link-delete bhy-remove-prop">&times;</button>';
                return row;
            }

            function buildRuleRow(ruleIndex) {
                var rule = document.createElement('div');
                rule.className = 'bhy-css-rule';
                rule.dataset.ruleIndex = String(ruleIndex);
                var stateOptions = '';
                Object.keys(bhyCssStateOptions).forEach(function (val) {
                    stateOptions += '<option value="' + escapeHtml(val) + '">' + escapeHtml(bhyCssStateOptions[val]) + '</option>';
                });
                rule.innerHTML = '<div class="bhy-css-rule-header">'
                    + '<input type="text" class="regular-text code" name="custom_css_rules[' + ruleIndex + '][selector]" value="" placeholder=".some-selector">'
                    + '<select name="custom_css_rules[' + ruleIndex + '][state]">' + stateOptions + '</select>'
                    + '<button type="button" class="button-link-delete bhy-remove-rule">Remove rule</button>'
                    + '</div>'
                    + '<div class="bhy-css-rule-props"></div>'
                    + '<button type="button" class="button bhy-add-prop">+ Add property</button>';
                return rule;
            }

            function nextRuleIndex() {
                var existing = rulesWrap.querySelectorAll('.bhy-css-rule');
                var max = -1;
                existing.forEach(function (el) { max = Math.max(max, parseInt(el.dataset.ruleIndex, 10) || 0); });
                return max + 1;
            }

            function nextPropIndex(rule) {
                var existing = rule.querySelectorAll('.bhy-css-prop-row');
                var max = -1;
                existing.forEach(function (el) { max = Math.max(max, parseInt(el.dataset.propIndex, 10) || 0); });
                return max + 1;
            }

            addRuleBtn.addEventListener('click', function () {
                var rule = buildRuleRow(nextRuleIndex());
                rulesWrap.appendChild(rule);
                rule.querySelector('.bhy-add-prop').click();
            });

            rulesWrap.addEventListener('click', function (e) {
                var target = e.target;
                if (target.classList.contains('bhy-remove-rule')) {
                    target.closest('.bhy-css-rule').remove();
                } else if (target.classList.contains('bhy-add-prop')) {
                    var rule = target.closest('.bhy-css-rule');
                    var propsWrap = rule.querySelector('.bhy-css-rule-props');
                    propsWrap.appendChild(buildPropRow(rule.dataset.ruleIndex, nextPropIndex(rule)));
                } else if (target.classList.contains('bhy-remove-prop')) {
                    target.closest('.bhy-css-prop-row').remove();
                }
            });

            rulesWrap.addEventListener('change', function (e) {
                if (!e.target.classList.contains('bhy-prop-select')) return;
                var select = e.target;
                var row = select.closest('.bhy-css-prop-row');
                var rule = select.closest('.bhy-css-rule');
                var slot = row.querySelector('.bhy-prop-control-slot');
                var namePrefix = 'custom_css_rules[' + rule.dataset.ruleIndex + '][props][' + row.dataset.propIndex + ']';
                slot.innerHTML = select.value ? buildPropControl(namePrefix + '[value]', select.value, '') : '';
            });

            // A rule pre-filled from the Customizer's "Add custom rule for
            // this element" deep-link (?add_selector=...) arrives with a
            // selector but zero properties — start it with one empty
            // property row so the admin isn't looking at a selector with
            // nothing to fill in.
            rulesWrap.querySelectorAll('.bhy-css-rule').forEach(function (rule) {
                if (!rule.querySelector('.bhy-css-prop-row')) rule.querySelector('.bhy-add-prop').click();
            });
        })();
        </script>
        <?php
    }
}
