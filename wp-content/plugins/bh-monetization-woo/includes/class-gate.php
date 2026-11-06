<?php
if (!defined('ABSPATH')) exit;

/**
 * Content-type-agnostic paywall: any post type marks itself locked with one postmeta key
 * and calls one static method to check access (used by bh-streaming and bh-courses).
 */
class BHM_Gate {
    public static function init(): void {
        // Stateless API; no hooks needed.
    }

    /**
     * USAGE for any content type wanting to be paylocked:
     *
     *   update_post_meta($post_id, '_bhm_required_tier', $tier_id);
     *   // ...and at render/serve time:
     *   if (!BHM_Gate::user_has_tier_access(get_current_user_id(), $tier_id)) {
     *       echo BHM_Gate::render_paywall_notice($tier_id);
     *       return;
     *   }
     *
     * No `_bhm_required_tier` meta means 0, which always passes: content is open by default.
     */
    public static function get_required_tier(int $post_id): int {
        return (int) get_post_meta($post_id, '_bhm_required_tier', true);
    }

    // Standalone purchase check, reachable without any tier requirement
    // (BHC_Gate::user_can_access_course() sells courses as one-time purchases).
    public static function user_owns_object(int $user_id, int $object_id): bool {
        if (!$user_id || !$object_id) return false;
        global $wpdb;
        $t = BHM_Tables::entitlements();
        return (bool) (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE user_id = %d AND type = 'purchase' AND object_id = %d", $user_id, $object_id
        ));
    }

    // True for an active grant at $required_tier or any higher-priced tier, a purchase of
    // $object_id, or anything the bhm_extra_entitlement_check filter grants. 0 always passes.
    public static function user_has_tier_access(int $user_id, int $required_tier, ?int $object_id = null): bool {
        if (!$required_tier) return true;
        if (!$user_id) return apply_filters('bhm_extra_entitlement_check', false, 0, $required_tier, $object_id);

        global $wpdb;
        $t = BHM_Tables::entitlements();
        $now = current_time('mysql');

        // expires_at NULL = permanent grant.
        $tier_ids = BHM_Tiers::ids_at_or_above($required_tier);
        if ($tier_ids) {
            $placeholders = implode(',', array_fill(0, count($tier_ids), '%d'));
            $sql = "SELECT COUNT(*) FROM $t WHERE user_id = %d AND type IN ('subscription','streaming_tier')
                    AND object_id IN ($placeholders) AND (expires_at IS NULL OR expires_at > %s)";
            $args = array_merge([$user_id], $tier_ids, [$now]);
            if ((int) $wpdb->get_var($wpdb->prepare($sql, $args))) return true;
        }

        // Buying the object outright always unlocks it.
        if ($object_id && self::user_owns_object($user_id, $object_id)) return true;

        // Extension point for other plugins (comped accounts, enrollments, etc.).
        return (bool) apply_filters('bhm_extra_entitlement_check', false, $user_id, $required_tier, $object_id);
    }

    /**
     * Benefit-based gating: any tier granting $benefit_key, independent of price rank.
     *
     *   update_post_meta($post_id, '_bhm_required_benefit', 'courses');
     *   if (!BHM_Gate::user_has_benefit(get_current_user_id(), 'courses', $post_id)) { ... }
     */
    public static function user_has_benefit(int $user_id, string $benefit_key, ?int $object_id = null): bool {
        if (!$benefit_key) return true;
        if (!$user_id) return apply_filters('bhm_extra_entitlement_check', false, 0, 0, $object_id);

        $tier_ids = BHM_Tiers::ids_granting_benefit($benefit_key);
        if ($tier_ids) {
            global $wpdb;
            $t = BHM_Tables::entitlements();
            $now = current_time('mysql');
            $placeholders = implode(',', array_fill(0, count($tier_ids), '%d'));
            $sql = "SELECT COUNT(*) FROM $t WHERE user_id = %d AND type IN ('subscription','streaming_tier')
                    AND object_id IN ($placeholders) AND (expires_at IS NULL OR expires_at > %s)";
            $args = array_merge([$user_id], $tier_ids, [$now]);
            if ((int) $wpdb->get_var($wpdb->prepare($sql, $args))) return true;
        }

        if ($object_id && self::user_owns_object($user_id, $object_id)) return true;

        return (bool) apply_filters('bhm_extra_entitlement_check', false, $user_id, 0, $object_id);
    }

    /* ---------------- tier upgrade/downgrade ---------------- */

    // Access period of a one-time tier purchase. WHY: grant expiry and downgrade
    // proration must use the same number, so both reference this constant.
    const FALLBACK_ACCESS_DAYS = 30;

    // Pure (unit-tested): unused whole days at the old tier's daily rate. No tax/partial-day handling.

    public static function calculate_downgrade_credit_cents(int $old_tier_price_cents, int $days_remaining): int {
        if ($old_tier_price_cents <= 0 || $days_remaining <= 0) return 0;
        $daily_rate_cents = $old_tier_price_cents / self::FALLBACK_ACCESS_DAYS;
        return (int) round($daily_rate_cents * $days_remaining);
    }

    // Pure (unit-tested); never negative when annual is priced at or above 12x monthly.
    public static function annual_savings_percent(int $monthly_price_cents, int $annual_price_cents): int {
        $full_year_at_monthly_rate = $monthly_price_cents * 12;
        if ($full_year_at_monthly_rate <= 0) return 0;
        $percent = round((1 - ($annual_price_cents / $full_year_at_monthly_rate)) * 100);
        return max(0, (int) $percent);
    }

    // Day-based wallet credit for a downgrade; steps aside when WC Subscriptions' switcher handles proration.
    public static function handle_tier_downgrade(int $user_id, int $old_tier_id, int $new_tier_id, string $expires_at): void {
        $has_switching = BH_Commerce::has_subscription_switching();
        if ($has_switching) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('info', 'Tier downgrade deferred to WooCommerce Subscriptions\' own switch/proration handling.', ['user_id' => $user_id, 'old_tier_id' => $old_tier_id, 'new_tier_id' => $new_tier_id], 'BH Monetization');
            }
            return; // WC Subscriptions owns this; never credit twice
        }
        if (!class_exists('BHM_Wallet') || !$expires_at) return;

        $old_tier = BHM_Tiers::get($old_tier_id);
        if (!$old_tier || $old_tier['price_cents'] <= 0) return;

        $days_remaining = max(0, (int) ceil((strtotime($expires_at) - current_time('timestamp')) / DAY_IN_SECONDS));
        if (!$days_remaining) return;

        $credit_cents = self::calculate_downgrade_credit_cents($old_tier['price_cents'], $days_remaining);
        if ($credit_cents <= 0) return;

        BHM_Wallet::credit($user_id, $credit_cents, 'tier_downgrade_credit: ' . $days_remaining . ' unused day(s) on "' . $old_tier['name'] . '"');

        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('info', 'Tier downgrade credited to wallet (no WooCommerce Subscriptions active — plain day-based proration).', [
                'user_id' => $user_id, 'old_tier_id' => $old_tier_id, 'new_tier_id' => $new_tier_id,
                'days_remaining' => $days_remaining, 'credit_cents' => $credit_cents,
            ], 'BH Monetization');
        }
    }

    // Generic "become a supporter" notice for any locked content type.
    public static function render_paywall_notice(int $tier_id): string {
        $tier = BHM_Tiers::get($tier_id);
        if (!$tier) return '<div class="bhm-paywall"><p>This content requires supporter access.</p></div>';

        ob_start();
        ?>
        <div class="bhm-paywall">
            <p class="bhm-paywall-title">This content is for <strong><?php echo esc_html($tier['name']); ?></strong> supporters and above.</p>
            <?php if (!empty($tier['benefits'])): ?><p class="bhm-paywall-benefits"><?php echo esc_html($tier['benefits']); ?></p><?php endif; ?>
            <a class="bhm-btn" href="<?php echo esc_url(BHM_Tiers::tiers_page_url()); ?>">Become a supporter</a>
        </div>
        <?php
        return ob_get_clean();
    }
}
