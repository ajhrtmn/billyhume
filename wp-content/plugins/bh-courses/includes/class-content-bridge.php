<?php
if (!defined('ABSPATH')) exit;

// BHC_VER 0.4.9 — real-post-editor migration: bh_lesson now has actual
// 'editor' support (class-post-types.php) instead of only ever being
// reachable through BH_Studio's separate, hand-built canvas — direct
// response to a live-session finding that Studio's canvas has no theme
// CSS, no design tokens, and no Advanced Styles panel (none of that
// reaches a manually-bootstrapped BlockEditorProvider the way it
// reaches the real editor screen's block_editor_settings_all/
// enqueue_block_editor_assets hooks), so lesson authoring visibly felt
// like a different product from every other page in the ecosystem.
// Storage moves from a bespoke `bhc_lesson` BH_Content context (a
// custom table row, reachable only via Studio's generic REST route) to
// the REAL `post` context BH_Content::get()/save() already supports —
// parse_blocks()/serialize_blocks() against this lesson's own
// post_content, exactly like any ordinary page. bhc/text|image|video|
// quiz|quiz-question (courses-studio-blocks.js) needed ZERO changes —
// they were already real wp.blocks.registerBlockType() blocks with
// real edit()/save(), only ever enqueued in the wrong place. Nothing
// about front-end rendering changes: BHC_Render/BHC_Progress/BHC_Gate/
// BHC_Steps::score_quiz() still read the legacy `_bhc_steps` postmeta
// array exclusively, kept in sync by sync_legacy_steps() below (a
// save_post_bh_lesson hook — fires on every real save regardless of
// which screen triggered it, a more reliable choke point than the
// single Studio-REST call site save_tree() previously, and only ever
// aspirationally, claimed to be the sole writer: Studio's generic REST
// route (class-studio.php's rest_save()) actually calls
// BH_Content::save() directly and never called save_tree() at all —
// a real, if inert, bug this migration sidesteps rather than fixes in
// place, since lessons no longer save through that route). Studio
// itself is untouched and stays the right tool for genuinely postless
// contexts (storefront collection pages, keyed to a WooCommerce
// taxonomy term with no post ID to give a real editor screen) — this
// migration only concerns bh_lesson, which always had a real post ID
// and never needed a bespoke canvas in the first place.

/**
 * The concrete BH_Content consumer this handoff asked for (per
 * ROADMAP-platform-evolution.md's own suggestion — bh-courses' lesson-step
 * authoring is "the concrete LMS payoff" of the block-builder interface).
 *
 * Entirely `class_exists('BH_Content')`-guarded (never at file-parse
 * time — this file isn't even required if the core doesn't define
 * BH_Content, see bh-courses.php's own bootstrap), same optional-
 * dependency convention as every other cross-plugin touch in this
 * ecosystem.
 *
 * Deliberate scope, matching the roadmap doc's own framing ("a step
 * just becomes one top-level block in the lesson's content tree
 * instead of one entry in a fixed-type array" — a storage/authoring-
 * model change, not a promise that class-render.php/class-progress.php's
 * step-INDEX-based progress tracking changes shape too): a lesson's
 * steps are readable/writable as a real BH_Content block tree
 * (`bhc/text`, `bhc/image`, `bhc/video`, `bhc/quiz` + `bhc/quiz-question`
 * as of the LMS-authoring wiring pass — see LMS-AUTHORING-DESIGN-PLAN.md),
 * AND kept in sync with the existing `_bhc_steps` postmeta array —
 * so BHC_Progress/BHC_Render/BHC_Gate, all written against the
 * step-index array, keep working completely unchanged.
 * `sync_legacy_steps()` (a `save_post_bh_lesson` hook, fires on every
 * real save of a lesson regardless of which screen triggered it) is
 * the ONLY writer of a lesson's step content; class-admin.php's old
 * repeater metabox was retired outright rather than left as a second
 * writer of the same data (a real dual-write hazard the design doc
 * flagged — see class-admin.php's own docblock for how it was closed).
 */
class BHC_ContentBridge {
    const CONTEXT = 'post';

    public static function init(): void {
        if (!class_exists('BH_Content')) return;
        self::register_block_types();

        // A concrete, clickable way to hydrate a lesson's real
        // post_content from its current step data (the one-time
        // migration — see migrate_lesson()'s own docblock) — same
        // "any plugin registers a seed/reset section"
        // shared Debug Tools pattern every other plugin already uses.
        if (class_exists('OUS_Debug')) {
            add_filter('ous_debug_tools', [self::class, 'register_debug_tool']);
        }

        // bhc/* now load on the REAL bh_lesson block-editor screen —
        // real wp.blocks.registerBlockType() blocks (courses-studio-
        // blocks.js) needed zero changes, only where they're enqueued.
        add_action('enqueue_block_editor_assets', [self::class, 'maybe_enqueue_lesson_blocks']);
        add_filter('block_categories_all', [self::class, 'register_block_category']);
        add_filter('block_editor_settings_all', [self::class, 'add_studio_block_editor_styles'], 10, 2);
        add_filter('allowed_block_types_all', [self::class, 'curate_lesson_inserter'], 10, 2);

        // The ONLY writer of `_bhc_steps` now — fires on every real
        // save of a bh_lesson post (the real editor, REST, anywhere),
        // reads the tree straight back out of the post's own
        // post_content it was just saved into, and converts it via the
        // existing tree_to_steps() (unchanged — same conversion the
        // Studio-authoring pass already wrote and validated).
        add_action('save_post_bh_lesson', [self::class, 'sync_legacy_steps']);
    }

