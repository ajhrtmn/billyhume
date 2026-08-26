<?php
if (!defined('ABSPATH')) exit;

/**
 * Dedicated settings screen (not folded into OUS_MediaWizard, unlike
 * bh-live's engine setup) — social/marketing integrations are a
 * distinct concern from storage/CDN/media setup, and each platform
 * needs its own OAuth-app credentials a media wizard step has no
 * natural place for.
 *
 * Organic platforms (YouTube/Twitch/Meta/TikTok) each get explicit,
 * named handlers — their credential shape and post-connect actions
 * differ enough (YouTube: upload a video; Twitch: send a chat
 * announcement) that a generic handler would need per-platform
 * branching anyway. The five BH_AdsPlatform implementations, by
 * contrast, share one identical draft shape (name/budget/dates/
 * targeting notes), so THOSE go through one real generic,
 * platform-key-driven handler instead of five near-duplicates.
 */
class BHSO_Admin {
    public static function init(): void {
        add_filter('ous_registered_plugins', [self::class, 'register']);
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);

        add_action('admin_post_bhso_save_youtube_credentials', [self::class, 'handle_save_youtube_credentials']);
        add_action('admin_post_bhso_disconnect_youtube', [self::class, 'handle_disconnect_youtube']);
        add_action('admin_post_bhso_pull_youtube_stats_now', [self::class, 'handle_pull_youtube_stats_now']);

        add_action('admin_post_bhso_save_twitch_credentials', [self::class, 'handle_save_twitch_credentials']);
        add_action('admin_post_bhso_disconnect_twitch', [self::class, 'handle_disconnect_twitch']);
        add_action('admin_post_bhso_pull_twitch_stats_now', [self::class, 'handle_pull_twitch_stats_now']);
        add_action('admin_post_bhso_twitch_announce', [self::class, 'handle_twitch_announce']);

        add_action('admin_post_bhso_save_meta_credentials', [self::class, 'handle_save_meta_credentials']);
        add_action('admin_post_bhso_disconnect_meta', [self::class, 'handle_disconnect_meta']);
        add_action('admin_post_bhso_pull_meta_stats_now', [self::class, 'handle_pull_meta_stats_now']);

        add_action('admin_post_bhso_save_tiktok_credentials', [self::class, 'handle_save_tiktok_credentials']);
        add_action('admin_post_bhso_disconnect_tiktok', [self::class, 'handle_disconnect_tiktok']);
        add_action('admin_post_bhso_pull_tiktok_stats_now', [self::class, 'handle_pull_tiktok_stats_now']);

