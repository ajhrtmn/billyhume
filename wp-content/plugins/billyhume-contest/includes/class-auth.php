<?php
if (!defined('ABSPATH')) exit;

class BH_Auth {
    public static function init() {
        add_shortcode('bh_contest_player', [self::class, 'render']);
        // A verification link lives in an email, clicked from wherever —
        // admin-post's nopriv/priv pair is the standard WP mechanism for
        // "a plain link needs to do something and then redirect," which
        // is exactly this, rather than a REST route that would just
        // return raw JSON to someone's browser instead of taking them
        // anywhere.
        add_action('admin_post_nopriv_bh_verify_email', [self::class, 'verify_email_action']);
        add_action('admin_post_bh_verify_email', [self::class, 'verify_email_action']);
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
        $attrs = 'class="bh-player-root" id="bh-player-root-' . $i . '" data-contest="' . esc_attr($cid ?: '') . '"';
        if ($cid) {
            $payload = BH_Settings::contest_style_payload($cid);
            if ($payload) $attrs .= ' data-style-overrides="' . esc_attr(wp_json_encode($payload)) . '"';
        }
        return '<div ' . $attrs . '></div>';
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
        register_rest_route('bh/v1', '/resend-verification', ['methods' => 'POST', 'callback' => [self::class, 'resend_verification']] + $auth);
    }

    // Whether a user's email is confirmed — checked by vote()/submit()
    // in class-api.php before either is allowed to go through.
    //
    // Grandfathers in any account that predates this feature: those
    // accounts never went through send_verification_email(), so they
    // have no verify-token meta at all — distinct from a genuinely new,
    // unverified account, which has a token sitting there unused until
    // it's clicked. Without this distinction, deploying this feature
    // would instantly lock every existing voter and submitter out of a
    // live site the moment it goes live, which is a real problem, not
    // an edge case worth shrugging off.
    public static function is_email_verified($uid) {
        if (get_user_meta($uid, '_bh_email_verified', true) === '1') return true;
        $never_issued = get_user_meta($uid, '_bh_email_verify_token', true) === ''
            && get_user_meta($uid, '_bh_email_verify_sent', true) === '';
        return $never_issued;
    }

    private static function send_verification_email($uid, $email, $username) {
        // Not stored raw — even though the stakes here are lower than a
        // password reset (worst case of a leaked token is someone else
        // confirming an email they don't own), hashing costs nothing and
        // matches the security posture used everywhere else in this
        // plugin rather than making an exception for "this one's not a
        // big deal."
        $token = wp_generate_password(32, false, false);
        update_user_meta($uid, '_bh_email_verify_token', wp_hash($token));
        update_user_meta($uid, '_bh_email_verify_sent', time());

        $verify_url = add_query_arg([
            'action' => 'bh_verify_email',
            'uid'    => $uid,
            'token'  => $token,
        ], admin_url('admin-post.php'));

        $subject = 'Confirm your email — ' . get_bloginfo('name');
        $body = "Hi {$username},\n\nOne more step before you can vote or submit a track: confirm this is really your email.\n\n{$verify_url}\n\nThis link works for 48 hours. If you didn't sign up for this, you can just ignore it.";
        wp_mail($email, $subject, $body);
    }

    // The actual link-click handler — validates the token, marks the
    // account verified, and sends the person back to where they
    // presumably came from (best-effort; an email link carries no
    // referer, so this falls back to the homepage) with a query flag the
    // front end reacts to — see the bh_verified handling in player.js.
    public static function verify_email_action() {
        $uid   = (int) ($_GET['uid'] ?? 0);
        $token = (string) ($_GET['token'] ?? '');
        $stored  = get_user_meta($uid, '_bh_email_verify_token', true);
        $sent_at = (int) get_user_meta($uid, '_bh_email_verify_sent', true);

        $valid = $uid && $token !== '' && $stored !== ''
            && hash_equals($stored, wp_hash($token))
            && (time() - $sent_at) < 48 * HOUR_IN_SECONDS;

        if ($valid) {
            update_user_meta($uid, '_bh_email_verified', '1');
            delete_user_meta($uid, '_bh_email_verify_token');
        }

        $redirect_to = wp_get_referer() ?: home_url('/');
        wp_safe_redirect(add_query_arg('bh_verified', $valid ? '1' : '0', $redirect_to));
        exit;
    }

    // Rate-limited to one send per 2 minutes per account — someone
    // impatiently mashing "resend" shouldn't be able to flood their own
    // inbox, let alone anyone else's if this were ever reachable for a
    // different account (it isn't — is_user_logged_in() plus always
    // acting on get_current_user_id() means this can only ever resend
    // to the account making the request).
    public static function resend_verification() {
        $uid = get_current_user_id();
        if (self::is_email_verified($uid)) {
            return new WP_REST_Response(['success' => true, 'already_verified' => true], 200);
        }
        $throttle_key = 'bh_resend_verify_' . $uid;
        if (get_transient($throttle_key)) {
            return new WP_Error('throttled', 'Please wait a couple of minutes before requesting another email.', ['status' => 429]);
        }
        set_transient($throttle_key, 1, 2 * MINUTE_IN_SECONDS);

        $user = get_userdata($uid);
        self::send_verification_email($uid, $user->user_email, $user->user_login);
        return new WP_REST_Response(['success' => true], 200);
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
        $username = (string) $req->get_param('username');
        $fail_key = 'bh_login_fail_' . md5(strtolower($username) . '|' . self::ip());

        // Checked BEFORE attempting the credentials, not after — a
        // locked-out attempt should never even reach wp_signon(), or an
        // attacker could keep guessing right up to (and including) the
        // one that would have succeeded, with the lockout only kicking
        // in a request too late.
        if ((int) get_transient($fail_key) >= BH_LOGIN_MAX_FAILS) {
            return new WP_Error('locked_out', 'Too many failed attempts. Please try again in 15 minutes.', ['status' => 429]);
        }

        $user = wp_signon([
            'user_login'    => $username,
            'user_password' => (string) $req->get_param('password'),
            'remember'      => true,
        ], is_ssl());

        if (is_wp_error($user)) {
            // Sliding window: each failure resets the countdown, so a
            // slow trickle of guesses across the lockout period doesn't
            // get a fresh full-strength attempt right as an old failure
            // ages out.
            set_transient($fail_key, (int) get_transient($fail_key) + 1, 15 * MINUTE_IN_SECONDS);
            return new WP_Error('login_failed', 'Invalid username or password.', ['status' => 401]);
        }

        delete_transient($fail_key);
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

        self::send_verification_email($id, $email, $user);

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
