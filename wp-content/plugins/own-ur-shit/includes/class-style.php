<?php
if (!defined('ABSPATH')) exit;

/**
 * The design-token system every ecosystem plugin's stylesheet reads
 * from — colors, typography, spacing, and a curated theme-preset
 * library. Originally built inside bh-contest (as BH_Settings); this is
 * that same, proven logic, generalized so any post in any plugin can
 * get a per-entity override (a contest, a track, a release — whatever
 * the consuming plugin's own concept of "one themeable thing" is),
 * not just a contest specifically.
 *
 * Stored as a single option (one row, one array) rather than one option
 * per field — this is small, always read together, and never queried
 * piecemeal, so one row is both simpler and cheaper than a dozen.
 */
class BHY_Style {
    const OPTION = 'bhy_style_settings';

    const DEFAULTS = [
        // Generic, brand-neutral out of the box — this is a two-tone
        // wordmark any site customizes via Settings & Style (or its
        // own per-contest/per-track override); it was never meant to be
        // one specific artist's name hardcoded as the fallback.
        'brand_part1'  => 'Your',
        'brand_part2'  => 'Brand',
        'brand_logo_id' => '',
        'color_bg'          => '#170807',
        'color_surface'     => '#220C0A',
        'color_surface_2'   => '#2C120E',
        'color_border'      => '#3D1B14',
        'color_text'        => '#EDDFCB',
        'color_text_dim'    => '#B99584',
        'color_accent'      => '#C1503A',
        'color_accent_soft' => '#E0A184',
        'color_overlay'     => '#0D0504D1',
        'cat_color_1' => '#C1503A', 'cat_color_2' => '#D9A441', 'cat_color_3' => '#B8785A', 'cat_color_4' => '#8C3B2E',
        'cat_color_5' => '#C98B5E', 'cat_color_6' => '#A66A4D', 'cat_color_7' => '#D96C4D', 'cat_color_8' => '#7A4A38',
        'font_display'        => 'Space Grotesk',
        'font_display_custom' => '',
        'font_body'           => 'Inter',
        'font_body_custom'    => '',
        'font_scale'  => '1',
        'space_scale' => '1',
        'radius'      => '12',
        'radius_sm'   => '8',
        'bar_height'  => '84',
    ];

    const FONT_OPTIONS = [
        'Space Grotesk'    => 'Space+Grotesk:wght@500;600;700',
        'Inter'            => 'Inter:wght@400;500;600;700',
        'Poppins'          => 'Poppins:wght@400;500;600;700',
        'Montserrat'       => 'Montserrat:wght@400;500;600;700',
        'Playfair Display' => 'Playfair+Display:wght@500;600;700',
        'Bebas Neue'       => 'Bebas+Neue',
        'Oswald'           => 'Oswald:wght@400;500;600;700',
        'DM Sans'          => 'DM+Sans:wght@400;500;600;700',
        'Sora'             => 'Sora:wght@500;600;700',
        'Work Sans'        => 'Work+Sans:wght@400;500;600;700',
        'Roboto Mono'      => 'Roboto+Mono:wght@400;500;600;700',
        'System UI'        => null,
    ];

    const THEME_GROUPS = [
        'Signature' => [
            'The Door — Night' => [
                'color_bg' => '#170807', 'color_surface' => '#220C0A', 'color_surface_2' => '#2C120E',
                'color_border' => '#3D1B14', 'color_text' => '#EDDFCB', 'color_text_dim' => '#B99584',
                'color_accent' => '#C1503A', 'color_accent_soft' => '#E0A184', 'color_overlay' => '#0D0504D1',
                'cat_color_1' => '#C1503A', 'cat_color_2' => '#D9A441', 'cat_color_3' => '#B8785A', 'cat_color_4' => '#8C3B2E',
                'cat_color_5' => '#C98B5E', 'cat_color_6' => '#A66A4D', 'cat_color_7' => '#D96C4D', 'cat_color_8' => '#7A4A38',
            ],
            'The Door — Day' => [
                'color_bg' => '#F4E9DC', 'color_surface' => '#EADCC8', 'color_surface_2' => '#E0CFB5',
                'color_border' => '#C9B096', 'color_text' => '#2B120C', 'color_text_dim' => '#6B4A3A',
                'color_accent' => '#C1503A', 'color_accent_soft' => '#E8B49A', 'color_overlay' => '#170807B3',
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
    ];

    // Fields a single entity (a contest, a track, whatever the
    // consuming plugin considers "one themeable thing") is allowed to
    // override — the whole color palette plus brand identity.
    // Typography, spacing, and component sizing stay global-only: those
    // are "how the app feels" decisions that should stay consistent
    // everywhere, whereas a full color re-skin per entity (a sponsor
    // look, a seasonal palette) is a completely reasonable thing to want.
    const OVERRIDABLE_FIELDS = [
        'brand_part1', 'brand_part2', 'brand_logo_id',
        'color_bg', 'color_surface', 'color_surface_2', 'color_border', 'color_text', 'color_text_dim',
        'color_accent', 'color_accent_soft', 'color_overlay',
        'cat_color_1', 'cat_color_2', 'cat_color_3', 'cat_color_4', 'cat_color_5', 'cat_color_6', 'cat_color_7', 'cat_color_8',
    ];

    // Pass an entity ID (any post, from any plugin) to layer that
    // entity's own overrides (if it has one enabled — see
    // entity_overrides()) on top of the global settings. Omit it for
    // the plain global settings, e.g. on the settings page itself.
    public static function get($entity_id = null) {
        $saved = get_option(self::OPTION, []);
        $settings = array_merge(self::DEFAULTS, is_array($saved) ? $saved : []);

        if ($entity_id) {
            $overrides = self::entity_overrides($entity_id);
            if ($overrides) $settings = array_merge($settings, $overrides);
        }

        return $settings;
    }

    // Generic meta keys (bhy_-prefixed, not tied to "contest" or any
    // other specific consuming plugin's vocabulary) — any plugin can set
    // these on any post it owns to give that post its own style override.
    // Only fields actually present AND in OVERRIDABLE_FIELDS make it
    // through, so a stray/old key in stored JSON can't leak into a field
    // this version doesn't intend to let entities touch.
    public static function entity_overrides($entity_id) {
        if (!get_post_meta($entity_id, '_bhy_style_override', true)) return [];
        $raw = get_post_meta($entity_id, '_bhy_style_json', true);
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data)) return [];
        return array_intersect_key($data, array_flip(self::OVERRIDABLE_FIELDS));
    }

