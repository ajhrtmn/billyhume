<?php
if (!defined('ABSPATH')) exit;

/**
 * Everyone who self-registers through the portal (BHI_Auth::register)
 * gets a real wp_users row — that's deliberate: the whole ecosystem
 * (course progress, contest identity, CRM, wallet, notifications, 2FA,
 * password reset) joins on user ID, and reimplementing WordPress auth
 * for a parallel "person" type would be a larger, more fragile, LESS
 * safe surface than hardening the one WordPress already ships.
 *
 * So instead: a fan account is the lowest-privilege thing it can be —
 * the dedicated `bh_member` role (read, nothing else — see OUS_Roles),
 * locked out of wp-admin (BHI_Portal::excluded_roles), and this class
 * closes the ambient surface a low-value account otherwise still has:
 *
 *   - author / user enumeration (?author=N, /author/slug/, the REST
 *     users route, the users sitemap, oEmbed author fields)
 *   - Application Passwords (an API-token factory a read-only fan has
 *     no use for)
 *   - XML-RPC pingback methods (amplification / probing)
 *   - login error text that distinguishes "no such user" from "wrong
 *     password"
 *
 * Every piece is filterable so a site that genuinely needs one back
 * can have it, and nothing here touches administrator/editor/author or
 * any logged-in request from a non-excluded role.
 */
class BHI_MemberHardening {

    const MIGRATED_OPTION = 'bhi_member_role_migrated';

    public static function init(): void {
        // --- enumeration ---
        add_action('template_redirect', [self::class, 'block_author_enumeration']);
        add_filter('rest_endpoints', [self::class, 'filter_rest_user_endpoints']);
        add_filter('wp_sitemaps_add_provider', [self::class, 'drop_users_sitemap'], 10, 2);
        add_filter('oembed_response_data', [self::class, 'strip_oembed_author']);
        add_filter('rest_prepare_user', [self::class, 'trim_rest_user_fields'], 10, 3);

        // --- account surface a read-only member doesn't need ---
        add_filter('wp_is_application_passwords_available_for_user', [self::class, 'app_passwords_for_user'], 10, 2);
        add_filter('xmlrpc_methods', [self::class, 'filter_xmlrpc_methods']);
        add_filter('xmlrpc_enabled', [self::class, 'maybe_disable_xmlrpc']);

        // --- generic login errors (no user-exists oracle) ---
        add_filter('login_errors', [self::class, 'generic_login_errors']);

        // --- one-time migration of pre-existing plain subscribers ---
        add_action('admin_init', [self::class, 'maybe_migrate_subscribers']);
    }

    /* ------------------------------------------------------------------ */
    /* helpers                                                            */
    /* ------------------------------------------------------------------ */

    /** Roles treated as "ordinary fan/student/supporter" — the same set
     *  the portal locks out of wp-admin, so the two stay in lockstep. */
    private static function fan_roles(): array {
        if (class_exists('BHI_Portal') && method_exists('BHI_Portal', 'excluded_roles')) {
            return (array) BHI_Portal::excluded_roles();
        }
        return apply_filters('bhi_portal_excluded_roles', ['subscriber', 'customer', OUS_Roles::MEMBER_ROLE]);
    }

    /** True when EVERY role the user holds is a fan role (so elevating a
     *  member to administrator later doesn't accidentally cage them). */
    public static function user_is_fan(?\WP_User $user): bool {
        if (!$user || !$user->exists()) return false;
        $roles = (array) $user->roles;
        if (!$roles) return true; // a no-role logged-in account is not staff either
        $fan = self::fan_roles();
        return array_intersect($fan, $roles) && !array_diff($roles, $fan);
    }

    /* ------------------------------------------------------------------ */
    /* enumeration                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * `?author=1` is the classic "map user IDs to login names" probe
     * (WordPress 302-redirects it to /author/<login>/). Numeric author
     * queries are blocked outright for everyone who isn't staff;
     * pretty /author/<slug>/ archives are 404'd unless a site opts them
     * back on. Filter `bhi_allow_author_archives` (bool) to keep them.
     */
    public static function block_author_enumeration(): void {
        if (apply_filters('bhi_allow_author_archives', false)) return;
        if (current_user_can('list_users')) return; // staff can still use them

        $raw_author = '';
        if (isset($_GET['author'])) $raw_author = (string) wp_unslash($_GET['author']); // phpcs:ignore WordPress.Security.NonceVerification
        $is_numeric_probe = $raw_author !== '' && preg_match('/^\d+$/', trim($raw_author));

        if ($is_numeric_probe || is_author()) {
            // 404 rather than redirect — a redirect to /author/<slug>/
            // still leaks the slug, which is the whole point of the probe.
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
            $tpl = get_404_template();
            if ($tpl && is_readable($tpl)) {
                include $tpl;
                exit;
            }
            // A block theme with no classic 404.php — hand back to the
            // theme's own template resolution instead of a blank exit.
            $wp_query->is_author = false;
            $wp_query->is_archive = false;
            wp_safe_redirect(home_url('/'), 302);
            exit;
        }
    }

