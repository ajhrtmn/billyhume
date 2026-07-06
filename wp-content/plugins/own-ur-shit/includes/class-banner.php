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
 */
class OUS_Banner {
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

        $any_active = false;
        foreach (OUS_Registry::all() as $info) {
            if ($info['check_class'] && class_exists($info['check_class'])) { $any_active = true; break; }
        }
        if (!$any_active) return;

        echo '<style>.ous-banner{background:#111;color:#fff;padding:8px 16px;margin:10px 0;border-radius:6px;font-size:13px;}
              .ous-banner a{color:#fff;text-decoration:underline;}</style>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){
            var h1 = document.querySelector(".wrap h1, .wrap h2");
            if (h1) { var b = document.createElement("div"); b.className = "ous-banner";
            b.innerHTML = "Part of the <a href=\"' . esc_url(admin_url('admin.php?page=own-ur-shit')) . '\">Own Ur Shit</a> ecosystem.";
            h1.parentNode.insertBefore(b, h1.nextSibling); }
        });</script>';
    }
}
