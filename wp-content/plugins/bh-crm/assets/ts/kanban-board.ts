/**
 * Project kanban board, mounted into #bhcrm-kanban-board (BHCRM_Projects::render_board()).
 *
 * A card is a bh/sticky-card placement; all reads/writes go through core's
 * ous/v1/elements/placements routes. Every edit (including drag) re-saves the
 * whole 'board' slot in order; a card's column is its config.attrs.column literal.
 * Drag-and-drop uses vendored SortableJS, one instance per column list.
 */

interface SortableInstance {
    destroy(): void;
}

// WHY: SortableApi and the Sortable/BHCoreToast globals are declared in subtasks.ts;
// module "none" shares global scope, so redeclaring them here would conflict.

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
                    // A bare 401/403 almost always means a stale session or nonce.
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
        sortables: [], // one per column list; destroyed before each re-render since render() wipes the DOM
        rollups: {}, // {placementId: [done, total]} recursive sub-task rollup, from bh-crm/v1/rollups
        stalled: {}, // {placementId: daysSinceLastMove}, only cards past STALL_DAYS
        flashId: null, // set by saveSlot(), consumed once by renderCard()
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
            console.error('bh-crm kanban board load failed:', err);
            root!.innerHTML = '';
            const p = el('p', 'description', 'Could not load the board — please try again.');
            root!.appendChild(p);
        });
    }

    // BHCoreToast is loaded on every admin screen by core; alert() is only a fallback.
    function reportSaveError(err: Error, action?: string): void {
        const msg = 'Failed to ' + (action || 'save') + ': ' + err.message;
        if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(msg, 'error'); } else { alert(msg); }
    }

/**
     * Full-slot upsert; 'position' is rebuilt server-side from array order.
     * flashId is tracked by id because render() rebuilds every card element.
     */
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

    /** Reads on-screen card order and columns back into state.placements after a drag. */
    function reorderFromDom(): void {
        const columns = cfg.columns || [];
        // WHY: one-directional — dropping into the last column marks done; dragging out never un-marks.
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

        // A shared group lets cards move between columns.
        if (win.Sortable) {
            root!.querySelectorAll('.bhcrm-kanban-column-cards').forEach((list) => {
                // @ts-expect-error subtasks.ts's shared SortableApi types create()'s return as unknown; it really is a Sortable instance with .destroy()
                state.sortables.push(win.Sortable!.create(list, {
                    group: 'bhcrm-kanban',
                    animation: 150,
                    ghostClass: 'is-drag-ghost',
                    handle: '.bhcrm-kanban-card-drag-handle',
                    // Simulated drag avoids native HTML5 DnD's cross-browser/touch inconsistencies.
                    forceFallback: true,
                    // Never start a drag from a control; preventOnFilter:false lets clicks still reach it.
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
        // Read back by reorderFromDom() after a drop.
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

        const daysStalled = state.stalled[p.id];
        if (daysStalled) {
            const badge = el('span', 'bhy-badge bhy-badge-warning bhcrm-kanban-stalled-badge', '⚠ ' + daysStalled + 'd stalled');
            badge.title = 'This card has been in "' + attrLiteral(p, 'column', '') + '" for ' + daysStalled + ' days.';
            card.appendChild(badge);
        }

        // Same markup as BHCRM_Subtasks::render_progress_bar()'s mini variant.
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
        // Opens the nested sub-task board (BHCRM_Subtasks) in the same tab.
        const subtaskUrl = new URL(window.location.href);
        subtaskUrl.searchParams.set('card_id', String(p.id));
        subtaskUrl.searchParams.delete('subtask_path');
        const subtaskLink = document.createElement('a');
        subtaskLink.href = subtaskUrl.toString();
        subtaskLink.className = 'button button-small';
        subtaskLink.textContent = 'View sub-tasks';
        actions.appendChild(subtaskLink);

        // Two-click delete instead of confirm(): first click arms for 3s, any other pointerdown on the card disarms.
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
            // WHY: placements are pushed optimistically, so a double-Enter mid-save would duplicate the card.
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
