<?php
if (!defined('ABSPATH')) exit;

/**
 * Optional TOTP (RFC 6238) two-factor auth in plain PHP. Off site-wide by
 * default; when on, opt-in per user, and only enforced after enrollment is
 * confirmed with a real code. Enforced once in the 'authenticate' filter, which
 * covers both wp-login.php and the REST login (both use wp_signon()).
 */
class BHI_TwoFactor {
    const SECRET_META_KEY = 'bhcore_2fa_secret';
    const ENABLED_META_KEY = 'bhcore_2fa_enabled';
    const SITE_OPTION = 'bhcore_2fa_site_enabled';
    const PENDING_META_KEY = 'bhcore_2fa_pending_secret';

    public static function init(): void {
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

    public static function site_enabled(): bool {
        return (bool) get_option(self::SITE_OPTION, false);
    }

    public static function user_has_2fa(int $user_id): bool {
        return (bool) get_user_meta($user_id, self::ENABLED_META_KEY, true);
    }

    /* ---------------- TOTP core (RFC 6238 / RFC 4226) ---------------- */

    private static function base32_encode(string $binary): string {
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

    private static function base32_decode(string $b32): string {
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

    public static function generate_secret(): string {
        return self::base32_encode(random_bytes(20)); // 160-bit secret, standard for TOTP
    }

    // 30-second step, 6 digits, SHA-1: the defaults every authenticator assumes.
    private static function totp_at(string $secret, int $timeslice): string {
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

    // Accepts the current window plus one step either side for clock drift.
    /** @param mixed $code */
    public static function verify_code(int $user_id, $code, ?string $secret = null): bool {
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

    public static function otpauth_uri(string $secret, \WP_User $user): string {
        $site = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'site';
        $label = rawurlencode($site . ':' . $user->user_login);
        $issuer = rawurlencode($site);
        return "otpauth://totp/$label?secret=$secret&issuer=$issuer&digits=6&period=30";
    }

    /* ---------------- login enforcement ---------------- */

    // Priority 30: after core's password check (20). Never re-checks the password.
    /**
     * @param mixed $user
     * @return mixed
     */
    public static function gate_login($user, string $username, string $password) {
        if (empty($username) || empty($password)) return $user; // blank initial page load, nothing to gate yet
        if (is_wp_error($user) || !($user instanceof WP_User)) return $user; // password already failed — not this class's problem
        if (!self::site_enabled() || !self::user_has_2fa($user->ID)) return $user;

        $code = isset($_POST['bhcore_2fa_code']) ? sanitize_text_field(wp_unslash($_POST['bhcore_2fa_code'])) : '';
        if (self::verify_code($user->ID, $code)) return $user;

        // Log wrong submitted codes (not the blank first render), throttled per
        // user so brute force shows as repeated failures.
        if ($code !== '' && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log_throttled('warning', 'bhcore_2fa_fail_' . $user->ID, 30,
                'Failed 2FA code attempt at login.', ['user_id' => $user->ID, 'username' => $username], 'Two-Factor'
            );
        }

        return new WP_Error('bhcore_2fa_required', __('<strong>Error</strong>: Enter the 6-digit code from your authenticator app.'));
    }

    // Always shown once the feature is on; accounts without 2FA leave it blank
    // and gate_login() ignores it.
    public static function render_code_field(): void {
        if (!self::site_enabled()) return;
        echo '<p><label for="bhcore_2fa_code">Authentication code <span class="description">(only if you have 2FA enabled on your account)</span><br>';
        echo '<input type="text" name="bhcore_2fa_code" id="bhcore_2fa_code" class="input" inputmode="numeric" autocomplete="one-time-code" maxlength="6" style="width:100%;"></label></p>';
    }

    /* ---------------- per-user enrollment (WP profile screen) ---------------- */

    public static function render_profile_fields(\WP_User $user): void {
        if (!self::site_enabled()) {
            if (current_user_can('manage_options')) {
                echo '<h2>Two-Factor Authentication</h2><p class="description">Disabled site-wide. Turn it on under <a href="' . esc_url(admin_url('admin.php?page=ous-security')) . '">The Self-Hosted Self → Security</a> before anyone can enroll.</p>';
            }
            return;
        }
        // Only the account owner can enroll their own 2FA.
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
                <p id="bhcore-2fa-error" class="bh-alert bh-alert-error" role="alert" hidden></p>
            </div>';
        }
        echo '</div>';
        self::enqueue_profile_script();
    }

    private static function enqueue_profile_script(): void {
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
                            var errEl = document.getElementById('bhcore-2fa-error');
                            errEl.textContent = (res.data && res.data.message) || 'Invalid code.';
                            errEl.hidden = false;
                            return;
                        }
                        window.location.reload();
                    })
                    .catch(function () {
                        confirmBtn.disabled = false;
                        confirmBtn.textContent = originalLabel;
                        var errEl2 = document.getElementById('bhcore-2fa-error');
                        errEl2.textContent = 'Could not reach the server — check your connection and try again.';
                        errEl2.hidden = false;
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

    public static function ajax_start_enroll(): void {
        check_ajax_referer('bhcore_2fa', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id || !self::site_enabled()) wp_send_json_error(['message' => 'Not available.'], 403);

        // WHY: a fresh secret is stored as PENDING and only goes live after a
        // real code confirms it, so a botched scan cannot lock anyone out.
        $secret = self::generate_secret();
        update_user_meta($user_id, self::PENDING_META_KEY, $secret);

        wp_send_json_success([
            'secret' => $secret,
            'otpauth_uri' => self::otpauth_uri($secret, get_userdata($user_id)),
        ]);
    }

    public static function ajax_confirm_enroll(): void {
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

    public static function ajax_disable(): void {
        check_ajax_referer('bhcore_2fa', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error([], 403);
        delete_user_meta($user_id, self::SECRET_META_KEY);
        delete_user_meta($user_id, self::ENABLED_META_KEY);
        delete_user_meta($user_id, self::PENDING_META_KEY);
        // Always log disabling 2FA (rare, security-relevant).
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('warning', '2FA disabled for account.', ['user_id' => $user_id], 'Two-Factor');
        }
        wp_send_json_success();
    }

    /* ---------------- site-wide toggle ---------------- */

    public static function add_settings_page(): void {
        add_submenu_page('ous', 'Security', 'Security', 'manage_options', 'ous-security', [self::class, 'render_settings_page']);
    }

    public static function render_settings_page(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        $enabled = self::site_enabled();
        $enrolled_count = (int) (new WP_User_Query(['meta_key' => self::ENABLED_META_KEY, 'meta_value' => '1', 'fields' => 'ID', 'number' => 0, 'count_total' => true]))->get_total();

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

    public static function save_site_setting(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('bhcore_2fa_site_setting')) wp_die('Not allowed.');
        update_option(self::SITE_OPTION, !empty($_POST['bhcore_2fa_site_enabled']));
        wp_safe_redirect(admin_url('admin.php?page=ous-security&updated=1'));
        exit;
    }
}
