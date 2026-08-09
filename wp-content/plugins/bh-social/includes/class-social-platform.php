<?php
if (!defined('ABSPATH')) exit;

/**
 * Organic cross-post/stats-pull platforms only (YouTube, Twitch, Meta,
 * TikTok) — deliberately excludes Roku Ads, which is a paid-campaign
 * shape (budget/targeting/billing) covered by a separate BH_AdsPlatform
 * interface once that gets built, not this one.
 */
interface BH_SocialPlatform {
    /** True once enough settings exist to attempt API calls (does not guarantee tokens are still valid). */
    public function is_configured(): bool;

    /** Human-readable connection status for the settings screen — e.g. connected/disconnected/needs-reauth. */
    public function get_status(): string;

    /** Disconnects — clears stored tokens without touching client_id/client_secret. */
    public function disconnect(): bool;

    /**
     * Publishes $args (platform-specific shape — e.g. attachment_id,
     * title, description for YouTube) to the platform. Called from a
     * background job (OUS_Jobs), never inline on a request — a slow
     * or failing third-party API must not block the request that
     * triggered it.
     *
     * @param array<string, mixed> $args
     * @return true|\WP_Error
     */
    public function cross_post(array $args);

    /**
     * Pulls current stats from the platform and writes them into
     * bhso_platform_stats. Called from a background job.
     *
     * @return true|\WP_Error
     */
    public function pull_stats();
}
