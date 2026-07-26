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
            echo '<p class="bhi-wallet-balance"><span class="bhi-wallet-balance-amount">$' . esc_html(BHM_Money::display($balance)) . '</span> <span class="bhi-overview-dim">balance</span></p>';

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

        if (class_exists('BHM_PurchaseLedger')) {
            $purchases = BHM_PurchaseLedger::for_user($user_id, 25);
            echo '<div class="bhi-portal-section">';
            echo '<h2>Purchases</h2>';
            if (!$purchases) {
                echo '<div class="bhi-portal-empty"><span class="dashicons dashicons-media-default"></span><p>No track/release purchases yet.</p></div>';
            } else {
                echo '<table class="bhi-portal-table"><thead><tr><th>Track</th><th>Price</th><th>Purchased</th><th>Status</th><th></th></tr></thead><tbody>';
                foreach ($purchases as $p) {
                    $title = $p->track_id ? (get_the_title($p->track_id) ?: ('#' . $p->track_id)) : '—';
                    $reversed = BHM_PurchaseLedger::reversal_for($p->id);
                    echo '<tr><td>' . esc_html($title) . '</td><td>$' . esc_html(BHM_Money::display($p->price_cents)) . '</td><td>' . esc_html(mysql2date('M j, Y', $p->created_at)) . '</td>';
                    echo '<td>' . ($reversed ? 'Refunded' : esc_html(BHM_PurchaseLedger::status_label($p->anchor_status))) . '</td>';
                    echo '<td><a class="button button-small" href="' . esc_url(add_query_arg('bhm_verify', $p->id, get_permalink())) . '">Verify</a></td></tr>';
                }
                echo '</tbody></table>';
                echo '<p class="description">"Verify" shows this purchase\'s tamper-evident proof record — independent of this site\'s own database.</p>';
            }
            // A verify link on this same page renders inline via the
            // shortcode rather than navigating away, so a fan reviewing
            // their purchases can check one without losing their place.
            if (isset($_GET['bhm_verify'])) {
                echo '<div class="bhi-portal-section">' . do_shortcode('[bhm_verify_purchase id="' . (int) $_GET['bhm_verify'] . '"]') . '</div>';
            }
            echo '</div>';
        }

        if (class_exists('BHM_Referrals')) BHM_Referrals::render_section($user_id);
    }
}
