/**
 * Element Builder GUI — vanilla JS, no build step (matches this
 * ecosystem's standing convention; see assets/js/studio.js for the
 * sibling no-build canvas this file's REST-call/nonce pattern is
 * copied from). Mounts into #bhel-app (own-ur-shit/includes/
 * class-element-builder.php's render_debug_section()).
 *
 * Talks ONLY to the EXISTING BH_Element / BH_Element_Data REST bridge
 * (class-element.php's register_routes()) — GET/POST ous/v1/elements/* —
 * no new route, no client-side reimplementation of server-side logic
 * (capability filtering, e.g., is done entirely server-side by
 * BH_Element::types_for_surface() before it ever reaches GET .../types;
 * this file just renders whatever that endpoint returns).
 *
 * REST SHAPE CONSTRAINT WORTH NOTING: this GUI's "✕" card action still
 * toggles a placement's 'enabled' flag off via the same POST .../placements
 * upsert (matching BH_Element::save_placement()'s existing 'enabled'
 * column) rather than calling the DELETE route class-element.php's
 * register_routes() added alongside the prefab work this pass — left
 * as-is deliberately, not a gap: disabling is non-destructive (undo by
 * re-enabling) and this GUI's whole editing flow already treats "enabled"
 * as the working toggle. A future pass MAY wire "✕" to the real DELETE
 * route if a true one-click delete is wanted; not done here to avoid
 * changing this file's already-working, if conservative, remove semantics
 * in the same pass that adds the unrelated prefab feature below.
 *
 * PREFAB ADDITIONS THIS PASS (not in the original design doc — a genuine
 * mid-build addition, see class-element-prefab.php's own docblock):
 * a "Save as Prefab" button next to "Save slot" in the topbar, and a
 * "Prefabs" section above the palette listing every saved prefab with an
 * "Insert" action that calls POST prefabs/{id}/instantiate against the
 * CURRENT surface/slot/context and reloads the slot — the deep-copy
 * contract lives entirely server-side (BH_Element_Prefab::instantiate()),
 * this file only triggers it and refreshes. (3.4.37 — "Save as Prefab"
 * for a whole slot has since moved off the topbar onto the Slot tree
 * node itself; see saveSlotAsPrefab() below and this file's 3.4.37
 * changelog paragraph further down.)
 *
 * Manual up/down reorder buttons, not HTML5 drag/drop — deliberate,
 * per this pass's own instructions: no live browser available to test
 * drag/drop interaction this session, and up/down buttons are trivially
 * correct-by-inspection (swap two array indices, re-render).
 *
 * 3.4.27 ADDITIONS (DESIGN-SUITE-UNIFICATION-PLAN.md §2.6 inspector UI —
 * the one piece 3.4.26's backend/render/security pass deliberately left
 * unshipped): a "Style — Advanced" section rendering EVERY §2.6 property
 * group (sizing/spacing/background/typography/border/display+flex+grid/
 * position/effects/overflow) as a preset-picker + custom-value escape
 * hatch, and an "HTML Attributes" section (tag picker, per-type attr
 * fields, repeatable custom data-* row editor). Both are built ENTIRELY
 * from data the REST bridge already returns dynamically — GET
 * .../elements/types' 'attrs'/'tags' keys and the new GET
 * .../elements/style-schema route (BHY_Style::style_schema_for_js()) —
 * so no element type or property group is hardcoded into this file;
 * adding a new type or a new PROPERTY_MAP entry server-side needs no JS
 * change here. Both sections write into the SAME config.style/
 * config.htmlAttrs shape BH_Element::render_placement()/wrap_placement_
 * html() already resolve, and save through the EXISTING POST
 * .../placements route (savePlacements()/globalSave() below is otherwise
 * unchanged — config already rides verbatim, per class-element.php's own
 * docblock).
 *
 * NOT runtime-verified: no live browser/WordPress/REST execution
 * available in this sandbox. Reasoned through against BH_Element's
 * actual REST response/request shapes read directly from class-
 * element.php, and against BHY_Style::PROPERTY_MAP/resolve_style_value()/
 * style_schema_for_js() read directly from class-style.php. Please
 * smoke-test the full round trip (load every surface, add from the
 * palette, edit attrs/binding/style/style-groups/htmlAttrs, reorder,
 * save, reload, and confirm the rendered wrapper tag/attrs/inline style
 * on a real page) against a real install before relying on this in
 * production.
 *
 * 3.4.34 — the canvas ("Pages" rail content, per class-style-gallery.php's
 * reparenting) is now a REAL recursive tree renderer, not a flat card
 * list, closing the honestly-disclosed gap DESIGN-SUITE-UNIFICATION-
 * PLAN.md §2 called out. Each slot's own placements array carries its own
 * real parent_placement_id column value (class-element.php's
 * rest_get_placements() does a bare SELECT *), so this file builds a
 * parent id => children array map from that flat array client-side
 * (buildChildrenMap()) and recurses from the roots (parent_placement_id
 * === 0) to render nested cards with per-depth indentation, an
 * expand/collapse toggle (only shown on a node with children), and a
 * per-node "+ child" action that scopes the next palette click to insert
 * AS A CHILD of that node instead of at the slot root. Reordering stays
 * up/down buttons, never drag-and-drop (this ecosystem's standing rule)
 * — moveCard() is SIBLING-scoped (swaps within the same
 * parent_placement_id group, wherever those siblings fall in the flat
 * array) rather than swapping raw array neighbors. Moving a node to a
 * DIFFERENT parent is a separate action — the inspector's "Parent"
 * dropdown (renderParentField()) — which also client-side-excludes a
 * node's own descendants from the list (the real cycle guard is
 * server-side in class-element.php's would_create_cycle(), this is just
 * not offering an obviously-bad choice in the UI). class-element.php's
 * rest_save_placements() computes 'position' per parent group from
 * submission order — see that method's own comment for the exact
 * contract. A brand-new (unsaved, id === 0) node cannot itself be an
 * add-child target — "+ child" only renders for nodes with a real, saved
 * id — so adding a parent and a child in the SAME unsaved batch isn't
 * supported; save the parent first.
 *
 * 3.4.36 — a literal, permanent, synthetic "Site" root was introduced
 * above the tree (no real DB row — see class-element.php's own docblock
 * on why a synthetic client-recognized root was chosen over a real
 * zeroth placement row), and the old standing "Library" rail column
 * became a floating contextual popup (openAddChildPicker()/
 * closeAddChildPicker()) opened from a node's "+" button or its
 * right-click context menu (showContextMenu()/buildNodeContextMenuItems())
 * — never a permanent panel, still click-to-open/click-to-choose, never
 * drag-and-drop. BH_Element_Prefab gained full-subtree save/restore this
 * pass too (class-element-prefab.php's save_from_node()/instantiate());
 * the "Save this subtree as a prefab…" context-menu action and the
 * Prefabs "Insert" action's under_placement_id param are the two
 * client-side call sites for that. When this file is embedded in
 * BHY_Gallery's unified Design Suite shell (class-style-gallery.php),
 * every selection change fires a 'bhel:selection' DOM CustomEvent that
 * page listens for to toggle its own #bhy-controls-panel (Global Styles)
 * against this file's #bhy-widgets-inspector-mount — see that file's
 * render_script() for the coordination mechanism.
 *
 * 3.4.37 — DESIGN-SUITE-UNIFICATION-PLAN.md's "FINAL ARCHITECTURE" note
 * turned out to still be half-built as of 3.4.36: the ONE tree existed,
 * but reaching any given surface/slot's tree STILL required the old
 * topbar's manual Surface/Slot <select> pair (plus a hidden "Context ID"
 * spinner and standing "Save slot"/"Save as Prefab" buttons) — exactly
 * the "raw form sitting on top of a tree" shape AJ's model has no room
 * for. This pass finishes the correction: EVERY registered surface (GET
 * .../elements/surfaces, unchanged route/shape) and EVERY slot it
 * declares now renders as real, permanent, synthetic tree nodes —
 * Site -> Surface -> Slot -> real placements — fetched automatically for
 * every surface up front at boot (loadSurfaceData() called once per
 * surface, reusing the exact same GET .../elements/placements/{surface}/
 * {context_id} route the old topbar's Slot dropdown used to call
 * on-demand for ONE surface at a time — no new route). Concretely:
 *   - state.surfaces (unchanged shape) now drives renderCanvas()'s outer
 *     loop directly — one Surface node per registered surface, grouped
 *     by the surface's own 'group' label (renderSurfaceNode()), exactly
 *     the grouping BHY_Gallery's separate "Preview surface" rail already
 *     uses for a different registry (bhy_style_surfaces), reused here
 *     for visual consistency, not code-shared (different data).
 *   - state.slotData holds one placements-array-per-slot PER (surface,
 *     context_id) pair, keyed by slotDataKey() — replacing the old
 *     single-slot-scoped state.placements. getPlacementsArray(loc) is
 *     the one place that resolves a {surface,slot,contextId} location to
 *     its live array; every placement-tree function below (buildChildrenMap,
 *     descendantIds, renderTreeNode, moveCard, renderParentField,
 *     addPlacement) now takes an explicit loc/placements pair instead of
 *     reading single global state.surface/state.slot/state.contextId
 *     fields, which no longer exist — there is no longer exactly ONE
 *     "current" surface/slot, there are as many as are expanded in the
 *     tree at once.
 *   - Selecting a Surface or Slot node just expands/collapses it and
 *     shows a plain label in the inspector (renderSlotInspector()) — no
 *     raw form, matching how Site itself has no delete/move affordance.
 *   - Context ID (§ per-entity surfaces like a CRM profile, where
 *     surface_context_id is a real entity id, not always 0): the
 *     surfaces registry (rest_get_surfaces()) carries no entity metadata
 *     to resolve a friendly name picker from — there is nothing in the
 *     REST response to look up a real person/entity's display name by
 *     id. JUDGMENT CALL, recorded honestly: a plain numeric "Context ID"
 *     field is offered, but ONLY inline in the Slot inspector, only
 *     while that slot is selected — never a permanent topbar field. This
 *     is a real, disclosed reduction in polish versus a true resolved
 *     picker; a future pass could add one IF a surface's registration
 *     manifest grows an actual "list known context ids" callback
 *     server-side to power it. Changing it re-fetches that surface's
 *     whole placement set at the new context id (state.surfaceContext).
 *   - Saving: editing a field only ever mutates in-memory state (marks
 *     state.dirtyKeys[slot key] = true) — it never persisted on its own
 *     even before this pass (savePlacements() was always an explicit,
 *     batched POST). With many surfaces/slots now loaded simultaneously
 *     there is no longer a single "current slot" for a per-slot Save
 *     button to target, so the old topbar "Save slot" button is GONE,
 *     replaced by ONE global "Save all changes" action (globalSave(),
 *     now the topbar's only remaining control besides the status line)
 *     that POSTs every dirty (surface, context_id, slot) in turn against
 *     the SAME unchanged POST .../elements/placements/{surface}/
 *     {context_id} route (still slot-scoped server-side — one POST per
 *     dirty slot, batched client-side via Promise.all), then reloads
 *     every touched surface so client-side ids (0 for new rows) become
 *     the real saved ids, exactly the same reload-after-save posture the
 *     old single-slot savePlacements() used.
 *   - The old whole-slot "Save as Prefab" topbar button is now
 *     saveSlotAsPrefab(), a small action on the Slot node's own row —
 *     same unchanged REST call (POST .../elements/prefabs with
 *     surface/context_id/slot), just relocated off the removed topbar
 *     form onto the tree node it actually concerns, rather than dropped.
 *   - class-style-gallery.php's reparentWidgets() (the left-rail mount
 *     for this whole shell) needed no structural change: it still just
 *     relocates .bhel-topbar/.bhel-canvas/.bhel-inspector wholesale —
 *     .bhel-topbar itself is simply much smaller now (status + one
 *     button, no <select>/<input> fields at all). See that file's own
 *     updated comment.
 */
