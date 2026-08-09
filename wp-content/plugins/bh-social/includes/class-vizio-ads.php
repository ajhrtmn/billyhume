<?php
if (!defined('ABSPATH')) exit;

/**
 * Vizio Ads / Platform Plus (platformplus.vizio.com) — added in the
 * same research pass as Spotify/Amazon/Samsung. The least accessible
 * of the four for a self-serve small advertiser: no published minimum
 * spend, no public self-serve REST API, and Vizio's own documentation
 * points to a "contact us through our content partners form" flow
 * rather than any direct signup console. This is functionally further
 * from self-serve than even Samsung's DSP-partner-gated access.
 *
 * Included for completeness/honest signaling only — draft-capture +
 * handoff, same BH_AdsPlatform shape as the others, but get_status()
 * and the admin UI should make clear this is the least-ready-to-use
 * of the group rather than implying parity with Roku/Spotify.
 */
class BHSO_VizioAds implements BH_AdsPlatform {
    const MANAGER_URL = 'https://platformplus.vizio.com/ad-solutions';

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
            'platform'        => 'vizio',
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
        return $inserted ? (int) $wpdb->insert_id : new WP_Error('db_error', 'Could not save the draft — ' . $wpdb->last_error);
    }

    /** @return array<int, array<string, mixed>> */
    public function list_campaign_drafts(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'bhso_ad_campaigns';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE platform = %s ORDER BY created_at DESC", 'vizio'
        ), ARRAY_A);
    }

    public function delete_campaign_draft(int $id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'bhso_ad_campaigns';
        return (bool) $wpdb->delete($table, ['id' => (int) $id, 'platform' => 'vizio']);
    }

    public function manager_url(): string {
        return self::MANAGER_URL;
    }
}
