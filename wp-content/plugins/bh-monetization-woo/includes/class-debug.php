<?php
if (!defined('ABSPATH')) exit;

// BHM_VER 0.4.5 — register() now sets 'group' => OUS_Debug::GROUP_SEED_RESET
// (own-ur-shit's Debug Tools reorganization pass — see that plugin's
// class-debug.php docblock), filing this section under "Seed & Reset
// Tools" instead of the default bucket. No other change.

/**
 * Registers this plugin's section on the core's shared Debug Tools page
 * — same extension point every other plugin here uses. Deliberately
 * more capable than a typical "seed some fake rows" section: money
 * flows are exactly the kind of thing you want to exercise end-to-end
 * WITHOUT touching a real payment gateway, a real card, or waiting on a
 * real WooCommerce checkout — every action below mints entitlements,
 * wallet credit, and simulated orders directly, so an artist (or
 * anyone testing this plugin) can walk through every paywall/purchase/
 * tip/pay-per-play code path on a real site with zero real money moved.
 *
 * Locked behind OUS_Debug::is_locked() exactly like every other
 * registered section — these actions create real database rows
 * (entitlements, wallet balances) and must never be reachable on a
 * production site by accident.
 */
class BHM_Debug {
    const SEED_TAG = '__bhm_test__';

    public static function init() {
        add_filter('ous_debug_tools', [self::class, 'register']);
    }

    public static function register($tools) {
        $tools['bh-monetization-woo'] = [
            'label'  => 'BH Monetization',
            'render' => [self::class, 'render_section'],
            'handle' => [self::class, 'handle_action'],
            'reset'  => [self::class, 'reset'],
            'group'  => OUS_Debug::GROUP_SEED_RESET,
        ];
        return $tools;
    }

