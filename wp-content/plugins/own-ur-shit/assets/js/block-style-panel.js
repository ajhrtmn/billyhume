/**
 * "Advanced Styles" — a generic InspectorControls panel added to EVERY
 * block in the native editor, not a bespoke block or a bespoke canvas.
 * See class-block-style.php's own docblock for the full reasoning; this
 * file is that class's client half.
 *
 * Vanilla wp.element.createElement throughout — no JSX, no build step,
 * enqueued directly (class-block-style.php's enqueue_editor_assets()),
 * matching this ecosystem's own no-build/no-JSX convention for every
 * other admin-side script.
 *
 * The property vocabulary rendered here comes entirely from
 * `bhyBlockStyleSchema` (wp_localize_script'd from
 * BHY_Style::style_schema_for_js(), class-style.php) — nothing about
 * which properties/presets exist is hardcoded in this file. Add a new
 * property to PROPERTY_MAP server-side and it shows up here with zero
 * JS changes.
 */
(function () {
    if (!window.wp || !wp.blockEditor || !wp.element || !wp.components || !wp.compose || !wp.hooks) return;

    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var SelectControl = wp.components.SelectControl;
    var TextControl = wp.components.TextControl;
    var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
    var addFilter = wp.hooks.addFilter;
    var __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };

    var schema = window.bhyBlockStyleSchema || { groups: {}, colorTokens: {} };
    var NONE = '';
    var CUSTOM_SENTINEL = '__bhy_custom__';

    /**
     * One property row. `value` is always the raw stored string this
     * block's bhStyle[prop.key] currently holds (or '' if unset) — the
     * exact same string BHY_Style::resolve_style_value() consumes
     * server-side, so there is never a second, JS-only value shape to
     * keep in sync with PHP.
     */
    function PropertyRow(props) {
        var prop = props.prop;
        var value = props.value || '';
        var onChange = props.onChange;

        // Token-only (colors): the value is ALWAYS "@token:<field>" or
        // unset — never a raw hex, never a custom escape. Mirrors
        // resolve_style_value()'s own "colors are always token refs"
        // rule exactly (class-style.php).
        if (prop.kind === 'token-only') {
            var tokenOptions = [{ label: __('— Inherit —', 'own-ur-shit'), value: NONE }];
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
            var options = [{ label: __('— Inherit —', 'own-ur-shit'), value: NONE }];
            Object.keys(prop.options).forEach(function (step) {
                options.push({ label: step + ' (' + prop.options[step] + ')', value: step });
            });
            if (prop.allowCustom) options.push({ label: __('Custom…', 'own-ur-shit'), value: CUSTOM_SENTINEL });

            var children = [
                el(SelectControl, {
                    key: 'select',
                    label: prop.css,
                    value: selectValue,
                    options: options,
                    onChange: function (v) {
                        if (v === CUSTOM_SENTINEL) { onChange('custom:'); return; }
                        onChange(v);
                    },
                }),
            ];
            if (isCustom) {
                children.push(el(TextControl, {
                    key: 'custom',
                    label: __('Custom value', 'own-ur-shit'),
                    value: value.slice(7),
                    placeholder: 'e.g. 2rem, calc(100% - 20px)',
                    onChange: function (v) { onChange('custom:' + v); },
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
            onChange: function (v) {
                if (v === '') { onChange(''); return; }
                onChange(isCustomOnly ? 'custom:' + v : v);
            },
        });
    }

    function AdvancedStylesPanels(props) {
        var attributes = props.attributes || {};
        var bhStyle = attributes.bhStyle || {};
        var setAttributes = props.setAttributes;

        function setValue(key, value) {
            var next = {};
            Object.keys(bhStyle).forEach(function (k) { next[k] = bhStyle[k]; });
            if (!value || value === 'custom:') { delete next[key]; } else { next[key] = value; }
            setAttributes({ bhStyle: next });
        }

        var groupKeys = Object.keys(schema.groups || {});
        if (!groupKeys.length) return null;

        return el(Fragment, {}, groupKeys.map(function (gk) {
            var group = schema.groups[gk];
            var propKeys = Object.keys(group.properties);
            var rows = propKeys.map(function (pk) {
                var prop = group.properties[pk];
                return el(PropertyRow, {
                    key: prop.key,
                    prop: prop,
                    value: bhStyle[prop.key] || '',
                    onChange: function (v) { setValue(prop.key, v); },
                });
            });
            return el(PanelBody, { key: gk, title: __('Style', 'own-ur-shit') + ': ' + group.label, initialOpen: false }, rows);
        }));
    }

    var withAdvancedStyles = createHigherOrderComponent(function (BlockEdit) {
        return function (ownProps) {
            var edit = el(BlockEdit, ownProps);
            if (!ownProps.isSelected) return edit;
            return el(Fragment, {}, edit, el(InspectorControls, {}, el(AdvancedStylesPanels, ownProps)));
        };
    }, 'withAdvancedStyles');

    addFilter('editor.BlockEdit', 'bhy/advanced-styles', withAdvancedStyles);
})();
