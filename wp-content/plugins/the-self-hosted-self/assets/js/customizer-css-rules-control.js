"use strict";
(function () {
    const wpApi = window.wp;
    if (!wpApi || !wpApi.customize)
        return;
    const customizeApi = wpApi.customize;
    const registry = window.bhyCssPropertyRegistry || {};
    const stateOptions = window.bhyCssStateOptions || {};
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    function formatPropertyValue(property, rawValue) {
        const def = registry[property];
        if (!def)
            return '';
        if (def.type === 'color')
            return rawValue || String(def.default || '#000000');
        if (def.type === 'keyword') {
            const options = def.options || {};
            if (Object.prototype.hasOwnProperty.call(options, rawValue))
                return rawValue;
            return String(def.default || Object.keys(options)[0] || '');
        }
        if (def.type === 'font') {
            const bare = (rawValue || '').replace(/^"?([^",]+)"?.*$/, '$1').trim() || String(def.default || 'Inter');
            const fallback = (def.fallback || 'sans-serif').toLowerCase().replace(/[^a-z-]/g, '') || 'sans-serif';
            return '"' + bare.replace(/"/g, '') + '", ' + fallback;
        }
        // 'size'
        const parsed = parseFloat(rawValue);
        const num = rawValue !== '' && !isNaN(parsed) ? parsed : Number(def.default || 0);
        return num + (def.unit || '');
    }
    function buildValueControlHtml(property, storedValue) {
        var _a, _b;
        const def = registry[property];
        if (!def)
            return '';
        if (def.type === 'color') {
            return '<input type="color" class="bhy-cz-value" value="' + escapeHtml(storedValue || String(def.default || '#000000')) + '">';
        }
        if (def.type === 'keyword') {
            let html = '<select class="bhy-cz-value">';
            Object.keys(def.options || {}).forEach(function (optVal) {
                const sel = optVal === storedValue ? ' selected' : '';
                html += '<option value="' + escapeHtml(optVal) + '"' + sel + '>' + escapeHtml(def.options[optVal]) + '</option>';
            });
            return html + '</select>';
        }
        if (def.type === 'font') {
            const display = (storedValue || '').replace(/^"?([^",]+)"?.*$/, '$1');
            return '<input type="text" class="bhy-cz-value" value="' + escapeHtml(display) + '" placeholder="' + escapeHtml(String(def.default || 'Inter')) + '">';
        }
        const num = storedValue ? parseFloat(storedValue) : Number(def.default || 0);
        return '<input type="number" class="bhy-cz-value" step="' + (def.step || 1) + '" min="' + ((_a = def.min) !== null && _a !== void 0 ? _a : '') + '" max="' + ((_b = def.max) !== null && _b !== void 0 ? _b : '') + '" value="' + num + '" style="width:100%;"> <span class="description">' + escapeHtml(def.unit || '') + '</span>';
    }
    function buildPropRow(property, storedValue) {
        const row = document.createElement('div');
        row.className = 'bhy-css-prop-row';
        let options = '<option value="">Choose a property…</option>';
        Object.keys(registry).forEach(function (key) {
            const sel = key === property ? ' selected' : '';
            options += '<option value="' + escapeHtml(key) + '"' + sel + '>' + escapeHtml(registry[key].label) + '</option>';
        });
        row.innerHTML = '<select class="bhy-prop-select">' + options + '</select>'
            + '<span class="bhy-prop-control-slot">' + (property ? buildValueControlHtml(property, storedValue) : '') + '</span>'
            + '<button type="button" class="button-link-delete bhy-remove-prop">&times;</button>';
        return row;
    }
    function buildRuleEl(rule) {
        const el = document.createElement('div');
        el.className = 'bhy-css-rule';
        let stateHtml = '';
        Object.keys(stateOptions).forEach(function (val) {
            const sel = val === (rule.state || '') ? ' selected' : '';
            stateHtml += '<option value="' + escapeHtml(val) + '"' + sel + '>' + escapeHtml(stateOptions[val]) + '</option>';
        });
        el.innerHTML = '<div class="bhy-css-rule-header">'
            + '<input type="text" class="bhy-cz-selector" value="' + escapeHtml(rule.selector || '') + '" placeholder=".some-selector">'
            + '<select class="bhy-cz-state">' + stateHtml + '</select>'
            + '<button type="button" class="button-link-delete bhy-remove-rule">Remove rule</button>'
            + '</div>'
            + '<div class="bhy-css-rule-props"></div>'
            + '<button type="button" class="button bhy-add-prop">+ Add property</button>';
        const propsWrap = el.querySelector('.bhy-css-rule-props');
        const decls = rule.declarations || {};
        const keys = Object.keys(decls);
        keys.forEach(function (prop) { propsWrap.appendChild(buildPropRow(prop, decls[prop])); });
        if (!keys.length)
            propsWrap.appendChild(buildPropRow('', ''));
        return el;
    }
    function renderRules(container, rules) {
        container.innerHTML = '';
        (rules || []).forEach(function (rule) { container.appendChild(buildRuleEl(rule)); });
    }
    function readRules(container) {
        const rules = [];
        // WHY: always include every rule row, complete or not. Dropping empty
        // ones meant an external change re-rendered from a truncated array and
        // wiped a rule still being typed. Empty rules emit no CSS and are dropped on save.
        container.querySelectorAll('.bhy-css-rule').forEach(function (ruleEl) {
            const selectorInput = ruleEl.querySelector('.bhy-cz-selector');
            const selector = selectorInput.value.trim();
            const state = ruleEl.querySelector('.bhy-cz-state').value;
            const declarations = {};
            ruleEl.querySelectorAll('.bhy-css-prop-row').forEach(function (row) {
                const property = row.querySelector('.bhy-prop-select').value;
                if (!property)
                    return;
                const valueEl = row.querySelector('.bhy-cz-value');
                const raw = valueEl ? valueEl.value : '';
                declarations[property] = formatPropertyValue(property, raw);
            });
            rules.push({ selector: selector, state: state, declarations: declarations });
        });
        return rules;
    }
    function initEditor(container) {
        const settingId = container.dataset.settingId;
        if (!settingId)
            return;
        const list = container.querySelector('.bhy-cz-rules-list');
        const addRuleBtn = container.querySelector('.bhy-cz-add-rule');
        let lastPushed = null;
        function pushChange() {
            const rules = readRules(list);
            lastPushed = JSON.stringify(rules);
            customizeApi(settingId, function (setting) { setting.set(rules); });
        }
        customizeApi(settingId, function (setting) {
            renderRules(list, setting.get() || []);
            setting.bind(function (newRules) {
                // Skip re-rendering our own push (it would drop focus on every
                // keystroke); external changes still re-render.
                if (JSON.stringify(newRules) === lastPushed)
                    return;
                renderRules(list, newRules || []);
            });
        });
        list.addEventListener('click', function (e) {
            const t = e.target;
            if (t.classList.contains('bhy-remove-rule')) {
                t.closest('.bhy-css-rule').remove();
                pushChange();
            }
            else if (t.classList.contains('bhy-add-prop')) {
                t.closest('.bhy-css-rule').querySelector('.bhy-css-rule-props').appendChild(buildPropRow('', ''));
            }
            else if (t.classList.contains('bhy-remove-prop')) {
                t.closest('.bhy-css-prop-row').remove();
                pushChange();
            }
        });
        list.addEventListener('change', function (e) {
            const t = e.target;
            if (t.classList.contains('bhy-prop-select')) {
                const row = t.closest('.bhy-css-prop-row');
                const slot = row.querySelector('.bhy-prop-control-slot');
                const val = t.value;
                slot.innerHTML = val ? buildValueControlHtml(val, '') : '';
            }
            pushChange();
        });
        list.addEventListener('input', function () { pushChange(); });
        addRuleBtn.addEventListener('click', function () {
            list.appendChild(buildRuleEl({ selector: '', state: '', declarations: {} }));
            pushChange();
        });
    }
    function initAll() {
        document.querySelectorAll('.bhy-cz-rules-editor').forEach(function (el) { initEditor(el); });
        // "Add custom rule for this element" from the preview's right-click menu
        // appends a prefilled rule. WHY: previewer is populated async, so this is
        // attached inside the 'ready' callback.
        if (customizeApi.previewer) {
            customizeApi.previewer.bind('bhy-add-custom-rule', function (selector) {
                customizeApi('bhy_style_settings[custom_css_rules]', function (setting) {
                    const current = setting.get() || [];
                    setting.set(current.concat([{ selector: String(selector), state: '', declarations: {} }]));
                });
                const section = customizeApi.section && customizeApi.section('bhy_live_custom_css');
                if (section)
                    section.focus();
            });
        }
    }
    if (customizeApi.bind) {
        customizeApi.bind('ready', initAll);
    }
    else {
        initAll();
    }
})();
