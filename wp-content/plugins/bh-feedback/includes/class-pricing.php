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
        'quick_take' => ['label' => 'Quick take', 'description' => 'A short, honest first-impression review.', 'cents' => 500],
        'detailed' => ['label' => 'Detailed review', 'description' => 'A full written breakdown — structure, mix, what\'s working and what isn\'t.', 'cents' => 1500],
    ];

    public static function label_for($tier) {
        return self::TIERS[$tier]['label'] ?? $tier;
    }

    public static function cents_for($tier) {
        $cents = self::TIERS[$tier]['cents'] ?? 0;
        return (int) apply_filters('bhf_tier_price_cents', $cents, $tier);
    }

    public static function is_valid_tier($tier) {
        return isset(self::TIERS[$tier]);
    }
}
