<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared registration/login/session/email-verification system. Same
 * proven mechanics as bh-contest's original BH_Auth (brute-force
 * lockout, per-IP registration throttling, hashed verification tokens
 * with a grandfather clause for pre-existing accounts) — generalized so
 * any ecosystem plugin's sign-up form can point at these endpoints
 * instead of building its own.
 */
class BHI_Auth {
    const REG_THROTTLE = 5;       // max registrations per IP per hour
    const LOGIN_MAX_FAILS = 5;    // failed logins (per username+IP) before a 15-minute lockout

    public static function init() {
        add_action('admin_post_nopriv_bhi_verify_email', [self::class, 'verify_email_action']);
        add_action('admin_post_bhi_verify_email', [self::class, 'verify_email_action']);
    }

    public static function register_routes() {
        $open = ['permission_callback' => '__return_true'];
        $auth = ['permission_callback' => 'is_user_logged_in'];
        register_rest_route('bhi/v1', '/session',  ['methods' => 'GET',  'callback' => [self::class, 'session']]  + $open);
        register_rest_route('bhi/v1', '/login',    ['methods' => 'POST', 'callback' => [self::class, 'login']]    + $open);
        register_rest_route('bhi/v1', '/register', ['methods' => 'POST', 'callback' => [self::class, 'register']] + $open);
        register_rest_route('bhi/v1', '/logout',   ['methods' => 'POST', 'callback' => [self::class, 'logout']]   + $open);
        register_rest_route('bhi/v1', '/profile',  ['methods' => 'GET',  'callback' => [self::class, 'profile']]  + $auth);
        register_rest_route('bhi/v1', '/resend-verification', ['methods' => 'POST', 'callback' => [self::class, 'resend_verification']] + $auth);
    }

    private static function ip() {
        return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function session() {
        if (!is_user_logged_in()) return new WP_REST_Response(['success' => true, 'loggedIn' => false], 200);
        $user = wp_get_current_user();
        return new WP_REST_Response([
            'success' => true, 'loggedIn' => true,
            'username' => $user->user_login, 'verified' => self::is_email_verified($user->ID),
        ], 200);
    }

    public static function login($req) {
        $username = (string) $req->get_param('username');
        $fail_key = 'bhi_login_fail_' . md5(strtolower($username) . '|' . self::ip());

        if ((int) get_transient($fail_key) >= self::LOGIN_MAX_FAILS) {
            return new WP_Error('locked_out', 'Too many failed attempts. Please try again in 15 minutes.', ['status' => 429]);
        }

        $user = wp_signon(['user_login' => $username, 'user_password' => (string) $req->get_param('password'), 'remember' => true], is_ssl());
        if (is_wp_error($user)) {
            set_transient($fail_key, (int) get_transient($fail_key) + 1, 15 * MINUTE_IN_SECONDS);
            return new WP_Error('login_failed', 'Invalid username or password.', ['status' => 401]);
        }

        delete_transient($fail_key);
        return new WP_REST_Response(['success' => true], 200);
    }

    public static function register($req) {
        $throttle_key = 'bhi_reg_throttle_' . md5(self::ip());
        if ((int) get_transient($throttle_key) >= self::REG_THROTTLE) {
            return new WP_Error('throttled', 'Too many registrations from this connection. Please try again later.', ['status' => 429]);
        }

        $username = sanitize_user((string) $req->get_param('username'));
        $email = sanitize_email((string) $req->get_param('email'));
        $password = (string) $req->get_param('password');

        if (!$username || !$email || !is_email($email) || strlen($password) < 8) {
            return new WP_Error('invalid', 'A valid username, email, and a password of at least 8 characters are required.', ['status' => 400]);
        }
        if (username_exists($username)) return new WP_Error('username_taken', 'That username is already in use.', ['status' => 409]);
        if (email_exists($email)) return new WP_Error('email_taken', 'That email is already registered.', ['status' => 409]);

        $id = wp_create_user($username, $password, $email);
        if (is_wp_error($id)) return new WP_Error('create_failed', 'Could not create account.', ['status' => 500]);

        set_transient($throttle_key, (int) get_transient($throttle_key) + 1, HOUR_IN_SECONDS);

        $profile_fields = BHI_Profiles::from_request($req);
        if ($profile_fields) BHI_Profiles::save($id, $profile_fields);

        self::send_verification_email($id, $email, $username);

        wp_set_current_user($id);
        wp_set_auth_cookie($id, true, is_ssl());
        return new WP_REST_Response(['success' => true], 200);
    }

    public static function logout() {
        wp_logout();
        return new WP_REST_Response(['success' => true], 200);
    }

    public static function profile() {
        return new WP_REST_Response(['success' => true, 'profile' => BHI_Profiles::get(get_current_user_id())], 200);
    }

    /* ---------- email verification ---------- */

    public static function is_email_verified($uid) {
        if (get_user_meta($uid, '_bhi_email_verified', true) === '1') return true;
        // Grandfather clause: an account that predates this system (or
        // was created directly in wp-admin, bypassing this flow
        // entirely) never had a token issued at all — distinct from a
        // genuinely new, unverified account that has one sitting unused.
        // Without this, turning on verification would instantly lock out
        // every pre-existing user rather than only gating new signups.
        return get_user_meta($uid, '_bhi_email_verify_token', true) === ''
            && get_user_meta($uid, '_bhi_email_verify_sent', true) === '';
    }

    private static function send_verification_email($uid, $email, $username) {
        $token = wp_generate_password(32, false, false);
        update_user_meta($uid, '_bhi_email_verify_token', wp_hash($token));
        update_user_meta($uid, '_bhi_email_verify_sent', time());

        $verify_url = add_query_arg(['action' => 'bhi_verify_email', 'uid' => $uid, 'token' => $token], admin_url('admin-post.php'));
        $subject = 'Confirm your email — ' . get_bloginfo('name');
        $body = "Hi {$username},\n\nOne more step: confirm this is really your email.\n\n{$verify_url}\n\nThis link works for 48 hours. If you didn't sign up for this, you can ignore it.";
        wp_mail($email, $subject, $body);
    }

    public static function verify_email_action() {
        $uid   = (int) ($_GET['uid'] ?? 0);
        $token = (string) ($_GET['token'] ?? '');
        $stored  = get_user_meta($uid, '_bhi_email_verify_token', true);
        $sent_at = (int) get_user_meta($uid, '_bhi_email_verify_sent', true);

        $valid = $uid && $token !== '' && $stored !== ''
            && hash_equals($stored, wp_hash($token))
            && (time() - $sent_at) < 48 * HOUR_IN_SECONDS;

        if ($valid) {
            update_user_meta($uid, '_bhi_email_verified', '1');
            delete_user_meta($uid, '_bhi_email_verify_token');
        }

        $redirect_to = wp_get_referer() ?: home_url('/');
        wp_safe_redirect(add_query_arg('bhi_verified', $valid ? '1' : '0', $redirect_to));
        exit;
    }

    public static function resend_verification() {
        $uid = get_current_user_id();
        if (self::is_email_verified($uid)) return new WP_REST_Response(['success' => true, 'already_verified' => true], 200);

        $throttle_key = 'bhi_resend_verify_' . $uid;
        if (get_transient($throttle_key)) {
            return new WP_Error('throttled', 'Please wait a couple of minutes before requesting another email.', ['status' => 429]);
        }
        set_transient($throttle_key, 1, 2 * MINUTE_IN_SECONDS);

        $user = get_userdata($uid);
        self::send_verification_email($uid, $user->user_email, $user->user_login);
        return new WP_REST_Response(['success' => true], 200);
    }
}
