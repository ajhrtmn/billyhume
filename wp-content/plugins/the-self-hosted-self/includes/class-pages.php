<?php
/**
 * Resolves the page that hosts a given shortcode or block.
 *
 * WHY shared: three different shapes of this had already grown up
 * independently -- bh-contest stores a bh_archive_page_id option at
 * activation AND separately runs a get_posts() search for the literal
 * shortcode, bh-monetization-woo remembers its pages on save_post, and
 * bh-courses had no way to find its own catalog at all. Each is a partial
 * answer: an option only exists if activation created the page, a save_post
 * hook only learns about a page when someone re-saves it, and neither knows
 * about a page an author made by hand.
 *
 * This tries the recorded answer first, then looks, then remembers.
 *
 * @package Own_Ur_Shit
 */
if (!defined('ABSPATH')) exit;

final class OUS_Pages {

    private const CACHE_PREFIX = 'ous_page_for_';

    public static function init(): void {
        // A page edit can add or remove a shortcode, so the cache must not
        // outlive one. Deleting transients is cheap.
        add_action('save_post_page', [self::class, 'flush']);
        add_action('deleted_post', [self::class, 'flush']);
    }

    public static function flush(): void {
        global $wpdb;
        $like = $wpdb->esc_like('_transient_' . self::CACHE_PREFIX) . '%';
        $like_timeout = $wpdb->esc_like('_transient_timeout_' . self::CACHE_PREFIX) . '%';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $like,
            $like_timeout
        ));
    }

    /**
     * The published page hosting $shortcode, or null.
     *
     * @param string        $shortcode  e.g. 'bh_courses'
     * @param string        $option_key an option already holding the page id, checked first
     * @param array<string> $blocks     block names rendering the same thing
     */
    public static function find(string $shortcode, string $option_key = '', array $blocks = []): ?int {
        $key = self::CACHE_PREFIX . $shortcode;
        $cached = get_transient($key);
        if ($cached !== false) return (int) $cached > 0 ? (int) $cached : null;

        $found = 0;

        // A recorded id is only trustworthy if that page still exists and is
        // published -- it can be trashed or reverted long after it was set.
        if ($option_key !== '') {
            $candidate = (int) get_option($option_key, 0);
            if ($candidate > 0 && get_post_status($candidate) === 'publish') $found = $candidate;
        }

        if (!$found) {
            foreach (self::candidate_pages() as $page) {
                if (has_shortcode($page->post_content, $shortcode)) { $found = (int) $page->ID; break; }
                foreach ($blocks as $block) {
                    if (has_block($block, $page)) { $found = (int) $page->ID; break 2; }
                }
            }
        }

        set_transient($key, $found, DAY_IN_SECONDS);
        return $found > 0 ? $found : null;
    }

    /**
     * Permalink of that page, or null.
     *
     * @param array<string> $blocks
     */
    public static function url(string $shortcode, string $option_key = '', array $blocks = []): ?string {
        $id = self::find($shortcode, $option_key, $blocks);
        if (!$id) return null;
        $link = get_permalink($id);
        return is_string($link) ? $link : null;
    }

    /**
     * Guarantees a page exists hosting $shortcode, creating it if not.
     *
     * WHY here: bh-contest and bh-streaming each grew their own private
     * maybe_create_singleton_page(), and bh-courses never got one at all --
     * so contests auto-created an Archive and streaming a Streaming page,
     * while the course catalog only existed if somebody happened to build it
     * by hand. A catalog that a plugin renders should not depend on the site
     * owner knowing to create a page for it.
     *
     * Two things this does that those copies do not:
     *
     * 1. It verifies the recorded page still EXISTS and is published. Those
     *    return early on the option alone, so trashing the page leaves the
     *    option pointing at nothing and the page is never recreated.
     * 2. It looks for an existing page already hosting the shortcode before
     *    creating one, so an install where somebody made the page by hand
     *    gets adopted rather than ending up with two.
     *
     * $blocks matters more than it looks. A page built in Gutenberg stores
     * block markup, not a shortcode, so a shortcode-only lookup does not
     * find it -- which is exactly how the first version of this created a
     * duplicate "Courses" page next to the perfectly good block-authored one
     * that already existed. Adoption has to look for both.
     *
     * @param array<string> $blocks block names that render the same thing
     * @return int page id, or 0 if creation failed
     */
    public static function ensure(string $shortcode, string $option_key, string $title, array $blocks = []): int {
        $recorded = (int) get_option($option_key, 0);
        if ($recorded > 0 && get_post_status($recorded) === 'publish') return $recorded;

        // Adopt a hand-made page rather than duplicating it.
        $existing = self::find($shortcode, '', $blocks);
        if ($existing) {
            update_option($option_key, $existing);
            return $existing;
        }

        $new_id = wp_insert_post([
            'post_title'   => $title,
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => '[' . $shortcode . ']',
        ], true);
        if (is_wp_error($new_id)) return 0;

        update_option($option_key, (int) $new_id);
        self::flush();
        return (int) $new_id;
    }

    /** @return array<int, WP_Post> */
    private static function candidate_pages(): array {
        return get_posts([
            'post_type'        => 'page',
            'post_status'      => 'publish',
            'numberposts'      => 200,
            'suppress_filters' => false,
        ]);
    }
}
