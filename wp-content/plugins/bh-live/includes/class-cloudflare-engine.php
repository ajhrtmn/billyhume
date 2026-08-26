<?php
if (!defined('ABSPATH')) exit;

/**
 * Second BHL_StreamEngine implementation — Cloudflare Stream Live
 * (developers.cloudflare.com/stream, API confirmed 2026-07-26), the
 * genuinely zero-infrastructure alternative to Owncast: no
 * BHL_HostProvisioner involved at all, since there's no box to
 * provision — a live input is created with one API call and Cloudflare
 * runs the ingest/transcode/delivery entirely on their own edge. Trades
 * "free, open-source, self-hosted" for "fully managed, metered
 * ($1-5/1000 min), zero ops" — the honest opposite tradeoff from
 * Owncast, surfaced as a genuine second choice in the wizard rather
 * than silently preferred or hidden.
 *
 * Real, meaningful limitation, not glossed over: Cloudflare Stream Live
 * is video-only — its API has no chat concept at all. Whichever engine
 * is active, BHL_Chat is a SEPARATE setting; pairing this engine with
 * a real chat means BHL_PollingChat (a custom implementation, matching
 * class-jam.php's own proven pattern) rather than anything Cloudflare
 * provides, unlike Owncast which bundles its own.
 *
 * Also real, not invented: Cloudflare's Live Inputs API has no
 * documented "force-disconnect the current broadcaster" action the way
 * Owncast's admin API does — disconnect() says so plainly rather than
 * silently no-op-ing or guessing at an endpoint that doesn't exist.
 */
class BHL_CloudflareStreamEngine implements BHL_StreamEngine {
    const API_BASE = 'https://api.cloudflare.com/client/v4';

    /** @return array{account_id:string, api_token:string, customer_code:string, live_input_uid:string, rtmps_url:string, stream_key:string} */
    public static function settings(): array {
        return get_option('bhl_cloudflare_settings', ['account_id' => '', 'api_token' => '', 'customer_code' => '', 'live_input_uid' => '', 'rtmps_url' => '', 'stream_key' => '']);
    }

    public static function save_connection_settings(string $account_id, string $api_token, string $customer_code): void {
        $existing = self::settings();
        $existing['account_id'] = sanitize_text_field($account_id);
        // Blank token keeps the existing saved one — same convention
        // every other credential field in this ecosystem's wizards use.
        if ($api_token !== '') $existing['api_token'] = sanitize_text_field($api_token);
        $existing['customer_code'] = sanitize_text_field($customer_code);
        update_option('bhl_cloudflare_settings', $existing);
    }

    private static function save_live_input(string $uid, string $rtmps_url, string $stream_key): void {
        $existing = self::settings();
        $existing['live_input_uid'] = $uid;
        $existing['rtmps_url'] = $rtmps_url;
        $existing['stream_key'] = $stream_key;
        update_option('bhl_cloudflare_settings', $existing);
    }

    public static function clear_live_input(): void {
        self::save_live_input('', '', '');
    }

    public function is_configured(): bool {
        $s = self::settings();
        return !empty($s['account_id']) && !empty($s['api_token']) && !empty($s['customer_code']);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|\WP_Error
     */
    private function request(string $method, string $path, ?array $body = null) {
        $s = self::settings();
        $args = [
            'method'  => $method,
            'timeout' => 15,
            'headers' => ['Authorization' => 'Bearer ' . $s['api_token'], 'Content-Type' => 'application/json'],
        ];
        if ($body !== null) $args['body'] = wp_json_encode($body);

        $response = wp_remote_request(self::API_BASE . $path, $args);
        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || empty($data['success'])) {
            $message = is_array($data) && !empty($data['errors'][0]['message']) ? $data['errors'][0]['message'] : ('Cloudflare API returned HTTP ' . $code);
            // Status carried as error data — delete_live_input() needs
            // to tell "already gone" (404) apart from a real failure,
            // same reasoning as BHL_FlyProvisioner/BHL_WorkersChat.
            return new WP_Error('cloudflare_api_error', $message, ['status' => $code]);
        }
        return $data['result'] ?? [];
    }

