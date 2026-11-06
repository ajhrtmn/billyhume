<?php
if (!defined('ABSPATH')) exit;

/**
 * Front-end user bar for logged-in users, docked at the bottom and styled with
 * --bh-* tokens (deliberately not a copy of the WP toolbar). Plugins add links
 * via `bhi_user_bar_links`: ['label' => string, 'url' => string, 'meta' =>
 * optional live micro-state]. Only contribute a link with something live to say.
 */
class OUS_UserBar {
    public static function init(): void {
        add_action('wp_body_open', [self::class, 'render']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
        add_filter('bhi_user_bar_links', [self::class, 'register_own_links']);
    }

    // Always-present Account / Log out links.
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

        // Reserve page space for the bar via an inline footer script; some themes'
        // <body> markup is not reachable by a body-class filter.
        add_action('wp_footer', function () {
            echo '<script>document.body.classList.add("bhi-has-user-bar");</script>';
        }, 1);
    }
}
