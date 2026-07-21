<?php
if (!defined('ABSPATH')) exit;

/**
 * ROADMAP-ux-polish-and-feature-parity-2026-07.md 5a — the WYSIWYG
 * shortcode-to-block conversion, continuing into bh-contest after
 * bh-monetization-woo's three easy-tier conversions (bhm/buy, bhm/
 * tip-jar, bhm/tiers). Same wp.serverSideRender mechanism, same "old
 * shortcode stays registered and untouched" posture — these blocks are
 * a new, additive authoring path, not a breaking replacement.
 *
 * A real, worth-stating distinction from the monetization blocks: this
 * plugin's shortcodes only ever render a static container div — the
 * actual interactive behavior (voting, playback, the reveal sequence,
 * the archive grid) is entirely owned by player.js/reveal.js/archive.js
 * hydrating that div client-side, on the REAL front-end page load. A
 * block's render_callback() returns the exact same container HTML the
 * shortcode always did, so nothing about that hydration changes — but
 * bh-contest.php's own asset-enqueue gate only ever checked
 * has_shortcode() against post_content, which a block-authored page has
 * none of. Fixed alongside these three blocks (see bh-contest.php's own
 * comment on that gate) — without it, a block-authored page would
 * render an inert, permanently-loading container with none of its JS
 * ever enqueued.
 */
class BH_Blocks {
    public static function init() {
        self::register_blocks();
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_blocks() {
        if (!function_exists('register_block_type')) return; // WP too old — harmless no-op, same posture every optional integration in this ecosystem uses

        wp_register_script(
            'bh-contest-blocks',
            BH_URL . 'assets/js/bh-contest-blocks.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-server-side-render'],
            BH_VER,
            true
        );

        register_block_type('bh/contest-player', [
            'editor_script'   => 'bh-contest-blocks',
            'render_callback' => [self::class, 'render_player'],
            'attributes'      => [
                'contest' => ['type' => 'string', 'default' => ''],
            ],
        ]);

        register_block_type('bh/results-reveal', [
            'editor_script'   => 'bh-contest-blocks',
            'render_callback' => [self::class, 'render_reveal'],
            'attributes'      => [
                'contest' => ['type' => 'string', 'default' => ''],
            ],
        ]);

        register_block_type('bh/archive', [
            'editor_script'   => 'bh-contest-blocks',
            'render_callback' => [self::class, 'render_archive'],
        ]);
    }

    public static function render_player($attributes) {
        return BH_Auth::render(['contest' => $attributes['contest'] ?? '']);
    }

    public static function render_reveal($attributes) {
        return BH_Reveal::render_display_shortcode(['contest' => $attributes['contest'] ?? '']);
    }

    public static function render_archive($attributes) {
        return BH_Archive::render_display_shortcode();
    }

    public static function register_routes() {
        register_rest_route('bh/v1', '/contests-picker', [
            'methods' => 'GET',
            'callback' => [self::class, 'rest_contests_picker'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
        ]);
    }

    // Populates the bh/contest-player and bh/results-reveal blocks'
    // Inspector picker — reuses BH_Helpers::all_contests() (every
    // published contest, newest first), the exact same source the
    // Console/Debug Tools contest pickers already use.
    public static function rest_contests_picker($req) {
        $out = [];
        foreach (BH_Helpers::all_contests() as $c) {
            $out[] = ['id' => $c->ID, 'title' => $c->post_title, 'slug' => $c->post_name];
        }
        return new WP_REST_Response($out, 200);
    }
}
