<?php
if (!defined('ABSPATH')) exit;

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
    }

    public static function enqueue_media($hook) {
        if (strpos($hook, 'bh-style') === false) return;
        wp_enqueue_media();
    }

    public static function add_menu() {
        add_submenu_page('own-ur-shit', 'Style', 'Style', 'manage_options', 'bh-style', [self::class, 'render']);
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

    public static function render() {
        $s = BHY_Style::get();
        $surfaces = apply_filters('bhy_style_surfaces', []);
        $grouped = [];
        foreach ($surfaces as $key => $surface) $grouped[$surface['group']][$key] = $surface;

        echo '<div class="wrap bhy-gallery">';
        echo '<h1>Style</h1>';
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

    private static function render_canvas($surfaces, $s) {
        echo '<div class="bhy-canvas">';
        $first = true;
        foreach ($surfaces as $key => $surface) {
            $payload = call_user_func($surface['render']);
            echo '<iframe class="bhy-story-frame' . ($first ? ' active' : '') . '" data-surface="' . esc_attr($key) . '" srcdoc="' . esc_attr(self::preview_doc($payload, $s)) . '"></iframe>';
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
            . '<link rel="stylesheet" href="' . esc_url($payload['css_url']) . '">'
            . '<style id="bhy-vars">' . BHY_Style::inline_css() . '</style>'
            . '<style>body{margin:0;background:var(--bh-bg);color:var(--bh-text);font-family:var(--bh-font-body);}</style>'
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

    private static function render_controls($s) {
        echo '<div class="bhy-controls">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="bhy-form">';
        wp_nonce_field('bhy_save_settings');
        echo '<input type="hidden" name="action" value="bhy_save_settings">';

        echo '<h2>Live token preview</h2>';
        self::render_token_preview($s);

        echo '<h2>Brand</h2>';
        echo '<p><input type="text" id="brand_part1" name="brand_part1" class="bhy-brand-input" value="' . esc_attr($s['brand_part1']) . '" placeholder="First part"> <input type="text" id="brand_part2" name="brand_part2" class="bhy-brand-input" value="' . esc_attr($s['brand_part2']) . '" placeholder="Accent part"></p>';

        echo '<h2>Quick theme</h2>';
        echo '<select id="bhy-theme-select"><option value="">Choose a theme…</option>';
        foreach (BHY_Style::THEME_GROUPS as $group_label => $themes) {
            echo '<optgroup label="' . esc_attr($group_label) . '">';
            foreach ($themes as $name => $colors) {
                echo '<option value="' . esc_attr($name) . '" data-set=\'' . esc_attr(wp_json_encode($colors)) . '\'>' . esc_html($name) . '</option>';
            }
            echo '</optgroup>';
        }
        echo '</select>';

        echo '<h2>Colors</h2>';
        echo '<div class="bhy-swatch-grid">';
        $color_labels = [
            'color_bg' => 'Background', 'color_surface' => 'Surface', 'color_surface_2' => 'Surface (raised)',
            'color_border' => 'Border', 'color_text' => 'Text', 'color_text_dim' => 'Text (dim)',
            'color_accent' => 'Accent', 'color_accent_soft' => 'Accent (soft)', 'color_overlay' => 'Modal backdrop',
        ];
        foreach ($color_labels as $key => $label) {
            BHY_UI::swatch_field($key, $key, $label, $s[$key]);
        }
        echo '</div>';

        echo '<h2>Category colors</h2><div class="bhy-swatch-grid">';
        for ($i = 1; $i <= 8; $i++) {
            BHY_UI::swatch_field('cat_color_' . $i, 'cat_color_' . $i, 'Category ' . $i, $s['cat_color_' . $i]);
        }
        echo '</div>';

        echo '<h2>Typography</h2>';
        BHY_UI::font_field('font_display', 'Display font', $s);
        BHY_UI::font_field('font_body', 'Body font', $s);

        echo '<h2>Scale</h2>';
        BHY_UI::slider_row('font_scale', 'Text size', $s, 0.75, 1.6, 0.05, '×');
        BHY_UI::slider_row('space_scale', 'Spacing', $s, 0.6, 1.8, 0.05, '×');
        BHY_UI::slider_row('radius', 'Corner radius', $s, 0, 32, 1, 'px');
        BHY_UI::slider_row('radius_sm', 'Corner radius (small)', $s, 0, 24, 1, 'px');
        BHY_UI::slider_row('bar_height', 'Now-playing bar height', $s, 56, 140, 2, 'px');

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

            buttons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    buttons.forEach(function (b) { b.classList.remove('active'); });
                    frames.forEach(function (f) { f.classList.remove('active'); });
                    btn.classList.add('active');
                    document.querySelector('.bhy-story-frame[data-surface="' + btn.dataset.surface + '"]').classList.add('active');
                });
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
                frames.forEach(function (f) {
                    var doc = f.contentDocument;
                    if (!doc) return;
                    var tag = doc.getElementById('bhy-vars');
                    if (tag) tag.textContent = css;
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
