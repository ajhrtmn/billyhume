"use strict";
/**
 * Client half of the lesson step blocks (bhc/text, bhc/image, bhc/video, bhc/quiz,
 * bhc/quiz-question, ...); BHC_ContentBridge::register_block_types() registers the
 * server-side schema/renderers. Loaded on the bh_lesson block editor.
 *
 * bhc/quiz is an InnerBlocks container of bhc/quiz-question children (parent-restricted).
 * `wp` is typed as `any` deliberately: the surface is huge and the risk is runtime behavior.
 */
(function (wp) {
    'use strict';
    if (!wp || !wp.blocks || !wp.element || !wp.blockEditor || !wp.components || !wp.primitives || !wp.data)
        return;
    var el = wp.element.createElement;
    var __ = wp.i18n ? wp.i18n.__ : function (s) { return s; };
    // WHY: inline SVG, not wp.icons (not loaded on every install) or a Dashicon
    // (the dashicons stylesheet doesn't reach the iframed editor canvas).
    function closeIcon() {
        return el(wp.primitives.SVG, { viewBox: '0 0 24 24', width: 20, height: 20, xmlns: 'http://www.w3.org/2000/svg' }, el(wp.primitives.Path, { d: 'M13.06 12l6.47-6.47-1.06-1.06L12 10.94 5.53 4.47 4.47 5.53 10.94 12l-6.47 6.47 1.06 1.06L12 13.06l6.47 6.47 1.06-1.06L13.06 12z' }));
    }
    // Bunny Stream library browser; talks to BHC_Bunny's REST routes so the API key stays server-side.
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
    // A labelled "Add another question" appender, so it isn't confused with a
    // question's own "Add choice" button.
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
    // Deliberately minimal: a step is a content primitive, not a layout box.
    // The "Advanced Styles" panel is excluded for bhc/* in block-style-panel.ts.
    var SUPPORTS = { html: false, position: false, customClassName: false };
    /* ==================================================================
     * Shared authoring shell for every lesson step block. Styling lives in
     * class-content-bridge.php's `styles` array, the only channel that
     * reaches the editor's iframed canvas.
     * ================================================================== */
    /** The step's own frame: type header + body. */
    function stepShell(icon, label, meta, blockProps, children) {
        return el('div', blockProps, el('div', { className: 'bhc-studio-head' }, el(wp.components.Icon, { icon: icon, size: 16 }), el('span', { className: 'bhc-studio-head-label' }, label), meta ? el('span', { className: 'bhc-studio-head-meta' }, meta) : null), el('div', { className: 'bhc-studio-body' }, children));
    }
    /** Resolves an attachment id to its REST media object; null while loading or if gone. */
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
    /** Decodes HTML entities in REST `title.rendered`; the editor renders strings as text. */
    function decodeEntities(s) {
        if (!s || s.indexOf('&') === -1)
            return s;
        var t = document.createElement('textarea');
        t.innerHTML = s;
        return t.value;
    }
    /** Human file name for an attachment, falling back to its id. */
    function mediaName(media, fallbackId) {
        if (media && (media.title || media.slug)) {
            return decodeEntities((media.title && media.title.rendered) || media.slug || ('#' + fallbackId));
        }
        return fallbackId ? __('Attachment #') + fallbackId : '';
    }
    /** Shared "nothing chosen yet" placeholder. */
    function pickerPlaceholder(icon, title, help, button) {
        return el('div', { className: 'bhc-studio-placeholder' }, el(wp.components.Icon, { icon: icon, size: 24 }), el('p', { className: 'bhc-studio-placeholder-title' }, title), help ? el('p', { className: 'bhc-studio-placeholder-help' }, help) : null, button);
    }
    /** Thumbnail for one attachment id; a component because useAttachment is a hook. */
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
    /**
     * A labelled RichText caption field (bold/italic/link), styled like TextControl.
     * Inline formatting only; block-level content can't be built from one RichText.
     */
    function CaptionField(props) {
        return el('div', { className: 'bhc-caption-field components-base-control' }, el('label', { className: 'components-base-control__label' }, props.label), el(wp.blockEditor.RichText, {
            tagName: 'div',
            className: 'bhc-caption-richtext',
            value: props.value,
            placeholder: props.placeholder,
            onChange: props.onChange,
        }));
    }
    /** A chosen file shown as a row. */
    function chosenFile(icon, name, note, changeButton) {
        return el('div', { className: 'bhc-studio-chosen' }, el(wp.components.Icon, { icon: icon, size: 18 }), el('span', { className: 'bhc-studio-chosen-name' }, name), note ? el('span', { className: 'bhc-studio-chosen-note' }, note) : null, changeButton);
    }
    wp.blocks.registerBlockType('bhc/text', {
        apiVersion: 3,
        title: __('Lesson: Text'),
        icon: 'text',
        category: 'lms',
        // WHY: a plain attribute, not `source: 'html'`. PHP's parse_blocks() never runs
        // attribute sourcing, so a sourced attribute reads back empty for _bhc_steps sync.
        attributes: { content: { type: 'string', default: '' } },
        supports: Object.assign({}, SUPPORTS),
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-text bhc-studio-step' });
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
                    ? el('div', { key: 'thumbs', className: 'bhc-studio-image-thumbs' }, ids.map(function (id) { return el(AttachmentThumb, { key: id, id: id }); }))
                    : pickerPlaceholder('format-image', __('No image chosen yet'), __('Pick one or more images to show at this point in the lesson.'), picker),
                ids.length ? el('div', { key: 'change', style: { marginBottom: '12px' } }, picker) : null,
                el(CaptionField, {
                    key: 'cap',
                    label: __('Caption'), value: attrs.caption,
                    placeholder: __('Optional — shown beneath the image'),
                    onChange: function (v) { setAttrs({ caption: v }); },
                }),
            ]);
        },
        save: function () { return null; }, // dynamic; rendered server-side by BHC_ContentBridge
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
            // Cloudflare Stream video UID, pasted in manually.
            stream_uid: { type: 'string', default: '' },
            // Private (signed) delivery via BHY_MediaToken; secrets are site-wide config.
            bunny_guid: { type: 'string', default: '' },
            r2_key: { type: 'string', default: '' },
            caption: { type: 'string', default: '' },
            // 0 means any playback marks it complete.
            watch_threshold: { type: 'number', default: 0 },
            // Editor-only warning flag; the server re-derives file size on save.
            over_limit_mb: { type: 'number', default: 0 },
            // [{ time, type, payload }]
            annotations: { type: 'array', default: [] },
            // [{ time, title }]. Rendered as a strip below the video, since native
            // <video controls> can't draw markers on its own seek bar.
            chapters: { type: 'array', default: [] },
        },
        supports: { html: false, customClassName: false },
        edit: function (props) {
            var attrs = props.attributes, setAttrs = props.setAttributes;
            var blockProps = wp.blockEditor.useBlockProps({ className: 'bhc-studio-video bhc-studio-step' });
            // Source picker lives in the Inspector, since inline it sat under the floating toolbar.
            // The preview lets an author scrub and grab a timestamp with one click.
            var previewUrlState = wp.element.useState('');
            var previewUrl = previewUrlState[0], setPreviewUrl = previewUrlState[1];
            var previewRef = wp.element.useRef(null);
            // Playhead mirrored into state so "Add chapter at 0:42" labels stay current
            // and the past-the-end warning has a duration to compare against.
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
            var bunnySyncState = wp.element.useState(null);
            var bunnySync = bunnySyncState[0], setBunnySync = bunnySyncState[1];
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
            // True when there's a preview whose playhead the "Now" buttons can read.
            var hasScrubPreview = !!previewUrl || (attrs.source === 'bunny_stream' && !!bunnyEmbedUrl);
            var chapters = attrs.chapters || [];
            function updateChapter(i, patch) {
                var next = chapters.slice();
                next[i] = Object.assign({}, next[i], patch);
                setAttrs({ chapters: next });
            }
            // Display-only mm:ss (stored as seconds); mirrors formatTime() in courses.ts.
            function fmtTime(total) {
                var s = Math.max(0, Math.floor(total || 0));
                return Math.floor(s / 60) + ':' + (s % 60 < 10 ? '0' : '') + (s % 60);
            }
            // Chapters play in time order (sanitize_chapters() sorts on save); show that
            // position without reordering rows mid-edit.
            var playbackOrder = chapters
                .map(function (c, i) { return { i: i, time: c.time || 0 }; })
                .sort(function (a, b) { return a.time - b.time; })
                .map(function (r) { return r.i; });
            var chapterRows = chapters.map(function (c, i) {
                var isPastEnd = previewDuration > 0 && (c.time || 0) > previewDuration;
                var untitled = !(c.title || '').trim();
                return el('div', { key: i, className: 'bhc-studio-chapter-row' + (isPastEnd || untitled ? ' has-warning' : '') }, el('div', { className: 'bhc-studio-chapter-row-top' }, 
                // Playback position, not array position.
                el('span', { className: 'bhc-studio-order-badge' }, String(playbackOrder.indexOf(i) + 1)), el(wp.components.TextControl, {
                    type: 'number', label: __('Start time, in seconds'), hideLabelFromVision: true,
                    className: 'bhc-studio-time-input', value: c.time || 0,
                    onChange: function (v) { updateChapter(i, { time: Math.max(0, parseInt(v, 10) || 0) }); },
                }), el('span', { className: 'bhc-studio-time-unit' }, __('sec')), hasScrubPreview ? el(wp.components.Button, {
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
                // Untitled chapters are dropped on save; past-the-end ones render no marker.
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
            // Overlay moments that pause this video at a timestamp and resume on dismiss.
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
            // Source is the defining choice for a video step, so it sits inline at the top.
            // Chapters and overlays are step content, authored in the canvas.
            var sourcePicker = el('div', { key: 'src', className: 'bhc-studio-source' }, el(wp.components.SelectControl, {
                label: __('Video source'),
                value: attrs.source,
                // Optional sources appear only when enabled/configured
                // (window.bhcMediaTierB, window.bhcMediaSigned).
                options: [{ label: __('Uploaded file'), value: 'upload' }, { label: __('URL (oEmbed)'), value: 'url' }]
                    .concat((window.bhcMediaTierB && window.bhcMediaTierB.enabled) ? [{ label: __('Cloudflare Stream'), value: 'cloudflare_stream' }] : [])
                    .concat((window.bhcMediaSigned && window.bhcMediaSigned.bunny) ? [{ label: __('Bunny Stream (private)'), value: 'bunny_stream' }] : [])
                    .concat((window.bhcMediaSigned && window.bhcMediaSigned.r2) ? [{ label: __('Cloudflare R2 (private, signed)'), value: 'signed_r2' }] : []),
                onChange: function (v) { setAttrs({ source: v }); },
                __nextHasNoMarginBottom: true,
            }));
            var chaptersSection = el('details', { key: 'chapters', className: 'bhc-studio-subsection', open: chapters.length > 0 }, el('summary', {}, __('Chapters'), chapters.length ? el('span', { className: 'bhc-studio-subsection-count' }, chapters.length) : null), el('p', { className: 'description' }, __('Markers on the player\'s seek bar plus a clickable list beneath the video, like YouTube.')), attrs.source === 'bunny_stream'
                ? el('p', { className: 'description' }, __('↪ These also sync to Bunny\'s own player (its scrub bar) each time you save the lesson.'))
                : null, (attrs.source === 'bunny_stream' && bunnyCfg && bunnyCfg.hasApi && attrs.bunny_guid)
                ? el('div', { className: 'bhc-studio-bunny-sync', style: { display: 'flex', alignItems: 'center', gap: '8px', margin: '4px 0 8px' } }, el(wp.components.Button, {
                    variant: 'secondary',
                    isBusy: !!(bunnySync && bunnySync.busy),
                    disabled: !!(bunnySync && bunnySync.busy) || !chapters.length,
                    onClick: function () {
                        setBunnySync({ busy: true });
                        wp.apiFetch({
                            url: bunnyCfg.restBase + '/sync-chapters',
                            method: 'POST',
                            headers: { 'X-WP-Nonce': bunnyCfg.nonce, 'Content-Type': 'application/json' },
                            body: JSON.stringify({ guid: attrs.bunny_guid, chapters: chapters }),
                        }).then(function (r) {
                            setBunnySync({ ok: (r && r.synced) || 0 });
                        }).catch(function (e) {
                            setBunnySync({ error: (e && e.message) || __('Sync failed.') });
                        });
                    },
                }, __('Sync chapters to Bunny now')), bunnySync && typeof bunnySync.ok === 'number'
                    ? el('span', { className: 'description', style: { color: '#1a7f37' } }, __('Pushed ') + bunnySync.ok + __(' to Bunny.'))
                    : null, bunnySync && bunnySync.error
                    ? el('span', { className: 'description', style: { color: '#cc1818' } }, bunnySync.error)
                    : null)
                : null, chapters.length
                ? chapterRows
                : el('div', { className: 'bhc-studio-empty' }, previewUrl
                    ? __('No chapters yet. Scrub the preview above to a moment, then add a chapter — it picks up that timestamp automatically.')
                    : __('No chapters yet. Add one at the moments that matter — type a time if there\'s no preview to scrub yet.')), 
            // Adding a chapter captures the preview's current playhead (0 with no preview).
            el(wp.components.Button, {
                variant: 'primary',
                onClick: function () {
                    var at = Math.floor(previewTime);
                    // Avoid two chapters on the same second (an unreachable duplicate marker).
                    var taken = chapters.some(function (c) { return (c.time || 0) === at; });
                    setAttrs({ chapters: chapters.concat([{ time: taken ? at + 1 : at, title: '' }]) });
                },
            }, hasScrubPreview ? __("+ Add chapter at ") + fmtTime(previewTime) : __("+ Add chapter")));
            var overlaysSection = el('details', { key: 'overlays', className: 'bhc-studio-subsection', open: annotations.length > 0 }, el('summary', {}, __('Overlays'), annotations.length ? el('span', { className: 'bhc-studio-subsection-count' }, annotations.length) : null), el('p', { className: 'description' }, __('Note, Hotspot and Question pause the video at a timestamp and wait for a click. Banner is deliberately different — a caption that slides in and leaves on its own, without stopping playback.')), annotations.length ? annotationRows : el('div', { className: 'bhc-studio-empty' }, __('No overlays yet.')), el(wp.components.Button, {
                variant: 'secondary',
                onClick: function () { setAttrs({ annotations: annotations.concat([{ time: Math.floor(previewTime), type: 'note', payload: { text: '' } }]) }); },
            }, hasScrubPreview ? __("+ Add overlay at ") + fmtTime(previewTime) : __("+ Add overlay")));
            // WHY: useAttachment() is a hook and must run unconditionally; a conditional call
            // trips React error #300 when Source changes. Pass 0 when not an upload.
            var videoAttachment = useAttachment(attrs.source === 'upload' ? attrs.attachment_id : 0);
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
                // Signed preview iframe, driven by player.js so "Now" reads the real playhead.
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
                    // Convenience warning only; BHC_VideoSettings::check_tree() is authoritative
                    // on save. WHY: no window.confirm()/alert(), which blocks automated QA tooling.
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
                    // Re-checked against the current limit each render, since the stored value can go stale.
                    ? el('p', { key: 'warn', className: 'bhc-video-size-warning', style: { color: '#b32d2e' } }, __('This file is about ') + attrs.over_limit_mb + __('MB, over this site\'s ') + window.BHCMaxVideoMB + __('MB direct-upload limit. Consider switching Source to "URL (oEmbed)" instead.'))
                    : null;
                picker = attrs.attachment_id
                    ? el('div', { key: 'chosen' }, 
                    // This preview is also the scrub source for chapters/overlays.
                    previewUrl ? el('video', {
                        key: 'prev', ref: previewRef, className: 'bhc-studio-preview', src: previewUrl, controls: true, preload: 'metadata',
                        onTimeUpdate: function (e) { setPreviewTime(e.target.currentTime); },
                        onLoadedMetadata: function (e) { setPreviewDuration(isFinite(e.target.duration) ? e.target.duration : 0); },
                    })
                        : chosenFile('format-video', mediaName(videoAttachment, attrs.attachment_id), null, null), el('div', { key: 'row', style: { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '12px' } }, el('span', { style: { fontSize: '13px', fontWeight: 500, flex: 1, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }, mediaName(videoAttachment, attrs.attachment_id)), uploadButton), overLimitWarning)
                    : pickerPlaceholder('format-video', __('No video chosen yet'), __('Select the file this lesson step plays.'), uploadButton);
            }
            return el(wp.element.Fragment, {}, stepShell('format-video', __('Video'), meta, blockProps, [
                sourcePicker,
                picker,
                chaptersSection,
                overlaysSection,
                el('div', { key: 'settings', className: 'bhc-studio-settings' }, el(CaptionField, {
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
    // A downloadable file attached to a step; any file type is allowed.
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
                    // Default the label to the file's own name on first pick.
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
                el(CaptionField, { key: 'desc', label: __('Description'), value: attrs.description, placeholder: __('Optional'), onChange: function (v) { setAttrs({ description: v }); } }),
            ]);
        },
        save: function () { return null; }, // dynamic; rendered server-side by BHC_ContentBridge
    });
    // Checklist, chord-chart and audio-compare are dynamic blocks rendered by BHC_ContentBridge.
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
                el(CaptionField, { key: 'cap', label: __('Caption'), value: attrs.caption, placeholder: __('Optional'), onChange: function (v) { setAttrs({ caption: v }); } }),
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
            // Questions are real child blocks; the inner-blocks area doubles as the shell's body.
            var innerBlocksProps = wp.blockEditor.useInnerBlocksProps({ className: 'bhc-studio-body' }, { allowedBlocks: ['bhc/quiz-question'], templateLock: false, renderAppender: AddQuestionAppender });
            var meta = __('Pass ') + attrs.passing_score + '%' + (attrs.max_attempts ? ', ' + attrs.max_attempts + __(' attempts') : '');
            return el(wp.element.Fragment, {}, el(wp.blockEditor.InspectorControls, {}, el(wp.components.PanelBody, { title: __('Quiz settings') }, el(wp.components.RangeControl, { label: __('Passing score (%)'), value: attrs.passing_score, onChange: function (v) { setAttrs({ passing_score: v }); }, min: 0, max: 100 }), el(wp.components.RangeControl, { label: __('Max attempts (0 = unlimited)'), value: attrs.max_attempts, onChange: function (v) { setAttrs({ max_attempts: v }); }, min: 0, max: 20 }), el(wp.components.ToggleControl, { label: __('Shuffle question order'), checked: attrs.shuffle_questions, onChange: function (v) { setAttrs({ shuffle_questions: v }); } }), el(wp.components.ToggleControl, { label: __('Shuffle answer order'), checked: attrs.shuffle_choices, onChange: function (v) { setAttrs({ shuffle_choices: v }); } }))), el('div', blockProps, el('div', { className: 'bhc-studio-head' }, el(wp.components.Icon, { icon: 'forms', size: 16 }), el('span', { className: 'bhc-studio-head-label' }, __('Quiz')), el('span', { className: 'bhc-studio-head-meta' }, meta)), el('div', innerBlocksProps)));
        },
        save: function () {
            // WHY: not dynamic at the container level; innerBlocks must serialize as real
            // child blocks so BH_Content::validate()/render() see them.
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
            // Position among sibling questions, derived live so it never drifts on reorder.
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
                // WHY: closeIcon() is inline SVG; icon: 'no-alt' renders 0x0 in the iframed canvas.
                choices.length > 2 ? el(wp.components.Button, { icon: closeIcon(), label: __('Remove choice'), onClick: function () { removeChoice(i); } }) : null);
            });
            return el('div', blockProps, el('div', { className: 'bhc-studio-question-badge' }, __('Question ') + (qIndex > -1 ? qIndex + 1 : '?')), el(wp.components.TextControl, { label: __('Question'), value: attrs.question, placeholder: __('What do you ask the student?'), onChange: function (v) { setAttrs({ question: v }); } }), el('p', { className: 'description' }, __('Select the radio next to the correct choice.')), choiceRows, 
            // Its own margin keeps it apart from the "Add another question" appender.
            el(wp.components.Button, { variant: 'secondary', className: 'bhc-studio-add-choice', onClick: addChoice }, __('Add choice')));
        },
        save: function () { return null; }, // dynamic
    });
    // ---- Lesson settings sidebar panel ----
    // Backed by the REST meta from BHC_Admin::register_lesson_meta(); course<->lesson order
    // is reconciled server-side. No-ops without wp.plugins / wp.editor / wp.coreData.
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
    // ---- Always-visible "Add a step" dock ----
    // WHY: Gutenberg's end-of-list appender often doesn't render on mobile, so this
    // portalled button always opens the inserter after the last block.
    (function registerAddStepDock() {
        var plugins = wp.plugins;
        var createPortal = wp.element.createPortal;
        if (!plugins || !createPortal || !wp.data || !wp.data.useSelect)
            return;
        var useSelect = wp.data.useSelect;
        function AddStepDock() {
            var info = useSelect(function (s) {
                var ed = s('core/editor');
                var be = s('core/block-editor');
                var inserterOpen = false;
                try {
                    var eStore = s('core/editor');
                    var pStore = s('core/edit-post');
                    inserterOpen = !!((eStore && eStore.isInserterOpened && eStore.isInserterOpened())
                        || (pStore && pStore.isInserterOpened && pStore.isInserterOpened()));
                }
                catch (e) { /* ignore */ }
                return {
                    postType: ed && ed.getCurrentPostType ? ed.getCurrentPostType() : null,
                    count: be && be.getBlockCount ? be.getBlockCount() : 0,
                    inserterOpen: inserterOpen,
                };
            }, []);
            if (info.postType !== 'bh_lesson' || info.inserterOpen)
                return null;
            function openInserter() {
                // The toggle is on core/editor (current WP) or core/edit-post (older), not
                // core/block-editor. Try the positioned-open object, then a plain boolean.
                var stores = ['core/editor', 'core/edit-post'];
                for (var i = 0; i < stores.length; i++) {
                    var d = wp.data.dispatch(stores[i]);
                    if (!d || typeof d.setIsInserterOpened !== 'function')
                        continue;
                    try {
                        d.setIsInserterOpened({ rootClientId: undefined, insertionIndex: info.count, tab: 'blocks' });
                    }
                    catch (e) {
                        try {
                            d.setIsInserterOpened(true);
                        }
                        catch (e2) {
                            continue;
                        }
                    }
                    return;
                }
            }
            return createPortal(el('div', { className: 'bhc-add-step-dock' + (info.count === 0 ? ' is-empty' : '') }, el(wp.components.Button, {
                variant: 'primary',
                icon: 'plus',
                className: 'bhc-add-step-dock-btn',
                onClick: openInserter,
            }, __('Add a step'))), document.body);
        }
        plugins.registerPlugin('bhc-add-step-dock', { render: AddStepDock });
    })();
})(window.wp);
