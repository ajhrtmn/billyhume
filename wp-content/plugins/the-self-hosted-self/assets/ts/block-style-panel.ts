/**
 * "Advanced Styles" — a generic InspectorControls panel added to EVERY
 * block in the native editor, not a bespoke block or a bespoke canvas.
 * See class-block-style.php's own docblock for the full reasoning; this
 * file is that class's client half.
 *
 * wp.element.createElement throughout — no JSX, no build step at
 * runtime, enqueued directly (class-block-style.php's
 * enqueue_editor_assets()), matching this ecosystem's own no-build/
 * no-JSX convention for every other admin-side script.
 *
 * The property vocabulary rendered here comes entirely from
 * `bhyBlockStyleSchema` (wp_localize_script'd from
 * BHY_Style::style_schema_for_js(), class-style.php) — nothing about
 * which properties/presets exist is hardcoded in this file. Add a new
 * property to PROPERTY_MAP server-side and it shows up here with zero
 * JS changes.
 *
 * TypeScript pilot, compiled with plain `tsc` to assets/js/block-style-
 * panel.js. wp.* types come from the shared wp-globals.d.ts; the
 * bhyBlockStyleSchema shape below is this file's own, since it's not a
 * wp.* API.
 */

type BhyPropertyKind =
    | 'space' | 'size' | 'scale' | 'enum-scale' | 'enum'
    | 'percent-0-100' | 'custom-or-number' | 'custom-only' | 'token-only';

interface BhyPropertyDef {
    key: string;
    css: string;
    kind: BhyPropertyKind;
    options?: Record<string, string>;
    allowCustom?: boolean;
}

interface BhyStyleGroup {
    label: string;
    properties: Record<string, BhyPropertyDef>;
}

interface BhyBlockStyleSchema {
    groups: Record<string, BhyStyleGroup>;
    colorTokens: Record<string, string>;
}

interface BhyWindow extends Window {
    bhyBlockStyleSchema?: BhyBlockStyleSchema;
}

type BhStyleMap = Record<string, string>;

