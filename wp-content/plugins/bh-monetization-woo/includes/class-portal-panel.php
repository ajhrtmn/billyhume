<?php
if (!defined('ABSPATH')) exit;

/**
 * This plugin's contribution to BHI_Portal (own-ur-shit's `bhi_portal_panels`
 * filter — see class-portal.php over there) — one of the four real panels
 * this handoff scoped (subscription/wallet, course progress, contest
 * submissions, notifications): a fan should be able to manage their
 * supporter tier from their own account area, not wp-admin. Read-only
 * for now (view balance/ledger/active tier); actual
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

    // Real bug (a fatal-adjacent DB error in the debug log): this
    // queried an `ORDER BY tier_id` column that doesn't exist
    // on `bhm_entitlements` (the real column, per class-activator.php's
    // own CREATE TABLE, is `object_id` — a tier entitlement's object_id
    // IS the tier post ID, just not named `tier_id`). Also widened to
    // only ever select tier-granting entitlement types: this table also
    // holds one-time track/release PURCHASE entitlements
    // (grant_entitlement()'s 'purchase' type), which have nothing to do
    // with "active tiers" and would have shown up here as bogus
    // "Tier #123" rows once the column-name bug above was fixed.
    private static function active_entitlements($user_id) {
        global $wpdb;
        $t = $wpdb->prefix . 'bhm_entitlements';
        $now = current_time('mysql');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE user_id = %d AND type IN ('subscription','streaming_tier') AND (expires_at IS NULL OR expires_at > %s) ORDER BY object_id ASC",
            $user_id, $now
        ), ARRAY_A);
    }

    public static function render() {
        $user_id = get_current_user_id();

        echo '<h1>Membership &amp; Wallet</h1>';

        echo '<div class="bhi-portal-section">';
        echo '<h2>Active tiers</h2>';
        $entitlements = self::active_entitlements($user_id);
        if (!$entitlements) {
            echo '<div class="bhi-portal-empty">'
               . '<span class="dashicons dashicons-star-filled"></span>'
               . '<p>No active supporter tier right now.</p>';
            if (class_exists('BHM_Tiers')) echo '<a class="button" href="' . esc_url(BHM_Tiers::tiers_page_url()) . '">See supporter tiers &rarr;</a>';
            echo '</div>';
        } else {
            // Chip-per-tier, matching the Overview tab's own tier badge
            // instead of a plain <li> list — this was the only panel
            // where "which tier am I on" was buried in bulleted text.
            echo '<div class="bhi-tier-chip-row">';
            foreach ($entitlements as $ent) {
                $tier = class_exists('BHM_Tiers') ? BHM_Tiers::get($ent['object_id']) : null;
                $label = $tier ? $tier['name'] : ('Tier #' . $ent['object_id']);
                $expiry = $ent['expires_at'] ? ('renews/expires ' . esc_html(mysql2date('M j, Y', $ent['expires_at']))) : 'ongoing';
                echo '<div class="bhi-tier-chip"><span class="bhi-overview-tier-badge">' . esc_html($label) . '</span><span class="bhi-overview-dim">' . esc_html($expiry) . '</span></div>';
            }
            echo '</div>';
            if (class_exists('BHM_Tiers')) {
                echo '<p><a class="button" href="' . esc_url(BHM_Tiers::tiers_page_url()) . '">Change tier</a></p>';
            }
        }
        echo '</div>';

        if (class_exists('BHM_Wallet')) {
            $balance = BHM_Wallet::balance_cents($user_id);
            echo '<div class="bhi-portal-section">';
            echo '<h2>Wallet</h2>';
            echo '<p class="bhi-wallet-balance"><span class="bhi-wallet-balance-amount">$' . esc_html(number_format($balance / 100, 2)) . '</span> <span class="bhi-overview-dim">balance</span></p>';

            $ledger = BHM_Wallet::ledger_for($user_id, 20);
            if ($ledger) {
                echo '<table class="bhi-portal-table"><thead><tr><th>When</th><th>Amount</th><th>Reason</th></tr></thead><tbody>';
                foreach ($ledger as $row) {
                    $amount = ((int) $row->delta_cents) / 100;
                    $sign = $amount >= 0 ? '+' : '';
                    $amount_class = $amount >= 0 ? 'bhi-ledger-credit' : 'bhi-ledger-debit';
                    echo '<tr><td>' . esc_html(mysql2date('M j, Y', $row->created_at)) . '</td><td class="' . $amount_class . '">' . esc_html($sign . number_format($amount, 2)) . '</td><td>' . esc_html($row->reason) . '</td></tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<div class="bhi-portal-empty"><span class="dashicons dashicons-money-alt"></span><p>No wallet activity yet.</p></div>';
            }
            echo '</div>';
        }
    }
}
