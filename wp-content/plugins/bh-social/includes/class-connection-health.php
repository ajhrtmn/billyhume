<?php
if (!defined('ABSPATH')) exit;

/**
 * Real connection-health tracking, added after a live-robustness audit
 * found a genuine gap: every platform's get_status() only ever checked
 * "do we have a stored refresh_token" — never whether the token still
 * actually WORKS. A revoked/expired token (Google OAuth apps left in
 * "Testing" publish mode — the default until an app is submitted for
 * verification — have refresh tokens that expire after just 7 days of
 * inactivity) would leave the settings page showing a green "Connected"
 * badge indefinitely while the twice-daily stats-pull job quietly fails
 * forever, visible only in the generic Debug Tools job-failure table
 * nobody proactively checks.
 *
 * Deliberately NOT a new table — piggybacks on each platform's own
 * settings option (`bhso_{platform}_settings`), the same option
 * cross_post()/pull_stats() already read/write, so no new schema and
 * no extra query on the settings page render path.
 *
 * `track()` is the one call site each platform's cross_post()/
 * pull_stats() wrapper needs — see e.g. class-youtube-platform.php's
 * public cross_post()/pull_stats() methods, which now delegate to a
 * renamed do_*() method and call track() once on the way out. Covers
 * every internal failure path (token refresh, HTTP error, non-2xx API
 * response) without needing a record call at each individual early
 * return inside those methods.
 */
class BHSO_ConnectionHealth {
    /** @param true|\WP_Error $result */
    public static function track(string $platform_key, $result): void {
        $option = 'bhso_' . $platform_key . '_settings';
        $s = get_option($option, []);
        if (!is_array($s)) $s = [];

        if (is_wp_error($result)) {
            $s['last_error'] = $result->get_error_message();
            $s['last_error_at'] = time();
        } else {
            $s['last_success_at'] = current_time('mysql', true);
        }
        update_option($option, $s);
    }

    /**
     * True only when the most recent OUTCOME was a failure — a platform
     * that's never been called since connecting (both fields empty) is
     * NOT broken, just untested yet; that's a deliberate optimistic
     * default, not a false negative, since 'connected' already meant
     * "we have a token" before this class existed and a freshly
     * connected platform shouldn't regress to a scarier-looking state.
     *
     * @param array<string, mixed> $settings This platform's own settings() array.
     */
    public static function is_broken(array $settings): bool {
        $last_error_at = (int) ($settings['last_error_at'] ?? 0);
        if (!$last_error_at) return false;

        $last_success_at = !empty($settings['last_success_at']) ? (int) strtotime($settings['last_success_at'] . ' UTC') : 0;
        return $last_error_at > $last_success_at;
    }

    /** @param array<string, mixed> $settings */
    public static function last_error(array $settings): string {
        return (string) ($settings['last_error'] ?? '');
    }
}
