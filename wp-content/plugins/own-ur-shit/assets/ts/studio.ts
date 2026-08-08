/**
 * BH_Studio's canvas — no build step at runtime, on purpose (see
 * class-studio.php's own docblock for the reasoning). Written as plain
 * wp.element.createElement calls against the same @wordpress/* packages
 * the block editor/Site Editor already ship inside WordPress core,
 * enqueued as ordinary script handles — nothing here is bundled or
 * fetched from anywhere outside this plugin.
 *
 * Version-sensitivity note, left in place on purpose (not verified
 * against a live install this session — no PHP/MySQL/WordPress
 * execution capability available, see HANDOFF-PROMPT-v25.md Step 0):
 * a couple of block-editor exports used below (ListView in particular)
 * moved/were marked experimental across different WordPress core
 * versions. Every such usage below is feature-detected (typeof/in
 * checks) with a visible fallback rather than assumed present, so a
 * version mismatch degrades a panel instead of breaking the whole page
 * — confirm against the actual target WordPress version and tighten
 * once verified.
 *
 * TypeScript pilot, compiled with plain `tsc` to assets/js/studio.js.
 * wp.* types come from the shared wp-globals.d.ts; bhStudioConfig and
 * BHCoreToast below are this file's own, since neither is a wp.* API.
 */

interface BhStudioConfig {
    restUrl: string;
    contextType: string;
    contextId: string | number;
}

interface BhStudioWindow extends Window {
    bhStudioConfig?: BhStudioConfig;
    BHCoreToast?: { show(message: unknown, type?: string, durationMs?: number): unknown };
}

interface BhContentNode {
    type: string;
    attrs?: Record<string, unknown>;
    children?: BhContentNode[];
}

