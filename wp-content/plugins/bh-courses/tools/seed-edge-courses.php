<?php
/**
 * Edge-case demo courses for user-journey testing, alongside seed-demo-course.php:
 *  - a long paid masterclass: very long titles/descriptions, 4 modules, every step type,
 *    a YouTube video with many chapters and banners, two videos in one lesson, a
 *    watch-gated last step, an empty lesson, a quiz-only lesson with limited attempts,
 *    and a lesson that unlocks 7 days after enrolling;
 *  - a free one-lesson course with a certificate;
 *  - a supporter-tier-only course (locked for non-members).
 * Plus persona accounts (passwords printed once, never stored).
 *
 *   wp eval-file wp-content/plugins/bh-courses/tools/seed-edge-courses.php
 *
 * Uses the same _bhc_demo_tag as seed-demo-course.php, so DEMO_RESET=1 on that script
 * removes these too. Local/dev use only.
 */
if (!defined('ABSPATH')) exit;

const BHC_EDGE_TAG = 'low-end-theory-demo';
$tag = fn(int $id) => update_post_meta($id, '_bhc_demo_tag', BHC_EDGE_TAG);
$exists = get_posts(['post_type' => 'bh_course', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_bhc_edge_course', 'meta_value' => 'masterclass']);
if ($exists) { echo "Edge courses already exist (masterclass #{$exists[0]}). Reset with DEMO_RESET=1 on seed-demo-course.php.\n"; return; }

$file_video = 'https://archive.org/download/ElephantsDream/ed_1024_512kb.mp4';
$file_video_2 = 'https://archive.org/download/Sintel/sintel-2048-stereo_512kb.mp4';
$youtube = 'https://www.youtube.com/watch?v=aqz-KE-bpKQ'; // Big Buck Bunny, Blender's official upload

// Two short generated tones for the A/B audio-compare step (no files to fetch).
$wav = function (string $name, float $freq, float $gain) use ($tag): int {
    $rate = 22050; $secs = 3; $data = '';
    for ($i = 0; $i < $rate * $secs; $i++) $data .= pack('v', (int) (sin(2 * M_PI * $freq * $i / $rate) * 32767 * $gain) & 0xFFFF);
    $bin = 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16) . 'data' . pack('V', strlen($data)) . $data;
    $up = wp_upload_bits($name, null, $bin);
    if (!empty($up['error'])) return 0;
    $id = wp_insert_attachment(['post_title' => pathinfo($name, PATHINFO_FILENAME), 'post_mime_type' => 'audio/wav', 'post_status' => 'inherit'], $up['file']);
    if ($id) $tag($id);
    return (int) $id;
};
$audio_a = $wav('demo-bass-before.wav', 55, 0.25);
$audio_b = $wav('demo-bass-after.wav', 55, 0.6);
$doc = function (string $name, string $body) use ($tag): int {
    $up = wp_upload_bits($name, null, $body);
    if (!empty($up['error'])) return 0;
    $id = wp_insert_attachment(['post_title' => pathinfo($name, PATHINFO_FILENAME), 'post_mime_type' => 'text/plain', 'post_status' => 'inherit'], $up['file']);
    if ($id) $tag($id);
    return (int) $id;
};
$unlabeled_file = $doc('masterclass-mix-template-v3-final-FINAL.txt', "Template notes.\n");
$image = (int) (get_posts(['post_type' => 'attachment', 'post_mime_type' => 'image', 'numberposts' => 1, 'post_status' => 'inherit', 'fields' => 'ids'])[0] ?? 0);

$course = function (string $title, string $content, array $meta, string $edge) use ($tag): int {
    $id = wp_insert_post(['post_type' => 'bh_course', 'post_status' => 'publish', 'post_title' => $title, 'post_author' => 1, 'post_content' => $content]);
    $tag($id);
    update_post_meta($id, '_bhc_edge_course', $edge);
    foreach ($meta as $k => $v) update_post_meta($id, $k, $v);
    return (int) $id;
};
$lesson = function (int $course_id, string $title, string $module, string $excerpt, array $steps, array $meta = []) use ($tag): int {
    $id = wp_insert_post(['post_type' => 'bh_lesson', 'post_status' => 'publish', 'post_title' => $title, 'post_excerpt' => $excerpt]);
    $tag($id);
    update_post_meta($id, '_bhc_course_id', $course_id);
    update_post_meta($id, '_bhc_module_title', $module);
    foreach ($meta as $k => $v) update_post_meta($id, $k, $v);
    BHC_Steps::save($id, $steps);
    BHC_ContentBridge::migrate_lesson($id);
    return (int) $id;
};
$long = str_repeat('Low end is where most home mixes fall apart, because small speakers and untreated rooms lie about what is really there. ', 6);

/* ---------- 1. Long, complicated paid masterclass ---------- */
$mc = $course(
    'The Complete Low End Masterclass: Mixing Kick, Bass and Sub for Clubs, Cars, Earbuds and Everything In Between (Demo)',
    '<p>' . $long . '</p><h3>Who this is for</h3><p>Producers who already finish tracks and want them to translate.</p>',
    ['_bhc_purchase_price_cents' => 12900, '_bhc_duration_note' => '~6 hours of video', '_bhc_difficulty' => 'advanced',
     '_bhc_credits' => "Taught by Billy Hume.\nDemo footage: Elephants Dream and Sintel (c) Blender Foundation, CC BY; Big Buck Bunny via YouTube.",
     '_bhc_landing_tagline' => 'Everything about low end, start to finish, in one place.',
     '_bhc_landing_faq' => "Is this too advanced for me?\nIf you have finished at least one track, no.\n\nWhy are some lessons locked?\nThe last module opens a week after you start, so you have time to practise first."],
    'masterclass'
);
$mc_product = BHM_ProductSync::sync_object_purchase_product($mc, 'bh_course', 12900);
if ($mc_product) $tag($mc_product);

$m1 = 'Module 1: Listening Before You Touch a Single Knob — Training Your Ears for the Bottom Octave';
$ids = [];
$ids[] = $lesson($mc, 'Why your mix sounds great in the studio and falls apart everywhere else, and what to do about it', $m1,
    'A long description on purpose, to see how it wraps: ' . substr($long, 0, 260), [
    ['type' => 'video', 'source' => 'url', 'video_url' => $youtube, 'caption' => 'YouTube-hosted lesson video.',
        'chapters' => array_map(fn($i) => ['time' => $i * 45, 'title' => 'Part ' . ($i + 1) . ($i === 3 ? ': a deliberately long chapter title that should wrap neatly on phones' : '')], range(0, 9)),
        'annotations' => [
            ['time' => 4, 'type' => 'banner', 'payload' => ['text' => 'Small banner: quick tip.', 'duration' => 4, 'size' => 'small']],
            ['time' => 12, 'type' => 'banner', 'payload' => ['text' => 'Medium banner with a much longer message, to check it wraps over the video without covering the controls.', 'duration' => 10, 'size' => 'medium']],
        ]],
    ['type' => 'text', 'content' => '<p>' . $long . '</p>'],
]);
$ids[] = $lesson($mc, 'Two videos, back to back', $m1, 'A lesson with two separate video steps.', [
    ['type' => 'video', 'source' => 'url', 'video_url' => $file_video, 'caption' => 'Part one.'],
    ['type' => 'callout', 'variant' => 'warning', 'content' => '<p>Turn your monitors down before the next clip.</p>'],
    ['type' => 'video', 'source' => 'url', 'video_url' => $file_video_2, 'caption' => 'Part two.', 'annotations' => [['time' => 30, 'type' => 'banner', 'payload' => ['text' => 'Large banner at 0:30.', 'duration' => 12, 'size' => 'large']]]],
]);
$m2 = 'Module 2: Tools';
$ids[] = $lesson($mc, 'Every step type in one lesson', $m2, 'Text, image, callouts, checklist, chord chart, A/B audio, file and a quiz.', array_values(array_filter([
    ['type' => 'text', 'content' => '<p>Short intro.</p>'],
    $image ? ['type' => 'image', 'attachment_ids' => [$image], 'caption' => 'An image step.'] : null,
    ['type' => 'callout', 'variant' => 'note', 'content' => '<p>A note callout.</p>'],
    ['type' => 'checklist', 'title' => 'Setup checklist', 'items' => ['Monitors level-matched', 'Room treatment checked', 'A really long checklist item that keeps going so we can see whether it wraps cleanly next to its checkbox on a small phone screen']],
    ['type' => 'chord-chart', 'title' => 'Bass line roots', 'content' => "E  -  G\nA  -  C"],
    ($audio_a && $audio_b) ? ['type' => 'audio-compare', 'attachment_id_a' => $audio_a, 'attachment_id_b' => $audio_b, 'label_a' => 'Before', 'label_b' => 'After', 'caption' => 'Hear the difference.'] : null,
    ['type' => 'resource', 'attachment_id' => $unlabeled_file, 'label' => '', 'description' => ''],
    ['type' => 'quiz', 'passing_score' => 50, 'questions' => [['question' => 'Mono below?', 'choices' => ['120 Hz', '12 kHz'], 'correct_index' => 0]]],
])));
$ids[] = $lesson($mc, 'Coming soon', $m2, 'An empty lesson, as if Billy has not added steps yet.', []);
$ids[] = $lesson($mc, 'Checkpoint quiz (2 attempts)', $m2, 'Only a quiz, with a limited number of tries.', [
    ['type' => 'quiz', 'passing_score' => 100, 'max_attempts' => 2, 'questions' => [
        ['question' => 'Which usually carries the fundamental of a kick?', 'choices' => ['40-70 Hz', '1-2 kHz', '8-10 kHz'], 'correct_index' => 0],
        ['question' => 'Sidechaining the bass to the kick mainly...', 'choices' => ['adds reverb', 'gives the kick room', 'raises the bass pitch'], 'correct_index' => 1],
        ['question' => 'A spectrum analyser can replace your ears.', 'choices' => ['True', 'False'], 'correct_index' => 1],
    ]],
]);
$m3 = 'Module 3: Practice';
$ids[] = $lesson($mc, 'Watch it all: the last step is a gated video', $m3, 'Ends on a video you must watch 90% of.', [
    ['type' => 'text', 'content' => '<p>Read this first.</p>'],
    ['type' => 'video', 'source' => 'url', 'video_url' => $file_video, 'watch_threshold' => 90, 'caption' => 'Watch 90% to finish.'],
]);
$m4 = 'Module 4: Advanced (opens a week after you start)';
$ids[] = $lesson($mc, 'Parallel saturation for sub that reads on phones', $m4, 'Locked until 7 days after enrolling.', [
    ['type' => 'text', 'content' => '<p>Advanced content.</p>'],
], ['_bhc_available_after_days' => 7]);
$ids[] = $lesson($mc, 'Final mix review', $m4, 'Also drip-locked.', [
    ['type' => 'checklist', 'title' => 'Final pass', 'items' => ['Phone check', 'Car check', 'Club check']],
], ['_bhc_available_after_days' => 7]);
update_post_meta($mc, '_bhc_lesson_order', $ids);

/* ---------- 2. Free one-lesson course with a certificate ---------- */
$free = $course('Free: One Quick Low End Tip (Demo)', '<p>A free taster. One lesson, one video.</p>',
    ['_bhc_certificate_enabled' => 1, '_bhc_landing_tagline' => 'Five minutes, one tip, free.', '_ous_public_access' => 1], 'free');
$free_lesson = $lesson($free, 'The one EQ move that fixes most muddy mixes', '', 'One video, then you are done.', [
    ['type' => 'video', 'source' => 'url', 'video_url' => $file_video, 'caption' => 'The tip.',
        'annotations' => [['time' => 6, 'type' => 'question', 'payload' => ['question' => 'Where does mud usually live?', 'choices' => ['200-400 Hz', '5 kHz'], 'correct_index' => 0]]]],
]);
update_post_meta($free, '_bhc_lesson_order', [$free_lesson]);
update_post_meta($free_lesson, '_ous_public_access', 1);

/* ---------- 3. Supporter-tier-only course ---------- */
$tier_id = 0;
if (class_exists('BHM_Tiers')) {
    $tier_id = (int) wp_insert_post(['post_type' => BHM_Tiers::CPT, 'post_status' => 'publish', 'post_title' => 'Supporter (Demo)']);
    $tag($tier_id);
    update_post_meta($tier_id, '_bhm_price_cents', 500);
    update_post_meta($tier_id, '_bhm_benefits', 'Demo tier for testing locked courses.');
}
$tiered = $course('Supporter-Only Session Breakdowns (Demo)', '<p>Only for supporters.</p>', ['_bhm_required_tier' => $tier_id], 'tiered');
$t1 = $lesson($tiered, 'Breaking down a full session', '', 'Members only.', [['type' => 'text', 'content' => '<p>Members-only content.</p>']]);
update_post_meta($tiered, '_bhc_lesson_order', [$t1]);

/* ---------- pages ---------- */
$page = function (string $title, string $slug, string $content) use ($tag): int {
    $id = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug, 'post_content' => $content]);
    $tag($id);
    return $id;
};
$mc_page = $page('Masterclass (Demo sales page)', 'masterclass-demo', '<!-- wp:bhc/course-landing {"id":' . $mc . '} /-->');
$free_page = $page('Free tip (Demo sales page)', 'free-tip-demo', '[bh_course_landing id="' . $free . '"]');

