<?php
if (!defined('ABSPATH')) exit;

/**
 * The single-lesson step walker hooked onto the_content for bh_lesson (one step
 * visible at a time), plus the quiz-answer review breakdown.
 */
class BHC_Render_Lesson {
    // Hooked onto the_content for bh_lesson so a plain lesson permalink works without a shortcode.
    public static function render_lesson_steps(int $lesson_id): string {
        $uid = get_current_user_id();
        $course_id = BHC_PostTypes::course_for_lesson($lesson_id);

        // Same login gate as the course page; a deep-linked lesson would otherwise bypass it.
        if ($course_id && !$uid && !OUS_Visibility::is_public($course_id)) {
            return OUS_Visibility::render_login_notice(__('Log in to view this lesson.', 'bh-courses'));
        }
        // Tier access and drip are checked separately so a paid-up student sees
        // "opens in 3 days", not a supporter prompt.
        if ($course_id && !BHC_Gate::user_can_access_course($uid, $course_id)) {
            return BHC_Gate::render_paywall_notice($course_id);
        }
        // Deep-linked lessons still start the drip clock.
        if ($uid && $course_id) BHC_Progress::enroll_if_needed($uid, $course_id);
        if (!BHC_Gate::lesson_is_open($uid, $lesson_id)) {
            return '<div class="bhc-drip-locked"><p>&#128274; ' . BHC_Gate::drip_notice($uid, $lesson_id) . '</p></div>';
        }

        $steps = BHC_Steps::get($lesson_id);
        if (!$steps) return '<p class="bhc-empty">This lesson has no content yet.</p>';

        $completed = $uid ? BHC_Progress::completed_steps($uid, $lesson_id) : [];
        // Land on the first not-yet-completed step.
        $start_index = 0;
        foreach ($steps as $i => $step) {
            if (!in_array($i, $completed, true)) { $start_index = $i; break; }
            $start_index = $i + 1;
        }
        $start_index = min($start_index, count($steps) - 1);

        // Next lesson in the course, or null if orphaned or last.
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

        // Persistent way back to the course, regardless of completion.
        if ($course_id) {
            echo '<div class="bhc-lesson-breadcrumb">';
            echo '<a href="' . esc_url(get_permalink($course_id)) . '">&larr; ' . esc_html(get_the_title($course_id)) . '</a>';
            if ($lesson_position) {
                echo '<span class="bhc-lesson-position">Lesson ' . (int) $lesson_position . ' of ' . (int) $lesson_count . '</span>';
            }
            echo '</div>';
        }

        // WHY: no `alignwide` here. This theme's own `.oust-prose .alignwide` 100vw bleed
        // overflows by the scrollbar width; courses.css widens the container instead.
        echo '<div class="bhc-lesson-layout">';
        // Lesson list + overall progress sidebar.
        if ($course_id && class_exists('BHC_Render_Course')) {
            echo BHC_Render_Course::render_lesson_sidebar($course_id, $uid, $lesson_id);
        }
        echo '<div class="bhc-lesson-main">';

        echo '<div class="bhc-lesson" data-lesson-id="' . (int) $lesson_id . '" data-step-count="' . count($steps) . '" data-start-index="' . (int) $start_index . '">';
        // aria-live so the step counter change is announced.
        echo '<div class="bhc-step-progress" role="status" aria-live="polite">Step <span class="bhc-step-current">' . ($start_index + 1) . '</span> of ' . count($steps) . '</div>';

        // Type-tagged stepper; only completed/current steps are clickable, never ahead.
        echo '<div class="bhc-stepper" role="tablist">';
        foreach ($steps as $i => $step) {
            $is_done = in_array($i, $completed, true);
            $is_current = $i === $start_index;
            $reachable = $is_done || $i <= $start_index;
            $classes = 'bhc-stepper-dot bhc-stepper-' . esc_attr($step['type']);
            if ($is_done) $classes .= ' bhc-stepper-done';
            if ($is_current) $classes .= ' bhc-stepper-current';
            // aria-label carries the step type the CSS ::before glyph shows visually.
            echo '<button type="button" class="' . $classes . '" data-target-index="' . (int) $i . '"'
                . (!$reachable ? ' disabled' : '') . ' title="Step ' . (int) ($i + 1) . ': ' . esc_attr(ucfirst($step['type'])) . '"'
                . ' aria-label="Step ' . (int) ($i + 1) . ': ' . esc_attr(ucfirst($step['type'])) . ($is_done ? ' (completed)' : '') . '"'
                . ' aria-current="' . ($is_current ? 'step' : 'false') . '"></button>';
        }
        echo '</div>';

        foreach ($steps as $i => $step) {
            $is_done = in_array($i, $completed, true);
            $visible = $i === $start_index ? '' : ' style="display:none;"';
            // tabindex="-1" lets showStep() move focus here without joining the tab order.
            // WHY: data-step-type/-done duplicate the class because the live Etch site strips
            // it on load; courses.js rebuilds the class from these attributes.
            echo '<div class="bhc-step bhc-step-' . esc_attr($step['type']) . ($is_done ? ' bhc-step-done' : '')
                . '" data-step-index="' . (int) $i . '" data-step-type="' . esc_attr($step['type']) . '"' . ($is_done ? ' data-step-done="1"' : '')
                . ' tabindex="-1"' . $visible . '>';
            echo self::render_step($lesson_id, $i, $step, $is_done);
            // Back is client-side only; omitted on the first step.
            if ($i > 0) echo '<button type="button" class="bhc-btn bhc-btn-secondary bhc-step-back" data-target-index="' . (int) ($i - 1) . '">&larr; Back</button>';
            echo '</div>';
        }

        // Revealed by courses.js's advance() when the last step completes.
        echo '<div class="bhc-lesson-next" tabindex="-1" style="display:none;">';
        if ($next_lesson_id) {
            // Per-lesson banner: lighter than the course-completion moment. Banner first,
            // actions grouped beneath it.
            $quiz_total = 0;
            foreach ($steps as $step) if ($step['type'] === 'quiz') $quiz_total++;
            echo '<div class="bhc-lesson-complete-banner">';
            echo '<div class="bhc-lesson-complete-icon">&#10003;</div>';
            echo '<p class="bhc-lesson-complete-title">Lesson complete</p>';
            $stats = count($steps) . ' of ' . count($steps) . ' steps';
            if ($quiz_total > 0) $stats .= ' &middot; ' . $quiz_total . ' quiz' . ($quiz_total > 1 ? 'zes' : '') . ' passed';
            echo '<p class="bhc-lesson-complete-stats">' . $stats . '</p>';
            echo '</div>';
            echo '<div class="bhc-lesson-next-actions">';
            echo '<button type="button" class="bhc-btn bhc-btn-secondary bhc-step-back" data-target-index="' . (int) (count($steps) - 1) . '">&larr; Back to lesson</button>';
            echo '<a class="bhc-btn" href="' . esc_url(get_permalink($next_lesson_id)) . '">Next Lesson &rarr;</a>';
            echo '</div>';
        } elseif ($course_id) {
            echo '<button type="button" class="bhc-btn bhc-btn-secondary bhc-step-back" data-target-index="' . (int) (count($steps) - 1) . '">&larr; Back to lesson</button>';
            // Course completion: the shared render_completion_screen(), same as the course page.
            if ($uid && class_exists('BHC_Render_Course')) {
                echo BHC_Render_Course::render_completion_screen($uid, $course_id, true);
            }
        }
        echo '</div>';

        // Optional BH_Element slot below the lesson, once per lesson (not per step).
        if (class_exists('BH_Element')) {
            echo BH_Element::render_slot('bh_courses_lesson', (int) $lesson_id, 'root', ['user_id' => $uid]);
        }

        echo '</div>'; // .bhc-lesson
        echo '</div>'; // .bhc-lesson-main
        echo '</div>'; // .bhc-lesson-layout
        return ob_get_clean();
    }

