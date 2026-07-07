<?php
if (!defined('ABSPATH')) exit;

/**
 * Registers this plugin's section on the core's shared Debug Tools page
 * — same extension point bh-contest and bh-streaming already use.
 * Seeds a handful of fake, already-verified artists/links (skipping the
 * real network-fetch verification checks entirely, since seed data is
 * for exercising the browse/search UI and admin queue, not for testing
 * the verification network calls themselves) tagged so "Reset
 * Everything" can find and remove them regardless of which plugin
 * created them.
 */
class BHR_Debug {
    const SEED_TAG = '__bhr_seed__';

    public static function init() {
        add_filter('ous_debug_tools', [self::class, 'register']);
    }

    public static function register($tools) {
        $tools['bh-registry'] = [
            'label'  => 'BH Registry',
            'render' => [self::class, 'render_section'],
            'handle' => [self::class, 'handle_action'],
            'reset'  => [self::class, 'reset'],
        ];
        return $tools;
    }

    public static function render_section() {
        echo '<p>Seed a few fake, pre-verified artists/links so browse/search and the review queue have something to show.</p>';
        echo OUS_Debug::button('bh-registry', 'seed', 'Seed 5 fake artists');
    }

    public static function handle_action($action, $post) {
        if ($action !== 'seed') return '';
        self::seed(5);
        return '5 fake artists seeded into BH Registry.';
    }

    private static function seed($count) {
        global $wpdb;
        $artists_t = $wpdb->prefix . 'bhr_artists';
        $links_t   = $wpdb->prefix . 'bhr_links';

        $names = ['Nova Bloom', 'Echo Parade', 'Static Hollow', 'Marigold Ash', 'Fen & Ember'];
        for ($i = 0; $i < $count; $i++) {
            $name = $names[$i % count($names)] . ' ' . self::SEED_TAG;
            $wpdb->insert($artists_t, [
                'display_name'  => $name,
                'bio'           => 'A fake seeded artist for testing browse/search.',
                'contact_email' => '',
                'status'        => 'active',
            ]);
            $artist_id = $wpdb->insert_id;

            $wpdb->insert($links_t, [
                'artist_id'           => $artist_id,
                'protocol'            => $i % 2 === 0 ? 'activitypub' : 'feed',
                'url'                 => 'https://example-instance.test/@seed' . $i . self::SEED_TAG,
                'verification_token'  => 'seed',
                'verification_status' => 'verified',
                'verified_at'         => current_time('mysql'),
                'metadata'            => wp_json_encode(['title' => $name]),
            ]);
        }
    }

    public static function reset() {
        global $wpdb;
        $artists_t = $wpdb->prefix . 'bhr_artists';
        $links_t   = $wpdb->prefix . 'bhr_links';
        $like = '%' . $wpdb->esc_like(self::SEED_TAG) . '%';

        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $artists_t WHERE display_name LIKE %s", $like));
        foreach ($ids as $id) {
            $wpdb->delete($links_t, ['artist_id' => $id]);
            $wpdb->delete($artists_t, ['id' => $id]);
        }
        return count($ids) . ' seeded BH Registry artist(s) removed.';
    }
}
