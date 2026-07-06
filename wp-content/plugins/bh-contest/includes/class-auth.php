<?php
if (!defined('ABSPATH')) exit;

/**
 * Narrowed scope as of the identity migration: this class now only
 * renders the [bh_contest_player] shortcode. Registration, login,
 * logout, session, profile, and email verification all live in the
 * Own Ur Shit core plugin now — see BHI_Auth. Kept the class name to
 * avoid a larger renaming cascade across every file that still
 * legitimately references "the thing that renders the player."
 */
class BH_Auth {
    public static function init() {
        add_shortcode('bh_contest_player', [self::class, 'render']);
    }

    public static function render($atts) {
        $atts = shortcode_atts(['contest' => ''], $atts, 'bh_contest_player');
        $raw  = trim((string) $atts['contest']);
        $cid  = 0;

        if ($raw !== '') {
            $cid = BH_Helpers::resolve_contest($raw);
            if (!$cid && current_user_can('edit_posts')) {
                // Only shown to logged-in editors — visitors just see nothing
                // rather than a broken-looking box.
                return '<p style="padding:12px 16px;background:#3a2a00;color:#ffcf6b;border-radius:6px;'
                     . 'font-family:sans-serif;font-size:13px;">'
                     . '<strong>BH Contest:</strong> no contest matches <code>' . esc_html($raw) . '</code>. '
                     . 'Check Contests → the Shortcode column for the exact value. (Only editors see this notice.)'
                     . '</p>';
            }
        }

        static $i = 0; $i++;
        $attrs = 'class="bh-player-root" id="bh-player-root-' . $i . '" data-contest="' . esc_attr($cid ?: '') . '"';
        if ($cid) {
            $payload = BHY_Style::entity_style_payload($cid);
            if ($payload) $attrs .= ' data-style-overrides="' . esc_attr(wp_json_encode($payload)) . '"';
        }
        return '<div ' . $attrs . '></div>';
    }
}
