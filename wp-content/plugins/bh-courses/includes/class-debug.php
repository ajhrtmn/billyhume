<?php
if (!defined('ABSPATH')) exit;

/**
 * This plugin's own section on the core's shared Debug Tools page —
 * same extension point, same locking (OUS_Debug::is_locked(), checked
 * centrally by that page before this class's callbacks ever run) every
 * other plugin here uses.
 *
 * "Seed realistic test data" is the actual point: one full course, two
 * lessons, the original three step types (text, image, quiz — audit fix,
 * 2026-07-25: corrected from "every step type," which overclaimed; the
 * four LMS depth-of-magic types — callout/checklist/chord-chart/
 * audio-compare — aren't seeded here yet), and — if BH Monetization
 * is active — one gated course behind a test tier, PLUS a real test
 * student with progress already partway through, so this whole feature
 * is genuinely clickable/demoable in one button rather than staring at
 * an empty Courses list. Reset wipes only this plugin's own tagged rows;
 * real courses/students are untouched.
 */
class BHC_Debug {
    const SEED_TAG = '__bhc_test__';

    public static function init(): void {
        // Registration itself happens in the main bootstrap file
        // (add_filter('ous_debug_tools', ...)) — kept as an init() entry
        // point anyway for consistency with every other class here.
    }

    public static function render_section(): void {
        echo '<p>Seed a fully working course (multiple lessons, text/image/quiz steps, a test student partway through it) — or wipe it all and start clean.</p>';

        echo '<h4>Seed</h4>';
        OUS_Debug::button('bh-courses', 'seed_course', 'Seed a complete test course (2 lessons, text + image + quiz steps)');
        if (class_exists('BHM_Tiers')) {
            OUS_Debug::button('bh-courses', 'seed_gated_course', 'Seed a second course gated behind a test supporter tier');
        } else {
            echo '<p class="description"><em>Install &amp; activate BH Monetization to also seed a tier-gated course.</em></p>';
        }
        OUS_Debug::button('bh-courses', 'seed_student_progress', 'Create a test student, partway through the seeded course (1 lesson done, mid-quiz on the next)');

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
        OUS_Debug::button('bh-courses', 'reseed', 'Wipe test data and reseed from scratch', '', '', false);
        OUS_Debug::button('bh-courses', 'reset', 'Wipe all BH Courses test data (course, lessons, progress, test student)', '', 'Delete all BH Courses test data? This cannot be undone.', false);

        $count = count(get_posts(['post_type' => 'bh_course', 'meta_key' => '_bhc_seed_tag', 'meta_value' => self::SEED_TAG, 'numberposts' => -1, 'fields' => 'ids']));
        echo '<p class="description">Currently seeded test courses: ' . (int) $count . '</p>';
    }

