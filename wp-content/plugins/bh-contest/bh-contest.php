<?php
/**
 * Plugin Name: BH Contest
 * Description: Music contest voting platform with a sleek, native-feeling player.
 * Version:     3.2.1
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

// 3.2.1 — 2026-07-12 — task #80's real, safe slice: a genuinely new
// 'header_extra' zone on the 'bh_contest_player' surface (class-element-
// surface.php), landing INSIDE the header bar itself for the first time
// — next to the brand/Results/Submit/Login/Logout buttons, not replacing
// any of them. player.js's renderSkeleton() still builds the entire
// required skeleton exactly as before (every this.q('.bh-results-btn')-
// style lookup elsewhere in that file is completely untouched); the only
// change is one new '.bh-header-extra' div that starts empty and is
// filled, once, by a new injectHeaderExtra() reading class-auth.php's
// base64-encoded real render_slot() output off a data attribute.
// player.css's ':empty { display: none; }' rule means a contest with no
// header_extra content renders byte-identical to before this pass.
//
// Deliberately still NOT touched: the header's own required buttons,
// the tabs/tracklist/now-playing bar, and the auth/submit/results
// modals — those stay exactly as risky to convert as WALKTHROUGH-
// GUIDE.md already flags, unchanged by this pass. This is a real,
// additive step forward, not a reversal of that caution.

// 3.2.0 — 2026-07-12 — bh-contest's first real BH_Element surface (AJ
// named this plugin the litmus test for real content/component/widget
// authoring — see class-element-surface.php's own docblock for the full
// scope reasoning). New 'bh_contest_player' surface, two slots
// ('before_player'/'after_player'), rendered server-side in class-
// auth.php's [bh_contest_player] shortcode as real siblings of the
// player's own JS-owned mount div — NOT inside it, since player.js
// rebuilds that div's entire innerHTML on load and would silently wipe
// anything placed inside it.
//
// Deliberately NOT converted this pass: the player's actual interactive
// skeleton (header/tabs/tracklist/now-playing bar/auth+submit modals,
// assets/js/player.js's renderSkeleton()) — every other method in that
// file depends on that exact markup via this.q('.bh-results-btn')-style
// lookups for auth-state, voting, and playback. Turning that into
// BH_Element placements safely means guaranteeing every placement always
// emits those exact required classes, which is real, live-breaking risk
// to take on with no browser available this session to verify against.
// Flagged in WALKTHROUGH-GUIDE.md as real follow-up work, not silently
// deferred.

// 3.1.3 — real bug fix: Live Console's contest-picker dropdown threw
// "Sorry, you are not allowed to access this page." on selection. Root
// cause: the page is registered as a submenu of edit.php?post_type=bh_contest,
// but the dropdown's <form method="get"> only carried page=bh-console —
// a bare GET form replaces the whole query string with just its own
// fields, so post_type was silently dropped and WordPress couldn't
// resolve the submenu. Fixed in class-console.php by adding a hidden
// post_type=bh_contest field to the form. NOT yet verified against the
// live site — user reported the symptom, fix follows from reading the
// exact form-submission mechanics; please confirm the dropdown now works.

// 3.1.1 — logging depth pass: BH_Discord::send() previously returned
// false identically for "no webhook configured" (routine, most contests
// don't have one) and "webhook configured but fails URL validation" (a
// real misconfiguration silently killing every notification for that
// contest). The second case now logs a throttled warning via
// OUS_DebugLog. Standing caveat: reasoning/brace-balance-checked only,
// not run against a real WordPress+MySQL install.
//
// 3.1.2 — continuation logging pass: vote add/remove DB writes
// (class-api.php's vote()) were previously unchecked and the response
// always claimed success regardless — now logged as 'error' on a real
// failure. Submission wp_insert_post()/media_handle_sideload() failures
// now log the actual WP_Error message instead of discarding it.
// email_winners() now tracks and logs which specific winners' emails
// failed to send in a bulk announce, instead of the whole batch's
// success/failure being invisible.
// 3.1.4 — bundled zip regenerated to match installed version, no code change
// 3.1.5 — vote()'s toggle-add and toggle-remove paths (class-api.php)
// now additionally emit a BH_Event 'bh/vote' event (own-ur-shit's new
// event-tracking layer, class-event.php) after each write commits —
// fire-and-forget, never inside the vote-limit transaction itself, so
// the synchronous votes_left response this endpoint returns is
// unaffected. See EVENT-TRACKING-ARCHITECTURE-PLAN.md Section 6.
// Standing caveat: reasoning/brace-balance-checked only, not run
// against a real WordPress+MySQL install.
//
// 3.1.6 — class-debug.php's register() now sets 'group' =>
// OUS_Debug::GROUP_SEED_RESET on this plugin's Debug Tools section, part
// of own-ur-shit's Debug Tools reorganization pass. No functional change
// to this plugin itself. Standing caveat: reasoning/brace-balance-
// checked only, not run against a real WordPress+MySQL install.
define('BH_VER',        '3.2.0');
define('BH_PATH',       plugin_dir_path(__FILE__));
define('BH_URL',        plugin_dir_url(__FILE__));
define('BH_VOTE_BASE',  1);                 // votes every user gets
define('BH_VOTE_BONUS', 1);                 // extra votes earned by submitting a track
define('BH_MAX_BYTES',  20 * 1024 * 1024);  // max upload size
define('BH_REG_THROTTLE', 3);               // max registrations per IP per hour
define('BH_LOGIN_MAX_FAILS', 5);            // failed logins (per username+IP) before a 15-minute lockout

foreach (['activator', 'post-types', 'helpers', 'auth', 'api', 'admin', 'debug', 'crm-integration', 'console', 'reveal', 'discord', 'archive', 'style-surfaces', 'element-surface', 'portal-panel'] as $f) {
    require_once BH_PATH . "includes/class-$f.php";
}

// Safe to register unconditionally — activation only creates this
// plugin's own table/default pages, neither of which touches the
// identity/style classes this plugin depends on for its actual
// features, so there's nothing here that can fatal-error even if the
// dependency below turns out to be missing.
register_activation_hook(__FILE__, ['BH_Activator', 'activate']);

/**
 * Everything else is gated behind plugins_loaded rather than checked
 * directly here at file-parse time. That distinction matters: WordPress
 * loads active plugins' files in alphabetical folder order, so a direct
 * class_exists() check at the top of this file could run BEFORE the
 * dependency's own file has even been read yet on a given request,
 * regardless of whether that dependency is genuinely active — a real,
 * previously-shipped bug, not a hypothetical one. plugins_loaded is a
 * hard WordPress guarantee: it only ever fires after EVERY active
 * plugin's main file has already been fully loaded, so by the time this
 * callback runs, the check is reliable no matter which letter either
 * plugin's folder happens to start with.
 */
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Contest</strong> requires the <strong>Own Ur Shit</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    // One-time migration of existing profile data into the (now merged)
    // core plugin's identity table. The two schemas are identical (this
    // plugin's table was the original source that table was extracted
    // from), so this is a single direct copy rather than field-by-field
    // remapping — INSERT IGNORE means it's safe to run more than once.
    if (get_option('bh_identity_migration_done') !== '1') {
        global $wpdb;
        $old = $wpdb->prefix . 'bh_participant_profiles';
        $new = $wpdb->prefix . 'bhi_profiles';
        $old_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $old)) === $old;
        $new_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $new)) === $new;

        if (!$old_exists) {
            update_option('bh_identity_migration_done', '1');
        } elseif ($new_exists) {
            $wpdb->query(
                "INSERT IGNORE INTO $new
                    (user_id, real_name, discord_name, twitch_name, youtube_name, phone, typical_platform, real_name_public, discord_public, twitch_public, youtube_public, updated_at)
                 SELECT user_id, real_name, discord_name, twitch_name, youtube_name, phone, typical_platform, real_name_public, discord_public, twitch_public, youtube_public, updated_at
                 FROM $old"
            );
            if (!$wpdb->last_error) update_option('bh_identity_migration_done', '1');
        }
        // Neither branch taken (old exists, new doesn't yet) means the
        // core plugin's own table isn't ready yet — leave the flag unset
        // so this retries on a later request instead of silently giving
        // up on a migration that should have happened.
    }

    BH_Activator::maybe_upgrade();
    BH_Activator::maybe_migrate_style_meta_keys();

    add_action('admin_init',    ['BH_Activator', 'maybe_create_default_pages']);
    add_action('init',          ['BH_PostTypes', 'register']);
    // BH_Event registration (own-ur-shit's event-tracking layer) — see
    // class-api.php's vote handler for the actual emit() call, fired
    // additively after the vote's own transaction commits. Per
    // EVENT-TRACKING-ARCHITECTURE-PLAN.md Section 6.
    add_action('init', function () {
        if (class_exists('BH_Event')) {
            BH_Event::register_event_type('bh/vote', ['contest_id' => 'int', 'category' => 'string', 'submission_id' => 'int', 'action' => 'string']);
        }
    });
    add_action('init',          ['BH_Auth', 'init']);
    add_action('rest_api_init', ['BH_API', 'register_routes']);
    add_action('init',          ['BH_Admin', 'init']);
    add_action('init',          ['BH_CRMIntegration', 'init']);
    add_action('init',          ['BH_StyleSurfaces', 'init']);
    add_action('init',          ['BH_ElementSurface', 'init']);
    add_action('init',          ['BH_Console', 'init']);
    add_action('init',          ['BH_Reveal', 'init']);
    add_action('init',          ['BH_Discord', 'init']);
    add_action('init',          ['BH_Archive', 'init']);

    // Registers this plugin's seeding/reset actions into the shared
    // Debug Tools page (see OUS_Debug in the core plugin) — the
    // production-safety check (OUS_Debug::is_locked()) is centralized
    // there now, checked once for every registered plugin's actions.
    add_action('init', ['BH_Debug', 'init']);
    add_action('init', ['BH_PortalPanel', 'init']);

    // Load assets only on pages that actually use the player, and hand
    // the front end everything it needs up front (REST base, a fresh
    // nonce, auth state) so there is no extra round trip before first
    // paint.
    add_action('wp_enqueue_scripts', function () {
        if (!is_singular()) return;
        global $post;
        if (!$post) return;
        $has_player   = has_shortcode($post->post_content, 'bh_contest_player');
        $has_reveal   = has_shortcode($post->post_content, 'bh_results_reveal');
        $has_archive  = has_shortcode($post->post_content, 'bh_archive');
        if (!$has_player && !$has_reveal && !$has_archive) return;

        // Shared by all three front-end shortcodes — same fonts, same
        // stylesheet, same theme variables (including any per-contest
        // override), so a Results Reveal or Archive page always matches
        // whatever look the main player has, automatically.
        $font_url = BHY_Style::google_fonts_url();
        if ($font_url) wp_enqueue_style('bh-fonts', $font_url, [], null);
        wp_enqueue_style('bh-player', BH_URL . 'assets/css/player.css', $font_url ? ['bh-fonts'] : [], BH_VER);
        wp_add_inline_style('bh-player', BHY_Style::inline_css());

        if ($has_player) {
            wp_enqueue_script('howler', 'https://cdnjs.cloudflare.com/ajax/libs/howler/2.2.4/howler.min.js', [], '2.2.4', true);
            wp_enqueue_script('bh-player', BH_URL . 'assets/js/player.js', ['howler'], BH_VER, true);
            $brand = BHY_Style::get();
            wp_localize_script('bh-player', 'BHData', [
                'rest'     => esc_url_raw(rest_url('bh/v1/')),
                'identity' => esc_url_raw(rest_url('bhi/v1/')),
                'nonce'    => wp_create_nonce('wp_rest'),
                'loggedIn' => is_user_logged_in(),
                'maxBytes' => BH_MAX_BYTES,
                'brand'    => ['part1' => $brand['brand_part1'], 'part2' => $brand['brand_part2'], 'logoUrl' => BHY_Style::logo_url($brand)],
            ]);
        }

        if ($has_reveal) {
            wp_enqueue_script('bh-common', BH_URL . 'assets/js/bh-common.js', [], BH_VER, true);
            wp_enqueue_script('bh-reveal', BH_URL . 'assets/js/reveal.js', ['bh-common'], BH_VER, true);
            wp_localize_script('bh-reveal', 'BHData', [
                'rest' => esc_url_raw(rest_url('bh/v1/')),
            ]);
        }

        if ($has_archive) {
            wp_enqueue_script('bh-common', BH_URL . 'assets/js/bh-common.js', [], BH_VER, true);
            wp_enqueue_script('bh-archive', BH_URL . 'assets/js/archive.js', ['bh-common'], BH_VER, true);
            wp_localize_script('bh-archive', 'BHData', [
                'rest' => esc_url_raw(rest_url('bh/v1/')),
            ]);
        }
    });
});
