<?php
if (!defined('ABSPATH')) exit;

/**
 * Order/subscription -> entitlement bridge.
 * Fires bhm_entitlement_granted / bhm_entitlement_revoked ($user_id, $type, $scope, $object_id[, $reason]).
 */
class BHM_Entitlements {
    public static function init(): void {
        add_action('woocommerce_order_status_completed', [self::class, 'on_order_completed']);
        // A refunded/cancelled order (incl. chargebacks) takes back what it granted.
        add_action('woocommerce_order_status_refunded', [self::class, 'on_order_reversed']);
        add_action('woocommerce_order_status_cancelled', [self::class, 'on_order_reversed']);
        $has_subs = BH_Commerce::has_subscriptions();
        if ($has_subs) {
            add_action('woocommerce_subscription_status_active', [self::class, 'on_subscription_active']);
            add_action('woocommerce_subscription_status_cancelled', [self::class, 'on_subscription_ended']);
            add_action('woocommerce_subscription_status_expired', [self::class, 'on_subscription_ended']);
            // Pausing revokes access; status_active fires again on resume and re-grants.
            add_action('woocommerce_subscription_status_on-hold', [self::class, 'on_subscription_paused']);
        }
    }

    /* ---------- order/subscription -> entitlement ---------- */

    // Runs for every completed order: tier purchases (no WC Subscriptions), track/release purchases, wallet top-ups.
    public static function on_order_completed(int $order_id): void {
        // Plain array: id/status/customer_id/total_cents/items[{product_id,quantity}].
        $order = BH_Commerce::get_order($order_id);
        if (!$order) {
            // Someone paid and we can't tell what for: log loudly.
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('error', "on_order_completed: order $order_id could not be loaded", ['order_id' => $order_id], 'BH Monetization');
            }
            return;
        }

        // WHY: try/catch per item, so one failed grant doesn't block the rest of the order.
        foreach ($order['items'] as $item) {
            $product_id = $item['product_id'];
            try {
                $user_id = $order['customer_id'];
                if (!$user_id) continue; // guest checkout: no account to grant to

                $tier_id = (int) get_post_meta($product_id, '_bhm_tier_id', true);
                if ($tier_id) {
                    // A gift line item grants the buyer nothing; it creates a redemption code for the recipient.
                    $gift_email = !empty($item['gift_email']) ? $item['gift_email'] : '';
                    if ($gift_email && class_exists('BHM_Gifts')) {
                        BHM_Gifts::create_redemption($tier_id, $user_id, $order_id, $gift_email);
                        continue;
                    }

                    $has_subs = BH_Commerce::has_subscriptions();
                    self::grant_entitlement($user_id, $has_subs ? 'subscription' : 'streaming_tier', 'account', $tier_id, $order_id, null,
                        $has_subs ? null : gmdate('Y-m-d H:i:s', strtotime('+' . BHM_Gate::FALLBACK_ACCESS_DAYS . ' days')));
                    continue;
                }

                $object_id = (int) get_post_meta($product_id, '_bhm_purchase_object_id', true);
                $object_type = get_post_meta($product_id, '_bhm_purchase_object_type', true);
                if ($object_id) {
                    $scope_by_type = ['bhs_release' => 'release', 'bh_course' => 'course'];
                    $scope = $scope_by_type[$object_type] ?? 'track';
                    self::grant_entitlement($user_id, 'purchase', $scope, $object_id, $order_id, null, null);
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
                // Swallowed: never fatal mid-way through WooCommerce's order-completion flow.
            }
        }
    }

