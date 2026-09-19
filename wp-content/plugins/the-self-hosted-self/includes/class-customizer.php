<?php
if (!defined('ABSPATH')) exit;

/**
 * BHY_Customizer — Phase 3 of the Design Suite work (see class-style.php's
 * component_tokens() docblock and bh-courses/bh-contest/bh-monetization-woo
 * for Phase 1/2, the token registrations this reads). Extends WordPress's
 * OWN native Customizer (WP_Customize_Manager) rather than building a
 * bespoke click-to-select canvas — AJ's own suggestion, and a far smaller
 * surface than reinventing live in-context editing from scratch: every
 * control here is a real wp.customize() setting, previewed via postMessage
 * against the actual rendered page, and saved through the exact same
 * bhy_style_settings option the Design Suite admin page reads and writes
 * (WP Customize's native array-syntax option settings — e.g.
 * "bhy_style_settings[components][badge][radius]" — deep-merge into that
 * option on save; nothing here is a second, parallel storage mechanism).
 *
 * Deliberately scoped to the same token surface as component_tokens()/
 * custom_sliders()/custom_fonts() — the granular, per-real-UI-piece
 * controls that actually make sense to preview against a live page. Global
 * scheme editing (colors, quick themes, font pickers) stays the Design
 * Suite admin page's job; that page is the "browse everything at once"
 * tool, this is the "tweak this one thing while looking at it" tool. Each
 * links to the other (see BHY_Gallery::render()'s "Open Live Editor"
 * button, and the Customizer's native `return` URL param wired below) so
 * switching between them is a single click either direction, using WP's
 * own built-in Close-button-returns-to-`return`-URL behavior rather than
 * a custom-built toggle.
 */
class BHY_Customizer {
    public static function init() {
        add_action('customize_register', [__CLASS__, 'register']);
        add_action('customize_preview_init', [__CLASS__, 'enqueue_preview_script']);
        add_action('customize_controls_enqueue_scripts', [__CLASS__, 'enqueue_controls_script']);
    }

    public static function register($wp_customize) {
        if (!class_exists('BHY_Style') || !class_exists('WP_Customize_Control')) return;
        require_once __DIR__ . '/class-customize-range-control.php';

        $wp_customize->add_panel('bhy_live_design', [
            'title'       => 'Design Suite (Live)',
            'description' => 'The same granular tokens as Settings & Style → Design Suite\'s "Components" section, edited here while looking at the real page. Saves to the exact same site-wide settings.'
                . "\n\n" . 'Click any styled element in the preview to jump straight to its section below. Right-click it for a menu of every component at that point (useful when one is nested inside another, like a badge inside a card) — each menu item either jumps to that section or, for "Copy CSS selector," copies a real CSS selector for that exact element to your clipboard so you can paste it into Design Suite\'s "Custom CSS" box for anything these controls don\'t cover.',
            'priority'    => 30,
        ]);

        $sliders = BHY_Style::custom_sliders();
        $fonts   = BHY_Style::custom_fonts();
        if ($sliders || $fonts) {
            $wp_customize->add_section('bhy_live_plugin_adjustments', [
                'title' => 'Plugin adjustments',
                'panel' => 'bhy_live_design',
            ]);
            foreach ($sliders as $key => $def) {
                self::add_size_control($wp_customize, 'bhy_live_plugin_adjustments', 'bhy_style_settings[custom][' . $key . ']', $def);
            }
            foreach ($fonts as $key => $def) {
                self::add_font_control($wp_customize, 'bhy_live_plugin_adjustments', 'bhy_style_settings[custom_fonts][' . $key . ']', $def);
            }
        }

        // The Customizer-side counterpart of the Design Suite admin
        // page's "Custom CSS" textarea (BHY_Gallery::render_controls())
        // — same option key, same trust boundary, WP core's own
        // Code Editor control (used by its native "Additional CSS"
        // feature) rather than inventing our own. 'selective_refresh'
        // isn't used since a full stylesheet replace on every keystroke
        // via postMessage (see enqueue_preview_script()'s dedicated
        // binding for this one setting, kept out of the generic schema
        // loop since it's not a --bh-* variable) is simpler and just as
        // fast for a single <style> tag swap.
        if (class_exists('WP_Customize_Code_Editor_Control')) {
            $wp_customize->add_section('bhy_live_custom_css', [
                'title'       => 'Custom CSS',
                'panel'       => 'bhy_live_design',
                'description' => 'For anything the Component sections above don\'t cover. Right-click an element in the preview and choose "Copy CSS selector" to get a real selector to start from.',
            ]);
            $wp_customize->add_setting('bhy_style_settings[custom_css]', [
                'type'              => 'option',
                'default'           => '',
                'sanitize_callback' => function ($val) {
                    return substr(str_ireplace(['</style', '<script'], ['<\/style', '<\/script'], (string) $val), 0, 20000);
                },
                'transport' => 'postMessage',
            ]);
            $wp_customize->add_control(new \WP_Customize_Code_Editor_Control($wp_customize, 'bhy_style_settings[custom_css]', [
                'label'     => 'Custom CSS',
                'section'   => 'bhy_live_custom_css',
                'settings'  => 'bhy_style_settings[custom_css]',
                'code_type' => 'text/css',
            ]));
        }

        foreach (BHY_Style::component_tokens() as $group => $def) {
            $safe_group = sanitize_key($group);
            $section_id = 'bhy_live_component_' . $safe_group;
            $wp_customize->add_section($section_id, [
                'title' => $def['label'] ?? ucwords(str_replace('_', ' ', $group)),
                'panel' => 'bhy_live_design',
            ]);
            foreach (($def['tokens'] ?? []) as $key => $tdef) {
                $id   = 'bhy_style_settings[components][' . $safe_group . '][' . sanitize_key($key) . ']';
                $type = $tdef['type'] ?? 'size';
                if ($type === 'color') {
                    self::add_color_control($wp_customize, $section_id, $id, $tdef);
                } elseif ($type === 'font') {
                    self::add_font_control($wp_customize, $section_id, $id, $tdef);
                } elseif ($type === 'toggle') {
                    self::add_toggle_control($wp_customize, $section_id, $id, $tdef);
                } else {
                    self::add_size_control($wp_customize, $section_id, $id, $tdef);
                }
            }
        }
    }

