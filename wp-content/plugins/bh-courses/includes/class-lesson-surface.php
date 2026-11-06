<?php
if (!defined('ABSPATH')) exit;

/**
 * BH_Element surface for lessons: one 'root' slot per lesson (context = the bh_lesson ID),
 * rendered once after the step walker. Additive; step content stays in the blocks.
 */
class BHC_LessonSurface {
    /**
     * @param array<string, mixed> $surfaces
     * @return array<string, mixed>
     */
    public static function register_element_surface($surfaces): array {
        $surfaces['bh_courses_lesson'] = [
            'group'       => 'Courses',
            'label'       => 'Lesson page extras',
            'slots'       => [
                'root' => ['label' => 'Below-lesson content'],
            ],
            'context'     => ['type' => 'post', 'param' => 'lesson_id'],
            // Preview context: the most recently published lesson, or 0 if none.
            'preview_ctx' => function () {
                $recent = get_posts(['post_type' => 'bh_lesson', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids']);
                return ['lesson_id' => $recent ? (int) $recent[0] : 0];
            },
        ];
        return $surfaces;
    }
}
