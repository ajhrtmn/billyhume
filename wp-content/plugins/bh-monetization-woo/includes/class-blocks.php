<?php
if (!defined('ABSPATH')) exit;

/**
 * ROADMAP-ux-polish-and-feature-parity-2026-07.md 5a (the "WYSIWYG:
 * shortcodes → real blocks" follow-up) — direct AJ feedback after
 * seeing `[bhm_buy id="109"]` render as raw bracket text in the real
 * post editor: "how plausible is that shortcode preview... as much as
 * we can make the page builder wysiwyg with stuff like that along with
 * Gutenberg the better."
 *
 * The mechanism is WordPress core's own `wp.serverSideRender` — no new
 * dependency, no npm, no build step (same "plain core globals" posture
 * own-ur-shit's class-gutenberg-block.php already established as this
 * ecosystem's first real block). A ServerSideRender-backed block calls
 * a REST endpoint that runs the SAME PHP render_callback a real visitor
 * sees, and drops the actual output into the editor canvas — not a
 * mimic, the real thing, live, debounced on attribute change.
 *
 * 'bhm/buy' is the FIRST block converted (per the roadmap doc's own
 * sequencing: prove the pattern here, cheaply, before expanding to
 * bhm_tip_jar/bhm_tiers/bhm_wallet and the other plugins' shortcodes).
 * The old [bhm_buy] SHORTCODE is intentionally left registered and
 * untouched (BHM_Frontend::init()) — existing content using it keeps
 * working exactly as before; this block is a new, better authoring
 * path alongside it, not a breaking replacement.
 */
class BHM_Blocks {
    // QA fix, caught live: BHM_Blocks::init() is itself only ever called
    // FROM an 'init' callback (bh-monetization-woo.php's plugins_loaded
    // handler does add_action('init', ['BHM_Blocks', 'init'])) — a
    // second, nested add_action('init', ...) registered from inside an
    // 'init' callback that's already executing never actually fires in
    // that same request (confirmed directly against WP_Hook's real
    // iteration behavior, not assumed): WordPress's hook system doesn't
    // re-visit a priority bucket it has already passed while iterating
    // it, so the callback silently never runs and the block never
    // registers, request after request, with no error anywhere. Calling
    // register_block() directly here (we're ALREADY inside 'init' by
    // the time this runs) sidesteps the whole footgun — there's nothing
    // register_block_type() needs from a later point in 'init' that
    // isn't already available the moment this executes.
    public static function init() {
        self::register_block();
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_block() {
        if (!function_exists('register_block_type')) return; // WP too old — harmless no-op, same posture every optional integration in this ecosystem uses

        wp_register_script(
            'bhm-buy-block',
            BHM_URL . 'assets/js/bhm-blocks.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-server-side-render'],
            BHM_VER,
            true
        );

        register_block_type('bhm/buy', [
            'editor_script'   => 'bhm-buy-block',
            'render_callback' => [self::class, 'render_buy'],
            'attributes'      => [
                'id' => ['type' => 'number', 'default' => 0],
            ],
        ]);

        // 'bhm/tip-jar' — the second block converted (roadmap doc's own
        // sequencing list, right after bhm/buy). Zero attributes: unlike
        // a purchase button, the tip jar is site-wide, always the one
        // shared Tip product ([bhm_tip_jar]'s own shortcode signature
        // takes no atts either) — no Inspector picker needed, so this
        // block's edit() renders ServerSideRender unconditionally with
        // no configuration step.
        register_block_type('bhm/tip-jar', [
            'editor_script'   => 'bhm-buy-block',
            'render_callback' => [self::class, 'render_tip_jar'],
        ]);
    }

    // render_callback runs for every real visitor too, not just the
    // editor's ServerSideRender call — this IS the front end, the block
    // has no separate saved markup of its own (see bhm-blocks.js's
    // save(): return null).
    public static function render_buy($attributes) {
        $id = (int) ($attributes['id'] ?? 0);
        if (!$id) return '';
        return BHM_Frontend::render_purchase_button(['id' => $id]);
    }

    public static function render_tip_jar($attributes) {
        return BHM_Frontend::render_tip_jar($attributes);
    }

    public static function register_routes() {
        register_rest_route('bhm/v1', '/purchasable-objects', [
            'methods' => 'GET',
            'callback' => [self::class, 'rest_purchasable_objects'],
            // Editor-only listing (which track/release has a purchase
            // price configured) — not public post content, gated the
            // same way own-ur-shit's element-prefab picker gates its own
            // REST listing endpoint.
            'permission_callback' => fn() => current_user_can('edit_posts'),
        ]);
    }

    // Populates the block's Inspector picker — every bhs_track/
    // bhs_release with a real purchase price configured (the same
    // _bhm_purchase_price_cents gate render_purchase_button() itself
    // already checks), so an author can never pick an object that would
    // just render blank.
    public static function rest_purchasable_objects($req) {
        $q = new WP_Query([
            'post_type' => ['bhs_track', 'bhs_release'],
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_key' => '_bhm_purchase_price_cents',
            'meta_compare' => 'EXISTS',
        ]);
        $out = [];
        foreach ($q->posts as $p) {
            $cents = (int) get_post_meta($p->ID, '_bhm_purchase_price_cents', true);
            if (!$cents) continue; // meta key exists but is 0/blank — nothing purchasable to pick
            $out[] = [
                'id' => $p->ID,
                'title' => $p->post_title,
                'type' => $p->post_type === 'bhs_release' ? 'Album' : 'Track',
                'price' => number_format($cents / 100, 2),
            ];
        }
        return new WP_REST_Response($out, 200);
    }
}
