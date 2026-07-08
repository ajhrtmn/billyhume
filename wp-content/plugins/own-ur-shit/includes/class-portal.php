<?php
if (!defined('ABSPATH')) exit;

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
        add_action('init', [self::class, 'add_rewrite']);
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
    }

    // Same self-diagnosing instinct the API Docs 404 fix started this
    // pass with — "why isn't my panel showing on the portal" now has a
    // one-click answer instead of requiring a re-read of every
    // contributing plugin's own bootstrap file.
    public static function register_debug_section($tools) {
        $tools['bhi-portal'] = ['label' => 'Portal', 'render' => [self::class, 'render_debug_section'], 'handle' => null, 'reset' => null];
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
        $rules = get_option('rewrite_rules');
        $pattern = '^' . self::REWRITE_SLUG . '/?$';
        $found = is_array($rules) && array_key_exists($pattern, $rules);
        if ($found) {
            echo '<p>&#9989; Found in the persisted rewrite table (<code>' . esc_html($pattern) . '</code> &#8594; <code>' . esc_html($rules[$pattern]) . '</code>) — WordPress itself knows this URL. '
               . 'If <code>/' . esc_html(self::REWRITE_SLUG) . '/</code> still 404s from here, the request likely never reaches WordPress\'s PHP at all — check the web server (nginx/Apache) config for a rewrite/proxy rule sending unmatched paths to <code>index.php</code>, or a caching layer serving a stale 404.</p>';
        } else {
            echo '<p>&#10060; NOT found in the persisted rewrite table. The rule is registered correctly in THIS request\'s code (see the panels above), but WordPress\'s saved <code>rewrite_rules</code> option doesn\'t have it — the flush this class runs on init isn\'t sticking. Possible causes: a persistent object cache (Redis/Memcached) serving a stale cached copy of the <code>rewrite_rules</code> option instead of the fresh one just written, or another plugin/mu-plugin overwriting rewrite rules after this one runs. Try Settings &rarr; Permalinks &rarr; Save once more with an object cache flushed/disabled if one is active.</p>';
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

    // Bump this whenever add_rewrite() below changes what it registers —
    // the self-healing flush right after it keys off this string, so a
    // real edit to the rules (not just re-running the same ones) forces
    // a fresh flush the same way a version bump does everywhere else in
    // this ecosystem's own migration pattern.
    // Bumped to 2 — a real install reported the v1 flush not sticking
    // (confirmed via this class's own Debug Tools diagnostic: the rule
    // was registered in-request but never showed up in the PERSISTED
    // rewrite_rules option). Most likely cause: a persistent object
    // cache (Redis/Memcached) serving back its own stale cached copy of
    // the 'rewrite_rules' option immediately after update_option() wrote
    // a fresh one — WordPress's object-cache API doesn't guarantee that
    // write and a subsequent read are seen by every cache backend
    // identically unless the cache is explicitly told to drop the old
    // entry. The explicit wp_cache_delete() calls below close that gap
    // directly rather than just hoping a plain flush is enough a second
    // time; bumping this constant forces every existing install
    // (including ones where the v1 flush "succeeded" per its own flag
    // but didn't actually persist) to retry with the fix in place.
    const REWRITE_VERSION = '2';

    public static function add_rewrite() {
        add_rewrite_rule('^' . self::REWRITE_SLUG . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
        add_rewrite_rule('^' . self::REWRITE_SLUG . '/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=1&panel=$matches[1]', 'top');

        // The activation-hook flush in own-ur-shit.php only fires on a
        // real WordPress "Activate" click — never on a file-replace
        // deploy of an already-active plugin (the exact scenario that
        // caused a real, reported 404 on /account/: this rule existed in
        // the new code, but WordPress's cached rewrite_rules option was
        // never told to regenerate). Same self-healing, one-time-per-
        // version flush BHM_Storefront::add_rewrite() already uses for
        // its own rewrite rule, applied here for the same reason.
        if (get_option('bhi_portal_rewrite_flushed') !== self::REWRITE_VERSION) {
            flush_rewrite_rules();
            // Explicitly evict any object-cache copy of the two keys a
            // stale cache could serve back instead of what flush_rewrite_rules()
            // just wrote — 'alloptions' is the bundled cache WordPress's
            // own get_option() reads from for options this size by
            // default, so a stale 'rewrite_rules' entry can hide behind
            // an equally stale 'alloptions' blob even if the individual
            // key were otherwise correct.
            wp_cache_delete('rewrite_rules', 'options');
            wp_cache_delete('alloptions', 'options');
            update_option('bhi_portal_rewrite_flushed', self::REWRITE_VERSION);
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
  body.bhi-portal { margin:0; background:var(--bhy-color-bg, #f6f6f7); font-family:var(--bhy-font-body, -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif); }
  .bhi-portal-shell { display:flex; min-height:100vh; }
  .bhi-portal-nav { width:220px; flex-shrink:0; background:var(--bhy-color-surface, #fff); border-right:1px solid var(--bhy-color-border, #e2e2e2); padding:24px 0; }
  .bhi-portal-nav a { display:flex; align-items:center; gap:8px; padding:10px 20px; color:var(--bhy-color-text, #1d2327); text-decoration:none; font-size:14px; }
  .bhi-portal-nav a.is-active { background:var(--bhy-color-accent-bg, #eef4ff); font-weight:600; }
  .bhi-portal-main { flex:1; padding:32px; max-width:820px; }
  .bhi-portal-brand { padding:0 20px 20px; font-weight:700; font-size:16px; }
  /* Shared by every panel — one place so bh-monetization-woo/bh-courses/
     bh-contest's own portal-panel classes don't each hand-roll table/card
     styling that then drifts from each other. */
  .bhi-portal-table { width:100%; border-collapse:collapse; margin-top:8px; }
  .bhi-portal-table th, .bhi-portal-table td { text-align:left; padding:8px 10px; border-bottom:1px solid var(--bhy-color-border, #e2e2e2); font-size:14px; }
  .bhi-portal-course-list { display:grid; gap:16px; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); margin-top:12px; }
  .bhi-portal-course-card { border:1px solid var(--bhy-color-border, #e2e2e2); border-radius:8px; padding:16px; background:var(--bhy-color-surface, #fff); }
  .bhi-portal-course-card h3 { margin:0 0 8px; font-size:15px; }
  .bhi-portal-progress-bar { height:6px; border-radius:3px; background:#e2e2e2; overflow:hidden; }
  .bhi-portal-progress-fill { height:100%; background:var(--bhy-color-accent, #2271b1); }
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
