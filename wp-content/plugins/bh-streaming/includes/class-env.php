<?php
if (!defined('ABSPATH')) exit;

/**
 * Streaming surfaces (dashboard card, admin menus, shortcode/block) are
 * visible by default, so visibility ships with a normal deploy.
 * define('BHS_FORCE_HIDDEN', true) in wp-config.php hides them on one install.
 */
class BHS_Env {
    public static function hidden_in_production(): bool {
        return defined('BHS_FORCE_HIDDEN') && BHS_FORCE_HIDDEN;
    }
}
