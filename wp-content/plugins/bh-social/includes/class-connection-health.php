<?php
if (!defined('ABSPATH')) exit;

/**
 * Tracks whether each platform's token still works (not just that one is stored),
 * in the platform's own bhso_{platform}_settings option. Platforms call track()
 * once from their public cross_post()/pull_stats() wrappers.
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
     * True only when the most recent outcome was a failure; never-called counts as healthy.
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
