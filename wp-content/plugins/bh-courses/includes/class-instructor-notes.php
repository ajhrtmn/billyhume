<?php
if (!defined('ABSPATH')) exit;

/**
 * Ecosystem depth-pass Tier 1e — instructor notes: a private,
 * NON-STUDENT-FACING note field on a lesson. One note per lesson (not
 * per student) — trivial postmeta, no schema change, matching the
 * plan's own scoping ("private note field on a lesson row = trivial
 * postmeta + capability gate on bhcore_manage_students"). Never
 * rendered anywhere on the front end — this is a WP-admin-only metabox
 * on the lesson's own edit screen, same capability
 * `BHC_ProgressAdmin`/`BHC_Reviews` already gate on.
 */
class BHC_InstructorNotes {
    const META_KEY = '_bhc_instructor_note';

    private static function required_cap() {
        return class_exists('OUS_Roles') ? 'bhcore_manage_students' : 'edit_posts';
    }

    public static function init() {
        add_action('add_meta_boxes', [self::class, 'add_metabox']);
        add_action('save_post_bh_lesson', [self::class, 'save']);
    }

    public static function add_metabox() {
        if (!current_user_can(self::required_cap())) return;
        add_meta_box(
            'bhc_instructor_notes', 'Instructor Notes (private — never shown to students)',
            [self::class, 'render_metabox'], 'bh_lesson', 'normal', 'default'
        );
    }

    public static function render_metabox($post) {
        wp_nonce_field('bhc_instructor_notes_save', 'bhc_instructor_notes_nonce');
        $note = get_post_meta($post->ID, self::META_KEY, true);
        echo '<textarea name="bhc_instructor_note" rows="4" style="width:100%;" placeholder="Private notes for instructors/reviewers only — students never see this.">' . esc_textarea($note) . '</textarea>';
    }

    public static function save($post_id) {
        if (!isset($_POST['bhc_instructor_notes_nonce']) || !wp_verify_nonce($_POST['bhc_instructor_notes_nonce'], 'bhc_instructor_notes_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can(self::required_cap())) return;
        update_post_meta($post_id, self::META_KEY, isset($_POST['bhc_instructor_note']) ? sanitize_textarea_field(wp_unslash($_POST['bhc_instructor_note'])) : '');
    }
}
