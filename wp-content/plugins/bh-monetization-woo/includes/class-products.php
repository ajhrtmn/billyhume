<?php
if (!defined('ABSPATH') ) exit;

/**
 * The actual WooCommerce bridge, plus the bh-streaming integration this
 * whole plugin exists to provide. Two responsibilities:
 *
 * 1. Sync a WooCommerce product behind each supporter tier (see
 *    BHM_Tiers::save()) and behind each track/release's own purchase
 *    option — the artist only ever edits price/name on THIS plugin's
 *    own screens (or bh-streaming's Monetization metabox); WooCommerce's
 *    product-edit screen is never something the artist is expected to
 *    touch directly, keeping the "wrap it" promise.
 * 2. Hook into the extension points bh-streaming now exposes
 *    (bhs_track_monetization_ui/save, bhs_track_access_allowed,
 *    bhs_track_lock_notice — see bh-streaming's class-admin.php and
 *    class-api.php) so bh-streaming never needs a single line of code
 *    that mentions WooCommerce, prices, or tiers.
 *
 * Also fires bhm_entitlement_granted / bhm_entitlement_revoked (see
 * grant_entitlement(), on_order_reversed(), on_subscription_ended()
 * below) — the tier-level third-party-integration choke point
 * ROADMAP-platform-evolution.md Section 4 asks for (its named example:
 * "connect Discord and grant a role per tier"). No such integration is
 * built here; this is just the one clean place for a future one to
 * hook in, args: ($user_id, $type, $scope, $object_id[, $reason]).
 */
class BHM_Products {
    public static function init() {
        // The bh-streaming integration — entirely conditional on
        // bh-streaming actually being active, checked the same way
        // class-streaming-bridge.php in bh-registry does it (a
        // class_exists() check safely inside an init-hooked callback,
        // never at file-parse time).
        if (class_exists('BHS_Admin')) {
            add_action('bhs_track_monetization_ui', [self::class, 'render_track_ui']);
            add_action('bhs_release_monetization_ui', [self::class, 'render_release_ui']);
            add_action('bhs_track_monetization_save', [self::class, 'save_track']);
            add_action('bhs_release_monetization_save', [self::class, 'save_release']);
            add_filter('bhs_track_access_allowed', [self::class, 'track_access_allowed'], 10, 2);
            add_filter('bhs_track_lock_notice', [self::class, 'track_lock_notice'], 10, 2);
            add_filter('bhs_track_play_allowed', [self::class, 'track_play_allowed'], 10, 3);
            add_filter('bhs_track_play_denied_message', [self::class, 'track_play_denied_message'], 10, 2);
        }

        add_action('woocommerce_order_status_completed', [self::class, 'on_order_completed']);
        // A refunded or cancelled order must actually TAKE BACK whatever
        // it granted — otherwise a chargeback (a real, common fraud
        // pattern: pay with a stolen/disputed card, keep the access or
        // wallet credit after the payment gets reversed) leaves the
        // entitlement standing forever with the artist eating the loss
        // AND the fraudster keeping what they "bought." This is the
        // actual, in-scope thing this plugin can do about fraud —
        // detecting the fraud itself is the payment gateway's job (see
        // this class's own docblock and the README).
        add_action('woocommerce_order_status_refunded', [self::class, 'on_order_reversed']);
        add_action('woocommerce_order_status_cancelled', [self::class, 'on_order_reversed']);
        // Subscription renewal/cancellation — only registered if
        // WooCommerce Subscriptions is actually present, since these
        // hooks don't exist otherwise.
        if (class_exists('WC_Subscriptions')) {
            add_action('woocommerce_subscription_status_active', [self::class, 'on_subscription_active']);
            add_action('woocommerce_subscription_status_cancelled', [self::class, 'on_subscription_ended']);
            add_action('woocommerce_subscription_status_expired', [self::class, 'on_subscription_ended']);
            // ROADMAP-ux-polish-and-feature-parity-2026-07.md 5a — real,
            // pre-existing bug, not just a missing feature: WooCommerce
            // Subscriptions' native on-hold/pause status fired this same
            // event bus all along, but nothing here ever listened for
            // it, so a paused subscription's entitlement was never
            // revoked (a fan could pause billing and silently keep
            // tier-gated access indefinitely). on_subscription_active()
            // already re-grants on the way back out of on-hold (that
            // hook fires again on resume), so pause+resume round-trips
            // correctly with just this one addition — no change needed
            // on the resume side.
            add_action('woocommerce_subscription_status_on-hold', [self::class, 'on_subscription_paused']);
        }
    }

