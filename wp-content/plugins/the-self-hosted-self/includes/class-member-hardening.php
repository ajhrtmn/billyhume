<?php
if (!defined('ABSPATH')) exit;

/**
 * Hardens self-registered fan accounts (real wp_users rows on the low-privilege `bh_member`
 * role, locked out of wp-admin): blocks user enumeration, Application Passwords, XML-RPC
 * pingback methods, and user-exists login error text.
 *
 * Every piece is filterable; staff roles and non-fan logged-in requests are never touched.
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

    /* ---------------- helpers ---------------- */

    /** Fan roles: the same set the portal locks out of wp-admin, so the two stay in lockstep. */
    private static function fan_roles(): array {
        if (class_exists('BHI_Portal') && method_exists('BHI_Portal', 'excluded_roles')) {
            return (array) BHI_Portal::excluded_roles();
        }
        return apply_filters('bhi_portal_excluded_roles', ['subscriber', 'customer', OUS_Roles::MEMBER_ROLE]);
    }

    /** True when EVERY role the user holds is a fan role (an elevated member is never caged). */
    public static function user_is_fan(?\WP_User $user): bool {
        if (!$user || !$user->exists()) return false;
        $roles = (array) $user->roles;
        if (!$roles) return true; // a no-role logged-in account is not staff either
        $fan = self::fan_roles();
        return array_intersect($fan, $roles) && !array_diff($roles, $fan);
    }

    /* ---------------- enumeration ---------------- */

    /**
     * Blocks numeric ?author=N probes for non-staff and 404s /author/<slug>/ archives.
     * Filter `bhi_allow_author_archives` (bool) to keep the archives.
     */
    public static function block_author_enumeration(): void {
        if (apply_filters('bhi_allow_author_archives', false)) return;
        if (current_user_can('list_users')) return; // staff can still use them

        $raw_author = '';
        if (isset($_GET['author'])) $raw_author = (string) wp_unslash($_GET['author']); // phpcs:ignore WordPress.Security.NonceVerification
        $is_numeric_probe = $raw_author !== '' && preg_match('/^\d+$/', trim($raw_author));

        if ($is_numeric_probe || is_author()) {
            // WHY: 404 rather than redirect — redirecting to /author/<slug>/ leaks the slug.
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
            $tpl = get_404_template();
            if ($tpl && is_readable($tpl)) {
                include $tpl;
                exit;
            }
            // Block theme with no classic 404.php: hand back to the theme's template resolution.
            $wp_query->is_author = false;
            $wp_query->is_archive = false;
            wp_safe_redirect(home_url('/'), 302);
            exit;
        }
    }

    /**
     * Removes the REST users routes for unauthenticated callers only; logged-in access stays
     * with core so editor author controls work. /wp/v2/users/me is unaffected.
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
     * For a non-staff account reaching a user object over REST, return only safe self-referential fields.
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

    /* ---------------- account surface ---------------- */

    /** No Application Passwords for a read-only fan account. */
    public static function app_passwords_for_user($available, $user) {
        if ($user instanceof \WP_User && self::user_is_fan($user)
            && !apply_filters('bhi_member_app_passwords', false, $user)) {
            return false;
        }
        return $available;
    }

    /** Drop pingback/blog-list XML-RPC methods; `bhi_disable_xmlrpc` disables the rest too. */
    public static function filter_xmlrpc_methods(array $methods): array {
        foreach (['pingback.ping', 'pingback.extensions.getPingbacks', 'wp.getUsersBlogs', 'blogger.getUsersBlogs', 'system.multicall'] as $m) {
            unset($methods[$m]);
        }
        return $methods;
    }

    public static function maybe_disable_xmlrpc(bool $enabled): bool {
        return apply_filters('bhi_disable_xmlrpc', false) ? false : $enabled;
    }

    /* ---------------- login error text ---------------- */

    /** One generic message for all credential errors so wp-login.php isn't a user-exists oracle. */
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

    /* ---------------- migration ---------------- */

    /**
     * One-time: moves accounts whose ONLY role is `subscriber` onto `bh_member` (a strict
     * subset of subscriber's caps, so it can only reduce privilege). Records the count moved.
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
