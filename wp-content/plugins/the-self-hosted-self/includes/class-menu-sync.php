<?php
if (!defined('ABSPATH') ) exit;

/**
 * Keeps a plugin's own submenu group (e.g. "Contests") in sync inside the site's nav menus,
 * for both classic nav_menu items (tagged via postmeta) and block-theme wp_navigation posts.
 * Manually added items are never touched.
 */
class OUS_MenuSync {
    const NAV_POST_TYPE = 'wp_navigation';
    const CLASSIC_GROUP_META_KEY = '_ous_menu_sync_group';
    const ACCOUNT_LINK_META_KEY = '_ous_menu_sync_account_link';

    /** The theme directory before the rename. */
    private const LEGACY_STYLESHEET = 'own-ur-shit-theme';

    public static function init(): void {
        add_filter('wp_nav_menu_objects', [self::class, 'localize_account_link'], 10, 1);
        add_action('admin_init', [self::class, 'migrate_legacy_theme_mods']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_cta_style']);

        // Standalone surface: an ecosystem page where the theme's nav/footer are hidden and a slim
        // "back to <site>" bar stands in. Peers opt in via bh_standalone_surface.
        add_filter('bh_standalone_surface', [self::class, 'portal_is_standalone']);
        add_filter('body_class', [self::class, 'mark_standalone_body']);
        add_action('wp_body_open', [self::class, 'render_eco_nav']);
    }

    public static function portal_is_standalone($is): bool {
        if ($is) return true;
        return class_exists('BHI_Portal') && BHI_Portal::is_portal_context();
    }

    /** @param string[] $classes @return string[] */
    public static function mark_standalone_body($classes): array {
        if (!is_admin() && apply_filters('bh_standalone_surface', false)) {
            $classes[] = 'bh-standalone';
        }
        return $classes;
    }

    // The ecosystem's slim nav bar at the top of <body> on standalone surfaces: a back link plus
    // Courses / Contests / Account. bh_eco_nav_items lets peers add/reorder/remove.
    public static function render_eco_nav(): void {
        if (is_admin() || !apply_filters('bh_standalone_surface', false)) return;

        $current = (int) get_queried_object_id();
        $known = [
            'Courses'  => (int) get_option('bhc_catalog_page_id', 0),
            'Contests' => (int) get_option('bh_contest_library_page_id', 0),
            'Support'  => (int) get_option('bhm_tiers_page_id', 0),
            'Account'  => class_exists('BHI_Portal') ? (int) get_option('bhi_portal_page_id', 0) : 0,
        ];
        $items = [];
        foreach ($known as $label => $pid) {
            if ($pid && get_post_status($pid) === 'publish') {
                $items[] = ['label' => $label, 'url' => (string) get_permalink($pid), 'id' => $pid, 'match' => $label];
            }
        }
        /**
         * @param array<int, array{label:string,url:string,id:int,match:string}> $items
         * @param int $current queried object id
         */
        $items = apply_filters('bh_eco_nav_items', $items, $current);

        // Active section for a page that isn't a landing page itself (lesson -> Courses, etc).
        $active_match = '';
        if (is_singular(['bh_course', 'bh_lesson'])) $active_match = 'Courses';
        elseif (is_singular('bh_contest')) $active_match = 'Contests';
        elseif (class_exists('BHI_Portal') && BHI_Portal::is_portal_context()) $active_match = 'Account';

        echo '<nav class="bh-eco-nav" aria-label="' . esc_attr__('Ecosystem', 'the-self-hosted-self') . '">';
        echo '<div class="bh-eco-nav-inner">';
        echo '<a class="bh-eco-nav-home" href="' . esc_url(home_url('/')) . '" rel="home">'
            . '<span class="bh-eco-nav-arrow" aria-hidden="true">&larr;</span> ' . esc_html(get_bloginfo('name')) . '</a>';
        if ($items) {
            echo '<span class="bh-eco-nav-links">';
            foreach ($items as $it) {
                $active = ((int) ($it['id'] ?? 0) === $current) || (($it['match'] ?? '') !== '' && $it['match'] === $active_match);
                echo '<a class="bh-eco-nav-link' . ($active ? ' is-active' : '') . '" href="' . esc_url($it['url']) . '">'
                    . esc_html($it['label']) . '</a>';
            }
            echo '</span>';
        }
        echo '</div></nav>';
    }

    /**
     * Front-end only, where a nav menu can appear. Ships with the plugin because this host
     * deploys plugins but not themes.
     */
    public static function enqueue_cta_style(): void {
        if (is_admin()) return;
        wp_enqueue_style('ous-front-nav', OUS_URL . 'assets/css/front-nav.css', [], OUS_VER);

        // Registered, not enqueued: peers call wp_enqueue_style('ous-catalog') where a catalog renders.
        wp_register_style('ous-catalog', OUS_URL . 'assets/css/catalog.css', [], OUS_VER);
    }

    /**
     * Carries theme mods across the theme directory rename.
     *
     * WHY: theme mods are keyed by stylesheet directory, so a rename orphans nav_menu_locations and
     * every ecosystem nav link disappears. Lives in core because a renamed theme can't fix itself.
     */
    public static function migrate_legacy_theme_mods(): void {
        if (get_option('ous_theme_mods_migrated')) return;

        $current = get_stylesheet();
        if ($current === self::LEGACY_STYLESHEET) return;

        $legacy = get_option('theme_mods_' . self::LEGACY_STYLESHEET);
        if (!is_array($legacy) || empty($legacy['nav_menu_locations'])) return;

        $mods = get_option('theme_mods_' . $current);
        if (!is_array($mods)) $mods = [];

        // Only fill a gap. An assignment someone made on the new theme wins.
        if (empty($mods['nav_menu_locations'])) {
            $mods['nav_menu_locations'] = $legacy['nav_menu_locations'];
            update_option('theme_mods_' . $current, $mods);

            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('info', 'Carried nav menu locations across the theme rename.', [
                    'from' => self::LEGACY_STYLESHEET,
                    'to'   => $current,
                ], 'OUS_MenuSync');
            }
        }

        update_option('ous_theme_mods_migrated', 1);
    }

