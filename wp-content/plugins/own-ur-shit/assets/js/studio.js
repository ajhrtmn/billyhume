/**
 * BH_Studio's canvas — no build step, on purpose (see class-studio.php's
 * own docblock for the reasoning). Written as plain wp.element.createElement
 * calls against the same @wordpress/* packages the block editor/Site
 * Editor already ship inside WordPress core, enqueued as ordinary script
 * handles — nothing here is compiled, bundled, or fetched from anywhere
 * outside this plugin.
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
 */
(function (wp) {
    'use strict';
    if (!wp || !wp.element || !wp.blocks || !wp.blockEditor) {
        console.error('BH_Studio: required @wordpress/* packages are not loaded — check the script dependency list in class-studio.php.');
        return;
    }

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var __ = wp.i18n ? wp.i18n.__ : function (s) { return s; };

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

    wp.blocks.registerBlockType('bh/container', {
        apiVersion: 3,
        title: __('Container'),
        icon: 'layout',
        category: 'design',
        attributes: { className: { type: 'string', default: '' } },
        supports: Object.assign({}, COMMON_SUPPORTS),
        edit: function (props) {
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bh-studio-container' });
            var innerBlocksProps = wp.blockEditor.useInnerBlocksProps(blockProps, {
                templateLock: false,
                renderAppender: wp.blockEditor.InnerBlocks.ButtonBlockAppender,
            });
            return el('div', innerBlocksProps);
        },
        save: function () {
            var blockProps = wp.blockEditor.useBlockProps.save({ className: 'bh-studio-container' });
            var innerBlocksProps = wp.blockEditor.useInnerBlocksProps.save(blockProps);
            return el('div', innerBlocksProps);
        },
    });

    wp.blocks.registerBlockType('bh/heading', {
        apiVersion: 3,
        title: __('Heading'),
        icon: 'heading',
        category: 'text',
        attributes: {
            content: { type: 'string', source: 'html', selector: 'h1,h2,h3,h4,h5,h6', default: '' },
            level: { type: 'number', default: 2 },
        },
        supports: Object.assign({}, COMMON_SUPPORTS),
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var tag = 'h' + (attrs.level || 2);
            var blockProps = wp.blockEditor.useBlockProps();
            return el(wp.element.Fragment, {},
                el(wp.blockEditor.BlockControls, {},
                    el(wp.components.ToolbarGroup, {},
                        [1, 2, 3, 4, 5, 6].map(function (lvl) {
                            return el(wp.components.ToolbarButton, {
                                key: lvl,
                                isPressed: attrs.level === lvl,
                                onClick: function () { setAttrs({ level: lvl }); },
                            }, 'H' + lvl);
                        })
                    )
                ),
                el(wp.blockEditor.RichText, Object.assign({}, blockProps, {
                    tagName: tag,
                    value: attrs.content,
                    onChange: function (content) { setAttrs({ content: content }); },
                    placeholder: __('Heading text…'),
                }))
            );
        },
        save: function (props) {
            var attrs = props.attributes;
            var tag = 'h' + (attrs.level || 2);
            var blockProps = wp.blockEditor.useBlockProps.save();
            return el(wp.blockEditor.RichText.Content, Object.assign({}, blockProps, { tagName: tag, value: attrs.content }));
        },
    });

    wp.blocks.registerBlockType('bh/text', {
        apiVersion: 3,
        title: __('Text'),
        icon: 'text',
        category: 'text',
        attributes: { content: { type: 'string', source: 'html', selector: 'p', default: '' } },
        supports: Object.assign({}, COMMON_SUPPORTS),
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps();
            return el(wp.blockEditor.RichText, Object.assign({}, blockProps, {
                tagName: 'p',
                value: attrs.content,
                onChange: function (content) { setAttrs({ content: content }); },
                placeholder: __('Start writing…'),
            }));
        },
        save: function (props) {
            var blockProps = wp.blockEditor.useBlockProps.save();
            return el(wp.blockEditor.RichText.Content, Object.assign({}, blockProps, { tagName: 'p', value: props.attributes.content }));
        },
    });

    wp.blocks.registerBlockType('bh/image', {
        apiVersion: 3,
        title: __('Image'),
        icon: 'format-image',
        category: 'media',
        attributes: {
            url: { type: 'string', default: '' },
            alt: { type: 'string', default: '' },
        },
        supports: Object.assign({}, COMMON_SUPPORTS, { html: false }),
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps();
            if (!attrs.url) {
                return el('div', blockProps,
                    el(wp.blockEditor.MediaPlaceholder, {
                        onSelect: function (media) { setAttrs({ url: media.url, alt: media.alt || '' }); },
                        allowedTypes: ['image'],
                        labels: { title: __('Image') },
                    })
                );
            }
            return el('figure', blockProps,
                el('img', { src: attrs.url, alt: attrs.alt, loading: 'lazy' }),
                el(wp.blockEditor.InspectorControls, {},
                    el(wp.components.PanelBody, { title: __('Image settings') },
                        el(wp.components.TextControl, {
                            label: __('Alt text'),
                            value: attrs.alt,
                            onChange: function (alt) { setAttrs({ alt: alt }); },
                        })
                    )
                )
            );
        },
        save: function (props) {
            var attrs = props.attributes;
            var blockProps = wp.blockEditor.useBlockProps.save();
            if (!attrs.url) return null;
            return el('figure', blockProps, el('img', { src: attrs.url, alt: attrs.alt, loading: 'lazy' }));
        },
    });

    wp.blocks.registerBlockType('bh/button', {
        apiVersion: 3,
        title: __('Button'),
        icon: 'button',
        category: 'design',
        attributes: {
            text: { type: 'string', default: '' },
            url: { type: 'string', default: '' },
        },
        supports: Object.assign({}, COMMON_SUPPORTS),
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bh-button' });
            return el(wp.element.Fragment, {},
                el(wp.blockEditor.RichText, Object.assign({}, blockProps, {
                    tagName: 'a',
                    value: attrs.text,
                    onChange: function (text) { setAttrs({ text: text }); },
                    placeholder: __('Button text'),
                    allowedFormats: [],
                })),
                el(wp.blockEditor.InspectorControls, {},
                    el(wp.components.PanelBody, { title: __('Link') },
                        el(wp.components.TextControl, {
                            label: __('URL'),
                            value: attrs.url,
                            onChange: function (url) { setAttrs({ url: url }); },
                        })
                    )
                )
            );
        },
        save: function (props) {
            var attrs = props.attributes;
            var blockProps = wp.blockEditor.useBlockProps.save({ className: 'bh-button' });
            return el(wp.blockEditor.RichText.Content, Object.assign({}, blockProps, { tagName: 'a', href: attrs.url, value: attrs.text }));
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
    function treeToBlocks(tree) {
        return (tree || []).map(function (node) {
            return wp.blocks.createBlock(node.type, node.attrs || {}, treeToBlocks(node.children || []));
        });
    }

    function blocksToTree(blocks) {
        return (blocks || []).map(function (block) {
            return { type: block.name, attrs: block.attributes || {}, children: blocksToTree(block.innerBlocks || []) };
        });
    }

    /* ---------------- the app ---------------- */

    function BHStudioApp() {
        var state = useState([]);
        var blocks = state[0], setBlocks = state[1];
        var loadingState = useState(true);
        var loading = loadingState[0], setLoading = loadingState[1];
        var savingState = useState(false);
        var saving = savingState[0], setSaving = savingState[1];
        var savedState = useState(null);
        var lastSaved = savedState[0], setLastSaved = savedState[1];

        var endpoint = bhStudioConfig.restUrl + encodeURIComponent(bhStudioConfig.contextType) + '/' + encodeURIComponent(bhStudioConfig.contextId);

        useEffect(function () {
            wp.apiFetch({ path: endpoint.replace(/^.*\/wp-json/, '') }).then(function (res) {
                setBlocks(treeToBlocks(res.tree));
                setLoading(false);
            }).catch(function (err) {
                console.error('BH_Studio: failed to load content', err);
                setLoading(false);
            });
        }, []);

        function handleSave() {
            setSaving(true);
            wp.apiFetch({
                path: endpoint.replace(/^.*\/wp-json/, ''),
                method: 'POST',
                data: { tree: blocksToTree(blocks) },
            }).then(function () {
                setSaving(false);
                setLastSaved(new Date());
            }).catch(function (err) {
                console.error('BH_Studio: save failed', err);
                setSaving(false);
            });
        }

        if (loading) {
            return el(wp.components.Spinner);
        }

        // ListView (the layers/nested-tree panel) — export name/shape has
        // moved across WordPress versions; feature-detected rather than
        // assumed, per this file's own version-sensitivity note at top.
        var ListViewComponent = wp.blockEditor.ListView || (wp.blockEditor.__experimentalListView) || null;

        return el(wp.blockEditor.BlockEditorProvider, {
            value: blocks,
            onInput: function (updated) { setBlocks(updated); },
            onChange: function (updated) { setBlocks(updated); },
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
                el(wp.components.Button, { variant: 'primary', isBusy: saving, disabled: saving, onClick: handleSave }, saving ? __('Saving…') : __('Save')),
                lastSaved ? el('span', { className: 'bh-studio-saved-at' }, __('Saved') + ' ' + lastSaved.toLocaleTimeString()) : null,
                el(wp.blockEditor.Inserter, { position: 'bottom right' })
            ),
            el('div', { className: 'bh-studio-body' },
                el('div', { className: 'bh-studio-layers' },
                    el('h3', {}, __('Layers')),
                    ListViewComponent
                        ? el(ListViewComponent, {})
                        : el('p', { className: 'description' }, __('Layer tree unavailable — ListView export not found on wp.blockEditor for this WordPress version. Canvas and inspector below are unaffected.'))
                ),
                el('div', { className: 'bh-studio-canvas' },
                    el(wp.blockEditor.BlockTools, {},
                        el(wp.blockEditor.WritingFlow, {},
                            el(wp.blockEditor.ObserveTyping, {},
                                el(wp.blockEditor.BlockList, {})
                            )
                        )
                    )
                ),
                el('div', { className: 'bh-studio-inspector' },
                    el('h3', {}, __('Block settings')),
                    el(wp.blockEditor.BlockInspector, {})
                )
            ),
            el(wp.components.Popover.Slot)
        );
    }

    wp.element.render(el(BHStudioApp), document.getElementById('bh-studio-root'));
})(window.wp);
