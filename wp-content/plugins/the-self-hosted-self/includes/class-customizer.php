<?php
if (!defined('ABSPATH')) exit;

/**
 * Design Suite controls inside WordPress's native Customizer, previewed via
 * postMessage and saved to the same bhy_style_settings option the Design
 * Suite page uses (array-syntax setting ids deep-merge on save).
 * Scope: per-component tokens, plugin sliders/fonts and custom CSS; global
 * scheme editing stays on the Design Suite page.
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
                . "\n\n" . 'Click any styled element in the preview to jump straight to its section below. Right-click it for a menu of every component at that point (useful when one is nested inside another, like a badge inside a card), plus "Add custom rule for this element" (starts a new rule in the Custom CSS section below, right here) and "Copy CSS selector" (for the Advanced box in that same section).',
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

        // Counterpart of the Design Suite page's Custom CSS section.
        $wp_customize->add_section('bhy_live_custom_css', [
            'title'       => 'Custom CSS',
            'panel'       => 'bhy_live_design',
            'description' => 'For anything the Component sections above don\'t cover. Right-click an element in the preview for "Add custom rule for this element" (starts a rule below, prefilled) or "Copy CSS selector" (for the Advanced box).',
        ]);

        // Same visual rule editor as the Design Suite page; both write
        // custom_css_rules through BHY_Style::sanitize_custom_css_rules().
        if (class_exists('BHY_Style')) {
            require_once __DIR__ . '/class-customize-css-rules-control.php';
            $wp_customize->add_setting('bhy_style_settings[custom_css_rules]', [
                'type'              => 'option',
                'default'           => BHY_Style::get()['custom_css_rules'] ?? [],
                'sanitize_callback' => ['BHY_Style', 'sanitize_custom_css_rules'],
                'transport'         => 'postMessage',
            ]);
            $wp_customize->add_control(new \BHY_Customize_Css_Rules_Control($wp_customize, 'bhy_style_settings[custom_css_rules]', [
                'section'  => 'bhy_live_custom_css',
                'settings' => 'bhy_style_settings[custom_css_rules]',
                'priority' => 5,
            ]));
        }

        // Raw-CSS escape hatch using WP core's Code Editor control; the
        // preview script swaps the <style> tag via postMessage.
        if (class_exists('WP_Customize_Code_Editor_Control')) {
            $wp_customize->add_setting('bhy_style_settings[custom_css]', [
                'type'              => 'option',
                'default'           => '',
                'sanitize_callback' => function ($val) {
                    return substr(str_ireplace(['</style', '<script'], ['<\/style', '<\/script'], (string) $val), 0, 20000);
                },
                'transport' => 'postMessage',
            ]);
            $wp_customize->add_control(new \WP_Customize_Code_Editor_Control($wp_customize, 'bhy_style_settings[custom_css]', [
                'label'     => 'Advanced / raw CSS',
                'section'   => 'bhy_live_custom_css',
                'settings'  => 'bhy_style_settings[custom_css]',
                'code_type' => 'text/css',
                'priority'  => 20,
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

        // Components that declare a 'selector' become clickable in the
        // preview: click jumps to the nearest matching group's section,
        // right-click lists every matching ancestor. Deliberately no
        // inspector/overlay/instance system.
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
     * Preview URL for "Open Live Editor"; filterable so a plugin can point
     * it at its own front-end page.
     */
    public static function default_preview_url(): string {
        return apply_filters('bhy_customizer_default_preview_url', home_url('/'));
    }
}
