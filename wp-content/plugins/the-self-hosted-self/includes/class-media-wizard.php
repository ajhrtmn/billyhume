<?php
if (!defined('ABSPATH')) exit;

/**
 * Guided media/CDN setup wrapping Advanced Media Offloader (ADVMO): writes ADVMO's own
 * `advmo_settings`/`advmo_credentials` options and validates with its providers' checkConnection().
 * Also hosts the optional Cloudflare Stream (Tier B) step.
 *
 * WHY: a submenu under 'the-self-hosted-self', not 'ous-debug' — new submenu slugs under
 * 'ous-debug' have resolved to the wrong screen on this install.
 * No Azure option: ADVMO only speaks the S3 API.
 */
class OUS_MediaWizard {
    // key => [name, recommended, credential fields, dashboard link, help text].
    // Field shapes copied from each ADVMO provider's credentialsField() (small, stable duplication).
    const PROVIDERS = [
        'cloudflare_r2' => [
            'name' => 'Cloudflare R2',
            'recommended' => true,
            'why' => 'No egress (bandwidth) fees at all — the standout choice for video specifically, since a popular long video can otherwise rack up real bandwidth cost fast on almost every other provider.',
            'dashboard_url' => 'https://dash.cloudflare.com/?to=/:account/r2/api-tokens',
            'dashboard_label' => 'Cloudflare dashboard → R2 → Manage API Tokens',
            'fields' => [
                ['name' => 'key', 'label' => 'Access Key ID', 'type' => 'text'],
                ['name' => 'secret', 'label' => 'Secret Access Key', 'type' => 'password'],
                ['name' => 'endpoint', 'label' => 'Endpoint URL', 'type' => 'text', 'placeholder' => 'https://your-account-id.r2.cloudflarestorage.com'],
                ['name' => 'bucket', 'label' => 'Bucket Name', 'type' => 'text'],
                ['name' => 'domain', 'label' => 'Custom Domain (CDN URL, optional but recommended)', 'type' => 'text', 'placeholder' => 'https://media.yourdomain.com'],
            ],
        ],
        'aws_s3' => [
            'name' => 'Amazon S3',
            'recommended' => false,
            'why' => 'The most widely documented option, and pairs with CloudFront for a CDN — but S3 itself charges for egress bandwidth, which adds up faster with video than with images.',
            'dashboard_url' => 'https://console.aws.amazon.com/iam/home#/security_credentials',
            'dashboard_label' => 'AWS Console → IAM → Security Credentials',
            'fields' => [
                ['name' => 'key', 'label' => 'Access Key ID', 'type' => 'text'],
                ['name' => 'secret', 'label' => 'Secret Access Key', 'type' => 'password'],
                ['name' => 'bucket', 'label' => 'Bucket Name', 'type' => 'text'],
                ['name' => 'region', 'label' => 'Region', 'type' => 'text', 'placeholder' => 'us-east-1'],
                ['name' => 'domain', 'label' => 'Custom Domain (CDN URL, optional)', 'type' => 'text', 'placeholder' => 'https://media.yourdomain.com'],
            ],
        ],
        'backblaze_b2' => [
            'name' => 'Backblaze B2',
            'recommended' => false,
            'why' => 'Cheap storage, and free egress when paired with Cloudflare\'s CDN specifically (the "Bandwidth Alliance") — a strong budget pick if a Cloudflare-fronted setup appeals but R2 itself doesn\'t fit for some reason.',
            'dashboard_url' => 'https://secure.backblaze.com/app_keys.htm',
            'dashboard_label' => 'Backblaze → App Keys',
            'fields' => [
                ['name' => 'key', 'label' => 'Application Key ID', 'type' => 'text'],
                ['name' => 'secret', 'label' => 'Application Key', 'type' => 'password'],
                ['name' => 'endpoint', 'label' => 'S3 Endpoint URL', 'type' => 'text', 'placeholder' => 'https://s3.us-west-004.backblazeb2.com'],
                ['name' => 'region', 'label' => 'Region', 'type' => 'text', 'placeholder' => 'us-west-004'],
                ['name' => 'bucket', 'label' => 'Bucket Name', 'type' => 'text'],
                ['name' => 'domain', 'label' => 'Custom Domain (CDN URL, optional)', 'type' => 'text', 'placeholder' => 'https://media.yourdomain.com'],
            ],
        ],
        'digitalocean' => [
            'name' => 'DigitalOcean Spaces',
            'recommended' => false,
            'why' => 'A flat, predictable monthly price that includes a bandwidth allowance — a reasonable pick if you\'re already hosting on DigitalOcean and want one bill.',
            'dashboard_url' => 'https://cloud.digitalocean.com/account/api/spaces',
            'dashboard_label' => 'DigitalOcean → API → Spaces Keys',
            'fields' => [
                ['name' => 'key', 'label' => 'Access Key ID', 'type' => 'text'],
                ['name' => 'secret', 'label' => 'Secret Access Key', 'type' => 'password'],
                ['name' => 'endpoint', 'label' => 'Endpoint URL', 'type' => 'text', 'placeholder' => 'https://nyc3.digitaloceanspaces.com'],
                ['name' => 'bucket', 'label' => 'Bucket Name', 'type' => 'text'],
                ['name' => 'domain', 'label' => 'Custom Domain (CDN URL, optional)', 'type' => 'text', 'placeholder' => 'https://media.yourdomain.com'],
            ],
        ],
        'wasabi' => [
            'name' => 'Wasabi',
            'recommended' => false,
            'why' => 'Flat pricing with no egress fee up to a generous multiple of what you store — a real budget option, worth a look if R2 doesn\'t fit.',
            'dashboard_url' => 'https://console.wasabisys.com/#/access-keys',
            'dashboard_label' => 'Wasabi Console → Access Keys',
            'fields' => [
                ['name' => 'key', 'label' => 'Access Key ID', 'type' => 'text'],
                ['name' => 'secret', 'label' => 'Secret Access Key', 'type' => 'password'],
                ['name' => 'bucket', 'label' => 'Bucket Name', 'type' => 'text'],
                ['name' => 'region', 'label' => 'Region', 'type' => 'text', 'placeholder' => 'us-east-1'],
                ['name' => 'domain', 'label' => 'Custom Domain (CDN URL, optional)', 'type' => 'text', 'placeholder' => 'https://media.yourdomain.com'],
            ],
        ],
        'minio' => [
            'name' => 'Other S3-compatible storage',
            'recommended' => false,
            'why' => 'For a self-hosted MinIO server, or any other provider (OVHcloud, Scaleway, etc.) that speaks the S3 API.',
            'dashboard_url' => '',
            'dashboard_label' => '',
            'fields' => [
                ['name' => 'key', 'label' => 'Access Key ID', 'type' => 'text'],
                ['name' => 'secret', 'label' => 'Secret Access Key', 'type' => 'password'],
                ['name' => 'endpoint', 'label' => 'S3 Endpoint URL', 'type' => 'text'],
                ['name' => 'region', 'label' => 'Region', 'type' => 'text', 'placeholder' => 'us-east-1'],
                ['name' => 'bucket', 'label' => 'Bucket Name', 'type' => 'text'],
                ['name' => 'domain', 'label' => 'Custom Domain (CDN URL, optional)', 'type' => 'text', 'placeholder' => 'https://media.yourdomain.com'],
            ],
        ],
    ];

