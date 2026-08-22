<?php
if (!defined('ABSPATH')) exit;

/**
 * Self-serves THIS site's own domain-ownership proof file at
 * https://{this-host}/.well-known/bh-registry-verify.txt via a WP
 * rewrite rule, so submitting this site's own feed to ANOTHER site's
 * registry doesn't require raw filesystem/SSH access outside
 * wp-content — confirmed genuinely unavailable this session on a real
 * Wasmer-hosted production install (wp-admin-only access), and a real
 * blocker on plenty of ordinary shared hosting too.
 *
 * This is NOT the same token as the per-submission challenge
 * BHR_Verification::check_domain_ownership() asks OTHER sites'
 * submitters to publish on THEIR OWN domains — that mechanism is
 * completely unchanged and still just an HTTP GET against whatever
 * host a submission claims. This class only changes what answers that
 * GET on THIS site, for when THIS site is the one submitting itself
 * elsewhere.
 *
 * A real static file at the same path still wins if one exists —
 * ordinary Apache/Nginx rewrite rules only engage for paths that don't
 * already resolve to a real file, so an admin who CAN drop a real file
 * loses nothing by this also existing.
 */
class BHR_WellKnown {
    const REWRITE_PATTERN = '^\.well-known/bh-registry-verify\.txt$';
    const QUERY_VAR = 'bhr_wellknown';
    const VERIFY_THROTTLE_SECONDS = 60;
    const TOKEN_OPTION = 'bhr_wellknown_token';

    public static function init(): void {
        add_action('init', [self::class, 'add_rewrite'], 20);
        add_filter('query_vars', [self::class, 'add_query_var']);
        add_action('template_redirect', [self::class, 'maybe_serve']);
    }

    public static function add_rewrite(): void {
        add_rewrite_rule(self::REWRITE_PATTERN, 'index.php?' . self::QUERY_VAR . '=1', 'top');

        if (class_exists('BHY_RewriteHealer')) {
            BHY_RewriteHealer::maybe_heal(
                '.well-known/bh-registry-verify',
                'bhr_wellknown_rewrite_last_attempt',
                'BH Registry WellKnown',
                self::VERIFY_THROTTLE_SECONDS
            );
        }
    }

    /**
     * @param array<int, string> $vars
     * @return array<int, string>
     */
    public static function add_query_var(array $vars): array {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public static function maybe_serve(): void {
        if (!get_query_var(self::QUERY_VAR)) return;

        $token = self::token();
        status_header($token ? 200 : 404);
        header('Content-Type: text/plain; charset=UTF-8');
        if ($token) echo $token;
        exit;
    }

    /**
     * This site's own outbound identity token — generated once on
     * first read, admin-visible/regenerable from the Registry
     * Submissions screen (see BHR_Admin's "This Site's Identity" box).
     */
    public static function token(): string {
        $token = get_option(self::TOKEN_OPTION, '');
        if (!$token && class_exists('BHR_Verification')) {
            $token = BHR_Verification::generate_token();
            update_option(self::TOKEN_OPTION, $token, false);
        }
        return (string) $token;
    }

    public static function regenerate_token(): string {
        $token = class_exists('BHR_Verification') ? BHR_Verification::generate_token() : wp_generate_password(32, false, false);
        update_option(self::TOKEN_OPTION, $token, false);
        return $token;
    }

    public static function challenge_url(): string {
        return home_url('/.well-known/bh-registry-verify.txt');
    }
}
