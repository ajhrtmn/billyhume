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
    public static function init() {
        add_filter('bhy_style_surfaces', [self::class, 'register']);
    }

    public static function register($surfaces) {
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

    public static function preview_catalog() {
        ob_start();
        ?>
<div class="bhc-catalog">
    <div class="bhc-course-card">
        <h3>Songwriting Fundamentals <span class="bhc-lock">&#128274;</span></h3>
        <div class="bhc-excerpt">Structure, hooks, and turning a riff into a real song.</div>
    </div>
    <div class="bhc-course-card">
        <h3>Home Recording Basics</h3>
        <div class="bhc-excerpt">Mic placement, gain staging, and a simple mix chain.</div>
        <div class="bhc-progress-bar"><div class="bhc-progress-fill" style="width:60%"></div></div>
        <p class="bhc-progress-label">60% complete</p>
    </div>
</div>
        <?php
        return ['css_url' => BHC_URL . 'assets/css/courses.css', 'html' => ob_get_clean()];
    }

    public static function preview_lesson() {
        ob_start();
        ?>
<div class="bhc-lesson" data-step-count="3">
    <div class="bhc-step-progress">Step <span class="bhc-step-current">2</span> of 3</div>
    <div class="bhc-step bhc-step-image bhc-step-done">
        <div class="bhc-step-image" style="width:100%;height:120px;border-radius:6px;background:var(--bh-accent-soft);"></div>
        <p class="bhc-step-caption">A basic gain-staging diagram.</p>
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
        <?php
        return ['css_url' => BHC_URL . 'assets/css/courses.css', 'html' => ob_get_clean()];
    }
}
