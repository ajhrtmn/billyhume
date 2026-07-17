<?php
if (!defined('ABSPATH')) exit;

/**
 * This plugin's own section on the core's shared Debug Tools page —
 * same extension point, same locking (OUS_Debug::is_locked(), checked
 * centrally by that page before this class's callbacks ever run) every
 * other plugin here uses.
 *
 * "Seed realistic test data" is the actual point: one full course, two
 * lessons, every step type (text, image, quiz), and — if BH Monetization
 * is active — one gated course behind a test tier, PLUS a real test
 * student with progress already partway through, so this whole feature
 * is genuinely clickable/demoable in one button rather than staring at
 * an empty Courses list. Reset wipes only this plugin's own tagged rows;
 * real courses/students are untouched.
 */
class BHC_Debug {
    const SEED_TAG = '__bhc_test__';

    public static function init() {
        // Registration itself happens in the main bootstrap file
        // (add_filter('ous_debug_tools', ...)) — kept as an init() entry
        // point anyway for consistency with every other class here.
    }

    public static function render_section() {
        echo '<p>Seed a fully working course (multiple lessons, every step type, a test student partway through it) — or wipe it all and start clean.</p>';

        echo '<h4>Seed</h4>';
        echo OUS_Debug::button('bh-courses', 'seed_course', 'Seed a complete test course (2 lessons, text + image + quiz steps)');
        if (class_exists('BHM_Tiers')) {
            echo OUS_Debug::button('bh-courses', 'seed_gated_course', 'Seed a second course gated behind a test supporter tier');
        } else {
            echo '<p class="description"><em>Install &amp; activate BH Monetization to also seed a tier-gated course.</em></p>';
        }
        echo OUS_Debug::button('bh-courses', 'seed_student_progress', 'Create a test student, partway through the seeded course (1 lesson done, mid-quiz on the next)');

        echo '<h4>Edge-case lessons</h4>';
        echo '<p class="description">Adds ONE lesson to the seeded course containing exactly the malformed/boundary step data BHC_Steps::save() is supposed to defend against — same cases the PHPUnit/Test Runner suites assert on, but visible/clickable in the real admin UI and front end instead of only asserted in code. Pick a preset, click seed, then open the lesson to see what actually got saved after sanitization.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:4px 8px 4px 0;">';
        echo '<input type="hidden" name="action" value="ous_debug_action">';
        echo '<input type="hidden" name="ous_plugin" value="bh-courses">';
        echo '<input type="hidden" name="ous_debug_action" value="seed_edge_case">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('ous_debug_bh-courses')) . '">';
        echo '<select name="preset">';
        foreach (self::edge_case_presets() as $key => $preset) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($preset['label']) . '</option>';
        }
        echo '</select> ';
        echo '<button class="button button-primary">Seed edge-case lesson</button>';
        echo '</form>';

        echo '<h4>Wipe / reseed</h4>';
        echo OUS_Debug::button('bh-courses', 'reseed', 'Wipe test data and reseed from scratch', '', '', false);
        echo OUS_Debug::button('bh-courses', 'reset', 'Wipe all BH Courses test data (course, lessons, progress, test student)', '', 'Delete all BH Courses test data? This cannot be undone.', false);

