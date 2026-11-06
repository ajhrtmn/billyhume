<?php
if (!defined('ABSPATH')) exit;

/**
 * Content-based related products (same approach as BHS_Recommendations), deliberately not
 * purchase-history based. Shared terms score: collection x3, category x2, tag x1.
 */
class BHM_Recommendations {
    /** @return array<int, \WC_Product> */
    public static function related_products(int $product_id, int $limit = 8): array {
        if (!function_exists('wc_get_product')) return [];
        $product_id = (int) $product_id;
        if (!$product_id || !wc_get_product($product_id)) return [];

        $collections = wp_get_post_terms($product_id, BHM_Storefront::TAXONOMY, ['fields' => 'ids']);
        $categories  = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
        $tags        = wp_get_post_terms($product_id, 'product_tag', ['fields' => 'ids']);
        $collections = is_wp_error($collections) ? [] : $collections;
        $categories  = is_wp_error($categories) ? [] : $categories;
        $tags        = is_wp_error($tags) ? [] : $tags;

        // Scores the whole catalog; fine at solo-artist catalog sizes.
        $candidates = get_posts([
            'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => -1,
            'post__not_in' => [$product_id], 'fields' => 'ids',
        ]);

        $scored = [];
        foreach ($candidates as $cid) {
            $score = 0;
            if ($collections) {
                $their = wp_get_post_terms($cid, BHM_Storefront::TAXONOMY, ['fields' => 'ids']);
                if (!is_wp_error($their)) $score += 3 * count(array_intersect($collections, $their));
            }
            if ($categories) {
                $their = wp_get_post_terms($cid, 'product_cat', ['fields' => 'ids']);
                if (!is_wp_error($their)) $score += 2 * count(array_intersect($categories, $their));
            }
            if ($tags) {
                $their = wp_get_post_terms($cid, 'product_tag', ['fields' => 'ids']);
                if (!is_wp_error($their)) $score += count(array_intersect($tags, $their));
            }
            if ($score > 0) $scored[$cid] = $score;
        }

        arsort($scored);
        $top_ids = array_slice(array_keys($scored), 0, max(1, min(24, (int) $limit)), true);

        $out = [];
        foreach ($top_ids as $id) {
            $p = wc_get_product($id);
            if ($p) $out[] = $p;
        }
        return $out;
    }

    // Shared by the placed block and the automatic single-product section.
    /** @param array<string, mixed> $attrs */
    public static function render_related_products_block($attrs): string {
        $product_id = (int) ($attrs['productId'] ?? 0);
        if (!$product_id) {
            global $post;
            if ($post && get_post_type($post) === 'product') $product_id = $post->ID;
        }
        if (!$product_id) return '';

        $limit = max(1, min(24, (int) ($attrs['limit'] ?? 8)));
        $products = self::related_products($product_id, $limit);
        if (!$products) return '';

        $heading = (string) ($attrs['heading'] ?? 'You may also like');
        $out = '<div class="bhm-related-products">';
        $out .= '<h3 class="bhm-related-products-heading">' . esc_html($heading) . '</h3>';
        $out .= '<div class="bhm-product-grid" style="--bhm-grid-cols:4;">';
        $out .= BHM_Storefront::render_product_cards($products);
        $out .= '</div></div>';
        return $out;
    }

    // Automatic related section on every product page; skipped when the block was placed manually.
    public static function auto_render_related(): void {
        if (!function_exists('wc_get_product')) return;
        global $post;
        if (!$post || get_post_type($post) !== 'product') return;
        if (self::$manual_block_rendered) return;
        echo self::render_related_products_block(['productId' => $post->ID, 'limit' => 8]);
    }

    private static bool $manual_block_rendered = false;

    /** @param array<string, mixed> $attrs */
    public static function render_related_products_block_public($attrs): string {
        self::$manual_block_rendered = true;
        return self::render_related_products_block($attrs);
    }
}
