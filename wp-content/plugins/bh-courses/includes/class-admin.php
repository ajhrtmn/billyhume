<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin authoring: a course's lesson order + optional tier gate, and a
 * lesson's ordered steps. Lesson step content is written only by
 * BHC_ContentBridge's save_post_bh_lesson hook.
 */
class BHC_Admin {
    public static function add_meta_boxes(): void {
        add_meta_box('bhc_course_details', 'Course Details', [self::class, 'render_course_metabox'], 'bh_course', 'normal', 'high');
        add_meta_box('bhc_course_downloads', 'Downloads', ['BHC_Downloads', 'render_admin_box'], 'bh_course', 'normal', 'high');
        // Course config lives in postmeta, so it needs its own OUS_Revisions history.
        add_meta_box('bhc_course_revisions', 'Version History', [self::class, 'render_course_revisions_metabox'], 'bh_course', 'side', 'low');
        add_meta_box('bhc_course_catalog', 'Catalog Details', [self::class, 'render_catalog_metabox'], 'bh_course', 'side', 'default');
        add_meta_box('bhc_course_site_menu', 'Site Menu', [self::class, 'render_site_menu_metabox'], 'bh_course', 'side', 'default');
        // Lesson settings live in the editor sidebar (registerLessonPanel() in courses-studio-blocks.ts).
    }

    // REST-exposed for the "Lesson" sidebar panel; save_lesson() covers the classic
    // $_POST path. reconcile_lesson_placement() keeps course<->lesson order consistent either way.
    public static function register_lesson_meta(): void {
        $auth = function ($allowed, $meta_key, $post_id) {
            return current_user_can('edit_post', $post_id);
        };
        register_post_meta('bh_lesson', '_bhc_course_id', [
            'type' => 'integer', 'single' => true, 'show_in_rest' => true, 'default' => 0,
            'auth_callback' => $auth,
        ]);
        register_post_meta('bh_lesson', '_bhc_module_title', [
            'type' => 'string', 'single' => true, 'show_in_rest' => true, 'default' => '',
            'sanitize_callback' => 'sanitize_text_field', 'auth_callback' => $auth,
        ]);
        register_post_meta('bh_lesson', '_bhc_available_after_days', [
            'type' => 'string', 'single' => true, 'show_in_rest' => true, 'default' => '',
            'sanitize_callback' => 'sanitize_text_field', 'auth_callback' => $auth,
        ]);
        register_post_meta('bh_lesson', '_bhc_available_on_date', [
            'type' => 'string', 'single' => true, 'show_in_rest' => true, 'default' => '',
            'sanitize_callback' => 'sanitize_text_field', 'auth_callback' => $auth,
        ]);
    }

    // Ensures this lesson sits in exactly its current course's _bhc_lesson_order.
    // Idempotent, so every save path can call it.
    public static function reconcile_lesson_placement(int $lesson_id): void {
        if (get_post_type($lesson_id) !== 'bh_lesson') return;
        $current = (int) get_post_meta($lesson_id, '_bhc_course_id', true);
        if ($current && get_post_type($current) !== 'bh_course') {
            $current = 0;
            update_post_meta($lesson_id, '_bhc_course_id', 0);
        }
        $course_ids = get_posts([
            'post_type' => 'bh_course', 'post_status' => 'any',
            'numberposts' => -1, 'fields' => 'ids',
        ]);

        // WHY: older data linked lessons only via the course-side order. Without this
        // backfill the first reconcile would drop the lesson from that order.
        if (!$current) {
            $owners = [];
            foreach ($course_ids as $cid) {
                if (in_array((int) $lesson_id, BHC_PostTypes::lesson_order((int) $cid), true)) $owners[] = (int) $cid;
            }
            if (count($owners) === 1) {
                $current = $owners[0];
                update_post_meta($lesson_id, '_bhc_course_id', $current);
            }
        }
        foreach ($course_ids as $cid) {
            $order = BHC_PostTypes::lesson_order((int) $cid);
            $has = in_array((int) $lesson_id, $order, true);
            if ((int) $cid === $current && !$has) {
                $order[] = (int) $lesson_id;
                update_post_meta((int) $cid, '_bhc_lesson_order', array_values($order));
            } elseif ((int) $cid !== $current && $has) {
                update_post_meta((int) $cid, '_bhc_lesson_order', array_values(array_diff($order, [(int) $lesson_id])));
            }
        }
    }

    /* ---------------- course metabox: catalog details ---------------- */