    public static function logo_url($settings) {
        $id = (int) ($settings['brand_logo_id'] ?? 0);
        if (!$id) return '';
        $url = wp_get_attachment_image_url($id, 'medium');
        return $url ?: '';
    }

    // Small JSON-ready payload a consuming plugin's front end can apply
    // client-side, scoped to just one rendered instance. Returns null
    // when the entity has no override enabled, so the caller can skip
    // the data attribute entirely — the common case of "no per-entity
    // override" adds zero extra markup or client-side work.
    public static function entity_style_payload($entity_id) {
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

        return [
            'vars'  => $vars,
            'brand' => ['part1' => $merged['brand_part1'], 'part2' => $merged['brand_part2'], 'logoUrl' => self::logo_url($merged)],
        ];
    }

    // Inline <style> block overriding the CSS custom properties every
    // consuming plugin's own stylesheet already reads from — enqueued
    // after that stylesheet so it wins the cascade. The stylesheets
    // themselves never need to change per site; only this changes.
    public static function inline_css($entity_id = null) {
        $s = self::get($entity_id);
        $vars = [
            '--bh-bg' => $s['color_bg'], '--bh-surface' => $s['color_surface'], '--bh-surface-2' => $s['color_surface_2'],
            '--bh-border' => $s['color_border'], '--bh-text' => $s['color_text'], '--bh-text-dim' => $s['color_text_dim'],
            '--bh-accent' => $s['color_accent'], '--bh-accent-soft' => $s['color_accent_soft'], '--bh-overlay' => $s['color_overlay'],
        ];
        for ($i = 1; $i <= 8; $i++) $vars['--bh-cat-' . $i] = $s['cat_color_' . $i];
        $decls = '';
        foreach ($vars as $name => $val) $decls .= $name . ':' . self::safe_color($val) . ';';

        $decls .= '--bh-font-display:' . self::css_safe_string(self::font_family($s, 'display')) . ', sans-serif;';
        $decls .= '--bh-font-body:' . self::css_safe_string(self::font_family($s, 'body')) . ', sans-serif;';
        $decls .= '--bh-font-scale:' . self::safe_number($s['font_scale'], 0.75, 1.6, 1) . ';';
        $decls .= '--bh-space-scale:' . self::safe_number($s['space_scale'], 0.6, 1.8, 1) . ';';
        $decls .= '--bh-radius:' . self::safe_number($s['radius'], 0, 32, 12) . 'px;';
        $decls .= '--bh-radius-sm:' . self::safe_number($s['radius_sm'], 0, 24, 8) . 'px;';
        $decls .= '--bh-bar-height:' . self::safe_number($s['bar_height'], 56, 140, 84) . 'px;';

        return ':root{' . $decls . '}';
    }

    public static function font_family($s, $slot) {
        $picked = $s['font_' . $slot];
        if ($picked === 'Custom' || !array_key_exists($picked, self::FONT_OPTIONS)) {
            $custom = trim((string) $s['font_' . $slot . '_custom']);
            return $custom !== '' ? $custom : self::DEFAULTS['font_' . $slot];
        }
        return $picked;
    }

    public static function css_safe_string($val) {
        $val = preg_replace('/[";{}]/', '', (string) $val);
        return '"' . trim($val) . '"';
    }

    public static function safe_number($val, $min, $max, $default) {
        if (!is_numeric($val)) return $default;
        return max($min, min($max, (float) $val));
    }

    public static function safe_color($val) {
        $val = trim((string) $val);
        if (strcasecmp($val, 'transparent') === 0) return 'transparent';
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $val)) return $val;
        if (preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/', $val)) return $val;
        return '#000000';
    }

    public static function google_fonts_url($s = null) {
        $s = $s ?: self::get();
        $params = [];
        foreach (['display', 'body'] as $slot) {
            $picked = $s['font_' . $slot];
            if (isset(self::FONT_OPTIONS[$picked]) && self::FONT_OPTIONS[$picked]) {
                $params[self::FONT_OPTIONS[$picked]] = true;
            }
        }
        if (!$params) return '';
        return 'https://fonts.googleapis.com/css2?' . implode('&', array_map(fn($p) => 'family=' . $p, array_keys($params))) . '&display=swap';
    }

    // Every curated font at once — only used inside the gallery preview,
    // which is never seen by a site visitor, so the extra weight is a
    // non-issue and buys instant switching in the font dropdown.
    public static function preview_all_fonts_url() {
        $params = array_filter(self::FONT_OPTIONS);
        return 'https://fonts.googleapis.com/css2?' . implode('&', array_map(fn($p) => 'family=' . $p, array_values($params))) . '&display=swap';
    }
}
