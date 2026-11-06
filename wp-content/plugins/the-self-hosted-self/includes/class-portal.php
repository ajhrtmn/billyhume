<?php
if (!defined('ABSPATH')) exit;

// The debug section is read-only, so it groups under "Reference & Docs".

/**
 * BHI_Portal: the branded front-end account area at /account/, built from
 * panels contributed via the `bhi_portal_panels` filter. Panel entry:
 *   [
 *     'id'       => 'profile',                       // unique slug, used in ?panel=
 *     'label'    => 'Profile',
 *     'icon'     => 'dashicons-admin-users',          // any dashicon class
 *     'render'   => ['BHI_PublicProfile', 'render_portal_panel'], // callable, echoes HTML
 *     'priority' => 10,                                // nav order, lower first
 *   ]
 */
class BHI_Portal {
    const QUERY_VAR = 'bhi_portal';
    const REWRITE_SLUG = 'account';

    public static function init(): void {
        // WHY: hooked at init priority 20, not called inline, so other plugins'
        // default-priority rewrite rules register first and the cache flush
        // inside add_rewrite() happens as late as possible in this request.
        add_action('init', [self::class, 'add_rewrite'], 20);
        // The portal renders through a real /account/ page so the theme wraps it.
        add_action('init', [self::class, 'ensure_page'], 25);
        // WHY: the shortcode is only a marker. The HTML is injected via a late
        // the_content filter so wptexturize/wpautop cannot curl the quotes in
        // Datastar data-* attributes.
        add_shortcode('bhi_account_portal', '__return_empty_string');
        add_filter('the_content', [self::class, 'inject_portal'], 99);
        add_filter('query_vars', [self::class, 'add_query_var']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue_assets']);
        // Logged-out panel deep links go to the base URL, which shows the login card.
        add_action('template_redirect', [self::class, 'maybe_redirect_panel_when_logged_out']);
        add_action('template_redirect', [self::class, 'never_cache_portal']);
        add_action('template_redirect', [self::class, 'maybe_redirect_logged_in']);
        add_action('wp_ajax_ous_portal_live_status', [self::class, 'ajax_live_status']);

        // Overview is the landing tab (priority 1, below Profile's 10). Each of its
        // sections is class_exists()-guarded so it degrades without peer plugins.
        add_filter('bhi_portal_panels', [self::class, 'register_overview_panel'], 1);

        // Redirect non-elevated roles off /wp-admin entirely (hiding the admin bar
        // alone leaves the dashboard reachable) and disable their admin bar.
        add_action('admin_init', [self::class, 'maybe_redirect_from_wp_admin']);
        add_filter('show_admin_bar', [self::class, 'maybe_hide_admin_bar']);

        // Skip the bounce through wp-admin on login; a UX change, not a security boundary.
        add_filter('login_redirect', [self::class, 'maybe_redirect_login'], 20, 3);
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
    }

    // Lists registered portal panels to answer "why isn't my panel showing".
    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register_debug_section($tools): array {
        $tools['bhi-portal'] = ['label' => 'Portal', 'render' => [self::class, 'render_debug_section'], 'handle' => null, 'reset' => null, 'group' => OUS_Debug::GROUP_REFERENCE];
        return $tools;
    }

    public static function render_debug_section(): void {
        echo '<p><a class="button" href="' . esc_url(home_url('/' . self::REWRITE_SLUG . '/')) . '" target="_blank">Open the portal</a></p>';
        echo '<h4>Registered panels</h4>';
        $panels = self::get_panels();
        if (!$panels) {
            echo '<p class="description">No panels registered — every plugin\'s own <code>bhi_portal_panels</code> filter callback either isn\'t hooked or returned nothing.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Label</th><th>Priority</th></tr></thead><tbody>';
            foreach ($panels as $panel) {
                echo '<tr><td><code>' . esc_html($panel['id']) . '</code></td><td>' . esc_html($panel['label']) . '</td><td>' . (int) ($panel['priority'] ?? 10) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<h4>wp-admin lockout</h4>';
        echo '<p>Excluded roles: <code>' . esc_html(implode(', ', self::excluded_roles())) . '</code>'
           . ' &mdash; an account is locked out only when <em>every</em> role it holds is on this list.</p>';

        echo '<h4>Fan accounts (' . esc_html(class_exists('OUS_Roles') ? OUS_Roles::MEMBER_ROLE : 'bh_member') . ')</h4>';
        if (class_exists('OUS_Roles') && get_role(OUS_Roles::MEMBER_ROLE)) {
            $member_count = count_users();
            $n = (int) ($member_count['avail_roles'][OUS_Roles::MEMBER_ROLE] ?? 0);
            $subs = (int) ($member_count['avail_roles']['subscriber'] ?? 0);
            echo '<p>' . esc_html((string) $n) . ' on <code>' . esc_html(OUS_Roles::MEMBER_ROLE) . '</code>'
               . ($subs ? ', ' . esc_html((string) $subs) . ' still on stock <code>subscriber</code>' : '') . '.</p>';
            $mig = get_option(BHI_MemberHardening::MIGRATED_OPTION);
            if (is_array($mig)) {
                echo '<p class="description">One-time subscriber&rarr;member migration ran ' . esc_html(human_time_diff((int) ($mig['at'] ?? time()))) . ' ago, moved ' . (int) ($mig['moved'] ?? 0) . '.</p>';
            } else {
                echo '<p class="description">Subscriber&rarr;member migration hasn\'t run yet (fires on the next admin page load; only touches accounts whose <em>only</em> role is <code>subscriber</code>).</p>';
            }
        } else {
            echo '<p class="description">Role not registered yet.</p>';
        }

        // Checks whether the rule reached WordPress's persisted rewrite table; if it
        // has but /account/ still 404s, the request is not reaching PHP at all.
        echo '<h4>Rewrite rule</h4>';
        // Reads the DB directly, bypassing a possibly stale object cache.
        $found = class_exists('BHY_RewriteHealer') ? BHY_RewriteHealer::rule_persisted('^' . self::REWRITE_SLUG) : false;
        if ($found) {
            echo '<p>&#9989; Found in the persisted rewrite table — WordPress itself knows this URL. '
               . 'If <code>/' . esc_html(self::REWRITE_SLUG) . '/</code> still 404s from here, the request likely never reaches WordPress\'s PHP at all — check the web server (nginx/Apache) config for a rewrite/proxy rule sending unmatched paths to <code>index.php</code>, or a caching layer serving a stale 404.</p>';
        } else {
            echo '<p>&#10060; NOT found in the persisted rewrite table as of this page load. This class self-heals automatically on the next un-throttled request (at most a ' . (int) self::VERIFY_THROTTLE_SECONDS . '-second wait, see <code>BHY_RewriteHealer::maybe_heal()</code>) — reload this page in a moment before assuming it\'s stuck. If it\'s STILL missing after that, the cause is outside what a flush + full cache eviction can fix from PHP: a reverse proxy/CDN caching the route itself, a read-only options table, or multisite domain mapping. Check <code>OUS_DebugLog</code> for a matching "still not persisted after a forced flush" entry, which confirms the self-heal genuinely ran and genuinely failed, rather than just not having fired yet.</p>';
        }
    }

    /** @param mixed $user */
    public static function maybe_redirect_login(string $redirect_to, string $requested_redirect_to, $user): string {
        if (!($user instanceof \WP_User)) return $redirect_to;
        if (!self::user_is_excluded($user)) return $redirect_to;
        // Respect a front-end redirect target (e.g. "log in to vote" links).
        if ($requested_redirect_to && strpos($requested_redirect_to, admin_url()) !== 0) {
            return $requested_redirect_to;
        }
        return home_url('/' . self::REWRITE_SLUG . '/');
    }

    // No longer gates flushing (add_rewrite() verifies persistence directly);
    // bump it when the rule shape changes.
    const REWRITE_VERSION = '3';

    // Throttles the flush-and-verify retry so a stuck cache cannot cause a flush storm.
    const VERIFY_THROTTLE_SECONDS = 60;

    // Registers this plugin's rules; the verify/throttle/flush logic is shared
    // in BHY_RewriteHealer.
    public static function add_rewrite(): void {
        // Throttled breadcrumb: if it never appears in Console & Logs, 'init' never
        // reached this class.
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log_throttled('info', 'portal_add_rewrite_entered', 120,
                'BHI_Portal::add_rewrite() was entered this request.', [], 'Portal'
            );
        }

        // /account/ is a real page (ensure_page()); only /account/{panel}/ needs a rule.
        add_rewrite_rule('^' . self::REWRITE_SLUG . '/([^/]+)/?$', 'index.php?pagename=' . self::REWRITE_SLUG . '&bhi_panel=$matches[1]', 'top');

        if (class_exists('BHY_RewriteHealer')) {
            BHY_RewriteHealer::maybe_heal('^' . self::REWRITE_SLUG, 'bhi_portal_rewrite_last_attempt', 'Portal', self::VERIFY_THROTTLE_SECONDS);
        }
    }

    /**
     * @param array<int, string> $vars
     * @return array<int, string>
     */
    public static function add_query_var($vars): array {
        $vars[] = self::QUERY_VAR;
        $vars[] = 'panel';
        $vars[] = 'bhi_panel';
        return $vars;
    }

    /**
     * Roles excluded from wp-admin and the admin bar (ordinary fans/students/
     * supporters, never staff). Filterable via `bhi_portal_excluded_roles`.
     */
    /** @return array<int, string> */
    public static function excluded_roles(): array {
        $default = ['subscriber', 'customer'];
        if (class_exists('OUS_Roles')) $default[] = OUS_Roles::MEMBER_ROLE;
        return apply_filters('bhi_portal_excluded_roles', $default);
    }

    /**
     * Excluded only when EVERY role the account holds is excluded, so a staff
     * account that also carries `customer` keeps wp-admin. No roles counts as excluded.
     */
    private static function user_is_excluded(?\WP_User $user): bool {
        if (!$user || !$user->exists()) return false;
        $roles = (array) $user->roles;
        if (!$roles) return true;
        $excluded = self::excluded_roles();
        return (bool) array_intersect($excluded, $roles) && !array_diff($roles, $excluded);
    }

    public static function maybe_redirect_from_wp_admin(): void {
        if (wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON)) return;
        $user = wp_get_current_user();
        if (!self::user_is_excluded($user)) return;
        wp_safe_redirect(home_url('/' . self::REWRITE_SLUG . '/'));
        exit;
    }

    public static function maybe_hide_admin_bar(bool $show): bool {
        $user = wp_get_current_user();
        if (self::user_is_excluded($user)) return false;
        return $show;
    }

    /* ---------- panel registry ---------- */

    /** @return array<int, array<string, mixed>> */
    public static function get_panels(): array {
        $panels = apply_filters('bhi_portal_panels', []);
        $panels = array_filter($panels, function ($p) {
            return !empty($p['id']) && !empty($p['render']) && is_callable($p['render']);
        });
        usort($panels, function ($a, $b) {
            return ($a['priority'] ?? 10) <=> ($b['priority'] ?? 10);
        });
        $panels = array_values($panels);

        // Admin-editable order/visibility overrides (OUS_PortalLayout), applied last.
        if (class_exists('OUS_PortalLayout')) {
            $panels = OUS_PortalLayout::apply($panels);
            usort($panels, function ($a, $b) {
                return ($a['priority'] ?? 10) <=> ($b['priority'] ?? 10);
            });
        }

        return $panels;
    }

    /** @return array<string, mixed>|null */
    public static function get_panel(string $id): ?array {
        foreach (self::get_panels() as $panel) {
            if ($panel['id'] === $id) return $panel;
        }
        return null;
    }

    /* ---------- rendering ---------- */

    /**
     * @param array<int, array<string, mixed>> $panels
     * @return array<int, array<string, mixed>>
     */
    public static function register_overview_panel($panels): array {
        $panels[] = [
            'id' => 'overview',
            'label' => 'Overview',
            'icon' => 'dashicons-dashboard',
            'render' => [self::class, 'render_overview_panel'],
            'priority' => 1,
        ];
        return $panels;
    }

    /**
     * Overview landing tab. Every block is optional, so a fresh account still
     * gets a welcome and catalog links.
     */
    public static function render_overview_panel(): void {
        $user_id = get_current_user_id();
        $user = wp_get_current_user();

        echo '<h1>Welcome back, ' . esc_html($user->display_name ?: $user->user_login) . '</h1>';

        $shown_anything = false;

        // ---- stats rollup across plugins ----
        $stats = [];
        if (class_exists('BHC_Progress')) {
            $in_progress = BHC_Progress::in_progress_count($user_id);
            if ($in_progress > 0) $stats[] = [(string) $in_progress, $in_progress === 1 ? 'course in progress' : 'courses in progress'];
        }
        if (post_type_exists('bh_submission')) {
            $sub_count = count(get_posts(['post_type' => 'bh_submission', 'author' => $user_id, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']));
            if ($sub_count > 0) $stats[] = [(string) $sub_count, $sub_count === 1 ? 'contest entry' : 'contest entries'];
        }
        if (class_exists('OUS_Notifications')) {
            $unread_stat = OUS_Notifications::unread_count($user_id);
            if ($unread_stat > 0) $stats[] = [(string) $unread_stat, 'unread notification' . ($unread_stat === 1 ? '' : 's')];
        }
        if ($stats) {
            echo '<div class="bhi-overview-stats">';
            foreach ($stats as $s) {
                echo '<div class="bhi-overview-stat"><span class="bhi-overview-stat-num">' . esc_html($s[0]) . '</span><span class="bhi-overview-stat-label">' . esc_html($s[1]) . '</span></div>';
            }
            echo '</div>';
        }

        // ---- membership snapshot ----
        if (class_exists('BHM_Tiers') && class_exists('BHM_Entitlements')) {
            $membership = BHM_Entitlements::active_membership($user_id);
            $active = $membership ? [$membership] : [];
            if ($active) {
                $shown_anything = true;
                $tier = BHM_Tiers::get($active[0]['object_id']);
                $label = $tier ? $tier['name'] : ('Tier #' . $active[0]['object_id']);
                echo '<div class="bhi-portal-section bhi-overview-membership">';
                echo '<h2>Membership</h2>';
                echo '<p><span class="bhi-overview-tier-badge">' . esc_html($label) . '</span>';
                if ($active[0]['expires_at']) {
                    // Access is a fixed-length grant unless real subscriptions are
                    // active; buying again before this date extends it.
                    echo ' <span class="bhi-overview-dim">renews ' . esc_html(mysql2date('M j, Y', $active[0]['expires_at'])) . '</span>';
                    echo '<span class="bhi-tip" tabindex="0" role="button" data-tip="This is when your current access period ends. Whether it re-charges automatically depends on how you paid — check Membership &amp; Wallet for the details." aria-label="This is when your current access period ends. Whether it re-charges automatically depends on how you paid — check Membership and Wallet for the details.">?</span>';
                }
                echo '</p>';
                echo '</div>';
            }
        }

        // ---- continue learning: most recently touched, unfinished course ----
        if (class_exists('BHC_Progress')) {
            $course_id = BHC_Progress::most_recent_in_progress_course($user_id);
            // WHY: enrollment and tier access are tracked separately, so check
            // access too; never link to a course that lands on a paywall.
            $accessible = $course_id && (!class_exists('BHC_Gate') || BHC_Gate::user_can_access_course($user_id, $course_id));
            if ($course_id && $accessible && get_post_status($course_id) === 'publish') {
                $shown_anything = true;
                $percent = BHC_Progress::course_percent($user_id, $course_id);
                echo '<div class="bhi-portal-section bhi-overview-course">';
                echo '<h2>Continue learning</h2>';
                echo '<div class="bhi-portal-course-card">';
                echo '<h3>' . esc_html(get_the_title($course_id)) . '</h3>';
                echo '<div class="bhi-progress-row">';
                echo '<div class="bhi-portal-progress-bar"><div class="bhi-portal-progress-fill" style="width:' . (int) $percent . '%;"></div></div>';
                echo '<span class="bhi-datum bhi-progress-figure"><span class="bhi-datum-value">' . (int) $percent . '%</span><span class="bhi-datum-label">done</span></span>';
                echo '</div>';
                echo '<p><a class="button" href="' . esc_url(get_permalink($course_id)) . '">Continue &rarr;</a></p>';
                echo '</div></div>';
            }
        }

        // ---- most recent contest activity ----
        if (post_type_exists('bh_submission')) {
            $recent = get_posts([
                'post_type' => 'bh_submission', 'author' => $user_id, 'post_status' => 'any',
                'posts_per_page' => 1, 'orderby' => 'date', 'order' => 'DESC',
            ]);
            if ($recent) {
                $shown_anything = true;
                $sub = $recent[0];
                $contest_id = (int) get_post_meta($sub->ID, '_bh_contest_id', true);
                $votes = 0;
                if (class_exists('BH_Helpers')) {
                    global $wpdb;
                    $votes = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . BH_Helpers::table() . ' WHERE submission_id = %d', $sub->ID));
                }
                echo '<div class="bhi-portal-section bhi-overview-contest">';
                echo '<h2>Latest contest activity</h2>';
                echo '<p class="bhi-overview-contest-line">&ldquo;' . esc_html($sub->post_title) . '&rdquo;';
                if ($contest_id) echo ' &middot; ' . esc_html(get_the_title($contest_id));
                echo '</p>';
                echo '<div class="bhi-datum bhi-contest-figure">'
                    . '<span class="bhi-datum-value">' . (int) $votes . '</span>'
                    . '<span class="bhi-datum-label">vote' . ($votes === 1 ? '' : 's') . ' &middot; ' . esc_html(ucfirst($sub->post_status)) . '</span></div>';
                echo '<p><a class="button" href="' . esc_url(home_url('/' . self::REWRITE_SLUG . '/submissions/')) . '">View submissions &rarr;</a></p>';
                echo '</div>';
            }
        }

        // ---- unread notifications ----
        if (class_exists('OUS_Notifications')) {
            $unread = OUS_Notifications::unread_count($user_id);
            if ($unread > 0) {
                $shown_anything = true;
                echo '<div class="bhi-portal-section bhi-overview-notifications">';
                echo '<h2>Notifications</h2>';
                echo '<div class="bhi-datum bhi-notif-figure">'
                    . '<span class="bhi-datum-value">' . (int) $unread . '</span>'
                    . '<span class="bhi-datum-label">unread notification' . ($unread === 1 ? '' : 's') . '</span></div>';
                echo '<p><a class="button" href="' . esc_url(home_url('/' . self::REWRITE_SLUG . '/notifications/')) . '">View &rarr;</a></p>';
                echo '</div>';
            }
        }

        if (!$shown_anything) {
            echo '<div class="bhi-portal-empty bhi-portal-empty-hero">';
            echo '<span class="dashicons dashicons-star-filled"></span>';
            echo '<p>Nothing to show yet — once you enroll in a course, submit to a contest, or pick up a supporter tier, it\'ll show up here.</p>';
            if (post_type_exists('bh_course')) echo '<a class="button" href="' . esc_url(home_url('/courses/')) . '">Browse courses</a> ';
            if (post_type_exists('bh_contest')) echo '<a class="button" href="' . esc_url(home_url('/contests/')) . '">See contests</a>';
            echo '</div>';
        }
    }

    /** The /account/ page (option-recorded, adopted if hand-made). */
    public static function ensure_page(): void {
        if (!class_exists('OUS_Pages')) return;
        OUS_Pages::ensure('bhi_account_portal', 'bhi_portal_page_id', 'Account');
    }

    public static function account_page_id(): int {
        return (int) get_option('bhi_portal_page_id', 0);
    }

    private static function is_account_request(): bool {
        return self::is_portal_context();
    }

    /** True on any /account/ (or /account/{panel}/) page load. Public so
     *  other code that used to check the old QUERY_VAR can switch to it. */
    public static function is_portal_context(): bool {
        $pid = self::account_page_id();
        return $pid > 0 && function_exists('is_page') && is_page($pid);
    }

    /**
     * Swap the /account/ page's body for the portal, after every other
     * the_content filter has run. Only on the real account page's main
     * loop — never a random page that happens to contain the marker text.
     */
    public static function inject_portal(string $content): string {
        if (!self::is_account_request() || !is_main_query() || !in_the_loop()) return $content;
        // Built once and reused: some setups run the_content twice, and
        // the second pass' wptexturize/wpautop would corrupt the HTML
        // this filter injected on the first (a bare `>` inside a
        // Datastar data-* attribute breaks their naive tag detection).
        static $html = null;
        if ($html === null) {
            $html = is_user_logged_in()
                ? self::portal_content()
                : self::login_html();
        }
        return $html;
    }

    public static function maybe_enqueue_assets(): void {
        if (!self::is_account_request()) return;
        // Scoped to .bhi-portal so nothing leaks onto the surrounding themed page.
        wp_enqueue_style('ous-portal', OUS_URL . 'assets/css/portal.css', [], OUS_VER);
        if (is_user_logged_in() && class_exists('OUS_Hypermedia')) OUS_Hypermedia::enqueue();

        wp_enqueue_script('ous-portal-auth', OUS_URL . 'assets/js/portal-auth.js', [], OUS_VER, true);
        $config = [
            'restBase' => esc_url_raw(rest_url('bhi/v1')),
            'redirectTo' => self::post_login_redirect(),
            // Only logged-in visitors get a REST nonce; a logged-out nonce is the same for everyone and protects nothing.
            'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
        ];
        wp_add_inline_script('ous-portal-auth', 'window.BHIPortalAuth = ' . wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
    }

    /** A logged-out deep link to /account/{panel}/ has nothing to show —
     *  bounce it to the base page (which renders the login card). */
    public static function maybe_redirect_panel_when_logged_out(): void {
        if (is_user_logged_in() || !get_query_var('bhi_panel')) return;
        if (!self::is_account_request()) return;
        wp_safe_redirect(home_url('/' . self::REWRITE_SLUG . '/'));
        exit;
    }

    // WHY: the page is per-user, so a full-page cache must never serve the
    // logged-out HTML after login. DONOTCACHEPAGE covers the major cache plugins;
    // nocache_headers() stops the browser cache re-showing the login page.
    public static function never_cache_portal(): void {
        if (!self::is_portal_context()) return;
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        nocache_headers();
        // The login form and account actions must never be framed by another site (clickjacking).
        if (!headers_sent()) {
            header('X-Frame-Options: SAMEORIGIN');
            header("Content-Security-Policy: frame-ancestors 'self'", false);
            header('Referrer-Policy: same-origin');
        }
    }

    /**
     * The branded fan-facing login/register screen shown at /account/ when
     * logged out. Posts to bhi/v1/login and bhi/v1/register (BHI_Auth),
     * the same endpoints the contest player uses; behaviour lives in
     * assets/ts/portal-auth.ts, styles in assets/css/portal.css.
     */
    private static function login_html(): string {
        $registration_open = !class_exists('BHI_Auth') || BHI_Auth::registration_open();
        $honeypot = class_exists('BHI_Auth') ? BHI_Auth::HONEYPOT_FIELD : 'website';
        ob_start();
        ?>
<div class="bhi-portal-login">
<div class="bhi-login-card">
  <div class="bhi-login-brand"><?php echo esc_html(get_bloginfo('name')); ?></div>
  <div class="bhi-login-sub"><?php echo $registration_open ? 'Log in or create your account' : 'Log in to your account'; ?></div>

  <?php if ($registration_open): ?>
  <div class="bhi-login-tabs" role="tablist">
    <button type="button" class="bhi-login-tab is-active" data-panel="login" role="tab" aria-selected="true">Log in</button>
    <button type="button" class="bhi-login-tab" data-panel="register" role="tab" aria-selected="false">Create account</button>
  </div>
  <?php endif; ?>

  <div class="bhi-login-error" id="bhi-login-error" role="alert" aria-live="assertive"></div>

  <form class="bhi-login-panel" id="bhi-login-panel-login" novalidate>
    <div class="bhi-login-field"><label for="bhi-login-username">Username or email</label><input type="text" id="bhi-login-username" name="username" autocomplete="username" maxlength="100" required></div>
    <div class="bhi-login-field"><label for="bhi-login-password">Password</label><input type="password" id="bhi-login-password" name="password" autocomplete="current-password" maxlength="200" required></div>
    <div class="bhi-login-field" id="bhi-login-2fa-field" hidden><label for="bhi-login-2fa">6-digit code</label><input type="text" id="bhi-login-2fa" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></div>
    <label class="bhi-login-check"><input type="checkbox" id="bhi-login-remember" name="remember" checked> Keep me signed in</label>
    <button type="submit" class="bhi-login-submit">Log in</button>
    <div class="bhi-login-foot"><a href="<?php echo esc_url(wp_lostpassword_url(home_url('/' . self::REWRITE_SLUG . '/'))); ?>">Forgot your password?</a></div>
  </form>

  <?php if ($registration_open): ?>
  <form class="bhi-login-panel" id="bhi-login-panel-register" hidden novalidate>
    <div class="bhi-login-field"><label for="bhi-reg-username">Username</label><input type="text" id="bhi-reg-username" name="username" autocomplete="username" maxlength="60" required>
      <p class="bhi-login-hint">Letters, numbers, spaces, and . - _ @</p></div>
    <div class="bhi-login-field"><label for="bhi-reg-email">Email</label><input type="email" id="bhi-reg-email" name="email" autocomplete="email" maxlength="100" required></div>
    <div class="bhi-login-field"><label for="bhi-reg-password">Password</label><input type="password" id="bhi-reg-password" name="password" autocomplete="new-password" minlength="8" maxlength="200" required>
      <p class="bhi-login-hint">At least 8 characters. A short phrase of a few words works well.</p></div>
    <div class="bhi-login-trap" aria-hidden="true"><label for="bhi-reg-<?php echo esc_attr($honeypot); ?>">Leave this empty</label><input type="text" id="bhi-reg-<?php echo esc_attr($honeypot); ?>" name="<?php echo esc_attr($honeypot); ?>" tabindex="-1" autocomplete="off"></div>
    <button type="submit" class="bhi-login-submit">Create account</button>
  </form>
  <?php endif; ?>
</div>
</div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Where to send someone after they log in or sign up: the page that
     * sent them here (?redirect_to=, same site only), else the portal.
     */
    public static function post_login_redirect(?string $requested = null): string {
        $default = home_url('/' . self::REWRITE_SLUG . '/');
        if ($requested === null) $requested = isset($_GET['redirect_to']) ? (string) wp_unslash($_GET['redirect_to']) : '';
        $requested = trim($requested);
        if ($requested === '') return $default;
        // wp_validate_redirect() refuses other hosts, protocol-relative //evil, and non-http schemes.
        $clean = esc_url_raw($requested, ['http', 'https']);
        if ($clean === '') return $default;
        $target = wp_validate_redirect($clean, $default);
        if ($target === '') return $default;
        $path = (string) wp_parse_url($target, PHP_URL_PATH);
        if (strpos($path, 'wp-login.php') !== false || strpos($path, 'admin-post.php') !== false) return $default;
        return $target;
    }

    /** A logged-in visitor arriving with a valid ?redirect_to= goes straight there. */
    public static function maybe_redirect_logged_in(): void {
        if (!is_user_logged_in() || !isset($_GET['redirect_to']) || !self::is_account_request()) return;
        $target = self::post_login_redirect();
        if ($target === home_url('/' . self::REWRITE_SLUG . '/')) return;
        wp_safe_redirect($target);
        exit;
    }

    /** "Confirm your email" prompt with a resend button, for accounts that haven't verified yet. */
    private static function verify_banner_html(): string {
        if (!class_exists('BHI_Auth')) return '';
        $user = wp_get_current_user();
        if (!$user->exists() || BHI_Auth::is_email_verified($user->ID)) return '';
        return '<div class="bhi-portal-verify" role="status">'
            . '<p><strong>Confirm your email.</strong> We sent a link to ' . esc_html($user->user_email) . '. Some features, like voting, need a confirmed email.</p>'
            . '<p><button type="button" class="bhi-portal-verify-resend">Send the link again</button> <span class="bhi-portal-verify-status" aria-live="polite"></span></p>'
            . '</div>';
    }

    /**
     * Datastar polling target (bounded GET, not held-open SSE). Recomputes the
     * same values render_shell() seeds at page load so the two never disagree.
     */
    public static function ajax_live_status(): void {
        if (!is_user_logged_in()) wp_die('', '', ['response' => 403]);

        $user_id = get_current_user_id();
        $signals = [
            'unreadCount' => class_exists('OUS_Notifications') ? (int) OUS_Notifications::unread_count($user_id) : 0,
        ];
        if (class_exists('BHM_Wallet')) {
            $signals['walletBalance'] = number_format(BHM_Wallet::balance_cents($user_id) / 100, 2);
        }

        if (class_exists('OUS_Hypermedia')) {
            OUS_Hypermedia::sse_headers();
            OUS_Hypermedia::patch_signals($signals);
        }
        exit;
    }

    /**
     * The portal's markup (nav rail + active panel), returned for the
     * [bhi_account_portal] shortcode so it renders inside the theme.
     */
    private static function portal_content(): string {
        $panels = self::get_panels();
        $requested = sanitize_key((string) (get_query_var('bhi_panel') ?: get_query_var('panel')));
        $active = $requested && self::get_panel($requested) ? $requested : ($panels[0]['id'] ?? '');
        $unread_count = class_exists('OUS_Notifications') ? (int) OUS_Notifications::unread_count(get_current_user_id()) : 0;
        $wallet_balance_display = class_exists('BHM_Wallet') ? number_format(BHM_Wallet::balance_cents(get_current_user_id()) / 100, 2) : '';

        ob_start();
        ?>
<div class="bhi-portal">
<div class="bhi-portal-shell"<?php if (class_exists('OUS_Hypermedia')): ?>
  data-signals="{unreadCount: <?php echo (int) $unread_count; ?>, walletBalance: '<?php echo esc_js($wallet_balance_display); ?>'}"
  data-on-interval__duration.30s="@get('<?php echo esc_url(admin_url('admin-ajax.php?action=ous_portal_live_status')); ?>')"
<?php endif; ?>>
  <nav class="bhi-portal-nav">
    <div class="bhi-portal-brand"><?php echo esc_html(get_bloginfo('name')); ?></div>
    <?php if (class_exists('BHM_Wallet')):
        // Persistent wallet balance in the nav, Datastar-bound to walletBalance so
        // purchases in another tab show up within one poll.
    ?>
      <a class="bhi-portal-wallet-chip" href="<?php echo esc_url(home_url('/' . self::REWRITE_SLUG . '/membership/')); ?>">
        <span class="dashicons dashicons-money-alt"></span> $<span data-text="$walletBalance"><?php echo esc_html($wallet_balance_display); ?></span>
      </a>
    <?php endif; ?>
    <?php foreach ($panels as $panel): ?>
      <a href="<?php echo esc_url(home_url('/' . self::REWRITE_SLUG . '/' . $panel['id'] . '/')); ?>"
         class="<?php echo $panel['id'] === $active ? 'is-active' : ''; ?>">
        <span class="dashicons <?php echo esc_attr($panel['icon'] ?? 'dashicons-admin-generic'); ?>"></span>
        <?php echo esc_html($panel['label']); ?>
        <?php if ($panel['id'] === 'notifications'): ?>
          <span class="bhi-portal-notif-badge" data-show="$unreadCount" data-text="$unreadCount"<?php echo $unread_count > 0 ? '' : ' style="display:none;"'; ?>><?php echo (int) $unread_count; ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
    <a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">
      <span class="dashicons dashicons-exit"></span> Log out
    </a>
  </nav>
  <main class="bhi-portal-main">
    <?php
    echo self::verify_banner_html(); // phpcs:ignore -- escaped inside verify_banner_html().
    $panel = self::get_panel($active);
    if ($panel) {
        ob_start();
        call_user_func($panel['render']);
        $panel_html = trim((string) ob_get_clean());
        // Panels without their own .bhi-portal-section cards get one shared card
        // wrapper, so peer plugins need no markup changes.
        if ($panel_html !== '' && strpos($panel_html, 'bhi-portal-section') === false) {
            echo '<div class="bhi-portal-section bhi-portal-section--auto">' . $panel_html . '</div>';
        } else {
            echo $panel_html;
        }
    } else {
        echo '<p>Nothing to show here yet.</p>';
    }
    ?>
  </main>
</div>
<script>
(function () {
  var bhiTipBubble = null;
  function show(el) {
    var text = el.getAttribute('data-tip');
    if (!text) return;
    if (!bhiTipBubble) {
      bhiTipBubble = document.createElement('div');
      bhiTipBubble.className = 'bhi-tip-bubble';
      document.body.appendChild(bhiTipBubble);
    }
    bhiTipBubble.textContent = text;
    var r = el.getBoundingClientRect();
    bhiTipBubble.style.left = '0px'; bhiTipBubble.style.top = '0px';
    bhiTipBubble.classList.add('is-visible');
    var bw = bhiTipBubble.offsetWidth, bh = bhiTipBubble.offsetHeight;
    var left = Math.min(Math.max(8, r.left + r.width / 2 - bw / 2), window.innerWidth - bw - 8);
    var top = r.top - bh - 8;
    if (top < 8) top = r.bottom + 8;
    bhiTipBubble.style.left = left + 'px';
    bhiTipBubble.style.top = top + 'px';
  }
  function hide() { if (bhiTipBubble) bhiTipBubble.classList.remove('is-visible'); }
  document.addEventListener('mouseover', function (e) { var t = e.target.closest('.bhi-tip'); if (t) show(t); });
  document.addEventListener('mouseout', function (e) { if (e.target.closest('.bhi-tip')) hide(); });
  document.addEventListener('focusin', function (e) { var t = e.target.closest('.bhi-tip'); if (t) show(t); });
  document.addEventListener('focusout', function (e) { if (e.target.closest('.bhi-tip')) hide(); });
})();
</script>
</div>
        <?php
        return (string) ob_get_clean();
    }
}
