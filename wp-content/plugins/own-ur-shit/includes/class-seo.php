<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared SEO/discoverability output — meta description, Open Graph/
 * Twitter Card tags, and JSON-LD structured data — one renderer every
 * plugin's own page-rendering code calls into, instead of each hand-
 * rolling its own tag soup (or, as confirmed before this class
 * existed, not rendering any of this at all — a full grep across the
 * whole ecosystem found zero meta/OG/schema.org output anywhere).
 * See ROADMAP-discoverability.md for the full plan this is Slice 1 of.
 *
 * Deliberately a simple set-then-render API, not a filter a plugin has
 * to independently re-derive "is this even my page" logic inside on
 * every request: whatever code already knows it's rendering a specific
 * piece of content (a shortcode handler, a template_redirect callback)
 * calls `BH_SEO::set_page_data([...])` once, right where it already
 * has the real data in hand; this class renders it at `wp_head` if
 * anything was set. `bh_seo_page_data` filter still exists as an
 * ESCAPE HATCH for a plugin that wants to adjust/override what another
 * piece of code already set, not as the primary registration path.
 */
class BH_SEO {
    private static $page_data = null;

    public static function init() {
        add_action('wp_head', [self::class, 'render_head_tags'], 1);
        add_action('template_redirect', [self::class, 'maybe_serve_llms_txt']);
    }

    /**
     * $data:
     *   title        string, required — falls back to wp_title() if omitted
     *   description  string — plain text, gets truncated to a sane meta-description length
     *   url           string — canonical URL, defaults to the current request URL
     *   image        string — absolute URL, used for og:image/twitter:image
     *   type         string — og:type, e.g. 'profile', 'music.song', 'website' (default)
     *   schema       array — a schema.org JSON-LD structured-data array, e.g.
     *                 ['@context' => 'https://schema.org', '@type' => 'Person', 'name' => ..., ...]
     *                 Rendered verbatim via wp_json_encode() — caller's responsibility to shape it correctly.
     */
    public static function set_page_data(array $data) {
        self::$page_data = apply_filters('bh_seo_page_data', $data);
        // WordPress core renders its own rel=canonical (pointing at the
        // literal page permalink) via WP_Head's rel_canonical() hook.
        // This class renders one too, deliberately pointing at the
        // semantic content URL instead (e.g. a shortcode's ?bh_user=1
        // rather than whatever page embeds it) — remove core's so a
        // page doesn't end up with two conflicting canonical tags.
        remove_action('wp_head', 'rel_canonical');
    }

    public static function render_head_tags() {
        if (!self::$page_data) return;
        $d = self::$page_data;

        $title = (string) ($d['title'] ?? wp_get_document_title());
        $description = isset($d['description']) ? wp_trim_words(wp_strip_all_tags((string) $d['description']), 40, '…') : '';
        $url = (string) ($d['url'] ?? (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''));
        $image = (string) ($d['image'] ?? '');
        $type = (string) ($d['type'] ?? 'website');

        echo "\n<!-- BH_SEO -->\n";
        if ($description) echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
        echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        if ($description) echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
        echo '<meta property="og:type" content="' . esc_attr($type) . '">' . "\n";
        if ($image) echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
        echo '<meta name="twitter:card" content="' . esc_attr($image ? 'summary_large_image' : 'summary') . '">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "\n";
        if ($description) echo '<meta name="twitter:description" content="' . esc_attr($description) . '">' . "\n";
        if ($image) echo '<meta name="twitter:image" content="' . esc_url($image) . '">' . "\n";

        if (!empty($d['schema']) && is_array($d['schema'])) {
            // Strip nulls (a consumer's own optional-field-not-set
            // shorthand, like a public profile with no bio) rather than
            // encoding "field": null into every structured-data block —
            // valid JSON-LD either way, but a cleaner, smaller payload
            // and less noise for whatever parses it downstream.
            $schema = array_filter($d['schema'], fn($v) => $v !== null);
            echo '<script type="application/ld+json">' . wp_json_encode($schema) . '</script>' . "\n";
        }
        echo "<!-- /BH_SEO -->\n";
    }

    /**
     * A small, mostly-static /llms.txt endpoint — ROADMAP-discoverability.md
     * Section 3's own description: the same spirit as robots.txt/
     * sitemap.xml, written for an LLM crawler's consumption pattern
     * (prose + links) instead of a search index's. Genuinely cheap once
     * the site name/tagline/public surfaces are already known —
     * assembled from get_bloginfo() plus whatever peer plugins declare
     * via the 'bh_llms_txt_sections' filter (same zero-central-
     * registration shape as ous_debug_tools/bhi_portal_panels), so a
     * new plugin's own public catalog/registry/course listing shows up
     * here automatically rather than needing this file edited by hand
     * every time a new surface ships.
     */
    public static function maybe_serve_llms_txt() {
        if (trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/') !== 'llms.txt') return;

        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=3600');

        $site_name = get_bloginfo('name');
        $description = get_bloginfo('description');

        echo "# " . $site_name . "\n\n";
        if ($description) echo "> " . $description . "\n\n";
        echo "This site is self-hosted on Own Ur Shit, an independent musician's own platform — not a rented profile on a third-party service. Content below is organized for easy reference.\n\n";

        $sections = apply_filters('bh_llms_txt_sections', []);
        foreach ($sections as $section) {
            if (empty($section['title']) || empty($section['links'])) continue;
            echo "## " . $section['title'] . "\n\n";
            foreach ($section['links'] as $link) {
                if (empty($link['url']) || empty($link['label'])) continue;
                echo "- [" . $link['label'] . "](" . $link['url'] . ")" . (!empty($link['description']) ? ": " . $link['description'] : "") . "\n";
            }
            echo "\n";
        }

        exit;
    }
}
