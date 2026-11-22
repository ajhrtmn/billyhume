<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared SEO output (meta description, Open Graph/Twitter, JSON-LD). Rendering code calls
 * BH_SEO::set_page_data() once; bh_seo_page_data is an override hatch.
 */
class BH_SEO {
    /** @var array<string, mixed>|null */
    private static $page_data = null;

    public static function init(): void {
        add_action('wp_head', [self::class, 'render_head_tags'], 1);
        add_action('template_redirect', [self::class, 'maybe_serve_llms_txt']);
    }

    /**
     * $data: title, description (truncated), url (canonical), image, type (og:type, default
     * website), schema (a JSON-LD array, output verbatim).
     */
    /** @param array<string, mixed> $data */
    public static function set_page_data(array $data): void {
        self::$page_data = apply_filters('bh_seo_page_data', $data);
        // This class emits its own canonical (the semantic content URL), so core's
        // is removed to avoid two conflicting tags.
        remove_action('wp_head', 'rel_canonical');
    }

    /**
     * Lets a fallback consumer (the theme) skip if something more specific
     * already set page data for this request.
     */
    public static function has_page_data(): bool {
        return self::$page_data !== null;
    }

    public static function render_head_tags(): void {
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
            // Strip nulls for a smaller, cleaner JSON-LD payload.
            $schema = array_filter($d['schema'], fn($v) => $v !== null);
            echo '<script type="application/ld+json">' . wp_json_encode($schema) . '</script>' . "\n";
        }
        echo "<!-- /BH_SEO -->\n";
    }

    /**
     * WHY: shortcode render() runs after wp_head, so SEO data must be set on
     * template_redirect. Returns the attributes of the first $tag shortcode in
     * the current queried post's content, or null if absent.
     *
     * @return array<string, mixed>|null
     */
    public static function shortcode_atts_on_current_page(string $tag): ?array {
        if (!is_singular()) return null;
        $post = get_post();
        if (!$post || !has_shortcode($post->post_content, $tag)) return null;

        // Group 3 of get_shortcode_regex() is the raw attribute string.
        preg_match('/' . get_shortcode_regex([$tag]) . '/s', $post->post_content, $m);
        if (empty($m[3])) return [];
        return shortcode_parse_atts($m[3]);
    }

    /**
     * Serves /llms.txt: site name/tagline plus sections peer plugins add via
     * the 'bh_llms_txt_sections' filter.
     */
    public static function maybe_serve_llms_txt(): void {
        if (trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/') !== 'llms.txt') return;

        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=3600');

        $site_name = get_bloginfo('name');
        $description = get_bloginfo('description');

        echo "# " . $site_name . "\n\n";
        if ($description) echo "> " . $description . "\n\n";
        echo OUS_Words::get('admin.seo.site_self_hosted_self_hosted') . '

';

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
