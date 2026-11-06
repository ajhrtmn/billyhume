<?php
if (!defined('ABSPATH')) exit;

/**
 * Small cohesion banner on other ecosystem plugins' admin screens, linking
 * back to the dashboard. One-directional: those plugins never call this.
 * Dismissal stores a signature of the active plugin set per user, so the
 * banner returns only when that set changes.
 */
class OUS_Banner {
    const DISMISS_META = 'ous_banner_dismissed_signature';

    public static function init(): void {
        add_action('admin_post_ous_dismiss_banner', [self::class, 'handle_dismiss']);
    }

    // Order-independent fingerprint of the active ecosystem plugins.
    private static function active_signature(): string {
        $active = [];
        foreach (OUS_Registry::all() as $key => $info) {
            if ($info['check_class'] && class_exists($info['check_class'])) $active[] = $key;
        }
        sort($active);
        return md5(implode(',', $active));
    }

    public static function handle_dismiss(): void {
        if (!is_user_logged_in() || !check_admin_referer('ous_dismiss_banner', '_wpnonce')) {
            wp_die('Not allowed.');
        }
        update_user_meta(get_current_user_id(), self::DISMISS_META, self::active_signature());
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    public static function maybe_print(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) return;
        if (strpos($screen->id, 'ous') !== false) return; // not on our own pages

        // Broad match on purpose: a miss just means no banner. bh-crm's
        // hyphenated slug doesn't match the underscore prefixes, so it's listed.
        $needles = ['bh_', 'bhi_', 'bhs_', 'bh-crm'];
        $matched = false;
        foreach ($needles as $needle) {
            if (strpos($screen->id, $needle) !== false) { $matched = true; break; }
        }
        if (!$matched) return;

        $signature = self::active_signature();
        if (!$signature) return;

        $dismissed = get_user_meta(get_current_user_id(), self::DISMISS_META, true);
        if ($dismissed && $dismissed === $signature) return;

        $dismiss_url = wp_nonce_url(admin_url('admin-post.php?action=ous_dismiss_banner'), 'ous_dismiss_banner');

        echo '<style>
            .ous-banner{background:#111;color:#fff;padding:8px 16px;margin:10px 0;border-radius:6px;font-size:13px;
                display:flex;align-items:center;justify-content:space-between;gap:12px;}
            .ous-banner a{color:#fff;text-decoration:underline;}
            .ous-banner-close{background:none;border:none;color:#fff;opacity:.7;cursor:pointer;font-size:16px;line-height:1;padding:0 2px;}
            .ous-banner-close:hover{opacity:1;}
        </style>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){
            var h1 = document.querySelector(".wrap h1, .wrap h2");
            if (!h1) return;
            var b = document.createElement("div"); b.className = "ous-banner";
            var msg = document.createElement("span");
            msg.innerHTML = "Part of the <a href=\"' . esc_url(admin_url('admin.php?page=ous')) . '\">The Self-Hosted Self</a> ecosystem.";
            var close = document.createElement("button");
            close.type = "button"; close.className = "ous-banner-close"; close.setAttribute("aria-label", "Dismiss");
            close.innerHTML = "&times;";
            close.addEventListener("click", function () {
                b.remove();
                var img = new Image(); img.src = ' . wp_json_encode($dismiss_url) . ' + "&_=" + Date.now();
            });
            b.appendChild(msg); b.appendChild(close);
            h1.parentNode.insertBefore(b, h1.nextSibling);
        });</script>';
    }
}
