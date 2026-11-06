"use strict";
/**
 * Front-end lesson stepper: steps, quizzes, video progress, overlays, chapters.
 * Non-null assertions (`!`) mark DOM lookups the server-rendered structure guarantees.
 */
/* ---- lesson step DOM helpers ----------------------------------------
 * Module-scoped so tests/unit/lesson-step-dom.test.ts can reach them via
 * window.BHCLessonStepDom. WHY: the live Etch theme blanks class="" off step
 * wrappers, so these key on data-step-* attrs (ETCH-COMPATIBILITY-NOTES.md).
 * ------------------------------------------------------------------- */
var BHC_STEP_SELECTOR = '.bhc-step, [data-step-index]';
function bhcStepClassName(type, done) {
    return 'bhc-step' + (type ? ' bhc-step-' + type : '') + (done ? ' bhc-step-done' : '');
}
/** Rebuilds the wrapper's class from data-step-* attrs if `.bhc-step` went missing. */
function bhcReassertStepClass(el) {
    if (el.classList.contains('bhc-step'))
        return;
    el.className = bhcStepClassName(el.dataset.stepType || '', !!el.dataset.stepDone);
}
/** Shows only the step at `index`; returns that index, or -1 if none matched. */
function bhcSetVisibleStep(root, index) {
    var shown = -1;
    root.querySelectorAll(BHC_STEP_SELECTOR).forEach(function (el) {
        var _a;
        var isTarget = parseInt((_a = el.dataset.stepIndex) !== null && _a !== void 0 ? _a : '-1', 10) === index;
        el.style.display = isTarget ? '' : 'none';
        if (isTarget)
            shown = index;
    });
    return shown;
}
// Exposed for the unit runner.
window.BHCLessonStepDom = {
    SELECTOR: BHC_STEP_SELECTOR,
    className: bhcStepClassName,
    reassert: bhcReassertStepClass,
    setVisible: bhcSetVisibleStep,
};
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        // Progressive enhancement of the GET filter form: selects submit on change.
        // The search box deliberately doesn't (it would refetch on every keystroke).
        var filterForm = document.querySelector('.bhc-catalog-filters');
        if (filterForm) {
            filterForm.querySelectorAll('select').forEach(function (select) {
                select.addEventListener('change', function () { filterForm.submit(); });
            });
        }
    });
    // Course-page review form; outside the .bhc-lesson block because that early-returns on the course page.
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('.bhc-review-form');
        if (!form)
            return;
        form.addEventListener('submit', function (e) {
            var _a;
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
                course_id: (_a = form.dataset.courseId) !== null && _a !== void 0 ? _a : '',
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
                resultBox.textContent = (res.data && res.data.message) || 'Thanks for your review!';
                submitBtn.textContent = 'Update review';
                if (typeof BHCoreToast !== 'undefined') {
                    BHCoreToast.show((res.data && res.data.message) || 'Review submitted.', 'success');
                }
            })
                .catch(function () {
                submitBtn.disabled = false;
                submitBtn.textContent = originalLabel;
                resultBox.textContent = 'Could not reach the server — check your connection and try again.';
            });
        });
    });
    // Fires on page load onto a completion screen and on the live last-step moment in advance().
    // opts scales it down for the per-lesson banner; defaults are the course-completion burst.
    window.bhcFireConfetti = function (completion, opts) {
        if (!completion || completion.dataset.bhcConfettiFired)
            return;
        completion.dataset.bhcConfettiFired = '1';
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)
            return;
        opts = opts || {};
        var count = opts.count || 18;
        var minDistance = opts.minDistance || 70;
        var distanceSpread = opts.distanceSpread || 90;
        var colors = ['var(--bh-accent)', 'var(--bh-accent-soft)', 'var(--bh-text)'];
        for (var i = 0; i < count; i++) {
            var piece = document.createElement('span');
            piece.className = 'bhc-confetti-piece';
            var angle = Math.random() * Math.PI * 2;
            var distance = minDistance + Math.random() * distanceSpread;
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
        if (completion && completion.offsetParent !== null)
            window.bhcFireConfetti(completion);
    });
    document.addEventListener('DOMContentLoaded', function () {
        var _a, _b;
        var lesson = document.querySelector('.bhc-lesson');
        if (!lesson)
            return;
        var lessonId = (_a = lesson.dataset.lessonId) !== null && _a !== void 0 ? _a : '';
        var stepCount = parseInt((_b = lesson.dataset.stepCount) !== null && _b !== void 0 ? _b : '0', 10);
        // WHY: the live Etch site's hydration blanks step wrapper classes at an unpredictable
        // moment, so re-assert via an observer for a few seconds (ETCH-COMPATIBILITY-NOTES.md).
        var stepWrappers = lesson.querySelectorAll('[data-step-index]');
        stepWrappers.forEach(bhcReassertStepClass);
        try {
            var classGuard = new MutationObserver(function (muts) {
                muts.forEach(function (m) {
                    var t = m.target;
                    if (m.attributeName === 'class' && t.hasAttribute('data-step-index'))
                        bhcReassertStepClass(t);
                });
            });
            stepWrappers.forEach(function (el) { classGuard.observe(el, { attributes: true, attributeFilter: ['class'] }); });
            setTimeout(function () { classGuard.disconnect(); }, 5000);
        }
        catch (e) { /* no MutationObserver — the one-shot pass above still ran */ }
        function showStep(index) {
            bhcSetVisibleStep(lesson, index);
            lesson.querySelectorAll(BHC_STEP_SELECTOR).forEach(function (el) {
                var _a;
                var isTarget = parseInt((_a = el.dataset.stepIndex) !== null && _a !== void 0 ? _a : '-1', 10) === index;
                el.classList.remove('bhc-step-entering');
                if (isTarget) {
                    // Force a reflow so re-adding the class retriggers the animation.
                    void el.offsetWidth;
                    el.classList.add('bhc-step-entering');
                    // Move focus to the new step (tabindex="-1") so keyboard/screen-reader
                    // users aren't left on a hidden control.
                    el.focus({ preventScroll: true });
                }
            });
            var counter = lesson.querySelector('.bhc-step-current');
            if (counter)
                counter.textContent = String(index + 1);
            lesson.querySelectorAll('.bhc-stepper-dot').forEach(function (dot) {
                var _a;
                var dotIndex = parseInt((_a = dot.dataset.targetIndex) !== null && _a !== void 0 ? _a : '-1', 10);
                dot.classList.toggle('bhc-stepper-current', dotIndex === index);
                dot.setAttribute('aria-current', dotIndex === index ? 'step' : 'false');
            });
        }
        // Marks a dot done and unlocks the next; called from every completion path.
        function markStepDone(index) {
            var dot = lesson.querySelector('.bhc-stepper-dot[data-target-index="' + index + '"]');
            if (dot) {
                dot.classList.add('bhc-stepper-done');
                // Brief pulse, removed afterwards so it can replay.
                dot.classList.add('bhc-stepper-pulse');
                setTimeout(function () { dot.classList.remove('bhc-stepper-pulse'); }, 500);
            }
            var nextDot = lesson.querySelector('.bhc-stepper-dot[data-target-index="' + (index + 1) + '"]');
            if (nextDot)
                nextDot.disabled = false;
        }
        // The sidebar is server-rendered once; every completion AJAX response returns
        // course_id/course_percent, applied here.
        function updateSidebarProgress(data) {
            if (!data || !data.course_id || data.course_percent === null || data.course_percent === undefined)
                return;
            document.querySelectorAll('.bhc-course-sidebar .bhc-progress-fill').forEach(function (el) {
                el.style.width = data.course_percent + '%';
            });
            document.querySelectorAll('.bhc-course-sidebar .bhc-progress-label').forEach(function (el) {
                el.textContent = data.course_percent + '% complete';
            });
        }
        // A short pause so the stepper-dot pulse is seen before the step changes.
        // Skipped under prefers-reduced-motion.
        function advanceWithBeat(index) {
            var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (reduced) {
                advance(index);
                return;
            }
            setTimeout(function () { advance(index); }, 450);
        }
        function advance(fromIndex) {
            var next = fromIndex + 1;
            if (next < stepCount) {
                showStep(next);
            }
            else {
                // Last step: reveal the Next Lesson / course-complete block.
                lesson.querySelectorAll('.bhc-step, [data-step-index]').forEach(function (el) { el.style.display = 'none'; });
                var nextBlock = lesson.querySelector('.bhc-lesson-next');
                if (nextBlock) {
                    nextBlock.style.display = '';
                    nextBlock.classList.remove('bhc-step-entering');
                    void nextBlock.offsetWidth;
                    nextBlock.classList.add('bhc-step-entering');
                    // Move focus into the block that replaced the step area.
                    nextBlock.focus({ preventScroll: true });
                    var completion = nextBlock.querySelector('.bhc-completion');
                    if (completion && window.bhcFireConfetti)
                        window.bhcFireConfetti(completion);
                    // Smaller pop + confetti for the "Lesson complete" banner. Live path only;
                    // bhcFireConfetti's guard stops it replaying.
                    var completeBanner = nextBlock.querySelector('.bhc-lesson-complete-banner');
                    if (completeBanner) {
                        completeBanner.classList.remove('bhc-banner-pop');
                        void completeBanner.offsetWidth;
                        completeBanner.classList.add('bhc-banner-pop');
                        if (window.bhcFireConfetti) {
                            window.bhcFireConfetti(completeBanner, { count: 8, minDistance: 30, distanceSpread: 40 });
                        }
                    }
                }
            }
        }
        /* ---------------- one player + one adapter per video step ----------------
         * Watch-progress, overlays and chapters all drive this one adapter. WHY: YouTube/
         * Vimeo steps are Plyr provider embeds with no <video> element, so Plyr's API is
         * used when present and the raw <video> only as a fallback.
         */
        var mediaAdapters = [];
        lesson.querySelectorAll('.bhc-step-video').forEach(function (el) {
            // Isolate each element so one failure can't abort the whole setup pass.
            try {
                var isProvider = el.hasAttribute('data-plyr-provider');
                var videoEl = el.tagName === 'VIDEO' ? el : null;
                // ---- Bunny Stream (private signed embed) ----
                // Cross-origin iframe driven by player.js. If player.js is missing, no adapter
                // is registered and the video still plays.
                if (el.classList.contains('bhc-step-video-bunny')) {
                    var iframe = el.querySelector('iframe.bhc-bunny-embed');
                    var PlayerJs = window.playerjs;
                    if (!iframe || !PlayerJs)
                        return;
                    var pjs = new PlayerJs.Player(iframe);
                    var cachedTime = 0;
                    var cachedDuration = 0;
                    var timeupdateCbs = [];
                    var endedCbs = [];
                    pjs.on('ready', function () {
                        pjs.getDuration(function (d) { if (isFinite(d) && d > 0)
                            cachedDuration = d; });
                    });
                    pjs.on('timeupdate', function (v) {
                        if (v && typeof v.seconds === 'number')
                            cachedTime = v.seconds;
                        if (v && typeof v.duration === 'number' && v.duration > 0)
                            cachedDuration = v.duration;
                        for (var i = 0; i < timeupdateCbs.length; i++)
                            timeupdateCbs[i]();
                    });
                    pjs.on('ended', function () { for (var j = 0; j < endedCbs.length; j++)
                        endedCbs[j](); });
                    var bunnyMedia = {
                        isProvider: true,
                        player: null,
                        currentTime: function () { return cachedTime; },
                        seek: function (t) { cachedTime = t; pjs.setCurrentTime(t); },
                        duration: function () { return cachedDuration; },
                        play: function () { pjs.play(); },
                        pause: function () { pjs.pause(); },
                        on: function (evt, cb) {
                            if (evt === 'timeupdate')
                                timeupdateCbs.push(cb);
                            else if (evt === 'ended')
                                endedCbs.push(cb);
                        },
                    };
                    mediaAdapters.push({ el: el, media: bunnyMedia });
                    return;
                }
                var PlyrCtor = window.Plyr;
                var player = null;
                if (PlyrCtor) {
                    var plyrAssets = window.BHCPlyrAssets || {};
                    player = new PlyrCtor(el, {
                        // No download button (a resource step is for files) and no PIP.
                        controls: ['play-large', 'play', 'progress', 'current-time', 'duration', 'mute', 'volume', 'settings', 'fullscreen'],
                        settings: ['speed'],
                        speed: { selected: 1, options: [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2] },
                        // WHY: Plyr's defaults point at cdn.plyr.io; vendored copies keep uploaded
                        // lessons off third-party CDNs.
                        iconUrl: plyrAssets.iconUrl,
                        blankVideo: plyrAssets.blankVideo,
                    });
                }
                // A provider embed with no Plyr can't be driven; skip it.
                if (!player && !videoEl)
                    return;
                var media = {
                    isProvider: isProvider,
                    player: player,
                    currentTime: function () { return player ? player.currentTime : (videoEl ? videoEl.currentTime : 0); },
                    seek: function (t) { if (player) {
                        player.currentTime = t;
                    }
                    else if (videoEl) {
                        videoEl.currentTime = t;
                    } },
                    duration: function () {
                        var d = player ? player.duration : (videoEl ? videoEl.duration : 0);
                        return (typeof d === 'number' && isFinite(d)) ? d : 0;
                    },
                    play: function () {
                        var r = player ? player.play() : (videoEl ? videoEl.play() : undefined);
                        // play() can reject under autoplay policy; the seek still happened.
                        if (r && typeof r.catch === 'function')
                            r.catch(function () { });
                    },
                    pause: function () { if (player) {
                        player.pause();
                    }
                    else if (videoEl) {
                        videoEl.pause();
                    } },
                    on: function (evt, cb) {
                        if (player) {
                            player.on(evt, cb);
                        }
                        else if (videoEl) {
                            videoEl.addEventListener(evt, cb);
                        }
                    },
                };
                mediaAdapters.push({ el: el, media: media });
            }
            catch (err) {
                if (window.console && console.warn)
                    console.warn('bh-courses: video adapter setup failed for a step, skipping it.', err);
            }
        });
        function mediaFor(el) {
            if (!el)
                return null;
            for (var i = 0; i < mediaAdapters.length; i++) {
                if (mediaAdapters[i].el === el)
                    return mediaAdapters[i].media;
            }
            return null;
        }
        // Video watch-progress. Only rendered with data-watch-threshold for trackable sources.
        // Sent once per whole-percent change to avoid hammering the endpoint.
        lesson.querySelectorAll('.bhc-step-video[data-watch-threshold]').forEach(function (video) {
            var _a;
            var media = mediaFor(video);
            if (!media)
                return;
            var step = video.closest('.bhc-step, [data-step-index]');
            var index = parseInt((_a = step.dataset.stepIndex) !== null && _a !== void 0 ? _a : '-1', 10);
            var lastSent = -1;
            function sendProgress(percent) {
                if (percent <= lastSent)
                    return;
                lastSent = percent;
                var body = new URLSearchParams({
                    action: 'bhc_update_watch_progress',
                    nonce: BHCData.nonce,
                    lesson_id: lessonId,
                    step_index: String(index),
                    percent: String(percent),
                });
                fetch(BHCData.ajaxUrl, { method: 'POST', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                    if (!res.success || !res.data || !res.data.auto_completed)
                        return;
                    var note = step.querySelector('.bhc-video-progress-note');
                    if (note) {
                        // Settle the gate into its "watched" state rather than hiding it.
                        note.classList.add('bhc-watch-gate--done');
                        var fill = note.querySelector('.bhc-watch-gate-fill');
                        if (fill)
                            fill.style.width = '100%';
                        var pctEl = note.querySelector('.bhc-watch-gate-pct');
                        if (pctEl)
                            pctEl.textContent = '100%';
                        var label = note.querySelector('.bhc-watch-gate-label');
                        if (label)
                            label.innerHTML = '<span class="bhc-watch-gate-pct">100%</span> watched';
                    }
                    var btn = step.querySelector('.bhc-mark-complete');
                    if (btn) {
                        btn.disabled = true;
                        btn.style.display = '';
                        btn.textContent = 'Completed';
                    }
                    step.classList.add('bhc-step-done');
                    if (typeof BHCoreToast !== 'undefined') {
                        BHCoreToast.show('Step complete.', 'success');
                    }
                    markStepDone(index);
                    updateSidebarProgress(res.data);
                    advanceWithBeat(index);
                });
            }
            var gateFill = step.querySelector('.bhc-watch-gate-fill');
            var gatePct = step.querySelector('.bhc-watch-gate-pct');
            var lastShown = -1;
            media.on('timeupdate', function () {
                var duration = media.duration();
                if (!duration || step.classList.contains('bhc-step-done'))
                    return;
                var percent = Math.floor((media.currentTime() / duration) * 100);
                if (percent !== lastShown) {
                    lastShown = percent;
                    if (gateFill)
                        gateFill.style.width = Math.min(100, Math.max(0, percent)) + '%';
                    if (gatePct)
                        gatePct.textContent = Math.min(100, Math.max(0, percent)) + '%';
                }
                sendProgress(percent);
            });
        });
        // Interactive video overlays: pause/resume this video only, never navigate.
        // 'question' answers persist via bhc_mark_annotation, fire-and-forget (unlike
        // bhc_mark_complete's retries), since failing to save a self-check shouldn't block playback.
        lesson.querySelectorAll('.bhc-step-video[data-annotations]').forEach(function (video) {
            var _a, _b;
            var media = mediaFor(video);
            if (!media)
                return;
            var annotationStep = video.closest('.bhc-step, [data-step-index]');
            var annotationStepIndex = annotationStep ? parseInt((_a = annotationStep.dataset.stepIndex) !== null && _a !== void 0 ? _a : '-1', 10) : -1;
            var annotations;
            try {
                annotations = JSON.parse((_b = video.dataset.annotations) !== null && _b !== void 0 ? _b : '[]');
            }
            catch (e) {
                return;
            }
            if (!Array.isArray(annotations) || !annotations.length)
                return;
            var wrap = video.closest('.bhc-video-wrap');
            if (!wrap)
                return;
            var shown = {};
            var overlay = null;
            function escText(s) {
                var d = document.createElement('div');
                d.textContent = s == null ? '' : String(s);
                return d.innerHTML;
            }
            function dismiss() {
                if (overlay)
                    overlay.remove();
                overlay = null;
                media.play();
            }
            // Lower-third banner: auto-dismisses and never pauses playback. Kept separate
            // from `overlay` so it can't block, or be blocked by, a pausing annotation.
            function showBanner(a, index) {
                shown[index] = true;
                var banner = document.createElement('div');
                banner.className = 'bhc-video-banner';
                banner.innerHTML = '<p class="bhc-video-banner-text">' + escText(a.payload.text) + '</p>';
                wrap.appendChild(banner);
                setTimeout(function () {
                    banner.classList.add('bhc-video-banner-out');
                    setTimeout(function () { banner.remove(); }, 400);
                }, 4000);
            }
            function showAnnotation(a, index) {
                if (a.type === 'banner') {
                    showBanner(a, index);
                    return;
                }
                media.pause();
                shown[index] = true;
                overlay = document.createElement('div');
                overlay.className = 'bhc-video-overlay bhc-video-overlay-' + a.type;
                if (a.type === 'question') {
                    var choicesHtml = (a.payload.choices || []).map(function (choice, i) {
                        return '<button type="button" class="bhc-video-overlay-choice" data-choice-index="' + i + '">' + escText(choice) + '</button>';
                    }).join('');
                    overlay.innerHTML =
                        '<div class="bhc-video-overlay-card">' +
                            '<p class="bhc-video-overlay-question">' + escText(a.payload.question) + '</p>' +
                            '<div class="bhc-video-overlay-choices">' + choicesHtml + '</div>' +
                            '</div>';
                    overlay.querySelectorAll('.bhc-video-overlay-choice').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var _a;
                            var chosen = parseInt((_a = btn.dataset.choiceIndex) !== null && _a !== void 0 ? _a : '-1', 10);
                            var correct = chosen === a.payload.correct_index;
                            overlay.querySelectorAll('.bhc-video-overlay-choice').forEach(function (b, i) {
                                b.disabled = true;
                                if (i === a.payload.correct_index)
                                    b.classList.add('bhc-video-overlay-correct');
                                else if (b === btn)
                                    b.classList.add('bhc-video-overlay-incorrect');
                            });
                            if (annotationStepIndex >= 0) {
                                fetch(BHCData.ajaxUrl, {
                                    method: 'POST',
                                    body: new URLSearchParams({
                                        action: 'bhc_mark_annotation',
                                        nonce: BHCData.nonce,
                                        lesson_id: lessonId,
                                        step_index: String(annotationStepIndex),
                                        annotation_index: String(index),
                                        chosen_index: String(chosen),
                                    }),
                                }).catch(function () { });
                            }
                            var continueBtn = document.createElement('button');
                            continueBtn.type = 'button';
                            continueBtn.className = 'bhc-btn bhc-video-overlay-continue';
                            continueBtn.textContent = correct ? 'Correct — continue' : 'Continue';
                            continueBtn.addEventListener('click', dismiss);
                            overlay.querySelector('.bhc-video-overlay-card').appendChild(continueBtn);
                        });
                    });
                }
                else {
                    overlay.innerHTML =
                        '<div class="bhc-video-overlay-card">' +
                            '<p class="bhc-video-overlay-text">' + escText(a.payload.text) + '</p>' +
                            '<button type="button" class="bhc-btn bhc-video-overlay-continue">Continue</button>' +
                            '</div>';
                    overlay.querySelector('.bhc-video-overlay-continue').addEventListener('click', dismiss);
                }
                wrap.appendChild(overlay);
            }
            media.on('timeupdate', function () {
                if (overlay)
                    return; // already paused on one; don't stack a second
                for (var i = 0; i < annotations.length; i++) {
                    if (shown[i])
                        continue;
                    var a = annotations[i];
                    if (media.currentTime() >= a.time) {
                        showAnnotation(a, i);
                        break;
                    }
                }
            });
            // Rewinding past a shown annotation re-arms it.
            media.on('seeked', function () {
                for (var i = 0; i < annotations.length; i++) {
                    if (media.currentTime() < annotations[i].time)
                        shown[i] = false;
                }
            });
        });
        // Chapter markers on the seek bar plus a clickable chapter list.
        // WHY: native <video controls> can't be painted on, so markers need Plyr's bar.
        // Without Plyr the list still renders and seeks; only the markers are lost.
        lesson.querySelectorAll('.bhc-step-video').forEach(function (video) {
            var media = mediaFor(video);
            if (!media)
                return;
            var wrap = video.closest('.bhc-video-wrap');
            if (!wrap || !wrap.parentNode)
                return;
            var chapters = [];
            if (video.dataset.chapters) {
                try {
                    var parsed = JSON.parse(video.dataset.chapters);
                    if (Array.isArray(parsed))
                        chapters = parsed;
                }
                catch (e) { /* malformed chapter data — treat as none, never break playback */ }
            }
            var player = media.player;
            function formatTime(seconds) {
                var m = Math.floor(seconds / 60);
                var s = Math.floor(seconds % 60);
                return m + ':' + (s < 10 ? '0' : '') + s;
            }
            function escText(s) {
                var d = document.createElement('div');
                d.textContent = s == null ? '' : String(s);
                return d.innerHTML;
            }
            function seekTo(seconds, tappedItem) {
                media.seek(seconds);
                media.play();
                // WHY: on iOS a tap outside the cross-origin iframe isn't a user activation for
                // its play(), so the tap can feel dead. Mark the chapter active and pulse it ourselves.
                if (tappedItem) {
                    items.forEach(function (it) { it.classList.toggle('active', it === tappedItem); });
                    tappedItem.classList.remove('bhc-chapter-jumped');
                    void tappedItem.offsetWidth;
                    tappedItem.classList.add('bhc-chapter-jumped');
                }
                var v = wrap;
                if (v && typeof v.scrollIntoView === 'function') {
                    var r = v.getBoundingClientRect();
                    if (r.top < 0 || r.bottom > window.innerHeight)
                        v.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
            }
            if (!chapters.length)
                return;
            /* ---- markers drawn directly on Plyr's own progress bar ---- */
            var markers = [];
            function buildMarkers() {
                if (!player)
                    return;
                var duration = media.duration();
                if (!duration)
                    return;
                var progress = wrap.querySelector('.plyr__progress');
                if (!progress)
                    return;
                markers.forEach(function (m) { m.remove(); });
                markers = [];
                chapters.forEach(function (chapter, i) {
                    // Skip chapters past the real runtime rather than pinning them at 100%.
                    if (chapter.time > duration)
                        return;
                    var marker = document.createElement('button');
                    marker.type = 'button';
                    marker.className = 'bhc-plyr-chapter-marker';
                    marker.style.left = ((chapter.time / duration) * 100) + '%';
                    marker.title = (chapter.title || 'Chapter ' + (i + 1)) + ' — ' + formatTime(chapter.time);
                    marker.setAttribute('aria-label', 'Jump to chapter: ' + (chapter.title || 'Chapter ' + (i + 1)));
                    marker.addEventListener('click', function (e) {
                        // The progress bar is itself a seek control; stop the click scrubbing too.
                        e.preventDefault();
                        e.stopPropagation();
                        seekTo(chapter.time);
                    });
                    progress.appendChild(marker);
                    markers.push(marker);
                });
            }
            /* ---- the chapter list beneath the video ---- */
            var container = document.createElement('div');
            container.className = 'bhc-video-chapters';
            var heading = document.createElement('p');
            heading.className = 'bhc-video-chapters-heading';
            heading.textContent = 'Chapters';
            container.appendChild(heading);
            var list = document.createElement('ol');
            list.className = 'bhc-video-chapter-list';
            container.appendChild(list);
            var items = [];
            chapters.forEach(function (chapter, i) {
                var item = document.createElement('li');
                item.className = 'bhc-video-chapter-item';
                item.innerHTML = '<button type="button" class="bhc-video-chapter-item-btn">'
                    + '<span class="bhc-video-chapter-item-time">' + formatTime(chapter.time) + '</span>'
                    + '<span class="bhc-video-chapter-item-title">' + escText(chapter.title || 'Chapter ' + (i + 1)) + '</span>'
                    + '</button>';
                item.querySelector('button').addEventListener('click', function () { seekTo(chapter.time, item); });
                list.appendChild(item);
                items.push(item);
            });
            function highlightActive() {
                var t = media.currentTime();
                var activeIndex = 0;
                for (var i = 0; i < chapters.length; i++) {
                    if (t >= chapters[i].time)
                        activeIndex = i;
                }
                items.forEach(function (item, i) { item.classList.toggle('active', i === activeIndex); });
                markers.forEach(function (m, i) { m.classList.toggle('active', i === activeIndex); });
            }
            // Plyr's progress bar exists only after 'ready' and is rebuilt on source change.
            if (player)
                player.on('ready', buildMarkers);
            if (media.duration() > 0)
                buildMarkers();
            media.on('loadedmetadata', buildMarkers);
            media.on('timeupdate', highlightActive);
            wrap.parentNode.insertBefore(container, wrap.nextSibling);
        });
        lesson.addEventListener('click', function (e) {
            var _a, _b;
            var target = e.target;
            if (target.classList.contains('bhc-step-back')) {
                // Pure navigation; every step's markup is already in the DOM.
                var targetIndex = parseInt((_a = target.dataset.targetIndex) !== null && _a !== void 0 ? _a : '0', 10);
                lesson.querySelectorAll('.bhc-step, [data-step-index]').forEach(function (el) { el.style.display = 'none'; });
                var nextBlock = lesson.querySelector('.bhc-lesson-next');
                if (nextBlock)
                    nextBlock.style.display = 'none';
                showStep(targetIndex);
                return;
            }
            if (!target.classList.contains('bhc-mark-complete'))
                return;
            var step = target.closest('.bhc-step, [data-step-index]');
            var index = parseInt((_b = step.dataset.stepIndex) !== null && _b !== void 0 ? _b : '-1', 10);
            var body = new URLSearchParams({
                action: 'bhc_mark_complete',
                nonce: BHCData.nonce,
                lesson_id: lessonId,
                step_index: String(index),
            });
            // Retry with backoff: safe because marking complete is an idempotent upsert
            // (unlike quiz submission).
            var btn = target;
            btn.disabled = true;
            var originalLabel = btn.textContent;
            submitMarkComplete(0);
            function submitMarkComplete(attempt) {
                fetch(BHCData.ajaxUrl, { method: 'POST', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                    // Bare -1 (check_ajax_referer failure / stale nonce) or 0 (no nopriv
                    // handler, i.e. logged out) means the session is the problem, not the request.
                    if (res === -1 || res === '-1' || res === 0 || res === '0') {
                        btn.disabled = false;
                        btn.textContent = originalLabel;
                        var expiredMsg = 'You need to be logged in to save your progress — log in and try again.';
                        if (typeof BHCoreToast !== 'undefined') {
                            BHCoreToast.show(expiredMsg, 'error');
                        }
                        else {
                            alert(expiredMsg);
                        }
                        return;
                    }
                    if (!res.success) {
                        btn.disabled = false;
                        btn.textContent = originalLabel;
                        var errMsg = (res.data && res.data.message) ? res.data.message : 'Something went wrong.';
                        // BHCoreToast is called directly (AJAX, no redirect for OUS_Toast);
                        // falls back to alert() if core's toast script isn't loaded.
                        if (typeof BHCoreToast !== 'undefined') {
                            BHCoreToast.show(errMsg, 'error');
                        }
                        else {
                            alert(errMsg);
                        }
                        return;
                    }
                    btn.textContent = 'Completed';
                    step.classList.add('bhc-step-done');
                    if (typeof BHCoreToast !== 'undefined') {
                        BHCoreToast.show('Step complete.', 'success');
                    }
                    markStepDone(index);
                    updateSidebarProgress(res.data);
                    advanceWithBeat(index);
                })
                    .catch(function () {
                    if (attempt < 2) {
                        btn.textContent = 'Retrying…';
                        setTimeout(function () { submitMarkComplete(attempt + 1); }, 500 * Math.pow(2, attempt) + Math.random() * 200);
                        return;
                    }
                    btn.disabled = false;
                    btn.textContent = originalLabel;
                    var msg = 'Could not reach the server — check your connection and try again.';
                    if (typeof BHCoreToast !== 'undefined') {
                        BHCoreToast.show(msg, 'error');
                    }
                    else {
                        alert(msg);
                    }
                });
            }
        });
        lesson.addEventListener('click', function (e) {
            var _a;
            var target = e.target;
            if (!target.classList.contains('bhc-stepper-dot') || target.disabled)
                return;
            var targetIndex = parseInt((_a = target.dataset.targetIndex) !== null && _a !== void 0 ? _a : '0', 10);
            lesson.querySelectorAll('.bhc-step, [data-step-index]').forEach(function (el) { el.style.display = 'none'; });
            var nextBlock = lesson.querySelector('.bhc-lesson-next');
            if (nextBlock)
                nextBlock.style.display = 'none';
            showStep(targetIndex);
        });
        // Mirrors BHC_Render::render_quiz_review()'s markup so the post-submit and later
        // review views match. Only shown after the whole quiz is submitted.
        function renderQuestionBreakdown(questions) {
            if (!questions || !questions.length)
                return '';
            var html = '';
            questions.forEach(function (q) {
                var chosen = typeof q.chosen_index === 'number' ? q.chosen_index : -1;
                var correctIndex = typeof q.correct_index === 'number' ? q.correct_index : -1;
                var qCorrect = chosen === correctIndex;
                html += '<fieldset class="bhc-quiz-question bhc-quiz-question-review ' + (qCorrect ? 'bhc-q-correct' : 'bhc-q-incorrect') + '"><legend>' + escapeHtml(q.q || '') + '</legend>';
                (q.choices || []).forEach(function (choice, ci) {
                    var classes = ['bhc-quiz-choice', 'bhc-quiz-choice-review'];
                    if (ci === correctIndex)
                        classes.push('bhc-correct');
                    if (ci === chosen && !qCorrect)
                        classes.push('bhc-choice-incorrect');
                    var marker = '';
                    if (ci === correctIndex)
                        marker = ' <span class="bhc-choice-marker">&#10003; Correct answer</span>';
                    else if (ci === chosen)
                        marker = ' <span class="bhc-choice-marker">&#10007; Your answer</span>';
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
        // Fallback for browsers without :has(); the :has() CSS rule stays the primary path.
        lesson.addEventListener('change', function (e) {
            var target = e.target;
            if (!target.matches('.bhc-quiz-choice input[type=radio]'))
                return;
            var fieldset = target.closest('.bhc-quiz-question');
            if (!fieldset)
                return;
            fieldset.querySelectorAll('.bhc-quiz-choice').forEach(function (label) {
                label.classList.remove('bhc-selected');
            });
            target.closest('.bhc-quiz-choice').classList.add('bhc-selected');
        });
        // Checklist step: client-side visual state only, nothing persisted.
        lesson.addEventListener('change', function (e) {
            var target = e.target;
            if (!target.matches('.bhc-checklist-check'))
                return;
            var li = target.closest('li');
            if (!li)
                return;
            li.classList.toggle('bhc-checklist-item-checked', target.checked);
        });
        lesson.addEventListener('submit', function (e) {
            var _a;
            var form = e.target;
            if (!form.classList.contains('bhc-quiz-form'))
                return;
            e.preventDefault();
            var step = form.closest('.bhc-step, [data-step-index]');
            var index = parseInt((_a = step.dataset.stepIndex) !== null && _a !== void 0 ? _a : '-1', 10);
            // WHY: never blind-retry quiz submission; each call burns a real attempt.
            // Disable up front to block double submits; re-enable only on failure.
            var quizSubmitBtn = form.querySelector('button[type="submit"]');
            var quizSubmitLabel = quizSubmitBtn ? quizSubmitBtn.textContent : '';
            // Change the label too; disabled alone isn't reliably announced by screen readers.
            if (quizSubmitBtn) {
                quizSubmitBtn.disabled = true;
                quizSubmitBtn.textContent = 'Submitting…';
            }
            var body = new URLSearchParams({ action: 'bhc_submit_quiz', nonce: BHCData.nonce, lesson_id: lessonId, step_index: String(index) });
            var formData = new FormData(form);
            for (var pair of formData.entries()) {
                body.append('answers[' + String(pair[0]).replace('q', '') + ']', String(pair[1]));
            }
            fetch(BHCData.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                var _a;
                var resultBox = form.querySelector('.bhc-quiz-result');
                // Same bare 0/-1 session cases as bhc_mark_complete above.
                if (res === -1 || res === '-1' || res === 0 || res === '0') {
                    if (quizSubmitBtn) {
                        quizSubmitBtn.disabled = false;
                        quizSubmitBtn.textContent = quizSubmitLabel;
                    }
                    resultBox.style.display = '';
                    resultBox.className = 'bhc-quiz-result bhc-fail';
                    resultBox.textContent = 'You need to be logged in to submit this quiz — log in and try again.';
                    return;
                }
                if (!res.success) {
                    if (quizSubmitBtn) {
                        quizSubmitBtn.disabled = false;
                        quizSubmitBtn.textContent = quizSubmitLabel;
                    }
                    // Attempts exhausted (403): show inline and lock the form.
                    var data = res.data || {};
                    resultBox.style.display = '';
                    resultBox.className = 'bhc-quiz-result bhc-fail';
                    resultBox.textContent = data.message || 'Something went wrong.';
                    if (data.max_attempts) {
                        form.querySelectorAll('input, button[type="submit"]').forEach(function (el) { el.disabled = true; });
                    }
                    return;
                }
                var result = res.data;
                resultBox.style.display = '';
                resultBox.className = 'bhc-quiz-result ' + (result.passed ? 'bhc-pass' : 'bhc-fail');
                var attemptsNote = '';
                if (result.max_attempts) {
                    attemptsNote = result.passed ? '' : (((_a = result.attempts_remaining) !== null && _a !== void 0 ? _a : 0) > 0 ? ' (' + result.attempts_remaining + ' attempt' + (result.attempts_remaining === 1 ? '' : 's') + ' remaining)' : ' (no attempts remaining)');
                }
                // result.correct is null on an already-passed replay; re-derive from
                // score/total (inverse of BHC_Steps::score_quiz()).
                var correctCount = (result.correct === null || result.correct === undefined)
                    ? Math.round((result.score / 100) * result.total)
                    : result.correct;
                // When retries are exhausted, name the specific missed questions.
                var attemptsExhausted = !result.passed && result.max_attempts && result.attempts_remaining === 0;
                if (result.passed) {
                    // Mirrors BHC_Render_Lesson::render_quiz_review(). All values are
                    // server-computed numbers, so building HTML here is safe.
                    resultBox.innerHTML = '<div class="bhc-quiz-pass-icon">&#10003;</div>'
                        + '<div class="bhc-datum bhc-quiz-pass-figure"><span class="bhc-datum-value">' + result.score + '%</span><span class="bhc-datum-label">quiz passed</span></div>'
                        + '<p class="bhc-quiz-pass-sub">' + correctCount + '/' + result.total + ' correct. Nice work.</p>';
                }
                else if (attemptsExhausted) {
                    var missedTopics = (result.questions || [])
                        .filter(function (q) { return q.chosen_index !== q.correct_index; })
                        .map(function (q) { return q.q; })
                        .filter(Boolean);
                    resultBox.textContent = missedTopics.length
                        ? 'Not this time — ' + correctCount + '/' + result.total + ' correct, and out of attempts. Before moving on, go back and review: ' + missedTopics.join('; ') + '.'
                        : 'Not this time — ' + correctCount + '/' + result.total + ' correct, and out of attempts. Review the lesson before moving on.';
                }
                else {
                    resultBox.textContent = correctCount + '/' + result.total + ' correct — not quite there yet.' + attemptsNote + ' Take another look and give it another shot.';
                }
                // Per-question breakdown; lock the inputs so it reads as a review.
                if (result.questions && result.questions.length) {
                    form.querySelectorAll('.bhc-quiz-question').forEach(function (fs) { fs.style.display = 'none'; });
                    var breakdown = document.createElement('div');
                    breakdown.className = 'bhc-quiz-review';
                    breakdown.innerHTML = renderQuestionBreakdown(result.questions);
                    form.insertBefore(breakdown, resultBox);
                }
                form.querySelectorAll('input').forEach(function (el) { el.disabled = true; });
                // Restores the form for a retry after a failed attempt.
                function resetQuizForm() {
                    var review = form.querySelector('.bhc-quiz-review');
                    if (review)
                        review.remove();
                    form.querySelectorAll('.bhc-quiz-question').forEach(function (fs) { fs.style.display = ''; });
                    form.querySelectorAll('input').forEach(function (el) { el.disabled = false; el.checked = false; });
                    resultBox.style.display = 'none';
                    resultBox.textContent = '';
                    if (quizSubmitBtn) {
                        quizSubmitBtn.style.display = '';
                        quizSubmitBtn.disabled = false;
                        quizSubmitBtn.textContent = quizSubmitLabel;
                    }
                    var tryAgainBtn = form.querySelector('.bhc-quiz-try-again');
                    if (tryAgainBtn)
                        tryAgainBtn.remove();
                }
                step.classList.add('bhc-step-done');
                markStepDone(index);
                updateSidebarProgress(result);
                if (result.passed) {
                    // No auto-advance on pass, so the breakdown can be read; require "Continue".
                    var submitBtn = form.querySelector('.bhc-submit-quiz');
                    if (submitBtn)
                        submitBtn.style.display = 'none';
                    var continueBtn = document.createElement('button');
                    continueBtn.type = 'button';
                    continueBtn.className = 'bhc-btn bhc-quiz-continue';
                    continueBtn.textContent = 'Continue';
                    continueBtn.addEventListener('click', function () { advance(index); });
                    form.appendChild(continueBtn);
                }
                else if (result.max_attempts && result.attempts_remaining === 0) {
                    form.querySelectorAll('input, button[type="submit"]').forEach(function (el) { el.disabled = true; });
                }
                else {
                    // Failed with a retry available: replace submit with "Try again".
                    if (quizSubmitBtn)
                        quizSubmitBtn.style.display = 'none';
                    var tryAgainBtn = document.createElement('button');
                    tryAgainBtn.type = 'button';
                    tryAgainBtn.className = 'bhc-btn bhc-btn-secondary bhc-quiz-try-again';
                    tryAgainBtn.textContent = 'Try again';
                    tryAgainBtn.addEventListener('click', resetQuizForm);
                    form.appendChild(tryAgainBtn);
                }
            })
                .catch(function () {
                // Deliberately no retry: quiz submission burns an attempt per call.
                // Re-enable so the student can resubmit deliberately.
                if (quizSubmitBtn) {
                    quizSubmitBtn.disabled = false;
                    quizSubmitBtn.textContent = quizSubmitLabel;
                }
                var resultBox = form.querySelector('.bhc-quiz-result');
                if (resultBox) {
                    resultBox.style.display = '';
                    resultBox.className = 'bhc-quiz-result bhc-fail';
                    resultBox.textContent = 'Could not reach the server — check your connection and submit again.';
                }
            });
        });
    });
})();
