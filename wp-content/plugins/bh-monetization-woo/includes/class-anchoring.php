<?php
if (!defined('ABSPATH')) exit;

/**
 * Thin wrapper around an OpenTimestamps calendar server — the
 * lightweight, no-wallet anchoring approach chosen in
 * ROADMAP-streaming-media-scope-and-blockchain.md Part 2 over a
 * custom Bitcoin OP_RETURN transaction or any wallet/token-based
 * approach: OpenTimestamps anchors a SHA-256 hash to Bitcoin via its
 * public, free calendar servers, and neither this site nor the artist
 * nor the buyer ever holds or spends any cryptocurrency to use it.
 *
 * Only the hash is ever sent over the wire — never the buyer's name,
 * email, or any other identifying field from bhm_purchase_ledger. A
 * SHA-256 hash of {order_id, user_id, track_id, content_hash,
 * price_cents, event_type, created_at} is meaningless to the calendar
 * server or to anyone inspecting the public ledger without this site's
 * own database to cross-reference it against — exactly the same
 * pseudonymity posture ROADMAP-federated-metrics.md already applies to
 * its own aggregate submissions.
 *
 * Submission is a two-step, eventually-consistent process by design
 * (OpenTimestamps' own protocol, not a choice made here): submit a
 * hash now (fast, gets a "pending" attestation immediately), then
 * "upgrade" later once that hash has actually been included in a mined
 * Bitcoin block (can take anywhere from minutes to several hours).
 * BHM_PurchaseLedger's own row starts life as anchor_status='pending'
 * and this class re-checks it via a delayed OUS_Jobs re-enqueue until
 * it either confirms or the site gives up after MAX_UPGRADE_ATTEMPTS —
 * matching this ecosystem's existing "no inline retry, just come back
 * on the next scheduled run" HTTP-failure convention (see
 * bh-streaming's class-feeds.php sync_all()/handle_manual_sync()).
 */
class BHM_Anchoring {
    const SUBMIT_JOB_HOOK  = 'bhm_anchor_submit';
    const UPGRADE_JOB_HOOK = 'bhm_anchor_upgrade';
    const UPGRADE_RETRY_DELAY = 30 * MINUTE_IN_SECONDS;
    const MAX_UPGRADE_ATTEMPTS = 20; // ~10 hours of retries at the delay above before giving up and leaving the row 'pending' for a manual admin re-check

    // OpenTimestamps' own public calendar servers — the same three the
    // reference `ots` client submits to by default, so a hash anchored
    // here is provable with any standard OpenTimestamps verifier, not
    // just this plugin's own code.
    const CALENDAR_URLS = [
        'https://alice.btc.calendar.opentimestamps.org',
        'https://bob.btc.calendar.opentimestamps.org',
        'https://finney.calendar.eternitywall.com',
    ];

    public static function init(): void {
        if (class_exists('OUS_Jobs')) {
            OUS_Jobs::register(self::SUBMIT_JOB_HOOK, [self::class, 'handle_submit_job']);
            OUS_Jobs::register(self::UPGRADE_JOB_HOOK, [self::class, 'handle_upgrade_job']);
        }
    }

    // Called synchronously by BHM_PurchaseLedger right after it writes
    // a row — but the actual network call happens off-request via
    // OUS_Jobs, same "never block the customer's checkout on a
    // third-party HTTP call" reasoning as every other async path in
    // this ecosystem (BH_Event::emit(), OUS_Notifications' email queue).
    public static function anchor_async(int $ledger_row_id): void {
        if (!class_exists('OUS_Jobs')) return;
        OUS_Jobs::enqueue(self::SUBMIT_JOB_HOOK, ['ledger_row_id' => (int) $ledger_row_id]);
    }

