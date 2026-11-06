<?php
if (!defined('ABSPATH')) exit;

/**
 * Anchors purchase-ledger record hashes to Bitcoin via public OpenTimestamps calendars (no wallet).
 * Only the SHA-256 hash leaves the site. Two steps: submit now (pending -> submitted), then upgrade
 * via delayed OUS_Jobs retries until confirmed or MAX_UPGRADE_ATTEMPTS.
 */
class BHM_Anchoring {
    const SUBMIT_JOB_HOOK  = 'bhm_anchor_submit';
    const UPGRADE_JOB_HOOK = 'bhm_anchor_upgrade';
    const UPGRADE_RETRY_DELAY = 30 * MINUTE_IN_SECONDS;
    const MAX_UPGRADE_ATTEMPTS = 20; // ~10 hours, then the row stays 'submitted' for a manual re-check

    // The reference `ots` client's default calendars, so any standard verifier works.
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

    // Network call runs off-request so checkout never waits on a third party.
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
                // Bitcoin confirmation takes at least ~10 minutes, so delay the first check.
                if (class_exists('OUS_Jobs')) {
                    OUS_Jobs::enqueue(self::UPGRADE_JOB_HOOK, ['ledger_row_id' => $row_id, 'attempt' => 1], self::UPGRADE_RETRY_DELAY);
                }
                return;
            }
            self::log('warning', 'Anchoring submit failed against ' . $url . ': ' . (is_wp_error($resp) ? $resp->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($resp)), $row_id);
        }
        // All calendars failed (likely an outage): stays 'pending', no automatic re-submit.
    }

    /** @param array<string, mixed> $args */
    public static function handle_upgrade_job($args): void {
        $row_id = (int) ($args['ledger_row_id'] ?? 0);
        $attempt = (int) ($args['attempt'] ?? 1);
        $row = self::get_row($row_id);
        if (!$row || $row->anchor_status !== 'submitted') return;

        $ots_receipt = base64_decode((string) $row->anchor_proof);
        foreach (self::CALENDAR_URLS as $url) {
            $resp = wp_remote_get(trailingslashit($url) . 'timestamp/' . $row->record_hash, ['timeout' => 10]);
            if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200) {
                $body = wp_remote_retrieve_body($resp);
                // A confirmed proof is longer than the pending receipt (it embeds a block attestation).
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
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . BHM_Tables::purchase_ledger() . " WHERE id = %d", $id));
    }

    /** @param array<string, mixed> $fields */
    private static function update_row(int $id, array $fields): void {
        global $wpdb;
        $wpdb->update(BHM_Tables::purchase_ledger(), $fields, ['id' => $id]);
    }

    private static function log(string $level, string $message, int $row_id): void {
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log($level, $message, ['ledger_row_id' => $row_id], 'BH Monetization');
        }
    }
}
