/**
 * bhm-blocks.js — editor-side registration for 'bhm/buy' and
 * 'bhm/tip-jar' (class-blocks.php). Plain ES5-safe JS against WP core's
 * own globals, no build step, same convention every other block in
 * this ecosystem follows (own-ur-shit's element-prefab-block.js,
 * bh-courses' Studio blocks). Unlike element-prefab-block.js (which
 * deliberately deferred a live preview), this uses wp.serverSideRender.
 * ServerSideRender — the actual PHP render_callback output shown live
 * in the canvas, not a mimic.
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
                    // Not fatal — an empty picker with a plain-language
                    // explanation, not a broken block, same posture
                    // element-prefab-block.js's own picker uses.
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

        // Server-rendered — the editor never saves markup of its own for
        // this block, only its attributes, matching WP core's own
        // dynamic-block contract (same as own-ur-shit's element-prefab
        // block).
        save: function () { return null; },
    });

    blocks.registerBlockType('bhm/tip-jar', {
        title: __('Tip Jar (Monetization)', 'bh-monetization-woo'),
        description: __('A "send a tip" form — the same [bhm_tip_jar] shortcode, as a real block with a live preview.', 'bh-monetization-woo'),
        icon: 'money-alt',
        category: 'widgets',

        // No attributes, no picker — the tip jar is always the one
        // site-wide Tip product (same as the shortcode itself takes no
        // attributes), so the preview renders unconditionally.
        edit: function () {
            return el(ServerSideRender, { block: 'bhm/tip-jar' });
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
