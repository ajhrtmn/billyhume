<?php
if (!defined('ABSPATH')) exit;

/**
 * Optional TOTP (RFC 6238) two-factor auth — standard enough to work
 * with Google Authenticator, Authy, 1Password, etc., implemented in
 * plain PHP (hash_hmac + a small base32 codec) rather than pulling in a
 * Composer dependency, matching this codebase's no-build-step
 * convention everywhere else.
 *
 * Two independent layers of "optional," on purpose:
 *
 * 1. SITE-WIDE: the site holder decides whether this feature exists on
 *    their install at all (Own Ur Shit → Security). Off by default.
 *    While off, nothing changes about login for anyone — no code
 *    field, no enforcement, completely inert.
 * 2. PER-USER: even with the site setting on, 2FA is opt-in per
 *    account, enrolled from the user's own WP profile screen (their
 *    own decision, not something an admin flips on for them). Only
 *    users who've actually completed enrollment (which requires
 *    confirming one real code first, so nobody can lock themselves out
 *    with a mistyped secret) are ever challenged at login.
 *
 * One enforcement point covers both the classic wp-login.php form and
 * this plugin's own REST login endpoint: WordPress's own 'authenticate'
 * filter chain, which wp_signon() (used by BOTH bhi/v1/login and
 * wp-login.php under the hood) already runs through — see gate_login()
 * below. That's the one place this needs to hook, rather than
 * duplicating the check in two different code paths.
 */
class BHI_TwoFactor {
    const SECRET_META_KEY = 'bhcore_2fa_secret';
    const ENABLED_META_KEY = 'bhcore_2fa_enabled';
    const SITE_OPTION = 'bhcore_2fa_site_enabled';
    const PENDING_META_KEY = 'bhcore_2fa_pending_secret';

    public static function init() {
        add_filter('authenticate', [self::class, 'gate_login'], 30, 3);
        add_action('login_form', [self::class, 'render_code_field']);

        add_action('show_user_profile', [self::class, 'render_profile_fields']);
        add_action('edit_user_profile', [self::class, 'render_profile_fields']);

        add_action('wp_ajax_bhcore_2fa_start_enroll', [self::class, 'ajax_start_enroll']);
        add_action('wp_ajax_bhcore_2fa_confirm_enroll', [self::class, 'ajax_confirm_enroll']);
        add_action('wp_ajax_bhcore_2fa_disable', [self::class, 'ajax_disable']);

        add_action('admin_menu', [self::class, 'add_settings_page']);
        add_action('admin_post_bhcore_save_2fa_site_setting', [self::class, 'save_site_setting']);
    }

    public static function site_enabled() {
        return (bool) get_option(self::SITE_OPTION, false);
    }

    public static function user_has_2fa($user_id) {
        return (bool) get_user_meta($user_id, self::ENABLED_META_KEY, true);
    }

    /* ---------------- TOTP core (RFC 6238 / RFC 4226) ---------------- */

