<?php
if (!defined('ABSPATH')) exit;

/**
 * Framework-agnostic colour math. No WordPress calls, no options, no
 * globals — every method is pure (same input, same output) so this file
 * can be lifted out of the plugin wholesale into a standalone package
 * later, with BHY_Style / BHY_Contrast as the only WP-aware adapters on
 * top of it.
 *
 * Three metrics live here and they are NOT interchangeable:
 *   - wcag_ratio()  : WCAG 2.x contrast ratio 1..21, piecewise-linear
 *                     luminance. The compliance number the audit reports.
 *   - apca_lc()     : APCA-style perceptual lightness contrast, signed
 *                     roughly -108..106, simple 2.4-power luminance. The
 *                     number BHY_Contrast's resolver actually decides on.
 *   - OKLCH         : perceptual colour space for GENERATING colours
 *                     (hold hue + chroma, move lightness until a metric
 *                     target is met), not for scoring them.
 *
 * The APCA implementation follows the published APCA-W3 0.1.9 "G-4g"
 * constants. It is deliberately called "apca_lc" / "perceptual contrast"
 * rather than claiming APCA conformance — WCAG 2.x stays the number we
 * report for accessibility compliance.
 */
class BHY_Color {

    // ---- hex <-> rgb -------------------------------------------------

    /**
     * "#abc", "#aabbcc", "#aabbccdd" -> [r, g, b] as floats 0..255.
     * Alpha is parsed but dropped: every metric here is defined on
     * opaque colours, and the resolver only ever deals in opaque roles.
     * Anything unparseable returns black — same fail-safe posture as
     * BHY_Style::safe_color().
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public static function hex_to_rgb(string $hex): array {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3 || strlen($hex) === 4) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) < 6 || !ctype_xdigit(substr($hex, 0, 6))) {
            return [0.0, 0.0, 0.0];
        }
        return [
            (float) hexdec(substr($hex, 0, 2)),
            (float) hexdec(substr($hex, 2, 2)),
            (float) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $rgb
     */
    public static function rgb_to_hex(array $rgb): string {
        $out = '#';
        foreach ($rgb as $c) {
            $v = (int) round(max(0.0, min(255.0, $c)));
            $out .= str_pad(dechex($v), 2, '0', STR_PAD_LEFT);
        }
        return $out;
    }

    /** True when $val is a plain #hex this math can actually consume. */
    public static function is_hex(string $val): bool {
        return (bool) preg_match('/^#?[0-9a-fA-F]{3,8}$/', trim($val));
    }

    // ---- WCAG 2.x -------------------------------------------------

