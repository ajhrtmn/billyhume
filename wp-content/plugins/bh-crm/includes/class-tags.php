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
    // ROADMAP-ux-polish-and-feature-parity-2026-07.md Section 3: "Tag
    // chips + autocomplete-from-existing-tags in the person editor,
    // replacing the current plain comma-separated text input. Contained
    // front-end change, no schema change (still a meta-array-of-strings
    // underneath)." Exactly that — the underlying storage, handle_save(),
    // and the BH_Event payload are all completely unchanged; only
    // render_editor()'s markup and this new enqueue hook are new. The
    // plain text input stays in the DOM (visually hidden once JS takes
    // over) as the actual form field the existing handle_save() reads —
    // tag-chips.js just keeps it in sync with the chip UI, so JS-off
    // degrades to exactly the old plain-text-field behavior, not a
    // broken form.
    public static function init() {
        add_action('admin_enqueue_scripts', [self::class, 'maybe_enqueue']);
    }

    public static function maybe_enqueue($hook) {
        if (empty($_GET['page']) || $_GET['page'] !== 'bh-crm' || empty($_GET['user_id'])) return;
        wp_enqueue_script('bhcrm-tag-chips', BHCRM_URL . 'assets/js/tag-chips.js', [], BHCRM_VER, true);
    }

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
        // Every distinct tag site-wide, for the autocomplete dropdown —
        // computed once per page load (all_in_use()'s own docblock
        // already covers why that's fine at this scale). Handed to
        // tag-chips.js via a data attribute rather than a separate REST
        // round trip, since it's already server-rendered right here.
        $suggestions = BHCRM_Tags::all_in_use();

        echo '<h3>Tags</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bhcrm_save_tags');
        echo '<input type="hidden" name="action" value="bhcrm_save_tags">';
        echo '<input type="hidden" name="user_id" value="' . (int) $user_id . '">';
        echo '<div class="bhcrm-tag-chips" data-suggestions="' . esc_attr(wp_json_encode($suggestions)) . '">';
        // The REAL form field tag-chips.js keeps in sync — handle_save()
        // reads THIS, unchanged, so JS-off (or a JS error) still submits
        // a working plain-text comma-separated field, just without the
        // chip UI on top of it.
        echo '<input type="text" name="tags" class="bhcrm-tag-chips-input" value="' . esc_attr(implode(', ', $tags)) . '" placeholder="comma, separated, tags" style="width:100%;max-width:400px;">';
        echo '</div>';
        echo '<p><button class="button">Save tags</button></p>';
        echo '</form>';
    }

    // ROADMAP-ux-polish-and-feature-parity-2026-07.md Section 3: "Bulk
    // actions on the person list (bulk tag, bulk export-selected) —
    // currently all-or-nothing." ADDS one tag to a person's existing
    // list rather than replacing it (unlike handle_save() above, which
    // is a full-list overwrite from the single-person editor) — bulk-
    // tagging 40 people with "vip" should never accidentally wipe out
    // whatever tags each of them already had.
    public static function add_tag($user_id, $tag) {
        $tag = trim($tag);
        if ($tag === '') return false;
        $tags = self::get($user_id);
        if (in_array($tag, $tags, true)) return true; // already tagged — not an error, just a no-op
        $tags[] = $tag;
        update_user_meta($user_id, '_bhcrm_tags', wp_json_encode($tags));
        return true;
    }

    public static function handle_bulk_tag() {
        if (!current_user_can('manage_options') || !check_admin_referer('bhcrm_bulk_action')) wp_die('Not allowed.');

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

    public static function handle_save() {
        if (!current_user_can('manage_options') || !check_admin_referer('bhcrm_save_tags')) wp_die('Not allowed.');

        $user_id = (int) ($_POST['user_id'] ?? 0);
        $tags = array_filter(array_map('trim', explode(',', sanitize_text_field($_POST['tags'] ?? ''))));
        $tags = array_values($tags);
        update_user_meta($user_id, '_bhcrm_tags', wp_json_encode($tags));

        // CRM-native event, additive only — doesn't affect the save
        // above in any way, just gives this tag update a row in the
        // shared activity stream (see class-event-activity.php). The
        // resulting tag list itself is included in the payload (short,
        // freeform-but-bounded words a staff member typed in — unlike
        // notes, there's no meaningful privacy reason to keep this one
        // out of the shared events table).
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