    public static function render_section() {
        $uid = get_current_user_id();
        echo '<p>Simulate every money-tied code path without a real WooCommerce checkout. All actions apply to <strong>your own current account</strong> (user #' . esc_html($uid) . ') unless noted.</p>';

        echo '<h4>Tiers</h4>';
        echo OUS_Debug::button('bh-monetization-woo', 'seed_tiers', 'Create 2 test tiers ($3/mo, $8/mo)');

        echo '<h4>Entitlements (simulated purchases)</h4>';
        echo OUS_Debug::button('bh-monetization-woo', 'grant_top_tier', 'Grant yourself the top tier (30 days)');
        echo OUS_Debug::button('bh-monetization-woo', 'revoke_all_tiers', 'Revoke all your tier entitlements');
        echo OUS_Debug::button('bh-monetization-woo', 'simulate_track_purchase', 'Simulate buying the first monetized track you own the rights to test with', '<input type="number" name="track_id" placeholder="Track ID" style="width:100px;">');

        echo '<h4>Wallet (pay-per-play)</h4>';
        echo OUS_Debug::button('bh-monetization-woo', 'credit_wallet', 'Credit your wallet $10.00 (no real charge)');
        echo OUS_Debug::button('bh-monetization-woo', 'zero_wallet', 'Zero out your wallet balance');
        $balance = class_exists('BHM_Wallet') ? BHM_Wallet::balance_cents($uid) : 0;
        echo '<p class="description">Current wallet balance: $' . esc_html(number_format($balance / 100, 2)) . '</p>';

        echo '<h4>Refund/fraud-path simulation</h4>';
        echo '<p class="description">Exercises the SAME revocation code path a real chargeback/refund triggers (class-products.php\'s on_order_reversed()) — confirms an entitlement or wallet credit actually gets taken back, not just granted.</p>';
        echo OUS_Debug::button('bh-monetization-woo', 'simulate_refund_last_grant', 'Simulate a refund of your most recent test grant', '', 'This will revoke your most recently granted test entitlement or wallet credit — continue?');

        // Real gap this closes: on_subscription_active()/on_subscription_ended()/
        // on_subscription_paused() (class-products.php), and the front-end
        // pause/resume UI (class-frontend.php), were completely
        // unreachable for testing without the real, paid WooCommerce
        // Subscriptions extension installed — this site doesn't have it.
        // BHM_MockCommerce hooks BH_Commerce's own filter seams
        // (bh_commerce_has_subscriptions/bh_commerce_get_subscription)
        // rather than faking WooCommerce Subscriptions globally, so real
        // production behavior is untouched unless this is explicitly
        // switched on here.
        echo '<h4>Subscription lifecycle (mock — no real extension needed)</h4>';
        $mock_enabled = class_exists('BHM_MockCommerce') && BHM_MockCommerce::is_enabled();
        if (class_exists('WC_Subscriptions')) {
            echo '<p class="description">WooCommerce Subscriptions is actually installed — testing against the real extension directly, mock mode not needed here.</p>';
        } elseif ($mock_enabled) {
            echo '<p class="description">Mock mode is <strong>ON</strong> — BH_Commerce::has_subscriptions() reports true and the pause/resume UI on the tier picker is now reachable for testing.</p>';
            echo OUS_Debug::button('bh-monetization-woo', 'mock_disable', 'Turn mock mode off');
            $mock_sub_id = self::user_mock_subscription_id($uid);
            if ($mock_sub_id) {
                $data = BHM_MockCommerce::get($mock_sub_id);
                echo '<p class="description">Your fake subscription #' . esc_html($mock_sub_id) . ' is currently: <strong>' . esc_html($data['status'] ?? 'unknown') . '</strong></p>';
                echo OUS_Debug::button('bh-monetization-woo', 'mock_pause', 'Pause it (fires the real on_subscription_paused())');
                echo OUS_Debug::button('bh-monetization-woo', 'mock_resume', 'Resume it (fires the real on_subscription_active())');
                echo OUS_Debug::button('bh-monetization-woo', 'mock_cancel', 'Cancel/end it (fires the real on_subscription_ended())');
            } else {
                echo OUS_Debug::button('bh-monetization-woo', 'mock_activate', 'Create a fake active subscription for the top test tier (fires the real on_subscription_active())');
            }
        } else {
            echo '<p class="description">Turns on a real, working stand-in for WooCommerce Subscriptions so the subscription pause/resume UI and its lifecycle handlers can be tested without installing the paid extension. Off by default — real behavior on this site (the "one-time purchase = 30 days" fallback) is completely unaffected until you turn this on.</p>';
            echo OUS_Debug::button('bh-monetization-woo', 'mock_enable', 'Turn mock mode on');
        }

        echo '<h4>WooCommerce order simulation</h4>';
        if (class_exists('WooCommerce')) {
            echo OUS_Debug::button('bh-monetization-woo', 'simulate_tier_order', 'Create + complete a real WC order for the top test tier (drives the actual on_order_completed() path, not a shortcut around it)');
            if (class_exists('BHM_Gifts')) {
                echo OUS_Debug::button('bh-monetization-woo', 'simulate_gift_order', 'Create + complete a real WC gift order for the top test tier (drives BHM_Gifts::create_redemption(), not a shortcut around it)', '<input type="email" name="gift_email" placeholder="Recipient email" style="width:200px;">');
            }
        } else {
            echo '<p class="description">Install WooCommerce to test the real order-completion path — until then, the buttons above exercise entitlement/wallet logic directly, which covers most of what actually matters for gating.</p>';
        }

        if (class_exists('BHM_Gifts')) {
            global $wpdb;
            $t = $wpdb->prefix . BHM_Gifts::TABLE;
            $recent = $wpdb->get_results("SELECT * FROM $t ORDER BY id DESC LIMIT 5", ARRAY_A);
            if ($recent) {
                echo '<h4>Recent gift redemptions</h4>';
                echo '<p class="description">wp_mail() may not actually deliver on a local install — claim links here so gifting can still be tested end-to-end.</p>';
                echo '<table class="widefat" style="max-width:700px;"><thead><tr><th>Recipient</th><th>Status</th><th>Claim link</th></tr></thead><tbody>';
                foreach ($recent as $row) {
                    $claim_url = add_query_arg('gift_code', $row['code'], BHM_Gifts::redeem_page_url());
                    echo '<tr><td>' . esc_html($row['recipient_email']) . '</td><td>' . esc_html($row['status']) . '</td><td><a href="' . esc_url($claim_url) . '">' . esc_html($claim_url) . '</a></td></tr>';
                }
                echo '</tbody></table>';
            }
        }

        if (class_exists('BHM_Storefront')) {
            echo '<h4>Storefront</h4>';
            echo OUS_Debug::button('bh-monetization-woo', 'seed_storefront_collection', 'Create 1 test collection + 1 test product');
        }
    }

