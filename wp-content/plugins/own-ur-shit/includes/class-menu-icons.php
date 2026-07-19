<?php
if (!defined('ABSPATH')) exit;

/**
 * A shared visual "family" for every OUS-owned top-level admin menu
 * item, replacing the plain "OUS ·" text prefix each one used to carry
 * in its label (AJ's own ask: signal ecosystem membership visually,
 * not just via a text label). Final direction after several live rounds
 * of visual iteration ("more iconic/whimsical/magical... space age...
 * more streamline moderne/art deco/googie... balanced, offset,
 * aerodynamic... old Hollywood glamour"): a graduated ray fan radiating
 * from the bottom-left corner — five rays of steadily increasing length
 * (7.5 → 10.5 → 13.5 → 16.5 → 19.5, widening in step), the same
 * "ordered, geometric, rhythmic" sunburst a 1930s theater marquee or
 * deco fireplace surround uses, doubling as a search-light-fan/rocket-
 * trail motion cue. Every OUS-owned top-level menu shares this exact
 * fan; only the small glyph tucked in the opposite (upper-right) corner
 * changes per feature, kept fully clear of the rays so nothing overlaps
 * or reads as muddy at true ~20px sidebar size.
 *
 * Rendered as inline base64 SVG data URIs (add_menu_page()'s and
 * register_post_type()'s own 'menu_icon' both accept this natively).
 *
 * Two real, confirmed-live bugs already fixed in getting here:
 *
 * 1. The color constant must be a literal '#a7aaad', not a URL-escaped
 *    '%23a7aaad' — the whole SVG string is base64-encoded, not embedded
 *    as raw/URL-encoded XML in the data URI, so a URL-escaped value is
 *    simply invalid to SVG's own parser. Confirmed live: blank icons.
 *
 * 2. WP core recolors a custom SVG menu icon to match the active admin
 *    color scheme, and it is far more aggressive than "swap the fill
 *    value": confirmed live, it rewrites every `fill="..."` attribute
 *    (including `fill="none"`) to one solid scheme color, AND it takes
 *    any `style="..."` attribute on an element and replaces the ENTIRE
 *    style string with a single `fill:SCHEMECOLOR` declaration —
 *    silently deleting stroke/stroke-width in the process. There is no
 *    attribute-level way to keep an element hollow/outlined via
 *    fill:none or stroke; every paintable element WP touches ends up a
 *    single, solid, uniformly-colored fill. Every shape below is either
 *    a genuinely solid fill (immune by construction) or, where a hollow
 *    ring is unavoidable (Design Suite's target mark), a single
 *    fill-rule="evenodd" path whose "hole" is real path geometry rather
 *    than a stroke-vs-fill contrast.
 */
class OUS_MenuIcons {
    const COLOR = '#a7aaad';

    // The shared ray fan, five rays radiating from (1.5, 18.5) — see
    // class docblock for the length/width rhythm. Computed once via a
    // small script (fan-out angle + monotonically increasing length and
    // half-width per ray), not hand-typed, so the rhythm stays exact.
    const FAN = '<path fill="' . self::COLOR . '" d="M1.62,18.51 L2.52,11.05 L1.52,10.98 L1.38,18.49 Z"/>'
              . '<path fill="' . self::COLOR . '" d="M1.61,18.54 L5.75,8.87 L4.43,8.39 L1.39,18.46 Z"/>'
              . '<path fill="' . self::COLOR . '" d="M1.60,18.57 L10.16,8.11 L8.71,7.05 L1.40,18.43 Z"/>'
              . '<path fill="' . self::COLOR . '" d="M1.57,18.59 L15.18,9.21 L13.82,7.47 L1.43,18.41 Z"/>'
              . '<path fill="' . self::COLOR . '" d="M1.54,18.61 L20.07,12.40 L19.09,9.99 L1.46,18.39 Z"/>';

    private static function svg($glyph) {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
             . self::FAN
             . $glyph
             . '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    // Own Ur Shit hub — a small waveform/audio-meter mark (three bars),
    // the one glyph in this set that represents the ecosystem itself
    // rather than one feature area within it.
    public static function hub() {
        return self::svg(
            '<rect x="14" y="1.5" width="1.4" height="4" rx="0.7" fill="' . self::COLOR . '"/>'
          . '<rect x="16.3" y="0" width="1.4" height="6.5" rx="0.7" fill="' . self::COLOR . '"/>'
          . '<rect x="18.6" y="2" width="1.2" height="4.5" rx="0.6" fill="' . self::COLOR . '"/>'
        );
    }

    // A ring (evenodd donut — the one hollow shape in this set, real
    // path geometry so it survives WP's recolor) plus a solid center
    // dot — a swatch/target mark.
    public static function design_suite() {
        return self::svg(
            '<path fill-rule="evenodd" fill="' . self::COLOR . '" d="M16,2.4 A2.6,2.6 0 1,1 16,7.6 A2.6,2.6 0 1,1 16,2.4 Z'
          . ' M16,3.9 A1.1,1.1 0 1,0 16,6.1 A1.1,1.1 0 1,0 16,3.9 Z"/>'
        );
    }

    public static function contests() {
        return self::svg(
            '<path fill="' . self::COLOR . '" d="M16 0.8 L17 3.5 L19.8 4.5 L17 5.5 L16 8.2 L15 5.5 L12.2 4.5 L15 3.5 Z"/>'
        );
    }

    // A solid open-book silhouette (both "pages" filled) rather than an
    // outline — reads clearly at 20px and needs no stroke at all.
    public static function courses() {
        return self::svg(
            '<path fill="' . self::COLOR . '" d="M16.5 2.9c-0.8-0.6-1.9-0.9-2.8-0.9v4.6c0.9 0 2 0.3 2.8 0.9c0.8-0.6 1.9-0.9 2.8-0.9v-4.6c-0.9 0-2 0.3-2.8 0.9z"/>'
        );
    }

    public static function streaming() {
        return self::svg(
            '<path fill="' . self::COLOR . '" d="M15 1.7 L19 4.2 L15 6.7 Z"/>'
        );
    }

    // Two solid overlapping circles — reads as "people/group" via the
    // wider merged silhouette rather than two separate ringed heads.
    public static function people() {
        return self::svg(
            '<circle cx="15" cy="4" r="1.9" fill="' . self::COLOR . '"/>'
          . '<circle cx="18.3" cy="4" r="1.9" fill="' . self::COLOR . '"/>'
        );
    }
}
