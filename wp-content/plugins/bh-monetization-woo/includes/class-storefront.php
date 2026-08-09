<?php
if (!defined('ABSPATH')) exit;

/**
 * The storefront/merchandising layer (ROADMAP-platform-evolution.md
 * Section 5) — product collections, category/collection landing pages,
 * and browse/search/filter controls, built as real BH_Studio pages
 * (not a reskin of WooCommerce's stock shop/archive templates). Same
 * "own the interface, don't reimplement WooCommerce's hard parts"
 * posture as the rest of this plugin: WooCommerce still owns the
 * actual product data, cart, and checkout — this class only owns how
 * products get BROWSED and DISCOVERED, aiming for
 * Amazon/Apple/Shopify-quality collections/categories/listing/browse-filter.
 *
 * Two new pieces on top of what already exists:
 *
 *   1. `bhm_collection` — a plain WordPress taxonomy on WooCommerce's
 *      own `product` post type. Deliberately a taxonomy, not a new CPT/
 *      table — curated merchandising collections ("Tour Exclusives",
 *      "New This Month") are conceptually identical to WooCommerce's
 *      own product categories (many-to-many product grouping with a
 *      term archive), so reusing WordPress's existing, well-understood
 *      taxonomy machinery costs nothing and interoperates for free with
 *      anything already taxonomy-aware.
 *   2. `bhm/product-grid` and `bhm/product-filter` — two new BH_Content
 *      block types (dynamic: rendered server-side from live WooCommerce
 *      data on every request, not baked into the stored block tree),
 *      registered with BH_Studio the same way BH_Studio's own core
 *      block types are. An artist builds a collection/category landing
 *      page BY DRAGGING THESE ONTO THE CANVAS alongside bh/heading,
 *      bh/text, bh/image, etc. — real page-building, not a fixed
 *      template with configuration options.
 */
/**
 * Scope note: bhm/product-grid and bhm/product-filter are BH_Content
 * block types (rendered via BH_Content::render(), independent of
 * WordPress's own block-render pipeline) — correct and sufficient for
 * every current use (collection landing pages, any bhm_collection-
 * context document). If either is ever placed inside a document stored
 * against context_type='post' AND that post is later rendered through
 * WordPress's normal the_content()/render_block() path instead of
 * BH_Content::render() directly, it would need a matching real
 * register_block_type() call with a server-side render_callback — not
 * added here, since no current consumer renders collection/storefront
 * documents that way. Flagging the boundary rather than silently
 * building for a case nothing exercises yet.
 */
class BHM_Storefront {
    const TAXONOMY = 'bhm_collection';
    const REWRITE_SLUG = 'shop-collection';

