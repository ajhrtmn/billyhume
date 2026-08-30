<?php
if (!defined('ABSPATH')) exit;

/**
 * Signed, private video delivery — the "the content is for sale, it must
 * not be a shareable link" layer that sits ON TOP of storage/offload
 * (OUS_MediaWizard / Advanced Media Offloader, which only ever make a
 * bucket file *public*).
 *
 * Two providers, one contract. A feature plugin (bh-courses today) asks
 * for a URL *at render time, after its own access gate has already
 * passed*, and gets back a URL that stops working within a few hours and
 * can't be reused off-site:
 *
 *   BHY_MediaToken::sign_bunny($video_guid)  -> a tokenised Bunny embed URL
 *   BHY_MediaToken::sign_r2($object_key)     -> a tokenised URL to the R2 Worker
 *
 * Both return null when the provider isn't configured, so a caller can
 * `?: fall back` to an "ask the site owner to finish media setup" notice
 * instead of a broken player — same optional-enhancement posture every
 * other cross-cutting service here takes.
 *
 * Settings live on the Media & CDN Setup page (OUS_MediaWizard) via its
 * `ous_media_setup_after` hook, not a second screen.
 */
class BHY_MediaToken {

    const OPTION = 'bhy_media_token_settings';
    /** How long a freshly-minted link stays valid. A lesson sitting open
     *  longer than this re-mints on the next step change / reload. */
    const DEFAULT_TTL = 4 * HOUR_IN_SECONDS;

    public static function init(): void {
        add_action('ous_media_setup_after', [self::class, 'render_settings_section']);
        add_action('admin_post_bhy_media_token_save', [self::class, 'handle_save']);
    }

    /* ---------------- settings ---------------- */

    /** @return array{bunny_library_id:string,bunny_token_key:string,bunny_api_key:string,r2_worker_url:string,r2_signing_secret:string} */
    public static function settings(): array {
        $s = get_option(self::OPTION, []);
        return [
            'bunny_library_id'  => (string) ($s['bunny_library_id'] ?? ''),
            // Signs playback embed URLs (Library → API → Token Authentication Key).
            'bunny_token_key'   => (string) ($s['bunny_token_key'] ?? ''),
            // Manages videos: list / create / presign resumable upload
            // (Library → API → the "API Key" / AccessKey). Optional — set
            // it to get the in-editor Bunny library picker + uploader.
            'bunny_api_key'     => (string) ($s['bunny_api_key'] ?? ''),
            'r2_worker_url'     => (string) ($s['r2_worker_url'] ?? ''),
            'r2_signing_secret' => (string) ($s['r2_signing_secret'] ?? ''),
        ];
    }

    /** Enough to sign a playback URL. */
    public static function bunny_configured(): bool {
        $s = self::settings();
        return $s['bunny_library_id'] !== '' && $s['bunny_token_key'] !== '';
    }

    /** Enough to talk to Bunny's video-management API (list / create / upload). */
    public static function bunny_api_configured(): bool {
        $s = self::settings();
        return $s['bunny_library_id'] !== '' && $s['bunny_api_key'] !== '';
    }

    public static function bunny_library_id(): string { return self::settings()['bunny_library_id']; }
    public static function bunny_api_key(): string { return self::settings()['bunny_api_key']; }

    public static function r2_configured(): bool {
        $s = self::settings();
        return $s['r2_worker_url'] !== '' && $s['r2_signing_secret'] !== '';
    }

    /** For wp_localize_script — tells an editor which signed sources to offer. */
    public static function js_config(): array {
        return [
            'bunny'    => self::bunny_configured(),
            'bunnyApi' => self::bunny_api_configured(),
            'r2'       => self::r2_configured(),
        ];
    }

