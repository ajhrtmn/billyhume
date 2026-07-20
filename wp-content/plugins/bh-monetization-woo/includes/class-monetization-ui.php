<?php
if (!defined('ABSPATH')) exit;

/**
 * Split out of class-products.php (DRY/SOLID audit Phase 3) — the
 * bh-streaming metabox UI (render/save) for a track/release's own
 * monetization fields (required tier, purchase price, pay-per-play
 * price). No entitlement or gating logic here — that's BHM_Entitlements
 * and BHM_PlayGating respectively; this class only renders and persists
 * the admin-facing config those other classes read.
 */
class BHM_MonetizationUI {
    public static function init() {
        // Entirely conditional on bh-streaming actually being active,
        // checked the same way class-streaming-bridge.php in bh-registry
        // does it (a class_exists() check safely inside an init-hooked
        // callback, never at file-parse time).
        if (class_exists('BHS_Admin')) {
            add_action('bhs_track_monetization_ui', [self::class, 'render_track_ui']);
            add_action('bhs_release_monetization_ui', [self::class, 'render_release_ui']);
            add_action('bhs_track_monetization_save', [self::class, 'save_track']);
            add_action('bhs_release_monetization_save', [self::class, 'save_release']);
        }
    }

    // A track this site pulled in from ANOTHER artist's own feed (see
    // bh-streaming's class-feeds.php, _bhs_source = 'external') is not
    // this site's own content to sell, gate, or charge per-play — doing
    // so would take a fan's money for music this site owner didn't make
    // and has no claim to. This is a hard exclusion, not a toggle: the
    // monetization UI doesn't even render for these, and BHM_PlayGating's
    // gating checks always allow access regardless of any stale meta a
    // track might have carried over from before it was re-synced as
    // external (belt and suspenders).
    // Public (not private, unlike its original class-products.php home)
    // since BHM_PlayGating now calls this from a different class.
    // Renamed from is_external_track() to what it's actually checking:
    // "is this track something OTHER than this site's own vetted
    // catalog." Originally this only excluded 'external' (aggregated
    // from another artist's feed) — but bh-streaming's local-import
    // feature (any logged-in user with upload_files) creates tracks
    // with _bhs_source = 'local-import', and that path had NO ownership
    // verification of any kind: nothing stops someone from importing a
    // song they don't own into their personal library. If such a track
    // could still be monetized, that's a real path for someone to sell
    // (or pay-per-play-charge for) music that isn't theirs — the same
    // harm this exclusion already existed to prevent for external
    // tracks, just via a different door. Only a track with NO _bhs_source
    // at all — meaning it went through the ordinary admin-managed
    // catalog flow, not a public upload/aggregation path — is eligible.
    public static function is_non_catalog_track($post_id) {
        return in_array(get_post_meta($post_id, '_bhs_source', true), ['external', 'local-import'], true);
    }

    public static function render_track_ui($post) { self::render_object_ui($post, 'bhs_track'); }
    public static function render_release_ui($post) { self::render_object_ui($post, 'bhs_release'); }

