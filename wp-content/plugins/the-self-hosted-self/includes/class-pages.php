<?php
/**
 * Resolves the page that hosts a given shortcode or block: tries the recorded
 * option first, then searches (including hand-made pages), then remembers.
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
     * Guarantees a page exists hosting $shortcode, creating it if not. Verifies
     * the recorded page still exists and is published, and adopts an existing
     * hand-made page before creating one.
     * WHY $blocks: Gutenberg pages store block markup, not the shortcode, so a
     * shortcode-only lookup creates duplicates.
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
