<?php
if (!defined('ABSPATH')) exit;

/**
 * Split out of class-products.php (DRY/SOLID audit Phase 3) — the
 * bh-streaming gating hooks (bhs_track_access_allowed/lock_notice/
 * play_allowed/play_denied_message) and the pay-per-play money-moving
 * logic they drive. Reads config BHM_MonetizationUI persisted; grants
 * come from BHM_Gate/BHM_Wallet, never from this class directly.
 */
class BHM_PlayGating {
    public static function init() {
        if (class_exists('BHS_Admin')) {
            add_filter('bhs_track_access_allowed', [self::class, 'track_access_allowed'], 10, 2);
            add_filter('bhs_track_lock_notice', [self::class, 'track_lock_notice'], 10, 2);
            add_filter('bhs_track_play_allowed', [self::class, 'track_play_allowed'], 10, 3);
            add_filter('bhs_track_play_denied_message', [self::class, 'track_play_denied_message'], 10, 2);
        }
    }

    public static function track_access_allowed($allowed, $track_id) {
        if (!$allowed) return false; // something else already said no — don't override
        if (BHM_MonetizationUI::is_non_catalog_track($track_id)) return true; // never gated — see is_non_catalog_track()
        $required_tier = (int) get_post_meta($track_id, '_bhm_required_tier', true);
        return BHM_Gate::user_has_tier_access(get_current_user_id(), $required_tier, $track_id);
    }

    public static function track_lock_notice($default, $track_id) {
        $required_tier = (int) get_post_meta($track_id, '_bhm_required_tier', true);
        return BHM_Gate::render_paywall_notice($required_tier);
    }

    // The actual money-moving half of pay-per-play: called at the
    // moment bh-streaming's /tracks/{id}/play endpoint is hit (see
    // class-api.php's record_play()), NOT at catalog-listing time —
    // debiting on every /tracks fetch would charge a listener just for
    // loading the page. Records a bhm_play_log row EVERY time a play is
    // allowed, whether or not money changed hands, so there's one
    // authoritative, server-authored history to build reporting or a
    // future payout engine on top of — never bh-streaming's own
    // unauthenticated, gameable _bhs_play_count.
    public static function track_play_allowed($allowed, $track_id, $user_id) {
        if (!$allowed) return false;
        if (BHM_MonetizationUI::is_non_catalog_track($track_id)) return true; // never charged — see is_non_catalog_track()

        $required_tier = (int) get_post_meta($track_id, '_bhm_required_tier', true);
        $ppp_cents = (int) get_post_meta($track_id, '_bhm_pay_per_play_cents', true);

        // Already covered by a tier/subscription/purchase entitlement —
        // free to play, no debit, but still logged.
        if (BHM_Gate::user_has_tier_access($user_id, $required_tier, $track_id)) {
            self::log_play($user_id, $track_id, false, 0);
            return true;
        }

        // No pay-per-play price set and no tier required at all — open
        // streaming, same as bh-streaming's own pre-monetization default.
        if (!$ppp_cents) {
            self::log_play($user_id, $track_id, false, 0);
            return true;
        }

        // A real pay-per-play price applies and no standing entitlement
        // covers it — an anonymous (not-logged-in) listener has no
        // wallet to debit at all, so they're declined outright rather
        // than silently letting the play through.
        if (!$user_id) return false;

        $debited = BHM_Wallet::debit($user_id, $ppp_cents, $track_id);
        if ($debited) {
            self::log_play($user_id, $track_id, true, $ppp_cents);
            self::check_play_velocity($user_id);
        }
        return $debited;
    }

    // A real pattern (someone genuinely queueing up a bunch of short
    // tracks) looks different from either a compromised account being
    // rapidly drained or a stolen-payment-method wallet-topup being
    // tested against your store before trying somewhere bigger — both
    // of which show up as an abnormal burst of paid plays in a short
    // window. This flags the same way the refund pattern does: a
    // signal for a human, never an automatic account action.
    const VELOCITY_WINDOW_SECONDS = 60;
    const VELOCITY_THRESHOLD = 8;

    private static function check_play_velocity($user_id) {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::VELOCITY_WINDOW_SECONDS);
        $recent_paid_plays = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bhm_play_log WHERE user_id = %d AND paid = 1 AND created_at > %s",
            $user_id, $cutoff
        ));
        if ($recent_paid_plays < self::VELOCITY_THRESHOLD) return;

        // Rate-limit the flag itself, not just the underlying activity —
        // otherwise every single play past the threshold re-fires the
        // action for as long as the burst continues.
        $flag_key = '_bhm_velocity_flagged_at';
        $last_flagged = (int) get_user_meta($user_id, $flag_key, true);
        if (time() - $last_flagged < self::VELOCITY_WINDOW_SECONDS) return;

        update_user_meta($user_id, $flag_key, time());
        update_user_meta($user_id, '_bhm_velocity_flagged', '1');
        do_action('bhm_play_velocity_flagged', $user_id, $recent_paid_plays);
    }

    public static function track_play_denied_message($default, $track_id) {
        $ppp_cents = (int) get_post_meta($track_id, '_bhm_pay_per_play_cents', true);
        if (!$ppp_cents) return $default;
        return sprintf(
            'This track costs $%s to play and your account isn\'t logged in or doesn\'t have enough play credit. Log in and top up your wallet to continue.',
            number_format($ppp_cents / 100, 2)
        );
    }

    private static function log_play($user_id, $track_id, $paid, $cents) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'bhm_play_log', [
            'user_id' => $user_id ?: 0, 'track_id' => $track_id, 'paid' => $paid ? 1 : 0, 'cents' => $cents,
        ]);
    }
}