    // Reverses a refunded/cancelled order. Wallet top-ups are debited (reason 'topup_reversed'),
    // capped at the current balance so it never goes negative.
    // WHY: try/catch because WooCommerce wraps all callbacks of a hook in one try/catch.
    public static function on_order_reversed(int $order_id): void {
        try {
            self::do_on_order_reversed($order_id);
        } catch (\Throwable $e) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('error', 'Entitlement reversal failed for order #' . $order_id . ': ' . $e->getMessage(), ['order_id' => $order_id], 'BH Monetization');
            }
        }
    }

    private static function do_on_order_reversed(int $order_id): void {
        global $wpdb;
        $order = BH_Commerce::get_order($order_id);
        if (!$order) return;

        $t = BHM_Tables::entitlements();
        // Read before deleting so bhm_entitlement_revoked listeners get each row's object/scope.
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

    // $subscription is a real WC_Subscription (hook signature); normalize_subscription() flattens it.
    /** @param mixed $subscription */
    public static function on_subscription_active($subscription): void {
        $sub = BH_Commerce::normalize_subscription($subscription);
        if (!$sub) {
            // Fallback for an older core whose normalize_subscription() returns nothing.
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

    /** @param mixed $subscription */
    public static function on_subscription_ended($subscription): void {
        self::revoke_subscription_entitlements($subscription, 'subscription_ended');
    }

    // Same revocation as cancellation, with its own reason so listeners can tell "paused" from "over".
    /** @param mixed $subscription */
    public static function on_subscription_paused($subscription): void {
        self::revoke_subscription_entitlements($subscription, 'subscription_paused');
    }

    /** @param mixed $subscription */
    private static function revoke_subscription_entitlements($subscription, string $reason): void {
        global $wpdb;
        $sub_id = $subscription->get_id();
        $t = BHM_Tables::entitlements();
        $revoked = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE wc_subscription_id = %d", $sub_id), ARRAY_A);
        $wpdb->delete($t, ['wc_subscription_id' => $sub_id]);
        foreach ($revoked as $row) {
            do_action('bhm_entitlement_revoked', (int) $row['user_id'], $row['type'], $row['scope'], (int) $row['object_id'], $reason);
        }

        // Tell the fan their access lapsed; copy differs for paused (resumable) vs ended.
        if ($revoked && class_exists('OUS_Notifications')) {
            $user_id = (int) $revoked[0]['user_id'];
            if ($user_id) {
                $is_paused = $reason === 'subscription_paused';
                OUS_Notifications::notify(
                    $user_id,
                    'subscription_' . ($is_paused ? 'paused' : 'ended'),
                    $is_paused ? 'Your subscription is paused' : 'Your subscription has ended',
                    $is_paused
                        ? 'Your supporter access is on hold while your subscription is paused. Resume it any time to pick back up right where you left off.'
                        : 'Your supporter subscription has ended, and the access it granted has been removed.',
                    '',
                    'BH Monetization'
                );
            }
        }
    }

    /**
     * The user's current unexpired membership entitlement, or null (so other plugins don't query this schema).
     *
     * @return array<string,mixed>|null
     */
    public static function active_membership(int $user_id): ?array {
        global $wpdb;
        $table = BHM_Tables::entitlements();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d AND type IN ('subscription','streaming_tier')
             AND (expires_at IS NULL OR expires_at > %s) ORDER BY object_id ASC LIMIT 1",
            $user_id, current_time('mysql')
        ), ARRAY_A);
        return $row ?: null;
    }

    // For BHM_Gifts::handle_redeem(): skips grant_entitlement()'s order-keyed dedupe (the order is
    // the buyer's); BHM_Gifts' own 'redeemed' status prevents double claims.
    public static function grant_gift_entitlement(int $user_id, int $tier_id, int $order_id): void {
        global $wpdb;
        $t = BHM_Tables::entitlements();
        // Bypasses grant_entitlement(), so tier exclusivity must be enforced here too.
        self::replace_active_tier_entitlements($user_id, $tier_id);
        $wpdb->insert($t, [
            'user_id' => $user_id, 'type' => 'streaming_tier', 'scope' => 'account', 'object_id' => $tier_id,
            'wc_order_id' => $order_id, 'expires_at' => gmdate('Y-m-d H:i:s', strtotime('+' . BHM_Gate::FALLBACK_ACCESS_DAYS . ' days')),
        ]);
        do_action('bhm_entitlement_granted', $user_id, 'streaming_tier', 'account', $tier_id);
    }

    // Shared by the CRM revoke button and Debug Tools; returns the deleted row or null.
    /** @return array<string, mixed>|null */
    public static function revoke_entitlement_by_id(int $entitlement_id, string $reason = 'manual_revoke'): ?array {
        global $wpdb;
        $t = BHM_Tables::entitlements();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", (int) $entitlement_id), ARRAY_A);
        if (!$row) return null;

        $wpdb->delete($t, ['id' => (int) $entitlement_id]);
        do_action('bhm_entitlement_revoked', (int) $row['user_id'], $row['type'], $row['scope'], (int) $row['object_id'], $reason);

        if (class_exists('OUS_Notifications')) {
            OUS_Notifications::notify(
                (int) $row['user_id'],
                'access_revoked',
                'Your access was updated',
                'An administrator removed access previously granted to your account.',
                '',
                'BH Monetization'
            );
        }
        return $row;
    }

    // Debug Tools grants go through the real grant_entitlement() path.
    public static function debug_grant_tier(int $user_id, int $tier_id, int $days = 30): void {
        self::grant_entitlement($user_id, 'streaming_tier', 'account', (int) $tier_id, null, null, gmdate('Y-m-d H:i:s', strtotime('+' . (int) $days . ' days')));
    }

    public static function debug_grant_purchase(int $user_id, int $object_id, string $scope = 'track'): void {
        self::grant_entitlement($user_id, 'purchase', $scope, (int) $object_id, null, null, null);
    }

    // Clears the user's other active account-scope tier rows so only one tier is ever active.
    // Returns true only if a DIFFERENT tier was replaced; same-tier rows are cleared silently.
    private static function replace_active_tier_entitlements(int $user_id, int $new_tier_id): bool {
        global $wpdb;
        $t = BHM_Tables::entitlements();
        $now = current_time('mysql', true);
        $existing_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE user_id = %d AND type IN ('subscription','streaming_tier') AND scope = 'account' AND (expires_at IS NULL OR expires_at > %s)",
            $user_id, $now
        ), ARRAY_A);
        if (!$existing_rows) return false;

        $replaced_different_tier = false;
        foreach ($existing_rows as $row) {
            $old_tier_id = (int) $row['object_id'];
            $wpdb->delete($t, ['id' => (int) $row['id']]);

            if ($old_tier_id === (int) $new_tier_id) {
                do_action('bhm_entitlement_revoked', $user_id, $row['type'], $row['scope'], $old_tier_id, 'tier_renewed');
                continue;
            }

            do_action('bhm_entitlement_revoked', $user_id, $row['type'], $row['scope'], $old_tier_id, 'tier_replaced');
            $replaced_different_tier = true;

            $old_tier = class_exists('BHM_Tiers') ? BHM_Tiers::get($old_tier_id) : null;
            $new_tier = class_exists('BHM_Tiers') ? BHM_Tiers::get($new_tier_id) : null;
            $is_downgrade = $old_tier && $new_tier && $new_tier['price_cents'] < $old_tier['price_cents'];

            // Credits unused old-tier days to the wallet (no-op when WC Subscriptions' switcher is active).
            if ($is_downgrade && class_exists('BHM_Gate') && $row['expires_at']) {
                BHM_Gate::handle_tier_downgrade($user_id, $old_tier_id, $new_tier_id, $row['expires_at']);
            }

            if ($old_tier && $new_tier && class_exists('OUS_Notifications')) {
                OUS_Notifications::notify(
                    $user_id,
                    'tier_switched',
                    $is_downgrade ? 'Your supporter tier changed' : 'You\'re now a ' . $new_tier['name'] . ' supporter!',
                    $is_downgrade
                        ? 'You switched from ' . $old_tier['name'] . ' to ' . $new_tier['name'] . '.' . (class_exists('BHM_Wallet') ? ' Any unused time on your old tier was credited to your wallet.' : '')
                        : 'You upgraded from ' . $old_tier['name'] . ' to ' . $new_tier['name'] . '. Welcome to the next level!',
                    '',
                    'BH Monetization'
                );
            }
        }
        return $replaced_different_tier;
    }

    private static function grant_entitlement(int $user_id, string $type, string $scope, int $object_id, ?int $order_id, ?int $subscription_id, ?string $expires_at): void {
        global $wpdb;
        $t = BHM_Tables::entitlements();

        // Idempotent against webhook retries. WHY: separate queries because %d would turn a NULL
        // order/subscription id into 0 and miss the existing row.
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
            $existing = null; // nothing to dedupe against
        }
        if ($existing) return;

        // One active tier at a time: a new tier grant replaces the old one. Purchases stack.
        $replaced_different_tier = false;
        if ($scope === 'account' && in_array($type, ['subscription', 'streaming_tier'], true)) {
            $replaced_different_tier = self::replace_active_tier_entitlements($user_id, $object_id);
        }

        $wpdb->insert($t, [
            'user_id' => $user_id, 'type' => $type, 'scope' => $scope, 'object_id' => $object_id,
            'wc_order_id' => $order_id, 'wc_subscription_id' => $subscription_id, 'expires_at' => $expires_at,
        ]);

        // Integration hook for every grant type (tiers and purchases).
        do_action('bhm_entitlement_granted', $user_id, $type, $scope, $object_id);

        // Skipped when replace_active_tier_entitlements() already sent a "you switched tiers" notice,
        // or when the plugin that owns the item sends its own (bh-courses' "Enrolled").
        $notify = !$replaced_different_tier && apply_filters('bhm_send_entitlement_notice', true, $user_id, $type, $scope, $object_id);
        if ($notify && class_exists('OUS_Notifications')) {
            $is_purchase = $type === 'purchase';
            OUS_Notifications::notify(
                $user_id,
                'entitlement_granted',
                $is_purchase ? 'Yours now: ' . get_the_title($object_id) : 'Access granted',
                $is_purchase ? 'It\'s in your account whenever you want it.' : 'Thanks for your support — your access is active now.',
                $is_purchase ? (string) get_permalink($object_id) : '',
                'BH Monetization',
                false // in-app only; WooCommerce already emails an order confirmation
            );
        }
    }
}