    public static function init(): void {
        if (class_exists('\Advanced_Media_Offloader\Factories\CloudProviderFactory')) {
            add_action('admin_post_ous_media_wizard_save', [self::class, 'handle_save']);
        }
        // Always registered: Tier B doesn't depend on ADVMO.
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_ous_media_wizard_save_cloudflare_stream', [self::class, 'handle_save_cloudflare_stream']);
    }

    public static function add_menu(): void {
        add_submenu_page('ous', 'Media & CDN Setup', 'Media & CDN Setup', 'manage_options', 'ous-media-setup', [self::class, 'render']);
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.', '', ['response' => 403, 'back_link' => true]);

        $has_advmo = class_exists('\Advanced_Media_Offloader\Factories\CloudProviderFactory');

        $settings = get_option('advmo_settings', []);
        $current_provider = $settings['cloud_provider'] ?? '';
        $credentials = get_option('advmo_credentials', []);
        $test_result = get_transient('ous_media_wizard_test_result');
        delete_transient('ous_media_wizard_test_result');

        echo '<div class="wrap"><h1>Media &amp; CDN Setup</h1>';

        if ($has_advmo && $current_provider) {
            $label = self::PROVIDERS[$current_provider]['name'] ?? $current_provider;
            echo '<div class="notice notice-success" style="padding:12px;"><p><strong>Currently connected:</strong> ' . esc_html($label) . '. New uploads offload automatically.' . (!empty($settings['auto_offload_uploads']) ? '' : ' <strong>Auto-offload is currently OFF</strong> — existing media stays local until you re-save below.') . '</p></div>';
        }

        if ($test_result) {
            $class = $test_result['success'] ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . '" style="padding:12px;"><p>' . ($test_result['success'] ? '&#9989; ' : '&#10060; ') . esc_html($test_result['message']) . '</p></div>';
        }

        if ($has_advmo) {
        echo '<div class="bhy-alert bhy-alert-info" style="max-width:760px;">';
        echo '<p><strong>Why this matters, especially for video:</strong> a single long, high-resolution video served straight from ordinary WordPress hosting can eat up your host\'s bandwidth allowance fast, or slow down for everyone else on shared hosting. Moving media to real object storage behind a CDN fixes both — your own server just stores a pointer, the CDN does the heavy lifting, and viewers get fast, reliable playback (including proper seeking/scrubbing) regardless of file size.</p>';
        echo '</div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="ous-media-wizard-form" style="max-width:760px;">';
        wp_nonce_field('ous_media_wizard_save', 'ous_media_wizard_nonce');
        echo '<input type="hidden" name="action" value="ous_media_wizard_save">';

        echo '<h2>1. Choose a provider</h2>';
        echo '<div class="ous-wizard-providers" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">';
        foreach (self::PROVIDERS as $key => $p) {
            $checked = ($current_provider === $key) || (!$current_provider && $key === 'cloudflare_r2');
            echo '<label style="display:block;border:2px solid ' . ($checked ? '#2271b1' : '#dcdcde') . ';border-radius:8px;padding:12px 14px;cursor:pointer;background:#fff;">';
            echo '<input type="radio" name="ous_provider" value="' . esc_attr($key) . '" class="ous-provider-radio"' . checked($checked, true, false) . '> ';
            echo '<strong>' . esc_html($p['name']) . '</strong>' . ($p['recommended'] ? ' <span style="background:#2271b1;color:#fff;font-size:11px;padding:2px 8px;border-radius:999px;">Recommended</span>' : '');
            echo '<p class="description" style="margin:6px 0 0;">' . esc_html($p['why']) . '</p>';
            echo '</label>';
        }
        echo '</div>';

        echo '<h2>2. Enter your credentials</h2>';
        foreach (self::PROVIDERS as $key => $p) {
            $display = ($current_provider === $key) || (!$current_provider && $key === 'cloudflare_r2') ? '' : 'display:none;';
            echo '<div class="ous-provider-fields" data-provider="' . esc_attr($key) . '" style="' . $display . 'border:1px solid #dcdcde;border-radius:8px;padding:16px;margin-bottom:16px;background:#fff;">';
            if ($p['dashboard_url']) {
                echo '<p><a class="button" href="' . esc_url($p['dashboard_url']) . '" target="_blank" rel="noopener">&rarr; Get your ' . esc_html($p['name']) . ' credentials (' . esc_html($p['dashboard_label']) . ')</a></p>';
            }
            $saved = $credentials[$key] ?? [];
            foreach ($p['fields'] as $f) {
                $val = $saved[$f['name']] ?? '';
                $placeholder = $f['placeholder'] ?? '';
                echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html($f['label']) . '<br>';
                echo '<input type="' . esc_attr($f['type']) . '" name="ous_cred[' . esc_attr($key) . '][' . esc_attr($f['name']) . ']" value="' . esc_attr($f['type'] === 'password' ? '' : $val) . '" placeholder="' . esc_attr($placeholder) . '" style="width:100%;max-width:480px;" autocomplete="off">';
                if ($f['type'] === 'password' && $val) echo '<span class="description"> (already set — leave blank to keep it)</span>';
                echo '</label></p>';
            }
            echo '</div>';
        }

        echo '<p><button type="submit" class="button button-primary button-hero">Save &amp; test connection</button></p>';
        echo '</form>';

        echo '<script>
        (function () {
            var radios = document.querySelectorAll(".ous-provider-radio");
            radios.forEach(function (r) {
                r.addEventListener("change", function () {
                    document.querySelectorAll(".ous-wizard-providers label").forEach(function (l) { l.style.borderColor = "#dcdcde"; });
                    r.closest("label").style.borderColor = "#2271b1";
                    document.querySelectorAll(".ous-provider-fields").forEach(function (block) {
                        block.style.display = (block.dataset.provider === r.value) ? "" : "none";
                    });
                });
            });
        })();
        </script>';
        } // end if ($has_advmo)

        self::render_tier_b_section();

        /** Lets other core services (e.g. BHY_MediaToken) add a section to this page, inside .wrap. */
        do_action('ous_media_setup_after');

        echo '</div>';
    }

