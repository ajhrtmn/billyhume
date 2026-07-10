<?php
if (!defined('ABSPATH')) exit;

/**
 * BH_Identity — anonymous client_id <-> user_id stitching for BH_Event
 * (see class-event.php and EVENT-TRACKING-ARCHITECTURE-PLAN.md Section
 * 3). A visitor gets a long-lived, first-party client_id cookie
 * (`bh_cid`) on first touch, present on every event whether or not
 * they're ever logged in — the actual join key that survives the
 * pre-signup -> post-signup transition, since user_id is derived from
 * it, not the other way around.
 *
 * Storage-design choice: no separate stitching table. The mapping is
 * just "which client_id cookie was present in the browser that logged
 * in/registered as user X," and the only thing that actually needs it
 * is backfilling user_id onto already-stored bhcore_events rows for
 * that client_id — which BH_Event::backfill_user_id() does directly,
 * a one-shot UPDATE, not an ongoing join. A dedicated stitching table
 * would only earn its keep if something needed to query "every
 * client_id a user has ever used" as an ongoing need; nothing in this
 * ecosystem does today, so the simpler one-shot backfill is the
 * implementation here — revisit if that need shows up later.
 */
class BH_Identity {
    const COOKIE = 'bh_cid';
    const COOKIE_TTL = YEAR_IN_SECONDS * 2;

    private static $client_id = null;

    public static function init() {
        add_action('init', [self::class, 'maybe_issue_cookie'], 1);
        add_action('wp_login', [self::class, 'on_known'], 10, 2);
        add_action('user_register', [self::class, 'on_registered']);
    }

    // Issues the cookie on first touch (front-end requests only — no
    // point tagging wp-admin/REST-only traffic with a visitor cookie).
    // Runs at init priority 1 so the client_id is available to anything
    // else hooking 'init' at normal priority (BH_Event consumers, etc).
    public static function maybe_issue_cookie() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return;
        if (!empty($_COOKIE[self::COOKIE])) {
            self::$client_id = sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE]));
            return;
        }
        if (headers_sent()) return;

        $cid = wp_generate_password(32, false, false);
        self::$client_id = $cid;
        setcookie(self::COOKIE, $cid, [
            'expires'  => time() + self::COOKIE_TTL,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    // Usable both mid-request (after maybe_issue_cookie() has run on
    // 'init') and as a fallback read directly off $_COOKIE for callers
    // that fire before/without that hook (e.g. a REST request that
    // never goes through the front-end 'init' flow in the same way).
    public static function client_id() {
        if (self::$client_id !== null) return self::$client_id;
        if (!empty($_COOKIE[self::COOKIE])) {
            return sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE]));
        }
        return '';
    }

    /* ---------------- stitching ---------------- */

    public static function on_known($user_login, $user) {
        self::stitch((int) $user->ID);
    }

    public static function on_registered($user_id) {
        self::stitch((int) $user_id);
    }

    private static function stitch($user_id) {
        $cid = self::client_id();
        if (!$cid || !$user_id) return;
        if (class_exists('BH_Event')) {
            BH_Event::backfill_user_id($cid, $user_id);
        }
    }

    /* ---------------- reverse lookup for consumers (added for bh-crm's activity view) ---------------- */

    // There is no separate stitching table (see the docblock above) —
    // the only place a user_id <-> client_id association is actually
    // recorded is on bhcore_events rows themselves, via
    // backfill_user_id()'s one-shot UPDATE. So this is NOT a lookup
    // against some dedicated identity table; it's just "which
    // client_id values have ever appeared on an events row already
    // stamped with this user_id" — useful for debugging/inspection
    // (e.g. "which cookies/devices has this person's history touched"),
    // but NOT required to pull that person's own activity, since any
    // row already carrying their user_id is already found by a plain
    // `WHERE user_id = %d` query — see BHCRM_Event_Activity::for_user()
    // in bh-crm, which queries that way directly rather than going
    // through this method. Returns an array of distinct, non-empty
    // client_id strings; empty array if none or if $user_id is falsy.
    public static function client_ids_for_user($user_id) {
        $user_id = (int) $user_id;
        if (!$user_id) return [];
        global $wpdb;
        $table = $wpdb->prefix . 'bhcore_events';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT client_id FROM {$table} WHERE user_id = %d AND client_id != ''",
            $user_id
        ));
        return is_array($ids) ? $ids : [];
    }
}
