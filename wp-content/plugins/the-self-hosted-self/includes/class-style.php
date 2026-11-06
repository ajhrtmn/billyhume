<?php
if (!defined('ABSPATH')) exit;

/**
 * Design-token system (colors, typography, spacing, theme presets) every
 * ecosystem stylesheet reads from, with optional per-entity overrides.
 * Stored as one option array: always read together, never queried piecemeal.
 */
class BHY_Style {
    /**
     * WHY: tokens must exist on every page, not just pages that echo inline_css().
     * The editor iframe is a separate document that ignores admin_head, so it
     * gets them via block_editor_settings_all's `styles` list instead.
     */
    public static function init(): void {
        add_action('wp_head', [self::class, 'print_global_css']);
        add_filter('block_editor_settings_all', [self::class, 'add_editor_iframe_styles']);
    }

    /**
     * Prints the front-end CSS layers in order (see STYLE-SYSTEM.md):
     * tokens (inline_css), utilities (text_overflow_utils_css), components (badge_css).
     * Anything more specific belongs in the consuming plugin's own stylesheet.
     */
    public static function print_global_css(): void {
        echo '<style id="bhy-global-vars">' . self::inline_css() . '</style>';
        echo '<style id="bh-text-overflow-utils">' . self::text_overflow_utils_css() . '</style>';
        echo '<style id="bh-badge">' . self::badge_css() . '</style>';
        echo '<style id="bh-alert">' . self::alert_css() . '</style>';
        // Load the webfont file the --bh-font-* vars reference; without this the
        // vars point at a font that was never fetched. 'System UI' has no URL.
        $fonts_url = self::google_fonts_url();
        if ($fonts_url !== '') {
            echo '<link rel="stylesheet" id="bhy-google-fonts" href="' . esc_url($fonts_url) . '">';
        }
    }

    /**
     * Front-end text-overflow utilities:
     *   .bh-nowrap         fixed-vocabulary badge labels that must never wrap.
     *   .bh-badge-truncate dynamic badge text; bounded width plus ellipsis.
     *   .bh-truncate       single-line truncation; pair with title="full text".
     *   .bh-clamp-2/-3     multi-line clamp for card titles/descriptions.
     * Long unbreakable tokens (URLs, hashes) use overflow-wrap inline instead.
     */
    private static function text_overflow_utils_css(): string {
        return '.bh-nowrap{white-space:nowrap;}'
            . '.bh-badge-truncate{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;display:inline-block;vertical-align:bottom;}'
            . '.bh-truncate{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
            . '.bh-clamp-2{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}'
            . '.bh-clamp-3{display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;}';
    }

    /**
     * WHY: status colors (success/warning/danger) are fixed, not themeable per
     * entity. Front-end --bh-success (#1DB954) intentionally differs from the
     * admin --bhy-success; they are separate visual contexts.
     */
    /**
     * LAYER 3 component: the front end's message block.
     * WHY: body text is --bh-text, not the hue; the hue lives in the left rule
     * and icon so the prose stays legible on any tint.
     */
    private static function alert_css(): string {
        return '.bh-alert{'
            . 'display:flex;gap:10px;align-items:flex-start;'
            . 'padding:12px 14px;margin:0 0 14px;'
            . 'border-radius:var(--bh-radius-sm,8px);'
            . 'border:1px solid var(--bh-border,#3d3527);'
            . 'border-left:4px solid var(--bh-text-dim,#6b7280);'
            . 'background:var(--bh-surface-2,var(--bh-surface,#1e1b15));'
            . 'color:var(--bh-text,inherit);'
            . 'font-size:14px;line-height:1.5;'
            . '}'
            . '.bh-alert-title{font-weight:600;margin:0 0 2px;}'
            . '.bh-alert p{margin:0;}'
            . '.bh-alert p + p{margin-top:6px;}'
            . '.bh-alert-icon{flex:0 0 auto;font-size:15px;line-height:1.4;}'
            // WHY: the icon mixes the hue toward --bh-text so it clears 3:1 on both
            // light and dark grounds; the raw admin hues fail on the dark front end.
            . '.bh-alert-success{border-left-color:var(--bh-success,#1DB954);}'
            . '.bh-alert-success .bh-alert-icon{color:color-mix(in srgb,var(--bh-success,#1DB954) 60%,var(--bh-text,#f2ede1));}'
            . '.bh-alert-warning{border-left-color:var(--bh-warning,#8a5a00);}'
            . '.bh-alert-warning .bh-alert-icon{color:color-mix(in srgb,var(--bh-warning,#8a5a00) 60%,var(--bh-text,#f2ede1));}'
            . '.bh-alert-error{border-left-color:var(--bh-danger,#b3261e);}'
            . '.bh-alert-error .bh-alert-icon{color:color-mix(in srgb,var(--bh-danger,#b3261e) 60%,var(--bh-text,#f2ede1));}'
            . '.bh-alert-info{border-left-color:var(--bh-accent,#2271b1);}'
            . '.bh-alert-info .bh-alert-icon{color:color-mix(in srgb,var(--bh-accent,#2271b1) 60%,var(--bh-text,#f2ede1));}';
    }

    /**
     * Renders a front-end alert. Callers pass text, never markup, so an
     * error string that happens to contain HTML cannot become markup.
     *
     * @param string $message plain text
     * @param string $type    error|warning|success|info
     * @param string $title   optional bold first line
     */
    public static function alert(string $message, string $type = 'error', string $title = ''): string {
        $icons = ['error' => '&#9888;', 'warning' => '&#9888;', 'success' => '&#10003;', 'info' => '&#8505;'];
        $type = isset($icons[$type]) ? $type : 'error';
        $roles = ['error' => ' role="alert"', 'warning' => ' role="alert"'];
        $html  = '<div class="bh-alert bh-alert-' . $type . '"' . ($roles[$type] ?? '') . '>';
        $html .= '<span class="bh-alert-icon" aria-hidden="true">' . $icons[$type] . '</span>';
        $html .= '<div>';
        if ($title !== '') $html .= '<p class="bh-alert-title">' . esc_html($title) . '</p>';
        $html .= '<p>' . esc_html($message) . '</p>';
        $html .= '</div></div>';
        return $html;
    }

