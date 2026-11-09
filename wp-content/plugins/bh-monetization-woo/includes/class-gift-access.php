<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin "Gift access" box on anything sold as a one-time purchase (courses, releases,
 * tracks). A gift is a real $0 WooCommerce order marked completed, so access, enrollment,
 * the ledger and WooCommerce's own emails all run exactly as for a paid order.
 */
class BHM_GiftAccess {
    public static function init(): void {
        add_action('add_meta_boxes', [self::class, 'add_metabox'], 10, 2);
        add_action('admin_post_bhm_gift_access', [self::class, 'handle']);
        add_action('admin_notices', [self::class, 'notice']);
    }

    /** @param mixed $post */
    public static function add_metabox(string $post_type, $post): void {
        if (!$post instanceof \WP_Post || !current_user_can('manage_woocommerce')) return;
        if (!(int) get_post_meta($post->ID, '_bhm_purchase_wc_product_id', true)) return;
        add_meta_box('bhm_gift_access', 'Gift access', [self::class, 'render_metabox'], $post_type, 'side', 'default');
    }

    // WHY: a nested <form> inside the post editor is invalid, so fields use the form
    // attribute to submit a separate form printed in admin_footer.
    public static function render_metabox(\WP_Post $post): void {
        $form_id = 'bhm-gift-access-form';
        echo '<p><label>Recipient email<br><input type="email" name="email" form="' . $form_id . '" class="widefat"></label></p>';
        echo '<p><label>Message (optional, shown in their order email)<br><textarea name="message" rows="2" form="' . $form_id . '" class="widefat"></textarea></label></p>';
        echo '<p><button type="submit" class="button" form="' . $form_id . '">Gift access</button></p>';
        echo '<p class="description">Creates a $0 completed order, so it shows under WooCommerce &rarr; Orders and can be refunded or cancelled there to take access back. New emails get an account and a set-password email.</p>';
        add_action('admin_footer', function () use ($post, $form_id) {
            echo '<form id="' . $form_id . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" hidden>';
            echo '<input type="hidden" name="action" value="bhm_gift_access">';
            echo '<input type="hidden" name="post_id" value="' . (int) $post->ID . '">';
            wp_nonce_field('bhm_gift_access_' . $post->ID);
            echo '</form>';
        });
    }

    public static function handle(): void {
        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!current_user_can('manage_woocommerce') || !current_user_can('edit_post', $post_id) || !check_admin_referer('bhm_gift_access_' . $post_id)) {
            wp_die('Not allowed.', '', ['response' => 403]);
        }
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
        $result = is_email($email) ? self::gift($post_id, $email, $message, get_current_user_id()) : new WP_Error('email', 'Enter a valid email address.');

        $msg = is_wp_error($result) ? $result->get_error_message() : 'Gifted to ' . $email . ' (order #' . $result . ').';
        wp_safe_redirect(add_query_arg(['bhm_gift_msg' => rawurlencode($msg), 'bhm_gift_ok' => is_wp_error($result) ? 0 : 1], (string) get_edit_post_link($post_id, 'raw')));
        exit;
    }

    /** @return int|\WP_Error the completed order id */
    public static function gift(int $object_id, string $email, string $message, int $gifted_by) {
        $product = wc_get_product((int) get_post_meta($object_id, '_bhm_purchase_wc_product_id', true));
        if (!$product) return new WP_Error('no_product', 'This item has no purchase product yet. Set a price and save first.');

        $user = get_user_by('email', $email);
        if (!$user) {
            $user_id = self::create_account($email);
            if (is_wp_error($user_id)) return $user_id;
            $user = get_userdata($user_id);
        }
        if (class_exists('BHM_Gate') && BHM_Gate::user_owns_object($user->ID, $object_id)) {
            return new WP_Error('owned', $email . ' already has access.');
        }

        $order = wc_create_order(['customer_id' => $user->ID, 'created_via' => 'bhm_gift']);
        if (is_wp_error($order)) return $order;
        $order->add_product($product, 1, ['subtotal' => 0, 'total' => 0]);
        $order->set_billing_email($user->user_email);
        if ($message !== '') $order->set_customer_note($message);
        $order->calculate_totals(false);
        $giver = get_userdata($gifted_by);
        $order->add_order_note('Gifted by ' . ($giver ? $giver->display_name : 'an admin') . ' via Gift access.');
        $order->update_meta_data('_bhm_gift_access', 1);
        $order->save();
        $order->update_status('completed'); // runs the normal fulfilment hooks
        return $order->get_id();
    }

    /** @return int|\WP_Error */
    private static function create_account(string $email) {
        $base = sanitize_user(strtok($email, '@'), true) ?: 'member';
        $login = $base;
        for ($i = 2; username_exists($login); $i++) $login = $base . $i;
        $role = get_role('bh_member') ? 'bh_member' : get_option('default_role', 'subscriber');
        $user_id = wp_insert_user(['user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password(32), 'role' => $role]);
        if (is_wp_error($user_id)) return $user_id;
        wp_new_user_notification($user_id, null, 'user');
        return (int) $user_id;
    }

    public static function notice(): void {
        if (!isset($_GET['bhm_gift_msg']) || !current_user_can('manage_woocommerce')) return;
        $class = !empty($_GET['bhm_gift_ok']) ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . $class . ' is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['bhm_gift_msg']))) . '</p></div>';
    }
}
