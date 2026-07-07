<?php
if (!defined('ABSPATH')) exit;

/**
 * This plugin's contribution to BHI_Portal (own-ur-shit's `bhi_portal_panels`
 * filter — see class-portal.php over there) — one of the four real panels
 * this handoff scoped (subscription/wallet, course progress, contest
 * submissions, notifications), matching AJ's own framing: a fan should be
 * able to "manage their sub tiers" from their own account area, not
 * wp-admin. Read-only for now (view balance/ledger/active tier); actual
 * tier changes still route through WooCommerce's own checkout/My Account
 * subscription management — this panel is the "own the interface" layer
 * on top of that, not a reimplementation of WooCommerce's billing UI.
 */
class BHM_PortalPanel {
    public static function init() {
        add_filter('bhi_portal_panels', [self::class, 'register_panel']);
    }

    public static function register_panel($panels) {
        $panels[] = [
            'id' => 'membership',
            'label' => 'Membership & Wallet',
            'icon' => 'dashicons-cart',
            'render' => [self::class, 'render'],
            'priority' => 20,
        ];
        return $panels;
    }

    private static function active_entitlements($user_id) {
        global $wpdb;
        $t = $wpdb->prefix . 'bhm_entitlements';
        $now = current_time('mysql');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE user_id = %d AND (expires_at IS NULL OR expires_at > %s) ORDER BY tier_id ASC",
            $user_id, $now
        ), ARRAY_A);
    }

    public static function render() {
        $user_id = get_current_user_id();

        echo '<h1>Membership &amp; Wallet</h1>';

        echo '<h2>Active tiers</h2>';
        $entitlements = self::active_entitlements($user_id);
        if (!$entitlements) {
            echo '<p>No active supporter tier right now.</p>';
        } else {
            echo '<ul>';
            foreach ($entitlements as $ent) {
                $tier = class_exists('BHM_Tiers') ? BHM_Tiers::get($ent['tier_id']) : null;
                $label = $tier ? $tier['name'] : ('Tier #' . $ent['tier_id']);
                $expiry = $ent['expires_at'] ? ('renews/expires ' . esc_html($ent['expires_at'])) : 'ongoing';
                echo '<li><strong>' . esc_html($label) . '</strong> — ' . esc_html($expiry) . '</li>';
            }
            echo '</ul>';
        }
        if (class_exists('BHM_Tiers')) {
            echo '<p><a class="button" href="' . esc_url(BHM_Tiers::tiers_page_url()) . '">Change tier</a></p>';
        }

        if (class_exists('BHM_Wallet')) {
            $balance = BHM_Wallet::balance_cents($user_id);
            echo '<h2>Wallet</h2>';
            echo '<p>Balance: <strong>$' . esc_html(number_format($balance / 100, 2)) . '</strong></p>';

            $ledger = BHM_Wallet::ledger_for($user_id, 20);
            if ($ledger) {
                echo '<table class="bhi-portal-table"><thead><tr><th>When</th><th>Amount</th><th>Reason</th></tr></thead><tbody>';
                foreach ($ledger as $row) {
                    $amount = ((int) $row->delta_cents) / 100;
                    $sign = $amount >= 0 ? '+' : '';
                    echo '<tr><td>' . esc_html($row->created_at) . '</td><td>' . esc_html($sign . number_format($amount, 2)) . '</td><td>' . esc_html($row->reason) . '</td></tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p>No wallet activity yet.</p>';
            }
        }
    }
}