    /** Skips autosaves/revisions (the standard WP guard every other save_post consumer in this ecosystem uses), then re-derives `_bhc_steps` from this lesson's own just-saved post_content. */
    public static function sync_legacy_steps(int $post_id): void {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
        if (!class_exists('BHC_Steps')) return;
        $tree = self::get_tree($post_id);
        BHC_Steps::save($post_id, self::tree_to_steps($tree));
        if (class_exists('BHC_VideoSettings')) BHC_VideoSettings::check_tree($post_id, $tree);
    }

    // Purely cosmetic (the inserter groups unregistered categories under
    // "Uncategorized" otherwise, not a functional break) but cheap and
    // worth doing — 'lms' is used by every block type registered below.
    /**
     * @param array<int, array<string, mixed>> $categories
     * @return array<int, array<string, mixed>>
     */
    public static function register_block_category($categories): array {
        foreach ($categories as $c) {
            if (($c['slug'] ?? '') === 'lms') return $categories; // another plugin already added it
        }
        $categories[] = ['slug' => 'lms', 'title' => 'LMS (BH Courses)'];
        return $categories;
    }

    // Loads bhc/* on the real bh_lesson block-editor screen only —
    // enqueue_block_editor_assets fires for every block editor screen
    // (any post type, plus the site editor), so this still needs its
    // own narrow check rather than assuming "fired at all" means
    // "fired for a lesson." get_current_screen() is always available
    // by the time this action fires (core guarantees it).
    public static function maybe_enqueue_lesson_blocks(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'bh_lesson') return;
        wp_enqueue_script(
            'bhc-courses-studio-blocks',
            defined('BHC_URL') ? BHC_URL . 'assets/js/courses-studio-blocks.js' : plugins_url('assets/js/courses-studio-blocks.js', dirname(__FILE__)),
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-api-fetch', 'wp-data', 'wp-plugins', 'wp-editor', 'wp-core-data', 'wp-i18n', 'wp-url'],
            defined('BHC_VER') ? BHC_VER : null,
            true
        );
        // OSS-integration master plan Phase 6 follow-up — the video
        // step's Cloudflare Stream source option only appears in the
        // block editor's SelectControl when Tier B was actually turned
        // on in Media & CDN Setup (the-self-hosted-self's OUS_MediaWizard), so an
        // install that never opted into Tier B never even sees the
        // option, let alone can accidentally pick it.
        wp_localize_script('bhc-courses-studio-blocks', 'bhcMediaTierB', [
            'enabled' => class_exists('OUS_MediaWizard') && OUS_MediaWizard::tier_b_enabled(),
        ]);

        // Private (signed) video sources — Bunny Stream and Cloudflare
        // R2 + Worker (BHY_MediaToken). Same "the option only appears
        // once it's actually configured" rule as Tier B above.
        $signed = class_exists('BHY_MediaToken') ? BHY_MediaToken::js_config() : ['bunny' => false, 'bunnyApi' => false, 'r2' => false];
        wp_localize_script('bhc-courses-studio-blocks', 'bhcMediaSigned', $signed);

