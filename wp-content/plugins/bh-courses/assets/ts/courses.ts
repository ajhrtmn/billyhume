/**
 * TypeScript pilot conversion — real types throughout (not
 * @ts-nocheck), same posture as every other file in this pilot: the
 * whole point is tsc catching real typos/shape mistakes at build time,
 * not just having the file live in assets/ts/. This is the lesson
 * stepper (steps/quiz/video-progress/annotations) — the largest, most
 * stateful file in the pilot, so non-null assertions (`!`) are used at
 * DOM lookups this code's own control flow already guarantees succeed
 * (e.g. inside a `.bhc-quiz-form` submit handler, `.bhc-step` and its
 * `.bhc-quiz-result` child are always present — the server only ever
 * renders that form inside that structure). BHCData/BHCoreToast are
 * the same untyped external globals every other file in this
 * ecosystem's pilot declares.
 */

interface BHCDataGlobal {
    nonce: string;
    ajaxUrl: string;
}

declare const BHCData: BHCDataGlobal;
declare const BHCoreToast: { show(message: string, type: string): void } | undefined;

interface BHCConfettiOpts {
    count?: number;
    minDistance?: number;
    distanceSpread?: number;
}

interface BHCWindow extends Window {
    bhcFireConfetti?: (completion: HTMLElement | null, opts?: BHCConfettiOpts) => void;
}

interface BHCQuizQuestionResult {
    q?: string;
    choices?: string[];
    chosen_index?: number | null;
    correct_index?: number | null;
}

interface BHCQuizSubmitResult {
    passed: boolean;
    score: number;
    total: number;
    correct: number | null;
    max_attempts?: number;
    attempts_remaining?: number;
    questions?: BHCQuizQuestionResult[];
    course_id?: number | string;
    course_percent?: number | null;
    message?: string;
    auto_completed?: boolean;
}

interface BHCAjaxResponse<T = unknown> {
    success: boolean;
    data?: T;
}

interface BHCVideoAnnotationPayload {
    text?: string;
    question?: string;
    choices?: string[];
    correct_index?: number;
}

interface BHCVideoAnnotation {
    time: number;
    type: 'note' | 'hotspot' | 'question' | 'banner';
    payload: BHCVideoAnnotationPayload;
}

interface BHCVideoChapter {
    time: number;
    title: string;
}

// Loosely typed on purpose — Plyr is vendored as a plain global (no
// @types package installed, no bundler), and this file only ever
// touches the handful of members below. Same local-ambient-declaration
// posture the rest of this ecosystem's TS uses for untyped globals
// (bhEsc, anime, Howl).
interface BHCPlyrInstance {
    media: HTMLVideoElement;
    currentTime: number;
    duration: number;
    on(event: string, handler: () => void): void;
    play(): Promise<void> | void;
    pause(): void;
    destroy(): void;
}

interface BHCPlyrConstructor {
    new(target: HTMLElement, options?: Record<string, unknown>): BHCPlyrInstance;
}

// Bunny Stream's iframe speaks the player.js postMessage protocol
// (assets.mediadelivery.net/playerjs/…). Only the members this file
// drives are declared. All getters are async (callback), so the adapter
// below keeps a synchronous cache fed by the 'timeupdate' event.
interface BHCPlayerJsInstance {
    on(event: string, handler: (value?: { seconds?: number; duration?: number }) => void): void;
    play(): void;
    pause(): void;
    setCurrentTime(seconds: number): void;
    getCurrentTime(cb: (seconds: number) => void): void;
    getDuration(cb: (seconds: number) => void): void;
}
interface BHCPlayerJsConstructor { new(el: HTMLIFrameElement | string): BHCPlayerJsInstance; }

/**
 * One uniform handle on "the media for this step", whether that's a
 * real <video> or a YouTube/Vimeo provider embed driven through Plyr.
 * Every video feature (watch-progress, overlays, chapters) talks to
 * this instead of to an element, which is what lets all three work
 * identically across sources.
 */
interface BHCMedia {
    isProvider: boolean;
    player: BHCPlyrInstance | null;
    currentTime(): number;
    seek(seconds: number): void;
    duration(): number;
    play(): void;
    pause(): void;
    on(event: string, handler: () => void): void;
}

/* ---- lesson step DOM helpers ----------------------------------------
 * Pure and hoisted to module scope so the unit runner can reach them
 * (tests/unit/lesson-step-dom.test.ts loads the compiled courses.js in
 * jsdom and reads window.BHCLessonStepDom). Every one is written to not
 * depend on the `.bhc-step` class surviving: on the live Etch site the
 * theme's hydration blanks class="" off each step wrapper on load, so
 * selection and the rebuilt class both key on the data-step-* attrs
 * render-lesson.php emits alongside. See ETCH-COMPATIBILITY-NOTES.md.
 * ------------------------------------------------------------------- */
var BHC_STEP_SELECTOR = '.bhc-step, [data-step-index]';

function bhcStepClassName(type: string, done: boolean): string {
    return 'bhc-step' + (type ? ' bhc-step-' + type : '') + (done ? ' bhc-step-done' : '');
}

/** Rebuild the wrapper's class from its data-step-* attrs, but only if
 *  the `.bhc-step` class has actually gone missing — a no-op otherwise. */
function bhcReassertStepClass(el: HTMLElement): void {
    if (el.classList.contains('bhc-step')) return;
    el.className = bhcStepClassName(el.dataset.stepType || '', !!el.dataset.stepDone);
}

/** Show exactly the step at `index`, hide the rest. Pure display toggle
 *  — showStep() layers the focus move, entering animation and step
 *  counter on top of this. Returns the index it actually showed, or -1
 *  if no wrapper matched. */
function bhcSetVisibleStep(root: ParentNode, index: number): number {
    var shown = -1;
    root.querySelectorAll<HTMLElement>(BHC_STEP_SELECTOR).forEach(function (el) {
        var isTarget = parseInt(el.dataset.stepIndex ?? '-1', 10) === index;
        el.style.display = isTarget ? '' : 'none';
        if (isTarget) shown = index;
    });
    return shown;
}

// Harmless in the browser (a 4-key object); the unit runner reads it.
(window as unknown as Record<string, unknown>).BHCLessonStepDom = {
    SELECTOR: BHC_STEP_SELECTOR,
    className: bhcStepClassName,
    reassert: bhcReassertStepClass,
    setVisible: bhcSetVisibleStep,
};

