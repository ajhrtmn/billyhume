<?php
if (!defined('ABSPATH')) exit;

/**
 * Ledger-anchored proof of purchase — ROADMAP-streaming-media-scope-
 * and-blockchain.md Part 2. Scope, deliberately narrow: this is
 * TAMPER-EVIDENT EVIDENCE that a purchase transaction occurred, for a
 * buyer's own records or a real dispute, and a durable per-artist
 * sales log (surfaced in bh-crm, see class-crm-integration.php's own
 * "activity_summary" convention). It is NOT an access-control
 * mechanism.
 *
 * THE ONE RULE THIS CLASS MUST NEVER BREAK: nothing here is ever
 * consulted by BHM_Gate/BHM_PlayGating to decide whether someone can
 * stream or download a track, and a reversed purchase's original row
 * is never treated as if it still grants anything. BHM_Entitlements::
 * on_order_reversed() already revokes access on refund/chargeback
 * specifically to stop "buy -> extract -> refund -> repeat" abuse
 * (see that class's own docblock, and class-fraud.php's
 * track_refund_pattern()) — an anchored "proof of purchase" that
 * overrode that would hand fraud a documented bypass. This class only
 * ever WRITES rows and reads its own table for display; it has no
 * hook into the gate/entitlement decision path at all.
 *
 * Only handles 'purchase'-type line items (a real track/release buy,
 * identified the same way BHM_Entitlements::on_order_completed() does
 * via _bhm_purchase_object_id/_bhm_purchase_object_type) — tier
 * subscriptions and wallet top-ups aren't "proof of purchase" for a
 * specific piece of media the way a track/release buy is, and are out
 * of scope here.
 */
class BHM_PurchaseLedger {
    public static function init() {
        // Registered as SEPARATE callbacks on the same hooks
        // BHM_Entitlements already uses — this class never modifies or
        // calls into class-entitlements.php, it just observes the same
        // order lifecycle independently, so a bug here can't ever affect
        // whether access is actually granted/revoked.
        add_action('woocommerce_order_status_completed', [self::class, 'on_order_completed']);
        add_action('woocommerce_order_status_refunded', [self::class, 'on_order_reversed']);
        add_action('woocommerce_order_status_cancelled', [self::class, 'on_order_reversed']);
        add_shortcode('bhm_verify_purchase', [self::class, 'render_verify_shortcode']);
        add_action('template_redirect', [self::class, 'maybe_serve_proof_file']);
    }

    // Serves the raw OpenTimestamps proof bytes so a buyer can run it
    // through any standard `ots verify` tool independent of this site —
    // the whole point of anchoring is that verification doesn't have to
    // go through bh-monetization-woo's own code.
    public static function maybe_serve_proof_file() {
        if (!isset($_GET['bhm_download_proof'])) return;
        $row = self::get((int) $_GET['bhm_download_proof']);
        if (!$row || $row->anchor_status !== 'confirmed' || !$row->anchor_proof) {
            wp_die('Proof not available.', '', ['response' => 404]);
        }
        if ((int) $row->user_id !== get_current_user_id() && !current_user_can('bhcore_view_crm_sensitive')) {
            wp_die('Not allowed.', '', ['response' => 403]);
        }
        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="purchase-' . (int) $row->id . '.ots"');
        echo base64_decode($row->anchor_proof);
        exit;
    }

    public static function on_order_completed($order_id) {
        $order = BH_Commerce::get_order($order_id);
        if (!$order || !$order['customer_id']) return;

        foreach ($order['items'] as $item) {
            $product_id = $item['product_id'];
            $object_id = (int) get_post_meta($product_id, '_bhm_purchase_object_id', true);
            if (!$object_id) continue; // not a track/release purchase line item — tier/wallet-topup, out of scope for this ledger

            $object_type = get_post_meta($product_id, '_bhm_purchase_object_type', true);
            $track_id = $object_type === 'bhs_release' ? self::first_track_of_release($object_id) : $object_id;
            $content_hash = $track_id ? (string) get_post_meta($track_id, '_bhs_audio_hash', true) : '';

            // Order-level total, not a per-line price — BH_Commerce::
            // get_order()'s normalized item shape doesn't expose a
            // per-line price today (see own-ur-shit/includes/
            // class-commerce.php's get_order()), and the common case
            // here is one purchase line item per order anyway. Accurate
            // enough for a proof-of-purchase/metrics record; not a
            // precise accounting ledger.
            self::write_row('purchase', $order_id, (int) $order['customer_id'], $track_id, $content_hash, (int) $order['total_cents'], null);
        }
    }

    public static function on_order_reversed($order_id) {
        global $wpdb;
        $t = $wpdb->prefix . 'bhm_purchase_ledger';
        // Every still-un-reversed purchase row for this order gets a
        // linked reversal row — "still un-reversed" so a second
        // refunded->cancelled (or vice versa) status transition on the
        // same order can't double-record the same reversal.
        $purchases = $wpdb->get_results($wpdb->prepare(
            "SELECT p.* FROM $t p WHERE p.wc_order_id = %d AND p.event_type = 'purchase'
             AND NOT EXISTS (SELECT 1 FROM $t r WHERE r.linked_record_id = p.id AND r.event_type = 'reversal')",
            $order_id
        ));
        foreach ($purchases as $p) {
            self::write_row('reversal', $order_id, (int) $p->user_id, (int) $p->track_id, (string) $p->content_hash, (int) $p->price_cents, (int) $p->id);
        }
    }

