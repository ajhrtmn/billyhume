<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin authoring: a course's lesson order + optional tier gate, and a
 * lesson's ordered steps.
 *
 * The step builder USED TO BE a plain repeater backed by one hidden
 * JSON field (assets/js/admin.js's now-inert #bhc-steps-builder code —
 * left in place, self-guards on the div's absence, harmless). As of
 * the LMS-authoring wiring pass (see LMS-AUTHORING-DESIGN-PLAN.md),
 * render_steps_metabox() below instead links out to BH_Studio's own
 * canvas, and BHC_ContentBridge::save_tree() (called from Studio's
 * REST route) is the only writer of lesson step content — the old
 * bhc_steps_json POST handling in save_lesson() was removed outright
 * rather than left as a second writer of the same data.
 */
class BHC_Admin {
    public static function add_meta_boxes() {
        add_meta_box('bhc_course_details', 'Course Details', [self::class, 'render_course_metabox'], 'bh_course', 'normal', 'high');
        // Separate box, not folded into Course Details above — this is
        // purely catalog/browse metadata (instructor, difficulty,
        // duration), a genuinely different concern from lesson ordering
        // and tier-gating, and keeping it separate matches how bh-streaming
        // splits its own catalog-facing fields from its own
        // access/monetization ones across separate metaboxes.
        add_meta_box('bhc_course_catalog', 'Catalog Details', [self::class, 'render_catalog_metabox'], 'bh_course', 'side', 'default');
        add_meta_box('bhc_lesson_details', 'Lesson Details', [self::class, 'render_lesson_metabox'], 'bh_lesson', 'normal', 'high');
        add_meta_box('bhc_lesson_steps', 'Lesson Steps', [self::class, 'render_steps_metabox'], 'bh_lesson', 'normal', 'high');
    }

    /* ---------------- course metabox: catalog details ---------------- */

    // Instructor/difficulty/duration — the new fields QUIZ-AND-CATALOG-
    // DESIGN-PLAN.md Part 2.2 scopes. Category/topic (bhc_course_category/
    // bhc_course_topic) are real taxonomies (class-post-types.php) and
    // get WordPress's own standard category/tag meta boxes automatically
    // — no custom UI needed or written for those here.
    public static function render_catalog_metabox($post) {
        wp_nonce_field('bhc_save_catalog', 'bhc_catalog_nonce');

        $instructor_id = (int) get_post_meta($post->ID, '_bhc_instructor_id', true);
        $difficulty = BHC_PostTypes::difficulty($post->ID);
        $duration_note = BHC_PostTypes::duration_note($post->ID);

        // Any user who can at least author a course-adjacent post is a
        // reasonable instructor candidate — same "who is even eligible"
        // bar WordPress's own author dropdown uses (edit_posts).
        $candidates = get_users(['capability' => 'edit_posts', 'orderby' => 'display_name']);
        echo '<p><label><strong>Instructor</strong><br><select name="bhc_instructor_id" style="width:100%;">';
        echo '<option value="0">— Post author (' . esc_html(get_the_author_meta('display_name', (int) $post->post_author)) . ') —</option>';
        foreach ($candidates as $u) {
            echo '<option value="' . (int) $u->ID . '"' . selected($instructor_id, $u->ID, false) . '>' . esc_html($u->display_name ?: $u->user_login) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p class="description">Shown on the catalog and course page. Leave on the default to use whoever authored this post.</p>';

        echo '<p><label><strong>Difficulty</strong><br><select name="bhc_difficulty" style="width:100%;">';
        echo '<option value="">— Not set —</option>';
        foreach (BHC_PostTypes::difficulty_registry() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($difficulty, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label></p>';

        echo '<p><label><strong>Duration (optional)</strong><br><input type="text" name="bhc_duration_note" value="' . esc_attr($duration_note) . '" placeholder="e.g. ~4 hours of video" style="width:100%;"></label></p>';
        echo '<p class="description">The catalog always shows a computed lesson count (' . (int) BHC_PostTypes::lesson_count($post->ID) . ' lesson' . (BHC_PostTypes::lesson_count($post->ID) === 1 ? '' : 's') . ' right now) whether or not this is filled in — this is an optional, more human estimate shown alongside it.</p>';

        echo '<p class="description">Category and tags: see the standard <strong>Course Categories</strong> / <strong>Course Topics</strong> boxes elsewhere on this screen.</p>';
    }

    public static function save_catalog_details($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhc_catalog_nonce']) || !wp_verify_nonce($_POST['bhc_catalog_nonce'], 'bhc_save_catalog')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['bhc_instructor_id'])) {
            update_post_meta($post_id, '_bhc_instructor_id', (int) $_POST['bhc_instructor_id']);
        }
        if (isset($_POST['bhc_difficulty'])) {
            $key = sanitize_key($_POST['bhc_difficulty']);
            $known = array_keys(BHC_PostTypes::difficulty_registry());
            update_post_meta($post_id, '_bhc_difficulty', in_array($key, $known, true) ? $key : '');
        }
        if (isset($_POST['bhc_duration_note'])) {
            update_post_meta($post_id, '_bhc_duration_note', sanitize_text_field($_POST['bhc_duration_note']));
        }
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

    // Replaced the old bhc-steps-builder repeater with a link out to
    // BH_Studio's own canvas (LMS-AUTHORING-DESIGN-PLAN.md Section 5.3's
    // "interim path," and Section 5's suggested sequencing step 5:
    // "replace render_steps_metabox()'s repeater with the embedded
    // canvas; retire the legacy metabox to close the dual-write
    // hazard"). This metabox no longer writes step content at all —
    // see save_lesson() below, which dropped the bhc_steps_json write
    // path entirely rather than leaving two writers pointed at the same
    // data. BHC_ContentBridge::get_tree()'s existing lazy-derive-from-
    // _bhc_steps fallback means a lesson authored under the old
    // repeater still opens correctly in Studio the first time — no bulk
    // migration required before this ships (the "Rebuild all lesson
    // content trees" Debug Tools button remains available for anyone
    // who wants to force it up front instead).
    public static function render_steps_metabox($post) {
        $steps = BHC_Steps::get($post->ID);
        $studio_available = class_exists('BH_Studio') && class_exists('BHC_ContentBridge');

        // Per-step content labels rather than just a comma-separated list
        // of TYPES — the original Studio-migration pass regressed
        // information density here (a 10-step lesson used to at least
        // show "Text, Image, Text, Quiz…"; that's still indistinguishable
        // step-to-step). describe_step() below pulls an actual snippet
        // from each step's own stored content.
        if ($steps) {
            echo '<p class="description">Current steps (' . count($steps) . '):</p>';
            echo '<ol class="bhc-steps-summary" style="margin:0 0 12px 22px;padding:0;">';
            foreach ($steps as $s) {
                echo '<li>' . esc_html(self::describe_step($s)) . '</li>';
            }
            echo '</ol>';
        } else {
            echo '<p class="description">No steps yet.</p>';
        }

        if ($studio_available) {
            $studio_url = admin_url('admin.php?page=bh-studio&context_type=' . rawurlencode(BHC_ContentBridge::CONTEXT) . '&context_id=' . (int) $post->ID);
            echo '<p><a class="button button-primary" href="' . esc_url($studio_url) . '">' . ($steps ? 'Edit lesson content in Content Studio &rarr;' : 'Build lesson content in Content Studio &rarr;') . '</a> ';
            // "Preview as student" — the actual public permalink, not a
            // Studio-internal preview, since the whole point is seeing
            // the real step-walker (BHC_Render::render_lesson_steps())
            // a student gets, including gating/drip state. Published
            // posts link straight to the permalink; a draft lesson (the
            // common case while an instructor is still building it out)
            // uses WordPress's own preview-link mechanism instead, since
            // a draft's permalink 404s for anyone without edit rights —
            // get_preview_post_link() handles the preview nonce/query
            // args this needs.
            $preview_url = get_post_status($post->ID) === 'publish' ? get_permalink($post->ID) : get_preview_post_link($post->ID);
            echo '<a class="button" href="' . esc_url($preview_url) . '" target="_blank" rel="noopener">Preview as student &rarr;</a></p>';
            echo '<p class="description">A lesson is a sequence of steps — mix text, images, and quizzes in any order in the Studio canvas. Students see one step at a time and move forward as they complete each one.</p>';
        } else {
            // Own Ur Shit is present (this metabox only registers at
            // all when bh-courses is active alongside it) but either an
            // older core without BH_Studio, or BHC_ContentBridge itself
            // didn't load (BH_Content missing) — degrade to plain
            // information rather than a dead link or, worse, silently
            // showing nothing with no explanation.
            echo '<p class="description"><strong>Content Studio isn\'t available</strong> — this needs a newer Own Ur Shit core (adds BH_Studio/BH_Content). Update the core plugin to author this lesson\'s steps.</p>';
        }
    }

    // One line of real content per step, not just its type — reads
    // straight off the legacy $step array shape (BHC_Steps::get()'s own
    // return format), the same shape whether a lesson was authored via
    // the old repeater or the Studio canvas (BHC_ContentBridge::save_tree()
    // keeps _bhc_steps in sync either way, see that class's docblock).
    private static function describe_step($step) {
        $type = $step['type'] ?? '?';
        switch ($type) {
            case 'text':
                $snippet = wp_trim_words(wp_strip_all_tags((string) ($step['content'] ?? '')), 10, '…');
                return 'Text — ' . ($snippet !== '' ? $snippet : '(empty)');
            case 'image':
                $count = count($step['attachment_ids'] ?? []);
                $label = 'Image — ' . $count . ' image' . ($count === 1 ? '' : 's');
                return $step['caption'] ? $label . ': ' . wp_trim_words((string) $step['caption'], 8, '…') : $label;
            case 'video':
                if (($step['source'] ?? 'upload') === 'url') {
                    return 'Video — ' . ($step['video_url'] ? wp_trim_words((string) $step['video_url'], 8, '…') : '(no URL set)');
                }
                return 'Video — uploaded file' . (($step['attachment_id'] ?? 0) ? '' : ' (none selected yet)');
            case 'quiz':
                $qcount = count($step['questions'] ?? []);
                return 'Quiz — ' . $qcount . ' question' . ($qcount === 1 ? '' : 's') . ' (passing score ' . (int) ($step['passing_score'] ?? 70) . '%)';
            default:
                return ucfirst($type);
        }
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

        // The old bhc_steps_json write path is gone (see
        // render_steps_metabox() above) — BHC_ContentBridge::save_tree(),
        // called from BH_Studio's own REST save route, is now the ONLY
        // writer of a lesson's step content. Two writers pointed at the
        // same _bhc_steps data was the exact "dual-write divergence"
        // hazard LMS-AUTHORING-DESIGN-PLAN.md Section 6 flagged; closing
        // it by removing the second writer outright (rather than adding
        // reconciliation logic) is that doc's own preferred resolution.
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
