<?php
if (!defined('ABSPATH')) exit;

/**
 * The real-time, paid-tier chat option — true WebSocket delivery via a
 * Cloudflare Worker + Durable Object (assets/worker/chat-worker.js),
 * deployed to the user's OWN Cloudflare account by this class rather
 * than a service bh-live itself runs. Same "administered from the WP
 * plugin, hosted elsewhere" posture as BHL_FlyProvisioner, just for
 * chat instead of the video server.
 *
 * Reuses the Cloudflare account_id/api_token already entered for
 * BHL_CloudflareStreamEngine (one Cloudflare login, not two separate
 * credential forms) — only adds its own script_name and
 * workers_subdomain fields, the latter because Cloudflare's Workers
 * REST API has no endpoint to look up an account's own workers.dev
 * subdomain slug (confirmed against their docs 2026-07-26); it's
 * entered once, same treatment as Cloudflare Stream's own
 * "customer code" field.
 *
 * Real, stated-honestly limitations (see chat-worker.js's own
 * docblock for the fullest version): v1 is anonymous and unmoderated
 * — no bridge from WordPress identity/capabilities into the deployed
 * Worker exists yet — and one specific detail of the deploy request
 * (the Content-Type Cloudflare expects on an ES-module script part,
 * "application/javascript+module") could NOT be confirmed against
 * Cloudflare's own docs in this session (their multipart-upload page
 * doesn't state it) — it's asserted from general knowledge of their
 * API, not verified here the way every other request shape in this
 * plugin was. Flagged plainly rather than presented as equally solid;
 * a real deploy attempt is the way to confirm it.
 */
class BHL_WorkersChat implements BHL_Chat {
    const SCRIPT_ASSET_PATH = 'assets/worker/chat-worker.js';
    const MAIN_MODULE = 'chat-worker.js';
    const DO_CLASS_NAME = 'ChatRoom';
    const DO_BINDING_NAME = 'CHAT_ROOM';

    public static function settings() {
        return get_option('bhl_workers_settings', ['script_name' => 'bh-live-chat', 'workers_subdomain' => '', 'deployed' => false]);
    }

    public static function save_settings($script_name, $workers_subdomain) {
        $existing = self::settings();
        $existing['script_name'] = sanitize_key($script_name ?: 'bh-live-chat');
        $existing['workers_subdomain'] = sanitize_text_field($workers_subdomain);
        update_option('bhl_workers_settings', $existing);
    }

    private static function set_deployed($deployed) {
        $existing = self::settings();
        $existing['deployed'] = (bool) $deployed;
        update_option('bhl_workers_settings', $existing);
    }

    // Deliberately reads BHL_CloudflareStreamEngine's OWN settings
    // rather than duplicating account_id/api_token storage — one
    // Cloudflare credential, shared by both classes. This does mean
    // BHL_WorkersChat has a soft dependency on that class existing,
    // same as anything else in this plugin that reads another class's
    // settings() directly.
    private static function cloudflare_credentials() {
        return class_exists('BHL_CloudflareStreamEngine') ? BHL_CloudflareStreamEngine::settings() : ['account_id' => '', 'api_token' => ''];
    }

    public function is_configured() {
        $cf = self::cloudflare_credentials();
        $s = self::settings();
        return !empty($cf['account_id']) && !empty($cf['api_token']) && !empty($s['workers_subdomain']) && !empty($s['deployed']);
    }

    private function request($method, $path, $body = null, $extra_headers = []) {
        $cf = self::cloudflare_credentials();
        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => array_merge(['Authorization' => 'Bearer ' . $cf['api_token']], $extra_headers),
        ];
        if ($body !== null) $args['body'] = $body;

