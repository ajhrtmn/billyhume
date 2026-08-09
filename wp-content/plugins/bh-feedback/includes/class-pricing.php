<?php
if (!defined('ABSPATH')) exit;

/**
 * v1's two tiers, per ROADMAP-feedback-and-courses-v2.md's own scoping
 * (the third, timestamped-audio-annotation tier, is explicitly
 * deferred — see bh-feedback.php's top docblock). Flat, filterable
 * prices rather than a full pricing-editor UI — the roadmap's own v1
 * scope is "ship the two simple tiers," not "build a merchandising
 * page for them."
 */
class BHF_Pricing {
    const TIERS = [
        'quick_take' => ['label' => 'Quick take', 'description' => 'A short, honest first-impression review.', 'cents' => 500, 'turnaround_days' => 3],
        'detailed' => ['label' => 'Detailed review', 'description' => 'A full written breakdown — structure, mix, what\'s working and what isn\'t.', 'cents' => 1500, 'turnaround_days' => 7],
    ];

    public static function label_for(string $tier): string {
        return self::TIERS[$tier]['label'] ?? $tier;
    }

    // Audit fix (2026-07-25): the submission flow never set a turnaround-
    // time expectation anywhere, so a paying user had no baseline for
    // what "normal wait" looked like. Deliberately a rough, filterable
    // ballpark rather than an SLA guarantee — this plugin has no queue-
    // depth-aware estimator, so promising anything more precise would be
    // its own "accept-and-hope" problem.
    public static function turnaround_days_for(string $tier): int {
        $days = self::TIERS[$tier]['turnaround_days'] ?? 5;
        return (int) apply_filters('bhf_tier_turnaround_days', $days, $tier);
    }

    public static function cents_for(string $tier): int {
        $cents = self::TIERS[$tier]['cents'] ?? 0;
        return (int) apply_filters('bhf_tier_price_cents', $cents, $tier);
    }

    public static function is_valid_tier(string $tier): bool {
        return isset(self::TIERS[$tier]);
    }
}
