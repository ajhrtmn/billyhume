<?php
if (!defined('ABSPATH')) exit;

/**
 * Generates OpenAPI 3.0 from WordPress's live REST route table, limited to
 * ecosystem namespaces (ous/, bhi/, bh*). Served as
 * /wp-json/ous/v1/openapi.json and as a dependency-free Debug Tools viewer.
 */
class OUS_ApiDocs {
    const RELEVANT_PREFIXES = ['ous/', 'bhi/', 'bh'];

    public static function init(): void {
        // add_menu() is deliberately not hooked: WP's page-hook resolution
        // denies the standalone page despite correct registration.

        // add_action('rest_api_init', [self::class, 'register_routes']);
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
    }

    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register_debug_section($tools): array {
        $tools['api-docs'] = ['label' => 'API Docs', 'render' => [self::class, 'render_debug_section'], 'handle' => null, 'reset' => null, 'group' => OUS_Debug::GROUP_REFERENCE];
        return $tools;
    }

    // The primary access path: API docs inline on Debug Tools.
    public static function render_debug_section(): void {
        self::render_content();
    }


    public static function add_menu(): void {
        // $hook = add_submenu_page('ous-debug', 'API Docs', 'API Docs', 'manage_options', 'ous-api-docs', [self::class, 'render']);
        // if (class_exists('OUS_DebugLog')) {
        //     OUS_DebugLog::log_throttled('info', 'api_docs_registered', 60,
        //         'add_submenu_page() for API Docs returned: ' . ($hook === false ? 'FALSE (registration failed)' : "'$hook'"),
        //         ['hook_suffix' => $hook], 'API Docs'
        //     );
        // }
    }

    public static function register_routes(): void {
        register_rest_route('ous/v1', '/openapi.json', [
            'methods' => 'GET',
            'permission_callback' => '__return_true', // gated by is_locked() below; dev tooling, not user data
            'callback' => function () {
                // Route map is dev info; hidden in production unless OUS_DEBUG_TOOLS_FORCE.
                if (class_exists('OUS_Debug') && OUS_Debug::is_locked()) {
                    return new WP_Error('bhcore_api_docs_disabled', 'API docs are only available on non-production environments.', ['status' => 404]);
                }
                return new WP_REST_Response(self::generate_spec(), 200);
            },
        ]);
    }

    private static function is_relevant(string $namespace): bool {
        foreach (self::RELEVANT_PREFIXES as $prefix) {
            if (strpos($namespace, $prefix) === 0) return true;
        }
        return false;
    }

    /**
     * Reshapes the live route table into OpenAPI 3.0.
     *
     * @return array<string, mixed>
     */
    public static function generate_spec(): array {
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
                'title' => 'The Self-Hosted Self ecosystem API',
                'version' => defined('OUS_VER') ? OUS_VER : '1.0.0',
                'description' => 'Auto-generated from this site\'s live registered REST routes. Only ecosystem-owned namespaces (ous/, bhi/, bh*) are included.',
            ],
            'servers' => [['url' => rest_url()]],
            'paths' => $paths,
        ];
    }

    private static function route_namespace(string $route, \WP_REST_Server $server): string {
        // The route table has no namespace field; match against get_namespaces().
        foreach ($server->get_namespaces() as $ns) {
            if (strpos(ltrim($route, '/'), $ns) === 0) return $ns;
        }
        return '';
    }

    // (?P<id>...) placeholders become {id}; the validation regex is dropped.
    private static function to_openapi_path(string $route): string {
        return preg_replace('/\(\?P<([^>]+)>[^)]*\)/', '{$1}', $route);
    }

    /* ---------------- in-admin viewer (no external JS/CDN) ---------------- */

    public static function render(): void {
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('info', 'OUS_ApiDocs::render() was entered — the page callback is actually running.', [], 'API Docs');
        }
        BHY_UI::shell_open('API Docs');
        self::render_content();
        BHY_UI::shell_close();
    }

    // Shared by the standalone page and the Debug Tools section.
    private static function render_content(): void {
        $spec = self::generate_spec();

        echo '<p class="description" id="bhy-openapi-url-line">Generated live from this site\'s own registered REST routes — always in sync with the actual code, never hand-maintained separately. '
           . 'Raw OpenAPI 3.0 JSON (importable into Postman/Insomnia/Swagger UI elsewhere): '
           . '<code id="bhy-openapi-url">' . esc_html(rest_url('ous/v1/openapi.json')) . '</code> '
           . '<button type="button" class="button bhy-copy-btn" data-copy-target="#bhy-openapi-url">Copy</button></p>';

        if (!$spec['paths']) {
            echo '<p class="description">No ecosystem REST routes registered yet.</p>';
            return;
        }

        // WHY: post/put are darkened from WP core's stock colours so white
        // badge text meets 4.5:1.
        $method_colors = ['get' => '#2271b1', 'post' => '#007d14', 'put' => '#8e6c0f', 'delete' => '#d63638', 'patch' => '#8e6c0f'];

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