    /**
     * Rewrites the seeded Account item (tagged ACCOUNT_LINK_META_KEY) per request: "Log In" for
     * visitors, "Go to Portal" for logged-in members. Runs on every classic wp_nav_menu() render.
     *
     * @param array<int, \WP_Post> $items
     * @return array<int, \WP_Post>
     */
    public static function localize_account_link(array $items): array {
        if (!class_exists('BHI_Portal')) return $items;
        $portal_url = home_url('/' . BHI_Portal::REWRITE_SLUG . '/');
        $logged_in = is_user_logged_in();
        $account = [];
        $rest    = [];
        foreach ($items as $item) {
            if (get_post_meta($item->ID, self::ACCOUNT_LINK_META_KEY, true) !== '1') {
                $rest[] = $item;
                continue;
            }
            // title/url are declared for PHPStan in phpstan-stubs/wp-post-nav-menu-item.stub.php.
            $item->title = $logged_in ? __('Go to Portal', 'the-self-hosted-self') : __('Log In', 'the-self-hosted-self');
            $item->url = $portal_url;
            // The menu's call to action, styled by assets/css/menu-cta.css (theme-agnostic class).
            $item->classes = array_merge($item->classes, ['ous-menu-account-cta']);
            $account[] = $item;
        }
        // Always last. Safe to append: this item is top-level and children always follow their parent.
        return array_merge($rest, $account);
    }

