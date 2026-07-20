<?php
if (!defined('ABSPATH')) exit;

/**
 * DRY/SOLID audit Phase 4: the exact two-part check
 * `current_user_can($cap) || !wp_verify_nonce($nonce, $action)` (usually
 * negated as the guard-clause condition for a wp_die()/redirect/JSON
 * error) was duplicated nearly word-for-word at 18+ admin-post/admin-
 * ajax handler call sites across this core plugin and every consuming
 * plugin (bh-contest, bh-courses, bh-monetization-woo, bh-streaming,
 * bh-registry). This consolidates the boolean predicate itself into one
 * place — each call site keeps its own failure handling (a specific
 * wp_die() message, a redirect, a JSON error response), since THAT
 * varies legitimately by context and isn't what was actually
 * duplicated.
 *
 * Also accepts a nonce value that was never set at all (an unset
 * $_POST/$_GET key) the same way every existing call site's own
 * `$_POST['x'] ?? ''` fallback already did — wp_verify_nonce('', ...)
 * is already a clean, safe false, so callers don't need their own
 * isset() check first.
 */
class OUS_AdminGuard {
    public static function verify_nonce_and_cap($capability, $nonce_value, $nonce_action) {
        return current_user_can($capability) && wp_verify_nonce((string) $nonce_value, $nonce_action);
    }
}
