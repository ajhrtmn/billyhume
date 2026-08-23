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
        // Priority 0, plus the canonical-redirect suppressor below:
        // WordPress was 301-ing this to a trailing-slash URL. Fetching
        // clients that don't follow redirects (a perfectly reasonable
        // posture for a security check) would see the redirect instead
        // of the token and conclude domain ownership had failed. Our
        // own checker passes 'redirection' => 2 so it coped, but
        // relying on every other implementation to follow a redirect
        // on a proof-of-control file is exactly the kind of fragility
        // worth removing.
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
     * Every token this file legitimately proves, one per line —
     * check_domain_ownership() already matches line-by-line, so a list
     * works exactly like a single value did.
     *
     * Contents: this site's own outbound identity token, PLUS the
     * challenge token of any link in our own registry whose URL points
     * at THIS host. That second part is what makes self-registration
     * automatic: a site submitting its own feed to its own registry
     * would otherwise be handed a challenge token it had no way to
     * publish, and could never verify itself.
     *
     * Not a hole in the trust model: we only ever auto-publish tokens
     * for URLs on our own domain, which is precisely the thing this
     * file exists to prove we control. A token for someone ELSE's
     * domain is never served here, and a remote artist still has to
     * publish their own token on their own domain exactly as before.
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
