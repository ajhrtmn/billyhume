/**
 * Editor registration for bhm/buy, bhm/tip-jar and bhm/tiers (class-blocks.php).
 * Hand-written ES5 against WP globals; previews use wp.serverSideRender.
 */
(function (blocks, element, blockEditor, components, i18n, apiFetch, serverSideRender) {
    'use strict';
    if (!blocks || !element || !blockEditor || !serverSideRender) return;

    var el = element.createElement;
    var useState = element.useState;
    var useEffect = element.useEffect;
    var __ = i18n.__;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody = components.PanelBody;
    var SelectControl = components.SelectControl;
    var ServerSideRender = serverSideRender.default || serverSideRender;

    blocks.registerBlockType('bhm/buy', {
        apiVersion: 3,
        title: __('Buy Button (Monetization)', 'bh-monetization-woo'),
        description: __('A "Buy" button/form for a purchasable track or release — the same [bhm_buy] shortcode, as a real block with a live preview.', 'bh-monetization-woo'),
        icon: 'cart',
        category: 'widgets',
        attributes: { id: { type: 'number', default: 0 } },

        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var state = useState([]);
            var objects = state[0];
            var setObjects = state[1];
            var loadState = useState(true);
            var loading = loadState[0];
            var setLoading = loadState[1];

            useEffect(function () {
                apiFetch({ path: '/bhm/v1/purchasable-objects' })
                    .then(function (list) {
                        setObjects(Array.isArray(list) ? list : []);
                        setLoading(false);
                    })
                    // Non-fatal: the picker shows its empty-state explanation.
                    .catch(function () { setLoading(false); });
            }, []);

            var options = [{ label: __('— Select a track or release —', 'bh-monetization-woo'), value: 0 }].concat(
                objects.map(function (o) {
                    return { label: o.type + ': ' + o.title + ' ($' + o.price + ')', value: o.id };
                })
            );

            return el('div', {},
                el(InspectorControls, {},
                    el(PanelBody, { title: __('Purchase', 'bh-monetization-woo') },
                        el(SelectControl, {
                            label: __('Which track/release', 'bh-monetization-woo'),
                            value: attributes.id,
                            options: options,
                            disabled: loading,
                            onChange: function (val) { setAttributes({ id: parseInt(val, 10) || 0 }); },
                        })
                    )
                ),
                attributes.id
                    ? el(ServerSideRender, { block: 'bhm/buy', attributes: attributes })
                    : el(components.Placeholder, {
                        icon: 'cart',
                        label: __('Buy Button', 'bh-monetization-woo'),
                        instructions: __('Choose a track or release in the block sidebar (Inspector panel) to preview its buy button here.', 'bh-monetization-woo'),
                    })
            );
        },

        // Dynamic block: only attributes are saved.
        save: function () { return null; },
    });

    blocks.registerBlockType('bhm/tip-jar', {
        apiVersion: 3,
        title: __('Tip Jar (Monetization)', 'bh-monetization-woo'),
        description: __('A "send a tip" form — the same [bhm_tip_jar] shortcode, as a real block with a live preview.', 'bh-monetization-woo'),
        icon: 'money-alt',
        category: 'widgets',

        // No attributes: the tip jar is site-wide.
        edit: function () {
            return el(ServerSideRender, { block: 'bhm/tip-jar' });
        },

        save: function () { return null; },
    });

    blocks.registerBlockType('bhm/tiers', {
        apiVersion: 3,
        title: __('Supporter Tiers (Monetization)', 'bh-monetization-woo'),
        description: __('The supporter-tier picker grid — the same [bhm_tiers] shortcode, as a real block with a live preview.', 'bh-monetization-woo'),
        icon: 'awards',
        category: 'widgets',

        edit: function () {
            return el(ServerSideRender, { block: 'bhm/tiers' });
        },

        save: function () { return null; },
    });
})(
    window.wp && window.wp.blocks,
    window.wp && window.wp.element,
    window.wp && window.wp.blockEditor,
    window.wp && window.wp.components,
    window.wp && window.wp.i18n,
    window.wp && window.wp.apiFetch,
    window.wp && window.wp.serverSideRender
);
