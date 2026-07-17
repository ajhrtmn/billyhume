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

    document.addEventListener('DOMContentLoaded', function () {
        var lesson = document.querySelector('.bhc-lesson');
        if (!lesson) return;

        var lessonId = lesson.dataset.lessonId;
        var stepCount = parseInt(lesson.dataset.stepCount, 10);

        function showStep(index) {
            lesson.querySelectorAll('.bhc-step').forEach(function (el) {
                el.style.display = (parseInt(el.dataset.stepIndex, 10) === index) ? '' : 'none';
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
            if (dot) dot.classList.add('bhc-stepper-done');
            var nextDot = lesson.querySelector('.bhc-stepper-dot[data-target-index="' + (index + 1) + '"]');
            if (nextDot) nextDot.disabled = false;
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
                if (nextBlock) nextBlock.style.display = '';
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
                        advance(index);
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
            fetch(BHCData.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) {
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
                    e.target.disabled = true;
                    e.target.textContent = 'Completed';
                    step.classList.add('bhc-step-done');
                    if (typeof BHCoreToast !== 'undefined') { BHCoreToast.show('Step complete.', 'success'); }
                    markStepDone(index);
                    advance(index);
                });
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

        lesson.addEventListener('submit', function (e) {
            if (!e.target.classList.contains('bhc-quiz-form')) return;
            e.preventDefault();
            var step = e.target.closest('.bhc-step');
            var index = parseInt(step.dataset.stepIndex, 10);

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
                    resultBox.textContent = 'Score: ' + result.score + '% (' + correctCount + '/' + result.total + ' correct) — ' + (result.passed ? 'Passed!' : 'Not quite.' + attemptsNote);

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
                });
        });
    });
})();
