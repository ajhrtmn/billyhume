<?php
if (!defined('ABSPATH')) exit;

/**
 * BH_Element_Builder — the visual three-pane GUI for BH_Element
 * (ELEMENT-BUILDER-DESIGN-PLAN.md §4, the "StorybookUI + Elementor
 * vibes... more of a visual CSS designer than a page builder" GUI phase
 * named in §6 step 2). This is a CLONE of BHY_Gallery's three-pane
 * layout (own-ur-shit/includes/class-style-gallery.php) — same
 * left-rail/canvas/right-rail shape, same CSS class naming instinct
 * (bhel-* mirroring bhy-*), same no-build vanilla-JS convention — not a
 * new visual language.
 *
 * DELIBERATE ACCESS-POINT DEVIATION FROM §4's LITERAL TEXT: the design
 * doc says "a new admin page ... submenu under own-ur-shit." This build
 * instead ships as a NEW, SEPARATE Debug Tools section
 * (admin.php?page=ous-debug#ous-section-bh-element-builder), not a
 * standalone admin.php?page=... page. Reason: this install has a
 * confirmed, documented WordPress-core hook-resolution bug that broke
 * BOTH a standalone top-level-adjacent page (OUS_ApiDocs::add_menu(),
 * see class-api-docs.php's own docblock) AND a standalone page
 * registered as a submenu of the working 'ous-debug' parent (same
 * file, same incident) — get_current_screen() resolved to the wrong
 * hook and denied access outright despite correct registration and
 * capability. VISION.md's "New dev/admin-only pages default to a Debug
 * Tools SECTION" entry generalizes this into a standing rule after
 * that incident. A Debug Tools section is the one access pattern this
 * codebase has proven, more than once, to actually work — see
 * OUS_ApiDocs::render_debug_section() and OUS_CodebaseDocs for the
 * same fallback. This section is ADDITIVE: BH_Element::render_debug_
 * section() (the existing bare add/remove/reorder list, registered
 * under the 'bh-element' key) is completely untouched — this class
 * registers a SECOND, separate 'bh-element-builder' key so both exist
 * side by side on the same Debug Tools page.
 *
 * Every REST call this GUI makes is a plain fetch() against the
 * EXISTING BH_Element/BH_Element_Data REST bridge (class-element.php's
 * register_routes()) — GET/POST ous/v1/elements/* — using the same
 * wp_rest cookie-nonce contract BH_Studio's assets/js/studio.js already
 * uses (wp_localize_script() ships a nonce, sent as the X-WP-Nonce
 * header on every request). No new REST route, no duplicated backend
 * logic: this file and assets/js/element-builder.js are UI only.
 *
 * NOT runtime-verified: no live browser/WordPress/REST execution is
 * available in this sandbox. Reasoned through against BH_Element's
 * actual, already-read REST response/request shapes and BHY_Gallery's
 * actual working markup/CSS/enqueue pattern, and brace/syntax-checked,
 * but please smoke-test the surface/slot picker, add-from-palette,
 * up/down reorder, attribute + binding inspector, and the real save/
 * load round trip against a live install before relying on this.
 */
