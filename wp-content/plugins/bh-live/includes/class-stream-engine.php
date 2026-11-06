<?php
if (!defined('ABSPATH')) exit;

/**
 * Video engine contract: everything reading live status or rendering an embed
 * goes through this, never a specific server's API. No start_stream(): a stream
 * starts when the broadcaster's software connects to RTMP ingest.
 */
interface BHL_StreamEngine {
    /** @return array{online:bool, viewer_count:int, stream_title:string}|\WP_Error */
    public function get_status();
    public function get_embed_html(): string; // -> an <iframe>-based embed, '' if not configured
    /** @return bool|\WP_Error true on success, WP_Error if unsupported/failed */
    public function disconnect();
    public function is_configured(): bool;
}

/**
 * Owncast engine (self-hosted RTMP ingest, chat and player), running on its own
 * box, not the WP host. disconnect() uses the token-gated admin API.
 */
class BHL_OwncastEngine implements BHL_StreamEngine {
    public function is_configured(): bool {
        $s = self::settings();
        return !empty($s['server_url']);
    }

    /** @return array{server_url:string, access_token:string} */
    public static function settings(): array {
        return get_option('bhl_owncast_settings', ['server_url' => '', 'access_token' => '']);
    }

    public static function save_settings(string $server_url, ?string $access_token = null): void {
        $existing = self::settings();
        $clean = ['server_url' => untrailingslashit(esc_url_raw($server_url))];
        // Blank token keeps the saved one (the form never re-displays it).
        $clean['access_token'] = ($access_token === null || $access_token === '') ? $existing['access_token'] : sanitize_text_field($access_token);
        update_option('bhl_owncast_settings', $clean);
    }

    // Owncast's public, unauthenticated status endpoint.
    /** @return array{online:bool, viewer_count:int, stream_title:string}|\WP_Error */
    public function get_status() {
        $s = self::settings();
        if (empty($s['server_url'])) return new WP_Error('not_configured', 'No Owncast server URL is set yet.');

        $response = wp_remote_get($s['server_url'] . '/api/status', ['timeout' => 8]);
        if (is_wp_error($response)) return $response;
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('bad_response', 'Owncast server responded with an unexpected status.');
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) return new WP_Error('bad_response', 'Owncast server did not return valid status data.');

        return [
            'online'       => !empty($data['online']),
            'viewer_count' => (int) ($data['viewerCount'] ?? 0),
            'stream_title' => (string) ($data['streamTitle'] ?? ''),
        ];
    }

    // Owncast's bundled video-only embed; chat is served separately via BHL_OwncastChat.
    public function get_embed_html(): string {
        $s = self::settings();
        if (empty($s['server_url'])) return '';
        $src = esc_url($s['server_url'] . '/embed/video');
        return '<iframe src="' . $src . '" style="width:100%;aspect-ratio:16/9;border:0;" allowfullscreen></iframe>';
    }

    /** @return bool|\WP_Error */
    public function disconnect() {
        $s = self::settings();
        if (empty($s['server_url'])) return new WP_Error('not_configured', 'No Owncast server URL is set yet.');
        if (empty($s['access_token'])) return new WP_Error('no_token', 'No admin access token is configured — disconnect requires one.');

        $response = wp_remote_post($s['server_url'] . '/api/admin/disconnect', [
            'timeout' => 8,
            'headers' => ['Authorization' => 'Bearer ' . $s['access_token']],
        ]);
        if (is_wp_error($response)) return $response;
        return wp_remote_retrieve_response_code($response) === 200 ? true : new WP_Error('disconnect_failed', 'Owncast rejected the disconnect request — check the access token.');
    }
}
