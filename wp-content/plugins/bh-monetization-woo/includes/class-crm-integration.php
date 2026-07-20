<?php
if (!defined('ABSPATH')) exit;

/**
 * Optionally enriches BH CRM's person view with this plugin's own
 * activity — active supporter tier, purchase history, and wallet
 * balance. Entirely one-directional and entirely optional, mirroring
 * bh-streaming's own class-crm-integration.php exactly: if BH CRM isn't
 * installed, these add_filter() calls just sit unused.
 *
 * This is also the pattern a future LMS/courses plugin should follow
 * for its own "enrolled and paid" activity — a separate, independent
 * bh_crm_activity_summary section, never something bolted onto this one.
 */
class BHM_CRMIntegration {
    public static function init() {
        add_filter('bh_crm_active_user_ids', [self::class, 'active_user_ids']);
        add_filter('bh_crm_activity_summary', [self::class, 'activity_summary'], 10, 2);
        add_action('admin_post_bhm_revoke_entitlement', [self::class, 'handle_revoke_entitlement']);
    }

    /**
     * Real support-case gap this closes: the only ways an entitlement
     * ever got revoked were a real WooCommerce refund/cancellation
     * (class-products.php's on_order_reversed()/revoke_subscription_
     * entitlements()) or a Debug Tools action that only ever touches
     * the CURRENTLY LOGGED-IN admin's own account (class-debug.php) —
     * a comped-access-given-in-error, or a chargeback/dispute handled
     * entirely outside WooCommerce (bank-side, not a WC refund), had no
     * admin-facing fix anywhere. Fires the exact same
     * bhm_entitlement_revoked action every automated revocation path
     * already fires, so any current or future listener (Discord-role
     * sync, bh-courses' own course-access notice, etc.) sees this as
     * identical to a real refund — not a second, divergent code path.
     */
    public static function handle_revoke_entitlement() {
        if (!current_user_can('bhcore_view_crm_sensitive') || !check_admin_referer('bhm_revoke_entitlement')) {
            wp_die('Not allowed.', '', ['back_link' => true]);
        }
        global $wpdb;
        $entitlement_id = (int) ($_GET['entitlement_id'] ?? 0);
        $t = $wpdb->prefix . 'bhm_entitlements';
        $row = $entitlement_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $entitlement_id), ARRAY_A) : null;

        if ($row) {
            $wpdb->delete($t, ['id' => $entitlement_id]);
            do_action('bhm_entitlement_revoked', (int) $row['user_id'], $row['type'], $row['scope'], (int) $row['object_id'], 'manual_admin_revoke');

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
            if (class_exists('OUS_Toast')) OUS_Toast::queue('Access revoked — the fan has been notified.', 'success');
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    public static function active_user_ids($ids) {
        global $wpdb;
        $entitled = $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->prefix}bhm_entitlements");
        $wallets = $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->prefix}bhm_wallet WHERE balance_cents > 0");
        return array_merge($ids, $entitled, $wallets);
    }

    public static function activity_summary($sections, $user_id) {
        global $wpdb;
        // QA fix: wallet balance, tier, purchase history, and refund-
        // fraud flags were shown to anyone who could reach the CRM
        // person page at all (bhcore_manage_crm, granted to editor and
        // the new Studio Manager role) — including in the collapsed
        // section SUMMARY line, visible without even expanding it. This
        // is exactly the "financial/private data" security-audit finding
        // — gated behind the new admin-only bhcore_view_crm_sensitive
        // capability instead.
        if (!current_user_can('bhcore_view_crm_sensitive')) return $sections;
        $entitlements = $wpdb->get_results($wpdb->prepare(
            // id DESC as a tiebreaker — created_at only has 1-second
            // resolution, and the loop below actually depends on this
            // order to pick which entitlement counts as the "active
            // tier" (the most recently granted non-expired one), so a
            // same-second tie (bulk migration/promo grant) isn't just a
            // display-order nit here, it could pick the wrong tier.
            "SELECT * FROM {$wpdb->prefix}bhm_entitlements WHERE user_id = %d ORDER BY created_at DESC, id DESC", $user_id
        ));
        $balance = class_exists('BHM_Wallet') ? BHM_Wallet::balance_cents($user_id) : 0;

        if (!$entitlements && !$balance) return $sections;

        $active_tier = null;
        foreach ($entitlements as $e) {
            if (in_array($e->type, ['subscription', 'streaming_tier'], true) && (!$e->expires_at || strtotime($e->expires_at) > time())) {
                $tier = BHM_Tiers::get($e->object_id);
                if ($tier) { $active_tier = $tier['name']; break; }
            }
        }

        $purchase_count = count(array_filter($entitlements, fn($e) => $e->type === 'purchase'));
        // BHM_Fraud (refund/velocity fraud-pattern detection) was split
        // out of BHM_Products in the DRY/SOLID refactor pass — see
        // includes/class-fraud.php.
        $refund_count = class_exists('BHM_Fraud') ? BHM_Fraud::refund_count_recent($user_id) : 0;
        $flagged = get_user_meta($user_id, '_bhm_refund_flagged', true) === '1';
        $shared_device_flagged = get_user_meta($user_id, '_bhm_refund_shared_device_flagged', true) === '1';
        $velocity_flagged = get_user_meta($user_id, '_bhm_velocity_flagged', true) === '1';
        // Wallet top-up velocity cap, AJ's own ask — a distinct signal
        // from the play-rate velocity flag above (that's plays, this is
        // money going INTO the wallet unusually fast).
        $topup_flagged = class_exists('BHM_Fraud') && get_user_meta($user_id, '_bhm_topup_velocity_flagged', true) === '1';
        $topup_recent_cents = class_exists('BHM_Fraud') ? BHM_Fraud::topup_total_recent_cents($user_id) : 0;

        $summary = ($active_tier ? $active_tier . ' supporter' : 'No active tier') . ', ' . $purchase_count . ' purchase' . ($purchase_count === 1 ? '' : 's')
            . ', $' . number_format($balance / 100, 2) . ' play credit'
            . ($flagged ? ' — ⚠ ' . $refund_count . ' refunds in 30 days' : '')
            . ($shared_device_flagged ? ' — ⚠ shared device with another refunding account' : '')
            . ($velocity_flagged ? ' — ⚠ unusual play-rate burst detected' : '')
            . ($topup_flagged ? ' — ⚠ unusual wallet top-up volume' : '');

        $sections[] = [
            'plugin'  => 'BH Monetization',
            'summary' => $summary,
            'render'  => fn() => self::render_detail($entitlements, $balance, $refund_count, $flagged, $shared_device_flagged, $velocity_flagged, $topup_flagged, $topup_recent_cents),
        ];
        return $sections;
    }

    private static function render_detail($entitlements, $balance, $refund_count = 0, $flagged = false, $shared_device_flagged = false, $velocity_flagged = false, $topup_flagged = false, $topup_recent_cents = 0) {
        echo '<p><strong>Wallet balance:</strong> $' . esc_html(number_format($balance / 100, 2)) . '</p>';
        if ($refund_count > 0) {
            echo '<p' . ($flagged ? ' style="color:#b32d2e;font-weight:600;"' : '') . '>'
               . ($flagged ? '⚠ ' : '') . esc_html($refund_count) . ' refund' . ($refund_count === 1 ? '' : 's') . ' in the last 30 days'
               . ($flagged ? ' — repeated-refund pattern, worth a human look before extending further trust (e.g. wallet top-ups).' : '') . '</p>';
        }
        if ($topup_flagged) {
            echo '<p style="color:#b32d2e;font-weight:600;">⚠ $' . esc_html(number_format($topup_recent_cents / 100, 2)) . ' in wallet top-ups in the last 24 hours — an unusually fast burst (default cap $500/24h). Could be normal heavy use or a compromised payment method being tested. Worth a look.</p>';
        }
        if ($shared_device_flagged) {
            echo '<p style="color:#b32d2e;font-weight:600;">⚠ This account\'s device/connection matches another account with a recent refund — possible same-person, new-account evasion. Worth a look, not proof on its own (shared networks/households do happen).</p>';
        }
        if ($velocity_flagged) {
            echo '<p style="color:#b32d2e;font-weight:600;">⚠ An unusually fast burst of paid plays was detected recently — could be normal heavy use, or a compromised account/payment method being tested. Worth a look.</p>';
        }
        if (!$entitlements) return;
        echo '<div class="bhy-table-wrap"><table class="wp-list-table widefat striped"><thead><tr><th>Type</th><th>What</th><th>Granted</th><th>Expires</th><th></th></tr></thead><tbody>';
        foreach ($entitlements as $e) {
            $label = $e->scope === 'account' ? (BHM_Tiers::get($e->object_id)['name'] ?? 'Tier #' . $e->object_id) : ($e->scope . ' #' . $e->object_id);
            $revoke_url = wp_nonce_url(admin_url('admin-post.php?action=bhm_revoke_entitlement&entitlement_id=' . (int) $e->id), 'bhm_revoke_entitlement');
            echo '<tr><td>' . esc_html($e->type) . '</td><td>' . esc_html($label) . '</td><td>' . esc_html($e->created_at) . '</td><td>' . esc_html($e->expires_at ?: 'Never') . '</td>';
            // Arm/disarm instead of confirm() — same banned-native-dialog
            // reason as every other irreversible-ish action in this
            // ecosystem (bh-streaming's jam-kick button, this same
            // reasoning). First click relabels/arms for 3s; a second
            // click while armed actually revokes.
            echo '<td><button type="button" class="button button-small bhm-revoke-btn" data-url="' . esc_url($revoke_url) . '" style="color:#b32d2e;">Revoke</button></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        self::maybe_print_revoke_script();
    }

    private static function maybe_print_revoke_script() {
        static $printed = false;
        if ($printed) return;
        $printed = true;
        ?>
        <script>
        (function () {
            document.querySelectorAll('.bhm-revoke-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!btn.classList.contains('is-armed')) {
                        btn.classList.add('is-armed');
                        btn.dataset.originalLabel = btn.textContent;
                        btn.textContent = 'Confirm revoke?';
                        btn._armTimer = setTimeout(function () {
                            btn.classList.remove('is-armed');
                            btn.textContent = btn.dataset.originalLabel;
                        }, 3000);
                        return;
                    }
                    clearTimeout(btn._armTimer);
                    window.location.href = btn.dataset.url;
                });
            });
        })();
        </script>
        <?php
    }
}