    private static function badge_css(): string {
        return ':root{'
            . '--bh-success:#1DB954;--bh-success-bg:rgba(29,185,84,0.15);'
            . '--bh-warning:#8a5a00;--bh-warning-bg:#fef3e2;'
            . '--bh-danger:#b3261e;--bh-danger-bg:#fbe4e2;'
            . '}'
            // Badge sizing comes from the 'badge' component_tokens() group, with
            // the original values as fallbacks.
            . '.bh-badge{display:inline-block;padding:var(--bh-comp-badge-padding_v,2px) var(--bh-comp-badge-padding_h,10px);border-radius:var(--bh-comp-badge-radius,999px);font-size:var(--bh-comp-badge-font_size,11px);font-weight:var(--bh-comp-badge-font_weight,600);white-space:nowrap;background:var(--bh-comp-badge-bg_color,#f0f0f1);color:var(--bh-comp-badge-text_color,#646970);}'
            . '.bh-badge-success{background:var(--bh-success-bg);color:var(--bh-success);}'
            . '.bh-badge-warning{background:var(--bh-warning-bg);color:var(--bh-warning);}'
            . '.bh-badge-danger{background:var(--bh-danger-bg);color:var(--bh-danger);}'
            . '.bh-badge-neutral{background:var(--bh-surface-2,#f0f0f1);color:var(--bh-text-dim,#646970);}';
        // For a truncating badge, combine with .bh-badge-truncate rather than
        // adding another variant here.
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function add_editor_iframe_styles($settings): array {
        $settings['styles'][] = ['css' => self::inline_css()];
        return $settings;
    }

    const OPTION = 'bhy_style_settings';

    const DEFAULTS = [
        // Generic, brand-neutral default wordmark.
        'brand_part1'  => 'Your',
        'brand_part2'  => 'Brand',
        'brand_logo_id' => '',
        'color_bg'          => '#170807',
        'color_surface'     => '#220C0A',
        'color_surface_2'   => '#2C120E',
        'color_border'      => '#3D1B14',
        'color_text'        => '#EDDFCB',
        'color_text_dim'    => '#B99584',
        // WHY: tuned so accent-on-night and dark-ink-on-accent both pass AA (4.5:1).
        'color_accent'      => '#C85C48',
        'color_accent_soft' => '#E0A184',
        'color_overlay'     => '#0D0504D1',
        'cat_color_1' => '#C1503A', 'cat_color_2' => '#D9A441', 'cat_color_3' => '#B8785A', 'cat_color_4' => '#8C3B2E',
        'cat_color_5' => '#C98B5E', 'cat_color_6' => '#A66A4D', 'cat_color_7' => '#D96C4D', 'cat_color_8' => '#7A4A38',
        // WHY: 'Theme default' (INHERIT_FONT) keeps the active theme's typeface
        // so the ecosystem looks like the host site out of the box.
        'font_display'        => 'Theme default',
        'font_display_custom' => '',
        'font_body'           => 'Theme default',
        'font_body_custom'    => '',
        'font_scale'  => '1',
        'space_scale' => '1',
        'radius'      => '12',
        'radius_sm'   => '8',
        'bar_height'  => '84',
    ];

    // The one non-Google-Font choice; emits `inherit` so the theme's font wins.
    // Handled specially everywhere FONT_OPTIONS is iterated.
    const INHERIT_FONT = 'Theme default';

    const FONT_OPTIONS = [
        'Space Grotesk'         => 'Space+Grotesk:wght@500;600;700',
        'Inter'                 => 'Inter:wght@400;500;600;700',
        'Poppins'               => 'Poppins:wght@400;500;600;700',
        'Montserrat'            => 'Montserrat:wght@400;500;600;700',
        'Playfair Display'      => 'Playfair+Display:wght@500;600;700',
        'Bebas Neue'            => 'Bebas+Neue',
        'Oswald'                => 'Oswald:wght@400;500;600;700',
        'DM Sans'               => 'DM+Sans:wght@400;500;600;700',
        'Sora'                  => 'Sora:wght@500;600;700',
        'Work Sans'             => 'Work+Sans:wght@400;500;600;700',
        'Roboto Mono'           => 'Roboto+Mono:wght@400;500;600;700',
        // Jost + Atkinson Hyperlegible (built for low-vision legibility) is the
        // recommended pairing. All options are free/OFL Google Fonts.
        'Jost'                  => 'Jost:wght@400;500;600;700',
        'Josefin Sans'          => 'Josefin+Sans:wght@400;600;700',
        'Righteous'             => 'Righteous',
        'Atkinson Hyperlegible' => 'Atkinson+Hyperlegible:wght@400;700',
        'System UI'             => null,
    ];

    const THEME_GROUPS = [
        'Signature' => [
            'The Door — Night' => [
                'color_bg' => '#170807', 'color_surface' => '#220C0A', 'color_surface_2' => '#2C120E',
                'color_border' => '#3D1B14', 'color_text' => '#EDDFCB', 'color_text_dim' => '#B99584',
                'color_accent' => '#C85C48', 'color_accent_soft' => '#E0A184', 'color_overlay' => '#0D0504D1',
                'cat_color_1' => '#C1503A', 'cat_color_2' => '#D9A441', 'cat_color_3' => '#B8785A', 'cat_color_4' => '#8C3B2E',
                'cat_color_5' => '#C98B5E', 'cat_color_6' => '#A66A4D', 'cat_color_7' => '#D96C4D', 'cat_color_8' => '#7A4A38',
            ],
            'The Door — Day' => [
                'color_bg' => '#F4E9DC', 'color_surface' => '#EADCC8', 'color_surface_2' => '#E0CFB5',
                'color_border' => '#C9B096', 'color_text' => '#2B120C', 'color_text_dim' => '#6B4A3A',
                // WHY: darker than Night's accent so text on Day's light grounds passes AA.
                'color_accent' => '#A83D1A', 'color_accent_soft' => '#E8B49A', 'color_overlay' => '#170807B3',
                'cat_color_1' => '#C1503A', 'cat_color_2' => '#B8860B', 'cat_color_3' => '#8B5E3C', 'cat_color_4' => '#7A2E22',
                'cat_color_5' => '#A8623D', 'cat_color_6' => '#8C5A3C', 'cat_color_7' => '#B6432E', 'cat_color_8' => '#5C2C1E',
            ],
            'Neon Vinyl' => [
                'color_bg' => '#0B0B0E', 'color_surface' => '#17171D', 'color_surface_2' => '#1F1F27',
                'color_border' => '#2A2A33', 'color_text' => '#F3F2F5', 'color_text_dim' => '#93919D',
                'color_accent' => '#FF5A36', 'color_accent_soft' => '#FFB199', 'color_overlay' => '#060608D1',
                'cat_color_1' => '#FF5A36', 'cat_color_2' => '#2DD4BF', 'cat_color_3' => '#A78BFA', 'cat_color_4' => '#F472B6',
                'cat_color_5' => '#38BDF8', 'cat_color_6' => '#A3E635', 'cat_color_7' => '#FBBF24', 'cat_color_8' => '#FB7185',
            ],
        ],
        'Vivid & Bold' => [
            'Midnight Blue' => [
                'color_bg' => '#060912', 'color_surface' => '#101A2E', 'color_surface_2' => '#16233D',
                'color_border' => '#223052', 'color_text' => '#EAF1FF', 'color_text_dim' => '#8CA0C9',
                'color_accent' => '#4C8DFF', 'color_accent_soft' => '#A7C8FF', 'color_overlay' => '#03060DD1',
                'cat_color_1' => '#4C8DFF', 'cat_color_2' => '#38D6C0', 'cat_color_3' => '#B892FF', 'cat_color_4' => '#FF7CB0',
                'cat_color_5' => '#52C7F5', 'cat_color_6' => '#9FE066', 'cat_color_7' => '#FFCE54', 'cat_color_8' => '#FF8FA3',
            ],
            'Sunset Gold' => [
                'color_bg' => '#170D08', 'color_surface' => '#241309', 'color_surface_2' => '#33190C',
                'color_border' => '#4A2A14', 'color_text' => '#FFF3E4', 'color_text_dim' => '#D8AE8A',
                'color_accent' => '#F5A623', 'color_accent_soft' => '#FFCB7A', 'color_overlay' => '#12090AD1',
                'cat_color_1' => '#F5A623', 'cat_color_2' => '#FF7C4D', 'cat_color_3' => '#E8546B', 'cat_color_4' => '#C77DFF',
                'cat_color_5' => '#4DB6AC', 'cat_color_6' => '#9CCC65', 'cat_color_7' => '#FFD54F', 'cat_color_8' => '#FF8A65',
            ],
            'Cyberpunk Magenta' => [
                'color_bg' => '#0A0410', 'color_surface' => '#150A22', 'color_surface_2' => '#1D0F30',
                'color_border' => '#331A4D', 'color_text' => '#F5E8FF', 'color_text_dim' => '#B48FD9',
                'color_accent' => '#FF2E9C', 'color_accent_soft' => '#FF8AC4', 'color_overlay' => '#05020AD1',
                'cat_color_1' => '#FF2E9C', 'cat_color_2' => '#00E5FF', 'cat_color_3' => '#7B61FF', 'cat_color_4' => '#FFD400',
                'cat_color_5' => '#00FFA3', 'cat_color_6' => '#FF6B35', 'cat_color_7' => '#C77DFF', 'cat_color_8' => '#39FF14',
            ],
            'Retro Arcade' => [
                'color_bg' => '#04080F', 'color_surface' => '#0B1522', 'color_surface_2' => '#101E30',
                'color_border' => '#1C324A', 'color_text' => '#E8F6FF', 'color_text_dim' => '#7FA0BE',
                'color_accent' => '#39FF14', 'color_accent_soft' => '#9BFF7A', 'color_overlay' => '#02050AD1',
                'cat_color_1' => '#39FF14', 'cat_color_2' => '#FF2E9C', 'cat_color_3' => '#00E5FF', 'cat_color_4' => '#FFD400',
                'cat_color_5' => '#FF6B35', 'cat_color_6' => '#7B61FF', 'cat_color_7' => '#FF3860', 'cat_color_8' => '#00FFD1',
            ],
            'Tropical Punch' => [
                'color_bg' => '#150C08', 'color_surface' => '#1A1210', 'color_surface_2' => '#241814',
                'color_border' => '#3A241C', 'color_text' => '#FFF6EE', 'color_text_dim' => '#E0AD8C',
                'color_accent' => '#FF6B4A', 'color_accent_soft' => '#FFAB8C', 'color_overlay' => '#0A0503D1',
                'cat_color_1' => '#FF6B4A', 'cat_color_2' => '#FFD23F', 'cat_color_3' => '#06D6A0', 'cat_color_4' => '#118AB2',
                'cat_color_5' => '#EF476F', 'cat_color_6' => '#FFA6C1', 'cat_color_7' => '#7BDFF2', 'cat_color_8' => '#B892FF',
            ],
            'Forest Rave' => [
                'color_bg' => '#050B08', 'color_surface' => '#0C1712', 'color_surface_2' => '#12211A',
                'color_border' => '#1E362A', 'color_text' => '#EAFBF1', 'color_text_dim' => '#86B39C',
                'color_accent' => '#39FF88', 'color_accent_soft' => '#8CFFC0', 'color_overlay' => '#030604D1',
                'cat_color_1' => '#39FF88', 'cat_color_2' => '#FFD23F', 'cat_color_3' => '#FF3860', 'cat_color_4' => '#7B61FF',
                'cat_color_5' => '#00D4FF', 'cat_color_6' => '#FF8C42', 'cat_color_7' => '#C77DFF', 'cat_color_8' => '#FF6B9D',
            ],
        ],
        'Muted & Moody' => [
            'Deep Ocean' => [
                'color_bg' => '#050A0E', 'color_surface' => '#0C161C', 'color_surface_2' => '#122029',
                'color_border' => '#1E323D', 'color_text' => '#E4F2F7', 'color_text_dim' => '#7FA3B0',
                'color_accent' => '#2AB7CA', 'color_accent_soft' => '#7FD8E3', 'color_overlay' => '#03060AD1',
                'cat_color_1' => '#2AB7CA', 'cat_color_2' => '#4C6EF5', 'cat_color_3' => '#845EF7', 'cat_color_4' => '#20C997',
                'cat_color_5' => '#FAB005', 'cat_color_6' => '#FF6B6B', 'cat_color_7' => '#63E6BE', 'cat_color_8' => '#748FFC',
            ],
            'Terracotta' => [
                'color_bg' => '#120C09', 'color_surface' => '#1E1410', 'color_surface_2' => '#2A1C15',
                'color_border' => '#402A1F', 'color_text' => '#FBF0E7', 'color_text_dim' => '#C9A186',
                'color_accent' => '#C9663B', 'color_accent_soft' => '#E39B72', 'color_overlay' => '#0A0605D1',
                'cat_color_1' => '#C9663B', 'cat_color_2' => '#B5891F', 'cat_color_3' => '#7A8C5E', 'cat_color_4' => '#5E7A8C',
                'cat_color_5' => '#8C5E6F', 'cat_color_6' => '#D4A24C', 'cat_color_7' => '#6F8C7A', 'cat_color_8' => '#A85E4C',
            ],
            'Plum Noir' => [
                'color_bg' => '#0B0510', 'color_surface' => '#16091E', 'color_surface_2' => '#200E2C',
                'color_border' => '#331947', 'color_text' => '#F2E6FA', 'color_text_dim' => '#A98CC2',
                'color_accent' => '#9B4DCA', 'color_accent_soft' => '#C48CE0', 'color_overlay' => '#05030AD1',
                'cat_color_1' => '#9B4DCA', 'cat_color_2' => '#4DA8CA', 'cat_color_3' => '#CA4D8F', 'cat_color_4' => '#CAA84D',
                'cat_color_5' => '#4DCA8F', 'cat_color_6' => '#8F4DCA', 'cat_color_7' => '#CA6B4D', 'cat_color_8' => '#4D6BCA',
            ],
            'Coffee House' => [
                'color_bg' => '#0F0B08', 'color_surface' => '#191310', 'color_surface_2' => '#241C16',
                'color_border' => '#392C22', 'color_text' => '#F5EDE3', 'color_text_dim' => '#BFA48C',
                'color_accent' => '#B8793E', 'color_accent_soft' => '#D9A671', 'color_overlay' => '#080604D1',
                'cat_color_1' => '#B8793E', 'cat_color_2' => '#7E9E6F', 'cat_color_3' => '#6F8E9E', 'cat_color_4' => '#9E6F8E',
                'cat_color_5' => '#C2A25A', 'cat_color_6' => '#8E6F5A', 'cat_color_7' => '#5A8E7E', 'cat_color_8' => '#9E7F5A',
            ],
        ],
        'Monochrome' => [
            'Mono Slate' => [
                'color_bg' => '#101113', 'color_surface' => '#17181B', 'color_surface_2' => '#1E2024',
                'color_border' => '#2C2F34', 'color_text' => '#F1F1F2', 'color_text_dim' => '#9A9DA3',
                'color_accent' => '#7C8CA8', 'color_accent_soft' => '#AEB9CC', 'color_overlay' => '#0A0A0BD1',
                'cat_color_1' => '#7C8CA8', 'cat_color_2' => '#8FA3B0', 'cat_color_3' => '#A79BC7', 'cat_color_4' => '#B08FA0',
                'cat_color_5' => '#7FAFA0', 'cat_color_6' => '#9BAE7F', 'cat_color_7' => '#B8A26E', 'cat_color_8' => '#A98F8F',
            ],
            'Mono Charcoal' => [
                'color_bg' => '#101010', 'color_surface' => '#181818', 'color_surface_2' => '#1F1F1F',
                'color_border' => '#2D2D2D', 'color_text' => '#F0F0F0', 'color_text_dim' => '#9A9A9A',
                'color_accent' => '#999999', 'color_accent_soft' => '#C2C2C2', 'color_overlay' => '#0A0A0AD1',
                'cat_color_1' => '#999999', 'cat_color_2' => '#ABABAB', 'cat_color_3' => '#858585', 'cat_color_4' => '#B5B5B5',
                'cat_color_5' => '#7A7A7A', 'cat_color_6' => '#C7C7C7', 'cat_color_7' => '#6E6E6E', 'cat_color_8' => '#D8D8D8',
            ],
            'Mono Stone' => [
                'color_bg' => '#121110', 'color_surface' => '#1A1817', 'color_surface_2' => '#221F1D',
                'color_border' => '#362F2B', 'color_text' => '#F2EDE8', 'color_text_dim' => '#ABA096',
                'color_accent' => '#A69582', 'color_accent_soft' => '#C9BCAE', 'color_overlay' => '#0A0908D1',
                'cat_color_1' => '#A69582', 'cat_color_2' => '#8C7C6C', 'cat_color_3' => '#BFAF9E', 'cat_color_4' => '#796A5C',
                'cat_color_5' => '#D1C3B4', 'cat_color_6' => '#675A4E', 'cat_color_7' => '#E0D4C6', 'cat_color_8' => '#5A4E43',
            ],
            'Mono Sage' => [
                'color_bg' => '#0E100E', 'color_surface' => '#161916', 'color_surface_2' => '#1E221E',
                'color_border' => '#2E332E', 'color_text' => '#EDF2ED', 'color_text_dim' => '#9CAB9C',
                'color_accent' => '#8FA88F', 'color_accent_soft' => '#B3C6B3', 'color_overlay' => '#090A09D1',
                'cat_color_1' => '#8FA88F', 'cat_color_2' => '#7A947A', 'cat_color_3' => '#A3BCA3', 'cat_color_4' => '#6B856B',
                'cat_color_5' => '#B8CFB8', 'cat_color_6' => '#5C755C', 'cat_color_7' => '#CCDECC', 'cat_color_8' => '#4D634D',
            ],
            'Mono Mauve' => [
                'color_bg' => '#100E10', 'color_surface' => '#191619', 'color_surface_2' => '#221E22',
                'color_border' => '#342E34', 'color_text' => '#F2EDF2', 'color_text_dim' => '#AB9CAB',
                'color_accent' => '#A88FA8', 'color_accent_soft' => '#C6B3C6', 'color_overlay' => '#0A090AD1',
                'cat_color_1' => '#A88FA8', 'cat_color_2' => '#947A94', 'cat_color_3' => '#BCA3BC', 'cat_color_4' => '#856B85',
                'cat_color_5' => '#CFB8CF', 'cat_color_6' => '#755C75', 'cat_color_7' => '#DECCDE', 'cat_color_8' => '#634D63',
            ],
        ],
        // Light-background presets.
        'Pastel & Soft' => [
            'Cotton Candy' => [
                'color_bg' => '#FDF2F8', 'color_surface' => '#FCE7F3', 'color_surface_2' => '#FBCFE8',
                'color_border' => '#F5B8DC', 'color_text' => '#4A1942', 'color_text_dim' => '#9D5A8F',
                'color_accent' => '#EC4899', 'color_accent_soft' => '#F9A8D4', 'color_overlay' => '#4A1942B3',
                'cat_color_1' => '#EC4899', 'cat_color_2' => '#A78BFA', 'cat_color_3' => '#34D399', 'cat_color_4' => '#FBBF24',
                'cat_color_5' => '#60A5FA', 'cat_color_6' => '#FB7185', 'cat_color_7' => '#2DD4BF', 'cat_color_8' => '#F472B6',
            ],
            'Sage Garden' => [
                'color_bg' => '#F3F7F2', 'color_surface' => '#E8F0E5', 'color_surface_2' => '#DCE9D7',
                'color_border' => '#C3D9BB', 'color_text' => '#23331D', 'color_text_dim' => '#5C7550',
                'color_accent' => '#6B9B4F', 'color_accent_soft' => '#A3C98D', 'color_overlay' => '#23331DB3',
                'cat_color_1' => '#6B9B4F', 'cat_color_2' => '#D9A441', 'cat_color_3' => '#B8785A', 'cat_color_4' => '#4C8DFF',
                'cat_color_5' => '#E8546B', 'cat_color_6' => '#38BDF8', 'cat_color_7' => '#9B4DCA', 'cat_color_8' => '#C1503A',
            ],
            'Powder Blue' => [
                'color_bg' => '#F0F6FA', 'color_surface' => '#E1EDF5', 'color_surface_2' => '#D0E3F0',
                'color_border' => '#B3D0E6', 'color_text' => '#14293A', 'color_text_dim' => '#4E6E85',
                'color_accent' => '#3B82C4', 'color_accent_soft' => '#8FC0E8', 'color_overlay' => '#14293AB3',
                'cat_color_1' => '#3B82C4', 'cat_color_2' => '#F5A623', 'cat_color_3' => '#06D6A0', 'cat_color_4' => '#EF476F',
                'cat_color_5' => '#7B61FF', 'cat_color_6' => '#FFD23F', 'cat_color_7' => '#118AB2', 'cat_color_8' => '#FF6B4A',
            ],
            'Peach Fuzz' => [
                'color_bg' => '#FDF4EC', 'color_surface' => '#FCE9D6', 'color_surface_2' => '#F9DBBC',
                'color_border' => '#F0C79A', 'color_text' => '#3D2410', 'color_text_dim' => '#8C6A45',
                'color_accent' => '#F2884B', 'color_accent_soft' => '#F7B685', 'color_overlay' => '#3D2410B3',
                'cat_color_1' => '#F2884B', 'cat_color_2' => '#4DB6AC', 'cat_color_3' => '#9B4DCA', 'cat_color_4' => '#4C8DFF',
                'cat_color_5' => '#E8546B', 'cat_color_6' => '#9CCC65', 'cat_color_7' => '#FFD54F', 'cat_color_8' => '#FF8A65',
            ],
        ],
        'Nature & Organic' => [
            'Autumn Forest' => [
                'color_bg' => '#0A0F08', 'color_surface' => '#131C0F', 'color_surface_2' => '#1B2814',
                'color_border' => '#2E4222', 'color_text' => '#EFF5E8', 'color_text_dim' => '#9BB98A',
                'color_accent' => '#D9772E', 'color_accent_soft' => '#F0AD70', 'color_overlay' => '#05070399',
                'cat_color_1' => '#D9772E', 'cat_color_2' => '#6B9B4F', 'cat_color_3' => '#C9A227', 'cat_color_4' => '#4C8DFF',
                'cat_color_5' => '#B8425A', 'cat_color_6' => '#7A9E6F', 'cat_color_7' => '#E0B94D', 'cat_color_8' => '#8C5E3C',
            ],
            'Desert Bloom' => [
                'color_bg' => '#120D08', 'color_surface' => '#1E150D', 'color_surface_2' => '#2A1E12',
                'color_border' => '#402D1C', 'color_text' => '#F7ECD9', 'color_text_dim' => '#C2A47E',
                'color_accent' => '#E0A458', 'color_accent_soft' => '#F0C98C', 'color_overlay' => '#0A0705D1',
                'cat_color_1' => '#E0A458', 'cat_color_2' => '#7A9E6F', 'cat_color_3' => '#C9663B', 'cat_color_4' => '#4DA8CA',
                'cat_color_5' => '#CA4D8F', 'cat_color_6' => '#B5891F', 'cat_color_7' => '#6F8C7A', 'cat_color_8' => '#A85E4C',
            ],
            'Ocean Reef' => [
                'color_bg' => '#05100E', 'color_surface' => '#0C1E1A', 'color_surface_2' => '#142A24',
                'color_border' => '#21493D', 'color_text' => '#E4F7F1', 'color_text_dim' => '#7FC4B0',
                'color_accent' => '#2ABF9E', 'color_accent_soft' => '#7FE0C7', 'color_overlay' => '#03080699',
                'cat_color_1' => '#2ABF9E', 'cat_color_2' => '#4C8DFF', 'cat_color_3' => '#F5A623', 'cat_color_4' => '#E8546B',
                'cat_color_5' => '#9B4DCA', 'cat_color_6' => '#FFD23F', 'cat_color_7' => '#38BDF8', 'cat_color_8' => '#FF6B4A',
            ],
            'Lavender Fields' => [
                'color_bg' => '#0D0A14', 'color_surface' => '#171123', 'color_surface_2' => '#211932',
                'color_border' => '#362853', 'color_text' => '#F1E9FA', 'color_text_dim' => '#B39BD9',
                'color_accent' => '#8B5FBF', 'color_accent_soft' => '#BB9AE0', 'color_overlay' => '#06040AD1',
                'cat_color_1' => '#8B5FBF', 'cat_color_2' => '#4C8DFF', 'cat_color_3' => '#2AB7CA', 'cat_color_4' => '#E8546B',
                'cat_color_5' => '#D9A441', 'cat_color_6' => '#6B9B4F', 'cat_color_7' => '#FF6B9D', 'cat_color_8' => '#C77DFF',
            ],
        ],
    ];

    // Fields a single entity may override: the color palette plus brand identity.
    // Typography, spacing and component sizing stay global for consistency.
    const OVERRIDABLE_FIELDS = [
        'brand_part1', 'brand_part2', 'brand_logo_id',
        'color_bg', 'color_surface', 'color_surface_2', 'color_border', 'color_text', 'color_text_dim',
        'color_accent', 'color_accent_soft', 'color_overlay',
        'cat_color_1', 'cat_color_2', 'cat_color_3', 'cat_color_4', 'cat_color_5', 'cat_color_6', 'cat_color_7', 'cat_color_8',
    ];

    // Pass an entity ID to layer its enabled overrides over the global settings.
    /** @return array<string, mixed> */
    public static function get(?int $entity_id = null): array {
        $saved = get_option(self::OPTION, []);
        $settings = array_merge(self::DEFAULTS, is_array($saved) ? $saved : []);

        if ($entity_id) {
            $overrides = self::entity_overrides($entity_id);
            if ($overrides) $settings = array_merge($settings, $overrides);
        }

        return $settings;
    }

    // Generic bhy_-prefixed meta keys any plugin can set on its own posts.
    // Only keys in OVERRIDABLE_FIELDS pass through.
    /** @return array<string, mixed> */
    public static function entity_overrides(int $entity_id): array {
        if (!get_post_meta($entity_id, '_bhy_style_override', true)) return [];
        $raw = get_post_meta($entity_id, '_bhy_style_json', true);
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data)) return [];
        return array_intersect_key($data, array_flip(self::OVERRIDABLE_FIELDS));
    }

    /** @param array<string, mixed> $settings */
    public static function logo_url($settings): string {
        $id = (int) ($settings['brand_logo_id'] ?? 0);
        if (!$id) return '';
        $url = wp_get_attachment_image_url($id, 'medium');
        return $url ?: '';
    }

    // Client-side payload scoped to one rendered instance; null when the
    // entity has no override, so callers can skip the data attribute.
    /** @return array<string, mixed>|null */
    public static function entity_style_payload(int $entity_id): ?array {
        $overrides = self::entity_overrides($entity_id);
        if (!$overrides) return null;

        $merged = self::get($entity_id);
        $var_names = [
            'color_bg' => '--bh-bg', 'color_surface' => '--bh-surface', 'color_surface_2' => '--bh-surface-2',
            'color_border' => '--bh-border', 'color_text' => '--bh-text', 'color_text_dim' => '--bh-text-dim',
            'color_accent' => '--bh-accent', 'color_accent_soft' => '--bh-accent-soft', 'color_overlay' => '--bh-overlay',
        ];
        for ($i = 1; $i <= 8; $i++) $var_names['cat_color_' . $i] = '--bh-cat-' . $i;

        $vars = [];
        foreach ($var_names as $field => $css_var) {
            if (isset($overrides[$field])) $vars[$css_var] = self::safe_color($merged[$field]);
        }
        // WHY: overridden seeds change the derived roles too; recompute them
        // from the merged seeds or they drift from the entity's real colors.
        $seed_fields = array_flip(array_keys(array_diff_key($var_names, ['color_overlay' => 1])));
        if (array_intersect_key($overrides, $seed_fields)) {
            foreach (BHY_Contrast::for_settings($merged) as $css_var => $val) {
                $vars[$css_var] = $val;
            }
        }

        return [
            'vars'  => $vars,
            'brand' => ['part1' => $merged['brand_part1'], 'part2' => $merged['brand_part2'], 'logoUrl' => self::logo_url($merged)],
        ];
    }

    // Inline <style> overriding the --bh-* properties every consuming stylesheet
    // reads; enqueued after those stylesheets so it wins the cascade.
    // Derived colors come from BHY_Contrast::resolve() (class-contrast.php).
    public static function inline_css(?int $entity_id = null, bool $include_custom_css = true): string {
        $s = self::get($entity_id);
        $vars = [
            '--bh-bg' => $s['color_bg'], '--bh-surface' => $s['color_surface'], '--bh-surface-2' => $s['color_surface_2'],
            '--bh-border' => $s['color_border'], '--bh-text' => $s['color_text'], '--bh-text-dim' => $s['color_text_dim'],
            '--bh-accent' => $s['color_accent'], '--bh-accent-soft' => $s['color_accent_soft'], '--bh-overlay' => $s['color_overlay'],
        ];
        for ($i = 1; $i <= 8; $i++) $vars['--bh-cat-' . $i] = $s['cat_color_' . $i];
        $decls = '';
        foreach ($vars as $name => $val) $decls .= $name . ':' . self::safe_color($val) . ';';

        // Every color derived from the seeds comes from BHY_Contrast's recipe
        // table. Debug Tools -> Contrast Audit shows each pairing measured.
        $derived = $entity_id === null
            ? BHY_Contrast::global_palette()
            : BHY_Contrast::for_settings($s);
        foreach ($derived as $var => $val) $decls .= $var . ':' . $val . ';';

        $fd = self::font_family($s, 'display');
        $fb = self::font_family($s, 'body');
        $decls .= '--bh-font-display:' . ($fd === 'inherit' ? 'inherit' : self::css_safe_string($fd) . ', sans-serif') . ';';
        $decls .= '--bh-font-body:'    . ($fb === 'inherit' ? 'inherit' : self::css_safe_string($fb) . ', sans-serif') . ';';
        $decls .= '--bh-font-scale:' . self::safe_number($s['font_scale'], 0.75, 1.6, 1) . ';';
        $decls .= '--bh-space-scale:' . self::safe_number($s['space_scale'], 0.6, 1.8, 1) . ';';
        $decls .= '--bh-radius:' . self::safe_number($s['radius'], 0, 32, 12) . 'px;';
        $decls .= '--bh-radius-sm:' . self::safe_number($s['radius_sm'], 0, 24, 8) . 'px;';
        $decls .= '--bh-bar-height:' . self::safe_number($s['bar_height'], 56, 140, 84) . 'px;';

        // Front-end elevation (shadow half; --bh-surface/-2 are the surface half).
        // Alpha is a middle value because the artist picks the ground color.
        $decls .= '--bh-shadow-sm:0 1px 2px rgba(0,0,0,.18), 0 2px 6px -2px rgba(0,0,0,.22);';
        $decls .= '--bh-shadow:0 12px 28px -14px rgba(0,0,0,.45);';
        $decls .= '--bh-shadow-lg:0 24px 56px -20px rgba(0,0,0,.55);';

        // Plugin-registered custom sliders, emitted as --bh-custom-<key>.
        foreach (self::custom_sliders() as $key => $def) {
            $safe_key = sanitize_key($key);
            $val = $s['custom'][$key] ?? ($def['default'] ?? 0);
            $decls .= '--bh-custom-' . $safe_key . ':' . self::safe_number($val, $def['min'] ?? 0, $def['max'] ?? 999999, $def['default'] ?? 0) . ($def['unit'] ?? '') . ';';
        }

        // Plugin-registered custom fonts, emitted as --bh-custom-font-<key>
        // (separate namespace so they cannot collide with slider keys).
        foreach (self::custom_fonts() as $key => $def) {
            $safe_key = sanitize_key($key);
            $val = trim((string) ($s['custom_fonts'][$key] ?? ''));
            if ($val === '') $val = trim((string) ($def['default'] ?? ''));
            $fallback = preg_replace('/[^a-z-]/', '', strtolower((string) ($def['fallback'] ?? 'sans-serif'))) ?: 'sans-serif';
            if ($val !== '') $decls .= '--bh-custom-font-' . $safe_key . ':' . self::css_safe_string($val) . ', ' . $fallback . ';';
        }

        // Grouped component tokens, emitted as --bh-comp-<group>-<key>.
        foreach (self::component_tokens() as $group => $def) {
            $safe_group = sanitize_key($group);
            foreach (($def['tokens'] ?? []) as $key => $tdef) {
                $safe_key = sanitize_key($key);
                $stored = $s['components'][$group][$key] ?? null;
                $type = $tdef['type'] ?? 'size';
                if ($type === 'color') {
                    $val = self::safe_color($stored ?? ($tdef['default'] ?? '#000000'));
                    $decls .= '--bh-comp-' . $safe_group . '-' . $safe_key . ':' . $val . ';';
                } elseif ($type === 'font') {
                    $val = trim((string) ($stored ?? '')) ?: trim((string) ($tdef['default'] ?? ''));
                    if ($val === '') continue;
                    $fallback = preg_replace('/[^a-z-]/', '', strtolower((string) ($tdef['fallback'] ?? 'sans-serif'))) ?: 'sans-serif';
                    $decls .= '--bh-comp-' . $safe_group . '-' . $safe_key . ':' . self::css_safe_string($val) . ', ' . $fallback . ';';
                } elseif ($type === 'toggle') {
                    // CSS keyword swap rather than 0/1: CSS cannot branch on a
                    // numeric custom property.
                    $on  = self::css_safe_string_keyword($tdef['on']  ?? 'none');
                    $off = self::css_safe_string_keyword($tdef['off'] ?? 'flex');
                    $val = !empty($stored) ? $on : $off;
                    $decls .= '--bh-comp-' . $safe_group . '-' . $safe_key . ':' . $val . ';';
                } else { // 'size' (default)
                    $val = self::safe_number($stored, $tdef['min'] ?? 0, $tdef['max'] ?? 999999, $tdef['default'] ?? 0);
                    $decls .= '--bh-comp-' . $safe_group . '-' . $safe_key . ':' . $val . ($tdef['unit'] ?? 'px') . ';';
                }
            }
        }

        $css = ':root{' . $decls . '}';

        // Raw custom CSS: real selectors, appended as-is; only guarded against
        // breaking out of <style>. $include_custom_css=false is for the gallery's
        // light-DOM preview, where arbitrary selectors would hit real admin chrome.
        if ($include_custom_css) {
            // Structured rules first, then raw CSS, so hand-typed overrides win.
            foreach ((array) ($s['custom_css_rules'] ?? []) as $rule) {
                if (!is_array($rule) || empty($rule['selector']) || empty($rule['declarations'])) continue;
                $selector = self::sanitize_css_selector($rule['selector']) . (string) ($rule['state'] ?? '');
                $decl_str = '';
                foreach ((array) $rule['declarations'] as $property => $value) {
                    $safe_property = preg_replace('/[^a-z-]/', '', sanitize_key((string) $property));
                    if ($safe_property === '') continue;
                    $decl_str .= $safe_property . ':' . $value . ';';
                }
                if ($decl_str !== '') $css .= "\n" . $selector . '{' . $decl_str . '}';
            }

            $custom = trim((string) ($s['custom_css'] ?? ''));
            if ($custom !== '') {
                $css .= "\n" . str_ireplace(['</style', '<script'], ['<\/style', '<\/script'], $custom);
            }
        }

        return $css;
    }

    /**
     * Plugin-registered numeric tokens, via the bhy_style_custom_sliders filter:
     *     $sliders['queue_row_height'] = ['label' => 'Queue row height', 'min' => 32,
     *         'max' => 96, 'step' => 2, 'unit' => 'px', 'default' => 48];
     * Stored under bhy_style_settings['custom'], emitted as --bh-custom-<key>.
     */
    /** @return array<string, mixed> */
    public static function custom_sliders(): array {
        return apply_filters('bhy_style_custom_sliders', []);
    }

    /**
     * Plugin-registered font tokens, via the bhy_style_custom_fonts filter:
     *     $fonts['caption'] = ['label' => 'Caption font', 'default' => 'Inter', 'fallback' => 'sans-serif'];
     * Stored under bhy_style_settings['custom_fonts'], emitted as --bh-custom-font-<key>.
     */
    /** @return array<string, mixed> */
    public static function custom_fonts(): array {
        return apply_filters('bhy_style_custom_fonts', []);
    }

    /**
     * Grouped component tokens, via the bhy_style_component_tokens filter:
     *     $components['course_card'] = ['label' => 'Course card', 'tokens' => [
     *         'thumb_radius' => ['label' => '...', 'type' => 'size', 'min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px', 'default' => 10],
     *         'accent'       => ['label' => '...', 'type' => 'color', 'default' => '#2271b1'],
     *         'heading_font' => ['label' => '...', 'type' => 'font', 'default' => 'Inter', 'fallback' => 'sans-serif'],
     *         'show_author'  => ['label' => '...', 'type' => 'toggle', 'on' => 'none', 'off' => 'flex', 'default' => false],
     *     ]];
     * 'toggle' swaps CSS display keywords ('on'/'off'). Stored under
     * bhy_style_settings['components'][group][key]; emitted as
     * --bh-comp-<group>-<key> (fonts: ...-<key>-font).
     */
    /** @return array<string, mixed> */
    public static function component_tokens(): array {
        return apply_filters('bhy_style_component_tokens', []);
    }

    /**
     * Property list for the visual Custom CSS rule editor: a curated set, not
     * all of CSS. Types: size/color/font/keyword. Filterable.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function css_property_registry(): array {
        $registry = [
            'color'            => ['label' => 'Text color',        'type' => 'color'],
            'background-color' => ['label' => 'Background color',  'type' => 'color'],
            'border-color'     => ['label' => 'Border color',      'type' => 'color'],
            'font-family'      => ['label' => 'Font',               'type' => 'font', 'fallback' => 'sans-serif'],
            'font-size'        => ['label' => 'Font size',          'type' => 'size', 'min' => 8,  'max' => 96,  'step' => 1, 'unit' => 'px', 'default' => 16],
            'font-weight'      => ['label' => 'Font weight',        'type' => 'keyword', 'options' => ['400' => 'Normal', '600' => 'Semibold', '700' => 'Bold', '800' => 'Extra bold'], 'default' => '400'],
            'line-height'      => ['label' => 'Line height',        'type' => 'size', 'min' => 0.8, 'max' => 3, 'step' => 0.05, 'unit' => '', 'default' => 1.4],
            'letter-spacing'   => ['label' => 'Letter spacing',     'type' => 'size', 'min' => -2, 'max' => 8, 'step' => 0.1, 'unit' => 'px', 'default' => 0],
            'text-align'       => ['label' => 'Text align',         'type' => 'keyword', 'options' => ['left' => 'Left', 'center' => 'Center', 'right' => 'Right', 'justify' => 'Justify'], 'default' => 'left'],
            'text-transform'   => ['label' => 'Text transform',     'type' => 'keyword', 'options' => ['none' => 'None', 'uppercase' => 'Uppercase', 'lowercase' => 'Lowercase', 'capitalize' => 'Capitalize'], 'default' => 'none'],
            'text-decoration'  => ['label' => 'Text decoration',    'type' => 'keyword', 'options' => ['none' => 'None', 'underline' => 'Underline', 'line-through' => 'Strikethrough'], 'default' => 'none'],
            'padding'          => ['label' => 'Padding (all sides)', 'type' => 'size', 'min' => 0, 'max' => 100, 'step' => 1, 'unit' => 'px', 'default' => 0],
            'margin'           => ['label' => 'Margin (all sides)', 'type' => 'size', 'min' => -100, 'max' => 100, 'step' => 1, 'unit' => 'px', 'default' => 0],
            'gap'              => ['label' => 'Gap',                'type' => 'size', 'min' => 0, 'max' => 100, 'step' => 1, 'unit' => 'px', 'default' => 0],
            'width'            => ['label' => 'Width',              'type' => 'size', 'min' => 0, 'max' => 2000, 'step' => 1, 'unit' => 'px', 'default' => 100],
            'max-width'        => ['label' => 'Max width',          'type' => 'size', 'min' => 0, 'max' => 2000, 'step' => 1, 'unit' => 'px', 'default' => 100],
            'height'           => ['label' => 'Height',             'type' => 'size', 'min' => 0, 'max' => 2000, 'step' => 1, 'unit' => 'px', 'default' => 100],
            'min-height'       => ['label' => 'Min height',         'type' => 'size', 'min' => 0, 'max' => 2000, 'step' => 1, 'unit' => 'px', 'default' => 100],
            'border-width'     => ['label' => 'Border width',       'type' => 'size', 'min' => 0, 'max' => 20, 'step' => 1, 'unit' => 'px', 'default' => 1],
            'border-style'     => ['label' => 'Border style',       'type' => 'keyword', 'options' => ['none' => 'None', 'solid' => 'Solid', 'dashed' => 'Dashed', 'dotted' => 'Dotted'], 'default' => 'solid'],
            'border-radius'    => ['label' => 'Corner radius',      'type' => 'size', 'min' => 0, 'max' => 999, 'step' => 1, 'unit' => 'px', 'default' => 0],
            'opacity'          => ['label' => 'Opacity',            'type' => 'size', 'min' => 0, 'max' => 1, 'step' => 0.05, 'unit' => '', 'default' => 1],
            'display'          => ['label' => 'Display',            'type' => 'keyword', 'options' => ['block' => 'Block', 'inline-block' => 'Inline block', 'inline' => 'Inline', 'flex' => 'Flex', 'inline-flex' => 'Inline flex', 'grid' => 'Grid', 'none' => 'None (hide)'], 'default' => 'block'],
            'position'         => ['label' => 'Position',           'type' => 'keyword', 'options' => ['static' => 'Static', 'relative' => 'Relative', 'absolute' => 'Absolute', 'fixed' => 'Fixed', 'sticky' => 'Sticky'], 'default' => 'static'],
            'flex-direction'   => ['label' => 'Flex direction',     'type' => 'keyword', 'options' => ['row' => 'Row', 'column' => 'Column', 'row-reverse' => 'Row reverse', 'column-reverse' => 'Column reverse'], 'default' => 'row'],
            'justify-content'  => ['label' => 'Justify content',    'type' => 'keyword', 'options' => ['flex-start' => 'Start', 'center' => 'Center', 'flex-end' => 'End', 'space-between' => 'Space between', 'space-around' => 'Space around'], 'default' => 'flex-start'],
            'align-items'      => ['label' => 'Align items',        'type' => 'keyword', 'options' => ['stretch' => 'Stretch', 'flex-start' => 'Start', 'center' => 'Center', 'flex-end' => 'End'], 'default' => 'stretch'],
            'z-index'          => ['label' => 'Stack order (z-index)', 'type' => 'size', 'min' => -10, 'max' => 999, 'step' => 1, 'unit' => '', 'default' => 0],
            'cursor'           => ['label' => 'Cursor',             'type' => 'keyword', 'options' => ['default' => 'Default', 'pointer' => 'Pointer', 'not-allowed' => 'Not allowed', 'text' => 'Text', 'grab' => 'Grab'], 'default' => 'default'],
        ];
        return apply_filters('bhy_style_css_properties', $registry);
    }

    /**
     * Pseudo-class states the rule editor offers; appended to the selector.
     *
     * @return array<string, string>
     */
    public static function css_state_options(): array {
        return ['' => 'Normal', ':hover' => 'Hover', ':focus' => 'Focus', ':focus-visible' => 'Focus (keyboard)', ':active' => 'Pressed', ':disabled' => 'Disabled'];
    }

    /**
     * Sanitizes one property value by its registered type. The single place
     * raw input from either editor becomes a trusted CSS value.
     *
     * @param array<string, mixed> $pdef
     * @param mixed $raw
     */
    public static function sanitize_css_property_value(array $pdef, $raw): string {
        $type = $pdef['type'] ?? 'text';
        if ($type === 'color') {
            return self::safe_color(sanitize_text_field($raw));
        }
        if ($type === 'keyword') {
            $options = $pdef['options'] ?? [];
            return array_key_exists((string) $raw, $options) ? (string) $raw : (string) ($pdef['default'] ?? array_key_first($options));
        }
        if ($type === 'font') {
            // Strip back to the bare name first so re-sanitizing a saved
            // '"name", fallback' value never doubles the fallback.
            $bare = preg_replace('/^"?([^",]+)"?.*$/', '$1', (string) $raw);
            $fallback = preg_replace('/[^a-z-]/', '', strtolower((string) ($pdef['fallback'] ?? 'sans-serif'))) ?: 'sans-serif';
            return self::css_safe_string(sanitize_text_field($bare)) . ', ' . $fallback;
        }
        // 'size'
        $num = self::safe_number($raw, $pdef['min'] ?? -99999, $pdef['max'] ?? 99999, $pdef['default'] ?? 0);
        return $num . ($pdef['unit'] ?? '');
    }

    /**
     * Normalizes rules from either editor (form-post 'props' rows or the
     * Customizer's 'declarations' map) into {selector, state, declarations},
     * allowlisted against css_property_registry().
     *
     * @param array<int, mixed> $incoming_rules
     * @return array<int, array<string, mixed>>
     */
    public static function sanitize_custom_css_rules($incoming_rules): array {
        if (!is_array($incoming_rules)) return [];
        $registry = self::css_property_registry();
        $states = self::css_state_options();
        $out = [];
        foreach ($incoming_rules as $rule) {
            if (!is_array($rule)) continue;
            $selector = self::sanitize_css_selector($rule['selector'] ?? '');
            if ($selector === '') continue;
            $state = (string) ($rule['state'] ?? '');
            if (!array_key_exists($state, $states)) $state = '';

            $declarations = [];
            // Form-post shape: a list of {property, value} rows.
            $incoming_props = is_array($rule['props'] ?? null) ? $rule['props'] : [];
            foreach ($incoming_props as $prop_row) {
                if (!is_array($prop_row)) continue;
                $property = str_replace('_', '-', sanitize_key($prop_row['property'] ?? ''));
                if (!isset($registry[$property])) continue; // strict allowlist, not a filter
                $declarations[$property] = self::sanitize_css_property_value($registry[$property], $prop_row['value'] ?? '');
            }
            // Customizer shape: already a {property: value} map.
            $incoming_declarations = is_array($rule['declarations'] ?? null) ? $rule['declarations'] : [];
            foreach ($incoming_declarations as $property => $raw_value) {
                $property = str_replace('_', '-', sanitize_key((string) $property));
                if (!isset($registry[$property])) continue;
                $declarations[$property] = self::sanitize_css_property_value($registry[$property], $raw_value);
            }

            if ($declarations) $out[] = ['selector' => $selector, 'state' => $state, 'declarations' => $declarations];
        }
        return $out;
    }

    /**
     * Single sanitizer for style settings from both the admin form ($_POST) and
     * the REST site-tokens endpoint. Add new fields here only.
     */
    /**
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    public static function save_from_input(array $incoming): array {
        $data = [];
        $data['brand_part1'] = sanitize_text_field($incoming['brand_part1'] ?? self::DEFAULTS['brand_part1']);
        $data['brand_part2'] = sanitize_text_field($incoming['brand_part2'] ?? self::DEFAULTS['brand_part2']);
        $data['brand_logo_id'] = isset($incoming['brand_logo_id']) ? (int) $incoming['brand_logo_id'] : 0;
        foreach (self::DEFAULTS as $key => $default) {
            if (strpos($key, 'color_') !== 0 && strpos($key, 'cat_color_') !== 0) continue;
            $val = isset($incoming[$key]) ? sanitize_text_field($incoming[$key]) : $default;
            $data[$key] = self::safe_color($val);
        }
        foreach (['font_display', 'font_body'] as $key) {
            $picked = sanitize_text_field($incoming[$key] ?? self::DEFAULTS[$key]);
            $data[$key] = (array_key_exists($picked, self::FONT_OPTIONS) || $picked === 'Custom' || $picked === self::INHERIT_FONT) ? $picked : self::DEFAULTS[$key];
            $data[$key . '_custom'] = sanitize_text_field($incoming[$key . '_custom'] ?? '');
        }
        $data['font_scale']  = self::safe_number($incoming['font_scale']  ?? null, 0.75, 1.6, 1);
        $data['space_scale'] = self::safe_number($incoming['space_scale'] ?? null, 0.6, 1.8, 1);
        $data['radius']      = self::safe_number($incoming['radius']      ?? null, 0, 32, 12);
        $data['radius_sm']   = self::safe_number($incoming['radius_sm']   ?? null, 0, 24, 8);
        $data['bar_height']  = self::safe_number($incoming['bar_height']  ?? null, 56, 140, 84);

        $custom_sliders = self::custom_sliders();
        if ($custom_sliders) {
            $data['custom'] = [];
            foreach ($custom_sliders as $key => $def) {
                $raw = $incoming['custom_' . $key] ?? ($def['default'] ?? 0);
                $data['custom'][$key] = self::safe_number($raw, $def['min'] ?? 0, $def['max'] ?? 999999, $def['default'] ?? 0);
            }
        }

        $custom_fonts = self::custom_fonts();
        if ($custom_fonts) {
            $data['custom_fonts'] = [];
            foreach ($custom_fonts as $key => $def) {
                $raw = sanitize_text_field($incoming['customfont_' . $key] ?? ($def['default'] ?? ''));
                $data['custom_fonts'][$key] = $raw !== '' ? $raw : (string) ($def['default'] ?? '');
            }
        }

        $components = self::component_tokens();
        if ($components) {
            $data['components'] = [];
            foreach ($components as $group => $def) {
                $data['components'][$group] = [];
                foreach (($def['tokens'] ?? []) as $key => $tdef) {
                    $field = 'comp_' . $group . '_' . $key;
                    $type = $tdef['type'] ?? 'size';
                    if ($type === 'color') {
                        $data['components'][$group][$key] = self::safe_color(sanitize_text_field($incoming[$field] ?? ($tdef['default'] ?? '#000000')));
                    } elseif ($type === 'font') {
                        $raw = sanitize_text_field($incoming[$field] ?? ($tdef['default'] ?? ''));
                        $data['components'][$group][$key] = $raw !== '' ? $raw : (string) ($tdef['default'] ?? '');
                    } elseif ($type === 'toggle') {
                        // Unchecked checkboxes are simply absent.
                        $data['components'][$group][$key] = !empty($incoming[$field]);
                    } else {
                        $data['components'][$group][$key] = self::safe_number($incoming[$field] ?? null, $tdef['min'] ?? 0, $tdef['max'] ?? 999999, $tdef['default'] ?? 0);
                    }
                }
            }
        }

        // Visual rule editor rows, stored structured so both editors (admin page
        // and Customizer) can re-render real controls.
        $data['custom_css_rules'] = self::sanitize_custom_css_rules(
            is_array($incoming['custom_css_rules'] ?? null) ? $incoming['custom_css_rules'] : []
        );

        // Raw CSS escape hatch; same trust model as core's Additional CSS.
        // Sanitized at emission time in inline_css().
        $data['custom_css'] = isset($incoming['custom_css']) ? substr((string) $incoming['custom_css'], 0, 20000) : '';

        return $data;
    }

    /** @param array<string, mixed> $s */
    public static function font_family($s, string $slot): string {
        $picked = $s['font_' . $slot];
        if ($picked === self::INHERIT_FONT) return 'inherit';
        if ($picked === 'Custom' || !array_key_exists($picked, self::FONT_OPTIONS)) {
            $custom = trim((string) $s['font_' . $slot . '_custom']);
            if ($custom !== '') return $custom;
            $fallback = self::DEFAULTS['font_' . $slot];
            return $fallback === self::INHERIT_FONT ? 'inherit' : $fallback;
        }
        return $picked;
    }

    /** @param mixed $val */
    public static function css_safe_string($val): string {
        $val = preg_replace('/[";{}]/', '', (string) $val);
        return '"' . trim($val) . '"';
    }

    /**
     * Toggle token 'on'/'off' values are unquoted CSS keywords, so they are
     * restricted to an allowlist.
     */
    public static function css_safe_string_keyword($val): string {
        $val = strtolower(trim((string) $val));
        $allowed = ['none', 'block', 'inline', 'inline-block', 'flex', 'inline-flex', 'grid', 'inline-grid', 'contents'];
        return in_array($val, $allowed, true) ? $val : 'none';
    }

    /**
     * Admin-entered CSS selector. Character allowlist makes breaking out of the
     * generated CSS block or <style> tag structurally impossible.
     */
    public static function sanitize_css_selector($val): string {
        $val = preg_replace('/[^a-zA-Z0-9 \.\#\-\_\>\+\~\:\[\]\=\"\047,\*]/', '', (string) $val);
        return trim((string) $val);
    }

    /**
     * @param mixed $val
     * @param mixed $min
     * @param mixed $max
     * @param mixed $default
     * @return float|mixed
     */
    public static function safe_number($val, $min, $max, $default) {
        if (!is_numeric($val)) return $default;
        return max($min, min($max, (float) $val));
    }

    /** @param mixed $val */
    public static function safe_color($val): string {
        $val = trim((string) $val);
        if (strcasecmp($val, 'transparent') === 0) return 'transparent';
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $val)) return $val;
        if (preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/', $val)) return $val;
        // okl(ch|ab) and one-level sRGB color-mix(). Character class stays tight
        // so this remains a value sanitizer, not an expression evaluator.
        if (preg_match('/^okl(ch|ab)\(\s*[0-9%.\s\/-]+\)$/i', $val)) return $val;
        if (preg_match('/^color-mix\(in srgb,\s*[#a-z0-9()%.,\s-]+\)$/i', $val)) return $val;
        return '#000000';
    }

    /** @param array<string, mixed>|null $s */
    public static function google_fonts_url($s = null): string {
        $s = $s ?: self::get();
        $params = [];
        foreach (['display', 'body'] as $slot) {
            $picked = $s['font_' . $slot];
            if (isset(self::FONT_OPTIONS[$picked])) {
                $params[self::FONT_OPTIONS[$picked]] = true;
            }
        }
        if (!$params) return '';
        return 'https://fonts.googleapis.com/css2?' . implode('&', array_map(fn($p) => 'family=' . $p, array_keys($params))) . '&display=swap';
    }

    // All curated fonts at once; gallery preview only, never seen by visitors.
    public static function preview_all_fonts_url(): string {
        $params = array_filter(self::FONT_OPTIONS);
        return 'https://fonts.googleapis.com/css2?' . implode('&', array_map(fn($p) => 'family=' . $p, array_values($params))) . '&display=swap';
    }

    /* =================================================================
     * Per-instance style overrides. scoped_inline_style() turns a placement's
     * config.style map into an inline style="" value. Two key shapes:
     *   - bare key ("color_accent"): emits a scoped --bh-* custom property.
     *   - "group.property" ("spacing.padding"): a preset step, "@token:*"
     *     ref, or "custom:*" value, emitted as a direct CSS declaration.
     * ================================================================= */

    const SPACE_SCALE_STEPS = [
        '0'  => '0',
        'xs' => 'calc(4px * var(--bh-space-scale, 1))',
        'sm' => 'calc(8px * var(--bh-space-scale, 1))',
        'md' => 'calc(16px * var(--bh-space-scale, 1))',
        'lg' => 'calc(24px * var(--bh-space-scale, 1))',
        'xl' => 'calc(40px * var(--bh-space-scale, 1))',
    ];

    // Self-contained rem steps (no site-wide token to anchor to). 'screen' is
    // 100vw or 100vh depending on the property; see resolve_size_step().
    const SIZE_STEPS = [
        'auto'   => 'auto',
        '0'      => '0',
        'xs'     => '8rem',
        'sm'     => '16rem',
        'md'     => '24rem',
        'lg'     => '32rem',
        'xl'     => '48rem',
        'full'   => '100%',
    ];

    const FONT_SIZE_STEPS = [
        'xs' => 'calc(12px * var(--bh-font-scale, 1))',
        'sm' => 'calc(14px * var(--bh-font-scale, 1))',
        'md' => 'calc(16px * var(--bh-font-scale, 1))',
        'lg' => 'calc(20px * var(--bh-font-scale, 1))',
        'xl' => 'calc(28px * var(--bh-font-scale, 1))',
    ];

    const FONT_WEIGHT_STEPS = ['400' => '400', '500' => '500', '600' => '600', '700' => '700'];

    const RADIUS_STEPS = [
        '0'    => '0',
        'sm'   => 'var(--bh-radius-sm, 8px)',
        'md'   => 'var(--bh-radius, 12px)',
        'lg'   => 'calc(var(--bh-radius, 12px) * 1.5)',
        'full' => '999px',
    ];

    const BORDER_WIDTH_STEPS = ['0' => '0', '1' => '1px', '2' => '2px', '4' => '4px'];
    const Z_INDEX_STEPS = ['0' => '0', '10' => '10', '20' => '20', '30' => '30', '40' => '40', '50' => '50'];

    const SHADOW_STEPS = [
        'none' => 'none',
        'sm'   => '0 1px 2px rgba(0,0,0,.08)',
        'md'   => '0 4px 10px rgba(0,0,0,.14)',
        'lg'   => '0 10px 26px rgba(0,0,0,.20)',
    ];

    const BG_SIZE_ENUM_PRESETS = ['auto' => 'auto', 'cover' => 'cover', 'contain' => 'contain'];

    const DISPLAY_ENUM         = ['block', 'flex', 'grid', 'inline-block', 'inline', 'none'];
    const POSITION_ENUM        = ['static', 'relative', 'absolute', 'sticky', 'fixed'];
    const FLEX_DIRECTION_ENUM  = ['row', 'row-reverse', 'column', 'column-reverse'];
    const FLEX_WRAP_ENUM       = ['nowrap', 'wrap', 'wrap-reverse'];
    const JUSTIFY_ENUM         = ['flex-start', 'center', 'flex-end', 'space-between', 'space-around', 'space-evenly'];
    const ALIGN_ENUM           = ['flex-start', 'center', 'flex-end', 'stretch', 'baseline'];
    const OVERFLOW_ENUM        = ['visible', 'hidden', 'scroll', 'auto'];
    const VISIBILITY_ENUM      = ['visible', 'hidden', 'collapse'];
    const BORDER_STYLE_ENUM    = ['none', 'solid', 'dashed', 'dotted', 'double'];
    const BG_REPEAT_ENUM       = ['repeat', 'no-repeat', 'repeat-x', 'repeat-y', 'space', 'round'];

    /**
     * "group.property" => [css property, resolver 'kind']. 'kind' selects the
     * branch of resolve_style_value():
     *   'space'            SPACE_SCALE_STEPS preset
     *   'size'             SIZE_STEPS preset (width/height-shaped)
     *   'scale'            a named scale table (see 'scale' sub-key)
     *   'enum'             a fixed allowlist (see 'enum' sub-key)
     *   'enum-scale'       a named table used as a fixed preset lookup
     *   'token-only'       colors: MUST be "@token:<field>", never a raw value
     *   'custom-only'      no preset table; only "custom:<value>"
     *   'custom-or-number' a bare unitless number (line-height) or "custom:<value>"
     *   'percent-0-100'    0-100, stored as a 0-1 opacity fraction
     */
    const PROPERTY_MAP = [
        // Sizing
        'sizing.width'      => ['css' => 'width',      'kind' => 'size'],
        'sizing.height'     => ['css' => 'height',      'kind' => 'size'],
        'sizing.min-width'  => ['css' => 'min-width',   'kind' => 'size'],
        'sizing.min-height' => ['css' => 'min-height',  'kind' => 'size'],
        'sizing.max-width'  => ['css' => 'max-width',   'kind' => 'size'],
        'sizing.max-height' => ['css' => 'max-height',  'kind' => 'size'],
        // Spacing
        'spacing.margin'         => ['css' => 'margin',         'kind' => 'space'],
        'spacing.margin-top'     => ['css' => 'margin-top',     'kind' => 'space'],
        'spacing.margin-right'   => ['css' => 'margin-right',   'kind' => 'space'],
        'spacing.margin-bottom'  => ['css' => 'margin-bottom',  'kind' => 'space'],
        'spacing.margin-left'    => ['css' => 'margin-left',    'kind' => 'space'],
        'spacing.padding'        => ['css' => 'padding',        'kind' => 'space'],
        'spacing.padding-top'    => ['css' => 'padding-top',    'kind' => 'space'],
        'spacing.padding-right'  => ['css' => 'padding-right',  'kind' => 'space'],
        'spacing.padding-bottom' => ['css' => 'padding-bottom', 'kind' => 'space'],
        'spacing.padding-left'   => ['css' => 'padding-left',   'kind' => 'space'],
        // Background
        'bg.color'    => ['css' => 'background-color',  'kind' => 'token-only'],
        'bg.image'    => ['css' => 'background-image',  'kind' => 'custom-only'],
        'bg.size'     => ['css' => 'background-size',   'kind' => 'enum-scale', 'scale' => 'BG_SIZE_ENUM_PRESETS'],
        'bg.position' => ['css' => 'background-position','kind' => 'custom-only'],
        'bg.repeat'   => ['css' => 'background-repeat', 'kind' => 'enum', 'enum' => 'BG_REPEAT_ENUM'],
        // Typography
        'type.font-size'      => ['css' => 'font-size',      'kind' => 'scale', 'scale' => 'FONT_SIZE_STEPS'],
        'type.font-weight'    => ['css' => 'font-weight',    'kind' => 'scale', 'scale' => 'FONT_WEIGHT_STEPS'],
        'type.line-height'    => ['css' => 'line-height',    'kind' => 'custom-or-number'],
        'type.letter-spacing' => ['css' => 'letter-spacing', 'kind' => 'custom-only'],
        'type.color'          => ['css' => 'color',          'kind' => 'token-only'],
        // Border
        'border.width'  => ['css' => 'border-width',  'kind' => 'scale', 'scale' => 'BORDER_WIDTH_STEPS'],
        'border.style'  => ['css' => 'border-style',  'kind' => 'enum',  'enum'  => 'BORDER_STYLE_ENUM'],
        'border.color'  => ['css' => 'border-color',  'kind' => 'token-only'],
        'border.radius' => ['css' => 'border-radius', 'kind' => 'scale', 'scale' => 'RADIUS_STEPS'],
        // Display / flex / grid
        'display.type'   => ['css' => 'display',         'kind' => 'enum', 'enum' => 'DISPLAY_ENUM'],
        'flex.direction'  => ['css' => 'flex-direction',  'kind' => 'enum', 'enum' => 'FLEX_DIRECTION_ENUM'],
        'flex.wrap'       => ['css' => 'flex-wrap',       'kind' => 'enum', 'enum' => 'FLEX_WRAP_ENUM'],
        'flex.justify'    => ['css' => 'justify-content', 'kind' => 'enum', 'enum' => 'JUSTIFY_ENUM'],
        'flex.align'      => ['css' => 'align-items',     'kind' => 'enum', 'enum' => 'ALIGN_ENUM'],
        'flex.gap'        => ['css' => 'gap',              'kind' => 'space'],
        'grid.cols'       => ['css' => 'grid-template-columns', 'kind' => 'custom-only'],
        'grid.gap'        => ['css' => 'gap', 'kind' => 'space'],
        // Position
        'position.type'     => ['css' => 'position', 'kind' => 'enum', 'enum' => 'POSITION_ENUM'],
        'position.top'      => ['css' => 'top',    'kind' => 'space'],
        'position.right'    => ['css' => 'right',  'kind' => 'space'],
        'position.bottom'   => ['css' => 'bottom', 'kind' => 'space'],
        'position.left'     => ['css' => 'left',   'kind' => 'space'],
        'position.z-index'  => ['css' => 'z-index', 'kind' => 'scale', 'scale' => 'Z_INDEX_STEPS'],
        // Effects / transforms
        'effects.opacity'   => ['css' => 'opacity',    'kind' => 'percent-0-100'],
        'effects.shadow'    => ['css' => 'box-shadow', 'kind' => 'scale', 'scale' => 'SHADOW_STEPS'],
        'effects.transform' => ['css' => 'transform',  'kind' => 'custom-only'],
        // Overflow / visibility
        'overflow.x'          => ['css' => 'overflow-x', 'kind' => 'enum', 'enum' => 'OVERFLOW_ENUM'],
        'overflow.y'          => ['css' => 'overflow-y', 'kind' => 'enum', 'enum' => 'OVERFLOW_ENUM'],
        'overflow.visibility' => ['css' => 'visibility', 'kind' => 'enum', 'enum' => 'VISIBILITY_ENUM'],
    ];

    /** Bare-token key => --bh-* custom property name, shared by entity_style_payload() and scoped_inline_style(). */
    /** @return array<string, string> */
    private static function style_var_map(): array {
        $map = [
            'color_bg' => '--bh-bg', 'color_surface' => '--bh-surface', 'color_surface_2' => '--bh-surface-2',
            'color_border' => '--bh-border', 'color_text' => '--bh-text', 'color_text_dim' => '--bh-text-dim',
            'color_accent' => '--bh-accent', 'color_accent_soft' => '--bh-accent-soft', 'color_overlay' => '--bh-overlay',
            'radius' => '--bh-radius', 'radius_sm' => '--bh-radius-sm',
            'space_scale' => '--bh-space-scale', 'font_scale' => '--bh-font-scale',
        ];
        for ($i = 1; $i <= 8; $i++) $map['cat_color_' . $i] = '--bh-cat-' . $i;
        return $map;
    }

    /** Sanitizes a bare-token value by field name using the existing validators. */
    /** @param mixed $value */
    private static function safe_style_token_value(string $field, $value): ?string {
        if (strpos($field, 'color') !== false) return self::safe_color($value);
        if ($field === 'radius')      return self::safe_number($value, 0, 32, 12) . 'px';
        if ($field === 'radius_sm')   return self::safe_number($value, 0, 24, 8) . 'px';
        if ($field === 'font_scale')  return self::safe_number($value, 0.75, 1.6, 1);
        if ($field === 'space_scale') return self::safe_number($value, 0.6, 1.8, 1);
        return self::safe_length($value); // any other bare key a plugin invents: run through the general CSS-value sanitizer, fail-safe to null (dropped) rather than guessing
    }

    // Constant name -> value; an unknown name fails safe to [].
    /** @return array<mixed> */
    private static function const_table(string $name): array {
        $fqcn = self::class . '::' . $name;
        return defined($fqcn) ? constant($fqcn) : [];
    }

    /** @return array<mixed> */
    private static function scale_table(string $name): array { return self::const_table($name); }
    /** @return array<mixed> */
    private static function enum_table(string $name): array { return self::const_table($name); }

    /** 'screen' is 100vh for height-shaped properties, 100vw otherwise; other steps are a SIZE_STEPS lookup. */
    private static function resolve_size_step(string $step, string $css_prop): ?string {
        if ($step === 'screen') return (strpos($css_prop, 'height') !== false) ? '100vh' : '100vw';
        return self::SIZE_STEPS[$step] ?? null;
    }

    /**
     * Sanitizer for every free-form CSS length/value. Blocks anything that could
     * break out of style="" or add a declaration (; quotes <> {}), plus
     * expression()/javascript:, then allowlists a conservative charset.
     * Fail-closed: returns null (dropped) otherwise.
     */
    /** @param mixed $val */
    public static function safe_length($val): ?string {
        $val = trim((string) $val);
        if ($val === '') return null;
        if (preg_match('/[;"\'<>{}]/', $val)) return null;
        if (stripos($val, 'expression') !== false || stripos($val, 'javascript:') !== false) return null;
        if (!preg_match('/^[a-zA-Z0-9%#.,\-\+\/\(\)\s\*]+$/', $val)) return null;
        return $val;
    }

    /** Enum-membership validator; one greppable choke point for every enum check. */
    /**
     * @param mixed $val
     * @param array<int, mixed> $allowed
     * @return mixed
     */
    public static function safe_enum($val, array $allowed) {
        return in_array($val, $allowed, true) ? $val : null;
    }

    /**
     * Resolves one "group.property" value against its PROPERTY_MAP entry into
     * a CSS value, or null if it cannot be resolved safely.
     */
    /**
     * @param mixed $raw
     * @param array<string, mixed> $map
     */
    private static function resolve_style_value($raw, array $map): ?string {
        $raw = (string) $raw;
        $kind = $map['kind'];
        $css  = $map['css'];

        if (strpos($raw, '@token:') === 0) {
            // Only color properties accept token refs; colors are never raw hex.
            if ($kind !== 'token-only') return null;
            $field = substr($raw, 7);
            $var_map = self::style_var_map();
            if (!isset($var_map[$field])) return null; // unknown/unsanctioned token name — refuse, never guess a var name
            return 'var(' . $var_map[$field] . ')';
        }

        if (strpos($raw, 'custom:') === 0) {
            $val = substr($raw, 7);
            if ($kind === 'token-only') return null; // colors: no raw-value escape hatch, by design
            if ($kind === 'percent-0-100') {
                return is_numeric($val) ? (string) (max(0, min(100, (float) $val)) / 100) : null;
            }
            return self::safe_length($val);
        }

        // A bare preset-step name (or, for some kinds, a raw number).
        switch ($kind) {
            case 'space':  return self::SPACE_SCALE_STEPS[$raw] ?? null;
            case 'size':   return self::resolve_size_step($raw, $css);
            case 'scale':  return self::scale_table($map['scale'])[$raw] ?? null;
            case 'enum-scale': return self::scale_table($map['scale'])[$raw] ?? null;
            case 'enum':   return self::safe_enum($raw, self::enum_table($map['enum']));
            case 'percent-0-100':
                return is_numeric($raw) ? (string) (max(0, min(100, (float) $raw)) / 100) : null;
            case 'custom-or-number':
                return is_numeric($raw) ? (string) (float) $raw : null;
            case 'token-only':
            case 'custom-only':
            default:
                return null; // these kinds ONLY accept the @token:/custom: forms handled above
        }
    }

    /**
     * Resolves a placement's config.style map into a style attribute VALUE
     * (declarations only; the caller esc_attr()s it). Each entry is resolved
     * independently; a bad entry is skipped, never fatal.
     */
    /** @param array<string, mixed> $style_map */
    public static function scoped_inline_style(array $style_map): string {
        $decls = '';
        $var_map = self::style_var_map();

        foreach ($style_map as $key => $value) {
            $key = (string) $key;
            if ($key === '' || !is_scalar($value)) continue;

            if (strpos($key, '.') === false) {
                // Bare token key: scoped --bh-* custom property.
                $css_var = $var_map[$key] ?? ('--bh-' . str_replace('_', '-', sanitize_key($key)));
                $safe = self::safe_style_token_value($key, $value);
                if ($safe !== null) $decls .= $css_var . ':' . $safe . ';';
                continue;
            }

            $map = self::PROPERTY_MAP[$key] ?? null;
            if (!$map) continue; // unrecognized group.property key — skip, never fatal
            $css_value = self::resolve_style_value((string) $value, $map);
            if ($css_value !== null) $decls .= $map['css'] . ':' . $css_value . ';';
        }

        return $decls;
    }

    /**
     * PROPERTY_MAP exported for the inspector JS, with every scale/enum table
     * resolved to {value: label} and grouped by the "group" half of the key.
     * 'colorTokens' is the vocabulary token-only properties may reference;
     * those never get a free-text custom option.
     */
    /** @return array<string, mixed> */
    public static function style_schema_for_js(): array {
        $group_labels = [
            'sizing'   => 'Sizing',
            'spacing'  => 'Spacing',
            'bg'       => 'Background',
            'type'     => 'Typography',
            'border'   => 'Border',
            'display'  => 'Display',
            'flex'     => 'Flex',
            'grid'     => 'Grid',
            'position' => 'Position',
            'effects'  => 'Effects',
            'overflow' => 'Overflow / Visibility',
        ];

        $groups = [];
        foreach (self::PROPERTY_MAP as $key => $map) {
            $dot = strpos($key, '.');
            $group_key = $dot !== false ? substr($key, 0, $dot) : 'other';
            $prop_key  = $dot !== false ? substr($key, $dot + 1) : $key;

            $options = null; // null => no fixed preset table (custom-only/custom-or-number/percent/token-only)
            switch ($map['kind']) {
                case 'space':       $options = self::SPACE_SCALE_STEPS; break;
                case 'size':        $options = self::SIZE_STEPS; break;
                case 'scale':
                case 'enum-scale':  $options = self::scale_table($map['scale']); break;
                case 'enum':        $options = array_combine(self::enum_table($map['enum']), self::enum_table($map['enum'])); break;
            }

            if (!isset($groups[$group_key])) {
                $groups[$group_key] = [
                    'label'      => $group_labels[$group_key] ?? ucfirst($group_key),
                    'properties' => [],
                ];
            }

            $groups[$group_key]['properties'][$prop_key] = [
                'key'          => $key, // full "group.property" — this is the config.style map key
                'css'          => $map['css'],
                'kind'         => $map['kind'],
                'options'      => $options, // {rawValue: label} or null
                'allowCustom'  => $map['kind'] !== 'token-only', // §2.6: colors never get a raw-value escape hatch
                'allowBlank'   => true,
            ];
        }

        // Values are real CSS custom property names (--bh-accent etc.) so the
        // client can build var(...) swatches without duplicating the naming rule.
        $color_tokens = [];
        foreach (self::style_var_map() as $field => $css_var) {
            if (strpos($field, 'color') !== false) $color_tokens[$field] = $css_var;
        }

        return [
            'groups'      => $groups,
            'colorTokens' => $color_tokens,
        ];
    }

    /**
     * Shared front-end empty state. Thin delegate kept for existing callers;
     * new code should call BHY_UI::empty_state_html() directly.
     */
    /** @param array<string, mixed> $args */
    public static function empty_state_html(array $args): string {
        return BHY_UI::empty_state_html($args);
    }
}
