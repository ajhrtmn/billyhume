<?php
if (!defined('ABSPATH')) exit;

/**
 * A shared visual "family" for every OUS-owned top-level admin menu
 * item, replacing the plain "OUS ·" text prefix each one used to carry
 * in its label (AJ's own ask: signal ecosystem membership visually,
 * not just via a text label). Every icon below shares the same rounded-
 * square badge frame, so Contests/Courses/Streaming/People read as
 * belonging together in the wp-admin sidebar the moment you see two of
 * them side by side, with zero label text doing that work.
 *
 * Rendered as inline base64 SVG data URIs (add_menu_page()'s and
 * register_post_type()'s own 'menu_icon' both accept this natively).
 *
 * Three real, confirmed-live bugs already fixed in getting here:
 *
 * 1. The color constant must be a literal '#a7aaad', not a URL-escaped
 *    '%23a7aaad' — the whole SVG string is base64-encoded, not embedded
 *    as raw/URL-encoded XML in the data URI, so a URL-escaped value is
 *    simply invalid to SVG's own parser. Confirmed live: blank icons.
 *
 * 2 & 3. WP core recolors a custom SVG menu icon to match the active
 *    admin color scheme, and it is far more aggressive than "swap the
 *    fill value": confirmed live, it rewrites every `fill="..."`
 *    attribute (including `fill="none"`) to one solid scheme color, AND
 *    it takes any `style="..."` attribute on an element and replaces
 *    the ENTIRE style string with a single `fill:SCHEMECOLOR`
 *    declaration — silently deleting stroke/stroke-width in the
 *    process. There is no attribute-level way to keep an element
 *    hollow/outlined; every paintable element WP touches ends up a
 *    single, solid, uniformly-colored fill.
 *
 *    The only shape that survives that intact is a single filled path
 *    that is ALREADY geometrically hollow — two nested contours (an
 *    outer ring boundary and an inner one) combined with
 *    fill-rule="evenodd" on one <path>, so the "hole" is real path
 *    geometry rather than a stroke-vs-fill contrast. That's what the
 *    badge frame and the Design Suite ring below actually are. Every
 *    other glyph in this set is a genuinely solid shape (a book
 *    silhouette instead of an outline, overlapping solid circles
 *    instead of ringed ones) rather than fighting this mechanism
 *    further — simpler, and immune to it by construction.
 */
class OUS_MenuIcons {
    const COLOR = '#a7aaad';

    // Rounded-square ring (the shared badge frame), built as one
    // evenodd path: outer rounded-rect boundary, then an inner
    // rounded-rect boundary as the "hole" — see class docblock.
    const FRAME = 'M6.5,1.5 H13.5 A5,5 0 0 1 18.5,6.5 V13.5 A5,5 0 0 1 13.5,18.5 H6.5 A5,5 0 0 1 1.5,13.5 V6.5 A5,5 0 0 1 6.5,1.5 Z'
                . ' M7,4 H13 A3,3 0 0 1 16,7 V13 A3,3 0 0 1 13,16 H7 A3,3 0 0 1 4,13 V7 A3,3 0 0 1 7,4 Z';

    private static function svg($glyph) {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
             . '<path fill-rule="evenodd" fill="' . self::COLOR . '" d="' . self::FRAME . '"/>'
             . $glyph
             . '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    // Own Ur Shit hub — a small waveform/audio-meter mark (three bars),
    // the one glyph in this set that represents the ecosystem itself
    // rather than one feature area within it.
    public static function hub() {
        return self::svg(
            '<rect x="6" y="9" width="1.6" height="5" rx="0.8" fill="' . self::COLOR . '"/>'
          . '<rect x="9.2" y="6" width="1.6" height="8" rx="0.8" fill="' . self::COLOR . '"/>'
          . '<rect x="12.4" y="8" width="1.6" height="6" rx="0.8" fill="' . self::COLOR . '"/>'
        );
    }

    // A ring (evenodd donut, same technique as the frame) plus a solid
    // center dot — a swatch/target mark.
    public static function design_suite() {
        return self::svg(
            '<path fill-rule="evenodd" fill="' . self::COLOR . '" d="M6.8,10 A3.2,3.2 0 1,0 13.2,10 A3.2,3.2 0 1,0 6.8,10 Z'
          . ' M8.6,10 A1.4,1.4 0 1,0 11.4,10 A1.4,1.4 0 1,0 8.6,10 Z"/>'
          . '<circle cx="10" cy="10" r="1" fill="' . self::COLOR . '"/>'
        );
    }

    public static function contests() {
        return self::svg(
            '<path d="M10 5.5 L11 9 L14.5 10 L11 11 L10 14.5 L9 11 L5.5 10 L9 9 Z" fill="' . self::COLOR . '"/>'
        );
    }

    // A solid open-book silhouette (both "pages" filled) rather than an
    // outline — reads clearly at 20px and needs no stroke at all.
    public static function courses() {
        return self::svg(
            '<path fill="' . self::COLOR . '" d="M10 7c-1.2-1-3-1.4-4.2-1.4v6.8c1.2 0 3 .4 4.2 1.4c1.2-1 3-1.4 4.2-1.4v-6.8c-1.2 0-3 .4-4.2 1.4z"/>'
        );
    }

    public static function streaming() {
        return self::svg(
            '<path d="M8.3 6.5 L14 10 L8.3 13.5 Z" fill="' . self::COLOR . '"/>'
        );
    }

    // Two solid overlapping circles — reads as "people/group" via the
    // wider merged silhouette rather than two separate ringed heads.
    public static function people() {
        return self::svg(
            '<circle cx="8" cy="9.5" r="2.6" fill="' . self::COLOR . '"/>'
          . '<circle cx="12" cy="9.5" r="2.6" fill="' . self::COLOR . '"/>'
        );
    }
}