// OUS_VER 3.4.37 — no functional change in this file: every registered
// surface/slot becoming real tree nodes (replacing the old topbar's
// manual Surface/Slot picker) is entirely a JS restructuring in
// assets/js/element-builder.js; this file's render_shell() still just
// outputs the same bare #bhel-app mount point. Only the shell/help text
// above (render()/render_shell()) was updated to describe the new tree
// shape. See class-element.php and assets/js/element-builder.js's own
// updated docblocks for the real mechanics.
//
// OUS_VER 3.4.30 — DESIGN-SUITE-UNIFICATION-PLAN.md real structural fix:
// the 3.4.29 standalone 'bh-element-builder' submenu of 'bh-design' is
// now ITSELF retired — per the user's explicit "there is no difference
// between the two" instruction, this GUI is no longer a second
// destination next to the Style page at all, it is now a "Widgets &
// Elements" tab INSIDE BHY_Gallery's one 'bh-style' page. add_menu()
// below is left fully defined (same "leave it defined, just unhook it"
// convention as its own prior comment about the Debug Tools fallback)
// but is no longer hooked to 'admin_menu' anywhere in own-ur-shit.php —
// see that file's own changelog comment. render_shell() is now PUBLIC
// (was private) so BHY_Gallery::render() can call it directly to mount
// the exact same markup this tab used to render on its own page, and
// maybe_enqueue()'s asset-loading body is now the reusable public
// enqueue_assets(), called directly by BHY_Gallery on the 'bh-style'/
// 'bh-design' hook instead of being gated behind a 'bh-element-builder'
// hook substring match that no longer exists. No REST/data logic
// changed anywhere in this file.
//
// OUS_VER 3.4.29 — DESIGN-SUITE-UNIFICATION-PLAN.md §1.4 follow-up: the
// Debug Tools section deviation documented in this docblock above is now
// RETIRED per explicit user instruction ("the element builder should
// now be entirely part of the design suite, not part of debug in any
// way"). add_menu() below registers this GUI as a real submenu of the
// top-level 'bh-design' (Design Suite) menu — same slug/parent/capability
// shape BHY_Gallery::add_menu()/BH_Studio::add_menu() already proved
// working, mirroring every one of §1.4's six mitigation rules (see
// add_menu()'s own comment). register_debug_section()/init()'s old
// add_filter('ous_debug_tools', ...) call is no longer hooked — see
// init()'s own comment, same "leave the method defined, just unhook it"
// convention class-api-docs.php's add_menu()/init() already established
// for its own retired standalone-page attempt. The HTML this GUI renders
// is unchanged: both the old Debug Tools section and the new admin page
// call the same shared render_shell() method, so this is an ACCESS-POINT
// change only, not a UI rewrite.
// 3.4.27 — element-builder.js/.css gained the inspector's "Style —
// Advanced" section (EVERY §2.6 property group as a dynamic preset
// picker + custom-value escape hatch, sourced from the new GET
// .../elements/style-schema route / BHY_Style::style_schema_for_js())
// and the "HTML Attributes" section (tag picker, per-type attr fields,
// repeatable custom data-* row editor, all built from GET .../elements/
// types' existing 'attrs'/'tags' keys). Both write into config.style/
// config.htmlAttrs and save through the EXISTING POST .../placements
// route unchanged. This PHP file itself is unchanged beyond this
// comment — the new inspector sections are additive JS/CSS only, same
// posture as every other addition to this screen so far.
//
// 3.4.24 — 2026-07-11 — element-builder.js/.css gained "Save as Prefab"
// (topbar) and a "Prefabs" palette section with per-prefab "Insert"
// actions, against the new BH_Element_Prefab REST routes
// (ous/v1/elements/prefabs*, class-element-prefab.php). This PHP file
// itself is unchanged — the prefab UI is additive JS/CSS only, same
// posture as every other addition to this screen so far.

class BH_Element_Builder {
    const CAP = 'bhcore_design_site';

    public static function init() {
        // add_filter('ous_debug_tools', ...) is deliberately NOT hooked
        // anymore — this GUI now has a real admin_menu registration (see
        // add_menu() below, hooked directly on 'admin_menu' further down
        // in own-ur-shit.php, same as BHY_Gallery/BH_Studio), per the
        // user's explicit instruction that the Element Builder be
        // entirely part of the Design Suite, not part of Debug Tools in
        // any way. register_debug_section()/render_debug_section() are
        // left defined below (not deleted) in case a future session
        // needs the fallback again — same "leave it defined, just
        // unhook it" posture OUS_ApiDocs::init()'s own comment about its
        // retired add_menu() call already established for this
        // ecosystem. NOTE this is the opposite direction of that
        // precedent (moving OFF Debug Tools onto a real page, not onto
        // Debug Tools as a fallback FROM a broken real page) — see
        // add_menu()'s own comment for why this page is expected to
        // work where the earlier standalone pages didn't.
        add_action('admin_enqueue_scripts', [self::class, 'maybe_enqueue']);
    }

