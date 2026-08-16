<?php
if (!defined('ABSPATH')) exit;

/**
 * Optional tier-gating for a course — the exact consumer
 * bh-monetization-woo's own class-gate.php docblock anticipated:
 * "the eventual learning-management/courses plugin gates its own
 * lesson/course post type the exact same way — set
 * `_bhm_required_tier` on its own CPT and call
 * `BHM_Gate::user_has_tier_access()`."
 *
 * Zero changes needed to bh-monetization-woo to support this. Checked
 * via class_exists() at call time (never at file-parse time), so this
 * plugin works completely fine with bh-monetization-woo absent —
 * courses are simply open in that case, same graceful-degradation
 * bh-streaming already demonstrates.
 */
class BHC_Gate {
    public static function init(): void {
        // Real gap an ecosystem-wide refund/revocation audit flagged:
        // bh-courses had zero awareness of bhm_entitlement_revoked — a
        // student who lost the tier gating a course they were mid-way
        // through got no signal at all beyond hitting the paywall again
        // next time they clicked into a lesson. Best-effort, not exact —
        // this doesn't re-derive BHM_Gate's own live access check, it
        // just tells a student their access to specific named courses
        // MAY have just changed, so they have a reason to look rather
        // than silently getting locked out mid-course with no context.
        add_action('bhm_entitlement_revoked', [self::class, 'maybe_notify_course_access_change'], 10, 5);
    }

    // scope 'account' is the only shape course gating ever checks
    // (user_can_access_course() -&gt; BHM_Gate::user_has_tier_access(),
    // itself account-wide, never per-track/release) — a revoked
    // per-object entitlement (a track/release purchase) has nothing to
    // do with course access and is skipped here.
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
            if (count($affected_titles) >= 3) break; // enough to name in a notification; a longer list just says "and more" below
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

    // Fine-grained alternative to required_tier() — set on a course
    // whose access should be sold as "any tier granting the 'courses'
    // benefit" rather than "any tier at or above a specific price rank"
    // (see BHM_Tiers::benefit_registry()'s own docblock for why those
    // are genuinely different questions). A course author picks ONE of
    // these two meta keys, not both — required_benefit(), when set,
    // takes priority in user_can_access_course() below.
    public static function required_benefit(int $course_id): string {
        $key = get_post_meta($course_id, '_bhm_required_benefit', true);
        return $key ? sanitize_key($key) : '';
    }

    // A lesson is gated by ITS course's tier, not its own — matches how
    // an individual track inherits its release's gating in bh-streaming
    // where relevant, and keeps authoring simple (set the tier once, on
    // the course).
    // Standing kill-switch for the whole one-time-purchase feature —
    // filter, not an admin-UI checkbox, matching this ecosystem's own
    // convention for "off in one line, no deploy" (bhm_extra_
    // entitlement_check, ous_debug_tools). Real reason this exists:
    // the feature touches actual money/entitlement logic and shipped
    // same-day as this docblock — `add_filter('bhc_course_purchase_
    // enabled', '__return_false')` in a must-use plugin or theme
    // functions.php disables it everywhere in one call: the admin UI
    // section stops rendering, the save handler stops syncing a WC
    // product, and this gate stops granting access via purchase
    // ownership (falls through to tier-only, same as before this
    // feature existed). Safe to flip off at any time before a real
    // purchase has happened; NOT retroactive once someone has actually
    // bought a course (see purchase_feature_enabled()'s own note).
    public static function purchase_feature_enabled(): bool {
        return (bool) apply_filters('bhc_course_purchase_enabled', true);
    }

    public static function user_can_access_course(int $user_id, int $course_id): bool {
        if (!class_exists('BHM_Gate')) return true; // bh-monetization-woo not active: nothing gates anything

        // One-time purchase check, deliberately FIRST and unconditional
        // — real bug avoided by reading BHM_Gate::user_has_tier_access()
        // before assuming this "just worked": that function returns
        // true immediately when no tier is required
        // (`if (!$required_tier) return true;`), meaning it NEVER
        // reaches its own purchase-fallback check for a course sold
        // purely as a one-time purchase with no tier set at all — the
        // exact main case this feature exists for. Checking ownership
        // here, independent of whichever gating model (or none) the
        // tier selects below are set to, is what actually makes "buy
        // this course outright, no tier required" work.
        //
        // Deliberately still checked even when purchase_feature_
        // enabled() is false: the toggle stops NEW purchases from being
        // configured/sold (UI + save handler below), it does not revoke
        // access someone already legitimately paid for — an entitlement
        // already in the database keeps working regardless of the
        // feature flag, matching how this ecosystem never wants a
        // config toggle to unilaterally break something already paid
        // for.
        if (BHM_Gate::user_owns_object($user_id, $course_id)) return true;

        $benefit = self::required_benefit($course_id);
        if ($benefit) {
            return BHM_Gate::user_has_benefit($user_id, $benefit, $course_id);
        }

        $tier = self::required_tier($course_id);
        return BHM_Gate::user_has_tier_access($user_id, $tier, $course_id);
    }