    /** @param array<string, mixed> $def */
    private static function add_size_control(\WP_Customize_Manager $wp_customize, string $section, string $id, array $def): void {
        $min = $def['min'] ?? 0;
        $max = $def['max'] ?? 999999;
        $default = $def['default'] ?? 0;
        $wp_customize->add_setting($id, [
            'type'              => 'option',
            'default'           => $default,
            'sanitize_callback' => function ($val) use ($min, $max, $default) {
                return BHY_Style::safe_number($val, $min, $max, $default);
            },
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control(new BHY_Customize_Range_Control($wp_customize, $id, [
            'label'       => $def['label'] ?? $id,
            'section'     => $section,
            'settings'    => $id,
            'input_attrs' => ['min' => $min, 'max' => $max, 'step' => $def['step'] ?? 1],
            'unit'        => $def['unit'] ?? 'px',
        ]));
    }

    /** @param array<string, mixed> $def */
    private static function add_color_control(\WP_Customize_Manager $wp_customize, string $section, string $id, array $def): void {
        $wp_customize->add_setting($id, [
            'type'              => 'option',
            'default'           => $def['default'] ?? '#000000',
            'sanitize_callback' => ['BHY_Style', 'safe_color'],
            'transport'         => 'postMessage',
        ]);
        $wp_customize->add_control(new \WP_Customize_Color_Control($wp_customize, $id, [
            'label'    => $def['label'] ?? $id,
            'section'  => $section,
            'settings' => $id,
        ]));
    }

    /** @param array<string, mixed> $def */
    private static function add_font_control(\WP_Customize_Manager $wp_customize, string $section, string $id, array $def): void {
        $default = $def['default'] ?? '';
        $wp_customize->add_setting($id, [
            'type'              => 'option',
            'default'           => $default,
            'sanitize_callback' => function ($val) use ($default) {
                $val = sanitize_text_field($val);
                return $val !== '' ? $val : $default;
            },
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control($id, [
            'label'       => ($def['label'] ?? $id) . ' (any Google Font name)',
            'section'     => $section,
            'settings'    => $id,
            'type'        => 'text',
        ]);
    }

    /** @param array<string, mixed> $def */
    private static function add_toggle_control(\WP_Customize_Manager $wp_customize, string $section, string $id, array $def): void {
        $default = !empty($def['default']);
        $wp_customize->add_setting($id, [
            'type'              => 'option',
            'default'           => $default,
            'sanitize_callback' => function ($val) {
                return !empty($val) && $val !== '0';
            },
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control($id, [
            'label'    => $def['label'] ?? $id,
            'section'  => $section,
            'settings' => $id,
            'type'     => 'checkbox',
        ]);
    }

    public static function enqueue_preview_script() {
        if (!class_exists('BHY_Style')) return;
        wp_enqueue_script('bhy-customizer-preview', OUS_URL . 'assets/js/customizer-preview.js', ['customize-preview'], OUS_VER, true);

        $schema = [];
        foreach (BHY_Style::custom_sliders() as $key => $def) {
            $schema[] = [
                'id'   => 'bhy_style_settings[custom][' . $key . ']',
                'var'  => '--bh-custom-' . $key,
                'unit' => $def['unit'] ?? 'px',
            ];
        }
        foreach (BHY_Style::custom_fonts() as $key => $def) {
            $schema[] = [
                'id'       => 'bhy_style_settings[custom_fonts][' . $key . ']',
                'var'      => '--bh-custom-font-' . $key,
                'isFont'   => true,
                'fallback' => preg_replace('/[^a-z-]/', '', strtolower($def['fallback'] ?? 'sans-serif')) ?: 'sans-serif',
            ];
        }
        foreach (BHY_Style::component_tokens() as $group => $def) {
            $safe_group = sanitize_key($group);
            foreach (($def['tokens'] ?? []) as $key => $tdef) {
                $safe_key = sanitize_key($key);
                $type = $tdef['type'] ?? 'size';
                $var = '--bh-comp-' . $safe_group . '-' . $safe_key;
                $id  = 'bhy_style_settings[components][' . $safe_group . '][' . $safe_key . ']';
                if ($type === 'font') {
                    $schema[] = [
                        'id'       => $id,
                        'var'      => $var,
                        'isFont'   => true,
                        'fallback' => preg_replace('/[^a-z-]/', '', strtolower($tdef['fallback'] ?? 'sans-serif')) ?: 'sans-serif',
                    ];
                } elseif ($type === 'color') {
                    $schema[] = ['id' => $id, 'var' => $var, 'unit' => ''];
                } elseif ($type === 'toggle') {
                    $schema[] = [
                        'id'      => $id,
                        'var'     => $var,
                        'isToggle' => true,
                        'onVal'   => BHY_Style::css_safe_string_keyword($tdef['on'] ?? 'none'),
                        'offVal'  => BHY_Style::css_safe_string_keyword($tdef['off'] ?? 'flex'),
                    ];
                } else {
                    $schema[] = ['id' => $id, 'var' => $var, 'unit' => $tdef['unit'] ?? 'px'];
                }
            }
        }
        wp_localize_script('bhy-customizer-preview', 'bhyCustomizerSchema', $schema);

        // Click-to-select / right-click ancestor picker: a plugin that
        // wants its component "clickable" in the live preview declares
        // a 'selector' on its component_tokens() group (the real CSS
        // selector for that component's root element — e.g. '.bh-badge',
        // '.bhc-course-sidebar'). This is intentionally the full extent
        // of the "click an element to edit it" feature: a click walks up
        // the DOM from the click point, finds the nearest ancestor
        // matching any registered selector, and jumps the CONTROLS pane
        // to that group's section — no floating inspector panel, no
        // persistent overlay, no linked-component/instance system (the
        // page-builder saga's actual mistake, per CLAUDE.md). Right-click
        // does the same walk but collects EVERY matching ancestor (for
        // nested components — a badge inside a card) and shows a small
        // native-styled menu to pick which one's section to jump to.
        $selectors = [];
        foreach (BHY_Style::component_tokens() as $group => $def) {
            if (!empty($def['selector'])) {
                $selectors[] = ['group' => sanitize_key($group), 'selector' => (string) $def['selector'], 'label' => $def['label'] ?? $group];
            }
        }
        wp_localize_script('bhy-customizer-preview', 'bhyCustomizerSelectors', $selectors);
    }

    public static function enqueue_controls_script() {
        wp_add_inline_script('customize-controls', "
            (function () {
                wp.customize.bind('ready', function () {
                    wp.customize.previewer.bind('bhy-jump-to-section', function (group) {
                        var section = wp.customize.section('bhy_live_component_' + group);
                        if (section) section.focus();
                    });
                });
            })();
        ");
    }

    /**
     * Where the "Open Live Editor" button (BHY_Gallery::render()) sends
     * the admin. A plugin whose surfaces are the main thing worth editing
     * in context (bh-courses' catalog, say) can override this to its own
     * real front-end page instead of the site home.
     */
    public static function default_preview_url(): string {
        return apply_filters('bhy_customizer_default_preview_url', home_url('/'));
    }
}
