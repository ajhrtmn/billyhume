<?php
if (!defined('ABSPATH')) exit;

/**
 * The abstraction the whole video/live scoping decision hinges on
 * (wondrous-mixing-forest.md, "Decided: Owncast for v1 ... behind
 * bh-live's own abstraction so OvenMediaEngine ... is a swap-in later,
 * not a rewrite"). Anything that reads live status or renders an embed
 * goes through this interface, never talks to Owncast's REST API
 * directly — a future BHL_OvenMediaEngine implementing the same
 * contract is the entire migration.
 *
 * Deliberately small: get the current live/offline status, get an
 * embeddable player, and (where the underlying engine actually
 * supports it) force-disconnect a stream. There is no start_stream()
 * — see BHL_OwncastEngine's own docblock for why that's not a fake
 * omission but an honest reflection of how RTMP ingest actually works.
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
 * v1's only implementation. Owncast is a real, self-hosted "your own
 * Twitch" server (AGPL, github.com/owncast/owncast) — RTMP ingest,
 * chat, and a web player all bundled in one deployable unit, chosen
 * specifically for how little there is to stand up before a first real
 * stream works (AJ's own call, 2026-07-26 scoping pass). It runs on
 * its OWN dedicated box, never on this WordPress install's own shared
 * hosting — a real-time media server is a different operational
 * animal than PHP/MySQL, the same "thin integration, not a reinvented
 * client" posture OUS_MediaWizard already takes toward Advanced Media
 * Offloader.
 *
 * What this class deliberately does NOT do: start a broadcast. Owncast
 * has no "start streaming" REST call — a stream begins the moment
 * OBS/streaming software connects to the server's RTMP endpoint using
 * the configured stream key, entirely outside WordPress's control.
 * disconnect() is the one real write action Owncast's admin API
 * exposes (POST /api/admin/disconnect — forcibly ends whatever is
 * currently live), gated on an access token because it's a genuine
 * admin action, not a public one.
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
        // A blank token field means "keep what's already saved" — the
        // settings screen never re-displays a saved token, same rule
        // OUS_MediaWizard's own credential fields already use.
        $clean['access_token'] = ($access_token === null || $access_token === '') ? $existing['access_token'] : sanitize_text_field($access_token);
        update_option('bhl_owncast_settings', $clean);
    }

    // Owncast's own real, public, no-auth-required status endpoint —
    // documented at owncast.online/docs/api/#get-current-broadcast-status.
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

    // Owncast's own bundled embed routes — a real, documented feature
    // (owncast.online/docs/embed/), not a custom player being built
    // here. video-only embed; the bundled chat has its own /embed/chat
    // route, left for bh-live's future chat-abstraction work rather
    // than wired in this scaffold.
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
