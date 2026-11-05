<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared registration/login/session/email-verification system. Any
 * ecosystem plugin's sign-up form points at these bhi/v1 endpoints
 * instead of building its own.
 *
 * Security model (no second factor is assumed, so the password and the
 * throttles are the real barrier):
 * - Every state-changing endpoint rejects requests whose Origin/Referer
 *   is another site (login/logout CSRF), and bhi/v1 responses never carry
 *   CORS headers for foreign origins.
 * - Failed logins lock out per account+IP and per IP across accounts.
 * - Sign-up can be switched off from the dashboard, is throttled per IP,
 *   rejects common passwords, and carries a honeypot for naive bots.
 */
class BHI_Auth {
    const REG_THROTTLE = 5;               // successful registrations per IP per hour
    const REG_ATTEMPT_THROTTLE = 20;      // registration attempts per IP per hour
    const LOGIN_MAX_FAILS = 5;            // failed logins per account+IP before lockout
    const LOGIN_MAX_FAILS_PER_IP = 30;    // failed logins per IP across all accounts
    const LOCKOUT_SECONDS = 900;          // 15 minutes
    const USERNAME_MAX = 60;              // wp_users.user_login column width
    const EMAIL_MAX = 100;                // wp_users.user_email column width
    const PASSWORD_MIN = 8;
    const PASSWORD_MAX = 200;             // hashing very long input is a cheap CPU-exhaustion lever
    const HONEYPOT_FIELD = 'website';

    const OPTION_REGISTRATION_OPEN = 'bhi_registration_open';
    const OPTION_IP_HEADER = 'bhi_trusted_ip_header';

    /** $_SERVER key => label. Only these can ever be trusted for the client IP. */
    const IP_HEADERS = [
        '' => 'Direct connection (default — use this unless the site is behind a proxy/CDN)',
        'HTTP_CF_CONNECTING_IP' => 'Cloudflare (CF-Connecting-IP)',
        'HTTP_X_REAL_IP' => 'X-Real-IP (nginx proxy)',
        'HTTP_X_FORWARDED_FOR' => 'X-Forwarded-For (generic proxy / load balancer)',
    ];

    /** Rejected at sign-up. Lowercased; length >= PASSWORD_MIN or the length rule already catches them. */
    const COMMON_PASSWORDS = [
        'password', 'password1', 'password12', 'password123', 'passw0rd', 'p@ssw0rd', '12345678',
        '123456789', '1234567890', '0987654321', '87654321', '11111111', '00000000', '12341234',
        '11223344', '12121212', 'aaaaaaaa', 'qwertyui', 'qwerty123', 'qwertyuiop', 'asdfghjk',
        'asdfghjkl', '1q2w3e4r', '1qaz2wsx', 'zaq12wsx', 'iloveyou', 'sunshine', 'princess',
        'football', 'baseball', 'superman', 'starwars', 'whatever', 'trustno1', 'welcome1',
        'letmein1', 'abc12345', 'abcd1234', 'computer', 'internet', 'michelle', 'jennifer',
        'changeme', 'admin123', 'administrator', 'iloveyou1', 'monkey123', 'dragon123',
        'master123', 'shadow123', 'login123', 'welcome123', 'football1', 'baseball1',
    ];

    public static function init(): void {
        add_action('admin_post_nopriv_bhi_verify_email', [self::class, 'verify_email_action']);
        add_action('admin_post_bhi_verify_email', [self::class, 'verify_email_action']);
        add_action('admin_post_bhi_save_account_settings', [self::class, 'handle_save_settings']);
        add_action('wp_footer', [self::class, 'maybe_print_auth_notice']);
        // After core's rest_send_cors_headers() (priority 10), which reflects any Origin.
        add_filter('rest_pre_serve_request', [self::class, 'strip_foreign_cors_headers'], 11, 3);
    }