    // Category/topic are taxonomies with WordPress's own standard meta boxes.
    public static function render_catalog_metabox(\WP_Post $post): void {
        wp_nonce_field('bhc_save_catalog', 'bhc_catalog_nonce');

        $instructor_id = (int) get_post_meta($post->ID, '_bhc_instructor_id', true);
        $difficulty = BHC_PostTypes::difficulty($post->ID);
        $duration_note = BHC_PostTypes::duration_note($post->ID);

        $candidates = get_users(['capability' => 'edit_posts', 'orderby' => 'display_name']);
        echo ('<p><label><strong>' . BHC_Words::get('admin.admin.instructor') . '</strong><br><select name="bhc_instructor_id" style="width:100%;">');
        echo ('<option value="0">' . BHC_Words::get('admin.admin.post_author')) . esc_html(get_the_author_meta('display_name', (int) $post->post_author)) . ') —</option>';
        foreach ($candidates as $u) {
            echo '<option value="' . (int) $u->ID . '"' . selected($instructor_id, $u->ID, false) . '>' . esc_html($u->display_name ?: $u->user_login) . '</option>';
        }
        echo '</select></label></p>';
        echo ('<p class="description">' . BHC_Words::get('admin.admin.shown_catalog_course_page_leave') . '</p>');

        echo ('<p><label><strong>' . BHC_Words::get('admin.admin.difficulty') . '</strong><br><select name="bhc_difficulty" style="width:100%;">');
        echo ('<option value="">' . BHC_Words::get('admin.admin.not_set') . '</option>');
        foreach (BHC_PostTypes::difficulty_registry() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($difficulty, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label></p>';

        echo ('<p><label><strong>' . BHC_Words::get('admin.admin.duration_optional') . '</strong><br><input type="text" name="bhc_duration_note" value="') . esc_attr($duration_note) . '" placeholder="e.g. ~4 hours of video" style="width:100%;"></label></p>';
        echo ('<p class="description">' . BHC_Words::get('admin.admin.catalog_always_shows_computed_lesson')) . (int) BHC_PostTypes::lesson_count($post->ID) . ' lesson' . (BHC_PostTypes::lesson_count($post->ID) === 1 ? '' : 's') . (' ' . BHC_Words::get('admin.admin.right_now_whether_not_filled') . '</p>');

        echo ('<p class="description">' . BHC_Words::get('admin.admin.category_tags_see_standard_course') . '</p>');
    }

    public static function render_site_menu_metabox(\WP_Post $post): void {
        wp_nonce_field('bhc_save_menu', 'bhc_menu_nonce');
        if (get_post_status($post->ID) !== 'publish') {
            echo ('<p class="description">' . BHC_Words::get('admin.admin.publish_course_first_course_with') . '</p>');
            return;
        }
        $checked = (bool) get_post_meta($post->ID, '_bhc_show_in_menu', true);
        $label = get_post_meta($post->ID, '_bhc_menu_label', true);
        echo '<p><label><input type="checkbox" name="bhc_show_in_menu" value="1"' . checked($checked, true, false) . ('> ' . BHC_Words::get('admin.admin.show_under_courses_site_menu') . '</label></p>');
        echo ('<p><label>' . BHC_Words::get('admin.admin.menu_label_optional') . '<br><input type="text" name="bhc_menu_label" value="') . esc_attr($label) . '" placeholder="' . esc_attr($post->post_title) . '" style="width:100%;"></label></p>';

        echo ('<hr><p><strong>' . BHC_Words::get('admin.admin.page') . '</strong> ') . self::page_link_html($post->ID) . '</p>';
        echo ('<p class="description">' . BHC_Words::get('admin.admin.simple_page_with_course_s') . '</p>');
    }

    private static function page_link_html(int $course_id): string {
        $page_id = (int) get_post_meta($course_id, '_bhc_page_id', true);
        if ($page_id && get_post_status($page_id) && get_post_status($page_id) !== 'trash') {
            return '<a href="' . esc_url(get_permalink($page_id)) . ('" target="_blank">' . BHC_Words::get('admin.admin.view') . '</a> &middot; <a href="') . esc_url(get_edit_post_link($page_id)) . ('">' . BHC_Words::get('admin.admin.edit') . '</a>');
        }
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=bhc_create_page&course_id=' . (int) $course_id),
            'bhc_create_page'
        );
        return '<a href="' . esc_url($url) . ('">' . BHC_Words::get('admin.admin.create_page') . '</a>');
    }

    public static function save_site_menu_settings(int $post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhc_menu_nonce']) || !wp_verify_nonce($_POST['bhc_menu_nonce'], 'bhc_save_menu')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        update_post_meta($post_id, '_bhc_show_in_menu', !empty($_POST['bhc_show_in_menu']) ? '1' : '');
        if (isset($_POST['bhc_menu_label'])) {
            update_post_meta($post_id, '_bhc_menu_label', sanitize_text_field($_POST['bhc_menu_label']));
        }

        self::resync_course_menu();
    }

    /** Same shape as BH_Admin::resync_menu() in bh-contest — see that docblock. */
    public static function resync_course_menu(): void {
        if (!class_exists('OUS_MenuSync')) return;

        $posts = get_posts([
            'post_type'   => 'bh_course',
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_key'    => '_bhc_show_in_menu',
            'meta_value'  => '1',
            'orderby'     => 'title',
            'order'       => 'ASC',
        ]);

        $items = [];
        foreach ($posts as $p) {
            $label = get_post_meta($p->ID, '_bhc_menu_label', true) ?: $p->post_title;
            $items[] = ['label' => $label, 'url' => self::menu_url_for_course($p->ID)];
        }

        // The group label links to the native /courses/ archive.
        OUS_MenuSync::sync_group('courses', 'Courses', $items, home_url('/courses/'));
    }

    /**
     * A bh_course's own permalink is a bare single-post template, so the menu links to
     * the page embedding [bh_course id="X"]: _bhc_page_id first, then a shortcode search.
     */
    private static function menu_url_for_course(int $course_id): string {
        $page_id = (int) get_post_meta($course_id, '_bhc_page_id', true);
        if ($page_id && get_post_status($page_id) === 'publish') {
            return get_permalink($page_id);
        }

        global $wpdb;
        $like = '%[bh_course id="' . $course_id . '"%';
        $like2 = "%[bh_course id='" . $course_id . "'%";
        $found_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND (post_content LIKE %s OR post_content LIKE %s) LIMIT 1",
            $like, $like2
        ));
        if ($found_id) return get_permalink((int) $found_id);
        return get_permalink($course_id);
    }

    public static function maybe_resync_menu_for_post(int $post_id): void {
        if (get_post_type($post_id) === 'bh_course') self::resync_course_menu();
    }

    public static function save_catalog_details(int $post_id): void {
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

    public static function enqueue_admin_assets(string $hook): void {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
        if (!in_array(get_post_type(), ['bh_course', 'bh_lesson'], true)) return;
        wp_enqueue_media();
        // Vendored (no npm). Only the lesson-order list uses it.
        wp_enqueue_script('sortablejs', BHC_URL . 'assets/js/vendor/sortable.min.js', [], '1.15.6', true);
        wp_enqueue_script('bhc-admin', BHC_URL . 'assets/js/admin.js', ['sortablejs'], BHC_VER, true);
        wp_enqueue_style('bhc-admin', BHC_URL . 'assets/css/admin.css', [], BHC_VER);
    }

    /* ---------------- course metabox ---------------- */

    public static function render_course_metabox(\WP_Post $post): void {
        wp_nonce_field('bhc_save_course', 'bhc_course_nonce');

        // A draft's permalink 404s for non-editors, so drafts use the preview link.
        $course_preview_url = get_post_status($post->ID) === 'publish' ? get_permalink($post->ID) : get_preview_post_link($post->ID);
        echo '<p><a class="button button-primary" href="' . esc_url($course_preview_url) . '" target="_blank" rel="noopener">Preview as student &rarr;</a></p>';

        $lesson_ids = BHC_PostTypes::lesson_order($post->ID);
        $all_lessons = get_posts([
            'post_type' => 'bh_lesson', 'numberposts' => -1, 'post_status' => ['publish', 'draft'],
            'meta_key' => '_bhc_course_id', 'meta_value' => (string) $post->ID,
            'orderby' => 'title', 'order' => 'ASC',
        ]);
        // Keep lessons in their saved order first, then any not-yet-ordered lesson.
        $ordered = [];
        foreach ($lesson_ids as $id) {
            foreach ($all_lessons as $l) if ($l->ID === $id) { $ordered[] = $l; break; }
        }
        foreach ($all_lessons as $l) if (!in_array($l, $ordered, true)) $ordered[] = $l;

        // Prefills the new lesson's course via ?bhc_course_id=.
        $add_lesson_url = admin_url('post-new.php?post_type=bh_lesson&bhc_course_id=' . (int) $post->ID);

        $published_count = count(array_filter($ordered, fn($l) => $l->post_status === 'publish'));
        $draft_count = count($ordered) - $published_count;
        $total_steps = class_exists('BHC_Steps') ? array_sum(array_map(fn($l) => BHC_Steps::count($l->ID), $ordered)) : 0;
        echo '<div class="bhc-course-stats">'
            . '<strong>' . count($ordered) . '</strong> lesson' . (count($ordered) === 1 ? '' : 's')
            . ' &middot; <strong>' . $published_count . '</strong> published'
            . ($draft_count ? ' &middot; <strong>' . $draft_count . '</strong> draft' : '')
            . ' &middot; <strong>' . $total_steps . '</strong> total step' . ($total_steps === 1 ? '' : 's')
            . '</div>';

        echo ('<p class="description">' . BHC_Words::get('admin.admin.drag_reorder_only_lessons_whose') . ' <a href="') . esc_url($add_lesson_url) . ('">' . BHC_Words::get('admin.admin.add_new_lesson_course') . '</a></p>');
        echo '<ul id="bhc-lesson-order-list" class="bhc-order-list">';
        foreach ($ordered as $l) {
            $steps = class_exists('BHC_Steps') ? BHC_Steps::get($l->ID) : [];
            $step_count = count($steps);
            $unassign_url = wp_nonce_url(
                admin_url('admin-post.php?action=bhc_unassign_lesson&lesson_id=' . (int) $l->ID . '&course_id=' . (int) $post->ID),
                'bhc_unassign_lesson_' . $l->ID
            );
            // WHY: native <details> rather than a JS widget so it never fights SortableJS,
            // which drags only via .bhc-order-drag-handle.
            echo '<li class="bhc-order-item" data-id="' . (int) $l->ID . '">';
            echo '<div class="bhc-order-item-header">';
            echo '<span class="bhc-order-drag-handle" title="Drag to reorder">&#8942;&#8942;</span>';
            echo '<a class="bhc-order-title" href="' . esc_url(get_edit_post_link($l->ID, 'raw')) . '" title="' . esc_attr($l->post_title) . '">' . esc_html($l->post_title) . '</a>';
            echo '<em class="bhc-order-status bhc-order-status-' . esc_attr($l->post_status) . '">' . esc_html($l->post_status) . '</em>';
            echo '<a class="bhc-order-unassign" href="' . esc_url($unassign_url) . '" onclick="return confirm(\'Remove ' . esc_js($l->post_title) . ' from this course? The lesson itself isn\\\'t deleted.\');" title="Remove from this course">&times;</a>';
            echo '</div>';
            if ($step_count) {
                echo '<details class="bhc-order-steps-detail">';
                echo '<summary>' . $step_count . ' step' . ($step_count === 1 ? '' : 's') . '</summary>';
                echo '<ol class="bhc-order-steps-list">';
                foreach ($steps as $s) {
                    $type = (string) ($s['type'] ?? '');
                    echo '<li><span class="bhc-step-type-icon bhc-step-type-' . esc_attr($type) . '" aria-hidden="true">' . self::step_type_icon($type) . '</span>' . esc_html(self::describe_step($s)) . '</li>';
                }
                echo '</ol>';
                echo '<a class="bhc-order-edit-link" href="' . esc_url(get_edit_post_link($l->ID, 'raw')) . '">Edit this lesson &rarr;</a>';
                echo '</details>';
            } else {
                echo ('<p class="bhc-order-no-steps">' . BHC_Words::get('admin.admin.no_steps_yet') . '</p>');
            }
            echo '</li>';
        }
        echo '</ul>';
        echo '<input type="hidden" name="bhc_lesson_order" id="bhc_lesson_order" value="' . esc_attr(implode(',', array_map(fn($l) => $l->ID, $ordered))) . '">';

        // Off by default; existing comments stay gated to whoever can see the lesson (BHC_Comments).
        $comments_enabled = (bool) get_post_meta($post->ID, '_bhc_comments_enabled', true);
        echo '<h4>Lesson Q&amp;A</h4>';
        echo '<p><label><input type="checkbox" name="bhc_comments_enabled" value="1"' . checked($comments_enabled, true, false) . '> <strong>Enable comments/Q&amp;A on this course\'s lessons</strong></label></p>';
        echo ('<p class="description">' . BHC_Words::get('admin.admin.off_by_default_when_only') . '</p>');

        $reviews_enabled = (bool) get_post_meta($post->ID, '_bhc_reviews_enabled', true);
        echo ('<h4>' . BHC_Words::get('admin.admin.reviews') . '</h4>');
        echo '<p><label><input type="checkbox" name="bhc_reviews_enabled" value="1"' . checked($reviews_enabled, true, false) . ('> <strong>' . BHC_Words::get('admin.admin.show_star_ratings_reviews_course') . '</strong></label></p>');
        echo ('<p class="description">' . BHC_Words::get('admin.admin.off_by_default_when_off') . '</p>');

        echo ('<h4>' . BHC_Words::get('admin.admin.credits') . '</h4>');
        echo '<p><textarea name="bhc_credits" rows="4" style="width:100%;" placeholder="e.g. Mixed by …, Music by …, Filmed at …">' . esc_textarea((string) get_post_meta($post->ID, '_bhc_credits', true)) . '</textarea></p>';
        echo '<p class="description">Shown at the bottom of the course page. Links and basic formatting are allowed; leave empty to hide the section.</p>';

        echo ('<h4>' . BHC_Words::get('admin.admin.sales_page') . '</h4>');
        echo ('<p class="description">' . BHC_Words::get('admin.admin.used_by_course_sales_page') . ' <code>[bh_course_landing id="') . (int) $post->ID . ('"]</code>' . BHC_Words::get('admin.admin.course_description_above_its_about') . '</p>');
        echo ('<p><label>' . BHC_Words::get('admin.admin.tagline') . '<br><input type="text" name="bhc_landing_tagline" class="widefat" value="') . esc_attr((string) get_post_meta($post->ID, '_bhc_landing_tagline', true)) . '"></label></p>';
        echo ('<p><label>' . BHC_Words::get('admin.admin.background_video_url') . ' <span class="description">(an MP4 from the Media Library; plays muted on a loop behind the title)</span><br><input type="url" name="bhc_landing_video" class="widefat" value="') . esc_attr((string) get_post_meta($post->ID, '_bhc_landing_video', true)) . '"></label></p>';
        echo ('<p><label>' . BHC_Words::get('admin.admin.testimonials') . ' <span class="description">(one per paragraph, separated by a blank line; end with a line like "— Name")</span><br><textarea name="bhc_landing_testimonials" rows="5" class="widefat">') . esc_textarea((string) get_post_meta($post->ID, '_bhc_landing_testimonials', true)) . '</textarea></label></p>';
        echo ('<p><label>' . BHC_Words::get('admin.admin.faq') . ' <span class="description">(first line is the question, the lines under it the answer; a blank line between questions)</span><br><textarea name="bhc_landing_faq" rows="6" class="widefat">') . esc_textarea((string) get_post_meta($post->ID, '_bhc_landing_faq', true)) . '</textarea></label></p>';

        $certificate_enabled = (bool) get_post_meta($post->ID, '_bhc_certificate_enabled', true);
        $certificate_signature = (string) get_post_meta($post->ID, '_bhc_certificate_signature', true);
        echo ('<h4>' . BHC_Words::get('admin.admin.certificate_completion') . '</h4>');
        echo '<p><label><input type="checkbox" name="bhc_certificate_enabled" value="1" id="bhc_certificate_enabled"' . checked($certificate_enabled, true, false) . ('> <strong>' . BHC_Words::get('admin.admin.offer_downloadable_certificate_when_stud') . '</strong></label></p>');
        echo ('<p><label>' . BHC_Words::get('admin.admin.signed_by') . ' <span class="description">' . BHC_Words::get('admin.admin.optional_printed_certificate_e_g') . '</span><br><input type="text" name="bhc_certificate_signature" value="') . esc_attr($certificate_signature) . '" style="max-width:400px;width:100%;"></label></p>';

        // Always on; only the visual style is a choice. Styles come from BH_ShareCard::STYLES.
        $card_style = class_exists('BH_ShareCard') && BH_ShareCard::is_valid_style(get_post_meta($post->ID, '_bhc_share_card_style', true))
            ? get_post_meta($post->ID, '_bhc_share_card_style', true) : 'brand';
        echo ('<h4>' . BHC_Words::get('admin.admin.shareable_completion_image') . '</h4>');
        echo '<p class="description">A "' . esc_html(get_the_title($post->ID) ?: 'course') . ' complete!" image a student can grab from the finish screen and post/attach anywhere. <strong>Brand</strong> matches this site\'s own live colors; the <strong>Poster</strong> options are bolder, stand-alone looks.</p>';
        if (class_exists('BH_ShareCard')) {
            echo ('<p><label>' . BHC_Words::get('admin.admin.style') . '<br><select name="bhc_share_card_style">');
            foreach (BH_ShareCard::STYLES as $key => $label) {
                echo '<option value="' . esc_attr($key) . '"' . selected($card_style, $key, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></label></p>';
        }

        // Off by default, per-course opt-in.
        $leaderboard_enabled = class_exists('BHC_Leaderboard') && BHC_Leaderboard::is_enabled($post->ID);
        echo ('<h4>' . BHC_Words::get('admin.admin.top_quiz_scorers') . '</h4>');
        echo '<p><label><input type="checkbox" name="bhc_leaderboard_enabled" value="1"' . checked($leaderboard_enabled, true, false) . ('> <strong>' . BHC_Words::get('admin.admin.show_leaderboard_top_quiz_scorers') . '</strong></label></p>');
        echo ('<p class="description">' . BHC_Words::get('admin.admin.off_by_default_ranks_students') . '</p>');

        // Courses require login by default; this checkbox is the explicit public opt-out.
        echo ('<h4>' . BHC_Words::get('admin.admin.login_requirement') . '</h4>');
        echo '<p>' . OUS_Visibility::checkbox_field($post->ID, BHC_Words::get('admin.admin.public_anyone_can_view_without')) . '</p>';
        echo ('<p class="description">' . BHC_Words::get('admin.admin.off_by_default_viewing_course') . '</p>');

        if (class_exists('BHM_Tiers')) {
            $required = BHC_Gate::required_tier($post->ID);
            $required_benefit = BHC_Gate::required_benefit($post->ID);
            echo ('<h4>' . BHC_Words::get('admin.admin.supporter_access') . '</h4><p class="description">' . BHC_Words::get('admin.admin.optional_leave_both_set_open') . '</p>');

            $tier_tip = class_exists('BHY_UI') ? BHY_UI::tip('Requires the tier selected here OR any higher-priced tier — a fan on a $10 tier still gets in if this is set to a $5 tier.') : '';
            echo ('<p><label><strong>' . BHC_Words::get('admin.admin.gate_by_tier_price_rank') . '</strong>') . $tier_tip . ('<br><select name="bhc_required_tier"><option value="0">' . BHC_Words::get('admin.admin.open_everyone') . '</option>');
            foreach (BHM_Tiers::all() as $tier) {
                echo '<option value="' . (int) $tier['id'] . '"' . selected($required, $tier['id'], false) . '>' . esc_html($tier['name']) . '</option>';
            }
            echo '</select></label></p>';

            // Per-benefit gate. If both this and tier rank are set, the benefit wins
            // (BHC_Gate::user_can_access_course()).
            echo ('<p><label><strong>' . BHC_Words::get('admin.admin.gate_by_specific_benefit') . '</strong> <span class="description">' . BHC_Words::get('admin.admin.takes_priority_over_tier_rank') . '</span><br><select name="bhc_required_benefit"><option value="">' . BHC_Words::get('admin.admin.use_tier_rank_select_instead') . '</option>');
            foreach (BHM_Tiers::benefit_registry() as $key => $label) {
                echo '<option value="' . esc_attr($key) . '"' . selected($required_benefit, $key, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></label></p>';

            // One-time purchase, independent of the tier gates: either path unlocks the
            // course, and purchase ownership is checked first. $0/blank means not offered.
            // Hidden entirely when the bhc_course_purchase_enabled kill switch is off.
            if (class_exists('BHC_Gate') && BHC_Gate::purchase_feature_enabled()) {
                $purchase_price_cents = (int) get_post_meta($post->ID, '_bhc_purchase_price_cents', true);
                $purchase_tip = class_exists('BHY_UI') ? BHY_UI::tip(BHC_Words::get('admin.admin.sells_course_outright_independent_any')) : '';
                echo ('<h4>' . BHC_Words::get('admin.admin.one_time_purchase') . '</h4>');
                echo ('<p><label><strong>' . BHC_Words::get('admin.admin.buy_once_price_usd') . '</strong>') . $purchase_tip . '<br><input type="number" step="0.01" min="0" name="bhc_purchase_price" value="' . esc_attr($purchase_price_cents ? BHM_Money::price($purchase_price_cents) : '') . '" style="width:160px;" placeholder="0.00"></label></p>';
                echo ('<p class="description">' . BHC_Words::get('admin.admin.leave_blank_zero_not_offer') . '</p>');
            }
        } else {
            echo '<p class="description"><em>Install &amp; activate BH Monetization to gate this course behind a supporter tier.</em></p>';
        }
    }

    public static function save_course(int $post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhc_course_nonce']) || !wp_verify_nonce($_POST['bhc_course_nonce'], 'bhc_save_course')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['bhc_lesson_order'])) {
            $ids = array_filter(array_map('intval', explode(',', $_POST['bhc_lesson_order'])));
            update_post_meta($post_id, '_bhc_lesson_order', $ids);
        }
        update_post_meta($post_id, '_bhc_comments_enabled', !empty($_POST['bhc_comments_enabled']) ? 1 : 0);
        update_post_meta($post_id, '_bhc_reviews_enabled', !empty($_POST['bhc_reviews_enabled']) ? 1 : 0);
        BHC_Downloads::save_admin($post_id);
        if (isset($_POST['bhc_credits'])) update_post_meta($post_id, '_bhc_credits', wp_kses_post(wp_unslash($_POST['bhc_credits'])));
        if (isset($_POST['bhc_landing_tagline'])) update_post_meta($post_id, '_bhc_landing_tagline', sanitize_text_field(wp_unslash($_POST['bhc_landing_tagline'])));
        if (isset($_POST['bhc_landing_video'])) update_post_meta($post_id, '_bhc_landing_video', esc_url_raw(wp_unslash($_POST['bhc_landing_video'])));
        foreach (['testimonials', 'faq'] as $field) {
            if (isset($_POST['bhc_landing_' . $field])) update_post_meta($post_id, '_bhc_landing_' . $field, sanitize_textarea_field(wp_unslash($_POST['bhc_landing_' . $field])));
        }
        update_post_meta($post_id, '_bhc_certificate_enabled', !empty($_POST['bhc_certificate_enabled']) ? 1 : 0);
        update_post_meta($post_id, '_bhc_certificate_signature', isset($_POST['bhc_certificate_signature']) ? sanitize_text_field($_POST['bhc_certificate_signature']) : '');
        $posted_style = (string) ($_POST['bhc_share_card_style'] ?? '');
        update_post_meta($post_id, '_bhc_share_card_style', (class_exists('BH_ShareCard') && BH_ShareCard::is_valid_style($posted_style)) ? $posted_style : 'brand');
        update_post_meta($post_id, '_bhc_leaderboard_enabled', !empty($_POST['bhc_leaderboard_enabled']) ? 1 : 0);
        OUS_Visibility::save_from_request($post_id);
        // Only written when bh-monetization-woo is active.
        if (isset($_POST['bhc_required_tier']) && class_exists('BHM_Tiers')) {
            update_post_meta($post_id, '_bhm_required_tier', (int) $_POST['bhc_required_tier']);
        }
        if (isset($_POST['bhc_required_benefit']) && class_exists('BHM_Tiers')) {
            $key = sanitize_key($_POST['bhc_required_benefit']);
            $known_keys = array_keys(BHM_Tiers::benefit_registry());
            update_post_meta($post_id, '_bhm_required_benefit', in_array($key, $known_keys, true) ? $key : '');
        }
        // WHY: also gated on the purchase kill switch, so a stale POST while the
        // feature is off can't create a WooCommerce product. Product sync mirrors BHM_Tiers::save().
        if (isset($_POST['bhc_purchase_price']) && class_exists('BHM_Tiers') && class_exists('BHC_Gate') && BHC_Gate::purchase_feature_enabled()) {
            $price_cents = BHM_Money::parse($_POST['bhc_purchase_price']);
            update_post_meta($post_id, '_bhc_purchase_price_cents', $price_cents);
            if ($price_cents > 0 && class_exists('BHM_ProductSync') && class_exists('BH_Commerce') && BH_Commerce::available()) {
                BHM_ProductSync::sync_object_purchase_product($post_id, 'bh_course', $price_cents);
            }
        }

        self::maybe_create_course_page($post_id);

        // Course config lives in postmeta, not post_content, so native revisions miss it.
        // Lessons don't need this: their steps are post_content.
        if (class_exists('OUS_Revisions')) {
            OUS_Revisions::snapshot('bh_course', $post_id, self::course_meta_snapshot($post_id));
        }
    }

    /**
     * Creates a page wrapping this course's shortcode on first publish, cross-linked via
     * _bhc_page_id/_bhc_course_ref. Skipped if a live page is linked, unless $force.
     */
    public static function maybe_create_course_page(int $course_id, bool $force = false): void {
        if (!$force && get_post_status($course_id) !== 'publish') return;

        $page_id = (int) get_post_meta($course_id, '_bhc_page_id', true);
        $status  = $page_id ? get_post_status($page_id) : false;
        if ($page_id && $status && $status !== 'trash') return;

        $new_id = wp_insert_post([
            'post_title'   => get_the_title($course_id) ?: 'Course',
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => '[bh_course id="' . (int) $course_id . '"]',
        ], true);
        if (is_wp_error($new_id)) return;

        update_post_meta($course_id, '_bhc_page_id', $new_id);
        update_post_meta($new_id, '_bhc_course_ref', $course_id);
    }

    public static function create_course_page_action(): void {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_GET['_wpnonce'] ?? '', 'bhc_create_page')) {
            wp_die('Not allowed.', '', ['back_link' => true]);
        }
        $course_id = (int) ($_GET['course_id'] ?? 0);
        if ($course_id && get_post_type($course_id) === 'bh_course') self::maybe_create_course_page($course_id, true);
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=bh_course'));
        exit;
    }

    // Backlink box on the auto-created page's edit screen.
    public static function add_page_backlink_meta_box(\WP_Post $post): void {
        $course_id = (int) get_post_meta($post->ID, '_bhc_course_ref', true);
        if (!$course_id || !get_post($course_id)) return;

        add_meta_box('bhc_page_backlink', 'BH Course', function () use ($course_id) {
            echo ('<p>' . BHC_Words::get('admin.admin.page_hosts_course') . '</p>');
            echo '<p><strong>' . esc_html(get_the_title($course_id)) . '</strong></p>';
            echo '<p><a href="' . esc_url(get_edit_post_link($course_id)) . ('" class="button">' . BHC_Words::get('admin.admin.edit_course') . '</a></p>');
        }, 'page', 'side', 'high');
    }

    /* ---------------- course revisions ---------------- */

    // Single definition of "this course's current state" for snapshot and restore.
    // WHY: bulk get_post_meta($id) returns raw serialized strings; fetching each key
    // with $single = true avoids double-serializing arrays like _bhc_lesson_order on restore.
    /** @return array<string, mixed> */
    private static function course_meta_snapshot(int $post_id): array {
        $all_meta = get_post_meta($post_id);
        $flat = [];
        foreach (array_keys($all_meta) as $key) {
            if (strpos($key, '_bhc_') === 0 || strpos($key, '_bhm_') === 0) {
                $flat[$key] = get_post_meta($post_id, $key, true);
            }
        }
        return $flat;
    }

    public static function render_course_revisions_metabox(\WP_Post $post): void {
        if (!class_exists('OUS_Revisions')) {
            echo ('<p class="description">' . BHC_Words::get('admin.admin.version_history_unavailable') . '</p>');
            return;
        }
        OUS_Revisions::render_history_panel('bh_course', $post->ID, 'bhc_restore_course_revision', 'bhc_restore_course_' . $post->ID);
    }

    // Writes the snapshot's postmeta back directly rather than re-simulating a save_course() POST.
    public static function handle_restore_course_revision(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        $post_id = (int) ($_GET['object_id'] ?? 0);
        $version = (int) ($_GET['version'] ?? 0);
        if (!isset($_GET['ous_revisions_nonce']) || !wp_verify_nonce($_GET['ous_revisions_nonce'], 'bhc_restore_course_' . $post_id)) {
            wp_die(BHC_Words::get('admin.admin.invalid_request'));
        }
        if (!$post_id || get_post_type($post_id) !== 'bh_course') wp_die(BHC_Words::get('admin.admin.not_course'));

        $snapshot = class_exists('OUS_Revisions') ? OUS_Revisions::get_version('bh_course', $post_id, $version) : null;
        if (!$snapshot) wp_die(BHC_Words::get('admin.admin.version_no_longer_exists'));

        foreach ((array) $snapshot['data'] as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        // Snapshot the restore too, so it can itself be undone.
        if (class_exists('OUS_Revisions')) {
            OUS_Revisions::snapshot('bh_course', $post_id, self::course_meta_snapshot($post_id), BHC_Words::get('admin.admin.restored_from_version') . $version);
        }
        if (class_exists('OUS_Toast')) {
            OUS_Toast::queue(BHC_Words::get('admin.admin.restored_version') . $version . '.', 'success');
        }

        wp_safe_redirect(get_edit_post_link($post_id, ''));
        exit;
    }

    // Display-only glyph per step type; never gates behavior.
    private static function step_type_icon(string $type): string {
        $icons = [
            'text' => '&#9776;', 'image' => '&#128247;', 'video' => '&#9654;',
            'quiz' => '&#10067;', 'resource' => '&#128206;', 'callout' => '&#128161;',
            'checklist' => '&#9745;', 'chord-chart' => '&#127925;', 'audio-compare' => '&#9878;',
        ];
        return $icons[$type] ?? '&#8226;';
    }

    // One-line summary of a step, from BHC_Steps::get()'s array shape.
    /** @param array<string, mixed> $step */
    private static function describe_step($step): string {
        $type = $step['type'] ?? '?';
        switch ($type) {
            case 'text':
                $snippet = wp_trim_words(wp_strip_all_tags((string) ($step['content'] ?? '')), 10, '…');
                return BHC_Words::get('admin.admin.text') . ' ' . ($snippet !== '' ? $snippet : '(empty)');
            case 'image':
                $count = count($step['attachment_ids'] ?? []);
                $label = BHC_Words::get('admin.admin.image') . ' ' . $count . ' image' . ($count === 1 ? '' : 's');
                return $step['caption'] ? $label . ': ' . wp_trim_words((string) $step['caption'], 8, '…') : $label;
            case 'video':
                if (($step['source'] ?? 'upload') === 'url') {
                    return BHC_Words::get('admin.admin.video') . ' ' . ($step['video_url'] ? wp_trim_words((string) $step['video_url'], 8, '…') : BHC_Words::get('admin.admin.no_url_set'));
                }
                return BHC_Words::get('admin.admin.video_uploaded_file') . (($step['attachment_id'] ?? 0) ? '' : ' ' . BHC_Words::get('admin.admin.none_selected_yet'));
            case 'quiz':
                $qcount = count($step['questions'] ?? []);
                return BHC_Words::get('admin.admin.quiz') . ' ' . $qcount . ' question' . ($qcount === 1 ? '' : 's') . ' ' . BHC_Words::get('admin.admin.passing_score') . ' ' . (int) ($step['passing_score'] ?? 70) . '%)';
            case 'callout':
                $snippet = wp_trim_words(wp_strip_all_tags((string) ($step['content'] ?? '')), 10, '…');
                return BHC_Words::get('admin.admin.callout') . ($step['variant'] ?? 'tip') . ') — ' . ($snippet !== '' ? $snippet : '(empty)');
            case 'checklist':
                $icount = count($step['items'] ?? []);
                $label = BHC_Words::get('admin.admin.checklist') . ' ' . $icount . ' item' . ($icount === 1 ? '' : 's');
                return $step['title'] ? $label . ': ' . wp_trim_words((string) $step['title'], 8, '…') : $label;
            case 'chord-chart':
                return BHC_Words::get('admin.admin.chord_chart') . ($step['title'] ? ' — ' . wp_trim_words((string) $step['title'], 8, '…') : '');
            case 'audio-compare':
                return BHC_Words::get('admin.admin.audio_compare') . ' ' . ($step['label_a'] ?? 'A') . ' vs. ' . ($step['label_b'] ?? 'B');
            default:
                return ucfirst($type);
        }
    }

    public static function save_lesson(int $post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (get_post_type($post_id) !== 'bh_lesson') return;
        if (!current_user_can('edit_post', $post_id)) return;

        // Lesson settings are REST meta reconciled by rest_after_insert_bh_lesson.
        // This covers non-REST saves (quick edit, programmatic) only.
        if (!(defined('REST_REQUEST') && REST_REQUEST)) {
            self::reconcile_lesson_placement($post_id);
        }

        // WHY: step content is written solely by BHC_ContentBridge::sync_legacy_steps(); never add a second writer here.
    }

    private static function remove_lesson_from_order(int $course_id, int $lesson_id): void {
        $order = BHC_PostTypes::lesson_order($course_id);
        $order = array_values(array_diff($order, [(int) $lesson_id]));
        update_post_meta($course_id, '_bhc_lesson_order', $order);
    }

    private static function add_lesson_to_order(int $course_id, int $lesson_id): void {
        $order = BHC_PostTypes::lesson_order($course_id);
        if (!in_array((int) $lesson_id, $order, true)) {
            $order[] = (int) $lesson_id;
            update_post_meta($course_id, '_bhc_lesson_order', $order);
        }
    }

    // On permanent deletion, clears _bhc_course_id from lessons that pointed at the course.
    public static function cleanup_deleted_course(int $post_id): void {
        if (get_post_type($post_id) !== 'bh_course') return;
        $lessons = get_posts([
            'post_type' => 'bh_lesson', 'numberposts' => -1, 'post_status' => 'any',
            'meta_key' => '_bhc_course_id', 'meta_value' => (string) $post_id,
        ]);
        foreach ($lessons as $lesson) {
            delete_post_meta($lesson->ID, '_bhc_course_id');
        }
    }

    // On permanent deletion, removes the lesson from its course's _bhc_lesson_order.
    public static function cleanup_deleted_lesson(int $post_id): void {
        if (get_post_type($post_id) !== 'bh_lesson') return;
        $course_id = BHC_PostTypes::course_for_lesson($post_id);
        if ($course_id) self::remove_lesson_from_order($course_id, $post_id);
    }

    /* ---------------- duplicate course ----------------
       Clones every lesson independently (never shares lesson IDs between courses),
       so editing one copy never touches another. */
    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public static function course_row_actions($actions, \WP_Post $post): array {
        if ($post->post_type !== 'bh_course' || !current_user_can('edit_post', $post->ID)) return $actions;
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=bhc_duplicate_course&course_id=' . (int) $post->ID),
            'bhc_duplicate_course_' . $post->ID
        );
        $actions['bhc_duplicate'] = '<a href="' . esc_url($url) . ('">' . BHC_Words::get('admin.admin.duplicate') . '</a>');
        return $actions;
    }

    public static function handle_duplicate_course(): void {
        $course_id = (int) ($_GET['course_id'] ?? 0);
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bhc_duplicate_course_' . $course_id)) wp_die(BHC_Words::get('admin.admin.security_check_failed'), '', ['response' => 403, 'back_link' => true]);
        if (!current_user_can('edit_post', $course_id)) wp_die('Not allowed.', '', ['response' => 403, 'back_link' => true]);
        $original = get_post($course_id);
        if (!$original || $original->post_type !== 'bh_course') wp_die(BHC_Words::get('admin.admin.course_not_found'), '', ['response' => 404, 'back_link' => true]);

        $new_course_id = wp_insert_post([
            'post_type' => 'bh_course',
            'post_status' => 'draft', // never auto-publish a clone — same posture as lesson duplication
            'post_title' => $original->post_title . ' (Copy)',
            'post_content' => $original->post_content,
            'post_author' => get_current_user_id(),
        ], true);
        if (is_wp_error($new_course_id)) wp_die(BHC_Words::get('admin.admin.could_not_duplicate_course'), '', ['response' => 500, 'back_link' => true]);

        // Course-level settings only, never enrollment/progress data.
        foreach ([
            '_bhc_instructor_id', '_bhc_difficulty', '_bhc_duration_note',
            '_bhc_comments_enabled', '_bhc_reviews_enabled', '_bhc_credits', '_bhc_landing_tagline', '_bhc_landing_video', '_bhc_landing_testimonials', '_bhc_landing_faq', '_bhc_downloads', '_bhc_certificate_enabled', '_bhc_certificate_signature', '_bhc_share_card_style',
            '_bhm_required_tier', '_bhm_required_benefit',
            '_bhc_leaderboard_enabled',
        ] as $key) {
            $val = get_post_meta($course_id, $key, true);
            if ($val !== '') update_post_meta($new_course_id, $key, $val);
        }
        foreach (['bhc_course_category', 'bhc_course_topic'] as $tax) {
            $terms = wp_get_object_terms($course_id, $tax, ['fields' => 'ids']);
            if (!is_wp_error($terms) && $terms) wp_set_object_terms($new_course_id, $terms, $tax);
        }
        $thumb_id = get_post_thumbnail_id($course_id);
        if ($thumb_id) set_post_thumbnail($new_course_id, $thumb_id);

        // Every lesson gets its own clone, collected into the new course's order.
        $new_order = [];
        foreach (BHC_PostTypes::lesson_order($course_id) as $lesson_id) {
            $lesson = get_post($lesson_id);
            if (!$lesson) continue;
            $new_lesson_id = wp_insert_post([
                'post_type' => 'bh_lesson',
                'post_status' => 'draft',
                'post_title' => $lesson->post_title,
                'post_content' => $lesson->post_content,
                'post_author' => get_current_user_id(),
            ], true);
            if (is_wp_error($new_lesson_id)) continue;

            update_post_meta($new_lesson_id, '_bhc_course_id', $new_course_id);
            $steps = get_post_meta($lesson_id, '_bhc_steps', true);
            if (is_array($steps)) update_post_meta($new_lesson_id, '_bhc_steps', $steps);
            foreach (['_bhc_available_after_days', '_bhc_available_on_date', '_bhc_module_title'] as $key) {
                $val = get_post_meta($lesson_id, $key, true);
                if ($val !== '') update_post_meta($new_lesson_id, $key, $val);
            }
            $new_order[] = $new_lesson_id;
        }
        update_post_meta($new_course_id, '_bhc_lesson_order', $new_order);

        wp_safe_redirect(get_edit_post_link($new_course_id, 'raw'));
        exit;
    }

    /* ---------------- list table ---------------- */

    public static function course_column_content(string $col, int $post_id): void {
        if ($col === 'bhc_lessons') echo count(BHC_PostTypes::lesson_order($post_id));
        if ($col === 'bhc_gate') {
            // Every way in, the same order BHC_Gate checks them; nothing set means open.
            $ways = [];
            if ($benefit = BHC_Gate::required_benefit($post_id)) {
                $ways[] = BHC_Words::get('admin.admin.access_benefit', ['benefit' => $benefit]);
            } elseif ($tier = BHC_Gate::required_tier($post_id)) {
                $t = class_exists('BHM_Tiers') ? BHM_Tiers::get($tier) : null;
                $ways[] = (string) ($t['name'] ?? BHC_Words::get('admin.admin.access_gated'));
            }
            if (BHC_Gate::has_purchase_option($post_id)) {
                $ways[] = BHC_Words::get('admin.admin.access_purchase', ['price' => number_format(BHC_Gate::purchase_price_cents($post_id) / 100, 2)]);
            }
            echo esc_html($ways ? implode(BHC_Words::get('admin.admin.access_or'), $ways) : BHC_Words::get('admin.admin.access_open'));
        }
    }

    /* ---------------- duplicate lesson ---------------- */
    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public static function lesson_row_actions($actions, \WP_Post $post): array {
        if ($post->post_type !== 'bh_lesson' || !current_user_can('edit_post', $post->ID)) return $actions;
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=bhc_duplicate_lesson&lesson_id=' . (int) $post->ID),
            'bhc_duplicate_lesson_' . $post->ID
        );
        $actions['bhc_duplicate'] = '<a href="' . esc_url($url) . ('">' . BHC_Words::get('admin.admin.duplicate') . '</a>');
        return $actions;
    }

    public static function handle_duplicate_lesson(): void {
        $lesson_id = (int) ($_GET['lesson_id'] ?? 0);
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bhc_duplicate_lesson_' . $lesson_id)) wp_die(BHC_Words::get('admin.admin.security_check_failed'), '', ['response' => 403]);
        if (!current_user_can('edit_post', $lesson_id)) wp_die('Not allowed.', '', ['response' => 403]);
        $original = get_post($lesson_id);
        if (!$original || $original->post_type !== 'bh_lesson') wp_die(BHC_Words::get('admin.admin.lesson_not_found'), '', ['response' => 404]);

        // _bhc_steps is regenerated from post_content on next save; copying it too lets
        // the clone render correctly before then.
        $new_id = wp_insert_post([
            'post_type' => 'bh_lesson',
            'post_status' => 'draft', // never auto-publish a clone
            'post_title' => $original->post_title . ' (Copy)',
            'post_content' => $original->post_content,
            'post_author' => get_current_user_id(),
        ], true);
        if (is_wp_error($new_id)) wp_die(BHC_Words::get('admin.admin.could_not_duplicate_lesson'), '', ['response' => 500]);

        $course_id = (int) get_post_meta($lesson_id, '_bhc_course_id', true);
        if ($course_id) {
            update_post_meta($new_id, '_bhc_course_id', $course_id);
            self::add_lesson_to_order($course_id, $new_id);
        }
        $steps = get_post_meta($lesson_id, '_bhc_steps', true);
        if (is_array($steps)) update_post_meta($new_id, '_bhc_steps', $steps);
        foreach (['_bhc_available_after_days', '_bhc_available_on_date', '_bhc_module_title'] as $key) {
            $val = get_post_meta($lesson_id, $key, true);
            if ($val !== '') update_post_meta($new_id, $key, $val);
        }

        wp_safe_redirect(get_edit_post_link($new_id, 'raw'));
        exit;
    }

    // Detaches a lesson from a course without deleting it: clears both sides of the link.
    public static function handle_unassign_lesson(): void {
        $lesson_id = (int) ($_GET['lesson_id'] ?? 0);
        $course_id = (int) ($_GET['course_id'] ?? 0);
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bhc_unassign_lesson_' . $lesson_id)) wp_die(BHC_Words::get('admin.admin.security_check_failed'), '', ['response' => 403, 'back_link' => true]);
        if (!current_user_can('edit_post', $lesson_id) || !current_user_can('edit_post', $course_id)) wp_die('Not allowed.', '', ['response' => 403, 'back_link' => true]);
        $lesson = get_post($lesson_id);
        if (!$lesson || $lesson->post_type !== 'bh_lesson') wp_die(BHC_Words::get('admin.admin.lesson_not_found'), '', ['response' => 404, 'back_link' => true]);

        delete_post_meta($lesson_id, '_bhc_course_id');
        self::remove_lesson_from_order($course_id, $lesson_id);

        wp_safe_redirect(get_edit_post_link($course_id, 'raw'));
        exit;
    }

    // Flags lessons with no course or an orphaned (deleted) course.
    public static function lesson_column_content(string $col, int $post_id): void {
        if ($col !== 'bhc_course') return;
        $course_id = BHC_PostTypes::course_for_lesson($post_id);
        if (!$course_id) {
            echo '<span style="color:#b32d2e;">&mdash; none —</span>';
            return;
        }
        if (get_post_type($course_id) !== 'bh_course') {
            echo '<span style="color:#b32d2e;">&mdash; orphaned (course deleted) —</span>';
            return;
        }
        echo '<a href="' . esc_url(get_edit_post_link($course_id)) . '">' . esc_html(get_the_title($course_id)) . '</a>';
    }

    /**
     * Adds a "View live catalog" link to the list table's status row.
     *
     * WHY: a views link rather than a menu entry, since standalone admin pages have
     * broken WordPress's page-hook resolution on this install (see CLAUDE.md).
     *
     * @param array<string, string> $views
     * @return array<string, string>
     */
    public static function add_catalog_view_link(array $views): array {
        if (!method_exists('OUS_Pages', 'url')) return $views;
        $url = OUS_Pages::url('bh_courses', 'bhc_catalog_page_id');
        if (!$url) return $views;
        $views['bh-live-catalog'] = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">'
            . esc_html(BHC_Words::get('admin.admin.view_live_catalog')) . ' <span aria-hidden="true">&#8599;</span></a>';
        return $views;
    }

    /**
     * Scopes the Lessons list to one course via a dropdown filter.
     */
    public static function lesson_course_filter(string $post_type): void {
        if ($post_type !== 'bh_lesson') return;
        $courses = get_posts([
            'post_type'   => 'bh_course',
            'numberposts' => -1,
            'post_status' => ['publish', 'draft'],
            'orderby'     => 'title',
            'order'       => 'ASC',
        ]);
        if (!$courses) return;
        $selected = isset($_GET['bhc_course']) ? (int) $_GET['bhc_course'] : 0;
        echo '<label class="screen-reader-text" for="bhc_course">' . esc_html(BHC_Words::get('admin.admin.filter_by_course')) . '</label>';
        echo '<select name="bhc_course" id="bhc_course">';
        echo '<option value="0">' . esc_html__('All courses', 'bh-courses') . '</option>';
        foreach ($courses as $course) {
            printf(
                '<option value="%d"%s>%s</option>',
                (int) $course->ID,
                selected($selected, (int) $course->ID, false),
                esc_html(get_the_title($course->ID))
            );
        }
        echo '</select>';
    }

    /** @param \WP_Query $query */
    public static function apply_lesson_course_filter($query): void {
        if (!is_admin() || !$query->is_main_query()) return;
        if (($query->get('post_type') ?: '') !== 'bh_lesson') return;
        $course_id = isset($_GET['bhc_course']) ? (int) $_GET['bhc_course'] : 0;
        if ($course_id <= 0) return;
        $query->set('meta_query', [[
            'key'   => '_bhc_course_id',
            'value' => $course_id,
        ]]);
    }

    /**
     * A direct route from a course to just its lessons.
     *
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public static function course_lessons_row_action(array $actions, \WP_Post $post): array {
        if ($post->post_type !== 'bh_course') return $actions;
        $count = count(BHC_PostTypes::lesson_order((int) $post->ID));
        $url = add_query_arg(
            ['post_type' => 'bh_lesson', 'bhc_course' => (int) $post->ID],
            admin_url('edit.php')
        );
        $actions['bhc-lessons'] = '<a href="' . esc_url($url) . '">'
            . sprintf(
                /* translators: %d: number of lessons in this course */
                esc_html(BHC_Words::get('admin.admin.lessons_d')),
                $count
            ) . '</a>';
        return $actions;
    }
}
