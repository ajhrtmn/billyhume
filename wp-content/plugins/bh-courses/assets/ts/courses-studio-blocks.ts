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
 *
 * TypeScript pilot conversion. `wp` is typed loosely as `any` here
 * rather than with real interfaces for every wp.components/blockEditor
 * call this file makes — that surface is huge and this file's own
 * risk (per the deferred-conversion note it used to carry) is in the
 * runtime block-editor behavior, not in typos tsc would catch on a
 * fully-typed `wp`. Mechanical, near-verbatim port of the original JS;
 * `any` throughout keeps behavior identical rather than risking a
 * subtly wrong hand-authored type for an API this large.
 */
(function (wp: any) {
    'use strict';
    if (!wp || !wp.blocks || !wp.element || !wp.blockEditor || !wp.components || !wp.primitives || !wp.data) return;

    var el = wp.element.createElement;
    var __ = wp.i18n ? wp.i18n.__ : function (s: string) { return s; };

    // A minimal, self-authored "×" SVG — deliberately NOT wp.icons
    // (that package isn't loaded/exposed as a global in every WP
    // install; confirmed missing entirely here, which crashed the
    // "Remove choice" button below with nothing on screen but "This
    // block has encountered an error") and NOT the Dashicon string
    // 'no-alt' either (that one silently rendered a 0x0-sized, invisible
    // icon inside the post editor's iframed canvas — the 'dashicons'
    // stylesheet doesn't reach that iframe). A plain inline SVG has no
    // external dependency to fail either way.
    function closeIcon() {
        return el(wp.primitives.SVG, { viewBox: '0 0 24 24', width: 20, height: 20, xmlns: 'http://www.w3.org/2000/svg' },
            el(wp.primitives.Path, { d: 'M13.06 12l6.47-6.47-1.06-1.06L12 10.94 5.53 4.47 4.47 5.53 10.94 12l-6.47 6.47 1.06 1.06L12 13.06l6.47 6.47 1.06-1.06L13.06 12z' })
        );
    }

    // Replaces InnerBlocks.ButtonBlockAppender (an icon-only "+" square)
    // for bhc/quiz. That default appender rendered right next to
    // bhc/quiz-question's own "Add choice" button (a completely
    // different action, scoped to one question's answer list, not the
    // whole quiz) — two small "+"-ish controls sitting side by side with
    // nothing to tell them apart. A real text label removes the
    // ambiguity regardless of exact spacing/position. Uses
    // useBlockEditContext() to get the CURRENT block's clientId (the
    // quiz container itself, since this component only ever renders
    // inside its InnerBlocks area) and inserts a real new child block
    // at the end — the same action the default appender performed,
    // just with a clear label instead of a bare icon.
    function AddQuestionAppender() {
        var clientId = wp.blockEditor.useBlockEditContext().clientId;
        var insertBlock = wp.data.useDispatch('core/block-editor').insertBlock;
        return el(wp.components.Button, {
            variant: 'secondary',
            className: 'bhc-studio-add-question',
            onClick: function () {
                insertBlock(wp.blocks.createBlock('bhc/quiz-question'), undefined, clientId);
            },
        }, __('Add another question'));
    }

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
        edit: function (props: any) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-text' });
            return el(wp.blockEditor.RichText, Object.assign({}, blockProps, {
                tagName: 'div',
                value: attrs.content,
                onChange: function (v: any) { setAttrs({ content: v }); },
                placeholder: __('Lesson text…'),
            }));
        },
        save: function (props: any) {
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
        edit: function (props: any) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            // paddingTop: Gutenberg docks its floating block toolbar
            // INSIDE a block's own top edge, overlapping its first
            // control, whenever there's no room to float it above (the
            // case whenever this is the first/only block in a lesson —
            // the common case for a course just getting started). Real
            // authoring friction caught this session on bhc/video's
            // Source picker; this small buffer keeps every block's own
            // first control clear of that zone instead of relying on
            // there happening to be another block above it.
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-image', style: { paddingTop: '32px' } });
            var thumbs = (attrs.attachment_ids || []).map(function (id: any) {
                return el('span', { key: id, className: 'bhc-studio-image-thumb' }, '#' + id);
            });
            return el('div', blockProps,
                el(wp.blockEditor.MediaUploadCheck, {},
                    el(wp.blockEditor.MediaUpload, {
                        multiple: true,
                        allowedTypes: ['image'],
                        value: attrs.attachment_ids,
                        onSelect: function (media: any) { setAttrs({ attachment_ids: (media || []).map(function (m: any) { return m.id; }) }); },
                        render: function (obj: any) {
                            return el(wp.components.Button, { variant: 'secondary', onClick: obj.open }, attrs.attachment_ids.length ? __('Change image(s)') : __('Select image(s)'));
                        },
                    })
                ),
                thumbs.length ? el('div', { className: 'bhc-studio-image-thumbs' }, thumbs) : null,
                el(wp.components.TextControl, { label: __('Caption'), value: attrs.caption, onChange: function (v: any) { setAttrs({ caption: v }); } })
            );
        },
        save: function () { return null; }, // dynamic — server renderer is BHC_ContentBridge's bhc/image callback
    });

    wp.blocks.registerBlockType('bhc/callout', {
        apiVersion: 3,
        title: __('Lesson: Callout'),
        icon: 'megaphone',
        category: 'lms',
        attributes: {
            content: { type: 'string', default: '' },
            variant: { type: 'string', default: 'tip' },
        },
        supports: Object.assign({}, SUPPORTS),
        edit: function (props: any) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-callout bhc-callout-' + attrs.variant });
            var variantPicker = el(wp.blockEditor.InspectorControls, {},
                el(wp.components.PanelBody, { title: __('Callout settings') },
                    el(wp.components.SelectControl, {
                        label: __('Variant'),
                        value: attrs.variant,
                        options: [
                            { label: __('Tip'), value: 'tip' },
                            { label: __('Note'), value: 'note' },
                            { label: __('Warning'), value: 'warning' },
                        ],
                        onChange: function (v: any) { setAttrs({ variant: v }); },
                    })
                )
            );
            return el(wp.element.Fragment, {},
                variantPicker,
                el(wp.blockEditor.RichText, Object.assign({}, blockProps, {
                    tagName: 'div',
                    value: attrs.content,
                    onChange: function (v: any) { setAttrs({ content: v }); },
                    placeholder: __('Key idea, tip, or warning…'),
                }))
            );
        },
        save: function (props: any) {
            var blockProps = wp.blockEditor.useBlockProps.save();
            return el(wp.blockEditor.RichText.Content, Object.assign({}, blockProps, { tagName: 'div', value: props.attributes.content }));
        },
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
            // OSS-integration master plan Phase 6 follow-up — a
            // Cloudflare Stream video UID, pasted in after the admin
            // uploads to Stream themselves (see class-steps.php's own
            // comment: no in-plugin upload-to-Stream flow yet).
            stream_uid: { type: 'string', default: '' },
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
            // ROADMAP-lms-v3.md Section 1 — [{ time, type, payload }].
            annotations: { type: 'array', default: [] },
            // YouTube-style chapters — [{ time, title }]. Rendered as a
            // segmented strip + a clickable list below the video (see
            // class-render-lesson.php/courses.ts); native <video controls>
            // gives no way to draw markers ON the browser's own seek bar
            // itself, so this is a companion strip directly beneath it
            // rather than a literal overlay on the native scrubber — the
            // deliberate, scoped answer to "like YouTube" without
            // replacing native controls (fullscreen/accessibility/mobile
            // behavior all stay exactly what they already are).
            chapters: { type: 'array', default: [] },
        },
        supports: { html: false },
        edit: function (props: any) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            // See bhc/image's blockProps comment — same toolbar-collision
            // buffer, still needed here even after moving Source to the
            // Inspector: the "Select video" button / URL field is now
            // the first remaining canvas control.
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-video', style: { paddingTop: '32px' } });
            // Source picker lives in the Inspector sidebar, not inline in
            // canvas — caught live (real authoring friction, confirmed
            // only reachable via the wp.data API during testing, not the
            // UI itself): as the first rendered element in the block, it
            // sat exactly where Gutenberg's own floating block toolbar
            // renders, making the control nearly unclickable. The
            // Inspector panel is a completely separate region with no
            // toolbar overlap, the same place core blocks already put
            // this kind of block-level setting.
            // First step toward a real visual placement tool (a full
            // drag-based timeline is the natural next iteration once this
            // is proven — flagged, not built here): a live preview of the
            // actual selected video right in the block, so an author can
            // scrub/play to the moment they want and grab that exact
            // timestamp with one click instead of typing seconds blind.
            var previewUrlState = wp.element.useState('');
            var previewUrl = previewUrlState[0], setPreviewUrl = previewUrlState[1];
            var previewRef = wp.element.useRef(null);
            // The preview's live playhead, mirrored into state purely so
            // the "+ Add chapter at 0:42" button can SHOW the timestamp
            // it's about to capture. A ref alone can't do this — scrubbing
            // the video changes no React state, so the label would render
            // once and then quietly lie. Also drives the past-the-end
            // warning, which needs a real duration to compare against.
            var previewTimeState = wp.element.useState(0);
            var previewTime = previewTimeState[0], setPreviewTime = previewTimeState[1];
            var previewDurationState = wp.element.useState(0);
            var previewDuration = previewDurationState[0], setPreviewDuration = previewDurationState[1];
            wp.element.useEffect(function () {
                if (attrs.source !== 'upload' || !attrs.attachment_id) { setPreviewUrl(''); return; }
                var cancelled = false;
                wp.apiFetch({ path: '/wp/v2/media/' + attrs.attachment_id }).then(function (media: any) {
                    if (!cancelled) setPreviewUrl((media && media.source_url) || '');
                }).catch(function () { if (!cancelled) setPreviewUrl(''); });
                return function () { cancelled = true; };
            }, [attrs.source, attrs.attachment_id]);

            var chapters = attrs.chapters || [];
            function updateChapter(i: number, patch: any) {
                var next = chapters.slice();
                next[i] = Object.assign({}, next[i], patch);
                setAttrs({ chapters: next });
            }
            // mm:ss, the way a timestamp is actually read — the stored
            // shape stays plain seconds (portability rule), this is
            // display only. Mirrors formatTime() in courses.ts so the
            // editor and the student see the identical string.
            function fmtTime(total: number) {
                var s = Math.max(0, Math.floor(total || 0));
                return Math.floor(s / 60) + ':' + (s % 60 < 10 ? '0' : '') + (s % 60);
            }
            // Chapters are stored in authoring order but always PLAY in
            // time order (BHC_Steps::sanitize_chapters() sorts on save).
            // Showing the real playback position here — rather than
            // silently reordering the rows under the author's cursor
            // mid-edit — keeps "what I'm editing" stable while still
            // making the true order obvious.
            var playbackOrder = chapters
                .map(function (c: any, i: any) { return { i: i, time: c.time || 0 }; })
                .sort(function (a: any, b: any) { return a.time - b.time; })
                .map(function (r: any) { return r.i; });

            var chapterRows = chapters.map(function (c: any, i: any) {
                var isPastEnd = previewDuration > 0 && (c.time || 0) > previewDuration;
                var untitled = !(c.title || '').trim();
                return el('div', {
                    key: i,
                    className: 'bhc-studio-chapter-row',
                    style: {
                        border: '1px solid ' + (isPastEnd || untitled ? '#d9a800' : '#e0e0e0'),
                        borderRadius: '6px', padding: '10px 12px', marginBottom: '8px', background: '#fff',
                    },
                },
                    el('div', { style: { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '8px' } },
                        // Playback position, not array position — the
                        // number an author actually reasons about.
                        el('span', {
                            style: {
                                flexShrink: 0, minWidth: '22px', height: '22px', borderRadius: '11px',
                                background: '#f0f0f0', color: '#1e1e1e', fontSize: '11px', fontWeight: 600,
                                display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
                            },
                        }, String(playbackOrder.indexOf(i) + 1)),
                        el('code', { style: { fontSize: '12px', padding: '2px 6px', background: '#f0f0f0', borderRadius: '3px' } }, fmtTime(c.time)),
                        el('span', { style: { flex: 1 } }),
                        el(wp.components.Button, {
                            variant: 'tertiary', isDestructive: true, size: 'small',
                            onClick: function () { setAttrs({ chapters: chapters.filter(function (_: any, idx: any) { return idx !== i; }) }); },
                        }, __('Remove'))
                    ),
                    el(wp.components.TextControl, {
                        label: __('Title'),
                        value: c.title || '',
                        placeholder: __('e.g. Setting the compressor'),
                        onChange: function (v: any) { updateChapter(i, { title: v }); },
                    }),
                    el('div', { style: { display: 'flex', gap: '8px', alignItems: 'flex-end' } },
                        el(wp.components.TextControl, {
                            type: 'number', label: __('Start (seconds)'), value: c.time || 0,
                            onChange: function (v: any) { updateChapter(i, { time: Math.max(0, parseInt(v, 10) || 0) }); },
                        }),
                        previewUrl ? el(wp.components.Button, {
                            variant: 'secondary', style: { marginBottom: '8px' },
                            onClick: function () {
                                if (previewRef.current) updateChapter(i, { time: Math.floor(previewRef.current.currentTime) });
                            },
                        }, __('Set to preview')) : null
                    ),
                    // Real, specific warnings rather than letting either
                    // case fail silently on the front end (an untitled
                    // chapter is dropped on save by sanitize_chapters();
                    // one past the end renders no marker at all).
                    untitled ? el('p', { style: { margin: '4px 0 0', fontSize: '12px', color: '#8a6d00' } },
                        __('Needs a title — untitled chapters are discarded when you save.')) : null,
                    isPastEnd ? el('p', { style: { margin: '4px 0 0', fontSize: '12px', color: '#8a6d00' } },
                        __('Starts after this video ends (') + fmtTime(previewDuration) + __(') — it won\'t appear on the seek bar.')) : null
                );
            });

            var annotations = attrs.annotations || [];
            function updateAnnotation(i: number, patch: any) {
                var next = annotations.slice();
                next[i] = Object.assign({}, next[i], patch);
                setAttrs({ annotations: next });
            }
            function updatePayload(i: number, patch: any) {
                updateAnnotation(i, { payload: Object.assign({}, annotations[i].payload, patch) });
            }
            // Pop-up-Video-style overlay moments — pauses playback at a
            // timestamp, shows a note/hotspot/self-check question, resumes
            // on dismiss. See class-steps.php's own comment on why this
            // needed zero progress-model change (ROADMAP-lms-v3.md
            // Section 1): an annotation only ever pauses/resumes THIS
            // video step, never redirects anywhere.
            var annotationRows = annotations.map(function (a: any, i: any) {
                var typeFields;
                if (a.type === 'question') {
                    typeFields = el(wp.element.Fragment, {},
                        el(wp.components.TextControl, { label: __('Question'), value: a.payload.question || '', onChange: function (v: any) { updatePayload(i, { question: v }); } }),
                        el(wp.components.TextareaControl, {
                            label: __('Choices (one per line)'),
                            help: __('The first line is choice #0, etc. — matches the "Correct choice #" below.'),
                            value: (a.payload.choices || []).join('\n'),
                            onChange: function (v: any) { updatePayload(i, { choices: v.split('\n') }); },
                        }),
                        el(wp.components.TextControl, {
                            type: 'number', label: __('Correct choice # (0-based)'),
                            value: a.payload.correct_index || 0,
                            onChange: function (v: any) { updatePayload(i, { correct_index: parseInt(v, 10) || 0 }); },
                        })
                    );
                } else {
                    typeFields = el(wp.components.TextControl, { label: __('Text'), value: a.payload.text || '', onChange: function (v: any) { updatePayload(i, { text: v }); } });
                }
                return el('div', { key: i, className: 'bhc-studio-annotation-row', style: { border: '1px solid #ddd', borderRadius: '4px', padding: '10px', marginBottom: '10px' } },
                    el('div', { style: { display: 'flex', gap: '8px', alignItems: 'flex-end' } },
                        el(wp.components.TextControl, {
                            type: 'number', label: __('Time (seconds)'), value: a.time || 0,
                            onChange: function (v: any) { updateAnnotation(i, { time: parseInt(v, 10) || 0 }); },
                        }),
                        previewUrl ? el(wp.components.Button, {
                            variant: 'secondary', style: { marginBottom: '8px' },
                            onClick: function () { updateAnnotation(i, { time: Math.floor(previewTime) }); },
                        }, __('Set to preview')) : null
                    ),
                    el(wp.components.SelectControl, {
                        label: __('Type'), value: a.type,
                        options: [
                            { label: __('Note'), value: 'note' },
                            { label: __('Hotspot'), value: 'hotspot' },
                            { label: __('Question (self-check)'), value: 'question' },
                            { label: __('Banner — non-blocking, TRL-style'), value: 'banner' },
                        ],
                        onChange: function (v: any) { updateAnnotation(i, { type: v, payload: v === 'question' ? { question: '', choices: [], correct_index: 0 } : { text: '' } }); },
                    }),
                    typeFields,
                    el(wp.components.Button, {
                        variant: 'tertiary', isDestructive: true,
                        onClick: function () { setAttrs({ annotations: annotations.filter(function (_: any, idx: any) { return idx !== i; }) }); },
                    }, __('Remove'))
                );
            });

            var sourcePicker = el(wp.blockEditor.InspectorControls, {},
                el(wp.components.PanelBody, { title: __('Video settings') },
                    el(wp.components.SelectControl, {
                        label: __('Source'),
                        value: attrs.source,
                        // Cloudflare Stream only appears as an option when
                        // Tier B is actually enabled (the-self-hosted-self's Media &
                        // CDN Setup, OUS_MediaWizard::tier_b_enabled(),
                        // localized as window.bhcMediaTierB) — an install
                        // that never opted in never sees it.
                        options: [{ label: __('Uploaded file'), value: 'upload' }, { label: __('URL (oEmbed)'), value: 'url' }]
                            .concat(((window as any).bhcMediaTierB && (window as any).bhcMediaTierB.enabled) ? [{ label: __('Cloudflare Stream'), value: 'cloudflare_stream' }] : []),
                        onChange: function (v: any) { setAttrs({ source: v }); },
                    })
                ),
                // Rendered once, shared by both the Chapters and Video
                // overlays panels below via the same previewRef — each
                // panel's own "Use preview's time" button just reads
                // whatever this one video's currentTime is. Two separate
                // <video ref={previewRef}> elements (one per panel) would
                // silently fight over the same ref (only the
                // last-rendered one wins it), breaking whichever panel
                // isn't currently attached if an author ever has both
                // panels open at once — PanelBody doesn't guarantee
                // they're mutually exclusive.
                previewUrl ? el(wp.components.PanelBody, { title: __('Preview'), initialOpen: true },
                    el('video', {
                        ref: previewRef, src: previewUrl, controls: true,
                        style: { width: '100%', borderRadius: '4px', display: 'block' },
                        onTimeUpdate: function (e: any) { setPreviewTime(e.target.currentTime); },
                        onLoadedMetadata: function (e: any) { setPreviewDuration(isFinite(e.target.duration) ? e.target.duration : 0); },
                    }),
                    el('p', { className: 'description', style: { marginBottom: 0 } },
                        __('Scrub to a moment, then add a chapter or overlay — both pick up this timestamp.'))
                ) : null,
                el(wp.components.PanelBody, { title: __('Chapters') + (chapters.length ? ' (' + chapters.length + ')' : ''), initialOpen: false },
                    el('p', { className: 'description', style: { marginTop: 0 } },
                        __('Markers on the player\'s seek bar plus a clickable list beneath the video, like YouTube.')),
                    chapters.length
                        ? chapterRows
                        // A real empty state instead of a bare button
                        // floating under a heading — says what this does
                        // and what the fastest path to a first chapter is.
                        : el('div', {
                            style: {
                                border: '1px dashed #ccc', borderRadius: '6px', padding: '16px',
                                textAlign: 'center', color: '#757575', fontSize: '13px', marginBottom: '10px',
                            },
                        }, previewUrl
                            ? __('No chapters yet. Scrub the preview above to a moment, then add a chapter — it picks up that timestamp automatically.')
                            : __('No chapters yet. Select a video first, then add chapters at the moments that matter.')),
                    // The magical bit: adding a chapter captures wherever
                    // the preview is sitting RIGHT NOW, so the normal
                    // workflow is "scrub, add, type a title" instead of
                    // "add, read the time off the player, retype it as a
                    // number." Falls back to 0 with no preview loaded.
                    el(wp.components.Button, {
                        variant: 'primary',
                        onClick: function () {
                            var at = Math.floor(previewTime);
                            // Never silently stack two chapters on the
                            // same second — the second one would be an
                            // unreachable duplicate marker.
                            var taken = chapters.some(function (c: any) { return (c.time || 0) === at; });
                            setAttrs({ chapters: chapters.concat([{ time: taken ? at + 1 : at, title: '' }]) });
                        },
                    }, previewUrl ? __('+ Add chapter at ') + fmtTime(previewTime) : __('+ Add chapter'))
                ),
                el(wp.components.PanelBody, { title: __('Overlays') + (annotations.length ? ' (' + annotations.length + ')' : ''), initialOpen: false },
                    el('p', { className: 'description', style: { marginTop: 0 } }, __('Note, Hotspot and Question pause the video at a timestamp and wait for a click. Banner is deliberately different — a caption that slides in and leaves on its own, without stopping playback.')),
                    annotations.length ? annotationRows : el('div', {
                        style: {
                            border: '1px dashed #ccc', borderRadius: '6px', padding: '16px',
                            textAlign: 'center', color: '#757575', fontSize: '13px', marginBottom: '10px',
                        },
                    }, __('No overlays yet.')),
                    el(wp.components.Button, {
                        variant: 'secondary',
                        onClick: function () { setAttrs({ annotations: annotations.concat([{ time: Math.floor(previewTime), type: 'note', payload: { text: '' } }]) }); },
                    }, previewUrl ? __('+ Add overlay at ') + fmtTime(previewTime) : __('+ Add overlay'))
                )
            );
            return el(wp.element.Fragment, {},
                sourcePicker,
                el('div', blockProps,
                attrs.source === 'url'
                    ? el(wp.components.TextControl, { label: __('Video URL'), value: attrs.video_url, onChange: function (v: any) { setAttrs({ video_url: v }); } })
                    : attrs.source === 'cloudflare_stream'
                    ? el(wp.components.TextControl, {
                        label: __('Cloudflare Stream video UID'),
                        help: __('Upload the video to Cloudflare Stream yourself first, then paste the resulting UID here — a 32-character code from its dashboard/API response.'),
                        value: attrs.stream_uid,
                        onChange: function (v: any) { setAttrs({ stream_uid: v.trim().toLowerCase() }); },
                    })
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
                            onSelect: function (media: any) {
                                var maxMB = (window as any).BHCMaxVideoMB || 0;
                                var sizeBytes = media.filesizeInBytes || (media.filesize ? media.filesize * 1024 : 0);
                                var overLimit = maxMB && sizeBytes && (sizeBytes / 1048576) > maxMB;
                                setAttrs({ attachment_id: media.id, over_limit_mb: overLimit ? Math.round(sizeBytes / 1048576) : 0 });
                            },
                            render: function (obj: any) {
                                return el(wp.components.Button, { variant: 'secondary', onClick: obj.open }, attrs.attachment_id ? __('Change video') : __('Select video'));
                            },
                        }),
                        // Recomputed against the CURRENT live limit on every
                        // render, not just trusted from the stored
                        // attribute — attrs.over_limit_mb was only ever
                        // correct at the moment the file was selected, so
                        // if the site's limit is later raised, lowered, or
                        // was 0 (no limit) all along, a stale stored value
                        // would otherwise keep showing a wrong warning
                        // (e.g. "over this site's 0MB limit").
                        (attrs.over_limit_mb && (window as any).BHCMaxVideoMB && attrs.over_limit_mb > (window as any).BHCMaxVideoMB)
                            ? el('p', { className: 'bhc-video-size-warning', style: { color: '#b32d2e' } },
                                __('This file is about ') + attrs.over_limit_mb + __('MB, over this site\'s ') + (window as any).BHCMaxVideoMB + __('MB direct-upload limit. Consider switching Source to "URL (oEmbed)" instead.'))
                            : null
                    ),
                el(wp.components.TextControl, { label: __('Caption'), value: attrs.caption, onChange: function (v: any) { setAttrs({ caption: v }); } }),
                el(wp.components.RangeControl, {
                    label: __('Require watched % to auto-complete (0 = off, any playback completes it)'),
                    value: attrs.watch_threshold,
                    min: 0,
                    max: 100,
                    onChange: function (v: any) { setAttrs({ watch_threshold: v || 0 }); },
                    help: attrs.source === 'url' ? __('Only enforceable for a direct video URL — a YouTube/Vimeo-style embed can\'t be watch-tracked, so this is ignored for iframe embeds.') : undefined,
                })
                )
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
        edit: function (props: any) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            // See bhc/image's blockProps comment — same toolbar-collision buffer.
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-resource', style: { paddingTop: '32px' } });
            return el('div', blockProps,
                el(wp.blockEditor.MediaUploadCheck, {},
                    el(wp.blockEditor.MediaUpload, {
                        value: attrs.attachment_id,
                        onSelect: function (media: any) {
                            // Default the label to the file's own name on
                            // first pick — a course creator can still
                            // override it, but "Worksheet.pdf" beats an
                            // empty label if they never touch this field.
                            setAttrs({ attachment_id: media.id, label: attrs.label || media.title || media.filename || '' });
                        },
                        render: function (obj: any) {
                            return el(wp.components.Button, { variant: 'secondary', onClick: obj.open }, attrs.attachment_id ? __('Change file') : __('Select file'));
                        },
                    })
                ),
                attrs.attachment_id ? el('p', { className: 'bhc-studio-resource-selected' }, __('File selected (#') + attrs.attachment_id + ')') : null,
                el(wp.components.TextControl, { label: __('Label'), value: attrs.label, placeholder: __('e.g. Worksheet.pdf'), onChange: function (v: any) { setAttrs({ label: v }); } }),
                el(wp.components.TextControl, { label: __('Description (optional)'), value: attrs.description, onChange: function (v: any) { setAttrs({ description: v }); } })
            );
        },
        save: function () { return null; }, // dynamic — server renderer is BHC_ContentBridge's bhc/resource callback
    });

    // Depth-of-magic Phase 2c — checklist/chord-chart/audio-compare,
    // scoped directly from AJ's own answer on what's actually missing
    // for THIS content (music production/songwriting courses), not a
    // generic "add more block types" guess. All three are dynamic
    // (save() returns null, same as bhc/resource above) — the server
    // renderer is BHC_ContentBridge's own callback for each; the editor
    // canvas shows the real edit() UI directly rather than a live
    // preview of rendered output, same posture every other dynamic
    // block here already takes.
    wp.blocks.registerBlockType('bhc/checklist', {
        apiVersion: 3,
        title: __('Lesson: Checklist'),
        icon: 'yes-alt',
        category: 'lms',
        attributes: {
            title: { type: 'string', default: '' },
            items: { type: 'array', default: [] },
        },
        supports: { html: false },
        edit: function (props: any) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-checklist', style: { paddingTop: '32px' } });
            var items = attrs.items || [];
            var rows = items.map(function (item: any, i: any) {
                return el('div', { key: i, className: 'bhc-studio-checklist-row' },
                    el(wp.components.TextControl, {
                        value: item,
                        placeholder: __('Checklist item…'),
                        onChange: function (v: any) {
                            var next = items.slice();
                            next[i] = v;
                            setAttrs({ items: next });
                        },
                    }),
                    el(wp.components.Button, {
                        variant: 'tertiary', isDestructive: true,
                        onClick: function () { setAttrs({ items: items.filter(function (_: any, idx: any) { return idx !== i; }) }); },
                    }, __('Remove'))
                );
            });
            return el('div', blockProps,
                el(wp.components.TextControl, { label: __('Title (optional)'), value: attrs.title, placeholder: __('e.g. Before you export…'), onChange: function (v: any) { setAttrs({ title: v }); } }),
                rows,
                el(wp.components.Button, { variant: 'secondary', onClick: function () { setAttrs({ items: items.concat(['']) }); } }, __('+ Add item'))
            );
        },
        save: function () { return null; },
    });

    wp.blocks.registerBlockType('bhc/chord-chart', {
        apiVersion: 3,
        title: __('Lesson: Chord/Tab Chart'),
        icon: 'editor-code',
        category: 'lms',
        attributes: {
            title: { type: 'string', default: '' },
            content: { type: 'string', default: '' },
        },
        supports: { html: false },
        edit: function (props: any) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-chord-chart', style: { paddingTop: '32px' } });
            return el('div', blockProps,
                el(wp.components.TextControl, { label: __('Title (optional)'), value: attrs.title, placeholder: __('e.g. Verse'), onChange: function (v: any) { setAttrs({ title: v }); } }),
                el(wp.components.TextareaControl, {
                    label: __('Chord/tab chart'),
                    help: __('Plain monospace text — alignment is preserved exactly as typed.'),
                    value: attrs.content,
                    rows: 8,
                    onChange: function (v: any) { setAttrs({ content: v }); },
                })
            );
        },
        save: function () { return null; },
    });

    wp.blocks.registerBlockType('bhc/audio-compare', {
        apiVersion: 3,
        title: __('Lesson: Audio A/B Compare'),
        icon: 'controls-volumeon',
        category: 'lms',
        attributes: {
            attachment_id_a: { type: 'number', default: 0 },
            attachment_id_b: { type: 'number', default: 0 },
            label_a: { type: 'string', default: 'A' },
            label_b: { type: 'string', default: 'B' },
            caption: { type: 'string', default: '' },
        },
        supports: { html: false },
        edit: function (props: any) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-audio-compare', style: { paddingTop: '32px' } });
            function clipPicker(idKey: any, labelKey: any, defaultLabel: any) {
                return el('div', { className: 'bhc-studio-audio-compare-clip' },
                    el(wp.components.TextControl, { label: __('Label'), value: attrs[labelKey], placeholder: defaultLabel, onChange: function (v: any) { setAttrs((function (o: any) { o[labelKey] = v; return o; })({})); } }),
                    el(wp.blockEditor.MediaUploadCheck, {},
                        el(wp.blockEditor.MediaUpload, {
                            allowedTypes: ['audio'],
                            value: attrs[idKey],
                            onSelect: function (media: any) { setAttrs((function (o: any) { o[idKey] = media.id; return o; })({})); },
                            render: function (obj: any) {
                                return el(wp.components.Button, { variant: 'secondary', onClick: obj.open }, attrs[idKey] ? __('Change audio') : __('Select audio'));
                            },
                        })
                    ),
                    attrs[idKey] ? el('p', { className: 'bhc-studio-audio-compare-selected' }, __('Audio selected (#') + attrs[idKey] + ')') : null
                );
            }
            return el('div', blockProps,
                clipPicker('attachment_id_a', 'label_a', 'A'),
                clipPicker('attachment_id_b', 'label_b', 'B'),
                el(wp.components.TextControl, { label: __('Caption (optional)'), value: attrs.caption, onChange: function (v: any) { setAttrs({ caption: v }); } })
            );
        },
        save: function () { return null; },
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
        edit: function (props: any) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-quiz' });
            // Real child blocks, not a repeater — this is the whole
            // point of Section 3.2's promotion. allowedBlocks keeps
            // quiz-question scoped to living only inside a quiz.
            var innerBlocksProps = wp.blockEditor.useInnerBlocksProps(blockProps, {
                allowedBlocks: ['bhc/quiz-question'],
                templateLock: false,
                renderAppender: AddQuestionAppender,
            });
            return el(wp.element.Fragment, {},
                el(wp.blockEditor.InspectorControls, {},
                    el(wp.components.PanelBody, { title: __('Quiz settings') },
                        el(wp.components.RangeControl, { label: __('Passing score (%)'), value: attrs.passing_score, onChange: function (v: any) { setAttrs({ passing_score: v }); }, min: 0, max: 100 }),
                        el(wp.components.RangeControl, { label: __('Max attempts (0 = unlimited)'), value: attrs.max_attempts, onChange: function (v: any) { setAttrs({ max_attempts: v }); }, min: 0, max: 20 }),
                        el(wp.components.ToggleControl, { label: __('Shuffle question order'), checked: attrs.shuffle_questions, onChange: function (v: any) { setAttrs({ shuffle_questions: v }); } }),
                        el(wp.components.ToggleControl, { label: __('Shuffle answer order'), checked: attrs.shuffle_choices, onChange: function (v: any) { setAttrs({ shuffle_choices: v }); } })
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
        edit: function (props: any) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            // See bhc/image's blockProps comment — same toolbar-collision buffer.
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-quiz-question', style: { paddingTop: '32px' } });
            var choices = attrs.choices && attrs.choices.length ? attrs.choices : ['', ''];

            function setChoice(i: number, v: any) {
                var next = choices.slice();
                next[i] = v;
                setAttrs({ choices: next });
            }
            function addChoice() { setAttrs({ choices: choices.concat(['']) }); }
            function removeChoice(i: number) {
                var next = choices.slice();
                next.splice(i, 1);
                setAttrs({ choices: next, correct_index: Math.min(attrs.correct_index, Math.max(0, next.length - 1)) });
            }

            var choiceRows = choices.map(function (c: any, i: any) {
                return el('div', { key: i, className: 'bhc-studio-choice-row' },
                    el(wp.components.RadioControl, {
                        selected: attrs.correct_index === i ? 'correct' : '',
                        options: [{ label: '', value: 'correct' }],
                        onChange: function () { setAttrs({ correct_index: i }); },
                    }),
                    el(wp.components.TextControl, { value: c, placeholder: __('Choice text'), onChange: function (v: any) { setChoice(i, v); } }),
                    // Real bug, caught live: icon: 'no-alt' renders via
                    // wp.components.Dashicon, a font-icon that depends on
                    // the 'dashicons' stylesheet actually reaching this
                    // element — confirmed NOT to inside the post editor's
                    // iframed canvas (computed icon size came back 0x0,
                    // font-family fell through to the theme's own body
                    // font instead of "dashicons"). closeIcon() (top of
                    // file) is a hand-authored inline SVG — no external
                    // stylesheet or package (wp.icons isn't loaded/exposed
                    // as a global in this install either, confirmed via
                    // console) for either rendering path to fail on.
                    choices.length > 2 ? el(wp.components.Button, { icon: closeIcon(), label: __('Remove choice'), onClick: function () { removeChoice(i); } }) : null
                );
            });

            return el('div', blockProps,
                el(wp.components.TextControl, { label: __('Question'), value: attrs.question, onChange: function (v: any) { setAttrs({ question: v }); } }),
                el('p', { className: 'description' }, __('Select the radio next to the correct choice.')),
                choiceRows,
                // className carries its own margin-bottom (see
                // add_studio_block_editor_styles() in class-content-
                // bridge.php) — without it, this button sat directly
                // against Gutenberg's own "Add quiz question" block-
                // appender (a totally different action: a new question,
                // not a new choice), the two reading as one confusing
                // cluster of "add" buttons.
                el(wp.components.Button, { variant: 'secondary', className: 'bhc-studio-add-choice', onClick: addChoice }, __('Add choice'))
            );
        },
        save: function () { return null; }, // dynamic
    });
})((window as any).wp);
