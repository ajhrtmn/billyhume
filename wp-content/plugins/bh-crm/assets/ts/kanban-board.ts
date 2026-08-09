/**
 * Kanban board — vanilla JS, no build step (this ecosystem's standing
 * convention; see own-ur-shit/assets/js/element-builder.js for the
 * sibling REST-call/nonce pattern this file's api() helper is copied
 * from verbatim). Mounts into #bhcrm-kanban-board
 * (bh-crm/includes/class-projects.php's render_board()).
 *
 * THIS IS A THIN PRESENTATION LAYER, NOT A PARALLEL DATA MODEL: every
 * read/write below goes through the EXISTING BH_Element REST bridge
 * (own-ur-shit's class-element.php) —
 *   GET  ous/v1/elements/placements/{surface}/{context_id}
 *   POST ous/v1/elements/placements/{surface}/{context_id}   (full-slot upsert, same as element-builder.js's "Save slot")
 *   DELETE ous/v1/elements/placements/{id}                    (true delete, for the card's own "Delete" action)
 * — no bh-crm-owned table stores card content anywhere; a card IS a
 * bh/sticky-card placement row, full stop. The "kanban column" a card
 * sits in is just its own config.attrs.column literal (see
 * class-projects.php's docblock for why that's a plain attr, not a
 * separate slot) — dragging a card to another column edits that one
 * attr client-side, then re-saves the WHOLE slot in the new order, the
 * same full-slot-upsert contract rest_save_placements() already defines.
 *
 * Recursive sub-task nesting (a card's own children) is NOT edited here
 * — a card's "Edit sub-tasks" link opens the EXISTING Content Studio
 * canvas (admin.php?page=bh-studio&context_type=bh_element&context_id=)
 * against that placement's content_context_id, exactly the way
 * element-builder.js's own inspector already tells a user to do for any
 * container element. Building a second, bespoke recursive-tree editor
 * inside this board was deliberately out of scope for this pass.
 *
 * Drag-and-drop uses SortableJS (assets/js/vendor/sortable.min.js,
 * MIT, vendored not npm — this ecosystem's no-build-step convention),
 * enqueued as this script's own dependency (class-projects.php's
 * maybe_enqueue()). Replaces an earlier hand-rolled HTML5 DnD
 * implementation (dragstart/dragover/drop) that only ever supported
 * dropping a card at the END of a column — no real same-column
 * reorder, and genuinely untested cross-browser/touch-device drag
 * behavior, exactly the risk a real drag library exists to absorb.
 * One Sortable instance per column list (`group: 'bhcrm-kanban'` lets
 * cards move between columns), `onEnd` rebuilds state.placements from
 * the live DOM order across every column and re-saves the whole slot —
 * same full-slot-upsert contract saveSlot() already uses everywhere
 * else in this file, so drag-reorder isn't a second write path.
 *
 * TypeScript pilot conversion — same posture as subtasks.ts (this
 * plugin's sibling multi-column Sortable board). Sortable and
 * BHCoreToast are untyped external globals; the placement row shape
 * mirrors own-ur-shit's BH_Element placement REST response loosely
 * (config.attrs values are either {literal: T} or {bind: string}).
 */

interface SortableInstance {
    destroy(): void;
}

// SortableOptions/SortableApi and the `Sortable`/`BHCoreToast` globals
// are declared once, in subtasks.ts — both files compile as part of the
// same tsc program (module: "none" means shared global scope, same as
// the plain <script> tags this compiles to), so redeclaring them here
// would conflict rather than merge. Sortable.create()'s real return
// value (a Sortable instance with .destroy()) is typed `unknown` over
// there since that file never calls .destroy(); cast it locally instead
// of widening the shared interface.

interface BHCrmKanbanConfig {
    restUrl?: string;
    nonce?: string;
    surface?: string;
    projectId?: string | number;
    columns?: string[];
    rollupsUrl?: string;
    stalledCardsUrl?: string;
}

interface BHKanbanWindow extends Window {
    bhcrmKanbanConfig?: BHCrmKanbanConfig;
    Sortable?: SortableApi;
}

