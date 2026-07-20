<?php
if (!defined('ABSPATH')) exit;

// OUS_VER 3.4.25 — DESIGN-SUITE-UNIFICATION-PLAN.md Phase 1: add_menu()
// now registers 'bh-studio' as a submenu of the new 'bh-design' top-level
// menu (was 'own-ur-shit'), with capability 'bhcore_design_site' (was
// 'manage_options') — it's a "design the site" tool, not an activation
// tool, per the design doc's §1.1. Slug/callback/render logic unchanged.

// OUS_VER 3.4.19 — register_debug_section() now sets 'group' =>
// OUS_Debug::GROUP_REFERENCE (Debug Tools reorganization pass — see
// class-debug.php's own docblock). This section is read-only (lists
// registered block types), so it groups with API/Codebase Docs under
// "Reference & Docs" rather than the default bucket. No other change.

/**
 * BH_Studio — the visual authoring canvas ROADMAP-platform-evolution.md
 * Section 3 called the single highest-leverage foundational piece still
 * missing: iWeb-style direct manipulation, a GrapesJS/Storybook-style
 * layer tree + inspector, but NONE of iWeb's actual output model
 * (absolute-positioned div soup) — real semantic HTML, and one content
 * model shared with everything else (`BH_Content`) rather than a
 * second, incompatible one.
 *
 * DELIBERATELY NOT built on a vendored third-party canvas library
 * (GrapesJS was evaluated and dropped — customizing it proved unwieldy,
 * and this ecosystem's own no-external-runtime-dependency convention
 * plus no network access to vendor a large binary asset both point the
 * same direction). Instead this is built entirely on
 * `@wordpress/block-editor` and its sibling packages
 * (`@wordpress/blocks`, `@wordpress/element`, `@wordpress/components`,
 * `@wordpress/data`) — the SAME toolkit WordPress's own Site Editor and
 * the post editor are built from. Three real advantages this buys over
 * a vendored library:
 *
 *   1. Zero build step, zero vendoring, zero network dependency — these
 *      packages ship inside WordPress core itself. Enqueuing their
 *      existing script handles (wp-block-editor, wp-blocks, wp-element,
 *      wp-components, wp-data) is the entire "install," on every single
 *      WordPress site this ecosystem will ever run on.
 *   2. It's a REAL, actively-developed, deeply customizable page-
 *      building toolkit already — WordPress's own Site Editor, and a
 *      long list of real page-builder plugins, are built on these exact
 *      same packages, not a simplified subset of them. "Customized to
 *      work how I want it to, not stuck with the stock editor chrome"
 *      is exactly what BlockEditorProvider is FOR — it's the same
 *      primitive the Site Editor's own custom canvas/layout is built
 *      from, not the stock post-editor screen.
 *   3. Block types registered here work identically inside BH_Studio's
 *      own custom canvas (assets/js/studio.js) AND inside the normal
 *      Gutenberg post editor, for free — one registration, two surfaces
 *      — because both are the same underlying block-registration API.
 *
 * The custom block types below (bh/container, bh/heading, bh/text,
 * bh/image, bh/button) are the "working subset" this handoff scoped —
 * bh-courses' existing bhc/text, bhc/image, bhc/video, bhc/quiz types
 * are a natural next registrant via the exact same
 * register_block_type()/JS registerBlockType() pair, from bh-courses'
 * own bootstrap, once its lesson-step editor is ready to swap onto this
 * canvas — not done in this pass, deliberately, matching this
 * ecosystem's "one real working example, not every consumer at once"
 * convention.
 */