    /**
     * Tier B: Cloudflare Stream adaptive on-demand video, strictly opt-in (ongoing per-minute cost).
     * Settings + live connection test only; per-plugin upload/player wiring is not built. Exposes
     * tier_b_enabled()/cloudflare_stream_credentials() and vendored hls.js for that later integration.
     */
    private static function render_tier_b_section(): void {
        $enabled = (bool) get_option('ous_cf_stream_enabled', false);
        $creds = get_option('ous_cf_stream_credentials', []);
        $test_result = get_transient('ous_cf_stream_test_result');
        delete_transient('ous_cf_stream_test_result');

        echo '<hr style="max-width:760px;margin:32px 0;">';
        echo '<h2>Tier B: Cloudflare Stream (optional, adaptive-bitrate video)</h2>';
        echo '<div class="bhy-alert bhy-alert-info" style="max-width:760px;">';
        echo '<p><strong>What this adds over Tier A:</strong> automatic transcoding and real adaptive bitrate — a viewer on a slow connection automatically gets a lower-quality rendition instead of buffering. <strong>What it costs:</strong> roughly $5 per 1,000 minutes stored and $1 per 1,000 minutes delivered (Cloudflare\'s own current pricing — verify before relying on this exact figure, pricing changes). Tier A (above) already gives you fast, reliable playback via CDN + object storage at no extra per-minute cost — only turn this on if long/high-resolution video specifically needs adaptive bitrate.</p>';
        echo '</div>';

        if ($test_result) {
            $class = $test_result['success'] ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . '" style="padding:12px;max-width:760px;"><p>' . ($test_result['success'] ? '&#9989; ' : '&#10060; ') . esc_html($test_result['message']) . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:760px;">';
        wp_nonce_field('ous_media_wizard_save_cloudflare_stream', 'ous_media_wizard_cf_stream_nonce');
        echo '<input type="hidden" name="action" value="ous_media_wizard_save_cloudflare_stream">';

        echo '<p><label><input type="checkbox" name="cf_stream_enabled" value="1"' . checked($enabled, true, false) . '> Enable Cloudflare Stream as an available option for video-heavy plugins (opt-in per plugin/upload — this switch alone doesn\'t move anything).</label></p>';

        echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Cloudflare Account ID<br>';
        echo '<input type="text" name="cf_account_id" value="' . esc_attr($creds['account_id'] ?? '') . '" style="width:100%;max-width:480px;" autocomplete="off"></label></p>';

        echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">API Token (Stream:Edit permission)<br>';
        echo '<input type="password" name="cf_api_token" value="" placeholder="' . (!empty($creds['api_token']) ? 'Already set — leave blank to keep it' : '') . '" style="width:100%;max-width:480px;" autocomplete="off"></label></p>';

        echo '<p><button type="submit" class="button button-primary">Save &amp; test connection</button></p>';
        echo '</form>';
    }

    /** Public accessor for a future per-plugin Cloudflare Stream integration. */
    public static function tier_b_enabled(): bool {
        return (bool) get_option('ous_cf_stream_enabled', false);
    }

    /** @return array{account_id:string, api_token:string} */
    public static function cloudflare_stream_credentials(): array {
        return get_option('ous_cf_stream_credentials', ['account_id' => '', 'api_token' => '']);
    }

    /** Vendored hls.js (assets/js/vendor/hls.min.js): HLS playback outside Safari. */
    public static function enqueue_hls_js(): void {
        wp_enqueue_script('ous-hls-js', OUS_URL . 'assets/js/vendor/hls.min.js', [], '1.6.16', true);
    }

    public static function handle_save_cloudflare_stream(): void {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_POST['ous_media_wizard_cf_stream_nonce'] ?? '', 'ous_media_wizard_save_cloudflare_stream')) {
            wp_die('Security check failed.', '', ['response' => 403, 'back_link' => true]);
        }

