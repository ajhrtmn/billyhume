<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared social-share image generator (PHP GD, no external service), 1200x630
 * OG size. 'brand' uses the live --bh-* palette; 'poster-*' styles use fixed
 * high-contrast colors. STYLES is the one place a style is registered.
 */
class BH_ShareCard {
    const WIDTH = 1200;
    const HEIGHT = 630;

    // key => label, in the order a picker UI should list them.
    const STYLES = [
        'brand' => 'Brand (matches site colors)',
        'poster' => 'Poster — Diagonal',
        'poster-frame' => 'Poster — Framed',
        'poster-block' => 'Poster — Color Block',
    ];

    public static function is_valid_style(string $style): bool {
        return isset(self::STYLES[$style]);
    }

    private static function font(string $name): string {
        return OUS_PATH . 'assets/fonts/' . $name;
    }

    // Corner wordmark: the live site name.
    private static function site_mark(): string {
        return mb_strtoupper(get_bloginfo('name'));
    }

    // WHY: hosts whose GD lacks FreeType fatal or warn inside imagettftext(),
    // corrupting the PNG body. output_png() checks this and falls back to a plain card.
    private static function gd_capable(): bool {
        if (!function_exists('imagettftext') || !function_exists('imagettfbbox')) return false;
        $info = function_exists('gd_info') ? gd_info() : [];
        if (empty($info['FreeType Support'])) return false;
        return is_readable(self::font('WorkSans-Variable.ttf')) && is_readable(self::font('BebasNeue-Regular.ttf'));
    }

    // No text rendering, so it cannot hit the FreeType path. Still on-brand.
    /**
     * @param mixed $entity_id
     * @return \GdImage
     */
    private static function render_fallback($entity_id) {
        $s = class_exists('BH_Style') ? BH_Style::get($entity_id) : [];
        $bg = $s['color_bg'] ?? '#170807';
        $accent = $s['color_accent'] ?? '#C1503A';
        $im = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefilledrectangle($im, 0, 0, self::WIDTH, self::HEIGHT, self::gd_color($im, $bg));
        imagefilledrectangle($im, 0, self::HEIGHT - 14, self::WIDTH, self::HEIGHT, self::gd_color($im, $accent));
        return $im;
    }

