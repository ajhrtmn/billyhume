<?php
if (!defined('ABSPATH')) exit;

/**
 * The optional cohesion banner shown on OTHER ecosystem plugins' own
 * admin screens — a distinct concern from rendering this plugin's own
 * dashboard page, so it gets its own class rather than living inside
 * OUS_Dashboard alongside unrelated rendering logic.
 *
 * Purely additive and one-directional: detects (via each registry
 * entry's check_class) whether the current admin screen probably
 * belongs to one of the ecosystem plugins, and if so, prints a small
 * shared banner linking back to the dashboard. Those plugins never call
 * into this code — this reaches out to them, never the other way
 * around, which is what keeps the whole relationship one-directional.
 *
 * Dismissible, per-user: closing it stores a "signature" of the
 * currently-active ecosystem plugin set against that user, and the
 * banner stays hidden as long as that exact set hasn't changed. The
 * moment a NEW ecosystem plugin gets installed/activated, the signature
 * changes, the stored dismissal no longer matches, and the banner comes
 * back — which is the "only show again on new plugins/installs" behavior
 * asked for, without needing a separate "seen version" counter to keep
 * in sync by hand.
 */
class OUS_Banner {
    const DISMISS_META = 'ous_banner_dismissed_signature';

    public static function init() {
        add_action('admin_post_ous_dismiss_banner', [self::class, 'handle_dismiss']);
    }

    // A stable fingerprint of exactly which ecosystem plugins are
    // currently active — order-independent, so activating the same set
    // in a different order never falsely reopens the banner.
    private static function active_signature() {
        $active = [];
        foreach (OUS_Registry::all() as $key => $info) {
            if ($info['check_class'] && class_exists($info['check_class'])) $active[] = $key;
        }
        sort($active);
        return md5(implode(',', $active));
    }

    public static function handle_dismiss() {
        if (!is_user_logged_in() || !check_admin_referer('ous_dismiss_banner', '_wpnonce', false)) {
            wp_die('Not allowed.');
        }
        update_user_meta(get_current_user_id(), self::DISMISS_META, self::active_signature());
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    public static function maybe_print() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) return;
        if (strpos($screen->id, 'own-ur-shit') !== false) return; // don't banner our own page

        // Broad on purpose: this only needs to catch "probably one of
        // ours" well enough to show a nice-to-have banner, not gate any
        // real functionality — a false negative just means one less
        // banner somewhere, never a broken screen. Most of this
        // ecosystem prefixes its post types and admin page slugs with
        // bh_, bhi_, or bhs_, which shows up directly in $screen->id.
        // bh-crm is the one exception — its menu slug is the hyphenated
        // "bh-crm" (no trailing underscore segment), which the
        // underscore-based prefixes below don't match on their own, so
        // it's listed explicitly rather than silently never bannered.
        $needles = ['bh_', 'bhi_', 'bhs_', 'bh-crm'];
        $matched = false;
        foreach ($needles as $needle) {
            if (strpos($screen->id, $needle) !== false) { $matched = true; break; }
        }
        if (!$matched) return;

        $signature = self::active_signature();
        if (!$signature) return; // nothing active at all — nothing to banner

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
            msg.innerHTML = "Part of the <a href=\"' . esc_url(admin_url('admin.php?page=own-ur-shit')) . '\">Own Ur Shit</a> ecosystem.";
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
