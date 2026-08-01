<?php
if (!defined('ABSPATH')) exit;

/**
 * Paid ad-campaign platforms — deliberately a separate interface from
 * BH_SocialPlatform, not one more implementation of it. Cross-posting
 * organic content and buying ad inventory are genuinely different
 * shapes: different auth (billing-scoped, not just content scopes),
 * different data (budget/targeting/creative, not caption/attachment),
 * and — for Roku specifically — no confirmed self-serve REST API to
 * call at all (see BHSO_RokuAds's own docblock).
 */
interface BH_AdsPlatform {
    public function is_configured();
    public function get_status();

    /** Persists a campaign's config locally as a draft — never submits spend anywhere by itself. */
    public function save_campaign_draft($args);

    /** @return array[] all stored drafts, most recent first */
    public function list_campaign_drafts();

    /** URL to the platform's own ad-manager UI, where a draft actually gets executed by a human. */
    public function manager_url();
}
