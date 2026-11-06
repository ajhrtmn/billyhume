<?php
if (!defined('ABSPATH')) exit;

/**
 * Tamper-evident proof of purchase for track/release buys (tiers and top-ups are out of scope).
 * WHY: never an access-control input. BHM_Gate must not consult it, or a refunded
 * "proof of purchase" would bypass the refund revocation.
 */
class BHM_PurchaseLedger {
    public static function init(): void {
        // Observes the same order hooks as BHM_Entitlements, independently.
        add_action('woocommerce_order_status_completed', [self::class, 'on_order_completed']);
        add_action('woocommerce_order_status_refunded', [self::class, 'on_order_reversed']);
        add_action('woocommerce_order_status_cancelled', [self::class, 'on_order_reversed']);
        add_shortcode('bhm_verify_purchase', [self::class, 'render_verify_shortcode']);
        add_action('template_redirect', [self::class, 'maybe_serve_proof_file']);
    }

    // Raw .ots proof, verifiable with any standard `ots verify` tool independent of this site.
    public static function maybe_serve_proof_file(): void {
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

    // WHY: try/catch because WooCommerce wraps all callbacks of a hook in one try/catch;
    // an exception here would abort later callbacks (referrals etc.).
    public static function on_order_completed(int $order_id): void {
        try {
            self::do_on_order_completed($order_id);
        } catch (\Throwable $e) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('error', 'Purchase-ledger write failed for order #' . $order_id . ': ' . $e->getMessage(), ['order_id' => $order_id], 'BH Monetization');
            }
        }
    }

    private static function do_on_order_completed(int $order_id): void {
        $order = BH_Commerce::get_order($order_id);
        if (!$order || !$order['customer_id']) return;

        foreach ($order['items'] as $item) {
            $product_id = $item['product_id'];
            $object_id = (int) get_post_meta($product_id, '_bhm_purchase_object_id', true);
            if (!$object_id) continue; // tier/top-up line item: out of scope

            $object_type = get_post_meta($product_id, '_bhm_purchase_object_type', true);
            $track_id = $object_type === 'bhs_release' ? self::first_track_of_release($object_id) : $object_id;
            $content_hash = $track_id ? (string) get_post_meta($track_id, '_bhs_audio_hash', true) : '';

            // Order total, not line price: BH_Commerce::get_order() items carry no price. Not accounting-grade.
            self::write_row('purchase', $order_id, (int) $order['customer_id'], $track_id, $content_hash, (int) $order['total_cents'], null);
        }
    }

    // Same try/catch reasoning as on_order_completed().
    public static function on_order_reversed(int $order_id): void {
        try {
            self::do_on_order_reversed($order_id);
        } catch (\Throwable $e) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('error', 'Purchase-ledger reversal write failed for order #' . $order_id . ': ' . $e->getMessage(), ['order_id' => $order_id], 'BH Monetization');
            }
        }
    }

    private static function do_on_order_reversed(int $order_id): void {
        global $wpdb;
        $t = BHM_Tables::purchase_ledger();
        // Only not-yet-reversed rows, so refunded->cancelled can't record a second reversal.
        $purchases = $wpdb->get_results($wpdb->prepare(
            "SELECT p.* FROM $t p WHERE p.wc_order_id = %d AND p.event_type = 'purchase'
             AND NOT EXISTS (SELECT 1 FROM $t r WHERE r.linked_record_id = p.id AND r.event_type = 'reversal')",
            $order_id
        ));
        foreach ($purchases as $p) {
            self::write_row('reversal', $order_id, (int) $p->user_id, (int) $p->track_id, (string) $p->content_hash, (int) $p->price_cents, (int) $p->id);
        }
    }

    private static function write_row(string $event_type, int $order_id, int $user_id, int $track_id, string $content_hash, int $price_cents, ?int $linked_record_id): int {
        global $wpdb;
        $record_hash = hash('sha256', wp_json_encode([
            'event_type' => $event_type, 'order_id' => (int) $order_id, 'user_id' => (int) $user_id,
            'track_id' => (int) $track_id, 'content_hash' => (string) $content_hash,
            'price_cents' => (int) $price_cents, 'linked_record_id' => $linked_record_id,
            'ts' => current_time('mysql', true),
        ]));

        $wpdb->insert(BHM_Tables::purchase_ledger(), [
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

    private static function first_track_of_release(int $release_id): int {
        $tracks = get_posts(['post_type' => 'bhs_track', 'post_status' => 'publish', 'posts_per_page' => 1, 'meta_key' => '_bhs_release_id', 'meta_value' => (string) $release_id, 'fields' => 'ids']);
        return $tracks ? (int) $tracks[0] : 0;
    }

    /* ---------------- read helpers (used by the portal panel and bh-crm integration) ---------------- */

    /** @return array<int, object> */
    public static function for_user(int $user_id, int $limit = 50): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . BHM_Tables::purchase_ledger() . " WHERE user_id = %d AND event_type = 'purchase' ORDER BY created_at DESC LIMIT %d",
            $user_id, $limit
        ));
    }

    public static function reversal_for(int $purchase_row_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . BHM_Tables::purchase_ledger() . " WHERE linked_record_id = %d AND event_type = 'reversal'", $purchase_row_id
        ));
    }

    /**
     * Is the ledger usable? False when this plugin's tables were never
     * created — peers must check before reading, since bh-monetization-woo
     * is optional to all of them.
     */
    public static function is_available(): bool {
        global $wpdb;
        $table = BHM_Tables::purchase_ledger();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * Confirmed purchases: 'purchase' rows with no matching 'reversal'. Peers use this, not their own SQL.
     *
     * @return array<int, object>
     */
    public static function confirmed_purchases(): array {
        global $wpdb;
        $table = BHM_Tables::purchase_ledger();
        return $wpdb->get_results(
            "SELECT p.* FROM $table p WHERE p.event_type = 'purchase'
             AND NOT EXISTS (SELECT 1 FROM $table r WHERE r.linked_record_id = p.id AND r.event_type = 'reversal')
             ORDER BY p.created_at ASC"
        );
    }

    public static function get(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . BHM_Tables::purchase_ledger() . " WHERE id = %d", (int) $id));
    }

    /* ---------------- buyer-facing verify page ---------------- */

    // [bhm_verify_purchase id="123"]: id is a ledger row id, not a WooCommerce order id.
    /** @param mixed $atts */
    public static function render_verify_shortcode($atts): string {
        $atts = shortcode_atts(['id' => 0], $atts);
        $row = self::get((int) $atts['id']);
        if (!$row || $row->event_type !== 'purchase') {
            return '<p>Purchase record not found.</p>';
        }
        // Buyer or bhcore_view_crm_sensitive only; row ids are guessable.
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

    public static function status_label(string $status): string {
        return ['pending' => 'Pending', 'submitted' => 'Submitted, awaiting confirmation', 'confirmed' => 'Confirmed on-chain'][$status] ?? ucfirst($status);
    }
}
