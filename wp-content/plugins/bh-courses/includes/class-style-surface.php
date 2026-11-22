<?php
if (!defined('ABSPATH')) exit;

/**
 * Registers previews of this plugin's front-end UI in the Style gallery
 * (`bhy_style_surfaces`): one for the catalog grid, one for the lesson layout.
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
            'group' => 'Courses', 'label' => BHC_Words::get('admin.style_surface.course_catalog'),
            'render' => [self::class, 'preview_catalog'],
        ];
        $surfaces['bh-courses-lesson'] = [
            'group' => 'Courses', 'label' => BHC_Words::get('admin.style_surface.lesson_steps_quiz'),
            'render' => [self::class, 'preview_lesson'],
        ];
        return $surfaces;
    }

    /**
     * Real catalog card markup (same classes as BHC_Render_Catalog): one with a thumbnail,
     * one placeholder, one locked with a price badge.
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
            <h3 class="bh-clamp-2 bhc-card-thumb-title"><a href="#"><?php echo BHC_Words::get('admin.style_surface.mixing_basics_bedroom_producers'); ?></a></h3>
        </div>
        <div class="bhc-card-meta">
            <span class="bh-badge bhc-badge bhc-badge-difficulty bhc-difficulty-beginner"><?php echo BHC_Words::get('admin.style_surface.beginner'); ?></span>
            <span class="bhc-card-lesson-count">6 lessons</span>
        </div>
        <div class="bhc-card-instructor"><span class="bhc-avatar-fallback" style="display:inline-block;width:20px;height:20px;border-radius:50%;background:var(--bh-accent-soft);vertical-align:middle;"></span> <span>Billy Hume</span></div>
        <div class="bhc-excerpt bhc-step-text">
            <p><?php echo BHC_Words::get('admin.style_surface.practical_intro_eq_compression_levels'); ?></p>
            <ul><li><?php echo BHC_Words::get('admin.style_surface.gain_staging_before_anything_else'); ?></li><li><?php echo BHC_Words::get('admin.style_surface.cut_first_boost_second'); ?></li></ul>
        </div>
        <div class="bhc-footer-spacer">
            <div class="bhc-progress-bar"><div class="bhc-progress-fill" style="width:60%"></div></div>
            <p class="bhc-progress-label"><?php echo BHC_Words::get('admin.style_surface.60_complete'); ?></p>
        </div>
    </div>
    <div class="bhc-course-card ous-catalog-card bhc-locked">
        <div class="bhc-card-thumb">
            <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='225'%3E%3Crect width='400' height='225' fill='%23555'/%3E%3C/svg%3E" alt="" style="object-fit:cover;width:100%;height:100%;">
            <div class="bhc-card-thumb-scrim" aria-hidden="true"></div>
            <h3 class="bh-clamp-2 bhc-card-thumb-title"><a href="#"><?php echo BHC_Words::get('admin.style_surface.mastering_bedroom_producers'); ?></a> <span class="bhc-lock">&#128274;</span></h3>
        </div>
        <div class="bhc-card-meta">
            <span class="bh-badge bhc-badge bhc-badge-difficulty bhc-difficulty-intermediate"><?php echo BHC_Words::get('admin.style_surface.intermediate'); ?></span>
            <span class="bhc-card-lesson-count">3 lessons</span>
            <span class="bhc-buy-once-badge">Buy once — $29.00</span>
        </div>
        <div class="bhc-excerpt bhc-step-text"><p><?php echo BHC_Words::get('admin.style_surface.take_bedroom_mixes_polished_loud'); ?></p></div>
    </div>
</div>
        <?php
        return ['css_url' => BHC_URL . 'assets/css/courses.css', 'html' => ob_get_clean()];
    }

    /**
     * The full lesson layout (sidebar, video, caption, text and quiz steps), class-for-class,
     * so the lesson sliders/fonts show their effect.
     *
     * @return array{css_url:string, html:string|false}
     */
    public static function preview_lesson(): array {
        ob_start();
        ?>
<div class="bhc-lesson-layout">
    <nav class="bhc-course-sidebar" aria-label="Course lessons">
        <a class="bhc-sidebar-course-link" href="#"><?php echo BHC_Words::get('admin.style_surface.mixing_basics_bedroom_producers'); ?></a>
        <div class="bhc-portal-progress-bar" style="height:6px;border-radius:3px;background:var(--bh-surface-2);overflow:hidden;margin-bottom:10px;"><div style="height:100%;width:40%;background:var(--bh-accent);"></div></div>
        <ol class="bhc-sidebar-lesson-list" style="list-style:none;margin:0;padding:0;">
            <li><a href="#">Gain Staging and Levels &#10003;</a></li>
            <li class="bhc-lesson-current"><a href="#"><?php echo BHC_Words::get('admin.style_surface.eq_fundamentals'); ?></a></li>
            <li><a href="#"><?php echo BHC_Words::get('admin.style_surface.compression_basics'); ?></a></li>
            <li><a href="#"><?php echo BHC_Words::get('admin.style_surface.putting_all_together'); ?></a></li>
        </ol>
    </nav>
    <div class="bhc-lesson-main">
        <div class="bhc-step-progress"><?php echo BHC_Words::get('admin.style_surface.step'); ?> <span class="bhc-step-current">1</span> of 3</div>
        <div class="bhc-step bhc-step-video">
            <div class="bhc-video-wrap">
                <div class="bhc-step-video-bunny" style="display:flex;align-items:center;justify-content:center;color:var(--bh-text-dim);"><?php echo BHC_Words::get('admin.style_surface.video'); ?></div>
            </div>
            <p class="bhc-step-caption bhc-step-text">
                <?php echo BHC_Words::get('admin.style_surface.cut_first_boost_second_sweep'); ?>

            </p>
            <button type="button" class="bhc-btn bhc-mark-complete" disabled><?php echo BHC_Words::get('admin.crm_integration.completed'); ?></button>
        </div>
        <div class="bhc-step bhc-step-quiz">
            <form class="bhc-quiz-form">
                <fieldset class="bhc-quiz-question">
                    <legend><?php echo BHC_Words::get('admin.style_surface.where_should_set_input_gain'); ?></legend>
                    <label class="bhc-quiz-choice"><input type="radio" name="q0"> <?php echo BHC_Words::get('admin.style_surface.as_high_as_possible'); ?></label>
                    <label class="bhc-quiz-choice"><input type="radio" name="q0" checked> <?php echo BHC_Words::get('admin.style_surface.just_under_loudest_peak'); ?></label>
                    <label class="bhc-quiz-choice"><input type="radio" name="q0"> <?php echo BHC_Words::get('admin.style_surface.doesn_t_matter'); ?></label>
                </fieldset>
                <button type="submit" class="bhc-btn bhc-submit-quiz"><?php echo BHC_Words::get('admin.style_surface.submit_answers'); ?></button>
            </form>
        </div>
    </div>
</div>
        <?php
        return ['css_url' => BHC_URL . 'assets/css/courses.css', 'html' => ob_get_clean()];
    }
}
