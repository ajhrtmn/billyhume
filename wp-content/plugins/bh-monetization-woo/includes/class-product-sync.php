<?php
if (!defined('ABSPATH')) exit;

/**
 * Keeps WooCommerce products in sync with tiers and track/release/course purchase options.
 */
class BHM_ProductSync {
    // Via BH_Commerce, with a direct-WooCommerce fallback for older cores.
    // $annual_price_cents = 0: no annual product synced; an existing one is left hidden, never deleted.
    public static function sync_tier_wc_product(int $tier_post_id, string $name, int $price_cents, int $annual_price_cents = 0, int $trial_days = 0): void {
        if (!(BH_Commerce::available())) return;
        $existing_id = (int) get_post_meta($tier_post_id, '_bhm_wc_product_id', true);
        $existing_annual_id = (int) get_post_meta($tier_post_id, '_bhm_wc_product_id_annual', true);

        if (class_exists('BH_Commerce')) {
            $product_id = BH_Commerce::upsert_product($existing_id, [
                'name' => $name . ' — Supporter Tier',
                'price_cents' => $price_cents,
                'virtual' => true, // no shipping — this is access, not a physical good
                'catalog_visibility' => 'hidden', // sold only via the tier picker
                'subscription' => true, // BH_Commerce degrades to a Simple product without WC Subscriptions
                'subscription_period' => 'month',
                'subscription_period_interval' => 1,
                'trial_length' => (int) $trial_days,
                'trial_period' => 'day',
            ]);
            if ($product_id) {
                update_post_meta($tier_post_id, '_bhm_wc_product_id', $product_id);
                update_post_meta($product_id, '_bhm_tier_id', $tier_post_id); // reverse lookup for fulfilment
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
                    // Same reverse lookup: either cadence grants the same tier.
                    update_post_meta($annual_id, '_bhm_tier_id', $tier_post_id);
                }
            }
            return;
        }

        // --- fallback: direct WooCommerce (pre-BH_Commerce core) ---
        $has_subs = class_exists('WC_Subscriptions') && class_exists('WC_Product_Subscription');
        $price = BHM_Money::price($price_cents);
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

        // Annual only means something with Subscriptions active.
        if ($annual_price_cents > 0 && $has_subs) {
            $annual_price = BHM_Money::price($annual_price_cents);
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

    // One-time downloadable product for an outright track/release/course purchase.
    public static function sync_object_purchase_product(int $object_id, string $post_type, int $price_cents): int {
        if (!(BH_Commerce::available())) return 0;
        $meta_key = '_bhm_purchase_wc_product_id';
        $existing_id = (int) get_post_meta($object_id, $meta_key, true);
        $title = get_the_title($object_id);
        $labels = ['bhs_release' => 'Album', 'bh_course' => 'Course'];
        $name = $title . ' (' . ($labels[$post_type] ?? 'Track') . ' Purchase)';

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
        $product->set_regular_price(BHM_Money::price($price_cents));
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
