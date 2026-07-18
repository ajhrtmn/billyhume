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
    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_bhy_save_settings', [self::class, 'save']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_media']);
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
        // carry an inline font-family per option (class-ui.php), but
        // that's cosmetically useless without the actual webfont files
        // loaded on THIS page — this stylesheet used to only ever be
        // enqueued INSIDE the canvas preview docs (preview_doc() below),
        // never on the real admin page the <select> itself lives on.
        $font_url = class_exists('BHY_Style') ? BHY_Style::preview_all_fonts_url() : '';
        if ($font_url) wp_enqueue_style('bhy-font-preview', $font_url, [], null);
    }

    // DESIGN-SUITE-UNIFICATION-PLAN.md Phase 1 — relocated from a
    // submenu of 'own-ur-shit' to a submenu of the top-level 'bh-design'
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
    public static function add_menu() {
        $hook = add_submenu_page(null, 'Designer', 'Designer', 'bhcore_design_site', 'bh-style', [self::class, 'render']);
        // Log-pollution fix, flagged by AJ directly — only the failure
        // case is worth a log row; this used to fire an INFO row for
        // every successful registration too, throttled only to once per
        // 60 seconds, on every admin page load.
        if ($hook === false && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('error',
                'add_submenu_page() for Designer (bh-style, hidden/null parent) FAILED (returned false).',
                [], 'BHY_Gallery::add_menu()'
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

        // Plugin-registered custom sliders (class-style.php's
        // custom_sliders()) — same sanitize-through-safe_number()
        // treatment as every built-in field above, just looped instead
        // of hardcoded, since the set is whatever's registered right now.
        $custom_sliders = BHY_Style::custom_sliders();
        if ($custom_sliders) {
            $data['custom'] = [];
            foreach ($custom_sliders as $key => $def) {
                $raw = $_POST['custom_' . $key] ?? ($def['default'] ?? 0);
                $data['custom'][$key] = BHY_Style::safe_number($raw, $def['min'] ?? 0, $def['max'] ?? 999999, $def['default'] ?? 0);
            }
        }

        update_option(BHY_Style::OPTION, $data);
        wp_safe_redirect(add_query_arg(['page' => 'bh-style', 'saved' => '1'], admin_url('admin.php')));
        exit;
    }

    /* ---------- the gallery page ---------- */

    public static function render() {
        $s = BHY_Style::get();
        // Flatten registered custom-slider values onto $s under
        // 'custom_<key>' so render_controls() can hand them straight to
        // BHY_UI::slider_row() exactly like a built-in field — no
        // separate code path for "a plugin's slider" vs. "our slider".
        foreach (BHY_Style::custom_sliders() as $key => $def) {
            $s['custom_' . $key] = $s['custom'][$key] ?? ($def['default'] ?? 0);
        }
        $surfaces = apply_filters('bhy_style_surfaces', []);
        $grouped = [];
        foreach ($surfaces as $key => $surface) $grouped[$surface['group']][$key] = $surface;

        echo '<div class="wrap bhy-gallery">';
        echo '<h1>Design Suite</h1>';
        if (isset($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';

        echo '<div class="bhy-layout">';
        self::render_sidebar($grouped);
        self::render_canvas($surfaces, $s);
        self::render_controls($s);
        echo '</div></div>';

        self::render_script($surfaces, $s);
    }

    private static function render_sidebar($grouped) {
        echo '<div class="bhy-sidebar">';
        if (!$grouped) {
            echo '<p class="description">No surfaces registered yet — a plugin registers one via the <code>bhy_style_surfaces</code> filter.</p>';
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
    private static function render_token_preview($s) {
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
    private static function render_controls($s, $default_group = 'brand') {
        echo '<div class="bhy-controls" id="bhy-controls-panel">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="bhy-form">';
        wp_nonce_field('bhy_save_settings');
        echo '<input type="hidden" name="action" value="bhy_save_settings">';

        // Real usability fix, AJ's own ask: this panel used to say
        // "Live token preview" with zero connection to whichever
        // surface is actually selected in the canvas — reading as if
        // IT were the live view, when the real live view is the canvas
        // itself, right there on screen. Renamed to be honest about
        // what this specific widget is (a fixed reference strip for
        // the shape/scale tokens — radius, bar height, font/space
        // scale — that many individual surfaces never happen to
        // exercise visibly, e.g. Player never shows --bh-radius
        // without opening a modal) and added a real "Previewing: X"
        // label that updates live from the canvas selection
        // (render_script()'s surface-switch handler), so the
        // connection between "what I'm looking at" and "what these
        // controls affect" is stated outright instead of implied.
        echo '<p class="description" style="margin:0 0 4px;">Editing global styles — applies everywhere. Previewing: <strong id="bhy-current-surface-label">&hellip;</strong></p>';
        echo '<h3>Shape &amp; scale reference <span class="description" style="text-transform:none;font-weight:400;">(always the same, not tied to the surface above)</span></h3>';
        self::render_token_preview($s);

        echo '<div class="bhy-token-group" data-token-group="brand">';
        echo '<h3>Brand</h3>';
        echo '<div class="bhel-field-row"><label>Wordmark</label><p style="display:flex;gap:8px;margin:6px 0 0;"><input type="text" id="brand_part1" name="brand_part1" class="bhy-brand-input" value="' . esc_attr($s['brand_part1']) . '" placeholder="First part" style="flex:1;"> <input type="text" id="brand_part2" name="brand_part2" class="bhy-brand-input" value="' . esc_attr($s['brand_part2']) . '" placeholder="Accent part" style="flex:1;"></p></div>';

        // Real gap, caught live: BHY_Style::logo_url()/'brand_logo_id'
        // have been part of the data model and the real save() handler
        // (above in this file) since before this pass — brand.js/the
        // player's own header already render a logo when one is set
        // (BHY_Style::get_brand_payload(), 'logoUrl') — but the
        // inspector never actually had an upload control for it, so
        // there was no way to ever set brand_logo_id from this screen
        // at all. wp.media() is already enqueued on this exact page
        // (enqueue_media(), above in this class) so this needed no new
        // asset, just the missing control — same upload-button/preview
        // shape bh-streaming's own artwork picker uses
        // (class-admin.php's pick() helper).
        $logo_id = (int) ($s['brand_logo_id'] ?? 0);
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';
        echo '<div class="bhel-field-row" style="margin-top:14px;">';
        echo '<label>Logo <span class="description">(optional — shown instead of the wordmark text above wherever a surface renders one)</span></label>';
        echo '<div style="display:flex;align-items:center;gap:12px;margin-top:6px;">';
        echo '<div id="bhy-logo-preview" style="width:64px;height:64px;border:1px solid var(--bhy-border,#dcdcde);border-radius:6px;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:0 0 auto;">' . ($logo_url ? '<img src="' . esc_url($logo_url) . '" style="width:100%;height:100%;object-fit:contain;">' : '<span class="description" style="font-size:11px;">None</span>') . '</div>';
        echo '<input type="hidden" id="brand_logo_id" name="brand_logo_id" value="' . esc_attr($logo_id) . '">';
        echo '<span><button type="button" class="button" id="bhy-logo-upload">' . ($logo_id ? 'Change logo' : 'Upload logo') . '</button> <button type="button" class="button-link" id="bhy-logo-clear" style="' . ($logo_id ? '' : 'display:none;') . 'color:#b32d2e;margin-left:6px;">Remove</button></span>';
        echo '</div></div>';
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

        echo '<p class="submit"><button type="submit" class="button button-primary">Save</button></p>';
        echo '</form></div>';
    }

    private static function render_script($surfaces, $s) {
        ?>
        <style><?php echo BHY_UI::admin_page_css(); ?></style>
        <style id="bhy-preview-vars"><?php echo str_replace(':root', '.bhy-token-preview', BHY_Style::inline_css()); ?></style>
        <script>
        <?php echo BHY_UI::swatch_js("refreshAllFrames();"); ?>
        (function () {
            var frames = document.querySelectorAll('.bhy-story-frame');
            var buttons = document.querySelectorAll('.bhy-story-btn');
            var currentLabel = document.getElementById('bhy-current-surface-label');

            // Real usability fix, AJ's own ask: the inspector's controls
            // are genuine GLOBAL tokens (one theme, applied everywhere —
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
                // Real bug, caught live: this script prints inline as part
                // of the page's own content, before wp_footer runs — and
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
                // Real "wonky character" bug, caught live: atob() decodes
                // base64 into a binary string where every JS character is
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
                // Real gap, AJ's own report ("logo doesn't appear to update
                // in the style viewer"): this only ever wrote the wordmark
                // TEXT into #bh-brand-1/#bh-brand-2 — a logo, once uploaded,
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

            // Real gap, AJ's own report ("logo doesn't appear to update in
            // the style viewer"): refreshAllFrames() was only ever called
            // in response to an edit — ANY logo already saved from a
            // previous visit never got drawn into a freshly loaded page's
            // frames at all, since nothing had "changed" yet to trigger it.
            refreshAllFrames();
        })();
        </script>
        <?php
    }
}
