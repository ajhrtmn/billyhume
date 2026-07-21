(function () {
    document.addEventListener('DOMContentLoaded', function () {
        // Catalog filter bar: works as a plain GET form with zero JS
        // (class-render.php's render_catalog_filters()) — this just
        // progressively enhances it so picking a category/topic/sort
        // submits immediately instead of waiting for the Filter button.
        // The search box deliberately does NOT auto-submit on keystroke
        // (that would refetch the whole catalog on every character).
        var filterForm = document.querySelector('.bhc-catalog-filters');
        if (filterForm) {
            filterForm.querySelectorAll('select').forEach(function (select) {
                select.addEventListener('change', function () { filterForm.submit(); });
            });
        }
    });

    // Course-page review form — lives OUTSIDE the .bhc-lesson-gated
    // block below (that whole block early-returns if .bhc-lesson isn't
    // on the page, which it never is on the course page itself, only
    // on a single lesson's own page) so this actually runs where the
    // review form is rendered.
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('.bhc-review-form');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var resultBox = form.querySelector('.bhc-review-form-result');
            var submitBtn = form.querySelector('button[type="submit"]');
            var rating = form.querySelector('input[name="rating"]:checked');
            if (!rating) {
                resultBox.textContent = 'Choose a star rating first.';
                return;
            }

            var originalLabel = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting…';
            resultBox.textContent = '';

            var body = new URLSearchParams({
                action: 'bhc_submit_review',
                nonce: BHCData.nonce,
                course_id: form.dataset.courseId,
                rating: rating.value,
                body: form.querySelector('.bhc-review-textarea').value,
            });

            fetch(BHCData.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalLabel;
                    if (!res.success) {
                        resultBox.textContent = (res.data && res.data.message) || 'Could not submit your review.';
                        return;
                    }
                    resultBox.textContent = res.data.message || 'Thanks for your review!';
                    submitBtn.textContent = 'Update review';
                    if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(res.data.message || 'Review submitted.', 'success'); }
                })
                .catch(function () {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalLabel;
                    resultBox.textContent = 'Could not reach the server — check your connection and try again.';
                });
        });
    });

    // Shared by two real trigger points: a fresh page load that lands
    // directly on the completion screen (server-rendered — see
    // class-render-lesson.php's bhc-completion block, e.g. revisiting
    // the course after finishing it elsewhere), AND the live, same-
    // session moment below where advance() reveals .bhc-lesson-next
    // right after the last step of the last lesson is marked complete —
    // by far the more common real path, and the one a first version of
    // this only fired for on page load, missing the actual live moment
    // entirely. window-scoped (not a module) to stay reachable from
    // both listeners without restructuring this whole IIFE.
    window.bhcFireConfetti = function (completion) {
        if (!completion || completion.dataset.bhcConfettiFired) return;
        completion.dataset.bhcConfettiFired = '1';
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        var colors = ['var(--bh-accent)', 'var(--bh-accent-soft)', 'var(--bh-text)'];
        for (var i = 0; i < 18; i++) {
            var piece = document.createElement('span');
            piece.className = 'bhc-confetti-piece';
            var angle = Math.random() * Math.PI * 2;
            var distance = 70 + Math.random() * 90;
            piece.style.setProperty('--bhc-confetti-x', (Math.cos(angle) * distance).toFixed(0) + 'px');
            piece.style.setProperty('--bhc-confetti-y', (Math.sin(angle) * distance + 40).toFixed(0) + 'px');
            piece.style.setProperty('--bhc-confetti-r', (Math.random() * 360).toFixed(0) + 'deg');
            piece.style.background = colors[i % colors.length];
            piece.style.animationDelay = (Math.random() * 0.15).toFixed(2) + 's';
            completion.appendChild(piece);
        }
        setTimeout(function () {
            completion.querySelectorAll('.bhc-confetti-piece').forEach(function (p) { p.remove(); });
        }, 1500);
    };

    document.addEventListener('DOMContentLoaded', function () {
        var completion = document.querySelector('.bhc-completion');
        if (completion && completion.offsetParent !== null) window.bhcFireConfetti(completion);
    });

    document.addEventListener('DOMContentLoaded', function () {
        var lesson = document.querySelector('.bhc-lesson');
        if (!lesson) return;

        var lessonId = lesson.dataset.lessonId;
        var stepCount = parseInt(lesson.dataset.stepCount, 10);

        function showStep(index) {
            lesson.querySelectorAll('.bhc-step').forEach(function (el) {
                var isTarget = parseInt(el.dataset.stepIndex, 10) === index;
                el.style.display = isTarget ? '' : 'none';
                el.classList.remove('bhc-step-entering');
                if (isTarget) {
                    // Force a reflow so re-adding the class retriggers the
                    // CSS animation even when this same step was shown
                    // before (e.g. navigating back and forward again).
                    void el.offsetWidth;
                    el.classList.add('bhc-step-entering');
                    // Move focus onto the newly-visible step (tabindex="-1"
                    // in class-render-lesson.php makes this focusable
                    // without joining the tab order) — previously focus
                    // stayed on whatever control was clicked to get here,
                    // which for a stepper-dot or Back click is OUTSIDE this
                    // div and could even be a now-hidden element, leaving a
                    // keyboard/screen-reader user with no orientation cue
                    // that the content under them just changed.
                    el.focus({ preventScroll: true });
                }
            });
            var counter = lesson.querySelector('.bhc-step-current');
            if (counter) counter.textContent = index + 1;
            lesson.querySelectorAll('.bhc-stepper-dot').forEach(function (dot) {
                var dotIndex = parseInt(dot.dataset.targetIndex, 10);
                dot.classList.toggle('bhc-stepper-current', dotIndex === index);
                dot.setAttribute('aria-current', dotIndex === index ? 'step' : 'false');
            });
        }

        // Marks a dot done and unlocks the next one — called wherever a
        // step is completed (button click, quiz pass, or the video
        // watch-threshold auto-complete below), so the stepper always
        // reflects real progress instead of drifting out of sync with
        // the .bhc-step-done class it mirrors.
        function markStepDone(index) {
            var dot = lesson.querySelector('.bhc-stepper-dot[data-target-index="' + index + '"]');
            if (dot) {
                dot.classList.add('bhc-stepper-done');
                // A brief pulse on the dot itself — the one small "that
                // counted" moment for finishing a step, not just a silent
                // class swap. Removed after the animation so it can
                // replay if this step is ever completed again.
                dot.classList.add('bhc-stepper-pulse');
                setTimeout(function () { dot.classList.remove('bhc-stepper-pulse'); }, 500);
            }
            var nextDot = lesson.querySelector('.bhc-stepper-dot[data-target-index="' + (index + 1) + '"]');
            if (nextDot) nextDot.disabled = false;
        }

        // Depth-of-magic beat: markStepDone()'s own stepper-dot pulse
        // (500ms) was previously cut short by advance() firing in the
        // very same tick, swapping the visible step content out from
        // under it before a student could actually register that a
        // step just completed — a silent snap to the next thing, not a
        // felt moment. A short real pause here (skipped entirely under
        // prefers-reduced-motion, same posture as the confetti/pulse
        // animations elsewhere in this file) is the whole fix: nothing
        // new to build, just letting the acknowledgment that already
        // exists actually be seen before the queue moves on.
        function advanceWithBeat(index) {
            var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (reduced) { advance(index); return; }
            setTimeout(function () { advance(index); }, 450);
        }

        function advance(fromIndex) {
            var next = fromIndex + 1;
            if (next < stepCount) {
                showStep(next);
            } else {
                // That was the last step — reveal the Next Lesson / course-
                // complete block instead of doing nothing (the pre-existing
                // behavior: advance() silently no-op'd past the end,
                // leaving a student stranded on the final step with no
                // way forward except manually finding the course page).
                lesson.querySelectorAll('.bhc-step').forEach(function (el) { el.style.display = 'none'; });
                var nextBlock = lesson.querySelector('.bhc-lesson-next');
                if (nextBlock) {
                    nextBlock.style.display = '';
                    nextBlock.classList.remove('bhc-step-entering');
                    void nextBlock.offsetWidth;
                    nextBlock.classList.add('bhc-step-entering');
                    // Same reasoning as showStep()'s own focus move — this
                    // block replaces the whole step area, so focus needs
                    // to land somewhere inside it (tabindex="-1" in
                    // class-render-lesson.php) rather than staying on the
                    // now-hidden final step's submit/mark-complete button.
                    nextBlock.focus({ preventScroll: true });
                    var completion = nextBlock.querySelector('.bhc-completion');
                    if (completion && window.bhcFireConfetti) window.bhcFireConfetti(completion);
                }
            }
        }

        // ROADMAP-ux-polish-and-feature-parity-2026-07.md 4b: real video
        // progress tracking. Only <video data-watch-threshold> elements
        // get a listener — class-render-lesson.php only renders that
        // attribute for a course-creator-configured, directly-trackable
        // (non-iframe) video step; everything else is untouched by this
        // block. Throttled to once per whole-percent change (a raw
        // timeupdate fires many times a second) to avoid hammering the
        // AJAX endpoint during normal playback.
        lesson.querySelectorAll('.bhc-step-video[data-watch-threshold]').forEach(function (video) {
            var step = video.closest('.bhc-step');
            var index = parseInt(step.dataset.stepIndex, 10);
            var lastSent = -1;

            function sendProgress(percent) {
                if (percent <= lastSent) return;
                lastSent = percent;
                var body = new URLSearchParams({
                    action: 'bhc_update_watch_progress',
                    nonce: BHCData.nonce,
                    lesson_id: lessonId,
                    step_index: index,
                    percent: percent,
                });
                fetch(BHCData.ajaxUrl, { method: 'POST', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success || !res.data.auto_completed) return;
                        var note = step.querySelector('.bhc-video-progress-note');
                        if (note) note.style.display = 'none';
                        var btn = step.querySelector('.bhc-mark-complete');
                        if (btn) { btn.disabled = true; btn.style.display = ''; btn.textContent = 'Completed'; }
                        step.classList.add('bhc-step-done');
                        if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show('Step complete.', 'success'); }
                        markStepDone(index);
                        advanceWithBeat(index);
                    });
            }

            video.addEventListener('timeupdate', function () {
                if (!video.duration || step.classList.contains('bhc-step-done')) return;
                sendProgress(Math.floor((video.currentTime / video.duration) * 100));
            });
        });

        lesson.addEventListener('click', function (e) {
            if (e.target.classList.contains('bhc-step-back')) {
                // Pure navigation — no server call, no completion state
                // touched. The target step's markup is already in the
                // DOM (rendered up front, just hidden), same as every
                // other step.
                var targetIndex = parseInt(e.target.dataset.targetIndex, 10);
                lesson.querySelectorAll('.bhc-step').forEach(function (el) { el.style.display = 'none'; });
                var nextBlock = lesson.querySelector('.bhc-lesson-next');
                if (nextBlock) nextBlock.style.display = 'none';
                showStep(targetIndex);
                return;
            }
            if (!e.target.classList.contains('bhc-mark-complete')) return;
            var step = e.target.closest('.bhc-step');
            var index = parseInt(step.dataset.stepIndex, 10);

            var body = new URLSearchParams({
                action: 'bhc_mark_complete',
                nonce: BHCData.nonce,
                lesson_id: lessonId,
                step_index: index,
            });

            // Retry-with-backoff, matching the reference pattern this
            // ecosystem's own reports flow set (own-ur-shit's
            // class-reports.php) — retry-audit pass, AJ's own standing
            // ask. Marking a step complete is idempotent (the server
            // side is an upsert on lesson_id+step_index, not an insert-
            // only log), so a real network blip retrying this is safe
            // in a way it would NOT be for, say, quiz submission —
            // this call previously had no .catch() at all, so a
            // dropped connection silently failed with zero feedback.
            e.target.disabled = true;
            var originalLabel = e.target.textContent;
            submitMarkComplete(0);
            function submitMarkComplete(attempt) {
                fetch(BHCData.ajaxUrl, { method: 'POST', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        // check_ajax_referer()'s default failure mode is
                        // wp_die(-1) — a bare "-1", not the {success:false,
                        // data:{...}} shape every real handler response
                        // has. That collapsed into the same generic
                        // "Something went wrong." as any other failure,
                        // when the real, actionable cause is a stale
                        // session/nonce (e.g. this tab sat open past a
                        // login timeout).
                        if (res === -1 || res === '-1') {
                            e.target.disabled = false;
                            e.target.textContent = originalLabel;
                            var expiredMsg = 'Your session has expired — refresh the page and log in again.';
                            if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(expiredMsg, 'error'); } else { alert(expiredMsg); }
                            return;
                        }
                        if (!res.success) {
                            e.target.disabled = false;
                            e.target.textContent = originalLabel;
                            var errMsg = (res.data && res.data.message) ? res.data.message : 'Something went wrong.';
                            // BHCoreToast (own-ur-shit core, loaded globally —
                            // see class-toast.php) is called directly here,
                            // not via the PHP-side OUS_Toast::queue() hand-off,
                            // because this is an AJAX flow with no redirect to
                            // hand a message across. typeof-guarded so this
                            // still degrades to the pre-existing alert() if
                            // own-ur-shit's toast script hasn't loaded for any
                            // reason (older core version, script blocked, etc.)
                            // — same "harmless no-op" posture as every other
                            // optional integration point in this ecosystem.
                            if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(errMsg, 'error'); } else { alert(errMsg); }
                            return;
                        }
                        e.target.textContent = 'Completed';
                        step.classList.add('bhc-step-done');
                        if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show('Step complete.', 'success'); }
                        markStepDone(index);
                        advanceWithBeat(index);
                    })
                    .catch(function () {
                        if (attempt < 2) {
                            e.target.textContent = 'Retrying…';
                            setTimeout(function () { submitMarkComplete(attempt + 1); }, 500 * Math.pow(2, attempt) + Math.random() * 200);
                            return;
                        }
                        e.target.disabled = false;
                        e.target.textContent = originalLabel;
                        var msg = 'Could not reach the server — check your connection and try again.';
                        if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show(msg, 'error'); } else { alert(msg); }
                    });
            }
        });

        lesson.addEventListener('click', function (e) {
            if (!e.target.classList.contains('bhc-stepper-dot') || e.target.disabled) return;
            var targetIndex = parseInt(e.target.dataset.targetIndex, 10);
            lesson.querySelectorAll('.bhc-step').forEach(function (el) { el.style.display = 'none'; });
            var nextBlock = lesson.querySelector('.bhc-lesson-next');
            if (nextBlock) nextBlock.style.display = 'none';
            showStep(targetIndex);
        });

        // Builds the same per-question breakdown markup/classes
        // BHC_Render::render_quiz_review() renders server-side for a
        // revisited passed quiz — kept in sync deliberately so the
        // immediate post-submit view and the later review view look
        // identical, not two different quiz UIs. This is the (B)
        // end-of-submission breakdown (QUIZ-AND-CATALOG-DESIGN-PLAN.md
        // Part 1.5), never revealed per-question before the whole quiz
        // is submitted.
        function renderQuestionBreakdown(questions) {
            if (!questions || !questions.length) return '';
            var html = '';
            questions.forEach(function (q) {
                var chosen = typeof q.chosen_index === 'number' ? q.chosen_index : -1;
                var correctIndex = typeof q.correct_index === 'number' ? q.correct_index : -1;
                var qCorrect = chosen === correctIndex;
                html += '<fieldset class="bhc-quiz-question bhc-quiz-question-review ' + (qCorrect ? 'bhc-q-correct' : 'bhc-q-incorrect') + '"><legend>' + escapeHtml(q.q || '') + '</legend>';
                (q.choices || []).forEach(function (choice, ci) {
                    var classes = ['bhc-quiz-choice', 'bhc-quiz-choice-review'];
                    if (ci === correctIndex) classes.push('bhc-correct');
                    if (ci === chosen && !qCorrect) classes.push('bhc-choice-incorrect');
                    var marker = '';
                    if (ci === correctIndex) marker = ' <span class="bhc-choice-marker">&#10003; Correct answer</span>';
                    else if (ci === chosen) marker = ' <span class="bhc-choice-marker">&#10007; Your answer</span>';
                    html += '<div class="' + classes.join(' ') + '"><span class="bhc-choice-text">' + escapeHtml(choice) + '</span>' + marker + '</div>';
                });
                html += '</fieldset>';
            });
            return html;
        }

        function escapeHtml(s) {
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        // Selected-answer highlight was CSS-only (:has(input:checked)),
        // which only reached universal browser support in late 2022/2023
        // (older Firefox in particular) — on anything without :has()
        // support, clicking a quiz choice showed zero visible feedback.
        // This JS-added class is a belt-and-suspenders fallback; the
        // :has() CSS rule stays as the primary/instant-paint path.
        lesson.addEventListener('change', function (e) {
            if (!e.target.matches('.bhc-quiz-choice input[type=radio]')) return;
            var fieldset = e.target.closest('.bhc-quiz-question');
            if (!fieldset) return;
            fieldset.querySelectorAll('.bhc-quiz-choice').forEach(function (label) {
                label.classList.remove('bhc-selected');
            });
            e.target.closest('.bhc-quiz-choice').classList.add('bhc-selected');
        });

        lesson.addEventListener('submit', function (e) {
            if (!e.target.classList.contains('bhc-quiz-form')) return;
            e.preventDefault();
            var step = e.target.closest('.bhc-step');
            var index = parseInt(step.dataset.stepIndex, 10);

            // Retry-audit pass, AJ's own standing ask: quiz submission
            // is explicitly NOT safe to blind-retry — the server side
            // burns a real attempt (max_attempts) per call, so a retry
            // (or, before this fix, a double-click / accidental double
            // submit on a slow connection) could cost a student an
            // attempt for a request that actually succeeded. The fix
            // here is the opposite of retry: disable the button up
            // front so a second submit physically can't fire while the
            // first is in flight, and only re-enable on a real failure
            // (never on success, since a successful submit already
            // shows results/locks the form below).
            var quizSubmitBtn = e.target.querySelector('button[type="submit"]');
            var quizSubmitLabel = quizSubmitBtn ? quizSubmitBtn.textContent : '';
            // A label change, not just disabled=true — a disabled state
            // alone isn't reliably announced by every screen reader, so
            // this was a silent "did my click even register?" moment for
            // AT users on a slow connection, same category of gap fixed
            // elsewhere in this ecosystem's other submit buttons.
            if (quizSubmitBtn) { quizSubmitBtn.disabled = true; quizSubmitBtn.textContent = 'Submitting…'; }

            var body = new URLSearchParams({ action: 'bhc_submit_quiz', nonce: BHCData.nonce, lesson_id: lessonId, step_index: index });
            var formData = new FormData(e.target);
            for (var pair of formData.entries()) {
                body.append('answers[' + pair[0].replace('q', '') + ']', pair[1]);
            }

            fetch(BHCData.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    var resultBox = e.target.querySelector('.bhc-quiz-result');
                    if (!res.success) {
                        if (quizSubmitBtn) { quizSubmitBtn.disabled = false; quizSubmitBtn.textContent = quizSubmitLabel; }
                        // Attempts-exhausted (403) comes back as an error with
                        // attempts_used/max_attempts rather than a generic
                        // failure — show it inline and lock the form instead
                        // of just alert()-ing a dead end.
                        var data = res.data || {};
                        resultBox.style.display = '';
                        resultBox.className = 'bhc-quiz-result bhc-fail';
                        resultBox.textContent = data.message || 'Something went wrong.';
                        if (data.max_attempts) {
                            e.target.querySelectorAll('input, button[type="submit"]').forEach(function (el) { el.disabled = true; });
                        }
                        return;
                    }
                    var result = res.data;
                    resultBox.style.display = '';
                    resultBox.className = 'bhc-quiz-result ' + (result.passed ? 'bhc-pass' : 'bhc-fail');
                    var attemptsNote = '';
                    if (result.max_attempts) {
                        attemptsNote = result.passed ? '' : (result.attempts_remaining > 0 ? ' (' + result.attempts_remaining + ' attempt' + (result.attempts_remaining === 1 ? '' : 's') + ' remaining)' : ' (no attempts remaining)');
                    }
                    // result.correct is deliberately null on an already-
                    // passed replay (class-progress.php's ajax_submit_quiz()
                    // doesn't recompute a count it already knows the
                    // answer to) — the literal string "null" rendering
                    // inline was a real, live bug, not hypothetical: any
                    // replayed/duplicate submit against an already-passed
                    // quiz hit this. Re-derived from score/total rather
                    // than assumed to equal total — a pass doesn't mean
                    // every question was right, just that score cleared
                    // the passing threshold (BHC_Steps::score_quiz()'s own
                    // score = round(correct/total*100), inverted here).
                    var correctCount = (result.correct === null || result.correct === undefined)
                        ? Math.round((result.score / 100) * result.total)
                        : result.correct;

                    // Checkpoint framing, not a form-validation message —
                    // depth-of-magic pass: quizzes should read as a real
                    // moment in the course's own story, not a bare score
                    // line. When retries are genuinely exhausted, name
                    // the SPECIFIC missed questions (real data already
                    // in result.questions — chosen_index vs. correct_index)
                    // instead of a flat "no attempts remaining" dead end,
                    // so a student out of retries at least knows exactly
                    // what to go back and re-read.
                    var attemptsExhausted = !result.passed && result.max_attempts && result.attempts_remaining === 0;
                    if (result.passed) {
                        resultBox.textContent = 'You\'ve got this — ' + correctCount + '/' + result.total + ' correct. Nice work.';
                    } else if (attemptsExhausted) {
                        var missedTopics = (result.questions || [])
                            .filter(function (q) { return q.chosen_index !== q.correct_index; })
                            .map(function (q) { return q.q; })
                            .filter(Boolean);
                        resultBox.textContent = missedTopics.length
                            ? 'Not this time — ' + correctCount + '/' + result.total + ' correct, and out of attempts. Before moving on, go back and review: ' + missedTopics.join('; ') + '.'
                            : 'Not this time — ' + correctCount + '/' + result.total + ' correct, and out of attempts. Review the lesson before moving on.';
                    } else {
                        resultBox.textContent = correctCount + '/' + result.total + ' correct — not quite there yet.' + attemptsNote + ' Take another look and give it another shot.';
                    }

                    // The real per-question breakdown, once — every choice
                    // marked correct/incorrect. Lock the inputs so the
                    // marked-up choices read as a review, not an editable
                    // form still sitting under the breakdown.
                    if (result.questions && result.questions.length) {
                        e.target.querySelectorAll('.bhc-quiz-question').forEach(function (fs) { fs.style.display = 'none'; });
                        var breakdown = document.createElement('div');
                        breakdown.className = 'bhc-quiz-review';
                        breakdown.innerHTML = renderQuestionBreakdown(result.questions);
                        e.target.insertBefore(breakdown, resultBox);
                    }
                    e.target.querySelectorAll('input').forEach(function (el) { el.disabled = true; });

                    step.classList.add('bhc-step-done');
                    markStepDone(index);
                    if (result.passed) {
                        // Do NOT auto-advance here — the whole point of
                        // the breakdown above is for the student to
                        // actually see it. The old behavior (immediate
                        // advance() on pass) would hide it again before
                        // anyone could read it. A real "Continue" click
                        // is required instead, same as every other step
                        // type's "Mark complete & continue" button.
                        var submitBtn = e.target.querySelector('.bhc-submit-quiz');
                        if (submitBtn) submitBtn.style.display = 'none';
                        var continueBtn = document.createElement('button');
                        continueBtn.type = 'button';
                        continueBtn.className = 'bhc-btn bhc-quiz-continue';
                        continueBtn.textContent = 'Continue';
                        continueBtn.addEventListener('click', function () { advance(index); });
                        e.target.appendChild(continueBtn);
                    } else if (result.max_attempts && result.attempts_remaining === 0) {
                        e.target.querySelectorAll('input, button[type="submit"]').forEach(function (el) { el.disabled = true; });
                    }
                })
                .catch(function () {
                    // Deliberately NO retry here (unlike mark-complete/
                    // subtask-save/judge-score above) — quiz submission
                    // burns a real attempt server-side per call, so a
                    // blind retry after a request that actually
                    // succeeded (e.g. the response just failed to come
                    // back) could cost a student an attempt for nothing.
                    // Re-enabling the button lets them submit again
                    // deliberately, once, rather than the code guessing.
                    if (quizSubmitBtn) { quizSubmitBtn.disabled = false; quizSubmitBtn.textContent = quizSubmitLabel; }
                    var resultBox = e.target.querySelector('.bhc-quiz-result');
                    if (resultBox) {
                        resultBox.style.display = '';
                        resultBox.className = 'bhc-quiz-result bhc-fail';
                        resultBox.textContent = 'Could not reach the server — check your connection and submit again.';
                    }
                });
        });
    });
})();
