<?php
if (!defined('ABSPATH')) exit;

/**
 * Anonymous client_id <-> user_id stitching for BH_Event. A long-lived first-party `bh_cid`
 * cookie is the join key; on login/registration BH_Event::backfill_user_id() stamps that
 * user_id onto existing rows in one UPDATE (no separate stitching table).
 */
class BH_Identity {
    const COOKIE = 'bh_cid';
    const COOKIE_TTL = YEAR_IN_SECONDS * 2;

    /** @var string|null */
    private static $client_id = null;

    public static function init(): void {
        add_action('init', [self::class, 'maybe_issue_cookie'], 1);
        add_action('wp_login', [self::class, 'on_known'], 10, 2);
        add_action('user_register', [self::class, 'on_registered']);
    }

    // Issues the cookie on first front-end touch, at init priority 1 so it is available to
    // normal-priority 'init' consumers.
    public static function maybe_issue_cookie(): void {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return;
        $existing = self::cookie_value();
        if ($existing !== '') {
            self::$client_id = $existing;
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

    // Works after maybe_issue_cookie() or, before it runs (e.g. REST), straight from $_COOKIE.
    public static function client_id(): string {
        if (self::$client_id !== null) return self::$client_id;
        return self::cookie_value();
    }

    private static function cookie_value(): string {
        if (empty($_COOKIE[self::COOKIE])) return '';
        return sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE]));
    }

    /* ---------------- stitching ---------------- */

    public static function on_known(string $user_login, \WP_User $user): void {
        self::stitch((int) $user->ID);
    }

    public static function on_registered(int $user_id): void {
        self::stitch((int) $user_id);
    }

    private static function stitch(int $user_id): void {
        $cid = self::client_id();
        if (!$cid || !$user_id) return;
        if (class_exists('BH_Event')) {
            BH_Event::backfill_user_id($cid, $user_id);
        }
    }

    /* ---------------- reverse lookup for consumers ---------------- */

    /**
     * Distinct client_ids seen on events rows already stamped with this user_id (inspection
     * only; activity queries should use WHERE user_id = %d directly). Empty if none.
     *
     * @return array<int, string>
     */
    public static function client_ids_for_user(int $user_id): array {
        $user_id = (int) $user_id;
        if (!$user_id) return [];
        if (!class_exists('BH_Event')) return [];
        global $wpdb;
        $table = BH_Event::table();
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT client_id FROM {$table} WHERE user_id = %d AND client_id != ''",
            $user_id
        ));
        return is_array($ids) ? $ids : [];
    }
}
