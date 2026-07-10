<?php
if (!defined('ABSPATH')) exit;

// OUS_VER 3.4.19 — register_debug_section() now sets 'group' =>
// OUS_Debug::GROUP_REFERENCE (Debug Tools reorganization pass — see
// class-debug.php's own docblock), filing this under "Reference &
// Docs" instead of the default bucket. No other change.

/**
 * Turns the REST routes this ecosystem already registers (BHI_Auth,
 * BHI_Reports, and anything any peer plugin registers via its own
 * register_rest_route() calls) into real, standard OpenAPI 3.0 —
 * generated from WordPress's own live route table
 * (rest_get_server()->get_routes()), not hand-maintained separately
 * and inevitably drifting from the actual code. Two ways to use it:
 *
 *   1. GET /wp-json/ous/v1/openapi.json — the raw spec, importable into
 *      Postman, Insomnia, real Swagger UI, or any other OpenAPI tool a
 *      dev already has.
 *   2. Own Ur Shit → API Docs — a plain, dependency-free in-admin
 *      viewer of the same spec (no Swagger-UI CDN pulled in, matching
 *      this ecosystem's "no build step, no external runtime
 *      dependency" convention — see OUS_Notifications' admin-bar
 *      handle fix for the same instinct applied elsewhere).
 *
 * Only routes under THIS ecosystem's own namespaces are included by
 * default (ous, bhi, bh-prefixed namespaces) — WordPress core's own huge
 * built-in route table (wp/v2/posts, etc.) would otherwise drown out the
 * actual ecosystem surface in noise nobody asked this page to document.
 */
class OUS_ApiDocs {
    const RELEVANT_PREFIXES = ['ous/', 'bhi/', 'bh'];

    public static function init() {
        // add_menu() (standalone admin.php?page=ous-api-docs page) is
        // deliberately NOT hooked anymore — confirmed via Query Monitor
        // on the live install that WordPress's own page-hook resolution
        // fails for this specific standalone page (get_current_screen()
        // resolves to the PARENT page's hook, not this one), denying
        // access every time despite registration and capability both
        // being correct. See VISION.md's "New dev/admin-only pages
        // default to a Debug Tools SECTION" entry for the full incident.
        // The real, working access point is register_debug_section()
        // below — a dead, always-broken link in the sidebar was worse
        // than no standalone page at all. add_menu() itself is left
        // defined (not deleted) in case a future session gets a real fix
        // for the underlying WordPress issue and wants to re-enable it.
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
    }

    // A real report this pass: API Docs 404ing with no OUS_DebugLog entry
    // explaining why — meaning is_locked() was returning false (menu WAS
    // registered) yet the page still wasn't reachable. This section shows
    // the is_locked() verdict AND whether the submenu is actually present
    // in the live $submenu global for THIS request, so "registered but
    // still 404s" (a routing/caching problem, same family as BHI_Portal's
    // rewrite-rule issue) is distinguishable from "never registered at
    // all" (an is_locked() problem) without needing to click through to
    // the 404 first.
    public static function register_debug_section($tools) {
        $tools['api-docs'] = ['label' => 'API Docs', 'render' => [self::class, 'render_debug_section'], 'handle' => null, 'reset' => null, 'group' => OUS_Debug::GROUP_REFERENCE];
        return $tools;
    }

    // Now the reliable PRIMARY way to reach this content — renders the
    // real API docs inline, on the one page this whole session has
    // proven never fails to load. The standalone admin.php?page=ous-api-docs
    // page is left registered as a secondary/bonus access point (see
    // add_menu() below), but a live bug report showed WordPress
    // consistently blocking that standalone page for reasons this
    // session could not fully root-cause even with registration and
    // capability both confirmed correct — so this section, not that
    // page, is what to actually use and link to from here on.
    public static function render_debug_section() {
        self::render_content();
    }

