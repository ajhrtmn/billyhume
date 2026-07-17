<?php
if (!defined('ABSPATH')) exit;

/**
 * Extracted from the old monolithic class-render.php (SRP QA pass,
 * bh-courses 0.4.8) — see class-render-catalog.php's own docblock for
 * the full "why three classes" reasoning; this is a pure move, not a
 * rewrite. This class owns exactly one thing: the single-lesson step
 * walker hooked onto the_content for bh_lesson (text/image/video/quiz
 * steps, one visible at a time) plus the quiz-answer review breakdown.
 */
class BHC_Render_Lesson {
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
        // Same enrollment recording as BHC_Render_Course::render_course()
        // — a student who deep-links straight to a lesson (never
        // visiting the course page first) still needs their drip clock
        // started.
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

        // Where "Next Lesson →" should go once the last step here is
        // cleared — the course-level sibling of the step-level "what's
        // next" logic above. null if this lesson is orphaned (no
        // course) or is the course's last lesson; the JS decides what
        // to show based on which of the two is true (see
        // .bhc-lesson-next below).
        $next_lesson_id = null;
        $lesson_position = null;
        $lesson_count = null;
        if ($course_id) {
            $order = BHC_PostTypes::lesson_order($course_id);
            $pos = array_search((int) $lesson_id, $order, true);
            if ($pos !== false && isset($order[$pos + 1])) $next_lesson_id = $order[$pos + 1];
            if ($pos !== false) { $lesson_position = $pos + 1; $lesson_count = count($order); }
        }

        ob_start();

        // Persistent way back to the course, rendered unconditionally
        // (not gated on lesson completion like .bhc-lesson-next below)
        // — a student who deep-links into a lesson or just wants out
        // mid-lesson previously had no exit until finishing every step.
        if ($course_id) {
            echo '<div class="bhc-lesson-breadcrumb">';
            echo '<a href="' . esc_url(get_permalink($course_id)) . '">&larr; ' . esc_html(get_the_title($course_id)) . '</a>';
            if ($lesson_position) {
                echo '<span class="bhc-lesson-position">Lesson ' . (int) $lesson_position . ' of ' . (int) $lesson_count . '</span>';
            }
            echo '</div>';
        }

        echo '<div class="bhc-lesson" data-lesson-id="' . (int) $lesson_id . '" data-step-count="' . count($steps) . '" data-start-index="' . (int) $start_index . '">';
        echo '<div class="bhc-step-progress">Step <span class="bhc-step-current">' . ($start_index + 1) . '</span> of ' . count($steps) . '</div>';

        // Visual stepper: every step type-tagged and shown at a glance
        // (previously "Step X of Y" was plain text with zero sense of
        // what's ahead or what kind of content each step is — every
        // type shared one flat card look). Dots up through the current
        // step are clickable (courses.js), same reachability rule the
        // existing per-step "Back" buttons already use — never lets a
        // student skip ahead to an unseen step from here.
        echo '<div class="bhc-stepper" role="tablist">';
        foreach ($steps as $i => $step) {
            $is_done = in_array($i, $completed, true);
            $is_current = $i === $start_index;
            $reachable = $is_done || $i <= $start_index;
            $classes = 'bhc-stepper-dot bhc-stepper-' . esc_attr($step['type']);
            if ($is_done) $classes .= ' bhc-stepper-done';
            if ($is_current) $classes .= ' bhc-stepper-current';
            // aria-label carries the same "Step N: Type" info the ::before
            // glyph conveys visually — the glyph itself is pure CSS
            // content, invisible to a screen reader on its own.
            echo '<button type="button" class="' . $classes . '" data-target-index="' . (int) $i . '"'
                . (!$reachable ? ' disabled' : '') . ' title="Step ' . (int) ($i + 1) . ': ' . esc_attr(ucfirst($step['type'])) . '"'
                . ' aria-label="Step ' . (int) ($i + 1) . ': ' . esc_attr(ucfirst($step['type'])) . ($is_done ? ' (completed)' : '') . '"'
                . ' aria-current="' . ($is_current ? 'step' : 'false') . '"></button>';
        }
        echo '</div>';

