<?php
if (!defined('ABSPATH')) exit;

/**
 * Roku Ads Manager (ads.roku.com) — the accessible, self-serve entry
 * point for CTV advertising named in the scoping pass this plugin
 * started from (~$500 minimum campaign spend; Roku OneView is the
 * separate, ~$25,000+ programmatic/agency side, deliberately NOT
 * targeted here since it's the wrong fit for an independent artist).
 *
 * Honest limitation, not a bug or an oversight: unlike YouTube Data
 * API, Twitch Helix, and Meta Graph API, no confirmed PUBLIC, documented
 * self-serve REST API for Roku Ads Manager was found during scoping —
 * Roku's public developer docs cover the Roku OS/channel side (SDKs,
 * Brightscript, the Roku Channel Store), not campaign creation for
 * Ads Manager advertisers, and Ads Manager's own UI is the only
 * confirmed way a self-serve advertiser configures/launches a
 * campaign today. Rather than build against undocumented or guessed
 * endpoints, this class does the part that's genuinely useful without
 * one: capture and organize campaign PLANS locally (budget, dates,
 * targeting notes, a linked creative attachment) so nothing has to be
 * re-typed into Roku's UI from scratch, then hand off to Ads Manager's
 * real UI for the actual launch. If Roku ever publishes a real
 * self-serve API, save_campaign_draft()'s stored shape is already the
 * right input to submit programmatically — only submit_campaign()
 * would need to be added, not a redesign.
 */
class BHSO_RokuAds implements BH_AdsPlatform {
    const MANAGER_URL = 'https://ads.roku.com';

    public function is_configured() {
        // No API credentials exist to configure — this integration is
        // draft-capture-plus-handoff, not an authenticated API client.
        // Always "configured" in the sense that drafting works with
        // zero setup; get_status() reflects the real, honest state.
        return true;
    }

    public function get_status() {
        return 'manual_handoff'; // never "connected" — there's no account link to make
    }

    public function save_campaign_draft($args) {
        global $wpdb;
        $table = $wpdb->prefix . 'bhso_ad_campaigns';

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

    public function list_campaign_drafts() {
        global $wpdb;
        $table = $wpdb->prefix . 'bhso_ad_campaigns';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE platform = %s ORDER BY created_at DESC", 'roku'
        ), ARRAY_A);
    }

    public function delete_campaign_draft($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'bhso_ad_campaigns';
        return (bool) $wpdb->delete($table, ['id' => (int) $id, 'platform' => 'roku']);
    }

    public function manager_url() {
        return self::MANAGER_URL;
    }
}
