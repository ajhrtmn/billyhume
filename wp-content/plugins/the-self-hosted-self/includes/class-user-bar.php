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

        wp_enqueue_style('bhi-user-bar', OUS_URL . 'assets/css/user-bar.css', [], OUS_VER);

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