    /**
     * Presigned signature for a Bunny TUS resumable upload. The client
     * (tus-js-client) sends this as the AuthorizationSignature /
     * AuthorizationExpire metadata; Bunny accepts the direct upload
     * without the API key ever reaching the browser.
     *   signature = sha256( library_id + api_key + expiry + video_guid )
     *
     * @return array{signature:string,expires:int,library_id:string,video_guid:string,endpoint:string}|null
     */
    public static function bunny_upload_signature(string $video_guid, ?int $ttl = null): ?array {
        if (!self::bunny_api_configured()) return null;
        $video_guid = strtolower(trim($video_guid));
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $video_guid)) return null;

        $s = self::settings();
        $expires = time() + ($ttl ?? HOUR_IN_SECONDS);
        return [
            'signature'  => hash('sha256', $s['bunny_library_id'] . $s['bunny_api_key'] . $expires . $video_guid),
            'expires'    => $expires,
            'library_id' => $s['bunny_library_id'],
            'video_guid' => $video_guid,
            'endpoint'   => 'https://video.bunnycdn.com/tusupload',
        ];
    }

    /* ---------------- signing ---------------- */

    /**
     * Bunny Stream "embed view token". The string hashed is
     * {token_authentication_key}{video_guid}{expiry}; SHA-256, lowercase
     * hex. Filterable in case Bunny changes the recipe — the caller
     * shouldn't have to.
     *
     * @return string|null full https://iframe.mediadelivery.net/... URL
     */
    public static function sign_bunny(string $video_guid, ?int $ttl = null): ?string {
        if (!self::bunny_configured()) return null;
        $video_guid = trim($video_guid);
        if (!preg_match('/^[a-f0-9-]{20,64}$/i', $video_guid)) return null;

        $s = self::settings();
        $expiry = time() + ($ttl ?? self::DEFAULT_TTL);
        $token = hash('sha256', $s['bunny_token_key'] . $video_guid . $expiry);
        $token = (string) apply_filters('bhy_media_token_bunny', $token, $video_guid, $expiry, $s['bunny_token_key']);

        // responsive=true — fill our 16:9 wrapper instead of letterboxing
        // to Bunny's own fixed frame. autoplay=false EXPLICITLY — a
        // lesson video is something the student chooses to start, and
        // passing it beats relying on the Bunny library's own Autoplay
        // toggle being set the way we want. Filterable for the rare
        // caller that does want it (e.g. a hero/trailer context).
        $params = apply_filters('bhy_media_token_bunny_params', [
            'token'      => $token,
            'expires'    => $expiry,
            'responsive' => 'true',
            'autoplay'   => 'false',
            'preload'    => 'true',
        ], $video_guid);
        return 'https://iframe.mediadelivery.net/embed/'
            . rawurlencode($s['bunny_library_id']) . '/' . rawurlencode($video_guid)
            . '?' . http_build_query($params);
    }

    /**
     * A URL to the R2 Worker (tools/r2-video-worker.js) carrying an HMAC
     * the Worker verifies before it will read the private object.
     *   sig = base64url( HMAC-SHA256(secret, "{key}:{exp}") )
     *
     * @param string $object_key  path within the bucket, e.g. "courses/lesson-12/master.mp4"
     * @return string|null
     */
    public static function sign_r2(string $object_key, ?int $ttl = null): ?string {
        if (!self::r2_configured()) return null;
        $object_key = ltrim(trim($object_key), '/');
        // No traversal, no scheme, no query — a plain relative object path.
        if ($object_key === '' || strpos($object_key, '..') !== false
            || preg_match('#^[a-z][a-z0-9+.-]*://#i', $object_key)
            || strpbrk($object_key, "?#") !== false) {
            return null;
        }

        $s = self::settings();
        $exp = time() + ($ttl ?? self::DEFAULT_TTL);
        $raw = hash_hmac('sha256', $object_key . ':' . $exp, $s['r2_signing_secret'], true);
        $sig = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        $base = rtrim($s['r2_worker_url'], '/');
        $path = implode('/', array_map('rawurlencode', explode('/', $object_key)));
        return sprintf('%s/%s?exp=%d&sig=%s', $base, $path, $exp, $sig);
    }

    /* ---------------- admin UI (a section on Media & CDN Setup) ---------------- */

    public static function render_settings_section(): void {
        $s = self::settings();
        $action = esc_url(admin_url('admin-post.php'));
        echo '<hr style="max-width:760px;margin:32px 0;">';
        echo '<h2>Private (signed) video delivery</h2>';
        echo '<p class="description" style="max-width:760px;">For paid course video that must not be a shareable link. The player only ever receives a URL that expires in ' . esc_html((string) (self::DEFAULT_TTL / HOUR_IN_SECONDS)) . ' hours and is tied to this site. Configure either or both; bh-courses then offers them as video-step sources.</p>';

        echo '<form method="post" action="' . $action . '" style="max-width:760px;">';
        wp_nonce_field('bhy_media_token_save');
        echo '<input type="hidden" name="action" value="bhy_media_token_save">';

        echo '<h3>Bunny Stream</h3>';
        echo '<p class="description">Everything below is on the Stream library\'s <strong>API</strong> tab. The Library ID is the number in the library\'s URL. Turn on <em>Token Authentication</em> for the key that signs playback. The <em>API Key</em> is optional — add it to get an in-lesson-editor "pick from your Bunny library" browser and drag-and-drop upload (bh-courses 0.14+); leave it blank and authors just paste a video GUID.</p>';
        echo '<p><label>Library ID<br><input type="text" name="bunny_library_id" value="' . esc_attr($s['bunny_library_id']) . '" style="width:100%;max-width:320px;"></label></p>';
        echo '<p><label>Token Authentication Key<br><input type="password" name="bunny_token_key" value="" placeholder="' . ($s['bunny_token_key'] !== '' ? 'already set — leave blank to keep it' : '') . '" style="width:100%;max-width:480px;" autocomplete="off"></label></p>';
        echo '<p><label>API Key <span class="description">(optional — for the in-editor library picker &amp; uploader)</span><br><input type="password" name="bunny_api_key" value="" placeholder="' . ($s['bunny_api_key'] !== '' ? 'already set — leave blank to keep it' : '') . '" style="width:100%;max-width:480px;" autocomplete="off"></label></p>';

        echo '<h3 style="margin-top:24px;">Cloudflare R2 + Worker</h3>';
        echo '<p class="description">Deploy <code>the-self-hosted-self/tools/r2-video-worker.js</code> (see the notes at the top of that file), bind your private bucket, and set the Worker\'s <code>SIGNING_SECRET</code> to the same value you enter here.</p>';
        echo '<p><label>Worker URL<br><input type="url" name="r2_worker_url" value="' . esc_attr($s['r2_worker_url']) . '" placeholder="https://course-video.your-subdomain.workers.dev" style="width:100%;max-width:480px;"></label></p>';
        echo '<p><label>Signing secret<br><input type="password" name="r2_signing_secret" value="" placeholder="' . ($s['r2_signing_secret'] !== '' ? 'already set — leave blank to keep it' : 'a long random string') . '" style="width:100%;max-width:480px;" autocomplete="off"></label></p>';

        submit_button('Save signed-video settings');
        echo '</form>';
    }

    public static function handle_save(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('bhy_media_token_save')) {
            wp_die('Not allowed.');
        }
        $existing = self::settings();
        $new = [
            'bunny_library_id'  => sanitize_text_field(wp_unslash($_POST['bunny_library_id'] ?? '')),
            'r2_worker_url'     => esc_url_raw(wp_unslash($_POST['r2_worker_url'] ?? '')),
            // blank password field = keep the stored secret
            'bunny_token_key'   => ($_POST['bunny_token_key'] ?? '') !== ''
                ? sanitize_text_field(wp_unslash($_POST['bunny_token_key'])) : $existing['bunny_token_key'],
            'bunny_api_key'     => ($_POST['bunny_api_key'] ?? '') !== ''
                ? sanitize_text_field(wp_unslash($_POST['bunny_api_key'])) : $existing['bunny_api_key'],
            'r2_signing_secret' => ($_POST['r2_signing_secret'] ?? '') !== ''
                ? sanitize_text_field(wp_unslash($_POST['r2_signing_secret'])) : $existing['r2_signing_secret'],
        ];
        update_option(self::OPTION, $new);

        wp_safe_redirect(add_query_arg('bhy_media_token_saved', '1', admin_url('admin.php?page=ous-media-setup')));
        exit;
    }
}
