<?php
if (!defined('ABSPATH')) exit;

/**
 * Organic cross-post/stats platforms (YouTube, Twitch, Meta, TikTok). Paid ads use BH_AdsPlatform.
 */
interface BH_SocialPlatform {
    /** True once enough settings exist to attempt API calls (does not guarantee tokens are still valid). */
    public function is_configured(): bool;

    /** Human-readable connection status for the settings screen — e.g. connected/disconnected/needs-reauth. */
    public function get_status(): string;

    /** Disconnects — clears stored tokens without touching client_id/client_secret. */
    public function disconnect(): bool;

    /**
     * Publishes $args (platform-specific shape). Called from a background job,
     * never inline, so a slow third-party API can't block a request.
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
