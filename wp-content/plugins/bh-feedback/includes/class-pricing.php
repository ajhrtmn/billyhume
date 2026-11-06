<?php
if (!defined('ABSPATH')) exit;

/**
 * The two tiers ('quick_take', 'detailed') with flat, filterable prices.
 */
class BHF_Pricing {
    const TIERS = [
        'quick_take' => ['label' => 'Quick take', 'description' => 'A short, honest first-impression review.', 'cents' => 500, 'turnaround_days' => 3],
        'detailed' => ['label' => 'Detailed review', 'description' => 'A full written breakdown — structure, mix, what\'s working and what isn\'t.', 'cents' => 1500, 'turnaround_days' => 7],
    ];

    public static function label_for(string $tier): string {
        return self::TIERS[$tier]['label'] ?? $tier;
    }

    // A rough, filterable turnaround ballpark, not an SLA.
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
