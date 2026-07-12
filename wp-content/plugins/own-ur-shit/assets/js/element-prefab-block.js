/**
 * element-prefab-block.js — editor-side registration for the
 * 'own-ur-shit/element-prefab' block (class-gutenberg-block.php). Plain
 * ES5-safe JS against WP core's own globals, no build step — same "no
 * Node/webpack" constraint the rest of this ecosystem's admin JS
 * follows. Deliberately minimal: a prefab picker in the sidebar
 * Inspector panel, and a plain-text placeholder in the block canvas
 * itself (NOT a live ServerSideRender preview — a real, honestly-scoped
 * simplification: this pass ships selecting + embedding a prefab, not a
 * live in-editor preview of its rendered output, which would need
 * either wp.serverSideRender or a bespoke fetch-based preview; left for
 * a follow-up once this simpler slice is confirmed working end to end).
 */
(function (blocks, element, blockEditor, components, i18n, apiFetch) {
    'use strict';
    if (!blocks || !element || !blockEditor) return;

    var el = element.createElement;
    var useState = element.useState;
    var useEffect = element.useEffect;
    var __ = i18n.__;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody = components.PanelBody;
    var SelectControl = components.SelectControl;
    var Placeholder = components.Placeholder;

    blocks.registerBlockType('own-ur-shit/element-prefab', {
        title: __('Element Prefab (Own Ur Shit)', 'own-ur-shit'),
        description: __('Embeds a saved Design Suite prefab (a node + its children) live in this post.', 'own-ur-shit'),
        icon: 'layout',
        category: 'widgets',
        attributes: { prefabId: { type: 'number', default: 0 } },

        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var state = useState([]);
            var prefabs = state[0];
            var setPrefabs = state[1];
            var loadState = useState(true);
            var loading = loadState[0];
            var setLoading = loadState[1];

            useEffect(function () {
                apiFetch({ path: '/ous/v1/elements/prefabs' })
                    .then(function (list) {
                        setPrefabs(Array.isArray(list) ? list : []);
                        setLoading(false);
                    })
                    // Not fatal — an admin without bhcore_design_site (or a
                    // request made before the REST route's own permission
                    // check passes) just sees an empty picker with a plain-
                    // language explanation, not a broken block.
                    .catch(function () { setLoading(false); });
            }, []);

            var options = [{ label: __('— Select a prefab —', 'own-ur-shit'), value: 0 }].concat(
                prefabs.map(function (p) {
                    return { label: p.name + ' (' + p.element_count + ' node' + (p.element_count === 1 ? '' : 's') + ')', value: p.id };
                })
            );

            var selectedLabel = '';
            for (var i = 0; i < prefabs.length; i++) {
                if (prefabs[i].id === attributes.prefabId) { selectedLabel = prefabs[i].name; break; }
            }

            return el('div', {},
                el(InspectorControls, {},
                    el(PanelBody, { title: __('Prefab', 'own-ur-shit') },
                        el(SelectControl, {
                            label: __('Which saved prefab to embed', 'own-ur-shit'),
                            value: attributes.prefabId,
                            options: options,
                            disabled: loading,
                            onChange: function (val) { setAttributes({ prefabId: parseInt(val, 10) || 0 }); },
                        })
                    )
                ),
                el(Placeholder, {
                    icon: 'layout',
                    label: __('Element Prefab', 'own-ur-shit'),
                    instructions: attributes.prefabId
                        ? __('Embedding: ', 'own-ur-shit') + selectedLabel + '. ' + __('Live preview isn’t shown in the editor — view the published page to see it rendered.', 'own-ur-shit')
                        : __('Choose a prefab in the block sidebar (Inspector panel) to embed it here.', 'own-ur-shit'),
                })
            );
        },

        // Server-rendered (render_callback in PHP) — the editor never
        // saves any markup of its own for this block, only its
        // attributes, matching WP core's own dynamic-block contract.
        save: function () { return null; },
    });
})(
    window.wp && window.wp.blocks,
    window.wp && window.wp.element,
    window.wp && window.wp.blockEditor,
    window.wp && window.wp.components,
    window.wp && window.wp.i18n,
    window.wp && window.wp.apiFetch
);
