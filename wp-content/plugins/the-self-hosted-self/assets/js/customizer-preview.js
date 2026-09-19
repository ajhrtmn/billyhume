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
    function ruleToCssText(rule) {
        if (!rule || !rule.selector || !rule.declarations)
            return '';
        const decls = Object.keys(rule.declarations).map(function (prop) {
            return prop + ':' + rule.declarations[prop] + ';';
        }).join('');
        return decls ? rule.selector + (rule.state || '') + '{' + decls + '}' : '';
    }
    wpApi.customize('bhy_style_settings[custom_css_rules]', function (value) {
        value.bind(function (rules) {
            let tag = document.getElementById('bhy-custom-css-rules-preview');
            if (!tag) {
                tag = document.createElement('style');
                tag.id = 'bhy-custom-css-rules-preview';
                document.head.appendChild(tag);
            }
            tag.textContent = (rules || []).map(ruleToCssText).join('\n');
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
            const target = e.target;
            // Real bug caught live: this is a CAPTURE-phase listener on
            // `document`, so it runs BEFORE a menu item's own bubble-
            // phase click listener ever gets a chance to fire — an
            // unconditional closeMenu() here was wiping the context menu
            // (including "Copy CSS selector") out of the DOM before its
            // own click handler could run, even though that handler
            // called stopPropagation(). Skip entirely when the click
            // landed inside the open menu; the dedicated "click outside
            // closes it" listener further down (a bubble-phase listener,
            // which correctly runs AFTER the menu item's own handler)
            // already owns closing it from anywhere else.
            const menu = document.getElementById('bhy-select-menu');
            if (menu && target && menu.contains(target))
                return;
            closeMenu();
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
            // Real bug caught live: WP's own Customizer preview iframe
            // has no clipboard-write permission delegated to it, so
            // navigator.clipboard.writeText() rejects with a silent
            // NotAllowedError in every browser tested — not something
            // fixable from this plugin's JS (we don't control that
            // iframe's `allow` attribute). document.execCommand('copy')
            // is deprecated but still works here since it's synchronous
            // and tied directly to the click's own user gesture, not
            // subject to the async Clipboard API's permission policy.
            // Falls back further to a plain selectable text field if
            // even that's blocked, so there's always a way to get the
            // selector out by hand.
            const copyItem = addItem('Copy CSS selector (' + selector + ')', function () {
                let copied = false;
                const scratch = document.createElement('textarea');
                scratch.value = selector;
                scratch.style.cssText = 'position:fixed;top:-9999px;left:-9999px;';
                document.body.appendChild(scratch);
                scratch.select();
                try {
                    copied = document.execCommand('copy');
                }
                catch (err) {
                    copied = false;
                }
                document.body.removeChild(scratch);
                if (copied) {
                    copyItem.textContent = 'Copied!';
                    setTimeout(closeMenu, 700);
                    return;
                }
                // Last resort: a selectable input right in the menu —
                // guaranteed to work regardless of clipboard permissions.
                copyItem.textContent = 'Select and copy:';
                const field = document.createElement('input');
                field.type = 'text';
                field.value = selector;
                field.readOnly = true;
                field.style.cssText = 'display:block;width:100%;box-sizing:border-box;margin-top:4px;padding:4px 6px;font:12px monospace;';
                field.addEventListener('click', function (ev) { ev.stopPropagation(); field.select(); });
                copyItem.appendChild(field);
                field.focus();
                field.select();
            });
            // "Add custom rule for this element" (AJ, 2026-09-19: keep a
            // way to do custom selectors alongside the visual controls;
            // "Can it stay all on the customizer side instead of jumping
            // to the backend. Its weird" — stays in-Customizer now,
            // rather than the earlier version of this which opened the
            // Design Suite admin page in a new tab): sends the selector
            // straight to BHY_Customize_Css_Rules_Control's own setting
            // (customizer-css-rules-control.ts's previewer.bind handler),
            // which appends a new rule and focuses the Custom CSS
            // section — the new rule just appears there, already open.
            // The selector field there is a plain editable text input,
            // not read-only — this is a starting point, not a locked
            // value, since the auto-computed selector is a heuristic
            // (tag+2 classes+parent) that sometimes needs hand-refinement.
            addItem('Add custom rule for this element…', function () {
                const preview = getPreview();
                if (preview)
                    preview.send('bhy-add-custom-rule', selector);
                closeMenu();
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
