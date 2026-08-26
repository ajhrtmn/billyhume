<?php
if (!defined('ABSPATH')) exit;

/**
 * v1's only BHL_HostProvisioner — Fly.io Machines (api.machines.dev),
 * a real, documented REST API (confirmed against fly.io/docs/machines/
 * api/ 2026-07-26, not guessed): POST /v1/apps/{app}/machines to
 * create a box running a container image, GET the same path + /{id}
 * for status, POST .../stop and DELETE .../{id} to tear down. Chosen
 * over a plain always-on VPS specifically because Fly Machines start
 * in under a second and can be stopped between streams — AJ's own ask
 * was "administered from the WP plugin, hosted elsewhere," and this
 * is the closest real match: no droplet running (and billing) 24/7,
 * a "Deploy" click in the wizard is a real API call, not a support
 * ticket or an SSH session.
 *
 * One real gap, documented honestly rather than automated around:
 * Fly's Machines REST API has NO endpoint for allocating a public
 * IPv4/IPv6 address — that's only available via `flyctl` or Fly's
 * GraphQL API (confirmed against fly.io/docs 2026-07-26). A brand-new
 * Fly app already gets its shared *.fly.dev hostname for HTTP/TLS
 * traffic for free (Owncast's web UI, embeds, and chat all work
 * immediately), but raw TCP passthrough on a non-standard port — which
 * is exactly what RTMP ingest (1935) needs — requires a DEDICATED
 * IPv4, which is a one-time `fly ips allocate-v4 -a <app-name>` a
 * human runs once, outside this plugin. Surfaced plainly in the
 * wizard UI rather than silently assumed to already work.
 */
class BHL_FlyProvisioner implements BHL_HostProvisioner {
    const API_BASE = 'https://api.machines.dev/v1';
    const OWNCAST_IMAGE = 'owncast/owncast:latest';
    const RTMP_PORT = 1935;
    const OWNCAST_HTTP_PORT = 8080;

    /**
     * @return array<string, string> keys: api_token, app_name, org_slug,
     *     region, active_machine_id — not a precise shape type since
     *     the-self-hosted-self's class-media-wizard.php also reads this method's
     *     return value with its own defensive `?? ''` fallbacks.
     */
    public static function settings(): array {
        return get_option('bhl_fly_settings', ['api_token' => '', 'app_name' => '', 'org_slug' => 'personal', 'region' => 'iad', 'active_machine_id' => '']);
    }

    public static function save_settings(string $api_token, string $app_name, string $org_slug, string $region): void {
        $existing = self::settings();
        update_option('bhl_fly_settings', [
            // A blank token field means "keep what's already saved" —
            // same convention every other credential field in this
            // ecosystem's wizards already uses.
            'api_token'          => ($api_token === '') ? $existing['api_token'] : sanitize_text_field($api_token),
            'app_name'           => sanitize_key($app_name),
            'org_slug'           => sanitize_key($org_slug ?: 'personal'),
            'region'             => sanitize_key($region ?: 'iad'),
            'active_machine_id'  => $existing['active_machine_id'],
        ]);
    }

    private static function set_active_machine_id(string $id): void {
        $s = self::settings();
        $s['active_machine_id'] = $id;
        update_option('bhl_fly_settings', $s);
    }

    public function is_configured(): bool {
        $s = self::settings();
        return !empty($s['api_token']) && !empty($s['app_name']);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|\WP_Error
     */
    private function request(string $method, string $path, ?array $body = null) {
        $s = self::settings();
        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $s['api_token'],
                'Content-Type'  => 'application/json',
            ],
        ];
        if ($body !== null) $args['body'] = wp_json_encode($body);

