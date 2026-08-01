<?php
if (!defined('ABSPATH')) exit;

/**
 * Samsung Ads (news.samsung.com/Samsung DSP) — added in the same
 * research pass as Spotify/Amazon/Vizio. Samsung DOES have a
 * self-serve DSP, but real self-serve access for a small/independent
 * advertiser is effectively gated behind existing DSP relationships —
 * Samsung's own 2026 announcements describe automated buying through
 * integrations with The Trade Desk and Google DV360, not a direct
 * small-advertiser signup console the way Spotify Ad Studio or Roku
 * Ads Manager have. No published minimum spend or public self-serve
 * REST API for a standalone advertiser was found.
 *
 * Included for completeness/honest signaling rather than as a
 * realistic near-term channel — draft-capture + handoff to Samsung
 * Ads' own site, same shape as the other BH_AdsPlatform
 * implementations, so the gap is visible in the UI rather than silent.
 */
class BHSO_SamsungAds implements BH_AdsPlatform {
    const MANAGER_URL = 'https://www.samsung.com/us/business/samsungads/';

    public function is_configured() {
        return true;
    }

    public function get_status() {
        return 'manual_handoff';
    }

    public function save_campaign_draft($args) {
        global $wpdb;
        $table = $wpdb->prefix . 'bhso_ad_campaigns';

        $data = [
            'platform'        => 'samsung',
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

    public function list_campaign_drafts() {
        global $wpdb;
        $table = $wpdb->prefix . 'bhso_ad_campaigns';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE platform = %s ORDER BY created_at DESC", 'samsung'
        ), ARRAY_A);
    }

    public function delete_campaign_draft($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'bhso_ad_campaigns';
        return (bool) $wpdb->delete($table, ['id' => (int) $id, 'platform' => 'samsung']);
    }

    public function manager_url() {
        return self::MANAGER_URL;
    }
}
