<?php
if (!defined('ABSPATH')) exit;

/**
 * Registers representative previews of this plugin's own front-end UI
 * into the shared Style gallery (`bhy_style_surfaces`) — same extension
 * mechanism bh-streaming and bh-contest already use, so a course's
 * cards, progress bars, lesson steps, and quiz UI all get styled from
 * the same shared design tokens (colors/typography/spacing) as every
 * other plugin, and can be live-previewed without needing real course
 * data or a logged-in student.
 *
 * Two separate surfaces rather than one, since a course-card grid and
 * a mid-lesson quiz step are genuinely different layouts worth
 * previewing/tuning independently.
 */
class BHC_StyleSurface {
    public static function init(): void {
        add_filter('bhy_style_surfaces', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $surfaces
     * @return array<string, mixed>
     */
    public static function register($surfaces): array {
        $surfaces['bh-courses-catalog'] = [
            'group' => 'Courses', 'label' => 'Course Catalog',
            'render' => [self::class, 'preview_catalog'],
        ];
        $surfaces['bh-courses-lesson'] = [
            'group' => 'Courses', 'label' => 'Lesson Steps & Quiz',
            'render' => [self::class, 'preview_lesson'],
        ];
        return $surfaces;
    }

    /**
     * Real card markup, not a thinner stand-in — same classes/structure
     * BHC_Render_Catalog::render_course_card() builds (thumbnail-or-
     * placeholder poster, difficulty badge, buy-once badge, instructor
     * avatar, lesson count, rich-text excerpt, progress), so tuning a
     * token here actually shows what a visitor sees at /courses/. One
     * card has a real thumbnail image, one uses the accent-gradient
     * placeholder, one is locked with a price badge — the three shapes
     * that grid actually has to handle.
     *
     * @return array{css_url:string, html:string|false}
     */
    public static function preview_catalog(): array {
        ob_start();
        ?>
<div class="bhc-catalog ous-catalog-grid">
    <div class="bhc-course-card ous-catalog-card">
        <div class="bhc-card-thumb bhc-card-thumb-placeholder">
            <div class="bhc-card-thumb-scrim" aria-hidden="true"></div>
            <h3 class="bh-clamp-2 bhc-card-thumb-title"><a href="#">Mixing Basics for Bedroom Producers</a></h3>
        </div>
        <div class="bhc-card-meta">
            <span class="bh-badge bhc-badge bhc-badge-difficulty bhc-difficulty-beginner">Beginner</span>
            <span class="bhc-card-lesson-count">6 lessons</span>
        </div>
        <div class="bhc-card-instructor"><span class="bhc-avatar-fallback" style="display:inline-block;width:20px;height:20px;border-radius:50%;background:var(--bh-accent-soft);vertical-align:middle;"></span> <span>Billy Hume</span></div>
        <div class="bhc-excerpt bhc-step-text">
            <p>A practical intro to EQ, compression, and levels. Covers:</p>
            <ul><li>Gain staging before anything else</li><li>Cut first, boost second</li></ul>
        </div>
        <div class="bhc-footer-spacer">
            <div class="bhc-progress-bar"><div class="bhc-progress-fill" style="width:60%"></div></div>
            <p class="bhc-progress-label">60% complete</p>
        </div>
    </div>
    <div class="bhc-course-card ous-catalog-card bhc-locked">
        <div class="bhc-card-thumb">
            <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='225'%3E%3Crect width='400' height='225' fill='%23555'/%3E%3C/svg%3E" alt="" style="object-fit:cover;width:100%;height:100%;">
            <div class="bhc-card-thumb-scrim" aria-hidden="true"></div>
            <h3 class="bh-clamp-2 bhc-card-thumb-title"><a href="#">Mastering for Bedroom Producers</a> <span class="bhc-lock">&#128274;</span></h3>
        </div>
        <div class="bhc-card-meta">
            <span class="bh-badge bhc-badge bhc-badge-difficulty bhc-difficulty-intermediate">Intermediate</span>
            <span class="bhc-card-lesson-count">3 lessons</span>
            <span class="bhc-buy-once-badge">Buy once — $29.00</span>
        </div>
        <div class="bhc-excerpt bhc-step-text"><p>Take your bedroom mixes to a polished, loud, release-ready master.</p></div>
    </div>
</div>
        <?php
        return ['css_url' => BHC_URL . 'assets/css/courses.css', 'html' => ob_get_clean()];
    }

    /**
     * The FULL lesson-taking layout — sidebar + video + caption + a
     * text step + a quiz step together, not an isolated fragment — so
     * the "Lesson sidebar width" / "Lesson video max width" / caption
     * size+font sliders (BHC_VideoSettings' custom_sliders()/
     * custom_fonts() registrations) actually show their effect here,
     * matching the real .bhc-lesson-layout shell
     * (BHC_Render_Course::render_lesson_sidebar()) and per-step video
     * wrapper (BHC_Render_Lesson::render_step()) class-for-class.
     *
     * @return array{css_url:string, html:string|false}
     */
    public static function preview_lesson(): array {
        ob_start();
        ?>
<div class="bhc-lesson-layout">
    <nav class="bhc-course-sidebar" aria-label="Course lessons">
        <a class="bhc-sidebar-course-link" href="#">Mixing Basics for Bedroom Producers</a>
        <div class="bhc-portal-progress-bar" style="height:6px;border-radius:3px;background:var(--bh-surface-2);overflow:hidden;margin-bottom:10px;"><div style="height:100%;width:40%;background:var(--bh-accent);"></div></div>
        <ol class="bhc-sidebar-lesson-list" style="list-style:none;margin:0;padding:0;">
            <li><a href="#">Gain Staging and Levels &#10003;</a></li>
            <li class="bhc-lesson-current"><a href="#">EQ Fundamentals</a></li>
            <li><a href="#">Compression Basics</a></li>
            <li><a href="#">Putting It All Together</a></li>
        </ol>
    </nav>
    <div class="bhc-lesson-main">
        <div class="bhc-step-progress">Step <span class="bhc-step-current">1</span> of 3</div>
        <div class="bhc-step bhc-step-video">
            <div class="bhc-video-wrap">
                <div class="bhc-step-video-bunny" style="display:flex;align-items:center;justify-content:center;color:var(--bh-text-dim);">Video</div>
            </div>
            <p class="bhc-step-caption bhc-step-text">
                <strong>Cut first, boost second.</strong> Sweep a narrow bell filter across the low-mids to find and tame the mud before you reach for anything else.
            </p>
            <button type="button" class="bhc-btn bhc-mark-complete" disabled>Completed</button>
        </div>
        <div class="bhc-step bhc-step-quiz">
            <form class="bhc-quiz-form">
                <fieldset class="bhc-quiz-question">
                    <legend>Where should you set input gain to avoid clipping?</legend>
                    <label class="bhc-quiz-choice"><input type="radio" name="q0"> As high as possible</label>
                    <label class="bhc-quiz-choice"><input type="radio" name="q0" checked> Just under the loudest peak</label>
                    <label class="bhc-quiz-choice"><input type="radio" name="q0"> It doesn't matter</label>
                </fieldset>
                <button type="submit" class="bhc-btn bhc-submit-quiz">Submit answers</button>
            </form>
        </div>
    </div>
</div>
        <?php
        return ['css_url' => BHC_URL . 'assets/css/courses.css', 'html' => ob_get_clean()];
    }
}