        foreach ($steps as $i => $step) {
            $is_done = in_array($i, $completed, true);
            $visible = $i === $start_index ? '' : ' style="display:none;"';
            echo '<div class="bhc-step bhc-step-' . esc_attr($step['type']) . ($is_done ? ' bhc-step-done' : '') . '" data-step-index="' . (int) $i . '"' . $visible . '>';
            echo self::render_step($lesson_id, $i, $step, $is_done);
            // Revisiting an earlier (already-rendered, already-completed)
            // step is just showing a different one of these divs — no
            // server round trip, no completion-state change. Omitted on
            // the first step (nothing behind it).
            if ($i > 0) echo '<button type="button" class="bhc-btn bhc-btn-secondary bhc-step-back" data-target-index="' . (int) ($i - 1) . '">&larr; Back</button>';
            echo '</div>';
        }

        // Hidden until the JS reveals it, the moment the LAST step is
        // marked complete/passed — see courses.js's advance(). Rendered
        // unconditionally (not just when start_index is already at the
        // last step) so a student who completes the lesson mid-session
        // sees it appear without a page reload.
        echo '<div class="bhc-lesson-next" style="display:none;">';
        if (count($steps) > 0) {
            echo '<button type="button" class="bhc-btn bhc-btn-secondary bhc-step-back" data-target-index="' . (int) (count($steps) - 1) . '">&larr; Back to lesson</button>';
        }
        if ($next_lesson_id) {
            echo '<a class="bhc-btn" href="' . esc_url(get_permalink($next_lesson_id)) . '">Next Lesson &rarr;</a>';
        } elseif ($course_id) {
            echo '<p class="bhc-course-complete">&#127881; You\'ve completed this course!</p>';
            echo '<a class="bhc-btn" href="' . esc_url(get_permalink($course_id)) . '">Back to course</a>';
        }
        echo '</div>';

