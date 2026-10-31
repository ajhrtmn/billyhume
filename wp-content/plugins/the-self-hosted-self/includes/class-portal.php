<?php
if (!defined('ABSPATH')) exit;

// register_debug_section() sets 'group' => OUS_Debug::GROUP_REFERENCE.
// This section is read-only (lists registered portal panels + a link to
// the live portal), so it groups with API/Codebase Docs under
// "Reference & Docs" rather than the default bucket.

/**
 * BHI_Portal — the custom user-facing account shell. A genuinely
 * separate, branded front-end account area, not a reskinned wp-admin
 * and not a pile of independent per-plugin shortcodes on separate
 * pages. Renders at a rewrite-owned `/account/` URL, built entirely
 * from panels contributed via the `bhi_portal_panels` filter — the
 * same zero-central-registration shape `ous_registered_plugins`/
 * `ous_debug_tools`/`bhy_style_surfaces` already use.
 *
 * Ships the shell, the filter contract, the wp-admin exclusion
 * rollout, and one real migrated panel (profile/identity — see
 * BHI_PublicProfile::render_portal_panel()).
 *
 * Each panel entry (registered via the filter):
 *   [
 *     'id'       => 'profile',                       // unique slug, used in ?panel=
 *     'label'    => 'Profile',
 *     'icon'     => 'dashicons-admin-users',          // any dashicon class
 *     'render'   => ['BHI_PublicProfile', 'render_portal_panel'], // callable, echoes HTML
 *     'priority' => 10,                                // nav order, lower first
 *   ]
 * A contributing plugin should wrap its own `add_filter('bhi_portal_panels', ...)`
 * call in a `class_exists()` guard on ITSELF (never needed — a plugin
 * always exists to itself) but, more usefully, simply not add a panel
 * at all if the feature it'd cover isn't relevant (e.g. bh-registry
 * correctly has no portal panel — registry submissions aren't tied to
 * an account, same reasoning that kept it out of Notifications).
 */
class BHI_Portal {
    const QUERY_VAR = 'bhi_portal';
    const REWRITE_SLUG = 'account';

    public static function init(): void {
        // add_rewrite() runs directly here rather than being re-hooked onto
        // 'init' from inside init() — self-hooking the currently-executing
        // action at an already-passed priority means WP_Hook's snapshot
        // iteration never picks it up again this request (a well-known
        // WordPress hook-timing footgun). We're already executing inside
        // 'init', so a plain call runs it immediately.
        //
        // Deferred to priority 20 rather than called at the top: this lets
        // other plugins' own default-priority (10) rewrite_rule
        // registrations complete first, and pushes this method's
        // wp_cache_flush() — which wipes the entire object cache
        // mid-request, including reads other same-request code (e.g.
        // OUS_Debug::is_locked()) depends on — as late as reasonably
        // possible. Priority 20 still runs within the same 'init' pass,
        // since WP_Hook::do_action() walks not-yet-reached priority
        // buckets in order within one call.
        add_action('init', [self::class, 'add_rewrite'], 20);
        // The portal now renders THROUGH a real /account/ page + a
        // shortcode, so it flows through the active theme (header nav,
        // footer, page background) instead of the standalone
        // <!DOCTYPE> document render_shell() used to print and exit on.
        add_action('init', [self::class, 'ensure_page'], 25);
        // The shortcode is just a marker a human sees in the page editor —
        // it returns nothing. The real HTML is injected via the_content at
        // a very late priority so no content filter (wpautop, wptexturize,
        // Etch's own processing) mangles the markup — a real bug the
        // straight-quote-based Datastar data-* attributes hit as
        // wptexturize turned them into curly quotes and broke the badge.
        add_shortcode('bhi_account_portal', '__return_empty_string');
        add_filter('the_content', [self::class, 'inject_portal'], 99);
        add_filter('query_vars', [self::class, 'add_query_var']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue_assets']);
        // Logged-out visitor to /account/ or a panel — send them to the
        // themed login card (the page itself renders it via the
        // shortcode, but a panel deep-link should land on the base URL).
        add_action('template_redirect', [self::class, 'maybe_redirect_panel_when_logged_out']);
        add_action('template_redirect', [self::class, 'never_cache_portal']);
        add_action('wp_ajax_ous_portal_live_status', [self::class, 'ajax_live_status']);

        // Without an overview tab, the portal landed a visitor on a bare
        // "upload an avatar" form (Profile) with zero sense of where they
        // stood across courses/contests/membership. Priority 1 (lower than
        // Profile's own 10) makes this the landing tab instead. Registered
        // from core since it reads across all plugins — each section is
        // independently class_exists()-guarded so this degrades cleanly
        // when bh-courses/bh-contest/bh-monetization-woo aren't active.
        add_filter('bhi_portal_panels', [self::class, 'register_overview_panel'], 1);

        // wp-admin exclusion rollout — redirect non-elevated roles off
        // /wp-admin entirely (not just hiding the admin bar, which is
        // cosmetic and leaves the dashboard reachable by direct URL),
        // and disable the admin bar for the same roles.
        add_action('admin_init', [self::class, 'maybe_redirect_from_wp_admin']);
        add_filter('show_admin_bar', [self::class, 'maybe_hide_admin_bar']);

        // WP core's default post-login redirect target is admin_url(),
        // which is still caught by maybe_redirect_from_wp_admin() the
        // moment that page loads, but that's still an extra bounce
        // through a page the person was never going to see. Sending them
        // straight to the portal on login is a UX improvement, not a new
        // security boundary — the boundary is already closed above.
        add_filter('login_redirect', [self::class, 'maybe_redirect_login'], 20, 3);
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);

        // The 'portal_panel' bh_element_surfaces contributor stays
        // registered (Element Builder can still compose content against
        // it), but it's no longer surfaced as its own portal nav tab —
        // register_elements_panel()/render_elements_panel() are kept
        // (harmless, unused) in case a real per-user or named panel use
        // case shows up later.
        // add_filter('bhi_portal_panels', [self::class, 'register_elements_panel']);
    }

