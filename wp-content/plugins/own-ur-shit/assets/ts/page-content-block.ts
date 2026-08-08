/**
 * "Page Content (Design Suite)" — one bridging Gutenberg block, not a
 * full per-BH_Element-type block set (see class-page-surface.php's own
 * docblock for why: the old visual tree/canvas builder was deliberately
 * deleted in an earlier session in favor of the block editor, and
 * rebuilding a whole parallel block-per-element-type system is real,
 * separate, much bigger scope than this one bridging block).
 *
 * Insertable anywhere in the normal block editor, like any other block
 * — a plain rich-text area whose value IS the authoritative content an
 * author sees/edits here. On save, OUS_PageSurface::sync_page_content_
 * block() (PHP) reads this block's own attribute back out of the saved
 * post_content and upserts a single bh/note placement in the bh_page/
 * root slot to match — the front end then renders through the SAME
 * render_slot() call every other Design Suite surface already uses,
 * "under the hood," not a second rendering path.
 *
 * TypeScript pilot, compiled with plain `tsc` to assets/js/page-content-
 * block.js. The `WpGlobal` interface below is a deliberately minimal,
 * hand-written shape covering only the wp.* surface this file actually
 * touches — NOT a full @wordpress/* type package (none is installed;
 * see search.ts's docblock for why this ecosystem's TS pilot stays on
 * plain `tsc` with no additional npm dependencies beyond typescript
 * itself). Widen this interface if a future edit here needs more of
 * wp.blockEditor/wp.components — don't reach for @types/wordpress__*
 * without a deliberate decision, same posture as the "no bundler"
 * convention.
 */

interface WpBlockEditProps<A> {
    attributes: A;
    setAttributes(next: Partial<A>): void;
}

interface WpPageContentAttributes {
    content: string;
}

interface WpGlobal {
    blocks?: {
        registerBlockType(name: string, settings: Record<string, unknown>): void;
    };
    element?: {
        createElement(type: unknown, props: Record<string, unknown> | null, ...children: unknown[]): unknown;
    };
    blockEditor?: {
        useBlockProps(props?: Record<string, unknown>): Record<string, unknown>;
        RichText: unknown;
    };
    components?: unknown;
    i18n?: {
        __(text: string): string;
    };
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
                } as unknown as Record<string, unknown>)
            );
        },
        // Dynamic block — save() persists only the attribute (for the
        // PHP-side sync on post save), never markup; the actual front-end
        // render always comes from render_callback (PHP), which reads the
        // CURRENT placement state via render_slot(), not this saved value.
        save: function () { return null; },
    });
})((window as unknown as { wp?: WpGlobal }).wp);
