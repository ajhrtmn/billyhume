<?php
if (!defined('ABSPATH')) exit;

/**
 * Adds tier, purchases, wallet and fraud flags to BH CRM's person view.
 * One-directional; the filters sit unused when BH CRM isn't installed.
 */
class BHM_CRMIntegration {
    public static function init(): void {
        add_filter('bh_crm_active_user_ids', [self::class, 'active_user_ids']);
        add_filter('bh_crm_activity_summary', [self::class, 'activity_summary'], 10, 2);
        add_action('admin_post_bhm_revoke_entitlement', [self::class, 'handle_revoke_entitlement']);
    }

    /** Manual admin revoke (e.g. a bank-side chargeback); fires the same bhm_entitlement_revoked as a refund. */
    public static function handle_revoke_entitlement(): void {
        if (!current_user_can('bhcore_view_crm_sensitive') || !check_admin_referer('bhm_revoke_entitlement')) {
            wp_die('Not allowed.', '', ['back_link' => true]);
        }
        $entitlement_id = (int) ($_GET['entitlement_id'] ?? 0);
        $row = $entitlement_id ? BHM_Entitlements::revoke_entitlement_by_id($entitlement_id, 'manual_admin_revoke') : null;
        if ($row && class_exists('OUS_Toast')) OUS_Toast::queue(BHM_Words::get('admin.crm_integration.access_revoked_fan_has_been'), 'success');

        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, int>
     */
    public static function active_user_ids($ids): array {
        global $wpdb;
        $entitled = $wpdb->get_col("SELECT DISTINCT user_id FROM " . BHM_Tables::entitlements());
        $wallets = $wpdb->get_col("SELECT DISTINCT user_id FROM " . BHM_Tables::wallet() . " WHERE balance_cents > 0");
        return array_merge($ids, $entitled, $wallets);
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     * @return array<int, array<string, mixed>>
     */
    public static function activity_summary($sections, int $user_id): array {
        global $wpdb;
        // WHY: financial data, so gated on bhcore_view_crm_sensitive, not bhcore_manage_crm (editors).
        if (!current_user_can('bhcore_view_crm_sensitive')) return $sections;
        $entitlements = $wpdb->get_results($wpdb->prepare(
            // id DESC breaks same-second ties; the loop below picks the active tier by this order.
            "SELECT * FROM " . BHM_Tables::entitlements() . " WHERE user_id = %d ORDER BY created_at DESC, id DESC", $user_id
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
        $refund_count = class_exists('BHM_Fraud') ? BHM_Fraud::refund_count_recent($user_id) : 0;
        $flagged = get_user_meta($user_id, '_bhm_refund_flagged', true) === '1';
        $shared_device_flagged = get_user_meta($user_id, '_bhm_refund_shared_device_flagged', true) === '1';
        $velocity_flagged = get_user_meta($user_id, '_bhm_velocity_flagged', true) === '1';
        // Top-up velocity (money in), distinct from play-rate velocity above.
        $topup_flagged = class_exists('BHM_Fraud') && get_user_meta($user_id, '_bhm_topup_velocity_flagged', true) === '1';
        $topup_recent_cents = class_exists('BHM_Fraud') ? BHM_Fraud::topup_total_recent_cents($user_id) : 0;

        $summary = ($active_tier ? $active_tier . ' supporter' : BHM_Words::get('admin.crm_integration.no_active_tier')) . ', ' . $purchase_count . ' purchase' . ($purchase_count === 1 ? '' : 's')
            . ', $' . BHM_Money::display($balance) . ' play credit'
            . ($flagged ? ' — ⚠ ' . $refund_count . ' refunds in 30 days' : '')
            . ($shared_device_flagged ? ' ' . BHM_Words::get('admin.crm_integration.shared_device_with_another_refunding') : '')
            . ($velocity_flagged ? ' ' . BHM_Words::get('admin.crm_integration.unusual_play_rate_burst_detected') : '')
            . ($topup_flagged ? ' ' . BHM_Words::get('admin.crm_integration.unusual_wallet_top_up_volume') : '');

        $sections[] = [
            'plugin'  => BHM_Words::get('admin.crm_integration.bh_monetization'),
            'summary' => $summary,
            'render'  => fn() => self::render_detail($entitlements, $balance, $refund_count, $flagged, $shared_device_flagged, $velocity_flagged, $topup_flagged, $topup_recent_cents),
        ];
        return $sections;
    }

    /** @param array<int, object> $entitlements */
    private static function render_detail($entitlements, int $balance, int $refund_count = 0, bool $flagged = false, bool $shared_device_flagged = false, bool $velocity_flagged = false, bool $topup_flagged = false, int $topup_recent_cents = 0): void {
        echo ('<p><strong>' . BHM_Words::get('admin.crm_integration.wallet_balance') . '</strong> $') . esc_html(BHM_Money::display($balance)) . '</p>';
        if ($refund_count > 0) {
            echo '<p' . ($flagged ? ' style="color:#b32d2e;font-weight:600;"' : '') . '>'
               . ($flagged ? '⚠ ' : '') . esc_html((string) $refund_count) . ' refund' . ($refund_count === 1 ? '' : 's') . ' in the last 30 days'
               . ($flagged ? ' ' . BHM_Words::get('admin.crm_integration.repeated_refund_pattern_worth_human') : '') . '</p>';
        }
        if ($topup_flagged) {
            echo '<p style="color:#b32d2e;font-weight:600;">⚠ $' . esc_html(BHM_Money::display($topup_recent_cents)) . ' in wallet top-ups in the last 24 hours — an unusually fast burst (default cap $500/24h). Could be normal heavy use or a compromised payment method being tested. Worth a look.</p>';
        }
        if ($shared_device_flagged) {
            echo ('<p style="color:#b32d2e;font-weight:600;">' . BHM_Words::get('admin.crm_integration.account_s_device_connection_matches') . '</p>');
        }
        if ($velocity_flagged) {
            echo ('<p style="color:#b32d2e;font-weight:600;">' . BHM_Words::get('admin.crm_integration.unusually_fast_burst_paid_plays') . '</p>');
        }
        if (!$entitlements) return;
        echo ('<div class="bhy-table-wrap"><table class="wp-list-table widefat striped"><thead><tr><th>' . BHM_Words::get('admin.crm_integration.type') . '</th><th>' . BHM_Words::get('admin.crm_integration.what') . '</th><th>' . BHM_Words::get('admin.crm_integration.granted') . '</th><th>' . BHM_Words::get('admin.crm_integration.expires') . '</th><th></th></tr></thead><tbody>');
        foreach ($entitlements as $e) {
            $label = $e->scope === 'account' ? (BHM_Tiers::get($e->object_id)['name'] ?? BHM_Words::get('admin.crm_integration.tier') . $e->object_id) : ($e->scope . ' #' . $e->object_id);
            $revoke_url = wp_nonce_url(admin_url('admin-post.php?action=bhm_revoke_entitlement&entitlement_id=' . (int) $e->id), 'bhm_revoke_entitlement');
            echo '<tr><td>' . esc_html($e->type) . '</td><td>' . esc_html($label) . '</td><td>' . esc_html($e->created_at) . '</td><td>' . esc_html($e->expires_at ?: 'Never') . '</td>';
            // Two-click arm/confirm instead of a native confirm() dialog.
            echo '<td><button type="button" class="button button-small bhm-revoke-btn" data-url="' . esc_url($revoke_url) . ('" style="color:#b32d2e;">' . BHM_Words::get('admin.crm_integration.revoke') . '</button></td>');
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        self::maybe_print_revoke_script();
    }

    private static function maybe_print_revoke_script(): void {
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

// Metrics dashboard cards, read from bhm_entitlements (grants emit no BH_Event).
add_filter('bhcore_metrics_widgets', function ($widgets) {
    if (!class_exists('OUS_Metrics')) return $widgets;

    $widgets[] = ['source' => BHM_Words::get('admin.crm_integration.bh_monetization'), 'render' => function () {
        global $wpdb;
        $active_tiers = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM " . BHM_Tables::entitlements() . "
             WHERE type IN ('subscription','streaming_tier') AND (expires_at IS NULL OR expires_at > NOW())"
        );
        OUS_Metrics::render_card(BHM_Words::get('admin.crm_integration.active_supporters'), $active_tiers, BHM_Words::get('admin.crm_integration.current_subscription_tier_entitlements'));
    }];
    $widgets[] = ['source' => BHM_Words::get('admin.crm_integration.bh_monetization'), 'render' => function () {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT DATE(created_at) as d, COUNT(*) as c FROM " . BHM_Tables::entitlements() . "
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY d", ARRAY_A
        );
        $by_day = [];
        foreach ($rows as $r) $by_day[$r['d']] = (int) $r['c'];
        $trend = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', time() - $i * DAY_IN_SECONDS);
            $trend[$day] = $by_day[$day] ?? 0;
        }
        OUS_Metrics::render_card(BHM_Words::get('admin.crm_integration.new_entitlements'), array_sum($trend), BHM_Words::get('admin.crm_integration.purchases_tier_grants_last_30'), $trend);
    }];
    return $widgets;
});
