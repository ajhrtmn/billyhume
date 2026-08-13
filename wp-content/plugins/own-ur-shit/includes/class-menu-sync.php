<?php
if (!defined('ABSPATH') ) exit;

/**
 * OUS_MenuSync — lets a plugin maintain its own submenu group inside
 * the site's real nav menu(s) automatically, instead of Billy having to
 * hand-add/remove a link every time a contest or course is
 * published/unpublished.
 *
 * Real bug fixed here (confirmed live: Billy could see contests/courses
 * he'd manually added to the menu himself, but nothing this class ever
 * synced): this class originally ONLY wrote to `wp_navigation` posts —
 * the storage a BLOCK theme's Navigation block reads from. own-ur-
 * shit-theme is a classic theme (header.php calls the classic
 * wp_nav_menu()/register_nav_menus() API, no theme.json, no block
 * templates) — `wp_navigation` posts exist in the database but nothing
 * on this site ever renders them. Every sync_group() call before this
 * fix silently wrote into a menu system this theme doesn't use, so the
 * "show in site menu" checkboxes on contests/courses looked like they
 * worked (the meta saved, no error) but nothing ever actually appeared.
 *
 * Now syncs BOTH real systems: every classic nav_menu (wp_get_nav_menus())
 * gets a real parent nav_menu_item (e.g. "Contests") with real child
 * nav_menu_item entries underneath, tagged via postmeta so a resync can
 * find and replace exactly its own group without touching manually-
 * added items; every `wp_navigation` block-theme post still gets the
 * original core/navigation-submenu block treatment too, so this keeps
 * working correctly if the theme is ever swapped for a block theme
 * later — this fix is additive, not a replacement of the (correct, just
 * previously-incomplete) block-theme path.
 */
class OUS_MenuSync {
    const NAV_POST_TYPE = 'wp_navigation';
    const CLASSIC_GROUP_META_KEY = '_ous_menu_sync_group';

    /**
     * Rebuilds ONE named submenu group (e.g. 'contests') inside every
     * nav menu on the site — both classic (wp_nav_menu) and block-theme
     * (wp_navigation) — leaving every other item/block (manually added
     * links, other groups) untouched. $items is the full, already-
     * filtered, already-ordered list this group should show right now —
     * [['label' => 'Summer Songwriting Contest', 'url' => '...'], ...].
     * An empty array removes the group's submenu entirely rather than
     * leaving a label with nothing under it.
     */
    /** @param array<int, array<string, mixed>> $items */
    public static function sync_group(string $group_key, string $label, array $items): void {
        self::sync_classic_menus($group_key, $label, $items);
        self::sync_block_navigations($group_key, $label, $items);
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function sync_classic_menus(string $group_key, string $label, array $items): void {
        $menus = wp_get_nav_menus();
        if (!$menus) {
            // Real gap found live: a site that has never been through
            // Appearance > Menus has ZERO wp_nav_menu terms at all — not
            // "a menu with nothing in it," genuinely none. Every classic
            // theme's own no-menu-assigned fallback (own-ur-shit-theme's
            // oust_default_menu(), for example) renders SOMETHING
            // (usually a naive get_pages() dump), which is exactly what
            // made this look like it was "working" — Billy was seeing
            // arbitrary Pages, not anything this system ever synced.
            // Auto-create one real menu and assign it to every
            // registered nav menu location that doesn't already have
            // one, so there's always a real menu object to sync into —
            // no theme-side setup step required.
            $created_id = self::ensure_default_menu_exists();
            if (!$created_id) return;
            $menus = wp_get_nav_menus();
            if (!$menus) return;
        }

        foreach ($menus as $menu) {
            $menu_id = $menu->term_id;
            $existing = wp_get_nav_menu_items($menu_id);
            if ($existing === false) $existing = [];

            // Find this group's own previously-synced parent + children
            // by postmeta tag, regardless of where they currently sit in
            // the menu order — the same "find by tag, drop, re-add
            // fresh" approach the block-theme path already used.
            $own_ids = [];
            foreach ($existing as $menu_item) {
                if (get_post_meta($menu_item->ID, self::CLASSIC_GROUP_META_KEY, true) === $group_key) {
                    $own_ids[] = (int) $menu_item->ID;
                }
            }
            foreach ($own_ids as $id) {
                wp_delete_post($id, true);
            }

            if (!$items) continue; // nothing to add — group removed entirely, matches the block-theme path's behavior

            $parent_id = wp_update_nav_menu_item($menu_id, 0, [
                'menu-item-title'  => $label,
                'menu-item-url'    => '#',
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

    // Creates one real nav_menu term ("Primary Menu") and assigns it to
    // every registered nav menu location (get_registered_nav_menus())
    // that doesn't already have a menu assigned — never overwrites an
    // existing assignment, only fills genuinely empty slots. Returns
    // the new menu's term_id, or 0 if a menu with this name somehow
    // already exists but wp_get_nav_menus() still came back empty
    // (defensive; shouldn't happen) or creation failed.
    private static function ensure_default_menu_exists(): int {
        $existing = wp_get_nav_menu_object('Primary Menu');
        $menu_id = $existing ? $existing->term_id : wp_create_nav_menu('Primary Menu');
        if (is_wp_error($menu_id) || !$menu_id) return 0;

        // Real regression found live: the classic theme's own no-menu-
        // assigned fallback (oust_default_menu() in header.php) rendered
        // Home + a handful of Pages + an Account/Log In link — the
        // moment this method assigns a real (but otherwise EMPTY) menu
        // to the theme's primary location, that fallback stops firing
        // and every one of those links vanishes, even though nothing
        // about them was ever a real menu Billy could edit. A brand-new
        // menu gets seeded with the same links, but as real, editable
        // nav_menu_items this time — never re-seeded on a menu that
        // already existed (that "$existing" case shouldn't happen, but
        // if it does, it may already hold Billy's own real content and
        // must not be touched).
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

    // Seeds a genuinely brand-new menu with the same content the classic
    // theme's own fallback used to show, so auto-creating a real menu is
    // never a visible regression: "Home", up to 6 published pages
    // (same query/order oust_default_menu() used), and an Account/Log
    // In link if own-ur-shit's portal is installed. Deliberately plugin-
    // side rather than relying on theme code for this — the portal link
    // is a real ecosystem feature that must show up regardless of which
    // theme is active or whether that theme's own code has deployed
    // correctly.
    private static function seed_default_menu_content(int $menu_id): void {
        wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title'  => __('Home', 'own-ur-shit'),
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
            wp_update_nav_menu_item($menu_id, 0, [
                'menu-item-title'  => __('Account', 'own-ur-shit'),
                'menu-item-url'    => home_url('/account/'),
                'menu-item-status' => 'publish',
                'menu-item-type'   => 'custom',
            ]);
        }
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function sync_block_navigations(string $group_key, string $label, array $items): void {
        $navs = get_posts([
            'post_type'      => self::NAV_POST_TYPE,
            'post_status'    => 'any',
            'numberposts'    => -1,
        ]);

        foreach ($navs as $nav) {
            $blocks = parse_blocks($nav->post_content);

            // Drop this group's own previously-synced submenu block
            // (tagged via its own metadata attr) wherever it currently
            // sits, then re-append a fresh one — simplest correct
            // approach; preserves manually-added top-level links, which
            // are never tagged this way and so are never touched.
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
                        'url'      => '#',
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
