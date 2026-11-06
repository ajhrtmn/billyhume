<?php
if (!defined('ABSPATH')) exit;

/**
 * Read-only "Membership & Wallet" panel in the fan portal (bhi_portal_panels).
 * Tier changes still go through WooCommerce checkout.
 */
class BHM_PortalPanel {
    public static function init(): void {
        add_filter('bhi_portal_panels', [self::class, 'register_panel']);
    }

    /**
     * @param array<int, array<string, mixed>> $panels
     * @return array<int, array<string, mixed>>
     */
    public static function register_panel($panels): array {
        $panels[] = [
            'id' => 'membership',
            'label' => 'Membership & Wallet',
            'icon' => 'dashicons-cart',
            'render' => [self::class, 'render'],
            'priority' => 20,
        ];
        return $panels;
    }

    // Tier entitlements only (object_id is the tier post ID); purchases are excluded.
    /** @return array<int, array<string, mixed>> */
    private static function active_entitlements(int $user_id): array {
        global $wpdb;
        $t = BHM_Tables::entitlements();
        $now = current_time('mysql');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE user_id = %d AND type IN ('subscription','streaming_tier') AND (expires_at IS NULL OR expires_at > %s) ORDER BY object_id ASC",
            $user_id, $now
        ), ARRAY_A);
    }

    public static function render(): void {
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
            // Verify renders inline so the fan keeps their place.
            if (isset($_GET['bhm_verify'])) {
                echo '<div class="bhi-portal-section">' . do_shortcode('[bhm_verify_purchase id="' . (int) $_GET['bhm_verify'] . '"]') . '</div>';
            }
            echo '</div>';
        }

        if (class_exists('BHM_Referrals')) BHM_Referrals::render_section($user_id);
    }
}
