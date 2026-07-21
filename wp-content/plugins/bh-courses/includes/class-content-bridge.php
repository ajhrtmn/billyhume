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

    public static function init() {
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

        // The ONLY writer of `_bhc_steps` now — fires on every real
        // save of a bh_lesson post (the real editor, REST, anywhere),
        // reads the tree straight back out of the post's own
        // post_content it was just saved into, and converts it via the
        // existing tree_to_steps() (unchanged — same conversion the
        // Studio-authoring pass already wrote and validated).
        add_action('save_post_bh_lesson', [self::class, 'sync_legacy_steps']);
    }

    /** Skips autosaves/revisions (the standard WP guard every other save_post consumer in this ecosystem uses), then re-derives `_bhc_steps` from this lesson's own just-saved post_content. */
    public static function sync_legacy_steps($post_id) {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
        if (!class_exists('BHC_Steps')) return;
        $tree = self::get_tree($post_id);
        BHC_Steps::save($post_id, self::tree_to_steps($tree));
        if (class_exists('BHC_VideoSettings')) BHC_VideoSettings::check_tree($post_id, $tree);
    }

    // Purely cosmetic (the inserter groups unregistered categories under
    // "Uncategorized" otherwise, not a functional break) but cheap and
    // worth doing — 'lms' is used by every block type registered below.
    public static function register_block_category($categories) {
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
    public static function maybe_enqueue_lesson_blocks() {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'bh_lesson') return;
        wp_enqueue_script(
            'bhc-courses-studio-blocks',
            defined('BHC_URL') ? BHC_URL . 'assets/js/courses-studio-blocks.js' : plugins_url('assets/js/courses-studio-blocks.js', dirname(__FILE__)),
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-api-fetch'],
            defined('BHC_VER') ? BHC_VER : null,
            true
        );
    }

    private static function register_block_types() {
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
            'caption' => ['type' => 'string', 'default' => ''],
            'watch_threshold' => ['type' => 'int', 'default' => 0],
        ], function ($attrs) {
            $out = '<div class="bhc-step bhc-step-video">';
            if ($attrs['source'] === 'url' && $attrs['video_url']) {
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
    }

    /**
     * Reads the lesson's content as a BH_Content block tree straight
     * from its own post_content (self::CONTEXT === 'post'). Falls back
     * to deriving the tree from the legacy `_bhc_steps` array on the fly
     * for a lesson whose post_content is still empty — a lesson created
     * before this migration, not yet opened in the real editor.
     */
    public static function get_tree($lesson_id) {
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
    public static function migrate_lesson($lesson_id) {
        $tree = self::steps_to_tree(BHC_Steps::get($lesson_id));
        return BH_Content::save(self::CONTEXT, $lesson_id, $tree);
    }

    private static function steps_to_tree(array $steps) {
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

    private static function tree_to_steps(array $tree) {
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

    public static function register_debug_tool($tools) {
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
                if (isset($_POST['bhc_content_bridge_action']) && check_admin_referer('bhc_content_bridge', 'bhc_content_bridge_nonce', false)) {
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
