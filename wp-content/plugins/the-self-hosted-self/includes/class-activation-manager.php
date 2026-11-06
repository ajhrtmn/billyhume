<?php
if (!defined('ABSPATH')) exit;

/**
 * Activation orchestration: dependencies before dependents, installing
 * anything missing. No redirects or nonces.
 */
class OUS_ActivationManager {
    // Activates $key after installing/activating its dependencies.
    public static function activate_with_dependencies(string $key): bool {
        if (!isset(OUS_Registry::all()[$key])) return false;

        foreach (self::resolve_activation_order($key) as $dep_key) {
            if (OUS_Registry::status($dep_key) === 'missing') {
                if (!OUS_Installer::install($dep_key)) return false;
            }
            if (OUS_Registry::status($dep_key) === 'inactive') {
                $result = activate_plugin(OUS_Registry::all()[$dep_key]['file']);
                if (is_wp_error($result)) return false;
            }
        }
        return true;
    }

    // Flat list: no dependency chain is currently more than one level deep.
    /** @return array<int, string> */
    private static function resolve_activation_order(string $key): array {
        $order = OUS_Registry::all()[$key]['depends_on'];
        $order[] = $key;
        return array_unique($order);
    }
}