(function (wp: WpGlobal | undefined) {
    'use strict';
    if (!wp || !wp.element || !wp.blocks || !wp.blockEditor || !wp.components || !wp.apiFetch) {
        console.error('BH_Studio: required @wordpress/* packages are not loaded — check the script dependency list in class-studio.php.');
        return;
    }

    var element = wp.element;
    var blocks = wp.blocks;
    var blockEditor = wp.blockEditor;
    var components = wp.components;
    var apiFetch = wp.apiFetch;

    var el = element.createElement;
    var useState = element.useState;
    var useEffect = element.useEffect;
    var __ = wp.i18n ? wp.i18n.__ : function (s: string) { return s; };

    /* ---------------- block type registration ----------------
     * Mirrors class-studio.php's block_types() + BH_Content schemas
     * exactly — one source of truth in intent, two registrations in
     * practice (server for BH_Content's renderer/validator, client for
     * this editor UI), same pattern Gutenberg core blocks themselves
     * use (PHP render_callback + JS edit/save).
     *
     * supports.position is deliberately omitted (defaults to
     * unavailable) on every block below — this is the concrete
     * enforcement of "expressive control, but never absolute-positioned
     * div soup" AJ asked for: real spacing/color/typography controls are
     * available, free-form positioning is not offered as an option at
     * all, not just discouraged by convention.
     */
    var COMMON_SUPPORTS = {
        html: false,
        position: false,
        color: { background: true, text: true, link: true },
        spacing: { margin: true, padding: true },
        typography: { fontSize: true, lineHeight: true },
    };

    interface WpSaveProps<A> {
        attributes: A;
    }
    interface WpEditProps<A> {
        attributes: A;
        setAttributes(next: Partial<A>): void;
    }

    blocks.registerBlockType('bh/container', {
        apiVersion: 3,
        title: __('Container'),
        icon: 'layout',
        category: 'design',
        attributes: { className: { type: 'string', default: '' } },
        supports: Object.assign({}, COMMON_SUPPORTS),
        edit: function () {
            var blockProps = blockEditor.useBlockProps({ className: 'bh-studio-container' });
            var innerBlocksProps = blockEditor.useInnerBlocksProps(blockProps, {
                templateLock: false,
                renderAppender: blockEditor.InnerBlocks.ButtonBlockAppender,
            });
            return el('div', innerBlocksProps);
        },
        save: function () {
            var blockProps = blockEditor.useBlockProps.save({ className: 'bh-studio-container' });
            var innerBlocksProps = blockEditor.useInnerBlocksProps.save(blockProps);
            return el('div', innerBlocksProps);
        },
    });

    interface HeadingAttrs { content: string; level: number; }

    blocks.registerBlockType('bh/heading', {
        apiVersion: 3,
        title: __('Heading'),
        icon: 'heading',
        category: 'text',
        attributes: {
            content: { type: 'string', source: 'html', selector: 'h1,h2,h3,h4,h5,h6', default: '' },
            level: { type: 'number', default: 2 },
        },
        supports: Object.assign({}, COMMON_SUPPORTS),
        edit: function (props: WpEditProps<HeadingAttrs>) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var tag = 'h' + (attrs.level || 2);
            var blockProps = blockEditor.useBlockProps();
            return el(element.Fragment, {},
                el(blockEditor.BlockControls, {},
                    el(components.ToolbarGroup, {},
                        [1, 2, 3, 4, 5, 6].map(function (lvl) {
                            return el(components.ToolbarButton, {
                                key: lvl,
                                isPressed: attrs.level === lvl,
                                onClick: function () { setAttrs({ level: lvl }); },
                            }, 'H' + lvl);
                        })
                    )
                ),
                el(blockEditor.RichText, Object.assign({}, blockProps, {
                    tagName: tag,
                    value: attrs.content,
                    onChange: function (content: string) { setAttrs({ content: content }); },
                    placeholder: __('Heading text…'),
                }))
            );
        },
        save: function (props: WpSaveProps<HeadingAttrs>) {
            var attrs = props.attributes;
            var tag = 'h' + (attrs.level || 2);
            var blockProps = blockEditor.useBlockProps.save();
            return el(blockEditor.RichText.Content, Object.assign({}, blockProps, { tagName: tag, value: attrs.content }));
        },
    });

    interface TextAttrs { content: string; }

    blocks.registerBlockType('bh/text', {
        apiVersion: 3,
        title: __('Text'),
        icon: 'text',
        category: 'text',
        attributes: { content: { type: 'string', source: 'html', selector: 'p', default: '' } },
        supports: Object.assign({}, COMMON_SUPPORTS),
        edit: function (props: WpEditProps<TextAttrs>) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = blockEditor.useBlockProps();
            return el(blockEditor.RichText, Object.assign({}, blockProps, {
                tagName: 'p',
                value: attrs.content,
                onChange: function (content: string) { setAttrs({ content: content }); },
                placeholder: __('Start writing…'),
            }));
        },
        save: function (props: WpSaveProps<TextAttrs>) {
            var blockProps = blockEditor.useBlockProps.save();
            return el(blockEditor.RichText.Content, Object.assign({}, blockProps, { tagName: 'p', value: props.attributes.content }));
        },
    });

    interface ImageAttrs { url: string; alt: string; }

    blocks.registerBlockType('bh/image', {
        apiVersion: 3,
        title: __('Image'),
        icon: 'format-image',
        category: 'media',
        attributes: {
            url: { type: 'string', default: '' },
            alt: { type: 'string', default: '' },
        },
        supports: Object.assign({}, COMMON_SUPPORTS, { html: false }),
        edit: function (props: WpEditProps<ImageAttrs>) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = blockEditor.useBlockProps();
            if (!attrs.url) {
                return el('div', blockProps,
                    el(blockEditor.MediaPlaceholder, {
                        onSelect: function (media: { url: string; alt?: string }) { setAttrs({ url: media.url, alt: media.alt || '' }); },
                        allowedTypes: ['image'],
                        labels: { title: __('Image') },
                    })
                );
            }
            return el('figure', blockProps,
                el('img', { src: attrs.url, alt: attrs.alt, loading: 'lazy' }),
                el(blockEditor.InspectorControls, {},
                    el(components.PanelBody, { title: __('Image settings') },
                        el(components.TextControl, {
                            label: __('Alt text'),
                            value: attrs.alt,
                            onChange: function (alt: string) { setAttrs({ alt: alt }); },
                        })
                    )
                )
            );
        },
        save: function (props: WpSaveProps<ImageAttrs>) {
            var attrs = props.attributes;
            var blockProps = blockEditor.useBlockProps.save();
            if (!attrs.url) return null;
            return el('figure', blockProps, el('img', { src: attrs.url, alt: attrs.alt, loading: 'lazy' }));
        },
    });

    interface ButtonAttrs { text: string; url: string; }

    blocks.registerBlockType('bh/button', {
        apiVersion: 3,
        title: __('Button'),
        icon: 'button',
        category: 'design',
        attributes: {
            text: { type: 'string', default: '' },
            url: { type: 'string', default: '' },
        },
        supports: Object.assign({}, COMMON_SUPPORTS),
        edit: function (props: WpEditProps<ButtonAttrs>) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = blockEditor.useBlockProps({ className: 'bh-button' });
            return el(element.Fragment, {},
                el(blockEditor.RichText, Object.assign({}, blockProps, {
                    tagName: 'a',
                    value: attrs.text,
                    onChange: function (text: string) { setAttrs({ text: text }); },
                    placeholder: __('Button text'),
                    allowedFormats: [],
                })),
                el(blockEditor.InspectorControls, {},
                    el(components.PanelBody, { title: __('Link') },
                        el(components.TextControl, {
                            label: __('URL'),
                            value: attrs.url,
                            onChange: function (url: string) { setAttrs({ url: url }); },
                        })
                    )
                )
            );
        },
        save: function (props: WpSaveProps<ButtonAttrs>) {
            var attrs = props.attributes;
            var blockProps = blockEditor.useBlockProps.save({ className: 'bh-button' });
            return el(blockEditor.RichText.Content, Object.assign({}, blockProps, { tagName: 'a', href: attrs.url, value: attrs.text }));
        },
    });

    /* ---------------- BH_Content tree <-> wp.blocks conversion ----------------
     * BH_Content's tree shape (type/attrs/children) and wp.blocks' own
     * block object shape (name/attributes/innerBlocks) are structurally
     * identical by design (see class-content.php's own docblock on this)
     * — this conversion is a straight rename, not a real transform,
     * which is the entire point of building BH_Studio on top of
     * Gutenberg's block model rather than a second, incompatible one.
     */
    function treeToBlocks(tree: BhContentNode[] | undefined): WpBlockInstance[] {
        return (tree || []).map(function (node) {
            return blocks.createBlock(node.type, node.attrs || {}, treeToBlocks(node.children || []));
        });
    }

    function blocksToTree(list: WpBlockInstance[] | undefined): BhContentNode[] {
        return (list || []).map(function (block) {
            return { type: block.name, attrs: block.attributes || {}, children: blocksToTree(block.innerBlocks || []) };
        });
    }

    /* ---------------- the app ---------------- */

    function BHStudioApp() {
        var state = useState<WpBlockInstance[]>([]);
        var blockList = state[0], setBlocks = state[1];
        var loadingState = useState<boolean>(true);
        var loading = loadingState[0], setLoading = loadingState[1];
        var savingState = useState<boolean>(false);
        var saving = savingState[0], setSaving = savingState[1];
        var savedState = useState<Date | null>(null);
        var lastSaved = savedState[0], setLastSaved = savedState[1];

        // wp_localize_script always provides this before studio.js runs
        // (class-studio.php) — asserted non-null rather than re-checked,
        // matching the original JS's own assumption.
        var bhStudioConfig = (window as BhStudioWindow).bhStudioConfig!;
        var endpoint = bhStudioConfig.restUrl + encodeURIComponent(bhStudioConfig.contextType) + '/' + encodeURIComponent(String(bhStudioConfig.contextId));

        useEffect(function () {
            (apiFetch({ path: endpoint.replace(/^.*\/wp-json/, '') }) as Promise<{ tree: BhContentNode[] }>).then(function (res) {
                setBlocks(treeToBlocks(res.tree));
                setLoading(false);
            }).catch(function (err) {
                console.error('BH_Studio: failed to load content', err);
                setLoading(false);
            });
        }, []);

        function handleSave() {
            setSaving(true);
            (apiFetch({
                path: endpoint.replace(/^.*\/wp-json/, ''),
                method: 'POST',
                data: { tree: blocksToTree(blockList) },
            }) as Promise<unknown>).then(function () {
                setSaving(false);
                setLastSaved(new Date());
            }).catch(function (err: unknown) {
                setSaving(false);
                // Previously this was console-only — the button just
                // reverted to "Save" with nothing telling the actual
                // user their edits weren't persisted.
                var errObj = err as { message?: string } | undefined;
                var msg = (errObj && errObj.message) || 'Could not save — check your connection and try again.';
                var toastApi = (window as BhStudioWindow).BHCoreToast;
                if (toastApi) { toastApi.show(msg, 'error'); } else { alert(msg); }
            });
        }

        if (loading) {
            return el(components.Spinner);
        }

        // ListView (the layers/nested-tree panel) — export name/shape has
        // moved across WordPress versions; feature-detected rather than
        // assumed, per this file's own version-sensitivity note at top.
        var ListViewComponent = blockEditor.ListView || blockEditor.__experimentalListView || null;

        return el(blockEditor.BlockEditorProvider, {
            value: blockList,
            onInput: function (updated: WpBlockInstance[]) { setBlocks(updated); },
            onChange: function (updated: WpBlockInstance[]) { setBlocks(updated); },
            settings: {
                hasFixedToolbar: true,
                // Explicitly no custom-position/absolute-drag capability
                // surfaced to the canvas — the same enforcement point as
                // each block's own supports.position:false above, applied
                // ecosystem-wide for anything mounted on this canvas.
                enableCustomUnits: false,
            },
        },
            el('div', { className: 'bh-studio-toolbar' },
                el(components.Button, { variant: 'primary', isBusy: saving, disabled: saving, onClick: handleSave }, saving ? __('Saving…') : __('Save')),
                lastSaved ? el('span', { className: 'bh-studio-saved-at' }, __('Saved') + ' ' + lastSaved.toLocaleTimeString()) : null,
                el(blockEditor.Inserter, { position: 'bottom right' })
            ),
            el('div', { className: 'bh-studio-body' },
                el('div', { className: 'bh-studio-layers' },
                    el('h3', {}, __('Layers')),
                    ListViewComponent
                        ? el(ListViewComponent, {})
                        : el('p', { className: 'description' }, __('Layer tree unavailable — ListView export not found on wp.blockEditor for this WordPress version. Canvas and inspector below are unaffected.'))
                ),
                el('div', { className: 'bh-studio-canvas' },
                    el(blockEditor.BlockTools, {},
                        el(blockEditor.WritingFlow, {},
                            el(blockEditor.ObserveTyping, {},
                                el(blockEditor.BlockList, {})
                            )
                        )
                    )
                ),
                el('div', { className: 'bh-studio-inspector' },
                    el('h3', {}, __('Block settings')),
                    el(blockEditor.BlockInspector, {})
                )
            ),
            el(components.Popover.Slot)
        );
    }

    element.render(el(BHStudioApp), document.getElementById('bh-studio-root'));
})(window.wp);
