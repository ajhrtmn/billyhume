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
        }
    }

    /* ---------- tier <-> WooCommerce product sync ---------- */

    public static function sync_tier_wc_product($tier_post_id, $name, $price_cents) {
        if (!class_exists('WooCommerce')) return;
        $existing_id = (int) get_post_meta($tier_post_id, '_bhm_wc_product_id', true);
        // WC_Subscriptions is the plugin's main bootstrap class;
        // WC_Product_Subscription is its actual product class — both
        // ship together in every real install, but checked separately
        // rather than assumed, since a class_exists() on one doesn't
        // guarantee the other loaded (e.g. mid-upgrade, a partial
        // install, or a future WooCommerce Subscriptions version that
        // restructures its own class map). Degrading to a plain Simple
        // product if either is missing is always safe; instantiating a
        // class that turns out not to exist is a fatal error.
        $has_subs = class_exists('WC_Subscriptions') && class_exists('WC_Product_Subscription');
        $price = number_format($price_cents / 100, 2, '.', '');

        // A real subscription product type if WooCommerce Subscriptions
        // is active; otherwise a plain Simple product — see this
        // plugin's own bootstrap docblock for why that's a deliberate,
        // documented degrade rather than a workaround.
        $product = $existing_id ? wc_get_product($existing_id) : null;
        if (!$product) {
            $product = $has_subs ? new WC_Product_Subscription() : new WC_Product_Simple();
        }

        $product->set_name($name . ' — Supporter Tier');
        $product->set_regular_price($price);
        $product->set_virtual(true); // no shipping — this is access, not a physical good
        $product->set_catalog_visibility('hidden'); // never shows up in a normal WooCommerce shop listing — sold only via this plugin's own tier picker
        if ($has_subs && method_exists($product, 'set_props')) {
            $product->set_props(['subscription_period' => 'month', 'subscription_period_interval' => 1]);
        }
        $product->save();

        update_post_meta($tier_post_id, '_bhm_wc_product_id', $product->get_id());
        update_post_meta($product->get_id(), '_bhm_tier_id', $tier_post_id); // reverse lookup, used by on_order_completed()/on_subscription_active()
    }

    // Same idea as sync_tier_wc_product() but for a single track/release's
    // own outright-purchase option — a plain one-time Simple product,
    // never a subscription (buying a track outright is never recurring).
    private static function sync_object_purchase_product($object_id, $post_type, $price_cents) {
        if (!class_exists('WooCommerce')) return 0;
        $meta_key = '_bhm_purchase_wc_product_id';
        $existing_id = (int) get_post_meta($object_id, $meta_key, true);
        $product = $existing_id ? wc_get_product($existing_id) : null;
        if (!$product) $product = new WC_Product_Simple();

        $title = get_the_title($object_id);
        $product->set_name($title . ' (' . ($post_type === 'bhs_release' ? 'Album' : 'Track') . ' Purchase)');
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
        $pay_per_play = (int) get_post_meta($post->ID, '_bhm_pay_per_play_cents', true);
        $tiers = BHM_Tiers::all();

        echo '<p><label><strong>Require a supporter tier to access this ' . ($post_type === 'bhs_release' ? 'release' : 'track') . '</strong><br>';
        echo '<select name="bhm_required_tier"><option value="0">— Open to everyone —</option>';
        foreach ($tiers as $t) {
            echo '<option value="' . esc_attr($t['id']) . '" ' . selected($required_tier, $t['id'], false) . '>' . esc_html($t['name']) . ' ($' . number_format($t['price_cents'] / 100, 2) . '/mo or equivalent)</option>';
        }
        echo '</select></label> <span class="description">' . (empty($tiers) ? 'No tiers created yet — see Supporter Tiers.' : '') . '</span></p>';

        echo '<p><label><strong>Outright purchase price (USD, optional)</strong><br><input type="number" step="0.01" min="0" name="bhm_purchase_price" value="' . esc_attr($purchase_price ? number_format($purchase_price / 100, 2, '.', '') : '') . '" style="width:140px;"> <span class="description">Delivers whatever quality encodes are attached (see Quality Encodes above) as downloads on purchase.</span></label></p>';

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
        $order = wc_get_order($order_id);
        if (!$order) {
            // A completed-order hook firing for an order ID WooCommerce
            // itself can't load is exactly the kind of "money moved but
            // nothing here can trust it" situation worth a loud log
            // entry rather than a silent early return — someone paid and
            // this plugin has no idea what they should now have access
            // to.
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('error', "on_order_completed: wc_get_order($order_id) returned nothing", ['order_id' => $order_id], 'BH Monetization');
            }
            return;
        }

        // Per-item, not per-order: one bad product meta lookup or a
        // single grant_entitlement() throwing must not stop every OTHER
        // item in the same order from being fulfilled — a fan who bought
        // a tier AND a track in one checkout shouldn't lose the track
        // just because the tier grant hit an edge case (or vice versa).
        foreach ($order->get_items() as $item) {
            try {
                $product_id = $item->get_product_id();
                $user_id = $order->get_customer_id();
                if (!$user_id) continue; // guest checkout has no account to grant an entitlement to — nothing to attach access to

                $tier_id = (int) get_post_meta($product_id, '_bhm_tier_id', true);
                if ($tier_id) {
                    self::grant_entitlement($user_id, class_exists('WC_Subscriptions') ? 'subscription' : 'streaming_tier', 'account', $tier_id, $order_id, null,
                        class_exists('WC_Subscriptions') ? null : gmdate('Y-m-d H:i:s', strtotime('+30 days')));
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
                        'order_id' => $order_id, 'product_id' => $item->get_product_id(),
                    ], 'BH Monetization');
                }
                // Deliberately swallowed past this point (not re-thrown)
                // — a fulfillment bug on one line item must never turn
                // into a fatal error mid-way through WooCommerce's own
                // order-completion flow for the customer's whole order.
            }
        }
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
        $order = wc_get_order($order_id);
        if (!$order) return;

        $wpdb->delete($wpdb->prefix . 'bhm_entitlements', ['wc_order_id' => $order_id]);

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $wallet_cents = (int) get_post_meta($product_id, '_bhm_wallet_topup_cents', true);
            if (!$wallet_cents) continue;
            $user_id = $order->get_customer_id();
            if (!$user_id) continue;
            $reverse_amount = min($wallet_cents, BHM_Wallet::balance_cents($user_id));
            if ($reverse_amount > 0) {
                BHM_Wallet::apply_ledger_delta($user_id, -$reverse_amount, 'topup_reversed', null, $order_id);
            }
        }

        if ($order->get_customer_id()) self::track_refund_pattern($order->get_customer_id(), $order_id);

        // A second real notification example alongside BH Courses'
        // course-completion one (see the core's OUS_Notifications
        // docblock) — chosen specifically because it's a genuine
        // transparency moment: something a customer's account just had
        // taken away, not a nice-to-have "hey, FYI." class_exists()-
        // guarded since notifications shipped in core 3.2.0 — this
        // plugin only ever depends on the core's PRESENCE, never any
        // one optional feature inside it, so an older core still works,
        // it just doesn't send this particular notice.
        if (class_exists('OUS_Notifications') && $order->get_customer_id()) {
            OUS_Notifications::notify(
                $order->get_customer_id(),
                'refund_reversed',
                'A refund adjusted your account',
                'Order #' . $order_id . ' was refunded or cancelled, and any access or wallet credit it granted has been reversed accordingly.',
                '',
                'BH Monetization'
            );
        }
    }

    // Refund/chargeback abuse pattern: buy → stream or download → refund
    // → repeat, extracting real content or plays while the artist eats
    // the cost every time. One refund is completely normal (real
    // dissatisfaction, a real payment dispute) — this only reacts to a
    // REPEATED pattern from the same account within a rolling window,
    // and it flags for admin review rather than silently auto-banning
    // anyone (a false positive here is a real fan getting locked out,
    // which is worse than a human spending 30 seconds checking a flag).
    const REFUND_ABUSE_WINDOW = 30 * DAY_IN_SECONDS;
    const REFUND_ABUSE_THRESHOLD = 3;

    private static function track_refund_pattern($user_id, $order_id) {
        $log = get_user_meta($user_id, '_bhm_refund_log', true);
        $log = is_array($log) ? $log : [];
        $cutoff = time() - self::REFUND_ABUSE_WINDOW;
        $log = array_values(array_filter($log, fn($ts) => $ts > $cutoff));
        $log[] = time();
        update_user_meta($user_id, '_bhm_refund_log', $log);

        $flagged = count($log) >= self::REFUND_ABUSE_THRESHOLD;
        update_user_meta($user_id, '_bhm_refund_flagged', $flagged ? '1' : '');

        // Cross-account correlation: the same evasion this per-account
        // log can't catch on its own — someone hitting the threshold,
        // then just signing up again under a new account. Hashed
        // (never raw IP) fingerprint of connection + a persistent,
        // non-tracking cookie id, recorded once per refund.
        $fingerprint = self::fingerprint_for();
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'bhm_refund_fingerprints', [
            'fingerprint' => $fingerprint, 'user_id' => $user_id, 'wc_order_id' => $order_id,
        ]);
        $cutoff_sql = gmdate('Y-m-d H:i:s', $cutoff);
        $distinct_users = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}bhm_refund_fingerprints
             WHERE fingerprint = %s AND created_at > %s", $fingerprint, $cutoff_sql
        ));
        $cross_account_flagged = $distinct_users >= 2;
        if ($cross_account_flagged) update_user_meta($user_id, '_bhm_refund_shared_device_flagged', '1');

        if ($flagged || $cross_account_flagged) {
            // Extension point, not an automatic restriction — this
            // plugin doesn't unilaterally decide to cut someone off;
            // it surfaces the pattern (here, and in bh-crm's activity
            // summary via class-crm-integration.php) so a human decides
            // what, if anything, to do about a specific account.
            do_action('bhm_refund_pattern_flagged', $user_id, count($log), $cross_account_flagged);
        }
    }

    // A hash, not the raw IP — this correlates repeat behavior without
    // this table itself becoming a new place raw connection data sits
    // around. The cookie half is a plain random id (set once, read on
    // subsequent visits), NOT a cross-site tracking mechanism and NOT
    // tied to any ad/analytics network — its only job is "does this
    // browser look like the same one as last time," same-origin only.
    const FINGERPRINT_COOKIE = 'bhm_fp';

    private static function fingerprint_for() {
        if (empty($_COOKIE[self::FINGERPRINT_COOKIE])) {
            $val = wp_generate_password(32, false);
            if (!headers_sent()) {
                setcookie(self::FINGERPRINT_COOKIE, $val, time() + 5 * YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
            }
            $_COOKIE[self::FINGERPRINT_COOKIE] = $val;
        }
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
        return hash('sha256', $ip . '|' . $_COOKIE[self::FINGERPRINT_COOKIE]);
    }

    public static function refund_count_recent($user_id) {
        $log = get_user_meta($user_id, '_bhm_refund_log', true);
        $log = is_array($log) ? $log : [];
        $cutoff = time() - self::REFUND_ABUSE_WINDOW;
        return count(array_filter($log, fn($ts) => $ts > $cutoff));
    }

    public static function on_subscription_active($subscription) {
        $user_id = $subscription->get_customer_id();
        foreach ($subscription->get_items() as $item) {
            $tier_id = (int) get_post_meta($item->get_product_id(), '_bhm_tier_id', true);
            if ($tier_id) {
                self::grant_entitlement($user_id, 'subscription', 'account', $tier_id, null, $subscription->get_id(), null);
            }
        }
    }

    public static function on_subscription_ended($subscription) {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'bhm_entitlements', ['wc_subscription_id' => $subscription->get_id()]);
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
