<?php
if (!defined('ABSPATH')) exit;

/**
 * Amazon DSP (advertising.amazon.com) — added in the same research
 * pass as Spotify/Samsung/Vizio. Amazon dropped its official self-serve
 * minimum spend in November 2025, but that's misleading in practice:
 * most practitioners report budgets under $5,000/month produce too
 * little delivery data for the platform's own optimization to work,
 * and $10-15k/month is the realistic floor for a usable campaign — a
 * different order of magnitude from Roku ($500) or Spotify ($250).
 * Included for completeness and honest budget signaling, not because
 * it's a realistic near-term fit for an independent artist's ad spend.
 *
 * Same API gap as the others in this file group: genuine DSP API
 * access exists but is gated to agencies/tool-providers with an
 * approved integration, not a plain self-serve REST endpoint a small
 * advertiser calls directly — draft-capture + handoff to Amazon's own
 * console, same shape as BHSO_RokuAds.
 */
class BHSO_AmazonDSP implements BH_AdsPlatform {
    const MANAGER_URL = 'https://advertising.amazon.com/solutions/products/amazon-dsp';

    // Not a hard platform-enforced minimum (Amazon itself has none as
    // of Nov 2025) — a soft, honestly-labeled warning threshold below
    // which the campaign is very unlikely to gather enough delivery
    // data to actually optimize, per the research this class's
    // docblock cites.
    const PRACTICAL_MINIMUM_CENTS = 500000; // $5,000

    public function is_configured(): bool {
        return true;
    }

    public function get_status(): string {
        return 'manual_handoff';
    }

    /**
     * @param array<string, mixed> $args
     * @return int|\WP_Error
     */
    public function save_campaign_draft(array $args) {
        global $wpdb;
        $table = $wpdb->prefix . 'bhso_ad_campaigns';

        $data = [
            'platform'        => 'amazon_dsp',
            'name'            => sanitize_text_field((string) ($args['name'] ?? '')),
            'budget_cents'    => max(0, (int) round((float) ($args['budget_dollars'] ?? 0) * 100)),
            'start_date'      => !empty($args['start_date']) ? sanitize_text_field($args['start_date']) : null,
            'end_date'        => !empty($args['end_date']) ? sanitize_text_field($args['end_date']) : null,
            'targeting_notes' => sanitize_textarea_field((string) ($args['targeting_notes'] ?? '')),
            'attachment_id'   => (int) ($args['attachment_id'] ?? 0),
            'status'          => 'draft',
            'created_at'      => current_time('mysql', true),
        ];

        if (empty($data['name'])) return new WP_Error('missing_name', 'A campaign draft needs a name.');

        $inserted = $wpdb->insert($table, $data);
        if (!$inserted) return new WP_Error('db_error', 'Could not save the draft — ' . $wpdb->last_error);

        $id = (int) $wpdb->insert_id;
        // A soft warning, not a hard block — Amazon itself imposes no
        // minimum, so rejecting the save would be dishonestly stricter
        // than the platform actually is.
        if ($data['budget_cents'] > 0 && $data['budget_cents'] < self::PRACTICAL_MINIMUM_CENTS) {
            return new WP_Error('below_practical_minimum', 'Draft saved (id ' . $id . '), but budgets under $5,000/month rarely generate enough delivery data for Amazon DSP to optimize — most practitioners recommend $10-15k/month as a realistic floor.', ['draft_id' => $id]);
        }
        return $id;
    }

    /** @return array<int, array<string, mixed>> */
    public function list_campaign_drafts(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'bhso_ad_campaigns';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE platform = %s ORDER BY created_at DESC", 'amazon_dsp'
        ), ARRAY_A);
    }

    public function delete_campaign_draft(int $id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'bhso_ad_campaigns';
        return (bool) $wpdb->delete($table, ['id' => (int) $id, 'platform' => 'amazon_dsp']);
    }

    public function manager_url(): string {
        return self::MANAGER_URL;
    }
}
