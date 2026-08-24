<?php
if (!defined('ABSPATH')) exit;

/**
 * The front-end user bar VISION.md names directly: wp-admin's own
 * toolbar is admin-oriented, often hidden entirely for an ordinary
 * subscriber/customer account (see BHI_Portal's own admin-bar
 * exclusion), and easy for the actual target user of most of these
 * plugins — a fan, a student, a supporter — to never see at all. This
 * is the one place in the whole ecosystem that's ALWAYS visible to an
 * ordinary logged-in user regardless of which plugin they're using
 * right now, meant to read as a genuinely designed piece of THIS
 * site's own UI (the same --bh-* brand tokens the portal/course pages
 * already use) — deliberately NOT a copy of WordPress's own dark
 * admin-toolbar look, and deliberately docked at the BOTTOM of the
 * viewport rather than the top, so it never reads as "the WP toolbar,
 * but on the front end."
 *
 * Quick links are contributed by any plugin via the `bhi_user_bar_links`
 * filter — same zero-central-registration shape as `bhi_portal_panels`/
 * `bhcore_metrics_widgets`. Each entry: ['label' => string, 'url' =>
 * string, 'meta' => optional string live micro-state]. A plugin should
 * only ever contribute a link when it has something ACTUALLY LIVE to
 * say (obvious-or-gone) — never a placeholder link to "browse courses"
 * for someone not enrolled in anything.
 */