// First real bh-monetization-woo contributor to the shared Metrics
// dashboard (own-ur-shit's OUS_Metrics) — same "tandem infrastructure"
// pass as bh-courses/bh-contest/bh-crm's own versions of this
// registration, filling the one real gap left: revenue/entitlement
// data wasn't represented anywhere on that dashboard. No BH_Event
// exists for a purchase/entitlement grant today (only
// bhm/wallet_credit and bhm/wallet_debit do — see class-wallet.php),
// so this reads bhm_entitlements directly rather than inventing event
// data that doesn't exist yet.
add_filter('bhcore_metrics_widgets', function ($widgets) {
    if (!class_exists('OUS_Metrics')) return $widgets;

    $widgets[] = ['source' => 'BH Monetization', 'render' => function () {
        global $wpdb;
        $active_tiers = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}bhm_entitlements
             WHERE type IN ('subscription','streaming_tier') AND (expires_at IS NULL OR expires_at > NOW())"
        );
        OUS_Metrics::render_card('Active supporters', $active_tiers, 'Current subscription/tier entitlements');
    }];
    $widgets[] = ['source' => 'BH Monetization', 'render' => function () {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT DATE(created_at) as d, COUNT(*) as c FROM {$wpdb->prefix}bhm_entitlements
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY d", ARRAY_A
        );
        $by_day = [];
        foreach ($rows as $r) $by_day[$r['d']] = (int) $r['c'];
        $trend = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', time() - $i * DAY_IN_SECONDS);
            $trend[$day] = $by_day[$day] ?? 0;
        }
        OUS_Metrics::render_card('New entitlements', array_sum($trend), 'Purchases + tier grants, last 30 days', $trend);
    }];
    return $widgets;
});
