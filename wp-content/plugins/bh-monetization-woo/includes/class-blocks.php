<?php
if (!defined('ABSPATH')) exit;

/**
 * Server-rendered blocks (wp.serverSideRender) for buy button, tip jar and tiers, so the
 * editor shows the real output. The matching shortcodes stay registered for existing content.
 */
class BHM_Blocks {
    // WHY: init() already runs inside 'init', so register directly; a nested
    // add_action('init', ...) at the same priority would never fire this request.
    public static function init(): void {
        self::register_block();
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_block(): void {
        if (!function_exists('register_block_type')) return;

        wp_register_script(
            'bhm-buy-block',
            BHM_URL . 'assets/js/bhm-blocks.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-server-side-render'],
            BHM_VER,
            true
        );

        register_block_type('bhm/buy', [
            'editor_script'   => 'bhm-buy-block',
            'render_callback' => [self::class, 'render_buy'],
            'attributes'      => [
                'id' => ['type' => 'number', 'default' => 0],
            ],
        ]);

        // Tip jar and tiers are site-wide, so they take no attributes.
        register_block_type('bhm/tip-jar', [
            'editor_script'   => 'bhm-buy-block',
            'render_callback' => [self::class, 'render_tip_jar'],
        ]);

        register_block_type('bhm/tiers', [
            'editor_script'   => 'bhm-buy-block',
            'render_callback' => [self::class, 'render_tiers'],
        ]);
    }

    // Also the front-end render: the block saves no markup (save() returns null).
    /** @param array<string, mixed> $attributes */
    public static function render_buy($attributes): string {
        $id = (int) ($attributes['id'] ?? 0);
        if (!$id) return '';
        return BHM_Frontend::render_purchase_button(['id' => $id]);
    }

    /** @param array<string, mixed> $attributes */
    public static function render_tip_jar($attributes): string {
        return BHM_Frontend::render_tip_jar($attributes);
    }

    /** @param array<string, mixed> $attributes */
    public static function render_tiers($attributes): string {
        return BHM_Frontend::render_tiers();
    }

    public static function register_routes(): void {
        register_rest_route('bhm/v1', '/purchasable-objects', [
            'methods' => 'GET',
            'callback' => [self::class, 'rest_purchasable_objects'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
        ]);
    }

    // Inspector picker: only tracks/releases with a purchase price, so nothing renders blank.
    /** @return \WP_REST_Response */
    public static function rest_purchasable_objects(\WP_REST_Request $req) {
        $q = new WP_Query([
            'post_type' => ['bhs_track', 'bhs_release'],
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_key' => '_bhm_purchase_price_cents',
            'meta_compare' => 'EXISTS',
        ]);
        $out = [];
        foreach ($q->posts as $p) {
            $cents = (int) get_post_meta($p->ID, '_bhm_purchase_price_cents', true);
            if (!$cents) continue;
            $out[] = [
                'id' => $p->ID,
                'title' => $p->post_title,
                'type' => $p->post_type === 'bhs_release' ? 'Album' : 'Track',
                'price' => BHM_Money::display($cents),
            ];
        }
        return new WP_REST_Response($out, 200);
    }
}
