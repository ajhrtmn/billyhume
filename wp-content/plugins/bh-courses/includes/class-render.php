<?php
if (!defined('ABSPATH')) exit;

/**
 * Front end: a catalog shortcode and a single-lesson step-walker.
 * Kept deliberately simple/server-rendered (one step visible at a
 * time, plain form posts to AJAX for quiz submit / mark-complete) —
 * matches this ecosystem's general "plain PHP + a little JS," not a
 * SPA rebuild of bh-streaming's player.
 */
class BHC_Render {
    public static function init() {
        add_shortcode('bh_courses', [self::class, 'render_catalog']);
        add_shortcode('bh_course', [self::class, 'render_course']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
    }

    public static function maybe_enqueue() {
        if (!is_singular()) return;
        global $post;
        if (!$post || !(has_shortcode($post->post_content, 'bh_courses') || has_shortcode($post->post_content, 'bh_course') || $post->post_type === 'bh_lesson')) return;

        wp_enqueue_style('bhc-front', BHC_URL . 'assets/css/courses.css', [], BHC_VER);
        if (class_exists('BHY_Style')) wp_add_inline_style('bhc-front', BHY_Style::inline_css());
        wp_enqueue_script('bhc-front', BHC_URL . 'assets/js/courses.js', [], BHC_VER, true);
        wp_localize_script('bhc-front', 'BHCData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('bhc_progress'),
        ]);
    }

    /* ---------------- catalog ---------------- */