/* ---------- persona accounts ---------- */
$persona = function (string $login, string $name) {
    $pass = wp_generate_password(16, false);
    $id = wp_insert_user(['user_login' => $login, 'user_email' => $login . '@example.test', 'user_pass' => $pass, 'display_name' => $name, 'role' => 'bh_member']);
    if (is_wp_error($id)) return [0, ''];
    update_user_meta($id, '_bhc_demo_tag', BHC_EDGE_TAG);
    return [(int) $id, $pass];
};
$people = [
    'demo-rosa' => 'Rosa (64, phone, new to tech)',
    'demo-dev' => 'Dev (pro engineer, impatient)',
    'demo-kai' => 'Kai (16, beginner, distracted)',
    'demo-morgan' => 'Morgan (keyboard and screen reader)',
    'demo-sam' => 'Sam (gifted VIP)',
    'demo-priya' => 'Priya (trying the free course)',
];
$created = [];
foreach ($people as $login => $name) $created[$login] = $persona($login, $name);
foreach (['demo-dev', 'demo-kai', 'demo-morgan', 'demo-sam'] as $login) {
    BHM_GiftAccess::gift($mc, $login . '@example.test', 'Demo access.', 1);
}
// demo-learner (from seed-demo-course.php) also gets the masterclass, to see two courses on My Courses.
if (get_user_by('login', 'demo-learner')) BHM_GiftAccess::gift($mc, 'demo-learner@example.test', 'Second course for the demo.', 1);

echo "Masterclass #$mc: " . get_permalink($mc) . "\nFree course #$free: " . get_permalink($free) . "\nTier-only course #$tiered: " . get_permalink($tiered) . "\n";
echo "Sales pages: " . get_permalink($mc_page) . " , " . get_permalink($free_page) . "\n";
echo "Personas (shown once):\n";
foreach ($created as $login => [$id, $pass]) echo "  $login / $pass\n";
