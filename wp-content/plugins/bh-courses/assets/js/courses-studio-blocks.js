/**
 * Registers bhc/text, bhc/image, bhc/video, bhc/quiz, and
 * bhc/quiz-question — real wp.blocks.registerBlockType() blocks (real
 * attributes schema, real edit()/save()), same no-build-step,
 * wp.element.createElement-only convention every admin/editor script in
 * this ecosystem uses.
 *
 * Loaded on the REAL bh_lesson block-editor screen (see
 * BHC_ContentBridge::maybe_enqueue_lesson_blocks()) — a lesson's steps
 * are authored directly there now, same screen as any page, not a
 * separate BH_Studio canvas. Nothing in this file needed to change for
 * that migration; these were already ordinary Gutenberg blocks, only
 * ever enqueued in the wrong place. This is the client half of a pair
 * — BHC_ContentBridge::register_block_types() already registers the
 * server-side schema/renderer for every type here (used when
 * BH_Content reads the tree back out of post_content); this file is
 * what actually makes them appear in the editor's inserter and gives
 * them an editing UI. See LMS-AUTHORING-DESIGN-PLAN.md Section 5 for
 * the original "what was missing and why" writeup.
 *
 * bhc/quiz is a CONTAINER block (InnerBlocks, allowedBlocks restricted
 * to bhc/quiz-question) rather than storing questions as an attribute
 * array — see class-content-bridge.php's own docblock on bhc/quiz for
 * why (Design Plan Section 3.2: real child blocks are what makes a
 * future table-view toggle possible over the same tree the canvas
 * edits, and what makes "reorder 40 questions" an ordinary array
 * reorder). bhc/quiz-question is never inserted anywhere except inside
 * a bhc/quiz — enforced by parent: ['bhc/quiz'] below, so it can't
 * accidentally end up as a stray top-level lesson step (which
 * BHC_ContentBridge::tree_to_steps() would just skip anyway, but
 * restricting it in the inserter is the honest place to prevent that
 * confusion in the first place).
 */