    // "#RRGGBB" or "#RRGGBBAA" -> GD color index. The alpha byte is dropped;
    // every caller wants a solid fill.
    /**
     * @param \GdImage $im
     * @return int|false
     */
    private static function gd_color($im, string $hex) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) >= 6) {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            return imagecolorallocate($im, $r, $g, $b);
        }
        return imagecolorallocate($im, 255, 255, 255);
    }

    // Greedy word-wrap measured with imagettfbbox; fine for short titles.
    /** @return array<int, string> */
    private static function wrap_text(string $text, string $font_path, int $size, int $max_width): array {
        $words = preg_split('/\s+/', trim($text));
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $test = $current === '' ? $word : $current . ' ' . $word;
            $box = imagettfbbox($size, 0, $font_path, $test);
            $width = $box[2] - $box[0];
            if ($width > $max_width && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $test;
            }
        }
        if ($current !== '') $lines[] = $current;
        return $lines;
    }

    /**
     * $args:
     *   style     'brand' (default) or a 'poster*' style
     *   eyebrow   small uppercase label above the title, e.g. "COURSE COMPLETE"
     *   title     the headline
     *   subtitle  smaller line under the title
     *   entity_id optional post ID; 'brand' uses that entity's style override
     * Caller must imagepng()/imagedestroy() the returned image.
     */
    /**
     * @param array<string, mixed> $args
     * @return \GdImage
     */
    public static function generate(array $args) {
        $style = self::is_valid_style($args['style'] ?? '') ? $args['style'] : 'brand';
        $eyebrow = (string) ($args['eyebrow'] ?? '');
        $title = (string) ($args['title'] ?? '');
        $subtitle = (string) ($args['subtitle'] ?? '');
        $entity_id = $args['entity_id'] ?? null;

        switch ($style) {
            case 'poster': return self::render_poster($eyebrow, $title, $subtitle);
            case 'poster-frame': return self::render_poster_frame($eyebrow, $title, $subtitle);
            case 'poster-block': return self::render_poster_block($eyebrow, $title, $subtitle);
            default: return self::render_brand($eyebrow, $title, $subtitle, $entity_id);
        }
    }

    /* ---------------- brand style ----------------
       Reads the live site palette so the card matches the active theme. */
    /**
     * @param mixed $entity_id
     * @return \GdImage
     */
    private static function render_brand(string $eyebrow, string $title, string $subtitle, $entity_id) {
        $s = class_exists('BH_Style') ? BH_Style::get($entity_id) : [];
        $bg = $s['color_bg'] ?? '#170807';
        $accent = $s['color_accent'] ?? '#C1503A';
        $accent_soft = $s['color_accent_soft'] ?? '#E0A184';

        $im = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($im, true);
        imagefilledrectangle($im, 0, 0, self::WIDTH, self::HEIGHT, self::gd_color($im, $bg));

        // A thin accent rule along the top.
        imagefilledrectangle($im, 0, 0, self::WIDTH, 10, self::gd_color($im, $accent));

        $body_font = self::font('WorkSans-Variable.ttf');
        $white = self::gd_color($im, '#ffffff');
        $accent_color = self::gd_color($im, $accent_soft);

        $x = 80;
        $y = 220;

        if ($eyebrow !== '') {
            imagettftext($im, 22, 0, $x, $y, $accent_color, $body_font, mb_strtoupper($eyebrow));
            $y += 60;
        }

        // Faux-bold: GD cannot pick a weight from a variable font, so glyphs are
        // drawn twice, offset by one pixel.
        $title_lines = self::wrap_text($title, $body_font, 54, self::WIDTH - ($x * 2));
        foreach (array_slice($title_lines, 0, 3) as $line) {
            imagettftext($im, 54, 0, $x + 1, $y, $white, $body_font, $line);
            imagettftext($im, 54, 0, $x, $y, $white, $body_font, $line);
            $y += 66;
        }

        if ($subtitle !== '') {
            $y += 14;
            imagettftext($im, 26, 0, $x, $y, $accent_color, $body_font, $subtitle);
        }

        // Small wordmark, bottom-right.
        $mark = self::site_mark();
        $box = imagettfbbox(18, 0, $body_font, $mark);
        $mark_width = $box[2] - $box[0];
        imagettftext($im, 18, 0, self::WIDTH - 80 - $mark_width, self::HEIGHT - 50, $accent_color, $body_font, $mark);

        return $im;
    }

    /* ---------------- poster style ----------------
       Bebas Neue on a diagonal accent band, fixed colors independent of the
       site palette. */
    /** @return \GdImage */
    private static function render_poster(string $eyebrow, string $title, string $subtitle) {
        $im = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefilledrectangle($im, 0, 0, self::WIDTH, self::HEIGHT, self::gd_color($im, '#0B0B0E'));

        // Diagonal band as a filled polygon (GD has no rotated rectangle).
        $accent = self::gd_color($im, '#FF5A36');
        imagefilledpolygon($im, [
            0, self::HEIGHT,
            self::WIDTH, 120,
            self::WIDTH, 320,
            0, self::HEIGHT,
        ], $accent);

        $display_font = self::font('BebasNeue-Regular.ttf');
        $body_font = self::font('WorkSans-Variable.ttf');
        $white = self::gd_color($im, '#ffffff');
        $dark = self::gd_color($im, '#0B0B0E');

        $x = 80;
        $y = 100;

        if ($eyebrow !== '') {
            imagettftext($im, 24, 0, $x, $y, $accent, $body_font, mb_strtoupper($eyebrow));
            $y += 100; // real clearance before the 90px display type below — Bebas Neue's tall ascent at that size needs more headroom than the eyebrow's own line-height suggests
        }

        // Bebas Neue is already bold; no faux-bold pass needed.
        $title_lines = self::wrap_text(mb_strtoupper($title), $display_font, 90, self::WIDTH - ($x * 2));
        foreach (array_slice($title_lines, 0, 2) as $line) {
            imagettftext($im, 90, 0, $x, $y, $white, $display_font, $line);
            $y += 92;
        }

        if ($subtitle !== '') {
            $y += 20;
            imagettftext($im, 28, 0, $x, $y, $white, $body_font, $subtitle);
        }

        // White: this corner sits on the dark background, outside the band.
        $mark = self::site_mark();
        $box = imagettfbbox(20, 0, $body_font, $mark);
        $mark_width = $box[2] - $box[0];
        imagettftext($im, 20, 0, self::WIDTH - 80 - $mark_width, self::HEIGHT - 45, $white, $body_font, $mark);

        return $im;
    }

    /* ---------------- poster-frame style ----------------
       Inset border, centered type, corner registration-style tick marks. */
    /** @return \GdImage */
    private static function render_poster_frame(string $eyebrow, string $title, string $subtitle) {
        $im = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $bg = self::gd_color($im, '#161311');
        $cream = self::gd_color($im, '#F3E9DC');
        $accent = self::gd_color($im, '#E8A33D');
        imagefilledrectangle($im, 0, 0, self::WIDTH, self::HEIGHT, $bg);

        $margin = 44;
        imagesetthickness($im, 2);
        imagerectangle($im, $margin, $margin, self::WIDTH - $margin, self::HEIGHT - $margin, $accent);

        // Decorative corner tick marks just outside the frame.
        $tick = 16;
        foreach ([[$margin, $margin], [self::WIDTH - $margin, $margin], [$margin, self::HEIGHT - $margin], [self::WIDTH - $margin, self::HEIGHT - $margin]] as $corner) {
            [$cx, $cy] = $corner;
            imageline($im, $cx - $tick, $cy, $cx + $tick, $cy, $accent);
            imageline($im, $cx, $cy - $tick, $cx, $cy + $tick, $accent);
        }

        $display_font = self::font('BebasNeue-Regular.ttf');
        $body_font = self::font('WorkSans-Variable.ttf');
        $inner_width = self::WIDTH - ($margin + 60) * 2;
        $y = 200;

        if ($eyebrow !== '') {
            $text = mb_strtoupper($eyebrow);
            $box = imagettfbbox(22, 0, $body_font, $text);
            $tw = $box[2] - $box[0];
            imagettftext($im, 22, 0, (int) ((self::WIDTH - $tw) / 2), $y, $accent, $body_font, $text);
            $y += 80;
        }

        $title_lines = self::wrap_text(mb_strtoupper($title), $display_font, 80, $inner_width);
        foreach (array_slice($title_lines, 0, 2) as $line) {
            $box = imagettfbbox(80, 0, $display_font, $line);
            $tw = $box[2] - $box[0];
            imagettftext($im, 80, 0, (int) ((self::WIDTH - $tw) / 2), $y, $cream, $display_font, $line);
            $y += 82;
        }

        if ($subtitle !== '') {
            $y += 24;
            $box = imagettfbbox(24, 0, $body_font, $subtitle);
            $tw = $box[2] - $box[0];
            imagettftext($im, 24, 0, (int) ((self::WIDTH - $tw) / 2), $y, $accent, $body_font, $subtitle);
        }

        $mark = self::site_mark();
        $box = imagettfbbox(16, 0, $body_font, $mark);
        $mw = $box[2] - $box[0];
        imagettftext($im, 16, 0, (int) ((self::WIDTH - $mw) / 2), self::HEIGHT - $margin - 22, $cream, $body_font, $mark);

        return $im;
    }

    /* ---------------- poster-block style ----------------
       Solid color block on the left third with reversed type; left-aligned. */
    /** @return \GdImage */
    private static function render_poster_block(string $eyebrow, string $title, string $subtitle) {
        $im = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $dark = self::gd_color($im, '#0E1116');
        $block = self::gd_color($im, '#3D5AFE');
        $white = self::gd_color($im, '#ffffff');
        imagefilledrectangle($im, 0, 0, self::WIDTH, self::HEIGHT, $dark);

        $block_width = 360;
        imagefilledrectangle($im, 0, 0, $block_width, self::HEIGHT, $block);

        $display_font = self::font('BebasNeue-Regular.ttf');
        $body_font = self::font('WorkSans-Variable.ttf');

        // Eyebrow sits inside the block in reversed color.
        if ($eyebrow !== '') {
            imagettftext($im, 20, 0, 44, 100, $dark, $body_font, mb_strtoupper($eyebrow));
        }
        // Large monogram of the title's first character.
        $initial = mb_strtoupper(mb_substr(trim($title), 0, 1)) ?: '#';
        imagettftext($im, 180, 0, 44, 420, $white, $display_font, $initial);

        $x = $block_width + 60;
        $y = 220;
        $title_lines = self::wrap_text($title, $body_font, 52, self::WIDTH - $x - 60);
        foreach (array_slice($title_lines, 0, 3) as $line) {
            imagettftext($im, 52, 0, $x + 1, $y, $white, $body_font, $line);
            imagettftext($im, 52, 0, $x, $y, $white, $body_font, $line);
            $y += 64;
        }
        if ($subtitle !== '') {
            $y += 16;
            imagettftext($im, 24, 0, $x, $y, self::gd_color($im, '#9AA5C0'), $body_font, $subtitle);
        }

        $mark = self::site_mark();
        $mbox = imagettfbbox(16, 0, $body_font, $mark);
        $mw = $mbox[2] - $mbox[0];
        imagettftext($im, 16, 0, self::WIDTH - 44 - $mw, self::HEIGHT - 40, self::gd_color($im, '#9AA5C0'), $body_font, $mark);

        return $im;
    }

    // Streams the card as a PNG response and exits; the shared serving half of
    // the "?xyz_share_card=ID" pattern consuming plugins use.
    /** @param array<string, mixed> $args */
    public static function output_png(array $args, string $filename = 'share-card.png'): void {
        if (self::gd_capable()) {
            try {
                $im = self::generate($args);
            } catch (\Throwable $e) {
                if (class_exists('OUS_DebugLog')) OUS_DebugLog::log('warning', 'share_card: falling back to plain card after generate() threw: ' . $e->getMessage(), [], 'share_card');
                $im = self::render_fallback($args['entity_id'] ?? null);
            }
        } else {
            if (class_exists('OUS_DebugLog')) OUS_DebugLog::log('warning', 'share_card: GD build lacks FreeType/TTF support — serving plain fallback card, no text.', [], 'share_card');
            $im = self::render_fallback($args['entity_id'] ?? null);
        }
        header('Content-Type: image/png');
        header('Content-Disposition: inline; filename="' . sanitize_file_name($filename) . '"');
        header('Cache-Control: public, max-age=3600');
        imagepng($im);
        imagedestroy($im);
        exit;
    }
}
