<?php
if (!defined('ABSPATH')) exit;

/**
 * The Design Suite page: a sidebar of surfaces (added via bhy_style_surfaces), a live
 * preview and one shared token panel. Previews render in a shadow root, so token CSS
 * uses :host. Site-wide tokens with a live preview only; no page builder here.
 */
class BHY_Gallery {
    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_bhy_save_settings', [self::class, 'save']);
        add_action('admin_post_bhy_restore_style_revision', [self::class, 'handle_restore_revision']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_media']);
    }

    public static function enqueue_media(string $hook): void {
        // Also matches 'bh-design': BH_Design_Suite reuses this render() callback.
        if (strpos($hook, 'bh-style') === false && strpos($hook, 'bh-design') === false) return;
        wp_enqueue_media();

        // Load every curated webfont so the font <option>s preview in their typeface.
        $font_url = class_exists('BHY_Style') ? BHY_Style::preview_all_fonts_url() : '';
        if ($font_url) wp_enqueue_style('bhy-font-preview', $font_url, [], null);

        // Shared color resolver so the live preview derives roles from the same
        // BHY_Contrast::spec() a save uses.
        if (class_exists('BHY_Contrast')) {
            wp_enqueue_script('bhy-contrast-core', OUS_URL . 'assets/js/contrast-core.js', [], OUS_VER, false);
            wp_localize_script('bhy-contrast-core', 'bhyContrastSpec', BHY_Contrast::spec());
        }
    }

    // Capability is 'bhcore_design_site' so non-admin staff can reach it.
    // WHY: parent is null (hidden page); a real second submenu under 'bh-design'
    // corrupts WordPress's pairing of the top-level page with its callback.
    public static function add_menu(): void {
        $hook = add_submenu_page(null, 'Designer', 'Designer', 'bhcore_design_site', 'bh-style', [self::class, 'render']);
        // Only the failure case is logged.
        if ($hook === false && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('error',
                'add_submenu_page() for Designer (bh-style, hidden/null parent) FAILED (returned false).',
                [], 'BHY_Gallery::add_menu()'
            );
        }
    }

    /* ---------- saving ---------- */

    public static function save(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bhy_save_settings');

        // BHY_Style::save_from_input() is the single sanitizer for this.
        $data = BHY_Style::save_from_input($_POST);

        update_option(BHY_Style::OPTION, $data);

        if (class_exists('OUS_Revisions')) {
            OUS_Revisions::snapshot('bhy_style', 1, $data);
        }

        wp_safe_redirect(add_query_arg(['page' => 'bh-style', 'saved' => '1'], admin_url('admin.php')));
        exit;
    }

    // Target of OUS_Revisions' Restore link. The snapshot is already the stored
    // shape, so it is written straight back to the option.
    public static function handle_restore_revision(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        $version = (int) ($_GET['version'] ?? 0);
        if (!isset($_GET['ous_revisions_nonce']) || !wp_verify_nonce($_GET['ous_revisions_nonce'], 'bhy_restore_style')) {
            wp_die(OUS_Words::get('admin.portal_layout.invalid_request'));
        }

        $snapshot = class_exists('OUS_Revisions') ? OUS_Revisions::get_version('bhy_style', 1, $version) : null;
        if (!$snapshot) wp_die(OUS_Words::get('admin.style_gallery.version_no_longer_exists'));

        update_option(BHY_Style::OPTION, $snapshot['data']);

        if (class_exists('OUS_Revisions')) {
            OUS_Revisions::snapshot('bhy_style', 1, $snapshot['data'], OUS_Words::get('admin.style_gallery.restored_from_version') . $version);
        }
        if (class_exists('OUS_Toast')) {
            OUS_Toast::queue(OUS_Words::get('admin.style_gallery.restored_version') . $version . '.', 'success');
        }

        wp_safe_redirect(add_query_arg(['page' => 'bh-style'], admin_url('admin.php')));
        exit;
    }

