<?php
if (!defined('ABSPATH')) exit;

/**
 * Registry BH_Commerce dispatches through. Only 'woocommerce' is
 * implemented. To add an adapter: implement BH_CommerceProvider, call
 * register($key, $provider) from its plugin (class_exists()-guarded), and
 * select it via the 'bh_commerce_active_provider_key' filter.
 * One provider is active at a time.
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
        if (!in_array($key, self::REGISTERABLE_KEYS, true)) return; // unrecognized slot
        self::$providers[$key] = $provider;
    }

    public static function get(string $key): ?BH_CommerceProvider {
        return self::$providers[sanitize_key($key)] ?? null;
    }

    /**
     * Provider every BH_Commerce call dispatches to (filterable). Never
     * null: falls back to WooCommerce so callers need no null checks.
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