    // Converts YouTube watch/short/youtu.be and vimeo.com/ID links to embed URLs, then
    // falls back to an embed-ish substring check. Null means treat as a direct file.
    private static function to_embed_url(string $url): ?string {
        if (preg_match('#youtu\.be/([A-Za-z0-9_-]+)#i', $url, $m)
            || preg_match('#youtube\.com/(?:watch\?v=|shorts/|embed/)([A-Za-z0-9_-]+)#i', $url, $m)) {
            return 'https://www.youtube.com/embed/' . $m[1];
        }
        if (preg_match('#vimeo\.com/(?:video/)?(\d+)#i', $url, $m)) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }
        if (preg_match('#(iframe|embed|player)#i', $url)) {
            return $url;
        }
        return null;
    }

    /**
     * YouTube/Vimeo URL resolved to the provider + video ID for a Plyr embed, so
     * chapters/overlays/watch-progress work through Plyr's instance API.
     * These steps contact the provider's origin by design; uploads and direct URLs stay local.
     *
     * @return array{provider:string, id:string}|null
     */
    private static function to_plyr_provider(string $url): ?array {
        if (preg_match('#youtu\.be/([A-Za-z0-9_-]+)#i', $url, $m)
            || preg_match('#youtube\.com/(?:watch\?v=|shorts/|embed/)([A-Za-z0-9_-]+)#i', $url, $m)) {
            return ['provider' => 'youtube', 'id' => $m[1]];
        }
        if (preg_match('#vimeo\.com/(?:video/)?(\d+)#i', $url, $m)) {
            return ['provider' => 'vimeo', 'id' => $m[1]];
        }
        return null;
    }

    /** @param array<string, mixed> $step */
    private static function render_step(int $lesson_id, int $index, $step, bool $is_done): string {
        ob_start();
        if ($step['type'] === 'text') {
            echo '<div class="bhc-step-text">' . wp_kses_post($step['content']) . '</div>';
            echo '<button type="button" class="bhc-btn bhc-mark-complete"' . ($is_done ? ' disabled' : '') . '>' . ($is_done ? 'Completed' : 'Mark complete &amp; continue') . '</button>';
        } elseif ($step['type'] === 'image') {
            foreach ($step['attachment_ids'] as $attachment_id) {
                echo wp_get_attachment_image($attachment_id, 'large', false, ['class' => 'bhc-step-image']);
            }
            if (!empty($step['caption'])) echo '<div class="bhc-step-caption bhc-step-text">' . wp_kses_post($step['caption']) . '</div>';
            echo '<button type="button" class="bhc-btn bhc-mark-complete"' . ($is_done ? ' disabled' : '') . '>' . ($is_done ? 'Completed' : 'Mark complete &amp; continue') . '</button>';
        } elseif ($step['type'] === 'video') {
            // Only sources driven through the media adapter are $trackable.
            $trackable = false;
            $threshold = (int) ($step['watch_threshold'] ?? 0);
            // Only emitted when there's a threshold to enforce.
            $threshold_attr = $threshold > 0 ? ' data-watch-threshold="' . $threshold . '"' : '';
            $annotations = (array) ($step['annotations'] ?? []);
            $annotations_attr = $annotations ? ' data-annotations="' . esc_attr(wp_json_encode($annotations)) . '"' : '';
            // Sorted server-side; courses.js assumes ascending time order.
            $chapters = (array) ($step['chapters'] ?? []);
            usort($chapters, function ($a, $b) { return ($a['time'] ?? 0) <=> ($b['time'] ?? 0); });
            $chapters_attr = $chapters ? ' data-chapters="' . esc_attr(wp_json_encode(array_values($chapters))) . '"' : '';
            if ($step['source'] === 'upload') {
                // wp_get_attachment_url() is what offload plugins rewrite transparently.
                $url = wp_get_attachment_url($step['attachment_id']);
                if ($url) {
                    // The wrapper is the positioned parent for overlays.
                    echo '<div class="bhc-video-wrap"><video class="bhc-step-video" controls preload="metadata" src="' . esc_url($url) . '"' . $threshold_attr . $annotations_attr . $chapters_attr . '></video></div>';
                    $trackable = true;
                } else {
                    echo '<p class="bhc-empty">Video file not found.</p>';
                }
            } elseif ($step['source'] === 'signed_r2') {
                // Private R2 + Worker with byte-range support: a real <video>, fully trackable.
                $url = class_exists('BHY_MediaToken')
                    ? BHY_MediaToken::sign_r2((string) ($step['r2_key'] ?? ''))
                    : null;
                if ($url) {
                    echo '<div class="bhc-video-wrap"><video class="bhc-step-video" controls preload="metadata" src="' . esc_url($url) . '"' . $threshold_attr . $annotations_attr . $chapters_attr . '></video></div>';
                    $trackable = true;
                } else {
                    echo '<p class="bhc-empty">' . esc_html__('This video needs signed-delivery setup finished in Media &amp; CDN Setup.', 'bh-courses') . '</p>';
                }
            } elseif ($step['source'] === 'bunny_stream') {
                // Private Bunny Stream iframe, driven via player.js, so chapters/annotations/
                // watch-threshold work. Markers can't go on Bunny's own scrub bar; the list does.
                $embed = class_exists('BHY_MediaToken')
                    ? BHY_MediaToken::sign_bunny((string) ($step['bunny_guid'] ?? ''))
                    : null;
                if ($embed) {
                    // Same shape as the provider branch: a .bhc-step-video div wrapping the iframe.
                    echo '<div class="bhc-video-wrap"><div class="bhc-step-video bhc-step-video-bunny"' . $threshold_attr . $annotations_attr . $chapters_attr . '>'
                        . '<iframe class="bhc-bunny-embed" src="' . esc_url($embed) . '" loading="lazy" style="border:none;" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;" allowfullscreen></iframe>'
                        . '</div></div>';
                    $trackable = ($threshold > 0 || $annotations || $chapters);
                } else {
                    echo '<p class="bhc-empty">' . esc_html__('This video needs Bunny Stream setup finished in Media &amp; CDN Setup.', 'bh-courses') . '</p>';
                }
            } elseif ($step['source'] === 'cloudflare_stream') {
                // Cloudflare Stream iframe embed; not trackable (cross-origin, no SDK).
                $stream_uid = preg_replace('/[^a-f0-9]/', '', (string) ($step['stream_uid'] ?? ''));
                if ($stream_uid) {
                    echo '<iframe class="bhc-step-video-embed" src="https://iframe.videodelivery.net/' . esc_attr($stream_uid) . '" style="border:none;" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;" allowfullscreen></iframe>';
                } else {
                    echo '<p class="bhc-empty">Cloudflare Stream video UID missing.</p>';
                }
            } else {
                // Plain external URL: Plyr provider, then a generic iframe embed, then a direct file.
                $url = $step['video_url'];
                $provider = self::to_plyr_provider($url);
                if ($provider) {
                    // YouTube/Vimeo as a Plyr-controlled player; trackable through the adapter.
                    echo '<div class="bhc-video-wrap"><div class="bhc-step-video bhc-step-video-provider"'
                        . ' data-plyr-provider="' . esc_attr($provider['provider']) . '"'
                        . ' data-plyr-embed-id="' . esc_attr($provider['id']) . '"'
                        . $threshold_attr . $annotations_attr . $chapters_attr . '></div></div>';
                    $trackable = true;
                } else {
                    $embed_url = self::to_embed_url($url);
                    if ($embed_url) {
                        // An unknown provider: an uncontrollable iframe, so video features are withheld.
                        echo '<iframe class="bhc-step-video-embed" src="' . esc_url($embed_url) . '" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>';
                    } else {
                        echo '<div class="bhc-video-wrap"><video class="bhc-step-video" controls preload="metadata" src="' . esc_url($url) . '"' . $threshold_attr . $annotations_attr . $chapters_attr . '></video></div>';
                        $trackable = true;
                    }
                }
            }
            if (!empty($step['caption'])) echo '<div class="bhc-step-caption bhc-step-text">' . wp_kses_post($step['caption']) . '</div>';

            $uid = get_current_user_id();
            $watched = ($uid && $trackable) ? BHC_Progress::watched_percent($uid, $lesson_id, $index) : 0;

            if ($threshold > 0 && $trackable) {
                // No manual button: courses.js auto-completes at the threshold. The gate shows
                // progress live and settles into a "watched" state rather than hiding.
                $pct = $is_done ? 100 : max(0, min(100, (int) $watched));
                echo '<div class="bhc-video-progress-note bhc-watch-gate' . ($is_done ? ' bhc-watch-gate--done' : '') . '" data-threshold="' . (int) $threshold . '">'
                    . '<div class="bhc-watch-gate-track"><span class="bhc-watch-gate-fill" style="width:' . $pct . '%;"></span></div>'
                    . '<p class="bhc-watch-gate-label"><span class="bhc-watch-gate-pct">' . $pct . '%</span> watched' . ($is_done ? '' : ' &middot; completes at ' . (int) $threshold . '%') . '</p>'
                    . '</div>';
                echo '<button type="button" class="bhc-btn bhc-mark-complete" style="display:' . ($is_done ? '' : 'none') . ';" disabled>Completed</button>';
            } else {
                echo '<button type="button" class="bhc-btn bhc-mark-complete"' . ($is_done ? ' disabled' : '') . '>' . ($is_done ? 'Completed' : 'Mark complete &amp; continue') . '</button>';
            }
        } elseif ($step['type'] === 'quiz') {
            $uid = get_current_user_id();
            $max_attempts = (int) ($step['max_attempts'] ?? 0);
            $attempts_used = $uid ? BHC_Progress::attempts($uid, $lesson_id, $index) : 0;
            $already_passed = $is_done; // a quiz row only reads "done" once passed
            $exhausted = !$already_passed && $max_attempts > 0 && $attempts_used >= $max_attempts;

            // A passed quiz renders its stored snapshot; rows without one fall back to a plain note.
            $snapshot = ($already_passed && $uid) ? BHC_Progress::stored_answers($uid, $lesson_id, $index) : null;

            if ($snapshot && !empty($snapshot['questions'])) {
                echo self::render_quiz_review($snapshot);
            } else {
                $disable_inputs = $exhausted || $already_passed;
                echo '<form class="bhc-quiz-form" data-max-attempts="' . $max_attempts . '" data-attempts-used="' . $attempts_used . '">';

                // Shuffle display order only; names/values keep the original indexes that
                // BHC_Steps::score_quiz() and courses.js read.
                $question_order = array_keys($step['questions']);
                if (!empty($step['shuffle_questions'])) shuffle($question_order);

                foreach ($question_order as $qi) {
                    $q = $step['questions'][$qi];
                    echo '<fieldset class="bhc-quiz-question" data-question-index="' . (int) $qi . '"><legend>' . esc_html($q['question']) . '</legend>';
                    $choice_order = array_keys($q['choices']);
                    if (!empty($step['shuffle_choices'])) shuffle($choice_order);
                    foreach ($choice_order as $ci) {
                        $choice = $q['choices'][$ci];
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
                // aria-live so the score is announced when courses.js fills it in.
                echo '<div class="bhc-quiz-result" role="status" aria-live="polite" style="display:' . ($exhausted && !$already_passed ? '' : 'none') . '">' . ($exhausted && !$already_passed ? 'No attempts remaining.' : '') . '</div>';
                echo '</form>';
            }
        } elseif ($step['type'] === 'resource') {
            // Non-blocking: downloading isn't required to advance.
            $url = wp_get_attachment_url($step['attachment_id']);
            $label = $step['label'] !== '' ? $step['label'] : basename(get_attached_file($step['attachment_id']) ?: 'Download');
            if ($url) {
                echo '<a class="bhc-btn bhc-resource-download" href="' . esc_url($url) . '" download>&#8681; ' . esc_html($label) . '</a>';
            } else {
                echo '<p class="bhc-empty">File not found.</p>';
            }
            if (!empty($step['description'])) echo '<div class="bhc-step-caption bhc-step-text">' . wp_kses_post($step['description']) . '</div>';
            echo '<button type="button" class="bhc-btn bhc-mark-complete"' . ($is_done ? ' disabled' : '') . '>' . ($is_done ? 'Completed' : 'Mark complete &amp; continue') . '</button>';
        } elseif ($step['type'] === 'callout') {
            $variant = in_array($step['variant'] ?? '', BHC_Steps::CALLOUT_VARIANTS, true) ? $step['variant'] : 'tip';
            echo '<div class="bhc-step-callout bhc-callout-' . esc_attr($variant) . '">' . wp_kses_post($step['content']) . '</div>';
            echo '<button type="button" class="bhc-btn bhc-mark-complete"' . ($is_done ? ' disabled' : '') . '>' . ($is_done ? 'Completed' : 'Mark complete &amp; continue') . '</button>';
        } elseif ($step['type'] === 'checklist') {
            // Non-blocking self-check; items aren't persisted, only step completion.
            echo '<div class="bhc-step-checklist">';
            if (!empty($step['title'])) echo '<p class="bhc-checklist-title">' . esc_html($step['title']) . '</p>';
            echo '<ul class="bhc-checklist-items">';
            foreach ((array) $step['items'] as $i => $item) {
                echo '<li><label><input type="checkbox" class="bhc-checklist-check" data-item-index="' . (int) $i . '"> ' . esc_html($item) . '</label></li>';
            }
            echo '</ul></div>';
            echo '<button type="button" class="bhc-btn bhc-mark-complete"' . ($is_done ? ' disabled' : '') . '>' . ($is_done ? 'Completed' : 'Mark complete &amp; continue') . '</button>';
        } elseif ($step['type'] === 'chord-chart') {
            // esc_html() inside <pre>: a chord chart's alignment is the content.
            if (!empty($step['title'])) echo '<p class="bhc-chord-chart-title">' . esc_html($step['title']) . '</p>';
            echo '<pre class="bhc-chord-chart-content">' . esc_html($step['content']) . '</pre>';
            echo '<button type="button" class="bhc-btn bhc-mark-complete"' . ($is_done ? ' disabled' : '') . '>' . ($is_done ? 'Completed' : 'Mark complete &amp; continue') . '</button>';
        } elseif ($step['type'] === 'audio-compare') {
            $url_a = wp_get_attachment_url($step['attachment_id_a']);
            $url_b = wp_get_attachment_url($step['attachment_id_b']);
            if ($url_a && $url_b) {
                echo '<p class="bhc-audio-compare-prompt">Play each and compare:</p>';
                echo '<div class="bhc-audio-compare-wrap">';
                echo '<div class="bhc-audio-compare-clip"><p class="bhc-audio-compare-label">' . esc_html($step['label_a']) . '</p>' . wp_audio_shortcode(['src' => $url_a]) . '</div>';
                echo '<div class="bhc-audio-compare-clip"><p class="bhc-audio-compare-label">' . esc_html($step['label_b']) . '</p>' . wp_audio_shortcode(['src' => $url_b]) . '</div>';
                echo '</div>';
            } else {
                echo '<p class="bhc-empty">One or both audio files not found.</p>';
            }
            if (!empty($step['caption'])) echo '<div class="bhc-step-caption bhc-step-text">' . wp_kses_post($step['caption']) . '</div>';
            echo '<button type="button" class="bhc-btn bhc-mark-complete"' . ($is_done ? ' disabled' : '') . '>' . ($is_done ? 'Completed' : 'Mark complete &amp; continue') . '</button>';
        }
        return ob_get_clean();
    }

    // Static breakdown of a stored quiz snapshot; courses.js mirrors it after a fresh submit.
    // WHY: correctness is shown only after the whole attempt is scored, so max_attempts
    // can't be gamed one question at a time.
    /** @param mixed $snapshot */
    public static function render_quiz_review($snapshot): string {
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
        // Same banner courses.js shows on a fresh pass.
        echo '<div class="bhc-quiz-result bhc-pass">';
        echo '<div class="bhc-quiz-pass-icon">&#10003;</div>';
        echo '<div class="bhc-datum bhc-quiz-pass-figure"><span class="bhc-datum-value">' . $score . '%</span><span class="bhc-datum-label">quiz passed</span></div>';
        echo '<p class="bhc-quiz-pass-sub">Nice work.</p>';
        echo '</div>';
        echo '</div>';
        return ob_get_clean();
    }
}