    /** @param array<string, mixed> $post */
    public static function handle_action(string $action, $post): string {
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

    public static function reset(): string {
        return self::wipe();
    }

    /* ---------------- seeding ---------------- */

    private static function seed_course(string $title, bool $gated): int {
        $course_id = wp_insert_post([
            'post_title' => $title, 'post_type' => 'bh_course', 'post_status' => 'publish',
            'post_content' => 'A seeded test course — safe to delete, or use "Wipe" above.',
        ]);
        if (!$course_id) return 0;
        update_post_meta($course_id, '_bhc_seed_tag', self::SEED_TAG);

        if ($gated && class_exists('BHM_Tiers')) {
            $tier_id = wp_insert_post(['post_title' => 'Course Access ' . self::SEED_TAG, 'post_type' => BHM_Tiers::CPT, 'post_status' => 'publish']);
            if ($tier_id) {
                update_post_meta($tier_id, '_bhm_price_cents', 500);
                update_post_meta($tier_id, '_bhm_benefits', 'Test tier — safe to delete.');
                update_post_meta($course_id, '_bhm_required_tier', $tier_id);
            }
        }

        $lesson1 = self::seed_lesson($course_id, 'Lesson 1: Song Structure', [
            ['type' => 'text', 'content' => '<p>Most songs lean on a handful of repeating sections: verse, chorus, and sometimes a bridge. A verse introduces new information every time it comes around — a new scene, a new detail, forward motion in the story. The chorus is the opposite: it repeats, near-verbatim, and carries the song\'s emotional center of gravity. A bridge, when a song has one, is a deliberate detour — a new chord progression or melodic idea that makes the final chorus land harder by contrast.</p><p>The most common shape in modern songwriting is verse–chorus–verse–chorus–bridge–chorus. It is common because it works, not because it is the only option — plenty of great songs skip the bridge entirely, or use a pre-chorus to build tension into the hook. This lesson focuses on the standard shape first, since every variation is easier to understand once the baseline is second nature.</p>'],
            ['type' => 'image', 'attachment_ids' => [], 'caption' => 'A typical verse-chorus-verse-chorus-bridge-chorus layout, mapped against a two-and-a-half-minute runtime.'],
            ['type' => 'callout', 'variant' => 'tip', 'content' => '<p>Try mapping an existing song you love onto this shape before writing your own — you will start hearing the architecture everywhere.</p>'],
            ['type' => 'checklist', 'title' => 'Before you move on', 'items' => [
                'Can you name the verse/chorus boundary in three songs off the top of your head?',
                'Do you know which section of your own song idea repeats?',
                'Have you picked the emotional peak your bridge (if any) should set up?',
            ]],
            ['type' => 'quiz', 'passing_score' => 70, 'questions' => [
                ['question' => 'Which section usually carries the song\'s main hook?', 'choices' => ['Verse', 'Chorus', 'Bridge'], 'correct_index' => 1],
                ['question' => 'What is a bridge\'s main job?', 'choices' => ['Introduce the title', 'Repeat the verse melody', 'Provide contrast before the final chorus'], 'correct_index' => 2],
            ]],
        ]);
        $lesson2 = self::seed_lesson($course_id, 'Lesson 2: Writing a Hook', [
            ['type' => 'text', 'content' => '<p>A hook is the part a listener remembers and hums back without trying. It is usually short — often just a handful of syllables — melodically simple enough to sing on the first listen, and repeated at least two or three times within the song. Lyrically, the strongest hooks tend to compress the whole song\'s idea into one line; if you can\'t explain your song in the hook line alone, the hook probably is not doing its job yet.</p><p>A common mistake is writing a hook that is interesting to the songwriter but forgettable to everyone else, usually because it is too wordy or the melody wanders. Try singing your hook idea from memory five minutes after writing it — if you stumble, simplify.</p>'],
            ['type' => 'video', 'source' => 'url', 'video_url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4', 'caption' => 'Example teaching video for this lesson (placeholder footage — swap for a real lesson recording).'],
            ['type' => 'callout', 'variant' => 'warning', 'content' => '<p>Do not bury your hook past the one-minute mark — most listeners decide whether to keep listening well before then.</p>'],
            ['type' => 'quiz', 'passing_score' => 70, 'questions' => [
                ['question' => 'A strong hook is usually...', 'choices' => ['Long and complex', 'Short and repeatable', 'Only in the bridge'], 'correct_index' => 1],
                ['question' => 'True or false: repetition helps a hook stick.', 'choices' => ['True', 'False'], 'correct_index' => 0],
                ['question' => 'A good test for a hook is...', 'choices' => ['Whether you can recall it 5 minutes later', 'Whether it uses complex chords', 'Whether it appears only once'], 'correct_index' => 0],
            ]],
        ]);
        $lesson3 = self::seed_lesson($course_id, 'Lesson 3: Chords That Support the Melody', [
            ['type' => 'text', 'content' => '<p>Chord choice is in service of the melody, not the other way around — a chord progression\'s job is to make the melody notes above it feel inevitable. Start simple: a I–V–vi–IV progression (or any rotation of it) underlies an enormous share of popular music because it gives a melody a stable, singable harmonic bed without competing for attention.</p><p>Once the basic progression is locked in, small substitutions — a relative minor swapped in, a passing chord between two anchors — are where a song starts to sound like it has its own identity instead of a generic template.</p>'],
            ['type' => 'chord-chart', 'title' => 'Common verse progression (key of C)', 'content' => "C  -  G\nAm -  F\nC  -  G\nAm -  F"],
            ['type' => 'quiz', 'passing_score' => 70, 'questions' => [
                ['question' => 'A chord progression\'s primary job is to...', 'choices' => ['Compete with the melody for attention', 'Support and frame the melody', 'Always use as many chords as possible'], 'correct_index' => 1],
            ]],
        ]);
        $lesson4 = self::seed_lesson($course_id, 'Lesson 4: Editing a Rough Draft', [
            ['type' => 'text', 'content' => '<p>A first draft\'s job is to exist, not to be good — editing is where the actual craft happens. Read your lyric out loud (not sung) and flag any line that only makes sense because you already know what you meant. A listener does not have that advantage; every line has to carry its own weight.</p><p>Cut ruthlessly. If a verse says the same thing twice in different words, keep the stronger version and delete the other one entirely — a shorter, denser verse almost always beats a longer, padded one.</p>'],
            ['type' => 'checklist', 'title' => 'Editing pass', 'items' => [
                'Read the lyric out loud with no melody',
                'Cut any line that only makes sense with outside context',
                'Check the hook still lands in under 60 seconds',
                'Confirm the bridge actually contrasts the verse/chorus',
            ]],
            ['type' => 'quiz', 'passing_score' => 70, 'questions' => [
                ['question' => 'The best first step when editing a rough draft is to...', 'choices' => ['Add more instrumentation', 'Read the lyric out loud with no melody', 'Immediately record a final take'], 'correct_index' => 1],
            ]],
        ]);
        $lesson5 = self::seed_lesson($course_id, 'Lesson 5: Putting a Full Song Together', [
            ['type' => 'text', 'content' => '<p>With structure, a hook, supporting chords, and an edited lyric in hand, the final step is arrangement — deciding what plays where, and just as importantly, what stays silent. A common beginner mistake is having every instrument play through the entire song at the same intensity; contrast between a stripped-down verse and a fuller chorus is often what makes the chorus feel like a chorus at all, independent of the melody itself.</p><p>This lesson closes out the course — the quiz below draws on everything covered in lessons 1 through 4.</p>'],
            ['type' => 'callout', 'variant' => 'note', 'content' => '<p>There is no single correct arrangement. Try at least two contrasting versions of your own song\'s dynamics before committing to one.</p>'],
            ['type' => 'quiz', 'passing_score' => 70, 'questions' => [
                ['question' => 'A common beginner arrangement mistake is...', 'choices' => ['Having every instrument play at the same intensity throughout', 'Using contrast between sections', 'Leaving space in the arrangement'], 'correct_index' => 0],
                ['question' => 'What makes a chorus feel bigger than the verse before it?', 'choices' => ['A louder vocal only', 'Contrast in arrangement density', 'A faster tempo'], 'correct_index' => 1],
            ]],
        ]);

        update_post_meta($course_id, '_bhc_lesson_order', [$lesson1, $lesson2, $lesson3, $lesson4, $lesson5]);
        return $course_id;
    }

    /** @param array<int, array<string, mixed>> $steps */
    private static function seed_lesson(int $course_id, string $title, $steps): int {
        $lesson_id = wp_insert_post(['post_title' => $title, 'post_type' => 'bh_lesson', 'post_status' => 'publish']);
        if (!$lesson_id) return 0;
        update_post_meta($lesson_id, '_bhc_course_id', $course_id);
        update_post_meta($lesson_id, '_bhc_seed_tag', self::SEED_TAG);
        BHC_Steps::save($lesson_id, $steps);
        return $lesson_id;
    }

    private static function seed_student_progress(): string {
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
    /** @return array<string, array<string, mixed>> */
    private static function edge_case_presets(): array {
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

    private static function seed_edge_case(string $preset_key): string {
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

    private static function find_seeded_course(): ?\WP_Post {
        $posts = get_posts(['post_type' => 'bh_course', 'meta_key' => '_bhc_seed_tag', 'meta_value' => self::SEED_TAG, 'numberposts' => 1]);
        return $posts[0] ?? null;
    }

    /* ---------------- wipe ---------------- */

    private static function wipe(): string {
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