    // Dev/reference tooling, not something a site owner needs on a live
    // production install — same production lock OUS_Debug's own menu
    // uses (wp_get_environment_type()-gated, overridable via
    // OUS_DEBUG_TOOLS_FORCE in wp-config.php for a live site that
    // genuinely needs to inspect its own API surface). A generated API
    // reference is a build-time/dev-time artifact, not a fan/customer-
    // facing feature — no reason for it to clutter a real site's admin
    // menu, or to be reachable at all once "which routes exist" isn't a
    // question anyone actively developing against this install is asking.
    public static function add_menu() {
        // REMOVED the is_locked() gate that used to wrap this call — API
        // Docs and Codebase Docs were the only two pages in the whole
        // ecosystem that conditionally skipped their own
        // add_submenu_page() call before registering. Every other admin
        // page in this ecosystem (Debug Tools itself, Job Queue, every
        // peer plugin's own screens) registers unconditionally;
        // is_locked() was designed to gate DESTRUCTIVE seed/reset
        // actions, not a read-only viewer page's mere existence in the
        // menu. Real reported symptom: this page consistently denied
        // access ("Sorry, you are not allowed to access this page") even
        // on requests where logging proved registration had already
        // succeeded (a real hook_suffix returned) and
        // current_user_can('manage_options') was TRUE — meaning the
        // CONDITIONAL registration path itself, not is_locked()'s
        // internal logic, was the actual problem. Registering
        // unconditionally like every other working page removes that
        // asymmetry.
        $hook = add_submenu_page('ous-debug', 'API Docs', 'API Docs', 'manage_options', 'ous-api-docs', [self::class, 'render']);
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log_throttled('info', 'api_docs_registered', 60,
                'add_submenu_page() for API Docs returned: ' . ($hook === false ? 'FALSE (registration failed)' : "'$hook'"),
                ['hook_suffix' => $hook], 'API Docs'
            );
        }
    }

    public static function register_routes() {
        register_rest_route('ous/v1', '/openapi.json', [
            'methods' => 'GET',
            'permission_callback' => '__return_true', // gated below by is_locked(), not by an auth check — same "dev tooling, not user data" reasoning as the admin menu above
            'callback' => function () {
                // Same production lock as the admin viewer — a live
                // site's route map is dev/reference information, not
                // something worth exposing to the open internet by
                // default just because the admin viewer happens to be
                // hidden. Overridable the same way (OUS_DEBUG_TOOLS_FORCE).
                if (class_exists('OUS_Debug') && OUS_Debug::is_locked()) {
                    return new WP_Error('bhcore_api_docs_disabled', 'API docs are only available on non-production environments.', ['status' => 404]);
                }
                return new WP_REST_Response(self::generate_spec(), 200);
            },
        ]);
    }

    private static function is_relevant($namespace) {
        foreach (self::RELEVANT_PREFIXES as $prefix) {
            if (strpos($namespace, $prefix) === 0) return true;
        }
        return false;
    }

    /**
     * Reads WordPress's own live, already-registered route table and
     * reshapes it into OpenAPI 3.0 — every register_rest_route() call
     * anywhere in the ecosystem (present or future) shows up here
     * automatically, with zero separate documentation-maintenance step.
     */
    public static function generate_spec() {
        $server = rest_get_server();
        $routes = $server->get_routes();
        $paths = [];

        foreach ($routes as $route => $handlers) {
            $namespace = self::route_namespace($route, $server);
            if (!self::is_relevant($namespace)) continue;

            $path_item = [];
            foreach ($handlers as $handler) {
                $methods = is_array($handler['methods'] ?? null) ? array_keys(array_filter($handler['methods'])) : [];
                $args = $handler['args'] ?? [];

                $parameters = [];
                foreach ($args as $name => $arg) {
                    if (!is_array($arg)) continue;
                    $parameters[] = [
                        'name' => $name,
                        'in' => (strpos($route, '(?P<' . $name . '>') !== false) ? 'path' : 'query',
                        'required' => !empty($arg['required']),
                        'description' => $arg['description'] ?? '',
                        'schema' => ['type' => is_string($arg['type'] ?? null) ? $arg['type'] : 'string'],
                    ];
                }

                foreach ($methods as $method) {
                    $path_item[strtolower($method)] = [
                        'summary' => $namespace . ' route',
                        'parameters' => $parameters,
                        'responses' => ['200' => ['description' => 'Success'], '4XX' => ['description' => 'Error — see message field of the response body']],
                    ];
                }
            }
            if ($path_item) $paths[self::to_openapi_path($route)] = $path_item;
        }

        return [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Own Ur Shit ecosystem API',
                'version' => defined('OUS_VER') ? OUS_VER : '1.0.0',
                'description' => 'Auto-generated from this site\'s live registered REST routes. Only ecosystem-owned namespaces (ous/, bhi/, bh*) are included.',
            ],
            'servers' => [['url' => rest_url()]],
            'paths' => $paths,
        ];
    }

    private static function route_namespace($route, $server) {
        // WordPress doesn't expose a route's namespace directly on the
        // route table itself — it's derivable from the registered
        // namespace index, which get_namespaces() does expose.
        foreach ($server->get_namespaces() as $ns) {
            if (strpos(ltrim($route, '/'), $ns) === 0) return $ns;
        }
        return '';
    }

    // WP's (?P<id>[\d]+) regex placeholders become OpenAPI's {id} —
    // good enough for a docs viewer's readability even though it drops
    // the actual validation regex (the "parameters" schema type above
    // is where real type info still lives).
    private static function to_openapi_path($route) {
        return preg_replace('/\(\?P<([^>]+)>[^)]*\)/', '{$1}', $route);
    }

    /* ---------------- in-admin viewer (no external JS/CDN) ---------------- */

    public static function render() {
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('info', 'OUS_ApiDocs::render() was entered — the page callback is actually running.', [], 'API Docs');
        }
        BHY_UI::shell_open('API Docs');
        self::render_content();
        BHY_UI::shell_close();
    }

    // The actual body content, shared by both the standalone page
    // (render()) and the Debug Tools section (render_debug_section(),
    // now the reliable primary path — see that method's own comment).
    private static function render_content() {
        $spec = self::generate_spec();

        echo '<p class="description" id="bhy-openapi-url-line">Generated live from this site\'s own registered REST routes — always in sync with the actual code, never hand-maintained separately. '
           . 'Raw OpenAPI 3.0 JSON (importable into Postman/Insomnia/Swagger UI elsewhere): '
           . '<code id="bhy-openapi-url">' . esc_html(rest_url('ous/v1/openapi.json')) . '</code> '
           . '<button type="button" class="button bhy-copy-btn" data-copy-target="#bhy-openapi-url">Copy</button></p>';

        if (!$spec['paths']) {
            echo '<p class="description">No ecosystem REST routes registered yet.</p>';
            return;
        }

        $method_colors = ['get' => '#2271b1', 'post' => '#00a32a', 'put' => '#dba617', 'delete' => '#d63638', 'patch' => '#dba617'];

        $route_i = 0;
        foreach ($spec['paths'] as $path => $methods) {
            echo '<div class="bhy-card">';
            echo '<h3><code>' . esc_html($path) . '</code></h3>';
            foreach ($methods as $method => $op) {
                $route_i++;
                $color = $method_colors[$method] ?? '#646970';
                $full_url = rest_url(ltrim(preg_replace('/\{[^}]+\}/', '1', $path), '/'));
                $url_id = 'bhy-route-url-' . $route_i;
                echo '<div style="margin:8px 0;padding:8px;border-left:3px solid ' . esc_attr($color) . ';">';
                echo '<span style="color:#fff;background:' . esc_attr($color) . ';padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;">' . esc_html(strtoupper($method)) . '</span> ';
                echo '<code>' . esc_html($path) . '</code> ';
                echo '<span id="' . esc_attr($url_id) . '" style="display:none;">' . esc_html($full_url) . '</span>';
                echo '<button type="button" class="button button-small bhy-copy-btn" data-copy-target="#' . esc_attr($url_id) . '" title="Copy full URL (placeholder values filled with 1)">Copy URL</button>';
                if ($op['parameters']) {
                    echo '<div class="bhy-table-wrap" style="margin-top:6px;"><table class="widefat"><thead><tr><th>Param</th><th>In</th><th>Required</th><th>Type</th><th>Description</th></tr></thead><tbody>';
                    foreach ($op['parameters'] as $p) {
                        echo '<tr><td><code>' . esc_html($p['name']) . '</code></td><td>' . esc_html($p['in']) . '</td><td>' . ($p['required'] ? 'Yes' : 'No') . '</td><td>' . esc_html($p['schema']['type']) . '</td><td>' . esc_html($p['description']) . '</td></tr>';
                    }
                    echo '</tbody></table></div>';
                } else {
                    echo '<p class="description" style="margin:6px 0 0;">No parameters.</p>';
                }
                echo '</div>';
            }
            echo '</div>';
        }
    }
}
