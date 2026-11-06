<?php
if (!defined('ABSPATH')) exit;

/**
 * Contract for provisioning the box that runs the live engine: provision, status,
 * destroy. Engine-agnostic; BHL_StreamEngine takes over once the box is up.
 */
interface BHL_HostProvisioner {
    public function is_configured(): bool;
    /** @return array{host_id:string, public_url:string}|\WP_Error */
    public function provision();
    /** @return array{state:string}|\WP_Error */
    public function get_status(string $host_id);
    /** @return bool|\WP_Error */
    public function destroy(string $host_id);
}