        add_action('admin_post_bhso_save_ad_draft', [self::class, 'handle_save_ad_draft']);
        add_action('admin_post_bhso_delete_ad_draft', [self::class, 'handle_delete_ad_draft']);
    }

    /**
     * @param array<string, mixed> $plugins
     * @return array<string, mixed>
     */
    public static function register(array $plugins): array {
        $plugins['bh-social'] = [
            'label'          => 'BH Social',
            'file'           => 'bh-social/bh-social.php',
            'depends_on'     => [],
            'check_class'    => 'BHSO_YouTube',
            'description'    => 'Social/marketing platform integrations — organic cross-post/stats (YouTube, Twitch, Meta, TikTok) plus paid ad-campaign drafting (Roku, Spotify, Amazon DSP, Samsung, Vizio).',
            'dashboard_link' => 'admin.php?page=bh-social',
            'bundled_zip'    => 'bh-social.zip',
        ];
        return $plugins;
    }

    public static function add_menu(): void {
        add_menu_page('BH Social', 'BH Social', 'manage_options', 'bh-social', [self::class, 'render'], 'dashicons-share', 58);
    }

    // ous-admin.css owns .ous-badge/.ous-badge-alpha etc. (the-self-hosted-self's
    // class-dashboard.php only enqueues it on its OWN pages) — this
    // page needs the same stylesheet for OUS_Badge::render() below to
    // actually render styled, not just as plain unstyled text.
    public static function enqueue_assets(string $hook): void {
        if (strpos($hook, 'bh-social') === false) return;
        wp_enqueue_style('ous-admin', OUS_URL . 'assets/css/admin.css', [], OUS_VER);
    }

    /* ---------------- YouTube handlers ---------------- */

    public static function handle_save_youtube_credentials(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bhso_save_youtube_credentials');

        BHSO_YouTube::save_credentials(
            sanitize_text_field(wp_unslash($_POST['client_id'] ?? '')),
            sanitize_text_field(wp_unslash($_POST['client_secret'] ?? ''))
        );

        if (class_exists('OUS_Toast')) OUS_Toast::queue('YouTube API credentials saved.', 'success');
        wp_safe_redirect(admin_url('admin.php?page=bh-social'));
        exit;
    }

    public static function handle_disconnect_youtube(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bhso_disconnect_youtube');
        (new BHSO_YouTube())->disconnect();
        if (class_exists('OUS_Toast')) OUS_Toast::queue('YouTube disconnected.', 'success');
        wp_safe_redirect(admin_url('admin.php?page=bh-social'));
        exit;
    }

    public static function handle_pull_youtube_stats_now(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bhso_pull_youtube_stats_now');
        $result = (new BHSO_YouTube())->pull_stats();
        $msg = is_wp_error($result) ? ('Stats pull failed: ' . $result->get_error_message()) : 'YouTube stats refreshed.';
        if (class_exists('OUS_Toast')) OUS_Toast::queue($msg, is_wp_error($result) ? 'error' : 'success');
        wp_safe_redirect(admin_url('admin.php?page=bh-social'));
        exit;
    }

    /* ---------------- Twitch handlers ---------------- */

    public static function handle_save_twitch_credentials(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bhso_save_twitch_credentials');

        BHSO_Twitch::save_credentials(
            sanitize_text_field(wp_unslash($_POST['client_id'] ?? '')),
            sanitize_text_field(wp_unslash($_POST['client_secret'] ?? ''))
        );

        if (class_exists('OUS_Toast')) OUS_Toast::queue('Twitch API credentials saved.', 'success');
        wp_safe_redirect(admin_url('admin.php?page=bh-social'));
        exit;
    }

    public static function handle_disconnect_twitch(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bhso_disconnect_twitch');
        (new BHSO_Twitch())->disconnect();
        if (class_exists('OUS_Toast')) OUS_Toast::queue('Twitch disconnected.', 'success');
        wp_safe_redirect(admin_url('admin.php?page=bh-social'));
        exit;
    }

    public static function handle_pull_twitch_stats_now(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bhso_pull_twitch_stats_now');
        $result = (new BHSO_Twitch())->pull_stats();
        $msg = is_wp_error($result) ? ('Stats pull failed: ' . $result->get_error_message()) : 'Twitch stats refreshed.';
        if (class_exists('OUS_Toast')) OUS_Toast::queue($msg, is_wp_error($result) ? 'error' : 'success');
        wp_safe_redirect(admin_url('admin.php?page=bh-social'));
        exit;
    }

    public static function handle_twitch_announce(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bhso_twitch_announce');

        $message = sanitize_text_field(wp_unslash($_POST['message'] ?? ''));
        $color = sanitize_key(wp_unslash($_POST['color'] ?? 'primary'));
        BHSO_Twitch::enqueue_cross_post($message, $color);

        if (class_exists('OUS_Toast')) OUS_Toast::queue('Announcement queued — it\'ll post to Twitch chat within a minute.', 'success');
        wp_safe_redirect(admin_url('admin.php?page=bh-social'));
        exit;
    }

    /* ---------------- Meta handlers ---------------- */

    public static function handle_save_meta_credentials(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bhso_save_meta_credentials');

        BHSO_Meta::save_credentials(
            sanitize_text_field(wp_unslash($_POST['app_id'] ?? '')),
            sanitize_text_field(wp_unslash($_POST['app_secret'] ?? ''))
        );

        if (class_exists('OUS_Toast')) OUS_Toast::queue('Meta app credentials saved.', 'success');
        wp_safe_redirect(admin_url('admin.php?page=bh-social'));
        exit;
    }

    public static function handle_disconnect_meta(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bhso_disconnect_meta');
        (new BHSO_Meta())->disconnect();
        if (class_exists('OUS_Toast')) OUS_Toast::queue('Meta disconnected.', 'success');
        wp_safe_redirect(admin_url('admin.php?page=bh-social'));
        exit;
    }

    public static function handle_pull_meta_stats_now(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bhso_pull_meta_stats_now');
        $result = (new BHSO_Meta())->pull_stats();
        $msg = is_wp_error($result) ? ('Stats pull failed: ' . $result->get_error_message()) : 'Meta stats refreshed.';
        if (class_exists('OUS_Toast')) OUS_Toast::queue($msg, is_wp_error($result) ? 'error' : 'success');
        wp_safe_redirect(admin_url('admin.php?page=bh-social'));
        exit;
    }

    /* ---------------- TikTok handlers ---------------- */

    public static function handle_save_tiktok_credentials(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bhso_save_tiktok_credentials');

        BHSO_TikTok::save_credentials(
            sanitize_text_field(wp_unslash($_POST['client_key'] ?? '')),
            sanitize_text_field(wp_unslash($_POST['client_secret'] ?? ''))
        );

        if (class_exists('OUS_Toast')) OUS_Toast::queue('TikTok app credentials saved.', 'success');
        wp_safe_redirect(admin_url('admin.php?page=bh-social'));
        exit;
    }

    public static function handle_disconnect_tiktok(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bhso_disconnect_tiktok');
        (new BHSO_TikTok())->disconnect();
        if (class_exists('OUS_Toast')) OUS_Toast::queue('TikTok disconnected.', 'success');
        wp_safe_redirect(admin_url('admin.php?page=bh-social'));
        exit;
    }

    public static function handle_pull_tiktok_stats_now(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bhso_pull_tiktok_stats_now');
        $result = (new BHSO_TikTok())->pull_stats();
        $msg = is_wp_error($result) ? ('Stats pull failed: ' . $result->get_error_message()) : 'TikTok stats refreshed.';
        if (class_exists('OUS_Toast')) OUS_Toast::queue($msg, is_wp_error($result) ? 'error' : 'success');
        wp_safe_redirect(admin_url('admin.php?page=bh-social'));
        exit;
    }

    /* ---------------- Ads (generic, all 5 platforms share this shape) ---------------- */

    public static function handle_save_ad_draft(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bhso_save_ad_draft');

        $platform_key = sanitize_key(wp_unslash($_POST['platform'] ?? ''));
        $platform = BHSO_AdsPlatformRegistry::get($platform_key);
        if (!$platform) wp_die('Unknown ad platform.');

        $result = $platform->save_campaign_draft([
            'name'            => wp_unslash($_POST['name'] ?? ''),
            'budget_dollars'  => wp_unslash($_POST['budget_dollars'] ?? '0'),
            'start_date'      => wp_unslash($_POST['start_date'] ?? ''),
            'end_date'        => wp_unslash($_POST['end_date'] ?? ''),
            'targeting_notes' => wp_unslash($_POST['targeting_notes'] ?? ''),
            'attachment_id'   => wp_unslash($_POST['attachment_id'] ?? 0),
        ]);

        // BHSO_AmazonDSP intentionally returns a WP_Error even on a
        // successful save, carrying draft_id in its error data, so a
        // soft "this will underperform" warning can surface without
        // blocking the save the way a hard validation failure does.
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $saved = is_array($data) && !empty($data['draft_id']);
            if (class_exists('OUS_Toast')) OUS_Toast::queue($result->get_error_message(), $saved ? 'warning' : 'error');
        } elseif (class_exists('OUS_Toast')) {
            OUS_Toast::queue('Campaign draft saved.', 'success');
        }

        wp_safe_redirect(admin_url('admin.php?page=bh-social'));
        exit;
    }

    public static function handle_delete_ad_draft(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bhso_delete_ad_draft');

        $platform_key = sanitize_key(wp_unslash($_POST['platform'] ?? ''));
        $platform = BHSO_AdsPlatformRegistry::get($platform_key);
        if ($platform && method_exists($platform, 'delete_campaign_draft')) {
            $platform->delete_campaign_draft((int) ($_POST['id'] ?? 0));
        }

        if (class_exists('OUS_Toast')) OUS_Toast::queue('Draft deleted.', 'success');
        wp_safe_redirect(admin_url('admin.php?page=bh-social'));
        exit;
    }

    /* ---------------- render ---------------- */

    public static function render(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');

        echo '<div class="wrap"><h1>BH Social ' . (class_exists('OUS_Badge') ? OUS_Badge::render('alpha', null, 'This whole plugin is new and, while code-correct against each platform\'s documented API, none of the four organic integrations have been exercised against a real connected account yet — connect one and watch it closely the first few times.') : '') . '</h1>';
        echo '<p class="description">Every integration below is real, working code against each platform\'s actual documented API — but "alpha" here specifically means <strong>not yet verified against a live account</strong>. Connect one, try a real cross-post/stats-pull, and watch it closely the first time.</p>';
        echo '<h2 class="nav-tab-wrapper" style="margin-bottom:16px;">Organic (cross-post + stats)</h2>';
        self::render_youtube_section();
        echo '<hr>';
        self::render_twitch_section();
        echo '<hr>';
        self::render_meta_section();
        echo '<hr>';
        self::render_tiktok_section();
        echo '<hr>';
        if (class_exists('BHSO_AutoAnnounce')) BHSO_AutoAnnounce::render_settings_section();

        echo '<h2 style="margin-top:32px;border-top:2px solid #ccd0d4;padding-top:20px;">Paid ad campaigns (draft &amp; hand off) ' . (class_exists('OUS_Badge') ? OUS_Badge::render('experimental', null, 'Draft-capture is real and tested, but none of these five platforms have a confirmed public self-serve API — every campaign still launches manually on the platform\'s own site.') : '') . '</h2>';
        echo '<p class="description">None of these five have a confirmed public self-serve API — each platform\'s own site is where a campaign actually launches. This just keeps the plan (budget, dates, targeting notes, creative) organized in one place so nothing has to be re-typed from scratch when you get there.</p>';
        foreach (BHSO_AdsPlatformRegistry::all() as $key => $instance) {
            self::render_ads_section($key, $instance);
            echo '<hr>';
        }

        echo '</div>';
    }

    /** @param array<string, array<string, mixed>> $stats */
    private static function render_stats_table(array $stats): void {
        if (!$stats) {
            echo '<p><em>No stats pulled yet.</em></p>';
            return;
        }
        echo '<div class="bhy-table-wrap" style="max-width:480px;"><table class="widefat striped"><thead><tr><th>Metric</th><th>Value</th><th>As of</th></tr></thead><tbody>';
        foreach ($stats as $key => $row) {
            echo '<tr><td>' . esc_html(ucwords(str_replace('_', ' ', $key))) . '</td><td>' . esc_html(number_format_i18n($row['value'])) . '</td><td>' . esc_html($row['recorded_at']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function render_youtube_section(): void {
        $yt = new BHSO_YouTube();
        $s = BHSO_YouTube::settings();
        $status = $yt->get_status();

        echo '<h3>YouTube ' . (class_exists('OUS_Badge') ? OUS_Badge::render('alpha', null, 'Code-complete against YouTube\'s documented API but not yet exercised against a real connected channel.') : '') . '</h3>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bhso_save_youtube_credentials');
        echo '<input type="hidden" name="action" value="bhso_save_youtube_credentials">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="bhso_yt_client_id">Client ID</label></th><td><input type="text" id="bhso_yt_client_id" name="client_id" value="' . esc_attr($s['client_id']) . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="bhso_yt_client_secret">Client Secret</label></th><td><input type="password" id="bhso_yt_client_secret" name="client_secret" value="" class="regular-text" placeholder="' . (!empty($s['client_secret']) ? '•••••••• (leave blank to keep)' : '') . '"></td></tr>';
        echo '<tr><th>Redirect URI</th><td><code>' . esc_html(admin_url('admin-post.php?action=bhso_youtube_oauth_callback')) . '</code><p class="description">Add this exact URI to your OAuth client\'s "Authorized redirect URIs" in Google Cloud Console.</p></td></tr>';
        echo '</tbody></table>';
        submit_button('Save Credentials');
        echo '</form>';

        echo '<p><strong>Status:</strong> ';
        if ($status === 'not_configured') {
            echo 'Not configured — save a Client ID and Client Secret above first.';
        } elseif ($status === 'not_connected') {
            $auth_url = BHSO_YouTube::authorize_url();
            echo is_wp_error($auth_url) ? esc_html($auth_url->get_error_message()) : 'Configured, not connected. <a href="' . esc_url($auth_url) . '" class="button button-primary">Connect YouTube Account</a>';
        } else {
            echo '&#9989; Connected' . (!empty($s['channel_title']) ? ' as <strong>' . esc_html($s['channel_title']) . '</strong>' : '') . '. ';
            echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=bhso_disconnect_youtube'), 'bhso_disconnect_youtube')) . '" class="button" onclick="return confirm(\'Disconnect this YouTube account?\');">Disconnect</a>';
        }
        echo '</p>';

        if ($status === 'connected') {
            echo '<h4>Stats</h4>';
            self::render_stats_table(BHSO_YouTube::latest_stats());
            echo '<p><a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=bhso_pull_youtube_stats_now'), 'bhso_pull_youtube_stats_now')) . '" class="button">Refresh stats now</a></p>';
        }
    }

    private static function render_twitch_section(): void {
        $tw = new BHSO_Twitch();
        $s = BHSO_Twitch::settings();
        $status = $tw->get_status();

        echo '<h3>Twitch ' . (class_exists('OUS_Badge') ? OUS_Badge::render('alpha', null, 'Code-complete against Twitch\'s documented Helix API but not yet exercised against a real connected channel.') : '') . '</h3>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bhso_save_twitch_credentials');
        echo '<input type="hidden" name="action" value="bhso_save_twitch_credentials">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="bhso_tw_client_id">Client ID</label></th><td><input type="text" id="bhso_tw_client_id" name="client_id" value="' . esc_attr($s['client_id']) . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="bhso_tw_client_secret">Client Secret</label></th><td><input type="password" id="bhso_tw_client_secret" name="client_secret" value="" class="regular-text" placeholder="' . (!empty($s['client_secret']) ? '•••••••• (leave blank to keep)' : '') . '"></td></tr>';
        echo '<tr><th>Redirect URI</th><td><code>' . esc_html(admin_url('admin-post.php?action=bhso_twitch_oauth_callback')) . '</code><p class="description">Add this exact URI to your app\'s "OAuth Redirect URLs" in the Twitch Developer Console.</p></td></tr>';
        echo '</tbody></table>';
        submit_button('Save Credentials');
        echo '</form>';

        echo '<p><strong>Status:</strong> ';
        if ($status === 'not_configured') {
            echo 'Not configured — save a Client ID and Client Secret above first.';
        } elseif ($status === 'not_connected') {
            $auth_url = BHSO_Twitch::authorize_url();
            echo is_wp_error($auth_url) ? esc_html($auth_url->get_error_message()) : 'Configured, not connected. <a href="' . esc_url($auth_url) . '" class="button button-primary">Connect Twitch Account</a>';
        } else {
            echo '&#9989; Connected' . (!empty($s['broadcaster_login']) ? ' as <strong>' . esc_html($s['broadcaster_login']) . '</strong>' : '') . '. ';
            echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=bhso_disconnect_twitch'), 'bhso_disconnect_twitch')) . '" class="button" onclick="return confirm(\'Disconnect this Twitch account?\');">Disconnect</a>';
        }
        echo '</p>';

        if ($status === 'connected') {
            echo '<h4>Send a chat announcement</h4>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('bhso_twitch_announce');
            echo '<input type="hidden" name="action" value="bhso_twitch_announce">';
            echo '<p><input type="text" name="message" class="large-text" maxlength="500" placeholder="e.g. New track just dropped — check it out!"></p>';
            echo '<p><select name="color"><option value="primary">Default</option><option value="blue">Blue</option><option value="green">Green</option><option value="orange">Orange</option><option value="purple">Purple</option></select> ';
            submit_button('Post Announcement', 'secondary', 'submit', false);
            echo '</p></form>';

            echo '<h4>Stats</h4>';
            self::render_stats_table(BHSO_Twitch::latest_stats());
            echo '<p><a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=bhso_pull_twitch_stats_now'), 'bhso_pull_twitch_stats_now')) . '" class="button">Refresh stats now</a></p>';
        }
    }

    private static function render_meta_section(): void {
        $meta = new BHSO_Meta();
        $s = BHSO_Meta::settings();
        $status = $meta->get_status();

        echo '<h3>Meta (Instagram) ' . (class_exists('OUS_Badge') ? OUS_Badge::render('experimental', null, 'Real production use requires Meta\'s app-review process (2-4 week turnaround) on top of being unverified against a live account — the least ready of the four to rely on today.') : '') . '</h3>';
        echo '<p class="description">Real production use needs Meta\'s app-review process per scope — expect 2-4 weeks turnaround. In Development Mode, this works immediately against IG accounts added as testers/roles on your Meta app.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bhso_save_meta_credentials');
        echo '<input type="hidden" name="action" value="bhso_save_meta_credentials">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="bhso_meta_app_id">App ID</label></th><td><input type="text" id="bhso_meta_app_id" name="app_id" value="' . esc_attr($s['app_id']) . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="bhso_meta_app_secret">App Secret</label></th><td><input type="password" id="bhso_meta_app_secret" name="app_secret" value="" class="regular-text" placeholder="' . (!empty($s['app_secret']) ? '•••••••• (leave blank to keep)' : '') . '"></td></tr>';
        echo '<tr><th>Redirect URI</th><td><code>' . esc_html(admin_url('admin-post.php?action=bhso_meta_oauth_callback')) . '</code><p class="description">Add this exact URI to your app\'s Facebook Login "Valid OAuth Redirect URIs" in Meta for Developers.</p></td></tr>';
        echo '</tbody></table>';
        submit_button('Save Credentials');
        echo '</form>';

        echo '<p><strong>Status:</strong> ';
        if ($status === 'not_configured') {
            echo 'Not configured — save an App ID and App Secret above first.';
        } elseif ($status === 'not_connected') {
            $auth_url = BHSO_Meta::authorize_url();
            echo is_wp_error($auth_url) ? esc_html($auth_url->get_error_message()) : 'Configured, not connected. <a href="' . esc_url($auth_url) . '" class="button button-primary">Connect Facebook/Instagram</a>';
        } elseif ($status === 'needs_reauth') {
            echo '&#9888; Token expired — reconnect. <a href="' . esc_url(BHSO_Meta::authorize_url()) . '" class="button button-primary">Reconnect</a>';
        } else {
            echo '&#9989; Connected' . (!empty($s['ig_username']) ? ' as <strong>@' . esc_html($s['ig_username']) . '</strong>' : '') . (!empty($s['page_name']) ? ' (Page: ' . esc_html($s['page_name']) . ')' : '') . '. ';
            echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=bhso_disconnect_meta'), 'bhso_disconnect_meta')) . '" class="button" onclick="return confirm(\'Disconnect this Meta connection?\');">Disconnect</a>';
        }
        echo '</p>';

        if ($status === 'connected') {
            echo '<h4>Stats</h4>';
            self::render_stats_table(BHSO_Meta::latest_stats());
            echo '<p><a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=bhso_pull_meta_stats_now'), 'bhso_pull_meta_stats_now')) . '" class="button">Refresh stats now</a></p>';
        }
    }

    private static function render_tiktok_section(): void {
        $tt = new BHSO_TikTok();
        $s = BHSO_TikTok::settings();
        $status = $tt->get_status();

        echo '<h3>TikTok ' . (class_exists('OUS_Badge') ? OUS_Badge::render('experimental', null, 'Requires an HTTPS domain registered with your TikTok app to even complete OAuth — won\'t connect from this local dev install — plus unaudited-mode limits (private-only posts, 5 test accounts) until app review clears.') : '') . '</h3>';
        echo '<p class="description">Requires an HTTPS redirect URI on a domain registered with your TikTok app — won\'t connect from a local/http dev install. Before app review completes, published posts are forced to private (SELF_ONLY) and limited to 5 authorized sandbox accounts.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bhso_save_tiktok_credentials');
        echo '<input type="hidden" name="action" value="bhso_save_tiktok_credentials">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="bhso_tt_client_key">Client Key</label></th><td><input type="text" id="bhso_tt_client_key" name="client_key" value="' . esc_attr($s['client_key']) . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="bhso_tt_client_secret">Client Secret</label></th><td><input type="password" id="bhso_tt_client_secret" name="client_secret" value="" class="regular-text" placeholder="' . (!empty($s['client_secret']) ? '•••••••• (leave blank to keep)' : '') . '"></td></tr>';
        echo '<tr><th>Redirect URI</th><td><code>' . esc_html(admin_url('admin-post.php?action=bhso_tiktok_oauth_callback')) . '</code><p class="description">Add this exact URI to your app\'s "Redirect URI" list in the TikTok Developer Portal.</p></td></tr>';
        echo '</tbody></table>';
        submit_button('Save Credentials');
        echo '</form>';

        echo '<p><strong>Status:</strong> ';
        if ($status === 'not_configured') {
            echo 'Not configured — save a Client Key and Client Secret above first.';
        } elseif ($status === 'not_connected') {
            $auth_url = BHSO_TikTok::authorize_url();
            echo is_wp_error($auth_url) ? esc_html($auth_url->get_error_message()) : 'Configured, not connected. <a href="' . esc_url($auth_url) . '" class="button button-primary">Connect TikTok Account</a>';
        } else {
            echo '&#9989; Connected' . (!empty($s['display_name']) ? ' as <strong>' . esc_html($s['display_name']) . '</strong>' : '') . '. ';
            echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=bhso_disconnect_tiktok'), 'bhso_disconnect_tiktok')) . '" class="button" onclick="return confirm(\'Disconnect this TikTok account?\');">Disconnect</a>';
        }
        echo '</p>';

        if ($status === 'connected') {
            echo '<h4>Stats</h4>';
            self::render_stats_table(BHSO_TikTok::latest_stats());
            echo '<p><a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=bhso_pull_tiktok_stats_now'), 'bhso_pull_tiktok_stats_now')) . '" class="button">Refresh stats now</a></p>';
        }
    }

    private static function render_ads_section(string $key, \BH_AdsPlatform $instance): void {
        $label = BHSO_AdsPlatformRegistry::PLATFORMS[$key]['label'];
        echo '<h3>' . esc_html($label) . '</h3>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bhso_save_ad_draft');
        echo '<input type="hidden" name="action" value="bhso_save_ad_draft"><input type="hidden" name="platform" value="' . esc_attr($key) . '">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Campaign name</th><td><input type="text" name="name" class="regular-text" required></td></tr>';
        echo '<tr><th>Budget (USD)</th><td><input type="number" name="budget_dollars" min="0" step="1" class="small-text"></td></tr>';
        echo '<tr><th>Dates</th><td><input type="date" name="start_date"> &ndash; <input type="date" name="end_date"></td></tr>';
        echo '<tr><th>Targeting notes</th><td><textarea name="targeting_notes" rows="3" class="large-text" placeholder="Audience, geography, whatever you\'ll need when you get to their UI"></textarea></td></tr>';
        echo '</tbody></table>';
        submit_button('Save Draft', 'secondary');
        echo '</form>';

        $drafts = $instance->list_campaign_drafts();
        if ($drafts) {
            echo '<div class="bhy-table-wrap"><table class="widefat striped" style="margin-top:8px;"><thead><tr><th>Name</th><th>Budget</th><th>Dates</th><th>Created</th><th></th></tr></thead><tbody>';
            foreach ($drafts as $d) {
                $dates = trim(($d['start_date'] ?: '?') . ' – ' . ($d['end_date'] ?: '?'));
                echo '<tr><td>' . esc_html($d['name']) . '</td><td>$' . esc_html(number_format_i18n($d['budget_cents'] / 100)) . '</td><td>' . esc_html($dates) . '</td><td>' . esc_html($d['created_at']) . '</td><td>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;" onsubmit="return confirm(\'Delete this draft?\');">';
                wp_nonce_field('bhso_delete_ad_draft');
                echo '<input type="hidden" name="action" value="bhso_delete_ad_draft"><input type="hidden" name="platform" value="' . esc_attr($key) . '"><input type="hidden" name="id" value="' . (int) $d['id'] . '">';
                echo '<button type="submit" class="button-link-delete">Delete</button></form>';
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<p><em>No drafts yet.</em></p>';
        }

        echo '<p><a href="' . esc_url($instance->manager_url()) . '" target="_blank" rel="noopener noreferrer" class="button">Open ' . esc_html($label) . ' &#8599;</a></p>';
    }
}
