<?php
if (!defined('ABSPATH')) exit;

/**
 * Serves this site's /.well-known/bh-registry-verify.txt via a rewrite rule, so proving ownership
 * needs no filesystem access. A real static file at that path still wins.
 */
class BHR_WellKnown {
    const REWRITE_PATTERN = '^\.well-known/bh-registry-verify\.txt$';
    const QUERY_VAR = 'bhr_wellknown';
    const VERIFY_THROTTLE_SECONDS = 60;
    const TOKEN_OPTION = 'bhr_wellknown_token';

    public static function init(): void {
        add_action('init', [self::class, 'add_rewrite'], 20);
        add_filter('query_vars', [self::class, 'add_query_var']);
        // WHY: priority 0 plus the canonical-redirect suppressor; checkers that don't follow a
        // trailing-slash 301 would otherwise see no token.
        add_action('template_redirect', [self::class, 'maybe_serve'], 0);
        add_filter('redirect_canonical', [self::class, 'suppress_canonical_redirect'], 10, 2);
    }

    /**
     * @param string|false $redirect_url
     * @param string       $requested_url
     * @return string|false
     */
    public static function suppress_canonical_redirect($redirect_url, string $requested_url = '') {
        if (get_query_var(self::QUERY_VAR)) return false;
        return $redirect_url;
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

        $tokens = self::served_tokens();
        status_header($tokens ? 200 : 404);
        header('Content-Type: text/plain; charset=UTF-8');
        if ($tokens) echo implode("\n", $tokens);
        exit;
    }

    /**
     * Tokens served one per line: this site's identity token plus challenge tokens of local links on THIS host.
     * WHY: only tokens for our own domain are ever served, so self-registration can't vouch for anyone else.
     *
     * @return array<int, string>
     */
    public static function served_tokens(): array {
        $tokens = [];
        $own = self::token();
        if ($own) $tokens[] = $own;

        global $wpdb;
        $table = BHR_Tables::links();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return $tokens;

        $our_host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        if (!$our_host) return $tokens;

        $rows = $wpdb->get_results("SELECT url, verification_token FROM $table WHERE verification_token != ''");
        foreach ($rows as $row) {
            $host = strtolower((string) wp_parse_url($row->url, PHP_URL_HOST));
            if ($host && $host === $our_host) $tokens[] = $row->verification_token;
        }

        return array_values(array_unique(array_filter($tokens)));
    }

    /** This site's outbound identity token, generated on first read (regenerable in BHR_Admin). */
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
