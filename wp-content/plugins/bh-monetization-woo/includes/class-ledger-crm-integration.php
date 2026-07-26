<?php
if (!defined('ABSPATH')) exit;

/**
 * bh-crm activity surfacing for the purchase ledger — a separate,
 * independent bh_crm_activity_summary section from BHM_CRMIntegration's
 * own wallet/tier/refund-flag summary (see that class's own docblock:
 * "a separate, independent ... section, never something bolted onto
 * this one"). Same one-directional, optional pattern: if bh-crm isn't
 * installed, these add_filter() calls just sit unused.
 *
 * This is the artist-facing "metrics log" half of
 * ROADMAP-streaming-media-scope-and-blockchain.md Part 2 — a durable,
 * tamper-evident sales record per person, distinct from (and a useful
 * cross-check against) whatever WooCommerce's own admin reports show.
 */
class BHM_LedgerCRMIntegration {
    public static function init() {
        add_filter('bh_crm_activity_summary', [self::class, 'activity_summary'], 10, 2);
    }

    public static function activity_summary($sections, $user_id) {
        // Same sensitivity class as BHM_CRMIntegration's own wallet/
        // purchase data — this is financial/proof-of-purchase data.
        if (!current_user_can('bhcore_view_crm_sensitive')) return $sections;
        if (!class_exists('BHM_PurchaseLedger')) return $sections;

        $purchases = BHM_PurchaseLedger::for_user($user_id, 100);
        if (!$purchases) return $sections;

        $confirmed = array_filter($purchases, fn($p) => $p->anchor_status === 'confirmed');
        $reversed_count = 0;
        $total_cents = 0;
        foreach ($purchases as $p) {
            if (BHM_PurchaseLedger::reversal_for($p->id)) { $reversed_count++; continue; }
            $total_cents += (int) $p->price_cents;
        }

        $summary = count($purchases) . ' anchored purchase' . (count($purchases) === 1 ? '' : 's')
            . ' ($' . BHM_Money::display($total_cents) . ' net), '
            . count($confirmed) . ' confirmed on-chain'
            . ($reversed_count ? ', ' . $reversed_count . ' reversed' : '');

        $sections[] = [
            'plugin'  => 'BH Monetization — Purchase Ledger',
            'summary' => $summary,
            'render'  => fn() => self::render_detail($purchases),
        ];
        return $sections;
    }

    private static function render_detail($purchases) {
        echo '<p class="description">Tamper-evident proof-of-purchase records, anchored to a public ledger (OpenTimestamps/Bitcoin) — evidence of what was purchased and when, never a mechanism that restores or protects access after a legitimate refund/chargeback revocation.</p>';
        echo '<div class="bhy-table-wrap"><table class="wp-list-table widefat striped"><thead><tr><th>Track</th><th>Price</th><th>Purchased</th><th>Anchor status</th><th>Reversed</th></tr></thead><tbody>';
        foreach ($purchases as $p) {
            $reversal = BHM_PurchaseLedger::reversal_for($p->id);
            $title = $p->track_id ? (get_the_title($p->track_id) ?: ('#' . $p->track_id)) : '—';
            echo '<tr><td>' . esc_html($title) . '</td><td>$' . esc_html(BHM_Money::display($p->price_cents)) . '</td><td>' . esc_html(mysql2date('M j, Y', $p->created_at)) . '</td><td>' . esc_html(BHM_PurchaseLedger::status_label($p->anchor_status)) . '</td>';
            echo '<td>' . ($reversal ? '⚠ ' . esc_html(mysql2date('M j, Y', $reversal->created_at)) : '—') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