        // First real BH_Element content on a lesson page (class-lesson-
        // surface.php, BHC_LessonSurface — the 'bh_courses_lesson'
        // surface's one 'root' slot, keyed per-lesson via $lesson_id as
        // surface_context_id). Deliberately rendered ONCE per lesson,
        // outside every per-step div above (not duplicated per step) —
        // an optional "below the lesson" area (resources, related
        // reading, a promo callout, whatever AJ builds in the Design
        // Suite), empty and invisible by default until something is
        // actually placed there. render_slot() itself is the guard when
        // BH_Element isn't loaded, same convention every other surface
        // call site in this ecosystem follows.
        if (class_exists('BH_Element')) {
            echo BH_Element::render_slot('bh_courses_lesson', (int) $lesson_id, 'root', ['user_id' => $uid]);
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
            // ROADMAP-ux-polish-and-feature-parity-2026-07.md 4b: only the
            // real <video> tag case is watch-position-trackable (a
            // timeupdate listener needs same-origin media, which a
            // cross-origin YouTube/Vimeo <iframe> embed can't offer
            // without that provider's own SDK) — $trackable stays false
            // for the iframe branch below regardless of watch_threshold,
            // and courses.js simply has nothing to attach a listener to
            // in that case.
            $trackable = false;
            $threshold = (int) ($step['watch_threshold'] ?? 0);
            // Only rendered onto the <video> tag when there's actually a
            // threshold to enforce — an untracked/threshold-0 video needn't
            // pay for a timeupdate listener courses.js would otherwise just
            // ignore the result of.
            $threshold_attr = $threshold > 0 ? ' data-watch-threshold="' . $threshold . '"' : '';
            if ($step['source'] === 'upload') {
                // wp_get_attachment_url() is the one API surface an
                // offload plugin (see Own Ur Shit's dashboard entry for
                // Advanced Media Offloader) rewrites transparently —
                // this plain <video> tag needs zero changes whether the
                // file is on this server's disk or Cloudflare R2.
                $url = wp_get_attachment_url($step['attachment_id']);
                if ($url) {
                    echo '<video class="bhc-step-video" controls preload="metadata" src="' . esc_url($url) . '"' . $threshold_attr . '></video>';
                    $trackable = true;
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
                    echo '<video class="bhc-step-video" controls preload="metadata" src="' . esc_url($url) . '"' . $threshold_attr . '></video>';
                    $trackable = true;
                }
            }
            if (!empty($step['caption'])) echo '<p class="bhc-step-caption">' . esc_html($step['caption']) . '</p>';

            $uid = get_current_user_id();
            $watched = ($uid && $trackable) ? BHC_Progress::watched_percent($uid, $lesson_id, $index) : 0;

            if ($threshold > 0 && $trackable) {
                // No manual override button here on purpose — courses.js's
                // timeupdate listener auto-completes this step once
                // $threshold is cleared (BHC_Progress::update_watch_progress()),
                // the same "no bespoke second completion mechanic" posture
                // the resource step's Mark-complete button already
                // follows, just inverted (auto instead of always-available).
                // The progress note gives the student a visible reason
                // nothing happened yet if they just click play and walk
                // away without it reaching the threshold.
                echo '<p class="bhc-video-progress-note"' . ($is_done ? ' style="display:none;"' : '') . '>Watch ' . (int) $threshold . '% to mark this step complete' . ($watched > 0 ? ' (' . (int) $watched . '% watched so far)' : '') . '.</p>';
                echo '<button type="button" class="bhc-btn bhc-mark-complete" style="display:' . ($is_done ? '' : 'none') . ';" disabled>Completed</button>';
            } else {
                echo '<button type="button" class="bhc-btn bhc-mark-complete"' . ($is_done ? ' disabled' : '') . '>' . ($is_done ? 'Completed' : 'Mark complete &amp; continue') . '</button>';
            }
        } elseif ($step['type'] === 'quiz') {
            $uid = get_current_user_id();
            $max_attempts = (int) ($step['max_attempts'] ?? 0);
            $attempts_used = $uid ? BHC_Progress::attempts($uid, $lesson_id, $index) : 0;
            $already_passed = $is_done; // is_step_complete()'s rule: a quiz row only reads "done" once passed
            $exhausted = !$already_passed && $max_attempts > 0 && $attempts_used >= $max_attempts;

            // Revisiting a passed quiz (via the back button) now renders
            // the REAL stored snapshot — QUIZ-AND-CATALOG-DESIGN-PLAN.md
            // Part 1, closing the gap this file used to flag in a comment
            // right here. A pre-migration row (passed before the answers
            // column existed) has no snapshot; that one honest case falls
            // back to the old aggregate-only "you passed" note rather
            // than fabricating a breakdown that was never recorded.
            $snapshot = ($already_passed && $uid) ? BHC_Progress::stored_answers($uid, $lesson_id, $index) : null;

            if ($snapshot && !empty($snapshot['questions'])) {
                echo self::render_quiz_review($snapshot);
            } else {
                $disable_inputs = $exhausted || $already_passed;
                echo '<form class="bhc-quiz-form" data-max-attempts="' . $max_attempts . '" data-attempts-used="' . $attempts_used . '">';
                foreach ($step['questions'] as $qi => $q) {
                    echo '<fieldset class="bhc-quiz-question" data-question-index="' . (int) $qi . '"><legend>' . esc_html($q['question']) . '</legend>';
                    foreach ($q['choices'] as $ci => $choice) {
                        echo '<label class="bhc-quiz-choice"><input type="radio" name="q' . (int) $qi . '" value="' . (int) $ci . '"' . ($disable_inputs ? ' disabled' : '') . '> <span class="bhc-choice-text">' . esc_html($choice) . '</span></label>';
                    }
                    echo '</fieldset>';
                }
                if ($already_passed) {
                    echo '<p class="bhc-attempts-note bhc-quiz-passed-note">&#10003; You already passed this quiz.</p>';
                } elseif ($max_attempts > 0) {
                    echo '<p class="bhc-attempts-note">' . ($exhausted ? 'No attempts remaining (' . $max_attempts . ' allowed).' : ($max_attempts - $attempts_used) . ' of ' . $max_attempts . ' attempts remaining.') . '</p>';
                }
                if (!$already_passed) {
                    echo '<button type="submit" class="bhc-btn bhc-submit-quiz"' . ($exhausted ? ' disabled' : '') . '>Submit answers</button>';
                }
                echo '<div class="bhc-quiz-result" style="display:' . ($exhausted && !$already_passed ? '' : 'none') . '">' . ($exhausted && !$already_passed ? 'No attempts remaining.' : '') . '</div>';
                echo '</form>';
            }
        } elseif ($step['type'] === 'resource') {
            // Non-blocking by design (ROADMAP-ux-polish-and-feature-
            // parity-2026-07.md 4c's own scoping note) — same Mark-
            // complete-and-continue pattern as text/image/video rather
            // than a bespoke "downloaded" tracking mechanic; a student
            // isn't required to actually click the download to advance,
            // same as they aren't required to actually read a text step.
            $url = wp_get_attachment_url($step['attachment_id']);
            $label = $step['label'] !== '' ? $step['label'] : basename(get_attached_file($step['attachment_id']) ?: 'Download');
            if ($url) {
                echo '<a class="bhc-btn bhc-resource-download" href="' . esc_url($url) . '" download>&#8681; ' . esc_html($label) . '</a>';
            } else {
                echo '<p class="bhc-empty">File not found.</p>';
            }
            if (!empty($step['description'])) echo '<p class="bhc-step-caption">' . esc_html($step['description']) . '</p>';
            echo '<button type="button" class="bhc-btn bhc-mark-complete"' . ($is_done ? ' disabled' : '') . '>' . ($is_done ? 'Completed' : 'Mark complete &amp; continue') . '</button>';
        }
        return ob_get_clean();
    }

    // Static, non-interactive breakdown of a stored quiz snapshot — used
    // both for the passed-quiz review above (server-rendered on page
    // load) and mirrored client-side by courses.js right after a fresh
    // submission (same visual language, built from the same 'questions'
    // shape BHC_Steps::score_quiz() returns — see that method's docblock).
    // Marks the student's chosen choice and, when they got it wrong, the
    // actually-correct one too — the "better visual states" half of the
    // quiz UX ask, matching (B) end-of-submission review rather than
    // (A) per-question-as-you-go (QUIZ-AND-CATALOG-DESIGN-PLAN.md Part
    // 1.5 — (A) would let a student game the max_attempts budget one
    // question at a time; (B) can't, since the attempt is already spent
    // and scored before any correctness is shown).
    public static function render_quiz_review($snapshot) {
        $score = (int) ($snapshot['score'] ?? 0);
        ob_start();
        echo '<div class="bhc-quiz-review">';
        foreach ((array) ($snapshot['questions'] ?? []) as $qi => $q) {
            $chosen = (int) ($q['chosen_index'] ?? -1);
            $correct_index = (int) ($q['correct_index'] ?? -1);
            $q_correct = $chosen === $correct_index;
            echo '<fieldset class="bhc-quiz-question bhc-quiz-question-review ' . ($q_correct ? 'bhc-q-correct' : 'bhc-q-incorrect') . '"><legend>' . esc_html($q['q'] ?? '') . '</legend>';
            foreach ((array) ($q['choices'] ?? []) as $ci => $choice) {
                $classes = ['bhc-quiz-choice', 'bhc-quiz-choice-review'];
                if ($ci === $correct_index) $classes[] = 'bhc-correct';
                if ($ci === $chosen && !$q_correct) $classes[] = 'bhc-choice-incorrect';
                $marker = '';
                if ($ci === $correct_index) $marker = ' <span class="bhc-choice-marker">&#10003; Correct answer</span>';
                elseif ($ci === $chosen) $marker = ' <span class="bhc-choice-marker">&#10007; Your answer</span>';
                echo '<div class="' . esc_attr(implode(' ', $classes)) . '"><span class="bhc-choice-text">' . esc_html($choice) . '</span>' . $marker . '</div>';
            }
            echo '</fieldset>';
        }
        echo '<p class="bhc-attempts-note bhc-quiz-passed-note">&#10003; You passed this quiz — score: ' . $score . '%.</p>';
        echo '</div>';
        return ob_get_clean();
    }
}