    /**
     * Remove the REST users collection/item for unauthenticated callers.
     * Logged-in behaviour is left to WordPress core (which already gates
     * the full list on `list_users` and only ever exposes users with
     * published posts) so the block editor's author controls keep
     * working for editors. `/wp/v2/users/me` is a separate route and is
     * left intact.
     *
     */
    public static function filter_rest_user_endpoints(array $endpoints): array {
        if (is_user_logged_in()) return $endpoints;
        if (apply_filters('bhi_expose_rest_users', false)) return $endpoints;

        foreach (['/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)'] as $route) {
            if (isset($endpoints[$route])) unset($endpoints[$route]);
        }
        return $endpoints;
    }

    /** Drop the core users sitemap (`/wp-sitemap-users-1.xml`). */
    public static function drop_users_sitemap($provider, string $name) {
        if ($name === 'users' && !apply_filters('bhi_expose_users_sitemap', false)) return false;
        return $provider;
    }

    /** oEmbed responses carry author_name / author_url — strip them. */
    public static function strip_oembed_author($data) {
        if (!is_array($data)) return $data;
        unset($data['author_name'], $data['author_url']);
        return $data;
    }

    /**
     * Belt-and-braces for the authenticated case: when a non-staff
     * account somehow reaches a user object over REST, hand back only
     * the fields that are safe and self-referential, never slug/email/
     * registered-date/meta for anyone else.
     */
    public static function trim_rest_user_fields($response, $user, $request) {
        if (!is_object($response) || !isset($response->data) || !is_array($response->data)) return $response;
        if (current_user_can('list_users')) return $response;

        $self = get_current_user_id() && (int) ($response->data['id'] ?? 0) === get_current_user_id();
        if ($self) return $response;

        foreach (['slug', 'email', 'registered_date', 'first_name', 'last_name', 'nickname', 'url', 'description', 'link', 'meta'] as $k) {
            unset($response->data[$k]);
        }
        return $response;
    }

    /* ------------------------------------------------------------------ */
    /* account surface                                                    */
    /* ------------------------------------------------------------------ */

    /** No Application Passwords for a read-only fan account. */
    public static function app_passwords_for_user($available, $user) {
        if ($user instanceof \WP_User && self::user_is_fan($user)
            && !apply_filters('bhi_member_app_passwords', false, $user)) {
            return false;
        }
        return $available;
    }

    /** Drop pingback/blog-list XML-RPC methods — enumeration/amplification
     *  vectors with no upside for this ecosystem. The rest of XML-RPC is
     *  left alone unless a site also flips `bhi_disable_xmlrpc`. */
    public static function filter_xmlrpc_methods(array $methods): array {
        foreach (['pingback.ping', 'pingback.extensions.getPingbacks', 'wp.getUsersBlogs', 'blogger.getUsersBlogs', 'system.multicall'] as $m) {
            unset($methods[$m]);
        }
        return $methods;
    }

    public static function maybe_disable_xmlrpc(bool $enabled): bool {
        return apply_filters('bhi_disable_xmlrpc', false) ? false : $enabled;
    }

    /* ------------------------------------------------------------------ */
    /* login error text                                                   */
    /* ------------------------------------------------------------------ */

    /** Collapse "invalid username" / "incorrect password" / "invalid
     *  email" into one message so wp-login.php isn't a user-exists
     *  oracle. The portal's own REST login (BHI_Auth::login) already
     *  does this; this covers the raw wp-login.php form. */
    public static function generic_login_errors($error) {
        if (!is_string($error) || $error === '') return $error;
        $needles = ['username', 'password', 'email address', 'incorrect', 'unknown', 'not registered'];
        foreach ($needles as $n) {
            if (stripos($error, $n) !== false) {
                return __('The username or password you entered is incorrect.', 'the-self-hosted-self');
            }
        }
        return $error;
    }

    /* ------------------------------------------------------------------ */
    /* migration                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * One-time, conservative: move accounts whose ONLY role is the stock
     * `subscriber` onto `bh_member`. Multi-role users, customers, staff
     * and admins are never touched (the exact-match guard), and
     * `bh_member` is a strict subset of what a lone `subscriber` could
     * do, so this can only ever reduce privilege. Runs once, in admin,
     * and records how many it moved.
     */
    public static function maybe_migrate_subscribers(): void {
        if (get_option(self::MIGRATED_OPTION)) return;
        if (!current_user_can('list_users')) return; // let an admin's pageload carry it
        if (!get_role(OUS_Roles::MEMBER_ROLE)) return; // role not registered yet — try again next load

        $moved = 0;
        $subs = get_users(['role' => 'subscriber', 'fields' => ['ID'], 'number' => 2000]);
        foreach ($subs as $row) {
            $u = get_userdata((int) $row->ID);
            if (!$u) continue;
            if ((array) $u->roles !== ['subscriber']) continue; // exact match only
            $u->set_role(OUS_Roles::MEMBER_ROLE);
            $moved++;
        }

        update_option(self::MIGRATED_OPTION, ['at' => time(), 'moved' => $moved], false);
        if ($moved && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('info', 'Migrated plain subscribers to bh_member.', ['moved' => $moved], 'BHI_MemberHardening');
        }
    }
}
