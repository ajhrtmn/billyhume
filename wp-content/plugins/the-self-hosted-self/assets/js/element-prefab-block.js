"use strict";
/**
 * Editor registration for the 'the-self-hosted-self/element-prefab' block
 * (class-gutenberg-block.php): a prefab picker in the Inspector and a plain-text
 * placeholder in the canvas (no live server-side preview).
 */
(function (blocks, element, blockEditor, components, i18n, apiFetch) {
    'use strict';
    if (!blocks || !element || !blockEditor || !components || !i18n || !apiFetch)
        return;
    var el = element.createElement;
    var useState = element.useState;
    var useEffect = element.useEffect;
    var __ = i18n.__;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody = components.PanelBody;
    var SelectControl = components.SelectControl;
    var Placeholder = components.Placeholder;
    blocks.registerBlockType('the-self-hosted-self/element-prefab', {
        title: __('Element Prefab (The Self-Hosted Self)', 'the-self-hosted-self'),
        description: __('Embeds a saved Design Suite prefab (a node + its children) live in this post.', 'the-self-hosted-self'),
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
                    // Not fatal: without permission the picker is just empty.
                    .catch(function () { setLoading(false); });
            }, []);
            var options = [{ label: __('— Select a prefab —', 'the-self-hosted-self'), value: 0 }].concat(prefabs.map(function (p) {
                return { label: p.name + ' (' + p.element_count + ' node' + (p.element_count === 1 ? '' : 's') + ')', value: p.id };
            }));
            var selectedLabel = '';
            for (var i = 0; i < prefabs.length; i++) {
                var candidate = prefabs[i];
                if (candidate && candidate.id === attributes.prefabId) {
                    selectedLabel = candidate.name;
                    break;
                }
            }
            return el('div', {}, el(InspectorControls, {}, el(PanelBody, { title: __('Prefab', 'the-self-hosted-self') }, el(SelectControl, {
                label: __('Which saved prefab to embed', 'the-self-hosted-self'),
                value: attributes.prefabId,
                options: options,
                disabled: loading,
                onChange: function (val) { setAttributes({ prefabId: parseInt(val, 10) || 0 }); },
            }))), el(Placeholder, {
                icon: 'layout',
                label: __('Element Prefab', 'the-self-hosted-self'),
                instructions: attributes.prefabId
                    ? __('Embedding: ', 'the-self-hosted-self') + selectedLabel + '. ' + __('Live preview isn’t shown in the editor — view the published page to see it rendered.', 'the-self-hosted-self')
                    : __('Choose a prefab in the block sidebar (Inspector panel) to embed it here.', 'the-self-hosted-self'),
            }));
        },
        // Dynamic block: render_callback renders; only attributes are saved.
        save: function () { return null; },
    });
})(window.wp && window.wp.blocks, window.wp && window.wp.element, window.wp && window.wp.blockEditor, window.wp && window.wp.components, window.wp && window.wp.i18n, window.wp && window.wp.apiFetch);