    public static function handle_action($action, $post) {
        $uid = get_current_user_id();
        global $wpdb;

        switch ($action) {
            case 'seed_tiers':
                // Benefit keys deliberately differ per tier, not just
                // price — real coverage for the "orthogonal to price
                // rank" case BHM_Tiers::benefit_registry() exists for
                // (see its own docblock): the cheaper tier grants
                // 'courses', the pricier one grants 'streaming' +
                // 'downloads' but NOT 'courses' — so testing "is the
                // cheap tier's course access actually independent of the
                // pricier tier's streaming access" is possible
                // immediately after seeding, not just theoretically true.
                foreach ([
                    ['Fan ' . self::SEED_TAG, 300, ['courses']],
                    ['Supporter ' . self::SEED_TAG, 800, ['streaming', 'downloads', 'merch_discount']],
                ] as $t) {
                    $id = wp_insert_post(['post_title' => $t[0], 'post_type' => BHM_Tiers::CPT, 'post_status' => 'publish']);
                    if (!is_wp_error($id)) {
                        update_post_meta($id, '_bhm_price_cents', $t[1]);
                        update_post_meta($id, '_bhm_benefits', 'Test tier — safe to delete.');
                        update_post_meta($id, '_bhm_benefit_keys', $t[2]);
                        if (class_exists('WooCommerce')) BHM_Products::sync_tier_wc_product($id, $t[0], $t[1]);
                    }
                }
                return '2 test tiers created (with different benefit keys each, not just different prices — see class-debug.php\'s own comment on this).';

            case 'seed_storefront_collection':
                if (!class_exists('BHM_Storefront') || !post_type_exists('product')) return 'WooCommerce isn\'t active — nothing to attach a collection/product to yet.';
                $term = wp_insert_term('Test Collection ' . self::SEED_TAG, BHM_Storefront::TAXONOMY);
                if (is_wp_error($term)) return 'Could not create test collection: ' . $term->get_error_message();
                $product_id = wp_insert_post(['post_title' => 'Test Product ' . self::SEED_TAG, 'post_type' => 'product', 'post_status' => 'publish']);
                if (!is_wp_error($product_id)) {
                    wp_set_object_terms($product_id, [$term['term_id']], BHM_Storefront::TAXONOMY);
                    if (function_exists('wc_get_product')) {
                        $p = wc_get_product($product_id);
                        if ($p) { $p->set_regular_price('19.99'); $p->save(); }
                    }
                }
                $created_term = get_term($term['term_id']);
                return 'Test collection + 1 test product created — visit /' . BHM_Storefront::REWRITE_SLUG . '/' . ($created_term ? $created_term->slug : '') . '/ to see the auto-generated collection landing page.';

            case 'grant_top_tier':
                // Was a raw $wpdb->insert(), bypassing grant_entitlement()
                // entirely — meaning this button never exercised the real
                // tier-exclusivity replacement logic, the
                // bhm_entitlement_granted event, or the grant
                // notification, giving false confidence that clicking it
                // proved anything about the real purchase path. Now
                // routes through BHM_Products::debug_grant_tier(), the
                // exact same private grant_entitlement() a real order
                // uses.
                $tiers = BHM_Tiers::all();
                if (!$tiers) return 'No tiers exist yet — click "Create 2 test tiers" first.';
                $top = end($tiers);
                BHM_Products::debug_grant_tier($uid, $top['id'], 30);
                return 'Granted "' . $top['name'] . '" to your account for 30 days (via the real grant path — also replaces any other active tier you were holding, exactly like a real purchase would).';

            case 'revoke_all_tiers':
                // Was a raw bulk $wpdb->delete() with no event/notification
                // fired at all — now revokes each row through the same
                // shared path the CRM's manual revoke button and the
                // refund-simulation button below use.
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}bhm_entitlements WHERE user_id = %d AND type IN ('streaming_tier','subscription')", $uid
                ));
                foreach ($rows as $row) BHM_Products::revoke_entitlement_by_id($row->id, 'debug_revoke_all');
                return count($rows) . ' tier/subscription entitlement(s) removed for your account (via the real revoke path — event fired + notification sent for each).';

            case 'simulate_track_purchase':
                $track_id = (int) ($post['track_id'] ?? 0);
                if (!$track_id || get_post_type($track_id) !== 'bhs_track') return 'Enter a real bhs_track post ID first.';
                BHM_Products::debug_grant_purchase($uid, $track_id, 'track');
                return 'Granted a simulated purchase entitlement for track #' . $track_id . ' (via the real grant path).';

            case 'credit_wallet':
                BHM_Wallet::credit($uid, 1000, 'test_credit_' . self::SEED_TAG);
                return '$10.00 credited to your wallet (no real charge).';

            case 'zero_wallet':
                $bal = BHM_Wallet::balance_cents($uid);
                if ($bal > 0) BHM_Wallet::apply_ledger_delta($uid, -$bal, 'test_zeroed');
                return 'Wallet zeroed.';

            case 'simulate_refund_last_grant':
                // Look at whichever happened more recently: the last
                // entitlement grant or the last wallet credit — and
                // reverse exactly that one, the same way a real
                // refund/cancellation webhook would.
                $last_ent = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}bhm_entitlements WHERE user_id = %d ORDER BY created_at DESC LIMIT 1", $uid
                ));
                $last_credit = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}bhm_wallet_ledger WHERE user_id = %d AND delta_cents > 0 ORDER BY created_at DESC LIMIT 1", $uid
                ));
                if ($last_ent && (!$last_credit || strtotime($last_ent->created_at) >= strtotime($last_credit->created_at))) {
                    // Now genuinely the same code path a real refund
                    // triggers (revoke_entitlement_by_id(), shared with
                    // on_order_reversed()'s row-level revocation and the
                    // CRM's manual revoke button) — this comment used to
                    // claim that and be wrong; a raw $wpdb->delete() here
                    // never fired the event or the notification a real
                    // refund does.
                    BHM_Products::revoke_entitlement_by_id($last_ent->id, 'debug_simulated_refund');
                    return 'Revoked entitlement #' . $last_ent->id . ' (type: ' . $last_ent->type . ') — simulating a refund/chargeback via the real revoke path (event fired + notification sent).';
                }
                if ($last_credit) {
                    $reverse = min((int) $last_credit->delta_cents, BHM_Wallet::balance_cents($uid));
                    BHM_Wallet::apply_ledger_delta($uid, -$reverse, 'test_refund_reversal');
                    return 'Reversed $' . number_format($reverse / 100, 2) . ' of wallet credit — simulating a refund/chargeback.';
                }
                return 'Nothing to reverse — grant yourself a tier or wallet credit first.';

            case 'mock_enable':
                if (!class_exists('BHM_MockCommerce')) return 'BHM_MockCommerce is not loaded.';
                BHM_MockCommerce::enable();
                return 'Mock subscriptions mode is ON — BH_Commerce::has_subscriptions() now reports true. Real production behavior is unaffected for any site that does not also do this.';

            case 'mock_disable':
                if (!class_exists('BHM_MockCommerce')) return 'BHM_MockCommerce is not loaded.';
                BHM_MockCommerce::disable();
                return 'Mock subscriptions mode is OFF — back to real WooCommerce Subscriptions detection.';

            case 'mock_activate':
                if (!class_exists('BHM_MockCommerce') || !BHM_MockCommerce::is_enabled()) return 'Turn mock mode on first.';
                $tiers = BHM_Tiers::all();
                if (!$tiers) return 'No tiers exist yet — click "Create 2 test tiers" first.';
                $top = end($tiers);
                if (!$top['wc_product_id']) return 'That tier has no WooCommerce product yet — save it once from the Supporter Tiers screen.';
                $fake_id = BHM_MockCommerce::create($uid, $top['wc_product_id']);
                $fake = new BHM_FakeSubscription(BHM_MockCommerce::get($fake_id));
                BHM_Products::on_subscription_active($fake); // the real production handler, exactly what a real WC Subscriptions webhook fires
                return 'Created fake subscription #' . $fake_id . ' for "' . $top['name'] . '" and activated it via the real on_subscription_active() handler.';

            case 'mock_pause':
            case 'mock_resume':
            case 'mock_cancel':
                if (!class_exists('BHM_MockCommerce') || !BHM_MockCommerce::is_enabled()) return 'Turn mock mode on first.';
                $sub_id = self::user_mock_subscription_id($uid);
                if (!$sub_id) return 'No fake subscription exists yet — create one first.';
                $data = BHM_MockCommerce::get($sub_id);
                if (!$data) return 'That fake subscription no longer exists.';
                $fake = new BHM_FakeSubscription($data);
                // update_status() itself calls the matching real
                // on_subscription_paused()/on_subscription_ended()/
                // on_subscription_active() handler — see class-mock-commerce.php.
                $new_status = $action === 'mock_pause' ? 'on-hold' : ($action === 'mock_resume' ? 'active' : 'cancelled');
                $fake->update_status($new_status);
                return 'Fake subscription #' . $sub_id . ' set to "' . $new_status . '" via the real ' . ($action === 'mock_pause' ? 'on_subscription_paused()' : ($action === 'mock_resume' ? 'on_subscription_active()' : 'on_subscription_ended()')) . ' handler.';

            case 'simulate_tier_order':
                if (!class_exists('WooCommerce')) return 'WooCommerce isn\'t active.';
                $tiers = BHM_Tiers::all();
                if (!$tiers) return 'No tiers exist yet — click "Create 2 test tiers" first.';
                $top = end($tiers);
                if (!$top['wc_product_id']) return 'That tier has no WooCommerce product yet — save it once from the Supporter Tiers screen.';

                $order = wc_create_order(['customer_id' => $uid]);
                $order->add_product(wc_get_product($top['wc_product_id']), 1);
                $order->calculate_totals();
                $order->update_status('completed'); // fires woocommerce_order_status_completed for real — this is the actual production code path, not a shortcut
                return 'Created and completed a real WooCommerce order (#' . $order->get_id() . ') for "' . $top['name'] . '" — check that the entitlement now shows up.';

            case 'simulate_gift_order':
                if (!class_exists('WooCommerce')) return 'WooCommerce isn\'t active.';
                $gift_email = sanitize_email((string) ($post['gift_email'] ?? ''));
                if (!is_email($gift_email)) return 'Enter a valid recipient email first.';
                $tiers = BHM_Tiers::all();
                if (!$tiers) return 'No tiers exist yet — click "Create 2 test tiers" first.';
                $top = end($tiers);
                if (!$top['wc_product_id']) return 'That tier has no WooCommerce product yet — save it once from the Supporter Tiers screen.';

                $order = wc_create_order(['customer_id' => $uid]);
                $item_id = $order->add_product(wc_get_product($top['wc_product_id']), 1);
                // Same meta key BHM_Gifts::persist_gift_email_to_order_item()
                // writes during a REAL checkout — set directly here since
                // this simulation skips the cart entirely.
                $item = $order->get_item($item_id);
                if ($item) { $item->add_meta_data('_bhm_gift_email', $gift_email, true); $item->save(); }
                $order->calculate_totals();
                $order->update_status('completed');
                return 'Created and completed a real gift WooCommerce order (#' . $order->get_id() . ') for "' . $top['name'] . '" — check Debug Tools > BH Monetization for the redemption code, or check ' . esc_html($gift_email) . '\'s inbox (wp_mail(), so a local/dev install may not actually deliver it).';
        }
        return '';
    }

    // The current user's most recent fake wc_subscription_id, if any —
    // real entitlement rows created via mock_activate() above carry a
    // wc_subscription_id in the >= 900001 sentinel range
    // (BHM_MockCommerce::next_id()'s own reasoning), so this is a plain
    // lookup against the real bhm_entitlements table, not a second
    // parallel store of "which fake sub is this user's."
    private static function user_mock_subscription_id($user_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT wc_subscription_id FROM {$wpdb->prefix}bhm_entitlements WHERE user_id = %d AND wc_subscription_id >= 900001 ORDER BY id DESC LIMIT 1",
            $user_id
        ));
    }

    public static function reset() {
        global $wpdb;
        $like = '%' . $wpdb->esc_like(self::SEED_TAG) . '%';

        $tier_ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title LIKE %s", BHM_Tiers::CPT, $like));
        foreach ($tier_ids as $id) {
            $wc_product_id = (int) get_post_meta($id, '_bhm_wc_product_id', true);
            if ($wc_product_id && function_exists('wc_get_product')) {
                $product = wc_get_product($wc_product_id);
                if ($product) $product->delete(true);
            }
            wp_delete_post($id, true);
        }

        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bhm_wallet_ledger WHERE reason LIKE %s", $like));

        $removed_products = 0;
        if (post_type_exists('product')) {
            $product_ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_title LIKE %s", $like));
            foreach ($product_ids as $id) {
                if (function_exists('wc_get_product')) {
                    $p = wc_get_product($id);
                    if ($p) { $p->delete(true); $removed_products++; continue; }
                }
                wp_delete_post($id, true);
                $removed_products++;
            }
        }
        $removed_terms = 0;
        if (class_exists('BHM_Storefront') && taxonomy_exists(BHM_Storefront::TAXONOMY)) {
            $terms = get_terms(['taxonomy' => BHM_Storefront::TAXONOMY, 'hide_empty' => false, 'name__like' => self::SEED_TAG]);
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) { wp_delete_term($term->term_id, BHM_Storefront::TAXONOMY); $removed_terms++; }
            }
        }

        return count($tier_ids) . ' test tier(s), ' . $removed_products . ' test product(s), and ' . $removed_terms . ' test collection(s) removed; test wallet ledger entries cleared. Entitlements/wallet balances created via the buttons above on your OWN account are left as-is (they\'re real account state, not tagged test data) — use "Revoke all tier entitlements" / "Zero out wallet" above to clear those specifically.';
    }
}