    private static function render_object_ui($post, $post_type) {
        wp_nonce_field('bhm_save_object', 'bhm_object_nonce');

        if ($post_type === 'bhs_track' && self::is_non_catalog_track($post->ID)) {
            $source = get_post_meta($post->ID, '_bhs_source', true);
            $why = $source === 'external'
                ? 'This track was imported from another artist\'s feed — it isn\'t this site\'s own content'
                : 'This track came in through a listener\'s personal local-import, not this site\'s vetted catalog — there\'s no ownership check on that upload path';
            echo '<p class="description">' . esc_html($why) . ', so it can\'t be sold, gated, or charged per-play here. Monetization only applies to tracks added through the ordinary admin catalog flow.</p>';
            return;
        }

        if (!(class_exists('BH_Commerce') ? BH_Commerce::available() : class_exists('WooCommerce'))) {
            echo '<p class="description">Install WooCommerce (Own Ur Shit → Monetization Settings) to turn any of this on.</p>';
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
            echo '<option value="' . esc_attr($t['id']) . '" ' . selected($required_tier, $t['id'], false) . '>' . esc_html($t['name']) . ' ($' . number_format($t['price_cents'] / 100, 2) . '/mo or equivalent)</option>';
        }
        echo '</select></label> <span class="description">' . (empty($tiers) ? 'No tiers created yet — see Supporter Tiers.' : '') . '</span></p>';

        // Bandcamp-style "name your price" — reuses the exact cart-item-
        // price-override mechanism the tip jar already proved out
        // (BHM_Frontend::apply_purchase_price()/apply_purchase_amount()),
        // rather than building new variable-price plumbing. $purchase_price
        // is the SAME field either way — a fixed price when PWYW is off, a
        // floor/minimum when it's on, exactly like the tip jar's own
        // TIP_MIN_CENTS concept.
        echo '<p><label><strong>Outright purchase price (USD, optional)</strong><br><input type="number" step="0.01" min="0" name="bhm_purchase_price" id="bhm_purchase_price" value="' . esc_attr($purchase_price ? number_format($purchase_price / 100, 2, '.', '') : '') . '" style="width:140px;"> <span class="description" id="bhm_purchase_price_desc">Delivers whatever quality encodes are attached (see Quality Encodes above) as downloads on purchase.</span></label></p>';
        echo '<p><label><input type="checkbox" name="bhm_purchase_pwyw" value="1" ' . checked($purchase_pwyw, true, false) . '> <strong>Let fans pay what they want</strong></label> <span class="description">If checked, the price above becomes a minimum instead of a fixed price — a fan can offer more at checkout.</span></p>';

        echo '<p><label><strong>Pay-per-play price (USD, optional)</strong><br><input type="number" step="0.01" min="0" name="bhm_pay_per_play" value="' . esc_attr($pay_per_play ? number_format($pay_per_play / 100, 2, '.', '') : '') . '" style="width:140px;"> <span class="description">Debited from the listener\'s play-credit wallet each time they start this track. Leave blank for free streaming (subject to any tier requirement above).</span></label></p>';
    }

    public static function save_track($post_id) { self::save_object($post_id, 'bhs_track'); }
    public static function save_release($post_id) { self::save_object($post_id, 'bhs_release'); }

    private static function save_object($post_id, $post_type) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhm_object_nonce']) || !wp_verify_nonce($_POST['bhm_object_nonce'], 'bhm_save_object')) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (!(class_exists('BH_Commerce') ? BH_Commerce::available() : class_exists('WooCommerce'))) return;
        // Never persist monetization config for an externally-aggregated
        // track — the UI doesn't render the fields for these at all, but
        // this is the actual enforcement point (a crafted POST request
        // bypassing the UI must not be able to monetize someone else's
        // content either).
        if ($post_type === 'bhs_track' && self::is_non_catalog_track($post_id)) return;

        $required_tier = isset($_POST['bhm_required_tier']) ? (int) $_POST['bhm_required_tier'] : 0;
        update_post_meta($post_id, '_bhm_required_tier', $required_tier);

        $purchase_price = isset($_POST['bhm_purchase_price']) ? (int) round(((float) $_POST['bhm_purchase_price']) * 100) : 0;
        update_post_meta($post_id, '_bhm_purchase_price_cents', $purchase_price);
        update_post_meta($post_id, '_bhm_purchase_pwyw', !empty($_POST['bhm_purchase_pwyw']) ? 1 : 0);
        if ($purchase_price > 0) {
            BHM_ProductSync::sync_object_purchase_product($post_id, $post_type, $purchase_price);
        }

        $ppp = isset($_POST['bhm_pay_per_play']) ? (int) round(((float) $_POST['bhm_pay_per_play']) * 100) : 0;
        update_post_meta($post_id, '_bhm_pay_per_play_cents', $ppp);
    }
}
