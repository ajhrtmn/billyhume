<?php
if (!defined('ABSPATH')) exit;

/**
 * Split out of class-products.php (DRY/SOLID audit Phase 3) — the actual
 * order/subscription -> entitlement bridge. Fires bhm_entitlement_granted /
 * bhm_entitlement_revoked — the tier-level third-party-integration choke
 * point ROADMAP-platform-evolution.md Section 4 asks for (its named
 * example: "connect Discord and grant a role per tier"). No such
 * integration is built here; this is just the one clean place for a
 * future one to hook in, args: ($user_id, $type, $scope, $object_id[, $reason]).
 */
class BHM_Entitlements {
    public static function init() {
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
        $has_subs = class_exists('BH_Commerce') ? BH_Commerce::has_subscriptions() : class_exists('WC_Subscriptions');
        if ($has_subs) {
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
        // treatment BHM_ProductSync::sync_tier_wc_product() already got.
        // class_exists('BH_Commerce') isn't checked here because this
        // whole class already requires the core (BHCORE_LOADED gate in
        // bh-monetization-woo.php); an old core without BH_Commerce
        // would need its own fallback, out of scope for this migration.
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
                    // Gifting: a line item carrying a recipient email
                    // (BHM_Gifts::capture_gift_email(), set at add-to-cart
                    // time) never grants the BUYER anything — it creates a
                    // redemption code and emails the recipient instead.
                    // The buyer's payment still fully processes as an
                    // ordinary tier purchase; only the entitlement side
                    // is redirected.
                    $gift_email = !empty($item['gift_email']) ? $item['gift_email'] : '';
                    if ($gift_email && class_exists('BHM_Gifts')) {
                        BHM_Gifts::create_redemption($tier_id, $user_id, $order_id, $gift_email);
                        continue;
                    }

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
            $items[] = ['product_id' => $item->get_product_id(), 'quantity' => $item->get_quantity(), 'gift_email' => (string) $item->get_meta('_bhm_gift_email')];
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

    // Refund/chargeback abuse-pattern detection lives in its own
    // BHM_Fraud class (includes/class-fraud.php) — see that file's
    // docblock for the DRY/SOLID-refactor rationale. This class only
    // calls into it (on_order_reversed() above).

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

        // Real gap, caught during a refund/revocation audit: on_order_reversed()
        // above notifies the customer when a refund/cancellation takes
        // access away, but this path (subscription paused or ended —
        // arguably the MORE common way access actually lapses, since it
        // needs no manual refund at all) never did. A fan whose card
        // just failed or who quietly cancelled got no signal their
        // access was gone until they next hit a paywall. Reason-aware
        // copy ('paused' can resume automatically per on_subscription_active(),
        // 'ended' cannot) rather than one generic message for both.
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

    // Public entry point for BHM_Gifts::handle_redeem() — a gift claim
    // has no order/subscription of its OWN to dedupe grant_entitlement()
    // against on the redeeming side (the order already belongs to the
    // buyer, not the recipient), so this always grants a fresh
    // streaming_tier entitlement rather than routing through the private
    // grant_entitlement()'s order/subscription-keyed idempotency check —
    // BHM_Gifts' own `status = 'redeemed'` guard is what prevents a
    // double-claim, not this.
    public static function grant_gift_entitlement($user_id, $tier_id, $order_id) {
        global $wpdb;
        $t = $wpdb->prefix . 'bhm_entitlements';
        // Same tier-exclusivity enforcement grant_entitlement() applies —
        // this bypasses that method entirely (see docblock above), so it
        // needs its own call or a gift redemption could stack a second
        // active tier on top of whatever the recipient already has.
        self::replace_active_tier_entitlements($user_id, $tier_id);
        $wpdb->insert($t, [
            'user_id' => $user_id, 'type' => 'streaming_tier', 'scope' => 'account', 'object_id' => $tier_id,
            'wc_order_id' => $order_id, 'expires_at' => gmdate('Y-m-d H:i:s', strtotime('+30 days')),
        ]);
        do_action('bhm_entitlement_granted', $user_id, 'streaming_tier', 'account', $tier_id);
    }

    // Public, single shared "revoke one specific entitlement row by ID"
    // path — factored out so BHM_CRMIntegration's manual admin revoke
    // button and BHM_Debug's own "simulate a refund" test action both
    // fire the exact same real event/notification, instead of two
    // independently-written copies of "delete a row and maybe notify"
    // drifting apart. Returns the deleted row (as an array) on success,
    // or null if no such entitlement existed.
    public static function revoke_entitlement_by_id($entitlement_id, $reason = 'manual_revoke') {
        global $wpdb;
        $t = $wpdb->prefix . 'bhm_entitlements';
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

    // Debug Tools' own test-grant buttons previously wrote directly to
    // $wpdb, bypassing grant_entitlement() entirely — meaning they never
    // exercised the real tier-exclusivity replacement logic, the
    // bhm_entitlement_granted event, or the grant notification, giving
    // false confidence that "testing" a grant here proved anything about
    // the real purchase path. These two thin public wrappers route
    // Debug Tools through the exact same private grant_entitlement()
    // every real order/subscription webhook uses.
    public static function debug_grant_tier($user_id, $tier_id, $days = 30) {
        self::grant_entitlement($user_id, 'streaming_tier', 'account', (int) $tier_id, null, null, gmdate('Y-m-d H:i:s', strtotime('+' . (int) $days . ' days')));
    }

    public static function debug_grant_purchase($user_id, $object_id, $scope = 'track') {
        self::grant_entitlement($user_id, 'purchase', $scope, (int) $object_id, null, null, null);
    }

    // Deletes every OTHER active account-scope subscription/streaming_tier
    // entitlement this user currently holds, so grant_entitlement()'s
    // insert below is always the ONE active tier, never a second one
    // stacked alongside it. Returns true only when a DIFFERENT tier was
    // actually replaced (used by grant_entitlement() to skip its own
    // generic "Access granted" notice in favor of this method's more
    // specific switch/upgrade/downgrade one).
    //
    // A same-tier existing row (early renewal, a duplicate webhook for
    // a case the order/subscription-keyed idempotency check above
    // didn't happen to catch) is cleared silently — no downgrade-credit
    // math, no "you switched" messaging, since it isn't a tier change.
    private static function replace_active_tier_entitlements($user_id, $new_tier_id) {
        global $wpdb;
        $t = $wpdb->prefix . 'bhm_entitlements';
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

            // Wallet-credits unused days on the OLD tier — previously
            // dead code (defined, never called from anywhere real) until
            // this fix wired the one call site that actually needed it.
            // Steps aside on its own when WooCommerce Subscriptions'
            // real switcher is active (see its own docblock).
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

        // Real correctness gap an ecosystem audit caught: nothing
        // enforced "one active tier at a time" — a fan could end up
        // with several simultaneous active subscription/streaming_tier
        // rows (two tiers in one cart, or bought at different times),
        // each independently satisfying BHM_Gate's "at or above" check
        // with no single canonical "current tier." Account-scope tier
        // grants are exclusive: granting a new one always replaces
        // whatever active tier entitlement already existed, the same
        // way a real subscription upgrade/downgrade works everywhere
        // else. Scoped to subscription/streaming_tier + scope='account'
        // only — a one-time track/release purchase legitimately stacks
        // (owning five tracks is normal; being subscribed to five tiers
        // at once isn't).
        $replaced_different_tier = false;
        if ($scope === 'account' && in_array($type, ['subscription', 'streaming_tier'], true)) {
            $replaced_different_tier = self::replace_active_tier_entitlements($user_id, $object_id);
        }

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
        // retry re-confirming something already granted. Skipped when
        // replace_active_tier_entitlements() already sent its own more
        // specific "you switched tiers" notification above — a generic
        // "Access granted" right after that would just be a confusing,
        // redundant second notice about the exact same event.
        if (!$replaced_different_tier && class_exists('OUS_Notifications')) {
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
