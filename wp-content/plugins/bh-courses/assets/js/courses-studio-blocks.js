"use strict";
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
(function (wp) {
    'use strict';
    if (!wp || !wp.blocks || !wp.element || !wp.blockEditor || !wp.components || !wp.primitives || !wp.data)
        return;
    var el = wp.element.createElement;
    var __ = wp.i18n ? wp.i18n.__ : function (s) { return s; };
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
        return el(wp.primitives.SVG, { viewBox: '0 0 24 24', width: 20, height: 20, xmlns: 'http://www.w3.org/2000/svg' }, el(wp.primitives.Path, { d: 'M13.06 12l6.47-6.47-1.06-1.06L12 10.94 5.53 4.47 4.47 5.53 10.94 12l-6.47 6.47 1.06 1.06L12 13.06l6.47 6.47 1.06-1.06L13.06 12z' }));
    }
    // The video block's "Choose from Bunny library" browser. Talks to
    // BHC_Bunny's REST routes (class-bunny.php) — the Bunny API key stays
    // server-side. Renders in a wp.components.Modal; a click hands the
    // GUID back to the block.
    function BunnyLibraryModal(props) {
        var itemsState = wp.element.useState([]);
        var items = itemsState[0], setItems = itemsState[1];
        var loadingState = wp.element.useState(true);
        var loading = loadingState[0], setLoading = loadingState[1];
        var errState = wp.element.useState('');
        var err = errState[0], setErr = errState[1];
        var searchState = wp.element.useState('');
        var search = searchState[0], setSearch = searchState[1];
        wp.element.useEffect(function () {
            var cancelled = false;
            setLoading(true);
            setErr('');
            var t = window.setTimeout(function () {
                wp.apiFetch({ url: props.cfg.restBase + '/videos?page=1&search=' + encodeURIComponent(search), headers: { 'X-WP-Nonce': props.cfg.nonce } })
                    .then(function (r) { if (!cancelled) {
                    setItems((r && r.items) || []);
                    setLoading(false);
                } })
                    .catch(function (e) { if (!cancelled) {
                    setErr(String((e && e.message) || __('Could not reach Bunny.')));
                    setLoading(false);
                } });
            }, 250); // debounce the search box
            return function () { cancelled = true; window.clearTimeout(t); };
        }, [search]);
        return el(wp.components.Modal, { title: __('Your Bunny Stream library'), onRequestClose: props.onClose, className: 'bhc-bunny-modal' }, el(wp.components.SearchControl, { value: search, onChange: setSearch, __nextHasNoMarginBottom: true }), err ? el(wp.components.Notice, { status: 'error', isDismissible: false }, err) : null, loading ? el('p', { className: 'description', style: { padding: '24px 0' } }, __('Loading…')) : null, (!loading && !err && !items.length) ? el('p', { className: 'description', style: { padding: '24px 0' } }, __('No videos in this library yet.')) : null, el('div', { className: 'bhc-bunny-grid', style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(150px, 1fr))', gap: '12px', maxHeight: '52vh', overflow: 'auto', marginTop: '12px' } }, items.map(function (v) {
            var ready = v.status === 4 || v.status === 3;
            return el('button', {
                key: v.guid, type: 'button', className: 'bhc-bunny-grid-item',
                onClick: function () { props.onPick(v.guid); },
                style: { textAlign: 'left', border: '1px solid #ddd', borderRadius: '6px', padding: '0', overflow: 'hidden', background: '#fff', cursor: 'pointer' },
            }, v.thumbnail
                ? el('img', { src: v.thumbnail, alt: '', style: { width: '100%', aspectRatio: '16 / 9', objectFit: 'cover', display: 'block', background: '#000' } })
                : el('div', { style: { width: '100%', aspectRatio: '16 / 9', background: '#111' } }), el('div', { style: { padding: '6px 8px' } }, el('div', { style: { fontSize: '12px', fontWeight: 600, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }, decodeEntities(v.title || '') || v.guid), el('div', { className: 'description', style: { fontSize: '11px' } }, ready ? (Math.floor((v.length || 0) / 60) + ':' + ('0' + Math.floor((v.length || 0) % 60)).slice(-2)) : __('processing…'))));
        })));
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
    // Deliberately minimal. A lesson step is a CONTENT primitive an
    // author fills in, not a layout box they tune — so no raw-HTML
    // escape hatch, no absolute positioning, and no spacing / color /
    // extra-CSS-class controls (those panels were the top "the step
    // inspector is cluttered noise" complaint). The generic "Advanced
    // Styles" panel is separately excluded for every bhc/* block in
    // the-self-hosted-self's block-style-panel.ts.
    var SUPPORTS = { html: false, position: false, customClassName: false };
    /* ==================================================================
     * Shared authoring shell for every lesson step block.
     *
     * Before this, each of the nine step blocks rendered as a bare stack
     * of form fields — visually identical to each other, so a lesson of
     * eight steps read as one undifferentiated column of inputs with no
     * way to tell a Quiz from a Callout at a glance. Each also repeated
     * its own `paddingTop: 32px` toolbar-collision hack inline, and the
     * media-backed ones surfaced a raw database row id ("File selected
     * (#268)") as if that meant something to a course author.
     *
     * These helpers give all nine one consistent frame: a labelled
     * header with the step's own type/icon, an optional at-a-glance
     * summary chip, real human file names instead of ids, and a shared
     * empty state. Styling lives in class-content-bridge.php's
     * `styles` array (the only channel that reaches the editor's
     * IFRAMED canvas — see that file's own note on why a plain
     * wp_enqueue_style can't).
     * ================================================================== */
    /** The step's own frame: type header + body. */
    function stepShell(icon, label, meta, blockProps, children) {
        return el('div', blockProps, el('div', { className: 'bhc-studio-head' }, el(wp.components.Icon, { icon: icon, size: 16 }), el('span', { className: 'bhc-studio-head-label' }, label), meta ? el('span', { className: 'bhc-studio-head-meta' }, meta) : null), el('div', { className: 'bhc-studio-body' }, children));
    }
    /**
     * Resolves an attachment id to something a human recognises (its
     * title, and a size/duration where the REST payload carries one).
     * Returns null while in flight or if the attachment is gone — every
     * caller renders a plain fallback rather than a spinner, since this
     * is decoration on top of an id that already works.
     */
    function useAttachment(id) {
        var state = wp.element.useState(null);
        var media = state[0], setMedia = state[1];
        wp.element.useEffect(function () {
            if (!id) {
                setMedia(null);
                return;
            }
            var cancelled = false;
            wp.apiFetch({ path: '/wp/v2/media/' + id })
                .then(function (m) { if (!cancelled)
                setMedia(m); })
                .catch(function () { if (!cancelled)
                setMedia(null); });
            return function () { cancelled = true; };
        }, [id]);
        return media;
    }
    /** REST `title.rendered` is HTML-encoded ("01 &#8211; Intro"); the
     *  block editor renders strings as text, so entities show literally.
     *  Decode once, here, where every media label passes through. */
    function decodeEntities(s) {
        if (!s || s.indexOf('&') === -1)
            return s;
        var t = document.createElement('textarea');
        t.innerHTML = s;
        return t.value;
    }
    /** "Worksheet.pdf" beats "File selected (#268)". */
    function mediaName(media, fallbackId) {
        if (media && (media.title || media.slug)) {
            return decodeEntities((media.title && media.title.rendered) || media.slug || ('#' + fallbackId));
        }
        return fallbackId ? __('Attachment #') + fallbackId : '';
    }
    /**
     * The shared "nothing chosen yet" state — an inviting target with a
     * real explanation, rather than a lone unlabelled button. Mirrors
     * how core's own image/video blocks open.
     */
    function pickerPlaceholder(icon, title, help, button) {
        return el('div', { className: 'bhc-studio-placeholder' }, el(wp.components.Icon, { icon: icon, size: 24 }), el('p', { className: 'bhc-studio-placeholder-title' }, title), help ? el('p', { className: 'bhc-studio-placeholder-help' }, help) : null, button);
    }
    /**
     * One real thumbnail for an attachment id. Its own component (not a
     * loop inside the caller) because it calls useAttachment — a hook,
     * which React forbids running in a loop.
     */
    function AttachmentThumb(props) {
        var media = useAttachment(props.id);
        var src = media && media.media_details && media.media_details.sizes && media.media_details.sizes.thumbnail
            ? media.media_details.sizes.thumbnail.source_url
            : (media && media.source_url) || '';
        if (!src)
            return el('span', { className: 'bhc-studio-image-thumb' }, '#' + props.id);
        return el('img', {
            className: 'bhc-studio-image-thumb',
            src: src,
            alt: (media && media.alt_text) || '',
            title: mediaName(media, props.id),
        });
    }
    /** A chosen file, shown as a real row rather than a bare id. */
    function chosenFile(icon, name, note, changeButton) {
        return el('div', { className: 'bhc-studio-chosen' }, el(wp.components.Icon, { icon: icon, size: 18 }), el('span', { className: 'bhc-studio-chosen-name' }, name), note ? el('span', { className: 'bhc-studio-chosen-note' }, note) : null, changeButton);
    }
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
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-text bhc-studio-step' });
            // RichText is nested inside the shell's body rather than
            // being the block root — the shell only frames it, so
            // Gutenberg's own rich-text editing surface is unchanged.
            return stepShell('text', __('Text'), null, blockProps, [
                el(wp.blockEditor.RichText, {
                    key: 'rt',
                    tagName: 'div',
                    value: attrs.content,
                    onChange: function (v) { setAttrs({ content: v }); },
                    placeholder: __('Write this step’s text…'),
                }),
            ]);
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
        supports: { html: false, customClassName: false },
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            // The old paddingTop:32px inline hack (Gutenberg docks its
            // floating toolbar inside a block's own top edge when there's
            // no room above) is gone — the shared shell's header band
            // and margin-top now give the toolbar something intentional
            // to sit against, for every step type at once.
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-image bhc-studio-step' });
            var ids = attrs.attachment_ids || [];
            var picker = el(wp.blockEditor.MediaUploadCheck, { key: 'pick' }, el(wp.blockEditor.MediaUpload, {
                multiple: true,
                allowedTypes: ['image'],
                value: ids,
                onSelect: function (media) { setAttrs({ attachment_ids: (media || []).map(function (m) { return m.id; }) }); },
                render: function (obj) {
                    return el(wp.components.Button, { variant: ids.length ? 'secondary' : 'primary', onClick: obj.open }, ids.length ? __('Change images') : __('Select images'));
                },
            }));
            return stepShell('format-image', __('Image'), ids.length ? ids.length + (ids.length === 1 ? __(' image') : __(' images')) : null, blockProps, [
                ids.length
                    // Real thumbnails of the actual chosen images —
                    // previously this showed "#268" chips, a database id
                    // presented to a course author as if it identified
                    // anything to them.
                    ? el('div', { key: 'thumbs', className: 'bhc-studio-image-thumbs' }, ids.map(function (id) { return el(AttachmentThumb, { key: id, id: id }); }))
                    : pickerPlaceholder('format-image', __('No image chosen yet'), __('Pick one or more images to show at this point in the lesson.'), picker),
                ids.length ? el('div', { key: 'change', style: { marginBottom: '12px' } }, picker) : null,
                el(wp.components.TextControl, {
                    key: 'cap', label: __('Caption'), value: attrs.caption,
                    placeholder: __('Optional — shown beneath the image'),
                    onChange: function (v) { setAttrs({ caption: v }); },
                }),
            ]);
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
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-callout bhc-studio-step bhc-callout-' + attrs.variant });
            var variantPicker = el(wp.blockEditor.InspectorControls, {}, el(wp.components.PanelBody, { title: __('Callout settings') }, el(wp.components.SelectControl, {
                label: __('Variant'),
                value: attrs.variant,
                options: [
                    { label: __('Tip'), value: 'tip' },
                    { label: __('Note'), value: 'note' },
                    { label: __('Warning'), value: 'warning' },
                ],
                onChange: function (v) { setAttrs({ variant: v }); },
            })));
            var variantLabel = attrs.variant === 'warning' ? __('Warning') : attrs.variant === 'note' ? __('Note') : __('Tip');
            return el(wp.element.Fragment, {}, variantPicker, stepShell('megaphone', __('Callout'), variantLabel, blockProps, [
                el(wp.blockEditor.RichText, {
                    key: 'rt',
                    tagName: 'div',
                    value: attrs.content,
                    onChange: function (v) { setAttrs({ content: v }); },
                    placeholder: __('Key idea, tip, or warning\u2026'),
                }),
            ]));
        },
        save: function (props) {
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
            // Private (signed) delivery — BHY_MediaToken. The step stores
            // only the id/key; library + secrets are site-wide config.
            bunny_guid: { type: 'string', default: '' },
            r2_key: { type: 'string', default: '' },
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
        supports: { html: false, customClassName: false },
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            // The old paddingTop:32px toolbar-collision hack is gone —
            // the shared shell's header band gives the floating toolbar
            // something intentional to dock against instead (see
            // bhc-studio-step's own comment in class-content-bridge.php).
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-video bhc-studio-step' });
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
                if (attrs.source !== 'upload' || !attrs.attachment_id) {
                    setPreviewUrl('');
                    return;
                }
                var cancelled = false;
                wp.apiFetch({ path: '/wp/v2/media/' + attrs.attachment_id }).then(function (media) {
                    if (!cancelled)
                        setPreviewUrl((media && media.source_url) || '');
                }).catch(function () { if (!cancelled)
                    setPreviewUrl(''); });
                return function () { cancelled = true; };
            }, [attrs.source, attrs.attachment_id]);
            // ---- Bunny Stream: in-editor library, upload, and preview ----
            var bunnyCfg = window.bhcBunny || null;
            var bunnyEmbedState = wp.element.useState('');
            var bunnyEmbedUrl = bunnyEmbedState[0], setBunnyEmbedUrl = bunnyEmbedState[1];
            var bunnyModalState = wp.element.useState(false);
            var bunnyModalOpen = bunnyModalState[0], setBunnyModalOpen = bunnyModalState[1];
            var bunnyUploadState = wp.element.useState(null);
            var bunnyUpload = bunnyUploadState[0], setBunnyUpload = bunnyUploadState[1];
            var bunnyPlayerRef = wp.element.useRef(null);
            var bunnyFileRef = wp.element.useRef(null);
            // Resolve a signed, scrubbing preview URL whenever the chosen GUID changes.
            wp.element.useEffect(function () {
                if (attrs.source !== 'bunny_stream' || !attrs.bunny_guid || !bunnyCfg) {
                    setBunnyEmbedUrl('');
                    return;
                }
                var cancelled = false;
                wp.apiFetch({ url: bunnyCfg.restBase + '/embed?guid=' + encodeURIComponent(attrs.bunny_guid), headers: { 'X-WP-Nonce': bunnyCfg.nonce } })
                    .then(function (r) { if (!cancelled)
                    setBunnyEmbedUrl((r && r.url) || ''); })
                    .catch(function () { if (!cancelled)
                    setBunnyEmbedUrl(''); });
                return function () { cancelled = true; };
            }, [attrs.source, attrs.bunny_guid]);
            // Drive previewTime from the Bunny preview iframe via player.js,
            // so "+ Add chapter at 0:42" works the same as for an upload.
            wp.element.useEffect(function () {
                var pjsCtor = window.playerjs;
                var frame = bunnyPlayerRef.current;
                if (!bunnyEmbedUrl || !pjsCtor || !frame)
                    return;
                var p = new pjsCtor.Player(frame);
                p.on('timeupdate', function (v) {
                    if (v && typeof v.seconds === 'number')
                        setPreviewTime(v.seconds);
                    if (v && typeof v.duration === 'number' && v.duration > 0)
                        setPreviewDuration(v.duration);
                });
                p.on('ready', function () { p.getDuration(function (d) { if (d > 0)
                    setPreviewDuration(d); }); });
                return function () { try {
                    p.off && p.off('timeupdate');
                }
                catch (e) { /* player.js has no off in 0.1 — GC handles it */ } };
            }, [bunnyEmbedUrl]);
            function bunnyPick(guid) {
                setAttrs({ bunny_guid: (guid || '').trim().toLowerCase() });
                setBunnyModalOpen(false);
            }
            function bunnyStartUpload(file) {
                var tus = window.tus;
                if (!bunnyCfg || !bunnyCfg.hasApi || !tus) {
                    setBunnyUpload({ pct: 0, label: '', error: __('Uploading needs the Bunny API key set in Media & CDN Setup.') });
                    return;
                }
                setBunnyUpload({ pct: 0, label: __('Creating video…') });
                var headers = { 'X-WP-Nonce': bunnyCfg.nonce, 'Content-Type': 'application/json' };
                wp.apiFetch({ url: bunnyCfg.restBase + '/video', method: 'POST', headers: headers, body: JSON.stringify({ title: file.name.replace(/\.[^.]+$/, '') }) })
                    .then(function (r) {
                    var guid = r && r.guid;
                    if (!guid)
                        throw new Error('no guid');
                    return wp.apiFetch({ url: bunnyCfg.restBase + '/upload-signature', method: 'POST', headers: headers, body: JSON.stringify({ guid: guid }) })
                        .then(function (sig) {
                        setBunnyUpload({ pct: 0, label: __('Uploading…') });
                        var up = new tus.Upload(file, {
                            endpoint: sig.endpoint,
                            retryDelays: [0, 2000, 6000, 12000],
                            headers: {
                                AuthorizationSignature: sig.signature,
                                AuthorizationExpire: String(sig.expires),
                                LibraryId: String(sig.library_id),
                                VideoId: sig.video_guid,
                            },
                            metadata: { filetype: file.type || 'video/mp4', title: file.name },
                            onError: function (err) { setBunnyUpload({ pct: 0, label: '', error: String((err && err.message) || err) }); },
                            onProgress: function (sent, total) { setBunnyUpload({ pct: total ? Math.round((sent / total) * 100) : 0, label: __('Uploading…') }); },
                            onSuccess: function () {
                                setBunnyUpload({ pct: 100, label: __('Bunny is processing the video — it becomes playable in a minute or two.') });
                                setAttrs({ bunny_guid: String(guid).toLowerCase() });
                                window.setTimeout(function () { setBunnyUpload(null); }, 6000);
                            },
                        });
                        up.start();
                    });
                })
                    .catch(function (e) { setBunnyUpload({ pct: 0, label: '', error: String((e && e.message) || __('Upload failed.')) }); });
            }
            // True whenever there's a scrubable preview whose playhead the
            // "at 0:42" affordances can read — an uploaded file, or a
            // Bunny video with its signed preview resolved.
            var hasScrubPreview = !!previewUrl || (attrs.source === 'bunny_stream' && !!bunnyEmbedUrl);
            var chapters = attrs.chapters || [];
            function updateChapter(i, patch) {
                var next = chapters.slice();
                next[i] = Object.assign({}, next[i], patch);
                setAttrs({ chapters: next });
            }
            // mm:ss, the way a timestamp is actually read — the stored
            // shape stays plain seconds (portability rule), this is
            // display only. Mirrors formatTime() in courses.ts so the
            // editor and the student see the identical string.
            function fmtTime(total) {
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
                .map(function (c, i) { return { i: i, time: c.time || 0 }; })
                .sort(function (a, b) { return a.time - b.time; })
                .map(function (r) { return r.i; });
            // Streamlined to two rows (three only when a warning applies)
            // instead of the original four-row stack. The old version
            // showed the timestamp TWICE — a read-only badge up top AND
            // a separate, fully-labelled "Start (seconds)" field further
            // down — which reads as two different facts about the
            // chapter until you look closely and realise they're the
            // same number. Now there's exactly one time field, it's the
            // editable one, and it sits where the old read-only badge
            // used to be.
            var chapterRows = chapters.map(function (c, i) {
                var isPastEnd = previewDuration > 0 && (c.time || 0) > previewDuration;
                var untitled = !(c.title || '').trim();
                return el('div', { key: i, className: 'bhc-studio-chapter-row' + (isPastEnd || untitled ? ' has-warning' : '') }, el('div', { className: 'bhc-studio-chapter-row-top' }, 
                // Playback position, not array position — the
                // number an author actually reasons about.
                el('span', { className: 'bhc-studio-order-badge' }, String(playbackOrder.indexOf(i) + 1)), el(wp.components.TextControl, {
                    type: 'number', label: __('Start time, in seconds'), hideLabelFromVision: true,
                    className: 'bhc-studio-time-input', value: c.time || 0,
                    onChange: function (v) { updateChapter(i, { time: Math.max(0, parseInt(v, 10) || 0) }); },
                }), el('span', { className: 'bhc-studio-time-unit' }, __('sec')), 
                // Icon + a real word, not an icon alone — a bare
                // icon here would need a hover to find out what
                // it does; "Now" says it outright: this sets the
                // time to wherever the preview is playing right
                // now. The tooltip (via `label`) still spells out
                // the full behavior for anyone who pauses on it.
                hasScrubPreview ? el(wp.components.Button, {
                    variant: 'tertiary', size: 'small', icon: 'controls-forward',
                    label: __('Set this chapter\'s time to the preview\'s current position'), showTooltip: true,
                    className: 'bhc-studio-grab-time',
                    onClick: function () { updateChapter(i, { time: Math.floor(previewTime) }); },
                }, __('Now')) : null, el('span', { style: { flex: 1 } }), el(wp.components.Button, {
                    variant: 'tertiary', isDestructive: true, size: 'small', icon: 'no-alt',
                    label: __('Remove this chapter'), showTooltip: true,
                    onClick: function () { setAttrs({ chapters: chapters.filter(function (_, idx) { return idx !== i; }) }); },
                }, __('Remove'))), el(wp.components.TextControl, {
                    label: __('Chapter title'), hideLabelFromVision: true,
                    value: c.title || '',
                    placeholder: __('Chapter title, e.g. “Setting the compressor”'),
                    onChange: function (v) { updateChapter(i, { title: v }); },
                }), 
                // Real, specific warnings rather than letting either
                // case fail silently on the front end (an untitled
                // chapter is dropped on save by sanitize_chapters();
                // one past the end renders no marker at all).
                untitled ? el('p', { className: 'bhc-studio-row-warning' }, __('Needs a title — untitled chapters are discarded when you save.')) : null, isPastEnd ? el('p', { className: 'bhc-studio-row-warning' }, __('Starts after this video ends (') + fmtTime(previewDuration) + __(') — it won\'t appear on the seek bar.')) : null);
            });
            var annotations = attrs.annotations || [];
            function updateAnnotation(i, patch) {
                var next = annotations.slice();
                next[i] = Object.assign({}, next[i], patch);
                setAttrs({ annotations: next });
            }
            function updatePayload(i, patch) {
                updateAnnotation(i, { payload: Object.assign({}, annotations[i].payload, patch) });
            }
            // Pop-up-Video-style overlay moments — pauses playback at a
            // timestamp, shows a note/hotspot/self-check question, resumes
            // on dismiss. See class-steps.php's own comment on why this
            // needed zero progress-model change (ROADMAP-lms-v3.md
            // Section 1): an annotation only ever pauses/resumes THIS
            // video step, never redirects anywhere.
            var annotationRows = annotations.map(function (a, i) {
                var typeFields;
                if (a.type === 'question') {
                    typeFields = el(wp.element.Fragment, {}, el(wp.components.TextControl, { label: __('Question'), value: a.payload.question || '', onChange: function (v) { updatePayload(i, { question: v }); } }), el(wp.components.TextareaControl, {
                        label: __('Choices (one per line)'),
                        help: __('The first line is choice #0, etc. — matches the "Correct choice #" below.'),
                        value: (a.payload.choices || []).join('\n'),
                        onChange: function (v) { updatePayload(i, { choices: v.split('\n') }); },
                    }), el(wp.components.TextControl, {
                        type: 'number', label: __('Correct choice # (0-based)'),
                        value: a.payload.correct_index || 0,
                        onChange: function (v) { updatePayload(i, { correct_index: parseInt(v, 10) || 0 }); },
                    }));
                }
                else {
                    typeFields = el(wp.components.TextControl, { label: __('Text'), value: a.payload.text || '', onChange: function (v) { updatePayload(i, { text: v }); } });
                }
                // Same streamlining as the chapter rows: time, the
                // "grab from preview" action, type, and remove all share
                // one compact top row instead of four stacked fields
                // before any real content (the note/question text) even
                // appears.
                return el('div', { key: i, className: 'bhc-studio-annotation-row' }, el('div', { className: 'bhc-studio-chapter-row-top' }, el(wp.components.TextControl, {
                    type: 'number', label: __('Time, in seconds'), hideLabelFromVision: true,
                    className: 'bhc-studio-time-input', value: a.time || 0,
                    onChange: function (v) { updateAnnotation(i, { time: Math.max(0, parseInt(v, 10) || 0) }); },
                }), el('span', { className: 'bhc-studio-time-unit' }, __('sec')), hasScrubPreview ? el(wp.components.Button, {
                    variant: 'tertiary', size: 'small', icon: 'controls-forward',
                    label: __('Set this overlay\'s time to the preview\'s current position'), showTooltip: true,
                    onClick: function () { updateAnnotation(i, { time: Math.floor(previewTime) }); },
                }, __('Now')) : null, el(wp.components.SelectControl, {
                    label: __('Overlay type'), hideLabelFromVision: true,
                    className: 'bhc-studio-overlay-type', value: a.type,
                    options: [
                        { label: __('Note'), value: 'note' },
                        { label: __('Hotspot'), value: 'hotspot' },
                        { label: __('Question (self-check)'), value: 'question' },
                        { label: __('Banner — non-blocking, TRL-style'), value: 'banner' },
                    ],
                    onChange: function (v) { updateAnnotation(i, { type: v, payload: v === 'question' ? { question: '', choices: [], correct_index: 0 } : { text: '' } }); },
                }), el('span', { style: { flex: 1 } }), el(wp.components.Button, {
                    variant: 'tertiary', isDestructive: true, size: 'small', icon: 'no-alt',
                    label: __('Remove this overlay'), showTooltip: true,
                    onClick: function () { setAttrs({ annotations: annotations.filter(function (_, idx) { return idx !== i; }) }); },
                }, __('Remove'))), typeFields);
            });
            // Source is a genuine block-level SETTING (like core's own
            // image block putting "alt text"/link destination in the
            // sidebar) — it stays in the Inspector. Chapters and
            // overlays are real STEP CONTENT an author builds up over
            // several passes, the same category as the caption or the
            // chapter/checklist text everywhere else in this file — so,
            // per AJ's live call ("can they not be part of lesson
            // building"), they're authored directly in the canvas below,
            // not hidden behind a settings-sidebar panel a course author
            // has no reason to think to open.
            var sourcePicker = el(wp.blockEditor.InspectorControls, {}, el(wp.components.PanelBody, { title: __('Video settings') }, el(wp.components.SelectControl, {
                label: __('Source'),
                value: attrs.source,
                // Cloudflare Stream only appears as an option when
                // Tier B is actually enabled (the-self-hosted-self's Media &
                // CDN Setup, OUS_MediaWizard::tier_b_enabled(),
                // localized as window.bhcMediaTierB) — an install
                // that never opted in never sees it.
                options: [{ label: __('Uploaded file'), value: 'upload' }, { label: __('URL (oEmbed)'), value: 'url' }]
                    .concat((window.bhcMediaTierB && window.bhcMediaTierB.enabled) ? [{ label: __('Cloudflare Stream'), value: 'cloudflare_stream' }] : [])
                    // Private (signed) sources — only shown once configured in Media & CDN Setup (window.bhcMediaSigned).
                    .concat((window.bhcMediaSigned && window.bhcMediaSigned.bunny) ? [{ label: __('Bunny Stream (private)'), value: 'bunny_stream' }] : [])
                    .concat((window.bhcMediaSigned && window.bhcMediaSigned.r2) ? [{ label: __('Cloudflare R2 (private, signed)'), value: 'signed_r2' }] : []),
                onChange: function (v) { setAttrs({ source: v }); },
            })));
            var chaptersSection = el('details', { key: 'chapters', className: 'bhc-studio-subsection', open: chapters.length > 0 }, el('summary', {}, __('Chapters'), chapters.length ? el('span', { className: 'bhc-studio-subsection-count' }, chapters.length) : null), el('p', { className: 'description' }, __('Markers on the player\'s seek bar plus a clickable list beneath the video, like YouTube.')), chapters.length
                ? chapterRows
                : el('div', { className: 'bhc-studio-empty' }, previewUrl
                    ? __('No chapters yet. Scrub the preview above to a moment, then add a chapter — it picks up that timestamp automatically.')
                    : __('No chapters yet. Add one at the moments that matter — type a time if there\'s no preview to scrub yet.')), 
            // The magical bit: adding a chapter captures wherever the
            // preview is sitting RIGHT NOW, so the normal workflow is
            // "scrub, add, type a title" instead of "add, read the
            // time off the player, retype it as a number." Falls
            // back to 0 with no preview loaded.
            el(wp.components.Button, {
                variant: 'primary',
                onClick: function () {
                    var at = Math.floor(previewTime);
                    // Never silently stack two chapters on the same
                    // second — the second one would be an
                    // unreachable duplicate marker.
                    var taken = chapters.some(function (c) { return (c.time || 0) === at; });
                    setAttrs({ chapters: chapters.concat([{ time: taken ? at + 1 : at, title: '' }]) });
                },
            }, hasScrubPreview ? __("+ Add chapter at ") + fmtTime(previewTime) : __("+ Add chapter")));
            var overlaysSection = el('details', { key: 'overlays', className: 'bhc-studio-subsection', open: annotations.length > 0 }, el('summary', {}, __('Overlays'), annotations.length ? el('span', { className: 'bhc-studio-subsection-count' }, annotations.length) : null), el('p', { className: 'description' }, __('Note, Hotspot and Question pause the video at a timestamp and wait for a click. Banner is deliberately different — a caption that slides in and leaves on its own, without stopping playback.')), annotations.length ? annotationRows : el('div', { className: 'bhc-studio-empty' }, __('No overlays yet.')), el(wp.components.Button, {
                variant: 'secondary',
                onClick: function () { setAttrs({ annotations: annotations.concat([{ time: Math.floor(previewTime), type: 'note', payload: { text: '' } }]) }); },
            }, hasScrubPreview ? __("+ Add overlay at ") + fmtTime(previewTime) : __("+ Add overlay")));
            var videoAttachment = attrs.source === 'upload' ? useAttachment(attrs.attachment_id) : null;
            var sourceLabel = attrs.source === 'url' ? __('URL')
                : attrs.source === 'cloudflare_stream' ? __('Cloudflare Stream')
                    : attrs.source === 'bunny_stream' ? __('Bunny Stream')
                        : attrs.source === 'signed_r2' ? __('Cloudflare R2')
                            : __('Upload');
            var meta = sourceLabel
                + (chapters.length ? ' · ' + chapters.length + __(' chapters') : '')
                + (annotations.length ? ' · ' + annotations.length + __(' overlays') : '');
            var picker;
            if (attrs.source === 'url') {
                picker = el(wp.components.TextControl, {
                    key: 'src', label: __('Video URL'),
                    placeholder: __('https://youtube.com/watch?v=… or a direct video file URL'),
                    value: attrs.video_url,
                    onChange: function (v) { setAttrs({ video_url: v }); },
                });
            }
            else if (attrs.source === 'cloudflare_stream') {
                picker = el(wp.components.TextControl, {
                    key: 'src', label: __('Cloudflare Stream video UID'),
                    help: __('Upload the video to Cloudflare Stream yourself first, then paste the resulting UID here — a 32-character code from its dashboard/API response.'),
                    value: attrs.stream_uid,
                    onChange: function (v) { setAttrs({ stream_uid: v.trim().toLowerCase() }); },
                });
            }
            else if (attrs.source === 'bunny_stream') {
                var bunnyFileInput = el('input', {
                    key: 'file', type: 'file', accept: 'video/*', style: { display: 'none' }, ref: bunnyFileRef,
                    onChange: function (e) { var f = e.target.files && e.target.files[0]; if (f)
                        bunnyStartUpload(f); e.target.value = ''; },
                });
                picker = el('div', { key: 'src', className: 'bhc-studio-bunny-picker' }, el(wp.components.TextControl, {
                    label: __('Bunny Stream video GUID'),
                    help: (bunnyCfg && bunnyCfg.hasApi)
                        ? __('Pick from your library or upload below — or paste a GUID. Chapters, overlays and the watch threshold all work; playback links are signed per viewer and expire.')
                        : __('Paste the video\'s GUID (a UUID) from your Bunny Stream library. Add the Bunny API key in Media & CDN Setup to browse and upload right here instead.'),
                    value: attrs.bunny_guid,
                    onChange: function (v) { setAttrs({ bunny_guid: v.trim().toLowerCase() }); },
                }), (bunnyCfg && bunnyCfg.hasApi) ? el('div', { className: 'bhc-studio-bunny-actions', style: { display: 'flex', gap: '8px', flexWrap: 'wrap', marginBottom: '8px' } }, el(wp.components.Button, { variant: 'secondary', onClick: function () { setBunnyModalOpen(true); } }, __('Choose from Bunny library')), el(wp.components.Button, { variant: 'secondary', onClick: function () { var n = bunnyFileRef.current; if (n)
                        n.click(); } }, __('Upload new video')), bunnyFileInput) : null, bunnyUpload ? el('div', { className: 'bhc-studio-bunny-upload' }, bunnyUpload.error
                    ? el(wp.components.Notice, { status: 'error', isDismissible: false }, bunnyUpload.error)
                    : el('div', {}, el('progress', { value: bunnyUpload.pct, max: 100, style: { width: '100%' } }), el('p', { className: 'description' }, bunnyUpload.label || (bunnyUpload.pct + '%')))) : null, bunnyModalOpen ? el(BunnyLibraryModal, { cfg: bunnyCfg, onPick: bunnyPick, onClose: function () { setBunnyModalOpen(false); } }) : null, 
                // Signed preview — the same iframe the front end shows,
                // driven by player.js so "+ Add chapter at 0:42" picks
                // up the real playhead instead of asking for a typed
                // number.
                (attrs.bunny_guid && bunnyEmbedUrl) ? el('div', { key: 'prev', className: 'bhc-studio-preview bhc-studio-preview-bunny', style: { aspectRatio: '16 / 9', marginTop: '8px' } }, el('iframe', { ref: bunnyPlayerRef, src: bunnyEmbedUrl, style: { width: '100%', height: '100%', border: '0' }, allow: 'autoplay; encrypted-media; picture-in-picture', allowFullScreen: true })) : null);
            }
            else if (attrs.source === 'signed_r2') {
                picker = el(wp.components.TextControl, {
                    key: 'src', label: __('R2 object key'),
                    help: __('The path to the file inside your R2 bucket, e.g. courses/lesson-12/master.mp4. Served through your Worker with a per-viewer signed link — behaves exactly like an uploaded file, chapters and all.'),
                    value: attrs.r2_key,
                    onChange: function (v) { setAttrs({ r2_key: v.replace(/^\/+/, '') }); },
                });
            }
            else {
                var uploadButton = el(wp.blockEditor.MediaUploadCheck, { key: 'pick' }, el(wp.blockEditor.MediaUpload, {
                    allowedTypes: ['video'],
                    value: attrs.attachment_id,
                    // Immediate, cheap feedback before an author even
                    // finishes — BHC_VideoSettings::check_tree() still
                    // re-checks the real file server-side on save
                    // (authoritative; this is a convenience only, a
                    // REST/programmatic save never runs this JS at
                    // all). window.BHCMaxVideoMB is localized
                    // per-request (0 = no limit, the default — never
                    // blocks anything here). Deliberately no
                    // window.confirm()/alert() — a blocking native
                    // dialog here is a known hazard in this
                    // ecosystem's own automated QA tooling (and a
                    // worse UX generally); the file is always
                    // accepted, with a plain inline warning shown
                    // instead via over_limit_mb state.
                    onSelect: function (media) {
                        var maxMB = window.BHCMaxVideoMB || 0;
                        var sizeBytes = media.filesizeInBytes || (media.filesize ? media.filesize * 1024 : 0);
                        var overLimit = maxMB && sizeBytes && (sizeBytes / 1048576) > maxMB;
                        setAttrs({ attachment_id: media.id, over_limit_mb: overLimit ? Math.round(sizeBytes / 1048576) : 0 });
                    },
                    render: function (obj) {
                        return el(wp.components.Button, { variant: attrs.attachment_id ? 'secondary' : 'primary', onClick: obj.open }, attrs.attachment_id ? __('Change video') : __('Select video'));
                    },
                }));
                var overLimitWarning = (attrs.over_limit_mb && window.BHCMaxVideoMB && attrs.over_limit_mb > window.BHCMaxVideoMB)
                    // Recomputed against the CURRENT live limit on every
                    // render, not just trusted from the stored attribute
                    // — attrs.over_limit_mb was only ever correct at the
                    // moment the file was selected, so if the site's
                    // limit is later raised, lowered, or was 0 (no limit)
                    // all along, a stale stored value would otherwise
                    // keep showing a wrong warning.
                    ? el('p', { key: 'warn', className: 'bhc-video-size-warning', style: { color: '#b32d2e' } }, __('This file is about ') + attrs.over_limit_mb + __('MB, over this site\'s ') + window.BHCMaxVideoMB + __('MB direct-upload limit. Consider switching Source to "URL (oEmbed)" instead.'))
                    : null;
                picker = attrs.attachment_id
                    // A real thumbnail + file name, in place of the old
                    // bare "Change video" button with no indication of
                    // WHICH video was already chosen.
                    ? el('div', { key: 'chosen' }, 
                    // This IS the scrub source for chapters/overlays
                    // below (ref + time/duration wiring) — one real
                    // preview player doing double duty as "does this
                    // look right" AND "grab me this timestamp",
                    // rather than a second, redundant player.
                    previewUrl ? el('video', {
                        key: 'prev', ref: previewRef, className: 'bhc-studio-preview', src: previewUrl, controls: true, preload: 'metadata',
                        onTimeUpdate: function (e) { setPreviewTime(e.target.currentTime); },
                        onLoadedMetadata: function (e) { setPreviewDuration(isFinite(e.target.duration) ? e.target.duration : 0); },
                    })
                        : chosenFile('format-video', mediaName(videoAttachment, attrs.attachment_id), null, null), el('div', { key: 'row', style: { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '12px' } }, el('span', { style: { fontSize: '13px', fontWeight: 500, flex: 1, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }, mediaName(videoAttachment, attrs.attachment_id)), uploadButton), overLimitWarning)
                    : pickerPlaceholder('format-video', __('No video chosen yet'), __('Select the file this lesson step plays.'), uploadButton);
            }
            return el(wp.element.Fragment, {}, sourcePicker, stepShell('format-video', __('Video'), meta, blockProps, [
                picker,
                chaptersSection,
                overlaysSection,
                // Caption + completion rule grouped as one quiet
                // "options for this step" panel, rather than loose
                // controls trailing off after the media.
                el('div', { key: 'settings', className: 'bhc-studio-settings' }, el(wp.components.TextControl, {
                    key: 'cap', label: __('Caption'), value: attrs.caption,
                    placeholder: __('Optional — shown beneath the video'),
                    onChange: function (v) { setAttrs({ caption: v }); },
                }), el(wp.components.RangeControl, {
                    key: 'thresh',
                    label: __('Require watched % to auto-complete'),
                    value: attrs.watch_threshold,
                    min: 0,
                    max: 100,
                    onChange: function (v) { setAttrs({ watch_threshold: v || 0 }); },
                    help: attrs.source === 'cloudflare_stream'
                        ? __('Not enforceable for Cloudflare Stream yet — this plugin has no SDK for it, so the step always completes on any playback.')
                        : attrs.watch_threshold === 0
                            ? __('Off — any playback marks this step complete.')
                            : __('A student must watch at least this much before the step auto-completes.'),
                })),
            ]));
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
        supports: { html: false, customClassName: false },
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-resource bhc-studio-step' });
            var media = useAttachment(attrs.attachment_id);
            var picker = el(wp.blockEditor.MediaUploadCheck, { key: 'pick' }, el(wp.blockEditor.MediaUpload, {
                value: attrs.attachment_id,
                onSelect: function (m) {
                    // Default the label to the file's own name on
                    // first pick — a course creator can still
                    // override it, but "Worksheet.pdf" beats an
                    // empty label if they never touch this field.
                    setAttrs({ attachment_id: m.id, label: attrs.label || m.title || m.filename || '' });
                },
                render: function (obj) {
                    return el(wp.components.Button, { variant: attrs.attachment_id ? 'secondary' : 'primary', onClick: obj.open }, attrs.attachment_id ? __('Change file') : __('Select file'));
                },
            }));
            return stepShell('media-default', __('Resource'), null, blockProps, [
                attrs.attachment_id
                    ? chosenFile('media-default', mediaName(media, attrs.attachment_id), null, picker)
                    : pickerPlaceholder('media-default', __('No file chosen yet'), __('A worksheet, PDF, or reference doc a student can download from this step.'), picker),
                el(wp.components.TextControl, { key: 'label', label: __('Label'), value: attrs.label, placeholder: __('e.g. Worksheet.pdf'), onChange: function (v) { setAttrs({ label: v }); } }),
                el(wp.components.TextControl, { key: 'desc', label: __('Description'), value: attrs.description, placeholder: __('Optional'), onChange: function (v) { setAttrs({ description: v }); } }),
            ]);
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
        supports: { html: false, customClassName: false },
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-checklist bhc-studio-step' });
            var items = attrs.items || [];
            var rows = items.map(function (item, i) {
                return el('div', { key: i, className: 'bhc-studio-checklist-row' }, el('span', { className: 'bhc-studio-checklist-box' }), el(wp.components.TextControl, {
                    value: item,
                    placeholder: __('Checklist item…'),
                    onChange: function (v) {
                        var next = items.slice();
                        next[i] = v;
                        setAttrs({ items: next });
                    },
                }), el(wp.components.Button, {
                    variant: 'tertiary', isDestructive: true, icon: 'no-alt', label: __('Remove item'),
                    onClick: function () { setAttrs({ items: items.filter(function (_, idx) { return idx !== i; }) }); },
                }));
            });
            return stepShell('yes-alt', __('Checklist'), items.length ? items.length + (items.length === 1 ? __(' item') : __(' items')) : null, blockProps, [
                el(wp.components.TextControl, { key: 'title', label: __('Title'), value: attrs.title, placeholder: __('e.g. Before you export…'), onChange: function (v) { setAttrs({ title: v }); } }),
                items.length ? el('div', { key: 'rows' }, rows) : el('p', { key: 'empty', className: 'bhc-studio-empty-hint' }, __('No items yet — add the first thing a student should check off.')),
                el(wp.components.Button, { key: 'add', variant: 'secondary', onClick: function () { setAttrs({ items: items.concat(['']) }); } }, __('+ Add item')),
            ]);
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
        supports: { html: false, customClassName: false },
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-chord-chart bhc-studio-step' });
            return stepShell('editor-code', __('Chord/Tab Chart'), null, blockProps, [
                el(wp.components.TextControl, { key: 'title', label: __('Title'), value: attrs.title, placeholder: __('e.g. Verse'), onChange: function (v) { setAttrs({ title: v }); } }),
                el(wp.components.TextareaControl, {
                    key: 'content',
                    label: __('Chord/tab chart'),
                    className: 'bhc-studio-monospace',
                    help: __('Plain monospace text — alignment is preserved exactly as typed.'),
                    value: attrs.content,
                    rows: 8,
                    onChange: function (v) { setAttrs({ content: v }); },
                }),
            ]);
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
        supports: { html: false, customClassName: false },
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-audio-compare bhc-studio-step' });
            function ClipPicker(p) {
                var media = useAttachment(attrs[p.idKey]);
                var picker = el(wp.blockEditor.MediaUploadCheck, { key: 'pick' }, el(wp.blockEditor.MediaUpload, {
                    allowedTypes: ['audio'],
                    value: attrs[p.idKey],
                    onSelect: function (m) { setAttrs((function (o) { o[p.idKey] = m.id; return o; })({})); },
                    render: function (obj) {
                        return el(wp.components.Button, { variant: attrs[p.idKey] ? 'secondary' : 'primary', onClick: obj.open }, attrs[p.idKey] ? __('Change audio') : __('Select audio'));
                    },
                }));
                return el('div', { className: 'bhc-studio-audio-compare-clip' }, el(wp.components.TextControl, { label: __('Label'), value: attrs[p.labelKey], placeholder: p.defaultLabel, onChange: function (v) { setAttrs((function (o) { o[p.labelKey] = v; return o; })({})); } }), attrs[p.idKey]
                    ? chosenFile('controls-volumeon', mediaName(media, attrs[p.idKey]), null, picker)
                    : pickerPlaceholder('controls-volumeon', __('No clip chosen'), null, picker));
            }
            return stepShell('controls-volumeon', __('Audio A/B Compare'), null, blockProps, [
                el(ClipPicker, { key: 'a', idKey: 'attachment_id_a', labelKey: 'label_a', defaultLabel: 'A' }),
                el(ClipPicker, { key: 'b', idKey: 'attachment_id_b', labelKey: 'label_b', defaultLabel: 'B' }),
                el(wp.components.TextControl, { key: 'cap', label: __('Caption'), value: attrs.caption, placeholder: __('Optional'), onChange: function (v) { setAttrs({ caption: v }); } }),
            ]);
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
        supports: { html: false, customClassName: false },
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-quiz bhc-studio-step' });
            // Real child blocks, not a repeater — this is the whole
            // point of Section 3.2's promotion. allowedBlocks keeps
            // quiz-question scoped to living only inside a quiz. The
            // inner-blocks area IS the shell's body — passed as its own
            // className so stepShell's own header/body divs still frame
            // it consistently with every other step type.
            var innerBlocksProps = wp.blockEditor.useInnerBlocksProps({ className: 'bhc-studio-body' }, { allowedBlocks: ['bhc/quiz-question'], templateLock: false, renderAppender: AddQuestionAppender });
            var meta = __('Pass ') + attrs.passing_score + '%' + (attrs.max_attempts ? ', ' + attrs.max_attempts + __(' attempts') : '');
            return el(wp.element.Fragment, {}, el(wp.blockEditor.InspectorControls, {}, el(wp.components.PanelBody, { title: __('Quiz settings') }, el(wp.components.RangeControl, { label: __('Passing score (%)'), value: attrs.passing_score, onChange: function (v) { setAttrs({ passing_score: v }); }, min: 0, max: 100 }), el(wp.components.RangeControl, { label: __('Max attempts (0 = unlimited)'), value: attrs.max_attempts, onChange: function (v) { setAttrs({ max_attempts: v }); }, min: 0, max: 20 }), el(wp.components.ToggleControl, { label: __('Shuffle question order'), checked: attrs.shuffle_questions, onChange: function (v) { setAttrs({ shuffle_questions: v }); } }), el(wp.components.ToggleControl, { label: __('Shuffle answer order'), checked: attrs.shuffle_choices, onChange: function (v) { setAttrs({ shuffle_choices: v }); } }))), el('div', blockProps, el('div', { className: 'bhc-studio-head' }, el(wp.components.Icon, { icon: 'forms', size: 16 }), el('span', { className: 'bhc-studio-head-label' }, __('Quiz')), el('span', { className: 'bhc-studio-head-meta' }, meta)), el('div', innerBlocksProps)));
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
        supports: { html: false, customClassName: false },
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-quiz-question' });
            // This block's own index among its quiz siblings — InnerBlocks
            // renders each question as an equal sibling with no number of
            // its own, so a quiz of 10 questions read as an undifferentiated
            // stack of "Question" text fields with nothing to tell them
            // apart while scrolling. useBlockEditContext() + getBlockOrder()
            // recovers the real position instead of any per-block stored
            // index (which would drift the moment questions are reordered).
            var qClientId = wp.blockEditor.useBlockEditContext().clientId;
            var qParentId = wp.data.select('core/block-editor').getBlockRootClientId(qClientId);
            var qIndex = wp.data.select('core/block-editor').getBlockOrder(qParentId).indexOf(qClientId);
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
                return el('div', { key: i, className: 'bhc-studio-choice-row' }, el(wp.components.RadioControl, {
                    selected: attrs.correct_index === i ? 'correct' : '',
                    options: [{ label: '', value: 'correct' }],
                    onChange: function () { setAttrs({ correct_index: i }); },
                }), el(wp.components.TextControl, { value: c, placeholder: __('Choice text'), onChange: function (v) { setChoice(i, v); } }), 
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
                choices.length > 2 ? el(wp.components.Button, { icon: closeIcon(), label: __('Remove choice'), onClick: function () { removeChoice(i); } }) : null);
            });
            return el('div', blockProps, el('div', { className: 'bhc-studio-question-badge' }, __('Question ') + (qIndex > -1 ? qIndex + 1 : '?')), el(wp.components.TextControl, { label: __('Question'), value: attrs.question, placeholder: __('What do you ask the student?'), onChange: function (v) { setAttrs({ question: v }); } }), el('p', { className: 'description' }, __('Select the radio next to the correct choice.')), choiceRows, 
            // className carries its own margin-bottom (see
            // add_studio_block_editor_styles() in class-content-
            // bridge.php) — without it, this button sat directly
            // against Gutenberg's own "Add quiz question" block-
            // appender (a totally different action: a new question,
            // not a new choice), the two reading as one confusing
            // cluster of "add" buttons.
            el(wp.components.Button, { variant: 'secondary', className: 'bhc-studio-add-choice', onClick: addChoice }, __('Add choice')));
        },
        save: function () { return null; }, // dynamic
    });
    // ---- Lesson settings sidebar panel ----
    // Belongs-to-course, module/section and drip availability used to be
    // two 'normal' metaboxes Gutenberg buries in the collapsed "Meta
    // Boxes" seam below the steps canvas — disjoint from authoring.
    // They're a native editor sidebar panel now: steps in the canvas,
    // settings in the sidebar, one cohesive screen. Backed by the REST
    // meta BHC_Admin::register_lesson_meta() registers; the
    // course<->lesson inverse order is reconciled server-side
    // (rest_after_insert_bh_lesson). Wrapped in a guarded IIFE so a WP
    // build without wp.plugins / wp.editor / wp.coreData just no-ops
    // (same defensive posture as the rest of this file).
    (function registerLessonPanel() {
        var plugins = wp.plugins;
        var editorNs = wp.editor || wp.editPost;
        var coreData = wp.coreData;
        if (!plugins || !editorNs || !editorNs.PluginDocumentSettingPanel || !coreData || !coreData.useEntityProp || !wp.data || !wp.data.useSelect)
            return;
        var PluginDocumentSettingPanel = editorNs.PluginDocumentSettingPanel;
        var useEntityProp = coreData.useEntityProp;
        var useSelect = wp.data.useSelect;
        var c = wp.components;
        function LessonPanel() {
            var postType = useSelect(function (s) { return s('core/editor').getCurrentPostType(); }, []);
            if (postType !== 'bh_lesson')
                return null;
            var metaTuple = useEntityProp('postType', 'bh_lesson', 'meta');
            var meta = metaTuple[0] || {};
            var setMeta = metaTuple[1];
            var set = function (patch) { setMeta(Object.assign({}, meta, patch)); };
            var courses = useSelect(function (s) {
                return s('core').getEntityRecords('postType', 'bh_course', {
                    per_page: -1, status: 'publish,draft', orderby: 'title', order: 'asc', _fields: 'id,title,status',
                }) || [];
            }, []);
            var previewLink = useSelect(function (s) {
                var ed = s('core/editor');
                return (ed.getEditedPostPreviewLink && ed.getEditedPostPreviewLink()) || (ed.getCurrentPost() || {}).link || '';
            }, []);
            var courseId = parseInt(meta._bhc_course_id || 0, 10) || 0;
            var courseOptions = [{ label: __('— Not in a course yet —'), value: '0' }].concat((courses || []).map(function (co) {
                var t = (co.title && co.title.rendered) ? decodeEntities(co.title.rendered) : ('#' + co.id);
                return { label: t + (co.status === 'draft' ? __(' (draft)') : ''), value: String(co.id) };
            }));
            var chosen = (courses || []).filter(function (co) { return co.id === courseId; })[0];
            var chosenTitle = chosen && chosen.title && chosen.title.rendered ? decodeEntities(chosen.title.rendered) : __('the course');
            var afterDays = meta._bhc_available_after_days || '';
            var onDate = meta._bhc_available_on_date || '';
            return el(PluginDocumentSettingPanel, { name: 'bhc-lesson', title: __('Lesson'), className: 'bhc-lesson-panel' }, el(c.SelectControl, {
                label: __('Part of course'),
                value: String(courseId),
                options: courseOptions,
                onChange: function (v) { set({ _bhc_course_id: parseInt(v, 10) || 0 }); },
                __nextHasNoMarginBottom: true,
            }), courseId === 0
                ? el(c.Notice, { status: 'warning', isDismissible: false, className: 'bhc-lesson-panel-warn' }, __('This lesson isn’t in any course — students won’t see it anywhere until you pick one.'))
                : el('p', { className: 'bhc-lesson-panel-hint' }, el('a', { href: 'post.php?action=edit&post=' + courseId }, __('Open ') + chosenTitle + __(' to set lesson order →'))), el(c.TextControl, {
                label: __('Module / section'),
                help: __('Consecutive lessons sharing a module name group under one heading in the course sidebar. Optional.'),
                value: meta._bhc_module_title || '',
                onChange: function (v) { set({ _bhc_module_title: v }); },
                __nextHasNoMarginBottom: true,
            }), el('div', { className: 'bhc-lesson-panel-drip' }, el('p', { className: 'bhc-lesson-panel-group-label' }, __('Availability')), el('p', { className: 'bhc-lesson-panel-hint' }, __('Blank = opens when the course unlocks. Set at most one.')), el(c.TextControl, {
                type: 'number', min: 0, label: __('Days after a student enrolls'),
                value: afterDays, disabled: !!onDate,
                onChange: function (v) { set({ _bhc_available_after_days: v, _bhc_available_on_date: '' }); },
                __nextHasNoMarginBottom: true,
            }), el(c.TextControl, {
                type: 'date', label: __('… or a fixed date for everyone'),
                value: onDate, disabled: !!afterDays,
                onChange: function (v) { set({ _bhc_available_on_date: v, _bhc_available_after_days: '' }); },
                __nextHasNoMarginBottom: true,
            })), previewLink
                ? el(c.Button, { variant: 'secondary', href: previewLink, target: '_blank', rel: 'noopener', className: 'bhc-lesson-panel-preview' }, __('Preview as student ↗'))
                : null);
        }
        plugins.registerPlugin('bhc-lesson-panel', { render: LessonPanel });
    })();
})(window.wp);