(function () {
    var wp = window.wp;
    if (!wp || !wp.blockEditor || !wp.element || !wp.components || !wp.compose || !wp.hooks) return;

    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var SelectControl = wp.components.SelectControl;
    var TextControl = wp.components.TextControl;
    var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
    var addFilter = wp.hooks.addFilter;
    var __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s: string) { return s; };

    var schema: BhyBlockStyleSchema = (window as BhyWindow).bhyBlockStyleSchema || { groups: {}, colorTokens: {} };
    var NONE = '';
    var CUSTOM_SENTINEL = '__bhy_custom__';

    // Flat "group.property" => prop-definition lookup, built once from
    // the same schema the panel itself renders from — the one place a
    // stored bhStyle key gets resolved back to its PROPERTY_MAP entry
    // for the live-preview resolver below.
    var propsByKey: Record<string, BhyPropertyDef> = {};
    Object.keys(schema.groups || {}).forEach(function (gk) {
        var props = schema.groups[gk]!.properties || {};
        Object.keys(props).forEach(function (pk) {
            var prop = props[pk]!;
            propsByKey[prop.key] = prop;
        });
    });

    /**
     * Client-side mirror of BHY_Style::resolve_style_value()
     * (class-style.php) — resolves ONE stored bhStyle value against its
     * PROPERTY_MAP entry into a real CSS value, for the editor-canvas
     * live preview only. Deliberately NOT a security boundary (unlike
     * its PHP counterpart): this only ever feeds a React inline `style`
     * object in the logged-in editor, `scoped_inline_style()` server-
     * side remains the one place that sanitizes what actually reaches a
     * real page's HTML. A value this resolver gets wrong or skips just
     * means the canvas preview is momentarily off — the front end,
     * rendered from the same stored bhStyle map through the real PHP
     * resolver, is unaffected.
     */
    function resolvePreviewValue(raw: unknown, prop: BhyPropertyDef): string | null {
        var rawStr = String(raw || '');
        if (rawStr === '') return null;

        if (rawStr.indexOf('@token:') === 0) {
            if (prop.kind !== 'token-only') return null;
            var cssVar = (schema.colorTokens || {})[rawStr.slice(7)];
            return cssVar ? 'var(' + cssVar + ')' : null;
        }

        if (rawStr.indexOf('custom:') === 0) {
            if (prop.kind === 'token-only') return null;
            var val = rawStr.slice(7);
            if (prop.kind === 'percent-0-100') {
                var pct = parseFloat(val);
                return isNaN(pct) ? null : String(Math.max(0, Math.min(100, pct)) / 100);
            }
            return val; // unsanitized preview value — see docblock above
        }

        switch (prop.kind) {
            case 'space':
            case 'size':
            case 'scale':
            case 'enum-scale':
                return (prop.options && prop.options[rawStr] !== undefined) ? prop.options[rawStr]! : null;
            case 'enum':
                return (prop.options && prop.options[rawStr] !== undefined) ? rawStr : null;
            case 'percent-0-100': {
                var n = parseFloat(rawStr);
                return isNaN(n) ? null : String(Math.max(0, Math.min(100, n)) / 100);
            }
            case 'custom-or-number': {
                var num = parseFloat(rawStr);
                return isNaN(num) ? null : String(num);
            }
            default:
                return null; // token-only/custom-only kinds only accept the @token:/custom: forms above
        }
    }

    /** kebab-case CSS property name -> camelCase, for a React inline `style` object (`background-color` -> `backgroundColor`). */
    function toCamelCase(cssProp: string): string {
        return cssProp.replace(/-([a-z])/g, function (_, c: string) { return c.toUpperCase(); });
    }

    /** A block's bhStyle map -> a React inline `style` object for the editor canvas — the live-preview counterpart of BHY_Style::scoped_inline_style()'s decl-string output. */
    function bhStyleToPreviewStyle(bhStyle: BhStyleMap): Record<string, string> | null {
        var style: Record<string, string> | null = null;
        Object.keys(bhStyle || {}).forEach(function (key) {
            var prop = propsByKey[key];
            if (!prop) return;
            var cssValue = resolvePreviewValue(bhStyle[key], prop);
            if (cssValue === null) return;
            style = style || {};
            style[toCamelCase(prop.css)] = cssValue;
        });
        return style;
    }

    interface PropertyRowProps {
        prop: BhyPropertyDef;
        value?: string;
        onChange: (value: string) => void;
    }

    /**
     * One property row. `value` is always the raw stored string this
     * block's bhStyle[prop.key] currently holds (or '' if unset) — the
     * exact same string BHY_Style::resolve_style_value() consumes
     * server-side, so there is never a second, JS-only value shape to
     * keep in sync with PHP.
     */
    function PropertyRow(props: PropertyRowProps) {
        var prop = props.prop;
        var value = props.value || '';
        var onChange = props.onChange;

        // Token-only (colors): the value is ALWAYS "@token:<field>" or
        // unset — never a raw hex, never a custom escape. Mirrors
        // resolve_style_value()'s own "colors are always token refs"
        // rule exactly (class-style.php).
        if (prop.kind === 'token-only') {
            var tokenOptions = [{ label: __('— Inherit —', 'the-self-hosted-self'), value: NONE }];
            Object.keys(schema.colorTokens || {}).forEach(function (field) {
                tokenOptions.push({ label: field.replace(/^color_/, '').replace(/_/g, ' '), value: '@token:' + field });
            });
            return el(SelectControl, {
                label: prop.css,
                value: value,
                options: tokenOptions,
                onChange: onChange,
            });
        }

        // A property with a real preset table (space/size/scale/enum/
        // enum-scale kinds) — a dropdown of every preset step, plus a
        // "Custom…" escape hatch when the property allows one.
        if (prop.options) {
            var isCustom = value.indexOf('custom:') === 0;
            var selectValue = isCustom ? CUSTOM_SENTINEL : value;
            var options = [{ label: __('— Inherit —', 'the-self-hosted-self'), value: NONE }];
            var stepOptions = prop.options;
            Object.keys(stepOptions).forEach(function (step) {
                options.push({ label: step + ' (' + stepOptions[step] + ')', value: step });
            });
            if (prop.allowCustom) options.push({ label: __('Custom…', 'the-self-hosted-self'), value: CUSTOM_SENTINEL });

            var children = [
                el(SelectControl, {
                    key: 'select',
                    label: prop.css,
                    value: selectValue,
                    options: options,
                    onChange: function (v: string) {
                        if (v === CUSTOM_SENTINEL) { onChange('custom:'); return; }
                        onChange(v);
                    },
                }),
            ];
            if (isCustom) {
                children.push(el(TextControl, {
                    key: 'custom',
                    label: __('Custom value', 'the-self-hosted-self'),
                    value: value.slice(7),
                    placeholder: 'e.g. 2rem, calc(100% - 20px)',
                    onChange: function (v: string) { onChange('custom:' + v); },
                }));
            }
            return el(Fragment, {}, children);
        }

        // No preset table at all — custom-only / custom-or-number /
        // percent-0-100. One free-text field; custom-only kinds are
        // silently prefixed with 'custom:' on write (that's the only
        // form resolve_style_value() accepts for them — a bare value
        // always resolves to null for this kind, by design), the
        // numeric kinds are written bare, matching how a plain number
        // is already the accepted bare form server-side.
        var isCustomOnly = prop.kind === 'custom-only';
        var displayValue = isCustomOnly && value.indexOf('custom:') === 0 ? value.slice(7) : value;
        return el(TextControl, {
            label: prop.css + (prop.kind === 'percent-0-100' ? ' (0–100)' : ''),
            value: displayValue,
            type: (prop.kind === 'percent-0-100' || prop.kind === 'custom-or-number') ? 'number' : 'text',
            onChange: function (v: string) {
                if (v === '') { onChange(''); return; }
                onChange(isCustomOnly ? 'custom:' + v : v);
            },
        });
    }

    interface AdvancedStylesPanelsProps {
        attributes?: { bhStyle?: BhStyleMap };
        setAttributes: (next: { bhStyle: BhStyleMap }) => void;
    }

    function AdvancedStylesPanels(props: AdvancedStylesPanelsProps) {
        var attributes = props.attributes || {};
        var bhStyle = attributes.bhStyle || {};
        var setAttributes = props.setAttributes;

        function setValue(key: string, value: string) {
            var next: BhStyleMap = {};
            Object.keys(bhStyle).forEach(function (k) { next[k] = bhStyle[k]!; });
            if (!value || value === 'custom:') { delete next[key]; } else { next[key] = value; }
            setAttributes({ bhStyle: next });
        }

        var groupKeys = Object.keys(schema.groups || {});
        if (!groupKeys.length) return null;

        return el(Fragment, {}, groupKeys.map(function (gk) {
            var group = schema.groups[gk]!;
            var propKeys = Object.keys(group.properties);
            var rows = propKeys.map(function (pk) {
                var prop = group.properties[pk]!;
                return el(PropertyRow as unknown as WpComponentType, {
                    key: prop.key,
                    prop: prop,
                    value: bhStyle[prop.key] || '',
                    onChange: function (v: string) { setValue(prop.key, v); },
                });
            });
            return el(PanelBody, { key: gk, title: __('Style', 'the-self-hosted-self') + ': ' + group.label, initialOpen: false }, rows);
        }));
    }

    interface BlockEditHocProps {
        isSelected?: boolean;
        [key: string]: unknown;
    }

    // Blocks this generic layout/style panel should NOT attach to.
    // Content-primitive "app" blocks (a lesson video step, a quiz
    // question) are authored for their content, not tuned for grid /
    // flex / typography — the panel is pure noise in that inspector and
    // was the top "authoring feels cluttered" complaint. A prefix
    // ending in "/" matches a whole namespace; anything else is an
    // exact block name. Filterable so any plugin can opt its own blocks
    // out (or back in) — bhc/* (bh-courses lesson steps) is excluded by
    // default.
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    var hooksAny: any = wp.hooks;
    var excludedBlocks: string[] = (typeof hooksAny.applyFilters === 'function'
        ? hooksAny.applyFilters('bhy.advancedStyles.excludedBlocks', ['bhc/'])
        : ['bhc/']) || ['bhc/'];
    function isStylePanelExcluded(name: unknown): boolean {
        if (typeof name !== 'string' || !name) return false;
        for (var i = 0; i < excludedBlocks.length; i++) {
            var p = excludedBlocks[i];
            if (typeof p !== 'string' || !p) continue;
            if (p.charAt(p.length - 1) === '/' ? name.indexOf(p) === 0 : name === p) return true;
        }
        return false;
    }

    var withAdvancedStyles = createHigherOrderComponent(function (BlockEdit: unknown) {
        return function (ownProps: BlockEditHocProps) {
            var edit = el(BlockEdit, ownProps);
            if (!ownProps.isSelected || isStylePanelExcluded(ownProps.name)) return edit;
            return el(Fragment, {}, edit, el(InspectorControls, {}, el(AdvancedStylesPanels as unknown as WpComponentType, ownProps)));
        };
    }, 'withAdvancedStyles');

    addFilter('editor.BlockEdit', 'bhy/advanced-styles', withAdvancedStyles as (...args: unknown[]) => unknown);

    // Server-side register_block_type_args (class-block-style.php) only
    // reserves `bhStyle` for REST/render-time validation — it has no
    // effect on the client's own block type registry, which is what
    // Gutenberg's serializer actually consults when writing the saved
    // block comment. Without this mirror, setAttributes({ bhStyle })
    // above appears to work in the editor's data store but is silently
    // dropped on save because the client-side block type never declared
    // the attribute. Must match the PHP side's shape exactly (type
    // object, default {}).
    addFilter('blocks.registerBlockType', 'bhy/advanced-styles-attribute', function (settingsArg: unknown) {
        var settings = settingsArg as { attributes?: Record<string, unknown> };
        settings.attributes = settings.attributes || {};
        if (!settings.attributes.bhStyle) {
            settings.attributes.bhStyle = { type: 'object', default: {} };
        }
        return settings;
    });

    interface BlockListBlockProps {
        block?: { attributes?: { bhStyle?: BhStyleMap } };
        wrapperProps?: { style?: Record<string, unknown>; [key: string]: unknown };
        [key: string]: unknown;
    }

    // The panel + the render_block/serialization fix above only ever
    // made bhStyle round-trip correctly and render on the real front
    // end — the editor canvas itself stayed unstyled, so a value that
    // silently failed to resolve (a bad token, a stale preset key) was
    // invisible until you actually checked the front end. This filter
    // (the same 'editor.BlockListBlock' wrapperProps extension point
    // core itself uses for e.g. alignment classes) merges the resolved
    // preview style onto the block's own wrapper element in the canvas,
    // so what you set in the panel is what you see while editing —
    // approximate for a handful of block types whose own root element
    // isn't the wrapper WordPress renders here, but correct for the
    // overwhelming majority (paragraphs, headings, groups, images, …).
    //
    // Deliberately NOT gated on `wp.blockEditor.BlockListBlock` existing
    // — that component stopped being part of the package's public
    // export surface in current WordPress (confirmed live on this
    // install: `wp.blockEditor.BlockListBlock` is undefined here), but
    // 'editor.BlockListBlock' is still a real filter Gutenberg's own
    // internal, non-exported block-list renderer applies against
    // whatever its actual (private) component is — addFilter() doesn't
    // need a reference to that component, only the filter NAME. Gating
    // on the public export silently no-opped this entire feature; an
    // earlier version of this file did exactly that and the filter
    // never fired.
    var withStylePreview = createHigherOrderComponent(function (OriginalBlockListBlock: unknown) {
        return function (props: BlockListBlockProps) {
            var bhStyle = props.block && props.block.attributes && props.block.attributes.bhStyle;
            var previewStyle = bhStyle ? bhStyleToPreviewStyle(bhStyle) : null;
            if (!previewStyle) return el(OriginalBlockListBlock, props);
            var wrapperProps = Object.assign({}, props.wrapperProps, {
                style: Object.assign({}, (props.wrapperProps && props.wrapperProps.style) || {}, previewStyle),
            });
            return el(OriginalBlockListBlock, Object.assign({}, props, { wrapperProps: wrapperProps }));
        };
    }, 'withStylePreview');
    addFilter('editor.BlockListBlock', 'bhy/advanced-styles-preview', withStylePreview as (...args: unknown[]) => unknown);
})();
