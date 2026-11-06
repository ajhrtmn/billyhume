<?php
if (!defined('ABSPATH')) exit;

/**
 * Monetization fields (required tier, purchase price, PWYW, pay-per-play) on bh-streaming's
 * track/release editor. Render and save only; gating lives elsewhere.
 */
class BHM_MonetizationUI {
    public static function init(): void {
        if (class_exists('BHS_Admin')) {
            add_action('bhs_track_monetization_ui', [self::class, 'render_track_ui']);
            add_action('bhs_release_monetization_ui', [self::class, 'render_release_ui']);
            add_action('bhs_track_monetization_save', [self::class, 'save_track']);
            add_action('bhs_release_monetization_save', [self::class, 'save_release']);
        }
    }

    // WHY: external-feed and local-import tracks aren't this site's to sell (local imports have
    // no ownership check). Hard exclusion: no UI, no save, and BHM_PlayGating always allows access.
    public static function is_non_catalog_track(int $post_id): bool {
        return in_array(get_post_meta($post_id, '_bhs_source', true), ['external', 'local-import'], true);
    }

    public static function render_track_ui(\WP_Post $post): void { self::render_object_ui($post, 'bhs_track'); }
    public static function render_release_ui(\WP_Post $post): void { self::render_object_ui($post, 'bhs_release'); }

    private static function render_object_ui(\WP_Post $post, string $post_type): void {
        wp_nonce_field('bhm_save_object', 'bhm_object_nonce');

        if ($post_type === 'bhs_track' && self::is_non_catalog_track($post->ID)) {
            $source = get_post_meta($post->ID, '_bhs_source', true);
            $why = $source === 'external'
                ? 'This track was imported from another artist\'s feed — it isn\'t this site\'s own content'
                : 'This track came in through a listener\'s personal local-import, not this site\'s vetted catalog — there\'s no ownership check on that upload path';
            echo '<p class="description">' . esc_html($why) . ', so it can\'t be sold, gated, or charged per-play here. Monetization only applies to tracks added through the ordinary admin catalog flow.</p>';
            return;
        }

        if (!(BH_Commerce::available())) {
            echo '<p class="description">Install WooCommerce (The Self-Hosted Self → Monetization Settings) to turn any of this on.</p>';
            return;
        }

        $required_tier = (int) get_post_meta($post->ID, '_bhm_required_tier', true);
        $purchase_price = (int) get_post_meta($post->ID, '_bhm_purchase_price_cents', true);
        $purchase_pwyw = (bool) get_post_meta($post->ID, '_bhm_purchase_pwyw', true);
        $pay_per_play = (int) get_post_meta($post->ID, '_bhm_pay_per_play_cents', true);
        $tiers = BHM_Tiers::all();

        echo '<p><label><strong>Require a supporter tier to access this ' . ($post_type === 'bhs_release' ? 'release' : 'track') . '</strong><br>';
        echo '<select name="bhm_required_tier"><option value="0">— Open to everyone —</option>';
        foreach ($tiers as $t) {
            echo '<option value="' . esc_attr($t['id']) . '" ' . selected($required_tier, $t['id'], false) . '>' . esc_html($t['name']) . ' ($' . BHM_Money::display($t['price_cents']) . '/mo or equivalent)</option>';
        }
        echo '</select></label> <span class="description">' . (empty($tiers) ? 'No tiers created yet — see Supporter Tiers.' : '') . '</span></p>';

        // With PWYW on, the purchase price becomes the floor.
        echo '<p><label><strong>Outright purchase price (USD, optional)</strong><br><input type="number" step="0.01" min="0" name="bhm_purchase_price" id="bhm_purchase_price" value="' . esc_attr($purchase_price ? BHM_Money::price($purchase_price) : '') . '" style="width:140px;"> <span class="description" id="bhm_purchase_price_desc">Delivers whatever quality encodes are attached (see Quality Encodes above) as downloads on purchase.</span></label></p>';
        echo '<p><label><input type="checkbox" name="bhm_purchase_pwyw" value="1" ' . checked($purchase_pwyw, true, false) . '> <strong>Let fans pay what they want</strong></label> <span class="description">If checked, the price above becomes a minimum instead of a fixed price — a fan can offer more at checkout.</span></p>';

        echo '<p><label><strong>Pay-per-play price (USD, optional)</strong><br><input type="number" step="0.01" min="0" name="bhm_pay_per_play" value="' . esc_attr($pay_per_play ? BHM_Money::price($pay_per_play) : '') . '" style="width:140px;"> <span class="description">Debited from the listener\'s play-credit wallet each time they start this track. Leave blank for free streaming (subject to any tier requirement above).</span></label></p>';
    }

    public static function save_track(int $post_id): void { self::save_object($post_id, 'bhs_track'); }
    public static function save_release(int $post_id): void { self::save_object($post_id, 'bhs_release'); }

    private static function save_object(int $post_id, string $post_type): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhm_object_nonce']) || !wp_verify_nonce($_POST['bhm_object_nonce'], 'bhm_save_object')) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (!(BH_Commerce::available())) return;
        // The real enforcement point; a crafted POST can bypass the hidden UI.
        if ($post_type === 'bhs_track' && self::is_non_catalog_track($post_id)) return;

        $required_tier = isset($_POST['bhm_required_tier']) ? (int) $_POST['bhm_required_tier'] : 0;
        update_post_meta($post_id, '_bhm_required_tier', $required_tier);

        $purchase_price = isset($_POST['bhm_purchase_price']) ? BHM_Money::parse($_POST['bhm_purchase_price']) : 0;
        update_post_meta($post_id, '_bhm_purchase_price_cents', $purchase_price);
        update_post_meta($post_id, '_bhm_purchase_pwyw', !empty($_POST['bhm_purchase_pwyw']) ? 1 : 0);
        if ($purchase_price > 0) {
            BHM_ProductSync::sync_object_purchase_product($post_id, $post_type, $purchase_price);
        }

        $ppp = isset($_POST['bhm_pay_per_play']) ? BHM_Money::parse($_POST['bhm_pay_per_play']) : 0;
        update_post_meta($post_id, '_bhm_pay_per_play_cents', $ppp);
    }
}
