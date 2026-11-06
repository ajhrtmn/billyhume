<?php
if (!defined('ABSPATH')) exit;

/**
 * Storefront layer: the bhm_collection taxonomy, collection landing pages, and
 * dynamic product grid/filter blocks. WooCommerce still owns product data, cart and checkout.
 */
class BHM_Storefront {
    const TAXONOMY = 'bhm_collection';
    const REWRITE_SLUG = 'shop-collection';

    public static function init(): void {
        add_action('init', [self::class, 'register_taxonomy']);
        add_action('init', [self::class, 'add_rewrite']);
        add_filter('query_vars', [self::class, 'add_query_var']);
        add_action('template_redirect', [self::class, 'maybe_render_collection']);
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('admin_enqueue_scripts', [self::class, 'maybe_enqueue_studio_blocks']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_frontend_assets']);

        // Real Gutenberg registration so these blocks work in ordinary post_content too.
        add_action('init', [self::class, 'register_core_blocks']);
        add_action('enqueue_block_editor_assets', [self::class, 'enqueue_editor_blocks']);
        // WHY: WooCommerce forces the block editor off for products at priority 10.
        add_filter('use_block_editor_for_post_type', [self::class, 'enable_block_editor_for_products'], 20, 2);
        // WHY: priority 20 runs after the description tab, so an explicit related-products block
        // has already set its no-double-render flag. WooCommerce's own related output is removed
        // from the same hook+priority, otherwise both render.
        remove_action('woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20);
        add_action('woocommerce_after_single_product_summary', ['BHM_Recommendations', 'auto_render_related'], 20);
        // Block themes render related products from the template's product-collection block instead.
        add_filter('render_block', [self::class, 'suppress_core_related_products_block'], 10, 2);

        if (class_exists('BH_Studio')) {
            add_filter('bh_studio_block_types', [self::class, 'register_studio_block_types']);
        }
        if (class_exists('BH_Content')) {
            self::register_content_block_types();
        }
    }

    public static function enable_block_editor_for_products(bool $can_edit, string $post_type): bool {
        return $post_type === 'product' ? true : $can_edit;
    }

    // Single source for block attributes; WP core and BH_Content registrations both derive from it.
    // Dynamic blocks: post_content stores only attributes, never baked HTML.
    const BLOCK_SCHEMAS = [
        'bhm/product-grid' => [
            'collection'  => ['type' => 'string', 'default' => ''],
            'category'    => ['type' => 'string', 'default' => ''],
            'columns'     => ['type' => 'int', 'default' => 4],
            'limit'       => ['type' => 'int', 'default' => 12],
            'showFilters' => ['type' => 'bool', 'default' => false],
        ],
        'bhm/product-filter' => [
            'showPrice'    => ['type' => 'bool', 'default' => true],
            'showCategory' => ['type' => 'bool', 'default' => true],
            'showStock'    => ['type' => 'bool', 'default' => true],
        ],
        'bhm/related-products' => [
            'productId' => ['type' => 'int', 'default' => 0],
            'limit'     => ['type' => 'int', 'default' => 8],
            'heading'   => ['type' => 'string', 'default' => 'You may also like'],
        ],
    ];

    // BH_Content shorthand types ('int'/'bool') -> WP core names ('integer'/'boolean').
    /**
     * @param array<string, array<string, mixed>> $schema
     * @return array<string, array<string, mixed>>
     */
    private static function core_attributes($schema): array {
        $map = ['int' => 'integer', 'bool' => 'boolean'];
        $out = [];
        foreach ($schema as $key => $def) {
            $out[$key] = ['type' => $map[$def['type']] ?? $def['type'], 'default' => $def['default']];
        }
        return $out;
    }

    public static function register_core_blocks(): void {
        if (!function_exists('register_block_type')) return;
        register_block_type('bhm/product-grid', [
            'api_version' => 3,
            'render_callback' => [self::class, 'render_product_grid_block'],
            'attributes' => self::core_attributes(self::BLOCK_SCHEMAS['bhm/product-grid']),
        ]);
        register_block_type('bhm/product-filter', [
            'api_version' => 3,
            'render_callback' => [self::class, 'render_product_filter_block'],
            'attributes' => self::core_attributes(self::BLOCK_SCHEMAS['bhm/product-filter']),
        ]);
        if (class_exists('BHM_Recommendations')) {
            register_block_type('bhm/related-products', [
                'api_version' => 3,
                'render_callback' => ['BHM_Recommendations', 'render_related_products_block_public'],
                'attributes' => self::core_attributes(self::BLOCK_SCHEMAS['bhm/related-products']),
            ]);
        }
    }

    // One handle shared by both enqueue paths. enqueue_editor_blocks() runs on every block
    // editor screen; the script itself guards on wp.blocks etc. existing.
    const STUDIO_BLOCKS_HANDLE = 'bhm-storefront-studio-blocks';

    public static function enqueue_editor_blocks(): void {
        $deps = ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor'];
        if (wp_script_is('bh-studio', 'registered')) $deps[] = 'bh-studio';
        wp_enqueue_script(
            self::STUDIO_BLOCKS_HANDLE,
            BHM_URL . 'assets/js/storefront-studio-blocks.js',
            $deps,
            defined('BHM_VER') ? BHM_VER : null,
            true
        );
    }

    /* ---------------- collections taxonomy ---------------- */

    public static function register_taxonomy(): void {
        if (!post_type_exists('product')) return; // WooCommerce not active yet — nothing to attach to
        register_taxonomy(self::TAXONOMY, ['product'], [
            'labels' => ['name' => 'Collections', 'singular_name' => 'Collection'],
            'public' => true, 'hierarchical' => false, 'show_admin_column' => true,
            'show_in_rest' => true,
            'rewrite' => ['slug' => self::REWRITE_SLUG],
        ]);
    }

    // Bump whenever add_rewrite()/register_taxonomy() change what they register.
    const REWRITE_VERSION = '2';
    const VERIFY_THROTTLE_SECONDS = 60;

    // WHY: BHY_RewriteHealer verifies the rule actually persisted; a persistent object
    // cache can serve stale rewrite_rules after a successful flush.
    public static function add_rewrite(): void {
        add_rewrite_rule('^' . self::REWRITE_SLUG . '/([^/]+)/?$', 'index.php?bhm_collection_slug=$matches[1]', 'top');

        if (class_exists('BHY_RewriteHealer')) {
            BHY_RewriteHealer::maybe_heal('^' . self::REWRITE_SLUG, 'bhm_storefront_rewrite_last_attempt', 'BH Storefront', self::VERIFY_THROTTLE_SECONDS);
        }
    }

    /**
     * @param array<int, string> $vars
     * @return array<int, string>
     */
    public static function add_query_var($vars): array {
        $vars[] = 'bhm_collection_slug';
        return $vars;
    }

    /* ---------------- collection landing page ---------------- */

    // Optional BH_Content hero (context bhm_collection/term_id) above an auto-generated product grid.
    public static function maybe_render_collection(): void {
        $slug = get_query_var('bhm_collection_slug');
        if (!$slug) return;

        $term = get_term_by('slug', sanitize_title($slug), self::TAXONOMY);
        if (!$term) { self::render_404(); return; }

        status_header(200);
        nocache_headers();
        self::render_collection_page($term);
        exit;
    }

    // WHY: block themes ship no header.php/footer.php, so get_header() would fall back to
    // core's bare theme-compat stub; block_header_area() prints the real template part.
    private static function print_header(): void {
        if (function_exists('wp_is_block_theme') && wp_is_block_theme() && function_exists('block_header_area')) {
            ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php block_header_area();
        } else {
            get_header();
        }
    }

    private static function print_footer(): void {
        if (function_exists('wp_is_block_theme') && wp_is_block_theme() && function_exists('block_footer_area')) {
            block_footer_area();
            wp_footer();
            ?></body></html><?php
        } else {
            get_footer();
        }
    }

    private static function render_404(): void {
        status_header(404);
        nocache_headers();
        self::print_header();
        echo '<div class="bhm-storefront-wrap"><p>That collection doesn\'t exist.</p></div>';
        self::print_footer();
        exit;
    }

    private static function render_collection_page(\WP_Term $term): void {
        self::print_header();
        echo '<div class="bhm-storefront-wrap">';
        echo '<h1 class="bhm-collection-title">' . esc_html($term->name) . '</h1>';
        if ($term->description) echo '<p class="bhm-collection-desc">' . esc_html($term->description) . '</p>';

        if (class_exists('BH_Content')) {
            $hero_tree = BH_Content::get('bhm_collection', $term->term_id);
            if ($hero_tree) echo BH_Content::render($hero_tree);
        }

        echo self::render_product_grid_block(['collection' => $term->slug, 'columns' => 4, 'limit' => 24, 'showFilters' => true]);
        echo '</div>';
        self::print_footer();
    }

    /* ---------------- BH_Content block registration (server render) ---------------- */

    private static function register_content_block_types(): void {
        BH_Content::register_block_type('bhm/product-grid', self::BLOCK_SCHEMAS['bhm/product-grid'], [self::class, 'render_product_grid_block']);
        BH_Content::register_block_type('bhm/product-filter', self::BLOCK_SCHEMAS['bhm/product-filter'], [self::class, 'render_product_filter_block']);

        if (class_exists('BHM_Recommendations')) {
            BH_Content::register_block_type('bhm/related-products', self::BLOCK_SCHEMAS['bhm/related-products'], ['BHM_Recommendations', 'render_related_products_block_public']);
        }
    }

    /**
     * Dynamic renderer: queries live WooCommerce data on every request.
     * WHY: also called with unvalidated hand-built $attrs, so the clamps/!empty() guards are load-bearing.
     */
    /** @param array<string, mixed> $attrs */
    public static function render_product_grid_block($attrs): string {
        if (!BH_Commerce::available() || !function_exists('wc_get_products')) {
            return '<p class="description">WooCommerce isn\'t active — nothing to show here yet.</p>';
        }

        $products = self::query_products([
            'collection' => $attrs['collection'],
            'category' => $attrs['category'],
            'limit' => max(1, min(48, (int) $attrs['limit'])),
        ]);

        $columns = max(1, min(6, (int) $attrs['columns']));
        $out = '';
        if (!empty($attrs['showFilters'])) {
            $out .= self::render_product_filter_block(['showPrice' => true, 'showCategory' => true, 'showStock' => true]);
        }
        $out .= '<div class="bhm-product-grid" data-bhm-collection="' . esc_attr($attrs['collection']) . '" data-bhm-category="' . esc_attr($attrs['category']) . '" style="--bhm-grid-cols:' . $columns . ';">';
        $out .= self::render_product_cards($products);
        $out .= '</div>';
        return $out;
    }

    /** @param array<string, mixed> $attrs */
    public static function render_product_filter_block($attrs): string {
        ob_start();
        ?>
        <form class="bhm-product-filter" onsubmit="return false;">
            <?php if (!empty($attrs['showCategory']) && function_exists('get_terms')): ?>
                <label>Category
                    <select class="bhm-filter-category">
                        <option value="">All</option>
                        <?php foreach (get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true]) as $cat): ?>
                            <option value="<?php echo esc_attr($cat->slug); ?>"><?php echo esc_html($cat->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <?php if (!empty($attrs['showPrice'])): ?>
                <label>Min price <input type="number" class="bhm-filter-min-price" min="0" step="0.01"></label>
                <label>Max price <input type="number" class="bhm-filter-max-price" min="0" step="0.01"></label>
            <?php endif; ?>
            <?php if (!empty($attrs['showStock'])): ?>
                <label><input type="checkbox" class="bhm-filter-in-stock"> In stock only</label>
            <?php endif; ?>
            <button type="button" class="bhm-filter-apply button">Apply</button>
        </form>
        <?php
        return ob_get_clean();
    }

    /** @param array<int, mixed> $products */
    public static function render_product_cards($products, bool $is_filtered = false): string {
        if (!$products) {
            return class_exists('BHY_Style') ? BHY_Style::empty_state_html($is_filtered ? [
                'reason' => 'filtered',
                'title' => 'No products match your filters',
            ] : [
                'reason' => 'zero',
                'title' => 'No products yet',
                'description' => 'Products you publish in WooCommerce will show up here.',
            ]) : '<p class="description">No products found' . ($is_filtered ? ' matching your filters.' : '.') . '</p>';
        }
        $out = '';
        foreach ($products as $product) {
            $out .= '<a class="bhm-product-card" href="' . esc_url($product->get_permalink()) . '">';
            $image = $product->get_image('medium');
            $out .= '<div class="bhm-product-card-image">' . $image . '</div>';
            $out .= '<div class="bhm-product-card-title">' . esc_html($product->get_name()) . '</div>';
            $out .= '<div class="bhm-product-card-price">' . wp_kses_post($product->get_price_html()) . '</div>';
            $out .= '</a>';
        }
        return $out;
    }

    /* ---------------- shared product query (used by both the server render above and the REST filter endpoint below) ---------------- */

    /**
     * @param array<string, mixed> $args
     * @return array<int, mixed>
     */
    private static function query_products($args): array {
        $wc_args = [
            'limit' => $args['limit'] ?? 12,
            'page' => $args['page'] ?? 1,
            'status' => 'publish',
        ];
        $tax_query = [];
        if (!empty($args['collection'])) {
            $tax_query[] = ['taxonomy' => self::TAXONOMY, 'field' => 'slug', 'terms' => sanitize_title($args['collection'])];
        }
        if (!empty($args['category'])) {
            $tax_query[] = ['taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => sanitize_title($args['category'])];
        }
        if ($tax_query) $wc_args['tax_query'] = $tax_query; // phpcs:ignore -- wc_get_products() itself proxies this straight into WP_Query, same key name

        if (!empty($args['in_stock'])) $wc_args['stock_status'] = 'instock';

        $products = wc_get_products($wc_args);

        // Price isn't a native wc_get_products() arg; filtered in PHP, fine at solo-artist catalog sizes.
        if (isset($args['min_price']) || isset($args['max_price'])) {
            $min = isset($args['min_price']) ? (float) $args['min_price'] : null;
            $max = isset($args['max_price']) ? (float) $args['max_price'] : null;
            $products = array_filter($products, function ($p) use ($min, $max) {
                $price = (float) $p->get_price();
                if ($min !== null && $price < $min) return false;
                if ($max !== null && $price > $max) return false;
                return true;
            });
        }

        return array_values($products);
    }

    /* ---------------- REST: the filter block's live re-query ---------------- */

    public static function register_routes(): void {
        register_rest_route('ous/v1', '/storefront/products', [
            'methods' => 'GET',
            'permission_callback' => '__return_true', // public catalog browsing — same openness as WooCommerce's own shop pages
            'callback' => [self::class, 'rest_query_products'],
        ]);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function rest_query_products(\WP_REST_Request $req) {
        if (!BH_Commerce::available() || !function_exists('wc_get_products')) {
            return new \WP_Error('bhm_storefront_no_woocommerce', 'WooCommerce is unavailable.', ['status' => 500]);
        }
        $is_filtered = $req->get_param('min_price') !== null || $req->get_param('max_price') !== null || (bool) $req->get_param('in_stock');
        $products = self::query_products([
            'collection' => sanitize_title($req->get_param('collection') ?: ''),
            'category' => sanitize_title($req->get_param('category') ?: ''),
            'min_price' => $req->get_param('min_price') !== null ? (float) $req->get_param('min_price') : null,
            'max_price' => $req->get_param('max_price') !== null ? (float) $req->get_param('max_price') : null,
            'in_stock' => (bool) $req->get_param('in_stock'),
            'limit' => min(48, max(1, (int) ($req->get_param('limit') ?: 24))),
        ]);
        return new \WP_REST_Response(['html' => self::render_product_cards($products, $is_filtered), 'count' => count($products)], 200);
    }

    /* ---------------- assets ---------------- */

    public static function enqueue_frontend_assets(): void {
        wp_enqueue_style('bhm-storefront', BHM_URL . 'assets/css/storefront.css', [], defined('BHM_VER') ? BHM_VER : null);
        wp_register_script('bhm-storefront-filter', BHM_URL . 'assets/js/storefront-filter.js', [], defined('BHM_VER') ? BHM_VER : null, true);
        wp_enqueue_script('bhm-storefront-filter');
        wp_localize_script('bhm-storefront-filter', 'bhmStorefrontConfig', [
            'restUrl' => esc_url_raw(rest_url('ous/v1/storefront/products')),
        ]);
    }

    // Only on BH_Studio's canvas page (same hook-substring check class-studio.php uses).
    public static function maybe_enqueue_studio_blocks(string $hook): void {
        if (strpos($hook, 'bh-studio') === false) return;
        if (!wp_script_is('bh-studio', 'enqueued') && !wp_script_is('bh-studio', 'registered')) return; // BH_Studio itself isn't active/loaded
        if (wp_script_is(self::STUDIO_BLOCKS_HANDLE, 'registered')) {
            wp_enqueue_script(self::STUDIO_BLOCKS_HANDLE);
            return;
        }
        // Fallback if enqueue_editor_blocks() hasn't registered it on this request.
        wp_enqueue_script(
            self::STUDIO_BLOCKS_HANDLE,
            BHM_URL . 'assets/js/storefront-studio-blocks.js',
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-api-fetch', 'bh-studio'],
            defined('BHM_VER') ? BHM_VER : null,
            true
        );
    }

    /**
     * @param array<string, mixed> $types
     * @return array<string, mixed>
     */
    public static function register_studio_block_types($types): array {
        $types['bhm/product-grid'] = ['tag' => 'div', 'category' => 'commerce', 'label' => 'Product Grid'];
        $types['bhm/product-filter'] = ['tag' => 'form', 'category' => 'commerce', 'label' => 'Product Filter'];
        $types['bhm/related-products'] = ['tag' => 'div', 'category' => 'commerce', 'label' => 'Related Products'];
        return $types;
    }

    // Drops only WooCommerce's "related" product-collection block; other collection blocks are untouched.
    /** @param array<string, mixed> $block */
    public static function suppress_core_related_products_block(string $block_content, $block): string {
        if (($block['blockName'] ?? '') !== 'woocommerce/product-collection') return $block_content;
        if (($block['attrs']['collection'] ?? '') !== 'woocommerce/product-collection/related') return $block_content;
        return '';
    }
}
