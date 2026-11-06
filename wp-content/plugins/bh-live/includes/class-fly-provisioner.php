<?php
if (!defined('ABSPATH')) exit;

/**
 * BHL_HostProvisioner on Fly.io Machines (api.machines.dev), running Owncast.
 * WHY: RTMP ingest (raw TCP 1935) needs a dedicated IPv4, which the Machines API
 * cannot allocate; the user runs `fly ips allocate-v4 -a <app-name>` once.
 */
class BHL_FlyProvisioner implements BHL_HostProvisioner {
    const API_BASE = 'https://api.machines.dev/v1';
    const OWNCAST_IMAGE = 'owncast/owncast:latest';
    const RTMP_PORT = 1935;
    const OWNCAST_HTTP_PORT = 8080;

    /**
     * @return array<string, string> keys: api_token, app_name, org_slug,
     *     region, active_machine_id (kept loose; class-media-wizard.php also reads it).
     */
    public static function settings(): array {
        return get_option('bhl_fly_settings', ['api_token' => '', 'app_name' => '', 'org_slug' => 'personal', 'region' => 'iad', 'active_machine_id' => '']);
    }

    public static function save_settings(string $api_token, string $app_name, string $org_slug, string $region): void {
        $existing = self::settings();
        update_option('bhl_fly_settings', [
            // Blank token keeps the existing saved one.
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
            // WHY: destroy() reads the status to treat 404 as "already gone".
            return new WP_Error('fly_api_error', $message, ['status' => $code]);
        }
        return is_array($data) ? $data : [];
    }

    // Idempotent; safe to call on every provision().
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
                    // Owncast web UI/API/embeds; Fly terminates TLS on the shared *.fly.dev host.
                    ['protocol' => 'tcp', 'internal_port' => self::OWNCAST_HTTP_PORT, 'ports' => [
                        ['port' => 443, 'handlers' => ['tls', 'http']],
                        ['port' => 80, 'handlers' => ['http']],
                    ]],
                    // RTMP ingest, raw TCP; reachable only once a dedicated IPv4 is allocated.
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

    // Collapses Fly's machine states to running/starting/stopped.
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

        // WHY: Fly rejects DELETE on a running machine; stop first (best-effort), then force-delete.
        $this->request('POST', '/apps/' . $s['app_name'] . '/machines/' . $host_id . '/stop');
        $result = $this->request('DELETE', '/apps/' . $s['app_name'] . '/machines/' . $host_id . '?force=true');
        // A 404 means already gone; treat as success so active_machine_id can clear.
        if (is_wp_error($result)) {
            $error_data = $result->get_error_data();
            $status = is_array($error_data) ? (int) ($error_data['status'] ?? 0) : 0;
            if ($status !== 404) return $result;
        }

        self::set_active_machine_id('');
        return true;
    }
}
