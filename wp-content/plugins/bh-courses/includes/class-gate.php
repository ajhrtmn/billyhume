<?php
if (!defined('ABSPATH')) exit;

/**
 * Optional tier-gating for a course via `_bhm_required_tier` and BHM_Gate. Checked with
 * class_exists() at call time; without bh-monetization-woo courses are open.
 */
class BHC_Gate {
    public static function init(): void {
        // Best-effort notice that course access may have changed after a revocation.
        add_action('bhm_entitlement_revoked', [self::class, 'maybe_notify_course_access_change'], 10, 5);
    }

    // Course gating is account-wide, so only 'account' scope revocations matter.
    public static function maybe_notify_course_access_change(int $user_id, string $type, string $scope, int $object_id, string $reason): void {
        if (!$user_id || $scope !== 'account' || !class_exists('BHM_Tiers') || !class_exists('OUS_Notifications')) return;

        $courses = get_posts(['post_type' => 'bh_course', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids']);
        $affected_titles = [];
        foreach ($courses as $course_id) {
            $required_tier = self::required_tier($course_id);
            $required_benefit = self::required_benefit($course_id);
            $affected = false;
            if ($required_benefit) {
                $affected = in_array((int) $object_id, BHM_Tiers::ids_granting_benefit($required_benefit), true);
            } elseif ($required_tier) {
                $affected = in_array((int) $object_id, BHM_Tiers::ids_at_or_above($required_tier), true);
            }
            if ($affected) $affected_titles[] = get_the_title($course_id);
            if (count($affected_titles) >= 3) break; // enough to name; the rest is "and more"
        }
        if (!$affected_titles) return;

        OUS_Notifications::notify(
            $user_id,
            'course_access_changed',
            'Your course access may have changed',
            'Your supporter tier changed, which affects access to: ' . implode(', ', $affected_titles) . '.',
            home_url('/courses/'),
            'BH Courses'
        );
    }

    public static function required_tier(int $course_id): int {
        return (int) get_post_meta($course_id, '_bhm_required_tier', true);
    }

    // "Any tier granting this benefit" alternative to required_tier(); takes priority when set.
    public static function required_benefit(int $course_id): string {
        $key = get_post_meta($course_id, '_bhm_required_benefit', true);
        return $key ? sanitize_key($key) : '';
    }

    // Kill switch for one-time purchases: `add_filter('bhc_course_purchase_enabled',
    // '__return_false')` hides the admin UI, stops product sync, and stops new purchase
    // access. Not retroactive: existing purchases keep working.
    public static function purchase_feature_enabled(): bool {
        return (bool) apply_filters('bhc_course_purchase_enabled', true);
    }

    public static function user_can_access_course(int $user_id, int $course_id): bool {
        if (!class_exists('BHM_Gate')) return true; // bh-monetization-woo not active

        // WHY: purchase ownership is checked first, since user_has_tier_access() returns true
        // early when no tier is set and never reaches its own purchase check. Checked even when
        // the kill switch is off, so paid access is never revoked by config.
        if (BHM_Gate::user_owns_object($user_id, $course_id)) return true;

        $benefit = self::required_benefit($course_id);
        if ($benefit) {
            return BHM_Gate::user_has_benefit($user_id, $benefit, $course_id);
        }

        $tier = self::required_tier($course_id);
        return BHM_Gate::user_has_tier_access($user_id, $tier, $course_id);
    }

    // Whether the course sells as a one-time purchase; the single choke point for the kill switch.
    public static function has_purchase_option(int $course_id): bool {
        if (!self::purchase_feature_enabled()) return false;
        return self::purchase_price_cents($course_id) > 0;
    }

    public static function purchase_price_cents(int $course_id): int {
        return (int) get_post_meta($course_id, '_bhc_purchase_price_cents', true);
    }

    // Tier access ("allowed in?") and drip ("open yet?") must both pass.
    public static function user_can_access_lesson(int $user_id, int $lesson_id): bool {
        $course_id = BHC_PostTypes::course_for_lesson($lesson_id);
        if (!$course_id) return true; // orphaned lesson
        if (!self::user_can_access_course($user_id, $course_id)) return false;
        return self::lesson_is_open($user_id, $lesson_id);
    }

    /* ---------------- drip scheduling ---------------- */

    // Either a relative delay from enrollment or a fixed date (not both); neither means open.
    public static function drip_after_days(int $lesson_id): ?int {
        $v = get_post_meta($lesson_id, '_bhc_available_after_days', true);
        return $v === '' ? null : max(0, (int) $v);
    }

    public static function drip_on_date(int $lesson_id): ?string {
        $v = get_post_meta($lesson_id, '_bhc_available_on_date', true);
        return $v ?: null;
    }

    // WHY: the fixed date is resolved in wp_timezone(); enrolled_at is MySQL UTC, so it's
    // compared against time(), not current_time().
    private static function on_date_timestamp_utc(string $on_date): int {
        try {
            $dt = new DateTime($on_date . ' 00:00:00', wp_timezone());
            return $dt->getTimestamp();
        } catch (Exception $e) {
            return strtotime($on_date . ' 00:00:00'); // malformed date: best-effort fallback
        }
    }

    public static function lesson_is_open(int $user_id, int $lesson_id): bool {
        $after_days = self::drip_after_days($lesson_id);
        $on_date = self::drip_on_date($lesson_id);
        if ($after_days === null && $on_date === null) return true; // no drip rule

        if ($on_date !== null) {
            return time() >= self::on_date_timestamp_utc($on_date);
        }

        // No enrollment yet means no clock to count from, so fail open.
        $course_id = BHC_PostTypes::course_for_lesson($lesson_id);
        $enrolled_at = BHC_Progress::enrolled_at($user_id, $course_id);
        if (!$enrolled_at) return true;

        return time() >= (strtotime($enrolled_at . ' UTC') + $after_days * DAY_IN_SECONDS);
    }

    // Human-readable reason a drip-locked lesson is locked.
    public static function drip_notice(int $user_id, int $lesson_id): string {
        $on_date = self::drip_on_date($lesson_id);
        if ($on_date !== null) {
            return 'This lesson opens on ' . esc_html(date_i18n(get_option('date_format'), self::on_date_timestamp_utc($on_date))) . '.';
        }
        $after_days = self::drip_after_days($lesson_id);
        $course_id = BHC_PostTypes::course_for_lesson($lesson_id);
        $enrolled_at = BHC_Progress::enrolled_at($user_id, $course_id);
        if ($after_days !== null && $enrolled_at) {
            $opens = strtotime($enrolled_at . ' UTC') + $after_days * DAY_IN_SECONDS;
            return 'This lesson opens ' . esc_html(human_time_diff(time(), $opens)) . ' from now.';
        }
        return 'This lesson isn\'t available yet.';
    }

    // Builds the purchase notice directly, since BHM_Gate's tier-shaped notice has no CTA
    // for a purchase-only course.
    public static function render_paywall_notice(int $course_id): string {
        $tier_notice = '';
        if (class_exists('BHM_Gate')) {
            $required_tier = self::required_tier($course_id);
            // A benefit-gated course has no single tier for BHM_Gate's notice, so skip it.
            if ($required_tier) {
                $tier_notice = BHM_Gate::render_paywall_notice($required_tier);
            }
        }

        $buy_notice = '';
        if (self::has_purchase_option($course_id) && class_exists('BH_Commerce') && BH_Commerce::available()) {
            $product_id = (int) get_post_meta($course_id, '_bhm_purchase_wc_product_id', true);
            if ($product_id) {
                // wc_price(): BHM_Money::price() has no currency symbol. Safe to echo.
                $price_display = function_exists('wc_price') ? wc_price(self::purchase_price_cents($course_id) / 100) : '$' . number_format(self::purchase_price_cents($course_id) / 100, 2);
                $buy_url = function_exists('wc_get_cart_url') ? wc_get_cart_url() . '?add-to-cart=' . $product_id : '';
                if ($buy_url) {
                    // "Or ... no tier required" only reads right next to a tier offer.
                    $title = $tier_notice
                        ? 'Or buy this course outright for <strong>' . $price_display . '</strong> — yours to keep, no supporter tier required.'
                        : 'Get this course for <strong>' . $price_display . '</strong> — buy once, yours to keep.';
                    $buy_notice = '<div class="bhc-paywall bhc-paywall-purchase">'
                        . '<p class="bhc-paywall-title">' . $title . '</p>'
                        . '<a class="bhc-btn bhc-btn-buy-once" href="' . esc_url($buy_url) . '">Buy once — ' . $price_display . '</a>'
                        . '</div>';
                }
            }
        }

        if ($tier_notice || $buy_notice) {
            // Both together read as two options ("Or buy it outright").
            return $tier_notice . $buy_notice;
        }
        return '<div class="bhc-paywall"><p>This content requires supporter access.</p></div>';
    }
}
