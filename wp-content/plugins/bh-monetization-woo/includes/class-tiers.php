<?php
if (!defined('ABSPATH')) exit;

/**
 * Supporter tiers — the Patreon-lite foundation. A tier is a real
 * bh_tier post (title = tier name, ordered by price via _bhm_price_cents
 * meta, a benefits description, and a link to whatever WooCommerce
 * product actually sells it). Deliberately a CPT, not an option array —
 * an artist wants the same familiar "add new, edit, reorder" admin
 * experience as everything else in this ecosystem (bhs_release, bhs_feed_source),
 * not a bespoke settings-page form builder.
 *
 * Each tier's WooCommerce product is created/kept in sync automatically
 * (see sync_wc_product() below) — the artist edits price/name here,
 * never in WooCommerce's own product screens directly, keeping the
 * "wrap it" promise: WooCommerce runs underneath, mostly invisible.
 *
 * Recurring billing note: if WooCommerce Subscriptions is active
 * (detected, never required — see bh-monetization-woo.php's own
 * docblock), a tier's product is a real subscription product with
 * actual recurring billing. If it ISN'T active, a tier's product is a
 * plain one-time WooCommerce product, and the admin UI says so plainly
 * ("one-time support — no recurring billing without WooCommerce
 * Subscriptions") rather than pretending to offer recurring billing
 * this plugin doesn't actually have the machinery to enforce.
 */
class BHM_Tiers {
    const CPT = 'bhm_tier';

    public static function init() {
        add_action('init', [self::class, 'register_post_type']);
        add_action('save_post_' . self::CPT, [self::class, 'save']);
        add_action('add_meta_boxes', [self::class, 'add_meta_box']);
    }

    public static function register_post_type() {
        register_post_type(self::CPT, [
            'labels' => [
                'name' => 'Supporter Tiers', 'singular_name' => 'Tier', 'add_new_item' => 'Add New Tier',
                'edit_item' => 'Edit Tier', 'all_items' => 'All Tiers',
            ],
            // Nested under WooCommerce's OWN top-level menu when it's
            // active — the same "lives where a WooCommerce admin
            // already expects money-adjacent settings to live" instinct
            // WooCommerce's own extensions (Subscriptions, Bookings,
            // etc.) follow, rather than a bare top-level "Supporter
            // Tiers" item competing for sidebar space against both
            // WooCommerce and Own Ur Shit. Falls back to a real
            // top-level menu if WooCommerce isn't active yet (this
            // plugin's whole premise needs it eventually, but an admin
            // configuring things BEFORE installing WooCommerce — or
            // during the brief window this plugin's own admin notice
            // is asking them to — must still be able to find this
            // screen at all, not have it silently vanish into a parent
            // menu slug that doesn't exist yet).
            'public' => false, 'show_ui' => true, 'show_in_menu' => class_exists('WooCommerce') ? 'woocommerce' : true,
            'menu_icon' => 'dashicons-star-filled', 'supports' => ['title'], 'capability_type' => 'post',
        ]);
    }

    public static function add_meta_box() {
        add_meta_box('bhm_tier_details', 'Tier Details', [self::class, 'render_metabox'], self::CPT, 'normal', 'high');
    }

    public static function render_metabox($post) {
        wp_nonce_field('bhm_save_tier', 'bhm_tier_nonce');
        $price = (int) get_post_meta($post->ID, '_bhm_price_cents', true);
        $benefits = (string) get_post_meta($post->ID, '_bhm_benefits', true);
        $wc_product_id = (int) get_post_meta($post->ID, '_bhm_wc_product_id', true);
        $has_subs = class_exists('WC_Subscriptions');

        if (!class_exists('WooCommerce')) {
            echo '<p class="description">WooCommerce isn\'t active yet — this tier will start selling automatically once it is. See <strong>Own Ur Shit → Monetization Settings</strong> to install it.</p>';
        }

        echo '<p><label><strong>Monthly price (USD)</strong><br><input type="number" step="0.01" min="0.50" name="bhm_price" value="' . esc_attr($price ? number_format($price / 100, 2, '.', '') : '') . '" style="width:160px;"></label></p>';
        echo '<p><label><strong>Benefits</strong> <span class="description">(shown to fans on the tier picker and on paywall notices)</span><br><textarea name="bhm_benefits" rows="3" style="width:100%;">' . esc_textarea($benefits) . '</textarea></label></p>';

        if ($has_subs) {
            echo '<p class="description">Real recurring billing via WooCommerce Subscriptions.</p>';
        } else {
            echo '<p class="description"><strong>WooCommerce Subscriptions isn\'t active</strong> — this tier will sell as a one-time purchase (30 days of access, no automatic renewal) until it is. Fans will see this plainly at checkout; nothing here silently promises recurring billing it can\'t enforce.</p>';
        }

        if ($wc_product_id && function_exists('wc_get_product') && wc_get_product($wc_product_id)) {
            echo '<p><a href="' . esc_url(get_edit_post_link($wc_product_id)) . '" target="_blank">View the underlying WooCommerce product &rarr;</a></p>';
        }
    }

    public static function save($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhm_tier_nonce']) || !wp_verify_nonce($_POST['bhm_tier_nonce'], 'bhm_save_tier')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $price_cents = isset($_POST['bhm_price']) ? (int) round(((float) $_POST['bhm_price']) * 100) : 0;
        update_post_meta($post_id, '_bhm_price_cents', $price_cents);
        if (isset($_POST['bhm_benefits'])) update_post_meta($post_id, '_bhm_benefits', sanitize_textarea_field($_POST['bhm_benefits']));

        if (class_exists('WooCommerce')) {
            BHM_Products::sync_tier_wc_product($post_id, get_the_title($post_id), $price_cents);
        }
    }

    /* ---------- read helpers used by BHM_Gate and the fan-facing tier picker ---------- */

    public static function get($tier_id) {
        $post = get_post((int) $tier_id);
        if (!$post || $post->post_type !== self::CPT || $post->post_status !== 'publish') return null;
        return [
            'id' => $post->ID, 'name' => $post->post_title,
            'price_cents' => (int) get_post_meta($post->ID, '_bhm_price_cents', true),
            'benefits' => (string) get_post_meta($post->ID, '_bhm_benefits', true),
            'wc_product_id' => (int) get_post_meta($post->ID, '_bhm_wc_product_id', true),
        ];
    }

    public static function all() {
        $posts = get_posts(['post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => -1]);
        $tiers = array_map(function ($p) { return self::get($p->ID); }, $posts);
        usort($tiers, function ($a, $b) { return $a['price_cents'] <=> $b['price_cents']; });
        return $tiers;
    }

    // Every tier ID priced at or above $tier_id's own price — this is
    // what makes a $10/mo tier's supporters also count as satisfying a
    // $5/mo paywall, without hardcoding a fixed tier count/order.
    public static function ids_at_or_above($tier_id) {
        $target = self::get($tier_id);
        if (!$target) return [];
        $ids = [];
        foreach (self::all() as $t) {
            if ($t['price_cents'] >= $target['price_cents']) $ids[] = $t['id'];
        }
        return $ids;
    }

    public static function tiers_page_url() {
        $page_id = (int) get_option('bhm_tiers_page_id', 0);
        return $page_id ? get_permalink($page_id) : home_url('/');
    }
}