    private static function write_row($event_type, $order_id, $user_id, $track_id, $content_hash, $price_cents, $linked_record_id) {
        global $wpdb;
        $record_hash = hash('sha256', wp_json_encode([
            'event_type' => $event_type, 'order_id' => (int) $order_id, 'user_id' => (int) $user_id,
            'track_id' => (int) $track_id, 'content_hash' => (string) $content_hash,
            'price_cents' => (int) $price_cents, 'linked_record_id' => $linked_record_id,
            'ts' => current_time('mysql', true),
        ]));

        $wpdb->insert($wpdb->prefix . 'bhm_purchase_ledger', [
            'event_type' => $event_type,
            'wc_order_id' => (int) $order_id,
            'user_id' => (int) $user_id,
            'track_id' => $track_id ?: null,
            'content_hash' => $content_hash ?: null,
            'price_cents' => (int) $price_cents,
            'record_hash' => $record_hash,
            'anchor_status' => 'pending',
            'linked_record_id' => $linked_record_id,
            'created_at' => current_time('mysql'),
        ]);
        $row_id = (int) $wpdb->insert_id;
        if ($row_id && class_exists('BHM_Anchoring')) {
            BHM_Anchoring::anchor_async($row_id);
        }
        return $row_id;
    }

    private static function first_track_of_release($release_id) {
        $tracks = get_posts(['post_type' => 'bhs_track', 'post_status' => 'publish', 'posts_per_page' => 1, 'meta_key' => '_bhs_release_id', 'meta_value' => $release_id, 'fields' => 'ids']);
        return $tracks ? (int) $tracks[0] : 0;
    }

    /* ---------------- read helpers (used by the portal panel and bh-crm integration) ---------------- */

    public static function for_user($user_id, $limit = 50) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bhm_purchase_ledger WHERE user_id = %d AND event_type = 'purchase' ORDER BY created_at DESC LIMIT %d",
            $user_id, $limit
        ));
    }

    public static function reversal_for($purchase_row_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bhm_purchase_ledger WHERE linked_record_id = %d AND event_type = 'reversal'", $purchase_row_id
        ));
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bhm_purchase_ledger WHERE id = %d", (int) $id));
    }

    /* ---------------- buyer-facing verify page ---------------- */

    // [bhm_verify_purchase id="123"] — 'id' is a bhm_purchase_ledger row
    // id (linked from the portal panel's own Purchases table, see
    // class-portal-panel.php), not the WooCommerce order id, so this
    // page never needs to re-derive which purchase within a
    // multi-item order is being verified.
    public static function render_verify_shortcode($atts) {
        $atts = shortcode_atts(['id' => 0], $atts);
        $row = self::get((int) $atts['id']);
        if (!$row || $row->event_type !== 'purchase') {
            return '<p>Purchase record not found.</p>';
        }
        // Only the buyer themselves or an admin who can see financial
        // CRM data may view this — a purchase record, even though it's
        // meant to be independently verifiable via the public anchor,
        // still shouldn't be readable by an arbitrary logged-in visitor
        // who guesses a row id.
        if ((int) $row->user_id !== get_current_user_id() && !current_user_can('bhcore_view_crm_sensitive')) {
            return '<p>Not allowed.</p>';
        }

        $reversal = self::reversal_for($row->id);
        $track_title = $row->track_id ? (get_the_title($row->track_id) ?: ('#' . $row->track_id)) : 'Unknown track';

        ob_start();
        echo '<div class="bhm-verify-purchase">';
        echo '<h3>Purchase record</h3>';
        echo '<table class="bhi-portal-table"><tbody>';
        echo '<tr><th>Track</th><td>' . esc_html($track_title) . '</td></tr>';
        echo '<tr><th>Price</th><td>$' . esc_html(BHM_Money::display($row->price_cents)) . '</td></tr>';
        echo '<tr><th>Purchased</th><td>' . esc_html(mysql2date('M j, Y g:i a', $row->created_at)) . '</td></tr>';
        echo '<tr><th>Record hash (SHA-256)</th><td><code style="font-size:11px;word-break:break-all;">' . esc_html($row->record_hash) . '</code></td></tr>';
        echo '<tr><th>Anchoring status</th><td>' . esc_html(self::status_label($row->anchor_status)) . '</td></tr>';
        echo '</tbody></table>';

        if ($row->anchor_status === 'confirmed' && $row->anchor_proof) {
            echo '<p class="description">This purchase\'s record hash has been anchored to the Bitcoin blockchain via OpenTimestamps — independent of this site, its database, or WooCommerce. Anyone (a bank, a court, another platform) can verify it happened at this time without needing to trust this site at all. <a href="https://opentimestamps.org" target="_blank" rel="noopener">Learn how to verify an OpenTimestamps proof &rarr;</a></p>';
            echo '<p><a class="button" href="' . esc_url(add_query_arg(['bhm_download_proof' => $row->id], home_url('/'))) . '">Download proof file (.ots)</a></p>';
        } elseif ($row->anchor_status === 'submitted') {
            echo '<p class="description">Submitted for anchoring — waiting on Bitcoin block confirmation (this can take a while; the submission itself is already timestamped).</p>';
        } else {
            echo '<p class="description">Anchoring pending.</p>';
        }

        if ($reversal) {
            echo '<div class="notice notice-warning" style="padding:10px;margin-top:12px;"><p><strong>This purchase was later reversed</strong> (refunded or cancelled) on ' . esc_html(mysql2date('M j, Y', $reversal->created_at)) . '. Access tied to this purchase was revoked at that time. This record is shown, not hidden, because an honest purchase history includes reversals.</p></div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    public static function status_label($status) {
        return ['pending' => 'Pending', 'submitted' => 'Submitted, awaiting confirmation', 'confirmed' => 'Confirmed on-chain'][$status] ?? ucfirst($status);
    }
}
