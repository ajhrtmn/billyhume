<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_Gutenberg_Block — DESIGN-SUITE-UNIFICATION-PLAN.md's "no special-
 * cased pages" direction, applied to the ONE surface this ecosystem had
 * never touched at all: the Gutenberg block editor. Named explicitly in
 * that doc as the highest-risk/last-in-sequence item, with two options
 * recorded: "either a custom block that hosts a node subtree, or
 * replacing block-editor authoring entirely." This is the FIRST one —
 * deliberately, since it's purely additive (a new block an author can
 * choose to use) and touches nothing about how WordPress posts are
 * normally authored, versus the second option, which would mean
 * replacing Gutenberg's own authoring flow — a far larger, riskier
 * change with real existing-content-migration implications not
 * attempted here.
 *
 * What this ships: ONE new block, 'own-ur-shit/element-prefab'. An
 * author picks an existing BH_Element_Prefab (already supports
 * full-subtree snapshot/restore) from a
 * dropdown; the block's render_callback calls the new BH_Element_
 * Prefab::render_definition() (read-only, zero database writes — see
 * that method's own docblock) to render that prefab's tree live,
 * wherever the block is placed in a post. This is genuinely "a node
 * subtree, hosted inside a normal WordPress page" — the concrete first
 * slice of "100% everything on the site would use this design
 * interface... from the LMS builder to the gutenberg block builder,"
 * scoped to what's safely buildable in one pass: embedding an EXISTING
 * saved composition, not yet authoring/editing a tree from directly
 * inside the block editor's own canvas (a real, larger follow-up,
 * honestly out of scope here — recorded in the design doc).
 *
 * No build tooling (webpack/npm) — the editor script below is plain
 * ES5-safe JS using WordPress core's own globals (wp.blocks/wp.element/
 * wp.blockEditor/wp.i18n), the same "no Node build step" constraint
 * class-style-gallery.php's own docblock states for this whole
 * ecosystem (shared hosting, no persistent Node process).
 */
class OUS_Gutenberg_Block {
    // init() is itself only ever invoked as an 'init' hook callback
    // (own-ur-shit.php's bootstrap) — a second, nested add_action('init', ...)
    // registered from inside an already-executing 'init' callback never
    // fires in that same request (see class-blocks.php in bh-monetization-woo
    // for the same pattern). register_block() itself no-ops today since
    // BH_Element_Prefab doesn't currently exist, but this stays correct
    // for if/when it comes back.
    public static function init() {
        self::register_block();
    }

    public static function register_block() {
        if (!function_exists('register_block_type') || !class_exists('BH_Element_Prefab')) return; // WP too old, or the prefab class isn't loaded — harmless no-op, same posture every other optional integration in this ecosystem uses

        wp_register_script(
            'ous-element-prefab-block',
            OUS_URL . 'assets/js/element-prefab-block.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch'],
            OUS_VER,
            true
        );

        register_block_type('own-ur-shit/element-prefab', [
            'editor_script'   => 'ous-element-prefab-block',
            'render_callback' => [self::class, 'render'],
            'attributes'      => [
                'prefabId' => ['type' => 'number', 'default' => 0],
            ],
        ]);
    }

    /**
     * Server-side render — runs for every real visitor viewing the
     * published post, not just in the editor. Deliberately NOT gated
     * behind current_user_can('manage_options') the way every REST
     * route in this ecosystem is: this is public post CONTENT (the
     * whole point of embedding it in a page), not an admin tool, so it
     * follows normal post-visibility rules instead — the same trust
     * boundary any other dynamic block's render_callback operates
     * under. $ctx uses the VIEWING visitor's own user_id (0 for a
     * logged-out guest, which bhcore_events.count's own resolver
     * already handles gracefully — see class-element-data.php's "no
     * subject to count for -> null -> default" branch), never an
     * admin's — a bound attribute inside an embedded prefab should
     * reflect whoever is actually looking at it.
     */
    public static function render($attributes) {
        $prefab_id = (int) ($attributes['prefabId'] ?? 0);
        if ($prefab_id <= 0) {
            // No prefab chosen yet — render nothing on the front end
            // (a half-configured block shouldn't show broken/empty
            // markup to a real visitor); the editor's own JS shows a
            // placeholder prompt instead, so the author isn't left
            // wondering why the canvas is blank.
            return '';
        }

        $ctx = ['user_id' => get_current_user_id()];
        $html = BH_Element_Prefab::render_definition($prefab_id, $ctx);
        if ($html === '') return '';

        return '<div class="bh-el-prefab-embed" data-bhel-prefab-id="' . (int) $prefab_id . '">' . $html . '</div>';
    }
}
