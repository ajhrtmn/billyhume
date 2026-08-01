<?php
if (!defined('ABSPATH')) exit;

/**
 * Spotify Ad Studio (ads.spotify.com) — added alongside Roku after a
 * follow-up research pass into similar paid platforms specifically
 * because it's the best-fitting one for an independent MUSICIAN's
 * audience: a $250 minimum campaign budget (vs. Roku's $500), audio +
 * optional companion-image creative rather than a full video spot, and
 * targeting built around music listening behavior rather than general
 * CTV demographics.
 *
 * Same honest limitation as Roku, confirmed during that same research
 * pass: no publicly documented self-serve REST API for Ad Studio was
 * found — campaign creation is UI-driven at ads.spotify.com. This
 * class follows BHSO_RokuAds' exact pattern for that reason: capture
 * and organize the campaign PLAN locally, then hand off to Ad Studio's
 * real UI to actually launch it — not a simulated integration.
 */
class BHSO_SpotifyAds implements BH_AdsPlatform {
    const MANAGER_URL = 'https://ads.spotify.com';
    const MINIMUM_BUDGET_CENTS = 25000; // Ad Studio's own documented $250 floor

    public function is_configured() {
        return true; // no API credentials to configure — see class docblock
    }

    public function get_status() {
        return 'manual_handoff';
    }

    public function save_campaign_draft($args) {
        global $wpdb;
        $table = $wpdb->prefix . 'bhso_ad_campaigns';

        $data = [
            'platform'        => 'spotify',
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
        if ($data['budget_cents'] < self::MINIMUM_BUDGET_CENTS) {
            return new WP_Error('below_minimum', 'Spotify Ad Studio\'s own documented minimum is $250 — enter a budget at or above that to avoid a surprise when you get to their UI.');
        }

        $inserted = $wpdb->insert($table, $data);
        return $inserted ? (int) $wpdb->insert_id : new WP_Error('db_error', 'Could not save the draft — ' . $wpdb->last_error);
    }

    public function list_campaign_drafts() {
        global $wpdb;
        $table = $wpdb->prefix . 'bhso_ad_campaigns';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE platform = %s ORDER BY created_at DESC", 'spotify'
        ), ARRAY_A);
    }

    public function delete_campaign_draft($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'bhso_ad_campaigns';
        return (bool) $wpdb->delete($table, ['id' => (int) $id, 'platform' => 'spotify']);
    }

    public function manager_url() {
        return self::MANAGER_URL;
    }
}