    /**
     * WCAG relative luminance. Same formula BHY_Style used before this
     * class existed and the same one tests/ux/audit.ts measures with, so
     * a value chosen against this metric is the value the audit scores.
     *
     * @param array{0: float, 1: float, 2: float} $rgb
     */
    public static function wcag_luminance(array $rgb): float {
        $ch = [];
        foreach ($rgb as $c) {
            $v = $c / 255;
            $ch[] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $ch[0] + 0.7152 * $ch[1] + 0.0722 * $ch[2];
    }

    /** WCAG contrast ratio, 1.0 .. 21.0. Order of arguments doesn't matter. */
    public static function wcag_ratio(string $hex_a, string $hex_b): float {
        $la = self::wcag_luminance(self::hex_to_rgb($hex_a));
        $lb = self::wcag_luminance(self::hex_to_rgb($hex_b));
        $hi = max($la, $lb);
        $lo = min($la, $lb);
        return ($hi + 0.05) / ($lo + 0.05);
    }

    // ---- APCA-style perceptual contrast --------------------------

    // APCA-W3 0.1.9 "G-4g" constants.
    private const APCA_R = 0.2126729;
    private const APCA_G = 0.7151522;
    private const APCA_B = 0.0721750;
    private const APCA_MAIN_TRC = 2.4;
    private const APCA_NORM_BG = 0.56;
    private const APCA_NORM_TXT = 0.57;
    private const APCA_REV_TXT = 0.62;
    private const APCA_REV_BG = 0.65;
    private const APCA_BLK_THRS = 0.022;
    private const APCA_BLK_CLMP = 1.414;
    private const APCA_SCALE = 1.14;
    private const APCA_LO_OFFSET = 0.027;
    private const APCA_LO_CLIP = 0.1;
    private const APCA_DELTA_Y_MIN = 0.0005;

    /**
     * APCA screen luminance Y — note this is the plain 2.4-power curve,
     * NOT the WCAG piecewise linearisation above. Keeping the two curves
     * separate is the whole point; do not "unify" them.
     *
     * @param array{0: float, 1: float, 2: float} $rgb
     */
    public static function apca_y(array $rgb): float {
        $r = pow($rgb[0] / 255, self::APCA_MAIN_TRC);
        $g = pow($rgb[1] / 255, self::APCA_MAIN_TRC);
        $b = pow($rgb[2] / 255, self::APCA_MAIN_TRC);
        return self::APCA_R * $r + self::APCA_G * $g + self::APCA_B * $b;
    }

    /**
     * Signed APCA lightness contrast (Lc). Positive = dark text on light
     * background (normal polarity), negative = light text on dark. The
     * resolver compares abs() of this to a target; the sign tells the
     * audit which polarity a pairing is.
     */
    public static function apca_lc(string $text_hex, string $bg_hex): float {
        $txt_y = self::apca_y(self::hex_to_rgb($text_hex));
        $bg_y  = self::apca_y(self::hex_to_rgb($bg_hex));

        // Soft-clamp very dark values so near-black pairs don't over-report.
        $txt_y = $txt_y > self::APCA_BLK_THRS
            ? $txt_y
            : $txt_y + pow(self::APCA_BLK_THRS - $txt_y, self::APCA_BLK_CLMP);
        $bg_y = $bg_y > self::APCA_BLK_THRS
            ? $bg_y
            : $bg_y + pow(self::APCA_BLK_THRS - $bg_y, self::APCA_BLK_CLMP);

        if (abs($bg_y - $txt_y) < self::APCA_DELTA_Y_MIN) {
            return 0.0;
        }

        if ($bg_y > $txt_y) {
            $sapc = (pow($bg_y, self::APCA_NORM_BG) - pow($txt_y, self::APCA_NORM_TXT)) * self::APCA_SCALE;
            $out = $sapc < self::APCA_LO_CLIP ? 0.0 : $sapc - self::APCA_LO_OFFSET;
        } else {
            $sapc = (pow($bg_y, self::APCA_REV_BG) - pow($txt_y, self::APCA_REV_TXT)) * self::APCA_SCALE;
            $out = $sapc > -self::APCA_LO_CLIP ? 0.0 : $sapc + self::APCA_LO_OFFSET;
        }

        return $out * 100.0;
    }

    /** Convenience: unsigned Lc, which is what target comparisons use. */
    public static function apca_lc_abs(string $text_hex, string $bg_hex): float {
        return abs(self::apca_lc($text_hex, $bg_hex));
    }

    // ---- sRGB <-> OKLab / OKLCH ---------------------------------

    private static function srgb_to_linear(float $v): float {
        $v = $v / 255;
        return $v <= 0.04045 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
    }

    private static function linear_to_srgb(float $v): float {
        $v = $v <= 0.0031308 ? $v * 12.92 : 1.055 * pow($v, 1 / 2.4) - 0.055;
        return $v * 255;
    }

    /**
     * @param array{0: float, 1: float, 2: float} $rgb
     * @return array{L: float, a: float, b: float}
     */
    public static function srgb_to_oklab(array $rgb): array {
        $r = self::srgb_to_linear($rgb[0]);
        $g = self::srgb_to_linear($rgb[1]);
        $b = self::srgb_to_linear($rgb[2]);

        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;

        $l_ = self::cbrt($l);
        $m_ = self::cbrt($m);
        $s_ = self::cbrt($s);

        return [
            'L' => 0.2104542553 * $l_ + 0.7936177850 * $m_ - 0.0040720468 * $s_,
            'a' => 1.9779984951 * $l_ - 2.4285922050 * $m_ + 0.4505937099 * $s_,
            'b' => 0.0259040371 * $l_ + 0.7827717662 * $m_ - 0.8086757660 * $s_,
        ];
    }

    /**
     * OKLab -> linear-then-gamma sRGB. May land OUTSIDE 0..255 when the
     * Lab point is not displayable; callers that need a real colour go
     * through oklch_to_hex() which gamut-maps first.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public static function oklab_to_srgb(float $L, float $a, float $b): array {
        $l_ = $L + 0.3963377774 * $a + 0.2158037573 * $b;
        $m_ = $L - 0.1055613458 * $a - 0.0638541728 * $b;
        $s_ = $L - 0.0894841775 * $a - 1.2914855480 * $b;

        $l = $l_ * $l_ * $l_;
        $m = $m_ * $m_ * $m_;
        $s = $s_ * $s_ * $s_;

        $r =  4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s;
        $g = -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s;
        $bl = -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s;

        return [self::linear_to_srgb($r), self::linear_to_srgb($g), self::linear_to_srgb($bl)];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $rgb
     * @return array{L: float, C: float, h: float} h in degrees 0..360
     */
    public static function srgb_to_oklch(array $rgb): array {
        $lab = self::srgb_to_oklab($rgb);
        $c = sqrt($lab['a'] * $lab['a'] + $lab['b'] * $lab['b']);
        $h = $c < 1e-9 ? 0.0 : rad2deg(atan2($lab['b'], $lab['a']));
        if ($h < 0) $h += 360.0;
        return ['L' => $lab['L'], 'C' => $c, 'h' => $h];
    }

    /** hex string -> OKLCH triple. @return array{L: float, C: float, h: float} */
    public static function hex_to_oklch(string $hex): array {
        return self::srgb_to_oklch(self::hex_to_rgb($hex));
    }

    /**
     * True when this OKLCH point sits inside the sRGB gamut (with a
     * hair of tolerance for float noise at the boundary).
     */
    public static function oklch_in_gamut(float $L, float $C, float $h): bool {
        $a = $C * cos(deg2rad($h));
        $b = $C * sin(deg2rad($h));
        $rgb = self::oklab_to_srgb($L, $a, $b);
        foreach ($rgb as $c) {
            if ($c < -0.5 || $c > 255.5) return false;
        }
        return true;
    }

    /**
     * OKLCH -> "#rrggbb", reducing chroma (hue and lightness held) until
     * the colour is displayable. This is the standard "hold hue, clip
     * chroma" gamut map — good enough here because the resolver only ever
     * nudges lightness on already-in-gamut seed hues.
     */
    public static function oklch_to_hex(float $L, float $C, float $h): string {
        $L = max(0.0, min(1.0, $L));
        if (!self::oklch_in_gamut($L, $C, $h)) {
            $lo = 0.0;
            $hi = $C;
            for ($i = 0; $i < 24; $i++) {
                $mid = ($lo + $hi) / 2;
                if (self::oklch_in_gamut($L, $mid, $h)) {
                    $lo = $mid;
                } else {
                    $hi = $mid;
                }
            }
            $C = $lo;
        }
        $a = $C * cos(deg2rad($h));
        $b = $C * sin(deg2rad($h));
        return self::rgb_to_hex(self::oklab_to_srgb($L, $a, $b));
    }

    // ---- derivations the resolver leans on -----------------------

    /**
     * Move a base colour's OKLCH lightness (hue + chroma fixed) until it
     * hits $target_lc unsigned APCA against $against_hex, then gamut-map.
     * $direction: +1 forces lighter, -1 darker, 0 auto-picks whichever
     * side of $against has headroom. Returns the base unchanged if the
     * target can't be reached (pure white/black already past it).
     */
    public static function solve_lightness_for_apca(
        string $base_hex,
        string $against_hex,
        float $target_lc,
        int $direction = 0
    ): string {
        $base = self::hex_to_oklch($base_hex);
        $against = self::hex_to_oklch($against_hex);

        if ($direction === 0) {
            // Head toward whichever end of the lightness axis is farther
            // from the background — that's where the contrast headroom is.
            $direction = $against['L'] >= $base['L'] ? -1 : 1;
        }

        $lo = $direction > 0 ? $base['L'] : 0.0;
        $hi = $direction > 0 ? 1.0 : $base['L'];

        // If even the extreme end can't clear the target, hand back the
        // most-contrasting end we have rather than failing loudly.
        $extreme = self::oklch_to_hex($direction > 0 ? $hi : $lo, $base['C'], $base['h']);
        if (self::apca_lc_abs($extreme, $against_hex) < $target_lc) {
            return $extreme;
        }

        for ($i = 0; $i < 22; $i++) {
            $mid = ($lo + $hi) / 2;
            $cand = self::oklch_to_hex($mid, $base['C'], $base['h']);
            $lc = self::apca_lc_abs($cand, $against_hex);
            $enough = $lc >= $target_lc;
            // Contrast rises as we move away from the background's lightness.
            if ($direction > 0) {
                if ($enough) { $hi = $mid; } else { $lo = $mid; }
            } else {
                if ($enough) { $lo = $mid; } else { $hi = $mid; }
            }
        }
        return self::oklch_to_hex($direction > 0 ? $hi : $lo, $base['C'], $base['h']);
    }

    /**
     * Land a colour NEAR a specific APCA target against $against_hex
     * (hue fixed, chroma optionally scaled), approaching from whichever
     * side the base already sits on. This is the "muted" / "disabled"
     * move: those roles want a DELIBERATE, bounded contrast (strong
     * enough to see, weak enough to read as secondary), not "as much as
     * possible". Contrast is monotonic along the lightness axis on each
     * side of the background's own lightness, so a bounded binary search
     * on the correct segment converges cleanly.
     */
    public static function approach_apca_target(
        string $base_hex,
        string $against_hex,
        float $target_lc,
        float $chroma_mul = 1.0
    ): string {
        $base = self::hex_to_oklch($base_hex);
        $against = self::hex_to_oklch($against_hex);
        $c = max(0.0, $base['C'] * $chroma_mul);

        $at = function (float $L) use ($c, $base, $against_hex): float {
            return self::apca_lc_abs(self::oklch_to_hex($L, $c, $base['h']), $against_hex);
        };

        $cur = $at($base['L']);
        // Which direction reduces contrast (toward the background's L),
        // which raises it (away). Pick the search segment accordingly.
        $toward_bg = $against['L'] >= $base['L'] ? 1 : -1;
        if ($cur > $target_lc) {
            $lo = $base['L'];
            $hi = $against['L'];
        } else {
            $lo = $base['L'];
            $hi = $toward_bg > 0 ? 0.0 : 1.0;
        }

        for ($i = 0; $i < 22; $i++) {
            $mid = ($lo + $hi) / 2;
            if ($at($mid) >= $target_lc) {
                // still too strong (or exactly enough) -> move toward bg
                if ($cur > $target_lc) { $lo = $mid; } else { $hi = $mid; }
            } else {
                if ($cur > $target_lc) { $hi = $mid; } else { $lo = $mid; }
            }
        }
        return self::oklch_to_hex(($lo + $hi) / 2, $c, $base['h']);
    }

    /**
     * Shift a colour's OKLCH lightness by a fixed delta (hue + chroma
     * fixed), optionally scaling chroma too. Used for state steps
     * (hover/active) that must move a predictable perceptual amount off
     * an already-validated base.
     */
    public static function shift_lightness(string $hex, float $delta_l, float $chroma_mul = 1.0): string {
        $c = self::hex_to_oklch($hex);
        return self::oklch_to_hex($c['L'] + $delta_l, max(0.0, $c['C'] * $chroma_mul), $c['h']);
    }

    /**
     * Pick an ink for $bg from $inks. $inks is PRIORITY-ORDERED: the
     * first one that clears $min (in $metric units) wins, so a theme's
     * own --bh-text is preferred over a raw black/white fallback
     * whenever it is actually legible enough. Nothing clears $min ->
     * the strongest-reading ink, so the result is never worse than
     * "max contrast available".
     *
     * $metric: 'apca' (default) or 'wcag' — the latter only where a
     * value BHY_Style historically derived with the WCAG ratio has to
     * be reproduced (--bh-accent-contrast).
     *
     * @param non-empty-array<string> $inks
     */
    public static function best_ink_on(string $bg_hex, array $inks, string $metric = 'apca', float $min = 0.0): string {
        $best = $inks[0];
        $best_score = -1.0;
        foreach ($inks as $ink) {
            $score = $metric === 'wcag'
                ? self::wcag_ratio($ink, $bg_hex)
                : self::apca_lc_abs($ink, $bg_hex);
            if ($min > 0 && $score >= $min) {
                return $ink;
            }
            if ($score > $best_score) {
                $best_score = $score;
                $best = $ink;
            }
        }
        return $best;
    }

    /** Whichever of the two inks reads stronger (unsigned APCA) on $bg. */
    public static function ink_on(string $bg_hex, string $ink_a_hex, string $ink_b_hex): string {
        return self::best_ink_on($bg_hex, [$ink_a_hex, $ink_b_hex]);
    }

    /**
     * Non-alpha sRGB mix, matching CSS `color-mix(in srgb, $a $p%, $b)`
     * closely enough to MEASURE a color-mix() token in the audit (the
     * emitted CSS keeps using real color-mix()).
     */
    public static function mix_srgb(string $hex_a, string $hex_b, float $weight_a): string {
        $weight_a = max(0.0, min(1.0, $weight_a));
        $a = self::hex_to_rgb($hex_a);
        $b = self::hex_to_rgb($hex_b);
        return self::rgb_to_hex([
            $a[0] * $weight_a + $b[0] * (1 - $weight_a),
            $a[1] * $weight_a + $b[1] * (1 - $weight_a),
            $a[2] * $weight_a + $b[2] * (1 - $weight_a),
        ]);
    }

    /**
     * CIE76 delta-E in Lab-ish OKLab space, scaled ~x100 so the numbers
     * read on the same order as classic dE. Only used by tests /
     * calibration to assert "close enough to the old hand-picked value".
     */
    public static function delta_e(string $hex_a, string $hex_b): float {
        $la = self::srgb_to_oklab(self::hex_to_rgb($hex_a));
        $lb = self::srgb_to_oklab(self::hex_to_rgb($hex_b));
        $dl = $la['L'] - $lb['L'];
        $da = $la['a'] - $lb['a'];
        $db = $la['b'] - $lb['b'];
        return sqrt($dl * $dl + $da * $da + $db * $db) * 100.0;
    }

    private static function cbrt(float $v): float {
        return $v < 0 ? -pow(-$v, 1 / 3) : pow($v, 1 / 3);
    }
}
