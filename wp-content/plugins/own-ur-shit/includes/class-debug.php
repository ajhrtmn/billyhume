<?php
if (!defined('ABSPATH')) exit;

// OUS_VER 3.4.18 — handle() now also queues a BHCoreToast supplement
// (OUS_Toast::queue()) alongside the existing $_GET['ous_msg'] plain-text
// notice, for every registered debug-section action (Run due jobs now,
// etc.) — additive only, the existing notice/redirect mechanism below is
// unchanged. See class-toast.php's own docblock for why this uses
// OUS_ReliableStore instead of a transient.
//
// OUS_VER 3.4.19 — reorganization pass: sections registered via
// ous_debug_tools had grown to a dozen-plus, all rendered flat in
// filter-registration order with no logical grouping (a monitoring tool
// like Job Queue sitting next to a reference doc like API Docs sitting
// next to a one-off data-seeding action, in whatever order plugins
// happened to load). Added an OPTIONAL 'group' key to the existing
// registration array shape (self::group_label() below has the full
// bucket list + the default any ungrouped section falls into) — this is
// purely additive: a plugin that never sets 'group' still renders
// exactly as before, just filed under the default bucket, so no
// existing add_filter('ous_debug_tools', ...) call site anywhere in the
// ecosystem had to change its registration shape to keep working. Every
// CURRENT registrant (class-jobs.php, class-event.php,
// class-debug-log.php, class-registry.php, class-test-runner.php,
// class-api-docs.php, class-codebase-docs.php, class-studio.php,
// class-portal.php, bh-registry, bh-contest, bh-courses,
// class-content-bridge.php, bh-monetization-woo) was also updated in
// this same pass to set an explicit, sensible 'group' so the new
// grouping takes effect immediately instead of everything defaulting to
// one bucket. render() below now prints a group heading above each
// bucket's sections (in a fixed, curated order — see GROUP_ORDER) and
// the existing "Jump to" quicknav is now grouped the same way. The
// per-section <details>/<summary> collapsible markup, scroll-margin
// anchor fix, localStorage open/closed memory, and "Reset everything"
// section are all completely unchanged — this only changes how sections
// are bucketed/labeled on the way to that same existing markup.

/**
 * One shared Debug Tools page under Own Ur Shit, extensible the same
 * way the dashboard registry and style gallery already are: any plugin
 * registers its own section via a filter, entirely from its own
 * bootstrap — this class never needs to know bh-contest or bh-streaming
 * exist.
 *
 *     add_filter('ous_debug_tools', function ($tools) {
 *         $tools['bh-contest'] = [
 *             'label' => 'BH Contest',
 *             // Echoes this plugin's own section — its own target
 *             // picker if it needs one, buttons via OUS_Debug::button()
 *             // so every plugin's buttons share one consistent look and
 *             // one consistent form/nonce structure rather than each
 *             // reinventing it.
 *             'render' => ['BH_Debug', 'render_section'],
 *             // Receives ($action, $_POST) for whichever button was
 *             // clicked under THIS section specifically. The common
 *             // case: return a message string, and the shared
 *             // dispatcher redirects back to this page showing it. For
 *             // an action that needs to do something else entirely —
 *             // bh-contest's "log in as a test voter" switches the
 *             // browser's own session and sends it to a front-end URL
 *             // instead — the callback can just call wp_safe_redirect()
 *             // and exit() itself; the shared dispatcher's own redirect
 *             // then simply never executes.
 *             'handle' => ['BH_Debug', 'handle_action'],
 *             // Wipes only this plugin's own tagged test data, returns
 *             // a message string. Called individually or as part of
 *             // "Reset Everything."
 *             'reset' => ['BH_Debug', 'reset'],
 *         ];
 *         return $tools;
 *     });
 *
 * The production-safety lock (is_locked()) is checked ONCE here, centrally,
 * for the whole page and every action on it — a registered plugin's
 * 'handle'/'reset' callbacks are simply never invoked while locked, so
 * no individual plugin needs to re-check this itself.
 */