    /**
     * Rebuilds ONE named submenu group in every nav menu (classic and block), leaving other items
     * untouched. $items is the final ordered list [['label' => ..., 'url' => ...], ...]; an empty
     * array removes the group. $group_url is the parent item's own link.
     *
     * @param array<int, array<string, mixed>> $items
     */
    public static function sync_group(string $group_key, string $label, array $items, string $group_url = '#'): void {
        self::sync_classic_menus($group_key, $label, $items, $group_url);
        self::sync_block_navigations($group_key, $label, $items, $group_url);
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function sync_classic_menus(string $group_key, string $label, array $items, string $group_url = '#'): void {
        $menus = wp_get_nav_menus();
        if (!$menus) {
            // WHY: a site that never visited Appearance > Menus has zero menus, and the theme fallback
            // hides that; create one and assign it to empty locations so there's always something to sync into.
            $created_id = self::ensure_default_menu_exists();
            if (!$created_id) return;
            $menus = wp_get_nav_menus();
            if (!$menus) return;
        }

        foreach ($menus as $menu) {
            $menu_id = $menu->term_id;
            $existing = wp_get_nav_menu_items($menu_id);
            if ($existing === false) $existing = [];

            // Find this group's previously synced parent + children by postmeta tag, then drop and re-add.
            $own_ids = [];
            foreach ($existing as $menu_item) {
                if (get_post_meta($menu_item->ID, self::CLASSIC_GROUP_META_KEY, true) === $group_key) {
                    $own_ids[] = (int) $menu_item->ID;
                }
            }
            foreach ($own_ids as $id) {
                wp_delete_post($id, true);
            }

            if (!$items) continue; // nothing to add: group removed entirely

            $parent_id = wp_update_nav_menu_item($menu_id, 0, [
                'menu-item-title'  => $label,
                'menu-item-url'    => $group_url,
                'menu-item-status' => 'publish',
                'menu-item-type'   => 'custom',
            ]);
            if (is_wp_error($parent_id) || !$parent_id) continue;
            update_post_meta($parent_id, self::CLASSIC_GROUP_META_KEY, $group_key);

            foreach ($items as $item) {
                $child_id = wp_update_nav_menu_item($menu_id, 0, [
                    'menu-item-title'     => $item['label'],
                    'menu-item-url'       => $item['url'],
                    'menu-item-status'    => 'publish',
                    'menu-item-type'      => 'custom',
                    'menu-item-parent-id' => $parent_id,
                ]);
                if (!is_wp_error($child_id) && $child_id) {
                    update_post_meta($child_id, self::CLASSIC_GROUP_META_KEY, $group_key);
                }
            }
        }
    }

    // Creates "Primary Menu" and assigns it to every empty registered location (never overwrites).
    // Returns the term_id, or 0 if it already existed or creation failed.
    private static function ensure_default_menu_exists(): int {
        $existing = wp_get_nav_menu_object('Primary Menu');
        $menu_id = $existing ? $existing->term_id : wp_create_nav_menu('Primary Menu');
        if (is_wp_error($menu_id) || !$menu_id) return 0;

        // WHY: assigning an empty menu kills the theme's fallback links, so a brand-new menu is seeded
        // with them as editable items. Never seed a menu that already existed.
        if (!$existing) self::seed_default_menu_content((int) $menu_id);

        $locations = get_nav_menu_locations();
        foreach (array_keys(get_registered_nav_menus()) as $location) {
            if (empty($locations[$location])) {
                $locations[$location] = $menu_id;
            }
        }
        set_theme_mod('nav_menu_locations', $locations);

        return (int) $menu_id;
    }

    // Seeds "Home", up to 6 published pages (same query as the theme fallback), and an Account/Log In
    // link when the portal is installed.
    private static function seed_default_menu_content(int $menu_id): void {
        wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title'  => __('Home', 'the-self-hosted-self'),
            'menu-item-url'    => home_url('/'),
            'menu-item-status' => 'publish',
            'menu-item-type'   => 'custom',
        ]);

        $pages = get_pages(['sort_column' => 'menu_order', 'number' => 6]);
        foreach ($pages as $page) {
            wp_update_nav_menu_item($menu_id, 0, [
                'menu-item-title'     => $page->post_title,
                'menu-item-object'    => 'page',
                'menu-item-object-id' => $page->ID,
                'menu-item-type'      => 'post_type',
                'menu-item-status'    => 'publish',
            ]);
        }

        if (class_exists('BHI_Portal')) {
            $account_item_id = wp_update_nav_menu_item($menu_id, 0, [
                'menu-item-title'  => __('Log In', 'the-self-hosted-self'),
                'menu-item-url'    => home_url('/' . BHI_Portal::REWRITE_SLUG . '/'),
                'menu-item-status' => 'publish',
                'menu-item-type'   => 'custom',
            ]);
            // Tagged so localize_account_link() can rewrite it per request.
            if (!is_wp_error($account_item_id) && $account_item_id) {
                update_post_meta($account_item_id, self::ACCOUNT_LINK_META_KEY, '1');
            }
        }
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function sync_block_navigations(string $group_key, string $label, array $items, string $group_url = '#'): void {
        $navs = get_posts([
            'post_type'      => self::NAV_POST_TYPE,
            'post_status'    => 'any',
            'numberposts'    => -1,
        ]);

        foreach ($navs as $nav) {
            $blocks = parse_blocks($nav->post_content);

            // Drop this group's tagged submenu block wherever it sits, then re-append a fresh one.
            $blocks = array_values(array_filter($blocks, function ($b) use ($group_key) {
                return ($b['attrs']['metadata']['ousMenuSyncGroup'] ?? null) !== $group_key;
            }));

            if ($items) {
                $children = [];
                foreach ($items as $item) {
                    $children[] = [
                        'blockName'    => 'core/navigation-link',
                        'attrs'        => ['label' => $item['label'], 'url' => $item['url'], 'kind' => 'custom'],
                        'innerBlocks'  => [],
                        'innerHTML'    => '',
                        'innerContent' => [],
                    ];
                }
                $blocks[] = [
                    'blockName'    => 'core/navigation-submenu',
                    'attrs'        => [
                        'label'    => $label,
                        'url'      => $group_url,
                        'kind'     => 'custom',
                        'metadata' => ['ousMenuSyncGroup' => $group_key],
                    ],
                    'innerBlocks'  => $children,
                    'innerHTML'    => '',
                    'innerContent' => array_fill(0, count($children), null),
                ];
            }

            $new_content = serialize_blocks($blocks);
            if ($new_content !== $nav->post_content) {
                wp_update_post(['ID' => $nav->ID, 'post_content' => $new_content]);
            }
        }
    }
}