        $count = count(get_posts(['post_type' => 'bh_course', 'meta_key' => '_bhc_seed_tag', 'meta_value' => self::SEED_TAG, 'numberposts' => -1, 'fields' => 'ids']));
        echo '<p class="description">Currently seeded test courses: ' . (int) $count . '</p>';
    }

    public static function handle_action($action, $post) {
        switch ($action) {
            case 'seed_course':
                $course_id = self::seed_course('Songwriting Fundamentals ' . self::SEED_TAG, false);
                return "Seeded course #$course_id with 2 lessons.";

            case 'seed_gated_course':
                $course_id = self::seed_course('Advanced Mixing ' . self::SEED_TAG, true);
                return "Seeded gated course #$course_id with 2 lessons and a test supporter tier.";

            case 'seed_student_progress':
                return self::seed_student_progress();

            case 'seed_edge_case':
                return self::seed_edge_case($post['preset'] ?? '');

            case 'reseed':
                self::wipe();
                $course_id = self::seed_course('Songwriting Fundamentals ' . self::SEED_TAG, false);
                self::seed_student_progress();
                return "Wiped and reseeded — course #$course_id plus a test student partway through it.";

            default:
                return 'Unknown action.';
        }
    }

    public static function reset() {
        return self::wipe();
    }

    /* ---------------- seeding ---------------- */

    private static function seed_course($title, $gated) {
        $course_id = wp_insert_post([
            'post_title' => $title, 'post_type' => 'bh_course', 'post_status' => 'publish',
            'post_content' => 'A seeded test course — safe to delete, or use "Wipe" above.',
        ]);
        if (is_wp_error($course_id)) return 0;
        update_post_meta($course_id, '_bhc_seed_tag', self::SEED_TAG);

        if ($gated && class_exists('BHM_Tiers')) {
            $tier_id = wp_insert_post(['post_title' => 'Course Access ' . self::SEED_TAG, 'post_type' => BHM_Tiers::CPT, 'post_status' => 'publish']);
            if (!is_wp_error($tier_id)) {
                update_post_meta($tier_id, '_bhm_price_cents', 500);
                update_post_meta($tier_id, '_bhm_benefits', 'Test tier — safe to delete.');
                update_post_meta($course_id, '_bhm_required_tier', $tier_id);
            }
        }

        $lesson1 = self::seed_lesson($course_id, 'Lesson 1: Song Structure', [
            ['type' => 'text', 'content' => '<p>Most songs lean on a handful of repeating sections: verse, chorus, and sometimes a bridge. This lesson walks through how they work together.</p>'],
            ['type' => 'image', 'attachment_ids' => [], 'caption' => 'A typical verse-chorus-verse-chorus-bridge-chorus layout.'],
            ['type' => 'quiz', 'passing_score' => 70, 'questions' => [
                ['question' => 'Which section usually carries the song\'s main hook?', 'choices' => ['Verse', 'Chorus', 'Bridge'], 'correct_index' => 1],
            ]],
        ]);
        $lesson2 = self::seed_lesson($course_id, 'Lesson 2: Writing a Hook', [
            ['type' => 'text', 'content' => '<p>A hook is the part a listener remembers and hums back. Keep it short, melodically simple, and repeat it.</p>'],
            ['type' => 'quiz', 'passing_score' => 70, 'questions' => [
                ['question' => 'A strong hook is usually...', 'choices' => ['Long and complex', 'Short and repeatable', 'Only in the bridge'], 'correct_index' => 1],
                ['question' => 'True or false: repetition helps a hook stick.', 'choices' => ['True', 'False'], 'correct_index' => 0],
            ]],
        ]);

        update_post_meta($course_id, '_bhc_lesson_order', [$lesson1, $lesson2]);
        return $course_id;
    }

    private static function seed_lesson($course_id, $title, $steps) {
        $lesson_id = wp_insert_post(['post_title' => $title, 'post_type' => 'bh_lesson', 'post_status' => 'publish']);
        if (is_wp_error($lesson_id)) return 0;
        update_post_meta($lesson_id, '_bhc_course_id', $course_id);
        update_post_meta($lesson_id, '_bhc_seed_tag', self::SEED_TAG);
        BHC_Steps::save($lesson_id, $steps);
        return $lesson_id;
    }

    private static function seed_student_progress() {
        $course = self::find_seeded_course();
        if (!$course) return 'Seed a course first (button above).';

        $student_id = OUS_Debug::get_or_create_test_user('bhc_student', false);
        $lesson_ids = BHC_PostTypes::lesson_order($course->ID);
        if (!$lesson_ids) return 'Seeded course has no lessons to mark progress on.';

        // Fully complete lesson 1.
        $first_lesson = $lesson_ids[0];
        foreach (BHC_Steps::get($first_lesson) as $i => $step) {
            if ($step['type'] === 'quiz') {
                $answers = array_fill(0, count($step['questions']), 0);
                foreach ($step['questions'] as $qi => $q) $answers[$qi] = $q['correct_index'];
                $result = BHC_Steps::score_quiz($step, $answers);
                BHC_Progress::mark_step_complete($student_id, $first_lesson, $i, $result['score'], $result['passed']);
            } else {
                BHC_Progress::mark_step_complete($student_id, $first_lesson, $i);
            }
        }

        // Partway (first non-quiz step only) into lesson 2, if it exists.
        if (isset($lesson_ids[1])) {
            $steps = BHC_Steps::get($lesson_ids[1]);
            if (isset($steps[0]) && $steps[0]['type'] !== 'quiz') {
                BHC_Progress::mark_step_complete($student_id, $lesson_ids[1], 0);
            }
        }

        $percent = BHC_Progress::course_percent($student_id, $course->ID);
        return "Test student #$student_id (user_login: see Users list, tagged bhcore_is_test=bhc_student) is now {$percent}% through \"" . esc_html($course->post_title) . "\".";
    }

    /**
     * The named edge-case presets, one raw (pre-sanitization) step array
     * per preset — deliberately the exact same shapes BHC_TestSuite's
     * assertions and the PHPUnit suite in tests/ already cover, so a
     * failure surfaced in either place has a matching "go look at it for
     * real" button here rather than living only as a pass/fail row.
     * Keyed so new presets can be added without renumbering anything.
     */
    private static function edge_case_presets() {
        return [
            'empty_lesson' => [
                'label' => 'Empty lesson (zero steps)',
                'steps' => [],
            ],
            'unknown_step_type' => [
                'label' => 'Unknown step type + no-type-key step (both should be dropped)',
                'steps' => [
                    ['type' => 'gif', 'content' => 'a type this plugin has never heard of'],
                    ['content' => '<p>this step has no "type" key at all</p>'],
                    ['type' => 'text', 'content' => '<p>this ordinary step should be the ONLY one that survives</p>'],
                ],
            ],
            'quiz_boundaries' => [
                'label' => 'Quiz with out-of-range correct_index + passing_score (should clamp)',
                'steps' => [
                    ['type' => 'quiz', 'passing_score' => 150, 'max_attempts' => -5, 'questions' => [
                        ['question' => 'correct_index way too high', 'choices' => ['A', 'B'], 'correct_index' => 99],
                        ['question' => 'correct_index negative', 'choices' => ['A', 'B'], 'correct_index' => -3],
                    ]],
                    ['type' => 'quiz', 'passing_score' => -20, 'questions' => [
                        ['question' => 'passing_score below zero', 'choices' => ['A', 'B'], 'correct_index' => 0],
                    ]],
                ],
            ],
            'quiz_zero_questions' => [
                'label' => 'Quiz step with zero questions (should be dropped entirely)',
                'steps' => [
                    ['type' => 'quiz', 'passing_score' => 70, 'questions' => []],
                    ['type' => 'text', 'content' => '<p>this text step should be the only survivor</p>'],
                ],
            ],
            'quiz_missing_passing_score' => [
                'label' => 'Quiz step with no passing_score key (should default to 70)',
                'steps' => [
                    ['type' => 'quiz', 'questions' => [
                        ['question' => 'No passing_score was set on this step', 'choices' => ['A', 'B'], 'correct_index' => 0],
                    ]],
                ],
            ],
            'video_urls' => [
                'label' => 'Video steps: empty URL + malformed URL (both should be dropped)',
                'steps' => [
                    ['type' => 'video', 'source' => 'url', 'video_url' => ''],
                    ['type' => 'video', 'source' => 'url', 'video_url' => 'not a url'],
                    ['type' => 'video', 'source' => 'url', 'video_url' => 'https://example.com/real-video.mp4'],
                ],
            ],
            'image_invalid_ids' => [
                'label' => 'Image step with zero/negative/non-numeric attachment IDs (should be filtered out)',
                'steps' => [
                    ['type' => 'image', 'attachment_ids' => [0, -1, 'not-a-number'], 'caption' => 'every ID here is invalid'],
                ],
            ],
            'text_xss' => [
                'label' => 'Text step containing a <script> tag (should be stripped by wp_kses_post)',
                'steps' => [
                    ['type' => 'text', 'content' => '<p>Hello</p><script>alert(1)</script><p>World</p>'],
                ],
            ],
        ];
    }

    private static function seed_edge_case($preset_key) {
        $presets = self::edge_case_presets();
        if (!isset($presets[$preset_key])) return 'Unknown preset.';

        $course = self::find_seeded_course();
        if (!$course) {
            $course_id = self::seed_course('Songwriting Fundamentals ' . self::SEED_TAG, false);
            $course = get_post($course_id);
            if (!$course) return 'Could not seed a course to attach this lesson to.';
        }

        $label = $presets[$preset_key]['label'];
        $lesson_id = self::seed_lesson($course->ID, 'Edge case: ' . $label, $presets[$preset_key]['steps']);
        if (!$lesson_id) return 'Could not create the edge-case lesson.';

        $order = (array) get_post_meta($course->ID, '_bhc_lesson_order', true);
        $order[] = $lesson_id;
        update_post_meta($course->ID, '_bhc_lesson_order', array_values(array_unique($order)));

        $saved = BHC_Steps::get($lesson_id);
        $sent = count($presets[$preset_key]['steps']);
        $kept = count($saved);
        return "Seeded lesson #$lesson_id (\"$label\") on \"" . esc_html($course->post_title) . "\" — sent $sent raw step(s), BHC_Steps::save() kept $kept after sanitization. Open the lesson (or the course front end) to see the actual saved result.";
    }

    private static function find_seeded_course() {
        $posts = get_posts(['post_type' => 'bh_course', 'meta_key' => '_bhc_seed_tag', 'meta_value' => self::SEED_TAG, 'numberposts' => 1]);
        return $posts[0] ?? null;
    }

    /* ---------------- wipe ---------------- */

    private static function wipe() {
        $courses = get_posts(['post_type' => 'bh_course', 'meta_key' => '_bhc_seed_tag', 'meta_value' => self::SEED_TAG, 'numberposts' => -1]);
        $lessons = get_posts(['post_type' => 'bh_lesson', 'meta_key' => '_bhc_seed_tag', 'meta_value' => self::SEED_TAG, 'numberposts' => -1]);

        global $wpdb;
        $lesson_ids = array_map(fn($l) => $l->ID, $lessons);
        if ($lesson_ids) {
            $placeholders = implode(',', array_fill(0, count($lesson_ids), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bhc_progress WHERE lesson_id IN ($placeholders)", $lesson_ids));
        }

        foreach ($lessons as $l) wp_delete_post($l->ID, true);
        foreach ($courses as $c) {
            $tier_id = (int) get_post_meta($c->ID, '_bhm_required_tier', true);
            if ($tier_id && class_exists('BHM_Tiers')) {
                $tier_post = get_post($tier_id);
                if ($tier_post && strpos($tier_post->post_title, self::SEED_TAG) !== false) wp_delete_post($tier_id, true);
            }
            wp_delete_post($c->ID, true);
        }

        // Test students created via OUS_Debug::get_or_create_test_user()
        // are shared/tagged infrastructure (bhcore_is_test) reset by the
        // "Wipe all test data (every plugin)" button on the main Debug
        // Tools page, not by this plugin's own reset alone — deleting
        // user accounts here could also wipe another plugin's test
        // fixtures tagged onto the same account, which isn't this
        // plugin's call to make.
        return count($courses) . ' seeded course(s) and ' . count($lessons) . ' seeded lesson(s) removed, plus their progress rows. Test student ACCOUNTS are left in place (shared across plugins) — use "Wipe all test data" on the main Debug Tools page to remove those too.';
    }
}
