/**
 * Live-preview side of BHY_Customizer: binds each registered token setting and
 * writes it to the same --bh-custom-* / --bh-comp-<group>-<key> property on
 * <html> that BHY_Style::inline_css() emits. Schema is localized from PHP.
 */
interface BhyCustomizerSchemaEntry {
    id: string;
    var: string;
    unit?: string;
    isFont?: boolean;
    fallback?: string;
    isToggle?: boolean;
    onVal?: string;
    offVal?: string;
}

(function () {
    const wpApi = window.wp;
    if (!wpApi || !wpApi.customize) return;
    const schema = (window as unknown as { bhyCustomizerSchema?: BhyCustomizerSchemaEntry[] }).bhyCustomizerSchema;
    if (!schema) return;

    schema.forEach(function (entry) {
        wpApi.customize!(entry.id, function (value) {
            value.bind(function (newVal: string | boolean) {
                const root = document.documentElement;
                if (entry.isToggle) {
                    const on = newVal === true || newVal === '1' || newVal === 'true';
                    root.style.setProperty(entry.var, on ? (entry.onVal || 'none') : (entry.offVal || 'flex'));
                } else if (entry.isFont) {
                    const name = String(newVal || '').trim();
                    if (!name) return;
                    root.style.setProperty(entry.var, '"' + name.replace(/"/g, '') + '", ' + (entry.fallback || 'sans-serif'));
                } else {
                    root.style.setProperty(entry.var, newVal + (entry.unit || ''));
                }
            });
        });
    });

    // Visual rule editor setting, turned into CSS like inline_css(). Its <style>
    // is created before the raw Custom CSS one so raw overrides still win.
    interface BhyCssRule { selector: string; state?: string; declarations?: Record<string, string>; }
    function ruleToCssText(rule: BhyCssRule): string {
        if (!rule || !rule.selector || !rule.declarations) return '';
        const decls = Object.keys(rule.declarations).map(function (prop) {
            return prop + ':' + (rule.declarations as Record<string, string>)[prop] + ';';
        }).join('');
        return decls ? rule.selector + (rule.state || '') + '{' + decls + '}' : '';
    }
    wpApi.customize!('bhy_style_settings[custom_css_rules]', function (value) {
        value.bind(function (rules: BhyCssRule[]) {
            let tag = document.getElementById('bhy-custom-css-rules-preview') as HTMLStyleElement | null;
            if (!tag) {
                tag = document.createElement('style');
                tag.id = 'bhy-custom-css-rules-preview';
                document.head.appendChild(tag);
            }
            tag.textContent = (rules || []).map(ruleToCssText).join('\n');
        });
    });

    // Raw Custom CSS: swaps one <style> tag's content on every change (core's
    // Additional CSS preview does not apply to our own option).
    wpApi.customize!('bhy_style_settings[custom_css]', function (value) {
        value.bind(function (css: string) {
            let tag = document.getElementById('bhy-custom-css-preview') as HTMLStyleElement | null;
            if (!tag) {
                tag = document.createElement('style');
                tag.id = 'bhy-custom-css-preview';
                document.head.appendChild(tag);
            }
            tag.textContent = String(css || '');
        });
    });

    // Click-to-select / right-click ancestor picker and "Copy CSS selector".
    // Scope stays at jumping to a section or copying a selector: no floating
    // inspector or linked-component system (see the page-builder saga).
    interface BhySelectorEntry { group: string; selector: string; label: string; }
    const selectors = (window as unknown as { bhyCustomizerSelectors?: BhySelectorEntry[] }).bhyCustomizerSelectors || [];
    {
        // WHY: wp.customize.preview is set up async, so it is read lazily inside
        // each handler rather than gating listener setup on it.
        const customizeApi = wpApi.customize;
        function getPreview(): { send(event: string, data?: unknown): void } | undefined {
            return customizeApi.preview;
        }

        function matchesFor(target: Element): BhySelectorEntry[] {
            const out: BhySelectorEntry[] = [];
            let el: Element | null = target;
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

        // A short, usable selector (id, else tag+classes with parent prefix);
        // not a robust unique-selector generator.
        function shortSelector(el: Element): string {
            if (el.id) return '#' + el.id;
            const cls = Array.from(el.classList).slice(0, 2);
            return el.tagName.toLowerCase() + (cls.length ? '.' + cls.join('.') : '');
        }
        function computeSelector(el: Element): string {
            const parent = el.parentElement;
            const self = shortSelector(el);
            if (parent && parent.tagName.toLowerCase() !== 'body' && (parent.id || parent.classList.length)) {
                return shortSelector(parent) + ' > ' + self;
            }
            return self;
        }

        function closeMenu() {
            const existing = document.getElementById('bhy-select-menu');
            if (existing) existing.remove();
        }

        // Outline clickable components on hover for discoverability.
        let highlighted: HTMLElement | null = null;
        document.addEventListener('mouseover', function (e) {
            const target = e.target as Element | null;
            if (!target) return;
            let el: Element | null = target;
            let matchEl: HTMLElement | null = null;
            while (el && el !== document.documentElement) {
                if (selectors.some(function (entry) { return el!.matches(entry.selector); })) { matchEl = el as HTMLElement; break; }
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
            const target = e.target as Element | null;
            // WHY: this capture-phase listener runs before menu items' own click
            // handlers, so it must ignore clicks inside the open menu.
            const menu = document.getElementById('bhy-select-menu');
            if (menu && target && menu.contains(target)) return;
            closeMenu();
            if (!target) return;
            const matches = matchesFor(target);
            if (!matches.length) return;
            // Deepest matching ancestor wins on a plain click.
            const preview = getPreview();
            if (preview) preview.send('bhy-jump-to-section', matches[0]!.group);
        }, true);

        document.addEventListener('contextmenu', function (e) {
            const target = e.target as Element | null;
            if (!target) return;
            e.preventDefault();
            closeMenu();
            const matches = matchesFor(target);
            const menu = document.createElement('div');
            menu.id = 'bhy-select-menu';
            menu.style.cssText = 'position:fixed;z-index:999999;background:#1e1e1e;color:#fff;border-radius:4px;box-shadow:0 4px 16px rgba(0,0,0,.4);padding:4px 0;font:13px -apple-system,sans-serif;min-width:200px;left:' + e.clientX + 'px;top:' + e.clientY + 'px;';
            function addItem(text: string, onClick: () => void) {
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
                    if (preview) preview.send('bhy-jump-to-section', entry.group);
                    closeMenu();
                });
            });
            if (matches.length) {
                const divider = document.createElement('div');
                divider.style.cssText = 'height:1px;background:#444;margin:4px 0;';
                menu.appendChild(divider);
            }
            const selector = computeSelector(target);
            // WHY: the Customizer preview iframe lacks clipboard-write permission,
            // so navigator.clipboard fails; use synchronous execCommand('copy'),
            // then a selectable text field as the last resort.
            const copyItem = addItem('Copy CSS selector (' + selector + ')', function () {
                let copied = false;
                const scratch = document.createElement('textarea');
                scratch.value = selector;
                scratch.style.cssText = 'position:fixed;top:-9999px;left:-9999px;';
                document.body.appendChild(scratch);
                scratch.select();
                try {
                    copied = document.execCommand('copy');
                } catch (err) {
                    copied = false;
                }
                document.body.removeChild(scratch);
                if (copied) {
                    copyItem.textContent = 'Copied!';
                    setTimeout(closeMenu, 700);
                    return;
                }
                // Last resort: a selectable input in the menu.
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
            // Sends the selector to the Custom CSS rules control, which appends an
            // editable new rule and focuses that section.
            addItem('Add custom rule for this element…', function () {
                const preview = getPreview();
                if (preview) preview.send('bhy-add-custom-rule', selector);
                closeMenu();
            });
            document.body.appendChild(menu);
        }, true);

        document.addEventListener('click', function (e) {
            const menu = document.getElementById('bhy-select-menu');
            if (menu && !menu.contains(e.target as Node)) closeMenu();
        });
    }
})();