        $enabled = !empty($_POST['cf_stream_enabled']);
        $account_id = sanitize_text_field(wp_unslash($_POST['cf_account_id'] ?? ''));
        $existing = get_option('ous_cf_stream_credentials', []);
        $api_token = wp_unslash($_POST['cf_api_token'] ?? '');
        $api_token = $api_token !== '' ? $api_token : ($existing['api_token'] ?? '');

        update_option('ous_cf_stream_enabled', $enabled);
        update_option('ous_cf_stream_credentials', ['account_id' => $account_id, 'api_token' => $api_token]);

        // Live validation: a plain GET against the account's Stream endpoint proves the token/account pair.
        $result = ['success' => false, 'message' => 'Enter both an Account ID and an API Token to test the connection.'];
        if ($account_id && $api_token) {
            $response = wp_remote_get(
                'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode($account_id) . '/stream?per_page=1',
                ['headers' => ['Authorization' => 'Bearer ' . $api_token], 'timeout' => 10]
            );
            if (is_wp_error($response)) {
                $result = ['success' => false, 'message' => 'Connection failed: ' . $response->get_error_message()];
            } else {
                $code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $result = ($code === 200 && !empty($body['success']))
                    ? ['success' => true, 'message' => 'Connected to Cloudflare Stream successfully.']
                    : ['success' => false, 'message' => 'Cloudflare rejected the request (HTTP ' . $code . ') — check the Account ID and API Token.'];
            }
        }
        set_transient('ous_cf_stream_test_result', $result, 60);

