<?php
if (!defined('ABSPATH')) exit;

/**
 * Amazon DSP. API access is gated to approved integrators, so this captures
 * drafts and hands off to Amazon's console, like BHSO_RokuAds.
 */
class BHSO_AmazonDSP implements BH_AdsPlatform {
    const MANAGER_URL = 'https://advertising.amazon.com/solutions/products/amazon-dsp';

    // Soft warning threshold, not a platform minimum: below it campaigns rarely get enough delivery data.
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
        $table = BHSO_Tables::ad_campaigns();

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
        // Soft warning only; Amazon imposes no minimum.
        if ($data['budget_cents'] > 0 && $data['budget_cents'] < self::PRACTICAL_MINIMUM_CENTS) {
            return new WP_Error('below_practical_minimum', 'Draft saved (id ' . $id . '), but budgets under $5,000/month rarely generate enough delivery data for Amazon DSP to optimize — most practitioners recommend $10-15k/month as a realistic floor.', ['draft_id' => $id]);
        }
        return $id;
    }

    /** @return array<int, array<string, mixed>> */
    public function list_campaign_drafts(): array {
        global $wpdb;
        $table = BHSO_Tables::ad_campaigns();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE platform = %s ORDER BY created_at DESC", 'amazon_dsp'
        ), ARRAY_A);
    }

    public function delete_campaign_draft(int $id): bool {
        global $wpdb;
        $table = BHSO_Tables::ad_campaigns();
        return (bool) $wpdb->delete($table, ['id' => (int) $id, 'platform' => 'amazon_dsp']);
    }

    public function manager_url(): string {
        return self::MANAGER_URL;
    }
}
