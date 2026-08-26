<?php
if (!defined('ABSPATH')) exit;

/**
 * 2026-08-26: streaming IS what's being shipped to production now —
 * AJ's explicit call, reversing the state this class originally
 * shipped in. The three surfaces below (dashboard card, admin menus,
 * front-end shortcode/block) are visible everywhere by default now.
 *
 * The FTP-deploy model this ecosystem uses (files copied directly,
 * never a fresh WP "Activate" click) is also why flipping this from
 * the live site's own wp-config.php never worked as the original
 * design assumed: define('BHS_FORCE_VISIBLE', true) requires editing
 * wp-config.php directly on the server, which isn't tracked by git and
 * isn't part of the deploy pipeline at all — there was never a way to
 * ship "streaming is now visible" through the normal push-to-master
 * flow. Inverting the default (visible unless explicitly hidden) fixes
 * that: this state now ships the same way every other change does.
 *
 * Escape hatch, same mechanism inverted: define('BHS_FORCE_HIDDEN',
 * true) in wp-config.php re-hides all three surfaces on one specific
 * install without another code change — for a staging/preview site
 * that wants to keep building on the NEXT unreleased streaming feature
 * without it leaking, the original problem this class solved.
 */
class BHS_Env {
    public static function hidden_in_production(): bool {
        return defined('BHS_FORCE_HIDDEN') && BHS_FORCE_HIDDEN;
    }
}
