<?php
if (!defined('ABSPATH')) exit;

/**
 * Front-end brand text and theme colors, editable from a simple admin
 * page instead of being hardcoded in the JS/CSS. The plugin itself stays
 * "BillyHume Contest" everywhere admin-facing (post type labels, admin
 * notices) — this only controls what a VISITOR sees: the brand wordmark
 * in the player header, and the color palette.
 *
 * Stored as a single option (one row, one array) rather than one
 * option per field — this is small, always read together, and never
 * queried piecemeal, so one row is both simpler and cheaper than eight.
 */
class BH_Settings {
    const OPTION = 'bh_settings';

    // Defaults reproduce the current BillyHume look exactly — installing
    // this update changes nothing visually until someone opens the
    // settings page and changes a value.
    const DEFAULTS = [
        'brand_part1'  => 'Billy',
        'brand_part2'  => 'Hume',
        // Attachment ID of an uploaded logo image. Empty (the default)
        // means the text brand above is what renders; setting this
        // switches the header to the image instead — see logo_url().
        'brand_logo_id' => '',
        'color_bg'          => '#170807',
        'color_surface'     => '#220C0A',
        'color_surface_2'   => '#2C120E',
        'color_border'      => '#3D1B14',
        'color_text'        => '#EDDFCB',
        'color_text_dim'    => '#B99584',
        'color_accent'      => '#C1503A',
        'color_accent_soft' => '#E0A184',
        // Behind every modal (sign up, submit a song, results). Default
        // matches the hex+alpha equivalent of "The Door" theme's near-
        // black maroon, at the same ~0.82 opacity the plugin has always
        // used behind modals.
        'color_overlay'     => '#0D0504D1',
        // One color per voting category, assigned by order — a contest's
        // first category always gets cat_color_1, and so on. Defaults
        // match "The Door" (see THEME_GROUPS) — a warm, harmonious family
        // pulled from billyhume.net rather than the original neon set.
        'cat_color_1' => '#C1503A',
        'cat_color_2' => '#D9A441',
        'cat_color_3' => '#B8785A',
        'cat_color_4' => '#8C3B2E',
        'cat_color_5' => '#C98B5E',
        'cat_color_6' => '#A66A4D',
        'cat_color_7' => '#D96C4D',
        'cat_color_8' => '#7A4A38',
        // Typography — 'font_display' is headings/brand/titles, 'font_body'
        // is interface text. Either can be 'custom', which reads the
        // paired *_custom field instead (a system font stack, or a font
        // already loaded some other way on the site).
        'font_display'        => 'Space Grotesk',
        'font_display_custom' => '',
        'font_body'           => 'Inter',
        'font_body_custom'    => '',
        // Scale multipliers and the handful of literal sizes that make
        // sense as their own dial (radius, bar height, disc size) rather
        // than a proportion of something else.
        'font_scale'  => '1',
        'space_scale' => '1',
        'radius'      => '12',
        'radius_sm'   => '8',
        'bar_height'  => '84',
        'disc_size'   => '40',
    ];

