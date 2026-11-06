<?php
if (!defined('ABSPATH')) exit;

/**
 * Test double for WooCommerce Subscriptions via BH_Commerce's filter seams. Off by default
 * (toggled in Debug Tools). BHM_FakeSubscription is duck-typed to the WC_Subscription methods used.
 */
class BHM_MockCommerce {
    const OPTION = 'bhm_mock_subscriptions_enabled';
    const STORE_OPTION = 'bhm_mock_subscription_store';

    public static function init(): void {
        if (!self::is_enabled()) return;
        add_filter('bh_commerce_has_subscriptions', '__return_true');
        add_filter('bh_commerce_get_subscription', [self::class, 'resolve_subscription'], 10, 2);
    }

    public static function is_enabled(): bool {
        return (bool) get_option(self::OPTION);
    }

    public static function enable(): void { update_option(self::OPTION, 1); }
    public static function disable(): void { update_option(self::OPTION, 0); }

    /** @return array<int, array<string, mixed>> */
    private static function store(): array {
        $store = get_option(self::STORE_OPTION, []);
        return is_array($store) ? $store : [];
    }

    /** @param array<int, array<string, mixed>> $store */
    private static function save_store($store): void {
        update_option(self::STORE_OPTION, $store);
    }

    // High sentinel start so fake ids never collide with real wc_subscription_ids.
    /** @param array<int, array<string, mixed>> $store */
    private static function next_id($store): int {
        return $store ? (max(array_keys($store)) + 1) : 900001;
    }

    public static function create(int $user_id, int $product_id): int {
        $store = self::store();
        $id = self::next_id($store);
        $store[$id] = ['id' => $id, 'customer_id' => (int) $user_id, 'product_id' => (int) $product_id, 'status' => 'active'];
        self::save_store($store);
        return $id;
    }

    /** @return array<string, mixed>|null */
    public static function get(int $id): ?array {
        $store = self::store();
        return isset($store[$id]) ? $store[$id] : null;
    }

    public static function set_status(int $id, string $status): void {
        $store = self::store();
        if (isset($store[$id])) {
            $store[$id]['status'] = $status;
            self::save_store($store);
        }
    }

    // Only steps in when the real lookup returned nothing.
    /**
     * @param mixed $subscription
     * @return mixed
     */
    public static function resolve_subscription($subscription, int $subscription_id) {
        if ($subscription) return $subscription;
        $data = self::get((int) $subscription_id);
        return $data ? new BHM_FakeSubscription($data) : null;
    }
}

class BHM_FakeSubscription {
    /** @var array<string, mixed> */
    private $data;

    /** @param array<string, mixed> $data */
    public function __construct($data) { $this->data = $data; }

    public function get_id(): int { return (int) $this->data['id']; }
    public function get_customer_id(): int { return (int) $this->data['customer_id']; }
    public function get_user_id(): int { return (int) $this->data['customer_id']; }
    public function get_status(): string { return $this->data['status']; }

    /** @return array<int, BHM_FakeSubscriptionItem> */
    public function get_items(): array {
        return [new BHM_FakeSubscriptionItem((int) $this->data['product_id'])];
    }

    public function can_be_updated_to(string $status): bool {
        return in_array($status, ['active', 'on-hold', 'cancelled', 'expired'], true) && $status !== $this->data['status'];
    }

    // Fires the same lifecycle handlers a real status change would.
    public function update_status(string $status, string $note = ''): void {
        BHM_MockCommerce::set_status($this->data['id'], $status);
        $this->data['status'] = $status;
        if ($status === 'on-hold') {
            BHM_Products::on_subscription_paused($this);
        } elseif (in_array($status, ['cancelled', 'expired'], true)) {
            BHM_Products::on_subscription_ended($this);
        } elseif ($status === 'active') {
            BHM_Products::on_subscription_active($this);
        }
    }
}

class BHM_FakeSubscriptionItem {
    private int $product_id;
    public function __construct(int $product_id) { $this->product_id = $product_id; }
    public function get_product_id(): int { return $this->product_id; }
    public function get_quantity(): int { return 1; }
}
