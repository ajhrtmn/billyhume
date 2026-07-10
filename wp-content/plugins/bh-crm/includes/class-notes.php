<?php
if (!defined('ABSPATH')) exit;

// BHCRM_VER 1.1.0 -> 1.1.1 — handle_save() now also queues a toast
// (OUS_Toast::queue(), own-ur-shit 3.4.18+) alongside the existing
// $_GET['bhcrm_msg'] plain-text admin notice this already redirects
// with — additive only, that notice is unchanged. Degrades to a no-op if
// own-ur-shit hasn't shipped OUS_Toast yet (older core version), same
// class_exists() guard the BH_Event call just below already uses.

/**
 * One freeform note per person — plain user meta, no new table needed
 * for something this simple. Admin-only, never exposed anywhere public.
 */
class BHCRM_Notes {
    public static function get($user_id) {
        return get_user_meta($user_id, '_bhcrm_notes', true);
    }

    public static function render_editor($user_id) {
        echo '<h3>Notes</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bhcrm_save_note');
        echo '<input type="hidden" name="action" value="bhcrm_save_note">';
        echo '<input type="hidden" name="user_id" value="' . (int) $user_id . '">';
        echo '<textarea name="notes" rows="4" style="width:100%;max-width:600px;">' . esc_textarea(self::get($user_id)) . '</textarea>';
        echo '<p><button class="button">Save notes</button></p>';
        echo '</form>';
    }

    public static function handle_save() {
        if (!current_user_can('manage_options') || !check_admin_referer('bhcrm_save_note')) wp_die('Not allowed.');

        $user_id = (int) ($_POST['user_id'] ?? 0);
        update_user_meta($user_id, '_bhcrm_notes', sanitize_textarea_field($_POST['notes'] ?? ''));

        // CRM-native event, additive only — doesn't affect the save
        // above in any way, just gives this note update a row in the
        // shared activity stream (see class-event-activity.php). The
        // note text itself is never put in the payload — it's freeform
        // admin-only content with no business duplicated into a table
        // other plugins may read.
        if ($user_id && class_exists('BH_Event')) {
            BH_Event::emit('bhcrm/note_saved', [
                'user_id' => $user_id,
                'subject_type' => 'user', 'subject_id' => $user_id,
            ]);
        }

        if (class_exists('OUS_Toast')) {
            OUS_Toast::queue('Notes saved.', 'success');
        }

        wp_safe_redirect(add_query_arg(['page' => 'bh-crm', 'user_id' => $user_id, 'bhcrm_msg' => rawurlencode('Notes saved.')], admin_url('admin.php')));
        exit;
    }
}
