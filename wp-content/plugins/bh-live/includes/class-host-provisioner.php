<?php
if (!defined('ABSPATH')) exit;

/**
 * The abstraction the "which cloud runs the box" decision hinges on —
 * same posture as BHL_StreamEngine/BHL_Chat. Fly.io Machines is v1's
 * only implementation; a future BHL_DigitalOceanProvisioner (or
 * anything else with a real API) implements this same contract, so
 * swapping providers is a new class, not a rewrite of the wizard or
 * anything that calls provision()/destroy() today.
 *
 * Deliberately small: provision a box running the configured live-
 * engine image, check its current state, tear it down. Nothing here
 * knows anything about Owncast specifically — that's BHL_StreamEngine's
 * job once the box is up and its URL is in bhl_owncast_settings.
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
