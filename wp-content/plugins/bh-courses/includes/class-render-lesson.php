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
    public static function render_lesson_steps(int $lesson_id): string {
        $uid = get_current_user_id();
        $course_id = BHC_PostTypes::course_for_lesson($lesson_id);

        // Same login-vs-tier split as BHC_Render_Course::render_course()
        // — a deep-linked lesson permalink was the OTHER real way a
        // logged-out visitor could reach real course content directly,
        // bypassing the course page's own gate entirely.
        if ($course_id && !$uid && !OUS_Visibility::is_public($course_id)) {
            return OUS_Visibility::render_login_notice(__('Log in to view this lesson.', 'bh-courses'));
        }
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

        // alignwide is the theme's own opt-in mechanism (theme.json's
        // wideSize, 1340px in Twenty Twenty-Five) for a block that wants
        // more than the default constrained content width (645px) — this
        // layout is a direct child of .entry-content.is-layout-constrained,
        // so it qualifies for the same core-generated CSS rule real
        // Gutenberg blocks use, without this plugin needing to fight the
        // theme's own width constraint with a bespoke override.
        echo '<div class="bhc-lesson-layout alignwide">';
        // Persistent lesson list + overall progress, so a student can jump
        // to any earlier lesson or see how much of the course is left
        // without leaving this page — previously the only course-level
        // context here was the one-line breadcrumb above.
        if ($course_id && class_exists('BHC_Render_Course')) {
            echo BHC_Render_Course::render_lesson_sidebar($course_id, $uid, $lesson_id);
        }
        echo '<div class="bhc-lesson-main">';

        echo '<div class="bhc-lesson" data-lesson-id="' . (int) $lesson_id . '" data-step-count="' . count($steps) . '" data-start-index="' . (int) $start_index . '">';
        // aria-live so a screen-reader user is told "Step 2 of 5" the
        // moment courses.js's showStep() updates the counter text below
        // — previously this only ever changed silently, the only signal
        // a step actually advanced was the (also silent-to-AT) visual
        // swap of which .bhc-step div was hidden/shown.
        echo '<div class="bhc-step-progress" role="status" aria-live="polite">Step <span class="bhc-step-current">' . ($start_index + 1) . '</span> of ' . count($steps) . '</div>';

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
            // tabindex="-1": not in the normal tab order (a visible step
            // is already reachable through its own controls), but
            // courses.js's showStep() can still move focus onto it
            // programmatically when a step change happens via a control
            // OUTSIDE this div (e.g. a stepper dot) — without this, focus
            // stayed on the now-hidden previous step's last-clicked
            // element, orienting nobody using a keyboard or screen reader.
            echo '<div class="bhc-step bhc-step-' . esc_attr($step['type']) . ($is_done ? ' bhc-step-done' : '') . '" data-step-index="' . (int) $i . '" tabindex="-1"' . $visible . '>';
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
        echo '<div class="bhc-lesson-next" tabindex="-1" style="display:none;">';
        if ($next_lesson_id) {
            // A step up from a plain step's "mark complete" but a step
            // down from the full course-completion moment below — this
            // happens every lesson, not just once per course, so it
            // gets a real filled banner (weight) without the gradient/
            // confetti treatment .bhc-completion reserves for the rarer,
            // bigger moment. The banner comes FIRST here (the payoff),
            // with both actions grouped in a row underneath it — audit
            // fix: "Back to lesson" used to render before the banner,
            // landing as an orphaned button sitting above an otherwise
            // empty gap instead of after the moment it's reacting to.
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
            // The real "payoff" moment, fired the instant the final step
            // of the final lesson completes. Delegates to the shared
            // BHC_Render_Course::render_completion_screen() (stats,
            // certificate/share-card centerpiece, distinction tier, next
            // steps) so this moment and the course page's own completion
            // state — reached later, e.g. a student revisiting after
            // finishing — are the exact same rich content, not two
            // independently-maintained versions that could drift apart.
            if ($uid && class_exists('BHC_Render_Course')) {
                echo BHC_Render_Course::render_completion_screen($uid, $course_id, true);
            }
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

        echo '</div>'; // .bhc-lesson
        echo '</div>'; // .bhc-lesson-main
        echo '</div>'; // .bhc-lesson-layout
        return ob_get_clean();
    }

    // Real bug: pasting a normal YouTube
    // "watch" link (youtube.com/watch?v=ID, youtu.be/ID, or a Shorts
    // link) or a plain vimeo.com/ID link — the single most likely thing
    // an author actually pastes, since that's just the URL from their
    // browser's address bar — matched NEITHER the old iframe/embed/
    // player substring check NOR a real direct-file URL, so it silently
    // rendered a broken, unplayable <video> tag pointed at an HTML page.
    // Recognized platforms convert to their real embeddable URL first;
    // anything else still falls through to the old substring heuristic,
    // then to "treat as a direct file" as the final fallback.
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
     * A YouTube/Vimeo URL resolved to the provider + bare video ID Plyr
     * needs for a real, controllable embed (data-plyr-provider /
     * data-plyr-embed-id) rather than a raw, opaque <iframe>.
     *
     * This is what lifts the old "chapters/annotations/watch-progress
     * only work on a same-origin <video> tag" limitation for these two
     * providers: Plyr loads YouTube's or Vimeo's own player SDK and
     * exposes the SAME instance API (currentTime, duration, play,
     * 'timeupdate') it does for an HTML5 video, so courses.ts drives all
     * three features through one adapter with no provider-specific code
     * of its own. The trade-off is real and deliberate (AJ's explicit
     * call): controlling a YouTube/Vimeo embed is impossible without
     * loading that provider's script, so these two step types do reach a
     * third-party origin — unlike every other asset in this ecosystem.
     * An author who wants zero third-party contact still has the
     * uploaded-file and direct-URL sources, which stay fully local.
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
            // ROADMAP-lms-v3.md Section 1 — same trackable-<video>-tag-
            // only constraint as watch_threshold above (a cross-origin
            // iframe embed can't be paused/resumed by our own JS), so
            // this is only ever emitted alongside a real <video> tag,
            // never the iframe branch below.
            $annotations = (array) ($step['annotations'] ?? []);
            $annotations_attr = $annotations ? ' data-annotations="' . esc_attr(wp_json_encode($annotations)) . '"' : '';
            // YouTube-style chapters — same trackable-<video>-tag-only
            // constraint as watch_threshold/annotations above (a
            // cross-origin iframe embed can't be seeked by our own JS),
            // so this is only ever emitted alongside a real <video> tag.
            // Sorted by time here once, server-side, rather than trusting
            // whatever order an author happened to add rows in the
            // editor — courses.js's strip/list rendering assumes
            // ascending order and would otherwise need to re-sort itself
            // (or worse, silently render a nonsensical strip) if this
            // shipped unsorted.
            $chapters = (array) ($step['chapters'] ?? []);
            usort($chapters, function ($a, $b) { return ($a['time'] ?? 0) <=> ($b['time'] ?? 0); });
            $chapters_attr = $chapters ? ' data-chapters="' . esc_attr(wp_json_encode(array_values($chapters))) . '"' : '';
            if ($step['source'] === 'upload') {
                // wp_get_attachment_url() is the one API surface an
                // offload plugin (see The Self-Hosted Self's dashboard entry for
                // Advanced Media Offloader) rewrites transparently —
                // this plain <video> tag needs zero changes whether the
                // file is on this server's disk or Cloudflare R2.
                $url = wp_get_attachment_url($step['attachment_id']);
                if ($url) {
                    // The wrapper is only needed to give annotation
                    // overlays (courses.js) a positioned parent to sit
                    // over the video frame — harmless either way when
                    // there are no annotations.
                    echo '<div class="bhc-video-wrap"><video class="bhc-step-video" controls preload="metadata" src="' . esc_url($url) . '"' . $threshold_attr . $annotations_attr . $chapters_attr . '></video></div>';
                    $trackable = true;
                } else {
                    echo '<p class="bhc-empty">Video file not found.</p>';
                }
            } elseif ($step['source'] === 'cloudflare_stream') {
                // OSS-integration master plan Phase 6 follow-up: Tier B
                // wired into a real content type. Cloudflare Stream's own
                // iframe embed (https://iframe.videodelivery.net/{uid}) —
                // deliberately the simple, zero-extra-JS first cut over an
                // hls.js-backed <video>, matching this session's "ship
                // the simple thing first" precedent; an hls.js path
                // (OUS_MediaWizard::enqueue_hls_js(), already available)
                // can follow once this is proven. Not trackable the same
                // way an upload/direct-URL <video> is — same cross-origin-
                // iframe constraint watch_threshold/annotations already
                // document above, so neither is emitted here.
                $stream_uid = preg_replace('/[^a-f0-9]/', '', (string) ($step['stream_uid'] ?? ''));
                if ($stream_uid) {
                    echo '<iframe class="bhc-step-video-embed" src="https://iframe.videodelivery.net/' . esc_attr($stream_uid) . '" style="border:none;" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;" allowfullscreen></iframe>';
                } else {
                    echo '<p class="bhc-empty">Cloudflare Stream video UID missing.</p>';
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
                $provider = self::to_plyr_provider($url);
                if ($provider) {
                    // YouTube/Vimeo as a REAL controllable player rather
                    // than an opaque <iframe>: Plyr mounts the provider's
                    // own SDK against this div and exposes the same
                    // instance API (currentTime/duration/play/'timeupdate')
                    // it does for an HTML5 <video>. That's what makes
                    // chapters, overlays and watch-progress work here at
                    // all — the constraint this file's own comment above
                    // used to describe as unfixable. Marked $trackable
                    // for the same reason: courses.ts drives it through
                    // one media adapter, so the progress note and
                    // auto-complete behave identically to an upload.
                    echo '<div class="bhc-video-wrap"><div class="bhc-step-video bhc-step-video-provider"'
                        . ' data-plyr-provider="' . esc_attr($provider['provider']) . '"'
                        . ' data-plyr-embed-id="' . esc_attr($provider['id']) . '"'
                        . $threshold_attr . $annotations_attr . $chapters_attr . '></div></div>';
                    $trackable = true;
                } else {
                    $embed_url = self::to_embed_url($url);
                    if ($embed_url) {
                        // A provider this plugin has no SDK for — still a
                        // plain, uncontrollable iframe, and still honestly
                        // not trackable. Chapters/overlays/watch-progress
                        // are all withheld rather than rendered as
                        // controls that would silently do nothing.
                        echo '<iframe class="bhc-step-video-embed" src="' . esc_url($embed_url) . '" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>';
                    } else {
                        echo '<div class="bhc-video-wrap"><video class="bhc-step-video" controls preload="metadata" src="' . esc_url($url) . '"' . $threshold_attr . $annotations_attr . $chapters_attr . '></video></div>';
                        $trackable = true;
                    }
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

                // Shuffle DISPLAY order only — every field's name/value
                // stays tied to the question/choice's ORIGINAL array
                // index (data-question-index, the "q{n}" field name, and
                // each radio's value), the same indices
                // BHC_Steps::score_quiz() and courses.js's FormData
                // parsing already read. That's what makes this purely a
                // rendering concern: shuffle the KEY ORDER walked below,
                // never the data itself, so scoring needed zero changes.
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
                // role="status"/aria-live so the pass/fail score is
                // actually announced to a screen reader the moment
                // courses.js fills it in on submit — previously a sighted
                // student saw the result appear; a screen-reader user
                // heard nothing unless they went looking for it.
                echo '<div class="bhc-quiz-result" role="status" aria-live="polite" style="display:' . ($exhausted && !$already_passed ? '' : 'none') . '">' . ($exhausted && !$already_passed ? 'No attempts remaining.' : '') . '</div>';
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
        } elseif ($step['type'] === 'callout') {
            $variant = in_array($step['variant'] ?? '', BHC_Steps::CALLOUT_VARIANTS, true) ? $step['variant'] : 'tip';
            echo '<div class="bhc-step-callout bhc-callout-' . esc_attr($variant) . '">' . wp_kses_post($step['content']) . '</div>';
            echo '<button type="button" class="bhc-btn bhc-mark-complete"' . ($is_done ? ' disabled' : '') . '>' . ($is_done ? 'Completed' : 'Mark complete &amp; continue') . '</button>';
        } elseif ($step['type'] === 'checklist') {
            // Non-blocking, same as every other non-quiz step — checking
            // items is a self-check for the student's own benefit, never
            // required to advance (client-side only, nothing persisted
            // per item; the step's own completion is what's tracked,
            // exactly like a text step).
            echo '<div class="bhc-step-checklist">';
            if (!empty($step['title'])) echo '<p class="bhc-checklist-title">' . esc_html($step['title']) . '</p>';
            echo '<ul class="bhc-checklist-items">';
            foreach ((array) $step['items'] as $i => $item) {
                echo '<li><label><input type="checkbox" class="bhc-checklist-check" data-item-index="' . (int) $i . '"> ' . esc_html($item) . '</label></li>';
            }
            echo '</ul></div>';
            echo '<button type="button" class="bhc-btn bhc-mark-complete"' . ($is_done ? ' disabled' : '') . '>' . ($is_done ? 'Completed' : 'Mark complete &amp; continue') . '</button>';
        } elseif ($step['type'] === 'chord-chart') {
            // Plain text, not HTML — see class-content-bridge.php's own
            // comment on why this is esc_html() inside a <pre>, not
            // wp_kses_post(): a chord chart's alignment IS the content.
            if (!empty($step['title'])) echo '<p class="bhc-chord-chart-title">' . esc_html($step['title']) . '</p>';
            echo '<pre class="bhc-chord-chart-content">' . esc_html($step['content']) . '</pre>';
            echo '<button type="button" class="bhc-btn bhc-mark-complete"' . ($is_done ? ' disabled' : '') . '>' . ($is_done ? 'Completed' : 'Mark complete &amp; continue') . '</button>';
        } elseif ($step['type'] === 'audio-compare') {
            $url_a = wp_get_attachment_url($step['attachment_id_a']);
            $url_b = wp_get_attachment_url($step['attachment_id_b']);
            if ($url_a && $url_b) {
                // Audit fix (2026-07-25): the two clips previously had no
                // explicit "compare these" framing — just two stacked
                // players with no cue that the point is to A/B them.
                echo '<p class="bhc-audio-compare-prompt">Play each and compare:</p>';
                echo '<div class="bhc-audio-compare-wrap">';
                echo '<div class="bhc-audio-compare-clip"><p class="bhc-audio-compare-label">' . esc_html($step['label_a']) . '</p>' . wp_audio_shortcode(['src' => $url_a]) . '</div>';
                echo '<div class="bhc-audio-compare-clip"><p class="bhc-audio-compare-label">' . esc_html($step['label_b']) . '</p>' . wp_audio_shortcode(['src' => $url_b]) . '</div>';
                echo '</div>';
            } else {
                echo '<p class="bhc-empty">One or both audio files not found.</p>';
            }
            if (!empty($step['caption'])) echo '<p class="bhc-step-caption">' . esc_html($step['caption']) . '</p>';
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
        // Same escalated banner courses.js writes into .bhc-quiz-result
        // on a fresh live pass (see courses.js's quiz-submit handler) —
        // a student revisiting an old pass sees the identical moment,
        // not a plainer server-rendered version of it.
        echo '<div class="bhc-quiz-result bhc-pass">';
        echo '<div class="bhc-quiz-pass-icon">&#10003;</div>';
        echo '<p class="bhc-quiz-pass-score">Quiz passed &mdash; ' . $score . '%.</p>';
        echo '<p class="bhc-quiz-pass-sub">Nice work.</p>';
        echo '</div>';
        echo '</div>';
        return ob_get_clean();
    }
}