    /* ---------- tier <-> WooCommerce product sync ---------- */

    // As of the platform-evolution handoff, this goes through the core's
    // BH_Commerce interface (own-ur-shit/includes/class-commerce.php)
    // instead of instantiating WC_Product_* classes directly — the
    // concrete first migration the roadmap doc asked for. Falls back to
    // the original direct-WooCommerce path if BH_Commerce isn't loaded
    // (an old core version), same class_exists()-guarded-degrade
    // convention as everywhere else in this ecosystem.
    // $annual_price_cents = 0 means "no annual option offered" — the
    // second (annual) product is created/kept in sync only when a real
    // price is set, and is otherwise left alone (never deleted here,
    // since the artist may just be toggling the field back and forth
    // while drafting; a stale-but-hidden annual product is harmless —
    // catalog_visibility is already 'hidden', same as the monthly one).
    public static function sync_tier_wc_product($tier_post_id, $name, $price_cents, $annual_price_cents = 0) {
        if (!class_exists('WooCommerce')) return;
        $existing_id = (int) get_post_meta($tier_post_id, '_bhm_wc_product_id', true);
        $existing_annual_id = (int) get_post_meta($tier_post_id, '_bhm_wc_product_id_annual', true);

        if (class_exists('BH_Commerce')) {
            $product_id = BH_Commerce::upsert_product($existing_id, [
                'name' => $name . ' — Supporter Tier',
                'price_cents' => $price_cents,
                'virtual' => true, // no shipping — this is access, not a physical good
                'catalog_visibility' => 'hidden', // never shows up in a normal WooCommerce shop listing — sold only via this plugin's own tier picker
                'subscription' => true, // BH_Commerce itself degrades to a plain Simple product if WooCommerce Subscriptions isn't active
                'subscription_period' => 'month',
                'subscription_period_interval' => 1,
            ]);
            if ($product_id) {
                update_post_meta($tier_post_id, '_bhm_wc_product_id', $product_id);
                update_post_meta($product_id, '_bhm_tier_id', $tier_post_id); // reverse lookup, used by on_order_completed()/on_subscription_active()
            }

            if ($annual_price_cents > 0) {
                $annual_id = BH_Commerce::upsert_product($existing_annual_id, [
                    'name' => $name . ' — Supporter Tier (Annual)',
                    'price_cents' => $annual_price_cents,
                    'virtual' => true,
                    'catalog_visibility' => 'hidden',
                    'subscription' => true,
                    'subscription_period' => 'year',
                    'subscription_period_interval' => 1,
                ]);
                if ($annual_id) {
                    update_post_meta($tier_post_id, '_bhm_wc_product_id_annual', $annual_id);
                    // Same reverse-lookup meta as the monthly product so
                    // on_order_completed()/on_subscription_active() grant
                    // the identical tier regardless of which billing
                    // cadence a fan actually bought — the entitlement
                    // itself (BHM_Tiers benefit_keys/price rank) doesn't
                    // know or care about billing period, only ABOUT the
                    // tier.
                    update_post_meta($annual_id, '_bhm_tier_id', $tier_post_id);
                }
            }
            return;
        }

        // --- fallback: direct WooCommerce (pre-BH_Commerce core) ---
        $has_subs = class_exists('WC_Subscriptions') && class_exists('WC_Product_Subscription');
        $price = number_format($price_cents / 100, 2, '.', '');
        $product = $existing_id ? wc_get_product($existing_id) : null;
        if (!$product) {
            $product = $has_subs ? new WC_Product_Subscription() : new WC_Product_Simple();
        }
        $product->set_name($name . ' — Supporter Tier');
        $product->set_regular_price($price);
        $product->set_virtual(true);
        $product->set_catalog_visibility('hidden');
        if ($has_subs && method_exists($product, 'set_props')) {
            $product->set_props(['subscription_period' => 'month', 'subscription_period_interval' => 1]);
        }
        $product->save();
        update_post_meta($tier_post_id, '_bhm_wc_product_id', $product->get_id());
        update_post_meta($product->get_id(), '_bhm_tier_id', $tier_post_id);

        // Annual fallback product only makes sense if Subscriptions is
        // active at all — a plain one-time WooCommerce product has no
        // billing period to speak of, so "annual" would be meaningless
        // (identical to the monthly one-time product, just mispriced).
        if ($annual_price_cents > 0 && $has_subs) {
            $annual_price = number_format($annual_price_cents / 100, 2, '.', '');
            $annual_product = $existing_annual_id ? wc_get_product($existing_annual_id) : null;
            if (!$annual_product) $annual_product = new WC_Product_Subscription();
            $annual_product->set_name($name . ' — Supporter Tier (Annual)');
            $annual_product->set_regular_price($annual_price);
            $annual_product->set_virtual(true);
            $annual_product->set_catalog_visibility('hidden');
            if (method_exists($annual_product, 'set_props')) {
                $annual_product->set_props(['subscription_period' => 'year', 'subscription_period_interval' => 1]);
            }
            $annual_product->save();
            update_post_meta($tier_post_id, '_bhm_wc_product_id_annual', $annual_product->get_id());
            update_post_meta($annual_product->get_id(), '_bhm_tier_id', $tier_post_id);
        }
    }

