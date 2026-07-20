<?php
if (!defined('ABSPATH')) exit;

/**
 * Split out of class-products.php (DRY/SOLID audit Phase 3) — this file's
 * one job is keeping a WooCommerce product in sync with a tier or a
 * track/release's own purchase option. No entitlement logic, no gating,
 * no rendering; just "the artist edited a price/name over here, make the
 * matching WC product agree."
 */
class BHM_ProductSync {
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
    public static function sync_tier_wc_product($tier_post_id, $name, $price_cents, $annual_price_cents = 0, $trial_days = 0) {
        if (!(class_exists('BH_Commerce') ? BH_Commerce::available() : class_exists('WooCommerce'))) return;
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
                // Free trial, ROADMAP-platform-evolution.md Section 4's
                // remaining open item — a real conversion lever Patreon
                // itself offers per-tier. Days is the unit this plugin's
                // own admin field uses; WC Subscriptions' trial_period
                // unit is fixed to 'day' here rather than exposed as a
                // second dropdown, since "N days" is the one artists
                // actually reach for and a week/month/year trial is
                // exactly representable as a day count anyway.
                'trial_length' => (int) $trial_days,
                'trial_period' => 'day',
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
                    'trial_length' => (int) $trial_days,
                    'trial_period' => 'day',
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
    // Public (not private, unlike its original class-products.php home)
    // since BHM_MonetizationUI::save_object() now calls it from a
    // different class.
    public static function sync_object_purchase_product($object_id, $post_type, $price_cents) {
        if (!(class_exists('BH_Commerce') ? BH_Commerce::available() : class_exists('WooCommerce'))) return 0;
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
}
