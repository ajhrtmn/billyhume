/**
 * "Page Content (Design Suite)" block: a rich-text area whose attribute is the
 * authored content. On post save, OUS_PageSurface::sync_page_content_block()
 * upserts one bh/note placement in bh_page/root; the front end renders via
 * render_slot().
 */

interface WpBlockEditProps<A> {
    attributes: A;
    setAttributes(next: Partial<A>): void;
}

interface WpPageContentAttributes {
    content: string;
}

(function (wp: WpGlobal | undefined) {
    'use strict';
    if (!wp || !wp.blocks || !wp.element || !wp.blockEditor || !wp.components) return;

    var el = wp.element.createElement;
    var __ = wp.i18n ? wp.i18n.__ : function (s: string) { return s; };
    var blockEditor = wp.blockEditor;

    wp.blocks.registerBlockType('bh/page-content', {
        apiVersion: 3,
        title: __('Page Content (Design Suite)'),
        description: __('Content rendered through the Design Suite’s shared element system, editable right here.'),
        icon: 'layout',
        category: 'design',
        attributes: {
            content: { type: 'string', default: '' },
        },
        supports: { html: false },
        edit: function (props: WpBlockEditProps<WpPageContentAttributes>) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = blockEditor.useBlockProps({ className: 'ous-page-content-block' });
            return el('div', blockProps,
                el(blockEditor.RichText, {
                    tagName: 'div',
                    className: 'ous-page-content-block-editor',
                    value: attrs.content,
                    onChange: function (v: string) { setAttrs({ content: v }); },
                    placeholder: __('Write this section’s content…'),
                    multiline: 'p',
                })
            );
        },
        // Dynamic block: only the attribute is saved; render_callback reads the
        // current placement state.
        save: function () { return null; },
    });
})(window.wp);
