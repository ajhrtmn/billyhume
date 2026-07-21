<?php
if (!defined('ABSPATH') ) exit;

/**
 * OUS_MenuSync — lets a plugin maintain its own submenu group inside
 * every site Navigation menu automatically, instead of Billy having to
 * hand-add/remove a link every time a contest or course is
 * published/unpublished.
 *
 * A "Navigation menu" in a block theme (this site's theme) is just a
 * `wp_navigation` post whose content is ordinary serialized Gutenberg
 * blocks (core/navigation-link, core/navigation-submenu). Etch (ETCH-
 * COMPATIBILITY-NOTES.md) authors to that exact same Gutenberg storage,
 * so writing valid core blocks here is Etch-compatible by construction
 * — no special-casing needed.
 *
 * Zero-central-registration shape is NOT used here on purpose (unlike
 * ous_search_providers/bhi_portal_panels) — a menu-sync group needs an
 * explicit resync call at the exact moment its own data changes (a
 * contest published, a course trashed), not a pull-based "ask everyone"
 * pass. Each consumer plugin calls sync_group() itself, right after its
 * own save/trash/delete hooks — same shape OUS_Revisions::snapshot()
 * already uses (a consumer calls IN, this class doesn't call OUT).
 */
class OUS_MenuSync {
    const NAV_POST_TYPE = 'wp_navigation';

    /**
     * Rebuilds ONE named submenu group (e.g. 'contests') inside every
     * Navigation menu on the site, leaving every other block (manually
     * added links, other groups) untouched. $items is the full, already-
     * filtered, already-ordered list this group should show right now —
     * [['label' => 'Summer Songwriting Contest', 'url' => '...'], ...].
     * An empty array removes the group's submenu entirely rather than
     * leaving a label with nothing under it.
     */
    public static function sync_group($group_key, $label, array $items) {
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