    public static function init() {
        add_action('init', [self::class, 'register_taxonomy']);
        add_action('init', [self::class, 'add_rewrite']);
        add_filter('query_vars', [self::class, 'add_query_var']);
        add_action('template_redirect', [self::class, 'maybe_render_collection']);
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('admin_enqueue_scripts', [self::class, 'maybe_enqueue_studio_blocks']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_frontend_assets']);

        // Real Gutenberg registration, ROADMAP-platform-evolution.md
        // Section 5's payoff: an artist authoring a product's own
        // long-description content (WooCommerce products already
        // support the ordinary post editor) can now drop these blocks
        // in directly, not just inside BH_Studio's separate canvas — the
        // exact boundary this file's own docblock previously flagged as
        // "not added here, since no current consumer renders storefront
        // documents that way." register_block_type()'s render_callback
        // reuses the SAME PHP renderers BH_Content already calls; only
        // the registration mechanism differs (WordPress core's own
        // do_blocks()/render_block() pipeline vs. BH_Content::render()).
        add_action('init', [self::class, 'register_core_blocks']);
        add_action('enqueue_block_editor_assets', [self::class, 'enqueue_editor_blocks']);
        // Real gap: WooCommerce core
        // unconditionally hardcodes the block editor OFF for products
        // (WC_Post_Types::gutenberg_can_edit_post_type() always returns
        // false for post_type 'product', priority 10) — so a product's
        // own long-description content couldn't ever actually be
        // composed with bh/*/bhm/* blocks no matter what got registered
        // above; it was still stuck on the classic TinyMCE editor.
        // Overriding back to true at a later priority is the "wrap
        // WooCommerce, don't fight it" version of turning this on,
        // matching how this ecosystem already treats every other
        // WooCommerce screen as something to enhance rather than route
        // around.
        add_filter('use_block_editor_for_post_type', [self::class, 'enable_block_editor_for_products'], 20, 2);
        // Zero-authoring default: every real single-product page gets a
        // related-items section for free. Priority 20 so it runs after
        // WooCommerce's own tabs (Description, etc. — priority 10) have
        // already rendered, so an explicitly-placed bhm/related-products
        // block inside the description has already set the
        // "don't double-render" flag by the time this checks it.
        //
        // Real bug, caught live: WooCommerce core hooks its OWN default
        // related-products output (woocommerce_output_related_products)
        // to this exact same action at this exact same priority — since
        // both land on the same hook+priority, WordPress runs them in
        // registration order and BOTH rendered, showing the same
        // recommended product twice on every single-product page in two
        // different visual treatments (this plugin's styled "You may
        // also like" grid, then WooCommerce's own plain list right below
        // it). Removing the core callback so this plugin's version is
        // the only one shown — same content, one considered
        // presentation instead of a redundant duplicate.
        remove_action('woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20);
        add_action('woocommerce_after_single_product_summary', ['BHM_Recommendations', 'auto_render_related'], 20);
        // The remove_action above only covers a CLASSIC theme's
        // template-hook based related-products output — live-checked on
        // this (block theme) install and the actual duplicate was
        // coming from a completely different source: the current
        // active theme's single-product block TEMPLATE itself ships a
        // woocommerce/product-collection block hardcoded to the
        // "related" collection (data-collection=
        // "woocommerce/product-collection/related", confirmed via the
        // rendered block's own attributes) — an entirely separate
        // rendering path the classic remove_action can't reach.
        // Suppressing it at render time so whichever theme/template this
        // runs under, only this plugin's own styled related-products
        // section shows, not a duplicate.
        add_filter('render_block', [self::class, 'suppress_core_related_products_block'], 10, 2);

        if (class_exists('BH_Studio')) {
            add_filter('bh_studio_block_types', [self::class, 'register_studio_block_types']);
        }
        if (class_exists('BH_Content')) {
            self::register_content_block_types();
        }
    }

    public static function enable_block_editor_for_products($can_edit, $post_type) {
        return $post_type === 'product' ? true : $can_edit;
    }

    // Real WordPress block registration (distinct from BH_Content's own
    // registration above, which only ever renders through
    // BH_Content::render() — a product's post_content goes through
    // core's own the_content()/do_blocks() instead). Dynamic blocks
    // (client save() returns null), so post_content only ever stores
    // the block comment + attributes, never baked HTML — same "live
    // WooCommerce data on every request" posture as the BH_Content
    // registration.
    // Audit fix (2026-07-25): the three attribute schemas below were
    // hand-duplicated between here (WP-core 'boolean'/'integer' type
    // names) and register_content_block_types() below (BH_Content's own
    // 'bool'/'int' shorthand) — same field names/defaults, just spelled
    // per-system. BLOCK_SCHEMAS is the one place a field/default now
    // lives; both registration methods derive their own type-name
    // format from it via core_attributes()/content_attributes().
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

    // BH_Content's own shorthand type names ('int'/'bool') -> WP core
    // block.json type names ('integer'/'boolean'); anything else
    // (currently just 'string') already matches both systems as-is.
    private static function core_attributes($schema) {
        $map = ['int' => 'integer', 'bool' => 'boolean'];
        $out = [];
        foreach ($schema as $key => $def) {
            $out[$key] = ['type' => $map[$def['type']] ?? $def['type'], 'default' => $def['default']];
        }
        return $out;
    }

    public static function register_core_blocks() {
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

    // Unlike maybe_enqueue_studio_blocks() (gated to the bh-studio admin
    // page only), this fires on EVERY real block editor screen — a
    // product edit screen, a page, anywhere — so these blocks show up
    // in the ordinary inserter wherever an artist is actually composing
    // real post_content. The client registration file itself already
    // guards on wp.blocks/wp.element/etc. existing, so enqueuing it
    // broadly is safe.
    // Audit fix (2026-07-25): this used to register the SAME file
    // (storefront-studio-blocks.js) under two different handles —
    // 'bhm-storefront-studio-blocks-core' here and
    // 'bhm-storefront-studio-blocks' in maybe_enqueue_studio_blocks()
    // below — with two different dependency lists, for no reason other
    // than the two call sites having different gating conditions. One
    // shared handle now (self::STUDIO_BLOCKS_HANDLE); if the 'bh-studio'
    // script happens to already be registered when this fires (broad
    // "every block editor screen" call site, not just the Studio canvas
    // one), it's included as a dependency too — harmless either way,
    // since Studio's own JS already no-ops without its canvas markup.
    const STUDIO_BLOCKS_HANDLE = 'bhm-storefront-studio-blocks';

    public static function enqueue_editor_blocks() {
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

    public static function register_taxonomy() {
        if (!post_type_exists('product')) return; // WooCommerce not active yet — nothing to attach to
        register_taxonomy(self::TAXONOMY, ['product'], [
            'labels' => ['name' => 'Collections', 'singular_name' => 'Collection'],
            'public' => true, 'hierarchical' => false, 'show_admin_column' => true,
            'show_in_rest' => true,
            'rewrite' => ['slug' => self::REWRITE_SLUG],
        ]);
    }

    // Bump whenever add_rewrite()/register_taxonomy() below change what
    // they register — same versioned-flush convention BHI_Portal's own
    // add_rewrite() uses (see class-portal.php), keyed off REWRITE_VERSION
    // rather than a single boolean so a FUTURE rule change also self-heals,
    // not just this one.
    // Bumped to 2 — see BHI_Portal::REWRITE_VERSION's own comment
    // (class-portal.php) for the full story: a real install's persistent
    // object cache was serving a stale copy of the rewrite_rules option
    // back even after a correct flush_rewrite_rules() call, confirmed via
    // that class's own Debug Tools diagnostic. Same explicit cache-evict
    // fix applied here.
    const REWRITE_VERSION = '2';
    const VERIFY_THROTTLE_SECONDS = 60;

    // Upgraded to BHI_Portal's self-verifying shape (class-portal.php) —
    // the version-gated "flush once, mark option done, never re-check"
    // pattern this replaced is the EXACT bug class a live install
    // already hit on Portal's own rewrite rule: update_option() marking
    // itself successful while a persistent object cache kept serving the
    // stale rewrite_rules value forever after, with no way to tell from
    // the outside. This had the identical shape and was flagged as a
    // near-identical unfixed instance during a broader logging/error-
    // handling audit, not from a live report on THIS specific rule — so
    // treat it as preventative, not confirmed-broken.
    // Audit fix (2026-07-25): this self-heal algorithm (persistence
    // check/throttle/flush/log) is now shared via BHY_RewriteHealer
    // (own-ur-shit/includes/class-rewrite-healer.php) instead of a
    // hand-synced copy of BHI_Portal's own version — that extraction
    // also fixed a real latent bug this copy had fallen behind on: the
    // old force_flush_and_verify() below called wp_cache_flush()
    // unconditionally on every attempt, the exact pattern Portal's own
    // history already identified as risky (see BHY_RewriteHealer's own
    // docblock for the full story). This is now escalation-only, same
    // as Portal.
    public static function add_rewrite() {
        add_rewrite_rule('^' . self::REWRITE_SLUG . '/([^/]+)/?$', 'index.php?bhm_collection_slug=$matches[1]', 'top');

        if (class_exists('BHY_RewriteHealer')) {
            BHY_RewriteHealer::maybe_heal('^' . self::REWRITE_SLUG, 'bhm_storefront_rewrite_last_attempt', 'BH Storefront', self::VERIFY_THROTTLE_SECONDS);
        }
    }

    public static function add_query_var($vars) {
        $vars[] = 'bhm_collection_slug';
        return $vars;
    }

    /* ---------------- collection landing page ---------------- */

    // The landing page itself: an optional BH_Content-authored hero/
    // intro region (context_type='bhm_collection', context_id=term_id —
    // empty/unauthored by default, same "graceful default" every other
    // BH_Content consumer in this ecosystem follows) above an
    // auto-generated product grid for every product in that collection.
    // An artist who wants a fully custom layout instead just builds one
    // in BH_Studio using bhm/product-grid directly with this collection
    // pre-selected — this route is the zero-effort default, not the
    // only way to show a collection.
    public static function maybe_render_collection() {
        $slug = get_query_var('bhm_collection_slug');
        if (!$slug) return;

        $term = get_term_by('slug', sanitize_title($slug), self::TAXONOMY);
        if (!$term) { self::render_404(); return; }

        status_header(200);
        nocache_headers();
        self::render_collection_page($term);
        exit;
    }

    // Real bug, caught live: plain get_header()/get_footer() only covers
    // a CLASSIC theme, which ships real header.php/footer.php files —
    // a block theme (Twenty Twenty-Five and every core theme since 5.9,
    // and any future theme built for this ecosystem) ships none, so
    // those calls silently fell through to WordPress core's own
    // decades-old bare theme-compat stub with no nav, no real chrome,
    // nothing this site actually looks like. Same failure mode
    // bh-courses' own templates/archive-bh_course.php already hit and
    // fixed (see that file's docblock) — block_header_area()/
    // block_footer_area() are WordPress's own documented way to print
    // whatever the active block theme's real header/footer template
    // parts are, so this renders correctly regardless of which theme
    // (classic or block, this ecosystem's own or a third-party one) is
    // active. This is the concrete fix for a real architectural rule:
    // plugin-owned pages must be theme-independent — never assume a
    // specific theme's structure, never break silently when the active
    // theme changes.
    private static function print_header() {
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

    private static function print_footer() {
        if (function_exists('wp_is_block_theme') && wp_is_block_theme() && function_exists('block_footer_area')) {
            block_footer_area();
            wp_footer();
            ?></body></html><?php
        } else {
            get_footer();
        }
    }

    private static function render_404() {
        status_header(404);
        nocache_headers();
        self::print_header();
        echo '<div class="bhm-storefront-wrap"><p>That collection doesn\'t exist.</p></div>';
        self::print_footer();
        exit;
    }

    private static function render_collection_page($term) {
        self::print_header();
        echo '<div class="bhm-storefront-wrap">';
        echo '<h1 class="bhm-collection-title">' . esc_html($term->name) . '</h1>';
        if ($term->description) echo '<p class="bhm-collection-desc">' . esc_html($term->description) . '</p>';

        if (class_exists('BH_Content')) {
            $hero_tree = BH_Content::get('bhm_collection', $term->term_id);
            if ($hero_tree) echo BH_Content::render($hero_tree);
        }

        // PHPStan caught a stray second argument — render_product_grid_block()
        // only ever took one ($attrs); the '' was leftover cruft (harmless
        // at runtime, PHP silently ignores an extra arg, but not correct).
        echo self::render_product_grid_block(['collection' => $term->slug, 'columns' => 4, 'limit' => 24, 'showFilters' => true]);
        echo '</div>';
        self::print_footer();
    }

    /* ---------------- BH_Content block registration (server render) ---------------- */

    private static function register_content_block_types() {
        BH_Content::register_block_type('bhm/product-grid', self::BLOCK_SCHEMAS['bhm/product-grid'], [self::class, 'render_product_grid_block']);
        BH_Content::register_block_type('bhm/product-filter', self::BLOCK_SCHEMAS['bhm/product-filter'], [self::class, 'render_product_filter_block']);

        if (class_exists('BHM_Recommendations')) {
            BH_Content::register_block_type('bhm/related-products', self::BLOCK_SCHEMAS['bhm/related-products'], ['BHM_Recommendations', 'render_related_products_block_public']);
        }
    }

    /**
     * A dynamic block's real renderer — queries live WooCommerce data on
     * every request rather than baking a product list into the stored
     * block tree, the same reason Gutenberg core's own "Latest Posts"
     * block works this way.
     *
     * Audit fix (2026-07-25): the previous version of this comment
     * claimed "$attrs here always has every schema key filled... so no
     * isset()/empty() guarding is needed" — that's only true for the
     * one caller that goes through BH_Content::validate() (the block-
     * render path). This method is ALSO called directly with hand-built
     * arrays from maybe_render_collection() and (for the filter block)
     * render_related_products_block(), neither of which validates first
     * — the defensive max(1,min(...))/!empty() guards below are real and
     * load-bearing for those two callers, not leftover caution to be
     * removed on a future cleanup pass.
     */
    public static function render_product_grid_block($attrs) {
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

    public static function render_product_filter_block($attrs) {
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

    public static function render_product_cards($products, $is_filtered = false) {
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

    private static function query_products($args) {
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

        // Price filtering isn't a native wc_get_products() arg (it varies
        // sale/regular/variable pricing in ways a single meta_query can't
        // cleanly express) — filtered here instead, on the already-
        // narrowed result set, which is fine at the catalog sizes this
        // ecosystem targets (a solo artist's merch store, not a
        // thousand-SKU marketplace).
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

    public static function register_routes() {
        register_rest_route('ous/v1', '/storefront/products', [
            'methods' => 'GET',
            'permission_callback' => '__return_true', // public catalog browsing — same openness as WooCommerce's own shop pages
            'callback' => [self::class, 'rest_query_products'],
        ]);
    }

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

    public static function enqueue_frontend_assets() {
        wp_enqueue_style('bhm-storefront', BHM_URL . 'assets/css/storefront.css', [], defined('BHM_VER') ? BHM_VER : null);
        wp_register_script('bhm-storefront-filter', BHM_URL . 'assets/js/storefront-filter.js', [], defined('BHM_VER') ? BHM_VER : null, true);
        wp_enqueue_script('bhm-storefront-filter');
        wp_localize_script('bhm-storefront-filter', 'bhmStorefrontConfig', [
            'restUrl' => esc_url_raw(rest_url('ous/v1/storefront/products')),
        ]);
    }

    // Only when BH_Studio's own canvas is the page being loaded — same
    // hook-name-substring check class-studio.php itself uses for its
    // own asset gating, kept consistent rather than inventing a second
    // convention.
    public static function maybe_enqueue_studio_blocks($hook) {
        if (strpos($hook, 'bh-studio') === false) return;
        if (!wp_script_is('bh-studio', 'enqueued') && !wp_script_is('bh-studio', 'registered')) return; // BH_Studio itself isn't active/loaded
        if (wp_script_is(self::STUDIO_BLOCKS_HANDLE, 'registered')) {
            wp_enqueue_script(self::STUDIO_BLOCKS_HANDLE);
            return;
        }
        // Not yet registered by enqueue_editor_blocks() on this request
        // (shouldn't normally happen on a real block-editor screen, but
        // guards the ordering assumption rather than fataling if it did).
        wp_enqueue_script(
            self::STUDIO_BLOCKS_HANDLE,
            BHM_URL . 'assets/js/storefront-studio-blocks.js',
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-api-fetch', 'bh-studio'],
            defined('BHM_VER') ? BHM_VER : null,
            true
        );
    }

    public static function register_studio_block_types($types) {
        $types['bhm/product-grid'] = ['tag' => 'div', 'category' => 'commerce', 'label' => 'Product Grid'];
        $types['bhm/product-filter'] = ['tag' => 'form', 'category' => 'commerce', 'label' => 'Product Filter'];
        $types['bhm/related-products'] = ['tag' => 'div', 'category' => 'commerce', 'label' => 'Related Products'];
        return $types;
    }

    // See the add_filter('render_block', ...) call above for why this
    // exists — only ever matches the one specific WooCommerce core
    // block/collection combination (identified the same way the browser
    // itself reports it: block name + the 'related' collection id),
    // never any other product-collection block a site owner might
    // deliberately place elsewhere (a "featured products" or manually
    // curated collection block is left completely alone).
    public static function suppress_core_related_products_block($block_content, $block) {
        if (($block['blockName'] ?? '') !== 'woocommerce/product-collection') return $block_content;
        if (($block['attrs']['collection'] ?? '') !== 'woocommerce/product-collection/related') return $block_content;
        return '';
    }
}
