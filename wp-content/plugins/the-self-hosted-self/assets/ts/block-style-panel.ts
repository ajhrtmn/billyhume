/**
 * "Advanced Styles": a generic InspectorControls panel on every block; the
 * client half of class-block-style.php. The property vocabulary comes entirely
 * from `bhyBlockStyleSchema` (BHY_Style::style_schema_for_js()), so new
 * PROPERTY_MAP entries need no JS changes.
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

    // Flat "group.property" => definition lookup for the preview resolver.
    var propsByKey: Record<string, BhyPropertyDef> = {};
    Object.keys(schema.groups || {}).forEach(function (gk) {
        var props = schema.groups[gk]!.properties || {};
        Object.keys(props).forEach(function (pk) {
            var prop = props[pk]!;
            propsByKey[prop.key] = prop;
        });
    });

    /**
     * Client mirror of BHY_Style::resolve_style_value() for the editor preview
     * only. Not a security boundary: scoped_inline_style() server-side sanitizes
     * what reaches real pages.
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

    /** kebab-case CSS property -> camelCase for a React `style` object. */
    function toCamelCase(cssProp: string): string {
        return cssProp.replace(/-([a-z])/g, function (_, c: string) { return c.toUpperCase(); });
    }

    /** A block's bhStyle map -> React inline `style` for the canvas preview. */
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
     * One property row. `value` is the raw stored bhStyle string (or ''), the
     * same string the PHP resolver consumes.
     */
    function PropertyRow(props: PropertyRowProps) {
        var prop = props.prop;
        var value = props.value || '';
        var onChange = props.onChange;

        // Token-only (colors): always "@token:<field>" or unset, never raw hex.
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

        // Preset table: dropdown of steps, plus "Custom…" when allowed.
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

        // No preset table: one text field. custom-only values are written with
        // the 'custom:' prefix (the only form the server accepts); numeric kinds bare.
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

    // Blocks the panel skips (content-primitive blocks like bhc/* lesson steps).
    // A prefix ending in "/" matches a namespace. Filterable.
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

    // WHY: the client block registry must declare bhStyle too, or the serializer
    // silently drops it on save. Must match the PHP shape (object, default {}).
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

    // Applies the resolved preview style to the block wrapper in the canvas.
    // WHY: not gated on wp.blockEditor.BlockListBlock, which is no longer
    // exported; the 'editor.BlockListBlock' filter still fires by name.
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