    // Curated so every option is a real, known-good pairing rather than
    // an open text field by default — 'Custom' still escapes this for
    // anyone who wants a font not on the list. Each value is the Google
    // Fonts CSS2 family+weights param for that font.
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
        'System UI'        => null, // no web font — the OS default stack
    ];

    // Full-palette one-click starting points, grouped like a paint-swatch
    // app: a family name, then several complete palettes under it. Each
    // value is a ready-to-apply field set — same shape save() writes.
    const THEME_GROUPS = [
        'Signature' => [
            // Pulled directly from billyhume.net's own look — near-black
            // maroon, warm cream text, a dusty terracotta accent echoing
            // the site's red linework. Night is the plugin's default;
            // Day is the same identity inverted to a warm paper/cream
            // background for a light-mode option. Neon Vinyl (the
            // original look) is kept below for anyone who preferred that.
            'The Door — Night' => [
                'color_bg' => '#170807', 'color_surface' => '#220C0A', 'color_surface_2' => '#2C120E',
                'color_border' => '#3D1B14', 'color_text' => '#EDDFCB', 'color_text_dim' => '#B99584',
                'color_accent' => '#C1503A', 'color_accent_soft' => '#E0A184', 'color_overlay' => '#0D0504D1',
                'cat_color_1' => '#C1503A', 'cat_color_2' => '#D9A441', 'cat_color_3' => '#B8785A', 'cat_color_4' => '#8C3B2E',
                'cat_color_5' => '#C98B5E', 'cat_color_6' => '#A66A4D', 'cat_color_7' => '#D96C4D', 'cat_color_8' => '#7A4A38',
            ],
            'The Door — Day' => [
                // Same accent family, luminance flipped: warm aged-paper
                // background instead of near-black, dark maroon-brown
                // text instead of cream, and every category color
                // deepened/richened so it still reads clearly against a
                // light surface rather than washing out.
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

    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_bh_save_settings', [self::class, 'save']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_media']);
    }

    public static function enqueue_media($hook) {
        if (strpos($hook, 'bh-settings') === false) return;
        wp_enqueue_media();
    }

    public static function add_menu() {
        add_submenu_page(
            BH_PostTypes::MENU_PARENT,
            'Settings & Style', 'Settings & Style', 'manage_options', 'bh-settings',
            [self::class, 'render']
        );
    }

    // Fields a single contest is allowed to override — brand identity and
    // the two "who's running the show" colors, not the whole design
    // system. Typography, spacing, and the base surface/text colors stay
    // site-wide on purpose: a per-contest sponsor logo or accent color
    // makes sense, a per-contest font size does not.
    // Fields a single contest is allowed to override — the whole color
    // palette plus brand identity. Typography, spacing, and component
    // sizing (fonts, radius, bar height, disc size) stay site-wide only —
    // those are "how the app feels" decisions that should stay
    // consistent everywhere, whereas a full color re-skin (including
    // background) per contest is a completely reasonable thing to want
    // for a sponsor or seasonal look.
    const CONTEST_OVERRIDABLE = [
        'brand_part1', 'brand_part2', 'brand_logo_id',
        'color_bg', 'color_surface', 'color_surface_2', 'color_border', 'color_text', 'color_text_dim',
        'color_accent', 'color_accent_soft', 'color_overlay',
        'cat_color_1', 'cat_color_2', 'cat_color_3', 'cat_color_4', 'cat_color_5', 'cat_color_6', 'cat_color_7', 'cat_color_8',
    ];

    // Pass a contest ID to layer that contest's own overrides (if it has
    // "Override site styling" enabled) on top of the site-wide settings.
    // Omit it (or pass 0/null) for the plain global settings, e.g. on the
    // Settings & Style page itself.
    public static function get($contest_id = null) {
        $saved = get_option(self::OPTION, []);
        $settings = array_merge(self::DEFAULTS, is_array($saved) ? $saved : []);

        if ($contest_id) {
            $overrides = self::contest_overrides($contest_id);
            if ($overrides) $settings = array_merge($settings, $overrides);
        }

        return $settings;
    }

    // Only the fields actually present in a contest's saved override data
    // AND in CONTEST_OVERRIDABLE — a stray/old key in the stored JSON
    // (e.g. from a future version) can't leak into a field this version
    // doesn't intend to let contests touch.
    public static function contest_overrides($contest_id) {
        if (!get_post_meta($contest_id, '_bh_style_override', true)) return [];
        $raw = get_post_meta($contest_id, '_bh_style_json', true);
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data)) return [];
        return array_intersect_key($data, array_flip(self::CONTEST_OVERRIDABLE));
    }

    // Resolves brand_logo_id (an attachment ID) to an actual URL, or ''
    // if unset/deleted — the front end falls back to the text brand
    // whenever this comes back empty.
    public static function logo_url($settings) {
        $id = (int) ($settings['brand_logo_id'] ?? 0);
        if (!$id) return '';
        $url = wp_get_attachment_image_url($id, 'medium');
        return $url ?: '';
    }

    // Small JSON-ready payload used by BH_Auth::render() to apply a
    // contest's style override client-side, scoped to just that
    // contest's rendered instance (see BHPlayer.constructor in
    // player.js). Returns null when the contest has no override
    // enabled, so the caller can skip the data attribute entirely —
    // the overwhelmingly common case of "no per-contest override" adds
    // zero extra markup or client-side work.
    public static function contest_style_payload($contest_id) {
        $overrides = self::contest_overrides($contest_id);
        if (!$overrides) return null;

        $merged = self::get($contest_id);
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
            'brand' => [
                'part1'   => $merged['brand_part1'],
                'part2'   => $merged['brand_part2'],
                'logoUrl' => self::logo_url($merged),
            ],
        ];
    }

    // Inline <style> block that overrides the CSS custom properties the
    // stylesheet already uses. Enqueued after bh-player.css so it wins the
    // cascade — the stylesheet itself never needs to change per site.
    public static function inline_css() {
        $s = self::get();
        $vars = [
            '--bh-bg'          => $s['color_bg'],
            '--bh-surface'     => $s['color_surface'],
            '--bh-surface-2'   => $s['color_surface_2'],
            '--bh-border'      => $s['color_border'],
            '--bh-text'        => $s['color_text'],
            '--bh-text-dim'    => $s['color_text_dim'],
            '--bh-accent'      => $s['color_accent'],
            '--bh-accent-soft' => $s['color_accent_soft'],
            '--bh-overlay'     => $s['color_overlay'],
        ];
        for ($i = 1; $i <= 8; $i++) $vars['--bh-cat-' . $i] = $s['cat_color_' . $i];
        $decls = '';
        foreach ($vars as $name => $val) $decls .= $name . ':' . self::safe_color($val) . ';';

        // Font stacks aren't validated against a whitelist like colors —
        // 'custom' is meant to accept arbitrary font-family text — but
        // still run through esc_html-equivalent escaping so a value full
        // of quotes or braces can't break out of the declaration.
        $decls .= '--bh-font-display:' . self::css_safe_string(self::font_family($s, 'display')) . ', sans-serif;';
        $decls .= '--bh-font-body:' . self::css_safe_string(self::font_family($s, 'body')) . ', sans-serif;';

        $decls .= '--bh-font-scale:' . self::safe_number($s['font_scale'], 0.75, 1.6, 1) . ';';
        $decls .= '--bh-space-scale:' . self::safe_number($s['space_scale'], 0.6, 1.8, 1) . ';';
        $decls .= '--bh-radius:' . self::safe_number($s['radius'], 0, 32, 12) . 'px;';
        $decls .= '--bh-radius-sm:' . self::safe_number($s['radius_sm'], 0, 24, 8) . 'px;';
        $decls .= '--bh-bar-height:' . self::safe_number($s['bar_height'], 56, 140, 84) . 'px;';
        $decls .= '--bh-disc-size:' . self::safe_number($s['disc_size'], 24, 72, 40) . 'px;';

        return ':root{' . $decls . '}';
    }

    // Resolves the effective family name for 'display' or 'body': the
    // curated pick, or the paired *_custom text if 'Custom' was chosen.
    private static function font_family($s, $slot) {
        $picked = $s['font_' . $slot];
        if ($picked === 'Custom' || !array_key_exists($picked, self::FONT_OPTIONS)) {
            $custom = trim((string) $s['font_' . $slot . '_custom']);
            return $custom !== '' ? $custom : self::DEFAULTS['font_' . $slot];
        }
        return $picked;
    }

    // A font-family value only ever needs quoting, not full CSS syntax —
    // strip characters that could close the declaration or the custom
    // property early (quotes, semicolons, braces) rather than trying to
    // validate arbitrary font names against a pattern.
    private static function css_safe_string($val) {
        $val = preg_replace('/[";{}]/', '', (string) $val);
        return '"' . trim($val) . '"';
    }

    // Clamps to a sane range and falls back to $default on anything that
    // isn't a plain number — every one of these ends up inside a CSS
    // calc(), so a stray non-numeric value here would break every
    // spacing/type declaration on the page at once, not just its own.
    private static function safe_number($val, $min, $max, $default) {
        if (!is_numeric($val)) return $default;
        return max($min, min($max, (float) $val));
    }

    // Builds one Google Fonts request for whichever curated fonts are
    // actually in use (deduped) — 'System UI' and 'Custom' selections
    // contribute nothing here, since neither needs a webfont loaded.
    // Used on the real front end, where only the fonts actually chosen
    // should cost a request. The admin preview uses a separate,
    // comprehensive URL (see preview_all_fonts_url) so switching the
    // dropdown never needs a reload.
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
        return 'https://fonts.googleapis.com/css2?' . implode('&', array_map(
            fn($p) => 'family=' . $p, array_keys($params)
        )) . '&display=swap';
    }

    // Every curated font at once — only used inside the admin preview
    // iframe, which is never seen by a site visitor, so the extra weight
    // is a non-issue and buys instant switching in the dropdown.
    private static function preview_all_fonts_url() {
        $params = array_filter(self::FONT_OPTIONS);
        return 'https://fonts.googleapis.com/css2?' . implode('&', array_map(
            fn($p) => 'family=' . $p, array_values($params)
        )) . '&display=swap';
    }

    // Only ever used inside a CSS custom property value, but still
    // whitelisted rather than trusted as-is — an admin-only setting is
    // still attacker-reachable if an admin account is ever compromised,
    // and this runs on every front-end page load. Accepts the three
    // shapes the settings page can produce: the literal keyword
    // "transparent", hex (3–8 digits, so #fff through #ffffffaa with
    // alpha), or rgb()/rgba().
    private static function safe_color($val) {
        $val = trim((string) $val);
        if (strcasecmp($val, 'transparent') === 0) return 'transparent';
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $val)) return $val;
        if (preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/', $val)) return $val;
        return '#000000';
    }

    public static function save() {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bh_save_settings');

        $data = [];
        $data['brand_part1'] = sanitize_text_field($_POST['brand_part1'] ?? self::DEFAULTS['brand_part1']);
        $data['brand_part2'] = sanitize_text_field($_POST['brand_part2'] ?? self::DEFAULTS['brand_part2']);
        $data['brand_logo_id'] = isset($_POST['brand_logo_id']) ? (int) $_POST['brand_logo_id'] : 0;
        foreach (self::DEFAULTS as $key => $default) {
            if (strpos($key, 'color_') !== 0 && strpos($key, 'cat_color_') !== 0) continue;
            $val = isset($_POST[$key]) ? sanitize_text_field($_POST[$key]) : $default;
            $data[$key] = self::safe_color($val);
        }

        foreach (['font_display', 'font_body'] as $key) {
            $picked = sanitize_text_field($_POST[$key] ?? self::DEFAULTS[$key]);
            $data[$key] = (array_key_exists($picked, self::FONT_OPTIONS) || $picked === 'Custom') ? $picked : self::DEFAULTS[$key];
            $data[$key . '_custom'] = sanitize_text_field($_POST[$key . '_custom'] ?? '');
        }

        $data['font_scale']  = self::safe_number($_POST['font_scale']  ?? null, 0.75, 1.6, 1);
        $data['space_scale'] = self::safe_number($_POST['space_scale'] ?? null, 0.6, 1.8, 1);
        $data['radius']      = self::safe_number($_POST['radius']      ?? null, 0, 32, 12);
        $data['radius_sm']   = self::safe_number($_POST['radius_sm']   ?? null, 0, 24, 8);
        $data['bar_height']  = self::safe_number($_POST['bar_height']  ?? null, 56, 140, 84);
        $data['disc_size']   = self::safe_number($_POST['disc_size']   ?? null, 24, 72, 40);

        update_option(self::OPTION, $data);
        wp_safe_redirect(add_query_arg(['page' => 'bh-settings', 'saved' => '1'], admin_url(BH_PostTypes::MENU_PARENT)));
        exit;
    }

    public static function render() {
        $s = self::get();
        ?>
        <div class="wrap bh-settings-wrap">
            <h1>Settings &amp; Style</h1>
            <?php if (!empty($_GET['saved'])): ?>
                <div class="notice notice-success"><p>Saved.</p></div>
            <?php endif; ?>
            <p class="bh-settings-intro">Controls what visitors see in the player: the brand name, the color palette, and each category's pill color. The plugin itself stays named BillyHume Contest in the admin — this only changes the front end. The preview on the right is the plugin's real CSS, rendering live as you edit — mouse over its buttons and tabs to check hover states.</p>

            <style>
                .bh-settings-wrap .bh-settings-intro { max-width: 900px; color: #555; margin: 8px 0 16px; }
                .bh-settings-layout { display: grid; grid-template-columns: minmax(340px, 520px) minmax(320px, 1fr); gap: 18px; align-items: start; }
                @media (max-width: 1100px) { .bh-settings-layout { grid-template-columns: 1fr; } }

                .bh-card {
                    background: #fff; border: 1px solid #dcdcde; border-radius: 8px;
                    padding: 16px 20px; margin: 0 0 14px;
                }
                .bh-card h2 { margin: 0 0 4px; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #1d2327; }
                .bh-card p.bh-card-desc { color: #666; font-size: 12px; margin: 0 0 12px; line-height: 1.5; }
                .bh-brand-row { display: flex; gap: 12px; flex-wrap: wrap; }
                .bh-field { display: flex; flex-direction: column; gap: 4px; }
                .bh-field label { font-weight: 600; font-size: 11px; color: #1d2327; }
                .bh-color-grid {
                    display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                    gap: 10px;
                }
                <?php echo self::swatch_css(); ?>
                .bh-settings-form-col .submit { margin: 6px 0 0; padding: 0; }

                .bh-logo-row { display: flex; align-items: center; gap: 14px; }
                .bh-logo-preview {
                    width: 72px; height: 72px; border-radius: 8px; border: 1px solid #dcdcde;
                    background: #f6f7f7; display: flex; align-items: center; justify-content: center;
                    overflow: hidden; flex: 0 0 auto;
                }
                .bh-logo-preview img { max-width: 100%; max-height: 100%; object-fit: contain; }
                .bh-logo-preview span { font-size: 11px; color: #888; }
                .bh-logo-actions { display: flex; flex-direction: column; gap: 6px; align-items: flex-start; }

                /* Collapsible groups keep the default view short — most
                   people never need to open anything past the theme
                   picker and the preset chips. */
                details.bh-group { border: 1px solid #dcdcde; border-radius: 6px; margin-bottom: 8px; }
                details.bh-group:last-child { margin-bottom: 0; }
                details.bh-group summary {
                    padding: 9px 12px; cursor: pointer; font-weight: 600; font-size: 12px; color: #1d2327;
                    list-style: none; display: flex; justify-content: space-between; align-items: center;
                }
                details.bh-group summary::-webkit-details-marker { display: none; }
                details.bh-group summary::after { content: '▾'; font-size: 10px; color: #888; }
                details.bh-group[open] summary::after { content: '▴'; }
                details.bh-group summary .bh-group-hint { font-weight: 400; color: #888; font-size: 11px; margin-left: 8px; }
                details.bh-group .bh-group-body { padding: 4px 12px 12px; border-top: 1px solid #eee; }

                /* Theme presets — grouped dropdown (optgroups), like a
                   paint-app palette picker, plus a small live swatch
                   strip next to it showing bg/surface/accent at a glance
                   for whichever theme is selected. */
                .bh-theme-picker { display: flex; align-items: center; gap: 10px; }
                .bh-theme-select { flex: 1; max-width: 320px; }
                .bh-theme-swatch-preview {
                    width: 64px; height: 32px; border-radius: 6px; flex: 0 0 auto;
                    border: 1px solid #dcdcde; background: #f6f7f7;
                }

                /* Preset chips — replace most sliders with a handful of
                   known-good values; the sliders themselves move into an
                   Advanced group for anyone who wants an exact number. */
                .bh-chip-group { margin-bottom: 12px; }
                .bh-chip-group:last-child { margin-bottom: 0; }
                .bh-chip-group > label { display: block; font-weight: 600; font-size: 11px; color: #1d2327; margin-bottom: 5px; }
                .bh-chip-row { display: flex; gap: 6px; flex-wrap: wrap; }
                .bh-chip {
                    padding: 6px 12px; border: 1px solid #dcdcde; border-radius: 6px;
                    background: #f6f7f7; cursor: pointer; font-size: 12px; color: #1d2327;
                }
                .bh-chip:hover { background: #eee; }
                .bh-chip.active { background: #2271b1; color: #fff; border-color: #2271b1; }

                /* Visual chips: swatch preview stacked above the label,
                   in a taller card-like button rather than a plain text
                   pill — reads more like a real design-token picker. */
                .bh-chip-row-visual { align-items: flex-end; gap: 8px; }
                .bh-chip-visual {
                    display: flex; flex-direction: column; align-items: center; justify-content: flex-end;
                    gap: 6px; padding: 10px 12px; min-width: 56px; height: 64px;
                }
                .bh-chip-visual .bh-chip-swatch { display: flex; align-items: center; justify-content: center; height: 24px; }
                .bh-chip-visual.active .bh-chip-swatch span { background: #fff !important; color: #2271b1 !important; }
                .bh-chip-vlabel { font-size: 11px; font-weight: 600; }

                .bh-slider-row { margin: 10px 0; }
                .bh-slider-row label { display: flex; justify-content: space-between; font-weight: 600; font-size: 11px; color: #1d2327; margin-bottom: 4px; }
                .bh-slider-row .bh-slider-val { font-weight: 700; color: #2271b1; }
                .bh-slider-row input[type=range] { width: 100%; margin: 0; }
                .bh-font-field { display: flex; flex-direction: column; gap: 4px; min-width: 180px; }
                .bh-font-field select { max-width: 200px; }
                .bh-font-field input[type=text] { max-width: 200px; margin-top: 2px; }

                /* Preview panel — dark toolbar chrome per box (kept as
                   originally designed), checkered canvas beneath so a
                   transparent background is genuinely visible instead of
                   reading as "just black". */
                .bh-preview-col { position: sticky; top: 20px; }
                .bh-preview-chrome {
                    background: #18181b; border-radius: 10px; overflow: hidden;
                    box-shadow: 0 8px 30px rgba(0,0,0,0.25); border: 1px solid #2a2a30;
                    margin-bottom: 20px;
                }
                .bh-preview-toolbar {
                    display: flex; align-items: center; gap: 8px; padding: 8px 12px;
                    background: #1f1f24; border-bottom: 1px solid #2a2a30;
                    font-family: -apple-system, sans-serif; font-size: 11px; color: #9a9aa2;
                }
                .bh-preview-dot { width: 7px; height: 7px; border-radius: 50%; background: #2DD4BF; box-shadow: 0 0 6px #2DD4BF; flex: 0 0 auto; }
                .bh-preview-toolbar strong { color: #e4e4e7; font-weight: 600; }
                .bh-preview-canvas {
                    background-image: linear-gradient(45deg, #2a2a30 25%, transparent 25%), linear-gradient(-45deg, #2a2a30 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #2a2a30 75%), linear-gradient(-45deg, transparent 75%, #2a2a30 75%);
                    background-size: 20px 20px; background-position: 0 0, 0 10px, 10px -10px, -10px 0;
                    background-color: #101012;
                }
                .bh-preview-canvas iframe { display: block; width: 100%; border: 0; }
                .bh-preview-note { font-size: 11px; color: #888; margin: 6px 2px 0; }
            </style>

            <div class="bh-settings-layout">
                <div class="bh-settings-form-col">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('bh_save_settings'); ?>
                        <input type="hidden" name="action" value="bh_save_settings">

                        <div class="bh-card">
                            <h2>Brand</h2>
                            <p class="bh-card-desc">A logo image replaces the text brand in the header when set. The text fields below still matter even with a logo — they're used as the image's alt text, and as the fallback anywhere a logo can't load.</p>
                            <div class="bh-logo-row">
                                <div class="bh-logo-preview" id="bh-logo-preview">
                                    <?php $logo_url = self::logo_url($s); ?>
                                    <img id="bh-logo-preview-img" src="<?php echo esc_url($logo_url); ?>" style="<?php echo $logo_url ? '' : 'display:none;'; ?>" alt="">
                                    <span id="bh-logo-preview-empty" style="<?php echo $logo_url ? 'display:none;' : ''; ?>">No logo</span>
                                </div>
                                <div class="bh-logo-actions">
                                    <input type="hidden" id="brand_logo_id" name="brand_logo_id" value="<?php echo esc_attr($s['brand_logo_id']); ?>">
                                    <button type="button" class="button" id="bh-logo-upload">Upload logo…</button>
                                    <button type="button" class="button" id="bh-logo-remove" style="<?php echo $logo_url ? '' : 'display:none;'; ?>">Remove</button>
                                </div>
                            </div>
                            <div class="bh-brand-row" style="margin-top:14px;">
                                <div class="bh-field">
                                    <label for="brand_part1">First part</label>
                                    <input type="text" id="brand_part1" name="brand_part1" value="<?php echo esc_attr($s['brand_part1']); ?>" class="regular-text">
                                </div>
                                <div class="bh-field">
                                    <label for="brand_part2">Accent part</label>
                                    <input type="text" id="brand_part2" name="brand_part2" value="<?php echo esc_attr($s['brand_part2']); ?>" class="regular-text">
                                </div>
                            </div>
                        </div>

                        <div class="bh-card">
                            <h2>Quick Themes</h2>
                            <p class="bh-card-desc">A full palette in one click, organized by family — like picking a paint chip. Choose one, then fine-tune anything below if you want.</p>
                            <?php self::theme_picker(self::THEME_GROUPS); ?>
                        </div>

                        <div class="bh-card">
                            <h2>Colors</h2>
                            <p class="bh-card-desc">Grouped by where each color shows up. Hex, <code>rgba()</code>, or <code>transparent</code> — the checkerboard shows through wherever a color is transparent.</p>

                            <details class="bh-group" open>
                                <summary>Base &amp; Surfaces <span class="bh-group-hint">background, text, borders — used everywhere</span></summary>
                                <div class="bh-group-body">
                                    <div class="bh-color-grid">
                                        <?php
                                        self::color_row('color_bg', 'Background', $s, true);
                                        self::color_row('color_surface', 'Surface (list, cards)', $s);
                                        self::color_row('color_surface_2', 'Surface (inputs, raised)', $s);
                                        self::color_row('color_border', 'Border', $s);
                                        self::color_row('color_text', 'Text', $s);
                                        self::color_row('color_text_dim', 'Text (dim)', $s);
                                        ?>
                                    </div>
                                </div>
                            </details>

                            <details class="bh-group">
                                <summary>Buttons &amp; Accent <span class="bh-group-hint">votes, play button, links, focus rings</span></summary>
                                <div class="bh-group-body">
                                    <div class="bh-color-grid">
                                        <?php
                                        self::color_row('color_accent', 'Accent', $s);
                                        self::color_row('color_accent_soft', 'Accent (hover/soft)', $s);
                                        ?>
                                    </div>
                                </div>
                            </details>

                            <details class="bh-group">
                                <summary>Modals &amp; Forms <span class="bh-group-hint">sign-up, submit-a-song</span></summary>
                                <div class="bh-group-body">
                                    <p class="bh-card-desc" style="margin-top:6px;">Form fields and modal cards use the Base &amp; Surfaces colors above — this is just the dimmed backdrop behind an open modal.</p>
                                    <div class="bh-color-grid">
                                        <?php self::color_row('color_overlay', 'Modal backdrop', $s); ?>
                                    </div>
                                </div>
                            </details>

                            <details class="bh-group">
                                <summary>Category Pills <span class="bh-group-hint">one color per voting category tab</span></summary>
                                <div class="bh-group-body">
                                    <p class="bh-card-desc" style="margin-top:6px;">In order — a contest's first category gets color 1, its second gets color 2, and so on. Each pill is monochromatic: text, border, hover tint, and active fill are all shades of one color.</p>
                                    <div class="bh-color-grid">
                                        <?php for ($i = 1; $i <= 8; $i++): ?>
                                            <?php self::color_row('cat_color_' . $i, 'Category ' . $i, $s); ?>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </details>
                        </div>

                        <div class="bh-card">
                            <h2>Typography</h2>
                            <p class="bh-card-desc">Display font is the brand, track titles, and modal headings. Body font is everything else.</p>
                            <div class="bh-brand-row">
                                <?php self::font_field('font_display', 'Display font', $s); ?>
                                <?php self::font_field('font_body', 'Body font', $s); ?>
                            </div>
                            <?php self::chip_group_visual('Text size', [
                                ['label' => 'XS', 'set' => ['font_scale' => '0.85'], 'swatch' => self::swatch_text(10)],
                                ['label' => 'SM', 'set' => ['font_scale' => '0.925'], 'swatch' => self::swatch_text(11)],
                                ['label' => 'MD', 'set' => ['font_scale' => '1'], 'swatch' => self::swatch_text(12)],
                                ['label' => 'LG', 'set' => ['font_scale' => '1.1'], 'swatch' => self::swatch_text(13)],
                                ['label' => 'XL', 'set' => ['font_scale' => '1.2'], 'swatch' => self::swatch_text(15)],
                            ], $s); ?>
                            <details class="bh-group">
                                <summary>Advanced <span class="bh-group-hint">exact value</span></summary>
                                <div class="bh-group-body">
                                    <?php self::slider_row('font_scale', 'Text size', $s, 0.75, 1.6, 0.05, '×'); ?>
                                </div>
                            </details>
                        </div>

                        <div class="bh-card">
                            <h2>Spacing &amp; Sizing</h2>
                            <p class="bh-card-desc">A fixed scale, like a type or spacing scale in any real design system — each step moves every gap, corner, and size together in the same proportion, so nothing gets randomly out of sync with anything else.</p>
                            <?php self::chip_group_visual('Spacing scale', [
                                ['label' => 'XS', 'set' => ['space_scale' => '0.75'], 'swatch' => self::swatch_dots(2)],
                                ['label' => 'SM', 'set' => ['space_scale' => '0.875'], 'swatch' => self::swatch_dots(3)],
                                ['label' => 'MD', 'set' => ['space_scale' => '1'], 'swatch' => self::swatch_dots(4)],
                                ['label' => 'LG', 'set' => ['space_scale' => '1.25'], 'swatch' => self::swatch_dots(5)],
                                ['label' => 'XL', 'set' => ['space_scale' => '1.5'], 'swatch' => self::swatch_dots(6)],
                            ], $s); ?>
                            <?php self::chip_group_visual('Corners', [
                                ['label' => 'None',    'set' => ['radius' => '0',  'radius_sm' => '0'],  'swatch' => self::swatch_radius(0)],
                                ['label' => 'Subtle',  'set' => ['radius' => '6',  'radius_sm' => '4'],  'swatch' => self::swatch_radius(4)],
                                ['label' => 'Rounded', 'set' => ['radius' => '12', 'radius_sm' => '8'],  'swatch' => self::swatch_radius(8)],
                                ['label' => 'Soft',    'set' => ['radius' => '20', 'radius_sm' => '14'], 'swatch' => self::swatch_radius(11)],
                                ['label' => 'Full',    'set' => ['radius' => '32', 'radius_sm' => '20'], 'swatch' => self::swatch_radius(14)],
                            ], $s); ?>
                            <?php self::chip_group_visual('Now-playing bar', [
                                ['label' => 'Compact',  'set' => ['bar_height' => '64'],  'swatch' => self::swatch_bar(8)],
                                ['label' => 'Standard', 'set' => ['bar_height' => '84'],  'swatch' => self::swatch_bar(10)],
                                ['label' => 'Tall',     'set' => ['bar_height' => '104'], 'swatch' => self::swatch_bar(13)],
                                ['label' => 'XL',       'set' => ['bar_height' => '124'], 'swatch' => self::swatch_bar(16)],
                            ], $s); ?>
                            <?php self::chip_group_visual('Track disc size', [
                                ['label' => 'SM', 'set' => ['disc_size' => '28'], 'swatch' => self::swatch_circle(12)],
                                ['label' => 'MD', 'set' => ['disc_size' => '40'], 'swatch' => self::swatch_circle(16)],
                                ['label' => 'LG', 'set' => ['disc_size' => '52'], 'swatch' => self::swatch_circle(20)],
                                ['label' => 'XL', 'set' => ['disc_size' => '64'], 'swatch' => self::swatch_circle(24)],
                            ], $s); ?>
                            <details class="bh-group">
                                <summary>Advanced <span class="bh-group-hint">exact values</span></summary>
                                <div class="bh-group-body">
                                    <?php self::slider_row('space_scale', 'Overall spacing', $s, 0.6, 1.8, 0.05, '×'); ?>
                                    <?php self::slider_row('radius', 'Corner roundness (cards, modals)', $s, 0, 32, 1, 'px'); ?>
                                    <?php self::slider_row('radius_sm', 'Corner roundness (small elements)', $s, 0, 24, 1, 'px'); ?>
                                    <?php self::slider_row('bar_height', 'Now-playing bar height', $s, 56, 140, 2, 'px'); ?>
                                    <?php self::slider_row('disc_size', 'Track disc size', $s, 24, 72, 2, 'px'); ?>
                                </div>
                            </details>
                        </div>

                        <?php submit_button('Save Settings'); ?>
                    </form>
                </div>

                <div class="bh-preview-col">
                    <?php
                    // Three views cover every styleable surface: Player
                    // (browsing + voting), Forms (sign-up and submit side
                    // by side — the submit column shows the dropdown open
                    // so both its states are each represented exactly
                    // once, with no third dedicated box needed), and
                    // Results (the actual results modal, not a stand-in).
                    // Fixed heights are content-matched by hand rather
                    // than measured at runtime — see the note that used
                    // to be here about why scrollHeight-based auto-sizing
                    // silently produced wrong-height boxes.
                    $sections = [
                        'player'  => ['label' => 'Player',           'height' => 480, 'body' => self::preview_body_player($s)],
                        'forms'   => ['label' => 'Forms & Modals',   'height' => 760, 'body' => self::preview_body_forms($s)],
                        'results' => ['label' => 'Results Modal',    'height' => 380, 'body' => self::preview_body_results($s)],
                    ];
                    foreach ($sections as $id => $section): ?>
                        <div class="bh-preview-chrome">
                            <div class="bh-preview-toolbar">
                                <span class="bh-preview-dot"></span>
                                <strong><?php echo esc_html($section['label']); ?></strong>
                            </div>
                            <div class="bh-preview-canvas">
                                <iframe id="bh-live-preview-<?php echo esc_attr($id); ?>" class="bh-preview-frame" title="<?php echo esc_attr($section['label']); ?> preview"
                                        style="height:<?php echo (int) $section['height']; ?>px;"
                                        srcdoc="<?php echo esc_attr(self::preview_doc($section['body'])); ?>"></iframe>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="bh-preview-note">Real player markup and CSS in every box — not a mockup. Hover the tabs, votes, and buttons to check every state. Updates as you type.</div>
                </div>
            </div>
        </div>

        <script>
        (function () {
            function isValidCssColor(v) {
                var s = new Option().style;
                s.color = '';
                s.color = v;
                return s.color !== '';
            }

            var COLOR_KEYS = [
                'color_bg', 'color_surface', 'color_surface_2', 'color_border', 'color_text', 'color_text_dim',
                'color_accent', 'color_accent_soft', 'color_overlay',
                'cat_color_1', 'cat_color_2', 'cat_color_3', 'cat_color_4', 'cat_color_5', 'cat_color_6', 'cat_color_7', 'cat_color_8',
            ];
            var VAR_NAMES = {
                color_bg: '--bh-bg', color_surface: '--bh-surface', color_surface_2: '--bh-surface-2', color_border: '--bh-border',
                color_text: '--bh-text', color_text_dim: '--bh-text-dim', color_accent: '--bh-accent', color_accent_soft: '--bh-accent-soft',
                color_overlay: '--bh-overlay',
                cat_color_1: '--bh-cat-1', cat_color_2: '--bh-cat-2', cat_color_3: '--bh-cat-3', cat_color_4: '--bh-cat-4',
                cat_color_5: '--bh-cat-5', cat_color_6: '--bh-cat-6', cat_color_7: '--bh-cat-7', cat_color_8: '--bh-cat-8',
            };
            // Sliders carry their own unit ('×' or 'px') in data-unit; only
            // 'px' ones need that suffix appended inside the CSS value —
            // the '×' ones are unitless multipliers used inside calc().
            var SLIDER_VARS = {
                font_scale: '--bh-font-scale', space_scale: '--bh-space-scale', radius: '--bh-radius',
                radius_sm: '--bh-radius-sm', bar_height: '--bh-bar-height', disc_size: '--bh-disc-size',
            };

            function fontValue(slot) {
                var select = document.getElementById('font_' + slot);
                if (!select) return '';
                if (select.value === 'Custom') {
                    var custom = document.getElementById('font_' + slot + '_custom');
                    return custom && custom.value.trim() ? custom.value.trim() : select.value;
                }
                return select.value;
            }

            var PREVIEW_IDS = ['player', 'forms', 'results'];

            function refreshPreview() {
                var decls = '';
                COLOR_KEYS.forEach(function (key) {
                    var input = document.getElementById(key);
                    if (input && input.value.trim()) decls += VAR_NAMES[key] + ':' + input.value.trim() + ';';
                });
                Object.keys(SLIDER_VARS).forEach(function (key) {
                    var input = document.getElementById(key);
                    if (!input) return;
                    var unit = input.dataset.unit === 'px' ? 'px' : '';
                    decls += SLIDER_VARS[key] + ':' + input.value + unit + ';';
                });
                var display = fontValue('display'), body = fontValue('body');
                if (display) decls += '--bh-font-display:"' + display.replace(/"/g, '') + '", sans-serif;';
                if (body) decls += '--bh-font-body:"' + body.replace(/"/g, '') + '", sans-serif;';
                var rootRule = ':root{' + decls + '}';

                var p1 = document.getElementById('brand_part1'), p2 = document.getElementById('brand_part2');

                PREVIEW_IDS.forEach(function (id) {
                    var iframe = document.getElementById('bh-live-preview-' + id);
                    var doc = iframe && iframe.contentDocument;
                    if (!doc) return;

                    var styleEl = doc.getElementById('bh-vars');
                    if (styleEl) styleEl.textContent = rootRule;

                    // Brand text only exists in the Player preview.
                    var b1 = doc.getElementById('bh-brand-1'), b2 = doc.getElementById('bh-brand-2');
                    if (p1 && b1) b1.textContent = p1.value;
                    if (p2 && b2) b2.textContent = p2.value;
                });
            }

            document.querySelectorAll('.bh-swatch-controls input[type=text]').forEach(function (input) {
                var key = input.dataset.key;
                var swatch = document.getElementById('bh-swatch-' + key);
                var picker = document.getElementById('bh-picker-' + key);

                function sync() {
                    var v = input.value.trim();
                    if (isValidCssColor(v)) swatch.style.background = v;
                    refreshPreview();
                }

                input.addEventListener('input', sync);
                if (picker) picker.addEventListener('input', function () { input.value = picker.value; sync(); });
            });

            var p1 = document.getElementById('brand_part1'), p2 = document.getElementById('brand_part2');
            if (p1) p1.addEventListener('input', refreshPreview);
            if (p2) p2.addEventListener('input', refreshPreview);

            var transBtn = document.getElementById('bh-set-transparent');
            if (transBtn) {
                transBtn.addEventListener('click', function () {
                    var input = document.getElementById('color_bg');
                    input.value = 'transparent';
                    input.dispatchEvent(new Event('input'));
                });
            }

            // Sliders: live-update the numeric readout next to the label,
            // then the preview.
            Object.keys(SLIDER_VARS).forEach(function (key) {
                var input = document.getElementById(key);
                var out = document.getElementById(key + '_val');
                if (!input) return;
                input.addEventListener('input', function () {
                    if (out) out.textContent = input.value + (input.dataset.unit || '');
                    refreshPreview();
                });
            });

            // Font pickers: toggle the custom text field, then preview.
            document.querySelectorAll('.bh-font-field select').forEach(function (select) {
                var customInput = document.getElementById(select.dataset.customTarget);
                function sync() {
                    if (customInput) customInput.style.display = select.value === 'Custom' ? '' : 'none';
                    refreshPreview();
                }
                select.addEventListener('change', sync);
                if (customInput) customInput.addEventListener('input', refreshPreview);
            });

            // Applies a {field: value} map to the actual inputs — used by
            // both the theme dropdown (full color palette) and preset
            // chips (one or two fields, e.g. radius + radius_sm together).
            // Swatches and slider readouts are kept in sync manually here
            // since setting .value programmatically fires no events.
            function applyValues(data) {
                Object.keys(data).forEach(function (key) {
                    var el = document.getElementById(key);
                    if (!el) return;
                    el.value = data[key];

                    var swatch = document.getElementById('bh-swatch-' + key);
                    if (swatch && isValidCssColor(el.value)) swatch.style.background = el.value;

                    var out = document.getElementById(key + '_val');
                    if (out) out.textContent = el.value + (el.dataset.unit || '');
                });
                refreshPreview();
            }

            var logoUploadBtn = document.getElementById('bh-logo-upload');
            var logoRemoveBtn = document.getElementById('bh-logo-remove');
            var logoField = document.getElementById('brand_logo_id');
            var logoImg = document.getElementById('bh-logo-preview-img');
            var logoEmpty = document.getElementById('bh-logo-preview-empty');
            var logoFrame = null;

            if (logoUploadBtn && window.wp && window.wp.media) {
                logoUploadBtn.addEventListener('click', function () {
                    if (logoFrame) { logoFrame.open(); return; }
                    logoFrame = wp.media({
                        title: 'Choose a logo',
                        button: { text: 'Use this image' },
                        library: { type: 'image' },
                        multiple: false,
                    });
                    logoFrame.on('select', function () {
                        var att = logoFrame.state().get('selection').first().toJSON();
                        logoField.value = att.id;
                        var previewUrl = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
                        logoImg.src = previewUrl;
                        logoImg.style.display = '';
                        logoEmpty.style.display = 'none';
                        logoRemoveBtn.style.display = '';
                    });
                    logoFrame.open();
                });
            }
            if (logoRemoveBtn) {
                logoRemoveBtn.addEventListener('click', function () {
                    logoField.value = '';
                    logoImg.src = '';
                    logoImg.style.display = 'none';
                    logoEmpty.style.display = '';
                    logoRemoveBtn.style.display = 'none';
                });
            }

            var themeSelect = document.getElementById('bh-theme-select');
            var themeSwatch = document.getElementById('bh-theme-swatch-preview');
            if (themeSelect) {
                themeSelect.addEventListener('change', function () {
                    var opt = themeSelect.options[themeSelect.selectedIndex];
                    if (!opt || !opt.dataset.set) { if (themeSwatch) themeSwatch.style.background = '#f6f7f7'; return; }
                    var data = JSON.parse(opt.dataset.set);
                    applyValues(data);
                    if (themeSwatch) {
                        themeSwatch.style.background = 'linear-gradient(135deg, '
                            + data.color_bg + ' 0%, ' + data.color_surface + ' 50%, ' + data.color_accent + ' 100%)';
                    }
                });
            }

            document.querySelectorAll('.bh-chip').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    applyValues(JSON.parse(btn.dataset.set));
                    btn.parentElement.querySelectorAll('.bh-chip').forEach(function (b) { b.classList.remove('active'); });
                    btn.classList.add('active');
                });
            });

            PREVIEW_IDS.forEach(function (id) {
                var iframe = document.getElementById('bh-live-preview-' + id);
                if (!iframe) return;
                iframe.addEventListener('load', refreshPreview);
            });
        })();
        </script>
        <?php
    }

    // Shared <head> + <body> wrapper — every preview section is its own
    // complete, independent HTML document (own iframe, own doc), so each
    // one needs the fonts, player.css, and the live-updatable
    // <style id="bh-vars"> block on its own.
    private static function preview_doc($body_html) {
        ob_start();
        ?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<link rel="stylesheet" href="<?php echo esc_url(self::preview_all_fonts_url()); ?>">
<link rel="stylesheet" href="<?php echo esc_url(BH_URL . 'assets/css/player.css'); ?>">
<style id="bh-vars"><?php echo self::inline_css(); ?></style>
<style>body{margin:0;color:var(--bh-text);font-family:var(--bh-font-body);}</style>
</head>
<body>
<?php echo $body_html; ?>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    private static function preview_body_player($s) {
        ob_start();
        ?>
<div class="bh-container">
    <div class="bh-header">
        <div class="bh-brand" id="bh-brand"><span id="bh-brand-1"><?php echo esc_html($s['brand_part1']); ?></span><span id="bh-brand-2"><?php echo esc_html($s['brand_part2']); ?></span></div>
        <div class="bh-header-actions">
            <button class="bh-results-btn bh-btn bh-btn-results">Results</button>
            <button class="bh-submit-btn bh-btn bh-btn-primary">Submit a Song</button>
            <a href="#" class="bh-logout-btn bh-btn bh-btn-outline">Log Out</a>
        </div>
    </div>

    <div class="bh-category-tabs">
        <button class="bh-cat-tab active" style="--bh-cat-color:var(--bh-cat-1)">Pop</button>
        <button class="bh-cat-tab" style="--bh-cat-color:var(--bh-cat-2)">Rock</button>
        <button class="bh-cat-tab" style="--bh-cat-color:var(--bh-cat-3)">Electronic</button>
    </div>

    <div class="bh-tracklist">
        <div class="bh-track-row">
            <div class="bh-disc spinning" style="--bh-hue:20;"></div>
            <div class="bh-track-details">
                <div class="bh-track-title">Midnight Static</div>
                <div class="bh-track-artist">Nova Bloom</div>
            </div>
            <button class="bh-vote-btn voted" style="--bh-cat-color:var(--bh-cat-1)"><span class="bh-check">&#10003;</span> Vote</button>
        </div>
        <div class="bh-track-row">
            <div class="bh-disc" style="--bh-hue:190;"></div>
            <div class="bh-track-details">
                <div class="bh-track-title">Glass Horizon</div>
                <div class="bh-track-artist">Echo Parade</div>
            </div>
            <button class="bh-vote-btn" style="--bh-cat-color:var(--bh-cat-1)">Vote</button>
        </div>
        <div class="bh-track-row">
            <div class="bh-disc" style="--bh-hue:300;"></div>
            <div class="bh-track-details">
                <div class="bh-track-title">Paper Satellites</div>
                <div class="bh-track-artist">The Low Reply</div>
            </div>
            <button class="bh-vote-btn" style="--bh-cat-color:var(--bh-cat-1)">Vote</button>
        </div>
    </div>

    <div class="bh-now-playing-bar">
        <div class="bh-np-track">
            <div class="bh-disc bh-np-disc spinning" style="--bh-hue:20;"></div>
            <div class="bh-np-info"><strong>Midnight Static</strong><br><small>Nova Bloom</small></div>
        </div>
        <div class="bh-scrubber-container">
            <span class="bh-time bh-time-elapsed">1:12</span>
            <input type="range" class="bh-scrubber" value="38" min="0" max="100" step="0.1">
            <span class="bh-time bh-time-duration">3:04</span>
        </div>
        <button class="bh-play-pause" aria-label="Play or pause">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
        </button>
    </div>
</div>
        <?php
        return ob_get_clean();
    }

    private static function preview_body_forms($s) {
        ob_start();
        ?>
<div style="display:flex;gap:20px;flex-wrap:wrap;padding:4px;">
    <div style="flex:1;min-width:280px;">
        <div class="bh-modal-content" style="position:relative;max-width:100%;max-height:none;overflow-y:visible;">
            <span class="bh-close">&times;</span>
            <h2>Sign Up</h2>
            <input type="text" placeholder="Username">
            <input type="password" placeholder="Password">
            <input type="email" placeholder="Email (sign up only)">
            <div class="bh-reg-extra" style="display:flex;">
                <small>Optional — helps us credit you if you ever submit a track.</small>
                <div class="bh-field-row">
                    <input type="text" placeholder="Real name">
                    <label class="bh-pub-toggle"><input type="checkbox"> public</label>
                </div>
                <div class="bh-field-row">
                    <input type="text" placeholder="Discord username">
                    <label class="bh-pub-toggle"><input type="checkbox" checked> public</label>
                </div>
                <div class="bh-select-wrap">
                    <button type="button" class="bh-select-trigger"><span>Where do you usually watch?</span>
                        <svg class="bh-select-chevron" viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg>
                    </button>
                </div>
            </div>
            <button class="bh-auth-submit bh-btn bh-btn-primary">Continue</button>
            <p><a href="#">Need an account? Sign up</a></p>
        </div>
    </div>

    <div style="flex:1;min-width:280px;">
        <div class="bh-modal-content" style="position:relative;max-width:100%;max-height:none;overflow-y:visible;">
            <span class="bh-close">&times;</span>
            <h2>Submit Your Track</h2>
            <input type="text" placeholder="Song title">
            <input type="text" placeholder="Artist name">
            <textarea placeholder="Note to admins (optional)" rows="2"></textarea>
            <small>We need your real name and at least one way to reach you.</small>
            <div class="bh-field-row">
                <input type="text" placeholder="Real name" value="Nova Bloom">
                <label class="bh-pub-toggle"><input type="checkbox" checked> public</label>
            </div>
            <!-- Shown open here (rather than a separate dedicated box) so
                 both the closed state (Sign Up, left) and open state
                 (here) are each represented exactly once. -->
            <div class="bh-select-wrap open">
                <button type="button" class="bh-select-trigger"><span>YouTube</span>
                    <svg class="bh-select-chevron" viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg>
                </button>
                <div class="bh-select-menu" style="display:block;">
                    <div class="bh-select-option">Where do you usually watch?</div>
                    <div class="bh-select-option selected">YouTube</div>
                    <div class="bh-select-option">Twitch</div>
                </div>
            </div>
            <div style="height:100px;"></div>
            <label class="bh-file-label"><span>Choose an audio file…</span></label>
            <small>MP3 or M4A · Max 20MB</small>
            <button class="bh-upload-btn bh-btn bh-btn-primary">Upload</button>
        </div>
    </div>
</div>
        <?php
        return ob_get_clean();
    }

    // Rendered exactly as the real results modal is: close button, h2,
    // category tabs, then the actual <ol class="bh-results-list"> markup
    // (medal emoji for the top 3 ranks) — not a simplified stand-in.
    private static function preview_body_results($s) {
        ob_start();
        ?>
<div style="padding:4px;">
    <div class="bh-modal-content" style="position:relative;max-width:100%;max-height:none;overflow-y:visible;">
        <span class="bh-close">&times;</span>
        <h2>Results</h2>
        <div class="bh-category-tabs bh-results-tabs">
            <button class="bh-cat-tab active" style="--bh-cat-color:var(--bh-text-dim)">All</button>
            <button class="bh-cat-tab" style="--bh-cat-color:var(--bh-cat-1)">Pop</button>
            <button class="bh-cat-tab" style="--bh-cat-color:var(--bh-cat-2)">Rock</button>
        </div>
        <ol class="bh-results-list">
            <li class="bh-results-top">
                <span class="bh-results-rank">🥇</span>
                <span class="bh-results-meta">
                    <span class="bh-results-song">Midnight Static</span>
                    <span class="bh-results-artist">Nova Bloom</span>
                </span>
                <span class="bh-results-cat" style="--bh-cat-color:var(--bh-cat-1)">Pop</span>
                <span class="bh-results-votes">128 votes</span>
            </li>
            <li class="bh-results-top">
                <span class="bh-results-rank">🥈</span>
                <span class="bh-results-meta">
                    <span class="bh-results-song">Glass Horizon</span>
                    <span class="bh-results-artist">Echo Parade</span>
                </span>
                <span class="bh-results-cat" style="--bh-cat-color:var(--bh-cat-2)">Rock</span>
                <span class="bh-results-votes">96 votes</span>
            </li>
            <li>
                <span class="bh-results-rank">#3</span>
                <span class="bh-results-meta">
                    <span class="bh-results-song">Paper Satellites</span>
                    <span class="bh-results-artist">The Low Reply</span>
                </span>
                <span class="bh-results-cat" style="--bh-cat-color:var(--bh-cat-1)">Pop</span>
                <span class="bh-results-votes">54 votes</span>
            </li>
        </ol>
    </div>
</div>
        <?php
        return ob_get_clean();
    }

    private static function theme_picker($groups) {
        ?>
        <div class="bh-theme-picker">
            <select id="bh-theme-select" class="bh-theme-select">
                <option value="">Choose a theme…</option>
                <?php foreach ($groups as $group_label => $themes): ?>
                    <optgroup label="<?php echo esc_attr($group_label); ?>">
                        <?php foreach ($themes as $name => $colors): ?>
                            <option value="<?php echo esc_attr($name); ?>"
                                    data-set='<?php echo esc_attr(wp_json_encode($colors)); ?>'>
                                <?php echo esc_html($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <div class="bh-theme-swatch-preview" id="bh-theme-swatch-preview"></div>
        </div>
        <?php
    }

    // Same idea as chip_group() below, but each button also carries a
    // small rendered preview of what that step actually looks like (a
    // dot cluster for spacing, a rounded square for corners, etc.)
    // instead of asking someone to interpret "LG" or "1.25×" in the
    // abstract.
    private static function chip_group_visual($label, $chips, $s) {
        ?>
        <div class="bh-chip-group">
            <label><?php echo esc_html($label); ?></label>
            <div class="bh-chip-row bh-chip-row-visual">
                <?php foreach ($chips as $chip):
                    $is_active = true;
                    foreach ($chip['set'] as $field => $val) {
                        if ((string) ($s[$field] ?? '') !== (string) $val) { $is_active = false; break; }
                    }
                    ?>
                    <button type="button" class="bh-chip bh-chip-visual<?php echo $is_active ? ' active' : ''; ?>"
                            data-set='<?php echo esc_attr(wp_json_encode($chip['set'])); ?>'>
                        <span class="bh-chip-swatch"><?php echo $chip['swatch']; ?></span>
                        <span class="bh-chip-vlabel"><?php echo esc_html($chip['label']); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    // Three dots whose gap grows with the spacing step — a direct,
    // literal preview of "this is what more/less space looks like"
    // rather than an abstract multiplier number.
    private static function swatch_dots($gap) {
        $dot = '<span style="width:5px;height:5px;border-radius:50%;background:#6b7280;display:inline-block;"></span>';
        return '<span style="display:flex;align-items:center;gap:' . (int) $gap . 'px;">' . str_repeat($dot, 3) . '</span>';
    }

    // A rounded square at the actual corner radius (capped so "Full"
    // doesn't just become a circle in a tiny box and stop reading as a
    // radius at all).
    private static function swatch_radius($radius_px) {
        return '<span style="display:block;width:24px;height:24px;background:#6b7280;border-radius:' . (int) $radius_px . 'px;"></span>';
    }

    // A horizontal bar whose height stands in for the now-playing bar's
    // own height, at a fixed reduced scale so all four steps fit
    // comfortably in the row.
    private static function swatch_bar($height_px) {
        return '<span style="display:block;width:28px;height:' . (int) $height_px . 'px;background:#6b7280;border-radius:2px;"></span>';
    }

    // A filled circle at the actual preview diameter — same idea as the
    // radius swatch, direct rather than abstract.
    private static function swatch_circle($diameter_px) {
        return '<span style="display:block;width:' . (int) $diameter_px . 'px;height:' . (int) $diameter_px . 'px;border-radius:50%;background:#6b7280;"></span>';
    }

    // "Aa" set at the actual relative font size for that step.
    private static function swatch_text($font_px) {
        return '<span style="font-size:' . (int) $font_px . 'px;font-weight:700;color:#6b7280;line-height:1;">Aa</span>';
    }

    private static function font_field($key, $label, $s) {
        $picked = $s[$key];
        $is_custom = !array_key_exists($picked, self::FONT_OPTIONS);
        ?>
        <div class="bh-font-field">
            <label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
            <select id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" data-custom-target="<?php echo esc_attr($key); ?>_custom">
                <?php foreach (self::FONT_OPTIONS as $name => $param): ?>
                    <option value="<?php echo esc_attr($name); ?>" <?php selected($picked, $name); ?>><?php echo esc_html($name); ?></option>
                <?php endforeach; ?>
                <option value="Custom" <?php selected($is_custom, true); ?>>Custom…</option>
            </select>
            <input type="text" id="<?php echo esc_attr($key); ?>_custom" name="<?php echo esc_attr($key); ?>_custom"
                   placeholder="e.g. Georgia, serif" value="<?php echo esc_attr($s[$key . '_custom']); ?>"
                   style="<?php echo $is_custom ? '' : 'display:none;'; ?>">
        </div>
        <?php
    }

    private static function slider_row($key, $label, $s, $min, $max, $step, $unit) {
        ?>
        <div class="bh-slider-row">
            <label for="<?php echo esc_attr($key); ?>">
                <span><?php echo esc_html($label); ?></span>
                <span class="bh-slider-val" id="<?php echo esc_attr($key); ?>_val"><?php echo esc_html($s[$key] . $unit); ?></span>
            </label>
            <input type="range" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>"
                   min="<?php echo esc_attr($min); ?>" max="<?php echo esc_attr($max); ?>" step="<?php echo esc_attr($step); ?>"
                   value="<?php echo esc_attr($s[$key]); ?>" data-unit="<?php echo esc_attr($unit); ?>">
        </div>
        <?php
    }

    // Shared by Settings & Style and the per-contest style metabox, so a
    // contest's override fields look and behave identically to the
    // site-wide ones — not a parallel, lower-fidelity implementation.
    public static function swatch_css() {
        return '
            .bh-swatch-card { border: 1px solid #dcdcde; border-radius: 6px; padding: 8px; display: flex; gap: 10px; align-items: center; }
            .bh-swatch {
                width: 32px; height: 32px; border-radius: 6px; flex: 0 0 auto; border: 1px solid #dcdcde;
                background-image: linear-gradient(45deg, #ccc 25%, transparent 25%), linear-gradient(-45deg, #ccc 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #ccc 75%), linear-gradient(-45deg, transparent 75%, #ccc 75%);
                background-size: 10px 10px; background-position: 0 0, 0 5px, 5px -5px, -5px 0;
            }
            .bh-swatch-body { flex: 1; min-width: 0; }
            .bh-swatch-body label { display: block; font-weight: 600; font-size: 11px; margin-bottom: 3px; }
            .bh-swatch-controls { display: flex; gap: 5px; align-items: center; }
            .bh-swatch-controls input[type=text] { width: 100%; font-size: 12px; padding: 3px 6px; }
            .bh-swatch-controls input[type=color] { width: 24px; height: 24px; padding: 0; border: 1px solid #dcdcde; cursor: pointer; }
            .bh-transparent-btn { font-size: 10px; padding: 2px 6px; border-radius: 4px; border: 1px solid #dcdcde; background: #f6f7f7; cursor: pointer; white-space: nowrap; }
            .bh-transparent-btn:hover { background: #eee; }
        ';
    }

    // Same swatch+text+picker UI as color_row() below, but with fully
    // generic id/name/value — usable anywhere, not just on fields shaped
    // like the site-wide settings array. $placeholder shows (as both the
    // input's placeholder text and, when $value is blank, the swatch's
    // own preview color) whatever the effective fallback is — e.g. a
    // per-contest override field that's blank previews the site-wide
    // default it'll actually fall back to, rather than showing nothing.
    public static function swatch_field($id, $name, $label, $value, $placeholder = '', $extra_html = '') {
        $display = $value !== '' ? $value : $placeholder;
        ?>
        <div class="bh-swatch-card">
            <div class="bh-swatch" id="bh-swatch-<?php echo esc_attr($id); ?>" style="background:<?php echo esc_attr($display ?: '#f6f7f7'); ?>"></div>
            <div class="bh-swatch-body">
                <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
                <div class="bh-swatch-controls">
                    <input type="text" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>"
                           value="<?php echo esc_attr($value); ?>" placeholder="<?php echo esc_attr($placeholder); ?>" data-key="<?php echo esc_attr($id); ?>">
                    <input type="color" id="bh-picker-<?php echo esc_attr($id); ?>"
                           value="<?php echo esc_attr(strlen($display) === 7 && $display[0] === '#' ? $display : '#000000'); ?>" tabindex="-1">
                    <?php echo $extra_html; ?>
                </div>
            </div>
        </div>
        <?php
    }

    // JS that wires up any .bh-swatch-controls text input on the page to
    // its paired swatch preview + color-picker dropper — generic over
    // however many fields are present, so both Settings & Style (many
    // fields, plus a live preview refresh) and the contest metabox (a
    // handful of fields, no live preview) can each call this and layer
    // their own extra behavior via $on_sync_js if they need to.
    public static function swatch_js($on_sync_js = '') {
        return "
        (function () {
            function isValidCssColor(v) {
                var s = new Option().style;
                s.color = '';
                s.color = v;
                return s.color !== '';
            }
            document.querySelectorAll('.bh-swatch-controls input[type=text]').forEach(function (input) {
                var key = input.dataset.key;
                var swatch = document.getElementById('bh-swatch-' + key);
                var picker = document.getElementById('bh-picker-' + key);
                function sync() {
                    var v = input.value.trim() || input.placeholder;
                    if (v && isValidCssColor(v)) swatch.style.background = v;
                    $on_sync_js
                }
                input.addEventListener('input', sync);
                if (picker) picker.addEventListener('input', function () { input.value = picker.value; sync(); });
            });
        })();
        ";
    }

    private static function color_row($key, $label, $s, $allow_transparent_button = false) {
        $extra = $allow_transparent_button ? '<button type="button" class="bh-transparent-btn" id="bh-set-transparent">None</button>' : '';
        self::swatch_field($key, $key, $label, $s[$key], '', $extra);
    }
}