    /**
     * The one-time "create the live input" step — cheap enough (a
     * single API call, no boot time, nothing to poll) that it doesn't
     * need its own BHL_HostProvisioner-style abstraction the way
     * standing up a VPS does. Returns the RTMP ingest URL + stream key
     * a broadcaster plugs into OBS, same role Owncast's own stream key
     * plays, just issued by Cloudflare instead of self-hosted.
     */
    /**
     * Live-robustness audit fix (2026-08-26): the wizard's own button
     * label for calling this when a live input already exists says
     * "replaces the one above" — but this never actually deleted the
     * old one, only overwrote the LOCALLY stored UID. The old live
     * input stayed live on the Cloudflare account forever, an orphaned
     * (if low-cost — Stream Live only meters actual streamed minutes,
     * not an idle input) resource the UI actively misrepresented as
     * gone. Now genuinely replaces it: the old input is deleted
     * (best-effort — logged, never allowed to block creating the new
     * one, since a stray old input is a much smaller problem than a
     * broadcaster having no working stream key at all) after the new
     * one is confirmed created.
     *
     * @return array{uid:string, rtmps_url:string, stream_key:string}|\WP_Error
     */
    public function create_live_input() {
        if (!$this->is_configured()) return new WP_Error('not_configured', 'Cloudflare Stream isn\'t configured yet — enter an account ID, API token, and customer code first.');

        $s = self::settings();
        $previous_uid = $s['live_input_uid'];

        $result = $this->request('POST', '/accounts/' . $s['account_id'] . '/stream/live_inputs', [
            'meta' => ['name' => get_bloginfo('name') . ' — bh-live'],
            'recording' => ['mode' => 'automatic'], // VOD/replay parity with the bhl_stream lifecycle Owncast's own path already has
        ]);
        if (is_wp_error($result)) return $result;

        $uid = $result['uid'] ?? '';
        $rtmps = $result['rtmps']['url'] ?? '';
        $key = $result['rtmps']['streamKey'] ?? '';
        if (!$uid) return new WP_Error('no_uid', 'Cloudflare created the live input but returned no uid — check the Cloudflare dashboard directly.');

        self::save_live_input($uid, $rtmps, $key);

        if ($previous_uid && $previous_uid !== $uid) {
            $cleanup = $this->delete_live_input($previous_uid);
            if (is_wp_error($cleanup) && class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('warning', 'Could not delete the replaced Cloudflare live input — it may still exist on the account.', ['previous_uid' => $previous_uid, 'error' => $cleanup->get_error_message()], 'BH Live');
            }
        }
        return ['uid' => $uid, 'rtmps_url' => $rtmps, 'stream_key' => $key];
    }

    // 'status' is the ONLY signal Cloudflare's API gives for whether a
    // live input is currently receiving a broadcast right now
    // (confirmed against their own API reference 2026-07-26) — no
    // viewer-count field exists here the way Owncast's /api/status
    // provides one, so get_status() honestly can't report viewers for
    // this engine.
    /** @return array{online:bool, viewer_count:int, stream_title:string}|\WP_Error */
    public function get_status() {
        $s = self::settings();
        if (empty($s['live_input_uid'])) return new WP_Error('not_configured', 'No live input has been created yet.');

        $result = $this->request('GET', '/accounts/' . $s['account_id'] . '/stream/live_inputs/' . $s['live_input_uid']);
        if (is_wp_error($result)) return $result;

        $status = $result['status'] ?? null;
        return [
            'online'       => $status === 'connected',
            'viewer_count' => 0, // not exposed by this API — see this method's own comment
            'stream_title' => '',
        ];
    }

    public function get_embed_html(): string {
        $s = self::settings();
        if (empty($s['live_input_uid']) || empty($s['customer_code'])) return '';
        $src = esc_url('https://customer-' . $s['customer_code'] . '.cloudflarestream.com/' . $s['live_input_uid'] . '/iframe');
        return '<iframe src="' . $src . '" style="width:100%;aspect-ratio:16/9;border:0;" allow="autoplay; encrypted-media" allowfullscreen></iframe>';
    }

    /** @return bool|\WP_Error */
    public function disconnect() {
        return new WP_Error('not_supported', 'Cloudflare Stream Live has no API to force-disconnect a broadcaster — this has to be stopped from the broadcasting software\'s own end (OBS, etc.).');
    }

    /**
     * Real Cloudflare Stream API endpoint (DELETE /accounts/{account}/
     * stream/live_inputs/{uid}) — used by create_live_input() above to
     * genuinely honor its own "replaces the one above" claim, and
     * exposed publicly so the wizard can also offer a standalone
     * "remove live input" action without requiring a replacement to be
     * created first. A 404 (already deleted directly via Cloudflare's
     * own dashboard) is treated as success — same "already achieved the
     * goal state" reasoning as this plugin's other two provisioning
     * classes.
     *
     * @return true|\WP_Error
     */
    public function delete_live_input(string $uid) {
        if ($uid === '') return true; // nothing to delete
        $s = self::settings();
        $result = $this->request('DELETE', '/accounts/' . $s['account_id'] . '/stream/live_inputs/' . $uid);
        if (is_wp_error($result)) {
            $error_data = $result->get_error_data();
            $status = is_array($error_data) ? (int) ($error_data['status'] ?? 0) : 0;
            if ($status !== 404) return $result;
        }
        return true;
    }
}