        // In-editor Bunny library browser / uploader / chapter preview
        // (BHC_Bunny). player.js drives the preview iframe the same way
        // the front end does; tus-js-client (vendored, UMD global) does
        // the resumable direct-to-Bunny upload. Only loaded when Bunny is
        // actually wired up — a lesson with no Bunny step pays nothing.
        if (!empty($signed['bunny'])) {
            wp_enqueue_script('bhc-bunny-playerjs', 'https://assets.mediadelivery.net/playerjs/player-0.1.0.min.js', [], null, true);
            wp_enqueue_script('bhc-tus', (defined('BHC_URL') ? BHC_URL : plugins_url('', dirname(__FILE__)) . '/') . 'assets/js/vendor/tus.min.js', [], '3.7.5', true);
            wp_localize_script('bhc-courses-studio-blocks', 'bhcBunny', [
                'restBase' => esc_url_raw(rest_url('bhc/v1/bunny')),
                'nonce'    => wp_create_nonce('wp_rest'),
                'hasApi'   => !empty($signed['bunnyApi']),
            ]);
        }
    }

    // Real bug, caught live: bhc/quiz-question's own edit view renders
    // each answer's radio + text input inside a plain
    // '.bhc-studio-choice-row' div with zero CSS anywhere in the
    // plugin — RadioControl/TextControl are both block-level Gutenberg
    // components, so with no flex styling they stacked vertically
    // (radio on its own line, ABOVE the choice text field) instead of
    // reading as one row.
    //
    // A plain wp_enqueue_style() hooked to enqueue_block_editor_assets
    // (tried first) never actually reaches this content: since WP 5.9
    // the post editor's canvas is a SEPARATE IFRAMED document, and
    // ordinary enqueued stylesheets only load in the outer admin
    // document, never inside that iframe — confirmed live (the
    // stylesheet loaded, `.bhc-studio-choice-row` inside the iframe
    // still computed `display: block`). The `styles` array on
    // `block_editor_settings_all` is core's own actual mechanism for
    // getting CSS into the iframe (the same one theme.json/
    // add_editor_style() use) — each entry's `css` string gets
    // collected into the iframe's own stylesheet.
    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    // OPEN.md item 21: "a curated LMS-specific inserter palette rather
    // than the generic Studio list." A lesson's editor was the full
    // native block editor (show_in_rest => true above), so its
    // inserter offered every core block WordPress ships — paragraph,
    // gallery, columns, embed, buttons, social icons, dozens more — none
    // of which a lesson step actually uses; every real step type is one
    // of the bhc/* blocks courses-studio-blocks.ts registers. Scoped to
    // ONLY bh_lesson (every other post type's editor, including this
    // ecosystem's own bh_course/bh_contest/etc., is untouched) and
    // returns the bhc/* list itself, not a broader allowlist — this
    // plugin's own bhc/text and bhc/image already cover the "plain
    // prose" and "plain picture" cases a generic core paragraph/image
    // block would otherwise be reached for.
    /**
     * @param bool|array<int, string> $allowed_block_types
     * @return bool|array<int, string>
     */
    public static function curate_lesson_inserter($allowed_block_types, \WP_Block_Editor_Context $context) {
        $post_type = $context->post ? get_post_type($context->post) : null;
        if ($post_type !== 'bh_lesson') return $allowed_block_types;

        return [
            'bhc/text', 'bhc/image', 'bhc/callout', 'bhc/video', 'bhc/resource',
            'bhc/checklist', 'bhc/chord-chart', 'bhc/audio-compare', 'bhc/quiz', 'bhc/quiz-question',
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function add_studio_block_editor_styles(array $settings, \WP_Block_Editor_Context $context): array {
        $screen = $context->post ? get_post_type($context->post) : null;
        if ($screen !== 'bh_lesson') return $settings;

        $settings['styles'][] = ['css' => '
            /* ---- lesson authoring canvas rhythm ----
               A lesson is a SEQUENCE of steps; the canvas should read as
               that sequence, not a pile of full-bleed cards. The width
               cap goes on .bhc-studio-step itself (and the appender) so
               it holds no matter what content width the active theme
               sets for the editor iframe — a selector on the editor\'s
               own root/layout wrappers is fragile across WP versions and
               was letting the theme\'s full-width layout win. */
            /* .bhc-studio-step is applied to the .wp-block element
               itself (useBlockProps className), so this targets the
               block wrapper directly. !important because the active
               theme\'s constrained-layout rule sets .wp-block max-width
               from its own (wide/full) content size at a specificity
               this would otherwise lose to. */
            .editor-styles-wrapper .bhc-studio-step {
                max-width: 680px !important;
                margin-left: auto !important;
                margin-right: auto !important;
            }
            /* Consistent gap between consecutive steps, whatever the
               theme\'s block-gap is. */
            .wp-block[data-type^="bhc/"] + .wp-block[data-type^="bhc/"] {
                margin-top: 22px;
            }
            /* The end-of-list appender — an obvious "Add a step" bar,
               same width as the steps, instead of Gutenberg\'s faint
               inter-block "+". Width/centre goes on the button itself
               (its wrapper .block-list-appender is sized by the theme
               layout at a specificity a plain class would lose to). */
            .editor-styles-wrapper .block-list-appender { margin-top: 22px; }
            .editor-styles-wrapper .block-editor-default-block-appender__content,
            .editor-styles-wrapper .block-list-appender__toggle,
            .editor-styles-wrapper .block-list-appender .block-editor-button-block-appender {
                display: flex !important;
                align-items: center;
                justify-content: center;
                gap: 8px;
                width: 100%;
                max-width: 680px !important;
                margin-left: auto !important;
                margin-right: auto !important;
                min-height: 48px;
                border: 1px dashed #b8bcc4 !important;
                border-radius: 8px;
                background: #f6f7f7 !important;
                color: #50575e;
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                box-shadow: none !important;
            }
            .editor-styles-wrapper .block-list-appender__toggle::after,
            .editor-styles-wrapper .block-editor-default-block-appender__content::after {
                content: "Add a step";
            }
            .editor-styles-wrapper .block-list-appender__toggle svg,
            .editor-styles-wrapper .block-editor-default-block-appender__content svg {
                width: 18px; height: 18px; fill: currentColor;
            }
            .editor-styles-wrapper .block-list-appender__toggle:hover,
            .editor-styles-wrapper .block-editor-default-block-appender__content:hover {
                border-color: var(--wp-admin-theme-color, #3858e9) !important;
                color: var(--wp-admin-theme-color, #3858e9);
                background: #fff !important;
            }

            /* ---- shared authoring shell for every lesson step block ----
               See courses-studio-blocks.ts stepShell()/pickerPlaceholder()
               for the reasoning. This is the only channel that reaches the
               post editor\'s IFRAMED canvas (the same constraint already
               documented for .bhc-studio-choice-row below), so all of it
               lives here rather than in an enqueued stylesheet. */
            .bhc-studio-step {
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                background: #fff;
                overflow: hidden;
                box-shadow: 0 1px 2px rgba(0,0,0,.04);
                /* The post editor\'s canvas is an IFRAME that loads the
                   active theme\'s own front-end stylesheet for true
                   WYSIWYG (this theme sets a cream/tan body text color
                   for its own dark hero look). Nothing in this shell set
                   an explicit color, so every label/paragraph/timestamp
                   inside it was silently inheriting that tan against
                   this card\'s white background — confirmed live via
                   getComputedStyle() (rgb(237,223,203) text on
                   rgb(255,255,255)), not just eyeballed. One color here
                   fixes every descendant that doesn\'t set its own,
                   including WP\'s own .components-base-control__label
                   (the "TITLE"/"CAPTION" field labels), which carries no
                   color rule of its own either. */
                color: #1e1e1e;
                /* Inter-step gap lives on the block wrapper rule above;
                   this small top margin is only so Gutenberg\'s floating
                   toolbar (docked inside a block\'s own top edge when
                   there\'s no room above) has somewhere to sit on the
                   very first step. */
                margin-top: 10px;
            }
            .bhc-studio-head {
                display: flex; align-items: center; gap: 7px;
                padding: 7px 12px;
                background: #f6f7f7;
                border-bottom: 1px solid #e0e0e0;
                font-size: 11px; font-weight: 600;
                letter-spacing: .05em; text-transform: uppercase;
                color: #50575e;
            }
            .bhc-studio-head svg { fill: #757575; }
            .bhc-studio-head-label { flex-shrink: 0; }
            /* At-a-glance summary ("3 chapters", "4 items") so a long
               lesson is scannable without opening each step. */
            .bhc-studio-head-meta {
                margin-left: auto;
                text-transform: none; letter-spacing: 0;
                font-weight: 400; color: #757575;
            }
            .bhc-studio-body { padding: 16px; }
            .bhc-studio-body > * { margin-bottom: 16px; }
            .bhc-studio-body > *:last-child { margin-bottom: 0; }
            /* WP component labels ("TITLE", "CAPTION", "REQUIRE WATCHED %")
               carry no bottom spacing of their own and sat almost on top
               of their input. One consistent rhythm for every field
               label in a step. */
            .bhc-studio-body .components-base-control__label {
                display: block;
                margin-bottom: 5px;
                font-size: 11px;
                font-weight: 600;
                letter-spacing: .04em;
                text-transform: uppercase;
                color: #50575e;
            }
            .bhc-studio-body .components-base-control__help {
                margin-top: 6px;
                font-size: 12px;
                color: #757575;
            }

            /* The tail-end per-step options (caption, completion rule) —
               grouped into one quiet panel so they read as "settings for
               this step", not a pile of loose controls after the media. */
            .bhc-studio-settings {
                border: 1px solid #e0e0e0;
                border-radius: 6px;
                background: #fbfbfb;
                padding: 14px;
            }
            .bhc-studio-settings > * { margin-bottom: 14px; }
            .bhc-studio-settings > *:last-child { margin-bottom: 0; }
            .bhc-studio-settings .components-range-control__root { margin-bottom: 0; }

            .bhc-studio-placeholder {
                display: flex; flex-direction: column; align-items: center;
                gap: 6px; text-align: center;
                padding: 22px 16px;
                border: 1px dashed #c3c4c7; border-radius: 6px;
                background: #fbfbfb;
            }
            .bhc-studio-placeholder svg { fill: #a7aaad; }
            .bhc-studio-placeholder-title { margin: 0; font-size: 13px; font-weight: 600; color: #1e1e1e; }
            .bhc-studio-placeholder-help { margin: 0 0 4px; font-size: 12px; color: #757575; max-width: 320px; }

            .bhc-studio-chosen {
                display: flex; align-items: center; gap: 8px;
                padding: 8px 10px; margin-bottom: 12px;
                border: 1px solid #e0e0e0; border-radius: 6px; background: #fbfbfb;
            }
            .bhc-studio-chosen svg { fill: #757575; flex-shrink: 0; }
            .bhc-studio-chosen-name {
                font-size: 13px; font-weight: 500; color: #1e1e1e;
                overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
            }
            .bhc-studio-chosen-note { font-size: 12px; color: #757575; flex-shrink: 0; }
            .bhc-studio-chosen > .components-button { margin-left: auto; flex-shrink: 0; }

            /* An in-canvas preview of the real media, so an author can
               see WHAT this step is without leaving the editor. */
            .bhc-studio-preview {
                width: 100%; max-height: 320px; display: block;
                border-radius: 6px; background: #000;
                border: 1px solid #e0e0e0;
                margin-bottom: 10px;
            }
            .bhc-studio-preview-bunny { border: 1px solid #e0e0e0; }
            .bhc-studio-preview-audio { background: transparent; border: 0; margin-bottom: 8px; }

            .bhc-studio-image-thumbs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
            img.bhc-studio-image-thumb {
                width: 64px; height: 64px; object-fit: cover;
                border-radius: 4px; border: 1px solid #e0e0e0; display: block;
            }
            span.bhc-studio-image-thumb {
                display: inline-flex; align-items: center; justify-content: center;
                width: 64px; height: 64px; border-radius: 4px;
                border: 1px dashed #c3c4c7; font-size: 11px; color: #757575;
            }
            .bhc-studio-checklist-row { display: flex; gap: 8px; align-items: center; margin-bottom: 6px; }
            .bhc-studio-checklist-row .components-base-control { flex: 1; margin-bottom: 0; }
            .bhc-studio-checklist-box {
                flex-shrink: 0; width: 16px; height: 16px; border-radius: 3px;
                border: 1.5px solid #c3c4c7; background: #fff;
            }
            .bhc-studio-empty-hint { margin: 0 0 10px; font-size: 12px; color: #757575; font-style: italic; }
            .bhc-studio-monospace textarea { font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; font-size: 12.5px; }
            .bhc-studio-audio-compare-clip { margin-bottom: 14px; padding-bottom: 14px; border-bottom: 1px solid #f0f0f0; }
            .bhc-studio-audio-compare-clip:last-of-type { border-bottom: none; padding-bottom: 0; margin-bottom: 12px; }

            /* Chapter/overlay rows — streamlined to a single compact
               "chrome" row (order, time, grab-from-preview, type,
               remove) above whatever real content the row holds,
               instead of each of those living on its own fully-labelled
               line. Icon buttons in this row always pair the icon with
               a real word (see courses-studio-blocks.ts\'s own comment)
               so the row stays explicit at a glance, not just on hover. */
            .bhc-studio-chapter-row, .bhc-studio-annotation-row {
                border: 1px solid #e0e0e0; border-radius: 6px;
                padding: 8px 10px; margin-bottom: 8px; background: #fff;
            }
            .bhc-studio-chapter-row.has-warning { border-color: #d9a800; background: #fefaf0; }
            .bhc-studio-chapter-row-top {
                display: flex; align-items: center; gap: 6px; margin-bottom: 6px;
                flex-wrap: wrap; /* time field + buttons + type select overflow a narrow canvas otherwise */
            }
            .bhc-studio-order-badge {
                flex-shrink: 0; width: 20px; height: 20px; border-radius: 10px;
                background: #f0f0f0; color: #1e1e1e; font-size: 11px; font-weight: 600;
                display: inline-flex; align-items: center; justify-content: center;
            }
            /* A plain number input reading as a real, explicit timestamp
               field rather than a bare unlabeled box — the "sec" suffix
               beside it stands in for the label hidden via
               hideLabelFromVision (kept for screen readers), the same
               trade every compact chrome-row control here makes. */
            .bhc-studio-time-input { width: 64px; margin-bottom: 0 !important; }
            .bhc-studio-time-input input { text-align: right; }
            .bhc-studio-time-unit { font-size: 12px; color: #757575; margin-right: 4px; }
            .bhc-studio-chapter-row-top .components-button.is-tertiary {
                padding: 0 6px; height: 28px;
            }
            .bhc-studio-overlay-type { min-width: 84px; margin-bottom: 0 !important; margin-left: 4px; }
            .bhc-studio-overlay-type select { height: 28px; min-height: 28px; }
            .bhc-studio-row-warning { margin: 4px 0 0; font-size: 12px; color: #8a6d00; }

            /* Chapters/overlays authored directly in the lesson-building
               canvas (AJ: "can they not be part of lesson building
               rather" than a settings-sidebar panel) — a plain <details>
               rather than PanelBody so it reads as part of this step\'s
               own content, not a floating settings widget stacked
               inside one. */
            .bhc-studio-subsection {
                border: 1px solid #e0e0e0; border-radius: 6px;
                margin-bottom: 12px; background: #fbfbfb;
            }
            .bhc-studio-subsection > summary {
                padding: 8px 12px; cursor: pointer; font-weight: 600; font-size: 13px;
                list-style: none; display: flex; align-items: center; gap: 6px;
                color: #1e1e1e;
            }
            .bhc-studio-subsection > summary::-webkit-details-marker { display: none; }
            .bhc-studio-subsection > summary::before {
                content: \'\'; width: 0; height: 0;
                border-left: 4px solid #757575; border-top: 4px solid transparent; border-bottom: 4px solid transparent;
                transition: transform .1s ease;
            }
            .bhc-studio-subsection[open] > summary::before { transform: rotate(90deg); }
            .bhc-studio-subsection-count {
                background: #e0e0e0; color: #1e1e1e; border-radius: 9px;
                font-size: 11px; font-weight: 600; padding: 1px 7px;
            }
            .bhc-studio-subsection > summary ~ * { padding-left: 12px; padding-right: 12px; }
            .bhc-studio-subsection > summary ~ *:last-child { padding-bottom: 12px; }
            .bhc-studio-subsection .description { margin-top: 4px; }

            .bhc-studio-empty {
                border: 1px dashed #ccc; border-radius: 6px; padding: 14px;
                text-align: center; color: #757575; font-size: 12px; margin: 8px 0 10px;
            }
'];
        $settings['styles'][] = ['css' => '
            .bhc-studio-choice-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
            .bhc-studio-choice-row > * { margin-bottom: 0 !important; }
            /* The text input sits two levels inside .components-base-control
               (fieldset > .components-base-control > ...__input) — flex only
               grows a DIRECT child of the row, so the grow rule belongs on
               .components-base-control itself, not the input nested inside it
               (targeting the input alone left it narrower than the Question
               field above, with a big empty gap on the right). */
            .bhc-studio-choice-row .components-base-control { flex: 1; min-width: 0; margin-bottom: 0; }
            .bhc-studio-choice-row .components-base-control__field { margin-bottom: 0; }
            .bhc-studio-choice-row .components-text-control__input { width: 100%; box-sizing: border-box; }
            /* RadioControl renders its option inside a <fieldset><legend>
               (even with no visible legend text) — the fieldset/legend\'s
               own default spacing pushed the actual radio circle down
               inside its box, so it read as sitting lower than the middle
               of the text field beside it instead of level with it. */
            .bhc-studio-choice-row fieldset.components-radio-control { margin: 0; padding: 0; border: 0; display: flex; align-items: center; }
            .bhc-studio-choice-row fieldset.components-radio-control legend { display: none; }
            .bhc-studio-choice-row .components-radio-control__option { margin: 0; }
            /* "Add choice" (adds another answer to THIS question, small/
               inline) vs "Add another question" (a real labeled button
               replacing Gutenberg\'s bare icon-only block appender,
               scoped to the WHOLE quiz, not one question) — distinct
               label text now says what each does, and this full-width/
               top-bordered treatment plus real spacing makes clear the
               second one belongs to a different, larger scope than the
               question it visually follows. */
            .bhc-studio-add-choice { margin-bottom: 20px; }
            /* Each question as its own card, matching the chapter-row
               treatment elsewhere in this file — without this, a quiz of
               several questions read as one undifferentiated scroll of
               "Question" text fields with no visual break between them. */
            .bhc-studio-quiz-question {
                border: 1px solid #e0e0e0; border-radius: 6px;
                background: #fff; padding: 14px 14px 4px; margin-bottom: 12px;
                color: #1e1e1e; /* see .bhc-studio-step\'s own comment on why this is needed inside the editor\'s themed iframe */
            }
            .bhc-studio-question-badge {
                display: inline-block; margin-bottom: 8px;
                font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase;
                color: #757575;
            }
            /* Gutenberg wraps any custom InnerBlocks appender in its own
               ".block-list-appender" div, which (a) shrink-wraps to the
               button\'s natural width by default — width:100% on the
               BUTTON alone did nothing while its own wrapper box was
               still only as wide as the button once was — and (b), the
               real culprit for the overlap: core styles this wrapper
               position:absolute (designed for the default tiny "+"
               icon-only appender, which doesn\'t need to reserve real
               flow space). A full-width, full-height custom button
               inside an absolutely-positioned wrapper doesn\'t push
               anything below it out of the way, so it rendered floating
               ON TOP of "Add choice" instead of stacked cleanly beneath
               it. position:static puts it back in normal document flow,
               where it belongs given how much visual space it now
               actually takes up. */
            .bhc-studio-quiz .block-list-appender { display: block; width: 100%; position: static; }
            .bhc-studio-add-question {
                display: flex; width: 100%; justify-content: center;
                box-sizing: border-box;
                margin-top: 12px; padding-top: 16px; border-top: 1px dashed #ccc;
            }
        '];
        return $settings;
    }

    private static function register_block_types(): void {
        BH_Content::register_block_type('bhc/text', [
            'content' => ['type' => 'html', 'default' => ''],
        ], function ($attrs) {
            return '<div class="bhc-step bhc-step-text">' . $attrs['content'] . '</div>';
        });

        BH_Content::register_block_type('bhc/image', [
            'attachment_ids' => ['type' => 'array', 'default' => []],
            'caption' => ['type' => 'string', 'default' => ''],
        ], function ($attrs) {
            $out = '<div class="bhc-step bhc-step-image">';
            foreach ((array) $attrs['attachment_ids'] as $id) {
                $out .= wp_get_attachment_image((int) $id, 'large');
            }
            if ($attrs['caption']) $out .= '<p class="bhc-caption">' . esc_html($attrs['caption']) . '</p>';
            return $out . '</div>';
        });

        BH_Content::register_block_type('bhc/video', [
            'source' => ['type' => 'string', 'default' => 'upload'],
            'attachment_id' => ['type' => 'int', 'default' => 0],
            'video_url' => ['type' => 'url', 'default' => ''],
            // OSS-integration master plan Phase 6 follow-up — round-trips
            // the Cloudflare Stream video UID through the tree<->legacy-
            // steps conversion the same way every other schema key here
            // already does; the real authoritative validation/rendering
            // is class-steps.php/class-render-lesson.php, not this
            // generic BH_Content preview path (see this array's own
            // 'annotations' comment for why).
            'stream_uid' => ['type' => 'string', 'default' => ''],
            'caption' => ['type' => 'string', 'default' => ''],
            'watch_threshold' => ['type' => 'int', 'default' => 0],
            // ROADMAP-lms-v3.md Section 1 — timestamped overlay
            // annotations (note/hotspot/question). This bridge's own
            // renderer is only ever used for BH_Content's generic
            // preview path, never the real student-facing lesson page
            // (class-render-lesson.php reads _bhc_steps directly, per
            // this file's own docblock) — the interactive pause/resume/
            // overlay behavior lives there and in courses.js, not here.
            // Just needs to round-trip through the schema so a lesson's
            // annotations survive the tree<->legacy-steps conversion.
            'annotations' => ['type' => 'array', 'default' => []],
            // YouTube-style chapters — [{ time, title }]. Real bug, found
            // live: this schema entry is what BH_Content's tree parser
            // actually validates/coerces attrs against when reading a
            // lesson's post_content back out — adding `chapters` to the
            // JS block's own attributes (courses-studio-blocks.ts) alone
            // was not enough. A chapter list saved fine into post_content
            // (confirmed via wp.data.select('core/editor').
            // getEditedPostContent()) but silently vanished by the time
            // get_tree()/tree_to_steps() produced the _bhc_steps array
            // class-render-lesson.php actually reads from, since this
            // side never knew the key existed.
            'chapters' => ['type' => 'array', 'default' => []],
        ], function ($attrs) {
            $out = '<div class="bhc-step bhc-step-video">';
            if ($attrs['source'] === 'cloudflare_stream' && $attrs['stream_uid']) {
                $out .= '<iframe src="https://iframe.videodelivery.net/' . esc_attr(preg_replace('/[^a-f0-9]/', '', $attrs['stream_uid'])) . '" style="border:none;" allowfullscreen></iframe>';
            } elseif ($attrs['source'] === 'url' && $attrs['video_url']) {
                $out .= wp_oembed_get($attrs['video_url']) ?: ('<a href="' . esc_url($attrs['video_url']) . '">' . esc_html($attrs['video_url']) . '</a>');
            } elseif ($attrs['attachment_id']) {
                $out .= wp_video_shortcode(['src' => wp_get_attachment_url((int) $attrs['attachment_id'])]);
            }
            if ($attrs['caption']) $out .= '<p class="bhc-caption">' . esc_html($attrs['caption']) . '</p>';
            return $out . '</div>';
        });

        // bhc/quiz is a CONTAINER whose children are bhc/quiz-question
        // blocks (LMS-AUTHORING-DESIGN-PLAN.md Section 3.2) — questions
        // used to be an `array`-typed attribute on this one block,
        // invisible to BH_Content's tree walk and to the Studio canvas'
        // ListView/table-view machinery. As real child blocks, "reorder
        // 40 questions" is an ordinary children-array reorder, and a
        // future table-view toggle (Section 3.3) gets it for free.
        // $renderer here receives $rendered_children (already-rendered
        // HTML from each bhc/quiz-question, depth-first) as its second
        // arg — see BH_Content::render()'s call signature.
        BH_Content::register_block_type('bhc/quiz', [
            'passing_score' => ['type' => 'int', 'default' => 70],
            'max_attempts' => ['type' => 'int', 'default' => 0],
            // Quiz depth pass — display-order randomization only, never
            // touches scoring: class-render-lesson.php renders questions/
            // choices in shuffled visual order but keeps every form
            // field's name/value tied to the ORIGINAL index (the same
            // indices BHC_Steps::score_quiz() and courses.js's FormData
            // read already expect), so this needed zero scoring changes.
            'shuffle_questions' => ['type' => 'bool', 'default' => false],
            'shuffle_choices' => ['type' => 'bool', 'default' => false],
        ], function ($attrs, $rendered_children) {
            return '<div class="bhc-step bhc-step-quiz" data-passing-score="' . (int) $attrs['passing_score'] . '">' . $rendered_children . '</div>';
        });

        BH_Content::register_block_type('bhc/quiz-question', [
            'question' => ['type' => 'string', 'default' => ''],
            'choices' => ['type' => 'array', 'default' => []],
            'correct_index' => ['type' => 'int', 'default' => 0],
        ], function ($attrs) {
            return '<p class="bhc-quiz-question">' . esc_html($attrs['question']) . '</p>';
        });

        // Depth-of-magic pass: real visual density inside a lesson — a
        // highlighted "key idea" / "watch out for this" moment, instead
        // of every step reading as an undifferentiated text block. Same
        // three fixed variants as BHC_Steps::CALLOUT_VARIANTS (tip/note/
        // warning) — each rendered as a shade/tint of ONE base color
        // per variant (courses.css), not three unrelated named colors.
        // (Audit fix, 2026-07-25: this comment used to sit above the
        // 'resource' registration below, describing THIS block instead.)
        BH_Content::register_block_type('bhc/callout', [
            'content' => ['type' => 'html', 'default' => ''],
            'variant' => ['type' => 'string', 'default' => 'tip'],
        ], function ($attrs) {
            $variant = in_array($attrs['variant'], BHC_Steps::CALLOUT_VARIANTS, true) ? $attrs['variant'] : 'tip';
            return '<div class="bhc-step bhc-step-callout bhc-callout-' . esc_attr($variant) . '">' . $attrs['content'] . '</div>';
        });

        // ROADMAP-ux-polish-and-feature-parity-2026-07.md 4c —
        // downloadable resources per step (a worksheet, a PDF, a
        // reference doc). Flat attrs, no children, so this needed
        // nothing in steps_to_tree()/tree_to_steps() beyond adding
        // 'resource' to BHC_Steps::VALID_TYPES — both already handle
        // any bhc/* block generically except bhc/quiz's child-block
        // promotion above.
        BH_Content::register_block_type('bhc/resource', [
            'attachment_id' => ['type' => 'int', 'default' => 0],
            'label' => ['type' => 'string', 'default' => ''],
            'description' => ['type' => 'string', 'default' => ''],
        ], function ($attrs) {
            if (!$attrs['attachment_id']) return '';
            $url = wp_get_attachment_url((int) $attrs['attachment_id']);
            if (!$url) return '<p class="bhc-step bhc-step-resource">File not found.</p>';
            $label = $attrs['label'] !== '' ? $attrs['label'] : basename(get_attached_file((int) $attrs['attachment_id']) ?: 'Download');
            $out = '<div class="bhc-step bhc-step-resource"><a href="' . esc_url($url) . '" download>&#8681; ' . esc_html($label) . '</a>';
            if ($attrs['description']) $out .= '<p class="bhc-caption">' . esc_html($attrs['description']) . '</p>';
            return $out . '</div>';
        });

        // Depth-of-magic Phase 2c — scoped directly from AJ's own answer
        // on what's actually missing for THIS content, not a generic
        // "add more block types" guess. Non-blocking, same posture as
        // resource/callout above.
        BH_Content::register_block_type('bhc/checklist', [
            'title' => ['type' => 'string', 'default' => ''],
            'items' => ['type' => 'array', 'default' => []],
        ], function ($attrs) {
            $out = '<div class="bhc-step bhc-step-checklist">';
            if ($attrs['title']) $out .= '<p class="bhc-checklist-title">' . esc_html($attrs['title']) . '</p>';
            $out .= '<ul class="bhc-checklist-items">';
            foreach ((array) $attrs['items'] as $i => $item) {
                $out .= '<li><label><input type="checkbox" class="bhc-checklist-check" data-item-index="' . (int) $i . '"> ' . esc_html($item) . '</label></li>';
            }
            $out .= '</ul></div>';
            return $out;
        });

        // Plain text, not HTML — a chord chart's alignment IS the
        // content (chords positioned over lyric lines via monospace
        // spacing), so this renders via esc_html() inside a <pre> to
        // preserve real whitespace, never as trusted rich content.
        BH_Content::register_block_type('bhc/chord-chart', [
            'title' => ['type' => 'string', 'default' => ''],
            'content' => ['type' => 'string', 'default' => ''],
        ], function ($attrs) {
            $out = '<div class="bhc-step bhc-step-chord-chart">';
            if ($attrs['title']) $out .= '<p class="bhc-chord-chart-title">' . esc_html($attrs['title']) . '</p>';
            $out .= '<pre class="bhc-chord-chart-content">' . esc_html($attrs['content']) . '</pre></div>';
            return $out;
        });

        BH_Content::register_block_type('bhc/audio-compare', [
            'attachment_id_a' => ['type' => 'int', 'default' => 0],
            'attachment_id_b' => ['type' => 'int', 'default' => 0],
            'label_a' => ['type' => 'string', 'default' => 'A'],
            'label_b' => ['type' => 'string', 'default' => 'B'],
            'caption' => ['type' => 'string', 'default' => ''],
        ], function ($attrs) {
            if (!$attrs['attachment_id_a'] || !$attrs['attachment_id_b']) return '';
            $url_a = wp_get_attachment_url((int) $attrs['attachment_id_a']);
            $url_b = wp_get_attachment_url((int) $attrs['attachment_id_b']);
            if (!$url_a || !$url_b) return '<p class="bhc-step bhc-step-audio-compare">One or both audio files not found.</p>';
            $out = '<div class="bhc-step bhc-step-audio-compare">';
            $out .= '<div class="bhc-audio-compare-clip"><p class="bhc-audio-compare-label">' . esc_html($attrs['label_a']) . '</p>' . wp_audio_shortcode(['src' => $url_a]) . '</div>';
            $out .= '<div class="bhc-audio-compare-clip"><p class="bhc-audio-compare-label">' . esc_html($attrs['label_b']) . '</p>' . wp_audio_shortcode(['src' => $url_b]) . '</div>';
            if ($attrs['caption']) $out .= '<p class="bhc-caption">' . esc_html($attrs['caption']) . '</p>';
            return $out . '</div>';
        });
    }

    /**
     * Reads the lesson's content as a BH_Content block tree straight
     * from its own post_content (self::CONTEXT === 'post'). Falls back
     * to deriving the tree from the legacy `_bhc_steps` array on the fly
     * for a lesson whose post_content is still empty — a lesson created
     * before this migration, not yet opened in the real editor.
     */
    /** @return array<int, array<string, mixed>> */
    public static function get_tree(int $lesson_id): array {
        $stored = BH_Content::get(self::CONTEXT, $lesson_id);
        if ($stored) return $stored;
        return self::steps_to_tree(BHC_Steps::get($lesson_id));
    }

    /**
     * One-time hydration: rebuilds a lesson's real post_content from
     * its current `_bhc_steps` data via a normal wp_update_post() call
     * (BH_Content::save()'s 'post' branch) — safe to run on any lesson,
     * any number of times. wp_update_post() fires save_post_bh_lesson
     * as a real side effect, so this immediately re-triggers
     * sync_legacy_steps() too; the round trip is idempotent (the same
     * step data comes back out), which is exactly what confirms the two
     * representations agree. Used by the debug tool below for lessons
     * that existed before this migration (their post_content is still
     * empty); a lesson created after never needs it — opening
     * it in the real editor and saving once is the same operation.
     */
    public static function migrate_lesson(int $lesson_id): bool {
        $tree = self::steps_to_tree(BHC_Steps::get($lesson_id));
        return (bool) BH_Content::save(self::CONTEXT, $lesson_id, $tree);
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     * @return array<int, array<string, mixed>>
     */
    private static function steps_to_tree(array $steps): array {
        $tree = [];
        foreach ($steps as $step) {
            $type = $step['type'] ?? '';
            if (!in_array($type, BHC_Steps::VALID_TYPES, true)) continue;
            $attrs = $step;
            unset($attrs['type']);

            $children = [];
            if ($type === 'quiz') {
                // Promote the legacy `questions` attribute-array into
                // real bhc/quiz-question child blocks — see
                // register_block_types()'s docblock on bhc/quiz above.
                // `questions` is removed from $attrs entirely so it
                // matches bhc/quiz's own (now question-free) schema.
                $questions = (array) ($attrs['questions'] ?? []);
                unset($attrs['questions']);
                foreach ($questions as $q) {
                    $children[] = [
                        'type' => 'bhc/quiz-question',
                        'attrs' => [
                            'question' => $q['question'] ?? '',
                            'choices' => (array) ($q['choices'] ?? []),
                            'correct_index' => (int) ($q['correct_index'] ?? 0),
                        ],
                        'children' => [],
                    ];
                }
            }

            $tree[] = ['type' => 'bhc/' . $type, 'attrs' => $attrs, 'children' => $children];
        }
        return $tree;
    }

    /**
     * @param array<int, array<string, mixed>> $tree
     * @return array<int, array<string, mixed>>
     */
    private static function tree_to_steps(array $tree): array {
        $steps = [];
        foreach ($tree as $block) {
            $type = $block['type'] ?? '';
            if (strpos($type, 'bhc/') !== 0) continue; // a step from a block type this plugin doesn't own — skip, don't fatal
            if ($type === 'bhc/quiz-question') continue; // only ever appears as a bhc/quiz child, never a top-level step in its own right
            $legacy_type = substr($type, 4);
            $step = array_merge(['type' => $legacy_type], $block['attrs'] ?? []);

            if ($legacy_type === 'quiz') {
                // Reassemble `questions` from the block's children —
                // BHC_Steps::score_quiz() (class-steps.php) reads
                // $step['questions'] and has no idea this is now
                // sourced from child blocks rather than an attribute;
                // that's the whole point of keeping this conversion
                // here rather than teaching scoring logic about
                // BH_Content trees.
                $step['questions'] = array_map(function ($child) {
                    return [
                        'question' => $child['attrs']['question'] ?? '',
                        'choices' => $child['attrs']['choices'] ?? [],
                        'correct_index' => $child['attrs']['correct_index'] ?? 0,
                    ];
                }, array_filter($block['children'] ?? [], function ($c) { return ($c['type'] ?? '') === 'bhc/quiz-question'; }));
                $step['questions'] = array_values($step['questions']);
            }

            $steps[] = $step;
        }
        return $steps;
    }

    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register_debug_tool($tools): array {
        $tools['bhc_content_bridge'] = [
            'label' => 'BH Courses: populate lesson content from steps',
            // Handled inline within render() rather than via the shared
            // 'handle' callback — that path is wired for the
            // admin_post_ous_debug_action redirect flow (plugin_key +
            // action in the request); this tool's one action is simpler
            // as a plain self-submitting form checked at render time,
            // same as several existing seed/reset sections that don't
            // need the redirect-with-message round trip.
            'render' => function () {
                if (isset($_POST['bhc_content_bridge_action']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bhc_content_bridge_nonce'] ?? '')), 'bhc_content_bridge')) {
                    $lessons = get_posts(['post_type' => 'bh_lesson', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids']);
                    foreach ($lessons as $lesson_id) {
                        self::migrate_lesson($lesson_id);
                    }
                    echo '<div class="notice notice-success"><p>Rebuilt ' . count($lessons) . ' lesson(s).</p></div>';
                }
                echo '<p>Populates every published lesson\'s real post_content from its current step data, so it opens correctly the first time in the real editor — needed only for a lesson that existed before this migration and hasn\'t been opened/saved in the editor yet. Safe to run any time; never touches the step data itself.</p>';
                echo '<form method="post"><input type="hidden" name="bhc_content_bridge_action" value="1">';
                wp_nonce_field('bhc_content_bridge', 'bhc_content_bridge_nonce');
                submit_button('Populate all lesson content');
                echo '</form>';
            },
            'group' => OUS_Debug::GROUP_SEED_RESET,
        ];
        return $tools;
    }
}