    private static function base32_encode($binary) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($binary) as $byte) $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= $alphabet[bindec($chunk)];
        }
        return $output;
    }

    private static function base32_decode($b32) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
        $bits = '';
        foreach (str_split($b32) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) continue;
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $binary = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) < 8) continue;
            $binary .= chr(bindec($byte));
        }
        return $binary;
    }

    public static function generate_secret() {
        return self::base32_encode(random_bytes(20)); // 160-bit secret, standard for TOTP
    }

    // 30-second step, 6 digits, SHA-1 — the universal defaults every
    // authenticator app assumes without being told otherwise.
    private static function totp_at($secret, $timeslice) {
        $key = self::base32_decode($secret);
        $time_bin = str_pad(pack('N', 0) . pack('N', $timeslice), 8, "\0", STR_PAD_LEFT);
        $hash = hash_hmac('sha1', $time_bin, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string) ($truncated % 1000000), 6, '0', STR_PAD_LEFT);
    }

    // Accepts the current 30-second window and one step on either side —
    // a real, common allowance for clock drift between the server and
    // whatever phone the authenticator app is running on, without
    // opening the window so wide a code stays guessable for minutes.
    public static function verify_code($user_id, $code, $secret = null) {
        $secret = $secret ?: get_user_meta($user_id, self::SECRET_META_KEY, true);
        if (!$secret) return false;
        $code = preg_replace('/[^0-9]/', '', (string) $code);
        if (strlen($code) !== 6) return false;

        $current_slice = (int) floor(time() / 30);
        foreach ([-1, 0, 1] as $delta) {
            if (hash_equals(self::totp_at($secret, $current_slice + $delta), $code)) return true;
        }
        return false;
    }

    public static function otpauth_uri($secret, $user) {
        $site = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'site';
        $label = rawurlencode($site . ':' . $user->user_login);
        $issuer = rawurlencode($site);
        return "otpauth://totp/$label?secret=$secret&issuer=$issuer&digits=6&period=30";
    }

    /* ---------------- login enforcement ---------------- */

    // Runs at priority 30 — after WP core's own username/password check
    // (priority 20) has already turned $user into either a valid
    // WP_User or a WP_Error. Nothing here re-checks the password itself.
    public static function gate_login($user, $username, $password) {
        if (empty($username) || empty($password)) return $user; // blank initial page load, nothing to gate yet
        if (is_wp_error($user) || !($user instanceof WP_User)) return $user; // password already failed — not this class's problem
        if (!self::site_enabled() || !self::user_has_2fa($user->ID)) return $user;

        $code = isset($_POST['bhcore_2fa_code']) ? sanitize_text_field(wp_unslash($_POST['bhcore_2fa_code'])) : '';
        if (self::verify_code($user->ID, $code)) return $user;

        // A genuinely wrong code (not just "the field hasn't been shown
        // yet") was previously invisible — this is a real brute-force/
        // account-takeover-attempt signal on a security-critical path
        // that had zero trace before. Only logged when a code was
        // actually submitted (not the blank-field first render, which
        // would otherwise log on every single password-only login
        // attempt for a 2FA-enabled account and drown out real signal).
        // Throttled per-user, not per-request, so a scripted brute-force
        // attempt against one account still shows up as "repeated
        // failures" in the log rather than either silence or a flood.
        if ($code !== '' && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log_throttled('warning', 'bhcore_2fa_fail_' . $user->ID, 30,
                'Failed 2FA code attempt at login.', ['user_id' => $user->ID, 'username' => $username], 'Two-Factor'
            );
        }

        return new WP_Error('bhcore_2fa_required', __('<strong>Error</strong>: Enter the 6-digit code from your authenticator app.'));
    }

    // Always rendered on wp-login.php once the site FEATURE is on
    // (regardless of whether THIS particular visitor's account has 2FA
    // enabled) — a field a non-2FA account simply leaves blank and
    // gate_login() never looks at, since user_has_2fa() is false for
    // them. Simpler than trying to know in advance, before a password is
    // even submitted, whether to show it.
    public static function render_code_field() {
        if (!self::site_enabled()) return;
        echo '<p><label for="bhcore_2fa_code">Authentication code <span class="description">(only if you have 2FA enabled on your account)</span><br>';
        echo '<input type="text" name="bhcore_2fa_code" id="bhcore_2fa_code" class="input" inputmode="numeric" autocomplete="one-time-code" maxlength="6" style="width:100%;"></label></p>';
    }

    /* ---------------- per-user enrollment (WP profile screen) ---------------- */

    public static function render_profile_fields($user) {
        if (!self::site_enabled()) {
            if (current_user_can('manage_options')) {
                echo '<h2>Two-Factor Authentication</h2><p class="description">Disabled site-wide. Turn it on under <a href="' . esc_url(admin_url('admin.php?page=ous-security')) . '">Own Ur Shit → Security</a> before anyone can enroll.</p>';
            }
            return;
        }
        // Only the account owner enrolls their OWN 2FA — an admin
        // viewing someone else's profile can see status, not set it up
        // on their behalf (they wouldn't have the authenticator app to
        // scan the QR code with anyway).
        $is_own_profile = get_current_user_id() === $user->ID;
        $enabled = self::user_has_2fa($user->ID);

        echo '<h2>Two-Factor Authentication</h2>';
        if (!$is_own_profile) {
            echo '<p>' . ($enabled ? 'Enabled' : 'Not enabled') . ' for this account.</p>';
            return;
        }

        echo '<div id="bhcore-2fa-panel" data-enabled="' . ($enabled ? '1' : '0') . '" data-nonce="' . esc_attr(wp_create_nonce('bhcore_2fa')) . '">';
        if ($enabled) {
            echo '<p>Enabled on your account. <button type="button" class="button" id="bhcore-2fa-disable">Disable</button></p>';
        } else {
            echo '<p><button type="button" class="button" id="bhcore-2fa-start">Set up two-factor authentication</button></p>';
            echo '<div id="bhcore-2fa-setup" style="display:none;max-width:420px;">
                <p>Scan this with any authenticator app (Google Authenticator, Authy, 1Password, etc.), or enter the secret manually:</p>
                <p><img id="bhcore-2fa-qr" src="" alt="QR code" style="border:1px solid #ccd0d4;"></p>
                <p><code id="bhcore-2fa-secret-text"></code></p>
                <p><label>Enter the 6-digit code your app shows to confirm setup: <input type="text" id="bhcore-2fa-confirm-code" maxlength="6" inputmode="numeric" style="width:100px;"></label>
                <button type="button" class="button button-primary" id="bhcore-2fa-confirm">Confirm &amp; enable</button></p>
                <p id="bhcore-2fa-error" style="color:#b32d2e;"></p>
            </div>';
        }
        echo '</div>';
        self::enqueue_profile_script();
    }

    private static function enqueue_profile_script() {
        ?>
        <script>
        (function () {
            var panel = document.getElementById('bhcore-2fa-panel');
            if (!panel) return;
            var nonce = panel.dataset.nonce;
            var ajaxUrl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';

            var startBtn = document.getElementById('bhcore-2fa-start');
            if (startBtn) startBtn.addEventListener('click', function () {
                var originalLabel = startBtn.textContent;
                startBtn.disabled = true;
                startBtn.textContent = 'Starting…';
                fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: 'bhcore_2fa_start_enroll', nonce: nonce }) })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        startBtn.disabled = false;
                        startBtn.textContent = originalLabel;
                        if (!res.success) { alert(res.data && res.data.message || 'Could not start setup.'); return; }
                        document.getElementById('bhcore-2fa-setup').style.display = '';
                        document.getElementById('bhcore-2fa-qr').src = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(res.data.otpauth_uri);
                        document.getElementById('bhcore-2fa-secret-text').textContent = res.data.secret;
                    })
                    .catch(function () {
                        startBtn.disabled = false;
                        startBtn.textContent = originalLabel;
                        alert('Could not reach the server — check your connection and try again.');
                    });
            });

            var confirmBtn = document.getElementById('bhcore-2fa-confirm');
            if (confirmBtn) confirmBtn.addEventListener('click', function () {
                var code = document.getElementById('bhcore-2fa-confirm-code').value;
                var originalLabel = confirmBtn.textContent;
                confirmBtn.disabled = true;
                confirmBtn.textContent = 'Confirming…';
                fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: 'bhcore_2fa_confirm_enroll', nonce: nonce, code: code }) })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success) {
                            confirmBtn.disabled = false;
                            confirmBtn.textContent = originalLabel;
                            document.getElementById('bhcore-2fa-error').textContent = (res.data && res.data.message) || 'Invalid code.';
                            return;
                        }
                        window.location.reload();
                    })
                    .catch(function () {
                        confirmBtn.disabled = false;
                        confirmBtn.textContent = originalLabel;
                        document.getElementById('bhcore-2fa-error').textContent = 'Could not reach the server — check your connection and try again.';
                    });
            });

            var disableBtn = document.getElementById('bhcore-2fa-disable');
            if (disableBtn) disableBtn.addEventListener('click', function () {
                if (!confirm('Disable two-factor authentication on your account?')) return;
                var originalLabel = disableBtn.textContent;
                disableBtn.disabled = true;
                disableBtn.textContent = 'Disabling…';
                fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: 'bhcore_2fa_disable', nonce: nonce }) })
                    .then(function () { window.location.reload(); })
                    .catch(function () {
                        disableBtn.disabled = false;
                        disableBtn.textContent = originalLabel;
                        alert('Could not reach the server — check your connection and try again. Two-factor is likely still enabled.');
                    });
            });
        })();
        </script>
        <?php
    }

    public static function ajax_start_enroll() {
        check_ajax_referer('bhcore_2fa', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id || !self::site_enabled()) wp_send_json_error(['message' => 'Not available.'], 403);

        // A freshly-generated secret each time "start setup" is clicked
        // (not reused across attempts) stored as PENDING, not live yet —
        // only promoted to the real, enforced secret once a real code
        // confirms it actually works (see ajax_confirm_enroll()), so a
        // botched scan can never leave someone's account silently
        // requiring a code they can't produce.
        $secret = self::generate_secret();
        update_user_meta($user_id, self::PENDING_META_KEY, $secret);

        wp_send_json_success([
            'secret' => $secret,
            'otpauth_uri' => self::otpauth_uri($secret, get_userdata($user_id)),
        ]);
    }

    public static function ajax_confirm_enroll() {
        check_ajax_referer('bhcore_2fa', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error([], 403);

        $pending_secret = get_user_meta($user_id, self::PENDING_META_KEY, true);
        if (!$pending_secret) wp_send_json_error(['message' => 'Start setup first.'], 400);

        $code = sanitize_text_field($_POST['code'] ?? '');
        if (!self::verify_code($user_id, $code, $pending_secret)) {
            wp_send_json_error(['message' => 'That code didn\'t match — try the current code from your app.'], 400);
        }

        update_user_meta($user_id, self::SECRET_META_KEY, $pending_secret);
        update_user_meta($user_id, self::ENABLED_META_KEY, 1);
        delete_user_meta($user_id, self::PENDING_META_KEY);
        wp_send_json_success();
    }

    public static function ajax_disable() {
        check_ajax_referer('bhcore_2fa', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error([], 403);
        delete_user_meta($user_id, self::SECRET_META_KEY);
        delete_user_meta($user_id, self::ENABLED_META_KEY);
        delete_user_meta($user_id, self::PENDING_META_KEY);
        // A security-relevant account change with zero audit trail
        // before this — a stolen/hijacked session disabling 2FA to
        // remove a barrier to further account takeover would leave no
        // trace at all. Unthrottled (this is a rare, deliberate action,
        // not a per-request check) and always logged, not just on
        // suspicion — the point is a searchable record existing at all.
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('warning', '2FA disabled for account.', ['user_id' => $user_id], 'Two-Factor');
        }
        wp_send_json_success();
    }

    /* ---------------- site-wide toggle ---------------- */

    public static function add_settings_page() {
        add_submenu_page('own-ur-shit', 'Security', 'Security', 'manage_options', 'ous-security', [self::class, 'render_settings_page']);
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        $enabled = self::site_enabled();
        $enrolled_count = (int) (new WP_User_Query(['meta_key' => self::ENABLED_META_KEY, 'meta_value' => 1, 'fields' => 'ID', 'number' => 0, 'count_total' => true]))->get_total();

        echo '<div class="wrap"><h1>Security</h1>';
        echo '<h2>Two-Factor Authentication</h2>';
        echo '<p class="description">Entirely your call as the site holder — off by default, and off means zero change to how anyone logs in. Turning it on doesn\'t force it on anyone: each person still opts in individually from their own profile screen.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="bhcore_save_2fa_site_setting">';
        wp_nonce_field('bhcore_2fa_site_setting');
        echo '<p><label><input type="checkbox" name="bhcore_2fa_site_enabled" value="1"' . checked($enabled, true, false) . '> Allow users to enable two-factor authentication on this site</label></p>';
        echo '<p class="description">' . $enrolled_count . ' account(s) currently have 2FA enabled.</p>';
        submit_button('Save');
        echo '</form></div>';
    }

    public static function save_site_setting() {
        if (!current_user_can('manage_options') || !check_admin_referer('bhcore_2fa_site_setting')) wp_die('Not allowed.');
        update_option(self::SITE_OPTION, !empty($_POST['bhcore_2fa_site_enabled']));
        wp_safe_redirect(admin_url('admin.php?page=ous-security&updated=1'));
        exit;
    }
}
