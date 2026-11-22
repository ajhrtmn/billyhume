<?php
/**
 * EVERY WORD STUDENTS SEE IN COURSES: button labels, headings and messages.
 *
 * To change wording, edit the text on the right of the arrow and save. Keep the
 * key on the left exactly as it is, and keep the quote marks around the text.
 * Write an apostrophe as \' (for example 'You\'re in').
 *
 * Words in {curly braces} get filled in for you, like {count} or {price}.
 * Keep them in your text (you can move them around), and don't translate them.
 *
 * Some messages come in pairs, one for "1" and one for more ("1 lesson" / "3 lessons").
 */
if (!defined('ABSPATH')) exit;

return [
    /* ---------- Course page ---------- */
    'course.all_courses'        => 'All courses',
    'course.taught_by'          => 'Taught by',
    'course.lesson_count_one'   => '{count} lesson',
    'course.lesson_count'       => '{count} lessons',
    'course.login_required'     => 'Log in to view this course.',
    'course.syllabus_heading'   => 'What you\'ll learn',
    'course.credits_heading'    => 'Credits',
    'course.start'              => 'Start →',
    'course.continue'           => 'Continue →',
    'course.review'             => 'Review course',
    'progress.complete'         => 'complete',
    'progress.mastery'          => 'Mastery: {pct}%',

    /* First visit after joining a course */
    'orientation.eyebrow'       => 'You\'re in',
    'orientation.title'         => 'Here\'s what\'s ahead',

    /* ---------- Lesson page ---------- */
    'lesson.login_required'     => 'Log in to view this lesson.',
    'lesson.coming_soon'        => 'This lesson is coming soon.',
    'lesson.coming_soon_short'  => 'Coming soon',
    'lesson.position'           => 'Lesson {n} of {total}',
    'lesson.preview_note'       => 'You\'re previewing this lesson. {link} to save your progress and notes.',
    'lesson.preview_link'       => 'Log in or create a free account',
    'lesson.step_counter'       => 'Step {n} of {total}',
    'lesson.complete_title'     => 'Lesson complete',
    'lesson.complete_steps'     => '{total} of {total} steps',
    'lesson.complete_quiz'      => '{count} quiz passed',
    'lesson.complete_quizzes'   => '{count} quizzes passed',
    'lesson.back_to_lesson'     => '← Back to lesson',
    'lesson.next_lesson'        => 'Next Lesson →',

    /* Buttons under each step */
    'step.next'                 => 'Next →',
    'step.back'                 => '← Back',
    'step.completed'            => 'Completed',
    'step.retrying'             => 'Retrying…',
    'step.complete_toast'       => 'Step complete.',

    /* Lessons that open later ("drip") */
    'drip.opens_on'             => 'This lesson opens on {date}.',
    'drip.opens_in'             => 'This lesson opens {time} from now.',
    'drip.not_yet'              => 'This lesson isn\'t available yet.',

    /* ---------- Videos ---------- */
    'video.watched'             => '{pct} watched',
    'video.completes_at'        => 'completes at {threshold}%',
    'video.resuming'            => 'Picking up at {time}.',
    'video.chapters'            => 'Chapters',
    'video.chapter'             => 'Chapter {n}',
    'video.overlay_continue'    => 'Continue',
    'video.overlay_correct'     => 'Correct — continue',
    'video.load_failed'         => 'This video couldn’t load. Check your connection and refresh the page.',
    'video.load_failed_link'    => 'If it keeps happening, let us know.',
    'video.file_missing'        => 'Video file not found.',
    'video.needs_r2_setup'      => 'This video needs signed-delivery setup finished in Media & CDN Setup.',
    'video.needs_bunny_setup'   => 'This video needs Bunny Stream setup finished in Media & CDN Setup.',
    'video.cloudflare_id_missing' => 'Cloudflare Stream video UID missing.',

    /* ---------- Quizzes ---------- */
    'quiz.submit'               => 'Submit answers',
    'quiz.submitting'           => 'Submitting…',
    'quiz.already_passed'       => '✓ You already passed this quiz.',
    'quiz.attempts_left'        => '{left} of {max} attempts remaining.',
    'quiz.no_attempts_left'     => 'No attempts remaining ({max} allowed).',
    'quiz.no_attempts_short'    => 'No attempts remaining.',
    'quiz.passed_label'         => 'quiz passed',
    'quiz.passed_sub'           => 'Nice work.',
    'quiz.passed_score'         => '{correct}/{total} correct. Nice work.',
    'quiz.failed_retry'         => '{correct}/{total} correct — not quite there yet.{attempts} Take another look and give it another shot.',
    'quiz.retry_left_one'       => '({count} attempt remaining)',
    'quiz.retry_left'           => '({count} attempts remaining)',
    'quiz.retry_none'           => '(no attempts remaining)',
    'quiz.failed_final'         => 'Not this time — {correct}/{total} correct, and out of attempts. Review the lesson before moving on.',
    'quiz.failed_final_topics'  => 'Not this time — {correct}/{total} correct, and out of attempts. Before moving on, go back and review: {topics}.',
    'quiz.correct_answer'       => '✓ Correct answer',
    'quiz.your_answer'          => '✗ Your answer',
    'quiz.continue'             => 'Continue',
    'quiz.try_again'            => 'Try again',
    'quiz.offline'              => 'Could not reach the server — check your connection and submit again.',
    'quiz.out_of_tries_help'    => 'Out of tries for this quiz. You can keep going with the course, and if you\'d like another go, ask us.',
    'quiz.ask_for_help'         => 'Ask for help',

    /* Other step types */
    'audio_compare.prompt'      => 'Play each and compare:',
    'audio_compare.missing'     => 'One or both audio files not found.',

    /* ---------- Finishing a course ---------- */
    'completion.title'          => 'Course complete!',
    'completion.title_distinction' => 'Course complete — with distinction!',
    'completion.sub'            => 'You finished {course}.',
    'completion.lessons_one'    => 'lesson completed',
    'completion.lessons'        => 'lessons completed',
    'completion.quizzes_one'    => 'quiz passed',
    'completion.quizzes'        => 'quizzes passed',
    'completion.days_one'       => 'day start to finish',
    'completion.days'           => 'days start to finish',
    'completion.certificate'    => 'Download certificate',
    'completion.certificate_distinction' => 'Download certificate (with distinction)',
    'completion.share_image'    => 'Get share image',
    'completion.browse_more'    => 'Browse more courses',
    'completion.leave_review'   => 'Leave a review',
    'completion.send_feedback'  => 'Send feedback',

    /* ---------- Buying / locked courses ---------- */
    'paywall.buy_title'         => 'Get this course for {price} — buy once, yours to keep.',
    'paywall.buy_title_with_tier' => 'Or buy this course outright for {price} — yours to keep, no supporter tier required.',
    'paywall.buy_button'        => 'Buy once — {price}',
    'paywall.supporters_only'   => 'This content requires supporter access.',

    /* ---------- Sales page ---------- */
    'landing.about'             => 'About the course',
    'landing.contents'          => 'What’s inside',
    'landing.testimonials'      => 'What students say',
    'landing.faq'               => 'Questions',
    'landing.buy'               => 'Get the course',
    'landing.buy_with_price'    => 'Get the course — {price}',
    'landing.offer_one_left'    => 'Launch price for the next {count} buyer.',
    'landing.offer_left'        => 'Launch price for the next {count} buyers.',
    'landing.offer'             => 'Launch price, applied at checkout.',
    'landing.start_free'        => 'Start free →',
    'landing.view_course'       => 'View the course →',
    'landing.go_to_course'      => 'Go to the course →',
    'landing.login_free'        => 'Already started? {link} to pick up where you left off.',
    'landing.login_paid'        => 'Already bought it? {link} to pick up where you left off.',
    'landing.login_link'        => 'Log in',
    'landing.terms'             => 'Terms of sale',
    'landing.privacy'           => 'Privacy',
    'landing.contact'           => 'Questions? Contact us',

    /* ---------- Course catalog ---------- */
    'catalog.kicker'            => 'Course Catalog',
    'catalog.title'             => 'Courses',
    'catalog.search'            => 'Search courses…',
    'catalog.all_categories'    => 'All categories',
    'catalog.all_topics'        => 'All topics',
    'catalog.sort_newest'       => 'Newest',
    'catalog.sort_alpha'        => 'A–Z',
    'catalog.sort_popular'      => 'Most popular',
    'catalog.sort_rated'        => 'Highest rated',
    'catalog.filter'            => 'Filter',
    'catalog.empty_title'       => 'No courses published yet',
    'catalog.empty_text'        => 'Check back soon — new courses will show up here as they\'re published.',
    'catalog.empty_admin_cta'   => 'Create your first course',
    'catalog.no_matches'        => 'No courses match your filters',
    'catalog.previous'          => '← Previous',
    'catalog.next'              => 'Next →',
    'catalog.page_of'           => 'Page {n} of {total}',

    /* ---------- "My Courses" in the account area ---------- */
    'portal.tab'                => 'My Courses',
    'portal.heading'            => 'My Courses',
    'portal.achievements'       => 'Achievements',
    'portal.none'               => 'You\'re not enrolled in any courses yet.',
    'portal.browse'             => 'Browse courses →',
    'portal.done'               => 'done',
    'portal.complete'           => '✓ complete',
    'portal.lapsed'             => 'Access has lapsed — {link}',
    'portal.lapsed_link'        => 'view options',
    'portal.view_course'        => 'View course →',
    'portal.unavailable'        => 'Course progress is unavailable right now.',

    /* ---------- Downloads ---------- */
    'downloads.link'            => 'Downloads',
    'downloads.heading'         => 'Downloads',
    'downloads.course_files'    => 'Course files',
    'downloads.download'        => 'Download',
    'downloads.download_named'  => 'Download {name}',
    'downloads.locked'          => 'Locked',
    'downloads.zip'             => 'Download all (.zip)',
    'downloads.none'            => 'This course has no downloads yet.',
    'downloads.all_for_course'  => 'All downloads for this course →',
    'downloads.not_yet'         => 'This file is not available yet.',
    'downloads.file_missing'    => 'This file is missing. Please let us know through Support.',
    'downloads.zip_too_big'     => 'Please download these files one at a time.',
    'downloads.zip_failed'      => 'Could not build the zip. Please download the files one at a time.',

    /* ---------- Notes ---------- */
    'notes.heading'             => 'My notes',
    'notes.private'             => 'Only you can see these.',
    'notes.bookmark_hint'       => 'Bookmarked notes jump back to that moment in the video.',
    'notes.new'                 => 'New note',
    'notes.placeholder'         => 'Write a note…',
    'notes.bookmark'            => 'Bookmark the current video time',
    'notes.save'                => 'Save note',
    'notes.delete'              => 'Delete note',
    'notes.delete_confirm'      => 'Delete this note?',
    'notes.empty'               => 'Write something first.',
    'notes.full'                => 'This lesson has the maximum number of notes. Delete some first.',
    'notes.save_failed'         => 'Could not save the note.',
    'notes.save_offline'        => 'Could not save the note. Check your connection.',
    'notes.delete_failed'       => 'Could not delete the note.',
    'notes.delete_offline'      => 'Could not delete the note. Check your connection.',
    'notes.login_required'      => 'Log in required.',
    'notes.access_required'     => 'Access required.',
    'notes.not_found'           => 'Note not found.',

    /* ---------- Reviews ---------- */
    'reviews.heading'           => 'Reviews',
    'reviews.none'              => 'No reviews yet.',
    'reviews.count_one'         => '{count} review',
    'reviews.count'             => '{count} reviews',
    'reviews.anonymous'         => 'A student',
    'reviews.badge_completed'   => 'Completed the course',
    'reviews.badge_enrolled'    => 'Enrolled',
    'reviews.leave_heading'     => 'Leave a review',
    'reviews.edit_heading'      => 'Edit your review',
    'reviews.stars_one'         => '{count} star',
    'reviews.stars'             => '{count} stars',
    'reviews.placeholder'       => 'What did you think? (optional)',
    'reviews.submit'            => 'Submit review',
    'reviews.update'            => 'Update review',
    'reviews.submitting'        => 'Submitting…',
    'reviews.choose_rating'     => 'Choose a star rating first.',
    'reviews.thanks'            => 'Thanks for your review!',
    'reviews.submitted'         => 'Review submitted.',
    'reviews.failed'            => 'Could not submit your review.',
    'reviews.pending'           => 'Your review is awaiting approval.',
    'reviews.rejected'          => 'Your review wasn\'t approved. You can edit and resubmit it below.',
    'reviews.live'              => 'Your review is live below — thanks for sharing it! You can still edit it any time.',

    /* ---------- Private feedback form ---------- */
    'feedback.heading'          => 'Send feedback',
    'feedback.intro'            => 'Tell us what\'s working and what isn\'t. Only the course team sees this.',
    'feedback.label'            => 'Your feedback',
    'feedback.send'             => 'Send feedback',
    'feedback.sent'             => 'Thanks, your feedback was sent.',
    'feedback.failed'           => 'That didn\'t send. Please wait a minute and try again.',

    /* ---------- One-to-one sessions (account area) ---------- */
    'sessions.upcoming'         => 'Your upcoming sessions',
    'sessions.none_booked'      => 'Nothing booked yet.',
    'sessions.book_heading'     => 'Book a session',
    'sessions.no_slots'         => 'No open slots right now.',
    'sessions.start'            => 'Start',
    'sessions.duration'         => 'Duration',
    'sessions.minutes'          => 'min',
    'sessions.book'             => 'Book',
    'sessions.cancel'           => 'Cancel',
    'sessions.cancel_confirm'   => 'Cancel this session?',
    'sessions.too_late_to_cancel' => 'Too close to start to self-cancel — contact your instructor.',
    'sessions.booked'           => 'Session booked!',
    'sessions.taken'            => 'That slot was just taken — pick another.',
    'sessions.cancelled'        => 'Session cancelled.',
    'sessions.cancel_failed'    => 'Could not cancel that session.',

    /* ---------- Problems ---------- */
    'error.generic'             => 'Something went wrong.',
    'error.offline'             => 'Could not reach the server — check your connection and try again.',
    // {action} is one of the two phrases below.
    'session.expired'           => 'This page has been open a while, so it needs a refresh. Reload the page (everything up to now is saved) and {action} again.',
    'session.logged_out'        => 'Log in to {action}. Your account keeps your place across devices.',
    'session.action_progress'   => 'save your progress',
    'session.action_quiz'       => 'submit this quiz',
];