    public static function render_catalog() {
        $courses = get_posts(['post_type' => 'bh_course', 'post_status' => 'publish', 'numberposts' => -1]);
        if (!$courses) return '<p class="bhc-empty">No courses yet.</p>';

        $uid = get_current_user_id();
        ob_start();
        echo '<div class="bhc-catalog">';
        foreach ($courses as $course) {
            $locked = !BHC_Gate::user_can_access_course($uid, $course->ID);
            $percent = $uid ? BHC_Progress::course_percent($uid, $course->ID) : 0;
            echo '<div class="bhc-course-card' . ($locked ? ' bhc-locked' : '') . '">';
            if (has_post_thumbnail($course->ID)) echo get_the_post_thumbnail($course->ID, 'medium');
            echo '<h3><a href="' . esc_url(get_permalink($course->ID)) . '">' . esc_html(get_the_title($course->ID)) . '</a>' . ($locked ? ' <span class="bhc-lock">&#128274;</span>' : '') . '</h3>';
            echo '<div class="bhc-excerpt">' . wp_kses_post(get_the_excerpt($course->ID)) . '</div>';
            if ($uid && !$locked) echo '<div class="bhc-progress-bar"><div class="bhc-progress-fill" style="width:' . (int) $percent . '%"></div></div><p class="bhc-progress-label">' . (int) $percent . '% complete</p>';
            echo '</div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    /* ---------------- single course (lesson list) ---------------- */

    public static function render_course($atts) {
        $course_id = (int) ($atts['id'] ?? get_the_ID());
        if (!$course_id || get_post_type($course_id) !== 'bh_course') return '';

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
        if ($locked) {
            echo BHC_Gate::render_paywall_notice($course_id);
        } else {
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

    /* ---------------- single lesson (the step walker) ---------------- */

    // Hooked onto the_content for bh_lesson so a plain lesson permalink
    // just works with zero shortcode needed — same "public CPT with its
    // own single view" approach bh-streaming doesn't use (it's an SPA)
    // but bh-contest's/most plain WP content does.
    public static function render_lesson_steps($lesson_id) {
        $uid = get_current_user_id();
        $course_id = BHC_PostTypes::course_for_lesson($lesson_id);

        // Tier access and drip scheduling are checked (and reported)
        // separately, not through the combined user_can_access_lesson()
        // check — a paid-up student hitting a not-yet-open lesson should
        // see "opens in 3 days," not a confusing "become a supporter"
        // prompt for something they've already paid for.
        if ($course_id && !BHC_Gate::user_can_access_course($uid, $course_id)) {
            return BHC_Gate::render_paywall_notice($course_id);
        }
        // Same enrollment recording as render_course() — a student who
        // deep-links straight to a lesson (never visiting the course
        // page first) still needs their drip clock started.
        if ($uid && $course_id) BHC_Progress::enroll_if_needed($uid, $course_id);
        if (!BHC_Gate::lesson_is_open($uid, $lesson_id)) {
            return '<div class="bhc-drip-locked"><p>&#128274; ' . BHC_Gate::drip_notice($uid, $lesson_id) . '</p></div>';
        }

        $steps = BHC_Steps::get($lesson_id);
        if (!$steps) return '<p class="bhc-empty">This lesson has no content yet.</p>';

        $completed = $uid ? BHC_Progress::completed_steps($uid, $lesson_id) : [];
        // First not-yet-completed step, so a returning student lands
        // where they left off rather than back at step 1 every time.
        $start_index = 0;
        foreach ($steps as $i => $step) {
            if (!in_array($i, $completed, true)) { $start_index = $i; break; }
            $start_index = $i + 1;
        }
        $start_index = min($start_index, count($steps) - 1);

        ob_start();
        echo '<div class="bhc-lesson" data-lesson-id="' . (int) $lesson_id . '" data-step-count="' . count($steps) . '" data-start-index="' . (int) $start_index . '">';
        echo '<div class="bhc-step-progress">Step <span class="bhc-step-current">' . ($start_index + 1) . '</span> of ' . count($steps) . '</div>';

        foreach ($steps as $i => $step) {
            $is_done = in_array($i, $completed, true);
            $visible = $i === $start_index ? '' : ' style="display:none;"';
            echo '<div class="bhc-step bhc-step-' . esc_attr($step['type']) . ($is_done ? ' bhc-step-done' : '') . '" data-step-index="' . (int) $i . '"' . $visible . '>';
            echo self::render_step($lesson_id, $i, $step, $is_done);
            echo '</div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    private static function render_step($lesson_id, $index, $step, $is_done) {
        ob_start();
        if ($step['type'] === 'text') {
            echo '<div class="bhc-step-text">' . wp_kses_post($step['content']) . '</div>';
            echo '<button type="button" class="bhc-btn bhc-mark-complete"' . ($is_done ? ' disabled' : '') . '>' . ($is_done ? 'Completed' : 'Mark complete &amp; continue') . '</button>';
        } elseif ($step['type'] === 'image') {
            foreach ($step['attachment_ids'] as $attachment_id) {
                echo wp_get_attachment_image($attachment_id, 'large', false, ['class' => 'bhc-step-image']);
            }
            if (!empty($step['caption'])) echo '<p class="bhc-step-caption">' . esc_html($step['caption']) . '</p>';
            echo '<button type="button" class="bhc-btn bhc-mark-complete"' . ($is_done ? ' disabled' : '') . '>' . ($is_done ? 'Completed' : 'Mark complete &amp; continue') . '</button>';
        } elseif ($step['type'] === 'video') {
            if ($step['source'] === 'upload') {
                // wp_get_attachment_url() is the one API surface an
                // offload plugin (see Own Ur Shit's dashboard entry for
                // Advanced Media Offloader) rewrites transparently —
                // this plain <video> tag needs zero changes whether the
                // file is on this server's disk or Cloudflare R2.
                $url = wp_get_attachment_url($step['attachment_id']);
                if ($url) {
                    echo '<video class="bhc-step-video" controls preload="metadata" src="' . esc_url($url) . '"></video>';
                } else {
                    echo '<p class="bhc-empty">Video file not found.</p>';
                }
            } else {
                // A plain external URL — Cloudflare Stream/Bunny Stream
                // iframe embeds and most other "give me embed code"
                // platforms hand you either a direct file URL (works in
                // a <video> tag) or their own iframe embed URL. Since we
                // can't tell which without knowing the provider, embed
                // via <iframe> when the URL looks like one of the common
                // *embed*/*iframe* patterns, otherwise treat it as a
                // direct video URL — good enough for v1 without needing
                // provider-specific integration code.
                $url = $step['video_url'];
                if (preg_match('#(iframe|embed|player)#i', $url)) {
                    echo '<iframe class="bhc-step-video-embed" src="' . esc_url($url) . '" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>';
                } else {
                    echo '<video class="bhc-step-video" controls preload="metadata" src="' . esc_url($url) . '"></video>';
                }
            }
            if (!empty($step['caption'])) echo '<p class="bhc-step-caption">' . esc_html($step['caption']) . '</p>';
            echo '<button type="button" class="bhc-btn bhc-mark-complete"' . ($is_done ? ' disabled' : '') . '>' . ($is_done ? 'Completed' : 'Mark complete &amp; continue') . '</button>';
        } elseif ($step['type'] === 'quiz') {
            $uid = get_current_user_id();
            $max_attempts = (int) ($step['max_attempts'] ?? 0);
            $attempts_used = $uid ? BHC_Progress::attempts($uid, $lesson_id, $index) : 0;
            $already_passed = $is_done; // is_step_complete()'s rule: a quiz row only reads "done" once passed
            $exhausted = !$already_passed && $max_attempts > 0 && $attempts_used >= $max_attempts;

            echo '<form class="bhc-quiz-form" data-max-attempts="' . $max_attempts . '" data-attempts-used="' . $attempts_used . '">';
            foreach ($step['questions'] as $qi => $q) {
                echo '<fieldset class="bhc-quiz-question"><legend>' . esc_html($q['question']) . '</legend>';
                foreach ($q['choices'] as $ci => $choice) {
                    echo '<label class="bhc-quiz-choice"><input type="radio" name="q' . (int) $qi . '" value="' . (int) $ci . '"' . ($exhausted ? ' disabled' : '') . '> ' . esc_html($choice) . '</label>';
                }
                echo '</fieldset>';
            }
            if ($max_attempts > 0) {
                echo '<p class="bhc-attempts-note">' . ($already_passed ? 'Passed.' : ($exhausted ? 'No attempts remaining (' . $max_attempts . ' allowed).' : ($max_attempts - $attempts_used) . ' of ' . $max_attempts . ' attempts remaining.')) . '</p>';
            }
            echo '<button type="submit" class="bhc-btn bhc-submit-quiz"' . ($exhausted ? ' disabled' : '') . '>Submit answers</button>';
            echo '<div class="bhc-quiz-result" style="display:' . ($exhausted && !$already_passed ? '' : 'none') . '">' . ($exhausted && !$already_passed ? 'No attempts remaining.' : '') . '</div>';
            echo '</form>';
        }
        return ob_get_clean();
    }
}