    public static function register_routes(): void {
        $open = ['permission_callback' => '__return_true'];
        $auth = ['permission_callback' => 'is_user_logged_in'];
        // /session and /profile need an X-WP-Nonce: without one, WordPress
        // treats every cookie request as logged out (its own CSRF guard).
        register_rest_route('bhi/v1', '/session',  ['methods' => 'GET',  'callback' => [self::class, 'session']]  + $open);
        register_rest_route('bhi/v1', '/login',    ['methods' => 'POST', 'callback' => [self::class, 'login']]    + $open);
        register_rest_route('bhi/v1', '/register', ['methods' => 'POST', 'callback' => [self::class, 'register']] + $open);
        register_rest_route('bhi/v1', '/logout',   ['methods' => 'POST', 'callback' => [self::class, 'logout']]   + $open);
        register_rest_route('bhi/v1', '/profile',  ['methods' => 'GET',  'callback' => [self::class, 'profile']]  + $auth);
        register_rest_route('bhi/v1', '/resend-verification', ['methods' => 'POST', 'callback' => [self::class, 'resend_verification']] + $auth);
    }

    /* ---------- request origin (CSRF) ---------- */

    /** @return string[] Normalized origins ("scheme://host[:port]") allowed to call the auth endpoints. */
    public static function allowed_origins(): array {
        $origins = array_filter([self::normalize_origin(home_url()), self::normalize_origin(site_url())]);
        return array_values(array_unique((array) apply_filters('bhi_auth_allowed_origins', $origins)));
    }

    public static function normalize_origin(string $url): string {
        $parts = wp_parse_url(trim($url));
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') return '';
        $port = isset($parts['port']) ? (int) $parts['port'] : 0;
        $default_port = $scheme === 'https' ? 443 : 80;
        return $scheme . '://' . strtolower($parts['host']) . ($port && $port !== $default_port ? ':' . $port : '');
    }

    /**
     * Browsers always send Origin on a cross-site POST (fetch and plain
     * forms alike), so a foreign or opaque ("null", e.g. a sandboxed
     * iframe) Origin is refused. With neither header present the caller
     * isn't a browser, and a non-browser client can't be a CSRF vector.
     *
     * @param string[]|null $allowed
     */
    public static function is_same_origin_request(?string $origin, ?string $referer, ?array $allowed = null): bool {
        $allowed = $allowed ?? self::allowed_origins();
        $origin = trim((string) $origin);
        if ($origin !== '') return in_array(self::normalize_origin($origin), $allowed, true);
        $referer = trim((string) $referer);
        if ($referer !== '') return in_array(self::normalize_origin($referer), $allowed, true);
        return true;
    }

    private static function guard_origin(\WP_REST_Request $req): ?\WP_Error {
        if (self::is_same_origin_request($req->get_header('origin'), $req->get_header('referer'))) return null;
        return new WP_Error('bad_origin', 'This request must come from this site.', ['status' => 403]);
    }

    /**
     * @param mixed $served
     * @param mixed $result
     * @return mixed
     */
    public static function strip_foreign_cors_headers($served, $result, \WP_REST_Request $request) {
        if (strpos($request->get_route(), '/bhi/v1') !== 0 || headers_sent()) return $served;
        $origin = (string) $request->get_header('origin');
        if ($origin !== '' && !in_array(self::normalize_origin($origin), self::allowed_origins(), true)) {
            header_remove('Access-Control-Allow-Origin');
            header_remove('Access-Control-Allow-Credentials');
        }
        return $served;
    }

    /* ---------- client IP ---------- */

