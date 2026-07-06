<?php
if (!defined('ABSPATH')) exit;

/**
 * Free-text tags per person — stored as a JSON array in user meta,
 * matching the same "small list, plain meta, no new table" pattern used
 * for playlists elsewhere in this ecosystem. Good enough for the scale
 * a CRM tag list actually needs; a dedicated table would be premature
 * for what's fundamentally a short list of words per person.
 */
class BHCRM_Tags {
    public static function get($user_id) {
        $raw = get_user_meta($user_id, '_bhcrm_tags', true);
        $tags = $raw ? json_decode($raw, true) : [];
        return is_array($tags) ? $tags : [];
    }

    // Every distinct tag currently in use, across everyone — powers the
    // "filter by tag" links on the list page. Fine to compute on every
    // page load at the scale a tag list actually reaches; revisit if
    // this ever needs to scale past a few thousand people.
    public static function all_in_use() {
        global $wpdb;
        $raw_values = $wpdb->get_col("SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = '_bhcrm_tags'");
        $all = [];
        foreach ($raw_values as $raw) {
            $tags = json_decode($raw, true);
            if (is_array($tags)) $all = array_merge($all, $tags);
        }
        return array_values(array_unique($all));
    }

    public static function render_editor($user_id) {
        $tags = self::get($user_id);
        echo '<h3>Tags</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bhcrm_save_tags');
        echo '<input type="hidden" name="action" value="bhcrm_save_tags">';
        echo '<input type="hidden" name="user_id" value="' . (int) $user_id . '">';
        echo '<input type="text" name="tags" value="' . esc_attr(implode(', ', $tags)) . '" placeholder="comma, separated, tags" style="width:100%;max-width:400px;">';
        echo '<p><button class="button">Save tags</button></p>';
        echo '</form>';
    }

    public static function handle_save() {
        if (!current_user_can('manage_options') || !check_admin_referer('bhcrm_save_tags')) wp_die('Not allowed.');

        $user_id = (int) ($_POST['user_id'] ?? 0);
        $tags = array_filter(array_map('trim', explode(',', sanitize_text_field($_POST['tags'] ?? ''))));
        update_user_meta($user_id, '_bhcrm_tags', wp_json_encode(array_values($tags)));

        wp_safe_redirect(add_query_arg(['page' => 'bh-crm', 'user_id' => $user_id, 'bhcrm_msg' => rawurlencode('Tags saved.')], admin_url('admin.php')));
        exit;
    }
}
