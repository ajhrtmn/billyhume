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
        echo '<div class="bhy-table-wrap"><table class="wp-list-table widefat striped"><thead><tr><th>Type</th><th>What</th><th>Granted</th><th>Expires</th></tr></thead><tbody>';
        foreach ($entitlements as $e) {
            $label = $e->scope === 'account' ? (BHM_Tiers::get($e->object_id)['name'] ?? 'Tier #' . $e->object_id) : ($e->scope . ' #' . $e->object_id);
            echo '<tr><td>' . esc_html($e->type) . '</td><td>' . esc_html($label) . '</td><td>' . esc_html($e->created_at) . '</td><td>' . esc_html($e->expires_at ?: 'Never') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
