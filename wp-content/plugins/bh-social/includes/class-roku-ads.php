<?php
if (!defined('ABSPATH')) exit;

/**
 * Roku Ads Manager. No public self-serve API exists, so this captures campaign
 * drafts locally and hands off to the Ads Manager UI for launch.
 */
class BHSO_RokuAds implements BH_AdsPlatform {
    const MANAGER_URL = 'https://ads.roku.com';

    public function is_configured(): bool {
        // Draft capture needs no credentials.
        return true;
    }

    public function get_status(): string {
        return 'manual_handoff'; // never "connected" — there's no account link to make
    }

    /**
     * @param array<string, mixed> $args
     * @return int|\WP_Error
     */
    public function save_campaign_draft(array $args) {
        global $wpdb;
        $table = BHSO_Tables::ad_campaigns();

        $data = [
            'platform'         => 'roku',
            'name'             => sanitize_text_field((string) ($args['name'] ?? '')),
            'budget_cents'     => max(0, (int) round((float) ($args['budget_dollars'] ?? 0) * 100)),
            'start_date'       => !empty($args['start_date']) ? sanitize_text_field($args['start_date']) : null,
            'end_date'         => !empty($args['end_date']) ? sanitize_text_field($args['end_date']) : null,
            'targeting_notes'  => sanitize_textarea_field((string) ($args['targeting_notes'] ?? '')),
            'attachment_id'    => (int) ($args['attachment_id'] ?? 0),
            'status'           => 'draft',
            'created_at'       => current_time('mysql', true),
        ];

        if (empty($data['name'])) return new WP_Error('missing_name', 'A campaign draft needs a name.');
        if ($data['budget_cents'] < 50000) return new WP_Error('below_minimum', 'Roku Ads Manager\'s own documented minimum is $500 — enter a budget at or above that to avoid a surprise when you get to their UI.');

        $inserted = $wpdb->insert($table, $data);
        return $inserted ? (int) $wpdb->insert_id : new WP_Error('db_error', 'Could not save the draft — ' . $wpdb->last_error);
    }

    /** @return array<int, array<string, mixed>> */
    public function list_campaign_drafts(): array {
        global $wpdb;
        $table = BHSO_Tables::ad_campaigns();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE platform = %s ORDER BY created_at DESC", 'roku'
        ), ARRAY_A);
    }

    public function delete_campaign_draft(int $id): bool {
        global $wpdb;
        $table = BHSO_Tables::ad_campaigns();
        return (bool) $wpdb->delete($table, ['id' => (int) $id, 'platform' => 'roku']);
    }

    public function manager_url(): string {
        return self::MANAGER_URL;
    }
}