type BHAttrValue<T> = { literal: T } | { bind: string } | T | undefined | null;

interface BHPlacementConfig {
    attrs?: Record<string, BHAttrValue<unknown>>;
}

interface BHPlacement {
    id: number;
    element_type: string;
    content_context_id: number;
    config?: BHPlacementConfig;
}

interface BHPlacementsResponse {
    board?: BHPlacement[];
    placements?: BHPlacement[];
}

(function () {
    'use strict';

    const win = window as BHKanbanWindow;
    const cfg: BHCrmKanbanConfig = win.bhcrmKanbanConfig || {};
    const root = document.getElementById('bhcrm-kanban-board');
    if (!root || !cfg.restUrl) return;

    function api<T = unknown>(path: string, opts?: { method?: string; body?: unknown; headers?: Record<string, string> }): Promise<T> {
        opts = opts || {};
        const headers: Record<string, string> = opts.headers || {};
        headers['X-WP-Nonce'] = cfg.nonce ?? '';
        if (opts.body) headers['Content-Type'] = 'application/json';
        return fetch(cfg.restUrl + path, {
            method: opts.method || 'GET',
            credentials: 'same-origin',
            headers: headers,
            body: opts.body ? JSON.stringify(opts.body) : undefined,
        }).then((res) => {
            if (!res.ok) {
                return res.json().catch(() => ({})).then((err: { message?: string }) => {
                    // A 401/403 previously surfaced whatever generic
                    // REST error text WordPress happened to send (or
                    // none at all, falling back to "HTTP 403") — reads
                    // like the SAVE failed, not that the admin's own
                    // session/nonce went stale (e.g. this tab sat open
                    // past a login timeout).
                    if ((res.status === 401 || res.status === 403) && !(err && err.message)) {
                        throw new Error('Your session has expired — refresh the page and log in again.');
                    }
                    throw new Error((err && err.message) || ('HTTP ' + res.status));
                });
            }
            return res.json();
        });
    }

    const state: {
        placements: BHPlacement[];
        sortables: SortableInstance[];
        rollups: Record<string, [number, number]>;
        stalled: Record<string, number>;
        flashId: number | null;
    } = {
        placements: [], // raw rows from GET .../placements/{surface}/{context}, slot 'board' only
        sortables: [], // live Sortable instances, one per column list — destroyed before each re-render since render() wipes the DOM they're attached to
        // {placementId: [done, total]} — a card's own recursive
        // sub-task rollup (AJ's own ask: "each card should track the
        // total progress of everything under it... display it back up
        // on the card itself"). NOT part of the generic BH_Element
        // placements response (that returns a placement's own config/
        // attrs, never its BH_Content tree) — fetched once per board
        // load from bh-crm's own small rollups route
        // (BHCRM_Projects::rest_rollups()) instead of a per-card round
        // trip.
        rollups: {},
        // {placementId: daysSinceLastMove} — Phase C stall analytics,
        // fetched from bh-crm's own /stalled-cards route (see
        // loadStalledCards()). Only ever contains cards that actually
        // cross BHCRM_Projects::STALL_DAYS — a card's absence here
        // means "not stalled" OR "no move history yet," not "stalled by
        // some other unknown amount."
        stalled: {},
        flashId: null, // set by saveSlot(), consumed once by renderCard() — see saveSlot()'s own docblock
    };

    function el<K extends keyof HTMLElementTagNameMap>(tag: K, className?: string, text?: string): HTMLElementTagNameMap[K] {
        const e = document.createElement(tag);
        if (className) e.className = className;
        if (text !== undefined) e.textContent = text;
        return e;
    }

    function placementsPath(): string {
        return 'placements/' + encodeURIComponent(cfg.surface ?? '') + '/' + encodeURIComponent(String(cfg.projectId ?? ''));
    }

    function loadRollups(): Promise<void> {
        if (!cfg.rollupsUrl) return Promise.resolve();
        return fetch(cfg.rollupsUrl + '?project_id=' + encodeURIComponent(String(cfg.projectId ?? '')), {
            headers: { 'X-WP-Nonce': cfg.nonce ?? '' },
            credentials: 'same-origin',
        }).then((res) => (res.ok ? res.json() : {}))
            .then((data) => { state.rollups = data || {}; })
            .catch(() => { state.rollups = {}; });
    }

    // Phase C stall analytics — {placementId: daysSinceLastMove}, only
    // for cards that actually cross BHCRM_Projects::STALL_DAYS; same
    // "one small bh-crm route, fetched once per board load" shape as
    // loadRollups() above.
    function loadStalledCards(): Promise<void> {
        if (!cfg.stalledCardsUrl) return Promise.resolve();
        return fetch(cfg.stalledCardsUrl + '?project_id=' + encodeURIComponent(String(cfg.projectId ?? '')), {
            headers: { 'X-WP-Nonce': cfg.nonce ?? '' },
            credentials: 'same-origin',
        }).then((res) => (res.ok ? res.json() : {}))
            .then((data) => { state.stalled = data || {}; })
            .catch(() => { state.stalled = {}; });
    }

    function load(): void {
        root!.setAttribute('data-loading', '1');
        Promise.all([api<BHPlacementsResponse>(placementsPath()), loadRollups(), loadStalledCards()]).then((results) => {
            const grouped = results[0];
            state.placements = (grouped && grouped.board) ? grouped.board : [];
            render();
        }).catch((err) => {
            // Previously surfaced the raw exception message straight to
            // the user (e.g. a fetch/parse error string) — inconsistent
            // with the friendly copy this ecosystem uses everywhere
            // else. Real detail still goes to the console for whoever's
            // actually debugging it.
            console.error('bh-crm kanban board load failed:', err);
            root!.innerHTML = '';
            const p = el('p', 'description', 'Could not load the board — please try again.');
            root!.appendChild(p);
        });
    }

    // BHCoreToast (own-ur-shit core, loaded on every admin screen — see
    // class-toast.php's enqueue_assets(), hooked to admin_enqueue_scripts
    // unconditionally) replaces every alert() that used to run this
    // board's error path silently-broken-into-a-blocking-dialog. Same
    // typeof guard every other call site in this ecosystem uses in case
    // toast.js somehow isn't loaded.
    function reportSaveError(err: Error, action?: string): void {
        const msg = 'Failed to ' + (action || 'save') + ': ' + err.message;
        if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(msg, 'error'); } else { alert(msg); }
    }

    /** Full-slot upsert — mirrors element-builder.js's "Save slot" exactly: send every current placement in the desired order, 'position' is reconstructed server-side from array order. flashId, when given, is the ONE card render() should visually flash as "just saved" — render() wipes and rebuilds every card element on every call, so a reference to the pre-save DOM node would be stale; tracking the id instead lets renderCard() re-attach the flash to whichever fresh element ends up representing that same card. */
    function saveSlot(flashId?: number): Promise<void> {
        const body = {
            slot: 'board',
            placements: state.placements.map((p) => ({
                id: p.id,
                element_type: p.element_type,
                config: p.config,
                content_context_id: p.content_context_id,
                enabled: true,
            })),
        };
        return api<BHPlacementsResponse>(placementsPath(), { method: 'POST', body: body }).then((res) => {
            state.placements = res.placements || state.placements;
            state.flashId = flashId || null;
            render();
        });
    }

    function attrLiteral<T>(p: BHPlacement, key: string, fallback: T): T {
        const attrs = (p.config && p.config.attrs) || {};
        const v = attrs[key];
        if (v && typeof v === 'object' && 'literal' in v) return (v as { literal: T }).literal;
        if (v && typeof v === 'object' && 'bind' in v) return fallback; // bound attrs aren't editable from this board
        return v !== undefined && v !== null ? (v as T) : fallback;
    }

    function setAttrLiteral<T>(p: BHPlacement, key: string, value: T): void {
        p.config = p.config || {};
        p.config.attrs = p.config.attrs || {};
        p.config.attrs[key] = { literal: value };
    }

    /**
     * Reads the live DOM (every column's card list, in on-screen order)
     * back into state.placements — the one place a drag result becomes
     * the new source of truth. Each card's column attr is set from
     * whichever column list it's physically in now; overall array order
     * follows cfg.columns order, then on-screen order within each
     * column, matching saveSlot()'s existing "position reconstructed
     * server-side from array order" contract exactly.
     */
    function reorderFromDom(): void {
        const columns = cfg.columns || [];
        // Last column = "done", same one-directional convention
        // BHCRM_Subtasks::handle_reorder() uses server-side for the
        // nested boards — AJ's own ask: "should update to done once
        // the task has been dragged to done." Dropping OUT of the done
        // column deliberately does NOT un-check it, so reorganizing
        // columns can never silently erase a completion someone set
        // on purpose.
        const doneColumn = columns[columns.length - 1];
        const next: BHPlacement[] = [];
        columns.forEach((colName) => {
            const list = root!.querySelector('.bhcrm-kanban-column[data-column="' + CSS.escape(colName) + '"] .bhcrm-kanban-column-cards');
            if (!list) return;
            Array.prototype.forEach.call(list.children, (cardEl: HTMLElement) => {
                const id = Number(cardEl.getAttribute('data-placement-id'));
                const p = state.placements.find((x) => x.id === id);
                if (!p) return;
                setAttrLiteral(p, 'column', colName);
                if (colName === doneColumn) setAttrLiteral(p, 'done', true);
                next.push(p);
            });
        });
        state.placements = next;
    }

    function render(): void {
        root!.removeAttribute('data-loading');
        state.sortables.forEach((s) => { s.destroy(); });
        state.sortables = [];
        root!.innerHTML = '';

        const columns = cfg.columns || [];
        const grid = el('div', 'bhcrm-kanban-grid');

        columns.forEach((colName) => {
            const col = el('div', 'bhcrm-kanban-column');
            col.setAttribute('data-column', colName);

            const header = el('div', 'bhcrm-kanban-column-header', colName);
            const cardsInCol = state.placements.filter((p) => attrLiteral(p, 'column', '') === colName);
            header.appendChild(el('span', 'bhcrm-kanban-column-count', ' (' + cardsInCol.length + ')'));
            col.appendChild(header);

            const list = el('div', 'bhcrm-kanban-column-cards');
            cardsInCol.forEach((p) => { list.appendChild(renderCard(p)); });

            col.appendChild(list);
            col.appendChild(renderAddCardForm(colName));
            grid.appendChild(col);
        });

        root!.appendChild(grid);

        // One Sortable instance per column list, all sharing a group
        // name so a card can be dragged from one column into another —
        // onEnd (fires once, after the DOM already reflects the drop)
        // rebuilds state.placements from that live DOM and re-saves the
        // whole slot, same as every other edit in this file.
        if (win.Sortable) {
            root!.querySelectorAll('.bhcrm-kanban-column-cards').forEach((list) => {
                // @ts-expect-error subtasks.ts's shared SortableApi types create()'s return as unknown; it really is a Sortable instance with .destroy()
                state.sortables.push(win.Sortable!.create(list, {
                    group: 'bhcrm-kanban',
                    animation: 150,
                    ghostClass: 'is-drag-ghost',
                    handle: '.bhcrm-kanban-card-drag-handle',
                    // SortableJS's own recommended setting for more
                    // consistent behavior — without this it defaults to
                    // the native HTML5 draggable API, which has real,
                    // well-documented cross-browser/touch-device
                    // inconsistencies (part of what this whole swap was
                    // meant to fix). forceFallback makes Sortable
                    // simulate the drag itself via plain mouse/pointer
                    // events instead of relying on the browser's own
                    // native drag gesture recognition.
                    forceFallback: true,
                    // Belt-and-suspenders alongside the explicit handle
                    // above — filter stops a drag from even starting on
                    // a card's own interactive controls (title/notes/
                    // checkbox/buttons), preventOnFilter:false so the
                    // normal click/focus still reaches them.
                    filter: 'input, textarea, button, a',
                    preventOnFilter: false,
                    onEnd: function () {
                        reorderFromDom();
                        saveSlot().catch(reportSaveError);
                    },
                }));
            });
        }
    }

    function renderCard(p: BHPlacement): HTMLDivElement {
        const title = attrLiteral(p, 'title', 'Untitled');
        const notes = attrLiteral(p, 'notes', '');
        const done = !!attrLiteral(p, 'done', false);

        const card = el('div', 'bhcrm-kanban-card' + (done ? ' is-done' : ''));
        // data-placement-id is what reorderFromDom() reads back after a
        // drop; the drag itself only starts from the dedicated handle
        // below (Sortable's `handle` option), not the card body, so
        // clicking into the title/notes/checkbox/buttons never fights
        // with drag detection.
        card.setAttribute('data-placement-id', String(p.id));
        if (state.flashId === p.id) {
            state.flashId = null;
            card.classList.add('is-saved');
            setTimeout(() => { card.classList.remove('is-saved'); }, 900);
        }
        card.appendChild(el('div', 'bhcrm-kanban-card-drag-handle', '⋮⋮'));

        const titleRow = el('div', 'bhcrm-kanban-card-title-row');
        const doneBox = document.createElement('input');
        doneBox.type = 'checkbox';
        doneBox.checked = done;
        doneBox.addEventListener('change', () => {
            setAttrLiteral(p, 'done', doneBox.checked);
            saveSlot(p.id).catch(reportSaveError);
        });
        titleRow.appendChild(doneBox);

        const titleInput = document.createElement('input');
        titleInput.type = 'text';
        titleInput.className = 'bhcrm-kanban-card-title-input';
        titleInput.value = title;
        titleInput.addEventListener('change', () => {
            setAttrLiteral(p, 'title', titleInput.value);
            saveSlot(p.id).catch(reportSaveError);
        });
        titleRow.appendChild(titleInput);
        card.appendChild(titleRow);

        // Phase C stall analytics — "hasn't moved in N days," surfaced
        // directly on the card itself (AJ's own ask: a visible flag
        // BEFORE it's obviously a problem, not a separate report page
        // nobody remembers to open).
        const daysStalled = state.stalled[p.id];
        if (daysStalled) {
            const badge = el('span', 'bhy-badge bhy-badge-warning bhcrm-kanban-stalled-badge', '⚠ ' + daysStalled + 'd stalled');
            badge.title = 'This card has been in "' + attrLiteral(p, 'column', '') + '" for ' + daysStalled + ' days.';
            card.appendChild(badge);
        }

        // A card's own recursive sub-task rollup — AJ's own ask, "each
        // card should track the total progress of everything under
        // it... display it back up on the card itself... add up for
        // every grandchild." Same visual treatment
        // (BHCRM_Subtasks::render_progress_bar()'s mini variant) as
        // the nested sub-task board itself, so a top-level card and a
        // deeply-nested one read the same way.
        const rollup = state.rollups[p.id];
        if (rollup && rollup[1] > 0) {
            const rDone = rollup[0], rTotal = rollup[1];
            const pct = Math.round((rDone / rTotal) * 100);
            const bar = el('div', 'bhcrm-progress-bar bhcrm-progress-bar-mini');
            const track = el('div', 'bhcrm-progress-bar-track');
            const fill = el('div', 'bhcrm-progress-bar-fill' + (pct >= 100 ? ' is-complete' : ''));
            fill.style.width = pct + '%';
            track.appendChild(fill);
            bar.appendChild(track);
            bar.appendChild(el('span', 'bhcrm-progress-bar-label', rDone + '/' + rTotal + ' · ' + pct + '%'));
            card.appendChild(bar);
        }

        const notesArea = document.createElement('textarea');
        notesArea.className = 'bhcrm-kanban-card-notes';
        notesArea.rows = 2;
        notesArea.value = notes;
        notesArea.placeholder = 'Notes…';
        notesArea.addEventListener('change', () => {
            setAttrLiteral(p, 'notes', notesArea.value);
            saveSlot(p.id).catch(reportSaveError);
        });
        card.appendChild(notesArea);

        const actions = el('div', 'bhcrm-kanban-card-actions');
        // QA change: this used to open Content Studio (a generic
        // WordPress block-editor canvas, no board/column concept, no
        // rollup display of its own) in a new tab. Replaces it
        // entirely with BHCRM_Subtasks — a real nested tracking view
        // in-page, same tab, with breadcrumb navigation and a progress
        // rollup at every level. Same-tab (not target=_blank) since
        // it's now a real part of this same admin screen, not an
        // unrelated external tool.
        const subtaskUrl = new URL(window.location.href);
        subtaskUrl.searchParams.set('card_id', String(p.id));
        subtaskUrl.searchParams.delete('subtask_path');
        const subtaskLink = document.createElement('a');
        subtaskLink.href = subtaskUrl.toString();
        subtaskLink.className = 'button button-small';
        subtaskLink.textContent = 'View sub-tasks';
        actions.appendChild(subtaskLink);

        // Arm/disarm instead of a native confirm() — banned elsewhere in
        // this ecosystem for the same reason (blocking dialog, worse UX,
        // a known hazard for automated QA tooling). First click arms it
        // (relabeled, distinct color, 3s window); a second click while
        // armed actually deletes. Any other interaction on the card
        // (typing, checking done, dragging) disarms it via blur/dragstart
        // below so a stray second click days later can't misfire.
        const delBtn = el('button', 'button button-small bhcrm-delete-btn', 'Delete');
        let armed = false;
        let armTimer: ReturnType<typeof setTimeout> | null = null;
        function disarm() {
            armed = false;
            if (armTimer) clearTimeout(armTimer);
            delBtn.classList.remove('is-armed');
            delBtn.textContent = 'Delete';
        }
        delBtn.addEventListener('click', () => {
            if (!armed) {
                armed = true;
                delBtn.classList.add('is-armed');
                delBtn.textContent = 'Really delete?';
                armTimer = setTimeout(disarm, 3000);
                return;
            }
            disarm();
            delBtn.disabled = true;
            api(`placements/${p.id}`, { method: 'DELETE' }).then(() => {
                state.placements = state.placements.filter((x) => x.id !== p.id);
                render();
            }).catch((err: Error) => {
                delBtn.disabled = false;
                reportSaveError(err, 'delete');
            });
        });
        card.addEventListener('pointerdown', (e) => { if (e.target !== delBtn) disarm(); }, true);
        actions.appendChild(delBtn);
        card.appendChild(actions);

        return card;
    }

    function renderAddCardForm(colName: string): HTMLDivElement {
        const wrap = el('div', 'bhcrm-kanban-add-card');
        const input = document.createElement('input');
        input.type = 'text';
        input.placeholder = '+ Add card…';
        wrap.appendChild(input);

        let adding = false;

        function addCard() {
            // Guarded against a fast double-Enter or Enter-then-click —
            // state.placements is mutated synchronously (optimistic UI,
            // before saveSlot() confirms), so without this a second
            // addCard() firing mid-save could push a near-duplicate card.
            if (adding) return;
            const title = input.value.trim();
            if (!title) return;
            adding = true;
            input.disabled = true;
            btn.disabled = true;
            state.placements.push({
                id: 0,
                element_type: 'bh/sticky-card',
                content_context_id: 0,
                config: { attrs: {
                    title: { literal: title },
                    notes: { literal: '' },
                    done: { literal: false },
                    column: { literal: colName },
                } },
            });
            input.value = '';
            saveSlot().catch(reportSaveError).finally(() => {
                adding = false;
                input.disabled = false;
                btn.disabled = false;
            });
        }

        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') addCard(); });
        const btn = el('button', 'button button-small', 'Add');
        btn.addEventListener('click', addCard);
        wrap.appendChild(btn);
        return wrap;
    }

    load();
})();
