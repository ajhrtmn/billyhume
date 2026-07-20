<?php
if (!defined('ABSPATH')) exit;

/**
 * A real, working test double for WooCommerce Subscriptions — plugs
 * into the filter seams BH_Commerce::has_subscriptions()/
 * get_subscription() now expose (own-ur-shit/includes/class-commerce.php),
 * per AJ's own standing architecture rule: no plugin here should be so
 * hard-wired to an external dependency that it can't be mocked or
 * swapped. Before this, on_subscription_active()/on_subscription_ended()/
 * on_subscription_paused() (class-products.php) and the front-end
 * pause/resume UI (class-frontend.php) were completely unreachable for
 * testing without the real, paid WooCommerce Subscriptions extension
 * installed.
 *
 * Off by default (gated on a real option, toggled from Debug Tools) —
 * real production behavior on a site WITHOUT this extension is
 * completely unaffected unless an admin explicitly turns this on to
 * test with.
 *
 * BHM_FakeSubscription below is duck-typed against the exact same
 * method surface BH_Commerce::normalize_subscription()/class-products.php/
 * class-frontend.php already call on a real WC_Subscription — nothing
 * here needed to change to accept it (normalize_subscription() checks
 * method_exists(), never instanceof WC_Subscription).
 */
class BHM_MockCommerce {
    const OPTION = 'bhm_mock_subscriptions_enabled';
    const STORE_OPTION = 'bhm_mock_subscription_store';

    public static function init() {
        if (!self::is_enabled()) return;
        add_filter('bh_commerce_has_subscriptions', '__return_true');
        add_filter('bh_commerce_get_subscription', [self::class, 'resolve_subscription'], 10, 2);
    }

    public static function is_enabled() {
        return (bool) get_option(self::OPTION);
    }

    public static function enable() { update_option(self::OPTION, 1); }
    public static function disable() { update_option(self::OPTION, 0); }

    private static function store() {
        $store = get_option(self::STORE_OPTION, []);
        return is_array($store) ? $store : [];
    }

    private static function save_store($store) {
        update_option(self::STORE_OPTION, $store);
    }

    // Real IDs start low; fake ones start at a high sentinel so they
    // never collide with a real wc_subscription_id if the real
    // extension is ever installed alongside test data created here.
    private static function next_id($store) {
        return $store ? (max(array_keys($store)) + 1) : 900001;
    }

    public static function create($user_id, $product_id) {
        $store = self::store();
        $id = self::next_id($store);
        $store[$id] = ['id' => $id, 'customer_id' => (int) $user_id, 'product_id' => (int) $product_id, 'status' => 'active'];
        self::save_store($store);
        return $id;
    }

    public static function get($id) {
        $store = self::store();
        return isset($store[$id]) ? $store[$id] : null;
    }

    public static function set_status($id, $status) {
        $store = self::store();
        if (isset($store[$id])) {
            $store[$id]['status'] = $status;
            self::save_store($store);
        }
    }

    public static function delete($id) {
        $store = self::store();
        unset($store[$id]);
        self::save_store($store);
    }

    // bh_commerce_get_subscription filter callback — only steps in when
    // BH_Commerce's real wcs_get_subscription() call came back empty
    // (never overrides a genuinely real subscription object).
    public static function resolve_subscription($subscription, $subscription_id) {
        if ($subscription) return $subscription;
        $data = self::get((int) $subscription_id);
        return $data ? new BHM_FakeSubscription($data) : null;
    }
}

class BHM_FakeSubscription {
    private $data;

    public function __construct($data) { $this->data = $data; }

    public function get_id() { return (int) $this->data['id']; }
    public function get_customer_id() { return (int) $this->data['customer_id']; }
    public function get_user_id() { return (int) $this->data['customer_id']; }
    public function get_status() { return $this->data['status']; }

    // Matches the shape BH_Commerce::normalize_subscription() reads —
    // one item, this fake sub's own product, quantity 1 (a supporter
    // tier subscription is never a multi-quantity cart line in practice).
    public function get_items() {
        return [new BHM_FakeSubscriptionItem((int) $this->data['product_id'])];
    }

    public function can_be_updated_to($status) {
        return in_array($status, ['active', 'on-hold', 'cancelled', 'expired'], true) && $status !== $this->data['status'];
    }

    // Real side effect, not just a status-string update — routes
    // through the exact same BHM_Products handlers a real WooCommerce
    // Subscriptions status-change webhook would fire, so entitlements
    // actually grant/revoke and notifications actually send.
    public function update_status($status, $note = '') {
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
    private $product_id;
    public function __construct($product_id) { $this->product_id = $product_id; }
    public function get_product_id() { return $this->product_id; }
    public function get_quantity() { return 1; }
}
