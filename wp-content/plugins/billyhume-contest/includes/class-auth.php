<?php
if (!defined('ABSPATH')) exit;

class BH_Auth {
    public static function init() {
        add_shortcode('bh_contest_player', [self::class, 'render']);
    }

    public static function render($atts) {
        $atts = shortcode_atts(['contest' => ''], $atts, 'bh_contest_player');
        $raw  = trim((string) $atts['contest']);
        $cid  = 0;

        if ($raw !== '') {
            $cid = BH_Helpers::resolve_contest($raw);
            if (!$cid && current_user_can('edit_posts')) {
                // Only shown to logged-in editors — visitors just see nothing
                // rather than a broken-looking box.
                return '<p style="padding:12px 16px;background:#3a2a00;color:#ffcf6b;border-radius:6px;'
                     . 'font-family:sans-serif;font-size:13px;">'
                     . '<strong>BillyHume Contest:</strong> no contest matches <code>' . esc_html($raw) . '</code>. '
                     . 'Check Contests → the Shortcode column for the exact value. (Only editors see this notice.)'
                     . '</p>';
            }
        }

        static $i = 0; $i++;
        return '<div class="bh-player-root" id="bh-player-root-' . $i . '" data-contest="' . esc_attr($cid ?: '') . '"></div>';
    }

    public static function register_routes() {
        $open = ['permission_callback' => '__return_true'];
        $auth = ['permission_callback' => 'is_user_logged_in'];
        register_rest_route('bh/v1', '/session',  ['methods' => 'GET',  'callback' => [self::class, 'session']]  + $open);
        register_rest_route('bh/v1', '/login',    ['methods' => 'POST', 'callback' => [self::class, 'login']]    + $open);
        register_rest_route('bh/v1', '/register', ['methods' => 'POST', 'callback' => [self::class, 'register']] + $open);
        register_rest_route('bh/v1', '/logout',   ['methods' => 'POST', 'callback' => [self::class, 'logout']]   + $open);
        // Lets the submit form pre-fill whatever profile data registration
        // already captured, so a returning submitter isn't asked twice.
        register_rest_route('bh/v1', '/profile',  ['methods' => 'GET',  'callback' => [self::class, 'profile']]  + $auth);
    }

    private static function ip() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
    }

    // Returns a nonce valid for the *current* auth cookie. The client calls
    // this right after login/logout, because a nonce minted in the same
    // request as wp_set_auth_cookie() would not yet match the new session.
    public static function session() {
        return new WP_REST_Response([
            'logged_in' => is_user_logged_in(),
            'nonce'     => wp_create_nonce('wp_rest'),
        ], 200);
    }

    public static function login($req) {
        $user = wp_signon([
            'user_login'    => $req->get_param('username'),
            'user_password' => (string) $req->get_param('password'),
            'remember'      => true,
        ], is_ssl());

        if (is_wp_error($user)) {
            return new WP_Error('login_failed', 'Invalid username or password.', ['status' => 401]);
        }
        return new WP_REST_Response(['success' => true], 200);
    }

    public static function register($req) {
        // Honeypot: bots fill every field; humans never see this one.
        if (!empty($req->get_param('website'))) {
            return new WP_REST_Response(['success' => true], 200); // fake success
        }

        // Per-IP throttle via transient.
        $key = 'bh_reg_' . md5(self::ip());
        $n   = (int) get_transient($key);
        if ($n >= BH_REG_THROTTLE) {
            return new WP_Error('throttled', 'Too many sign-up attempts. Please try again in an hour.', ['status' => 429]);
        }
        set_transient($key, $n + 1, HOUR_IN_SECONDS);

        $user  = sanitize_user($req->get_param('username'), true);
        $email = sanitize_email($req->get_param('email'));
        $pass  = (string) $req->get_param('password');

        if ($user === '' || !is_email($email) || strlen($pass) < 8) {
            return new WP_Error('reg_failed', 'Enter a valid username, email, and an 8+ character password.', ['status' => 400]);
        }
        if (username_exists($user) || email_exists($email)) {
            return new WP_Error('reg_failed', 'That username or email is already taken.', ['status' => 400]);
        }

        $id = wp_create_user($user, $pass, $email);
        if (is_wp_error($id)) {
            return new WP_Error('reg_failed', 'Could not create the account. Please try again.', ['status' => 400]);
        }

        // All optional at signup — a pure voter never has to fill these.
        // Anything left blank here can still be collected at submit time.
        $profile_fields = BH_Profiles::from_request($req);
        if ($profile_fields) BH_Profiles::save($id, $profile_fields);

        wp_set_current_user($id);
        wp_set_auth_cookie($id, true, is_ssl());
        return new WP_REST_Response(['success' => true], 200);
    }

    public static function profile() {
        return new WP_REST_Response(['success' => true, 'profile' => BH_Profiles::get(get_current_user_id())], 200);
    }

    public static function logout() {
        wp_logout();
        return new WP_REST_Response(['success' => true], 200);
    }
}