(function () {
    document.addEventListener('DOMContentLoaded', function () {
        // Catalog filter bar: works as a plain GET form with zero JS
        // (class-render.php's render_catalog_filters()) — this just
        // progressively enhances it so picking a category/topic/sort
        // submits immediately instead of waiting for the Filter button.
        // The search box deliberately does NOT auto-submit on keystroke
        // (that would refetch the whole catalog on every character).
        var filterForm = document.querySelector('.bhc-catalog-filters') as HTMLFormElement | null;
        if (filterForm) {
            filterForm.querySelectorAll('select').forEach(function (select) {
                select.addEventListener('change', function () { filterForm!.submit(); });
            });
        }
    });

    // Course-page review form — lives OUTSIDE the .bhc-lesson-gated
    // block below (that whole block early-returns if .bhc-lesson isn't
    // on the page, which it never is on the course page itself, only
    // on a single lesson's own page) so this actually runs where the
    // review form is rendered.
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('.bhc-review-form') as HTMLFormElement | null;
        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var resultBox = form!.querySelector('.bhc-review-form-result') as HTMLElement;
            var submitBtn = form!.querySelector('button[type="submit"]') as HTMLButtonElement;
            var rating = form!.querySelector('input[name="rating"]:checked') as HTMLInputElement | null;
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
                course_id: form!.dataset.courseId ?? '',
                rating: rating.value,
                body: (form!.querySelector('.bhc-review-textarea') as HTMLTextAreaElement).value,
            });

            fetch(BHCData.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json() as Promise<BHCAjaxResponse<{ message?: string }>>; })
                .then(function (res) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalLabel;
                    if (!res.success) {
                        resultBox.textContent = (res.data && res.data.message) || 'Could not submit your review.';
                        return;
                    }
                    resultBox.textContent = (res.data && res.data.message) || 'Thanks for your review!';
                    submitBtn.textContent = 'Update review';
                    if (typeof BHCoreToast !== 'undefined') { BHCoreToast!.show((res.data && res.data.message) || 'Review submitted.', 'success'); }
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
    // opts lets a QUIETER moment (the per-lesson completion banner,
    // added later — see advance() below) reuse this same mechanism at a
    // smaller scale instead of duplicating it: fewer pieces, thrown a
    // shorter distance, so it reads as a small flourish rather than
    // competing with the course-completion burst's own visual weight.
    // Every default below matches this function's original, unparameterized
    // behavior exactly, so the course-completion call site (unchanged)
    // looks pixel-identical to before.
    (window as BHCWindow).bhcFireConfetti = function (completion, opts) {
        if (!completion || completion.dataset.bhcConfettiFired) return;
        completion.dataset.bhcConfettiFired = '1';
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
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
            piece.style.background = colors[i % colors.length]!;
            piece.style.animationDelay = (Math.random() * 0.15).toFixed(2) + 's';
            completion.appendChild(piece);
        }
        setTimeout(function () {
            completion.querySelectorAll('.bhc-confetti-piece').forEach(function (p) { p.remove(); });
        }, 1500);
    };

    document.addEventListener('DOMContentLoaded', function () {
        var completion = document.querySelector('.bhc-completion') as HTMLElement | null;
        if (completion && completion.offsetParent !== null) (window as BHCWindow).bhcFireConfetti!(completion);
    });

    document.addEventListener('DOMContentLoaded', function () {
        var lesson = document.querySelector('.bhc-lesson') as HTMLElement | null;
        if (!lesson) return;

        var lessonId = lesson.dataset.lessonId ?? '';
        var stepCount = parseInt(lesson.dataset.stepCount ?? '0', 10);

        // The live (Etch) site's frontend hydration blanks class="bhc-step
        // …" off each step wrapper on load. bhcReassertStepClass rebuilds
        // it from the data-step-* attrs; because the strip's timing vs.
        // this script isn't guaranteed, re-assert for a few seconds via
        // an observer rather than just once. No-op where the class
        // already survived. See ETCH-COMPATIBILITY-NOTES.md.
        var stepWrappers = lesson.querySelectorAll<HTMLElement>('[data-step-index]');
        stepWrappers.forEach(bhcReassertStepClass);
        try {
            var classGuard = new MutationObserver(function (muts) {
                muts.forEach(function (m) {
                    var t = m.target as HTMLElement;
                    if (m.attributeName === 'class' && t.hasAttribute('data-step-index')) bhcReassertStepClass(t);
                });
            });
            stepWrappers.forEach(function (el) { classGuard.observe(el, { attributes: true, attributeFilter: ['class'] }); });
            setTimeout(function () { classGuard.disconnect(); }, 5000);
        } catch (e) { /* no MutationObserver — the one-shot pass above still ran */ }

        function showStep(index: number) {
            bhcSetVisibleStep(lesson!, index);
            lesson!.querySelectorAll<HTMLElement>(BHC_STEP_SELECTOR).forEach(function (el) {
                var isTarget = parseInt(el.dataset.stepIndex ?? '-1', 10) === index;
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
            var counter = lesson!.querySelector('.bhc-step-current');
            if (counter) counter.textContent = String(index + 1);
            lesson!.querySelectorAll<HTMLButtonElement>('.bhc-stepper-dot').forEach(function (dot) {
                var dotIndex = parseInt(dot.dataset.targetIndex ?? '-1', 10);
                dot.classList.toggle('bhc-stepper-current', dotIndex === index);
                dot.setAttribute('aria-current', dotIndex === index ? 'step' : 'false');
            });
        }

        // Marks a dot done and unlocks the next one — called wherever a
        // step is completed (button click, quiz pass, or the video
        // watch-threshold auto-complete below), so the stepper always
        // reflects real progress instead of drifting out of sync with
        // the .bhc-step-done class it mirrors.
        function markStepDone(index: number) {
            var dot = lesson!.querySelector('.bhc-stepper-dot[data-target-index="' + index + '"]');
            if (dot) {
                dot.classList.add('bhc-stepper-done');
                // A brief pulse on the dot itself — the one small "that
                // counted" moment for finishing a step, not just a silent
                // class swap. Removed after the animation so it can
                // replay if this step is ever completed again.
                dot.classList.add('bhc-stepper-pulse');
                setTimeout(function () { dot!.classList.remove('bhc-stepper-pulse'); }, 500);
            }
            var nextDot = lesson!.querySelector('.bhc-stepper-dot[data-target-index="' + (index + 1) + '"]') as HTMLButtonElement | null;
            if (nextDot) nextDot.disabled = false;
        }

        // Bug found via live walkthrough (2026-07-26): the course-
        // progress sidebar (class-render-course.php's
        // render_lesson_sidebar()) is server-rendered once at page load
        // and this whole lesson flow never touches it again — completing
        // a step (via mark-complete, video auto-complete, or a quiz
        // submit) left the sidebar's percentage/bar stuck at the OLD
        // number until a full reload, most jarringly right when the
        // final step's own completion screen reveals itself as "Course
        // complete!" next to a sidebar still claiming 67%. The three AJAX
        // handlers behind those actions (class-progress.php's
        // ajax_mark_complete/ajax_update_watch_progress/ajax_submit_quiz)
        // now all return course_id/course_percent; this is the one place
        // that response data gets applied, so all three call sites stay
        // in sync through a single function rather than three near-
        // duplicate DOM updates.
        function updateSidebarProgress(data: BHCQuizSubmitResult | undefined | null) {
            if (!data || !data.course_id || data.course_percent === null || data.course_percent === undefined) return;
            document.querySelectorAll<HTMLElement>('.bhc-course-sidebar .bhc-progress-fill').forEach(function (el) {
                el.style.width = data.course_percent + '%';
            });
            document.querySelectorAll('.bhc-course-sidebar .bhc-progress-label').forEach(function (el) {
                el.textContent = data.course_percent + '% complete';
            });
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
        function advanceWithBeat(index: number) {
            var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (reduced) { advance(index); return; }
            setTimeout(function () { advance(index); }, 450);
        }

        function advance(fromIndex: number) {
            var next = fromIndex + 1;
            if (next < stepCount) {
                showStep(next);
            } else {
                // That was the last step — reveal the Next Lesson / course-
                // complete block instead of doing nothing (the pre-existing
                // behavior: advance() silently no-op'd past the end,
                // leaving a student stranded on the final step with no
                // way forward except manually finding the course page).
                lesson!.querySelectorAll<HTMLElement>('.bhc-step, [data-step-index]').forEach(function (el) { el.style.display = 'none'; });
                var nextBlock = lesson!.querySelector('.bhc-lesson-next') as HTMLElement | null;
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
                    var completion = nextBlock.querySelector('.bhc-completion') as HTMLElement | null;
                    if (completion && (window as BHCWindow).bhcFireConfetti) (window as BHCWindow).bhcFireConfetti!(completion);
                    // The mid-tier "Lesson complete" banner (no course_id
                    // match, i.e. a next lesson still follows) gets its own
                    // smaller beat — a real entrance pop distinct from the
                    // wrapper's plain fade, plus a scaled-down confetti
                    // burst reusing bhcFireConfetti's new opts param. Both
                    // only fire here, the live same-session path — a
                    // student who reloads and lands back on an already-
                    // revealed banner (dataset.bhcConfettiFired already set,
                    // see bhcFireConfetti's own guard) doesn't see it replay.
                    var completeBanner = nextBlock.querySelector('.bhc-lesson-complete-banner') as HTMLElement | null;
                    if (completeBanner) {
                        completeBanner.classList.remove('bhc-banner-pop');
                        void completeBanner.offsetWidth;
                        completeBanner.classList.add('bhc-banner-pop');
                        if ((window as BHCWindow).bhcFireConfetti) {
                            (window as BHCWindow).bhcFireConfetti!(completeBanner, { count: 8, minDistance: 30, distanceSpread: 40 });
                        }
                    }
                }
            }
        }

        /* ---------------- one player + one adapter per video step ----------------
         *
         * Built ONCE up front so the three feature blocks below
         * (watch-progress, overlays, chapters) all drive the same player
         * through one interface instead of each reaching for the raw
         * <video> element.
         *
         * That indirection is what lets all three work on a YouTube or
         * Vimeo step at all. Those render as a Plyr provider embed (a
         * <div data-plyr-provider>, see class-render-lesson.php), where
         * there is no <video> element to hold a .currentTime or fire a
         * 'timeupdate' — Plyr's own instance API supplies both, and
         * exposes the identical shape for a plain HTML5 file. So:
         * whenever Plyr is present, everything routes through it; the
         * raw-element path is only the fallback for a genuine <video>
         * when Plyr's script failed to load at all.
         */
        var mediaAdapters: { el: HTMLElement; media: BHCMedia }[] = [];

        lesson.querySelectorAll<HTMLElement>('.bhc-step-video').forEach(function (el) {
          // One malformed step (or a player library that loaded but
          // threw on construction) must never abort the whole setup
          // pass — that would take the chapter list, quiz handlers and
          // everything downstream with it. Isolate each element.
          try {
            var isProvider = el.hasAttribute('data-plyr-provider');
            var videoEl = el.tagName === 'VIDEO' ? (el as HTMLVideoElement) : null;

            // ---- Bunny Stream (private signed embed) ----
            // A cross-origin iframe, but player.js gives us the same
            // control surface Plyr does for YouTube/Vimeo, so chapters,
            // pausing annotations and watch-threshold all work. Degrades
            // cleanly: if player.js didn't load or never reports ready,
            // no adapter is registered and the video still plays (the
            // chapter list just isn't clickable), same as an opaque embed.
            if (el.classList.contains('bhc-step-video-bunny')) {
                var iframe = el.querySelector<HTMLIFrameElement>('iframe.bhc-bunny-embed');
                var PlayerJs = (window as unknown as { playerjs?: { Player: BHCPlayerJsConstructor } }).playerjs;
                if (!iframe || !PlayerJs) return;
                var pjs = new PlayerJs.Player(iframe);
                var cachedTime = 0;
                var cachedDuration = 0;
                var timeupdateCbs: Array<() => void> = [];
                var endedCbs: Array<() => void> = [];
                pjs.on('ready', function () {
                    pjs.getDuration(function (d) { if (isFinite(d) && d > 0) cachedDuration = d; });
                });
                pjs.on('timeupdate', function (v) {
                    if (v && typeof v.seconds === 'number') cachedTime = v.seconds;
                    if (v && typeof v.duration === 'number' && v.duration > 0) cachedDuration = v.duration;
                    for (var i = 0; i < timeupdateCbs.length; i++) timeupdateCbs[i]!();
                });
                pjs.on('ended', function () { for (var j = 0; j < endedCbs.length; j++) endedCbs[j]!(); });
                var bunnyMedia: BHCMedia = {
                    isProvider: true,
                    player: null,
                    currentTime: function () { return cachedTime; },
                    seek: function (t) { cachedTime = t; pjs.setCurrentTime(t); },
                    duration: function () { return cachedDuration; },
                    play: function () { pjs.play(); },
                    pause: function () { pjs.pause(); },
                    on: function (evt, cb) {
                        if (evt === 'timeupdate') timeupdateCbs.push(cb);
                        else if (evt === 'ended') endedCbs.push(cb);
                    },
                };
                mediaAdapters.push({ el: el, media: bunnyMedia });
                return;
            }

            var PlyrCtor = (window as unknown as { Plyr?: BHCPlyrConstructor }).Plyr;
            var player: BHCPlyrInstance | null = null;
            if (PlyrCtor) {
                var plyrAssets = (window as unknown as { BHCPlyrAssets?: { iconUrl?: string; blankVideo?: string } }).BHCPlyrAssets || {};
                player = new PlyrCtor(el, {
                    // Deliberately NOT Plyr's full default control set —
                    // this is a lesson video, not a media app: no
                    // download button (a resource step is the real
                    // "here, take this file" affordance), no PIP clutter.
                    controls: ['play-large', 'play', 'progress', 'current-time', 'duration', 'mute', 'volume', 'settings', 'fullscreen'],
                    settings: ['speed'],
                    speed: { selected: 1, options: [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2] },
                    // Both of Plyr's own shipped defaults point at
                    // cdn.plyr.io — overridden to this plugin's vendored
                    // copies so an uploaded/direct-URL lesson never
                    // reaches a third-party CDN (CLAUDE.md's standing
                    // self-hosted rule). A YouTube/Vimeo step
                    // unavoidably contacts its own provider; that is the
                    // explicit, accepted trade for controlling it.
                    iconUrl: plyrAssets.iconUrl,
                    blankVideo: plyrAssets.blankVideo,
                });
            }

            // No Plyr AND no real <video> means a provider embed we
            // cannot drive — skip it rather than register an adapter
            // whose every method silently no-ops.
            if (!player && !videoEl) return;

            var media: BHCMedia = {
                isProvider: isProvider,
                player: player,
                currentTime: function () { return player ? player.currentTime : (videoEl ? videoEl.currentTime : 0); },
                seek: function (t) { if (player) { player.currentTime = t; } else if (videoEl) { videoEl.currentTime = t; } },
                duration: function () {
                    var d = player ? player.duration : (videoEl ? videoEl.duration : 0);
                    return (typeof d === 'number' && isFinite(d)) ? d : 0;
                },
                play: function () {
                    var r = player ? player.play() : (videoEl ? videoEl.play() : undefined);
                    // A programmatic play() can legitimately reject
                    // (autoplay policy) — any seek that preceded it still
                    // happened, which is the part that matters.
                    if (r && typeof (r as Promise<void>).catch === 'function') (r as Promise<void>).catch(function () { /* playback just didn't auto-start */ });
                },
                pause: function () { if (player) { player.pause(); } else if (videoEl) { videoEl.pause(); } },
                on: function (evt, cb) {
                    if (player) { player.on(evt, cb); } else if (videoEl) { videoEl.addEventListener(evt, cb); }
                },
            };
            mediaAdapters.push({ el: el, media: media });
          } catch (err) {
            if (window.console && console.warn) console.warn('bh-courses: video adapter setup failed for a step, skipping it.', err);
          }
        });

        function mediaFor(el: Element | null): BHCMedia | null {
            if (!el) return null;
            for (var i = 0; i < mediaAdapters.length; i++) {
                if (mediaAdapters[i]!.el === el) return mediaAdapters[i]!.media;
            }
            return null;
        }

        // ROADMAP-ux-polish-and-feature-parity-2026-07.md 4b: real video
        // progress tracking. Only elements carrying data-watch-threshold
        // get a listener — class-render-lesson.php only renders that
        // attribute for a course-creator-configured, genuinely trackable
        // step (an upload, a direct URL, or a YouTube/Vimeo provider
        // embed); an opaque third-party iframe never gets it. Throttled
        // to once per whole-percent change (a raw timeupdate fires many
        // times a second) to avoid hammering the AJAX endpoint.
        lesson.querySelectorAll<HTMLElement>('.bhc-step-video[data-watch-threshold]').forEach(function (video) {
            var media = mediaFor(video);
            if (!media) return;
            var step = video.closest('.bhc-step, [data-step-index]') as HTMLElement;
            var index = parseInt(step.dataset.stepIndex ?? '-1', 10);
            var lastSent = -1;

            function sendProgress(percent: number) {
                if (percent <= lastSent) return;
                lastSent = percent;
                var body = new URLSearchParams({
                    action: 'bhc_update_watch_progress',
                    nonce: BHCData.nonce,
                    lesson_id: lessonId,
                    step_index: String(index),
                    percent: String(percent),
                });
                fetch(BHCData.ajaxUrl, { method: 'POST', body: body })
                    .then(function (r) { return r.json() as Promise<BHCAjaxResponse<BHCQuizSubmitResult>>; })
                    .then(function (res) {
                        if (!res.success || !res.data || !res.data.auto_completed) return;
                        var note = step.querySelector('.bhc-video-progress-note') as HTMLElement | null;
                        if (note) {
                            // Settle the gate into its "watched" state
                            // instead of hiding it — the figure stays on
                            // screen, full bar, no "completes at N%" tail.
                            note.classList.add('bhc-watch-gate--done');
                            var fill = note.querySelector('.bhc-watch-gate-fill') as HTMLElement | null;
                            if (fill) fill.style.width = '100%';
                            var pctEl = note.querySelector('.bhc-watch-gate-pct') as HTMLElement | null;
                            if (pctEl) pctEl.textContent = '100%';
                            var label = note.querySelector('.bhc-watch-gate-label') as HTMLElement | null;
                            if (label) label.innerHTML = '<span class="bhc-watch-gate-pct">100%</span> watched';
                        }
                        var btn = step.querySelector('.bhc-mark-complete') as HTMLButtonElement | null;
                        if (btn) { btn.disabled = true; btn.style.display = ''; btn.textContent = 'Completed'; }
                        step.classList.add('bhc-step-done');
                        if (typeof BHCoreToast !== 'undefined') { BHCoreToast!.show('Step complete.', 'success'); }
                        markStepDone(index);
                        updateSidebarProgress(res.data);
                        advanceWithBeat(index);
                    });
            }

            var gateFill = step.querySelector('.bhc-watch-gate-fill') as HTMLElement | null;
            var gatePct = step.querySelector('.bhc-watch-gate-pct') as HTMLElement | null;
            var lastShown = -1;

            media.on('timeupdate', function () {
                var duration = media!.duration();
                if (!duration || step.classList.contains('bhc-step-done')) return;
                var percent = Math.floor((media!.currentTime() / duration) * 100);
                if (percent !== lastShown) {
                    lastShown = percent;
                    if (gateFill) gateFill.style.width = Math.min(100, Math.max(0, percent)) + '%';
                    if (gatePct) gatePct.textContent = Math.min(100, Math.max(0, percent)) + '%';
                }
                sendProgress(percent);
            });
        });

        // ROADMAP-lms-v3.md Section 1 — interactive video overlays.
        // An annotation pauses/resumes the SAME video step, never
        // redirects anywhere, so playback position and step navigation
        // are unaffected by any of this — purely a playback-UI behavior
        // layered on top of the existing video step.
        //
        // 2026-08-26 (OPEN.md item 21, built on item 22's sub_index):
        // 'question' annotations now DO persist server-side — the
        // instant client-side right/wrong reveal below is unchanged
        // (a network round-trip has no business gating that feedback),
        // but the answer is also sent to bhc_mark_annotation so it
        // shows up in this student's real progress record instead of
        // evaporating on refresh. Fire-and-forget, deliberately not the
        // full retry-with-backoff bhc_mark_complete uses below: failing
        // to persist a self-check doesn't block the student from
        // continuing the video, unlike a step failing to mark complete.
        lesson.querySelectorAll<HTMLElement>('.bhc-step-video[data-annotations]').forEach(function (video) {
            var media = mediaFor(video);
            if (!media) return;
            var annotationStep = video.closest('.bhc-step, [data-step-index]') as HTMLElement | null;
            var annotationStepIndex = annotationStep ? parseInt(annotationStep.dataset.stepIndex ?? '-1', 10) : -1;
            var annotations: BHCVideoAnnotation[];
            try {
                annotations = JSON.parse(video.dataset.annotations ?? '[]');
            } catch (e) {
                return;
            }
            if (!Array.isArray(annotations) || !annotations.length) return;

            var wrap = video.closest('.bhc-video-wrap') as HTMLElement | null;
            if (!wrap) return;
            var shown: Record<number, boolean> = {};
            var overlay: HTMLElement | null = null;

            function escText(s: unknown) { // reuse the same escaping convention as the rest of this file's inline HTML building
                var d = document.createElement('div');
                d.textContent = s == null ? '' : String(s);
                return d.innerHTML;
            }

            function dismiss() {
                if (overlay) overlay.remove();
                overlay = null;
                media!.play();
            }

            // TRL-style lower-third: slides in, auto-dismisses on its
            // own after a few seconds, and — the whole point of this
            // one, unlike note/hotspot/question — never pauses playback.
            // Kept entirely separate from the `overlay` variable/dismiss()
            // above so a banner showing doesn't block (or get blocked by)
            // a pausing annotation elsewhere in the same video.
            function showBanner(a: BHCVideoAnnotation, index: number) {
                shown[index] = true;
                var banner = document.createElement('div');
                banner.className = 'bhc-video-banner';
                banner.innerHTML = '<p class="bhc-video-banner-text">' + escText(a.payload.text) + '</p>';
                wrap!.appendChild(banner);
                setTimeout(function () {
                    banner.classList.add('bhc-video-banner-out');
                    setTimeout(function () { banner.remove(); }, 400);
                }, 4000);
            }

            function showAnnotation(a: BHCVideoAnnotation, index: number) {
                if (a.type === 'banner') { showBanner(a, index); return; }
                media!.pause();
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
                    overlay.querySelectorAll<HTMLButtonElement>('.bhc-video-overlay-choice').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var chosen = parseInt(btn.dataset.choiceIndex ?? '-1', 10);
                            var correct = chosen === a.payload.correct_index;
                            overlay!.querySelectorAll<HTMLButtonElement>('.bhc-video-overlay-choice').forEach(function (b, i) {
                                b.disabled = true;
                                if (i === a.payload.correct_index) b.classList.add('bhc-video-overlay-correct');
                                else if (b === btn) b.classList.add('bhc-video-overlay-incorrect');
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
                                }).catch(function () { /* self-check, non-blocking — see this block's own comment */ });
                            }
                            var continueBtn = document.createElement('button');
                            continueBtn.type = 'button';
                            continueBtn.className = 'bhc-btn bhc-video-overlay-continue';
                            continueBtn.textContent = correct ? 'Correct — continue' : 'Continue';
                            continueBtn.addEventListener('click', dismiss);
                            overlay!.querySelector('.bhc-video-overlay-card')!.appendChild(continueBtn);
                        });
                    });
                } else {
                    overlay.innerHTML =
                        '<div class="bhc-video-overlay-card">' +
                        '<p class="bhc-video-overlay-text">' + escText(a.payload.text) + '</p>' +
                        '<button type="button" class="bhc-btn bhc-video-overlay-continue">Continue</button>' +
                        '</div>';
                    overlay.querySelector('.bhc-video-overlay-continue')!.addEventListener('click', dismiss);
                }

                wrap!.appendChild(overlay);
            }

            media!.on('timeupdate', function () {
                if (overlay) return; // already paused on one; don't stack a second
                for (var i = 0; i < annotations.length; i++) {
                    if (shown[i]) continue;
                    var a = annotations[i]!;
                    if (media!.currentTime() >= a.time) {
                        showAnnotation(a, i);
                        break;
                    }
                }
            });
            // A student rewinding past an already-shown annotation
            // shouldn't be trapped by it again — same "resume, don't
            // relitigate" posture a real interactive-video player takes.
            media!.on('seeked', function () {
                for (var i = 0; i < annotations.length; i++) {
                    if (media!.currentTime() < annotations[i]!.time) shown[i] = false;
                }
            });
        });

        // Real YouTube-style chapter markers ON the seek bar itself, plus
        // a clickable chapter list beneath the video.
        //
        // Why a custom control bar at all: native `<video controls>` is
        // drawn by the browser engine, not the page — no CSS or JS can
        // reach inside it to paint markers on its scrubber. Supplying
        // the bar ourselves (via Plyr) is the only way, and is exactly
        // what every player with visible chapter markers does. The
        // player itself is created once in the shared adapter pass
        // above; this block only draws chrome onto it.
        //
        // Degrades honestly: with no Plyr at all, a real <video> keeps
        // its native controls and the chapter LIST below still renders
        // and still seeks — only the on-scrubber markers are lost, being
        // the one piece that cannot exist without a custom bar.
        lesson.querySelectorAll<HTMLElement>('.bhc-step-video').forEach(function (video) {
            var media = mediaFor(video);
            if (!media) return;
            var wrap = video.closest('.bhc-video-wrap');
            if (!wrap || !wrap.parentNode) return;

            var chapters: BHCVideoChapter[] = [];
            if (video.dataset.chapters) {
                try {
                    var parsed = JSON.parse(video.dataset.chapters);
                    if (Array.isArray(parsed)) chapters = parsed;
                } catch (e) { /* malformed chapter data — treat as none, never break playback */ }
            }

            var player = media.player;

            function formatTime(seconds: number): string {
                var m = Math.floor(seconds / 60);
                var s = Math.floor(seconds % 60);
                return m + ':' + (s < 10 ? '0' : '') + s;
            }

            function escText(s: unknown) {
                var d = document.createElement('div');
                d.textContent = s == null ? '' : String(s);
                return d.innerHTML;
            }

            function seekTo(seconds: number) {
                media!.seek(seconds);
                media!.play();
            }

            if (!chapters.length) return;

            /* ---- markers drawn directly on Plyr's own progress bar ---- */
            var markers: HTMLElement[] = [];
            function buildMarkers() {
                if (!player) return;
                var duration = media!.duration();
                if (!duration) return;
                var progress = (wrap as HTMLElement).querySelector('.plyr__progress');
                if (!progress) return;

                markers.forEach(function (m) { m.remove(); });
                markers = [];

                chapters.forEach(function (chapter, i) {
                    // A chapter authored past the real runtime (a shorter
                    // file swapped in later) has nowhere on the bar to
                    // sit — skip rather than pin a misleading marker at
                    // 100%.
                    if (chapter.time > duration) return;
                    var marker = document.createElement('button');
                    marker.type = 'button';
                    marker.className = 'bhc-plyr-chapter-marker';
                    marker.style.left = ((chapter.time / duration) * 100) + '%';
                    marker.title = (chapter.title || 'Chapter ' + (i + 1)) + ' — ' + formatTime(chapter.time);
                    marker.setAttribute('aria-label', 'Jump to chapter: ' + (chapter.title || 'Chapter ' + (i + 1)));
                    marker.addEventListener('click', function (e) {
                        // Plyr's progress bar is itself a seek control —
                        // without this the click both hits the marker AND
                        // scrubs to wherever the pointer landed, which is
                        // a few pixels off from the chapter's real start.
                        e.preventDefault();
                        e.stopPropagation();
                        seekTo(chapter.time);
                    });
                    progress!.appendChild(marker);
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

            var items: HTMLElement[] = [];
            chapters.forEach(function (chapter, i) {
                var item = document.createElement('li');
                item.className = 'bhc-video-chapter-item';
                item.innerHTML = '<button type="button" class="bhc-video-chapter-item-btn">'
                    + '<span class="bhc-video-chapter-item-time">' + formatTime(chapter.time) + '</span>'
                    + '<span class="bhc-video-chapter-item-title">' + escText(chapter.title || 'Chapter ' + (i + 1)) + '</span>'
                    + '</button>';
                item.querySelector('button')!.addEventListener('click', function () { seekTo(chapter.time); });
                list.appendChild(item);
                items.push(item);
            });

            function highlightActive() {
                var t = media!.currentTime();
                var activeIndex = 0;
                for (var i = 0; i < chapters.length; i++) {
                    if (t >= chapters[i]!.time) activeIndex = i;
                }
                items.forEach(function (item, i) { item.classList.toggle('active', i === activeIndex); });
                markers.forEach(function (m, i) { m.classList.toggle('active', i === activeIndex); });
            }

            // Plyr rebuilds its own controls on a source change, and its
            // progress bar doesn't exist until 'ready' — hooking both
            // that and loadedmetadata covers "markers survive whenever
            // the bar or the duration is (re)established".
            if (player) player.on('ready', buildMarkers);
            if (media.duration() > 0) buildMarkers();
            media.on('loadedmetadata', buildMarkers);
            media.on('timeupdate', highlightActive);

            wrap.parentNode.insertBefore(container, wrap.nextSibling);
        });

        lesson.addEventListener('click', function (e) {
            var target = e.target as HTMLElement;
            if (target.classList.contains('bhc-step-back')) {
                // Pure navigation — no server call, no completion state
                // touched. The target step's markup is already in the
                // DOM (rendered up front, just hidden), same as every
                // other step.
                var targetIndex = parseInt(target.dataset.targetIndex ?? '0', 10);
                lesson!.querySelectorAll<HTMLElement>('.bhc-step, [data-step-index]').forEach(function (el) { el.style.display = 'none'; });
                var nextBlock = lesson!.querySelector('.bhc-lesson-next') as HTMLElement | null;
                if (nextBlock) nextBlock.style.display = 'none';
                showStep(targetIndex);
                return;
            }
            if (!target.classList.contains('bhc-mark-complete')) return;
            var step = target.closest('.bhc-step, [data-step-index]') as HTMLElement;
            var index = parseInt(step.dataset.stepIndex ?? '-1', 10);

            var body = new URLSearchParams({
                action: 'bhc_mark_complete',
                nonce: BHCData.nonce,
                lesson_id: lessonId,
                step_index: String(index),
            });

            // Retry-with-backoff, matching the reference pattern this
            // ecosystem's own reports flow set (the-self-hosted-self's
            // class-reports.php) — retry-audit pass, AJ's own standing
            // ask. Marking a step complete is idempotent (the server
            // side is an upsert on lesson_id+step_index, not an insert-
            // only log), so a real network blip retrying this is safe
            // in a way it would NOT be for, say, quiz submission —
            // this call previously had no .catch() at all, so a
            // dropped connection silently failed with zero feedback.
            var btn = target as HTMLButtonElement;
            btn.disabled = true;
            var originalLabel = btn.textContent;
            submitMarkComplete(0);
            function submitMarkComplete(attempt: number) {
                fetch(BHCData.ajaxUrl, { method: 'POST', body: body })
                    .then(function (r) { return r.json() as Promise<-1 | '-1' | 0 | '0' | BHCAjaxResponse<BHCQuizSubmitResult>>; })
                    .then(function (res) {
                        // check_ajax_referer()'s default failure mode is
                        // wp_die(-1) — a bare "-1", not the {success:false,
                        // data:{...}} shape every real handler response
                        // has. That collapsed into the same generic
                        // "Something went wrong." as any other failure,
                        // when the real, actionable cause is a stale
                        // session/nonce (e.g. this tab sat open past a
                        // login timeout).
                        //
                        // A logged-OUT visitor hits a different bare
                        // response: admin-ajax.php itself replies "0"
                        // (not "-1") for an action with no
                        // wp_ajax_nopriv_* handler registered — real gap
                        // found while auditing why a logged-out click
                        // here just showed "Something went wrong."
                        // instead of a clear "log in" prompt. This is
                        // now defense-in-depth (the lesson itself
                        // requires login to view at all — see
                        // OUS_Visibility/BHC_Gate — so a logged-out
                        // visitor shouldn't normally reach this button),
                        // covering a session that expires mid-lesson.
                        if (res === -1 || res === '-1' || res === 0 || res === '0') {
                            btn.disabled = false;
                            btn.textContent = originalLabel;
                            var expiredMsg = 'You need to be logged in to save your progress — log in and try again.';
                            if (typeof BHCoreToast !== 'undefined') { BHCoreToast!.show(expiredMsg, 'error'); } else { alert(expiredMsg); }
                            return;
                        }
                        if (!res.success) {
                            btn.disabled = false;
                            btn.textContent = originalLabel;
                            var errMsg = (res.data && res.data.message) ? res.data.message : 'Something went wrong.';
                            // BHCoreToast (the-self-hosted-self core, loaded globally —
                            // see class-toast.php) is called directly here,
                            // not via the PHP-side OUS_Toast::queue() hand-off,
                            // because this is an AJAX flow with no redirect to
                            // hand a message across. typeof-guarded so this
                            // still degrades to the pre-existing alert() if
                            // the-self-hosted-self's toast script hasn't loaded for any
                            // reason (older core version, script blocked, etc.)
                            // — same "harmless no-op" posture as every other
                            // optional integration point in this ecosystem.
                            if (typeof BHCoreToast !== 'undefined') { BHCoreToast!.show(errMsg, 'error'); } else { alert(errMsg); }
                            return;
                        }
                        btn.textContent = 'Completed';
                        step.classList.add('bhc-step-done');
                        if (typeof BHCoreToast !== 'undefined') { BHCoreToast!.show('Step complete.', 'success'); }
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
                        if (typeof BHCoreToast !== 'undefined') { BHCoreToast!.show(msg, 'error'); } else { alert(msg); }
                    });
            }
        });

        lesson.addEventListener('click', function (e) {
            var target = e.target as HTMLButtonElement;
            if (!target.classList.contains('bhc-stepper-dot') || target.disabled) return;
            var targetIndex = parseInt(target.dataset.targetIndex ?? '0', 10);
            lesson!.querySelectorAll<HTMLElement>('.bhc-step, [data-step-index]').forEach(function (el) { el.style.display = 'none'; });
            var nextBlock = lesson!.querySelector('.bhc-lesson-next') as HTMLElement | null;
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
        function renderQuestionBreakdown(questions: BHCQuizQuestionResult[] | undefined) {
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

        function escapeHtml(s: string) {
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
            var target = e.target as HTMLElement;
            if (!target.matches('.bhc-quiz-choice input[type=radio]')) return;
            var fieldset = target.closest('.bhc-quiz-question');
            if (!fieldset) return;
            fieldset.querySelectorAll('.bhc-quiz-choice').forEach(function (label) {
                label.classList.remove('bhc-selected');
            });
            target.closest('.bhc-quiz-choice')!.classList.add('bhc-selected');
        });

        // Checklist step — client-side only, nothing persisted (see
        // class-render-lesson.php's own comment on why: it's a self-check
        // for the student's own benefit, never required to advance). Just
        // a visual "done" state on the item itself.
        lesson.addEventListener('change', function (e) {
            var target = e.target as HTMLInputElement;
            if (!target.matches('.bhc-checklist-check')) return;
            var li = target.closest('li');
            if (!li) return;
            li.classList.toggle('bhc-checklist-item-checked', target.checked);
        });

        lesson.addEventListener('submit', function (e) {
            var form = e.target as HTMLFormElement;
            if (!form.classList.contains('bhc-quiz-form')) return;
            e.preventDefault();
            var step = form.closest('.bhc-step, [data-step-index]') as HTMLElement;
            var index = parseInt(step.dataset.stepIndex ?? '-1', 10);

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
            var quizSubmitBtn = form.querySelector('button[type="submit"]') as HTMLButtonElement | null;
            var quizSubmitLabel = quizSubmitBtn ? quizSubmitBtn.textContent : '';
            // A label change, not just disabled=true — a disabled state
            // alone isn't reliably announced by every screen reader, so
            // this was a silent "did my click even register?" moment for
            // AT users on a slow connection, same category of gap fixed
            // elsewhere in this ecosystem's other submit buttons.
            if (quizSubmitBtn) { quizSubmitBtn.disabled = true; quizSubmitBtn.textContent = 'Submitting…'; }

            var body = new URLSearchParams({ action: 'bhc_submit_quiz', nonce: BHCData.nonce, lesson_id: lessonId, step_index: String(index) });
            var formData = new FormData(form);
            for (var pair of formData.entries()) {
                body.append('answers[' + String(pair[0]).replace('q', '') + ']', String(pair[1]));
            }

            fetch(BHCData.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json() as Promise<-1 | '-1' | 0 | '0' | BHCAjaxResponse<BHCQuizSubmitResult & { message?: string; max_attempts?: number }>>; })
                .then(function (res) {
                    var resultBox = form.querySelector('.bhc-quiz-result') as HTMLElement;
                    // Same bare 0/-1 cases as bhc_mark_complete above — a
                    // logged-out or session-expired submit here previously
                    // fell straight into the generic "Something went
                    // wrong." branch below with no indication login was
                    // the actual problem.
                    if (res === -1 || res === '-1' || res === 0 || res === '0') {
                        if (quizSubmitBtn) { quizSubmitBtn.disabled = false; quizSubmitBtn.textContent = quizSubmitLabel; }
                        resultBox.style.display = '';
                        resultBox.className = 'bhc-quiz-result bhc-fail';
                        resultBox.textContent = 'You need to be logged in to submit this quiz — log in and try again.';
                        return;
                    }
                    if (!res.success) {
                        if (quizSubmitBtn) { quizSubmitBtn.disabled = false; quizSubmitBtn.textContent = quizSubmitLabel; }
                        // Attempts-exhausted (403) comes back as an error with
                        // attempts_used/max_attempts rather than a generic
                        // failure — show it inline and lock the form instead
                        // of just alert()-ing a dead end.
                        var data = res.data || ({} as BHCQuizSubmitResult & { message?: string });
                        resultBox.style.display = '';
                        resultBox.className = 'bhc-quiz-result bhc-fail';
                        resultBox.textContent = data.message || 'Something went wrong.';
                        if (data.max_attempts) {
                            form.querySelectorAll<HTMLInputElement | HTMLButtonElement>('input, button[type="submit"]').forEach(function (el) { el.disabled = true; });
                        }
                        return;
                    }
                    var result = res.data!;
                    resultBox.style.display = '';
                    resultBox.className = 'bhc-quiz-result ' + (result.passed ? 'bhc-pass' : 'bhc-fail');
                    var attemptsNote = '';
                    if (result.max_attempts) {
                        attemptsNote = result.passed ? '' : ((result.attempts_remaining ?? 0) > 0 ? ' (' + result.attempts_remaining + ' attempt' + (result.attempts_remaining === 1 ? '' : 's') + ' remaining)' : ' (no attempts remaining)');
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
                        // Same escalated banner markup as the static
                        // revisit path (BHC_Render_Lesson::render_quiz_review())
                        // — a student sees the identical moment whether
                        // this is a fresh pass or an old one. correctCount/
                        // result.total/result.score are all server-computed
                        // numbers, never user input, so building this as
                        // HTML (not textContent) carries no injection risk.
                        resultBox.innerHTML = '<div class="bhc-quiz-pass-icon">&#10003;</div>'
                            + '<div class="bhc-datum bhc-quiz-pass-figure"><span class="bhc-datum-value">' + result.score + '%</span><span class="bhc-datum-label">quiz passed</span></div>'
                            + '<p class="bhc-quiz-pass-sub">' + correctCount + '/' + result.total + ' correct. Nice work.</p>';
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
                        form.querySelectorAll<HTMLElement>('.bhc-quiz-question').forEach(function (fs) { fs.style.display = 'none'; });
                        var breakdown = document.createElement('div');
                        breakdown.className = 'bhc-quiz-review';
                        breakdown.innerHTML = renderQuestionBreakdown(result.questions);
                        form.insertBefore(breakdown, resultBox);
                    }
                    form.querySelectorAll<HTMLInputElement>('input').forEach(function (el) { el.disabled = true; });

                    // Real bug, caught live (AJ: "if you fail a quiz, it
                    // breaks"): a fail WITH attempts remaining fell
                    // through every branch below with no code path left
                    // to re-enable the submit button or the (just
                    // force-disabled, two lines up) inputs — quizSubmitBtn
                    // stayed stuck reading "Submitting…" forever, and the
                    // "give it another shot" message right above it had
                    // no actual way to act on it. Only the pass case
                    // (swaps in "Continue") and the exhausted case
                    // (explicitly re-disables everything, already
                    // correct) ever touched the button again.
                    function resetQuizForm() {
                        var review = form.querySelector('.bhc-quiz-review');
                        if (review) review.remove();
                        form.querySelectorAll<HTMLElement>('.bhc-quiz-question').forEach(function (fs) { fs.style.display = ''; });
                        form.querySelectorAll<HTMLInputElement>('input').forEach(function (el) { el.disabled = false; el.checked = false; });
                        resultBox.style.display = 'none';
                        resultBox.textContent = '';
                        if (quizSubmitBtn) {
                            quizSubmitBtn.style.display = '';
                            quizSubmitBtn.disabled = false;
                            quizSubmitBtn.textContent = quizSubmitLabel;
                        }
                        var tryAgainBtn = form.querySelector('.bhc-quiz-try-again');
                        if (tryAgainBtn) tryAgainBtn.remove();
                    }

                    step.classList.add('bhc-step-done');
                    markStepDone(index);
                    updateSidebarProgress(result);
                    if (result.passed) {
                        // Do NOT auto-advance here — the whole point of
                        // the breakdown above is for the student to
                        // actually see it. The old behavior (immediate
                        // advance() on pass) would hide it again before
                        // anyone could read it. A real "Continue" click
                        // is required instead, same as every other step
                        // type's "Mark complete & continue" button.
                        var submitBtn = form.querySelector('.bhc-submit-quiz') as HTMLElement | null;
                        if (submitBtn) submitBtn.style.display = 'none';
                        var continueBtn = document.createElement('button');
                        continueBtn.type = 'button';
                        continueBtn.className = 'bhc-btn bhc-quiz-continue';
                        continueBtn.textContent = 'Continue';
                        continueBtn.addEventListener('click', function () { advance(index); });
                        form.appendChild(continueBtn);
                    } else if (result.max_attempts && result.attempts_remaining === 0) {
                        form.querySelectorAll<HTMLInputElement | HTMLButtonElement>('input, button[type="submit"]').forEach(function (el) { el.disabled = true; });
                    } else {
                        // Failed, but a retry is genuinely available
                        // (attempts_remaining > 0, or max_attempts is 0/
                        // unlimited) — this is the exact case that used
                        // to leave the form permanently stuck. A real
                        // "Try again" button, same pattern as the pass
                        // case's "Continue" button just above, replaces
                        // the now-inert original submit button and
                        // actually does what the result message already
                        // promises.
                        if (quizSubmitBtn) quizSubmitBtn.style.display = 'none';
                        var tryAgainBtn = document.createElement('button');
                        tryAgainBtn.type = 'button';
                        tryAgainBtn.className = 'bhc-btn bhc-btn-secondary bhc-quiz-try-again';
                        tryAgainBtn.textContent = 'Try again';
                        tryAgainBtn.addEventListener('click', resetQuizForm);
                        form.appendChild(tryAgainBtn);
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
                    var resultBox = form.querySelector('.bhc-quiz-result') as HTMLElement | null;
                    if (resultBox) {
                        resultBox.style.display = '';
                        resultBox.className = 'bhc-quiz-result bhc-fail';
                        resultBox.textContent = 'Could not reach the server — check your connection and submit again.';
                    }
                });
        });
    });
})();