    /* ---------- the gallery page ---------- */

    public static function render(): void {
        $s = BHY_Style::get();
        // Flatten custom-slider values onto $s as 'custom_<key>' so they render
        // exactly like built-in fields.
        foreach (BHY_Style::custom_sliders() as $key => $def) {
            $s['custom_' . $key] = $s['custom'][$key] ?? ($def['default'] ?? 0);
        }
        // Custom fonts use a distinct 'customfont_' prefix to avoid id collisions.
        foreach (BHY_Style::custom_fonts() as $key => $def) {
            $s['customfont_' . $key] = $s['custom_fonts'][$key] ?? ($def['default'] ?? '');
        }
        // Component tokens flatten as 'comp_<group>_<key>'.
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
        if (isset($_GET['saved'])) echo ('<div class="notice notice-success is-dismissible"><p>' . OUS_Words::get('admin.style_gallery.saved') . '</p></div>');

        // Link to the same tokens in the Customizer; its `return` param sends the
        // Customizer's Close button back here.
        if (class_exists('BHY_Customizer')) {
            $customize_url = add_query_arg([
                'url'                    => rawurlencode(BHY_Customizer::default_preview_url()),
                'autofocus[panel]'       => 'bhy_live_design',
                'return'                 => rawurlencode(admin_url('admin.php?page=bh-style')),
            ], admin_url('customize.php'));
            echo '<p class="bhy-live-editor-cta"><a href="' . esc_url($customize_url) . ('" class="button button-primary">' . OUS_Words::get('admin.style_gallery.open_live_editor_edit_components') . '</a></p>');
            echo '<p class="description">Same Component tokens as below, previewed against the actual site — and its own Custom CSS section (with the exact same rule builder as the one on this page, further down) so you never have to leave it. Inside it: <strong>click</strong> any styled element (a card, badge, button…) to jump straight to its controls; <strong>right-click</strong> for a menu of every matching component at that point (useful when one is nested inside another), plus "Add custom rule for this element" and "Copy CSS selector". Its own Close (X) button brings you back here.</p>';
        }

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
                'title' => OUS_Words::get('admin.style_gallery.no_surfaces_registered_yet'),
                'description' => OUS_Words::get('admin.style_gallery.plugin_registers_one_via_bhy'),
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

    // Emits a <div> with the preview document base64-encoded in a data
    // attribute; render_script() attaches it under a shadow root.
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
            'title' => OUS_Words::get('admin.style_gallery.nothing_preview_yet'),
            'description' => OUS_Words::get('admin.style_gallery.once_plugin_registers_surface_its'),
        ]);
        echo '</div>';
    }

    // One preview document per surface: its stylesheet, current tokens (stable
    // id so live-edit JS can rewrite that tag), and its markup.
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
            // WHY: there is no <body> inside a shadow root, so :host stands in for it.
            . '<style>:host{display:block;margin:0;background:var(--bh-bg);color:var(--bh-text);font-family:var(--bh-font-body);}</style>'
            . '</head><body>' . $payload['html'] . '</body></html>';
    }

    // Sample chips applying every scale/shape token directly, since no single
    // surface is guaranteed to show them all.
    /** @param array<string, mixed> $s */
    private static function render_token_preview($s): void {
        echo '<div class="bhy-token-preview" id="bhy-token-preview">';
        echo ('<div class="bhy-token-chip bhy-token-chip-radius">' . OUS_Words::get('admin.style_gallery.card') . ' <span>radius</span></div>');
        echo ('<div class="bhy-token-chip bhy-token-chip-radius-sm">' . OUS_Words::get('admin.style_gallery.chip') . ' <span>radius_sm</span></div>');
        echo ('<button type="button" class="bhy-token-pill">' . OUS_Words::get('admin.style_gallery.pill_button') . '</button>');
        echo ('<div class="bhy-token-bar" title="bar_height"><span>' . OUS_Words::get('admin.style_gallery.now_playing_bar_height') . '</span></div>');
        echo ('<div class="bhy-token-text"><strong>' . OUS_Words::get('admin.contrast.aa') . '</strong> font_scale &amp; space_scale</div>');
        echo '</div>';
    }

    // Core sections stay visible; the rest are collapsible <details> groups.
    /** @param array<string, mixed> $s */
    private static function render_controls($s, string $default_group = 'brand'): void {
        echo '<div class="bhy-controls" id="bhy-controls-panel">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="bhy-form">';
        wp_nonce_field('bhy_save_settings');
        echo '<input type="hidden" name="action" value="bhy_save_settings">';

        // The "Previewing" label tracks the canvas selection (render_script()).
        echo '<p class="description" style="margin:0 0 4px;">Editing global styles — applies everywhere. Previewing: <strong id="bhy-current-surface-label">&hellip;</strong></p>';
        echo ('<h3>Shape &amp; scale reference <span class="description" style="text-transform:none;font-weight:400;">' . OUS_Words::get('admin.style_gallery.always_same_not_tied_surface') . '</span></h3>');
        self::render_token_preview($s);

        echo '<div class="bhy-token-group" data-token-group="brand">';
        echo ('<h3>' . OUS_Words::get('admin.style_gallery.brand') . '</h3>');
        echo ('<div class="bhel-field-row"><label>' . OUS_Words::get('admin.style_gallery.wordmark') . '</label><p style="display:flex;gap:8px;margin:6px 0 0;"><input type="text" id="brand_part1" name="brand_part1" class="bhy-brand-input" value="') . esc_attr($s['brand_part1']) . '" placeholder="First part" style="flex:1;"> <input type="text" id="brand_part2" name="brand_part2" class="bhy-brand-input" value="' . esc_attr($s['brand_part2']) . '" placeholder="Accent part" style="flex:1;"></p></div>';

        // Logo upload for brand_logo_id, using the wp.media() already enqueued here.
        $logo_id = (int) ($s['brand_logo_id'] ?? 0);
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';
        echo '<div class="bhel-field-row" style="margin-top:14px;">';
        echo ('<label>' . OUS_Words::get('admin.style_gallery.logo') . ' <span class="description">' . OUS_Words::get('admin.style_gallery.optional_shown_instead_wordmark_text') . '</span></label>');
        echo '<div style="display:flex;align-items:center;gap:12px;margin-top:6px;">';
        echo '<div id="bhy-logo-preview" style="width:64px;height:64px;border:1px solid var(--bhy-border,#dcdcde);border-radius:6px;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:0 0 auto;">' . ($logo_url ? '<img src="' . esc_url($logo_url) . '" style="width:100%;height:100%;object-fit:contain;">' : ('<span class="description" style="font-size:11px;">' . OUS_Words::get('admin.style_gallery.none') . '</span>')) . '</div>';
        echo '<input type="hidden" id="brand_logo_id" name="brand_logo_id" value="' . esc_attr((string) $logo_id) . '">';
        echo '<span><button type="button" class="button" id="bhy-logo-upload">' . ($logo_id ? OUS_Words::get('admin.style_gallery.change_logo') : OUS_Words::get('admin.style_gallery.upload_logo')) . '</button> <button type="button" class="button-link" id="bhy-logo-clear" style="' . ($logo_id ? '' : 'display:none;') . ('color:#b32d2e;margin-left:6px;">' . OUS_Words::get('admin.style_gallery.remove') . '</button></span>');
        echo '</div></div>';
        echo '</div>';

        echo '<div class="bhy-token-group" data-token-group="colors">';
        // Each preset swatch shows 4 dots from the preset's own colors, the same
        // data render_script()'s click handler reads via data-set.
        echo ('<div class="bhel-field-row"><label>' . OUS_Words::get('admin.style_gallery.quick_theme') . '</label>');
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

        // Core colors: always visible.
        echo ('<h3>' . OUS_Words::get('admin.style_gallery.colors') . '</h3><div class="bhy-swatch-grid">');
        $color_labels = [
            'color_bg' => 'Background', 'color_surface' => 'Surface', 'color_surface_2' => OUS_Words::get('admin.style_gallery.surface_raised'),
            'color_border' => 'Border', 'color_text' => 'Text', 'color_accent' => 'Accent',
        ];
        foreach ($color_labels as $key => $label) {
            BHY_UI::swatch_field($key, $key, $label, $s[$key]);
        }
        echo '</div>';

        // Less-common colors and category swatches: collapsible.
        echo ('<details class="bhel-style-group"><summary class="bhel-style-group-title">' . OUS_Words::get('admin.style_gallery.advanced_colors') . '</summary><div class="bhel-style-group-body bhy-swatch-grid">');
        $advanced_color_labels = [
            'color_text_dim' => OUS_Words::get('admin.style_gallery.text_dim'), 'color_accent_soft' => OUS_Words::get('admin.style_gallery.accent_soft'), 'color_overlay' => OUS_Words::get('admin.style_gallery.modal_backdrop'),
        ];
        foreach ($advanced_color_labels as $key => $label) {
            BHY_UI::swatch_field($key, $key, $label, $s[$key]);
        }
        echo '</div></details>';

        echo ('<details class="bhel-style-group"><summary class="bhel-style-group-title">' . OUS_Words::get('admin.style_gallery.category_colors') . '</summary><div class="bhel-style-group-body bhy-swatch-grid">');
        for ($i = 1; $i <= 8; $i++) {
            BHY_UI::swatch_field('cat_color_' . $i, 'cat_color_' . $i, 'Category ' . $i, $s['cat_color_' . $i]);
        }
        echo '</div></details>';
        echo '</div>'; // data-token-group="colors"

        echo '<div class="bhy-token-group" data-token-group="typography">';
        echo ('<h3>' . OUS_Words::get('admin.style_gallery.typography') . '</h3>');
        BHY_UI::font_field('font_display', OUS_Words::get('admin.style_gallery.display_font'), $s);
        BHY_UI::font_field('font_body', OUS_Words::get('admin.style_gallery.body_font'), $s);
        echo '</div>';

        echo '<div class="bhy-token-group" data-token-group="scale">';
        echo ('<h3>' . OUS_Words::get('admin.style_gallery.scale') . '</h3>');
        BHY_UI::slider_row('font_scale', OUS_Words::get('admin.style_gallery.text_size'), $s, 0.75, 1.6, 0.05, '×');
        BHY_UI::slider_row('space_scale', 'Spacing', $s, 0.6, 1.8, 0.05, '×');
        BHY_UI::slider_row('radius', OUS_Words::get('admin.style_gallery.corner_radius'), $s, 0, 32, 1, 'px');
        BHY_UI::slider_row('radius_sm', OUS_Words::get('admin.style_gallery.corner_radius_small'), $s, 0, 24, 1, 'px');
        BHY_UI::slider_row('bar_height', OUS_Words::get('admin.style_gallery.now_playing_bar_height'), $s, 56, 140, 2, 'px');
        echo '</div>';

        // Plugin-registered custom sliders, same markup as the built-ins.
        $custom_sliders = BHY_Style::custom_sliders();
        if ($custom_sliders) {
            echo '<div class="bhy-token-group" data-token-group="custom">';
            echo ('<h3>' . OUS_Words::get('admin.customizer.plugin_adjustments') . '</h3>');
            foreach ($custom_sliders as $key => $def) {
                BHY_UI::slider_row('custom_' . $key, $def['label'] ?? $key, $s, $def['min'] ?? 0, $def['max'] ?? 100, $def['step'] ?? 1, $def['unit'] ?? '');
            }
            echo '</div>';
        }

        // Plugin-registered custom fonts, one text field each.
        $custom_fonts = BHY_Style::custom_fonts();
        if ($custom_fonts) {
            echo '<div class="bhy-token-group" data-token-group="custom-fonts">';
            echo ('<h3>' . OUS_Words::get('admin.style_gallery.plugin_fonts') . '</h3>');
            foreach ($custom_fonts as $key => $def) {
                $field_id = 'customfont_' . $key;
                echo '<div class="bhel-field-row"><label for="' . esc_attr($field_id) . '">' . esc_html($def['label'] ?? $key) . '</label> ';
                echo '<input type="text" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id) . '" value="' . esc_attr($s[$field_id] ?? '') . '" class="regular-text" placeholder="' . esc_attr($def['default'] ?? '') . '"></div>';
            }
            echo '</div>';
        }

        // Component tokens: one collapsed <details> per registered component.
        $components = BHY_Style::component_tokens();
        if ($components) {
            echo '<div class="bhy-token-group" data-token-group="components">';
            echo ('<h3>' . OUS_Words::get('admin.style_gallery.components') . ' <span class="description" style="text-transform:none;font-weight:400;">' . OUS_Words::get('admin.style_gallery.granular_control_per_real_ui') . '</span></h3>');
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

        // Visual Custom CSS rule editor: selector (often from the Customizer's
        // "Copy CSS selector"), optional state, and typed property controls.
        // The raw textarea below covers anything else.
        echo '<div class="bhy-token-group" data-token-group="custom-css" id="custom-css">';
        echo ('<h3>' . OUS_Words::get('admin.customizer.custom_css') . ' <span class="description" style="text-transform:none;font-weight:400;">' . OUS_Words::get('admin.style_gallery.anything_components_above_don_t') . '</span></h3>');
        echo ('<p class="description">' . OUS_Words::get('admin.style_gallery.exact_same_rule_builder_as') . ' <a href="') . esc_url(add_query_arg(['url' => rawurlencode(class_exists('BHY_Customizer') ? BHY_Customizer::default_preview_url() : home_url('/'))], admin_url('customize.php'))) . ('">' . OUS_Words::get('admin.style_gallery.live_editor') . '</a>\'s own Custom CSS section — right-clicking an element there adds a rule directly in the Customizer without leaving it, but you can build rules here just as well, or come back to fine-tune one later. <strong>How to get a selector without opening the Live Editor:</strong> not really practical by hand — right-click is the way. Preview: reload the real page after saving (rules here don\'t live-preview in the canvas above, since those previews are sandboxed per-surface and a real page selector wouldn\'t match anything in them anyway).</p>');

        $rules = is_array($s['custom_css_rules'] ?? null) ? $s['custom_css_rules'] : [];
        // ?add_selector= from the Customizer appends one empty prefilled rule;
        // nothing is saved until the admin presses Save.
        $prefill_selector = isset($_GET['add_selector']) ? BHY_Style::sanitize_css_selector(wp_unslash($_GET['add_selector'])) : '';
        if ($prefill_selector !== '') $rules[] = ['selector' => $prefill_selector, 'state' => '', 'declarations' => []];

        echo '<div id="bhy-css-rules">';
        foreach (array_values($rules) as $i => $rule) {
            self::render_css_rule_row($i, is_array($rule) ? $rule : []);
        }
        echo '</div>';
        echo ('<button type="button" id="bhy-add-rule" class="button">' . OUS_Words::get('admin.customize_css_rules_control.add_rule') . '</button>');

        echo ('<h4 style="margin-top:24px;">' . OUS_Words::get('admin.style_gallery.advanced_raw_css') . '</h4>');
        echo ('<p class="description">' . OUS_Words::get('admin.style_gallery.real_css_text_anything_rule') . '</p>');
        echo '<textarea name="custom_css" id="custom_css" rows="6" class="large-text code" placeholder="' . esc_attr('.some-selector {' . "\n" . '    /* ... */' . "\n" . '}') . '">' . esc_textarea((string) ($s['custom_css'] ?? '')) . '</textarea>';
        echo '</div>';

        echo ('<p class="submit"><button type="submit" class="button button-primary">' . OUS_Words::get('admin.style_gallery.save') . '</button></p>');

        // Single site-wide config, so object_id is a constant 1. The history
        // panel is links only (no <form>), so it is safe inside #bhy-form.
        if (class_exists('OUS_Revisions')) {
            echo '<div class="bhy-token-group" data-token-group="revisions">';
            echo ('<h3>' . OUS_Words::get('admin.portal_layout.version_history') . '</h3>');
            OUS_Revisions::render_history_panel('bhy_style', 1, 'bhy_restore_style_revision', 'bhy_restore_style');
            echo '</div>';
        }

        echo '</form></div>';
    }

    /**
     * One rule-editor row. WHY: field names (custom_css_rules[i][selector|state|
     * props[j][property|value]]) must match the JS buildRuleRow(), or new rules fail to save.
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
        echo ('<button type="button" class="button-link-delete bhy-remove-rule">' . OUS_Words::get('admin.style_gallery.remove_rule') . '</button>');
        echo '</div>';
        echo '<div class="bhy-css-rule-props">';
        $j = 0;
        foreach ($declarations as $property => $value) {
            self::render_css_prop_row($i, $j, (string) $property, (string) $value);
            $j++;
        }
        echo '</div>';
        echo ('<button type="button" class="button bhy-add-prop">' . OUS_Words::get('admin.style_gallery.add_property') . '</button>');
        echo '</div>';
    }

    /** One property row inside a rule: a property picker plus its typed control. */
    private static function render_css_prop_row(int $i, int $j, string $property, string $value): void {
        $registry = BHY_Style::css_property_registry();
        echo '<div class="bhy-css-prop-row" data-prop-index="' . esc_attr((string) $j) . '">';
        echo '<select class="bhy-prop-select" name="custom_css_rules[' . esc_attr((string) $i) . '][props][' . esc_attr((string) $j) . '][property]">';
        echo ('<option value="">' . OUS_Words::get('admin.style_gallery.choose_property') . '</option>');
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
     * Typed control for one property. WHY: must match the JS buildPropControl()
     * field names and shapes, or the save handler sees different input.
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
            // Strip the stored fallback back to the bare font name for editing.
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
<?php echo (string) file_get_contents(OUS_PATH . 'assets/css/style-gallery.css'); ?></style>
        <style id="bhy-preview-vars"><?php echo str_replace(':root', '.bhy-token-preview', BHY_Style::inline_css(null, false)); ?></style>
        <script>
        <?php echo BHY_UI::swatch_js("refreshAllFrames();"); ?>
        // Component token schema, read directly by buildCssText() because a group or
        // key may itself contain an underscore.
        var bhyComponentTokens = <?php echo wp_json_encode(BHY_Style::component_tokens()); ?>;
        (function () {
            var frames = document.querySelectorAll('.bhy-story-frame');
            var buttons = document.querySelectorAll('.bhy-story-btn');
            var currentLabel = document.getElementById('bhy-current-surface-label');

            // The controls are global tokens, so switching surfaces only updates this
            // label to show which surface the canvas is on.
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

            // Logo upload reuses the wp.media() already enqueued on this page.
            (function () {
                var uploadBtn = document.getElementById('bhy-logo-upload');
                var clearBtn = document.getElementById('bhy-logo-clear');
                var hidden = document.getElementById('brand_logo_id');
                var preview = document.getElementById('bhy-logo-preview');
                if (!uploadBtn) return;
                // wp.media loads in the footer, after this inline script, so check it
                // inside the click handler, not at setup.
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

            // Each preview frame lives in its own shadow root; token CSS printed for :root
            // is rewritten to :host here and in refreshAllFrames().
            frames.forEach(function (frame) {
                var raw = frame.dataset.doc;
                if (!raw) return;
                var html;
                // atob() returns bytes, not UTF-8 text; decode with TextDecoder so dashes
                // and curly quotes in previews render correctly.
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

            // Live edits rebuild the full token set for every surface at once, because
            // this replaces the whole #bhy-vars text.
            window.refreshAllFrames = function () {
                var css = buildCssText();
                var brand1 = document.getElementById('brand_part1');
                var brand2 = document.getElementById('brand_part2');
                // Show an uploaded logo here too (as the contest player does), reusing
                // the logo preview box src.
                var logoIdEl = document.getElementById('brand_logo_id');
                var logoImgEl = document.querySelector('#bhy-logo-preview img');
                var logoUrl = (logoIdEl && logoIdEl.value && logoImgEl) ? logoImgEl.src : '';
                frames.forEach(function (f) {
                    var doc = f.shadowRoot;
                    if (!doc) return;
                    var tag = doc.getElementById('bhy-vars');
                    if (tag) tag.textContent = css.replace(':root', ':host');
                    // Surfaces with these brand ids update live; others no-op.
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
                // The token preview strip gets the same tokens scoped to .bhy-token-preview,
                // so every slider stays visible.
                var previewTag = document.getElementById('bhy-preview-vars');
                if (previewTag) previewTag.textContent = css.replace(':root', '.bhy-token-preview');
            };

            // Mirrors BHY_Style::font_family(): "Custom" uses its text field (or the
            // defaults), otherwise the picked font name.
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

            // Builds the same custom properties as BHY_Style::inline_css(), so the
            // preview never drifts from a save.
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

                // Plugin sliders: input#custom_<key> maps to --bh-custom-<key>, as server-side.
                document.querySelectorAll('input[id^="custom_"]').forEach(function (input) {
                    var varName = '--bh-custom-' + input.id.slice('custom_'.length);
                    vars[varName] = input.value + (input.dataset.unit || '');
                });

                // Plugin fonts: input#customfont_<key> maps to --bh-custom-font-<key>; the
                // preview falls back to sans-serif, the saved CSS to the registered fallback.
                document.querySelectorAll('input[id^="customfont_"]').forEach(function (input) {
                    var varName = '--bh-custom-font-' + input.id.slice('customfont_'.length);
                    var val = (input.value || input.placeholder || '').replace(/["{};]/g, '').trim();
                    if (val) vars[varName] = '"' + val + '", sans-serif';
                });
                // (listener that triggers this rebuild on keystroke is
                // registered further down, alongside the other input
                // groups' listeners — this block only feeds buildCssText())

                // Component tokens, read from the localized schema (bhyComponentTokens).
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

                // Derived roles via the same resolver a save runs; seeds only if the
                // resolver script is missing.
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

            // Component font fields; sizes and colors in that group already have listeners.
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
                    // A whole theme changed at once: flash the canvas so the change registers.
                    var canvas = document.querySelector('.bhy-canvas');
                    if (canvas) {
                        canvas.classList.remove('bhy-canvas-flash');
                        void canvas.offsetWidth;
                        canvas.classList.add('bhy-canvas-flash');
                    }
                });
            });

            // Draw an already-saved logo on first load, not only after an edit.
            refreshAllFrames();
        })();
        </script>
        <script>
        // Custom CSS rule editor: adds/removes rules and properties. Must build exactly
        // the same field names as render_css_rule_row() and friends, or new rows will not save.
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

            // A rule pre-filled from the Customizer deep link (?add_selector=) starts with
            // one empty property row.
            rulesWrap.querySelectorAll('.bhy-css-rule').forEach(function (rule) {
                if (!rule.querySelector('.bhy-css-prop-row')) rule.querySelector('.bhy-add-prop').click();
            });
        })();
        </script>
        <?php
    }
}