    /**
     * DESIGN-SUITE-UNIFICATION-PLAN.md §1.4 — registers this GUI as a
     * real submenu of the top-level 'bh-design' (Design Suite) menu,
     * mirroring BHY_Gallery::add_menu()/BH_Studio::add_menu() exactly,
     * and every one of §1.4's six standalone-page mitigation rules:
     *   1. Unconditional registration — no is_locked()/environment
     *      conditional wraps this add_submenu_page() call.
     *   2. Default 'admin_menu' priority (10) — hooked directly, same
     *      style as BHY_Gallery/BH_Studio's own add_menu() hookups.
     *   3. A real callback (self::render()), never an empty string.
     *   4. Gated on 'bhcore_design_site' — granted to administrator and
     *      editor on the 'init' hook (OUS_Roles), which always fires
     *      before 'admin_menu', so the capability is genuinely present
     *      at registration time.
     *   5. A submenu of a TOP-LEVEL menu ('bh-design'), not a submenu of
     *      a submenu — the exact shape §1.4 identifies as proven-working
     *      (own-ur-shit, ous-debug, bh-design) versus the failure-prone
     *      submenu-of-'ous-debug' shape both originally-broken pages had.
     *   6. Registration-result logging via OUS_DebugLog::log_throttled(),
     *      the same pattern class-api-docs.php/BHY_Gallery/BH_Studio use.
     *
     * NOT runtime-verified — smoke-test this menu especially carefully,
     * ideally as a non-admin 'editor'-role account holding only
     * 'bhcore_design_site' (not an admin, whose manage_options masks
     * capability-scoping bugs), per §1.4's own deployment-risk note.
     */
    // RETIRED (3.4.30) — no longer hooked to 'admin_menu' anywhere in
    // own-ur-shit.php; this GUI now lives as a tab inside BHY_Gallery's
    // one 'bh-style' page instead of its own submenu. Left fully defined,
    // not deleted, in case a future session needs the standalone-page
    // fallback again — same posture as register_debug_section() below.
    public static function add_menu() {
        $hook = add_submenu_page('bh-design', 'Element Builder', 'Element Builder', self::CAP, 'bh-element-builder', [self::class, 'render']);
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log_throttled('info', 'element_builder_menu', 60,
                'add_submenu_page() for Element Builder (bh-element-builder, parent bh-design) returned: ' . ($hook === false ? 'FALSE (registration failed)' : "'$hook'"),
                ['hook_suffix' => $hook, 'current_user_can_cap' => current_user_can(self::CAP) ? 'TRUE' : 'FALSE'],
                'BH_Element_Builder::add_menu()'
            );
        }
    }

    /**
     * Retired fallback — see init()'s own comment for why this filter is
     * no longer hooked. Left defined, not deleted, in case a future
     * session needs to fall back to a Debug Tools section again.
     * Registered as its OWN section key ('bh-element-builder'), separate
     * from BH_Element::register_debug_section()'s existing 'bh-element'
     * key — that bare list is untouched by this change (see
     * class-element.php; it's a different, lower-level raw-placement
     * CRUD tool, not "the element builder" this GUI is).
     */
    public static function register_debug_section($tools) {
        $tools['bh-element-builder'] = [
            'label'  => 'Element Builder (Visual)',
            'render' => [self::class, 'render_debug_section'],
            'group'  => OUS_Debug::GROUP_REFERENCE,
        ];
        return $tools;
    }

    // RETIRED (3.4.30) — the 'bh-element-builder' hook this checked for
    // no longer exists (add_menu() above is unhooked), so this is a
    // permanent no-op now. Left defined/hooked (init() still calls it on
    // 'admin_enqueue_scripts') for the same "leave it, don't delete it"
    // posture as add_menu() above; enqueue_assets() below is the real,
    // reusable body BHY_Gallery now calls directly.
    public static function maybe_enqueue($hook) {
        if (strpos((string) $hook, 'bh-element-builder') === false) return;
        self::enqueue_assets();
    }

    /**
     * The real, reusable asset-loading body (3.4.30) — split out of the
     * old hook-gated maybe_enqueue() so BHY_Gallery::maybe_enqueue_widgets_
     * assets() can call it directly on the 'bh-style'/'bh-design' hook,
     * now that this GUI is a tab inside that page rather than its own.
     * studioUrl is new in this pass — the exact admin.php?page=bh-studio
     * URL the "Edit nested content" modal (element-builder.js's
     * openStudioModal()) loads in an iframe; bh-studio is still a real,
     * capability-checked page (class-studio.php's add_menu(), now
     * registered with a null/hidden parent), just no longer in any menu.
     */
    public static function enqueue_assets() {
        wp_enqueue_style('bh-element-builder', OUS_URL . 'assets/css/element-builder.css', [], OUS_VER);
        wp_enqueue_script('bh-element-builder', OUS_URL . 'assets/js/element-builder.js', [], OUS_VER, true);

        wp_localize_script('bh-element-builder', 'bhElementBuilderConfig', [
            // Mirrors BH_Studio::maybe_enqueue()'s exact localize shape
            // (restUrl + wp_rest nonce) — same auth contract BH_Element's
            // REST routes already assume (manage_options + WP's own
            // cookie/nonce auth, no separate token scheme).
            'restUrl'   => esc_url_raw(rest_url('ous/v1/elements/')),
            'nonce'     => wp_create_nonce('wp_rest'),
            'studioUrl' => esc_url_raw(admin_url('admin.php?page=bh-studio')),
            // AJ's own ask: real JS authoring via the builder, gated
            // behind a real capability (OUS_Roles::DEFAULT_CAPS,
            // administrator-only by default — see class-element.php's
            // save_placement() for the SERVER-side enforcement, which is
            // what actually matters; this flag only controls whether the
            // client-side inspector even SHOWS the field, a UX nicety,
            // not the security boundary itself).
            'canAuthorCustomJs' => current_user_can('bhcore_author_custom_js'),
        ]);
    }

    /**
     * The real admin page callback (bh-element-builder, submenu of
     * bh-design) — wraps the shared render_shell() body in the same
     * page-chrome convention BHY_Gallery::render()/BH_Studio::render()
     * use (BHY_UI::shell_open()/shell_close()).
     */
    public static function render() {
        BHY_UI::shell_open('Element Builder', 'A visual builder for composing per-surface/per-slot element placements — one node tree (rooted at "Site", with every registered surface and slot as real nodes beneath it as of 3.4.37) on the left, inspector on the right; adding a child opens a contextual picker rather than a standing palette.');
        self::render_shell();
        BHY_UI::shell_close();
    }

    /**
     * Retired Debug Tools fallback callback — see register_debug_section()'s
     * own comment. Left defined for the same reason.
     */
    public static function render_debug_section() {
        self::render_shell();
    }

    /**
     * The shared body — a static shell (surface/slot picker mount point
     * + three-pane grid skeleton); every dynamic bit (palette contents,
     * canvas cards, inspector fields) is filled in by
     * assets/js/element-builder.js against the live REST bridge. No
     * inline data is pre-rendered here — keeping this output
     * cacheable/static-analyzable and pushing all the "what's actually
     * registered right now" logic through the same REST routes a future
     * non-admin client could also use. Called by both render() (the real
     * admin page) and render_debug_section() (the retired fallback) so
     * the HTML-building code exists in exactly one place. PUBLIC as of
     * 3.4.30 (was private) — BHY_Gallery::render() now calls this
     * directly to mount the identical markup inside its "Widgets &
     * Elements" tab.
     */
    public static function render_shell() {
        echo '<p class="description">A visual builder cloned from the Style editor\'s (<code>BHY_Gallery</code>) Storybook layout — ONE node tree rooted at a synthetic "Site" node (left) plus an inspector (right) that branches on what\'s selected; as of 3.4.37 every registered surface and slot is a real, automatically-populated tree node under Site too (no manual Surface/Slot picker anywhere), and adding a child opens a contextual popup picker instead of a standing palette. Reads/writes the SAME <code>bhcore_element_placements</code> data as the bare list-based Element admin tool (<code>ous/v1/elements/*</code> REST routes) — nothing here is separate storage.</p>';
        echo '<noscript><p class="description">This tool requires JavaScript.</p></noscript>';
        echo '<div id="bhel-app" class="bhel-app" data-loading="1"><p class="description">Loading Element Builder…</p></div>';
    }
}
