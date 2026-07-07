<?php
if (!defined('ABSPATH')) exit;

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
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('rest_api_init', [self::class, 'register_routes']);
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
        if (class_exists('OUS_Debug') && OUS_Debug::is_locked()) {
            // Previously a silent return — a site owner clicking a
            // stale/bookmarked link to this page while it's gated had no
            // way to tell "this page doesn't exist right now" apart from
            // "something is actually broken." Logging it here means the
            // very bug report that triggered this fix (a 404 with no
            // obvious cause) shows up in Console & Logs next time, one
            // click away, instead of requiring a re-read of this file.
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('info', 'API Docs admin menu not registered — OUS_Debug::is_locked() returned true for this request (environment detected as production, or a non-local host).', [
                    'host' => wp_parse_url(home_url(), PHP_URL_HOST),
                    'environment_type' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : '(unknown — function unavailable)',
                ], 'API Docs');
            }
            return;
        }
        // Lives under the dedicated "OUS Debug" top-level menu (see
        // class-debug.php) alongside Debug Tools — both are dev/debug
        // tooling for the same audience, not part of the main "Own Ur
        // Shit" hub's own submenu. WordPress doesn't require the parent's
        // own add_menu_page() call to have run first; both just need to
        // fire during the same admin_menu hook, which they do.
        //
        // NOTE on the "wp-admin/ous-api-docs 404" bug report this fix
        // responds to: this registration is correct as written — a
        // submenu registered this way is always rendered by WordPress
        // core as admin.php?page=ous-api-docs, never as a bare
        // /wp-admin/ous-api-docs path. No code anywhere in this ecosystem
        // was found (across all seven plugins) that constructs that bare
        // URL. The two realistic causes on a real install: (1) this
        // is_locked() gate above was silently true for that request —
        // now logged, see above — or (2) a stale browser-cached menu /
        // stale bookmark from before this page existed at all. If Console
        // & Logs shows no matching entry after reproducing the 404, the
        // cause is almost certainly (2): hard-refresh the wp-admin
        // sidebar or re-navigate from the OUS Debug menu itself rather
        // than a saved link.
        add_submenu_page('ous-debug', 'API Docs', 'API Docs', 'manage_options', 'ous-api-docs', [self::class, 'render']);
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
        $spec = self::generate_spec();
        BHY_UI::shell_open('API Docs');

        echo '<p class="description" id="bhy-openapi-url-line">Generated live from this site\'s own registered REST routes — always in sync with the actual code, never hand-maintained separately. '
           . 'Raw OpenAPI 3.0 JSON (importable into Postman/Insomnia/Swagger UI elsewhere): '
           . '<code id="bhy-openapi-url">' . esc_html(rest_url('ous/v1/openapi.json')) . '</code> '
           . '<button type="button" class="button bhy-copy-btn" data-copy-target="#bhy-openapi-url">Copy</button></p>';

        if (!$spec['paths']) {
            echo '<p class="description">No ecosystem REST routes registered yet.</p>';
            BHY_UI::shell_close();
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
        BHY_UI::shell_close();
    }
}
