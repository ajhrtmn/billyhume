<?php
if (!defined('ABSPATH')) exit;

/**
 * Pure, framework-agnostic colour math (no WordPress calls).
 * The metrics are NOT interchangeable: wcag_ratio() is the compliance
 * number reported; apca_lc() is what BHY_Contrast's resolver decides on;
 * OKLCH is for generating colours, not scoring them.
 */
class BHY_Color {

    // ---- hex <-> rgb -------------------------------------------------

    /**
     * "#abc", "#aabbcc", "#aabbccdd" -> [r, g, b] floats 0..255. Alpha is
     * dropped; unparseable input returns black.
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
     * WCAG relative luminance (same formula tests/ux/audit.ts measures with).
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
     * APCA screen luminance Y: plain 2.4-power curve, deliberately NOT the
     * WCAG piecewise curve. Do not unify them.
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
     * Signed APCA Lc: positive = dark text on light bg, negative = light on dark.
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

    /** Unsigned Lc, used for target comparisons. */
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
     * OKLab -> sRGB; may fall outside 0..255. oklch_to_hex() gamut-maps first.
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

    /** True when this OKLCH point is inside sRGB (with float tolerance). */
    public static function oklch_in_gamut(float $L, float $C, float $h): bool {
        $a = $C * cos(deg2rad($h));
        $b = $C * sin(deg2rad($h));
        $rgb = self::oklab_to_srgb($L, $a, $b);
        foreach ($rgb as $c) {
            if ($c < -0.5 || $c > 255.5) return false;
        }
        return true;
    }

    /** OKLCH -> "#rrggbb", reducing chroma (hue + lightness held) until displayable. */
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
     * Move OKLCH lightness (hue + chroma fixed) until unsigned APCA vs
     * $against_hex reaches $target_lc. $direction: +1 lighter, -1 darker,
     * 0 auto. Returns the extreme end if the target is unreachable.
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
            // Head toward the end of the lightness axis farther from the background.
            $direction = $against['L'] >= $base['L'] ? -1 : 1;
        }

        $lo = $direction > 0 ? $base['L'] : 0.0;
        $hi = $direction > 0 ? 1.0 : $base['L'];

            // Unreachable target: return the most-contrasting end rather than fail.
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
     * Land NEAR an APCA target (bounded contrast for muted/disabled roles),
     * hue fixed, chroma optionally scaled. Contrast is monotonic in L on each
     * side of the background's L, so a binary search converges.
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

    /** Shift OKLCH lightness by a fixed delta (for hover/active state steps). */
    public static function shift_lightness(string $hex, float $delta_l, float $chroma_mul = 1.0): string {
        $c = self::hex_to_oklch($hex);
        return self::oklch_to_hex($c['L'] + $delta_l, max(0.0, $c['C'] * $chroma_mul), $c['h']);
    }

    /**
     * Pick an ink for $bg from priority-ordered $inks: the first that clears
     * $min wins, otherwise the strongest. $metric: 'apca' or 'wcag' (the
     * latter only to reproduce --bh-accent-contrast).
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

    /** sRGB mix approximating CSS color-mix(in srgb, ...), for measuring tokens. */
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

    /** OKLab delta-E scaled ~x100; used by tests/calibration only. */
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
