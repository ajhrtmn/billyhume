<?php
if (!defined('ABSPATH')) exit;

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

        wp_safe_redirect(add_query_arg(['page' => 'bh-crm', 'user_id' => $user_id, 'bhcrm_msg' => rawurlencode('Notes saved.')], admin_url('admin.php')));
        exit;
    }
}
