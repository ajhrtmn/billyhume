<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared social-share image generator (PHP GD, no external service or
 * headless-browser dependency) — one implementation reused by any
 * plugin that wants a "shareable moment" card (bh-courses: course
 * completion; bh-contest: submission entered / vote-for-me), instead
 * of each plugin rolling its own GD code. 'brand' reads the site's own
 * live --bh-* palette (BH_Style::get()), for a card that looks like it
 * belongs next to everything else this ecosystem renders; the
 * 'poster-*' styles are deliberately bolder, stand-alone treatments
 * (fixed high-contrast colors, not the live site palette) for when a
 * plugin/entity wants something louder than the site's own chrome —
 * three distinct compositions today (diagonal band, bordered frame,
 * color block), not three names for the same layout.
 *
 * STYLES is the one place a new style gets registered — every caller
 * (admin style-picker UIs in bh-courses/bh-contest, this class's own
 * dispatch) reads off this list rather than each hardcoding its own
 * copy of "which styles exist," so adding a future style (e.g. a
 * custom-logo/custom-asset-driven one) is a one-place change.
 *
 * Standard 1200x630 OG-image size throughout — the one dimension every
 * major platform (X/Twitter, Facebook, Discord, iMessage) actually
 * renders a link-preview image at without cropping oddly.
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

    public static function is_valid_style($style) {
        return isset(self::STYLES[$style]);
    }

    private static function font($name) {
        return OUS_PATH . 'assets/fonts/' . $name;
    }

    // Hex string ("#RRGGBB" or "#RRGGBBAA") -> GD color index on $im.
    // The 8-digit form (BH_Style's own color_overlay values) drops the
    // alpha byte — GD's alpha model (0-127, inverted) doesn't map
    // cleanly onto a straight hex alpha byte, and every call site here
    // wants a solid fill, never a translucent one.
    private static function gd_color($im, $hex) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) >= 6) {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            return imagecolorallocate($im, $r, $g, $b);
        }
        return imagecolorallocate($im, 255, 255, 255);
    }

    // Naive greedy word-wrap using imagettfbbox for real measured width
    // (not a fixed char-count guess, which a variable-width font like
    // Work Sans would make look wrong) — good enough for the short
    // titles/labels these cards render, not a general typesetting engine.
    private static function wrap_text($text, $font_path, $size, $max_width) {
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
     *   style     'brand' (default) or 'poster'
     *   eyebrow   small uppercase label above the title, e.g. "COURSE COMPLETE"
     *   title     the headline — a course/contest/submission name
     *   subtitle  smaller line under the title, e.g. an artist name or contest name
     *   entity_id optional post ID — resolves 'brand' style to that entity's own style override if it has one
     * Returns a GdImage (PHP 8) / gd resource, caller's responsibility
     * to imagepng()/imagedestroy() it.
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
       Reads the site's live palette so the card visually matches
       whatever theme/preset is actually active (dark by default, but
       BH_Style has 10+ presets an admin can be running) — never a
       hardcoded color set that could drift from the real site. */
    private static function render_brand($eyebrow, $title, $subtitle, $entity_id) {
        $s = class_exists('BH_Style') ? BH_Style::get($entity_id) : [];
        $bg = $s['color_bg'] ?? '#170807';
        $accent = $s['color_accent'] ?? '#C1503A';
        $accent_soft = $s['color_accent_soft'] ?? '#E0A184';

        $im = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($im, true);
        imagefilledrectangle($im, 0, 0, self::WIDTH, self::HEIGHT, self::gd_color($im, $bg));

        // A single thin accent rule along the top — the whole "brand"
        // restraint this style is for, vs. poster's much louder
        // diagonal band.
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

        // Faux-bold: GD/FreeType can't select a named weight out of a
        // variable font, so a real headline weight is simulated by
        // drawing the same glyphs twice, offset by one device pixel —
        // a standard GD workaround, not a rendering bug.
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

        // Small wordmark, bottom-right — identifies which site/brand
        // this came from once it's out in a social feed on its own,
        // detached from any surrounding page chrome.
        $mark = 'OWN UR SHIT';
        $box = imagettfbbox(18, 0, $body_font, $mark);
        $mark_width = $box[2] - $box[0];
        imagettftext($im, 18, 0, self::WIDTH - 80 - $mark_width, self::HEIGHT - 50, $accent_color, $body_font, $mark);

        return $im;
    }

    /* ---------------- poster style ----------------
       Deliberately louder and self-contained — condensed all-caps
       display type (Bebas Neue) on a big diagonal accent band, not
       reading the site's own live palette (a poster is meant to stand
       apart from the page it's embedded in, same reasoning a gig
       poster doesn't try to match the venue's website). Colors are a
       fixed high-contrast pair rather than BH_Style's palette so this
       style always looks the same regardless of which of BH_Style's
       10+ presets happens to be active. */
    private static function render_poster($eyebrow, $title, $subtitle) {
        $im = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefilledrectangle($im, 0, 0, self::WIDTH, self::HEIGHT, self::gd_color($im, '#0B0B0E'));

        // The diagonal band: a filled polygon, not a rotated rectangle
        // (GD has no native rotated-rect primitive) — four points
        // sweeping from the lower-left up to the upper-right.
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

        // Bebas Neue is already a bold condensed display face at any
        // size, so no faux-bold pass is needed here the way the
        // Work-Sans-based brand style needs one.
        $title_lines = self::wrap_text(mb_strtoupper($title), $display_font, 90, self::WIDTH - ($x * 2));
        foreach (array_slice($title_lines, 0, 2) as $line) {
            imagettftext($im, 90, 0, $x, $y, $white, $display_font, $line);
            $y += 92;
        }

        if ($subtitle !== '') {
            $y += 20;
            imagettftext($im, 28, 0, $x, $y, $white, $body_font, $subtitle);
        }

        // White, not $dark — the bottom-right corner is where the
        // diagonal band's polygon does NOT reach (it sweeps from
        // bottom-left up to the top-right band only), so this sits on
        // the plain dark background, not the accent band; $dark here
        // would be invisible against that near-black background.
        $mark = 'OWN UR SHIT';
        $box = imagettfbbox(20, 0, $body_font, $mark);
        $mark_width = $box[2] - $box[0];
        imagettftext($im, 20, 0, self::WIDTH - 80 - $mark_width, self::HEIGHT - 45, $white, $body_font, $mark);

        return $im;
    }

    /* ---------------- poster-frame style ----------------
       A concert-poster inset border with centered type — a genuinely
       different composition from the diagonal-band 'poster' (centered
       vs. left-aligned, a contained border vs. a bleeding shape,
       cream-on-near-black vs. white-on-near-black), not a recolor of
       the same layout. Corner tick marks are the one decorative flourish,
       echoing a printed poster's own registration/crop marks. */
    private static function render_poster_frame($eyebrow, $title, $subtitle) {
        $im = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $bg = self::gd_color($im, '#161311');
        $cream = self::gd_color($im, '#F3E9DC');
        $accent = self::gd_color($im, '#E8A33D');
        imagefilledrectangle($im, 0, 0, self::WIDTH, self::HEIGHT, $bg);

        $margin = 44;
        imagesetthickness($im, 2);
        imagerectangle($im, $margin, $margin, self::WIDTH - $margin, self::HEIGHT - $margin, $accent);

        // Corner tick marks, just outside the frame — a printed-poster
        // registration-mark reference, purely decorative.
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

        $mark = 'OWN UR SHIT';
        $box = imagettfbbox(16, 0, $body_font, $mark);
        $mw = $box[2] - $box[0];
        imagettftext($im, 16, 0, (int) ((self::WIDTH - $mw) / 2), self::HEIGHT - $margin - 22, $cream, $body_font, $mark);

        return $im;
    }

    /* ---------------- poster-block style ----------------
       A solid color block on the left third with reversed (dark-on-
       accent) type, title continuing onto the dark right two-thirds —
       the "album-art tile" composition: big flat color shape as the
       dominant visual weight, rather than a line/border doing the work
       'poster'/'poster-frame' use. Left-aligned throughout, unlike
       poster-frame's centered layout. */
    private static function render_poster_block($eyebrow, $title, $subtitle) {
        $im = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $dark = self::gd_color($im, '#0E1116');
        $block = self::gd_color($im, '#3D5AFE');
        $white = self::gd_color($im, '#ffffff');
        imagefilledrectangle($im, 0, 0, self::WIDTH, self::HEIGHT, $dark);

        $block_width = 360;
        imagefilledrectangle($im, 0, 0, $block_width, self::HEIGHT, $block);

        $display_font = self::font('BebasNeue-Regular.ttf');
        $body_font = self::font('WorkSans-Variable.ttf');

        // Eyebrow lives INSIDE the block, reversed color — the block
        // itself functions as a tag/label, not just a background shape.
        if ($eyebrow !== '') {
            imagettftext($im, 20, 0, 44, 100, $dark, $body_font, mb_strtoupper($eyebrow));
        }
        // A big single-letter monogram (the title's first character) —
        // the block's dominant visual element, same "one bold graphic
        // mark" reasoning album-art tiles/app icons lean on, filling
        // space a plain color rectangle would otherwise leave empty.
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

        $mark = 'OWN UR SHIT';
        $mbox = imagettfbbox(16, 0, $body_font, $mark);
        $mw = $mbox[2] - $mbox[0];
        imagettftext($im, 16, 0, self::WIDTH - 44 - $mw, self::HEIGHT - 40, self::gd_color($im, '#9AA5C0'), $body_font, $mark);

        return $im;
    }

    // Streams the generated card as a PNG response and exits — the
    // shared serving half of the "?xyz_share_card=ID" query-arg pattern
    // both consuming plugins use (same shape as bh-courses' existing
    // certificate download endpoint), so neither plugin needs to
    // duplicate cache-header/content-type/imagepng-then-exit
    // boilerplate.
    public static function output_png(array $args, $filename = 'share-card.png') {
        $im = self::generate($args);
        header('Content-Type: image/png');
        header('Content-Disposition: inline; filename="' . sanitize_file_name($filename) . '"');
        header('Cache-Control: public, max-age=3600');
        imagepng($im);
        imagedestroy($im);
        exit;
    }
}
