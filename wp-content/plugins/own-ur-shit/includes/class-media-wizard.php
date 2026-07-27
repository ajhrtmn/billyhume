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
        // The live-engine step (below) is independent of ADVMO — a
        // site running bh-live without ADVMO installed still gets that
        // one step, so the early-return only ever skips the
        // storage/CDN section, never this whole class.
        if (class_exists('\Advanced_Media_Offloader\Factories\CloudProviderFactory')) {
            add_action('admin_post_ous_media_wizard_save', [self::class, 'handle_save']);
        }
        if (class_exists('\Advanced_Media_Offloader\Factories\CloudProviderFactory') || class_exists('BHL_EngineRegistry')) {
            add_action('admin_menu', [self::class, 'add_menu']);
        }
        if (class_exists('BHL_EngineRegistry')) {
            add_action('admin_post_ous_media_wizard_save_engine_choice', [self::class, 'handle_save_engine_choice']);
            add_action('admin_post_ous_media_wizard_save_chat_choice', [self::class, 'handle_save_chat_choice']);
        }
        if (class_exists('BHL_OwncastEngine')) {
            add_action('admin_post_ous_media_wizard_save_live', [self::class, 'handle_save_live']);
        }
        if (class_exists('BHL_CloudflareStreamEngine')) {
            add_action('admin_post_ous_media_wizard_save_cloudflare', [self::class, 'handle_save_cloudflare']);
            add_action('admin_post_ous_media_wizard_create_cf_input', [self::class, 'handle_create_cloudflare_input']);
        }
        if (class_exists('BHL_FlyProvisioner')) {
            add_action('admin_post_ous_media_wizard_save_provisioner', [self::class, 'handle_save_provisioner']);
            add_action('admin_post_ous_media_wizard_deploy_host', [self::class, 'handle_deploy_host']);
            add_action('admin_post_ous_media_wizard_destroy_host', [self::class, 'handle_destroy_host']);
        }
    }

    // key => [name, class implementing BHL_HostProvisioner, why, fields].
    // Only Fly.io exists today — the registry shape itself (matching
    // PROVIDERS above) is what makes adding a second provider later
    // just another array entry plus a class, not a rewrite of this
    // section's rendering/save logic.
    const HOST_PROVISIONERS = [
        'fly' => [
            'name' => 'Fly.io',
            'class' => 'BHL_FlyProvisioner',
            'why' => 'Machines start in under a second and can be stopped between streams — you only pay while actually live, not for an always-on box the way a plain VPS charges 24/7.',
            'fields' => [
                ['name' => 'api_token', 'label' => 'Fly API Token', 'type' => 'password'],
                ['name' => 'app_name', 'label' => 'App Name', 'type' => 'text', 'placeholder' => 'e.g. yourname-live (must be globally unique across all of Fly.io)'],
                ['name' => 'org_slug', 'label' => 'Organization Slug', 'type' => 'text', 'placeholder' => 'personal'],
                ['name' => 'region', 'label' => 'Region', 'type' => 'text', 'placeholder' => 'iad'],
            ],
        ],
    ];

    public static function add_menu() {
        add_submenu_page('own-ur-shit', 'Media & CDN Setup', 'Media & CDN Setup', 'manage_options', 'ous-media-setup', [self::class, 'render']);
    }

    public static function render() {
        if (!current_user_can('manage_options')) wp_die('Not allowed.', '', ['response' => 403, 'back_link' => true]);

        $has_advmo = class_exists('\Advanced_Media_Offloader\Factories\CloudProviderFactory');
        $has_live = class_exists('BHL_EngineRegistry');

        $settings = get_option('advmo_settings', []);
        $current_provider = $settings['cloud_provider'] ?? '';
        $credentials = get_option('advmo_credentials', []);
        $test_result = get_transient('ous_media_wizard_test_result');
        delete_transient('ous_media_wizard_test_result');

        echo '<div class="wrap"><h1>Media &amp; CDN Setup</h1>';
        echo '<p class="description">Part of the Own Ur Shit ecosystem — see <code>ROADMAP-guided-setup-wizards.md</code> for the full plan this implements.</p>';

        if ($has_advmo && $current_provider) {
            $label = self::PROVIDERS[$current_provider]['name'] ?? $current_provider;
            echo '<div class="notice notice-success" style="padding:12px;"><p><strong>Currently connected:</strong> ' . esc_html($label) . '. New uploads offload automatically.' . (!empty($settings['auto_offload_uploads']) ? '' : ' <strong>Auto-offload is currently OFF</strong> — existing media stays local until you re-save below.') . '</p></div>';
        }

        if ($test_result) {
            $class = $test_result['success'] ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . '" style="padding:12px;"><p>' . ($test_result['success'] ? '&#9989; ' : '&#10060; ') . esc_html($test_result['message']) . '</p></div>';
        }

        if ($has_advmo) {
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
        } // end if ($has_advmo)

        if ($has_live) {
            self::render_live_section();
        }

        echo '</div>';
    }

    /**
     * Live streaming's own step in the same one wizard (AJ's own call,
     * 2026-07-26: "For VODs and for live, we should have wizards for
     * the required upgrading to media hosting offloading ... it should
     * still all be managed from the one place here") — not a second
     * settings screen. Owns the 'bhl_owncast_settings' option's shape;
     * BHL_OwncastEngine (bh-live's own class) is the only other reader/
     * writer of that option, and only ever through save_settings()/
     * settings(), so this form is the one place the shape is defined.
     */
    private static function render_live_section() {
        $active_key = BHL_EngineRegistry::active_key();

        echo '<hr style="max-width:760px;margin:32px 0;">';
        echo '<h2>Live Streaming</h2>';
        echo '<div class="bhy-alert" style="border-left:3px solid #2271b1;background:#f6f7f7;padding:14px;margin:16px 0;max-width:760px;">';
        echo '<p><strong>Why this is separate from storage above:</strong> real-time live video (RTMP ingest, transcoding, low-latency delivery) genuinely can\'t run on ordinary shared WordPress hosting. Two real choices, opposite tradeoffs: <strong>Owncast</strong> is free and self-hosted, but needs its own server somewhere (deploy one below, or self-host it yourself); <strong>Cloudflare Stream Live</strong> is fully managed (no server at all to run), metered per minute, and video-only (no bundled chat).</p>';
        echo '</div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:760px;margin-bottom:20px;">';
        wp_nonce_field('ous_media_wizard_save_engine_choice', 'ous_media_wizard_engine_nonce');
        echo '<input type="hidden" name="action" value="ous_media_wizard_save_engine_choice">';
        foreach (BHL_EngineRegistry::ENGINES as $key => $info) {
            echo '<label style="display:block;margin-bottom:6px;"><input type="radio" name="bhl_engine" value="' . esc_attr($key) . '" onchange="this.form.submit()"' . checked($active_key, $key, false) . '> ' . esc_html($info['label']) . '</label>';
        }
        echo '<noscript><button type="submit" class="button">Switch engine</button></noscript>';
        echo '</form>';

        if ($active_key === 'owncast' && class_exists('BHL_OwncastEngine')) {
            self::render_owncast_section();
            if (class_exists('BHL_FlyProvisioner')) self::render_provisioner_section();
        } elseif ($active_key === 'cloudflare' && class_exists('BHL_CloudflareStreamEngine')) {
            self::render_cloudflare_section();
        }

        self::render_chat_choice_section();
    }

    /**
     * Owns the 'bhl_owncast_settings' option's shape; BHL_OwncastEngine
     * (bh-live's own class) is the only other reader/writer of that
     * option, and only ever through save_settings()/settings(), so
     * this form is the one place the shape is defined.
     */
    private static function render_owncast_section() {
        $s = BHL_OwncastEngine::settings();
        $status_result = get_transient('ous_media_wizard_live_test_result');
        delete_transient('ous_media_wizard_live_test_result');

        echo '<h3>Owncast connection</h3>';
        echo '<p class="description" style="max-width:760px;">Connects to an <a href="https://owncast.online/docs/quickstart/" target="_blank" rel="noopener">Owncast</a> server (a real, free, self-hosted "your own Twitch") — this site just embeds its player and reads its live status. Streaming starts the moment your broadcasting software (e.g. OBS) connects to that server with its stream key; there\'s no "start" button here.</p>';

        if ($status_result) {
            $class = $status_result['success'] ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . '" style="padding:12px;max-width:760px;"><p>' . ($status_result['success'] ? '&#9989; ' : '&#10060; ') . esc_html($status_result['message']) . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:760px;">';
        wp_nonce_field('ous_media_wizard_save_live', 'ous_media_wizard_live_nonce');
        echo '<input type="hidden" name="action" value="ous_media_wizard_save_live">';
        echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Owncast Server URL<br>';
        echo '<input type="text" name="bhl_server_url" value="' . esc_attr($s['server_url']) . '" placeholder="https://live.yourdomain.com" style="width:100%;max-width:480px;"></label></p>';
        echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Admin Access Token <span class="description">(optional — only needed to force-end a stream from here)</span><br>';
        echo '<input type="password" name="bhl_access_token" value="" placeholder="' . (!empty($s['access_token']) ? 'already set — leave blank to keep it' : '') . '" style="width:100%;max-width:480px;" autocomplete="off"></label></p>';
        echo '<p><button type="submit" class="button button-primary button-hero">Save &amp; test connection</button></p>';
        echo '</form>';
    }

    /**
     * Cloudflare Stream Live needs no BHL_HostProvisioner section at
     * all — there's no box, "deploying" is just the one-time
     * create-live-input API call below, which returns an RTMP URL +
     * stream key to paste into OBS, same role a self-hosted Owncast
     * server's own stream key plays.
     */
    private static function render_cloudflare_section() {
        $s = BHL_CloudflareStreamEngine::settings();
        $result = get_transient('ous_media_wizard_cf_result');
        delete_transient('ous_media_wizard_cf_result');

        echo '<h3>Cloudflare Stream Live connection</h3>';
        echo '<p class="description" style="max-width:760px;">Needs a Cloudflare account with Stream enabled. Find your Account ID and create an API token (Stream:Edit permission) in the Cloudflare dashboard; the "customer code" is the subdomain Cloudflare gives your account\'s stream playback URLs (also on the Stream dashboard).</p>';

        if ($result) {
            $class = $result['success'] ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . '" style="padding:12px;max-width:760px;"><p>' . ($result['success'] ? '&#9989; ' : '&#10060; ') . esc_html($result['message']) . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:760px;">';
        wp_nonce_field('ous_media_wizard_save_cloudflare', 'ous_media_wizard_cf_nonce');
        echo '<input type="hidden" name="action" value="ous_media_wizard_save_cloudflare">';
        echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Account ID<br>';
        echo '<input type="text" name="cf_account_id" value="' . esc_attr($s['account_id']) . '" style="width:100%;max-width:480px;"></label></p>';
        echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">API Token<br>';
        echo '<input type="password" name="cf_api_token" value="" placeholder="' . (!empty($s['api_token']) ? 'already set — leave blank to keep it' : '') . '" style="width:100%;max-width:480px;" autocomplete="off"></label></p>';
        echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Customer Code<br>';
        echo '<input type="text" name="cf_customer_code" value="' . esc_attr($s['customer_code']) . '" style="width:100%;max-width:480px;"></label></p>';
        echo '<p><button type="submit" class="button button-primary">Save connection settings</button></p>';
        echo '</form>';

        if (!empty($s['account_id']) && !empty($s['api_token'])) {
            if (!empty($s['live_input_uid'])) {
                echo '<p><strong>Live input:</strong> <code>' . esc_html($s['live_input_uid']) . '</code></p>';
                echo '<p><strong>RTMP URL:</strong> <code>' . esc_html($s['rtmps_url']) . '</code><br><strong>Stream Key:</strong> <code>' . esc_html($s['stream_key']) . '</code> — paste both into OBS.</p>';
            }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('ous_media_wizard_create_cf_input', 'ous_media_wizard_cf_input_nonce');
            echo '<input type="hidden" name="action" value="ous_media_wizard_create_cf_input">';
            echo '<button type="submit" class="button">' . (!empty($s['live_input_uid']) ? 'Create a new live input (replaces the one above)' : 'Create live input') . '</button>';
            echo '</form>';
        }
    }

    /**
     * "Levels of it just works to fit every need and budget" — AJ's
     * own ask, 2026-07-26. Only shows chat options actually compatible
     * with whichever engine is active right now (BHL_EngineRegistry::
     * available_chat_options()) — Owncast's own bundled chat only ever
     * appears as a choice when Owncast itself is the active engine.
     */
    private static function render_chat_choice_section() {
        $options = BHL_EngineRegistry::available_chat_options();
        if (count($options) < 2) return; // nothing to choose between (e.g. Owncast's own bundled chat is the only option today unless bh-live's other chat classes are also active)

        $active_chat = BHL_EngineRegistry::active_chat_key();
        echo '<h3>Chat</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:760px;">';
        wp_nonce_field('ous_media_wizard_save_chat_choice', 'ous_media_wizard_chat_nonce');
        echo '<input type="hidden" name="action" value="ous_media_wizard_save_chat_choice">';
        foreach ($options as $key => $info) {
            echo '<label style="display:block;margin-bottom:6px;"><input type="radio" name="bhl_chat" value="' . esc_attr($key) . '" onchange="this.form.submit()"' . checked($active_chat, $key, false) . '> ' . esc_html($info['label']) . '</label>';
        }
        echo '<noscript><button type="submit" class="button">Switch chat</button></noscript>';
        echo '</form>';

        if ($active_chat === 'workers' && class_exists('BHL_WorkersChat')) {
            self::render_workers_chat_section();
        }
    }

    /**
     * "Administered from the WP plugin, hosted elsewhere" — AJ's own
     * ask. Deploy/destroy the actual box Owncast runs on directly from
     * this screen, via BHL_HostProvisioner (a real API call, not a
     * trip to another dashboard). A successful deploy auto-fills the
     * Owncast server URL above — the one thing this can't do for you
     * is allocate a dedicated public IPv4 for RTMP, a one-time
     * `fly ips allocate-v4` step Fly's REST API has no endpoint for at
     * all (confirmed against their own docs) — said plainly here
     * rather than implying full automation this API can't back up.
     */
    private static function render_provisioner_section() {
        $provider_key = 'fly'; // the only entry in HOST_PROVISIONERS today
        $provider = self::HOST_PROVISIONERS[$provider_key];
        $settings = BHL_FlyProvisioner::settings();
        $deploy_result = get_transient('ous_media_wizard_deploy_result');
        delete_transient('ous_media_wizard_deploy_result');

        echo '<hr style="max-width:760px;margin:32px 0;">';
        echo '<h2>Deploy a live server (optional)</h2>';
        echo '<div class="bhy-alert" style="border-left:3px solid #2271b1;background:#f6f7f7;padding:14px;margin:16px 0;max-width:760px;">';
        echo '<p><strong>' . esc_html($provider['name']) . ':</strong> ' . esc_html($provider['why']) . ' A one-time step this can\'t do for you: allocate a dedicated public IPv4 for RTMP (<code>fly ips allocate-v4 -a ' . esc_html($settings['app_name'] ?: '&lt;app-name&gt;') . '</code>) — Fly\'s API has no endpoint for that at all, only their CLI/GraphQL API.</p>';
        echo '</div>';

        if ($deploy_result) {
            $class = $deploy_result['success'] ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . '" style="padding:12px;max-width:760px;"><p>' . ($deploy_result['success'] ? '&#9989; ' : '&#10060; ') . esc_html($deploy_result['message']) . '</p></div>';
        }

        if (!empty($settings['active_machine_id']) && class_exists($provider['class'])) {
            $engine = new $provider['class']();
            $status = $engine->get_status($settings['active_machine_id']);
            if (!is_wp_error($status)) {
                echo '<p><strong>Current machine:</strong> <code>' . esc_html($settings['active_machine_id']) . '</code> — status: <strong>' . esc_html($status['state']) . '</strong> (' . esc_html($status['raw_state']) . ')</p>';
            }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">';
            wp_nonce_field('ous_media_wizard_destroy_host', 'ous_media_wizard_destroy_nonce');
            echo '<input type="hidden" name="action" value="ous_media_wizard_destroy_host">';
            echo '<button type="submit" class="button" onclick="return confirm(\'Destroy this machine? This stops the live server entirely.\');">Destroy machine</button>';
            echo '</form>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:760px;margin-top:16px;">';
        wp_nonce_field('ous_media_wizard_save_provisioner', 'ous_media_wizard_provisioner_nonce');
        echo '<input type="hidden" name="action" value="ous_media_wizard_save_provisioner">';
        foreach ($provider['fields'] as $f) {
            $val = $settings[$f['name']] ?? '';
            echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html($f['label']) . '<br>';
            echo '<input type="' . esc_attr($f['type']) . '" name="fly_' . esc_attr($f['name']) . '" value="' . esc_attr($f['type'] === 'password' ? '' : $val) . '" placeholder="' . esc_attr($f['placeholder'] ?? '') . '" style="width:100%;max-width:480px;" autocomplete="off">';
            if ($f['type'] === 'password' && $val) echo '<span class="description"> (already set — leave blank to keep it)</span>';
            echo '</label></p>';
        }
        echo '<p><button type="submit" class="button">Save connection settings</button></p>';
        echo '</form>';

        if (!empty($settings['api_token']) && !empty($settings['app_name'])) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('ous_media_wizard_deploy_host', 'ous_media_wizard_deploy_nonce');
            echo '<input type="hidden" name="action" value="ous_media_wizard_deploy_host">';
            echo '<button type="submit" class="button button-primary">Deploy Owncast to ' . esc_html($provider['name']) . '</button>';
            echo '</form>';
        }
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

    public static function handle_save_live() {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_POST['ous_media_wizard_live_nonce'] ?? '', 'ous_media_wizard_save_live')) {
            wp_die('Security check failed.', '', ['response' => 403, 'back_link' => true]);
        }

        $server_url = wp_unslash($_POST['bhl_server_url'] ?? '');
        $access_token = wp_unslash($_POST['bhl_access_token'] ?? '');
        BHL_OwncastEngine::save_settings($server_url, $access_token);

        // Real, live status check — same "validate in real time" rule
        // the storage section's own checkConnection() call follows,
        // via BHL_OwncastEngine's own get_status() (a real wp_remote_get
        // against Owncast's public /api/status endpoint), not a
        // format-only URL check.
        $engine = new BHL_OwncastEngine();
        $status = $engine->get_status();
        $result = is_wp_error($status)
            ? ['success' => false, 'message' => 'Could not reach the Owncast server: ' . $status->get_error_message()]
            : ['success' => true, 'message' => 'Connected — server is currently ' . ($status['online'] ? 'LIVE' : 'offline') . '.'];
        set_transient('ous_media_wizard_live_test_result', $result, 60);

        wp_safe_redirect(admin_url('admin.php?page=ous-media-setup'));
        exit;
    }

    public static function handle_save_provisioner() {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_POST['ous_media_wizard_provisioner_nonce'] ?? '', 'ous_media_wizard_save_provisioner')) {
            wp_die('Security check failed.', '', ['response' => 403, 'back_link' => true]);
        }
        BHL_FlyProvisioner::save_settings(
            wp_unslash($_POST['fly_api_token'] ?? ''),
            wp_unslash($_POST['fly_app_name'] ?? ''),
            wp_unslash($_POST['fly_org_slug'] ?? ''),
            wp_unslash($_POST['fly_region'] ?? '')
        );
        wp_safe_redirect(admin_url('admin.php?page=ous-media-setup'));
        exit;
    }

    public static function handle_deploy_host() {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_POST['ous_media_wizard_deploy_nonce'] ?? '', 'ous_media_wizard_deploy_host')) {
            wp_die('Security check failed.', '', ['response' => 403, 'back_link' => true]);
        }

        $provisioner = new BHL_FlyProvisioner();
        $result = $provisioner->provision();
        if (is_wp_error($result)) {
            set_transient('ous_media_wizard_deploy_result', ['success' => false, 'message' => 'Deploy failed: ' . $result->get_error_message()], 60);
        } else {
            // Auto-fill the Owncast connection settings above with the
            // freshly-deployed box's own URL — the actual "one click"
            // loop AJ asked for, not deploy-then-copy-paste-manually.
            BHL_OwncastEngine::save_settings($result['public_url'], null);
            set_transient('ous_media_wizard_deploy_result', [
                'success' => true,
                'message' => 'Deployed — machine ' . $result['host_id'] . ' is starting at ' . $result['public_url'] . '. It can take a minute to finish booting; refresh this page to check its status. Remember the one-time IPv4 step above before RTMP ingest will work.',
            ], 60);
        }

        wp_safe_redirect(admin_url('admin.php?page=ous-media-setup'));
        exit;
    }

    public static function handle_destroy_host() {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_POST['ous_media_wizard_destroy_nonce'] ?? '', 'ous_media_wizard_destroy_host')) {
            wp_die('Security check failed.', '', ['response' => 403, 'back_link' => true]);
        }

        $settings = BHL_FlyProvisioner::settings();
        $provisioner = new BHL_FlyProvisioner();
        $result = $provisioner->destroy($settings['active_machine_id']);
        set_transient('ous_media_wizard_deploy_result', is_wp_error($result)
            ? ['success' => false, 'message' => 'Could not destroy the machine: ' . $result->get_error_message()]
            : ['success' => true, 'message' => 'Machine destroyed.'], 60);

        wp_safe_redirect(admin_url('admin.php?page=ous-media-setup'));
        exit;
    }

    public static function handle_save_engine_choice() {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_POST['ous_media_wizard_engine_nonce'] ?? '', 'ous_media_wizard_save_engine_choice')) {
            wp_die('Security check failed.', '', ['response' => 403, 'back_link' => true]);
        }
        BHL_EngineRegistry::save_active_key(sanitize_key($_POST['bhl_engine'] ?? 'owncast'));
        wp_safe_redirect(admin_url('admin.php?page=ous-media-setup'));
        exit;
    }

    public static function handle_save_chat_choice() {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_POST['ous_media_wizard_chat_nonce'] ?? '', 'ous_media_wizard_save_chat_choice')) {
            wp_die('Security check failed.', '', ['response' => 403, 'back_link' => true]);
        }
        BHL_EngineRegistry::save_active_chat_key(sanitize_key($_POST['bhl_chat'] ?? 'polling'));
        wp_safe_redirect(admin_url('admin.php?page=ous-media-setup'));
        exit;
    }

    public static function handle_save_cloudflare() {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_POST['ous_media_wizard_cf_nonce'] ?? '', 'ous_media_wizard_save_cloudflare')) {
            wp_die('Security check failed.', '', ['response' => 403, 'back_link' => true]);
        }
        BHL_CloudflareStreamEngine::save_connection_settings(
            wp_unslash($_POST['cf_account_id'] ?? ''),
            wp_unslash($_POST['cf_api_token'] ?? ''),
            wp_unslash($_POST['cf_customer_code'] ?? '')
        );
        wp_safe_redirect(admin_url('admin.php?page=ous-media-setup'));
        exit;
    }

    public static function handle_create_cloudflare_input() {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_POST['ous_media_wizard_cf_input_nonce'] ?? '', 'ous_media_wizard_create_cf_input')) {
            wp_die('Security check failed.', '', ['response' => 403, 'back_link' => true]);
        }
        $engine = new BHL_CloudflareStreamEngine();
        $result = $engine->create_live_input();
        set_transient('ous_media_wizard_cf_result', is_wp_error($result)
            ? ['success' => false, 'message' => 'Could not create a live input: ' . $result->get_error_message()]
            : ['success' => true, 'message' => 'Live input created — copy the RTMP URL and stream key below into OBS.'], 60);

        wp_safe_redirect(admin_url('admin.php?page=ous-media-setup'));
        exit;
    }
}
