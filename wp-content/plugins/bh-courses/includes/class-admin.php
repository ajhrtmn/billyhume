<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin authoring: a course's lesson order + optional tier gate, and a
 * lesson's ordered steps. The step builder is a plain repeater (add a
 * text/image/quiz step, drag to reorder, remove) backed by one hidden
 * JSON field — same "one structured field, a bit of JS to edit it"
 * shape bh-streaming uses for quality encodes (`_bhs_audio_qualities`,
 * saved as wp_json_encode from a small set of named inputs), scaled up
 * to an arbitrary-length, arbitrary-type list here.
 */
class BHC_Admin {
    public static function add_meta_boxes() {
        add_meta_box('bhc_course_details', 'Course Details', [self::class, 'render_course_metabox'], 'bh_course', 'normal', 'high');
        add_meta_box('bhc_lesson_details', 'Lesson Details', [self::class, 'render_lesson_metabox'], 'bh_lesson', 'normal', 'high');
        add_meta_box('bhc_lesson_steps', 'Lesson Steps', [self::class, 'render_steps_metabox'], 'bh_lesson', 'normal', 'high');
    }

    public static function enqueue_admin_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
        if (!in_array(get_post_type(), ['bh_course', 'bh_lesson'], true)) return;
        wp_enqueue_media();
        wp_enqueue_script('bhc-admin', BHC_URL . 'assets/js/admin.js', ['jquery'], BHC_VER, true);
        wp_enqueue_style('bhc-admin', BHC_URL . 'assets/css/admin.css', [], BHC_VER);
    }

    /* ---------------- course metabox ---------------- */

    public static function render_course_metabox($post) {
        wp_nonce_field('bhc_save_course', 'bhc_course_nonce');

        $lesson_ids = BHC_PostTypes::lesson_order($post->ID);
        $all_lessons = get_posts([
            'post_type' => 'bh_lesson', 'numberposts' => -1, 'post_status' => ['publish', 'draft'],
            'meta_key' => '_bhc_course_id', 'meta_value' => $post->ID,
            'orderby' => 'title', 'order' => 'ASC',
        ]);
        // Keep lessons in their saved order first, then any not-yet-ordered lesson.
        $ordered = [];
        foreach ($lesson_ids as $id) {
            foreach ($all_lessons as $l) if ($l->ID === $id) { $ordered[] = $l; break; }
        }
        foreach ($all_lessons as $l) if (!in_array($l, $ordered, true)) $ordered[] = $l;

        echo '<p class="description">Drag to reorder. Only lessons whose "Belongs to course" field (below, on the lesson itself) points here show up. Add a new lesson from <a href="' . esc_url(admin_url('post-new.php?post_type=bh_lesson')) . '">Add New Lesson</a>, set its course, then it appears here to order.</p>';
        echo '<ul id="bhc-lesson-order-list" style="max-width:500px;">';
        foreach ($ordered as $l) {
            echo '<li class="bhc-order-item" draggable="true" data-id="' . (int) $l->ID . '" style="padding:8px 10px;border:1px solid #ccc;margin-bottom:4px;background:#fff;cursor:move;">'
               . '&#8942;&#8942; ' . esc_html($l->post_title) . ' <em style="color:#888;">(' . esc_html($l->post_status) . ')</em></li>';
        }
        echo '</ul>';
        echo '<input type="hidden" name="bhc_lesson_order" id="bhc_lesson_order" value="' . esc_attr(implode(',', array_map(fn($l) => $l->ID, $ordered))) . '">';

        if (class_exists('BHM_Tiers')) {
            $required = BHC_Gate::required_tier($post->ID);
            $required_benefit = BHC_Gate::required_benefit($post->ID);
            echo '<h4>Supporter access</h4><p class="description">Optional — leave both set to "open" for a fully open course. Requires BH Monetization.</p>';

            echo '<p><label><strong>Gate by tier price rank</strong><br><select name="bhc_required_tier"><option value="0">— Open to everyone —</option>';
            foreach (BHM_Tiers::all() as $tier) {
                echo '<option value="' . (int) $tier['id'] . '"' . selected($required, $tier['id'], false) . '>' . esc_html($tier['name']) . '</option>';
            }
            echo '</select></label></p>';

            // The fine-grained alternative (BHM_Gate::user_has_benefit())
            // — "any tier granting THIS benefit," independent of price
            // rank. If both this and the tier-rank select above are set,
            // required_benefit() wins (see BHC_Gate::user_can_access_course()) —
            // stated here too so an author editing this screen isn't
            // surprised which one actually took effect.
            echo '<p><label><strong>OR gate by specific benefit</strong> <span class="description">(takes priority over the tier-rank select above if set)</span><br><select name="bhc_required_benefit"><option value="">— Use tier-rank select instead —</option>';
            foreach (BHM_Tiers::benefit_registry() as $key => $label) {
                echo '<option value="' . esc_attr($key) . '"' . selected($required_benefit, $key, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></label></p>';
        } else {
            echo '<p class="description"><em>Install &amp; activate BH Monetization to gate this course behind a supporter tier.</em></p>';
        }
    }

    public static function save_course($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhc_course_nonce']) || !wp_verify_nonce($_POST['bhc_course_nonce'], 'bhc_save_course')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['bhc_lesson_order'])) {
            $ids = array_filter(array_map('intval', explode(',', $_POST['bhc_lesson_order'])));
            update_post_meta($post_id, '_bhc_lesson_order', $ids);
        }
        // Only ever written if bh-monetization-woo is active enough to
        // have rendered the select above — a crafted POST on a site
        // without it does nothing harmful (BHM_Gate simply isn't
        // consulted when class_exists('BHM_Gate') is false anyway).
        if (isset($_POST['bhc_required_tier']) && class_exists('BHM_Tiers')) {
            update_post_meta($post_id, '_bhm_required_tier', (int) $_POST['bhc_required_tier']);
        }
        if (isset($_POST['bhc_required_benefit']) && class_exists('BHM_Tiers')) {
            $key = sanitize_key($_POST['bhc_required_benefit']);
            $known_keys = array_keys(BHM_Tiers::benefit_registry());
            update_post_meta($post_id, '_bhm_required_benefit', in_array($key, $known_keys, true) ? $key : '');
        }
    }

    /* ---------------- lesson metabox (course assignment) ---------------- */

    public static function render_lesson_metabox($post) {
        wp_nonce_field('bhc_save_lesson', 'bhc_lesson_nonce');
        $current_course = BHC_PostTypes::course_for_lesson($post->ID);
        $courses = get_posts(['post_type' => 'bh_course', 'numberposts' => -1, 'post_status' => ['publish', 'draft']]);

        echo '<p><label>Belongs to course<br><select name="bhc_course_id">';
        echo '<option value="0">— none yet —</option>';
        foreach ($courses as $c) {
            echo '<option value="' . (int) $c->ID . '"' . selected($current_course, $c->ID, false) . '>' . esc_html($c->post_title) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p class="description">After saving, go to that course\'s own edit screen to place this lesson in the lesson order.</p>';

        // Drip scheduling — see class-gate.php's docblock: exactly one
        // of these two, never both, matching "self-paced" vs. "scheduled
        // cohort" as genuinely different shapes rather than one combined
        // concept. Blank both = opens immediately once the course itself
        // is unlocked (unchanged default behavior).
        $after_days = get_post_meta($post->ID, '_bhc_available_after_days', true);
        $on_date = get_post_meta($post->ID, '_bhc_available_on_date', true);
        echo '<h4>Availability (optional)</h4>';
        echo '<p class="description">Leave both blank to open as soon as the course itself unlocks. Fill in at most one.</p>';
        echo '<p><label>Available this many days after a student enrolls: <input type="number" min="0" name="bhc_available_after_days" value="' . esc_attr($after_days) . '" style="width:80px;"></label></p>';
        echo '<p><label>OR available on a fixed date for everyone: <input type="date" name="bhc_available_on_date" value="' . esc_attr($on_date) . '"></label></p>';
    }

    /* ---------------- lesson metabox (steps builder) ---------------- */

    public static function render_steps_metabox($post) {
        wp_nonce_field('bhc_save_steps', 'bhc_steps_nonce');
        $steps = BHC_Steps::get($post->ID);
        echo '<div id="bhc-steps-builder" data-steps=\'' . esc_attr(wp_json_encode($steps)) . '\'></div>';
        echo '<input type="hidden" name="bhc_steps_json" id="bhc_steps_json">';
        echo '<p class="description">A lesson is a sequence of steps — mix text, images, and quizzes in any order. Students see one step at a time and move forward as they complete each one.</p>';
    }

    public static function save_lesson($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['bhc_lesson_nonce']) && wp_verify_nonce($_POST['bhc_lesson_nonce'], 'bhc_save_lesson')) {
            if (isset($_POST['bhc_course_id'])) update_post_meta($post_id, '_bhc_course_id', (int) $_POST['bhc_course_id']);

            $after_days = sanitize_text_field($_POST['bhc_available_after_days'] ?? '');
            $on_date = sanitize_text_field($_POST['bhc_available_on_date'] ?? '');
            // Enforce "at most one" server-side too, not just via the
            // form's own description text — a fixed date takes priority
            // if a crafted or careless POST somehow sets both.
            if ($on_date !== '') {
                update_post_meta($post_id, '_bhc_available_on_date', $on_date);
                delete_post_meta($post_id, '_bhc_available_after_days');
            } elseif ($after_days !== '') {
                update_post_meta($post_id, '_bhc_available_after_days', max(0, (int) $after_days));
                delete_post_meta($post_id, '_bhc_available_on_date');
            } else {
                delete_post_meta($post_id, '_bhc_available_after_days');
                delete_post_meta($post_id, '_bhc_available_on_date');
            }
        }

        if (isset($_POST['bhc_steps_nonce']) && wp_verify_nonce($_POST['bhc_steps_nonce'], 'bhc_save_steps')) {
            if (isset($_POST['bhc_steps_json'])) {
                $decoded = json_decode(wp_unslash($_POST['bhc_steps_json']), true);
                BHC_Steps::save($post_id, is_array($decoded) ? $decoded : []);
            }
        }
    }

    /* ---------------- list table ---------------- */

    public static function course_columns($cols) {
        $new = [];
        foreach ($cols as $k => $v) {
            $new[$k] = $v;
            if ($k === 'title') { $new['bhc_lessons'] = 'Lessons'; $new['bhc_gate'] = 'Access'; }
        }
        return $new;
    }

    public static function course_column_content($col, $post_id) {
        if ($col === 'bhc_lessons') echo count(BHC_PostTypes::lesson_order($post_id));
        if ($col === 'bhc_gate') {
            $tier = BHC_Gate::required_tier($post_id);
            if (!$tier || !class_exists('BHM_Tiers')) { echo 'Open'; return; }
            $t = BHM_Tiers::get($tier);
            echo esc_html($t['name'] ?? 'Gated');
        }
    }
}