    /** @param array<string, mixed> $args */
    public static function handle_submit_job($args): void {
        $row_id = (int) ($args['ledger_row_id'] ?? 0);
        $row = self::get_row($row_id);
        if (!$row || $row->anchor_status !== 'pending') return;

        $hash_bytes = hex2bin($row->record_hash);
        if ($hash_bytes === false) {
            self::log('error', 'Anchoring skipped: record_hash is not valid hex.', $row_id);
            return;
        }

        foreach (self::CALENDAR_URLS as $url) {
            $resp = wp_remote_post(trailingslashit($url) . 'digest', [
                'timeout' => 10,
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body'    => $hash_bytes,
            ]);
            if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200) {
                $ots_receipt = wp_remote_retrieve_body($resp);
                self::update_row($row_id, [
                    'anchor_status' => 'submitted',
                    'anchor_proof'  => base64_encode($ots_receipt),
                ]);
                // First upgrade check gets a real head start (Bitcoin
                // confirmation is never faster than ~10 minutes) rather
                // than hammering the calendar server immediately.
                if (class_exists('OUS_Jobs')) {
                    OUS_Jobs::enqueue(self::UPGRADE_JOB_HOOK, ['ledger_row_id' => $row_id, 'attempt' => 1], self::UPGRADE_RETRY_DELAY);
                }
                return;
            }
            self::log('warning', 'Anchoring submit failed against ' . $url . ': ' . (is_wp_error($resp) ? $resp->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($resp)), $row_id);
        }
        // Every calendar server failed — leave anchor_status='pending' so
        // a future manual "retry anchoring" admin action (or the next
        // purchase-triggered code path, if this were ever re-run) can
        // pick it back up. No automatic re-submit scheduled here since a
        // total submit failure across all three servers is more likely a
        // real outage than a transient blip.
    }

    /** @param array<string, mixed> $args */
    public static function handle_upgrade_job($args): void {
        $row_id = (int) ($args['ledger_row_id'] ?? 0);
        $attempt = (int) ($args['attempt'] ?? 1);
        $row = self::get_row($row_id);
        if (!$row || $row->anchor_status !== 'submitted') return;

        $ots_receipt = base64_decode((string) $row->anchor_proof);
        foreach (self::CALENDAR_URLS as $url) {
            // OpenTimestamps' /timestamp/<commitment> upgrade endpoint —
            // the commitment (the original hash) identifies which
            // pending attestation to check for a completed Bitcoin
            // block-inclusion proof.
            $resp = wp_remote_get(trailingslashit($url) . 'timestamp/' . $row->record_hash, ['timeout' => 10]);
            if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200) {
                $body = wp_remote_retrieve_body($resp);
                // A confirmed OpenTimestamps proof is strictly longer
                // than the pending "submitted" receipt (it now embeds a
                // real Bitcoin block-header attestation) — the cheap,
                // real signal this client-side code can check without
                // needing the full OTS binary proof format parsed here.
                if ($body && strlen($body) > strlen($ots_receipt)) {
                    self::update_row($row_id, ['anchor_status' => 'confirmed', 'anchor_proof' => base64_encode($body)]);
                    if (class_exists('BH_Event')) {
                        BH_Event::emit('bhm/purchase_anchored', [
                            'user_id' => (int) $row->user_id,
                            'subject_type' => 'bhm_purchase_ledger',
                            'subject_id' => $row_id,
                            'payload' => ['event_type' => $row->event_type, 'wc_order_id' => (int) $row->wc_order_id],
                        ]);
                    }
                    return;
                }
            }
        }

        if ($attempt >= self::MAX_UPGRADE_ATTEMPTS) {
            self::log('warning', "Anchoring left 'submitted' (not yet confirmed) after $attempt attempts — Bitcoin confirmation is just slow, or a real issue. Row is still valid proof of SUBMISSION time either way; a manual re-check can resume this later.", $row_id);
            return;
        }
        if (class_exists('OUS_Jobs')) {
            OUS_Jobs::enqueue(self::UPGRADE_JOB_HOOK, ['ledger_row_id' => $row_id, 'attempt' => $attempt + 1], self::UPGRADE_RETRY_DELAY);
        }
    }

    private static function get_row(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bhm_purchase_ledger WHERE id = %d", $id));
    }

    /** @param array<string, mixed> $fields */
    private static function update_row(int $id, array $fields): void {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'bhm_purchase_ledger', $fields, ['id' => $id]);
    }

    private static function log(string $level, string $message, int $row_id): void {
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log($level, $message, ['ledger_row_id' => $row_id], 'BH Monetization');
        }
    }
}
