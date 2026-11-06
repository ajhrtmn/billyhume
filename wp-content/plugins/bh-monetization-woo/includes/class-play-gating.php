<?php
if (!defined('ABSPATH')) exit;

/**
 * bh-streaming gating filters (access, lock notice, play allowed/denied) and pay-per-play debiting.
 */
class BHM_PlayGating {
    public static function init(): void {
        if (class_exists('BHS_Admin')) {
            add_filter('bhs_track_access_allowed', [self::class, 'track_access_allowed'], 10, 2);
            add_filter('bhs_track_lock_notice', [self::class, 'track_lock_notice'], 10, 2);
            add_filter('bhs_track_play_allowed', [self::class, 'track_play_allowed'], 10, 3);
            add_filter('bhs_track_play_denied_message', [self::class, 'track_play_denied_message'], 10, 2);
        }
    }

    public static function track_access_allowed(bool $allowed, int $track_id): bool {
        if (!$allowed) return false; // something else already said no — don't override
        if (BHM_MonetizationUI::is_non_catalog_track($track_id)) return true; // never gated — see is_non_catalog_track()
        $required_tier = (int) get_post_meta($track_id, '_bhm_required_tier', true);
        return BHM_Gate::user_has_tier_access(get_current_user_id(), $required_tier, $track_id);
    }

    public static function track_lock_notice(string $default, int $track_id): string {
        $required_tier = (int) get_post_meta($track_id, '_bhm_required_tier', true);
        return BHM_Gate::render_paywall_notice($required_tier);
    }

    // Runs on the /tracks/{id}/play request, never at listing time (that would charge for page loads).
    // Every allowed play is logged to bhm_play_log, paid or not.
    public static function track_play_allowed(bool $allowed, int $track_id, int $user_id): bool {
        if (!$allowed) return false;
        if (BHM_MonetizationUI::is_non_catalog_track($track_id)) return true; // never charged — see is_non_catalog_track()

        $required_tier = (int) get_post_meta($track_id, '_bhm_required_tier', true);
        $ppp_cents = (int) get_post_meta($track_id, '_bhm_pay_per_play_cents', true);

        // Covered by an entitlement: free, but logged.
        if (BHM_Gate::user_has_tier_access($user_id, $required_tier, $track_id)) {
            self::log_play($user_id, $track_id, false, 0);
            return true;
        }

        if (!$ppp_cents) {
            self::log_play($user_id, $track_id, false, 0);
            return true;
        }

        // Anonymous listeners have no wallet: declined.
        if (!$user_id) return false;

        $debited = BHM_Wallet::debit($user_id, $ppp_cents, $track_id);
        if ($debited) {
            self::log_play($user_id, $track_id, true, $ppp_cents);
            self::check_play_velocity($user_id);
        }
        return $debited;
    }

    // A burst of paid plays can mean a drained account or a tested stolen card; flagged for a human only.
    const VELOCITY_WINDOW_SECONDS = 60;
    const VELOCITY_THRESHOLD = 8;

    private static function check_play_velocity(int $user_id): void {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::VELOCITY_WINDOW_SECONDS);
        $recent_paid_plays = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . BHM_Tables::play_log() . " WHERE user_id = %d AND paid = 1 AND created_at > %s",
            $user_id, $cutoff
        ));
        if ($recent_paid_plays < self::VELOCITY_THRESHOLD) return;

        // Rate-limit the flag so it doesn't re-fire on every play of a burst.
        $flag_key = '_bhm_velocity_flagged_at';
        $last_flagged = (int) get_user_meta($user_id, $flag_key, true);
        if (time() - $last_flagged < self::VELOCITY_WINDOW_SECONDS) return;

        update_user_meta($user_id, $flag_key, time());
        update_user_meta($user_id, '_bhm_velocity_flagged', '1');
        do_action('bhm_play_velocity_flagged', $user_id, $recent_paid_plays);
    }

    public static function track_play_denied_message(string $default, int $track_id): string {
        $ppp_cents = (int) get_post_meta($track_id, '_bhm_pay_per_play_cents', true);
        if (!$ppp_cents) return $default;
        return sprintf(
            'This track costs $%s to play and your account isn\'t logged in or doesn\'t have enough play credit. Log in and top up your wallet to continue.',
            BHM_Money::display($ppp_cents)
        );
    }

    private static function log_play(int $user_id, int $track_id, bool $paid, int $cents): void {
        global $wpdb;
        $wpdb->insert(BHM_Tables::play_log(), [
            'user_id' => $user_id ?: 0, 'track_id' => $track_id, 'paid' => $paid ? 1 : 0, 'cents' => $cents,
        ]);
    }
}