class OUS_Debug {
    // Curated display order for the optional 'group' key (see each
    // registration's own 'group' => '...' below). Any group name not
    // listed here (a future plugin inventing its own bucket name) still
    // renders fine — group_order() appends unknown names, sorted
    // alphabetically, right before the catch-all default so nothing is
    // ever silently dropped. The default bucket is deliberately last:
    // an ungrouped section (one that never set 'group' at all) reads as
    // "uncategorized," not as belonging with any curated bucket.
    const GROUP_MONITORING = 'Monitoring & Health';
    const GROUP_REFERENCE  = 'Reference & Docs';
    const GROUP_SEED_RESET = 'Seed & Reset Tools';
    const GROUP_DEFAULT    = 'Diagnostics & Tools';
    const GROUP_ORDER = [self::GROUP_MONITORING, self::GROUP_REFERENCE, self::GROUP_SEED_RESET, self::GROUP_DEFAULT];

    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_ous_debug_action', [self::class, 'handle']);
    }

    // Buckets $tools (the raw apply_filters('ous_debug_tools', []) result)
    // by each entry's own 'group' key, defaulting anything unset to
    // GROUP_DEFAULT — this is the one place that default is applied, so
    // render() itself never has to special-case a missing key. Returns
    // an ordered [group_label => [key => tool, ...]] array; group order
    // follows GROUP_ORDER, with any group name a plugin invented that
    // isn't in that curated list appended alphabetically just before the
    // default bucket.
    private static function group_tools(array $tools) {
        $buckets = [];
        foreach ($tools as $key => $tool) {
            $group = !empty($tool['group']) ? $tool['group'] : self::GROUP_DEFAULT;
            $buckets[$group][$key] = $tool;
        }
        $known = self::GROUP_ORDER;
        $extra = array_values(array_diff(array_keys($buckets), $known));
        sort($extra);
        // Splice any unknown groups in right before the default bucket
        // rather than at the very end, so a future plugin's own custom
        // bucket name still reads as "a real category," not as
        // dumped-after-everything-including-uncategorized.
        $default_pos = array_search(self::GROUP_DEFAULT, $known, true);
        $order = array_merge(array_slice($known, 0, $default_pos), $extra, array_slice($known, $default_pos));

        $ordered = [];
        foreach ($order as $group) {
            if (!empty($buckets[$group])) $ordered[$group] = $buckets[$group];
        }
        return $ordered;
    }

    /**
     * Optional convenience wrapper around the raw
     * add_filter('ous_debug_tools', ...) pattern shown in this class's
     * own docblock above — every current registrant (bh-contest,
     * bh-streaming, bh-courses, bh-registry, bh-crm, bh-monetization-woo)
     * hand-rolls an identically-shaped closure, so this collapses that
     * boilerplate to one call for anything written against it going
     * forward. Purely additive: existing add_filter() registrations are
     * untouched and keep working exactly as before — this was added in
     * the DRY/SOLID refactor pass specifically so future registrants
     * don't have to duplicate the pattern, not to force a rewrite of
     * every plugin that already registers directly (a live QA pass
     * against a real WordPress install, which this static-analysis-only
     * refactor doesn't have available, would be needed to safely retrofit
     * every existing call site without risking a regression).
     *
     * Usage, from any plugin's own bootstrap:
     *   OUS_Debug::register('bh-lyrics', 'BH Lyrics',
     *       ['BHL_Debug', 'render_section'], ['BHL_Debug', 'handle_action'],
     *       ['BHL_Debug', 'reset']);
     *
     * $group is optional (added in the 3.4.19 reorganization pass) — one
     * of self::GROUP_MONITORING / GROUP_REFERENCE / GROUP_SEED_RESET, or
     * any custom string a new bucket needs. Left null, the section files
     * under GROUP_DEFAULT ("Diagnostics & Tools"), same as any section
     * still registered via the raw add_filter() pattern that hasn't set
     * 'group' at all — this parameter existing doesn't require every
     * caller of this wrapper to pass it.
     */
    public static function register($key, $label, $render, $handle, $reset = null, $safe_in_production = false, $group = null) {
        add_filter('ous_debug_tools', function ($tools) use ($key, $label, $render, $handle, $reset, $safe_in_production, $group) {
            $tools[$key] = [
                'label' => $label, 'render' => $render, 'handle' => $handle, 'reset' => $reset,
                'safe_in_production' => $safe_in_production,
                'group' => $group,
            ];
            return $tools;
        });
    }

    /**
     * True if this looks like a production install. wp_get_environment_type()
     * defaults to 'production' unless WP_ENVIRONMENT_TYPE is set, so this
     * fails safe: unknown = blocked. Override with
     * define('OUS_DEBUG_TOOLS_FORCE', true) in wp-config.php if a live
     * site genuinely needs to seed data.
     */
    public static function is_locked() {
        if (defined('OUS_DEBUG_TOOLS_FORCE') && OUS_DEBUG_TOOLS_FORCE) return false;
        if (!function_exists('wp_get_environment_type') || wp_get_environment_type() === 'production') {
            // wp_get_environment_type() defaults to 'production' unless
            // WP_ENVIRONMENT_TYPE is explicitly set in wp-config.php —
            // which almost nobody's local dev tool (Local, MAMP, Valet,
            // etc.) does out of the box. Without this fallback, a real
            // local install would get treated as production and lose
            // API Docs / seed-data tooling for no reason other than a
            // wp-config constant nobody thought to add. A well-known
            // local-only hostname pattern is a reasonable, low-risk
            // second signal — none of these TLDs/hosts are ever valid on
            // the public internet, so this can't accidentally unlock a
            // real production site.
            //
            // Real, confirmed bug this fix responds to: home_url() reads
            // the 'home' option, which on an install running a persistent
            // object cache (Redis/Memcached) can be served stale on some
            // requests but not others — the exact same staleness class
            // that broke BHI_Portal's rewrite rule earlier in this same
            // install. That made this local/production check flip
            // per-request: the Debug Tools page (one request) would
            // correctly see "local," while navigating directly to
            // admin.php?page=ous-api-docs (a separate request) would see
            // "production" and silently skip add_submenu_page() —
            // producing WordPress core's own "Sorry, you are not allowed
            // to access this page" (its standard response for a page
            // slug with no matching registered menu entry, not a 404).
            // $_SERVER['HTTP_HOST'] is the literal Host header of THIS
            // request — never cached, never filtered, always accurate —
            // so it's checked first/independently rather than trusting
            // home_url() alone for something this consequential.
            $raw_host = isset($_SERVER['HTTP_HOST']) ? wp_parse_url('http://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST) : '';
            $option_host = wp_parse_url(home_url(), PHP_URL_HOST) ?: '';
            // A third read, bypassing the object cache entirely — same
            // fix shape as BHI_Portal's rewrite-rule self-heal (see that
            // class's REWRITE_VERSION docblock for the full history of
            // why this ecosystem no longer trusts get_option()/home_url()
            // alone for anything this consequential on an install with a
            // persistent object cache active). If the cache is serving a
            // stale 'home' value on this request, this catches it anyway.
            global $wpdb;
            $db_home = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'home'));
            $db_host = $db_home ? (wp_parse_url($db_home, PHP_URL_HOST) ?: '') : '';
            $local_pattern = '/(^localhost$|^127\.0\.0\.1$|\.(test|local|localhost)$)/i';
            $looks_local = (bool) preg_match($local_pattern, $raw_host)
                || (bool) preg_match($local_pattern, $option_host)
                || (bool) preg_match($local_pattern, $db_host);
            if (!$looks_local) {
                if (class_exists('OUS_DebugLog')) {
                    OUS_DebugLog::log('info', 'is_locked() returned TRUE — none of raw HTTP_HOST, cached home_url(), or a direct DB read of the home option looked local.', [
                        'raw_host' => $raw_host, 'option_host' => $option_host, 'db_host' => $db_host,
                    ], 'OUS_Debug::is_locked()');
                }
                return true;
            }
            // Throttled, unconditional trace of a PASSING evaluation too
            // — an empty log for this key is otherwise ambiguous between
            // "checked and unlocked every time" and "stopped running
            // entirely" (e.g. a fatal earlier in the same request
            // preventing this code from ever executing), which is
            // precisely the blind spot that made the live "not allowed"
            // report undiagnosable from log data alone. See
            // OUS_DebugLog::log_throttled()'s own docblock.
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log_throttled('info', 'is_locked_pass', 300,
                    'is_locked() evaluated and returned FALSE (unlocked) this request.',
                    ['raw_host' => $raw_host, 'option_host' => $option_host, 'db_host' => $db_host],
                    'OUS_Debug::is_locked()'
                );
            }
        }
        return false;
    }

    // Its own top-level menu ("OUS Debug"), separate from the main "Own
    // Ur Shit" hub — dev/debug tooling (seed data, Console & Logs, Test
    // Runner, and API Docs — see class-api-docs.php, which hangs its own
    // page off THIS parent now too) is a genuinely different audience
    // and use case than the hub's install/dashboard/reports pages, and
    // deserves its own clearly-labeled place in the sidebar rather than
    // being buried at the bottom of the hub's submenu. add_menu_page()
    // auto-creates a first submenu item labeled after the top-level menu
    // title — the add_submenu_page() call right after it, using the SAME
    // slug, is the standard WP trick to relabel that first item
    // "Debug Tools" instead of a redundant second "OUS Debug".
    public static function add_menu() {
        // Used to hide this whole page on production (is_locked()) —
        // loosened, because Console & Logs and the Test Runner (see
        // class-debug-log.php / class-test-runner.php) are genuinely
        // useful for an admin troubleshooting a LIVE site, not just a
        // dev environment, and hiding the whole page hid those too.
        // manage_options is the actual gate now (same as every other
        // admin-only page in this ecosystem); the seed/reset "fake test
        // data" actions specifically stay blocked in production via
        // is_locked(), checked per-section in handle() below.
        add_menu_page('Debug Tools', 'OUS Debug', 'manage_options', 'ous-debug', [self::class, 'render'], 'dashicons-admin-tools', 99);
        add_submenu_page('ous-debug', 'Debug Tools', 'Debug Tools', 'manage_options', 'ous-debug', [self::class, 'render']);
    }

    /* ---------------- shared UI helpers every registered plugin's render() can use ---------------- */

    // One consistent button/form for every plugin's debug actions —
    // same nonce, same admin-post action, same markup, so a person using
    // this page sees one coherent tool rather than a different look per
    // plugin. $extra_html can carry additional hidden/visible fields
    // (e.g. bh-contest's "count" input for how many fake submissions).
    public static function button($plugin_key, $action, $label, $extra_html = '', $confirm = '', $primary = true) {
        $nonce = wp_create_nonce('ous_debug_' . $plugin_key);
        $onclick = $confirm ? " onclick=\"return confirm('" . esc_js($confirm) . "')\"" : '';
        echo "<form method='post' action='" . esc_url(admin_url('admin-post.php')) . "' style='display:inline-block;margin:4px 8px 4px 0;'$onclick>";
        echo "<input type='hidden' name='action' value='ous_debug_action'>";
        echo "<input type='hidden' name='ous_plugin' value='" . esc_attr($plugin_key) . "'>";
        echo "<input type='hidden' name='ous_debug_action' value='" . esc_attr($action) . "'>";
        echo "<input type='hidden' name='_wpnonce' value='" . esc_attr($nonce) . "'>";
        echo $extra_html;
        echo "<button class='button" . ($primary ? ' button-primary' : ' button-secondary') . "'>" . esc_html($label) . "</button>";
        echo "</form>";
    }

    // Shared so bh-contest, bh-streaming, and anything registered later
    // don't each reimplement "create or reuse a tagged fake account" —
    // one copy, one consistent tagging convention (bhcore_is_test user
    // meta) so a single Reset Everything can find every plugin's fake
    // accounts regardless of which plugin created them.
    public static function get_or_create_test_user($tag, $reuse_odds = true) {
        $pool = get_users(['meta_key' => 'bhcore_is_test', 'meta_value' => $tag, 'fields' => 'ID', 'number' => 20]);
        if ($pool && $reuse_odds && wp_rand(0, 1)) return $pool[array_rand($pool)];

        $n = wp_rand(1000, 999999);
        $id = wp_insert_user([
            'user_login' => "test_{$tag}_{$n}",
            'user_email' => "test_{$tag}_{$n}@example.test",
            'user_pass'  => wp_generate_password(20),
            'role'       => 'subscriber',
        ]);
        if (is_wp_error($id)) return get_current_user_id(); // fallback, shouldn't happen
        update_user_meta($id, 'bhcore_is_test', $tag);
        return $id;
    }

    /* ---------------- the page ---------------- */

    public static function render() {
        $notice = isset($_GET['ous_msg']) ? sanitize_text_field(wp_unslash($_GET['ous_msg'])) : '';
        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown';

        BHY_UI::shell_open('Debug Tools');
        if (self::is_locked()) {
            echo '<div class="bhy-alert bhy-alert-danger"><strong>Locked.</strong> Detected environment: <code>' . esc_html($env) . '</code>. '
               . 'Seeding/reset actions are blocked because this looks like production. '
               . 'To unlock, add <code>define(\'OUS_DEBUG_TOOLS_FORCE\', true);</code> to wp-config.php, or set <code>WP_ENVIRONMENT_TYPE</code> to <code>local</code>/<code>development</code>/<code>staging</code>.</div>';
        } else {
            echo '<div class="bhy-alert bhy-alert-success"><strong>Unlocked.</strong> Detected environment: <code>' . esc_html($env) . '</code>. Safe to seed test data.</div>';
        }
        if ($notice) echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';

        // Jump links to the API Docs / Codebase Docs SECTIONS further
        // down this same page — the reliable path now (see each class's
        // own render_content()/render_debug_section() comments). The
        // standalone admin.php?page=ous-api-docs / ous-codebase-docs
        // pages are still registered but a live bug report showed
        // WordPress consistently blocking them for reasons this session
        // could not fully root-cause even with registration and
        // capability both confirmed correct — anchor links to the
        // in-page sections below sidestep that entirely rather than
        // waiting on a fix for the standalone pages.
        echo '<p>'
           . '<a class="button" href="#ous-section-api-docs">API Docs</a> '
           . '<a class="button" href="#ous-section-codebase-docs">Codebase Docs</a>'
           . '</p>';

        $tools = apply_filters('ous_debug_tools', []);
        if (!$tools) {
            echo '<p class="description">No plugin has registered any debug tools yet.</p>';
            BHY_UI::shell_close();
            return;
        }

        // Real, reported UX problem this closes: every button on this
        // page (Test Runner included) posts to admin-post.php and
        // redirects back to a bare admin.php?page=ous-debug — landing at
        // the very TOP of a page that can easily be a dozen-plus
        // registered sections long, forcing a manual scroll back down to
        // wherever the actual result lives every single time. The
        // sticky quick-nav below solves the "long scrolling" half of the
        // complaint (jump anywhere without a full scroll), and
        // redirect()'s new $anchor param (see handle() below) solves the
        // "jumps to page top" half directly — a button click now lands
        // you back exactly where you clicked from.
        // Real bug found after the first version of this fix shipped: a
        // native browser anchor-jump puts the TARGET's top edge at the
        // very top of the scrollport — which is exactly where the WP
        // admin bar (32px, position:fixed) and this quick-nav bar
        // (position:sticky) both already live. The section heading lands
        // directly BEHIND them, so the page visibly looks unchanged even
        // though a real scroll happened — indistinguishable from "still
        // stuck at the top" to the person looking at it, which is
        // exactly what got reported. scroll-margin-top tells the browser
        // to stop short of the raw anchor position by that much, which
        // is the direct, CSS-only fix for a sticky-header-covers-the-
        // anchor problem — no JS needed for browsers that support it
        // (all current-generation ones).
        // Grouped by the optional 'group' key (see GROUP_ORDER / group_tools()
        // above) — sections that never set 'group' land in GROUP_DEFAULT
        // automatically, so this grouping works immediately even for a
        // section registered before this pass existed and never updated.
        $grouped = self::group_tools($tools);

        echo '<style>
            .ous-debug-section, #ous-section-reset-all { scroll-margin-top: 90px; }
            .ous-debug-section > summary, #ous-section-reset-all > summary {
                cursor: pointer; font-weight: 600; font-size: 1.3em; list-style: none;
                display: flex; align-items: center; gap: 8px;
            }
            .ous-debug-section > summary::-webkit-details-marker, #ous-section-reset-all > summary::-webkit-details-marker { display: none; }
            .ous-debug-section > summary::before, #ous-section-reset-all > summary::before { content: "\25B6"; font-size: 0.7em; transition: transform 0.15s ease; }
            .ous-debug-section[open] > summary::before, #ous-section-reset-all[open] > summary::before { transform: rotate(90deg); }
            .ous-debug-section > .ous-debug-section-body, #ous-section-reset-all > .ous-debug-section-body { margin-top: 12px; }
            .ous-debug-group-heading {
                font-size: 1.05em; text-transform: uppercase; letter-spacing: 0.04em;
                color: #646970; margin: 28px 0 8px; padding-bottom: 6px;
                border-bottom: 1px solid #dcdcde;
            }
            .ous-debug-group-heading:first-of-type { margin-top: 4px; }
            .ous-debug-quicknav-group { display: flex; flex-wrap: wrap; gap: 4px 10px; align-items: baseline; }
            .ous-debug-quicknav-group > strong { color: #646970; font-size: 11px; text-transform: uppercase; letter-spacing: 0.03em; margin-right: 2px; }
        </style>';

        // Same sticky quicknav as before, just grouped now to match the
        // sections below it — each bucket gets its own inline label so
        // "Jump to" reads as a map of the page's actual structure, not
        // just a flat alphabet-soup of every section title in
        // registration order.
        echo '<div class="ous-debug-quicknav" style="position:sticky;top:32px;z-index:10;background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:8px 12px;margin-bottom:16px;display:flex;flex-direction:column;gap:6px;font-size:12px;">';
        foreach ($grouped as $group_label => $group_tools) {
            echo '<span class="ous-debug-quicknav-group"><strong>' . esc_html($group_label) . ':</strong>';
            foreach ($group_tools as $key => $tool) {
                echo ' <a href="#ous-section-' . esc_attr($key) . '">' . esc_html($tool['label']) . '</a>';
            }
            echo '</span>';
        }
        echo '<span class="ous-debug-quicknav-group"><a href="#ous-section-reset-all">Reset everything</a></span>';
        echo '</div>';

        // Collapsible per user request — every section starts CLOSED by
        // default (a page with a dozen-plus registered sections is a lot
        // to scroll through when you only care about one), and each
        // section's own open/closed state is remembered per-browser via
        // localStorage (see the script below) so it doesn't reset back
        // to closed on every page load. A real <details>/<summary> pair,
        // not a custom-JS toggle — free keyboard/accessibility support,
        // and if JS fails to load for any reason this still degrades to
        // "click to expand" working via native browser behavior alone.
        // Now looped per-group (see $grouped above) with a heading
        // between buckets instead of one flat list.
        foreach ($grouped as $group_label => $group_tools) {
            echo '<h2 class="ous-debug-group-heading">' . esc_html($group_label) . '</h2>';
            foreach ($group_tools as $key => $tool) {
                echo '<details class="bhy-card ous-debug-section" id="ous-section-' . esc_attr($key) . '">';
                echo '<summary>' . esc_html($tool['label']) . '</summary>';
                echo '<div class="ous-debug-section-body">';
                if (!empty($tool['render'])) call_user_func($tool['render'], $key);
                echo '</div>';
                echo '</details>';
            }
        }

        echo '<details class="bhy-card" id="ous-section-reset-all">';
        echo '<summary>Reset everything</summary>';
        echo '<div class="ous-debug-section-body"><p>Wipes every registered plugin\'s own tagged test data in one pass. Real data is untouched.</p>';
        self::button('__all__', 'reset_all', 'Wipe all test data (every plugin)', '', 'Delete ALL test data from every plugin? This cannot be undone.', false);
        echo '</div></details>';

        // Belt-and-suspenders on top of scroll-margin-top: explicitly
        // re-scroll to the hash target on load (covers any browser/
        // WP-admin-JS edge case that re-adjusts scroll after initial
        // paint) AND briefly highlights the section so landing "near"
        // the right place reads as unmistakably "here's your result,"
        // not just a subtle scroll position change easy to miss. Also
        // restores each section's remembered open/closed state, and
        // force-opens (+ remembers as open) whichever section a redirect
        // anchor points at — landing on a CLOSED section with your test
        // results hidden inside would defeat the whole point of the
        // anchor fix.
        echo '<script>
        (function () {
            var PREFIX = "ous_debug_section_open_";
            document.querySelectorAll("details.ous-debug-section, #ous-section-reset-all").forEach(function (d) {
                try {
                    if (localStorage.getItem(PREFIX + d.id) === "1") d.open = true;
                } catch (e) {}
                d.addEventListener("toggle", function () {
                    try { localStorage.setItem(PREFIX + d.id, d.open ? "1" : "0"); } catch (e) {}
                });
            });

            if (!window.location.hash) return;
            var target = document.querySelector(window.location.hash);
            if (!target) return;
            if (target.tagName === "DETAILS") {
                target.open = true;
                try { localStorage.setItem(PREFIX + target.id, "1"); } catch (e) {}
            }
            requestAnimationFrame(function () {
                target.scrollIntoView({ block: "start" });
                target.style.transition = "box-shadow 0.3s ease";
                target.style.boxShadow = "0 0 0 3px #2271b1";
                setTimeout(function () { target.style.boxShadow = ""; }, 1800);
            });
        })();
        </script>';
        BHY_UI::shell_close();
    }

    /* ---------------- dispatch ---------------- */

    public static function handle() {
        $plugin_key = sanitize_key($_POST['ous_plugin'] ?? '');
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'ous_debug_' . $plugin_key)) {
            wp_die('Not allowed.');
        }

        $action = sanitize_key($_POST['ous_debug_action'] ?? '');
        $tools = apply_filters('ous_debug_tools', []);

        // The production lock now applies PER SECTION, not to the whole
        // page: seeding fake test data or wiping real tables is exactly
        // what "this looks like production" should block, but Console &
        // Logs (clearing a log table) and the Test Runner (running pure
        // logic assertions) do nothing a live site needs protecting
        // from — a section opts out of the lock by setting
        // 'safe_in_production' => true on its own ous_debug_tools entry.
        // Every redirect below now carries an anchor back to the
        // specific section that produced it — see render()'s own
        // "Real, reported UX problem" comment for the full story. Using
        // the same 'ous-section-{key}' id render() already assigns each
        // section, so a button click lands you back at your own result,
        // not a scroll away from it.
        $anchor = ($plugin_key && $plugin_key !== '__all__') ? 'ous-section-' . $plugin_key : '';

        $safe = ($plugin_key !== '__all__') && !empty($tools[$plugin_key]['safe_in_production']);
        if (self::is_locked() && !$safe) {
            self::redirect('Blocked: this looks like a production environment. Add define(\'OUS_DEBUG_TOOLS_FORCE\', true) to wp-config.php to override.', $anchor);
        }

        if ($plugin_key === '__all__' && $action === 'reset_all') {
            $messages = [];
            foreach ($tools as $tool) {
                if (!empty($tool['reset'])) $messages[] = call_user_func($tool['reset']);
            }
            self::redirect($messages ? implode(' ', $messages) : 'Nothing to reset.', 'ous-section-reset-all');
        }

        if (!isset($tools[$plugin_key]) || empty($tools[$plugin_key]['handle'])) {
            self::redirect('Unknown debug action.', $anchor);
        }

        $msg = call_user_func($tools[$plugin_key]['handle'], $action, $_POST);
        $final_msg = $msg ?: 'Done.';

        // Additive toast supplement — the existing $_GET['ous_msg'] admin
        // notice (rendered in render() above) is completely unchanged;
        // this just ALSO surfaces the same message as a toast for anyone
        // who has JS enabled, on the very next page load. No way here to
        // distinguish success from failure (this shared dispatcher has
        // never carried a status flag, only a message string — see this
        // class's own docblock), so every result is queued as 'info'
        // rather than guessing.
        if (class_exists('OUS_Toast')) OUS_Toast::queue($final_msg, 'info');

        self::redirect($final_msg, $anchor);
    }

    private static function redirect($msg, $anchor = '') {
        $url = add_query_arg('ous_msg', rawurlencode($msg), admin_url('admin.php?page=ous-debug'));
        if ($anchor) $url .= '#' . $anchor;
        wp_safe_redirect($url);
        exit;
    }
}
