/**
 * BH_Studio's canvas: plain wp.element.createElement against the
 * @wordpress/* packages core already ships. Block-editor exports that moved
 * across WordPress versions (ListView) are feature-detected with a fallback.
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
     * Mirrors class-studio.php's block_types(): server registers for
     * BH_Content's renderer/validator, client for the editor UI.
     * WHY: supports.position is omitted on every block so free-form absolute
     * positioning is never offered.
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
     * The shapes are structurally identical (type/attrs/children vs
     * name/attributes/innerBlocks), so this is a straight rename.
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

        // wp_localize_script always provides this before studio.js runs.
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
                // Surface the failure to the user, not just the console.
                var errObj = err as { message?: string } | undefined;
                var msg = (errObj && errObj.message) || 'Could not save — check your connection and try again.';
                var toastApi = (window as BhStudioWindow).BHCoreToast;
                if (toastApi) { toastApi.show(msg, 'error'); } else { alert(msg); }
            });
        }

        if (loading) {
            return el(components.Spinner);
        }

        // ListView's export name varies across WordPress versions; feature-detected.
        var ListViewComponent = blockEditor.ListView || blockEditor.__experimentalListView || null;

        return el(blockEditor.BlockEditorProvider, {
            value: blockList,
            onInput: function (updated: WpBlockInstance[]) { setBlocks(updated); },
            onChange: function (updated: WpBlockInstance[]) { setBlocks(updated); },
            settings: {
                hasFixedToolbar: true,
                // No custom-position/absolute-drag on this canvas.
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
