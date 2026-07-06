<?php
if (!defined('ABSPATH')) exit;

/**
 * Pure activation orchestration — resolving and executing the correct
 * order (dependencies before dependents, installing anything missing
 * along the way). No redirects, no nonces; OUS_Dashboard's admin-post
 * handlers own the HTTP side and call into this.
 */
class OUS_ActivationManager {
    // Activates $key AND any inactive/missing dependencies first, in
    // order — for a plugin registered with real dependencies via the
    // filter (see class-registry.php), a person clicking "Activate"
    // shouldn't need to already understand what has to go first. Neither
    // bh-contest nor bh-streaming currently declares a dependency here
    // (their one real dependency is this same plugin, already active by
    // definition if you're looking at this dashboard) — this exists for
    // whatever gets registered with a real one later.
    public static function activate_with_dependencies($key) {
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

    // Dependencies first, then $key itself — a flat list is enough here
    // since nothing in this ecosystem currently has a dependency more
    // than one level deep.
    private static function resolve_activation_order($key) {
        $order = OUS_Registry::all()[$key]['depends_on'];
        $order[] = $key;
        return array_unique($order);
    }
}