    // Same idea as sync_tier_wc_product() but for a single track/release's
    // own outright-purchase option — a plain one-time Simple product,
    // never a subscription (buying a track outright is never recurring).
    // Also migrated behind BH_Commerce, same fallback shape as above.
    private static function sync_object_purchase_product($object_id, $post_type, $price_cents) {
        if (!class_exists('WooCommerce')) return 0;
        $meta_key = '_bhm_purchase_wc_product_id';
        $existing_id = (int) get_post_meta($object_id, $meta_key, true);
        $title = get_the_title($object_id);
        $name = $title . ' (' . ($post_type === 'bhs_release' ? 'Album' : 'Track') . ' Purchase)';

        if (class_exists('BH_Commerce')) {
            $product_id = BH_Commerce::upsert_product($existing_id, [
                'name' => $name,
                'price_cents' => $price_cents,
                'virtual' => true,
                'downloadable' => true,
                'catalog_visibility' => 'hidden',
            ]);
            if (!$product_id) return 0;
            update_post_meta($object_id, $meta_key, $product_id);
            update_post_meta($product_id, '_bhm_purchase_object_id', $object_id);
            update_post_meta($product_id, '_bhm_purchase_object_type', $post_type);
            return $product_id;
        }

        // --- fallback: direct WooCommerce (pre-BH_Commerce core) ---
        $product = $existing_id ? wc_get_product($existing_id) : null;
        if (!$product) $product = new WC_Product_Simple();
        $product->set_name($name);
        $product->set_regular_price(number_format($price_cents / 100, 2, '.', ''));
        $product->set_virtual(true);
        $product->set_downloadable(true);
        $product->set_catalog_visibility('hidden');
        $product->save();
        update_post_meta($object_id, $meta_key, $product->get_id());
        update_post_meta($product->get_id(), '_bhm_purchase_object_id', $object_id);
        update_post_meta($product->get_id(), '_bhm_purchase_object_type', $post_type);
        return $product->get_id();
    }

    /* ---------- bh-streaming metabox UI ---------- */

    public static function render_track_ui($post) { self::render_object_ui($post, 'bhs_track'); }
    public static function render_release_ui($post) { self::render_object_ui($post, 'bhs_release'); }

