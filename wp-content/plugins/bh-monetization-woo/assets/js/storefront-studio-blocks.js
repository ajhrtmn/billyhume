/**
 * Editor registration for the storefront blocks (BH_Studio canvas and block editor screens;
 * never the front end). Dynamic blocks: only query attributes are stored, and edit() shows
 * a placeholder rather than a live product preview.
 */
(function (wp) {
    'use strict';
    if (!wp || !wp.blocks || !wp.element || !wp.blockEditor || !wp.components) return;

    var el = wp.element.createElement;
    var __ = wp.i18n ? wp.i18n.__ : function (s) { return s; };

    wp.blocks.registerBlockType('bhm/product-grid', {
        apiVersion: 3,
        title: __('Product Grid'),
        icon: 'grid-view',
        category: 'commerce',
        attributes: {
            collection: { type: 'string', default: '' },
            category: { type: 'string', default: '' },
            columns: { type: 'number', default: 4 },
            limit: { type: 'number', default: 12 },
            showFilters: { type: 'boolean', default: false },
        },
        supports: { html: false },
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhm-studio-block-placeholder' });
            return el('div', blockProps,
                el('p', {}, __('Product Grid') + ' — ' + (attrs.collection ? ('collection: ' + attrs.collection) : (attrs.category ? ('category: ' + attrs.category) : __('all products'))) + ' (' + attrs.columns + ' cols, ' + attrs.limit + ' max)'),
                el(wp.blockEditor.InspectorControls, {},
                    el(wp.components.PanelBody, { title: __('Product Grid settings') },
                        el(wp.components.TextControl, { label: __('Collection slug'), value: attrs.collection, onChange: function (v) { setAttrs({ collection: v, category: '' }); } }),
                        el(wp.components.TextControl, { label: __('Or WooCommerce category slug'), value: attrs.category, onChange: function (v) { setAttrs({ category: v, collection: '' }); } }),
                        el(wp.components.RangeControl, { label: __('Columns'), value: attrs.columns, onChange: function (v) { setAttrs({ columns: v }); }, min: 1, max: 6 }),
                        el(wp.components.RangeControl, { label: __('Max products'), value: attrs.limit, onChange: function (v) { setAttrs({ limit: v }); }, min: 1, max: 48 }),
                        el(wp.components.ToggleControl, { label: __('Show filter controls above grid'), checked: !!attrs.showFilters, onChange: function (v) { setAttrs({ showFilters: v }); } })
                    )
                )
            );
        },
        // Dynamic block: rendered server-side.
        save: function () { return null; },
    });

    wp.blocks.registerBlockType('bhm/product-filter', {
        apiVersion: 3,
        title: __('Product Filter'),
        icon: 'filter',
        category: 'commerce',
        attributes: {
            showPrice: { type: 'boolean', default: true },
            showCategory: { type: 'boolean', default: true },
            showStock: { type: 'boolean', default: true },
        },
        supports: { html: false },
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhm-studio-block-placeholder' });
            return el('div', blockProps,
                el('p', {}, __('Product Filter') + ' (' + [attrs.showCategory && 'category', attrs.showPrice && 'price', attrs.showStock && 'stock'].filter(Boolean).join(', ') + ')'),
                el(wp.blockEditor.InspectorControls, {},
                    el(wp.components.PanelBody, { title: __('Filter settings') },
                        el(wp.components.ToggleControl, { label: __('Category filter'), checked: !!attrs.showCategory, onChange: function (v) { setAttrs({ showCategory: v }); } }),
                        el(wp.components.ToggleControl, { label: __('Price range filter'), checked: !!attrs.showPrice, onChange: function (v) { setAttrs({ showPrice: v }); } }),
                        el(wp.components.ToggleControl, { label: __('In-stock-only filter'), checked: !!attrs.showStock, onChange: function (v) { setAttrs({ showStock: v }); } })
                    )
                )
            );
        },
        save: function () { return null; },
    });

    wp.blocks.registerBlockType('bhm/related-products', {
        apiVersion: 3,
        title: __('Related Products'),
        icon: 'networking',
        category: 'commerce',
        attributes: {
            productId: { type: 'number', default: 0 },
            limit: { type: 'number', default: 8 },
            heading: { type: 'string', default: 'You may also like' },
        },
        supports: { html: false },
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhm-studio-block-placeholder' });
            return el('div', blockProps,
                el('p', {}, __('Related Products') + ' — ' + (attrs.productId ? ('product #' + attrs.productId) : __('current product')) + ', ' + attrs.limit + ' max'),
                el(wp.blockEditor.InspectorControls, {},
                    el(wp.components.PanelBody, { title: __('Related Products settings') },
                        el(wp.components.TextControl, { label: __('Heading'), value: attrs.heading, onChange: function (v) { setAttrs({ heading: v }); } }),
                        el(wp.components.TextControl, { label: __('Product ID (0 = current product)'), type: 'number', value: attrs.productId, onChange: function (v) { setAttrs({ productId: parseInt(v, 10) || 0 }); } }),
                        el(wp.components.RangeControl, { label: __('Max items'), value: attrs.limit, onChange: function (v) { setAttrs({ limit: v }); }, min: 1, max: 24 })
                    )
                )
            );
        },
        save: function () { return null; },
    });
})(window.wp);
