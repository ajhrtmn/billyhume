<?php
if (!defined('ABSPATH')) exit;

/**
 * "Customers also bought" / "You may also like" — the storefront's own
 * version of bh-streaming's BHS_Recommendations (ROADMAP-platform-
 * evolution.md Section 5 names that class directly as reusable prior
 * art: "worth sharing the scoring approach rather than inventing a
 * second one"). Same deliberate posture as the original: content-based
 * (shared collection/category/tag terms), not collaborative-filtering
 * or purchase-history-driven — an honest scoping choice, not a
 * placeholder. A real "customers who bought X also bought Y" engine
 * needs real order-volume at real scale to beat these simple,
 * explainable rules; claiming that without the data to back it would be
 * overselling what a solo artist's storefront actually has.
 *
 * The product equivalent of tracks' artist/release/genre three-signal
 * score: bhm_collection (this ecosystem's own curated-collection
 * taxonomy, BHM_Storefront::TAXONOMY) weighted highest since it's the
 * artist's own deliberate merchandising grouping, product_cat next,
 * product_tag last as the loosest signal.
 */
class BHM_Recommendations {
    public static function related_products($product_id, $limit = 8) {
        if (!function_exists('wc_get_product')) return [];
        $product_id = (int) $product_id;
        if (!$product_id || !wc_get_product($product_id)) return [];

        $collections = wp_get_post_terms($product_id, BHM_Storefront::TAXONOMY, ['fields' => 'ids']);
        $categories  = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
        $tags        = wp_get_post_terms($product_id, 'product_tag', ['fields' => 'ids']);
        $collections = is_wp_error($collections) ? [] : $collections;
        $categories  = is_wp_error($categories) ? [] : $categories;
        $tags        = is_wp_error($tags) ? [] : $tags;

        // Same "score the whole catalog, no separate index" posture as
        // BHS_Recommendations::get_related() — fine at the catalog sizes
        // this ecosystem targets (a solo artist's merch store).
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

    // Shared by the BH_Content/core-block render path (an artist
    // deliberately placed this block) AND the automatic
    // woocommerce_after_single_product_summary hook (every product gets
    // this for free, no authoring required) — one renderer, two callers.
    public static function render_related_products_block($attrs) {
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

    // Zero-authoring default: every real single-product page gets a
    // related-items section automatically. An artist who wants to
    // reposition/restyle it can still place the bhm/related-products
    // block explicitly in the product's own description (this hook
    // no-ops via the `bhm_related_products_manual` flag an explicit
    // block render sets, so the automatic section never double-renders
    // alongside a manually-placed one on the same page).
    public static function auto_render_related() {
        if (!function_exists('wc_get_product')) return;
        global $post;
        if (!$post || get_post_type($post) !== 'product') return;
        if (self::$manual_block_rendered) return;
        echo self::render_related_products_block(['productId' => $post->ID, 'limit' => 8]);
    }

    private static $manual_block_rendered = false;

    public static function render_related_products_block_public($attrs) {
        self::$manual_block_rendered = true;
        return self::render_related_products_block($attrs);
    }
}