        wp_safe_redirect(admin_url('admin.php?page=ous-media-setup'));
        exit;
    }

    public static function handle_save(): void {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_POST['ous_media_wizard_nonce'] ?? '', 'ous_media_wizard_save')) {
            wp_die('Security check failed.', '', ['response' => 403, 'back_link' => true]);
        }

        $provider = sanitize_key($_POST['ous_provider'] ?? '');
        if (!isset(self::PROVIDERS[$provider])) {
            wp_die('Unknown provider.', '', ['response' => 400, 'back_link' => true]);
        }

        // Merge onto all providers' credentials, like ADVMO's sanitize_credentials(), so switching
        // providers keeps others. A blank secret field means keep the saved value.
        $existing = get_option('advmo_credentials', []);
        $posted = $_POST['ous_cred'][$provider] ?? [];
        $clean = $existing[$provider] ?? [];
        foreach (self::PROVIDERS[$provider]['fields'] as $f) {
            $raw = isset($posted[$f['name']]) ? wp_unslash($posted[$f['name']]) : '';
            if ($f['type'] === 'password' && $raw === '') continue; // keep existing secret
            $is_url_field = in_array($f['name'], ['endpoint', 'domain'], true);
            $clean[$f['name']] = $is_url_field ? esc_url_raw($raw) : sanitize_text_field($raw);
        }
        $existing[$provider] = $clean;
        update_option('advmo_credentials', $existing);

        // Same shape GeneralSettings::sanitize() writes; auto_offload_uploads on so new uploads offload immediately.
        $settings = get_option('advmo_settings', []);
        $settings['cloud_provider'] = $provider;
        if (!isset($settings['auto_offload_uploads'])) $settings['auto_offload_uploads'] = 1;
        update_option('advmo_settings', $settings);

        // Live test via ADVMO's provider checkConnection() (a real headBucket(); returns bool).
        $result = ['success' => false, 'message' => 'Could not test the connection.'];
        try {
            $factory = new \Advanced_Media_Offloader\Factories\CloudProviderFactory();
            $cloud_provider = $factory::create($provider);
            $result = $cloud_provider->checkConnection()
                ? ['success' => true, 'message' => 'Connected successfully — ' . self::PROVIDERS[$provider]['name'] . ' is ready.']
                : ['success' => false, 'message' => 'Connection failed — double-check your credentials and bucket name.'];
        } catch (\Throwable $e) {
            $result = ['success' => false, 'message' => 'Connection failed: ' . $e->getMessage()];
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log_exception($e, 'OUS_MediaWizard');
            }
        }
        set_transient('ous_media_wizard_test_result', $result, 60);

        wp_safe_redirect(admin_url('admin.php?page=ous-media-setup'));
        exit;
    }
}
