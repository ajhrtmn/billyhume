<?php
if (!defined('ABSPATH')) exit;

// BHC_VER 0.4.6 — register_debug_tool() now sets 'group' =>
// OUS_Debug::GROUP_SEED_RESET (own-ur-shit's Debug Tools reorganization
// pass — see that plugin's class-debug.php docblock), filing this
// section under "Seed & Reset Tools" instead of the default bucket. No
// other change.

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
 * AND kept in sync with the existing `_bhc_steps` postmeta array via
 * BHC_Steps — so BHC_Progress/BHC_Render/BHC_Gate, all written against
 * the step-index array, keep working completely unchanged.
 * `save_tree()` (called from BH_Studio's own REST save route — see
 * register_studio_block_types()/maybe_enqueue_studio_blocks() below) is
 * now the ONLY writer of a lesson's step content; class-admin.php's old
 * repeater metabox was retired outright rather than left as a second
 * writer of the same data (a real dual-write hazard the design doc
 * flagged — see class-admin.php's own docblock for how it was closed).
 */
class BHC_ContentBridge {
    const CONTEXT = 'bhc_lesson';

    public static function init() {
        if (!class_exists('BH_Content')) return;
        self::register_block_types();

        // A concrete, clickable way to exercise this migration without
        // a real block-authoring UI yet — same "any plugin registers a
        // seed/reset section" shared Debug Tools pattern every other
        // plugin in this ecosystem already uses (see VISION.md).
        if (class_exists('OUS_Debug')) {
            add_filter('ous_debug_tools', [self::class, 'register_debug_tool']);
        }

        // The wiring LMS-AUTHORING-DESIGN-PLAN.md Section 5.3 flagged as
        // the actual gap: this class registered bhc/* with BH_Content
        // (server-side rendering) but nothing put them in the Studio
        // canvas. Same two-piece pattern BHM_Storefront already
        // establishes (class-storefront.php): a bh_studio_block_types
        // filter entry, plus a client script enqueued ONLY on the Studio
        // admin page.
        if (class_exists('BH_Studio')) {
            add_filter('bh_studio_block_types', [self::class, 'register_studio_block_types']);
        }
        add_action('admin_enqueue_scripts', [self::class, 'maybe_enqueue_studio_blocks']);
        add_filter('block_categories_all', [self::class, 'register_block_category']);
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

    public static function register_studio_block_types($types) {
        $types['bhc/text'] = ['tag' => 'div', 'category' => 'lms', 'label' => 'Lesson: Text'];
        $types['bhc/image'] = ['tag' => 'div', 'category' => 'lms', 'label' => 'Lesson: Image'];
        $types['bhc/video'] = ['tag' => 'div', 'category' => 'lms', 'label' => 'Lesson: Video'];
        $types['bhc/quiz'] = ['tag' => 'div', 'category' => 'lms', 'label' => 'Lesson: Quiz'];
        $types['bhc/quiz-question'] = ['tag' => 'div', 'category' => 'lms', 'label' => 'Quiz Question'];
        return $types;
    }

    // Same hook-name-substring gate BHM_Storefront::maybe_enqueue_studio_blocks()
    // uses, kept identical rather than inventing a second convention —
    // this file is the second real consumer of that pattern, not the
    // first.
    public static function maybe_enqueue_studio_blocks($hook) {
        if (strpos($hook, 'bh-studio') === false) return;
        if (!wp_script_is('bh-studio', 'enqueued') && !wp_script_is('bh-studio', 'registered')) return;
        wp_enqueue_script(
            'bhc-courses-studio-blocks',
            defined('BHC_URL') ? BHC_URL . 'assets/js/courses-studio-blocks.js' : plugins_url('assets/js/courses-studio-blocks.js', dirname(__FILE__)),
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-api-fetch', 'bh-studio'],
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
    }

    /**
     * Reads the lesson's content as a BH_Content block tree. Prefers
     * whatever's already stored via BH_Content (a future visual editor's
     * own saves); if nothing's there yet, derives the tree from the
     * legacy `_bhc_steps` array on the fly (lazy migration — no bulk
     * rewrite needed for every existing lesson just to make this readable).
     */
    public static function get_tree($lesson_id) {
        $stored = BH_Content::get(self::CONTEXT, $lesson_id);
        if ($stored) return $stored;
        return self::steps_to_tree(BHC_Steps::get($lesson_id));
    }

    /**
     * Writes a block tree through BH_Content (validated/coerced against
     * each block type's schema) AND converts it back into the legacy
     * step array via BHC_Steps::save() (which does its own defensive
     * re-sanitization) — so BHC_Progress/BHC_Render/BHC_Gate, all
     * written against the step-index array, need zero changes to keep
     * working against content authored this new way.
     */
    public static function save_tree($lesson_id, array $tree) {
        $clean = BH_Content::save(self::CONTEXT, $lesson_id, $tree);
        BHC_Steps::save($lesson_id, self::tree_to_steps($clean));
        return $clean;
    }

    /** One-way: rebuild the BH_Content tree from the current legacy steps and store it. */
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
            'label' => 'BH Courses: rebuild BH_Content trees',
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
                echo '<p>Rebuilds every published lesson\'s BH_Content block tree from its current step data — safe to run any time, never touches the legacy step data itself.</p>';
                echo '<form method="post"><input type="hidden" name="bhc_content_bridge_action" value="1">';
                wp_nonce_field('bhc_content_bridge', 'bhc_content_bridge_nonce');
                submit_button('Rebuild all lesson content trees');
                echo '</form>';
            },
            'group' => OUS_Debug::GROUP_SEED_RESET,
        ];
        return $tools;
    }
}
