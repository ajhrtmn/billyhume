<?php
if (!defined('ABSPATH')) exit;

/**
 * First real BH_Element integration for bh-courses (DESIGN-SUITE-
 * UNIFICATION-PLAN.md "NO SPECIAL-CASED PAGES" — LMS lesson pages, the
 * "1" in AJ's "Do 3, then 2, then 1" ordering; data-binding v1 and the
 * Gutenberg block are the already-shipped "3" and "2"). Before this,
 * bh-courses had ZERO BH_Element surface registered anywhere — only a
 * hand-authored HTML mockup in class-style-surface.php (BHC_StyleSurface,
 * a Style Gallery PREVIEW, not real node-tree content). This file is the
 * real thing: an editable, per-lesson node-tree area.
 *
 * Mirrors BHCRM_People::register_element_surface() (bh-crm/includes/
 * class-people.php) exactly, including its own already-learned lesson —
 * ONE 'root' slot, not several framework-chosen zones. CRM shipped three
 * slots (header/main/sidebar) in 1.1.2 and had to collapse them to one in
 * 1.3.3 once it was clear "how many zones does this page have" is a
 * product decision, not something to guess up front. Starting lessons at
 * one slot from the start avoids repeating that exact mistake.
 *
 * Context is per-LESSON (surface_context_id = the bh_lesson post ID),
 * not per-course and not per-user — every student viewing the same
 * lesson sees the same authored content, matching how the rest of a
 * lesson's content (its steps) already works.
 *
 * Scope: registration + one render_slot() call appended
 * after the existing step-walker output in BHC_Render::render_lesson_steps()
 * (see that method's own comment at the call site) — an optional
 * "extras" area (a downloadable-resources list, a related-reading
 * callout, embedded promo, etc.) rendered once per lesson,
 * AFTER a student clears every step. Deliberately NOT touching the step
 * walker's own step-by-step output (text/image/video/quiz) — that
 * content is authored through BH_Studio/BH_Content already
 * (LMS-AUTHORING-DESIGN-PLAN.md, 0.3.0) and is a completely different,
 * pre-existing system; this surface is additive, a new area, not a
 * replacement for that one.
 */
class BHC_LessonSurface {
    public static function register_element_surface($surfaces) {
        $surfaces['bh_courses_lesson'] = [
            'group'       => 'Courses',
            'label'       => 'Lesson page extras',
            'slots'       => [
                'root' => ['label' => 'Below-lesson content'],
            ],
            'context'     => ['type' => 'post', 'param' => 'lesson_id'],
            // Preview context for the builder GUI's canvas — the most
            // recently published lesson stands in as a representative
            // subject, same "no single 'the' lesson exists outside a
            // real id" reasoning BHCRM_People's own preview_ctx uses for
            // profiles. Falls back to 0 (an empty, harmless slot) if no
            // lesson exists yet on this install.
            'preview_ctx' => function () {
                $recent = get_posts(['post_type' => 'bh_lesson', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids']);
                return ['lesson_id' => $recent ? (int) $recent[0] : 0];
            },
        ];
        return $surfaces;
    }
}