(function (wp) {
    'use strict';
    if (!wp || !wp.blocks || !wp.element || !wp.blockEditor || !wp.components) return;

    var el = wp.element.createElement;
    var __ = wp.i18n ? wp.i18n.__ : function (s) { return s; };

    // Same COMMON_SUPPORTS posture as studio.js's own default block set —
    // no absolute positioning, no raw-HTML editing escape hatch.
    var SUPPORTS = { html: false, position: false, spacing: { margin: true, padding: true }, color: { background: true, text: true } };

    wp.blocks.registerBlockType('bhc/text', {
        apiVersion: 3,
        title: __('Lesson: Text'),
        icon: 'text',
        category: 'lms',
        // Plain (non-HTML-sourced) attribute, matching bhc/image's
        // caption / bhc/video's caption / bhc/quiz-question's question —
        // deliberately NOT `source: 'html', selector: 'div'` (extract
        // from the block's own rendered markup at parse time). That's a
        // client-side (editor) concept only — parse_blocks() (PHP,
        // BH_Content::get('post', ...)'s own read path) never runs
        // attribute sourcing, so `content` came back empty every time a
        // lesson was read back for _bhc_steps sync, confirmed live via
        // direct DB inspection this pass. RichText works identically
        // bound to a plain attribute; only the storage shape changes.
        attributes: { content: { type: 'string', default: '' } },
        supports: Object.assign({}, SUPPORTS),
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-text' });
            return el(wp.blockEditor.RichText, Object.assign({}, blockProps, {
                tagName: 'div',
                value: attrs.content,
                onChange: function (v) { setAttrs({ content: v }); },
                placeholder: __('Lesson text…'),
            }));
        },
        save: function (props) {
            var blockProps = wp.blockEditor.useBlockProps.save();
            return el(wp.blockEditor.RichText.Content, Object.assign({}, blockProps, { tagName: 'div', value: props.attributes.content }));
        },
    });

    wp.blocks.registerBlockType('bhc/image', {
        apiVersion: 3,
        title: __('Lesson: Image'),
        icon: 'format-image',
        category: 'lms',
        attributes: {
            attachment_ids: { type: 'array', default: [] },
            caption: { type: 'string', default: '' },
        },
        supports: { html: false },
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-image' });
            var thumbs = (attrs.attachment_ids || []).map(function (id) {
                return el('span', { key: id, className: 'bhc-studio-image-thumb' }, '#' + id);
            });
            return el('div', blockProps,
                el(wp.blockEditor.MediaUploadCheck, {},
                    el(wp.blockEditor.MediaUpload, {
                        multiple: true,
                        allowedTypes: ['image'],
                        value: attrs.attachment_ids,
                        onSelect: function (media) { setAttrs({ attachment_ids: (media || []).map(function (m) { return m.id; }) }); },
                        render: function (obj) {
                            return el(wp.components.Button, { variant: 'secondary', onClick: obj.open }, attrs.attachment_ids.length ? __('Change image(s)') : __('Select image(s)'));
                        },
                    })
                ),
                thumbs.length ? el('div', { className: 'bhc-studio-image-thumbs' }, thumbs) : null,
                el(wp.components.TextControl, { label: __('Caption'), value: attrs.caption, onChange: function (v) { setAttrs({ caption: v }); } })
            );
        },
        save: function () { return null; }, // dynamic — server renderer is BHC_ContentBridge's bhc/image callback
    });

    wp.blocks.registerBlockType('bhc/video', {
        apiVersion: 3,
        title: __('Lesson: Video'),
        icon: 'format-video',
        category: 'lms',
        attributes: {
            source: { type: 'string', default: 'upload' },
            attachment_id: { type: 'number', default: 0 },
            video_url: { type: 'string', default: '' },
            caption: { type: 'string', default: '' },
            // ROADMAP-ux-polish-and-feature-parity-2026-07.md 4b — 0
            // means "any playback marks it complete" (the pre-existing
            // Mark-complete-button behavior, unchanged), matching
            // bhc/quiz's own max_attempts "0 = unlimited" convention.
            watch_threshold: { type: 'number', default: 0 },
            // Client-side-only convenience flag (BHC_VideoSettings'
            // own docblock) — set when a picked file exceeds the
            // configured direct-upload limit, purely to render the
            // inline warning below; never read server-side (the
            // authoritative check re-derives file size fresh from the
            // real attachment on every save instead of trusting this).
            over_limit_mb: { type: 'number', default: 0 },
        },
        supports: { html: false },
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-video' });
            return el('div', blockProps,
                el(wp.components.SelectControl, {
                    label: __('Source'),
                    value: attrs.source,
                    options: [{ label: __('Uploaded file'), value: 'upload' }, { label: __('URL (oEmbed)'), value: 'url' }],
                    onChange: function (v) { setAttrs({ source: v }); },
                }),
                attrs.source === 'url'
                    ? el(wp.components.TextControl, { label: __('Video URL'), value: attrs.video_url, onChange: function (v) { setAttrs({ video_url: v }); } })
                    : el(wp.blockEditor.MediaUploadCheck, {},
                        el(wp.blockEditor.MediaUpload, {
                            allowedTypes: ['video'],
                            value: attrs.attachment_id,
                            // Immediate, cheap feedback before an author
                            // even finishes — BHC_VideoSettings::check_tree()
                            // still re-checks the real file server-side on
                            // save (authoritative; this is a convenience
                            // only, a REST/programmatic save never runs
                            // this JS at all). window.BHCMaxVideoMB is
                            // localized per-request (0 = no limit, the
                            // default — never blocks anything here).
                            // Deliberately no window.confirm()/alert() —
                            // a blocking native dialog here is a known
                            // hazard in this ecosystem's own automated
                            // QA tooling (and a worse UX generally); the
                            // file is always accepted, with a plain
                            // inline warning shown instead via
                            // over_limit_mb state.
                            onSelect: function (media) {
                                var maxMB = window.BHCMaxVideoMB || 0;
                                var sizeBytes = media.filesizeInBytes || (media.filesize ? media.filesize * 1024 : 0);
                                var overLimit = maxMB && sizeBytes && (sizeBytes / 1048576) > maxMB;
                                setAttrs({ attachment_id: media.id, over_limit_mb: overLimit ? Math.round(sizeBytes / 1048576) : 0 });
                            },
                            render: function (obj) {
                                return el(wp.components.Button, { variant: 'secondary', onClick: obj.open }, attrs.attachment_id ? __('Change video') : __('Select video'));
                            },
                        }),
                        attrs.over_limit_mb
                            ? el('p', { className: 'bhc-video-size-warning', style: { color: '#b32d2e' } },
                                __('This file is about ') + attrs.over_limit_mb + __('MB, over this site\'s ') + (window.BHCMaxVideoMB || 0) + __('MB direct-upload limit. Consider switching Source to "URL (oEmbed)" instead.'))
                            : null
                    ),
                el(wp.components.TextControl, { label: __('Caption'), value: attrs.caption, onChange: function (v) { setAttrs({ caption: v }); } }),
                el(wp.components.RangeControl, {
                    label: __('Require watched % to auto-complete (0 = off, any playback completes it)'),
                    value: attrs.watch_threshold,
                    min: 0,
                    max: 100,
                    onChange: function (v) { setAttrs({ watch_threshold: v || 0 }); },
                    help: attrs.source === 'url' ? __('Only enforceable for a direct video URL — a YouTube/Vimeo-style embed can\'t be watch-tracked, so this is ignored for iframe embeds.') : undefined,
                })
            );
        },
        save: function () { return null; }, // dynamic
    });

    // ROADMAP-ux-polish-and-feature-parity-2026-07.md 4c — a
    // downloadable file (worksheet, PDF, reference doc) attached to a
    // step. allowedTypes deliberately omitted from MediaUpload below
    // (unlike bhc/image's ['image']/bhc/video's ['video']) — a resource
    // is meant to accept any file type a course creator might want to
    // hand a student, not a narrowed media category.
    wp.blocks.registerBlockType('bhc/resource', {
        apiVersion: 3,
        title: __('Lesson: Resource'),
        icon: 'media-default',
        category: 'lms',
        attributes: {
            attachment_id: { type: 'number', default: 0 },
            label: { type: 'string', default: '' },
            description: { type: 'string', default: '' },
        },
        supports: { html: false },
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-resource' });
            return el('div', blockProps,
                el(wp.blockEditor.MediaUploadCheck, {},
                    el(wp.blockEditor.MediaUpload, {
                        value: attrs.attachment_id,
                        onSelect: function (media) {
                            // Default the label to the file's own name on
                            // first pick — a course creator can still
                            // override it, but "Worksheet.pdf" beats an
                            // empty label if they never touch this field.
                            setAttrs({ attachment_id: media.id, label: attrs.label || media.title || media.filename || '' });
                        },
                        render: function (obj) {
                            return el(wp.components.Button, { variant: 'secondary', onClick: obj.open }, attrs.attachment_id ? __('Change file') : __('Select file'));
                        },
                    })
                ),
                attrs.attachment_id ? el('p', { className: 'bhc-studio-resource-selected' }, __('File selected (#') + attrs.attachment_id + ')') : null,
                el(wp.components.TextControl, { label: __('Label'), value: attrs.label, placeholder: __('e.g. Worksheet.pdf'), onChange: function (v) { setAttrs({ label: v }); } }),
                el(wp.components.TextControl, { label: __('Description (optional)'), value: attrs.description, onChange: function (v) { setAttrs({ description: v }); } })
            );
        },
        save: function () { return null; }, // dynamic — server renderer is BHC_ContentBridge's bhc/resource callback
    });

    wp.blocks.registerBlockType('bhc/quiz', {
        apiVersion: 3,
        title: __('Lesson: Quiz'),
        icon: 'forms',
        category: 'lms',
        attributes: {
            passing_score: { type: 'number', default: 70 },
            max_attempts: { type: 'number', default: 0 },
            shuffle_questions: { type: 'boolean', default: false },
            shuffle_choices: { type: 'boolean', default: false },
        },
        supports: { html: false },
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-quiz' });
            // Real child blocks, not a repeater — this is the whole
            // point of Section 3.2's promotion. allowedBlocks keeps
            // quiz-question scoped to living only inside a quiz.
            var innerBlocksProps = wp.blockEditor.useInnerBlocksProps(blockProps, {
                allowedBlocks: ['bhc/quiz-question'],
                templateLock: false,
                renderAppender: wp.blockEditor.InnerBlocks.ButtonBlockAppender,
            });
            return el(wp.element.Fragment, {},
                el(wp.blockEditor.InspectorControls, {},
                    el(wp.components.PanelBody, { title: __('Quiz settings') },
                        el(wp.components.RangeControl, { label: __('Passing score (%)'), value: attrs.passing_score, onChange: function (v) { setAttrs({ passing_score: v }); }, min: 0, max: 100 }),
                        el(wp.components.RangeControl, { label: __('Max attempts (0 = unlimited)'), value: attrs.max_attempts, onChange: function (v) { setAttrs({ max_attempts: v }); }, min: 0, max: 20 }),
                        el(wp.components.ToggleControl, { label: __('Shuffle question order'), checked: attrs.shuffle_questions, onChange: function (v) { setAttrs({ shuffle_questions: v }); } }),
                        el(wp.components.ToggleControl, { label: __('Shuffle answer order'), checked: attrs.shuffle_choices, onChange: function (v) { setAttrs({ shuffle_choices: v }); } })
                    )
                ),
                el('div', innerBlocksProps)
            );
        },
        save: function () {
            // NOT dynamic at the container level — innerBlocks (the
            // actual questions) need to serialize as real child blocks
            // in the tree so BH_Content::validate()/render() see them.
            var blockProps = wp.blockEditor.useBlockProps.save();
            var innerBlocksProps = wp.blockEditor.useInnerBlocksProps.save(blockProps);
            return el('div', innerBlocksProps);
        },
    });

    wp.blocks.registerBlockType('bhc/quiz-question', {
        apiVersion: 3,
        title: __('Quiz Question'),
        icon: 'editor-help',
        category: 'lms',
        parent: ['bhc/quiz'],
        attributes: {
            question: { type: 'string', default: '' },
            choices: { type: 'array', default: ['', ''] },
            correct_index: { type: 'number', default: 0 },
        },
        supports: { html: false },
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-quiz-question' });
            var choices = attrs.choices && attrs.choices.length ? attrs.choices : ['', ''];

            function setChoice(i, v) {
                var next = choices.slice();
                next[i] = v;
                setAttrs({ choices: next });
            }
            function addChoice() { setAttrs({ choices: choices.concat(['']) }); }
            function removeChoice(i) {
                var next = choices.slice();
                next.splice(i, 1);
                setAttrs({ choices: next, correct_index: Math.min(attrs.correct_index, Math.max(0, next.length - 1)) });
            }

            var choiceRows = choices.map(function (c, i) {
                return el('div', { key: i, className: 'bhc-studio-choice-row' },
                    el(wp.components.RadioControl, {
                        selected: attrs.correct_index === i ? 'correct' : '',
                        options: [{ label: '', value: 'correct' }],
                        onChange: function () { setAttrs({ correct_index: i }); },
                    }),
                    el(wp.components.TextControl, { value: c, placeholder: __('Choice text'), onChange: function (v) { setChoice(i, v); } }),
                    choices.length > 2 ? el(wp.components.Button, { icon: 'no-alt', label: __('Remove choice'), onClick: function () { removeChoice(i); } }) : null
                );
            });

            return el('div', blockProps,
                el(wp.components.TextControl, { label: __('Question'), value: attrs.question, onChange: function (v) { setAttrs({ question: v }); } }),
                el('p', { className: 'description' }, __('Select the radio next to the correct choice.')),
                choiceRows,
                el(wp.components.Button, { variant: 'secondary', onClick: addChoice }, __('Add choice'))
            );
        },
        save: function () { return null; }, // dynamic
    });
})(window.wp);
