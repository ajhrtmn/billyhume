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
    /**
     * Site-wide token availability — AJ's own direct follow-up after
     * BHY_BlockStyle's editor-canvas live preview (3.4.81) turned out to
     * only ever be in HONEST parity with a real, separate, pre-existing
     * gap: inline_css()'s `:root{--bh-*:...}` block was only ever echoed
     * on specific pages that already knew to ask for it (class-public-
     * profile.php's public profile pages, class-portal.php's portal
     * pages, the gallery preview) — never globally. A `bg.color` token
     * ref set through the Advanced Styles panel on an ORDINARY page/post
     * produced a real `background-color:var(--bh-accent)` declaration in
     * both the editor canvas and the rendered front end, but `--bh-
     * accent` itself resolved to nothing outside those special pages —
     * confirmed empty via getComputedStyle() on this actual install, not
     * assumed. Two hooks fix both halves of that gap: `wp_head` (every
     * real front-end page, `print_global_css()`) and the block editor
     * iframe's own styles list via `block_editor_settings_all`
     * (`add_editor_iframe_styles()`) — the iframe has its own separate
     * document and does NOT inherit admin `wp_head`/`admin_head`, so
     * `enqueue_block_editor_assets` alone can't reach it; `styles` is
     * core's own supported extension point for raw CSS in that iframe
     * (used internally for `add_editor_style()` under the hood). Neither
     * hook touches the existing per-page echoes above — those still
     * fire later in the document and still correctly win the cascade for
     * any entity-specific override, since CSS's own last-declaration-
     * wins rule already handles that; this only adds the SITE DEFAULT
     * that was previously missing everywhere else.
     */
    public static function init() {
        add_action('wp_head', [self::class, 'print_global_css']);
        add_filter('block_editor_settings_all', [self::class, 'add_editor_iframe_styles']);
    }

    public static function print_global_css() {
        echo '<style id="bhy-global-vars">' . self::inline_css() . '</style>';
    }

    public static function add_editor_iframe_styles($settings) {
        $settings['styles'][] = ['css' => self::inline_css()];
        return $settings;
    }

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
        // If this entity overrides either color that --bh-accent-muted-bg
        // derives from, recompute it here too — otherwise a scoped accent/
        // surface override would leave the SITE-WIDE muted-bg (computed
        // from the un-overridden colors) applied underneath it, drifting
        // out of sync with whichever accent this entity actually uses.
        if (isset($overrides['color_accent']) || isset($overrides['color_surface'])) {
            $vars['--bh-accent-muted-bg'] = 'color-mix(in srgb, ' . self::safe_color($merged['color_accent']) . ' 18%, ' . self::safe_color($merged['color_surface']) . ')';
        }
        if (isset($overrides['color_accent'])) {
            $vars['--bh-accent-hover'] = 'color-mix(in srgb, ' . self::safe_color($merged['color_accent']) . ' 85%, black)';
            $vars['--bh-accent-pressed'] = 'color-mix(in srgb, ' . self::safe_color($merged['color_accent']) . ' 70%, black)';
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

        // Derived, not a hand-picked field like color_accent_soft above —
        // that field has zero enforced relationship to color_accent, so
        // an admin picking colors independently can (and did, confirmed
        // live in bh-courses/bh-contest before this existed) land on a
        // near-illegible ~1.4:1 contrast pairing with --bh-text. This is
        // a small, fixed proportion of the CHOSEN accent mixed into the
        // CHOSEN surface — always dominated by surface (so always dark
        // enough for light text on top), and it recomputes automatically
        // for whatever accent/surface pair Settings & Style ends up with,
        // rather than needing a second correctly-contrasting color to be
        // hand-picked every time. Any consuming stylesheet should reach
        // for this — never --bh-accent-soft — behind body text; soft
        // stays reserved for its original one-off job (lightening an
        // already-accent-colored element on hover).
        $decls .= '--bh-accent-muted-bg:color-mix(in srgb, ' . self::safe_color($s['color_accent']) . ' 18%, ' . self::safe_color($s['color_surface']) . ');';

        // Same derivation logic, different job: a button using
        // --bh-accent as its own background with light/white text on
        // top needs its :hover state to get DARKER (more contrast), not
        // lighter — --bh-accent-soft lightens, which is exactly backwards
        // for that combination (confirmed live: a real bug this session,
        // white text over a lightened accent-soft hover). Buttons that
        // pair --bh-accent with DARK text don't need this — lightening
        // already improves their contrast on hover, so they're unaffected
        // by leaving them on --bh-accent-soft.
        $decls .= '--bh-accent-hover:color-mix(in srgb, ' . self::safe_color($s['color_accent']) . ' 85%, black);';
        $decls .= '--bh-accent-pressed:color-mix(in srgb, ' . self::safe_color($s['color_accent']) . ' 70%, black);';

        $decls .= '--bh-font-display:' . self::css_safe_string(self::font_family($s, 'display')) . ', sans-serif;';
        $decls .= '--bh-font-body:' . self::css_safe_string(self::font_family($s, 'body')) . ', sans-serif;';
        $decls .= '--bh-font-scale:' . self::safe_number($s['font_scale'], 0.75, 1.6, 1) . ';';
        $decls .= '--bh-space-scale:' . self::safe_number($s['space_scale'], 0.6, 1.8, 1) . ';';
        $decls .= '--bh-radius:' . self::safe_number($s['radius'], 0, 32, 12) . 'px;';
        $decls .= '--bh-radius-sm:' . self::safe_number($s['radius_sm'], 0, 24, 8) . 'px;';
        $decls .= '--bh-bar-height:' . self::safe_number($s['bar_height'], 56, 140, 84) . 'px;';

        // Plugin-registered custom sliders (custom_sliders() below) — same
        // treatment as every built-in token: read from the same option,
        // sanitized with the same safe_number(), emitted as a real
        // --bh-custom-<key> var a consuming plugin's own stylesheet reads
        // directly, e.g. var(--bh-custom-queue-row-height). No first-
        // class/second-class distinction from a plugin's own CSS's point
        // of view.
        foreach (self::custom_sliders() as $key => $def) {
            $safe_key = sanitize_key($key);
            $val = $s['custom'][$key] ?? ($def['default'] ?? 0);
            $decls .= '--bh-custom-' . $safe_key . ':' . self::safe_number($val, $def['min'] ?? 0, $def['max'] ?? 999999, $def['default'] ?? 0) . ($def['unit'] ?? '') . ';';
        }

        return ':root{' . $decls . '}';
    }

    /**
     * "The ability for the dev to add custom sliders like the 'now
     * playing bar height' for plugin specific adjustments" (AJ). A
     * consuming plugin registers one from its own bootstrap:
     *
     *     add_filter('bhy_style_custom_sliders', function ($sliders) {
     *         $sliders['queue_row_height'] = [
     *             'label' => 'Queue row height', 'min' => 32, 'max' => 96,
     *             'step' => 2, 'unit' => 'px', 'default' => 48,
     *         ];
     *         return $sliders;
     *     });
     *
     * and it shows up in the Design Suite's "Plugin adjustments" group
     * (BHY_Gallery::render_controls()) rendered with the exact same
     * BHY_UI::slider_row() every built-in scale slider uses — same
     * markup, same live-preview wiring, same save path, same
     * --bh-custom-<key> var naming convention as the built-ins. The
     * value round-trips through the SAME bhy_style_settings option,
     * namespaced under 'custom' => [key => value] so it can never
     * collide with a real DEFAULTS key.
     */
    public static function custom_sliders() {
        return apply_filters('bhy_style_custom_sliders', []);
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

    /* =================================================================
     * §2.3/§2.6 (DESIGN-SUITE-UNIFICATION-PLAN.md) — per-instance style
     * overrides. scoped_inline_style() is the ONE new formatter this
     * phase adds: it resolves a placement's config.style map into an
     * inline `style="..."` attribute value for that placement's own
     * wrapper element (BH_Element::render_placement() is the call site).
     *
     * Two key shapes coexist in one map, per §2.6's convention:
     *   - a BARE key (e.g. "color_accent") is §2.3's original mechanic,
     *     UNCHANGED: emits a scoped --bh-* custom property, so the
     *     type's own stylesheet (which already reads var(--bh-*))
     *     inherits every token it does NOT override from :root.
     *   - a NAMESPACED "group.property" key (e.g. "spacing.padding",
     *     "bg.color") is new this phase: resolves a preset-scale step,
     *     an "@token:*" reference to an existing BHY_Style color/scale
     *     token, or a "custom:*" escape hatch, straight to a direct CSS
     *     declaration on the same wrapper.
     *
     * Every preset step below is anchored to this install's REAL
     * existing scale values (DEFAULTS' radius=12/radius_sm=8, the
     * font_scale 0.75-1.6 / space_scale 0.6-1.8 multipliers already
     * read via --bh-font-scale/--bh-space-scale elsewhere in this
     * class) — no new numbers were invented for this pass.
     *
     * NOT runtime-verified: no live PHP/browser execution is available
     * in this environment; reasoned through against this class's own
     * existing safe_color()/safe_number()/inline_css() shapes and
     * brace/logic-checked only. Smoke-test scoped_inline_style() against
     * a real placement carrying a mixed bare+namespaced style map (and
     * against a deliberately malformed one, to confirm it degrades to
     * "skip that one declaration" rather than emitting anything unsafe)
     * before trusting this in production.
     * ================================================================= */

    const SPACE_SCALE_STEPS = [
        '0'  => '0',
        'xs' => 'calc(4px * var(--bh-space-scale, 1))',
        'sm' => 'calc(8px * var(--bh-space-scale, 1))',
        'md' => 'calc(16px * var(--bh-space-scale, 1))',
        'lg' => 'calc(24px * var(--bh-space-scale, 1))',
        'xl' => 'calc(40px * var(--bh-space-scale, 1))',
    ];

    // Sizing has no pre-existing site-wide token to anchor to (unlike
    // spacing/typography/radius) — these are plain, self-contained rem
    // steps, Tailwind-scale-inspired per §2.6's own framing. 'screen'
    // resolves to 100vw or 100vh depending on which CSS property it's
    // applied to — see resolve_size_step().
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
     * "group.property" => [css property, resolver 'kind']. This IS
     * §2.6's property-groups table, translated into code — every group
     * in the doc (sizing, spacing, background, typography, border,
     * display/flex/grid, position, effects/transforms, overflow/
     * visibility) ships here in one pass, per AJ's explicit "I don't
     * want suggestions deferred" instruction — nothing held back to a
     * later phase.
     *
     * 'kind' selects which branch of resolve_style_value() runs:
     *   'space'            — SPACE_SCALE_STEPS preset, or 'custom:'/'@token:' n/a (space has no token form)
     *   'size'              — SIZE_STEPS preset (width/height-shaped)
     *   'scale'             — a named scale table (see 'scale' sub-key)
     *   'enum'              — a fixed allowlist (see 'enum' sub-key)
     *   'enum-scale'        — a named table used as a fixed set of presets (no scale relationship, just a lookup)
     *   'token-only'        — colors: MUST be "@token:<BHY_Style field>", never a raw/custom value (§2.6: "colors are always token refs, never raw hex")
     *   'custom-only'       — no preset table exists for this property; only "custom:<value>" is accepted
     *   'custom-or-number'  — a bare unitless number (line-height) or "custom:<value>"
     *   'percent-0-100'     — a 0-100 integer/float, stored as a 0-1 CSS opacity fraction
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

    /** Bare-token key => --bh-* custom property name, the exact map entity_style_payload() already used for colors, extended here with the non-color overridable tokens (radius/radius_sm/space_scale/font_scale) so scoped_inline_style() can reuse one lookup for both. */
    private static function style_var_map() {
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

    /** Sanitizes a bare-token value by field name, reusing the EXISTING safe_color()/safe_number() validators — never a new sanitizer for the §2.3 mechanic, which is unchanged by this pass. */
    private static function safe_style_token_value($field, $value) {
        if (strpos($field, 'color') !== false) return self::safe_color($value);
        if ($field === 'radius')      return self::safe_number($value, 0, 32, 12) . 'px';
        if ($field === 'radius_sm')   return self::safe_number($value, 0, 24, 8) . 'px';
        if ($field === 'font_scale')  return self::safe_number($value, 0.75, 1.6, 1);
        if ($field === 'space_scale') return self::safe_number($value, 0.6, 1.8, 1);
        return self::safe_length($value); // any other bare key a plugin invents: run through the general CSS-value sanitizer, fail-safe to null (dropped) rather than guessing
    }

    private static function scale_table($name) {
        switch ($name) {
            case 'SPACE_SCALE_STEPS':    return self::SPACE_SCALE_STEPS;
            case 'SIZE_STEPS':           return self::SIZE_STEPS;
            case 'FONT_SIZE_STEPS':      return self::FONT_SIZE_STEPS;
            case 'FONT_WEIGHT_STEPS':    return self::FONT_WEIGHT_STEPS;
            case 'RADIUS_STEPS':         return self::RADIUS_STEPS;
            case 'BORDER_WIDTH_STEPS':   return self::BORDER_WIDTH_STEPS;
            case 'Z_INDEX_STEPS':        return self::Z_INDEX_STEPS;
            case 'SHADOW_STEPS':         return self::SHADOW_STEPS;
            case 'BG_SIZE_ENUM_PRESETS': return self::BG_SIZE_ENUM_PRESETS;
            default: return [];
        }
    }

    private static function enum_table($name) {
        switch ($name) {
            case 'DISPLAY_ENUM':        return self::DISPLAY_ENUM;
            case 'POSITION_ENUM':       return self::POSITION_ENUM;
            case 'FLEX_DIRECTION_ENUM': return self::FLEX_DIRECTION_ENUM;
            case 'FLEX_WRAP_ENUM':      return self::FLEX_WRAP_ENUM;
            case 'JUSTIFY_ENUM':        return self::JUSTIFY_ENUM;
            case 'ALIGN_ENUM':          return self::ALIGN_ENUM;
            case 'OVERFLOW_ENUM':       return self::OVERFLOW_ENUM;
            case 'VISIBILITY_ENUM':     return self::VISIBILITY_ENUM;
            case 'BORDER_STYLE_ENUM':   return self::BORDER_STYLE_ENUM;
            case 'BG_REPEAT_ENUM':      return self::BG_REPEAT_ENUM;
            default: return [];
        }
    }

    /** 'screen' means "the full viewport in whichever axis this property moves in" — height-shaped properties get 100vh, everything else gets 100vw. Every other step is a flat SIZE_STEPS lookup. */
    private static function resolve_size_step($step, $css_prop) {
        if ($step === 'screen') return (strpos($css_prop, 'height') !== false) ? '100vh' : '100vw';
        return self::SIZE_STEPS[$step] ?? null;
    }

    /**
     * General-purpose CSS *value* sanitizer for every 'custom:'/free-
     * form length this pass introduces (safe_length(), §2.6's "new
     * validators as needed alongside safe_color/safe_number"). Hard-
     * blocks anything that could break out of a `style="..."` attribute
     * or smuggle a second declaration (semicolons/quotes/angle
     * brackets/braces), plus the legacy expression()/javascript: CSS
     * injection vectors, THEN allowlists a conservative charset for
     * everything else (numbers, units, %, #hex, calc()/var() nesting,
     * commas, spaces, and the arithmetic symbols plus, minus, times,
     * divide). Returns null (dropped, never emitted)
     * on anything that doesn't clear both checks — fail-closed, matching
     * safe_color()'s own "unknown input -> safe fallback, never pass
     * through" posture.
     */
    public static function safe_length($val) {
        $val = trim((string) $val);
        if ($val === '') return null;
        if (preg_match('/[;"\'<>{}]/', $val)) return null;
        if (stripos($val, 'expression') !== false || stripos($val, 'javascript:') !== false) return null;
        if (!preg_match('/^[a-zA-Z0-9%#.,\-\+\/\(\)\s\*]+$/', $val)) return null;
        return $val;
    }

    /** Enum-membership validator — the other §2.6-promised new validator, alongside safe_length(). A thin, explicit wrapper (rather than inlining in_array() everywhere) so every enum check in this file goes through one named, greppable choke point. */
    public static function safe_enum($val, array $allowed) {
        return in_array($val, $allowed, true) ? $val : null;
    }

    /**
     * Resolves ONE "group.property" style value (a preset step, an
     * "@token:*" ref, or a "custom:*" escape hatch) against $map (one
     * PROPERTY_MAP entry) into a concrete CSS value, or null if it
     * can't be resolved safely — the caller (scoped_inline_style())
     * simply omits that one declaration on null, never throwing.
     */
    private static function resolve_style_value($raw, array $map) {
        $raw = (string) $raw;
        $kind = $map['kind'];
        $css  = $map['css'];

        if (strpos($raw, '@token:') === 0) {
            // Only color-shaped properties ('token-only') accept a
            // token reference — §2.6: "colors are always token refs,
            // never raw hex", and no other group has a token vocabulary
            // to reference in the first place.
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

        // A bare preset-step name (or, for a couple of kinds, a raw
        // number) — never a token/custom prefix at all.
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
     * Resolves a placement's config.style map (§2.3/§2.6) into a
     * `style="..."` attribute VALUE (the declarations only, no
     * surrounding style="" wrapper or quoting — the caller,
     * BH_Element::render_placement(), esc_attr()'s the whole thing when
     * it assembles the final HTML string). See this section's docblock
     * above for the bare-key vs. group.property key contract.
     *
     * Every declaration is resolved and sanitized independently; one
     * bad/unknown entry is silently skipped (never emitted, never
     * fatal) rather than aborting the whole map — same fail-closed-per-
     * entry posture as render_placement()'s attr coercion loop.
     */
    public static function scoped_inline_style(array $style_map) {
        $decls = '';
        $var_map = self::style_var_map();

        foreach ($style_map as $key => $value) {
            $key = (string) $key;
            if ($key === '' || !is_scalar($value)) continue;

            if (strpos($key, '.') === false) {
                // Bare token key — §2.3's original, UNCHANGED mechanic.
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
     * 3.4.27 — JSON-shaped export of PROPERTY_MAP (§2.6), with every
     * 'scale'/'enum'/'enum-scale' table already resolved to a plain
     * {value: label} map, so the element-builder inspector JS can build
     * every property-group's preset picker WITHOUT hardcoding a single
     * one of §2.6's property groups or their preset tables client-side —
     * this is the one export point the GUI reads to stay dynamic even as
     * PROPERTY_MAP grows. Grouped by the "group" half of each
     * "group.property" key (sizing/spacing/background/typography/border/
     * display/flex/grid/position/effects/overflow) purely for inspector
     * layout — the group boundary has no server-side meaning beyond that.
     *
     * 'colorTokens' is the token vocabulary 'token-only' kinds are
     * allowed to reference (style_var_map()'s keys) — token-only
     * properties NEVER get a free-text custom option in the inspector,
     * mirroring resolve_style_value()'s "colors are always token refs,
     * never raw hex" rule exactly.
     *
     * NOT runtime-verified: no live PHP execution available this pass;
     * reasoned through directly against PROPERTY_MAP/resolve_style_value()
     * above, which ARE this file's own already-shipped, brace-checked
     * logic — this method only reads/reshapes them, it adds no new
     * resolution behavior.
     */
    public static function style_schema_for_js() {
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

        // 3.4.49 follow-up — value changed from the field's own name
        // (`$color_tokens[$field] = $field`) to its REAL CSS custom
        // property name (`--bh-accent` etc.), direct response to AJ's
        // own ask: "would be cool if the color and font selectors could
        // preview what they look like... with like swatches in the
        // dropdown." Nothing previously read this map's VALUES (every
        // client-side consumer only ever iterated its KEYS), so this is
        // a safe, non-breaking payload shape change — the client can now
        // build `var(--bh-accent)` directly instead of hardcoding a
        // second copy of style_var_map()'s naming convention in JS.
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
     * The one shared "nothing to show" component for front-end list/
     * catalog surfaces — UX-AUDIT-2026-07.md's own top recommendation:
     * the exact same bare "No courses found yet." / "No tracks match."
     * pattern showed up independently in bh-courses' catalog and
     * bh-streaming's library, with no explanation and no next step,
     * while WooCommerce's own default empty state (one plugin away,
     * "try clearing any filters or head to our store's home") already
     * solves this correctly. One reusable piece here, retrofit onto
     * both existing call sites, rather than fixing the same gap twice
     * (and however many more times it would otherwise recur later).
     *
     * $args:
     *   'reason'      — 'zero' (nothing exists yet at all) or
     *                    'filtered' (a search/filter matched nothing) —
     *                    changes both the default message and whether a
     *                    "clear filters" affordance makes sense at all.
     *   'title'       — required, short. Own the specific cause instead
     *                    of a generic "No items found" ("No courses
     *                    published yet" beats "No results").
     *   'description' — optional, one more sentence of context.
     *   'cta_label'/'cta_url' — optional single next-step link/button.
     *   'clear_url'   — optional; only rendered when reason='filtered',
     *                    a plain "Clear filters" link.
     *   'icon'        — optional dashicon name (no 'dashicons-' prefix),
     *                    defaults to 'search' for filtered, 'info' for zero.
     *
     * Self-contained: ships its own <style> block (guarded by a static
     * flag so it only prints once per request even if this is called
     * more than once on the same page) rather than requiring every
     * consumer to remember a separate enqueue — same posture
     * BH_Studio's own inline <style> blocks already use elsewhere in
     * this codebase. Mobile-first sizing throughout (the icon/padding
     * shrink under 480px rather than a separate breakpoint-gated
     * layout, since the component's shape doesn't actually change on a
     * narrow viewport, only its scale).
     */
    // Two tiny (~200 byte) inline SVGs, not dashicons — dashicons is an
    // admin-only stylesheet WordPress doesn't enqueue on the front end
    // by default, and this component is meant to be genuinely portable
    // (drop into any front-end template, own-ur-shit loaded or not,
    // with zero extra enqueue to remember). currentColor so it inherits
    // the surrounding text color automatically.
    const EMPTY_STATE_ICONS = [
        'search' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="10" cy="10" r="7"></circle><line x1="21" y1="21" x2="15.5" y2="15.5"></line></svg>',
        'info-outline' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"></circle><line x1="12" y1="11" x2="12" y2="16"></line><line x1="12" y1="8" x2="12" y2="8"></line></svg>',
    ];

    public static function empty_state_html(array $args) {
        $reason = $args['reason'] ?? 'zero';
        $title = (string) ($args['title'] ?? ($reason === 'filtered' ? 'Nothing matches your filters' : 'Nothing here yet'));
        $description = (string) ($args['description'] ?? '');
        $icon_key = (string) ($args['icon'] ?? ($reason === 'filtered' ? 'search' : 'info-outline'));
        $icon_svg = self::EMPTY_STATE_ICONS[$icon_key] ?? self::EMPTY_STATE_ICONS['info-outline'];

        // Deliberately embedded on EVERY call, not guarded to print
        // once per request — confirmed live (bh-streaming's player.js)
        // that a "print once" guard breaks the moment a consumer swaps
        // `element.innerHTML` between two different rendered variants
        // (e.g. the zero-data fragment, then the filtered fragment on
        // the same view): the second swap destroys the first
        // fragment's <style> tag along with everything else that was
        // inside that container, silently losing all the CSS with no
        // error. A few hundred duplicated bytes of CSS per call is a
        // trivial cost next to a component that only sometimes works
        // depending on how its caller happens to use the markup.
        $out = '<style>
            .bhy-empty-state { text-align: center; padding: 48px 20px; color: var(--bh-text-dim, #6b6b6b); }
            .bhy-empty-state .bhy-empty-icon { display: inline-block; width: 40px; height: 40px; margin: 0 auto; color: var(--bh-border, #ccc); }
            .bhy-empty-state .bhy-empty-icon svg { width: 100%; height: 100%; }
            .bhy-empty-state h3 { margin: 12px 0 4px; font-size: 18px; color: var(--bh-text, #222); }
            .bhy-empty-state p { margin: 0 0 16px; font-size: 14px; }
            .bhy-empty-state .bhy-empty-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
            .bhy-empty-state .bhy-empty-cta { display: inline-block; padding: 8px 18px; border-radius: 6px; background: var(--bh-accent, #2271b1); color: #fff; text-decoration: none; font-size: 14px; }
            .bhy-empty-state .bhy-empty-clear { display: inline-block; padding: 8px 18px; font-size: 14px; color: var(--bh-text-dim, #6b6b6b); text-decoration: underline; }
            @media (max-width: 480px) {
                .bhy-empty-state { padding: 32px 16px; }
                .bhy-empty-state .bhy-empty-icon { width: 28px; height: 28px; }
                .bhy-empty-state h3 { font-size: 16px; }
                .bhy-empty-state .bhy-empty-actions { flex-direction: column; align-items: center; }
            }
        </style>';

        $out .= '<div class="bhy-empty-state">';
        $out .= '<span class="bhy-empty-icon" aria-hidden="true">' . $icon_svg . '</span>';
        $out .= '<h3>' . esc_html($title) . '</h3>';
        if ($description !== '') $out .= '<p>' . esc_html($description) . '</p>';

        $has_cta = !empty($args['cta_label']) && !empty($args['cta_url']);
        $has_clear = $reason === 'filtered' && !empty($args['clear_url']);
        if ($has_cta || $has_clear) {
            $out .= '<div class="bhy-empty-actions">';
            if ($has_cta) $out .= '<a class="bhy-empty-cta" href="' . esc_url($args['cta_url']) . '">' . esc_html($args['cta_label']) . '</a>';
            if ($has_clear) $out .= '<a class="bhy-empty-clear" href="' . esc_url($args['clear_url']) . '">Clear filters</a>';
            $out .= '</div>';
        }
        $out .= '</div>';
        return $out;
    }
}
