"use strict";
(function () {
    const wpApi = window.wp;
    if (!wpApi || !wpApi.customize)
        return;
    const schema = window.bhyCustomizerSchema;
    if (!schema)
        return;
    schema.forEach(function (entry) {
        wpApi.customize(entry.id, function (value) {
            value.bind(function (newVal) {
                const root = document.documentElement;
                if (entry.isToggle) {
                    const on = newVal === true || newVal === '1' || newVal === 'true';
                    root.style.setProperty(entry.var, on ? (entry.onVal || 'none') : (entry.offVal || 'flex'));
                }
                else if (entry.isFont) {
                    const name = String(newVal || '').trim();
                    if (!name)
                        return;
                    root.style.setProperty(entry.var, '"' + name.replace(/"/g, '') + '", ' + (entry.fallback || 'sans-serif'));
                }
                else {
                    root.style.setProperty(entry.var, newVal + (entry.unit || ''));
                }
            });
        });
    });
    // Custom CSS (BHY_Customizer::register()'s WP_Customize_Code_Editor_
    // Control) — not a --bh-* variable, so kept out of the generic
    // schema loop above; just swaps one <style> tag's whole content on
    // every change, live, same as WP core's own Additional CSS control
    // does in its own default preview handling (which we don't get for
    // free here since this setting lives under our own option, not
    // theme_mods/custom_css post content).
    wpApi.customize('bhy_style_settings[custom_css]', function (value) {
        value.bind(function (css) {
            let tag = document.getElementById('bhy-custom-css-preview');
            if (!tag) {
                tag = document.createElement('style');
                tag.id = 'bhy-custom-css-preview';
                document.head.appendChild(tag);
            }
            tag.textContent = String(css || '');
        });
    });
    const selectors = window.bhyCustomizerSelectors || [];
    {
        // Real bug caught live: `wp.customize.preview` is not necessarily
        // populated yet at THIS script's load time (it's set up async by
        // 'customize-preview' during its own init sequence) even though
        // this script depends on that handle — gating the whole listener
        // block on its truthiness here silently skipped attaching any
        // listeners at all. Read it lazily, inside each handler, instead.
        const customizeApi = wpApi.customize;
        function getPreview() {
            return customizeApi.preview;
        }
        function matchesFor(target) {
            const out = [];
            let el = target;
            while (el && el !== document.documentElement) {
                for (const entry of selectors) {
                    if (el.matches(entry.selector) && !out.some(function (o) { return o.group === entry.group; })) {
                        out.push(entry);
                    }
                }
                el = el.parentElement;
            }
            return out;
        }
        // A short, real, usable CSS selector for an arbitrary element —
        // id if it has one, else its own tag+classes, prefixed with its
        // parent's tag+classes for a little extra specificity (enough to
        // paste into Custom CSS and actually work, not meant to be a
        // unique/robust selector generator).
        function shortSelector(el) {
            if (el.id)
                return '#' + el.id;
            const cls = Array.from(el.classList).slice(0, 2);
            return el.tagName.toLowerCase() + (cls.length ? '.' + cls.join('.') : '');
        }
        function computeSelector(el) {
            const parent = el.parentElement;
            const self = shortSelector(el);
            if (parent && parent.tagName.toLowerCase() !== 'body' && (parent.id || parent.classList.length)) {
                return shortSelector(parent) + ' > ' + self;
            }
            return self;
        }
        function closeMenu() {
            const existing = document.getElementById('bhy-select-menu');
            if (existing)
                existing.remove();
        }
        // Hover highlight — the discoverability affordance: an element
        // that's actually clickable (matches a registered component)
        // gets a visible outline on hover, so "click things to edit
        // them" doesn't have to be discovered by trial and error.
        let highlighted = null;
        document.addEventListener('mouseover', function (e) {
            const target = e.target;
            if (!target)
                return;
            let el = target;
            let matchEl = null;
            while (el && el !== document.documentElement) {
                if (selectors.some(function (entry) { return el.matches(entry.selector); })) {
                    matchEl = el;
                    break;
                }
                el = el.parentElement;
            }
            if (highlighted && highlighted !== matchEl) {
                highlighted.style.outline = '';
                highlighted.style.cursor = '';
            }
            if (matchEl) {
                matchEl.style.outline = '2px dashed #2271b1';
                matchEl.style.outlineOffset = '-1px';
                matchEl.style.cursor = 'pointer';
            }
            highlighted = matchEl;
        }, true);
        document.addEventListener('click', function (e) {
            closeMenu();
            const target = e.target;
            if (!target)
                return;
            const matches = matchesFor(target);
            if (!matches.length)
                return;
            // Deepest/most-specific ancestor wins on a plain click —
            // the same "closest real component" instinct a click-to-
            // select tool should have, without needing a menu for the
            // common single-component case.
            const preview = getPreview();
            if (preview)
                preview.send('bhy-jump-to-section', matches[0].group);
        }, true);
        document.addEventListener('contextmenu', function (e) {
            const target = e.target;
            if (!target)
                return;
            e.preventDefault();
            closeMenu();
            const matches = matchesFor(target);
            const menu = document.createElement('div');
            menu.id = 'bhy-select-menu';
            menu.style.cssText = 'position:fixed;z-index:999999;background:#1e1e1e;color:#fff;border-radius:4px;box-shadow:0 4px 16px rgba(0,0,0,.4);padding:4px 0;font:13px -apple-system,sans-serif;min-width:200px;left:' + e.clientX + 'px;top:' + e.clientY + 'px;';
            function addItem(text, onClick) {
                const item = document.createElement('div');
                item.textContent = text;
                item.style.cssText = 'padding:7px 14px;cursor:pointer;';
                item.addEventListener('mouseenter', function () { item.style.background = '#333'; });
                item.addEventListener('mouseleave', function () { item.style.background = ''; });
                item.addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    onClick();
                });
                menu.appendChild(item);
                return item;
            }
            matches.forEach(function (entry) {
                addItem('Edit: ' + entry.label, function () {
                    const preview = getPreview();
                    if (preview)
                        preview.send('bhy-jump-to-section', entry.group);
                    closeMenu();
                });
            });
            if (matches.length) {
                const divider = document.createElement('div');
                divider.style.cssText = 'height:1px;background:#444;margin:4px 0;';
                menu.appendChild(divider);
            }
            const selector = computeSelector(target);
            const copyItem = addItem('Copy CSS selector (' + selector + ')', function () {
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(selector).then(function () {
                        copyItem.textContent = 'Copied!';
                        setTimeout(closeMenu, 600);
                    });
                }
            });
            document.body.appendChild(menu);
        }, true);
        document.addEventListener('click', function (e) {
            const menu = document.getElementById('bhy-select-menu');
            if (menu && !menu.contains(e.target))
                closeMenu();
        });
    }
})();
