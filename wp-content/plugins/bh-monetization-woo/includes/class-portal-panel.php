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
            'label' => BHM_Words::get('portal.tab'),
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

        echo '<h1>' . BHM_Words::e('portal.heading') . '</h1>';

        // Only for people who actually hold a tier; sites that don't sell tiers never see this.
        $entitlements = self::active_entitlements($user_id);
        if ($entitlements) {
            echo '<div class="bhi-portal-section">';
            echo '<h2>' . BHM_Words::e('portal.active_tiers') . '</h2>';
            echo '<div class="bhi-tier-chip-row">';
            foreach ($entitlements as $ent) {
                $tier = class_exists('BHM_Tiers') ? BHM_Tiers::get($ent['object_id']) : null;
                $label = $tier ? $tier['name'] : (BHM_Words::get('admin.crm_integration.tier') . $ent['object_id']);
                $expiry = $ent['expires_at'] ? ('renews/expires ' . esc_html(mysql2date('M j, Y', $ent['expires_at']))) : 'ongoing';
                echo '<div class="bhi-tier-chip"><span class="bhi-overview-tier-badge">' . esc_html($label) . '</span><span class="bhi-overview-dim">' . esc_html($expiry) . '</span></div>';
            }
            echo '</div>';
            if (class_exists('BHM_Tiers')) {
                echo '<p><a class="button" href="' . esc_url(BHM_Tiers::tiers_page_url()) . '">' . BHM_Words::e('portal.change_tier') . '</a></p>';
            }
            echo '</div>';
        }

        if (class_exists('BHM_Wallet')) {
            $balance = BHM_Wallet::balance_cents($user_id);
            echo '<div class="bhi-portal-section">';
            echo '<h2>' . BHM_Words::e('portal.wallet') . '</h2>';
            echo '<p class="bhi-wallet-balance"><span class="bhi-wallet-balance-amount">$' . esc_html(BHM_Money::display($balance)) . '</span> <span class="bhi-overview-dim">balance</span></p>';

            $ledger = BHM_Wallet::ledger_for($user_id, 20);
            if ($ledger) {
                echo ('<table class="bhi-portal-table"><thead><tr><th>' . BHM_Words::get('admin.portal_panel.when') . '</th><th>' . BHM_Words::get('admin.portal_panel.amount') . '</th><th>' . BHM_Words::get('admin.portal_panel.reason') . '</th></tr></thead><tbody>');
                foreach ($ledger as $row) {
                    $amount = ((int) $row->delta_cents) / 100;
                    $sign = $amount >= 0 ? '+' : '';
                    $amount_class = $amount >= 0 ? 'bhi-ledger-credit' : 'bhi-ledger-debit';
                    echo '<tr><td>' . esc_html(mysql2date('M j, Y', $row->created_at)) . '</td><td class="' . $amount_class . '">' . esc_html($sign . number_format($amount, 2)) . '</td><td>' . esc_html($row->reason) . '</td></tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<div class="bhi-portal-empty"><span class="dashicons dashicons-money-alt"></span><p>' . BHM_Words::e('portal.wallet_empty') . '</p></div>';
            }
            echo '</div>';
        }

        if (class_exists('BHM_Referrals')) BHM_Referrals::render_section($user_id);
    }
}
