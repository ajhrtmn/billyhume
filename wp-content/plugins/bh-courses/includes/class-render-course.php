<?php
if (!defined('ABSPATH')) exit;

/**
 * Extracted from the old monolithic class-render.php (SRP QA pass,
 * bh-courses 0.4.8) — see class-render-catalog.php's own docblock for
 * the full "why three classes" reasoning; this is a pure move, not a
 * rewrite. This class owns exactly one page: the [bh_course] shortcode /
 * single course detail view (cover, header, description, terms,
 * enrollment CTA, lesson list or locked syllabus preview).
 *
 * render_continue_cta() is PUBLIC here (it used to be a private helper
 * shared within one file) because BHC_Render_Catalog's own course-card
 * rendering also needs the exact same "where should this student go
 * next" decision — both call sites need the identical logic, so it lives
 * on whichever of the two classes is the more natural owner (the course
 * page itself) and the catalog calls across to it, rather than either
 * duplicating the logic or inventing a third shared-helper class for one
 * method.
 */
class BHC_Render_Course {
    private static function render_course_header($course_id, $uid, $locked) {
        $difficulty_label = BHC_PostTypes::difficulty_label($course_id);
        $instructor = BHC_PostTypes::instructor($course_id);
        $lesson_count = BHC_PostTypes::lesson_count($course_id);
        $duration = BHC_PostTypes::duration_note($course_id);
        $categories = get_the_terms($course_id, 'bhc_course_category');
        $topics = get_the_terms($course_id, 'bhc_course_topic');

        ob_start();
        echo '<div class="bhc-course-header">';
        if (has_post_thumbnail($course_id)) echo '<div class="bhc-course-cover">' . get_the_post_thumbnail($course_id, 'large') . '</div>';
        echo '<h1 class="bhc-course-title">' . esc_html(get_the_title($course_id)) . '</h1>';

        echo '<div class="bhc-course-meta">';
        if ($difficulty_label) echo '<span class="bhc-badge bhc-badge-difficulty bhc-difficulty-' . esc_attr(BHC_PostTypes::difficulty($course_id)) . '">' . esc_html($difficulty_label) . '</span>';
        echo '<span>' . (int) $lesson_count . ' lesson' . ($lesson_count === 1 ? '' : 's') . '</span>';
        if ($duration) echo '<span>' . esc_html($duration) . '</span>';
        if ($instructor) echo '<span class="bhc-course-instructor">' . get_avatar($instructor->ID, 24) . ' Taught by ' . esc_html($instructor->display_name ?: $instructor->user_login) . '</span>';
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
            $cta = self::render_continue_cta($uid, $course_id, $percent);
            if ($cta) echo '<div class="bhc-course-header-cta">' . $cta . '</div>';

            // ROADMAP-ux-polish-and-feature-parity-2026-07.md 4a — only
            // shown once BOTH the course opts in AND this specific
            // student has actually finished it; BHC_Certificates::
            // maybe_serve_download() re-checks both server-side too, this
            // is just the visibility gate on the link itself.
            if (class_exists('BHC_Certificates') && BHC_Certificates::course_offers_certificate($course_id) && BHC_Progress::is_course_completed($uid, $course_id)) {
                echo '<div class="bhc-course-header-cta"><a class="bhc-btn bhc-btn-secondary" href="' . esc_url(BHC_Certificates::download_url($course_id)) . '">Download certificate</a></div>';
            }
        }
        echo '</div>';
        return ob_get_clean();
    }

    public static function render_course($atts) {
        $course_id = (int) ($atts['id'] ?? get_the_ID());
        if (!$course_id || get_post_type($course_id) !== 'bh_course') return '';

        if (class_exists('BH_SEO')) {
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

        $uid = get_current_user_id();
        $locked = !BHC_Gate::user_can_access_course($uid, $course_id);
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);

        // The moment a logged-in student is confirmed to have real
        // access, start their drip-scheduling clock for this course —
        // see BHC_Progress::enroll_if_needed()'s docblock for why this
        // is the one place that's safe/correct to call it from (access
        // just got confirmed, not merely attempted).
        if ($uid && !$locked) BHC_Progress::enroll_if_needed($uid, $course_id);

        ob_start();
        echo '<div class="bhc-course-view">';
        // Detail-page header (QUIZ-AND-CATALOG-DESIGN-PLAN.md Section
        // 2.7): wraps the existing lesson-list output below rather than
        // replacing it — full cover, description, instructor,
        // difficulty, duration, category/topic terms, enrollment CTA.
        // Ratings/reviews deliberately deferred (design doc scoped them
        // out of this pass — no data model for them exists yet).
        echo self::render_course_header($course_id, $uid, $locked);
        if ($locked) {
            echo BHC_Gate::render_paywall_notice($course_id);
            // Even locked, a prospective student can see what they'd be
            // signing up for — a syllabus of lesson TITLES only (no
            // content, no permalink), same "tease, don't leak" posture
            // the drip-lock notice already takes for individual lessons.
            if ($lesson_ids) {
                echo '<div class="bhc-syllabus-preview"><h4>What you\'ll learn</h4><ol class="bhc-lesson-list bhc-lesson-list-preview">';
                foreach ($lesson_ids as $lesson_id) {
                    if (get_post_status($lesson_id) !== 'publish') continue;
                    echo '<li><span>' . esc_html(get_the_title($lesson_id)) . '</span></li>';
                }
                echo '</ol></div>';
            }
        } else {
            // Enrollment/continue CTA now lives in render_course_header()
            // above (shown for every locked/unlocked state consistently)
            // — not duplicated here anymore.
            if ($uid) {
                $percent = BHC_Progress::course_percent($uid, $course_id);
                echo '<div class="bhc-progress-bar bhc-progress-bar-large"><div class="bhc-progress-fill" style="width:' . (int) $percent . '%"></div></div><p class="bhc-progress-label">' . (int) $percent . '% complete</p>';
            }
            echo '<ol class="bhc-lesson-list">';
            foreach ($lesson_ids as $i => $lesson_id) {
                if (get_post_status($lesson_id) !== 'publish') continue;
                $open = BHC_Gate::lesson_is_open($uid, $lesson_id);
                $step_count = BHC_Steps::count($lesson_id);
                $done_count = $uid ? count(BHC_Progress::completed_steps($uid, $lesson_id)) : 0;
                $complete = $step_count > 0 && $done_count >= $step_count;
                echo '<li class="' . ($complete ? 'bhc-lesson-done' : '') . ($open ? '' : ' bhc-lesson-locked') . '">';
                if ($open) {
                    echo '<a href="' . esc_url(get_permalink($lesson_id)) . '">' . esc_html(get_the_title($lesson_id)) . '</a>';
                } else {
                    echo '<span>' . esc_html(get_the_title($lesson_id)) . '</span> <span class="bhc-drip-notice">&#128274; ' . BHC_Gate::drip_notice($uid, $lesson_id) . '</span>';
                }
                if ($open && $uid && $step_count) echo ' <span class="bhc-lesson-progress">(' . (int) $done_count . '/' . (int) $step_count . ')</span>';
                if ($complete) echo ' <span class="bhc-check">&#10003;</span>';
                echo '</li>';
            }
            echo '</ol>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    // Shared between the course page's own lesson list (unlocked branch
    // above) and BHC_Render_Lesson's per-lesson view — a student inside a
    // lesson previously had no way to see the rest of the course or jump
    // between lessons without going back to the course page first.
    // $current_lesson_id highlights the lesson currently open; null on
    // the course page itself (nothing is "current" there).
    public static function render_lesson_sidebar($course_id, $uid, $current_lesson_id = null) {
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);
        if (!$lesson_ids) return '';
        $percent = $uid ? BHC_Progress::course_percent($uid, $course_id) : 0;

        ob_start();
        echo '<nav class="bhc-course-sidebar" aria-label="Course lessons">';
        echo '<a class="bhc-sidebar-course-link" href="' . esc_url(get_permalink($course_id)) . '">' . esc_html(get_the_title($course_id)) . '</a>';
        if ($uid) {
            echo '<div class="bhc-progress-bar"><div class="bhc-progress-fill" style="width:' . (int) $percent . '%"></div></div><p class="bhc-progress-label">' . (int) $percent . '% complete</p>';
        }
        echo '<ol class="bhc-lesson-list bhc-sidebar-lesson-list">';
        foreach ($lesson_ids as $lesson_id) {
            if (get_post_status($lesson_id) !== 'publish') continue;
            $open = BHC_Gate::lesson_is_open($uid, $lesson_id);
            $step_count = BHC_Steps::count($lesson_id);
            $done_count = $uid ? count(BHC_Progress::completed_steps($uid, $lesson_id)) : 0;
            $complete = $step_count > 0 && $done_count >= $step_count;
            $is_current = $current_lesson_id && (int) $lesson_id === (int) $current_lesson_id;
            $classes = ($complete ? 'bhc-lesson-done' : '') . (!$open ? ' bhc-lesson-locked' : '') . ($is_current ? ' bhc-lesson-current' : '');
            echo '<li class="' . trim($classes) . '">';
            if ($open) {
                echo '<a href="' . esc_url(get_permalink($lesson_id)) . '"' . ($is_current ? ' aria-current="page"' : '') . '>' . esc_html(get_the_title($lesson_id)) . '</a>';
            } else {
                echo '<span>' . esc_html(get_the_title($lesson_id)) . '</span> <span class="bhc-drip-notice">&#128274;</span>';
            }
            if ($complete) echo ' <span class="bhc-check">&#10003;</span>';
            echo '</li>';
        }
        echo '</ol></nav>';
        return ob_get_clean();
    }

    // Shared between the catalog card (BHC_Render_Catalog) and this
    // course page itself — both need the exact same "where should this
    // student go next" decision, just rendered at different sizes.
    // $percent is passed in rather than recomputed since both call sites
    // already have it (or compute it themselves right before calling
    // this).
    public static function render_continue_cta($uid, $course_id, $percent) {
        $target_lesson = BHC_Progress::first_incomplete_lesson($uid, $course_id);
        if ($target_lesson) {
            $label = $percent > 0 ? 'Continue' : 'Start';
            return '<a class="bhc-btn bhc-continue-btn" href="' . esc_url(get_permalink($target_lesson)) . '">' . esc_html($label) . ' &rarr;</a>';
        }
        // Nothing left to resume into — either genuinely 100% done, or
        // every lesson is either empty or drip-locked (first_incomplete_lesson()
        // can't tell those apart, and neither needs a CTA either way).
        // Only show "Review" once actually complete, not for the
        // ambiguous "nothing open yet" case.
        if ($percent >= 100) {
            $lesson_ids = BHC_PostTypes::lesson_order($course_id);
            if ($lesson_ids) {
                return '<a class="bhc-btn bhc-btn-secondary bhc-continue-btn" href="' . esc_url(get_permalink($lesson_ids[0])) . '">Review course</a>';
            }
        }
        return '';
    }
}
