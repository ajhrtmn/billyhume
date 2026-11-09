<?php
if (!defined('ABSPATH')) exit;

/**
 * The [bh_course] shortcode / single course detail view: header, description,
 * enrollment CTA, lesson list or locked syllabus preview, completion and orientation screens.
 * render_continue_cta() is public because BHC_Render_Catalog's cards need the same decision.
 */
class BHC_Render_Course {
    private static function render_course_header(int $course_id, int $uid, bool $locked): string {
        $difficulty_label = BHC_PostTypes::difficulty_label($course_id);
        $instructor = BHC_PostTypes::instructor($course_id);
        $lesson_count = BHC_PostTypes::lesson_count($course_id);
        $duration = BHC_PostTypes::duration_note($course_id);
        $categories = get_the_terms($course_id, 'bhc_course_category');
        $topics = get_the_terms($course_id, 'bhc_course_topic');

        $has_cover = has_post_thumbnail($course_id);

        ob_start();
        // Back link to the catalog, resolved via OUS_Pages; omitted when there's no catalog page.
        if (method_exists('OUS_Pages', 'url')) {
            $catalog = OUS_Pages::url('bh_courses', 'bhc_catalog_page_id');
            if ($catalog) {
                echo '<a class="ous-back-link bhc-back-to-catalog" href="' . esc_url($catalog) . '">'
                    . '<span aria-hidden="true">&larr;</span> '
                    . esc_html__('All courses', 'bh-courses') . '</a>';
            }
        }
        echo '<div class="bhc-course-header">';
        // Every course gets the hero; no cover means the catalog's gradient placeholder.
        echo '<div class="bhc-course-hero' . ($has_cover ? '' : ' bhc-course-hero-placeholder') . '">';
        if ($has_cover) echo get_the_post_thumbnail($course_id, 'large');
        echo '<div class="bhc-course-hero-scrim"><div class="bhc-course-hero-content">';
        echo '<h1 class="bhc-course-title">' . esc_html(get_the_title($course_id)) . '</h1>';
        if ($difficulty_label) echo '<span class="bh-badge bhc-badge bhc-badge-difficulty bhc-difficulty-' . esc_attr(BHC_PostTypes::difficulty($course_id)) . '">' . esc_html($difficulty_label) . '</span>';
        echo '</div></div>';
        echo '</div>';

        // Instructor row, only when there is one.
        if ($instructor) {
            $inst_name = $instructor->display_name ?: $instructor->user_login;
            echo '<div class="bhc-course-instructor-row">' . self::instructor_avatar($instructor, $inst_name)
                . '<div><span class="bhc-course-instructor-label">Taught by</span><span class="bhc-course-instructor-name">' . esc_html($inst_name) . '</span></div></div>';
        }

        echo '<div class="bhc-course-meta">';
        echo '<span>' . (int) $lesson_count . ' lesson' . ($lesson_count === 1 ? '' : 's') . '</span>';
        if ($duration) echo '<span>' . esc_html($duration) . '</span>';
        if (class_exists('BHC_Reviews') && BHC_Reviews::enabled_for($course_id)) {
            $rating = BHC_Reviews::average_rating($course_id);
            if ($rating['count'] > 0) {
                echo '<span class="bhc-course-rating">' . self::render_stars($rating['average']) . ' <span class="bhc-rating-number">' . esc_html((string) $rating['average']) . '</span> <span class="bhc-rating-count">(' . (int) $rating['count'] . ' review' . ($rating['count'] === 1 ? '' : 's') . ')</span></span>';
            }
        }
        echo '</div>';

        if (!empty($categories) && !is_wp_error($categories)) {
            echo '<div class="bhc-course-terms">';
            foreach ($categories as $t) echo '<span class="bhc-term bhc-term-category">' . esc_html($t->name) . '</span>';
            if (!empty($topics) && !is_wp_error($topics)) {
                foreach ($topics as $t) echo '<span class="bhc-term bhc-term-topic">' . esc_html($t->name) . '</span>';
            }
            echo '</div>';
        }

        $content = get_post_field('post_content', $course_id);
        if ($content) echo '<div class="bhc-course-description">' . apply_filters('the_content', $content) . '</div>';

        if ($uid && !$locked) {
            $percent = BHC_Progress::course_percent($uid, $course_id);
            $cta = self::render_continue_cta($uid, $course_id, $percent) . BHC_Downloads::render_link($course_id);
            if ($cta) echo '<div class="bhc-course-header-cta">' . $cta . '</div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    /**
     * Sets BH_SEO page data for a course.
     * WHY: render_course() runs during the_content, after wp_head, so bh-courses.php also
     * calls this from template_redirect for the course's own page.
     */
    public static function set_seo_data(int $course_id): void {
        if (!class_exists('BH_SEO')) return;
        $instructor = BHC_PostTypes::instructor($course_id);
        BH_SEO::set_page_data([
            'title' => get_the_title($course_id) . ' — ' . get_bloginfo('name'),
            'description' => wp_strip_all_tags(get_post_field('post_content', $course_id)) ?: (get_the_title($course_id) . ', a course on ' . get_bloginfo('name')),
            'url' => get_permalink($course_id),
            'image' => has_post_thumbnail($course_id) ? get_the_post_thumbnail_url($course_id, 'large') : null,
            'type' => 'website',
            'schema' => [
                '@context' => 'https://schema.org',
                '@type' => 'Course',
                'name' => get_the_title($course_id),
                'description' => wp_strip_all_tags(get_post_field('post_content', $course_id)) ?: null,
                'url' => get_permalink($course_id),
                'image' => has_post_thumbnail($course_id) ? get_the_post_thumbnail_url($course_id, 'large') : null,
                'provider' => [
                    '@type' => 'Organization',
                    'name' => get_bloginfo('name'),
                    'sameAs' => home_url(),
                ],
                'hasCourseInstance' => $instructor ? [
                    '@type' => 'CourseInstance',
                    'courseMode' => 'online',
                    'instructor' => [
                        '@type' => 'Person',
                        'name' => $instructor->display_name ?: $instructor->user_login,
                    ],
                ] : null,
            ],
        ]);
    }

    /** @param mixed $atts */
    public static function render_course($atts): string {
        $course_id = (int) ($atts['id'] ?? get_the_ID());
        if (!$course_id || get_post_type($course_id) !== 'bh_course') return '';

        self::set_seo_data($course_id);

        $uid = get_current_user_id();
        // Login and tier are separate checks, so the locked view shows the right message.
        // Tier checks pass vacuously when no tier is set.
        $requires_login = !$uid && !OUS_Visibility::is_public($course_id);
        $locked = $requires_login || !BHC_Gate::user_can_access_course($uid, $course_id);
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);

        // Start the drip clock once access is confirmed. $just_enrolled is true only on
        // the first-ever enrollment, for the one-time orientation screen.
        $just_enrolled = false;
        if ($uid && !$locked) $just_enrolled = BHC_Progress::enroll_if_needed($uid, $course_id);

        if (!$locked && BHC_Downloads::is_requested()) return BHC_Downloads::render($course_id, $uid);

        ob_start();
        echo '<div class="bhc-course-view">';
        // Detail-page header wraps the lesson-list output below.
        echo self::render_course_header($course_id, $uid, $locked);
        if ($locked) {
            if ($requires_login) {
                echo OUS_Visibility::render_login_notice(__('Log in to view this course.', 'bh-courses'));
                // Say what it takes to get in, not just "log in", when the course is paid or tiered.
                if (BHC_Gate::required_tier($course_id) || BHC_Gate::has_purchase_option($course_id)) echo BHC_Gate::render_paywall_notice($course_id);
            } else {
                echo BHC_Gate::render_paywall_notice($course_id);
            }
            // Locked: a syllabus of lesson titles only (no content or permalinks).
            if ($lesson_ids) {
                echo '<div class="bhc-syllabus-preview"><h4>What you\'ll learn</h4><ol class="bhc-lesson-list bhc-lesson-list-preview">';
                foreach ($lesson_ids as $lesson_id) {
                    if (get_post_status($lesson_id) !== 'publish') continue;
                    echo '<li><span>' . esc_html(get_the_title($lesson_id)) . '</span>' . self::lesson_description($lesson_id) . '</li>';
                }
                echo '</ol></div>';
            }
        } else {
            // First-ever enrollment shows the orientation screen instead of the usual view.
            if ($just_enrolled) echo self::render_orientation_screen($uid, $course_id, $lesson_ids);

            // Completion screen sits above the progress bar once the course is done.
            if ($uid) echo self::render_completion_screen($uid, $course_id);

            // Skipped on the orientation screen, which already shows the syllabus and a Start CTA.
            if (!$just_enrolled) {
                if ($uid) {
                    $percent = BHC_Progress::course_percent($uid, $course_id);
                    echo '<div class="bhc-progress-bar bhc-progress-bar-large"><div class="bhc-progress-fill" style="width:' . (int) $percent . '%"></div></div><p class="bhc-progress-label"><span class="bhc-progress-pct">' . (int) $percent . '%</span> complete</p>';
                    // Only shown once a quiz has been scored (null until then).
                    $quiz_average = BHC_Progress::course_quiz_average($uid, $course_id);
                    if ($quiz_average !== null) {
                        echo '<p class="bhc-mastery-label">Mastery: ' . (int) $quiz_average . '%</p>';
                    }
                }
                echo self::render_grouped_lesson_list($course_id, $uid, null, false);
            }
        }
        if (!$locked && class_exists('BHC_Leaderboard')) echo BHC_Leaderboard::render($course_id);
        if (!$locked && class_exists('BHC_Reviews') && BHC_Reviews::enabled_for($course_id)) echo self::render_reviews_section($course_id, $uid);
        if (!$locked) echo BHC_Feedback::render_form($course_id, $uid);
        $credits = (string) get_post_meta($course_id, '_bhc_credits', true);
        if (trim(wp_strip_all_tags($credits)) !== '') {
            echo '<section class="bhc-course-credits"><h2>Credits</h2>' . wpautop(wp_kses_post($credits)) . '</section>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    // Whole stars rounded to the nearest; no half-star glyph.
    private static function render_stars(float $rating): string {
        $full = (int) round($rating);
        $full = max(0, min(5, $full));
        return '<span class="bhc-stars" aria-hidden="true">' . str_repeat('&#9733;', $full) . str_repeat('&#9734;', 5 - $full) . '</span>';
    }

    // Approved reviews plus the submission/edit form for enrolled students.
    // The caller already hides this when locked.
    private static function render_reviews_section(int $course_id, int $uid): string {
        ob_start();
        echo '<div class="bhc-reviews-section" id="bhc-reviews">';
        echo '<h2>Reviews</h2>';

        $reviews = BHC_Reviews::reviews_for_course($course_id, 'approved');
        if (!$reviews) {
            echo '<p class="description">No reviews yet.</p>';
        } else {
            echo '<div class="bhc-review-list">';
            foreach ($reviews as $review) {
                $reviewer = get_userdata((int) $review['user_id']);
                $name = $reviewer ? ($reviewer->display_name ?: $reviewer->user_login) : 'A student';
                echo '<div class="bhc-review">';
                echo '<div class="bhc-review-head">' . self::render_stars((int) $review['rating']) . ' <strong>' . esc_html($name) . '</strong>';
                echo $review['completed_at_review']
                    ? ' <span class="bh-badge bhc-review-badge bhc-review-badge-completed">Completed the course</span>'
                    : ' <span class="bh-badge bhc-review-badge bhc-review-badge-enrolled">Enrolled</span>';
                echo '</div>';
                if ($review['body']) echo '<p class="bhc-review-body">' . esc_html($review['body']) . '</p>';
                echo '<p class="bhc-review-date">' . esc_html(date_i18n(get_option('date_format'), strtotime($review['created_at']))) . '</p>';
                echo '</div>';
            }
            echo '</div>';
        }

        if ($uid && BHC_Progress::enrolled_at($uid, $course_id)) {
            $mine = BHC_Reviews::user_review($uid, $course_id);
            echo '<div class="bhc-review-form-wrap">';
            if ($mine) {
                if ($mine['status'] === 'pending') {
                    echo '<p class="bhc-review-status-note">Your review is awaiting approval.</p>';
                } elseif ($mine['status'] === 'rejected') {
                    echo '<p class="bhc-review-status-note bhc-review-status-rejected">Your review wasn\'t approved. You can edit and resubmit it below.</p>';
                } else {
                    echo '<p class="bhc-review-status-note">Your review is live below — thanks for sharing it! You can still edit it any time.</p>';
                }
            }
            echo '<form class="bhc-review-form" data-course-id="' . (int) $course_id . '">';
            echo '<fieldset><legend>' . ($mine ? 'Edit your review' : 'Leave a review') . '</legend>';
            echo '<div class="bhc-review-star-picker" role="radiogroup" aria-label="Rating">';
            for ($i = 5; $i >= 1; $i--) {
                $checked = $mine && (int) $mine['rating'] === $i;
                echo '<label class="bhc-review-star-choice"><input type="radio" name="rating" value="' . $i . '"' . ($checked ? ' checked' : '') . ' required> <span aria-hidden="true">&#9733;</span><span class="screen-reader-text">' . $i . ' star' . ($i === 1 ? '' : 's') . '</span></label>';
            }
            echo '</div>';
            echo '<textarea class="bhc-review-textarea" placeholder="What did you think? (optional)" rows="3">' . esc_textarea($mine['body'] ?? '') . '</textarea>';
            echo '<button type="submit" class="bhc-btn bhc-btn-secondary bhc-review-submit">' . ($mine ? 'Update review' : 'Submit review') . '</button>';
            echo '<div class="bhc-review-form-result" role="status" aria-live="polite"></div>';
            echo '</fieldset></form>';
            echo '</div>';
        }

        echo '</div>';
        return ob_get_clean();
    }

    // Lesson list + progress, shared by the course page and the lesson view.
    // $current_lesson_id highlights the open lesson; null on the course page.
    public static function render_lesson_sidebar(int $course_id, int $uid, ?int $current_lesson_id = null): string {
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);
        if (!$lesson_ids) return '';
        $percent = $uid ? BHC_Progress::course_percent($uid, $course_id) : 0;

        ob_start();
        echo '<nav class="bhc-course-sidebar" aria-label="Course lessons">';
        echo '<a class="bhc-sidebar-course-link" href="' . esc_url(get_permalink($course_id)) . '">' . esc_html(get_the_title($course_id)) . '</a>';
        if ($uid) {
            echo '<div class="bhc-progress-bar"><div class="bhc-progress-fill" style="width:' . (int) $percent . '%"></div></div><p class="bhc-progress-label"><span class="bhc-progress-pct">' . (int) $percent . '%</span> complete</p>';
        }
        echo self::render_grouped_lesson_list($course_id, $uid, $current_lesson_id, true);
        echo BHC_Downloads::render_link($course_id, 'bhc-sidebar-downloads');
        echo '</nav>';
        return ob_get_clean();
    }

    /**
     * The real profile avatar when the instructor set one, otherwise a monogram
     * (never WordPress's grey mystery-person).
     */
    private static function instructor_avatar(\WP_User $user, string $name): string {
        $has_real = class_exists('BHI_Profiles') && (int) (BHI_Profiles::get($user->ID)['avatar_id'] ?? 0) > 0;
        if ($has_real) {
            return get_avatar($user->ID, 48, '', $name, ['class' => 'bhc-course-instructor-avatar']);
        }
        $initial = function_exists('mb_substr') ? mb_substr(trim($name), 0, 1) : substr(trim($name), 0, 1);
        return '<span class="bhc-course-instructor-avatar bhc-instructor-monogram" aria-hidden="true">' . esc_html(mb_strtoupper($initial)) . '</span>';
    }

    // Hand-written excerpts only; WordPress's auto-excerpt would be step text.
    private static function lesson_description(int $lesson_id): string {
        if (!has_excerpt($lesson_id)) return '';
        return '<p class="bhc-lesson-desc">' . esc_html(get_the_excerpt($lesson_id)) . '</p>';
    }

    /** @return array{html:string, complete:bool, is_current:bool} */
    private static function render_lesson_li(int $lesson_id, int $uid, ?int $current_lesson_id, bool $is_sidebar): array {
        $open = BHC_Gate::lesson_is_open($uid, $lesson_id);
        $step_count = BHC_Steps::count($lesson_id);
        $done_count = $uid ? count(BHC_Progress::completed_steps($uid, $lesson_id)) : 0;
        $complete = $step_count > 0 && $done_count >= $step_count;
        $is_current = $is_sidebar && $current_lesson_id && (int) $lesson_id === (int) $current_lesson_id;
        $classes = trim(($complete ? 'bhc-lesson-done' : '') . (!$open ? ' bhc-lesson-locked' : '') . ($is_current ? ' bhc-lesson-current' : ''));

        $html = '<li class="' . $classes . '">';
        if ($open && $step_count === 0) {
            // Nothing to open yet: no dead-end link.
            $html .= '<span>' . esc_html(get_the_title($lesson_id)) . '</span> <span class="bhc-drip-notice">Coming soon</span>';
        } elseif ($open) {
            $html .= '<a href="' . esc_url(get_permalink($lesson_id)) . '"' . ($is_current ? ' aria-current="page"' : '') . '>' . esc_html(get_the_title($lesson_id)) . '</a>';
        } else {
            $html .= '<span>' . esc_html(get_the_title($lesson_id)) . '</span> <span class="bhc-drip-notice">&#128274;'
                . ($is_sidebar ? '' : ' ' . BHC_Gate::drip_notice($uid, $lesson_id)) . '</span>';
        }
        // Step ratio for in-progress lessons; finished lessons show the check instead.
        if (!$is_sidebar && $open && $uid && $step_count && !$complete) {
            $html .= ' <span class="bhc-lesson-progress"><b>' . (int) $done_count . '</b>/' . (int) $step_count . '</span>';
        }
        if ($complete) $html .= ' <span class="bhc-check" aria-label="Lesson complete">&#10003;</span>';
        if (!$is_sidebar) $html .= self::lesson_description($lesson_id);
        $html .= '</li>';
        return ['html' => $html, 'complete' => $complete, 'is_current' => $is_current];
    }

    // Module groups render as <details>, open unless complete and not holding the current
    // lesson. Untitled groups render bare <li>s.
    private static function render_grouped_lesson_list(int $course_id, int $uid, ?int $current_lesson_id, bool $is_sidebar): string {
        $list_class = $is_sidebar ? 'bhc-lesson-list bhc-sidebar-lesson-list' : 'bhc-lesson-list';
        $out = '<ol class="' . $list_class . '">';
        foreach (BHC_PostTypes::grouped_lesson_order($course_id) as $group) {
            $items = '';
            $group_complete = true;
            $group_has_current = false;
            foreach ($group['lesson_ids'] as $lesson_id) {
                if (get_post_status($lesson_id) !== 'publish') continue;
                $li = self::render_lesson_li($lesson_id, $uid, $current_lesson_id, $is_sidebar);
                $items .= $li['html'];
                if (!$li['complete']) $group_complete = false;
                if ($li['is_current']) $group_has_current = true;
            }
            if ($items === '') continue;
            if ($group['title'] === '') {
                $out .= $items;
            } else {
                $open_attr = (!$group_complete || $group_has_current) ? ' open' : '';
                $out .= '<li class="bhc-module-group"><details' . $open_attr . '><summary class="bhc-module-title">' . esc_html($group['title']) . '</summary><ol class="bhc-lesson-list bhc-module-lesson-list">' . $items . '</ol></details></li>';
            }
        }
        $out .= '</ol>';
        return $out;
    }

    // "Where should this student go next", shared with the catalog card.
    public static function render_continue_cta(int $uid, int $course_id, int $percent): string {
        $target_lesson = BHC_Progress::first_incomplete_lesson($uid, $course_id);
        if ($target_lesson) {
            $label = $percent > 0 ? 'Continue' : 'Start';
            return '<a class="bhc-btn bhc-continue-btn" href="' . esc_url(get_permalink($target_lesson)) . '">' . esc_html($label) . ' &rarr;</a>';
        }
        // Nothing to resume: only offer "Review" when actually complete.
        if ($percent >= 100) {
            $lesson_ids = BHC_PostTypes::lesson_order($course_id);
            if ($lesson_ids) {
                return '<a class="bhc-btn bhc-btn-secondary bhc-continue-btn" href="' . esc_url(get_permalink($lesson_ids[0])) . '">Review course</a>';
            }
        }
        return '';
    }

    /**
     * Completion screen (stats, certificate, share card, next steps); '' unless completed.
     *
     * $assume_complete: for the lesson page's final-step reveal, which is rendered at page
     * load before the completing AJAX write, so is_course_completed() would still be false.
     */
    public static function render_completion_screen(int $uid, int $course_id, bool $assume_complete = false): string {
        if (!$uid || !class_exists('BHC_Progress')) return '';
        if (!$assume_complete && !BHC_Progress::is_course_completed($uid, $course_id)) return '';

        $lesson_count = BHC_PostTypes::lesson_count($course_id);
        $quizzes_passed = BHC_Progress::quizzes_passed_count($uid, $course_id);
        $quiz_average = BHC_Progress::course_quiz_average($uid, $course_id);
        $enrolled_at = BHC_Progress::enrolled_at($uid, $course_id);
        $completed_at = BHC_Progress::course_completed_at($uid, $course_id);
        $has_certificate = class_exists('BHC_Certificates') && BHC_Certificates::course_offers_certificate($course_id);
        $has_share_card = class_exists('BHC_ShareCards');
        $distinction_threshold = (int) apply_filters('bhc_certificate_distinction_threshold', 90);
        $with_distinction = $quiz_average !== null && $quiz_average >= $distinction_threshold;

        ob_start();
        // WHY: '.bhc-completion' is the selector courses.js fires confetti on.
        echo '<div class="bhc-completion">';
        echo '<div class="bhc-completion-badge" aria-hidden="true">&#127942;</div>';
        echo '<h2 class="bhc-completion-title">Course complete' . ($with_distinction ? ' — with distinction' : '') . '!</h2>';
        echo '<p class="bhc-completion-sub">You finished <strong>' . esc_html(get_the_title($course_id)) . '</strong>.</p>';

        echo '<div class="bhc-completion-stats">';
        echo '<div class="bhc-completion-stat"><span class="bhc-completion-stat-num">' . (int) $lesson_count . '</span><span class="bhc-completion-stat-label">lesson' . ($lesson_count === 1 ? '' : 's') . ' completed</span></div>';
        if ($quizzes_passed > 0) {
            echo '<div class="bhc-completion-stat"><span class="bhc-completion-stat-num">' . (int) $quizzes_passed . '</span><span class="bhc-completion-stat-label">quiz' . ($quizzes_passed === 1 ? '' : 'zes') . ' passed</span></div>';
        }
        if ($enrolled_at && $completed_at) {
            $days = max(1, (int) round((strtotime($completed_at) - strtotime($enrolled_at)) / DAY_IN_SECONDS));
            echo '<div class="bhc-completion-stat"><span class="bhc-completion-stat-num">' . $days . '</span><span class="bhc-completion-stat-label">day' . ($days === 1 ? '' : 's') . ' start to finish</span></div>';
        }
        echo '</div>';

        if ($has_certificate || $has_share_card) {
            echo '<div class="bhc-completion-actions">';
            if ($has_certificate) {
                echo '<a class="bhc-btn bhc-btn-primary" href="' . esc_url(BHC_Certificates::download_url($course_id)) . '">Download certificate' . ($with_distinction ? ' (with distinction)' : '') . '</a>';
            }
            if ($has_share_card) {
                // download attribute saves the PNG directly.
                echo '<a class="bhc-btn bhc-btn-secondary" href="' . esc_url(BHC_ShareCards::card_url($uid, $course_id)) . '" target="_blank" rel="noopener" download>Get share image</a>';
            }
            echo '</div>';
        }
        // Show the share card inline.
        if ($has_share_card) {
            echo '<img class="bhc-completion-share-preview" src="' . esc_url(BHC_ShareCards::card_url($uid, $course_id)) . '" alt="' . esc_attr(get_the_title($course_id)) . ' completion share card" loading="lazy">';
        }

        echo '<div class="bhc-completion-next-steps">';
        $archive_url = get_post_type_archive_link('bh_course');
        if ($archive_url) echo '<a href="' . esc_url($archive_url) . '">Browse more courses</a>';
        echo (class_exists('BHC_Reviews') && BHC_Reviews::enabled_for($course_id))
            ? ' &middot; <a href="#bhc-reviews">Leave a review</a>'
            : ' &middot; <a href="' . esc_url((string) get_permalink($course_id)) . '#bhc-feedback">Send feedback</a>';
        echo '</div>';
        echo '</div>';
        return ob_get_clean();
    }

    /**
     * One-time orientation on first enrollment, showing the real syllabus.
     */
    /** @param array<int, int> $lesson_ids */
    private static function render_orientation_screen(int $uid, int $course_id, array $lesson_ids): string {
        ob_start();
        echo '<div class="bhc-orientation">';
        echo '<p class="bhc-orientation-eyebrow">You\'re in</p>';
        echo '<h2 class="bhc-orientation-title">Here\'s what\'s ahead</h2>';
        $content = get_post_field('post_content', $course_id);
        if ($content) {
            echo '<div class="bhc-orientation-description">' . wp_kses_post(get_the_excerpt($course_id) ?: wp_trim_words(wp_strip_all_tags($content), 40)) . '</div>';
        }
        if ($lesson_ids) {
            echo '<ol class="bhc-lesson-list bhc-orientation-syllabus">';
            foreach ($lesson_ids as $lesson_id) {
                if (get_post_status($lesson_id) !== 'publish') continue;
                echo '<li><span>' . esc_html(get_the_title($lesson_id)) . '</span></li>';
            }
            echo '</ol>';
        }
        // first_incomplete_lesson() so "Start" never links to a drip-locked lesson.
        $start_lesson = BHC_Progress::first_incomplete_lesson($uid, $course_id);
        if ($start_lesson) {
            echo '<a class="bhc-btn bhc-orientation-start" href="' . esc_url(get_permalink($start_lesson)) . '">Start &rarr;</a>';
        }
        echo '</div>';
        return ob_get_clean();
    }
}