    /**
     * Registers the 'portal_panel' surface for BH_Element (design doc
     * §3.3/§5.4), mirroring BHCRM_People::register_element_surface()'s
     * shape exactly. One slot, 'body' — the whole panel body is
     * composable from elements, same as the design doc's own §5.4 text
     * ("an element-composed panel whose render callback calls
     * BH_Element::render_slot('portal_panel', $panel_context, 'body')").
     *
     * Context: this is a SITE-WIDE panel (every logged-in portal user
     * sees the same composed content), not per-user — same singleton
     * shape as the dashboard's 'dashboard' surface (OUS_Dashboard::
     * register_element_surface(), surface_context_id always 0), not the
     * per-person shape 'bh_crm_profile' uses. A future per-user portal
     * panel (context => user_id) is a straightforward follow-on using
     * the exact same registration shape with a different 'context'/
     * 'preview_ctx' pair — not built here, since the design doc names
     * only "one new panel type" for this phase (§5.4).
     */
    /**
     * @param array<string, mixed> $surfaces
     * @return array<string, mixed>
     */
    public static function register_element_surface($surfaces): array {
        $surfaces['portal_panel'] = [
            'group'       => 'Portal',
            'label'       => 'Portal panel (element-composed)',
            'slots'       => [
                'body' => ['label' => 'Panel body'],
            ],
            'context'     => ['type' => 'site', 'param' => null],
            'preview_ctx' => function () { return ['user_id' => get_current_user_id()]; },
        ];
        return $surfaces;
    }

    /**
     * An element-composed panel registered through the existing
     * bhi_portal_panels contract, exactly like every other panel —
     * nothing about the Portal's own panel machinery changes.
     * render_elements_panel() below is the panel's 'render' callback; it
     * does nothing but call BH_Element::render_slot() for the
     * 'portal_panel' surface's 'body' slot, context 0 (the one site-wide
     * panel this phase ships).
     *
     * class_exists('BH_Element') guarded so this panel simply doesn't
     * register at all if the element-builder classes are ever absent.
     */
    /**
     * @param array<int, array<string, mixed>> $panels
     * @return array<int, array<string, mixed>>
     */
    public static function register_elements_panel($panels): array {
        if (!class_exists('BH_Element')) return $panels;
        $panels[] = [
            'id'       => 'elements',
            'label'    => 'Custom',
            'icon'     => 'dashicons-layout',
            'render'   => [self::class, 'render_elements_panel'],
            'priority' => 90, // after the built-in panels (profile, etc. register lower priorities) — this is an admin-composed extra, not the primary account view
        ];
        return $panels;
    }

