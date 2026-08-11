<?php
if (!defined('ABSPATH')) exit;

/**
 * BH_CommerceProviders — the registry BH_Commerce dispatches through.
 * Same keyed-registry shape bh-social's BHSO_PlatformRegistry already
 * uses for a "several named implementations of one interface" problem
 * (get('youtube')/get('twitch')/etc.) — not reinvented, just the same
 * pattern applied to commerce.
 *
 * 'woocommerce' is the only key with a real, working class registered
 * today (BH_WooCommerceProvider). 'shopify' / 'stripe' / 'squarespace'
 * are named, anticipated slots — not fake implementations. A future
 * adapter for any of them is:
 *   1. A new class implementing BH_CommerceProvider (class-commerce-
 *      provider.php has the full contract).
 *   2. One call to BH_CommerceProviders::register($key, new
 *      YourClass()) — typically from that adapter's own plugin
 *      bootstrap, class_exists()-guarded the same way every other
 *      cross-plugin touch in this ecosystem is.
 *   3. Optionally, apply_filters('bh_commerce_active_provider_key', ...)
 *      to switch which registered provider is active — defaults to
 *      'woocommerce', the one provider always guaranteed to be
 *      registered (its own available() still correctly reports false
 *      until WooCommerce itself is actually installed, same as today).
 *
 * Only one provider is ever "active" at a time (no multi-provider
 * split-cart scenario) — this is a swap point, not a marketplace
 * aggregator.
 */
class BH_CommerceProviders {
    const REGISTERABLE_KEYS = ['woocommerce', 'shopify', 'stripe', 'squarespace'];

    /** @var array<string, BH_CommerceProvider> */
    private static $providers = [];

    public static function init(): void {
        self::register('woocommerce', new BH_WooCommerceProvider());
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
    }

    public static function register(string $key, BH_CommerceProvider $provider): void {
        $key = sanitize_key($key);
        if (!in_array($key, self::REGISTERABLE_KEYS, true)) return; // not a recognized slot — see this class's own docblock before adding a new one
        self::$providers[$key] = $provider;
    }

    public static function get(string $key): ?BH_CommerceProvider {
        return self::$providers[sanitize_key($key)] ?? null;
    }

    /**
     * The provider every BH_Commerce call actually dispatches to.
     * Filterable (never hard-coded past 'woocommerce') so a future
     * adapter plugin can switch the active provider without editing
     * this file — same "mockable/swappable, never hard-wired" posture
     * BH_Commerce's has_subscriptions()/get_subscription() already use.
     * Falls back to BH_WooCommerceProvider directly (not null) if the
     * filtered key doesn't resolve to a registered provider — BH_Commerce's
     * callers have never had to null-check its return values, and this
     * refactor's whole point is that they still don't.
     */
    public static function active(): BH_CommerceProvider {
        $key = (string) apply_filters('bh_commerce_active_provider_key', 'woocommerce');
        return self::get($key) ?? self::get('woocommerce') ?? new BH_WooCommerceProvider();
    }

    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register_debug_section($tools): array {
        $tools['bh-commerce-providers'] = [
            'label'  => 'Commerce Providers',
            'render' => [self::class, 'render_debug_section'],
            'safe_in_production' => true,
            'group'  => OUS_Debug::GROUP_MONITORING,
        ];
        return $tools;
    }

    public static function render_debug_section(): void {
        $active_key = (string) apply_filters('bh_commerce_active_provider_key', 'woocommerce');
        echo '<p class="description">Which commerce provider is active — a swap point (see BH_CommerceProviders\' own docblock), not a settings screen.</p>';
        echo '<div class="bhy-table-wrap"><table class="widefat striped"><thead><tr><th>Key</th><th>Registered class</th><th>Status</th></tr></thead><tbody>';
        foreach (self::REGISTERABLE_KEYS as $key) {
            $provider = self::get($key);
            $class_cell = $provider ? '<code>' . esc_html(get_class($provider)) . '</code>' : '<span class="description">— not registered —</span>';
            if ($key === $active_key && $provider) {
                $status = $provider->available() ? '<span class="bhy-badge bhy-badge-success">Active &amp; available</span>' : '<span class="bhy-badge bhy-badge-neutral">Active, not configured</span>';
            } elseif ($provider) {
                $status = '<span class="description">Registered, not active</span>';
            } else {
                $status = '<span class="description">Reserved slot — no adapter installed</span>';
            }
            echo '<tr><td><code>' . esc_html($key) . '</code></td><td>' . $class_cell . '</td><td>' . $status . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
