<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared "user can $cap AND nonce is valid" predicate for admin-post/AJAX
 * handlers; each caller keeps its own failure handling. An unset nonce
 * ('') is a clean false.
 */
class OUS_AdminGuard {
    /** @param mixed $nonce_value */
    public static function verify_nonce_and_cap(string $capability, $nonce_value, string $nonce_action): bool {
        return current_user_can($capability) && wp_verify_nonce((string) $nonce_value, $nonce_action);
    }
}
