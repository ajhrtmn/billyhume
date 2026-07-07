(function () {
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
        }

        function advance(fromIndex) {
            var next = fromIndex + 1;
            if (next < stepCount) showStep(next);
        }

        lesson.addEventListener('click', function (e) {
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
                    if (!res.success) { alert(res.data && res.data.message ? res.data.message : 'Something went wrong.'); return; }
                    e.target.disabled = true;
                    e.target.textContent = 'Completed';
                    step.classList.add('bhc-step-done');
                    advance(index);
                });
        });

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
                    resultBox.textContent = 'Score: ' + result.score + '% (' + result.correct + '/' + result.total + ' correct) — ' + (result.passed ? 'Passed!' : 'Not quite.' + attemptsNote);
                    step.classList.add('bhc-step-done');
                    if (result.passed) {
                        advance(index);
                    } else if (result.max_attempts && result.attempts_remaining === 0) {
                        e.target.querySelectorAll('input, button[type="submit"]').forEach(function (el) { el.disabled = true; });
                    }
                });
        });
    });
})();
