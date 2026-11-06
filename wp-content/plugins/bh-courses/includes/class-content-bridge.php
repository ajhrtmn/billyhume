<?php
if (!defined('ABSPATH')) exit;

/**
 * Lesson steps as a BH_Content block tree in the lesson's own post_content, kept in
 * sync with the legacy `_bhc_steps` array that BHC_Progress/BHC_Render/BHC_Gate read.
 * sync_legacy_steps() (save_post_bh_lesson) is the only writer of a lesson's step content.
 */
class BHC_ContentBridge {
    const CONTEXT = 'post';

    public static function init(): void {
        if (!class_exists('BH_Content')) return;
        self::register_block_types();

        // Debug Tools section to hydrate post_content from step data (see migrate_lesson()).
        if (class_exists('OUS_Debug')) {
            add_filter('ous_debug_tools', [self::class, 'register_debug_tool']);
        }

        add_action('enqueue_block_editor_assets', [self::class, 'maybe_enqueue_lesson_blocks']);
        add_filter('block_categories_all', [self::class, 'register_block_category']);
        add_filter('block_editor_settings_all', [self::class, 'add_studio_block_editor_styles'], 10, 2);
        add_filter('allowed_block_types_all', [self::class, 'curate_lesson_inserter'], 10, 2);

        // The only writer of `_bhc_steps`: runs on every save and re-derives it from post_content.
        add_action('save_post_bh_lesson', [self::class, 'sync_legacy_steps']);
    }

    /** Skips autosaves/revisions, then re-derives `_bhc_steps` from the just-saved post_content. */
    public static function sync_legacy_steps(int $post_id): void {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
        if (!class_exists('BHC_Steps')) return;
        $tree = self::get_tree($post_id);
        BHC_Steps::save($post_id, self::tree_to_steps($tree));
        if (class_exists('BHC_VideoSettings')) BHC_VideoSettings::check_tree($post_id, $tree);
    }

    // Cosmetic: the 'lms' inserter category used by every block below.
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

    // enqueue_block_editor_assets fires on every block editor, so check for bh_lesson.
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
        // Outer-document CSS (sidebar panel + "Add a step" dock); iframed canvas CSS goes
        // through add_studio_block_editor_styles().
        wp_enqueue_style(
            'bhc-admin',
            defined('BHC_URL') ? BHC_URL . 'assets/css/admin.css' : plugins_url('assets/css/admin.css', dirname(__FILE__)),
            [],
            defined('BHC_VER') ? BHC_VER : null
        );
        // Cloudflare Stream only appears once Tier B is enabled in Media & CDN Setup.
        wp_localize_script('bhc-courses-studio-blocks', 'bhcMediaTierB', [
            'enabled' => class_exists('OUS_MediaWizard') && OUS_MediaWizard::tier_b_enabled(),
        ]);

        // Signed sources (Bunny, R2) likewise appear only once configured.
        $signed = class_exists('BHY_MediaToken') ? BHY_MediaToken::js_config() : ['bunny' => false, 'bunnyApi' => false, 'r2' => false];
        wp_localize_script('bhc-courses-studio-blocks', 'bhcMediaSigned', $signed);

        // Bunny library/uploader/preview: player.js for the preview, vendored tus for
        // resumable uploads. Loaded only when Bunny is configured.
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

    // Restricts the lesson inserter to the bhc/* step blocks; other post types are untouched.
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

    // WHY: the post editor canvas is an iframe that ordinary enqueued stylesheets never
    // reach; block_editor_settings_all's `styles` array is core's way in.
    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function add_studio_block_editor_styles(array $settings, \WP_Block_Editor_Context $context): array {
        $screen = $context->post ? get_post_type($context->post) : null;
        if ($screen !== 'bh_lesson') return $settings;

        $settings['styles'][] = ['css' => (string) file_get_contents(BHC_PATH . 'assets/css/studio-editor.css')];
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
            // Cloudflare Stream UID; real validation/rendering is class-steps.php/class-render-lesson.php.
            'stream_uid' => ['type' => 'string', 'default' => ''],
            'caption' => ['type' => 'string', 'default' => ''],
            'watch_threshold' => ['type' => 'int', 'default' => 0],
            // This renderer is only BH_Content's generic preview; the interactive behavior lives
            // in class-render-lesson.php and courses.ts. Listed so annotations round-trip.
            'annotations' => ['type' => 'array', 'default' => []],
            // [{ time, title }]. WHY: BH_Content coerces attrs against this schema when reading
            // post_content, so a key missing here is silently dropped from _bhc_steps.
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

        // Container block: questions are bhc/quiz-question children. The renderer gets
        // their already-rendered HTML as $rendered_children.
        BH_Content::register_block_type('bhc/quiz', [
            'passing_score' => ['type' => 'int', 'default' => 70],
            'max_attempts' => ['type' => 'int', 'default' => 0],
            // Display-order shuffling only; scoring uses original indexes.
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

        // Highlighted tip/note/warning (BHC_Steps::CALLOUT_VARIANTS).
        BH_Content::register_block_type('bhc/callout', [
            'content' => ['type' => 'html', 'default' => ''],
            'variant' => ['type' => 'string', 'default' => 'tip'],
        ], function ($attrs) {
            $variant = in_array($attrs['variant'], BHC_Steps::CALLOUT_VARIANTS, true) ? $attrs['variant'] : 'tip';
            return '<div class="bhc-step bhc-step-callout bhc-callout-' . esc_attr($variant) . '">' . $attrs['content'] . '</div>';
        });

        // Downloadable file per step; flat attrs, no children.
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

        // Non-blocking self-check list.
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

        // Plain text in <pre> via esc_html(): a chord chart's alignment is the content.
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
     * The lesson's block tree from post_content, falling back to the legacy `_bhc_steps`
     * when post_content is still empty.
     */
    /** @return array<int, array<string, mixed>> */
    public static function get_tree(int $lesson_id): array {
        $stored = BH_Content::get(self::CONTEXT, $lesson_id);
        if ($stored) return $stored;
        return self::steps_to_tree(BHC_Steps::get($lesson_id));
    }

    /**
     * Rebuilds post_content from `_bhc_steps` via BH_Content::save(); idempotent, since the
     * save hook round-trips the same step data back.
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
                // Promote legacy `questions` into bhc/quiz-question child blocks.
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
            if (strpos($type, 'bhc/') !== 0) continue; // not a block this plugin owns
            if ($type === 'bhc/quiz-question') continue; // only ever a bhc/quiz child
            $legacy_type = substr($type, 4);
            $step = array_merge(['type' => $legacy_type], $block['attrs'] ?? []);

            if ($legacy_type === 'quiz') {
                // Reassemble `questions` from child blocks so scoring stays tree-agnostic.
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
            // Handled inline in render() as a self-submitting form, not via the shared 'handle' redirect flow.
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
