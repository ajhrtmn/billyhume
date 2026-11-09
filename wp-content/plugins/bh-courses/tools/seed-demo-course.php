<?php
/**
 * Seeds a realistic "Low End Theory (Demo)" course for walking the student experience:
 * modules, lesson descriptions, videos with chapters/banners/overlays, quizzes, downloads,
 * credits, a sales page, a Support page, an automatic launch coupon and two demo students.
 *
 *   wp eval-file wp-content/plugins/bh-courses/tools/seed-demo-course.php
 *   DEMO_RESET=1 wp eval-file ...   (deletes the demo first, then rebuilds it)
 *
 * Everything it creates carries meta _bhc_demo_tag = low-end-theory-demo. Demo account
 * passwords are printed once and never stored; reset them in Users if lost.
 * Local/dev use only: never run this on the live site.
 */
if (!defined('ABSPATH')) exit;

const BHC_DEMO_TAG = 'low-end-theory-demo';
$demo_video = 'https://archive.org/download/ElephantsDream/ed_1024_512kb.mp4'; // ~11 min public sample (Elephants Dream, CC BY 2.5), long enough to test resume

$tagged = fn(string $type) => get_posts(['post_type' => $type, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_bhc_demo_tag', 'meta_value' => BHC_DEMO_TAG]);

if (getenv('DEMO_RESET')) {
    foreach (['bh_lesson', 'bh_course', 'page', 'shop_coupon', 'product', 'attachment'] as $type) {
        foreach ($tagged($type) as $id) $type === 'attachment' ? wp_delete_attachment($id, true) : wp_delete_post($id, true);
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach (get_users(['meta_key' => '_bhc_demo_tag', 'meta_value' => BHC_DEMO_TAG]) as $u) wp_delete_user($u->ID);
    BHM_AutoCoupons::flush();
    echo "Demo removed.\n";
}
if ($tagged('bh_course')) {
    echo "Demo already exists (course #" . $tagged('bh_course')[0] . "). Run with DEMO_RESET=1 to rebuild.\n";
    return;
}
$tag = fn(int $id) => update_post_meta($id, '_bhc_demo_tag', BHC_DEMO_TAG);

// A small downloadable file, as a student would get.
$attach = function (string $name, string $body) use ($tag): int {
    $up = wp_upload_bits($name, null, $body);
    if (!empty($up['error'])) return 0;
    $id = wp_insert_attachment(['post_title' => pathinfo($name, PATHINFO_FILENAME), 'post_mime_type' => 'text/plain', 'post_status' => 'inherit'], $up['file']);
    if ($id) $tag($id);
    return (int) $id;
};
$notes_file = $attach('low-end-theory-session-notes.txt', "Low End Theory: session notes\n\nKick: 50-60 Hz fundamental, click at 2-4 kHz.\nBass: carve 1-2 dB where the kick lives.\nMono everything under 120 Hz.\n");
$chart_file = $attach('low-end-theory-sub-chart.txt', "Sub bass note/frequency chart\n\nE1 41.2 Hz\nF1 43.7 Hz\nG1 49.0 Hz\nA1 55.0 Hz\nB1 61.7 Hz\nC2 65.4 Hz\n");

$course_id = wp_insert_post([
    'post_type' => 'bh_course', 'post_status' => 'publish', 'post_title' => 'Low End Theory (Demo)', 'post_author' => 1,
    'post_content' => "<h3>Make your low end hit on every system</h3>\n<p>Five short lessons on getting kick and bass to work together: from setting up your session to a finished, translated mix. Built from the way Billy actually works in the studio.</p>\n<ul><li>Session files and charts to download</li><li>Quizzes to check you've got it</li><li>Your own private notes and video bookmarks</li></ul>",
]);
$tag($course_id);
update_post_meta($course_id, '_bhc_duration_note', '~45 minutes of video');
update_post_meta($course_id, '_bhc_difficulty', 'intermediate');
update_post_meta($course_id, '_bhc_credits', "Taught by Billy Hume.\nFilmed and edited at the studio.\nDemo content: placeholder footage from w3schools.com.");
update_post_meta($course_id, '_bhc_landing_tagline', 'Kick and bass that hit on every speaker, from earbuds to club rigs.');
update_post_meta($course_id, '_bhc_landing_video', $demo_video);
update_post_meta($course_id, '_bhc_landing_testimonials', "Finally understand why my mixes fell apart in the car.\n— Jordan, producer\n\nThe kick and bass lesson alone was worth it.\n— Priya");
update_post_meta($course_id, '_bhc_landing_faq', "Do I need specific plugins?\nNo. Everything is shown with stock EQ and compression.\n\nHow long do I have access?\nForever. Buy once and it's yours.\n\nCan I watch on my phone?\nYes, and your place in each video is saved across devices.");
update_post_meta($course_id, '_bhc_purchase_price_cents', 4900);
$product_id = BHM_ProductSync::sync_object_purchase_product($course_id, 'bh_course', 4900);
if ($product_id) $tag($product_id);

$lesson = function (string $title, string $module, string $excerpt, array $steps) use ($course_id, $tag): int {
    $id = wp_insert_post(['post_type' => 'bh_lesson', 'post_status' => 'publish', 'post_title' => $title, 'post_excerpt' => $excerpt]);
    $tag($id);
    update_post_meta($id, '_bhc_course_id', $course_id);
    update_post_meta($id, '_bhc_module_title', $module);
    BHC_Steps::save($id, $steps);
    BHC_ContentBridge::migrate_lesson($id); // editor content matches, so opening the lesson keeps its steps
    return $id;
};

$l1 = $lesson('Welcome & session setup', 'Foundations', 'What you need, how the course works, and grabbing the session files.', [
    ['type' => 'video', 'source' => 'url', 'video_url' => $demo_video, 'caption' => 'Welcome to Low End Theory.',
        'chapters' => [['time' => 0, 'title' => 'Welcome'], ['time' => 60, 'title' => 'What you need'], ['time' => 240, 'title' => 'Session tour']],
        'annotations' => [['time' => 5, 'type' => 'banner', 'payload' => ['text' => 'Grab the session notes from Downloads before you start.', 'duration' => 8, 'size' => 'large']]]],
    ['type' => 'text', 'content' => '<p>Each lesson is a few short steps. Watch, read, try it in your own session, then hit <strong>Next</strong>. Your progress and your place in every video are saved.</p>'],
    ['type' => 'resource', 'attachment_id' => $notes_file, 'label' => 'Session notes (TXT)', 'description' => '<p>Frequencies and settings used throughout the course.</p>'],
]);
$l2 = $lesson('Kick and bass: who owns what', 'Foundations', 'Why they fight, and how to give each one its own space.', [
    ['type' => 'video', 'source' => 'url', 'video_url' => $demo_video, 'watch_threshold' => 80, 'caption' => 'Watch most of this one: the next step depends on it.',
        'annotations' => [
            ['time' => 20, 'type' => 'note', 'payload' => ['text' => 'Listen for the click of the kick separate from its boom.']],
            ['time' => 45, 'type' => 'question', 'payload' => ['question' => 'Where does the kick fundamental usually sit?', 'choices' => ['50-60 Hz', '500 Hz', '5 kHz'], 'correct_index' => 0]],
        ]],
    ['type' => 'callout', 'variant' => 'tip', 'content' => '<p>Solo the kick and bass together, not separately. The problem only exists when they play at once.</p>'],
    ['type' => 'quiz', 'passing_score' => 70, 'questions' => [
        ['question' => 'Kick and bass usually fight in which range?', 'choices' => ['Sub and low mids', 'High mids', 'Air'], 'correct_index' => 0],
        ['question' => 'A quick fix for masking is to...', 'choices' => ['Boost both', 'Carve a small cut in one where the other lives', 'Pan the bass hard left'], 'correct_index' => 1],
    ]],
]);
$l3 = $lesson('Designing the sub', 'Making the low end', 'Picking notes and tones that translate to small speakers.', [
    ['type' => 'text', 'content' => '<p>A sub that only lives below 50 Hz disappears on phones. Add a little harmonic content an octave up so small speakers can "imply" the note.</p>'],
    ['type' => 'resource', 'attachment_id' => $chart_file, 'label' => 'Sub note/frequency chart (TXT)', 'description' => ''],
    ['type' => 'checklist', 'title' => 'Try it', 'items' => ['Pick the root note of your track', 'Check it on earbuds', 'Add saturation until it reads on the phone speaker']],
]);
$l4 = $lesson('Mix checkpoint', 'Making the low end', 'Check your progress against a reference before finishing.', [
    ['type' => 'video', 'source' => 'url', 'video_url' => $demo_video, 'caption' => 'Referencing the low end.'],
    ['type' => 'quiz', 'passing_score' => 70, 'questions' => [
        ['question' => 'Everything under about 120 Hz should usually be...', 'choices' => ['Wide stereo', 'Mono', 'Muted'], 'correct_index' => 1],
    ]],
]);
$l5 = $lesson('Final project', 'Finishing', 'Put it together in a full mix and check it on three systems.', [
    ['type' => 'text', 'content' => '<p>Mix one full track using everything so far, then listen on headphones, a phone and a car. Note what changes.</p>'],
    ['type' => 'checklist', 'title' => 'Before you call it done', 'items' => ['Kick and bass both audible on a phone', 'No boom building up in the car', 'Low end mono-compatible']],
]);
update_post_meta($course_id, '_bhc_lesson_order', [$l1, $l2, $l3, $l4, $l5]);

// Sales page, Support page, launch coupon.
$page = function (string $title, string $slug, string $content) use ($tag): int {
    $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug, 'post_content' => $content]);
    $tag($id);
    return (int) $id;
};
$sales = $page('Low End Theory (Demo sales page)', 'low-end-theory-demo', '<!-- wp:bhc/course-landing {"id":' . $course_id . '} /-->');
$support = $page('Support (Demo)', 'support-demo',
    "<!-- wp:heading --><h2>Common questions</h2><!-- /wp:heading -->\n"
    . "<!-- wp:details --><details class=\"wp-block-details\"><summary>I bought a course. Where is it?</summary><!-- wp:paragraph --><p>Log in and open Account. Your courses are on the My Courses tab.</p><!-- /wp:paragraph --></details><!-- /wp:details -->\n"
    . "<!-- wp:details --><details class=\"wp-block-details\"><summary>I forgot my password.</summary><!-- wp:paragraph --><p>Use \"Lost your password?\" on the log-in page.</p><!-- /wp:paragraph --></details><!-- /wp:details -->\n"
    . "<!-- wp:shortcode -->[bht_support]<!-- /wp:shortcode -->");

$coupon = new WC_Coupon();
$coupon->set_code('lowend-launch-demo');
$coupon->set_discount_type('percent');
$coupon->set_amount(20);
$coupon->set_usage_limit(10);
$coupon->set_product_ids([$product_id]);
$coupon->set_description('Demo: 20% off for the first 10 buyers, applied automatically.');
$coupon->save();
$tag($coupon->get_id());
update_post_meta($coupon->get_id(), BHM_AutoCoupons::META, 'yes');
BHM_AutoCoupons::flush();

// Two students: one fresh (to walk buying), one already part-way through (gifted).
$student = function (string $login, string $name) {
    $pass = wp_generate_password(16, false);
    $id = wp_insert_user(['user_login' => $login, 'user_email' => $login . '@example.test', 'user_pass' => $pass, 'display_name' => $name, 'role' => 'bh_member']);
    if (is_wp_error($id)) return [0, ''];
    update_user_meta($id, '_bhc_demo_tag', BHC_DEMO_TAG);
    return [(int) $id, $pass];
};
[$fresh_id, $fresh_pass] = $student('demo-newcomer', 'Demo Newcomer');
[$learner_id, $learner_pass] = $student('demo-learner', 'Demo Learner');
$gift = BHM_GiftAccess::gift($course_id, 'demo-learner@example.test', 'Demo: gifted to show a student part-way through.', 1);
if (!is_wp_error($gift)) {
    BHC_Progress::mark_step_complete($learner_id, $l1, 0);
    BHC_Progress::mark_step_complete($learner_id, $l1, 1);
    BHC_Progress::mark_step_complete($learner_id, $l1, 2);
    BHC_Progress::save_video_position($learner_id, $l2, 0, 6);
    BHC_Notes::add($learner_id, $l1, 0, 4, 'Session notes are under Downloads.');
}

echo "Course #$course_id: " . get_permalink($course_id) . "\n";
echo "Sales page: " . get_permalink($sales) . "\nSupport page: " . get_permalink($support) . "\n";
echo "Coupon: lowend-launch-demo (20%, 10 uses, automatic)\n";
echo "Students (shown once):\n  demo-newcomer / $fresh_pass  (no access yet)\n  demo-learner / $learner_pass  (gifted, lesson 1 done)\n";