        $response = wp_remote_request('https://api.cloudflare.com/client/v4' . $path, $args);
        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || empty($data['success'])) {
            $message = is_array($data) && !empty($data['errors'][0]['message']) ? $data['errors'][0]['message'] : ('Cloudflare API returned HTTP ' . $code);
            return new WP_Error('cloudflare_api_error', $message);
        }
        return $data['result'] ?? [];
    }

    /**
     * Hand-built multipart/form-data body — WordPress's HTTP API has
     * no helper for arbitrary named multipart parts (only the file-
     * upload-specific one), so this constructs it directly per
     * Cloudflare's own documented shape (confirmed 2026-07-26): a
     * 'metadata' JSON part plus one part named exactly like
     * main_module, containing the script.
     */
    private static function build_multipart_body($boundary, $metadata, $script) {
        $body = '';
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Disposition: form-data; name=\"metadata\"\r\n";
        $body .= "Content-Type: application/json\r\n\r\n";
        $body .= wp_json_encode($metadata) . "\r\n";

        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="' . self::MAIN_MODULE . '"; filename="' . self::MAIN_MODULE . "\"\r\n";
        // See this class's own docblock: this Content-Type is asserted
        // from general knowledge, not confirmed against Cloudflare's
        // docs this session.
        $body .= "Content-Type: application/javascript+module\r\n\r\n";
        $body .= $script . "\r\n";

        $body .= '--' . $boundary . "--\r\n";
        return $body;
    }

    /**
     * Deploys assets/worker/chat-worker.js to the configured Cloudflare
     * account, then enables its workers.dev subdomain so it's publicly
     * reachable. Idempotent — re-running this re-uploads the same
     * script (a "redeploy" action), same shape a version-bump would
     * naturally want anyway.
     */
    public function deploy() {
        $cf = self::cloudflare_credentials();
        $s = self::settings();
        if (empty($cf['account_id']) || empty($cf['api_token'])) {
            return new WP_Error('not_configured', 'Cloudflare account ID and API token aren\'t set yet — enter those in the Cloudflare Stream Live section above first (this reuses the same credentials).');
        }
        if (empty($s['workers_subdomain'])) {
            return new WP_Error('not_configured', 'Enter your Cloudflare workers.dev subdomain first (found on your account\'s Workers dashboard).');
        }

        $script_path = BHL_PATH . self::SCRIPT_ASSET_PATH;
        if (!file_exists($script_path)) return new WP_Error('script_missing', 'The bundled chat-worker.js asset is missing from this install.');
        $script = file_get_contents($script_path);

        $metadata = [
            'main_module' => self::MAIN_MODULE,
            'compatibility_date' => '2024-01-01',
            'bindings' => [
                ['type' => 'durable_object_namespace', 'name' => self::DO_BINDING_NAME, 'class_name' => self::DO_CLASS_NAME],
            ],
            'migrations' => [
                ['tag' => 'v1', 'new_sqlite_classes' => [self::DO_CLASS_NAME]],
            ],
        ];

        $boundary = 'bhl' . wp_generate_password(24, false);
        $body = self::build_multipart_body($boundary, $metadata, $script);

        $upload = $this->request('PUT', '/accounts/' . $cf['account_id'] . '/workers/scripts/' . $s['script_name'], $body, [
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        ]);
        if (is_wp_error($upload)) return $upload;

        $subdomain_result = $this->request('POST', '/accounts/' . $cf['account_id'] . '/workers/scripts/' . $s['script_name'] . '/subdomain', wp_json_encode(['enabled' => true]), [
            'Content-Type' => 'application/json',
        ]);
        if (is_wp_error($subdomain_result)) return $subdomain_result;

        self::set_deployed(true);
        return ['url' => $this->base_url()];
    }

    private function base_url() {
        $s = self::settings();
        return 'https://' . $s['script_name'] . '.' . $s['workers_subdomain'] . '.workers.dev';
    }

    public function render($stream_id) {
        if (!$this->is_configured()) return ['type' => 'iframe', 'html' => ''];
        $src = esc_url($this->base_url() . '/?stream=' . (int) $stream_id);
        return ['type' => 'iframe', 'html' => '<iframe src="' . $src . '" style="width:100%;height:100%;min-height:400px;border:0;"></iframe>'];
    }
}