    /**
     * REMOTE_ADDR unless an admin has chosen a proxy header on the
     * dashboard. A forwarding header is only honest when a proxy sets it,
     * so it is never trusted by default (anyone can send one).
     *
     * @param array<string, mixed>|null $server
     */
    public static function client_ip(?array $server = null, ?string $header = null): string {
        $server = $server ?? $_SERVER;
        $header = $header ?? (string) get_option(self::OPTION_IP_HEADER, '');
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        $ip = filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';

        if ($header !== '' && array_key_exists($header, self::IP_HEADERS) && !empty($server[$header])) {
            $candidates = array_map('trim', explode(',', (string) $server[$header]));
            // X-Forwarded-For: the right-most entry is the one our own proxy appended; anything left of it is client-supplied.
            $candidate = (string) end($candidates);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) $ip = $candidate;
        }
        return (string) apply_filters('bhi_client_ip', $ip, $server);
    }

    /* ---------- session ---------- */

    public static function session(): \WP_REST_Response {
        if (!is_user_logged_in()) return new WP_REST_Response(['success' => true, 'loggedIn' => false], 200);
        $user = wp_get_current_user();
        return new WP_REST_Response([
            'success' => true, 'loggedIn' => true,
            'username' => $user->user_login, 'verified' => self::is_email_verified($user->ID),
        ], 200);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function login(\WP_REST_Request $req) {
        $bad_origin = self::guard_origin($req);
        if ($bad_origin) return $bad_origin;

        $username = trim((string) $req->get_param('username'));
        $password = (string) $req->get_param('password');
        if ($username === '' || $password === '' || strlen($username) > self::EMAIL_MAX || strlen($password) > self::PASSWORD_MAX) {
            return new WP_Error('login_failed', 'Invalid username or password.', ['status' => 401]);
        }

        $ip = self::client_ip();
        $account_key = 'bhi_login_fail_' . md5(self::account_fingerprint($username) . '|' . $ip);
        $ip_key = 'bhi_login_fail_ip_' . md5($ip);
        // OUS_ReliableStore, not transients: a throttle whose counter silently fails to persist fails open.
        if ((int) OUS_ReliableStore::get($account_key, 0) >= self::LOGIN_MAX_FAILS
            || (int) OUS_ReliableStore::get($ip_key, 0) >= self::LOGIN_MAX_FAILS_PER_IP) {
            return new WP_Error('locked_out', 'Too many failed attempts. Please try again in 15 minutes.', ['status' => 429]);
        }

        // Optional 2FA: BHI_TwoFactor::gate_login() reads the code from $_POST inside the 'authenticate' chain.
        $code = trim((string) $req->get_param('code'));
        if ($code !== '') $_POST['bhcore_2fa_code'] = sanitize_text_field($code);

        $remember = $req->get_param('remember') === null ? true : rest_sanitize_boolean($req->get_param('remember'));
        $user = wp_signon(['user_login' => $username, 'user_password' => $password, 'remember' => $remember], is_ssl());

        if (is_wp_error($user)) {
            // Password was right but no code yet: prompt for it without counting a failure.
            if ($user->get_error_code() === 'bhcore_2fa_required' && $code === '') {
                return new WP_Error('requires_2fa', 'Enter the 6-digit code from your authenticator app.', ['status' => 401, 'requires_2fa' => true]);
            }
            OUS_ReliableStore::increment($account_key, self::LOCKOUT_SECONDS);
            OUS_ReliableStore::increment($ip_key, self::LOCKOUT_SECONDS);
            if ($user->get_error_code() === 'bhcore_2fa_required') {
                return new WP_Error('invalid_2fa', 'That code didn\'t match. Try the newest code from your app.', ['status' => 401, 'requires_2fa' => true]);
            }
            return new WP_Error('login_failed', 'Invalid username or password.', ['status' => 401]);
        }

        OUS_ReliableStore::delete($account_key);
        return new WP_REST_Response(['success' => true], 200);
    }

    /** Same key whether someone types the login name, the email, or different capitalization. */
    private static function account_fingerprint(string $username): string {
        $user = get_user_by('login', $username);
        if (!$user && is_email($username)) $user = get_user_by('email', $username);
        return $user ? 'uid:' . $user->ID : 'name:' . strtolower($username);
    }

    /* ---------- registration ---------- */

    public static function registration_open(): bool {
        return (bool) apply_filters('bhi_registration_open', get_option(self::OPTION_REGISTRATION_OPEN, '1') === '1');
    }

    /** Returns '' when acceptable, otherwise a message for the person signing up. */
    public static function username_problem(string $username): string {
        if ($username === '') return 'Choose a username.';
        if (strlen($username) > self::USERNAME_MAX) return 'Usernames can be at most ' . self::USERNAME_MAX . ' characters.';
        // Reject rather than silently rewrite (sanitize_user() would turn "José" into "Jose").
        if (sanitize_user($username, true) !== $username) {
            return 'Usernames can use only letters (a–z), numbers, spaces, and . - _ @';
        }
        return '';
    }

    /** Returns '' when acceptable, otherwise a message for the person signing up. */
    public static function password_problem(string $password, string $username = '', string $email = ''): string {
        if (strlen($password) < self::PASSWORD_MIN) return 'Use a password of at least ' . self::PASSWORD_MIN . ' characters.';
        if (strlen($password) > self::PASSWORD_MAX) return 'Passwords can be at most ' . self::PASSWORD_MAX . ' characters.';
        $lower = strtolower($password);
        if (in_array($lower, self::COMMON_PASSWORDS, true) || count(array_unique(str_split($lower))) <= 2) {
            return 'That password is too easy to guess. Try a short phrase of a few words.';
        }
        $local_part = strtolower((string) strstr($email, '@', true));
        foreach ([strtolower($username), $local_part, strtolower($email)] as $personal) {
            if (strlen($personal) >= 4 && strpos($lower, $personal) !== false) {
                return 'Your password shouldn\'t contain your username or email.';
            }
        }
        return '';
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function register(\WP_REST_Request $req) {
        $bad_origin = self::guard_origin($req);
        if ($bad_origin) return $bad_origin;
        if (!self::registration_open()) {
            return new WP_Error('registration_closed', 'New accounts aren\'t being accepted right now.', ['status' => 403]);
        }

        $ip = self::client_ip();
        $attempt_key = 'bhi_reg_attempt_' . md5($ip);
        $throttle_key = 'bhi_reg_throttle_' . md5($ip);
        if (OUS_ReliableStore::increment($attempt_key, HOUR_IN_SECONDS) > self::REG_ATTEMPT_THROTTLE
            || (int) OUS_ReliableStore::get($throttle_key, 0) >= self::REG_THROTTLE) {
            return new WP_Error('throttled', 'Too many sign-ups from this connection. Please try again later.', ['status' => 429]);
        }

        // Honeypot: a visually hidden field people never see. Same error a person would get, so bots learn nothing.
        if (trim((string) $req->get_param(self::HONEYPOT_FIELD)) !== '') {
            return new WP_Error('invalid', 'Please check the form and try again.', ['status' => 400]);
        }

        $username = trim((string) $req->get_param('username'));
        $email_raw = trim((string) $req->get_param('email'));
        $email = sanitize_email($email_raw);
        $password = (string) $req->get_param('password');

        $problem = self::username_problem($username);
        if ($problem === '' && ($email === '' || $email !== $email_raw || !is_email($email) || strlen($email) > self::EMAIL_MAX)) {
            $problem = 'Enter a valid email address.';
        }
        if ($problem === '') $problem = self::password_problem($password, $username, $email);
        if ($problem !== '') return new WP_Error('invalid', $problem, ['status' => 400]);

        if (username_exists($username)) return new WP_Error('username_taken', 'That username is already in use.', ['status' => 409]);
        if (email_exists($email)) {
            return new WP_Error('email_taken', 'That email already has an account. Try logging in, or reset your password.', ['status' => 409]);
        }

        $id = wp_create_user($username, $password, $email);
        if (is_wp_error($id)) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('error', 'wp_create_user() failed during registration.', [
                    'username' => $username, 'wp_error' => $id->get_error_message(),
                ], 'BHI_Auth');
            }
            return new WP_Error('create_failed', 'Could not create your account. Please try again.', ['status' => 500]);
        }

        OUS_ReliableStore::increment($throttle_key, HOUR_IN_SECONDS);

        // Dedicated low-privilege member role instead of default_role; an unknown filtered role is ignored.
        $role = (string) apply_filters('bhi_registration_role', OUS_Roles::MEMBER_ROLE, $id, $email);
        if ($role && get_role($role)) {
            $new_user = new WP_User($id);
            $new_user->set_role($role);
        }

        // Attachment IDs can't belong to an account that doesn't exist yet; accepting them would let a
        // sign-up claim someone else's private upload as its avatar.
        $profile_fields = array_diff_key(BHI_Profiles::from_request($req), array_flip(BHI_Profiles::INT_COLS));
        if ($profile_fields) BHI_Profiles::save($id, $profile_fields);

        self::send_verification_email($id, $email, $username);

        wp_set_current_user($id);
        wp_set_auth_cookie($id, true, is_ssl());
        return new WP_REST_Response(['success' => true], 200);
    }

    /* ---------- logout ---------- */

    /** @return \WP_REST_Response|\WP_Error */
    public static function logout(\WP_REST_Request $req) {
        $bad_origin = self::guard_origin($req);
        if ($bad_origin) return $bad_origin;

        if (is_user_logged_in()) {
            wp_logout();
            return new WP_REST_Response(['success' => true], 200);
        }
        // Without an X-WP-Nonce, REST has already reset the current user to 0, so wp_logout() alone would
        // clear the browser cookie but leave the server-side session valid. End that session explicitly.
        $uid = wp_validate_auth_cookie('', 'logged_in');
        $cookie = wp_parse_auth_cookie('', 'logged_in');
        if ($uid && is_array($cookie) && !empty($cookie['token'])) {
            WP_Session_Tokens::get_instance($uid)->destroy($cookie['token']);
            do_action('wp_logout', $uid);
        }
        wp_clear_auth_cookie();
        return new WP_REST_Response(['success' => true], 200);
    }

    public static function profile(): \WP_REST_Response {
        return new WP_REST_Response(['success' => true, 'profile' => BHI_Profiles::get(get_current_user_id())], 200);
    }

    /* ---------- email verification ---------- */

    public static function is_email_verified(int $uid): bool {
        if (get_user_meta($uid, '_bhi_email_verified', true) === '1') return true;
        // Grandfather clause: an account that never had a token issued (predates this system, or was made in
        // wp-admin) counts as verified, so turning verification on doesn't lock out existing users.
        return get_user_meta($uid, '_bhi_email_verify_token', true) === ''
            && get_user_meta($uid, '_bhi_email_verify_sent', true) === '';
    }

    private static function send_verification_email(int $uid, string $email, string $username): void {
        $token = wp_generate_password(32, false, false);
        update_user_meta($uid, '_bhi_email_verify_token', wp_hash($token));
        update_user_meta($uid, '_bhi_email_verify_sent', time());

        $verify_url = add_query_arg(['action' => 'bhi_verify_email', 'uid' => $uid, 'token' => $token], admin_url('admin-post.php'));
        $subject = 'Confirm your email — ' . get_bloginfo('name');
        $body = "Hi {$username},\n\nOne more step: confirm this is really your email.\n\n{$verify_url}\n\nThis link works for 48 hours. If you didn't sign up for this, you can ignore it.";
        if (class_exists('BH_Mail')) {
            BH_Mail::send(['to' => $email, 'user_id' => $uid, 'subject' => $subject, 'body' => $body, 'source' => 'BH Auth']);
        } else {
            wp_mail($email, $subject, $body);
        }
    }

    public static function verify_email_action(): void {
        $uid = (int) ($_GET['uid'] ?? 0);
        $token = (string) wp_unslash($_GET['token'] ?? '');
        $stored = $uid ? (string) get_user_meta($uid, '_bhi_email_verify_token', true) : '';
        $sent_at = $uid ? (int) get_user_meta($uid, '_bhi_email_verify_sent', true) : 0;

        $valid = $stored !== '' && $token !== '' && strlen($token) <= 64
            && hash_equals($stored, wp_hash($token))
            && (time() - $sent_at) < 48 * HOUR_IN_SECONDS;

        if ($valid) {
            update_user_meta($uid, '_bhi_email_verified', '1');
            delete_user_meta($uid, '_bhi_email_verify_token');
        }

        // Links are clicked from email clients, which send no Referer, so the portal is the usual landing spot.
        $redirect_to = wp_get_referer() ?: home_url('/account/');
        wp_safe_redirect(add_query_arg('bhi_verified', $valid ? '1' : '0', $redirect_to));
        exit;
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function resend_verification(\WP_REST_Request $req) {
        $bad_origin = self::guard_origin($req);
        if ($bad_origin) return $bad_origin;

        $uid = get_current_user_id();
        if (self::is_email_verified($uid)) return new WP_REST_Response(['success' => true, 'already_verified' => true], 200);

        $throttle_key = 'bhi_resend_verify_' . $uid;
        if (OUS_ReliableStore::get($throttle_key)) {
            return new WP_Error('throttled', 'Please wait a couple of minutes before requesting another email.', ['status' => 429]);
        }
        OUS_ReliableStore::set($throttle_key, 1, 2 * MINUTE_IN_SECONDS);

        $user = get_userdata($uid);
        self::send_verification_email($uid, $user->user_email, $user->user_login);
        return new WP_REST_Response(['success' => true], 200);
    }

    /** Toasts for ?bhi_verified= (from the email link) and ?bhi_welcome= (right after sign-up). Fixed strings only. */
    public static function maybe_print_auth_notice(): void {
        if (isset($_GET['bhi_verified'])) {
            $ok = $_GET['bhi_verified'] === '1';
            $msg = $ok ? 'Email confirmed — thanks!' : 'That confirmation link is invalid or has expired. You can request a new one from your account page.';
            $param = 'bhi_verified';
        } elseif (isset($_GET['bhi_welcome']) && is_user_logged_in()) {
            $ok = true;
            $msg = 'Welcome! Your account is ready. We emailed you a link to confirm your address.';
            $param = 'bhi_welcome';
        } else {
            return;
        }
        ?>
        <script>
        (function () {
            if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(<?php echo wp_json_encode($msg, JSON_HEX_TAG | JSON_HEX_AMP); ?>, <?php echo wp_json_encode($ok ? 'success' : 'error'); ?>); }
            var url = new URL(window.location.href);
            url.searchParams.delete(<?php echo wp_json_encode($param); ?>);
            window.history.replaceState({}, '', url);
        })();
        </script>
        <?php
    }

    /* ---------- dashboard settings ---------- */

    public static function render_settings_card(): void {
        if (!current_user_can('manage_options')) return;
        $open = self::registration_open();
        $header = (string) get_option(self::OPTION_IP_HEADER, '');
        $detected = [];
        foreach (array_keys(self::IP_HEADERS) as $key) {
            if ($key !== '' && !empty($_SERVER[$key])) $detected[] = self::IP_HEADERS[$key];
        }

        echo '<h2 style="margin-top:32px;">Fan accounts</h2>';
        if (isset($_GET['bhi_account_settings_saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Account settings saved.</p></div>';
        }
        echo '<div class="ous-cards"><div class="ous-card">';
        echo '<div class="ous-card-header"><strong>Sign-ups</strong> ' . BHY_UI::badge($open ? 'Open' : 'Closed', ['variant' => $open ? 'success' : 'neutral']) . '</div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="bhi_save_account_settings">';
        wp_nonce_field('bhi_save_account_settings');
        echo '<p><label><input type="checkbox" name="registration_open" value="1"' . checked($open, true, false) . '> ';
        echo 'Let visitors create accounts from the <a href="' . esc_url(home_url('/account/')) . '">account page</a></label></p>';
        echo '<p class="ous-card-desc">When off, the "Create account" tab disappears and new sign-ups are refused. Existing members can still log in. WordPress\'s own wp-login.php registration is controlled separately (Settings &rarr; General).</p>';
        echo '<p><label for="bhi-ip-header"><strong>Visitor IP source</strong></label><br><select id="bhi-ip-header" name="ip_header">';
        foreach (self::IP_HEADERS as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($header, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></p>';
        echo '<p class="ous-card-desc">Login lockouts and sign-up limits count per visitor IP. Only pick a proxy header if the site really sits behind that proxy; otherwise anyone could fake their IP. ';
        echo 'IP the site sees for you right now: <code>' . esc_html(self::client_ip()) . '</code>.';
        if ($detected) echo ' Proxy headers present on this request: ' . esc_html(implode(', ', $detected)) . '.';
        echo '</p>';
        submit_button('Save account settings', 'secondary', 'submit', false);
        echo '</form></div></div>';
    }

    public static function handle_save_settings(): void {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to change these settings.', '', ['response' => 403]);
        check_admin_referer('bhi_save_account_settings');

        update_option(self::OPTION_REGISTRATION_OPEN, empty($_POST['registration_open']) ? '0' : '1');
        $header = sanitize_key(wp_unslash($_POST['ip_header'] ?? ''));
        $header = strtoupper($header);
        update_option(self::OPTION_IP_HEADER, array_key_exists($header, self::IP_HEADERS) ? $header : '');

        wp_safe_redirect(add_query_arg('bhi_account_settings_saved', '1', admin_url('admin.php?page=ous')));
        exit;
    }
}
