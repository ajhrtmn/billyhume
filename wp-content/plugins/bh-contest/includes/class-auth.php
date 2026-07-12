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

        // bh-contest's first real BH_Element surface (class-element-
        // surface.php's own docblock explains the scope/why). Deliberately
        // OUTSIDE the JS-owned #bh-player-root div, not inside it — player.js
        // rebuilds that div's entire innerHTML on load (renderSkeleton()),
        // so anything placed inside it would be wiped the instant the
        // script runs. These two zones are real server-rendered HTML,
        // siblings of the player, not something player.js knows about or
        // touches at all.
        $before = ''; $after = '';
        if ($cid && class_exists('BH_Element')) {
            $before = BH_Element::render_slot('bh_contest_player', $cid, 'before_player', ['contest_id' => $cid]);
            $after  = BH_Element::render_slot('bh_contest_player', $cid, 'after_player', ['contest_id' => $cid]);

            // Task #80's real, safe slice: this one DOES need to end up
            // INSIDE the header bar player.js builds — a sibling wouldn't
            // visually read as "part of the header" the way a real
            // header addition should. Since player.js owns and rebuilds
            // #bh-player-root's entire innerHTML on load, the only safe
            // way in is to hand player.js the already-rendered HTML as
            // data (base64, same reasoning class-style-gallery.php's own
            // shadow-DOM data-doc attribute uses — avoids any HTML-
            // attribute-escaping edge case a raw JSON/HTML string could
            // hit) and let IT insert it once, into a brand-new
            // '.bh-header-extra' div that does not exist in the header
            // today and does not replace anything that does.
            $header_extra = BH_Element::render_slot('bh_contest_player', $cid, 'header_extra', ['contest_id' => $cid]);
            if (trim(wp_strip_all_tags($header_extra)) !== '' || strpos($header_extra, '<') !== false) {
                // render_slot() always returns at least the empty wrapper
                // div even with zero placements (own-ur-shit 3.4.49's own
                // fix) — only bother passing it to player.js if there's
                // real content inside, not an empty wrapper every single
                // page load.
                if (self::slot_has_visible_content($header_extra)) {
                    $attrs .= ' data-header-extra="' . esc_attr(base64_encode($header_extra)) . '"';
                }
            }
        }

        return $before . '<div ' . $attrs . '></div>' . $after;
    }

    // render_slot()'s wrapper div is always present, even empty — this
    // tells "genuinely empty" apart from "has real placement content"
    // without player.js needing to know anything about BH_Element's own
    // wrapper convention.
    private static function slot_has_visible_content($html) {
        return trim(wp_strip_all_tags($html)) !== '' || preg_match('/<(img|svg|iframe|video|audio)\b/i', $html) === 1;
    }
}