        $response = wp_remote_request(self::API_BASE . $path, $args);
        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $message = is_array($data) && !empty($data['error']) ? $data['error'] : ('Fly API returned HTTP ' . $code);
            // Status carried as error data (live-robustness audit
            // addition) — destroy() below needs to tell "the machine is
            // genuinely gone (404)" apart from a real API failure, and
            // a bare error string can't be branched on reliably.
            return new WP_Error('fly_api_error', $message, ['status' => $code]);
        }
        return is_array($data) ? $data : [];
    }

    // Idempotent by design — safe to call every time provision() runs,
    // same "check-and-repair-if-missing" self-healing shape this
    // ecosystem already uses elsewhere (BHY_RewriteHealer's own
    // reference pattern) rather than a separate one-time setup step
    // a user could forget to run.
    /** @return bool|\WP_Error */
    private function ensure_app_exists() {
        $s = self::settings();
        $existing = $this->request('GET', '/apps/' . $s['app_name']);
        if (!is_wp_error($existing)) return true; // already exists

        $created = $this->request('POST', '/apps', [
            'app_name' => $s['app_name'],
            'org_slug' => $s['org_slug'],
        ]);
        return is_wp_error($created) ? $created : true;
    }

    /** @return array{host_id:string, public_url:string}|\WP_Error */
    public function provision() {
        if (!$this->is_configured()) return new WP_Error('not_configured', 'Fly.io isn\'t configured yet — enter an API token and app name first.');

        $app_check = $this->ensure_app_exists();
        if (is_wp_error($app_check)) return $app_check;

        $s = self::settings();
        $machine = $this->request('POST', '/apps/' . $s['app_name'] . '/machines', [
            'region' => $s['region'],
            'config' => [
                'image' => self::OWNCAST_IMAGE,
                'guest' => ['cpu_kind' => 'shared', 'cpus' => 1, 'memory_mb' => 1024],
                'services' => [
                    // Owncast's own web UI, REST API, and embed/chat
                    // routes — Fly terminates TLS automatically for
                    // 'tls'/'http' handlers on the app's shared
                    // *.fly.dev hostname, no dedicated IP needed for
                    // this one.
                    ['protocol' => 'tcp', 'internal_port' => self::OWNCAST_HTTP_PORT, 'ports' => [
                        ['port' => 443, 'handlers' => ['tls', 'http']],
                        ['port' => 80, 'handlers' => ['http']],
                    ]],
                    // RTMP ingest — raw TCP passthrough. Reachable only
                    // once a dedicated IPv4 is allocated to this app
                    // (see this class's own docblock) — declared here
                    // regardless, since the service config itself is
                    // valid and correct the moment that one-time step
                    // is done.
                    ['protocol' => 'tcp', 'internal_port' => self::RTMP_PORT, 'ports' => [
                        ['port' => self::RTMP_PORT, 'handlers' => ['tcp']],
                    ]],
                ],
            ],
        ]);
        if (is_wp_error($machine)) return $machine;

        $id = $machine['id'] ?? '';
        if (!$id) return new WP_Error('no_machine_id', 'Fly created the machine but returned no id — check the Fly dashboard directly.');
        self::set_active_machine_id($id);

        return ['host_id' => $id, 'public_url' => 'https://' . $s['app_name'] . '.fly.dev'];
    }

    // Normalizes Fly's own state values (confirmed against fly.io/docs
    // 2026-07-26: created, starting, started, stopping, stopped,
    // suspended, destroying, destroyed) down to the three states
    // anything calling this actually needs to act on.
    /** @return array{state:string, raw_state?:string}|\WP_Error */
    public function get_status(string $host_id) {
        $s = self::settings();
        if (empty($host_id)) return new WP_Error('no_host', 'No machine has been provisioned yet.');

        $machine = $this->request('GET', '/apps/' . $s['app_name'] . '/machines/' . $host_id);
        if (is_wp_error($machine)) return $machine;

        $raw = $machine['state'] ?? 'unknown';
        $map = [
            'started' => 'running',
            'created' => 'starting', 'starting' => 'starting',
            'stopping' => 'stopped', 'stopped' => 'stopped', 'suspended' => 'stopped',
            'destroying' => 'stopped', 'destroyed' => 'stopped',
        ];
        return ['state' => $map[$raw] ?? 'unknown', 'raw_state' => $raw];
    }

    /** @return bool|\WP_Error */
    public function destroy(string $host_id) {
        $s = self::settings();
        if (empty($host_id)) return new WP_Error('no_host', 'No machine to destroy.');

        // Fly requires a stopped machine before deleting (a running one
        // rejects the DELETE) — stop first, best-effort, then delete
        // with force so an already-stopped/mid-transition machine
        // doesn't block cleanup entirely.
        $this->request('POST', '/apps/' . $s['app_name'] . '/machines/' . $host_id . '/stop');
        $result = $this->request('DELETE', '/apps/' . $s['app_name'] . '/machines/' . $host_id . '?force=true');
        // A 404 here means the machine is ALREADY gone (deleted directly
        // via the Fly dashboard/CLI, outside this plugin entirely) —
        // live-robustness audit fix: treating that as a failure left
        // active_machine_id permanently stuck pointing at a machine that
        // no longer exists, with no way for an admin to clear it short
        // of editing the option directly. "Already achieved the goal
        // state" is success, not an error, for a destroy/delete action.
        if (is_wp_error($result)) {
            $error_data = $result->get_error_data();
            $status = is_array($error_data) ? (int) ($error_data['status'] ?? 0) : 0;
            if ($status !== 404) return $result;
        }

        self::set_active_machine_id('');
        return true;
    }
}
