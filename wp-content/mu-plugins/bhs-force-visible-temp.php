<?php
if (!defined('ABSPATH')) exit;

/**
 * TEMPORARY, scoped to billyhume.wasmer.app only — real cross-site
 * federation test (publish a real track live, pull it via Browse
 * Registry). Flips BHS_Env::hidden_in_production() back on for this
 * one host via the documented override constant (class-env.php),
 * without touching wp-config.php (gitignored, not deployable here via
 * git push — this repo's wp-content/plugins and mu-plugins ARE
 * auto-deployed). DELETE this file once the round-trip test is done.
 */
if (($_SERVER['HTTP_HOST'] ?? '') === 'billyhume.wasmer.app') {
    define('BHS_FORCE_VISIBLE', true);
}
