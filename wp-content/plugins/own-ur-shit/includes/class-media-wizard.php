<?php
if (!defined('ABSPATH')) exit;

/**
 * "It just works" guided media/CDN setup — Tier A from
 * ROADMAP-guided-setup-wizards.md, the concrete first slice of that
 * plan (see VISION.md's own "it just works" design principle for the
 * philosophy this implements). Wraps the already-installed Advanced
 * Media Offloader plugin rather than replacing it — same "own the
 * interface, don't reinvent the third-party piece" posture this
 * ecosystem already applies to WooCommerce. Writes DIRECTLY into
 * ADVMO's own option shape (`advmo_settings`/`advmo_credentials`,
 * confirmed by reading `GeneralSettings::sanitize()`/
 * `sanitize_credentials()`) and reuses ADVMO's own provider classes'
 * `checkConnection()` for real, live credential validation — never a
 * format-only check, per VISION.md's "validate in real time" rule.
 *
 * Deliberately NOT registered as a Debug Tools section — this is a
 * real end-user setup flow, not a dev/QA tool. Nested under the
 * 'own-ur-shit' top-level menu (the same parent Reports/Security
 * already use successfully), not under 'ous-debug' — this install has
 * a documented, still-unexplained WordPress-core bug where NEW
 * standalone submenu slugs under THAT specific parent silently
 * resolved to the wrong screen context (see VISION.md's own account
 * of the API Docs/Codebase Docs incident). Reusing an already-proven-
 * working parent sidesteps that risk entirely rather than re-testing
 * into it.
 *
 * Six providers covered — every one Advanced Media Offloader itself
 * ships a real integration for (confirmed by reading each provider
 * class's own credentialsField()): Cloudflare R2, Amazon S3, Backblaze
 * B2, DigitalOcean Spaces, Wasabi, and generic S3-compatible (MinIO/
 * OVHcloud/etc). Azure Blob Storage is NOT in this list — Advanced
 * Media Offloader has no Azure integration at all (it only speaks the
 * S3 API), so an "Azure" option here would be fake. Stated honestly in
 * the UI rather than silently omitted.
 */
class OUS_MediaWizard {
    // key => [name, recommended, credential fields, dashboard link, help text]
    // Field shapes copied directly from each ADVMO provider class's own
    // credentialsField() — a deliberate, small, static duplication of a
    // stable third-party surface (see this file's own docblock), not a
    // live reflection hack against another plugin's private methods.
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

    public static function init() {
        if (!class_exists('\Advanced_Media_Offloader\Factories\CloudProviderFactory')) return; // harmless no-op if ADVMO isn't installed/active — same class_exists()-guard posture as every other cross-plugin touch in this ecosystem
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_ous_media_wizard_save', [self::class, 'handle_save']);
    }

    public static function add_menu() {
        add_submenu_page('own-ur-shit', 'Media & CDN Setup', 'Media & CDN Setup', 'manage_options', 'ous-media-setup', [self::class, 'render']);
    }

    public static function render() {
        if (!current_user_can('manage_options')) wp_die('Not allowed.', '', ['response' => 403, 'back_link' => true]);

        $settings = get_option('advmo_settings', []);
        $current_provider = $settings['cloud_provider'] ?? '';
        $credentials = get_option('advmo_credentials', []);
        $test_result = get_transient('ous_media_wizard_test_result');
        delete_transient('ous_media_wizard_test_result');

        echo '<div class="wrap"><h1>Media &amp; CDN Setup</h1>';
        echo '<p class="description">Part of the Own Ur Shit ecosystem — see <code>ROADMAP-guided-setup-wizards.md</code> for the full plan this implements.</p>';

        if ($current_provider) {
            $label = self::PROVIDERS[$current_provider]['name'] ?? $current_provider;
            echo '<div class="notice notice-success" style="padding:12px;"><p><strong>Currently connected:</strong> ' . esc_html($label) . '. New uploads offload automatically.' . (!empty($settings['auto_offload_uploads']) ? '' : ' <strong>Auto-offload is currently OFF</strong> — existing media stays local until you re-save below.') . '</p></div>';
        }

        if ($test_result) {
            $class = $test_result['success'] ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . '" style="padding:12px;"><p>' . ($test_result['success'] ? '&#9989; ' : '&#10060; ') . esc_html($test_result['message']) . '</p></div>';
        }

        echo '<div class="bhy-alert" style="border-left:3px solid #2271b1;background:#f6f7f7;padding:14px;margin:16px 0;max-width:760px;">';
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

        echo '</div>';
    }

    public static function handle_save() {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_POST['ous_media_wizard_nonce'] ?? '', 'ous_media_wizard_save')) {
            wp_die('Security check failed.', '', ['response' => 403, 'back_link' => true]);
        }

        $provider = sanitize_key($_POST['ous_provider'] ?? '');
        if (!isset(self::PROVIDERS[$provider])) {
            wp_die('Unknown provider.', '', ['response' => 400, 'back_link' => true]);
        }

        // Merge onto existing credentials for every provider (not just
        // this one) — matches ADVMO's own sanitize_credentials()
        // behavior exactly, so switching providers later doesn't wipe
        // out a previously-configured one. A blank password field means
        // "keep what's already saved" (the UI never re-displays a saved
        // secret), never "clear it".
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

        // Same option shape GeneralSettings::sanitize() writes —
        // auto_offload_uploads on by default so this actually takes
        // effect for new uploads immediately, not just "saved but
        // inactive until a second settings visit."
        $settings = get_option('advmo_settings', []);
        $settings['cloud_provider'] = $provider;
        if (!isset($settings['auto_offload_uploads'])) $settings['auto_offload_uploads'] = 1;
        update_option('advmo_settings', $settings);

        // Real, live connection test — reuses ADVMO's own provider
        // class (checkConnection() does a real headBucket() S3 call,
        // confirmed by reading S3_Provider::checkConnection() — always
        // a plain true/false, never an array) rather than
        // re-implementing an S3 client call here. Per VISION.md's
        // "validate in real time" rule: this is a real API call, not a
        // format check.
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
