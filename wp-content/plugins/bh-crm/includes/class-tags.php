<?php
if (!defined('ABSPATH')) exit;

/** Free-text tags per person, stored as a JSON array in user meta. */
class BHCRM_Tags {
    // tag-chips.js enhances the plain text input; with JS off the plain field still submits.
    public static function init(): void {
        add_action('admin_enqueue_scripts', [self::class, 'maybe_enqueue']);
    }

    public static function maybe_enqueue(string $hook): void {
        if (empty($_GET['page']) || $_GET['page'] !== 'bh-crm' || empty($_GET['user_id'])) return;
        wp_enqueue_script('bhcrm-tag-chips', BHCRM_URL . 'assets/js/tag-chips.js', [], BHCRM_VER, true);
    }

    /** @return array<int, string> */
    public static function get(int $user_id): array {
        $raw = get_user_meta($user_id, '_bhcrm_tags', true);
        $tags = $raw ? json_decode($raw, true) : [];
        return is_array($tags) ? $tags : [];
    }

    // Every distinct tag in use; computed per page load, fine at CRM scale.
    /** @return array<int, string> */
    public static function all_in_use(): array {
        global $wpdb;
        $raw_values = $wpdb->get_col("SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = '_bhcrm_tags'");
        $all = [];
        foreach ($raw_values as $raw) {
            $tags = json_decode($raw, true);
            if (is_array($tags)) $all = array_merge($all, $tags);
        }
        return array_values(array_unique($all));
    }

    public static function render_editor(int $user_id): void {
        $tags = self::get($user_id);
        // Handed to tag-chips.js via a data attribute.
        $suggestions = BHCRM_Tags::all_in_use();

        echo '<h3>Tags</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bhcrm_save_tags');
        echo '<input type="hidden" name="action" value="bhcrm_save_tags">';
        echo '<input type="hidden" name="user_id" value="' . (int) $user_id . '">';
        echo '<div class="bhcrm-tag-chips" data-suggestions="' . esc_attr(wp_json_encode($suggestions)) . '">';
        // The real submitted field; tag-chips.js keeps it in sync.
        echo '<input type="text" name="tags" class="bhcrm-tag-chips-input" value="' . esc_attr(implode(', ', $tags)) . '" placeholder="comma, separated, tags" style="width:100%;max-width:400px;">';
        echo '</div>';
        echo '<p><button class="button">Save tags</button></p>';
        echo '</form>';
    }

    // Bulk tagging ADDS one tag; it never replaces a person's existing tags.
    public static function add_tag(int $user_id, string $tag): bool {
        $tag = trim($tag);
        if ($tag === '') return false;
        $tags = self::get($user_id);
        if (in_array($tag, $tags, true)) return true; // already tagged — not an error, just a no-op
        $tags[] = $tag;
        update_user_meta($user_id, '_bhcrm_tags', wp_json_encode($tags));
        return true;
    }

    public static function handle_bulk_tag(): void {
        if (!current_user_can('bhcore_manage_crm') || !check_admin_referer('bhcrm_bulk_action')) wp_die('Not allowed.');

        $tag = sanitize_text_field(wp_unslash($_POST['bulk_tag'] ?? ''));
        $ids = array_map('intval', (array) ($_POST['bulk_ids'] ?? []));
        $count = 0;
        if ($tag !== '') {
            foreach ($ids as $uid) {
                if ($uid && self::add_tag($uid, $tag)) $count++;
            }
        }

        if (class_exists('OUS_Toast')) {
            OUS_Toast::queue($tag === '' ? 'Enter a tag to apply.' : "Tagged $count " . ($count === 1 ? 'person' : 'people') . " with \"$tag\".", $tag === '' ? 'error' : 'success');
        }

        wp_safe_redirect(add_query_arg(['page' => 'bh-crm', 'bhcrm_msg' => rawurlencode($tag === '' ? 'Enter a tag to apply.' : "Tagged $count people.")], admin_url('admin.php')));
        exit;
    }

    public static function handle_save(): void {
        if (!current_user_can('bhcore_manage_crm') || !check_admin_referer('bhcrm_save_tags')) wp_die('Not allowed.');

        $user_id = (int) ($_POST['user_id'] ?? 0);
        $tags = array_filter(array_map('trim', explode(',', sanitize_text_field($_POST['tags'] ?? ''))));
        $tags = array_values($tags);
        update_user_meta($user_id, '_bhcrm_tags', wp_json_encode($tags));

        // Unlike notes, the tag list is safe to include in the shared event payload.
        if ($user_id && class_exists('BH_Event')) {
            BH_Event::emit('bhcrm/tags_saved', [
                'user_id' => $user_id,
                'subject_type' => 'user', 'subject_id' => $user_id,
                'payload' => ['tags' => $tags],
            ]);
        }

        wp_safe_redirect(add_query_arg(['page' => 'bh-crm', 'user_id' => $user_id, 'bhcrm_msg' => rawurlencode('Tags saved.')], admin_url('admin.php')));
        exit;
    }
}