class OUS_UserBar {
    public static function init(): void {
        add_action('wp_body_open', [self::class, 'render']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
        add_filter('bhi_user_bar_links', [self::class, 'register_own_links']);
    }

    // This plugin's own contribution — always-present Account/Log out,
    // the one pair of links that makes sense regardless of which peer
    // plugins are active.
    /**
     * @param array<int, array<string, mixed>> $links
     * @return array<int, array<string, mixed>>
     */
    public static function register_own_links($links): array {
        $links[] = ['label' => 'Account', 'url' => home_url('/account/')];
        $links[] = ['label' => 'Log out', 'url' => wp_logout_url(home_url())];
        return $links;
    }

    public static function render(): void {
        if (!is_user_logged_in() || is_admin()) return;
        $user_id = get_current_user_id();
        $links = apply_filters('bhi_user_bar_links', []);
        $unread = class_exists('OUS_Notifications') ? OUS_Notifications::unread_count($user_id) : 0;

        echo '<div class="bhi-user-bar">';
        echo '<div class="bhi-user-bar-inner">';

        if (class_exists('OUS_Notifications')) {
            echo '<a class="bhi-user-bar-bell" href="' . esc_url(home_url('/account/notifications/')) . '" aria-label="Notifications' . ($unread ? ' (' . (int) $unread . ' unread)' : '') . '">';
            echo '&#128276;';
            if ($unread) echo '<span class="bhi-user-bar-badge">' . (int) $unread . '</span>';
            echo '</a>';
        }

        echo '<div class="bhi-user-bar-links">';
        foreach ($links as $link) {
            if (empty($link['label']) || empty($link['url'])) continue;
            echo '<a class="bhi-user-bar-link" href="' . esc_url($link['url']) . '">';
            echo esc_html($link['label']);
            if (!empty($link['meta'])) echo '<span class="bhi-user-bar-link-meta">' . esc_html($link['meta']) . '</span>';
            echo '</a>';
        }
        echo '</div>';

        echo '</div></div>';
    }

    public static function maybe_enqueue(): void {
        if (!is_user_logged_in() || is_admin()) return;

        wp_register_style('bhi-user-bar', false);
        wp_enqueue_style('bhi-user-bar');
        // A real, considered piece of this site's own chrome — --bh-*
        // brand tokens (BHY_Style::inline_css(), already loaded on every
        // front-end page), a soft shadow lifting it off the page rather
        // than a flat bar, rounded top corners so it visually "docks"
        // instead of just clipping the viewport edge like a native
        // browser/OS toolbar would.
        wp_add_inline_style('bhi-user-bar', '
            /* Real bug, caught live: bh-contest/bh-streaming\'s own
               bottom bars need to know this bar\'s exact height to stack
               above it without a gap or an overlap — they were guessing
               56px (this bar\'s OWN body padding-bottom value below,
               which deliberately includes a few extra px of breathing
               room under scrolled content) instead of this bar\'s true
               rendered height, leaving a stray 7px gap between the two
               bars. Fixing the height at 49px (8px inner padding top+
               bottom + the 32px bell icon + 1px top border — matches
               what this already rendered as) and exposing it as a
               custom property so other plugins reference the real
               number instead of guessing it a second time.
            */
            :root { --bhi-user-bar-height: 49px; }
            .bhi-user-bar {
                position: fixed; left: 0; right: 0; bottom: 0; z-index: 99998;
                height: var(--bhi-user-bar-height); box-sizing: border-box;
                background: var(--bh-surface, #fff); border-top: 1px solid var(--bh-border, #e2e2e2);
                box-shadow: 0 -2px 10px rgba(0,0,0,0.08);
                border-radius: 12px 12px 0 0;
            }
            /* Tried a frosted-glass (backdrop-filter blur) treatment
               here to dodge the color clash against bh-contest\'s dark
               player bar — live-checked and reverted: it revealed
               blurred page content bleeding through (noisy, not
               "magical"), and once this bar was translucent while the
               still-opaque player bar sat right above it, the mismatch
               became a MATERIAL clash (glass vs. flat) instead of just
               a color one. This bar is global site chrome that shows up
               on every front-end page regardless of that page\'s own
               theme — an opaque, consistent identity of its own is the
               right call here, same as a browser\'s or OS\'s own chrome
               doesn\'t try to blend into every page it sits above. The
               real fix for the stacking case is the corner-flattening
               rule below, not disguising this bar as part of the page. */
            .bhi-user-bar-inner {
                max-width: 960px; margin: 0 auto; padding: 8px 16px;
                display: flex; align-items: center; gap: 14px; overflow-x: auto;
            }
            .bhi-user-bar-bell {
                position: relative; flex: 0 0 auto; display: flex; align-items: center; justify-content: center;
                width: 32px; height: 32px; border-radius: 50%; background: var(--bh-surface-2, #f6f6f7);
                text-decoration: none; font-size: 15px;
            }
            .bhi-user-bar-badge {
                position: absolute; top: -4px; right: -4px; background: var(--bh-accent, #b3502e); color: #fff;
                font-size: 10px; font-weight: 700; border-radius: 9px; padding: 0 5px; line-height: 1.5;
            }
            .bhi-user-bar-links { display: flex; align-items: center; gap: 10px; overflow-x: auto; }
            .bhi-user-bar-link {
                flex: 0 0 auto; font-size: 13px; color: var(--bh-text, #1d2327); text-decoration: none;
                padding: 6px 12px; border-radius: 999px; background: var(--bh-surface-2, #f6f6f7); white-space: nowrap;
            }
            .bhi-user-bar-link:hover { background: var(--bh-accent-soft, #eef4ff); }
            .bhi-user-bar-link-meta { color: var(--bh-text-dim, #6b7280); margin-left: 6px; }
            body.bhi-has-user-bar { padding-bottom: 56px; }
            /* Real bug, caught live: the rounded top corners + lift
               shadow above assume this bar sits directly over ordinary
               page content — correct on most pages, but on bh-contest/
               bh-streaming pages with their own fixed bottom playback
               bar (already shifted up to sit above this one, see
               .bh-now-playing-bar / .bhs-nowplaying), the two read as
               two separate floating chrome pieces stacked with an
               awkward gap/shadow between them instead of one continuous
               dock. Flattening this bar\'s corners/shadow when a player
               bar is present makes the seam read as one dock, two zones,
               rather than two mismatched cards. Doesn\'t solve the
               deeper dark-contest-theme vs. light-account-bar color
               clash — that needs a real per-surface theming decision,
               not a shape fix. */
            body:has(.bh-now-playing-bar) .bhi-user-bar,
            body:has(.bhs-nowplaying) .bhi-user-bar {
                border-radius: 0; box-shadow: none;
            }
            @media (max-width: 480px) {
                .bhi-user-bar-links { gap: 6px; }
                .bhi-user-bar-link { padding: 5px 10px; font-size: 12px; }
            }
        ');

        // A visible bar needs the page to leave room for it, or it
        // covers whatever's at the bottom of the viewport (a footer, a
        // sticky CTA) — added via a plain inline <script> in the footer
        // rather than a server-side body class, since some themes build
        // <body> markup in ways a filter can't reliably reach on every
        // install.
        add_action('wp_footer', function () {
            echo '<script>document.body.classList.add("bhi-has-user-bar");</script>';
        }, 1);
    }
}