(function () {
    'use strict';

    var cfg = window.bhElementBuilderConfig || {};
    var root = document.getElementById('bhel-app');
    if (!root || !cfg.restUrl) return;

    /* ---------------- tiny REST helper — same X-WP-Nonce contract BH_Studio's studio.js uses ---------------- */
    function api(path, opts) {
        opts = opts || {};
        var headers = opts.headers || {};
        headers['X-WP-Nonce'] = cfg.nonce;
        if (opts.body) headers['Content-Type'] = 'application/json';
        return fetch(cfg.restUrl + path, {
            method: opts.method || 'GET',
            credentials: 'same-origin',
            headers: headers,
            body: opts.body ? JSON.stringify(opts.body) : undefined,
        }).then(function (res) {
            if (!res.ok) {
                return res.json().catch(function () { return {}; }).then(function (err) {
                    throw new Error((err && err.message) || ('HTTP ' + res.status));
                });
            }
            return res.json();
        });
    }

    /* ---------------- Content Studio modal (DESIGN-SUITE-UNIFICATION-PLAN.md unification pass) ----------------
     * A lightweight iframe-in-a-lightbox over bh-studio's existing,
     * unchanged admin page — chosen over fully inlining Studio's
     * @wordpress/block-editor canvas into this inspector (real scope/risk
     * call for this pass, see DESIGN-SUITE-UNIFICATION-PLAN.md's status
     * note) while still honoring "there is no difference between the
     * two": no full-page navigation away from this screen ever happens,
     * the URL bar never changes, and closing the modal returns to the
     * exact same tree/canvas/inspector state underneath it. */
    function openStudioModal(contextId) {
        closeStudioModal();
        var overlay = el('div', 'bhel-studio-modal-overlay');
        var box = el('div', 'bhel-studio-modal-box');
        var bar = el('div', 'bhel-studio-modal-bar');
        bar.appendChild(el('strong', null, 'Content Studio'));
        var closeBtn = el('button', 'button bhel-studio-modal-close', 'Close');
        closeBtn.type = 'button';
        closeBtn.addEventListener('click', closeStudioModal);
        bar.appendChild(closeBtn);
        var frame = document.createElement('iframe');
        frame.className = 'bhel-studio-modal-frame';
        var src = (cfg.studioUrl || (window.location.origin + '/wp-admin/admin.php?page=bh-studio'));
        src += (src.indexOf('?') === -1 ? '?' : '&') + 'context_type=bh_element&context_id=' + encodeURIComponent(contextId || 0);
        frame.src = src;
        box.appendChild(bar);
        box.appendChild(frame);
        overlay.appendChild(box);
        overlay.addEventListener('click', function (ev) { if (ev.target === overlay) closeStudioModal(); });
        document.body.appendChild(overlay);
    }
    function closeStudioModal() {
        var existing = document.querySelector('.bhel-studio-modal-overlay');
        if (existing) existing.remove();
    }

    /* ---------------- state ---------------- */
    var state = {
        surfaces: {},          // slug => {group,label,slots} — GET .../elements/surfaces, unchanged shape
        typesBySurface: {},    // surface slug => types manifest for THAT surface (GET .../elements/types?surface=), loaded lazily (boot + on first add-child pick)
        sources: {},           // slug => manifest (current attr-kind filtered lookups cached separately)
        surfaceContext: {},    // surface slug => the context_id currently being viewed for that surface (default 0 for every surface at boot)
        // 3.4.37 — one placements array PER (surface, context_id, slot),
        // replacing the old single-slot-scoped state.placements. Keyed by
        // slotDataKey(surface, contextId) => { slotSlug => [placements] }.
        // getPlacementsArray(loc) is the one place that resolves a
        // {surface,slot,contextId} location to its live array.
        slotData: {},
        // 3.4.37 — the ONE selection, now one of four shapes:
        //   {type:'site'}
        //   {type:'surface', surface}
        //   {type:'slot', surface, slot}
        //   {type:'placement', surface, slot, contextId, idx}
        // There is no real placement row for Site, nor for a Surface/Slot
        // node — those three are purely client-side selection/organizational
        // concepts, never sent to any REST route.
        selection: { type: 'site' },
        // 3.4.37 — dirty tracking is per (surface, context_id, slot), since
        // that's the exact granularity POST .../elements/placements/
        // {surface}/{context_id} saves at (one 'slot' field per call).
        // Key = surface + ' ' + contextId + ' ' + slot.
        dirtyKeys: {},
        prefabs: [],        // list summaries — id/slug/name/description/element_count/element_types
        styleSchema: { groups: {}, colorTokens: {} }, // GET style-schema — §2.6 property-group preset tables, loaded once at boot
        pendingTarget: null, // {surface,slot,contextId,parentId} while the add-child popup is open; null otherwise
        collapsedIds: {},    // real placement id => true when that node's children are hidden — absent/false = expanded (the default)
        collapsedNodes: {},  // synthetic node key ('surface:'+slug or 'slot:'+surface+':'+slot) => true when collapsed
        siteTokens: null,    // GET .../elements/site-tokens (BHY_Style::get()'s global option row) — the Site node's own inspector data, loaded once at boot
    };

    // The ONE selection-change funnel — every place that used to set
    // state.selectedIndex/state.siteSelected directly now goes through
    // one of these four, so state.selection can never be internally
    // inconsistent and the 'bhel:selection' CustomEvent (class-style-
    // gallery.php's render_script() listens for it to toggle
    // #bhy-controls-panel vs #bhy-widgets-inspector-mount when this file
    // is embedded in the unified Design Suite shell) is ALWAYS fired on a
    // real change. detail.type stays the same two-value 'site'/'placement'
    // contract that page already listens for — a Surface/Slot selection
    // is bucketed as 'placement' (i.e. "show this file's own inspector
    // column, not Global Styles"), since neither has global-style content.
    // AJ's own ask: "the whole thing should save your state and pick up
    // where you left off when you come back." A plain localStorage
    // mirror of state.selection — the ONE shape every select*() function
    // already funnels through (this function's own docblock), so this is
    // one write site, not one per selection-changing call. Guarded by
    // persistenceReady so the boot-time default fireSelectionEvent(true)
    // call (a few lines below, before any restore attempt has happened)
    // never clobbers a real saved selection from a PREVIOUS session with
    // today's fresh-page-load default of {type:'site'}.
    var SELECTION_STORAGE_KEY = 'bhelDesignSuiteSelection';
    var persistenceReady = false;
    function saveSelectionState() {
        if (!persistenceReady) return;
        try { localStorage.setItem(SELECTION_STORAGE_KEY, JSON.stringify(state.selection)); } catch (e) { /* private browsing / storage disabled — just don't persist, never break selection itself over this */ }
    }
    // Called once, after surfaces/placements have actually loaded (see
    // the api('surfaces') boot sequence below) — restoring a 'surface'/
    // 'slot'/'placement' selection any earlier would target data that
    // doesn't exist in state yet. Returns true if something was actually
    // restored, so the caller knows whether to fall back to the default
    // Site selection.
    function restoreSelectionState() {
        var saved;
        try {
            var raw = localStorage.getItem(SELECTION_STORAGE_KEY);
            if (!raw) return false;
            saved = JSON.parse(raw);
        } catch (e) { return false; }
        if (!saved || !saved.type) return false;

        if (saved.type === 'site') { selectSite(); return true; }
        if (saved.type === 'surface' && state.surfaces[saved.surface]) { selectSurface(saved.surface); return true; }
        if (saved.type === 'slot' && state.surfaces[saved.surface]) { selectSlot(saved.surface, saved.slot); return true; }
        if (saved.type === 'placement' && state.surfaces[saved.surface]) {
            selectPlacementNode({ surface: saved.surface, slot: saved.slot, contextId: saved.contextId }, saved.idx);
            return true;
        }
        // 'demo' selections aren't restorable from here — they depend on
        // which .bhy-story-btn was last active, a DOM/state concept that
        // lives entirely in class-style-gallery.php's own script, not
        // this file's state.surfaces. That file persists/restores its
        // own last-active story button independently (see its own
        // render_script() comment) and re-dispatches 'bhel:select-surface'
        // on restore, which is exactly the same event a real click would
        // fire — so a restored demo selection still ends up correct, just
        // driven from the other side of that same existing boundary.
        return false;
    }

    function fireSelectionEvent(isSite) {
        // 3.4.38 — detail.surface carries the currently-selected node's
        // surface slug (undefined for the Site node, which has none) so
        // class-style-gallery.php's render_script() can also sync the
        // center canvas's active .bhy-story-frame/.bhy-story-btn to match
        // tree selection — previously the "Preview surface" story picker
        // and the tree were two fully independent selection systems with
        // no relationship, confirmed via live screenshot as a real UX gap.
        // detail.type keeps its existing two-value 'site'/'placement'
        // contract unchanged (see this function's own comment above) —
        // this only adds a new key, never changes the existing one.
        document.dispatchEvent(new CustomEvent('bhel:selection', { detail: { type: isSite ? 'site' : 'placement', surface: isSite ? undefined : (state.selection && state.selection.surface) } }));
        saveSelectionState();
    }
    function selectSite() {
        state.selection = { type: 'site' };
        fireSelectionEvent(true);
        renderCanvas();
        renderInspector();
    }
    function selectSurface(surface) {
        state.selection = { type: 'surface', surface: surface };
        fireSelectionEvent(false);
        renderCanvas();
        renderInspector();
    }
    function selectSlot(surface, slot) {
        state.selection = { type: 'slot', surface: surface, slot: slot };
        fireSelectionEvent(false);
        renderCanvas();
        renderInspector();
    }

    // The other half of class-style-gallery.php's story-button click
    // handler (its own updated comment has the full "why" — direct,
    // live-confirmed feedback that clicking a Live View updated the
    // canvas but left the tree/inspector showing a stale, unrelated
    // selection). When the clicked story's surface slug IS a real
    // registered BH_Element surface, select its matching tree node for
    // real. Otherwise (a hand-authored demo-only mockup — bh-contest,
    // bh-streaming, the bh-courses catalog/lesson-step previews, etc.,
    // none of which have a tree node at all) this now explicitly clears
    // to the 'demo' inspector state (renderInspector()'s own new
    // branch) instead of silently leaving whatever was selected before
    // — a second round of live feedback confirmed the stale-inspector
    // case specifically ("still not doing what it's supposed to"),
    // distinct from the earlier fix that only handled the real-surface
    // half of this.
    document.addEventListener('bhel:select-surface', function (ev) {
        var slug = ev.detail && ev.detail.surface;
        if (!slug) return;
        if (state.surfaces[slug]) {
            selectSurface(slug);
            return;
        }
        var label = ev.detail && ev.detail.label;
        state.selection = { type: 'demo', label: label };
        fireSelectionEvent(false);
        renderInspector();
    });
    function selectPlacementNode(loc, idx) {
        state.selection = { type: 'placement', surface: loc.surface, slot: loc.slot, contextId: loc.contextId, idx: idx };
        fireSelectionEvent(false);
        renderCanvas();
        renderInspector();
    }

    var CONTEXT_TOKENS = ['context.user_id', 'context.post_id', 'context.entity_id', 'context.viewer_id'];

    /* ---------------- location / data-store helpers (3.4.37) ---------------- */
    function slotDataKey(surface, contextId) { return surface + ' ' + (contextId || 0); }
    function dirtyKey(loc) { return loc.surface + ' ' + (loc.contextId || 0) + ' ' + loc.slot; }
    // DRY pass: every inspector field handler needs the exact same two-
    // step "mark this (surface, context, slot) dirty, then debounce a
    // live-canvas preview" sequence — 3.4.49's own live-preview wiring
    // pasted markLocDirty(loc);
    // at ~20 separate call sites instead of naming it once. Named here;
    // schedulePreviewUpdate is defined further down this file (near
    // markDirty()) but that's fine — plain function declarations are
    // hoisted within this IIFE's scope, so source order doesn't matter.
    function markLocDirty(loc) {
        state.dirtyKeys[dirtyKey(loc)] = true;
        schedulePreviewUpdate(loc);
    }
    function getSurfaceSlotMap(surface, contextId) {
        var k = slotDataKey(surface, contextId);
        if (!state.slotData[k]) state.slotData[k] = {};
        return state.slotData[k];
    }
    function getPlacementsArray(loc) {
        var map = getSurfaceSlotMap(loc.surface, loc.contextId);
        if (!map[loc.slot]) map[loc.slot] = [];
        return map[loc.slot];
    }

    /* ---------------- boot ---------------- */
    root.innerHTML = '';
    // 3.4.37 — the topbar is now just a status line and ONE global save
    // action — every Surface/Slot/Context-ID control that used to live
    // here is gone; see this file's own updated docblock for exactly
    // where each one moved to (a tree node action / an inline inspector
    // field / a right-click context menu item).
    var topbar = el('div', 'bhel-topbar');
    var status = el('span', 'bhel-status');
    var saveAllBtn = el('button', 'button button-primary bhel-save-btn');
    saveAllBtn.type = 'button';
    saveAllBtn.textContent = 'Save all changes';
    saveAllBtn.title = 'Persists every unsaved edit across every surface/slot at once. Surfaces and slots are tree nodes now, not a single manually-selected context, so there is one global save action instead of a per-slot "Save slot" button.';
    saveAllBtn.addEventListener('click', globalSave);
    topbar.appendChild(status);
    topbar.appendChild(saveAllBtn);
    root.appendChild(topbar);

    var layout = el('div', 'bhel-layout');
    // paletteEl is the contextual add-child POPUP (see openAddChildPicker()/
    // closeAddChildPicker() and renderCanvas(), which appends it inside
    // .bhel-canvas — the tree's own container — rather than into `layout`
    // directly, so it's always positioned relative to the tree it's
    // adding into).
    var paletteEl = el('div', 'bhel-palette bhel-add-child-popup');
    var canvasEl = el('div', 'bhel-canvas');
    // §2.5 — the inspector is the mobile "bottom sheet": at ≤782px
    // (WP admin's own breakpoint) this element is fixed to the viewport
    // bottom and slid up over the canvas only while a placement is
    // selected (.bhel-sheet-open, toggled below), rather than being a
    // permanent third rail — see the CSS for the actual sheet chrome
    // (drag handle + dismiss control).
    var inspectorEl = el('div', 'bhel-inspector');
    var inspectorHandle = el('div', 'bhel-sheet-handle');
    var inspectorCloseBtn = el('button', 'button bhel-sheet-close', 'Done');
    inspectorCloseBtn.type = 'button';
    inspectorCloseBtn.addEventListener('click', selectSite);
    layout.appendChild(canvasEl);
    layout.appendChild(inspectorEl);
    root.appendChild(layout);

    setStatus('Loading surfaces…');

    // 3.4.37 — fetches EVERY registered surface's placements (and type
    // manifest) up front, instead of on-demand for whichever ONE surface
    // the old topbar's Surface <select> happened to have chosen. Both
    // calls are the SAME existing routes the old topbar used one-at-a-time
    // (GET .../elements/placements/{surface}/{context_id} and GET
    // .../elements/types?surface=) — no new route.
    api('surfaces').then(function (surfaces) {
        state.surfaces = surfaces || {};
        var slugs = Object.keys(state.surfaces);
        if (!slugs.length) {
            setStatus('No surfaces registered yet (bh_element_surfaces filter is empty).', 'error');
            renderCanvas();
            renderInspector();
            return;
        }
        renderCanvas(); // paint the Site/Surface/Slot shell immediately; real placements fill in as each surface's fetch below resolves
        renderInspector();
        var loads = slugs.map(function (slug) {
            state.surfaceContext[slug] = 0;
            return Promise.all([loadSurfaceData(slug, 0), ensureTypesLoaded(slug)]);
        });
        Promise.all(loads).then(function () {
            setStatus('Loaded ' + slugs.length + ' surface(s).', 'ok');
            // Real placement data now exists in state — safe to attempt
            // restoring a saved 'surface'/'slot'/'placement' selection.
            // persistenceReady flips true regardless of whether a saved
            // selection actually existed/matched, so every selection
            // change FROM this point on gets persisted for next time.
            var restored = restoreSelectionState();
            persistenceReady = true;
            if (!restored) saveSelectionState(); // nothing to restore — persist today's default ({type:'site'}) so it's there next load
            renderCanvas();
            renderInspector();
        });
    }).catch(function (e) {
        setStatus('Failed to load surfaces: ' + e.message, 'error');
    });

    loadPrefabs();
    loadStyleSchema();
    loadSiteTokens();
    // Site is the tree's default selection — fire the coordination event
    // once at boot too, so an embedding class-style-gallery.php page shows
    // #bhy-controls-panel from the very first paint, not just after the
    // first click.
    fireSelectionEvent(true);

    // GET .../elements/site-tokens — see class-element.php's
    // rest_get_site_tokens() docblock. Non-fatal on failure, same
    // graceful-degrade posture as loadPrefabs()/loadStyleSchema().
    function loadSiteTokens() {
        api('site-tokens').then(function (tokens) {
            state.siteTokens = tokens || {};
            if (state.selection.type === 'site') renderInspector();
        }).catch(function () {
            state.siteTokens = null;
        });
    }

    // 3.4.27 — loaded once at boot (the schema is process-global, not
    // per-surface/per-type), non-fatal on failure same as loadPrefabs():
    // the Style — Advanced section just renders empty rather than
    // blocking the rest of the GUI if BHY_Style somehow isn't loaded.
    function loadStyleSchema() {
        api('style-schema').then(function (schema) {
            state.styleSchema = schema || { groups: {}, colorTokens: {} };
            if (state.selection.type === 'placement') renderInspector();
        }).catch(function () {
            state.styleSchema = { groups: {}, colorTokens: {} };
        });
    }

    // Fetches ALL slots for ONE surface at ONE context id in a single
    // call (GET .../elements/placements/{surface}/{context_id} already
    // returns every slot, grouped — unchanged shape) and normalizes each
    // row exactly as the pre-3.4.37 loadSlotPlacements() did.
    function loadSurfaceData(surfaceSlug, contextId) {
        return api('placements/' + encodeURIComponent(surfaceSlug) + '/' + (contextId || 0)).then(function (grouped) {
            grouped = grouped || {};
            var map = {};
            Object.keys(grouped).forEach(function (slotSlug) {
                var rows = (grouped[slotSlug] || []).slice();
                rows.forEach(function (p) {
                    p.config = p.config || {};
                    p.config.attrs = p.config.attrs || {};
                    p.config.style = p.config.style || {};
                    p.config.htmlAttrs = p.config.htmlAttrs || {};
                    p.config.htmlAttrs.custom = p.config.htmlAttrs.custom || {};
                    p.parent_placement_id = (typeof p.parent_placement_id === 'number') ? p.parent_placement_id : (parseInt(p.parent_placement_id, 10) || 0);
                });
                map[slotSlug] = rows;
            });
            state.slotData[slotDataKey(surfaceSlug, contextId)] = map;
            renderCanvas();
            if (state.selection.type === 'placement' && state.selection.surface === surfaceSlug && (state.selection.contextId || 0) === (contextId || 0)) renderInspector();
        }).catch(function (e) {
            setStatus('Failed to load placements for "' + surfaceSlug + '": ' + e.message, 'error');
        });
    }

    // Lazily loads (and caches) ONE surface's type manifest — same GET
    // .../elements/types?surface= route the old topbar's Surface <select>
    // change handler used to call every time. Cached per surface slug so
    // reopening the add-child popup for the same surface never re-fetches.
    function ensureTypesLoaded(surfaceSlug) {
        if (state.typesBySurface[surfaceSlug]) return Promise.resolve(state.typesBySurface[surfaceSlug]);
        return api('types?surface=' + encodeURIComponent(surfaceSlug)).then(function (types) {
            state.typesBySurface[surfaceSlug] = types || {};
            return state.typesBySurface[surfaceSlug];
        }).catch(function (e) {
            setStatus('Failed to load types for "' + surfaceSlug + '": ' + e.message, 'error');
            state.typesBySurface[surfaceSlug] = {};
            return state.typesBySurface[surfaceSlug];
        });
    }

    /* ---------------- prefabs (loaded once, rendered as a palette section) ---------------- */
    function loadPrefabs() {
        api('prefabs').then(function (list) {
            state.prefabs = list || [];
            if (state.pendingTarget) renderPalette();
        }).catch(function () {
            // Non-fatal — the prefab REST routes might be unavailable
            // (e.g. BH_Element_Prefab not loaded); the palette simply
            // shows no Prefabs section rather than blocking the rest of
            // the GUI, same graceful-degrade posture as every other
            // class_exists()-guarded registrant in this ecosystem.
            state.prefabs = [];
        });
    }

    function renderPrefabSection() {
        if (!state.prefabs.length) return;
        paletteEl.appendChild(el('div', 'bhel-palette-group', 'Prefabs'));
        state.prefabs.forEach(function (prefab) {
            var item = el('div', 'bhel-palette-item bhel-prefab-item');
            var txt = el('span', null, prefab.name + ' (' + prefab.element_count + ' element' + (prefab.element_count === 1 ? '' : 's') + ')');
            txt.title = (prefab.description || '') + (prefab.element_types.length ? ('\nContains: ' + prefab.element_types.join(', ')) : '');
            item.appendChild(txt);
            var insertBtn = document.createElement('button');
            insertBtn.type = 'button';
            insertBtn.className = 'button';
            insertBtn.textContent = 'Insert';
            insertBtn.title = 'Instantiate a fresh, independent copy of this prefab into the current surface/slot/context — editing the copy will never change this saved prefab.';
            insertBtn.addEventListener('click', function () { instantiatePrefab(prefab.id); });
            item.appendChild(insertBtn);
            paletteEl.appendChild(item);
        });
    }

    // Instantiates server-side (BH_Element_Prefab::instantiate() — the
    // real deep-copy logic, including any container's BH_Content tree,
    // lives entirely there, not in this file) directly into whichever
    // surface/slot/context the add-child popup was opened against
    // (state.pendingTarget), then reloads that surface so the new
    // placements show up immediately.
    function instantiatePrefab(prefabId) {
        var target = state.pendingTarget;
        if (!target || !target.surface || !target.slot) return;
        var loc = { surface: target.surface, slot: target.slot, contextId: target.contextId || 0 };
        if (state.dirtyKeys[dirtyKey(loc)] && !window.confirm('You have unsaved changes in this slot that will be discarded when the prefab is inserted and the slot reloads. Continue?')) {
            return;
        }
        var underId = target.parentId || 0;
        setStatus('Inserting prefab…');
        api('prefabs/' + prefabId + '/instantiate', {
            method: 'POST',
            body: { surface: loc.surface, context_id: loc.contextId, slot: loc.slot, under_placement_id: underId },
        }).then(function (res) {
            setStatus('Inserted ' + ((res.created && res.created.length) || 0) + ' element(s) from prefab' + (underId ? (' under #' + underId) : ' under the slot root') + '.', 'ok');
            closeAddChildPicker();
            delete state.dirtyKeys[dirtyKey(loc)];
            loadSurfaceData(loc.surface, loc.contextId);
        }).catch(function (e) {
            setStatus('Insert failed: ' + e.message, 'error');
        });
    }

    // "Save this slot as a prefab" — reads ONE slot's already-persisted
    // placements server-side (BH_Element_Prefab::save_from_slot()) and
    // snapshots them as a new prefab, including a full BH_Content tree
    // copy for any container placement. Deliberately does NOT send the
    // client's in-memory array (which may include unsaved edits) — asks
    // the user to save first if this slot is dirty. 3.4.37 — this used to
    // be the topbar's standing "Save as Prefab" button, scoped to whatever
    // surface/slot the topbar's <select> pair happened to have chosen;
    // it is now a small action on the Slot node's own inspector, scoped
    // explicitly to that node — same unchanged REST call either way.
    function saveSlotAsPrefab(surface, slot, contextId) {
        var loc = { surface: surface, slot: slot, contextId: contextId || 0 };
        if (state.dirtyKeys[dirtyKey(loc)]) {
            setStatus('Save this slot’s changes first ("Save all changes" in the topbar) — "Save as Prefab" snapshots the SAVED placements, not unsaved edits.', 'error');
            return;
        }
        var placements = getPlacementsArray(loc);
        if (!placements.length) {
            setStatus('Nothing in this slot to save as a prefab.', 'error');
            return;
        }
        var name = window.prompt('Prefab name:', surface + ' / ' + slot);
        if (name === null) return; // cancelled
        var description = window.prompt('Description (optional):', '') || '';

        setStatus('Saving prefab…');
        api('prefabs', {
            method: 'POST',
            body: { surface: surface, context_id: loc.contextId, slot: slot, name: name, description: description },
        }).then(function (prefab) {
            setStatus('Saved prefab "' + prefab.name + '".', 'ok');
            loadPrefabs();
        }).catch(function (e) {
            setStatus('Save as Prefab failed: ' + e.message, 'error');
        });
    }

    /* ---------------- contextual add-child picker (replaces the old standing "Library" rail section) ----------------
     * The palette of addable types is a picker shown ONLY when the user
     * performs an "add child" action on a specific tree location (a
     * Slot/placement node's own "+" button, or the right-click context
     * menu's "Add child…" item). paletteEl is still the same DOM node
     * this file has always built (renderPalette() below is otherwise
     * UNCHANGED — same category grouping, same GET .../elements/types-
     * driven rendering, same Prefabs section with "Insert" actions) — it
     * is simply mounted as a floating popup (see element-builder.css's
     * .bhel-add-child-popup rules) instead of a standing column, opened
     * by openAddChildPicker() and closed after a pick (or a click
     * outside it / Escape).
     */
    function renderPalette() {
        paletteEl.innerHTML = '';
        paletteEl.appendChild(el('div', 'bhel-pane-title', 'Add a child'));
        renderPrefabSection();
        var target = state.pendingTarget || {};
        var bySlug = state.typesBySurface[target.surface] || {};
        var groups = {};
        Object.keys(bySlug).forEach(function (slug) {
            var t = bySlug[slug];
            var cat = t.category || 'layout';
            groups[cat] = groups[cat] || [];
            groups[cat].push([slug, t]);
        });
        var catNames = Object.keys(groups);
        if (!catNames.length) {
            paletteEl.appendChild(el('p', 'bhel-empty', 'No element types are valid on this surface.'));
            return;
        }
        catNames.sort().forEach(function (cat) {
            paletteEl.appendChild(el('div', 'bhel-palette-group', cat));
            groups[cat].forEach(function (pair) {
                var slug = pair[0], type = pair[1];
                var btn = el('button', 'bhel-palette-item');
                btn.type = 'button';
                var icon = document.createElement('span');
                icon.className = 'dashicons ' + (type.icon || 'dashicons-admin-generic');
                btn.appendChild(icon);
                var txt = document.createElement('span');
                txt.textContent = type.label + (type.container ? ' (container)' : '');
                btn.appendChild(txt);
                btn.title = slug;
                btn.addEventListener('click', function () { addPlacement(slug, type); closeAddChildPicker(); });
                paletteEl.appendChild(btn);
            });
        });
    }

    // Opens the picker SCOPED to inserting a child of $parentId (0 = a
    // direct child of the slot root) within ONE (surface, slot,
    // contextId) location. Click-to-open, click-to-choose — never
    // drag-and-drop, this codebase's standing rule.
    function openAddChildPicker(surface, slot, contextId, parentId) {
        state.pendingTarget = { surface: surface, slot: slot, contextId: contextId || 0, parentId: parentId || 0 };
        ensureTypesLoaded(surface).then(function () {
            renderPalette(); // rebuild so the Prefabs section's "Insert" targets the CURRENT pendingTarget (see instantiatePrefab())
            paletteEl.classList.add('bhel-add-child-popup-open');
            var onOutside = function (ev) {
                if (paletteEl.contains(ev.target)) return;
                closeAddChildPicker();
            };
            var onEscape = function (ev) { if (ev.key === 'Escape') closeAddChildPicker(); };
            setTimeout(function () {
                document.addEventListener('click', onOutside, { capture: true, once: true });
                document.addEventListener('keydown', onEscape, { once: true });
            }, 0);
        });
    }
    function closeAddChildPicker() {
        paletteEl.classList.remove('bhel-add-child-popup-open');
        state.pendingTarget = null;
    }

    function addPlacement(slug, type) {
        var target = state.pendingTarget;
        if (!target || !target.surface || !target.slot) return;
        var loc = { surface: target.surface, slot: target.slot, contextId: target.contextId || 0 };
        var parentId = target.parentId || 0;
        var placements = getPlacementsArray(loc);
        placements.push({
            id: 0,
            element_type: slug,
            config: { attrs: {}, style: {}, htmlAttrs: { custom: {} } },
            content_context_id: 0,
            enabled: true,
            parent_placement_id: parentId,
        });
        markLocDirty(loc);
        var idx = placements.length - 1;
        selectPlacementNode(loc, idx);
        setStatus('Added "' + type.label + '"' + (parentId ? (' as a child of #' + parentId) : ' at the slot root') + ' — click Save all changes to persist.', '');
    }

    /* ---------------- canvas (center) — real recursive tree, one per registered surface+slot ---------------- */
    // Builds a parent_placement_id => [array indices] map over ONE
    // (surface, slot, contextId) location's flat placements array.
    // Indices, not row copies, are stored — every action (select/move/
    // add-child/disable) below operates on the real array by index.
    function buildChildrenMap(placements) {
        var map = {};
        placements.forEach(function (p, idx) {
            var parentId = p.parent_placement_id || 0;
            map[parentId] = map[parentId] || [];
            map[parentId].push(idx);
        });
        return map;
    }

    // All descendant ids of the placement at $idx within ONE location's
    // own tree (walking the SAME children map) — used by the inspector's
    // Parent dropdown to exclude obviously-cyclic choices from the UI.
    // Not the real cycle guard (class-element.php's would_create_cycle()
    // is, server-side); this is just "don't offer a choice we already
    // know the server will reject."
    function descendantIds(placements, idx, childrenMap) {
        var p = placements[idx];
        if (!p || !p.id) return []; // an unsaved (id === 0) node can't be anyone's saved parent yet, so it has no meaningful descendant set to exclude
        var out = [];
        var stack = (childrenMap[p.id] || []).slice();
        while (stack.length) {
            var childIdx = stack.pop();
            var child = placements[childIdx];
            if (child.id) out.push(child.id);
            (childrenMap[child.id] || []).forEach(function (i) { stack.push(i); });
        }
        return out;
    }

    // The ONE tree, Site as its literal, permanent root — now with real
    // Surface and Slot nodes as Site's descendants (3.4.37), instead of
    // requiring a topbar Surface/Slot <select> pair to decide which
    // single tree is even being looked at. Every registered surface's
    // every registered slot renders here, grouped by the surface's own
    // 'group' label, each recursing into the SAME renderTreeNode()
    // real-placement recursion 3.4.34 built.
    function renderCanvas() {
        canvasEl.innerHTML = '';
        canvasEl.appendChild(el('div', 'bhel-pane-title', 'Site'));
        renderSiteNode();

        var groups = {};
        var order = [];
        Object.keys(state.surfaces).forEach(function (slug) {
            var s = state.surfaces[slug];
            var g = s.group || '';
            if (!groups[g]) { groups[g] = []; order.push(g); }
            groups[g].push(slug);
        });
        order.sort();
        order.forEach(function (g) {
            if (g) canvasEl.appendChild(el('div', 'bhel-tree-group-heading', g));
            groups[g].sort().forEach(function (surfaceSlug) {
                renderSurfaceNode(surfaceSlug);
            });
        });
        if (!order.length) {
            var empty = el('p', 'bhel-empty');
            empty.style.marginLeft = '20px';
            empty.textContent = 'No surfaces registered yet.';
            canvasEl.appendChild(empty);
        }
        canvasEl.appendChild(paletteEl); // the add-child popup — hidden unless openAddChildPicker() opened it
    }

    // The tree's permanent root row. Depth 0, never indented, never
    // collapsible, never reparentable/deletable/addable-to directly (its
    // only children are the synthetic Surface nodes below, never a real
    // placement). Selecting it is what makes renderInspector() show
    // Global Styles instead of a Surface/Slot/placement's own inspector.
    function renderSiteNode() {
        var row = el('div', 'bhel-card bhel-tree-node bhel-site-node' + (state.selection.type === 'site' ? ' active' : ''));
        row.appendChild(el('span', 'bhel-tree-toggle bhel-tree-toggle-spacer'));
        var main = el('div', 'bhel-card-main');
        main.appendChild(el('div', 'bhel-card-label', 'Site'));
        main.appendChild(el('div', 'bhel-card-meta', 'Global styles · every surface/slot nests below'));
        row.appendChild(main);
        row.addEventListener('click', selectSite);
        canvasEl.appendChild(row);
    }

    // A registered surface (e.g. "Dashboard", "CRM Profile") as a
    // synthetic, permanent tree node — Site's direct child. Expands to
    // show every slot that surface declares. No real placement is ever a
    // direct child of a Surface node; only Slot nodes are.
    function renderSurfaceNode(surfaceSlug) {
        var surface = state.surfaces[surfaceSlug] || {};
        var nodeKey = 'surface:' + surfaceSlug;
        var collapsed = !!state.collapsedNodes[nodeKey];
        var isSelected = state.selection.type === 'surface' && state.selection.surface === surfaceSlug;

        var row = el('div', 'bhel-card bhel-tree-node bhel-surface-node' + (isSelected ? ' active' : ''));
        row.style.marginLeft = '20px';
        var toggle = iconBtn(collapsed ? '▸' : '▾', collapsed ? 'Expand slots' : 'Collapse slots', function (e) {
            e.stopPropagation();
            state.collapsedNodes[nodeKey] = !collapsed;
            renderCanvas();
        });
        toggle.className += ' bhel-tree-toggle';
        row.appendChild(toggle);
        var main = el('div', 'bhel-card-main');
        main.appendChild(el('div', 'bhel-card-label', surface.label || surfaceSlug));
        main.appendChild(el('div', 'bhel-card-meta', surfaceSlug));
        row.appendChild(main);
        row.addEventListener('click', function () { selectSurface(surfaceSlug); });
        canvasEl.appendChild(row);

        if (collapsed) return;
        var slots = surface.slots || {};
        Object.keys(slots).sort().forEach(function (slotSlug) {
            renderSlotNode(surfaceSlug, slotSlug, slots[slotSlug] || {});
        });
        if (!Object.keys(slots).length) {
            var empty = el('p', 'bhel-empty');
            empty.style.marginLeft = '40px';
            empty.textContent = 'This surface declares no slots.';
            canvasEl.appendChild(empty);
        }
    }

    // One slot within a surface (e.g. "Main", "Header") as a synthetic,
    // permanent tree node — a Surface node's direct child, and the
    // parent every REAL placement in this location ultimately nests
    // under (parent_placement_id === 0 means "direct child of this slot
    // root", exactly as parent_placement_id === 0 meant "direct child of
    // Site" before this pass, just now scoped per slot instead of
    // globally).
    function renderSlotNode(surfaceSlug, slotSlug, slotDef) {
        var contextId = state.surfaceContext[surfaceSlug] || 0;
        var nodeKey = 'slot:' + surfaceSlug + ':' + slotSlug;
        var collapsed = !!state.collapsedNodes[nodeKey];
        var isSelected = state.selection.type === 'slot' && state.selection.surface === surfaceSlug && state.selection.slot === slotSlug;
        var loc = { surface: surfaceSlug, slot: slotSlug, contextId: contextId };

        var row = el('div', 'bhel-card bhel-tree-node bhel-slot-node' + (isSelected ? ' active' : ''));
        row.style.marginLeft = '40px';
        var toggle = iconBtn(collapsed ? '▸' : '▾', collapsed ? 'Expand placements' : 'Collapse placements', function (e) {
            e.stopPropagation();
            state.collapsedNodes[nodeKey] = !collapsed;
            renderCanvas();
        });
        toggle.className += ' bhel-tree-toggle';
        row.appendChild(toggle);
        var main = el('div', 'bhel-card-main');
        main.appendChild(el('div', 'bhel-card-label', slotDef.label || slotSlug));
        main.appendChild(el('div', 'bhel-card-meta', slotSlug + (contextId ? (' · context #' + contextId) : '')));
        row.appendChild(main);
        var actions = el('div', 'bhel-card-actions');
        // 3.4.42 — same '+ child' -> '+' shrink as the placement row's own
        // add-child button (renderTreeNode()'s own updated comment) —
        // consistent single-glyph action buttons across every row kind.
        actions.appendChild(iconBtn('+', 'Add a placement to this slot', function (e) {
            e.stopPropagation();
            openAddChildPicker(surfaceSlug, slotSlug, contextId, 0);
        }));
        row.appendChild(actions);
        row.addEventListener('click', function () { selectSlot(surfaceSlug, slotSlug); });
        canvasEl.appendChild(row);

        if (collapsed) return;
        var placements = getPlacementsArray(loc);
        var childrenMap = buildChildrenMap(placements);
        (childrenMap[0] || []).forEach(function (idx) {
            renderTreeNode(loc, placements, idx, 3, childrenMap);
        });
        if (!placements.length) {
            var empty = el('p', 'bhel-empty');
            empty.style.marginLeft = '60px';
            empty.textContent = 'Nothing in this slot yet — use the "+" above to add one.';
            canvasEl.appendChild(empty);
        }
    }

    /* ---------------- right-click context menu (real, node-scoped actions) ---------------- */
    function showContextMenu(x, y, items) {
        var existing = document.querySelector('.bhel-context-menu');
        if (existing) existing.remove();
        var menu = el('div', 'bhel-context-menu');
        menu.style.left = x + 'px';
        menu.style.top = y + 'px';
        items.forEach(function (item) {
            if (item === '-') { menu.appendChild(el('div', 'bhel-context-menu-sep')); return; }
            var btn = el('button', 'bhel-context-menu-item', item.label);
            btn.type = 'button';
            btn.addEventListener('click', function () { menu.remove(); item.action(); });
            menu.appendChild(btn);
        });
        document.body.appendChild(menu);
        setTimeout(function () {
            document.addEventListener('click', function onOutside(ev) {
                if (!menu.contains(ev.target)) { menu.remove(); }
            }, { once: true, capture: true });
        }, 0);
    }

    function buildNodeContextMenuItems(loc, p, idx) {
        var items = [
            { label: 'Add child…', action: function () { openAddChildPicker(loc.surface, loc.slot, loc.contextId, p.id || 0); if (!p.id) setStatus('Save this placement first — an unsaved node can\'t be an add-child target yet.', 'error'); } },
        ];
        if (p.id) {
            items.push({ label: 'Save this subtree as a prefab…', action: function () { saveNodeAsPrefab(p.id); } });
        }
        items.push('-');
        items.push({ label: p.enabled === false ? 'Enable' : 'Disable', action: function () { p.enabled = !(p.enabled !== false); markLocDirty(loc); renderCanvas(); } });
        return items;
    }

    // The FINAL-ARCHITECTURE full-subtree prefab save (this node PLUS
    // every recursive descendant), against BH_Element_Prefab::
    // save_from_node() (class-element-prefab.php) via POST .../prefabs
    // { placement_id }. Requires the node to already be SAVED (a real
    // id) — the server walks the live DB tree, so an unsaved local edit
    // isn't part of the snapshot. Not scoped to a loc since a placement
    // id alone is enough for the server to walk its own subtree.
    function saveNodeAsPrefab(placementId) {
        var anyDirty = Object.keys(state.dirtyKeys).length > 0;
        if (anyDirty && !window.confirm('You have unsaved changes somewhere in the tree. "Save subtree as prefab" reads the SAVED database state, not unsaved edits. Save first?\n\nOK = I already saved / will save separately and understand this may be stale.\nCancel = go save first.')) {
            return;
        }
        var name = window.prompt('Prefab name:', 'Subtree of #' + placementId);
        if (name === null) return;
        var description = window.prompt('Description (optional):', '') || '';
        setStatus('Saving subtree as prefab…');
        api('prefabs', { method: 'POST', body: { placement_id: placementId, name: name, description: description } })
            .then(function (prefab) {
                setStatus('Saved prefab "' + prefab.name + '" (subtree).', 'ok');
                loadPrefabs();
            }).catch(function (e) {
                setStatus('Save subtree as prefab failed: ' + e.message, 'error');
            });
    }

    // Renders one node (placements[idx]) at tree $depth, then recurses
    // into its own children (unless collapsed). Real placements now
    // start at depth 3 (Site=0, Surface=1, Slot=2) — everything about the
    // per-node controls below is otherwise identical in behavior to the
    // pre-3.4.37 single-tree renderer, just addressed by loc+placements
    // instead of implicit globals.
    function renderTreeNode(loc, placements, idx, depth, childrenMap) {
        var p = placements[idx];
        var type = (state.typesBySurface[loc.surface] || {})[p.element_type] || {};
        var childIndices = (p.id && childrenMap[p.id]) ? childrenMap[p.id] : [];
        var hasChildren = childIndices.length > 0;
        var collapsed = p.id ? !!state.collapsedIds[p.id] : false;
        var isSelected = state.selection.type === 'placement' && state.selection.surface === loc.surface && state.selection.slot === loc.slot && (state.selection.contextId || 0) === (loc.contextId || 0) && state.selection.idx === idx;

        var card = el('div', 'bhel-card bhel-tree-node' + (isSelected ? ' active' : '') + (p.enabled === false ? ' disabled' : ''));
        card.style.marginLeft = (depth * 20) + 'px'; // per-depth indentation

        if (hasChildren) {
            var toggle = iconBtn(collapsed ? '▸' : '▾', collapsed ? 'Expand children' : 'Collapse children', function (e) {
                e.stopPropagation();
                state.collapsedIds[p.id] = !collapsed;
                renderCanvas();
            });
            toggle.className += ' bhel-tree-toggle';
            card.appendChild(toggle);
        } else {
            card.appendChild(el('span', 'bhel-tree-toggle bhel-tree-toggle-spacer'));
        }

        var main = el('div', 'bhel-card-main');
        main.appendChild(el('div', 'bhel-card-label', (type.label || p.element_type) + (p.enabled === false ? ' (disabled)' : '')));
        main.appendChild(el('div', 'bhel-card-meta', p.element_type + (p.id ? (' · #' + p.id) : ' · unsaved')));
        card.appendChild(main);

        var actions = el('div', 'bhel-card-actions');
        actions.appendChild(iconBtn('↑', 'Move up (within siblings)', function (e) { e.stopPropagation(); moveCard(loc, placements, idx, -1); }));
        actions.appendChild(iconBtn('↓', 'Move down (within siblings)', function (e) { e.stopPropagation(); moveCard(loc, placements, idx, 1); }));
        if (p.id) {
            // 3.4.42 — was '+ child' as literal button text, wide enough
            // on its own to force the actions row to wrap onto its own
            // line for almost every node (direct cause of the "too big,
            // shoved in" feedback on a live screenshot). The tooltip
            // (iconBtn's own 'title' param) already says "Add a child of
            // this node" in full — the button itself only needs '+',
            // same single-glyph sizing as the ↑/↓/✕ buttons next to it.
            actions.appendChild(iconBtn('+', 'Add a child of this node', function (e) {
                e.stopPropagation();
                openAddChildPicker(loc.surface, loc.slot, loc.contextId, p.id);
            }));
        }
        actions.appendChild(iconBtn('✕', p.enabled === false ? 'Enable' : 'Disable (no delete route — see file docblock)', function (e) {
            e.stopPropagation();
            p.enabled = !(p.enabled !== false);
            markLocDirty(loc);
            renderCanvas();
        }));
        card.appendChild(actions);

        card.addEventListener('click', function () {
            selectPlacementNode(loc, idx);
        });
        card.addEventListener('contextmenu', function (ev) {
            ev.preventDefault();
            showContextMenu(ev.clientX, ev.clientY, buildNodeContextMenuItems(loc, p, idx));
        });
        canvasEl.appendChild(card);

        if (hasChildren && !collapsed) {
            childIndices.forEach(function (childIdx) {
                renderTreeNode(loc, placements, childIdx, depth + 1, childrenMap);
            });
        }
    }

    // Sibling-scoped: finds $idx's siblings (same parent_placement_id,
    // wherever they fall in the flat array, within ONE location's own
    // array) and swaps $idx with its neighbor AMONG THOSE SIBLINGS in
    // $dir — not a raw array-index swap. Swapping the two entries' ARRAY
    // positions (even though they may not be array-adjacent) is
    // sufficient: rest_save_placements() derives each entry's final
    // 'position' from its RELATIVE order among same-parent entries in the
    // submitted array, not from raw index.
    function moveCard(loc, placements, idx, dir) {
        var p = placements[idx];
        var parentId = p.parent_placement_id || 0;
        var siblingIdx = [];
        placements.forEach(function (row, i) {
            if ((row.parent_placement_id || 0) === parentId) siblingIdx.push(i);
        });
        var pos = siblingIdx.indexOf(idx);
        var swapPos = pos + dir;
        if (swapPos < 0 || swapPos >= siblingIdx.length) return;
        var otherIdx = siblingIdx[swapPos];

        var tmp = placements[idx];
        placements[idx] = placements[otherIdx];
        placements[otherIdx] = tmp;
        if (state.selection.type === 'placement' && state.selection.surface === loc.surface && state.selection.slot === loc.slot) {
            if (state.selection.idx === idx) state.selection.idx = otherIdx;
            else if (state.selection.idx === otherIdx) state.selection.idx = idx;
        }
        markLocDirty(loc);
        renderCanvas();
    }

    // "Move to a different parent" (distinct from moveCard()'s up/down
    // sibling reorder): a plain <select> of every OTHER placement in the
    // SAME (surface, slot, contextId) location plus "(slot root)",
    // excluding the selected node's own descendants — parent_placement_id
    // is only ever meaningful within one location's own tree (class-
    // element.php's save_placement() enforces this server-side too), so
    // there is no cross-slot reparenting option offered here, same as
    // before this pass.
    function renderParentField(loc, placements, p) {
        var idx = state.selection.idx;
        inspectorEl.appendChild(el('h3', null, 'Parent'));
        var row = el('div', 'bhel-field-row');
        row.appendChild(el('label', null, 'Nested under'));

        if (!p.id) {
            row.appendChild(el('span', 'bhel-empty', '(save this placement first — an unsaved node always starts at the slot root)'));
            inspectorEl.appendChild(row);
            return;
        }

        var childrenMap = buildChildrenMap(placements);
        var excluded = descendantIds(placements, idx, childrenMap);
        excluded.push(p.id); // a node can never be its own parent either

        var select = document.createElement('select');
        var rootOpt = document.createElement('option');
        rootOpt.value = '0';
        rootOpt.textContent = '(slot root)';
        if (!p.parent_placement_id) rootOpt.selected = true;
        select.appendChild(rootOpt);

        placements.forEach(function (other) {
            if (!other.id || excluded.indexOf(other.id) !== -1) return;
            var o = document.createElement('option');
            o.value = String(other.id);
            var otherType = (state.typesBySurface[loc.surface] || {})[other.element_type] || {};
            o.textContent = (otherType.label || other.element_type) + ' (#' + other.id + ')';
            if (p.parent_placement_id === other.id) o.selected = true;
            select.appendChild(o);
        });

        select.addEventListener('change', function () {
            p.parent_placement_id = parseInt(select.value, 10) || 0;
            markLocDirty(loc);
            renderCanvas();
        });
        row.appendChild(select);
        inspectorEl.appendChild(row);
    }

    /* ---------------- inspector (right rail / mobile bottom sheet) ---------------- */
    // The ONE inspector, branching on state.selection.type. Everything in
    // the 'placement' branch below is UNCHANGED placement-inspector logic
    // (the prior audit already confirmed these controls work correctly)
    // — this pass only changed WHICH selection routes here and how a
    // placement's own location (loc) is threaded through, not how a real
    // placement's fields render.
    function renderInspector() {
        inspectorEl.innerHTML = '';
        var sel = state.selection;

        if (sel.type === 'site') {
            inspectorEl.classList.remove('bhel-sheet-open');
            renderSiteInspector();
            return;
        }

        // Live-confirmed feedback: picking a hand-authored demo-only
        // Live View (bh-contest, the bh-courses catalog/lesson-step
        // mockups, etc. — never a real registered BH_Element surface)
        // left the inspector showing whatever placement happened to be
        // selected before, completely unrelated to what the canvas now
        // showed — "still not doing what it's supposed to." The
        // 'bhel:select-surface' listener below now explicitly clears to
        // THIS state for exactly that case, instead of silently leaving
        // the previous selection stale. This is the honest answer, not
        // a deeper fix pretending these mockups are editable — they
        // aren't; see DESIGN-SUITE-UNIFICATION-PLAN.md's own "no
        // special-cased pages" status notes for why some surfaces still
        // don't have a real node-tree presence yet.
        if (sel.type === 'demo') {
            // The read-only structure tree lives in the left rail's
            // "Live view markup" section now (same as the real Structure
            // tree above it — renderDemoOutline() below just fills that
            // mount), the exact same way every other kind of tree in this
            // app already lives in the rail, not the inspector. The style
            // panel for whatever's currently selected in that tree stays
            // right here, same as it does for a real placement.
            inspectorEl.classList.remove('bhel-sheet-open');
            inspectorEl.appendChild(el('h3', null, sel.label || 'Style preview'));
            inspectorEl.appendChild(el('p', 'bhel-empty',
                'This is a style-only preview, not an editable Design Suite page — nothing here is backed by real placement data. Browse its markup in the "Live view markup" tree in the left rail; clicking a row there styles it below.'));
            renderDemoOutline();
            return;
        }

        if (sel.type === 'surface') {
            inspectorEl.classList.remove('bhel-sheet-open');
            var surface = state.surfaces[sel.surface] || {};
            inspectorEl.appendChild(el('h3', null, surface.label || sel.surface));
            // 3.4.39 — same treatment as the Slot inspector: a real list
            // of the surface's own slots (clickable, jumps straight to
            // that Slot node's own inspector) instead of a dead-end
            // "expand it in the tree" sentence with nothing else here.
            var slotSlugs = Object.keys(surface.slots || {});
            if (!slotSlugs.length) {
                inspectorEl.appendChild(el('p', 'bhel-empty', 'This surface has no registered slots.'));
            } else {
                var slotList = el('div', 'bhel-slot-contents');
                slotSlugs.forEach(function (slotSlug) {
                    var slotDef = surface.slots[slotSlug] || {};
                    var row = el('button', 'bhel-slot-content-row', slotDef.label || slotSlug);
                    row.type = 'button';
                    row.addEventListener('click', function () { selectSlot(sel.surface, slotSlug); });
                    slotList.appendChild(row);
                });
                inspectorEl.appendChild(slotList);
            }
            return;
        }

        if (sel.type === 'slot') {
            inspectorEl.classList.remove('bhel-sheet-open');
            renderSlotInspector(sel.surface, sel.slot);
            return;
        }

        // sel.type === 'placement'
        var loc = { surface: sel.surface, slot: sel.slot, contextId: sel.contextId };
        var placements = getPlacementsArray(loc);
        var p = placements[sel.idx];
        inspectorEl.classList.toggle('bhel-sheet-open', !!p);
        if (!p) {
            inspectorEl.appendChild(el('p', 'bhel-empty', 'Select a node in the tree to edit it.'));
            return;
        }
        inspectorEl.appendChild(inspectorHandle);
        inspectorEl.appendChild(inspectorCloseBtn);
        var type = (state.typesBySurface[loc.surface] || {})[p.element_type];
        if (!type) {
            inspectorEl.appendChild(el('p', 'bhel-empty', 'Type "' + p.element_type + '" is not registered for this surface.'));
            return;
        }

        inspectorEl.appendChild(el('h3', null, 'Content / attrs'));
        Object.keys(type.schema || {}).forEach(function (key) {
            renderAttrField(p, type, key, type.schema[key]);
        });

        if (type.container) {
            // DESIGN-SUITE-UNIFICATION-PLAN.md unification pass: Content
            // Studio's canvas is NOT inlined into this inspector (real
            // scope/risk call, documented in the plan doc's status note)
            // — but per the "there is no difference between the two"
            // instruction, opening it is a MODAL over this same shell,
            // never a full page navigation away.
            var note = el('p', 'bhel-empty', 'Container element — inner content is edited via the Content Studio canvas (BH_Content context "bh_element", id ' + (p.content_context_id || '(unsaved)') + ').');
            inspectorEl.appendChild(note);
            var openBtn = el('button', 'button bhel-studio-open-btn', 'Edit nested content…');
            openBtn.type = 'button';
            openBtn.addEventListener('click', function () {
                openStudioModal(p.content_context_id);
            });
            inspectorEl.appendChild(openBtn);
        }

        if ((type.style || []).length) {
            inspectorEl.appendChild(el('h3', null, 'Style'));
            type.style.forEach(function (token) {
                renderStyleField(p, token, loc);
            });
        }

        renderStyleAdvancedSection(p, loc);
        renderActionsSection(p, loc);
        renderHtmlAttrsSection(p, type, loc);
        renderParentField(loc, placements, p);

        inspectorEl.appendChild(el('h3', null, 'Enabled'));
        var enabledRow = el('div', 'bhel-field-row');
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.checked = p.enabled !== false;
        cb.addEventListener('change', function () { p.enabled = cb.checked; markLocDirty(loc); renderCanvas(); });
        var cbLabel = document.createElement('label');
        cbLabel.style.display = 'inline-flex';
        cbLabel.style.alignItems = 'center';
        cbLabel.style.gap = '6px';
        cbLabel.appendChild(cb);
        cbLabel.appendChild(document.createTextNode('Renders on the live surface'));
        enabledRow.appendChild(cbLabel);
        inspectorEl.appendChild(enabledRow);
    }

    /**
     * AJ's own direct follow-up chain: "can we still have 'trees' for
     * the plugin live views?" (structure — done, read-only, see the
     * outline this builds), then "the read only tree should be for
     * structure of the thing only, we still need to edit the styles of
     * each thing." This is that second half: clicking an outline row now
     * ALSO opens a style panel for that exact element, reusing the same
     * property set the real per-placement inspector already exposes
     * (background/text color via the color-token popup, padding,
     * border-radius, font-size) rather than inventing a second set of
     * controls.
     *
     * Honest scope line, stated up front rather than discovered later:
     * these mockups have NO backing placement data (no
     * bhcore_element_placements row, no surface/slot/context), so there
     * is nowhere real to PERSIST a per-element override to. This panel
     * writes directly to the target element's own inline `style` inside
     * the iframe, live — genuinely useful for visually trying something
     * out against the real markup/theme — but it resets the moment the
     * page reloads. A real, PERSISTED version of this is a bigger,
     * separate feature (effectively: give every hand-authored demo
     * surface a real BH_Element registration, the same "no special-cased
     * pages" migration CRM/LMS lessons already got) — not something to
     * quietly half-build under a different name here. The panel's own
     * heading says "session-only" so this is never mistaken for a real
     * save.
     */
    function renderDemoOutline() {
        // No-iframes build — each .bhy-story-frame is now a same-document
        // div carrying its own attachShadow({mode:'open'}) root (see
        // class-style-gallery.php's render_canvas()/render_script() for
        // the server/client halves of that swap). A ShadowRoot exposes
        // the same querySelectorAll()/getElementById() API a real
        // Document does, but has no .body wrapper — its style/link/
        // content nodes sit as flat top-level children instead, handled
        // by buildInto()'s own root-iteration below.
        var frame = document.querySelector('.bhy-story-frame.active');
        var doc = frame && frame.shadowRoot;

        // AJ's own correction, right after the last pass: the TREE moves
        // into the left rail (#bhy-rail-demo-outline-section,
        // class-style-gallery.php's render_left_rail()) — same as the
        // real Structure tree already does, nothing special. The STYLE
        // PANEL stays right here in the inspector, same as every other
        // selection's controls — clicking a rail row selects + highlights
        // the element and opens ITS style panel over here, it does not
        // also relocate the panel itself into the rail.
        var railSection = document.getElementById('bhy-rail-demo-outline-section');
        var railMount = document.getElementById('bhy-rail-demo-outline-mount');
        if (railMount) railMount.innerHTML = '';
        if (railSection) railSection.style.display = 'none';
        if (!doc || !railMount) return;

        var stylePanel = el('div', 'bhel-outline-style-panel');
        inspectorEl.appendChild(el('h3', null, 'Style selected element'));
        inspectorEl.appendChild(el('p', 'bhel-empty', 'Select an element in the left rail\'s outline to style it here.'));
        inspectorEl.appendChild(stylePanel);

        var SKIP_TAGS = { SCRIPT: 1, STYLE: 1, LINK: 1, META: 1, NOSCRIPT: 1 };

        function nodeLabel(elNode) {
            var label = elNode.tagName.toLowerCase();
            if (elNode.id) label += '#' + elNode.id;
            if (elNode.className && typeof elNode.className === 'string' && elNode.className.trim()) {
                label += '.' + elNode.className.trim().split(/\s+/).slice(0, 2).join('.');
            }
            return label;
        }

        function highlight(target) {
            doc.querySelectorAll('.bhel-outline-highlight').forEach(function (n) { n.classList.remove('bhel-outline-highlight'); });
            target.classList.add('bhel-outline-highlight');
            target.scrollIntoView({ block: 'center', behavior: 'smooth' });
            setTimeout(function () { target.classList.remove('bhel-outline-highlight'); }, 1500);
        }

        // Direct-to-inline-style controls — NOT the same code path as
        // renderStylePropertyField() (that one writes to
        // p.config.style/a real placement and round-trips through
        // BHY_Style::resolve_style_value() server-side); this is a much
        // dumber, purely client-side "set this CSS property on this one
        // live DOM node" — same property VOCABULARY (background, text
        // color, padding, radius, font size) for a consistent feel, but
        // a genuinely different, session-only mechanism, since there's
        // no server-side placement for it to resolve against.
        var STYLE_FIELDS = [
            { key: 'backgroundColor', label: 'Background color', type: 'color' },
            { key: 'color', label: 'Text color', type: 'color' },
            { key: 'padding', label: 'Padding', type: 'text', placeholder: 'e.g. 12px or 8px 16px' },
            { key: 'borderRadius', label: 'Border radius', type: 'text', placeholder: 'e.g. 8px' },
            { key: 'fontSize', label: 'Font size', type: 'text', placeholder: 'e.g. 16px' },
        ];

        // AJ's own direct follow-up on top of the Background/Color/
        // Padding/etc. fixed vocabulary above: "add arbitrary class names
        // and custom CSS to things as needed" — not every useful tweak
        // has its own dedicated field (a hover state, a pseudo-element, a
        // property not in STYLE_FIELDS). These two give an escape hatch
        // without trying to enumerate every CSS property one input at a
        // time:
        //  - "Add CSS class(es)" appends whatever space-separated
        //    class(es) already exist on the element in the surface's own
        //    real stylesheet (a class this outline can SEE via
        //    nodeLabel() but this panel doesn't have to know what it
        //    does) — genuinely useful for e.g. toggling a ".is-active"/
        //    ".dark" variant class a stylesheet already defines.
        //  - "Custom CSS" is a raw `property: value; property: value;`
        //    textarea applied via target.style.cssText (append, not
        //    replace, so it composes with the fixed fields above rather
        //    than fighting them) — same session-only, DOM-only, nothing-
        //    to-persist-to caveat as everything else in this panel.
        function renderStylePanel(target) {
            stylePanel.innerHTML = '';
            stylePanel.appendChild(el('h3', null, 'Style this element (session-only, not saved)'));
            stylePanel.appendChild(el('p', 'bhel-empty', 'Live edits below apply directly to this exact element in the preview — nothing here persists past a page reload. There is no real placement behind a demo mockup to save these to (see this panel\'s own code comment for what a real, persisted version of this would take).'));

            STYLE_FIELDS.forEach(function (field) {
                var row = el('div', 'bhel-field-row');
                row.appendChild(el('label', null, field.label));
                var input = document.createElement('input');
                input.type = field.type;
                if (field.placeholder) input.placeholder = field.placeholder;
                var current = target.style[field.key];
                if (field.type === 'color') {
                    input.value = current || '#000000';
                } else {
                    input.value = current || '';
                }
                input.addEventListener('input', function () {
                    target.style[field.key] = input.value;
                });
                row.appendChild(input);
                stylePanel.appendChild(row);
            });

            var classRow = el('div', 'bhel-field-row');
            classRow.appendChild(el('label', null, 'Add CSS class(es)'));
            var classInput = document.createElement('input');
            classInput.type = 'text';
            classInput.placeholder = 'e.g. is-active dark';
            classInput.value = target.className && typeof target.className === 'string' ? target.className : '';
            classInput.addEventListener('change', function () {
                target.className = classInput.value.trim();
            });
            classRow.appendChild(classInput);
            stylePanel.appendChild(classRow);

            var cssRow = el('div', 'bhel-field-row');
            cssRow.appendChild(el('label', null, 'Custom CSS'));
            var cssArea = document.createElement('textarea');
            cssArea.rows = 4;
            cssArea.placeholder = 'e.g. text-transform: uppercase; letter-spacing: .05em;';
            cssArea.value = target.dataset.bhelCustomCss || '';
            cssArea.addEventListener('input', function () {
                target.dataset.bhelCustomCss = cssArea.value;
                // Rebuild style.cssText from the fixed fields above PLUS
                // this raw block, rather than just appending on every
                // keystroke — appending on every keystroke would pile up
                // a duplicate declaration per keystroke instead of
                // replacing the previous custom-CSS contribution.
                var fixedCss = '';
                STYLE_FIELDS.forEach(function (field) {
                    var val = target.style[field.key];
                    if (val) fixedCss += field.key.replace(/[A-Z]/g, function (m) { return '-' + m.toLowerCase(); }) + ':' + val + ';';
                });
                target.style.cssText = fixedCss + cssArea.value;
            });
            cssRow.appendChild(cssArea);
            stylePanel.appendChild(cssRow);

            var resetBtn = el('button', 'button', 'Reset this element\'s style');
            resetBtn.type = 'button';
            resetBtn.addEventListener('click', function () {
                target.style.cssText = '';
                delete target.dataset.bhelCustomCss;
                renderStylePanel(target);
            });
            stylePanel.appendChild(resetBtn);
        }

        function selectNode(target) {
            highlight(target);
            renderStylePanel(target);
        }

        // Shared by both mounts (inspector + left rail) so there's one
        // tree-building code path, not two copies that could drift —
        // AJ's own ask was to show the SAME read-only tree in both
        // places, both wired to the SAME highlight/style-panel behavior,
        // not a second parallel tree implementation.
        function buildInto(container) {
            var nodeBudget = { n: 400 };

            function build(elNode, depth) {
                if (depth > 8 || nodeBudget.n <= 0) return null;
                var children = [];
                elNode.childNodes.forEach(function (child) {
                    if (child.nodeType !== 1 || SKIP_TAGS[child.tagName]) return;
                    nodeBudget.n--;
                    var built = build(child, depth + 1);
                    if (built) children.push(built);
                });

                var row = el('div', 'bhel-outline-row');
                var btn = el('button', 'bhel-outline-label', nodeLabel(elNode));
                btn.type = 'button';
                // Was `highlight(elNode)` only — clicking a row scrolled/
                // flashed the element but never actually opened the style
                // panel, silently leaving "style each thing" unreachable
                // by click. selectNode() does both.
                btn.addEventListener('click', function () { selectNode(elNode); });
                row.appendChild(btn);

                var wrap = el('div', 'bhel-outline-node');
                wrap.appendChild(row);
                if (children.length) {
                    var kids = el('div', 'bhel-outline-children');
                    children.forEach(function (c) { kids.appendChild(c); });
                    wrap.appendChild(kids);
                }
                return wrap;
            }

            // A ShadowRoot has no single .body root element to hand
            // build() the way a real Document did — its content nodes are
            // flat top-level children (head's style/link tags + body's
            // real markup, appended side-by-side by render_script()'s
            // shadow-attach code). Build each one as its own top-level
            // outline row instead of assuming exactly one wrapper element
            // exists.
            doc.childNodes.forEach(function (child) {
                if (child.nodeType !== 1 || SKIP_TAGS[child.tagName]) return;
                nodeBudget.n--;
                var built = build(child, 0);
                if (built) container.appendChild(built);
            });
            if (nodeBudget.n <= 0) container.appendChild(el('p', 'bhel-empty', '(outline truncated — this preview\'s markup is larger than the outline shows)'));
        }

        buildInto(railMount);
        if (railSection) railSection.style.display = '';
    }

    // A Slot node's own inspector content: a plain label plus, per this
    // pass's point 5 (per-entity surfaces), a small inline "Context ID"
    // control — see this file's own updated docblock for the honest
    // JUDGMENT CALL recorded there (no friendly entity-name picker is
    // possible from data the surfaces registry actually returns).
    // 3.4.39 — was a near-empty stub (one throwaway sentence + a raw
    // "Context ID" number field) next to the fully-fleshed placement
    // inspector, a real visual/functional gap flagged from a live
    // screenshot. Now mirrors that inspector's own section-header (h3)
    // + card pattern and, most importantly, actually shows what's IN
    // the slot — a real list of its current top-level placements, each
    // clickable to jump straight to that node's own inspector — instead
    // of forcing "expand it in the tree" as the only way to see them.
    function renderSlotInspector(surfaceSlug, slotSlug) {
        var surface = state.surfaces[surfaceSlug] || {};
        var slotDef = (surface.slots || {})[slotSlug] || {};
        var contextId = state.surfaceContext[surfaceSlug] || 0;
        var loc = { surface: surfaceSlug, slot: slotSlug, contextId: contextId };

        inspectorEl.appendChild(el('h3', null, (surface.label || surfaceSlug) + ' — ' + (slotDef.label || slotSlug)));

        var placements = getPlacementsArray(loc).filter(function (p) { return !p.parent_placement_id; });
        var list = el('div', 'bhel-slot-contents');
        if (!placements.length) {
            list.appendChild(el('p', 'bhel-empty', 'Nothing in this slot yet — use the "+" on this node in the tree to add one.'));
        } else {
            placements.forEach(function (p, idx) {
                var realIdx = getPlacementsArray(loc).indexOf(p);
                var type = (state.typesBySurface[surfaceSlug] || {})[p.element_type];
                var row = el('button', 'bhel-slot-content-row', (type && type.label) || p.element_type);
                row.type = 'button';
                var meta = el('span', 'bhel-slot-content-meta', p.enabled === false ? 'hidden' : '');
                row.appendChild(meta);
                row.addEventListener('click', function () { selectPlacementNode(loc, realIdx); });
                list.appendChild(row);
            });
        }
        inspectorEl.appendChild(list);

        inspectorEl.appendChild(el('h3', null, 'Slot settings'));
        var row = el('div', 'bhel-field-row');
        row.appendChild(el('label', null, 'Context ID (advanced — for per-entity surfaces)'));
        var input = document.createElement('input');
        input.type = 'number';
        input.min = '0';
        input.style.width = '90px';
        input.value = String(contextId);
        input.title = 'Most surfaces are singletons and only ever use context 0. A per-entity surface (e.g. a CRM profile) uses the entity’s own id here. There is no name picker — the surfaces registry has no entity metadata to resolve one from; see this file’s docblock.';
        input.addEventListener('change', function () {
            var val = parseInt(input.value, 10) || 0;
            state.surfaceContext[surfaceSlug] = val;
            setStatus('Loading placements for context #' + val + '…');
            loadSurfaceData(surfaceSlug, val).then(function () {
                renderCanvas();
                renderInspector();
            });
        });
        row.appendChild(input);
        inspectorEl.appendChild(row);

        var actions = el('div', 'bhel-field-row');
        var prefabBtn = el('button', 'button', 'Save this slot as a prefab…');
        prefabBtn.type = 'button';
        prefabBtn.addEventListener('click', function () {
            saveSlotAsPrefab(surfaceSlug, slotSlug, contextId);
        });
        actions.appendChild(prefabBtn);
        inspectorEl.appendChild(actions);
    }

    // The Site node's own inspector content: global style tokens
    // (BHY_Style's option row), read/written through the GET/POST
    // .../elements/site-tokens routes (class-element.php). Reuses the
    // SAME .bhy-swatch-card/.bhy-slider-row markup renderStyleField()
    // already builds for a per-placement color/scale override, rather
    // than inventing a second control style.
    //
    // JUDGMENT CALL (undecided by the plan note, recorded here): when
    // this file is embedded in BHY_Gallery's unified Design Suite shell
    // (class-style-gallery.php), that page's OWN Global Styles form
    // (#bhy-controls-panel — the full, canonical editor: brand fields,
    // every color including advanced/category swatches, font pickers,
    // theme presets) is what actually becomes visible when Site is
    // selected — render_script()'s 'bhel:selection' listener shows that
    // panel and HIDES this file's own inspector column entirely, so
    // what this function builds is inert (built but not shown) in that
    // context, avoiding two competing editors for the same data. This
    // function's own rendering is the REAL, visible editor only in
    // STANDALONE contexts (the retired Debug Tools fallback / the
    // 'bh-element-builder' submenu, where no #bhy-controls-panel
    // exists) — and there it is DELIBERATELY a reduced-scope editor
    // (brand + core colors + the five scale sliders; no advanced/
    // category colors, no font picker, no theme presets) rather than a
    // full re-implementation of BHY_Gallery's form, since the canonical,
    // full-featured editor is the Design Suite page itself.
    function renderSiteInspector() {
        inspectorEl.appendChild(el('h3', null, 'Site — Global Styles'));
        if (!state.siteTokens) {
            inspectorEl.appendChild(el('p', 'bhel-empty', 'Loading global styles…'));
            return;
        }
        var note = el('p', 'bhel-empty', 'These tokens apply site-wide — every surface/slot/placement below inherits them unless it has its own Style override. When this builder is embedded in the Design Suite, edit them there for the full set of controls (brand, fonts, theme presets, advanced/category colors); this reduced panel (core colors + scale) is the standalone fallback.');
        inspectorEl.appendChild(note);

        var t = state.siteTokens;
        var coreColors = { color_bg: 'Background', color_surface: 'Surface', color_border: 'Border', color_text: 'Text', color_accent: 'Accent' };
        Object.keys(coreColors).forEach(function (key) {
            renderSiteColorField(key, coreColors[key]);
        });

        var scales = {
            font_scale:  { label: 'Text size',    min: 0.75, max: 1.6, step: 0.05, unit: '×' },
            space_scale: { label: 'Spacing',       min: 0.6,  max: 1.8, step: 0.05, unit: '×' },
            radius:      { label: 'Corner radius', min: 0,    max: 32,  step: 1,    unit: 'px' },
            radius_sm:   { label: 'Corner radius (small)', min: 0, max: 24, step: 1, unit: 'px' },
            bar_height:  { label: 'Bar height',    min: 56,   max: 140, step: 2,    unit: 'px' },
        };
        Object.keys(scales).forEach(function (key) {
            renderSiteScaleField(key, scales[key]);
        });

        var saveRow = el('div', 'bhel-field-row');
        var saveSiteBtn = el('button', 'button button-primary', 'Save site styles');
        saveSiteBtn.type = 'button';
        saveSiteBtn.addEventListener('click', function () {
            setStatus('Saving site styles…');
            api('site-tokens', { method: 'POST', body: { tokens: state.siteTokens } }).then(function (saved) {
                state.siteTokens = saved || state.siteTokens;
                setStatus('Saved site styles.', 'ok');
            }).catch(function (e) {
                setStatus('Save failed: ' + e.message, 'error');
            });
        });
        saveRow.appendChild(saveSiteBtn);
        inspectorEl.appendChild(saveRow);
    }

    function renderSiteColorField(key, label) {
        var t = state.siteTokens;
        var card = el('div', 'bhy-swatch-card');
        var swatch = el('div', 'bhy-swatch');
        swatch.style.background = t[key] || '#f6f7f7';
        card.appendChild(swatch);
        var body = el('div', 'bhy-swatch-body');
        body.appendChild(el('label', null, label));
        var controls = el('div', 'bhy-swatch-controls');
        var text = document.createElement('input');
        text.type = 'text';
        text.value = t[key] || '';
        var picker = document.createElement('input');
        picker.type = 'color';
        picker.value = (t[key] && t[key][0] === '#' && t[key].length === 7) ? t[key] : '#000000';
        function sync() {
            t[key] = text.value.trim();
            swatch.style.background = t[key] || '#f6f7f7';
        }
        text.addEventListener('input', sync);
        picker.addEventListener('input', function () { text.value = picker.value; sync(); });
        controls.appendChild(text);
        controls.appendChild(picker);
        body.appendChild(controls);
        card.appendChild(body);
        inspectorEl.appendChild(card);
    }

    function renderSiteScaleField(key, def) {
        var t = state.siteTokens;
        var row = el('div', 'bhy-slider-row');
        var label = el('label');
        var span1 = document.createElement('span');
        span1.textContent = def.label;
        var span2 = document.createElement('span');
        span2.className = 'bhy-slider-val';
        span2.textContent = (t[key] !== undefined ? t[key] : '') + def.unit;
        label.appendChild(span1); label.appendChild(span2);
        row.appendChild(label);
        var range = document.createElement('input');
        range.type = 'range';
        range.min = String(def.min); range.max = String(def.max); range.step = String(def.step);
        range.value = t[key] !== undefined ? t[key] : def.min;
        range.addEventListener('input', function () {
            span2.textContent = range.value + def.unit;
            t[key] = range.value;
        });
        row.appendChild(range);
        inspectorEl.appendChild(row);
    }

    // One attr = either {literal: ...} or {bind: {source,args,subject}}.
    // Mirrors BH_Element_Data::resolve()'s exact descriptor shape — see
    // that file's docblock. Non-bindable attrs never show the bind
    // toggle at all (bindable === false in schema).
    function renderAttrField(p, type, key, def) {
        var row = el('div', 'bhel-field-row');
        row.appendChild(el('label', null, key + (def.bindable ? ' (bindable, kind: ' + (def.kind || 'scalar') + ')' : '')));

        var current = p.config.attrs[key];
        var mode = (current && current.bind) ? 'bind' : 'literal';

        if (def.bindable) {
            var toggle = el('div', 'bhel-bind-toggle');
            var litBtn = el('button', 'button' + (mode === 'literal' ? ' active' : ''), 'Literal');
            var bindBtn = el('button', 'button' + (mode === 'bind' ? ' active' : ''), 'Bind');
            litBtn.type = 'button'; bindBtn.type = 'button';
            litBtn.addEventListener('click', function () {
                p.config.attrs[key] = { literal: (current && current.literal !== undefined) ? current.literal : (def.default || '') };
                markDirtyAndRerenderInspector();
            });
            bindBtn.addEventListener('click', function () {
                p.config.attrs[key] = { bind: { source: '', args: {}, subject: '' } };
                markDirtyAndRerenderInspector();
            });
            toggle.appendChild(litBtn); toggle.appendChild(bindBtn);
            row.appendChild(toggle);
        }

        if (mode === 'bind' && def.bindable) {
            renderBindFields(row, p, key, def, current.bind);
        } else {
            renderLiteralField(row, p, key, def, current && current.literal !== undefined ? current.literal : def.default);
        }

        inspectorEl.appendChild(row);
    }

    // Small shared helper: field renderers below only ever operate on
    // the CURRENTLY SELECTED placement, so they can mark that selection's
    // own location dirty without threading loc through every field
    // function individually.
    function markDirty() {
        var sel = state.selection;
        if (sel.type !== 'placement') return;
        markLocDirty({ surface: sel.surface, slot: sel.slot, contextId: sel.contextId });
    }
    function markDirtyAndRerenderInspector() {
        markDirty();
        renderInspector();
    }

    /**
     * Real live-canvas preview, wired to the ALREADY-EXISTING `POST
     * /elements/preview` route (`BH_Element::rest_preview()`) — that
     * route was built specifically for this (its own docblock literally
     * says "return rendered HTML for the live canvas") but was never
     * actually called from anywhere in this file. Direct response to a
     * live screenshot: an edited-but-unsaved field showed nothing in the
     * canvas at all — confirmed as a real, never-wired gap, not a
     * caching issue. Debounced (400ms after the last edit) rather than
     * firing on every keystroke, same restraint every other REST-backed
     * field in this file already shows.
     *
     * Scope, honestly: patches ONE placement's own rendered wrapper in
     * place inside whichever `.bhy-story-frame` iframe matches the
     * current surface (class-style-gallery.php's canvas — a deliberate
     * cross-file DOM coupling, same as that file's own `bhel:selection`
     * listener reaching back the other way). A SAVED placement (real id)
     * is found by its existing `data-placement-id` and outerHTML-
     * replaced. A brand-new, still-unsaved placement (id <= 0, "Note ·
     * unsaved" in the tree) has no existing DOM node to patch — it's
     * inserted at the end of its slot's `.bh-element-slot` container
     * instead, tagged with a synthetic `data-bhel-preview-key` (assigned
     * once, stored on the in-memory placement object) so a SECOND edit
     * finds and replaces that same temp node rather than appending a
     * duplicate. Known, accepted v1 limitation: if more than one
     * unsaved-new sibling placement is being live-previewed at once,
     * only the currently-selected one's `data-bhel-preview-key` is ever
     * assigned/tracked — not a correctness risk (nothing writes to the
     * DB here), just a cosmetic edge case not worth the extra bookkeeping
     * this pass.
     */
    var previewDebounceTimer = null;
    function schedulePreviewUpdate(loc) {
        clearTimeout(previewDebounceTimer);
        previewDebounceTimer = setTimeout(function () { applyLivePreview(loc); }, 400);
    }
    function applyLivePreview(loc) {
        var placements = getPlacementsArray(loc);
        var sel = state.selection;
        if (sel.type !== 'placement') return;
        var p = placements[sel.idx];
        if (!p) return;

        // 'preview', NOT '/elements/preview' — cfg.restUrl already ends in
        // '.../ous/v1/elements/' (class-element-builder.php's own
        // wp_localize_script() call), same base every OTHER api() call in
        // this file already relies on (see e.g. api('surfaces'),
        // api('site-tokens'), and the pre-existing api('preview', ...)
        // bind-field preview call a few hundred lines below this one,
        // which got the path right). The leading-slash version silently
        // 404'd (caught by this function's own catch(){} and swallowed
        // with zero visible error) — confirmed via live screenshot as a
        // second, embarrassingly simple bug layered on top of the two
        // real ones already fixed this pass.
        api('preview', {
            method: 'POST',
            body: { element_type: p.element_type, config: p.config, content_context_id: p.content_context_id || 0, ctx: {} },
        }).then(function (res) {
            if (!res || typeof res.html !== 'string') return;
            // No-iframes build — same-document div + shadow root now,
            // not an iframe (see class-style-gallery.php's render_canvas()
            // comment); shadowRoot exposes the same querySelector() API
            // contentDocument did, so this patch logic is unchanged below.
            var frame = document.querySelector('.bhy-story-frame[data-surface="' + loc.surface + '"]');
            var doc = frame && frame.shadowRoot;
            if (!doc) return; // no matching live story for this surface, or shadow root not yet attached — nothing to patch, not an error

            var wrapper = document.createElement('div');
            wrapper.innerHTML = res.html;
            var fresh = wrapper.firstElementChild;
            if (!fresh) return;

            var isSaved = p.id && p.id > 0;
            var existing = isSaved
                ? doc.querySelector('[data-placement-id="' + p.id + '"]')
                : (p.__previewKey ? doc.querySelector('[data-bhel-preview-key="' + p.__previewKey + '"]') : null);

            if (existing) {
                if (!isSaved) fresh.setAttribute('data-bhel-preview-key', p.__previewKey);
                existing.replaceWith(fresh);
            } else if (!isSaved) {
                p.__previewKey = p.__previewKey || 'temp-' + Math.random().toString(36).slice(2);
                fresh.setAttribute('data-bhel-preview-key', p.__previewKey);
                var slotEl = doc.querySelector('.bh-element-slot[data-surface="' + loc.surface + '"][data-slot="' + loc.slot + '"]');
                if (slotEl) slotEl.appendChild(fresh);
                // no matching slot element in this story's markup (e.g. a
                // hand-authored mockup that never called render_slot() for
                // this exact slot) — nothing safe to append to, skip.
            }
            // isSaved && !existing: the saved placement's story doesn't
            // render this slot at all (same "no matching slot markup"
            // case) — nothing to patch, silently a no-op.
        }).catch(function () {}); // preview is best-effort UX sugar — a failed preview call never blocks editing or the real save path
    }

    function renderLiteralField(row, p, key, def, value) {
        value = value === undefined || value === null ? '' : value;
        var input;
        if (def.type === 'bool') {
            input = document.createElement('input');
            input.type = 'checkbox';
            input.checked = !!value;
            input.addEventListener('change', function () {
                p.config.attrs[key] = { literal: input.checked };
                markDirty();
            });
        } else if (def.type === 'int') {
            input = document.createElement('input');
            input.type = 'number';
            input.value = value;
            input.addEventListener('change', function () {
                p.config.attrs[key] = { literal: parseInt(input.value, 10) || 0 };
                markDirty();
            });
        } else if (def.type === 'html') {
            input = document.createElement('textarea');
            input.value = value;
            input.addEventListener('change', function () {
                p.config.attrs[key] = { literal: input.value };
                markDirty();
            });
        } else {
            input = document.createElement('input');
            input.type = 'text';
            input.value = value;
            input.addEventListener('change', function () {
                p.config.attrs[key] = { literal: input.value };
                markDirty();
            });
        }
        row.appendChild(input);
    }

    function renderBindFields(row, p, key, def, binding) {
        binding = binding || { source: '', args: {}, subject: '' };
        var kind = def.kind || 'scalar';
        var sourceSelect = document.createElement('select');
        var loadingOpt = document.createElement('option');
        loadingOpt.textContent = 'Loading sources…';
        sourceSelect.appendChild(loadingOpt);
        row.appendChild(sourceSelect);

        var argsWrap = el('div');
        row.appendChild(argsWrap);

        var subjectRow = el('div', 'bhel-field-row');
        subjectRow.appendChild(el('label', null, 'Subject (context token)'));
        var subjectSelect = document.createElement('select');
        var blankOpt = document.createElement('option');
        blankOpt.value = ''; blankOpt.textContent = '(none)';
        subjectSelect.appendChild(blankOpt);
        CONTEXT_TOKENS.forEach(function (tok) {
            var o = document.createElement('option');
            o.value = tok; o.textContent = tok;
            if (tok === binding.subject) o.selected = true;
            subjectSelect.appendChild(o);
        });
        subjectSelect.addEventListener('change', function () {
            binding.subject = subjectSelect.value;
            p.config.attrs[key] = { bind: binding };
            markDirty();
        });
        subjectRow.appendChild(subjectSelect);
        row.appendChild(subjectRow);

        var previewLine = el('div', 'bhel-bind-preview', '');
        row.appendChild(previewLine);
        var previewBtn = el('button', 'button', 'Preview resolved value');
        previewBtn.type = 'button';
        previewBtn.addEventListener('click', function () {
            previewLine.textContent = 'Resolving…';
            var sel = state.selection;
            api('preview', { method: 'POST', body: {
                element_type: p.element_type,
                config: { attrs: (function () { var o = {}; o[key] = { bind: binding }; return o; })() },
                ctx: { user_id: (sel.type === 'placement' ? sel.contextId : 0) || undefined },
            } }).then(function (res) {
                previewLine.textContent = 'Placement HTML (first 200 chars): ' + String(res.html || '').slice(0, 200);
            }).catch(function (e) {
                previewLine.textContent = 'Preview failed: ' + e.message;
            });
        });
        row.appendChild(previewBtn);

        api('sources?kind=' + encodeURIComponent(kind)).then(function (sources) {
            sourceSelect.innerHTML = '';
            var blank = document.createElement('option');
            blank.value = ''; blank.textContent = '(choose a source)';
            sourceSelect.appendChild(blank);
            Object.keys(sources).forEach(function (slug) {
                var o = document.createElement('option');
                o.value = slug; o.textContent = sources[slug].label + ' (' + slug + ')';
                if (slug === binding.source) o.selected = true;
                sourceSelect.appendChild(o);
            });
            renderArgFields(sources[binding.source]);
            sourceSelect.addEventListener('change', function () {
                binding.source = sourceSelect.value;
                binding.args = {};
                p.config.attrs[key] = { bind: binding };
                markDirty();
                renderArgFields(sources[binding.source]);
            });
        }).catch(function () {
            sourceSelect.innerHTML = '<option value="">(failed to load sources)</option>';
        });

        function renderArgFields(sourceManifest) {
            argsWrap.innerHTML = '';
            if (!sourceManifest) return;
            Object.keys(sourceManifest.arg_schema || {}).forEach(function (argKey) {
                var argDef = sourceManifest.arg_schema[argKey];
                var argRow = el('div', 'bhel-field-row');
                argRow.appendChild(el('label', null, argKey));
                var input = document.createElement('input');
                input.type = 'text';
                input.placeholder = argDef && argDef.default !== undefined ? String(argDef.default) : '';
                input.value = binding.args && binding.args[argKey] !== undefined ? binding.args[argKey] : '';
                input.addEventListener('change', function () {
                    binding.args = binding.args || {};
                    binding.args[argKey] = input.value;
                    p.config.attrs[key] = { bind: binding };
                    markDirty();
                });
                argRow.appendChild(input);
                argsWrap.appendChild(argRow);
            });
            if (sourceManifest.requires && sourceManifest.requires.length) {
                argsWrap.appendChild(el('p', 'bhel-bind-preview', 'Requires context: ' + sourceManifest.requires.join(', ')));
            }
        }
    }

    // Style tokens reuse BHY_UI's swatch/slider markup SHAPE (see
    // element-builder.css's comment block) — rendered here in JS rather
    // than via BHY_UI's PHP methods directly because those methods are
    // wired to the ONE global token set's fixed element ids
    // (BHY_Gallery's own <form>), not to a dynamic per-placement,
    // per-token override map. Values are written into
    // p.config.style[token], matching design doc §4's "per-element token
    // overrides into config.style" contract.
    function renderStyleField(p, token, loc) {
        var isColor = token.indexOf('color') === 0 || token.indexOf('color_') !== -1;
        var value = p.config.style[token] !== undefined ? p.config.style[token] : '';

        if (isColor) {
            var card = el('div', 'bhy-swatch-card');
            var swatch = el('div', 'bhy-swatch');
            swatch.style.background = value || '#f6f7f7';
            card.appendChild(swatch);
            var body = el('div', 'bhy-swatch-body');
            body.appendChild(el('label', null, token));
            var controls = el('div', 'bhy-swatch-controls');
            var text = document.createElement('input');
            text.type = 'text';
            text.value = value;
            text.placeholder = 'inherit theme token';
            var picker = document.createElement('input');
            picker.type = 'color';
            picker.value = (value && value[0] === '#' && value.length === 7) ? value : '#000000';
            function sync() {
                p.config.style[token] = text.value.trim();
                if (!p.config.style[token]) delete p.config.style[token];
                swatch.style.background = text.value.trim() || '#f6f7f7';
                markLocDirty(loc);
            }
            text.addEventListener('input', sync);
            picker.addEventListener('input', function () { text.value = picker.value; sync(); });
            controls.appendChild(text);
            controls.appendChild(picker);
            body.appendChild(controls);
            card.appendChild(body);
            inspectorEl.appendChild(card);
        } else {
            // radius/space_scale/etc — a bounded slider, same visual
            // shape as BHY_UI::slider_row(). Bounds are a generic,
            // reasonable guess (this override is scoped to one element,
            // not the global token range control) since the per-token
            // min/max BHY_Style::DEFAULTS uses for the GLOBAL slider
            // isn't exposed through this REST bridge.
            var row = el('div', 'bhy-slider-row');
            var label = el('label');
            var span1 = document.createElement('span');
            span1.textContent = token;
            var span2 = document.createElement('span');
            span2.className = 'bhy-slider-val';
            span2.textContent = value !== '' ? value : '(inherit)';
            label.appendChild(span1); label.appendChild(span2);
            row.appendChild(label);
            var range = document.createElement('input');
            range.type = 'range';
            range.min = '0'; range.max = '3'; range.step = '0.05';
            range.value = value !== '' ? value : '1';
            range.addEventListener('input', function () {
                span2.textContent = range.value;
                p.config.style[token] = range.value;
                markLocDirty(loc);
            });
            row.appendChild(range);
            inspectorEl.appendChild(row);
        }
    }

    /* ---------------- Style — Advanced (§2.6 property groups, config.style "group.property" keys) ---------------- */
    // Built ENTIRELY from GET .../elements/style-schema (BHY_Style::
    // style_schema_for_js(), loaded once into state.styleSchema at boot)
    // — every group/property/preset table this renders comes from that
    // response, never hardcoded here, so a new PROPERTY_MAP entry added
    // server-side needs no change in this file. This is separate from
    // the existing bare-token "Style" section above (type.style,
    // unchanged) — that section writes bare --bh-* token overrides
    // (config.style['color_accent'] etc.); this one writes the newer
    // namespaced "group.property" keys (config.style['sizing.width']
    // etc.), both of which BHY_Style::scoped_inline_style() already
    // resolves side by side in the same map.
    function renderStyleAdvancedSection(p, loc) {
        var groups = (state.styleSchema && state.styleSchema.groups) || {};
        var groupKeys = Object.keys(groups);
        if (!groupKeys.length) return; // schema not loaded yet / route unavailable — degrade silently, same as loadStyleSchema()'s catch

        inspectorEl.appendChild(el('h3', null, 'Style — Advanced'));
        var wrap = el('div', 'bhel-style-advanced');
        inspectorEl.appendChild(wrap);

        // Each property group is a <details> disclosure — a group is
        // OPEN BY DEFAULT only if it already has at least one active
        // override in p.config.style — an untouched group starts
        // collapsed so the panel reads as "here's what's actually
        // customized" at a glance, with every other group one click away.
        groupKeys.sort().forEach(function (groupKey) {
            var group = groups[groupKey];
            var props = group.properties || {};
            var propKeys = Object.keys(props);
            var hasOverride = propKeys.some(function (propKey) {
                return p.config.style[props[propKey].key] !== undefined;
            });

            var details = document.createElement('details');
            details.className = 'bhel-style-group';
            if (hasOverride) details.open = true;
            var summary = document.createElement('summary');
            summary.className = 'bhel-style-group-title';
            summary.textContent = (group.label || groupKey) + (hasOverride ? ' •' : '');
            details.appendChild(summary);

            var body = el('div', 'bhel-style-group-body');
            propKeys.forEach(function (propKey) {
                renderStylePropertyField(p, body, props[propKey], loc);
            });
            details.appendChild(body);
            wrap.appendChild(details);
        });

        // AJ's own ask: "add arbitrary class names and custom CSS to
        // things as needed" — extended here to real, PERSISTED
        // placements too (the demo-mockup version of this same idea is
        // session-only/DOM-only, see renderDemoOutline()'s own comment;
        // this one is the real thing — p.config.style.custom_class/
        // custom_css round-trip through the normal save path exactly
        // like every other style field above, and class-element.php's
        // wrap_placement_html() reads them at render time so they apply
        // on the real front-end too, not just this live preview). Kept
        // in the inspector alongside the rest of this element's real
        // style controls rather than also duplicated into the rail —
        // splitting one element's style controls across two panels would
        // be a worse UX than what's here, not a better one.
        var customDetails = document.createElement('details');
        customDetails.className = 'bhel-style-group';
        if (p.config.style.custom_class || p.config.style.custom_css) customDetails.open = true;
        var customSummary = document.createElement('summary');
        customSummary.className = 'bhel-style-group-title';
        customSummary.textContent = 'Custom class / CSS' + ((p.config.style.custom_class || p.config.style.custom_css) ? ' •' : '');
        customDetails.appendChild(customSummary);

        var customBody = el('div', 'bhel-style-group-body');

        var classRow = el('div', 'bhel-field-row');
        classRow.appendChild(el('label', null, 'Extra CSS class(es)'));
        var classInput = document.createElement('input');
        classInput.type = 'text';
        classInput.placeholder = 'e.g. is-featured dark';
        classInput.value = p.config.style.custom_class || '';
        classInput.addEventListener('change', function () {
            var val = classInput.value.trim();
            if (val) p.config.style.custom_class = val; else delete p.config.style.custom_class;
            markLocDirty(loc);
        });
        classRow.appendChild(classInput);
        customBody.appendChild(classRow);

        var cssRow = el('div', 'bhel-field-row');
        cssRow.appendChild(el('label', null, 'Custom CSS (inline)'));
        var cssArea = document.createElement('textarea');
        cssArea.rows = 4;
        cssArea.placeholder = 'e.g. text-transform: uppercase; letter-spacing: .05em;';
        cssArea.value = p.config.style.custom_css || '';
        cssArea.addEventListener('input', function () {
            var val = cssArea.value.trim();
            if (val) p.config.style.custom_css = cssArea.value; else delete p.config.style.custom_css;
            markLocDirty(loc);
        });
        cssRow.appendChild(cssArea);
        customBody.appendChild(cssRow);

        customDetails.appendChild(customBody);
        wrap.appendChild(customDetails);
    }

    // AJ's own ask, as part of the GUI (not a hidden config-only
    // feature): "easy ways to wire up UI events to actions... 'On
    // click' could trigger UI and server side stuff via fetch." Two
    // genuinely different trust levels, two genuinely different UIs:
    //
    //  - "On click" actions (p.config.actions) — a plain, codeless list
    //    builder. Every author who can edit this placement at all can
    //    add one; class-element.php's build_actions_js() maps each row
    //    to a small, fixed, reviewed JS snippet server-side, never raw
    //    script. No capability gate needed here for the same reason.
    //
    //  - Custom JS (p.config.custom_js) — real, raw JavaScript, run on
    //    the live site for every visitor. Only shown at all if
    //    cfg.canAuthorCustomJs (server-localized from the real
    //    bhcore_author_custom_js capability check — class-element-
    //    builder.php's enqueue_assets()), AND still requires an explicit
    //    "I understand this runs unreviewed on the live site" checkbox
    //    before the textarea itself becomes usable — the field existing
    //    at all shouldn't mean a stray click can silently arm it.
    var ACTION_KINDS = [
        { value: 'toggle_class', label: 'Toggle a CSS class' },
        { value: 'fetch', label: 'Call a URL (fetch)' },
        { value: 'navigate', label: 'Go to a URL' },
    ];
    var ACTION_TRIGGERS = ['click', 'mouseenter', 'mouseleave', 'submit'];

    function renderActionsSection(p, loc) {
        if (!Array.isArray(p.config.actions)) p.config.actions = [];

        inspectorEl.appendChild(el('h3', null, 'Actions & scripting'));
        var wrap = el('div', 'bhel-actions-section');
        inspectorEl.appendChild(wrap);

        var list = el('div', 'bhel-actions-list');
        wrap.appendChild(list);

        function renderList() {
            list.innerHTML = '';
            p.config.actions.forEach(function (action, idx) {
                var row = el('div', 'bhel-action-row');

                var triggerSelect = document.createElement('select');
                ACTION_TRIGGERS.forEach(function (t) {
                    var opt = document.createElement('option');
                    opt.value = t; opt.textContent = 'On ' + t;
                    if ((action.trigger || 'click') === t) opt.selected = true;
                    triggerSelect.appendChild(opt);
                });
                triggerSelect.addEventListener('change', function () {
                    action.trigger = triggerSelect.value;
                    markLocDirty(loc);
                });
                row.appendChild(triggerSelect);

                var kindSelect = document.createElement('select');
                ACTION_KINDS.forEach(function (k) {
                    var opt = document.createElement('option');
                    opt.value = k.value; opt.textContent = k.label;
                    if (action.action === k.value) opt.selected = true;
                    kindSelect.appendChild(opt);
                });
                kindSelect.addEventListener('change', function () {
                    action.action = kindSelect.value;
                    markLocDirty(loc);
                    renderList(); // param fields below depend on the picked kind
                });
                row.appendChild(kindSelect);

                if (action.action === 'toggle_class') {
                    var classInput = document.createElement('input');
                    classInput.type = 'text';
                    classInput.placeholder = 'class name, e.g. is-open';
                    classInput.value = action.class || '';
                    classInput.addEventListener('change', function () { action.class = classInput.value.trim(); markLocDirty(loc); });
                    row.appendChild(classInput);

                    var targetInput = document.createElement('input');
                    targetInput.type = 'text';
                    targetInput.placeholder = 'target: self (default) or a CSS selector';
                    targetInput.value = action.target || '';
                    targetInput.addEventListener('change', function () { action.target = targetInput.value.trim(); markLocDirty(loc); });
                    row.appendChild(targetInput);
                } else if (action.action === 'fetch') {
                    var urlInput = document.createElement('input');
                    urlInput.type = 'text';
                    urlInput.placeholder = 'URL to call';
                    urlInput.value = action.url || '';
                    urlInput.addEventListener('change', function () { action.url = urlInput.value.trim(); markLocDirty(loc); });
                    row.appendChild(urlInput);

                    var methodSelect = document.createElement('select');
                    ['GET', 'POST'].forEach(function (m) {
                        var opt = document.createElement('option');
                        opt.value = m; opt.textContent = m;
                        if ((action.method || 'GET') === m) opt.selected = true;
                        methodSelect.appendChild(opt);
                    });
                    methodSelect.addEventListener('change', function () { action.method = methodSelect.value; markLocDirty(loc); });
                    row.appendChild(methodSelect);

                    var thenSelect = document.createElement('select');
                    [['none', 'Do nothing after'], ['reload', 'Reload the page after']].forEach(function (pair) {
                        var opt = document.createElement('option');
                        opt.value = pair[0]; opt.textContent = pair[1];
                        if ((action.then || 'none') === pair[0]) opt.selected = true;
                        thenSelect.appendChild(opt);
                    });
                    thenSelect.addEventListener('change', function () { action.then = thenSelect.value; markLocDirty(loc); });
                    row.appendChild(thenSelect);
                } else if (action.action === 'navigate') {
                    var navInput = document.createElement('input');
                    navInput.type = 'text';
                    navInput.placeholder = 'URL to go to';
                    navInput.value = action.url || '';
                    navInput.addEventListener('change', function () { action.url = navInput.value.trim(); markLocDirty(loc); });
                    row.appendChild(navInput);
                }

                var removeBtn = el('button', 'bhel-action-remove', '✕');
                removeBtn.type = 'button';
                removeBtn.title = 'Remove this action';
                removeBtn.addEventListener('click', function () {
                    p.config.actions.splice(idx, 1);
                    markLocDirty(loc);
                    renderList();
                });
                row.appendChild(removeBtn);

                list.appendChild(row);
            });
        }
        renderList();

        var addBtn = el('button', 'button', '+ Add action');
        addBtn.type = 'button';
        addBtn.addEventListener('click', function () {
            p.config.actions.push({ trigger: 'click', action: 'toggle_class' });
            markLocDirty(loc);
            renderList();
        });
        wrap.appendChild(addBtn);

        // Custom JS — only reachable at all with the real capability;
        // cfg comes from bhElementBuilderConfig (class-element-builder.php's
        // wp_localize_script()), same object every other cfg.* read in
        // this file already uses.
        if (typeof cfg !== 'undefined' && cfg.canAuthorCustomJs) {
            var jsDetails = document.createElement('details');
            jsDetails.className = 'bhel-style-group';
            if (p.config.custom_js) jsDetails.open = true;
            var jsSummary = document.createElement('summary');
            jsSummary.className = 'bhel-style-group-title';
            jsSummary.textContent = 'Custom JS (advanced)' + (p.config.custom_js ? ' •' : '');
            jsDetails.appendChild(jsSummary);

            var jsBody = el('div', 'bhel-style-group-body');
            jsBody.appendChild(el('p', 'bhel-empty', 'Runs as real, unreviewed JavaScript on the live site for every visitor who loads this element. Your own code, your own responsibility — there is no sandbox.'));

            var confirmRow = el('label', 'bhel-field-row');
            var confirmCb = document.createElement('input');
            confirmCb.type = 'checkbox';
            confirmCb.checked = !!p.config.custom_js;
            confirmRow.appendChild(confirmCb);
            confirmRow.appendChild(document.createTextNode(' I understand this runs unreviewed on the live site'));
            jsBody.appendChild(confirmRow);

            var jsArea = document.createElement('textarea');
            jsArea.rows = 6;
            jsArea.placeholder = 'el is this placement\'s own DOM element, e.g.: el.style.opacity = 0.5;';
            jsArea.value = p.config.custom_js || '';
            jsArea.disabled = !confirmCb.checked;
            jsBody.appendChild(jsArea);

            confirmCb.addEventListener('change', function () {
                jsArea.disabled = !confirmCb.checked;
                if (!confirmCb.checked) { jsArea.value = ''; p.config.custom_js = ''; markLocDirty(loc); }
            });
            jsArea.addEventListener('input', function () {
                p.config.custom_js = jsArea.value;
                markLocDirty(loc);
            });

            jsDetails.appendChild(jsBody);
            wrap.appendChild(jsDetails);
        }
    }

    // One "group.property" control: a preset <select> (values sourced
    // from the schema's 'options' map, or the theme color-token
    // vocabulary for 'token-only' kinds) plus, for every kind EXCEPT
    // 'token-only' (colors are always token refs, never raw hex —
    // mirrored client-side, though the real enforcement is BHY_Style::
    // resolve_style_value()'s server-side kind check), a free-text
    // custom-value escape hatch stored as "custom:<value>". Values
    // written to p.config.style[def.key] verbatim in whichever form
    // (bare preset key / "@token:x" / "custom:x") BHY_Style::
    // resolve_style_value() already expects.
    /**
     * The custom color-token dropdown referenced above: a trigger button
     * (current swatch + label) that toggles a small popup list (swatch +
     * label per row, plus a leading "(inherit)" row with no swatch).
     * `colorTokens` is `{tokenName: cssVarName}` (BHY_Style::
     * style_schema_for_js(), 3.4.49 follow-up — its VALUES used to just
     * echo the token name back; now they're the real CSS custom property,
     * e.g. '--bh-accent', so a swatch can literally be
     * `background: var(--bh-accent)` and stay live/correct even while
     * AJ is mid-edit in the Global Styles token sliders (same
     * `.bhy-token-preview` live-rebuild mechanism class-style-gallery.php
     * already drives for its own sample-chip strip — reused here by
     * giving each swatch that same class rather than inventing a second
     * one).
     */
    function buildColorTokenPopup(select, colorTokens) {
        var wrap = el('div', 'bhel-color-select');
        var trigger = el('button', 'bhel-color-select-trigger');
        trigger.type = 'button';
        var triggerSwatch = el('span', 'bhel-color-swatch bhy-token-preview');
        var triggerLabel = el('span', 'bhel-color-select-label');
        trigger.appendChild(triggerSwatch);
        trigger.appendChild(triggerLabel);
        wrap.appendChild(trigger);

        var popup = el('div', 'bhel-color-select-popup');
        popup.style.display = 'none';
        wrap.appendChild(popup);

        function currentTokenName() {
            var v = select.value;
            return v.indexOf('@token:') === 0 ? v.slice(7) : '';
        }
        function syncTrigger() {
            var tok = currentTokenName();
            if (tok && colorTokens[tok]) {
                triggerSwatch.style.background = 'var(' + colorTokens[tok] + ')';
                triggerSwatch.style.visibility = '';
                triggerLabel.textContent = tok;
            } else {
                triggerSwatch.style.visibility = 'hidden';
                triggerLabel.textContent = '(inherit)';
            }
        }
        function choose(value) {
            select.value = value;
            select.dispatchEvent(new Event('change'));
            syncTrigger();
            popup.style.display = 'none';
        }

        var blankRow = el('button', 'bhel-color-select-row');
        blankRow.type = 'button';
        blankRow.appendChild(el('span', 'bhel-color-swatch', '')); // empty/transparent — no token selected
        blankRow.appendChild(el('span', null, '(inherit)'));
        blankRow.addEventListener('click', function () { choose(''); });
        popup.appendChild(blankRow);

        Object.keys(colorTokens).forEach(function (tok) {
            var rowBtn = el('button', 'bhel-color-select-row');
            rowBtn.type = 'button';
            var swatch = el('span', 'bhel-color-swatch bhy-token-preview');
            swatch.style.background = 'var(' + colorTokens[tok] + ')';
            rowBtn.appendChild(swatch);
            rowBtn.appendChild(el('span', null, tok));
            rowBtn.addEventListener('click', function () { choose('@token:' + tok); });
            popup.appendChild(rowBtn);
        });

        trigger.addEventListener('click', function () {
            var opening = popup.style.display === 'none';
            // Only one color popup open at a time — same "closes anything
            // else already open" courtesy every other popup/context-menu
            // in this file already shows (openAddChildPicker(), the node
            // context menu).
            document.querySelectorAll('.bhel-color-select-popup').forEach(function (p) { p.style.display = 'none'; });
            popup.style.display = opening ? '' : 'none';
        });
        document.addEventListener('click', function (ev) {
            if (!wrap.contains(ev.target)) popup.style.display = 'none';
        });

        syncTrigger();
        return wrap;
    }

    function renderStylePropertyField(p, fieldset, def, loc) {
        var row = el('div', 'bhel-field-row');
        row.appendChild(el('label', null, def.css + ' (' + def.key + ')'));

        var current = p.config.style[def.key] !== undefined ? String(p.config.style[def.key]) : '';
        var isCustom = current.indexOf('custom:') === 0;
        var isToken = current.indexOf('@token:') === 0;

        var select = document.createElement('select');
        var blank = document.createElement('option');
        blank.value = ''; blank.textContent = '(inherit)';
        select.appendChild(blank);

        if (def.kind === 'token-only') {
            Object.keys(state.styleSchema.colorTokens || {}).forEach(function (tok) {
                var o = document.createElement('option');
                o.value = '@token:' + tok;
                o.textContent = tok;
                if (isToken && current === o.value) o.selected = true;
                select.appendChild(o);
            });
        } else if (def.options) {
            Object.keys(def.options).forEach(function (val) {
                var o = document.createElement('option');
                o.value = val;
                o.textContent = def.options[val] + (String(def.options[val]) !== String(val) ? ' (' + val + ')' : '');
                if (!isCustom && !isToken && current === val) o.selected = true;
                select.appendChild(o);
            });
        }

        if (def.allowCustom) {
            var customOpt = document.createElement('option');
            customOpt.value = '__custom__';
            customOpt.textContent = 'Custom…';
            if (isCustom) customOpt.selected = true;
            select.appendChild(customOpt);
        }

        row.appendChild(select);

        // AJ's own ask: "would be cool if the color and font selectors
        // could preview what they look like... with like swatches in the
        // dropdown next to the option." A native <option> can't host a
        // colored swatch (most browsers flatly ignore background-color
        // styling inside <option> — this is a real, well-known HTML
        // limitation, not an oversight; font-family styling on <option>
        // DOES work, which is why the separate Global Styles font picker
        // (BHY_UI::font_field(), class-ui.php) just got an inline style
        // added directly instead of needing this same custom-popup
        // treatment). For 'token-only' (color) fields only, this builds a
        // real custom dropdown with actual swatches, while leaving the
        // native <select> above as the untouched single source of truth
        // for the actual value/state — the popup only ever sets
        // select.value and dispatches a real 'change' event, so the
        // EXISTING handler below (unchanged) still does 100% of the
        // actual write-back/dirty-marking/live-preview work. This keeps
        // the two code paths from drifting against each other; the
        // popup is purely a presentation layer on top of the same select.
        if (def.kind === 'token-only') {
            select.style.display = 'none';
            row.appendChild(buildColorTokenPopup(select, state.styleSchema.colorTokens || {}));
        }

        var customInput = null;
        if (def.allowCustom) {
            customInput = document.createElement('input');
            customInput.type = 'text';
            customInput.placeholder = 'raw CSS value for ' + def.css;
            customInput.value = isCustom ? current.slice(7) : '';
            customInput.style.display = isCustom ? '' : 'none';
            customInput.addEventListener('change', function () {
                if (customInput.value.trim() === '') {
                    delete p.config.style[def.key];
                } else {
                    p.config.style[def.key] = 'custom:' + customInput.value.trim();
                }
                markLocDirty(loc);
            });
            row.appendChild(customInput);
        }

        select.addEventListener('change', function () {
            if (select.value === '') {
                delete p.config.style[def.key];
            } else if (select.value === '__custom__') {
                if (customInput) { customInput.style.display = ''; customInput.focus(); }
                return; // don't write a value until the custom text is actually entered
            } else {
                p.config.style[def.key] = select.value;
            }
            if (customInput && select.value !== '__custom__') customInput.style.display = 'none';
            markLocDirty(loc);
        });

        fieldset.appendChild(row);
    }

    /* ---------------- HTML Attributes (§2.6 — type.tags/type.attrs manifest, config.htmlAttrs) ---------------- */
    // Built ENTIRELY from the CURRENTLY SELECTED placement's type manifest
    // (type.tags / type.attrs, both already returned by GET .../elements/
    // types — see class-element.php's rest_get_types() docblock) — no
    // per-type-slug branching in this file; a brand new element type
    // registered server-side with its own tags/attrs gets a working
    // inspector section here with zero JS changes. Writes into
    // p.config.htmlAttrs, which BH_Element::wrap_placement_html()/
    // build_html_attrs() already strictly re-allowlist server-side
    // against the SAME type.attrs manifest — this UI only avoids
    // offering controls the server would drop anyway, it is not itself
    // the security boundary (see class-element.php's build_html_attrs()
    // docblock for that boundary).
    function renderHtmlAttrsSection(p, type, loc) {
        var schema = type.attrs || {};
        var tags = (type.tags && type.tags.length) ? type.tags : ['div'];

        inspectorEl.appendChild(el('h3', null, 'HTML Attributes'));
        var wrap = el('div', 'bhel-attr-fieldset');
        inspectorEl.appendChild(wrap);

        var ha = p.config.htmlAttrs;
        var currentTag = ha.tag && tags.indexOf(ha.tag) !== -1 ? ha.tag : tags[0];

        // Tag picker.
        var tagRow = el('div', 'bhel-field-row');
        tagRow.appendChild(el('label', null, 'Tag'));
        var tagSelect = document.createElement('select');
        tags.forEach(function (tagName) {
            var o = document.createElement('option');
            o.value = tagName; o.textContent = '<' + tagName + '>';
            if (tagName === currentTag) o.selected = true;
            tagSelect.appendChild(o);
        });
        tagSelect.addEventListener('change', function () {
            ha.tag = tagSelect.value;
            markLocDirty(loc);
            renderInspector(); // re-render so href/target/rel show/hide follows the new tag, matching resolve_tag()'s server-side "only meaningful on <a>" rule
        });
        tagRow.appendChild(tagSelect);
        wrap.appendChild(tagRow);

        // Simple text attrs — id/class/title/aria-label — offered only
        // when the type's manifest opted each one in (schema[key] truthy).
        ['id', 'class', 'title', 'aria-label'].forEach(function (key) {
            if (!schema[key]) return;
            var row = el('div', 'bhel-field-row');
            row.appendChild(el('label', null, key));
            var input = document.createElement('input');
            input.type = 'text';
            input.value = ha[key] || '';
            input.addEventListener('change', function () {
                if (input.value.trim() === '') delete ha[key]; else ha[key] = input.value.trim();
                markLocDirty(loc);
            });
            row.appendChild(input);
            wrap.appendChild(row);
        });

        // href/target/rel — ONLY rendered when the CURRENT tag is 'a', an
        // exact mirror of BH_Element::build_html_attrs()'s "$tag === 'a'"
        // gate — these controls simply don't exist in the DOM for any
        // other tag rather than being present-but-disabled.
        if (currentTag === 'a') {
            if (schema['href']) {
                var hrefRow = el('div', 'bhel-field-row');
                hrefRow.appendChild(el('label', null, 'href'));
                var hrefInput = document.createElement('input');
                hrefInput.type = 'text';
                hrefInput.value = ha.href || '';
                hrefInput.addEventListener('change', function () {
                    if (hrefInput.value.trim() === '') delete ha.href; else ha.href = hrefInput.value.trim();
                    markLocDirty(loc);
                });
                hrefRow.appendChild(hrefInput);
                wrap.appendChild(hrefRow);
            }
            if (schema['target']) {
                var targetRow = el('div', 'bhel-field-row');
                targetRow.appendChild(el('label', null, 'target'));
                var targetSelect = document.createElement('select');
                var targetBlank = document.createElement('option');
                targetBlank.value = ''; targetBlank.textContent = '(default)';
                targetSelect.appendChild(targetBlank);
                var targetEnum = (schema['target'] && schema['target'].enum) ? schema['target'].enum : ['_self', '_blank'];
                targetEnum.forEach(function (val) {
                    var o = document.createElement('option');
                    o.value = val; o.textContent = val;
                    if (ha.target === val) o.selected = true;
                    targetSelect.appendChild(o);
                });
                targetSelect.addEventListener('change', function () {
                    if (targetSelect.value === '') delete ha.target; else ha.target = targetSelect.value;
                    markLocDirty(loc);
                });
                targetRow.appendChild(targetSelect);
                wrap.appendChild(targetRow);
            }
            if (schema['rel']) {
                var relRow = el('div', 'bhel-field-row');
                relRow.appendChild(el('label', null, 'rel (space-separated: noopener noreferrer nofollow sponsored ugc)'));
                var relInput = document.createElement('input');
                relInput.type = 'text';
                relInput.value = ha.rel || '';
                relInput.addEventListener('change', function () {
                    if (relInput.value.trim() === '') delete ha.rel; else ha.rel = relInput.value.trim();
                    markLocDirty(loc);
                });
                relRow.appendChild(relInput);
                wrap.appendChild(relRow);
            }
        }

        renderDataAttrsEditor(p, type, wrap, loc);
    }

    // Pre-declared structured data-* attrs (schema key like 'data-status'
    // mapped to ['enum' => [...]]) render as a <select> of the declared
    // enum, same fail-closed contract BH_Element::build_html_attrs()
    // enforces server-side. The blanket 'data-*' => true opt-in (if the
    // type declares it) additionally gets a repeatable freeform key/value
    // row editor for any OTHER data-* attribute not already pre-declared.
    // Both write into the SAME p.config.htmlAttrs.custom map (keyed
    // WITHOUT the 'data-' prefix, matching build_html_attrs()'s own
    // 'data-' . $key reconstruction).
    function renderDataAttrsEditor(p, type, wrap, loc) {
        var schema = type.attrs || {};
        var custom = p.config.htmlAttrs.custom;

        var declaredDataAttrs = Object.keys(schema).filter(function (k) { return k.indexOf('data-') === 0 && k !== 'data-*'; });
        declaredDataAttrs.forEach(function (attrName) {
            var shortKey = attrName.slice(5); // strip 'data-'
            var def = schema[attrName];
            var row = el('div', 'bhel-field-row');
            row.appendChild(el('label', null, attrName));
            if (def && def.enum) {
                var select = document.createElement('select');
                var blank = document.createElement('option');
                blank.value = ''; blank.textContent = '(none)';
                select.appendChild(blank);
                def.enum.forEach(function (val) {
                    var o = document.createElement('option');
                    o.value = val; o.textContent = val;
                    if (custom[shortKey] === val) o.selected = true;
                    select.appendChild(o);
                });
                select.addEventListener('change', function () {
                    if (select.value === '') delete custom[shortKey]; else custom[shortKey] = select.value;
                    markLocDirty(loc);
                });
                row.appendChild(select);
            } else {
                var input = document.createElement('input');
                input.type = 'text';
                input.value = custom[shortKey] || '';
                input.addEventListener('change', function () {
                    if (input.value.trim() === '') delete custom[shortKey]; else custom[shortKey] = input.value.trim();
                    markLocDirty(loc);
                });
                row.appendChild(input);
            }
            wrap.appendChild(row);
        });

        if (!schema['data-*']) return; // type didn't opt into the freeform escape hatch — no repeatable editor offered (server would drop these anyway)

        var freeformTitle = el('div', 'bhel-style-group-title', 'Custom data-* attributes');
        wrap.appendChild(freeformTitle);

        var rowsWrap = el('div', 'bhel-data-rows');
        wrap.appendChild(rowsWrap);

        var declaredShortKeys = declaredDataAttrs.map(function (a) { return a.slice(5); });
        function freeformKeys() {
            return Object.keys(custom).filter(function (k) { return declaredShortKeys.indexOf(k) === -1; });
        }

        function renderRows() {
            rowsWrap.innerHTML = '';
            freeformKeys().forEach(function (key) {
                appendDataRow(key, custom[key]);
            });
            appendDataRow('', ''); // one trailing blank row to add a new entry
        }

        function appendDataRow(initialKey, initialVal) {
            var row = el('div', 'bhel-data-row');
            var keyInput = document.createElement('input');
            keyInput.type = 'text';
            keyInput.placeholder = 'key (without data- prefix)';
            keyInput.value = initialKey;
            var valInput = document.createElement('input');
            valInput.type = 'text';
            valInput.placeholder = 'value';
            valInput.value = initialVal;
            var removeBtn = iconBtn('✕', 'Remove this data-* row', function () {
                if (initialKey && custom.hasOwnProperty(initialKey)) delete custom[initialKey];
                markLocDirty(loc);
                renderRows();
            });

            function commit() {
                var newKey = keyInput.value.trim().toLowerCase().replace(/[^a-z0-9-]/g, '');
                if (initialKey && initialKey !== newKey && custom.hasOwnProperty(initialKey)) delete custom[initialKey];
                if (newKey !== '') {
                    custom[newKey] = valInput.value;
                    markLocDirty(loc);
                }
                initialKey = newKey;
                renderRows();
            }
            keyInput.addEventListener('change', commit);
            valInput.addEventListener('change', commit);

            row.appendChild(keyInput);
            row.appendChild(valInput);
            row.appendChild(removeBtn);
            rowsWrap.appendChild(row);
        }

        renderRows();
    }

    /* ---------------- save ---------------- */
    // 3.4.37 — the ONE global save action, replacing the old per-slot
    // "Save slot" topbar button (see this file's own docblock for why:
    // with every surface/slot loaded as tree nodes at once, there is no
    // longer a single "current slot" for a per-slot button to target).
    // POSTs each dirty (surface, context_id, slot) in turn against the
    // SAME unchanged POST .../elements/placements/{surface}/{context_id}
    // route (still slot-scoped server-side — one call per dirty slot),
    // then reloads every distinct (surface, context_id) touched so
    // client-side ids (0 for new rows) become the real saved ids, same
    // reasoning the old savePlacements() reload used.
    function globalSave() {
        var keys = Object.keys(state.dirtyKeys);
        if (!keys.length) {
            setStatus('Nothing to save — no changes.', 'ok');
            return;
        }
        setStatus('Saving ' + keys.length + ' slot(s)…');
        var promises = keys.map(function (k) {
            var parts = k.split(' ');
            var surface = parts[0], contextId = parseInt(parts[1], 10) || 0, slot = parts[2];
            var loc = { surface: surface, contextId: contextId, slot: slot };
            var placements = getPlacementsArray(loc);
            var body = {
                slot: slot,
                placements: placements.map(function (p) {
                    return {
                        id: p.id || 0,
                        element_type: p.element_type,
                        config: p.config,
                        content_context_id: p.content_context_id || 0,
                        enabled: p.enabled !== false,
                        parent_placement_id: p.parent_placement_id || 0, // class-element.php's rest_save_placements() computes 'position' per parent group from this
                    };
                }),
            };
            return api('placements/' + encodeURIComponent(surface) + '/' + contextId, { method: 'POST', body: body })
                .then(function () {
                    delete state.dirtyKeys[k];
                    return { surface: surface, contextId: contextId };
                });
        });
        Promise.all(promises).then(function (results) {
            var seen = {};
            var reloads = [];
            results.forEach(function (r) {
                var rk = slotDataKey(r.surface, r.contextId);
                if (seen[rk]) return;
                seen[rk] = true;
                reloads.push(loadSurfaceData(r.surface, r.contextId));
            });
            return Promise.all(reloads);
        }).then(function () {
            setStatus('Saved ' + keys.length + ' slot(s).', 'ok');
            renderCanvas();
            renderInspector();
        }).catch(function (e) {
            setStatus('Save failed: ' + e.message, 'error');
        });
    }

    /* ---------------- small DOM helpers ---------------- */
    function el(tag, className, text) {
        var e = document.createElement(tag);
        if (className) e.className = className;
        if (text !== undefined && text !== null) e.textContent = text;
        return e;
    }
    function iconBtn(text, title, onClick) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'button';
        b.title = title;
        b.textContent = text;
        b.addEventListener('click', onClick);
        return b;
    }
    function setStatus(text, kind) {
        status.textContent = text;
        status.className = 'bhel-status' + (kind === 'error' ? ' bhel-status-error' : kind === 'ok' ? ' bhel-status-ok' : '');
    }
})();