class BH_Studio {
    // Every block type this canvas ships with by default — each one
    // registered BOTH server-side (below, for render_block()/save
    // validation via BH_Content's own schema) and client-side (in
    // studio.js, for the editor UI) from this single source of truth.
    // Filterable so bh-courses/bh-monetization-woo/etc. can add their
    // own without touching this file — same zero-central-registration
    // shape as ous_debug_tools/ous_registered_plugins.
    public static function block_types() {
        return apply_filters('bh_studio_block_types', [
            'bh/container' => ['tag' => 'section', 'category' => 'layout', 'label' => 'Container'],
            'bh/heading'   => ['tag' => 'h2',      'category' => 'text',   'label' => 'Heading'],
            'bh/text'      => ['tag' => 'p',       'category' => 'text',   'label' => 'Text'],
            'bh/image'     => ['tag' => 'img',     'category' => 'media',  'label' => 'Image'],
            'bh/button'    => ['tag' => 'a',       'category' => 'layout', 'label' => 'Button'],
        ]);
    }

    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('admin_enqueue_scripts', [self::class, 'maybe_enqueue']);
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
        self::register_content_block_types();
    }

    /**
     * A registration/gating problem should be visible from Debug Tools,
     * not something that requires re-reading source to diagnose. Lists
     * every block type BH_Studio actually has registered with
     * BH_Content right now (both this file's own defaults and anything
     * another plugin added via the `bh_studio_block_types` filter, e.g.
     * bh-monetization-woo's bhm/product-grid), so "why doesn't my new
     * block type show up in the canvas" has a one-click answer.
     */
    public static function register_debug_section($tools) {
        $tools['bh-studio'] = [
            'label' => 'Content Studio',
            'render' => [self::class, 'render_debug_section'],
            'handle' => null,
            'reset' => null,
            'group' => OUS_Debug::GROUP_REFERENCE,
        ];
        return $tools;
    }

    public static function render_debug_section() {
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

    // Registers each default block type with BH_Content too (schema +
    // a plain server-side HTML renderer) so a document authored in
    // BH_Studio renders correctly through BH_Content::render() anywhere
    // else in the ecosystem that already calls it — the canvas is a new
    // AUTHORING surface, not a second content/rendering system.
    private static function register_content_block_types() {
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

    // Dev/authoring tooling, same manage_options-only posture as the
    // rest of this ecosystem's admin surfaces — a real front-end-facing
    // "let a supporter design their own profile page" flow is a later,
    // deliberately separate capability decision, not assumed here.
    public static function register_routes() {
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

    public static function rest_get(\WP_REST_Request $req) {
        if (!class_exists('BH_Content')) return new \WP_Error('bh_studio_no_content', 'BH_Content is unavailable.', ['status' => 500]);
        $tree = BH_Content::get($req->get_param('context_type'), (int) $req->get_param('context_id'));
        return new \WP_REST_Response(['tree' => $tree, 'block_types' => self::block_types()], 200);
    }

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

    // DESIGN-SUITE-UNIFICATION-PLAN.md unification pass — Content Studio
    // is no longer its own visible destination under 'bh-design'. Per the
    // explicit "there is no difference between the two" instruction, a
    // container element's nested content is now edited via a MODAL iframe
    // launched from inside BHY_Gallery's unified shell (see
    // class-element-builder.php's inspector / assets/js/element-builder.js's
    // openStudioModal()), not a page you navigate away to. The page itself
    // is intentionally kept REGISTERED (parent slug is null, WordPress's
    // documented pattern for a capability-gated admin page reachable by
    // direct URL/iframe but absent from every menu list) rather than
    // unregistered outright, because the modal iframe still needs a real,
    // capability-checked admin.php?page=bh-studio target to load — fully
    // unhooking add_menu() would 403 the iframe along with the menu item.
    // Slug/callback/capability are otherwise unchanged from before this pass.
    public static function add_menu() {
        $hook = add_submenu_page(null, 'Content Studio', 'Content Studio', 'bhcore_design_site', 'bh-studio', [self::class, 'render']);
        // Only the failure case is worth a log row — previously this
        // fired an INFO row for every successful registration too.
        if ($hook === false && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('error',
                'add_submenu_page() for Content Studio (bh-studio, hidden/null parent) FAILED (returned false).',
                [], 'BH_Studio::add_menu()'
            );
        }
    }

    public static function maybe_enqueue($hook) {
        if (strpos($hook, 'bh-studio') === false) return;

        // The exact package set the Site Editor itself depends on for
        // its own canvas — wp-editor is intentionally NOT included here,
        // since that handle pulls in the classic post-editor screen's
        // chrome (title field, publish box, permalink UI, etc.) that
        // this canvas has no use for; wp-block-editor is the lower-level
        // primitive both the post editor and the Site Editor build on.
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

    public static function render() {
        BHY_UI::shell_open('Content Studio', 'A direct-manipulation canvas over real BH_Content blocks — semantic output, no absolute-positioned div soup. Everything below the toolbar is rendered by studio.js.');
        echo '<div id="bh-studio-root" style="border:1px solid #dcdcde;background:#fff;min-height:600px;"></div>';
        BHY_UI::shell_close();
    }
}