    // A track this site pulled in from ANOTHER artist's own feed (see
    // bh-streaming's class-feeds.php, _bhs_source = 'external') is not
    // this site's own content to sell, gate, or charge per-play — doing
    // so would take a fan's money for music this site owner didn't make
    // and has no claim to. This is a hard exclusion, not a toggle: the
    // monetization UI doesn't even render for these, and the gating
    // check below always allows access regardless of any stale meta a
    // track might have carried over from before it was re-synced as
    // external (belt and suspenders — see is_external_track()).
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
    private static function is_non_catalog_track($post_id) {
        return in_array(get_post_meta($post_id, '_bhs_source', true), ['external', 'local-import'], true);
    }

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

        if (!class_exists('WooCommerce')) {
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
        // (BHM_Frontend::apply_purchase_price()/apply_purchase_amount()
        // below), rather than building new variable-price plumbing.
        // $purchase_price is the SAME field either way — a fixed price
        // when PWYW is off, a floor/minimum when it's on, exactly like
        // the tip jar's own TIP_MIN_CENTS concept.
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
        if (!class_exists('WooCommerce')) return;
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
            self::sync_object_purchase_product($post_id, $post_type, $purchase_price);
        }

        $ppp = isset($_POST['bhm_pay_per_play']) ? (int) round(((float) $_POST['bhm_pay_per_play']) * 100) : 0;
        update_post_meta($post_id, '_bhm_pay_per_play_cents', $ppp);
    }

    /* ---------- bh-streaming gating hooks ---------- */

    public static function track_access_allowed($allowed, $track_id) {
        if (!$allowed) return false; // something else already said no — don't override
        if (self::is_non_catalog_track($track_id)) return true; // never gated — see is_external_track()
        $required_tier = (int) get_post_meta($track_id, '_bhm_required_tier', true);
        return BHM_Gate::user_has_tier_access(get_current_user_id(), $required_tier, $track_id);
    }

    public static function track_lock_notice($default, $track_id) {
        $required_tier = (int) get_post_meta($track_id, '_bhm_required_tier', true);
        return BHM_Gate::render_paywall_notice($required_tier);
    }

    // The actual money-moving half of pay-per-play: called at the
    // moment bh-streaming's /tracks/{id}/play endpoint is hit (see
    // class-api.php's record_play()), NOT at catalog-listing time —
    // debiting on every /tracks fetch would charge a listener just for
    // loading the page. Records a bhm_play_log row EVERY time a play is
    // allowed, whether or not money changed hands, so there's one
    // authoritative, server-authored history to build reporting or a
    // future payout engine on top of — never bh-streaming's own
    // unauthenticated, gameable _bhs_play_count.
    public static function track_play_allowed($allowed, $track_id, $user_id) {
        if (!$allowed) return false;
        if (self::is_non_catalog_track($track_id)) return true; // never charged — see is_external_track()

        $required_tier = (int) get_post_meta($track_id, '_bhm_required_tier', true);
        $ppp_cents = (int) get_post_meta($track_id, '_bhm_pay_per_play_cents', true);

        // Already covered by a tier/subscription/purchase entitlement —
        // free to play, no debit, but still logged.
        if (BHM_Gate::user_has_tier_access($user_id, $required_tier, $track_id)) {
            self::log_play($user_id, $track_id, false, 0);
            return true;
        }

        // No pay-per-play price set and no tier required at all — open
        // streaming, same as bh-streaming's own pre-monetization default.
        if (!$ppp_cents) {
            self::log_play($user_id, $track_id, false, 0);
            return true;
        }

        // A real pay-per-play price applies and no standing entitlement
        // covers it — an anonymous (not-logged-in) listener has no
        // wallet to debit at all, so they're declined outright rather
        // than silently letting the play through.
        if (!$user_id) return false;

        $debited = BHM_Wallet::debit($user_id, $ppp_cents, $track_id);
        if ($debited) {
            self::log_play($user_id, $track_id, true, $ppp_cents);
            self::check_play_velocity($user_id);
        }
        return $debited;
    }

    // A real pattern (someone genuinely queueing up a bunch of short
    // tracks) looks different from either a compromised account being
    // rapidly drained or a stolen-payment-method wallet-topup being
    // tested against your store before trying somewhere bigger — both
    // of which show up as an abnormal burst of paid plays in a short
    // window. This flags the same way the refund pattern does: a
    // signal for a human, never an automatic account action.
    const VELOCITY_WINDOW_SECONDS = 60;
    const VELOCITY_THRESHOLD = 8;

    private static function check_play_velocity($user_id) {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::VELOCITY_WINDOW_SECONDS);
        $recent_paid_plays = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bhm_play_log WHERE user_id = %d AND paid = 1 AND created_at > %s",
            $user_id, $cutoff
        ));
        if ($recent_paid_plays < self::VELOCITY_THRESHOLD) return;

        // Rate-limit the flag itself, not just the underlying activity —
        // otherwise every single play past the threshold re-fires the
        // action for as long as the burst continues.
        $flag_key = '_bhm_velocity_flagged_at';
        $last_flagged = (int) get_user_meta($user_id, $flag_key, true);
        if (time() - $last_flagged < self::VELOCITY_WINDOW_SECONDS) return;

        update_user_meta($user_id, $flag_key, time());
        update_user_meta($user_id, '_bhm_velocity_flagged', '1');
        do_action('bhm_play_velocity_flagged', $user_id, $recent_paid_plays);
    }

    public static function track_play_denied_message($default, $track_id) {
        $ppp_cents = (int) get_post_meta($track_id, '_bhm_pay_per_play_cents', true);
        if (!$ppp_cents) return $default;
        return sprintf(
            'This track costs $%s to play and your account isn\'t logged in or doesn\'t have enough play credit. Log in and top up your wallet to continue.',
            number_format($ppp_cents / 100, 2)
        );
    }

    private static function log_play($user_id, $track_id, $paid, $cents) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'bhm_play_log', [
            'user_id' => $user_id ?: 0, 'track_id' => $track_id, 'paid' => $paid ? 1 : 0, 'cents' => $cents,
        ]);
    }

    /* ---------- order/subscription -> entitlement ---------- */

    // Fires for EVERY completed WooCommerce order, not just this
    // plugin's own products — cheap early-return for anything that
    // isn't one of ours. Handles: tier purchases (one-time, when
    // WooCommerce Subscriptions isn't active), track/release purchases,
    // and wallet top-ups.
    public static function on_order_completed($order_id) {
        // BH_Commerce::get_order() normalizes the order into a plain
        // array (id/status/customer_id/total_cents/items[{product_id,quantity}])
        // — this file no longer touches a WC_Order object directly, same
        // treatment sync_tier_wc_product() already got in the tier-depth
        // pass. class_exists('BH_Commerce') isn't checked here because
        // this whole class already requires the core (BHCORE_LOADED
        // gate in bh-monetization-woo.php); an old core without
        // BH_Commerce would need its own fallback, out of scope for this
        // pass same as the rest of this migration.
        $order = class_exists('BH_Commerce') ? BH_Commerce::get_order($order_id) : self::legacy_get_order_array($order_id);
        if (!$order) {
            // A completed-order hook firing for an order ID that can't
            // be loaded is exactly the kind of "money moved but nothing
            // here can trust it" situation worth a loud log entry rather
            // than a silent early return — someone paid and this plugin
            // has no idea what they should now have access to.
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('error', "on_order_completed: order $order_id could not be loaded", ['order_id' => $order_id], 'BH Monetization');
            }
            return;
        }

        // Per-item, not per-order: one bad product meta lookup or a
        // single grant_entitlement() throwing must not stop every OTHER
        // item in the same order from being fulfilled — a fan who bought
        // a tier AND a track in one checkout shouldn't lose the track
        // just because the tier grant hit an edge case (or vice versa).
        foreach ($order['items'] as $item) {
            $product_id = $item['product_id'];
            try {
                $user_id = $order['customer_id'];
                if (!$user_id) continue; // guest checkout has no account to grant an entitlement to — nothing to attach access to

                $tier_id = (int) get_post_meta($product_id, '_bhm_tier_id', true);
                if ($tier_id) {
                    $has_subs = class_exists('BH_Commerce') ? BH_Commerce::has_subscriptions() : class_exists('WC_Subscriptions');
                    self::grant_entitlement($user_id, $has_subs ? 'subscription' : 'streaming_tier', 'account', $tier_id, $order_id, null,
                        $has_subs ? null : gmdate('Y-m-d H:i:s', strtotime('+30 days')));
                    continue;
                }

                $object_id = (int) get_post_meta($product_id, '_bhm_purchase_object_id', true);
                $object_type = get_post_meta($product_id, '_bhm_purchase_object_type', true);
                if ($object_id) {
                    self::grant_entitlement($user_id, 'purchase', $object_type === 'bhs_release' ? 'release' : 'track', $object_id, $order_id, null, null);
                    continue;
                }

                $wallet_cents = (int) get_post_meta($product_id, '_bhm_wallet_topup_cents', true);
                if ($wallet_cents) {
                    BHM_Wallet::credit($user_id, $wallet_cents, 'topup', null, $order_id);
                }
            } catch (\Throwable $e) {
                if (class_exists('OUS_DebugLog')) {
                    OUS_DebugLog::log('error', 'on_order_completed item fulfillment failed: ' . $e->getMessage(), [
                        'order_id' => $order_id, 'product_id' => $product_id,
                    ], 'BH Monetization');
                }
                // Deliberately swallowed past this point (not re-thrown)
                // — a fulfillment bug on one line item must never turn
                // into a fatal error mid-way through WooCommerce's own
                // order-completion flow for the customer's whole order.
            }
        }
    }

    // Only reached if BH_Commerce itself isn't loaded (an old core) —
    // the exact same direct-WooCommerce shape this migration removed
    // from the main path, kept as a single explicit fallback rather
    // than silently no-op'ing order fulfillment on an old core.
    private static function legacy_get_order_array($order_id) {
        if (!function_exists('wc_get_order')) return null;
        $order = wc_get_order($order_id);
        if (!$order) return null;
        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = ['product_id' => $item->get_product_id(), 'quantity' => $item->get_quantity()];
        }
        return ['id' => $order->get_id(), 'customer_id' => $order->get_customer_id(), 'items' => $items];
    }

    // The reversal counterpart to on_order_completed() — a refunded or
    // cancelled order takes back whatever it granted. Wallet top-ups are
    // reversed by debiting the SAME amount that was credited (recorded
    // in the ledger with reason 'topup_reversed', so the history stays
    // honest about what happened rather than just vanishing a row) —
    // capped at the wallet's current balance so a fan who already spent
    // some of the disputed credit doesn't end up with a negative
    // balance; the artist absorbs whatever was already spent, same as
    // any real merchant would on a chargeback for a consumed good.
    public static function on_order_reversed($order_id) {
        global $wpdb;
        $order = class_exists('BH_Commerce') ? BH_Commerce::get_order($order_id) : self::legacy_get_order_array($order_id);
        if (!$order) return;

        $t = $wpdb->prefix . 'bhm_entitlements';
        // Fetch what's about to be deleted BEFORE deleting it, so the
        // bhm_entitlement_revoked action (see grant_entitlement()'s
        // granted counterpart below) can tell a listener which specific
        // entitlements just got taken back — a future Discord-role-sync
        // consumer (the exact example ROADMAP-platform-evolution.md
        // Section 4 names) needs the object/scope, not just "something
        // changed for this user."
        $revoked = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE wc_order_id = %d", $order_id), ARRAY_A);
        $wpdb->delete($t, ['wc_order_id' => $order_id]);
        foreach ($revoked as $row) {
            do_action('bhm_entitlement_revoked', (int) $row['user_id'], $row['type'], $row['scope'], (int) $row['object_id'], 'order_reversed');
        }

        foreach ($order['items'] as $item) {
            $wallet_cents = (int) get_post_meta($item['product_id'], '_bhm_wallet_topup_cents', true);
            if (!$wallet_cents) continue;
            $user_id = $order['customer_id'];
            if (!$user_id) continue;
            $reverse_amount = min($wallet_cents, BHM_Wallet::balance_cents($user_id));
            if ($reverse_amount > 0) {
                BHM_Wallet::apply_ledger_delta($user_id, -$reverse_amount, 'topup_reversed', null, $order_id);
            }
        }

        if ($order['customer_id']) BHM_Fraud::track_refund_pattern($order['customer_id'], $order_id);

        // A second real notification example alongside BH Courses'
        // course-completion one (see the core's OUS_Notifications
        // docblock) — chosen specifically because it's a genuine
        // transparency moment: something a customer's account just had
        // taken away, not a nice-to-have "hey, FYI." class_exists()-
        // guarded since notifications shipped in core 3.2.0 — this
        // plugin only ever depends on the core's PRESENCE, never any
        // one optional feature inside it, so an older core still works,
        // it just doesn't send this particular notice.
        if (class_exists('OUS_Notifications') && $order['customer_id']) {
            OUS_Notifications::notify(
                $order['customer_id'],
                'refund_reversed',
                'A refund adjusted your account',
                'Order #' . $order_id . ' was refunded or cancelled, and any access or wallet credit it granted has been reversed accordingly.',
                '',
                'BH Monetization'
            );
        }
    }

    // Refund/chargeback abuse-pattern detection moved to its own
    // BHM_Fraud class (includes/class-fraud.php) — see that file's
    // docblock for the DRY/SOLID-refactor rationale. This class only
    // calls into it now (on_order_reversed() above); everything that
    // used to live here (track_refund_pattern, fingerprint_for,
    // refund_count_recent, the REFUND_ABUSE_*/FINGERPRINT_COOKIE
    // constants) is unchanged in behavior, just relocated.

    // $subscription arrives as the real WC_Subscription object — that
    // part can't be abstracted away, it's WooCommerce Subscriptions' own
    // action hook signature (woocommerce_subscription_status_active).
    // What DOES get abstracted is everything this method does WITH it:
    // normalize_subscription() is the one place that reaches into
    // get_customer_id()/get_items()/get_id(), same treatment
    // BH_Commerce::get_order() already gives WC_Order objects.
    public static function on_subscription_active($subscription) {
        $sub = class_exists('BH_Commerce') ? BH_Commerce::normalize_subscription($subscription) : null;
        if (!$sub) {
            // Fallback for an old core without BH_Commerce — same
            // direct-object shape this migration removed from the main
            // path.
            $sub = ['id' => $subscription->get_id(), 'customer_id' => $subscription->get_customer_id(), 'items' => array_map(function ($item) {
                return ['product_id' => $item->get_product_id()];
            }, array_values($subscription->get_items()))];
        }

        foreach ($sub['items'] as $item) {
            $tier_id = (int) get_post_meta($item['product_id'], '_bhm_tier_id', true);
            if ($tier_id) {
                self::grant_entitlement($sub['customer_id'], 'subscription', 'account', $tier_id, null, $sub['id'], null);
            }
        }
    }

    public static function on_subscription_ended($subscription) {
        self::revoke_subscription_entitlements($subscription, 'subscription_ended');
    }

    /**
     * WooCommerce Subscriptions' on-hold status (a fan pausing billing
     * rather than cancelling outright) — same revocation as a real
     * cancellation, since a paused subscription isn't being billed and
     * shouldn't keep gated access, but tagged with its own reason so
     * anything listening on bhm_entitlement_revoked (the tier-level
     * integration choke point this class's own docblock describes) can
     * tell "paused, might come back" apart from "actually over."
     * on_subscription_active() already re-grants automatically when a
     * fan resumes (WooCommerce Subscriptions fires that same event again
     * on the way out of on-hold) — nothing extra needed on that side.
     */
    public static function on_subscription_paused($subscription) {
        self::revoke_subscription_entitlements($subscription, 'subscription_paused');
    }

    private static function revoke_subscription_entitlements($subscription, $reason) {
        global $wpdb;
        $sub_id = $subscription->get_id(); // trivial accessor, not worth a full normalize_subscription() round trip just for this
        $t = $wpdb->prefix . 'bhm_entitlements';
        $revoked = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE wc_subscription_id = %d", $sub_id), ARRAY_A);
        $wpdb->delete($t, ['wc_subscription_id' => $sub_id]);
        foreach ($revoked as $row) {
            do_action('bhm_entitlement_revoked', (int) $row['user_id'], $row['type'], $row['scope'], (int) $row['object_id'], $reason);
        }
    }

    private static function grant_entitlement($user_id, $type, $scope, $object_id, $order_id, $subscription_id, $expires_at) {
        global $wpdb;
        $t = $wpdb->prefix . 'bhm_entitlements';

        // Idempotent per (user, type, scope, object, and whichever of
        // order/subscription actually applies) — a webhook retry or a
        // page reload hitting this twice must not create duplicate
        // grants. $order_id is legitimately NULL for a subscription-
        // driven grant (see on_subscription_active()) — %d would coerce
        // that to 0 and silently break this check against a real NULL
        // column value, so the two cases are built as separate,
        // correctly-NULL-safe queries rather than one that papers over
        // the difference.
        if ($subscription_id) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $t WHERE user_id = %d AND type = %s AND scope = %s AND object_id = %d AND wc_subscription_id = %d",
                $user_id, $type, $scope, $object_id, $subscription_id
            ));
        } elseif ($order_id) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $t WHERE user_id = %d AND type = %s AND scope = %s AND object_id = %d AND wc_order_id = %d",
                $user_id, $type, $scope, $object_id, $order_id
            ));
        } else {
            $existing = null; // neither an order nor a subscription to dedupe against — always insert (shouldn't normally happen)
        }
        if ($existing) return;

        $wpdb->insert($t, [
            'user_id' => $user_id, 'type' => $type, 'scope' => $scope, 'object_id' => $object_id,
            'wc_order_id' => $order_id, 'wc_subscription_id' => $subscription_id, 'expires_at' => $expires_at,
        ]);

        // ROADMAP-platform-evolution.md Section 4's tier-level
        // third-party-integration hook — the concrete example named
        // there is "connect Discord and grant a role per tier." Not
        // building Discord integration itself (that's Section 7/
        // roadmap-only territory), just the one clean choke point a
        // future consumer needs: this fires for every entitlement grant
        // (tier subscription, one-time tier purchase, track/release
        // purchase — same $type values on_order_completed()/
        // on_subscription_active() already pass in above), not just
        // tiers, since a future integration might reasonably care about
        // "this user bought this track" too. Sits at this single
        // low-level choke point rather than in each higher-level caller,
        // same reasoning as the OUS_Notifications call two lines below.
        do_action('bhm_entitlement_granted', $user_id, $type, $scope, $object_id);

        // A third real notification example (alongside BH Courses'
        // completion notice and this same plugin's own refund-reversal
        // one above) — deliberately placed at this single low-level
        // choke point rather than in each of the several higher-level
        // callers (tier purchase, one-time buy, subscription activation)
        // that funnel through here, so every entitlement type gets this
        // for free without duplicating the notify() call per caller.
        // Sitting AFTER the `if ($existing) return;` guard above means
        // this only ever fires once per real grant, never on a webhook
        // retry re-confirming something already granted.
        if (class_exists('OUS_Notifications')) {
            OUS_Notifications::notify(
                $user_id,
                'entitlement_granted',
                'Access granted',
                'Thanks for your support — your access is active now.',
                '',
                'BH Monetization',
                false // in-app only; a purchase already gets its own WooCommerce order-confirmation email, no need to double up
            );
        }
    }
}