    // Whether this course has been configured to sell as a one-time
    // purchase at all — the catalog/portal CTA and lesson-download
    // eligibility both need this same yes/no without re-deriving it.
    // Also the single choke point for the kill switch: every UI
    // consumer of this (catalog badge, any future detail-page CTA)
    // inherits "off" automatically the moment purchase_feature_
    // enabled() is flipped, with zero changes needed at each call site.
    public static function has_purchase_option(int $course_id): bool {
        if (!self::purchase_feature_enabled()) return false;
        return self::purchase_price_cents($course_id) > 0;
    }

    public static function purchase_price_cents(int $course_id): int {
        return (int) get_post_meta($course_id, '_bhc_purchase_price_cents', true);
    }

    // Two independent checks: tier access (above) is "are you ALLOWED
    // into this course at all," drip scheduling is "has it opened up
    // for you YET" — a fully-paid student can still be waiting on a
    // lesson's release date. Both must pass.
    public static function user_can_access_lesson(int $user_id, int $lesson_id): bool {
        $course_id = BHC_PostTypes::course_for_lesson($lesson_id);
        if (!$course_id) return true; // orphaned lesson, nothing to gate against
        if (!self::user_can_access_course($user_id, $course_id)) return false;
        return self::lesson_is_open($user_id, $lesson_id);
    }

    /* ---------------- drip scheduling ---------------- */

    // A lesson can specify EITHER (not both) a relative delay from the
    // student's own enrollment date, or a fixed calendar date every
    // student sees at once — the same two shapes "self-paced" vs.
    // "scheduled cohort" courses actually need, rather than inventing a
    // third combined concept nobody asked for. Absence of both meta
    // keys means "open immediately," same default-open-unless-set
    // pattern _bhm_required_tier already uses.
    public static function drip_after_days(int $lesson_id): ?int {
        $v = get_post_meta($lesson_id, '_bhc_available_after_days', true);
        return $v === '' ? null : max(0, (int) $v);
    }

    public static function drip_on_date(int $lesson_id): ?string {
        $v = get_post_meta($lesson_id, '_bhc_available_on_date', true);
        return $v ?: null;
    }

    // QA fix: the whole file used to compare current_time('timestamp')
    // (WordPress's site-timezone-adjusted "now") against strtotime() of
    // raw stored strings, which PHP interprets in the server's default
    // timezone — not necessarily the site's configured one. On a site
    // whose WP timezone setting differs from the server's PHP default,
    // a drip date's boundary and "opens in N days" countdown could be
    // off by the difference between the two. Fixed by resolving
    // _bhc_available_on_date (a calendar date the admin picked in the
    // site's own timezone) against wp_timezone() explicitly, and by
    // comparing enrolled_at (a raw UTC `CURRENT_TIMESTAMP` value from
    // MySQL, same convention WordPress's own *_gmt columns use) against
    // raw UTC time() rather than the site-offset-adjusted current_time().
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
        if ($after_days === null && $on_date === null) return true; // no drip rule set at all

        if ($on_date !== null) {
            return time() >= self::on_date_timestamp_utc($on_date);
        }

        // Relative delay needs an enrollment date to count from — a
        // user who has never actually been recorded as enrolled (see
        // BHC_Progress::enroll_if_needed(), called from class-render.php
        // the moment access is confirmed) has no clock running yet, so
        // fails open here rather than permanently locking a lesson out
        // for someone the system never got a chance to enroll (e.g. an
        // admin previewing as themselves before any real visit).
        $course_id = BHC_PostTypes::course_for_lesson($lesson_id);
        $enrolled_at = BHC_Progress::enrolled_at($user_id, $course_id);
        if (!$enrolled_at) return true;

        return time() >= (strtotime($enrolled_at . ' UTC') + $after_days * DAY_IN_SECONDS);
    }

    // Human-readable reason a locked-by-drip lesson is locked, for the
    // front end to show instead of a generic "no access" message.
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

    public static function render_paywall_notice(int $course_id): string {
        if (class_exists('BHM_Gate')) {
            return BHM_Gate::render_paywall_notice(self::required_tier($course_id));
        }
        return '<div class="bhc-paywall"><p>This content requires supporter access.</p></div>';
    }
}
