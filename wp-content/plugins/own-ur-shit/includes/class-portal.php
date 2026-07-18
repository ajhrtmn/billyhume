<?php
if (!defined('ABSPATH')) exit;

// OUS_VER 3.4.19 — register_debug_section() now sets 'group' =>
// OUS_Debug::GROUP_REFERENCE (Debug Tools reorganization pass — see
// class-debug.php's own docblock). This section is read-only (lists
// registered portal panels + a link to the live portal), so it groups
// with API/Codebase Docs under "Reference & Docs" rather than the
// default bucket. No other change.

/**
 * BHI_Portal — the custom user-facing account shell (ROADMAP-platform-evolution.md
 * Section 6). A genuinely separate, branded front-end account area, not
 * a reskinned wp-admin and not a pile of independent per-plugin
 * shortcodes on separate pages. Renders at a rewrite-owned `/account/`
 * URL, built entirely from panels contributed via the `bhi_portal_panels`
 * filter — the same zero-central-registration shape `ous_registered_plugins`/
 * `ous_debug_tools`/`bhy_style_surfaces` already use successfully.
 *
 * This handoff ships the SHELL, the filter contract, the wp-admin
 * exclusion rollout, and ONE real migrated panel (profile/identity —
 * see BHI_PublicProfile::render_portal_panel()) — matching how this
 * ecosystem always ships a new extension point: one real, working
 * example plus a documented contract, not every possible consumer at
 * once (see own-ur-shit's own OUS_Notifications/OUS_Jobs history).
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

    public static function init() {
        // THE REAL BUG, finally found: own-ur-shit.php hooks
        // BHI_Portal::init() itself onto 'init' (default priority 10).
        // The line that used to be here — add_action('init', [self::class,
        // 'add_rewrite']) — was registering a NEW 'init' callback from
        // INSIDE a callback that is itself currently running as part of
        // 'init' priority 10. PHP's foreach over WP_Hook's callback array
        // for a given priority is a snapshot taken when that priority's
        // iteration begins; a handler appended to that same priority
        // AFTER iteration has already started is not picked up until the
        // NEXT time the hook fires — which, for 'init' on a normal page
        // load, never happens again in that request. So add_rewrite()
        // was being scheduled to run, successfully, on a request that
        // would never come — which is exactly why even the always-
        // throttled breadcrumb at the top of add_rewrite() never once
        // appeared in Console & Logs: the method itself was never
        // actually being called, not failing partway through. This is a
        // well-known WordPress hook-timing footgun (self-hooking the
        // currently-executing action at the same or an already-passed
        // priority), not a caching issue like this class's other fixes.
        // Fix: call it directly. We're already executing inside 'init'
        // right now (that's how we got here), so there's no need to
        // re-hook it at all — a plain call runs it immediately, in the
        // same request, every time.
        // Deferred to priority 20, not called immediately — running it at
        // the default priority-10 pass (like the first fix did) meant it
        // could execute BEFORE other plugins' own 'init'-priority-10
        // rewrite_rule registrations had happened, so a flush triggered
        // this early could capture an incomplete rule set and, worse,
        // this method's own wp_cache_flush() wipes the ENTIRE object
        // cache mid-request — including cached reads other code later in
        // the SAME request (like OUS_Debug::is_locked()'s host checks)
        // depends on, which is the most likely cause of the API Docs
        // page intermittently breaking right after the first Portal fix
        // shipped. Priority 20 is still well within the SAME 'init' pass
        // (WP_Hook::do_action() walks priority buckets in order within
        // one call — a bucket that hasn't been reached yet when a
        // callback is added to it during an earlier bucket's iteration
        // DOES still run this same request, unlike same-priority
        // self-hooking, which is the actual bug the first fix solved).
        // This gives every other plugin's own default-priority rewrite
        // registration a chance to complete first, and pushes the
        // heaviest, most cache-disruptive part of this method as late as
        // reasonably possible in the request.
        add_action('init', [self::class, 'add_rewrite'], 20);
        add_filter('query_vars', [self::class, 'add_query_var']);
        add_action('template_redirect', [self::class, 'maybe_render']);

        // wp-admin exclusion rollout — redirect non-elevated roles off
        // /wp-admin entirely (not just hiding the admin bar, which is
        // cosmetic and leaves the dashboard reachable by direct URL),
        // and disable the admin bar for the same roles. One place, one
        // filter for role exemptions, per the roadmap's own direction
        // ("in the core, not per-plugin").
        add_action('admin_init', [self::class, 'maybe_redirect_from_wp_admin']);
        add_filter('show_admin_bar', [self::class, 'maybe_hide_admin_bar']);

        // Closes the one real remaining hop this rollout left open: WP
        // core's default post-login redirect target is admin_url() —
        // technically still caught by maybe_redirect_from_wp_admin()
        // above the moment that page loads (every real wp-admin screen,
        // including admin-post.php, runs through wp-admin/admin.php,
        // which is what actually fires 'admin_init' — admin-ajax.php is
        // the one wp-admin entry point that does NOT, which is correct
        // and intentional: it returns JSON/fragments, not an HTML
        // screen, and a blanket block there would break legitimate
        // customer-facing AJAX, e.g. WooCommerce cart, contest voting —
        // but that's still an extra bounce through a page the person
        // was never going to be allowed to see. Sending them straight to
        // the portal on login is a straight correctness/UX improvement,
        // not a new security boundary — the boundary was already closed
        // by maybe_redirect_from_wp_admin().
        add_filter('login_redirect', [self::class, 'maybe_redirect_login'], 20, 3);
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);

        // ELEMENT-BUILDER-DESIGN-PLAN.md §5.4 — the 'portal_panel'
        // bh_element_surfaces contributor stays registered (Design Suite's
        // Element Builder can still compose content against it), but per
        // AJ's explicit "don't need Custom panel in portal" call, it's no
        // longer surfaced as its own portal nav tab — register_elements_
        // panel()/render_elements_panel() are kept (harmless, unused) in
        // case a real per-user or named panel use case shows up later,
        // rather than deleting working code for a naming/UX call.
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
    public static function register_element_surface($surfaces) {
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
     * The "one new panel type" §5.4 asks for: an element-composed panel
     * registered through the EXISTING bhi_portal_panels contract, exactly
     * like every other panel (profile, etc.) — nothing about the Portal's
     * own panel machinery changes. render_elements_panel() below is the
     * panel's 'render' callback; it does nothing but call
     * BH_Element::render_slot() for the 'portal_panel' surface's 'body'
     * slot, context 0 (the one site-wide panel this phase ships).
     *
     * class_exists('BH_Element') guarded so this panel simply doesn't
     * register at all if the element-builder classes are ever absent —
     * same "harmless to keep, never a hard dependency" posture
     * BHCRM_People::register_element_surface()'s own docblock describes.
     */
    public static function register_elements_panel($panels) {
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

    public static function render_elements_panel() {
        echo '<h2>Custom</h2>';
        if (!class_exists('BH_Element')) {
            echo '<p>Element Builder is unavailable.</p>';
            return;
        }
        $ctx = ['user_id' => get_current_user_id()];
        $html = BH_Element::render_slot('portal_panel', 0, 'body', $ctx);
        if ($html === '') {
            // Real dead-link bug, caught and fixed: this used to point
            // at admin.php?page=bh-element-builder — a real page in an
            // earlier arc of this project, since deleted (see
            // class-style-gallery.php's own docblock on why) and never
            // replaced with an equivalent admin UI. No such page exists
            // to link to anymore; stating that honestly rather than
            // leaving a broken link in a real, live empty-state message.
            echo '<p>Nothing has been placed here yet (surface "portal_panel", slot "body") — no admin UI for composing placements exists in this version.</p>';
            return;
        }
        echo $html; // phpcs:ignore -- BH_Element::render_slot()'s own output is already escaped/kses'd per-element at the render_placement() boundary, same trust posture render_slot()'s other call sites (dashboard, CRM) already use.
    }

    // Same self-diagnosing instinct the API Docs 404 fix started this
    // pass with — "why isn't my panel showing on the portal" now has a
    // one-click answer instead of requiring a re-read of every
    // contributing plugin's own bootstrap file.
    public static function register_debug_section($tools) {
        $tools['bhi-portal'] = ['label' => 'Portal', 'render' => [self::class, 'render_debug_section'], 'handle' => null, 'reset' => null, 'group' => OUS_Debug::GROUP_REFERENCE];
        return $tools;
    }

    public static function render_debug_section() {
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
        echo '<p>Excluded roles: <code>' . esc_html(implode(', ', self::excluded_roles())) . '</code></p>';

        // Registered panels above only prove the PHP ran — a "/account/
        // 404s despite everything else working" report needs a DIFFERENT
        // question answered: did the rewrite rule actually make it into
        // WordPress's own persisted rewrite table, or is this a web-
        // server-level proxy issue (nginx/Apache never even handing the
        // request to WordPress, in which case this PHP never runs at
        // all for that URL and nothing on this page could show it
        // either way)? This reads the SAME option WordPress itself
        // consults on every front-end request, so a "not found here"
        // result is a real, actionable signal, not a guess.
        echo '<h4>Rewrite rule</h4>';
        // Reads straight from the DB (same bypass add_rewrite() itself
        // uses) rather than get_option(), which is exactly the layer
        // that was proven to lie on this class's own real bug report —
        // this panel needs to answer "what's ACTUALLY in the database,"
        // not "what does the (possibly stale) object cache claim."
        $found = self::rewrite_rule_persisted();
        if ($found) {
            echo '<p>&#9989; Found in the persisted rewrite table — WordPress itself knows this URL. '
               . 'If <code>/' . esc_html(self::REWRITE_SLUG) . '/</code> still 404s from here, the request likely never reaches WordPress\'s PHP at all — check the web server (nginx/Apache) config for a rewrite/proxy rule sending unmatched paths to <code>index.php</code>, or a caching layer serving a stale 404.</p>';
        } else {
            echo '<p>&#10060; NOT found in the persisted rewrite table as of this page load. This class self-heals automatically on the next un-throttled request (at most a ' . (int) self::VERIFY_THROTTLE_SECONDS . '-second wait, see <code>not_recently_attempted()</code>) — reload this page in a moment before assuming it\'s stuck. If it\'s STILL missing after that, the cause is outside what a flush + full cache eviction can fix from PHP: a reverse proxy/CDN caching the route itself, a read-only options table, or multisite domain mapping. Check <code>OUS_DebugLog</code> for a matching "still not persisted after a forced flush" entry, which confirms the self-heal genuinely ran and genuinely failed, rather than just not having fired yet.</p>';
        }
    }

    public static function maybe_redirect_login($redirect_to, $requested_redirect_to, $user) {
        if (!($user instanceof \WP_User) || is_wp_error($user)) return $redirect_to;
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

    // Historical version-gate constant — no longer used to DECIDE whether
    // to flush (see add_rewrite() below, which now verifies persistence
    // directly instead of trusting a "done" flag), kept only because
    // bumping it is still the right signal to a human reading git history
    // that the rule shape itself changed. The v1 -> v2 bump on a real
    // install proved a one-shot version-gated flush isn't good enough:
    // it can mark itself "done" via update_option() while a persistent
    // object cache (Redis/Memcached) keeps serving the OLD rewrite_rules
    // value on every subsequent request, forever, because nothing ever
    // re-checks. See verify_rewrite_persisted() for the actual fix.
    const REWRITE_VERSION = '2';

    // Rate-limit guard for the DB-bypassing verification below — cheap on
    // its own, but a real flush_rewrite_rules() touches .htaccess/DB on
    // every hit, so if something is fundamentally broken (a cache that
    // refuses to ever let go of a stale value) this stops it from being
    // re-attempted on literally every single request. 60s is short enough
    // that a real fix (activating an object cache, disabling a broken
    // one) is visible almost immediately, long enough that init-hook
    // traffic doesn't turn into a flush storm.
    const VERIFY_THROTTLE_SECONDS = 60;

    public static function add_rewrite() {
        // Diagnostic breadcrumb — a real, reported symptom (rewrite rule
        // confirmed missing on every reload, but ZERO Portal log entries
        // at all, not even the throttled "still broken" warning that
        // should fire at least once across many reloads/minutes) points
        // at this whole method possibly never being ENTERED, not a bug
        // in its internal logic. This one unconditional (but still
        // throttled, so it can't flood) line at the very top settles
        // that definitively on the next page load: if this never
        // appears in Console & Logs either, the problem is upstream of
        // this class entirely (the 'init' hook never firing for
        // BHI_Portal::init(), or a fatal earlier in the same request) —
        // if it DOES appear but nothing below it does, the problem is
        // isolated to rewrite_rule_persisted()/not_recently_attempted().
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log_throttled('info', 'portal_add_rewrite_entered', 120,
                'BHI_Portal::add_rewrite() was entered this request.', [], 'Portal'
            );
        }

        add_rewrite_rule('^' . self::REWRITE_SLUG . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
        add_rewrite_rule('^' . self::REWRITE_SLUG . '/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=1&panel=$matches[1]', 'top');

        // Self-heals on every request that isn't currently throttled,
        // rather than once per version — the real bug this replaces
        // (reported on a live install, see REWRITE_VERSION's docblock)
        // was a flush that reported success via its own flag while never
        // actually persisting, because nothing ever went back and
        // checked the PERSISTED value again. This does — reads
        // rewrite_rules straight from the DB (bypassing wp_cache_get()
        // entirely, so a stale object-cache layer can't lie about it),
        // and only flushes when that direct read proves the pattern is
        // genuinely still missing. No wp-admin visit, permalink resave,
        // or manual step required — this runs on the same 'init' hook
        // every front-end and admin request already fires.
        if (self::rewrite_rule_persisted()) {
            // Throttled trace of a PASSING check — without this, an
            // empty Console & Logs table for "Portal" is ambiguous
            // between "checked every request and always fine" and
            // "the self-heal stopped running at all" (e.g. a throttle
            // bug silently blocking it forever, which is exactly what
            // happened in 3.3.3 before this same 3.3.4 pass fixed it —
            // that bug produced precisely this kind of indistinguishable
            // silence). See OUS_DebugLog::log_throttled()'s own docblock.
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log_throttled('info', 'portal_rewrite_pass', 300,
                    'Rewrite-rule persistence check ran and confirmed the rule is present.', [], 'Portal'
                );
            }
        } elseif (self::not_recently_attempted()) {
            self::force_flush_and_verify();
        } elseif (class_exists('OUS_DebugLog')) {
            // Missing AND throttled — logged at 'warning' (not 'info',
            // unlike the two throttled traces above) because this is the
            // exact state that was silently invisible before: the rule
            // is confirmed broken on THIS request, but the self-heal is
            // sitting out its throttle window rather than acting. Without
            // this line, that state produces zero log output, which is
            // the precise blind spot this whole logging pass exists to
            // close.
            OUS_DebugLog::log_throttled('warning', 'portal_rewrite_missing_throttled', 300,
                'Rewrite rule confirmed missing from the persisted table this request, but a self-heal attempt was made recently — sitting out the throttle window rather than re-flushing.', [], 'Portal'
            );
        }
    }

    // Bypasses the object cache on purpose — $wpdb->get_var() talks
    // straight to the database, so this can't be fooled by a persistent
    // cache (Redis/Memcached) serving back a stale copy of the option,
    // which is exactly the failure mode that made the old version-gated
    // flush look successful when it wasn't.
    private static function rewrite_rule_persisted() {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'rewrite_rules'));
        if (!$raw) return false;
        return strpos($raw, '^' . self::REWRITE_SLUG) !== false;
    }

    // Deliberately NOT get_transient()/set_transient() — on an install
    // with a persistent object cache active (the exact install class
    // this whole fix targets), transients are stored IN that cache, not
    // the options table. If the cache is genuinely stuck/broken, a
    // transient-based throttle can read as "already attempted" forever,
    // silently skipping the self-heal on every single request with
    // nothing ever logged — which is indistinguishable from "working
    // correctly, just waiting" from the outside. That's a real bug this
    // class shipped with initially and a real reported symptom (zero log
    // entries despite the rule staying broken across many reloads) — the
    // fix is a direct, cache-bypassing DB read/write, same technique as
    // rewrite_rule_persisted() above.
    private static function not_recently_attempted() {
        global $wpdb;
        $last = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'bhi_portal_rewrite_last_attempt'));
        if ($last && (time() - (int) $last) < self::VERIFY_THROTTLE_SECONDS) return false;
        // Direct INSERT ... ON DUPLICATE KEY UPDATE, not update_option() —
        // update_option() reads the current cached value first to decide
        // whether a write is even needed, which reintroduces the exact
        // cache-trust problem this method exists to avoid. This writes
        // unconditionally and evicts any cached copy of the key
        // immediately after, the same pattern force_flush_and_verify()
        // already uses for 'rewrite_rules'/'alloptions'.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            'bhi_portal_rewrite_last_attempt', (string) time()
        ));
        wp_cache_delete('bhi_portal_rewrite_last_attempt', 'options');
        wp_cache_delete('alloptions', 'options');
        return true;
    }

    private static function force_flush_and_verify() {
        flush_rewrite_rules();
        // Explicitly evict any object-cache copy of the two keys a stale
        // cache could serve back instead of what flush_rewrite_rules()
        // just wrote — 'alloptions' is the bundled cache WordPress's own
        // get_option() reads from for options this size by default, so a
        // stale 'rewrite_rules' entry can hide behind an equally stale
        // 'alloptions' blob even if the individual key were otherwise
        // correct.
        wp_cache_delete('rewrite_rules', 'options');
        wp_cache_delete('alloptions', 'options');

        $persisted = self::rewrite_rule_persisted();

        // The full wp_cache_flush() is now an ESCALATION, only reached if
        // the two targeted evictions above weren't enough — not called
        // unconditionally on every throttled attempt like before. A full
        // flush wipes the ENTIRE object cache mid-request, which other
        // code running later in this same request (OUS_Debug::is_locked()'s
        // host checks, among others) depends on having a warm cache for;
        // doing that on every self-heal attempt (as often as once a
        // minute, across all site traffic) was very likely the cause of
        // the API Docs page intermittently breaking right after the first
        // version of this fix shipped. Reaching for it only when the
        // cheaper, targeted eviction demonstrably wasn't enough keeps
        // this method's blast radius as small as it can be while still
        // actually fixing a genuinely stuck cache when one exists.
        if (!$persisted && function_exists('wp_cache_flush')) {
            wp_cache_flush();
            $persisted = self::rewrite_rule_persisted();
        }

        if ($persisted) {
            update_option('bhi_portal_rewrite_flushed', self::REWRITE_VERSION);
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('info', 'Portal rewrite rule self-healed and confirmed persisted.', [], 'Portal');
            }
        } elseif (class_exists('OUS_DebugLog')) {
            // Still broken after a real flush + full cache eviction —
            // worth a log entry (not just silent retry-forever) since at
            // this point the likely cause is something outside WordPress
            // entirely (a reverse proxy/CDN caching /account/ itself, a
            // read-only options table, multisite domain mapping) rather
            // than the object-cache staleness this fix targets. The
            // Debug Tools panel below surfaces this same verdict live.
            OUS_DebugLog::log('warning', 'Portal rewrite rule still not persisted after a forced flush + full cache eviction — likely cause is outside WordPress\'s own caching layer (reverse proxy/CDN, read-only DB, multisite domain mapping).', [], 'Portal');
        }
    }

    public static function add_query_var($vars) {
        $vars[] = self::QUERY_VAR;
        $vars[] = 'panel';
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
    public static function excluded_roles() {
        return apply_filters('bhi_portal_excluded_roles', ['subscriber', 'customer']);
    }

    private static function user_is_excluded($user) {
        if (!$user || !$user->exists()) return false;
        return (bool) array_intersect(self::excluded_roles(), (array) $user->roles);
    }

    public static function maybe_redirect_from_wp_admin() {
        if (wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON)) return;
        $user = wp_get_current_user();
        if (!self::user_is_excluded($user)) return;
        wp_safe_redirect(home_url('/' . self::REWRITE_SLUG . '/'));
        exit;
    }

    public static function maybe_hide_admin_bar($show) {
        $user = wp_get_current_user();
        if (self::user_is_excluded($user)) return false;
        return $show;
    }

    /* ---------- panel registry ---------- */

    public static function get_panels() {
        $panels = apply_filters('bhi_portal_panels', []);
        $panels = array_filter($panels, function ($p) {
            return !empty($p['id']) && !empty($p['render']) && is_callable($p['render']);
        });
        usort($panels, function ($a, $b) {
            return ($a['priority'] ?? 10) <=> ($b['priority'] ?? 10);
        });
        return array_values($panels);
    }

    public static function get_panel($id) {
        foreach (self::get_panels() as $panel) {
            if ($panel['id'] === $id) return $panel;
        }
        return null;
    }

    /* ---------- rendering ---------- */

    public static function maybe_render() {
        if (!get_query_var(self::QUERY_VAR)) return;

        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url('/' . self::REWRITE_SLUG . '/')));
            exit;
        }

        status_header(200);
        nocache_headers();
        self::render_shell();
        exit;
    }

    private static function render_shell() {
        $panels = self::get_panels();
        $requested = sanitize_key(get_query_var('panel'));
        $active = $requested && self::get_panel($requested) ? $requested : ($panels[0]['id'] ?? '');

        // The portal gets its own front-end design treatment (explicitly
        // meant to look nothing like default WordPress, per the roadmap
        // doc) but still draws on BHY_Style's existing design tokens
        // rather than inventing a second, disconnected visual language.
        $has_style = class_exists('BHY_Style');
        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html(get_bloginfo('name')); ?> — Account</title>
<?php wp_head(); ?>
<?php if ($has_style): BHY_Style::inline_css(); endif; ?>
<style>
  /* QA fix, caught live: every rule below previously referenced
     --bhy-color-* custom properties (--bhy-color-bg, --bhy-color-
     surface, --bhy-color-border, --bhy-color-text, --bhy-color-
     accent-bg, --bhy-color-accent, --bhy-font-body) — none of which
     this codebase defines ANYWHERE. This ecosystem has two REAL, but
     DIFFERENT, token systems: --bhy-* (own-ur-shit's admin-only design
     system, class-ui.php, scoped to .bhy-shell) and --bh-* (BHY_Style::
     inline_css()'s front-end/entity brand tokens, what the comment
     just above this block already correctly says the portal draws
     on). Since the portal is a front-end page, --bh-* is the correct
     family — the old code just had the wrong exact names, so every
     declaration silently fell through to its hardcoded fallback
     (generic WordPress blue #2271b1, plain white/grey) instead of the
     site's real warm-cream/terracotta brand, on every single portal
     page load. Confirmed live: inspected the actual page's real
     <link>/<style> output before this fix and found zero portal-
     specific styling reaching the DOM in any usable form.
     Also newly added here: a real mobile breakpoint (the sidebar
     previously had no @media query at all — a fixed 220px nav plus a
     820px-capped, 32px-padded main column simply doesn't fit a phone
     screen) and tighter, token-driven spacing in place of the
     original's ad hoc pixel values. */
  body.bhi-portal { margin:0; background:var(--bh-bg, #f6f6f7); color:var(--bh-text, #1d2327); font-family:var(--bh-font-body, -apple-system), -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; }
  .bhi-portal-shell { display:flex; min-height:100vh; }
  .bhi-portal-nav { width:220px; flex-shrink:0; background:var(--bh-surface, #fff); border-right:1px solid var(--bh-border, #e2e2e2); padding:24px 0; }
  .bhi-portal-nav a { display:flex; align-items:center; gap:10px; padding:11px 20px; color:var(--bh-text, #1d2327); text-decoration:none; font-size:14px; border-left:3px solid transparent; }
  .bhi-portal-nav a:hover { background:var(--bh-surface-2, #f6f7f7); }
  .bhi-portal-nav a.is-active { background:var(--bh-accent-soft, #eef4ff); border-left-color:var(--bh-accent, #2271b1); font-weight:600; }
  .bhi-portal-main { flex:1; min-width:0; padding:32px 40px; max-width:820px; }
  .bhi-portal-brand { padding:0 20px 20px; font-family:var(--bh-font-display, inherit); font-weight:700; font-size:16px; }
  /* Shared by every panel — one place so bh-monetization-woo/bh-courses/
     bh-contest's own portal-panel classes don't each hand-roll table/card
     styling that then drifts from each other. */
  .bhi-portal-table { width:100%; border-collapse:collapse; margin-top:8px; }
  .bhi-portal-table th, .bhi-portal-table td { text-align:left; padding:10px 12px; border-bottom:1px solid var(--bh-border, #e2e2e2); font-size:14px; }
  /* QA fix: panels (bh-monetization-woo, bh-courses, bh-contest) were
     outputting bare h1/h2/p/ul/table with zero wrapping divs, so
     adjacent sections (e.g. "Active tiers" + "Wallet") visually blended
     together with no separation — exactly the "too crammed, no proper
     padding/margin/gaps" complaint. This is the shared card/section
     wrapper every panel should use to group related content. */
  .bhi-portal-section { background:var(--bh-surface, #fff); border:1px solid var(--bh-border, #e2e2e2); border-radius:var(--bh-radius, 10px); padding:20px 24px; margin-bottom:20px; }
  .bhi-portal-section:last-child { margin-bottom:0; }
  .bhi-portal-section h2 { margin:0 0 14px; font-size:16px; font-weight:600; }
  .bhi-portal-section > *:last-child { margin-bottom:0; }
  .bhi-portal-course-list { display:grid; gap:16px; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); margin-top:12px; }
  .bhi-portal-course-card { border:1px solid var(--bh-border, #e2e2e2); border-radius:var(--bh-radius-sm, 8px); padding:16px; background:var(--bh-surface, #fff); }
  .bhi-portal-course-card h3 { margin:0 0 8px; font-size:15px; }
  .bhi-portal-progress-bar { height:6px; border-radius:3px; background:var(--bh-surface-2, #e2e2e2); overflow:hidden; }
  .bhi-portal-progress-fill { height:100%; background:var(--bh-accent, #2271b1); }

  /* Mobile: the fixed sidebar becomes a horizontal, scrollable tab strip
     above the content instead — same navigation, no hidden/hamburger
     menu to build, and nothing a visitor has to discover. Content
     padding drops so it isn't fighting a phone's own margins. */
  @media (max-width: 782px) {
    .bhi-portal-shell { flex-direction:column; }
    .bhi-portal-nav { width:100%; display:flex; overflow-x:auto; padding:8px 0; border-right:none; border-bottom:1px solid var(--bh-border, #e2e2e2); -webkit-overflow-scrolling:touch; }
    .bhi-portal-brand { display:none; } /* the page <title>/site header already says whose account this is */
    .bhi-portal-nav a { flex-shrink:0; padding:10px 14px; border-left:none; border-bottom:3px solid transparent; }
    .bhi-portal-nav a.is-active { border-left-color:transparent; border-bottom-color:var(--bh-accent, #2271b1); }
    .bhi-portal-main { padding:20px 16px; max-width:none; }
  }
</style>
</head>
<body class="bhi-portal">
<div class="bhi-portal-shell">
  <nav class="bhi-portal-nav">
    <div class="bhi-portal-brand"><?php echo esc_html(get_bloginfo('name')); ?></div>
    <?php foreach ($panels as $panel): ?>
      <a href="<?php echo esc_url(home_url('/' . self::REWRITE_SLUG . '/' . $panel['id'] . '/')); ?>"
         class="<?php echo $panel['id'] === $active ? 'is-active' : ''; ?>">
        <span class="dashicons <?php echo esc_attr($panel['icon'] ?? 'dashicons-admin-generic'); ?>"></span>
        <?php echo esc_html($panel['label']); ?>
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
        call_user_func($panel['render']);
    } else {
        echo '<p>Nothing to show here yet.</p>';
    }
    ?>
  </main>
</div>
<?php wp_footer(); ?>
</body>
</html>
        <?php
    }
}
