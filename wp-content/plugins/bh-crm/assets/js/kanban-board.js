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
 * Drag-and-drop uses plain HTML5 DnD (dragstart/dragover/drop), not a
 * library — reasoned through and internally consistent, but genuinely
 * UNTESTED: no live browser is available in this environment (same
 * caveat element-builder.js's own docblock gives for why ITS reorder UI
 * uses up/down buttons instead of drag/drop — this file takes the
 * drag/drop risk anyway because a kanban board without it would defeat
 * the point of the visual layer; please smoke-test drag between columns
 * and within a column before relying on this).
 *
 * NOT runtime-verified beyond that: no live PHP/MySQL/WordPress/REST/
 * browser execution is available in this sandbox at all. Reasoned
 * through against BH_Element's actual, already-read REST response/
 * request shapes.
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
                    throw new Error((err && err.message) || ('HTTP ' + res.status));
                });
            }
            return res.json();
        });
    }

    var state = {
        placements: [], // raw rows from GET .../placements/{surface}/{context}, slot 'board' only
        dragId: null,
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

    function load() {
        root.setAttribute('data-loading', '1');
        api(placementsPath()).then(function (grouped) {
            state.placements = (grouped && grouped.board) ? grouped.board : [];
            render();
        }).catch(function (err) {
            root.innerHTML = '';
            var p = el('p', 'description', 'Failed to load board: ' + err.message);
            root.appendChild(p);
        });
    }

    /** Full-slot upsert — mirrors element-builder.js's "Save slot" exactly: send every current placement in the desired order, 'position' is reconstructed server-side from array order. */
    function saveSlot() {
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

    function render() {
        root.removeAttribute('data-loading');
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
            list.addEventListener('dragover', function (e) { e.preventDefault(); list.classList.add('is-drag-over'); });
            list.addEventListener('dragleave', function () { list.classList.remove('is-drag-over'); });
            list.addEventListener('drop', function (e) {
                e.preventDefault();
                list.classList.remove('is-drag-over');
                if (state.dragId == null) return;
                var p = state.placements.find(function (x) { return x.id === state.dragId; });
                if (!p) return;
                setAttrLiteral(p, 'column', colName);
                // Move it to the end of this column's run within the flat
                // array — simplest correct reorder: pull it out, push it
                // after the last card currently in this column.
                state.placements = state.placements.filter(function (x) { return x.id !== state.dragId; });
                var lastIndexInCol = -1;
                state.placements.forEach(function (x, i) { if (attrLiteral(x, 'column', '') === colName) lastIndexInCol = i; });
                state.placements.splice(lastIndexInCol + 1, 0, p);
                saveSlot().catch(function (err) { alert('Failed to save: ' + err.message); });
            });

            cardsInCol.forEach(function (p) { list.appendChild(renderCard(p)); });

            col.appendChild(list);
            col.appendChild(renderAddCardForm(colName));
            grid.appendChild(col);
        });

        root.appendChild(grid);
    }

    function renderCard(p) {
        var title = attrLiteral(p, 'title', 'Untitled');
        var notes = attrLiteral(p, 'notes', '');
        var done = !!attrLiteral(p, 'done', false);

        var card = el('div', 'bhcrm-kanban-card' + (done ? ' is-done' : ''));
        card.setAttribute('draggable', 'true');
        card.addEventListener('dragstart', function () { state.dragId = p.id; card.classList.add('is-dragging'); });
        card.addEventListener('dragend', function () { card.classList.remove('is-dragging'); });

        var titleRow = el('div', 'bhcrm-kanban-card-title-row');
        var doneBox = document.createElement('input');
        doneBox.type = 'checkbox';
        doneBox.checked = done;
        doneBox.addEventListener('change', function () {
            setAttrLiteral(p, 'done', doneBox.checked);
            saveSlot().catch(function (err) { alert('Failed to save: ' + err.message); });
        });
        titleRow.appendChild(doneBox);

        var titleInput = document.createElement('input');
        titleInput.type = 'text';
        titleInput.className = 'bhcrm-kanban-card-title-input';
        titleInput.value = title;
        titleInput.addEventListener('change', function () {
            setAttrLiteral(p, 'title', titleInput.value);
            saveSlot().catch(function (err) { alert('Failed to save: ' + err.message); });
        });
        titleRow.appendChild(titleInput);
        card.appendChild(titleRow);

        var notesArea = document.createElement('textarea');
        notesArea.className = 'bhcrm-kanban-card-notes';
        notesArea.rows = 2;
        notesArea.value = notes;
        notesArea.placeholder = 'Notes…';
        notesArea.addEventListener('change', function () {
            setAttrLiteral(p, 'notes', notesArea.value);
            saveSlot().catch(function (err) { alert('Failed to save: ' + err.message); });
        });
        card.appendChild(notesArea);

        var actions = el('div', 'bhcrm-kanban-card-actions');
        if (cfg.studioUrl) {
            var studioLink = document.createElement('a');
            studioLink.href = cfg.studioUrl + '&context_type=bh_element&context_id=' + encodeURIComponent(p.content_context_id || p.id);
            studioLink.target = '_blank';
            studioLink.rel = 'noopener';
            studioLink.className = 'button button-small';
            studioLink.textContent = 'Edit sub-tasks';
            actions.appendChild(studioLink);
        }

        var delBtn = el('button', 'button button-small', 'Delete');
        delBtn.addEventListener('click', function () {
            if (!confirm('Delete "' + title + '"? This also removes its sub-tasks.')) return;
            api('placements/' + p.id, { method: 'DELETE' }).then(function () {
                state.placements = state.placements.filter(function (x) { return x.id !== p.id; });
                render();
            }).catch(function (err) { alert('Failed to delete: ' + err.message); });
        });
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

        function addCard() {
            var title = input.value.trim();
            if (!title) return;
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
            saveSlot().catch(function (err) { alert('Failed to save: ' + err.message); });
        }

        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') addCard(); });
        var btn = el('button', 'button button-small', 'Add');
        btn.addEventListener('click', addCard);
        wrap.appendChild(btn);
        return wrap;
    }

    load();
})();
