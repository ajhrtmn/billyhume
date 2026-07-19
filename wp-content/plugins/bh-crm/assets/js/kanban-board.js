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
 */
(function () {
    'use strict';

    var cfg = window.bhcrmKanbanConfig || {};
    var root = document.getElementById('bhcrm-kanban-board');
    if (!root || !cfg.restUrl) return;

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

    var state = {
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
        flashId: null, // set by saveSlot(), consumed once by renderCard() — see saveSlot()'s own docblock
    };

    function el(tag, className, text) {
        var e = document.createElement(tag);
        if (className) e.className = className;
        if (text !== undefined) e.textContent = text;
        return e;
    }

    function placementsPath() {
        return 'placements/' + encodeURIComponent(cfg.surface) + '/' + encodeURIComponent(cfg.projectId);
    }

    function loadRollups() {
        if (!cfg.rollupsUrl) return Promise.resolve();
        return fetch(cfg.rollupsUrl + '?project_id=' + encodeURIComponent(cfg.projectId), {
            headers: { 'X-WP-Nonce': cfg.nonce },
            credentials: 'same-origin',
        }).then(function (res) { return res.ok ? res.json() : {}; })
            .then(function (data) { state.rollups = data || {}; })
            .catch(function () { state.rollups = {}; });
    }

    function load() {
        root.setAttribute('data-loading', '1');
        Promise.all([api(placementsPath()), loadRollups()]).then(function (results) {
            var grouped = results[0];
            state.placements = (grouped && grouped.board) ? grouped.board : [];
            render();
        }).catch(function (err) {
            // Previously surfaced the raw exception message straight to
            // the user (e.g. a fetch/parse error string) — inconsistent
            // with the friendly copy this ecosystem uses everywhere
            // else. Real detail still goes to the console for whoever's
            // actually debugging it.
            console.error('bh-crm kanban board load failed:', err);
            root.innerHTML = '';
            var p = el('p', 'description', 'Could not load the board — please try again.');
            root.appendChild(p);
        });
    }

    // BHCoreToast (own-ur-shit core, loaded on every admin screen — see
    // class-toast.php's enqueue_assets(), hooked to admin_enqueue_scripts
    // unconditionally) replaces every alert() that used to run this
    // board's error path silently-broken-into-a-blocking-dialog. Same
    // typeof guard every other call site in this ecosystem uses in case
    // toast.js somehow isn't loaded.
    function reportSaveError(err, action) {
        var msg = 'Failed to ' + (action || 'save') + ': ' + err.message;
        if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(msg, 'error'); } else { alert(msg); }
    }

    /** Full-slot upsert — mirrors element-builder.js's "Save slot" exactly: send every current placement in the desired order, 'position' is reconstructed server-side from array order. $flashId, when given, is the ONE card render() should visually flash as "just saved" — render() wipes and rebuilds every card element on every call, so a reference to the pre-save DOM node would be stale; tracking the id instead lets renderCard() re-attach the flash to whichever fresh element ends up representing that same card. */
    function saveSlot(flashId) {
        var body = {
            slot: 'board',
            placements: state.placements.map(function (p) {
                return {
                    id: p.id,
                    element_type: p.element_type,
                    config: p.config,
                    content_context_id: p.content_context_id,
                    enabled: true,
                };
            }),
        };
        return api(placementsPath(), { method: 'POST', body: body }).then(function (res) {
            state.placements = res.placements || state.placements;
            state.flashId = flashId || null;
            render();
        });
    }

    function attrLiteral(p, key, fallback) {
        var attrs = (p.config && p.config.attrs) || {};
        var v = attrs[key];
        if (v && typeof v === 'object' && 'literal' in v) return v.literal;
        if (v && typeof v === 'object' && 'bind' in v) return fallback; // bound attrs aren't editable from this board
        return v !== undefined && v !== null ? v : fallback;
    }

    function setAttrLiteral(p, key, value) {
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
    function reorderFromDom() {
        var columns = cfg.columns || [];
        // Last column = "done", same one-directional convention
        // BHCRM_Subtasks::handle_reorder() uses server-side for the
        // nested boards — AJ's own ask: "should update to done once
        // the task has been dragged to done." Dropping OUT of the done
        // column deliberately does NOT un-check it, so reorganizing
        // columns can never silently erase a completion someone set
        // on purpose.
        var doneColumn = columns[columns.length - 1];
        var next = [];
        columns.forEach(function (colName) {
            var list = root.querySelector('.bhcrm-kanban-column[data-column="' + CSS.escape(colName) + '"] .bhcrm-kanban-column-cards');
            if (!list) return;
            Array.prototype.forEach.call(list.children, function (cardEl) {
                var id = Number(cardEl.getAttribute('data-placement-id'));
                var p = state.placements.find(function (x) { return x.id === id; });
                if (!p) return;
                setAttrLiteral(p, 'column', colName);
                if (colName === doneColumn) setAttrLiteral(p, 'done', true);
                next.push(p);
            });
        });
        state.placements = next;
    }

    function render() {
        root.removeAttribute('data-loading');
        state.sortables.forEach(function (s) { s.destroy(); });
        state.sortables = [];
        root.innerHTML = '';

        var columns = cfg.columns || [];
        var grid = el('div', 'bhcrm-kanban-grid');

        columns.forEach(function (colName) {
            var col = el('div', 'bhcrm-kanban-column');
            col.setAttribute('data-column', colName);

            var header = el('div', 'bhcrm-kanban-column-header', colName);
            var cardsInCol = state.placements.filter(function (p) { return attrLiteral(p, 'column', '') === colName; });
            header.appendChild(el('span', 'bhcrm-kanban-column-count', ' (' + cardsInCol.length + ')'));
            col.appendChild(header);

            var list = el('div', 'bhcrm-kanban-column-cards');
            cardsInCol.forEach(function (p) { list.appendChild(renderCard(p)); });

            col.appendChild(list);
            col.appendChild(renderAddCardForm(colName));
            grid.appendChild(col);
        });

        root.appendChild(grid);

        // One Sortable instance per column list, all sharing a group
        // name so a card can be dragged from one column into another —
        // onEnd (fires once, after the DOM already reflects the drop)
        // rebuilds state.placements from that live DOM and re-saves the
        // whole slot, same as every other edit in this file.
        if (window.Sortable) {
            root.querySelectorAll('.bhcrm-kanban-column-cards').forEach(function (list) {
                state.sortables.push(window.Sortable.create(list, {
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

    function renderCard(p) {
        var title = attrLiteral(p, 'title', 'Untitled');
        var notes = attrLiteral(p, 'notes', '');
        var done = !!attrLiteral(p, 'done', false);

        var card = el('div', 'bhcrm-kanban-card' + (done ? ' is-done' : ''));
        // data-placement-id is what reorderFromDom() reads back after a
        // drop; the drag itself only starts from the dedicated handle
        // below (Sortable's `handle` option), not the card body, so
        // clicking into the title/notes/checkbox/buttons never fights
        // with drag detection.
        card.setAttribute('data-placement-id', String(p.id));
        if (state.flashId === p.id) {
            state.flashId = null;
            card.classList.add('is-saved');
            setTimeout(function () { card.classList.remove('is-saved'); }, 900);
        }
        card.appendChild(el('div', 'bhcrm-kanban-card-drag-handle', '⋮⋮'));

        var titleRow = el('div', 'bhcrm-kanban-card-title-row');
        var doneBox = document.createElement('input');
        doneBox.type = 'checkbox';
        doneBox.checked = done;
        doneBox.addEventListener('change', function () {
            setAttrLiteral(p, 'done', doneBox.checked);
            saveSlot(p.id).catch(reportSaveError);
        });
        titleRow.appendChild(doneBox);

        var titleInput = document.createElement('input');
        titleInput.type = 'text';
        titleInput.className = 'bhcrm-kanban-card-title-input';
        titleInput.value = title;
        titleInput.addEventListener('change', function () {
            setAttrLiteral(p, 'title', titleInput.value);
            saveSlot(p.id).catch(reportSaveError);
        });
        titleRow.appendChild(titleInput);
        card.appendChild(titleRow);

        // A card's own recursive sub-task rollup — AJ's own ask, "each
        // card should track the total progress of everything under
        // it... display it back up on the card itself... add up for
        // every grandchild." Same visual treatment
        // (BHCRM_Subtasks::render_progress_bar()'s mini variant) as
        // the nested sub-task board itself, so a top-level card and a
        // deeply-nested one read the same way.
        var rollup = state.rollups[p.id];
        if (rollup && rollup[1] > 0) {
            var rDone = rollup[0], rTotal = rollup[1];
            var pct = Math.round((rDone / rTotal) * 100);
            var bar = el('div', 'bhcrm-progress-bar bhcrm-progress-bar-mini');
            var track = el('div', 'bhcrm-progress-bar-track');
            var fill = el('div', 'bhcrm-progress-bar-fill' + (pct >= 100 ? ' is-complete' : ''));
            fill.style.width = pct + '%';
            track.appendChild(fill);
            bar.appendChild(track);
            bar.appendChild(el('span', 'bhcrm-progress-bar-label', rDone + '/' + rTotal + ' · ' + pct + '%'));
            card.appendChild(bar);
        }

        var notesArea = document.createElement('textarea');
        notesArea.className = 'bhcrm-kanban-card-notes';
        notesArea.rows = 2;
        notesArea.value = notes;
        notesArea.placeholder = 'Notes…';
        notesArea.addEventListener('change', function () {
            setAttrLiteral(p, 'notes', notesArea.value);
            saveSlot(p.id).catch(reportSaveError);
        });
        card.appendChild(notesArea);

        var actions = el('div', 'bhcrm-kanban-card-actions');
        // QA change: this used to open Content Studio (a generic
        // WordPress block-editor canvas, no board/column concept, no
        // rollup display of its own) in a new tab. Replaces it
        // entirely with BHCRM_Subtasks — a real nested tracking view
        // in-page, same tab, with breadcrumb navigation and a progress
        // rollup at every level. Same-tab (not target=_blank) since
        // it's now a real part of this same admin screen, not an
        // unrelated external tool.
        var subtaskUrl = new URL(window.location.href);
        subtaskUrl.searchParams.set('card_id', p.id);
        subtaskUrl.searchParams.delete('subtask_path');
        var subtaskLink = document.createElement('a');
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
        var delBtn = el('button', 'button button-small bhcrm-delete-btn', 'Delete');
        var armed = false, armTimer = null;
        function disarm() {
            armed = false;
            clearTimeout(armTimer);
            delBtn.classList.remove('is-armed');
            delBtn.textContent = 'Delete';
        }
        delBtn.addEventListener('click', function () {
            if (!armed) {
                armed = true;
                delBtn.classList.add('is-armed');
                delBtn.textContent = 'Really delete?';
                armTimer = setTimeout(disarm, 3000);
                return;
            }
            disarm();
            delBtn.disabled = true;
            api('placements/' + p.id, { method: 'DELETE' }).then(function () {
                state.placements = state.placements.filter(function (x) { return x.id !== p.id; });
                render();
            }).catch(function (err) {
                delBtn.disabled = false;
                reportSaveError(err, 'delete');
            });
        });
        card.addEventListener('pointerdown', function (e) { if (e.target !== delBtn) disarm(); }, true);
        actions.appendChild(delBtn);
        card.appendChild(actions);

        return card;
    }

    function renderAddCardForm(colName) {
        var wrap = el('div', 'bhcrm-kanban-add-card');
        var input = document.createElement('input');
        input.type = 'text';
        input.placeholder = '+ Add card…';
        wrap.appendChild(input);

        var adding = false;

        function addCard() {
            // Guarded against a fast double-Enter or Enter-then-click —
            // state.placements is mutated synchronously (optimistic UI,
            // before saveSlot() confirms), so without this a second
            // addCard() firing mid-save could push a near-duplicate card.
            if (adding) return;
            var title = input.value.trim();
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
            saveSlot().catch(reportSaveError).finally(function () {
                adding = false;
                input.disabled = false;
                btn.disabled = false;
            });
        }

        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') addCard(); });
        var btn = el('button', 'button button-small', 'Add');
        btn.addEventListener('click', addCard);
        wrap.appendChild(btn);
        return wrap;
    }

    load();
})();
