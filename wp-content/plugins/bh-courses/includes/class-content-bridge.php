<?php
if (!defined('ABSPATH')) exit;

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
 * Deliberate scope for THIS migration, matching the roadmap doc's own
 * framing ("a step just becomes one top-level block in the lesson's
 * content tree instead of one entry in a fixed-type array" — a storage/
 * authoring-model change, not a promise that class-render.php/
 * class-progress.php's step-INDEX-based progress tracking changes
 * shape too): a lesson's steps are now readable/writable as a real
 * BH_Content block tree (`bhc/text`, `bhc/image`, `bhc/video`,
 * `bhc/quiz` block types, one top-level block per legacy step, in the
 * same order), AND kept in sync with the existing `_bhc_steps` postmeta
 * array via BHC_Steps — so BHC_Progress/BHC_Render/BHC_Gate, which are
 * all written against the step-index array today, keep working
 * completely unchanged. `BHC_ContentBridge::save_tree()` is the one
 * new write path a future block-based authoring UI would call; until
 * that UI exists, the existing metabox form (class-admin.php) remains
 * the one actually wired up, and `save_tree()`/`get_tree()` are real,
 * tested-by-reasoning conversions ready for that UI to call.
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

        BH_Content::register_block_type('bhc/quiz', [
            'passing_score' => ['type' => 'int', 'default' => 70],
            'max_attempts' => ['type' => 'int', 'default' => 0],
            'questions' => ['type' => 'array', 'default' => []],
        ], function ($attrs) {
            $out = '<div class="bhc-step bhc-step-quiz" data-passing-score="' . (int) $attrs['passing_score'] . '">';
            foreach ($attrs['questions'] as $q) {
                $out .= '<p class="bhc-quiz-question">' . esc_html($q['question'] ?? '') . '</p>';
            }
            return $out . '</div>';
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
            $tree[] = ['type' => 'bhc/' . $type, 'attrs' => $attrs, 'children' => []];
        }
        return $tree;
    }

    private static function tree_to_steps(array $tree) {
        $steps = [];
        foreach ($tree as $block) {
            $type = $block['type'] ?? '';
            if (strpos($type, 'bhc/') !== 0) continue; // a step from a block type this plugin doesn't own — skip, don't fatal
            $legacy_type = substr($type, 4);
            $steps[] = array_merge(['type' => $legacy_type], $block['attrs'] ?? []);
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
        ];
        return $tools;
    }
}
