<?php
if (!defined('ABSPATH')) exit;


/**
 * BH_Studio: a visual authoring canvas built on @wordpress/block-editor and its
 * sibling packages (shipped in core, so nothing to vendor or build), storing
 * documents in BH_Content. Block types registered here work both in this
 * canvas (studio.js) and in the normal post editor.
 */
class BH_Studio {
    // Default block types, registered server-side (BH_Content schema/render) and
    // client-side (studio.js). Filterable via bh_studio_block_types.
    /** @return array<string, mixed> */
    public static function block_types(): array {
        return apply_filters('bh_studio_block_types', [
            'bh/container' => ['tag' => 'section', 'category' => 'layout', 'label' => 'Container'],
            'bh/heading'   => ['tag' => 'h2',      'category' => 'text',   'label' => 'Heading'],
            'bh/text'      => ['tag' => 'p',       'category' => 'text',   'label' => 'Text'],
            'bh/image'     => ['tag' => 'img',     'category' => 'media',  'label' => 'Image'],
            'bh/button'    => ['tag' => 'a',       'category' => 'layout', 'label' => 'Button'],
        ]);
    }

    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('admin_enqueue_scripts', [self::class, 'maybe_enqueue']);
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
        self::register_content_block_types();
    }

    /**
     * Debug Tools section listing every registered block type, including ones
     * added via the `bh_studio_block_types` filter.
     */
    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register_debug_section($tools): array {
        $tools['bh-studio'] = [
            'label' => 'Content Studio',
            'render' => [self::class, 'render_debug_section'],
            'handle' => null,
            'reset' => null,
            'group' => OUS_Debug::GROUP_REFERENCE,
        ];
        return $tools;
    }

    public static function render_debug_section(): void {
        echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=bh-studio')) . '">Open Content Studio</a></p>';
        echo '<h4>Registered block types</h4>';
        $studio_types = self::block_types();
        $content_types = class_exists('BH_Content') ? BH_Content::get_registered_types() : [];
        echo '<table class="widefat striped"><thead><tr><th>Type</th><th>Label</th><th>Canvas (bh_studio_block_types)</th><th>BH_Content renderer registered</th></tr></thead><tbody>';
        $all_types = array_unique(array_merge(array_keys($studio_types), $content_types));
        sort($all_types);
        foreach ($all_types as $type) {
            $in_studio = isset($studio_types[$type]);
            $in_content = in_array($type, $content_types, true);
            echo '<tr><td><code>' . esc_html($type) . '</code></td>';
            echo '<td>' . esc_html($studio_types[$type]['label'] ?? '&#8212;') . '</td>';
            echo '<td>' . ($in_studio ? '&#9989;' : '&#8212;') . '</td>';
            echo '<td>' . ($in_content ? '&#9989;' : '<span style="color:#d63638;">missing — will render as nothing, see BH_Content::render()</span>') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    // Registers each block type with BH_Content so studio documents render
    // through BH_Content::render() everywhere.
    private static function register_content_block_types(): void {
        if (!class_exists('BH_Content')) return;

        BH_Content::register_block_type('bh/container', [
            'className' => ['type' => 'string', 'default' => ''],
        ], function ($attrs, $children) {
            $class = $attrs['className'] ? ' class="' . esc_attr($attrs['className']) . '"' : '';
            return '<section' . $class . '>' . $children . '</section>';
        });

        BH_Content::register_block_type('bh/heading', [
            'content' => ['type' => 'html', 'default' => ''],
            'level'   => ['type' => 'int', 'default' => 2],
        ], function ($attrs) {
            $level = max(1, min(6, (int) $attrs['level']));
            return "<h$level>" . wp_kses_post($attrs['content']) . "</h$level>";
        });

        BH_Content::register_block_type('bh/text', [
            'content' => ['type' => 'html', 'default' => ''],
        ], function ($attrs) {
            return '<p>' . wp_kses_post($attrs['content']) . '</p>';
        });

        BH_Content::register_block_type('bh/image', [
            'url' => ['type' => 'url', 'default' => ''],
            'alt' => ['type' => 'string', 'default' => ''],
        ], function ($attrs) {
            if (!$attrs['url']) return '';
            return '<img src="' . esc_url($attrs['url']) . '" alt="' . esc_attr($attrs['alt']) . '" loading="lazy">';
        });

        BH_Content::register_block_type('bh/button', [
            'text' => ['type' => 'string', 'default' => ''],
            'url'  => ['type' => 'url', 'default' => ''],
        ], function ($attrs) {
            if (!$attrs['text']) return '';
            return '<a class="bh-button" href="' . esc_url($attrs['url']) . '">' . esc_html($attrs['text']) . '</a>';
        });
    }

    /* ---------------- REST bridge: BH_Content <-> the canvas ---------------- */

    // manage_options only.
    public static function register_routes(): void {
        register_rest_route('ous/v1', '/studio/(?P<context_type>[\w-]+)/(?P<context_id>\d+)', [
            [
                'methods' => 'GET',
                'permission_callback' => function () { return current_user_can('manage_options'); },
                'callback' => [self::class, 'rest_get'],
            ],
            [
                'methods' => 'POST',
                'permission_callback' => function () { return current_user_can('manage_options'); },
                'callback' => [self::class, 'rest_save'],
            ],
        ]);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function rest_get(\WP_REST_Request $req) {
        if (!class_exists('BH_Content')) return new \WP_Error('bh_studio_no_content', 'BH_Content is unavailable.', ['status' => 500]);
        $tree = BH_Content::get($req->get_param('context_type'), (int) $req->get_param('context_id'));
        return new \WP_REST_Response(['tree' => $tree, 'block_types' => self::block_types()], 200);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function rest_save(\WP_REST_Request $req) {
        if (!class_exists('BH_Content')) return new \WP_Error('bh_studio_no_content', 'BH_Content is unavailable.', ['status' => 500]);
        $body = json_decode($req->get_body(), true);
        $tree = is_array($body['tree'] ?? null) ? $body['tree'] : [];
        $saved = BH_Content::save($req->get_param('context_type'), (int) $req->get_param('context_id'), $tree);
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('info', 'BH_Studio document saved', [
                'context_type' => $req->get_param('context_type'),
                'context_id' => $req->get_param('context_id'),
                'block_count' => count($saved),
            ], 'BH_Studio');
        }
        return new \WP_REST_Response(['tree' => $saved], 200);
    }

    /* ---------------- admin page ---------------- */

    // WHY: kept registered with a null parent (hidden, direct-URL only) because
    // the modal iframe that edits container content still loads admin.php?page=bh-studio.
    public static function add_menu(): void {
        $hook = add_submenu_page(null, 'Content Studio', 'Content Studio', 'bhcore_design_site', 'bh-studio', [self::class, 'render']);
        // Only the failure case is logged.
        if ($hook === false && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('error',
                'add_submenu_page() for Content Studio (bh-studio, hidden/null parent) FAILED (returned false).',
                [], 'BH_Studio::add_menu()'
            );
        }
    }

    public static function maybe_enqueue(string $hook): void {
        if (strpos($hook, 'bh-studio') === false) return;

        // The Site Editor's canvas packages. Not wp-editor, which pulls in the
        // classic post-editor chrome.
        $deps = ['wp-block-editor', 'wp-blocks', 'wp-element', 'wp-components', 'wp-data', 'wp-block-library', 'wp-api-fetch', 'wp-i18n'];
        foreach ($deps as $dep) wp_enqueue_script($dep);
        wp_enqueue_style('wp-block-editor');
        wp_enqueue_style('wp-block-library');
        wp_enqueue_style('wp-components');
        wp_enqueue_style('wp-edit-blocks');

        wp_enqueue_script('bh-studio', OUS_URL . 'assets/js/studio.js', $deps, OUS_VER, true);
        wp_enqueue_style('bh-studio', OUS_URL . 'assets/css/studio.css', ['wp-components'], OUS_VER);

        wp_localize_script('bh-studio', 'bhStudioConfig', [
            'restUrl' => esc_url_raw(rest_url('ous/v1/studio/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'contextType' => sanitize_key($_GET['context_type'] ?? 'bh_studio_demo'),
            'contextId' => absint($_GET['context_id'] ?? 1),
            'blockTypes' => self::block_types(),
        ]);
    }

    public static function render(): void {
        BHY_UI::shell_open('Content Studio', 'A direct-manipulation canvas over real BH_Content blocks — semantic output, no absolute-positioned div soup. Everything below the toolbar is rendered by studio.js.');
        echo '<div id="bh-studio-root" style="border:1px solid #dcdcde;background:#fff;min-height:600px;"></div>';
        BHY_UI::shell_close();
    }
}