    public static function render_elements_panel(): void {
        echo '<h2>Custom</h2>';
        if (!class_exists('BH_Element')) {
            echo '<p>Element Builder is unavailable.</p>';
            return;
        }
        $ctx = ['user_id' => get_current_user_id()];
        $html = BH_Element::render_slot('portal_panel', 0, 'body', $ctx);
        if ($html === '') {
            // No admin UI for composing placements exists in this version
            // (the earlier admin.php?page=bh-element-builder page was
            // deleted — see class-style-gallery.php — and never replaced),
            // so state that honestly rather than link to a page that
            // doesn't exist.
            echo '<p>Nothing has been placed here yet (surface "portal_panel", slot "body") — no admin UI for composing placements exists in this version.</p>';
            return;
        }
        echo $html; // phpcs:ignore -- BH_Element::render_slot()'s own output is already escaped/kses'd per-element at the render_placement() boundary, same trust posture render_slot()'s other call sites (dashboard, CRM) already use.
    }

    // "Why isn't my panel showing on the portal" gets a one-click answer
    // here instead of requiring a re-read of every contributing plugin's
    // own bootstrap file.
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

        // Registered panels above only prove the PHP ran — a "/account/
        // 404s despite everything else working" report needs a different
        // question answered: did the rewrite rule actually make it into
        // WordPress's persisted rewrite table, or is this a web-server-
        // level proxy issue (request never reaching WordPress's PHP at
        // all, in which case nothing on this page could show it either
        // way)? This reads the same option WordPress itself consults on
        // every front-end request, so a "not found here" result is a
        // real, actionable signal.
        echo '<h4>Rewrite rule</h4>';
        // Reads straight from the DB rather than get_option(), so this
        // answers "what's actually in the database" rather than "what
        // does a possibly-stale object cache claim."
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
        // A requested_redirect_to pointing somewhere on the front end
        // (not wp-admin) is respected — e.g. "log in to vote" links that
        // expect to land back on the exact page that prompted login,
        // not always the portal home.
        if ($requested_redirect_to && strpos($requested_redirect_to, admin_url()) !== 0) {
            return $requested_redirect_to;
        }
        return home_url('/' . self::REWRITE_SLUG . '/');
    }

    // Historical version-gate constant — no longer used to decide whether
    // to flush (add_rewrite() below now verifies persistence directly
    // instead of trusting a "done" flag), kept because bumping it is
    // still the right signal to a human reading git history that the rule
    // shape changed. A one-shot version-gated flush isn't good enough: it
    // can mark itself "done" via update_option() while a persistent
    // object cache (Redis/Memcached) keeps serving the old rewrite_rules
    // value on every subsequent request, forever, because nothing ever
    // re-checks.
    const REWRITE_VERSION = '3';

    // Rate-limit guard for the DB-bypassing verification below — cheap on
    // its own, but a real flush_rewrite_rules() touches .htaccess/DB on
    // every hit, so if something is fundamentally broken (a cache that
    // refuses to ever let go of a stale value) this stops it from being
    // re-attempted on every single request. 60s is short enough that a
    // real fix is visible almost immediately, long enough that init-hook
    // traffic doesn't turn into a flush storm.
    const VERIFY_THROTTLE_SECONDS = 60;

    // Audit fix (2026-07-25): the self-heal algorithm previously
    // implemented directly in this class (rewrite_rule_persisted()/
    // not_recently_attempted()/force_flush_and_verify()) is now shared
    // with bh-monetization-woo's BHM_Storefront via BHY_RewriteHealer
    // (the-self-hosted-self/includes/class-rewrite-healer.php) — that class's own
    // docblock has the full history of why this shape exists and a real
    // bug found in the Storefront copy while extracting it. Only the
    // rule registration below (this plugin's own slugs/query vars)
    // stays here; the verify/throttle/flush/log algorithm is shared.
    public static function add_rewrite(): void {
        // Unconditional (but throttled) breadcrumb: if this never appears
        // in Console & Logs, the problem is upstream of this class
        // entirely (the 'init' hook never firing, or a fatal earlier in
        // the request); if it appears but nothing below it does, the
        // problem is isolated to BHY_RewriteHealer itself.
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log_throttled('info', 'portal_add_rewrite_entered', 120,
                'BHI_Portal::add_rewrite() was entered this request.', [], 'Portal'
            );
        }

        // /account/ is a real published page (ensure_page()); only the
        // /account/{panel}/ sub-URLs need a rule, pointing at that same
        // page with the panel id in a query var the shortcode reads.
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
     * Which roles get excluded from wp-admin / the admin bar. Filterable
     * (`bhi_portal_excluded_roles`) so a site can exempt a custom role
     * later without editing this file. Default: exactly the roles this
     * ecosystem's own accounts actually use for ordinary fans/students/
     * supporters — never administrator/editor/author, so nobody who
     * genuinely needs wp-admin loses it.
     */
    /** @return array<int, string> */
    public static function excluded_roles(): array {
        $default = ['subscriber', 'customer'];
        if (class_exists('OUS_Roles')) $default[] = OUS_Roles::MEMBER_ROLE;
        return apply_filters('bhi_portal_excluded_roles', $default);
    }

    /**
     * Excluded only when EVERY role the account holds is an excluded
     * ("ordinary fan") role. Previously any single excluded role was
     * enough — which meant an account deliberately elevated to
     * administrator *and still carrying* `bh_member`/`customer` (a
     * normal state after a staff member also buys something) would get
     * redirected out of its own wp-admin. A pure no-role account still
     * counts as excluded — it isn't staff either.
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

        // Admin-editable order/visibility overrides (OUS_PortalLayout) —
        // applied last, on top of whatever the filter contributed, so a
        // panel provider never needs to know this exists.
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
     * A real "here's where you stand" home tab instead of the Profile
     * upload form being the first thing anyone sees. Each block below is
     * independently optional — a fresh account with no course/contest/
     * membership activity yet still gets a real page (a welcome +
     * catalog links), not a wall of empty sections.
     */
    public static function render_overview_panel(): void {
        $user_id = get_current_user_id();
        $user = wp_get_current_user();

        echo '<h1>Welcome back, ' . esc_html($user->display_name ?: $user->user_login) . '</h1>';

        $shown_anything = false;

        // ---- stats rollup: a real cross-plugin count, not just one
        // snapshot card per plugin — "3 courses in progress" etc. gives
        // an at-a-glance sense of a member's whole footprint before
        // drilling into any one panel's own full list. ----
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
                    // "renews" was ambiguous copy — on this site, absent
                    // a real WooCommerce Subscriptions integration,
                    // access is a fixed-length grant that just ends on
                    // this date; it does not auto-charge again. Kept the
                    // word "renews" (a fan buying again before this date
                    // extends it) but added the tip rather than silently
                    // changing wording that other code may already
                    // expect, since the real behavior genuinely does
                    // depend on whether BHM_Subscriptions mock/real mode
                    // is active.
                    echo ' <span class="bhi-overview-dim">renews ' . esc_html(mysql2date('M j, Y', $active[0]['expires_at'])) . '</span>';
                    echo '<span class="bhi-tip" tabindex="0" role="button" data-tip="This is when your current access period ends. Whether it re-charges automatically depends on how you paid — check Membership &amp; Wallet for the details." aria-label="This is when your current access period ends. Whether it re-charges automatically depends on how you paid — check Membership and Wallet for the details.">?</span>';
                }
                echo '</p>';
                echo '</div>';
            }
        }

        // ---- continue learning: the most recently touched enrolled,
        // not-yet-completed course, so this is genuinely "pick up where
        // you left off" rather than an arbitrary enrolled-course list
        // (that full list already lives on the Courses tab itself). ----
        if (class_exists('BHC_Progress')) {
            $course_id = BHC_Progress::most_recent_in_progress_course($user_id);
            // Enrollment and ongoing tier/purchase access are tracked
            // independently (a supporter tier can lapse after
            // enrollment) — found live spot-checking this exact widget:
            // it linked straight into a course this account could no
            // longer open, landing on that course's own paywall despite
            // being framed here as "Continue learning" with a real
            // progress percent. "Obvious or gone" (this widget's own
            // stated rule below) means gone, not a dead end.
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
                : self::login_html(home_url('/' . self::REWRITE_SLUG . '/'));
        }
        return $html;
    }

    public static function maybe_enqueue_assets(): void {
        if (!self::is_account_request()) return;
        // Scoped to .bhi-portal so nothing leaks onto the surrounding themed page.
        wp_enqueue_style('ous-portal', OUS_URL . 'assets/css/portal.css', [], OUS_VER);
        if (is_user_logged_in() && class_exists('OUS_Hypermedia')) OUS_Hypermedia::enqueue();
    }

    /** A logged-out deep link to /account/{panel}/ has nothing to show —
     *  bounce it to the base page (which renders the login card). */
    public static function maybe_redirect_panel_when_logged_out(): void {
        if (is_user_logged_in() || !get_query_var('bhi_panel')) return;
        if (!self::is_account_request()) return;
        wp_safe_redirect(home_url('/' . self::REWRITE_SLUG . '/'));
        exit;
    }

    // The account page is per-user by definition — the login form when
    // logged out, the personal dashboard when logged in. A full-page
    // cache that served the logged-out HTML to a user who just
    // authenticated is exactly the "logs in but stays on the login
    // page" bug reported from live. DONOTCACHEPAGE covers every major
    // WP cache plugin (Rocket, W3TC, WP Super Cache, LiteSpeed, the
    // Bluehost/Endurance cache); nocache_headers() also stops the
    // browser's own HTTP cache from re-showing the login page on the
    // post-login redirect to the same URL.
    public static function never_cache_portal(): void {
        if (!self::is_portal_context()) return;
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        nocache_headers();
    }

    /**
     * A real, branded fan-facing login/register screen — replaces the
     * previous behavior of bouncing a logged-out portal visitor to
     * WordPress's own generic wp-login.php. Posts to the existing
     * bhi/v1/login and bhi/v1/register REST routes (BHI_Auth) — the
     * exact same endpoints the contest player's own embedded auth form
     * already uses, so this is a second front-end onto proven auth
     * logic (brute-force lockout, 2FA challenge, registration
     * throttling), never a parallel implementation of any of it.
     * wp_signon() (inside BHI_Auth::login()) sets the real auth cookies
     * server-side, so a successful REST call is followed by a plain
     * full-page redirect to pick up the new session — no client-side
     * session state to manage here.
     */
    private static function login_html(string $redirect_to): string {
        ob_start();
        ?>
<style>
  .bhi-portal-login {
    min-height:60vh; display:flex; align-items:center; justify-content:center;
    color:var(--bh-text, #1d2327);
    padding:24px; box-sizing:border-box;
  }
  .bhi-login-card {
    width:100%; max-width:380px; background:var(--bh-surface, #fff);
    border:1px solid var(--bh-border, #e2e2e2); border-radius:var(--bh-radius, 10px);
    padding:32px 28px; box-sizing:border-box;
  }
  .bhi-login-brand { font-family:var(--bh-font-display, inherit); font-weight:700; font-size:18px; text-align:center; margin-bottom:4px; }
  .bhi-login-sub { text-align:center; color:var(--bh-text-dim, #6b7280); font-size:13px; margin-bottom:24px; }
  .bhi-login-tabs { display:flex; gap:4px; margin-bottom:20px; background:var(--bh-surface-2, #f0f0f1); border-radius:var(--bh-radius-sm, 8px); padding:3px; }
  .bhi-login-tab {
    flex:1; text-align:center; padding:8px 0; border-radius:calc(var(--bh-radius-sm, 8px) - 2px); font-size:13px; font-weight:600;
    cursor:pointer; color:var(--bh-text-dim, #6b7280); background:transparent; border:none; font-family:inherit;
  }
  .bhi-login-tab.is-active { background:var(--bh-surface, #fff); color:var(--bh-text, #1d2327); }
  .bhi-login-field { margin-bottom:14px; }
  .bhi-login-field label { display:block; font-size:12.5px; font-weight:600; margin-bottom:5px; color:var(--bh-text-dim, #6b7280); }
  .bhi-login-field input {
    width:100%; box-sizing:border-box; padding:9px 11px; border:1px solid var(--bh-border, #e2e2e2);
    border-radius:var(--bh-radius-sm, 6px); font-size:14px; background:var(--bh-bg, #fff); color:inherit;
  }
  .bhi-login-submit {
    width:100%; padding:10px 0; border:none; border-radius:var(--bh-radius-sm, 6px); background:var(--bh-accent, #2271b1);
    /* 2026-08-26: was hardcoded #fff -- same class of bug as
       --bh-accent-contrast (class-style.php), just a literal instead of
       a fallback this time. Found by the front-end audit's continuation
       pass: 4.13:1 white-on-#C85C48, needs 4.5. --bh-accent-contrast is
       exactly the token derived for this job (measured against whichever
       accent is actually chosen), the fallback keeps the pre-existing
       white for installs on an older the-self-hosted-self without it. */
    color:var(--bh-accent-contrast, #fff); font-size:14px; font-weight:600; cursor:pointer; margin-top:4px;
  }
  .bhi-login-submit:disabled { opacity:0.6; cursor:default; }
  .bhi-login-error {
    display:none; background:#fbeaea; color:#b32d2e; border-radius:var(--bh-radius-sm, 6px);
    padding:9px 11px; font-size:13px; margin-bottom:14px;
  }
  .bhi-login-foot { text-align:center; margin-top:16px; font-size:12.5px; }
  .bhi-login-foot a { color:var(--bh-accent, #2271b1); text-decoration:none; }
  .bhi-login-panel[hidden] { display:none; }
</style>
<div class="bhi-portal-login">
<div class="bhi-login-card">
  <div class="bhi-login-brand"><?php echo esc_html(get_bloginfo('name')); ?></div>
  <div class="bhi-login-sub">Sign in to your account</div>

  <div class="bhi-login-tabs">
    <button type="button" class="bhi-login-tab is-active" data-panel="login">Log in</button>
    <button type="button" class="bhi-login-tab" data-panel="register">Create account</button>
  </div>

  <div class="bhi-login-error" id="bhi-login-error"></div>

  <form class="bhi-login-panel" id="bhi-login-panel-login">
    <div class="bhi-login-field"><label for="bhi-login-username">Username or email</label><input type="text" id="bhi-login-username" autocomplete="username" required></div>
    <div class="bhi-login-field"><label for="bhi-login-password">Password</label><input type="password" id="bhi-login-password" autocomplete="current-password" required></div>
    <div class="bhi-login-field" id="bhi-login-2fa-field" hidden><label for="bhi-login-2fa">6-digit code</label><input type="text" id="bhi-login-2fa" inputmode="numeric" autocomplete="one-time-code"></div>
    <button type="submit" class="bhi-login-submit">Log in</button>
    <div class="bhi-login-foot"><a href="<?php echo esc_url(wp_lostpassword_url(home_url('/' . self::REWRITE_SLUG . '/'))); ?>">Forgot your password?</a></div>
  </form>

  <form class="bhi-login-panel" id="bhi-login-panel-register" hidden>
    <div class="bhi-login-field"><label for="bhi-reg-username">Username</label><input type="text" id="bhi-reg-username" autocomplete="username" required></div>
    <div class="bhi-login-field"><label for="bhi-reg-email">Email</label><input type="email" id="bhi-reg-email" autocomplete="email" required></div>
    <div class="bhi-login-field"><label for="bhi-reg-password">Password</label><input type="password" id="bhi-reg-password" autocomplete="new-password" minlength="8" required></div>
    <button type="submit" class="bhi-login-submit">Create account</button>
  </form>
</div>
<script>
(function () {
  var redirectTo = <?php echo wp_json_encode($redirect_to); ?>;
  var restBase = <?php echo wp_json_encode(esc_url_raw(rest_url('bhi/v1'))); ?>;
  var tabs = document.querySelectorAll('.bhi-login-tab');
  var panels = { login: document.getElementById('bhi-login-panel-login'), register: document.getElementById('bhi-login-panel-register') };
  var errorBox = document.getElementById('bhi-login-error');

  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      tabs.forEach(function (t) { t.classList.remove('is-active'); });
      tab.classList.add('is-active');
      var target = tab.dataset.panel;
      Object.keys(panels).forEach(function (key) { panels[key].hidden = key !== target; });
      errorBox.style.display = 'none';
    });
  });

  function showError(message) {
    errorBox.textContent = message;
    errorBox.style.display = 'block';
  }

  function submitJSON(path, body, submitBtn) {
    submitBtn.disabled = true;
    return fetch(restBase + path, {
      method: 'POST',
      // Explicit — some mobile in-app browsers still default fetch to
      // credentials:'omit', which drops the Set-Cookie the auth
      // response carries, so wp_signon()'s session never lands and the
      // redirect comes straight back to this form.
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }).then(function (res) {
      return res.json().then(function (data) { return { ok: res.ok, data: data }; });
    }).finally(function () {
      submitBtn.disabled = false;
    });
  }

  document.getElementById('bhi-login-panel-login').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = e.target.querySelector('.bhi-login-submit');
    var body = {
      username: document.getElementById('bhi-login-username').value,
      password: document.getElementById('bhi-login-password').value,
    };
    var codeField = document.getElementById('bhi-login-2fa');
    if (!document.getElementById('bhi-login-2fa-field').hidden && codeField.value) {
      body.code = codeField.value;
    }
    submitJSON('/login', body, btn).then(function (result) {
      if (result.ok) {
        // Cache-buster on the hop back: if a full-page cache still holds
        // the logged-out HTML for this exact URL, a plain navigation to
        // it can re-render the login form even though the session now
        // exists. A unique query arg forces a fresh render; the portal
        // ignores unknown args.
        window.location.href = redirectTo + (redirectTo.indexOf('?') > -1 ? '&' : '?') + '_li=' + Date.now();
        return;
      }
      if (result.data && result.data.data && result.data.data.requires_2fa) {
        document.getElementById('bhi-login-2fa-field').hidden = false;
        showError(result.data.message || 'Enter your 2FA code.');
        return;
      }
      showError((result.data && result.data.message) || 'Login failed.');
    });
  });

  document.getElementById('bhi-login-panel-register').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = e.target.querySelector('.bhi-login-submit');
    var body = {
      username: document.getElementById('bhi-reg-username').value,
      email: document.getElementById('bhi-reg-email').value,
      password: document.getElementById('bhi-reg-password').value,
    };
    submitJSON('/register', body, btn).then(function (result) {
      if (result.ok) {
        window.location.href = redirectTo + (redirectTo.indexOf('?') > -1 ? '&' : '?') + '_li=' + Date.now();
        return;
      }
      showError((result.data && result.data.message) || 'Could not create your account.');
    });
  });
})();
</script>
</div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * The Datastar polling target render_shell() points
     * data-on-interval__duration.30s at — see that method's own comment
     * for why this is a bounded per-poll GET (not a held-open SSE
     * stream). Recomputes the exact same two values render_shell()
     * seeds signals with at page load, so a poll and a fresh page load
     * can never show different numbers for the same underlying state.
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
        // Real gap this closes: wallet balance was only ever visible via
        // the [bhm_wallet] shortcode (wherever an admin happened to drop
        // it) or by drilling into the Membership & Wallet panel — a fan
        // could easily lose track of their own balance anywhere else in
        // the portal. One persistent line in the nav, always in view
        // regardless of which panel is open, links straight to the full
        // panel for topping up/reviewing the ledger. The amount itself is
        // Datastar-bound (data-text) to the walletBalance signal seeded
        // above, so a purchase/tip/topup made in another tab shows up
        // here within one poll interval instead of only on next reload.
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
    $panel = self::get_panel($active);
    if ($panel) {
        ob_start();
        call_user_func($panel['render']);
        $panel_html = trim((string) ob_get_clean());
        // Panels that already group their own content into
        // .bhi-portal-section cards (Overview does, with several) print
        // as-is. Everything else — the many peer-plugin panels that
        // still echo bare h2/h3/p/form straight into the column — gets
        // one shared card wrapper so it reads as a deliberate section
        // with a gutter and rhythm instead of text dumped against the
        // left edge. One place, so every panel benefits without each
        // peer plugin needing the wrapper retrofitted into its PHP.
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
