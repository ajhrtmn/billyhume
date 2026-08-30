<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin authoring: a course's lesson order + optional tier gate, and a
 * lesson's ordered steps.
 *
 * The step builder USED TO BE a plain repeater backed by one hidden
 * JSON field (assets/js/admin.js's now-inert #bhc-steps-builder code —
 * left in place, self-guards on the div's absence, harmless), then
 * (LMS-AUTHORING-DESIGN-PLAN.md) linked out to BH_Studio's own
 * separate canvas. As of the real-post-editor migration (see
 * BHC_ContentBridge's own docblock — bh_lesson now has real 'editor'
 * support), a lesson's steps are authored directly on THIS screen, in
 * the real main content area, same as any page — render_steps_metabox()
 * below is now just a read-only current-steps summary + a "preview as
 * student" link, not an editor of its own. BHC_ContentBridge's
 * save_post_bh_lesson hook is the only writer of lesson step content;
 * the old bhc_steps_json POST handling in save_lesson() was removed
 * outright rather than left as a second writer of the same data.
 */
class BHC_Admin {
    public static function add_meta_boxes(): void {
        add_meta_box('bhc_course_details', 'Course Details', [self::class, 'render_course_metabox'], 'bh_course', 'normal', 'high');
        // OPEN.md item 20 — same OUS_Revisions consumer shape as
        // bh_contest's own revisions box; see save_course()'s own
        // comment for why a course (not a lesson) needs this.
        add_meta_box('bhc_course_revisions', 'Version History', [self::class, 'render_course_revisions_metabox'], 'bh_course', 'side', 'low');
        // Separate box, not folded into Course Details above — this is
        // purely catalog/browse metadata (instructor, difficulty,
        // duration), a genuinely different concern from lesson ordering
        // and tier-gating, and keeping it separate matches how bh-streaming
        // splits its own catalog-facing fields from its own
        // access/monetization ones across separate metaboxes.
        add_meta_box('bhc_course_catalog', 'Catalog Details', [self::class, 'render_catalog_metabox'], 'bh_course', 'side', 'default');
        add_meta_box('bhc_course_site_menu', 'Site Menu', [self::class, 'render_site_menu_metabox'], 'bh_course', 'side', 'default');
        // Lesson settings (belongs-to-course, module, drip) used to be
        // two 'normal' metaboxes that Gutenberg dumps into the collapsed
        // "Meta Boxes" seam below the steps canvas — disjoint from the
        // authoring flow. They're a native editor sidebar panel now
        // (registerLessonPanel() in courses-studio-blocks.ts, backed by
        // the REST meta registered in register_lesson_meta() below), so
        // the screen reads as one thing: steps in the canvas, settings
        // in the sidebar. The read-only "Lesson Steps" summary box is
        // gone entirely — the canvas is the source of truth, and the
        // cross-lesson outline it half-provided lives on the course
        // screen where it belongs.
    }

    // REST-exposed so the Gutenberg "Lesson" sidebar panel can read/
    // write them via useEntityProp. save_lesson() still handles the
    // classic $POST path (quick edit, programmatic saves); both write
    // the same keys, and reconcile_lesson_placement() keeps the
    // course<->lesson inverse order consistent whichever path ran.
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

    // Self-correcting: makes sure this lesson sits in exactly its
    // current course's _bhc_lesson_order and no other's — regardless of
    // what the previous value was. Cheap (course count is tiny) and
    // idempotent, so it's safe to run from every save path
    // (rest_after_insert_bh_lesson AND save_lesson's classic path).
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

        // Backfill: older data (and the seeders) linked a lesson to its
        // course ONLY via the course-side _bhc_lesson_order, never the
        // lesson-side _bhc_course_id this panel now treats as the source
        // of truth. Without this, the first reconcile would read
        // _bhc_course_id as 0 and DROP the lesson from the order it was
        // only ever in. So: if the lesson has no course of its own but
        // exactly one course's order already contains it, adopt that.
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

    // Instructor/difficulty/duration — the new fields QUIZ-AND-CATALOG-
    // DESIGN-PLAN.md Part 2.2 scopes. Category/topic (bhc_course_category/
    // bhc_course_topic) are real taxonomies (class-post-types.php) and
    // get WordPress's own standard category/tag meta boxes automatically
    // — no custom UI needed or written for those here.
    public static function render_catalog_metabox(\WP_Post $post): void {
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

    public static function render_site_menu_metabox(\WP_Post $post): void {
        wp_nonce_field('bhc_save_menu', 'bhc_menu_nonce');
        if (get_post_status($post->ID) !== 'publish') {
            echo '<p class="description">Publish this course first — a course with no live permalink can\'t appear in the menu.</p>';
            return;
        }
        $checked = (bool) get_post_meta($post->ID, '_bhc_show_in_menu', true);
        $label = get_post_meta($post->ID, '_bhc_menu_label', true);
        echo '<p><label><input type="checkbox" name="bhc_show_in_menu" value="1"' . checked($checked, true, false) . '> Show under <strong>Courses</strong> in the site menu</label></p>';
        echo '<p><label>Menu label (optional)<br><input type="text" name="bhc_menu_label" value="' . esc_attr($label) . '" placeholder="' . esc_attr($post->post_title) . '" style="width:100%;"></label></p>';

        echo '<hr><p><strong>Page:</strong> ' . self::page_link_html($post->ID) . '</p>';
        echo '<p class="description">A simple page with this course\'s shortcode was created automatically when you published. If you deleted it, "Create page" makes a new one. This is the real public page a student sees — the course\'s own permalink is not a full experience.</p>';
    }

    // Same "View · Edit" / "Create page" fallback link pattern
    // BH_Admin::page_links_html() already uses for contests.
    private static function page_link_html(int $course_id): string {
        $page_id = (int) get_post_meta($course_id, '_bhc_page_id', true);
        if ($page_id && get_post_status($page_id) && get_post_status($page_id) !== 'trash') {
            return '<a href="' . esc_url(get_permalink($page_id)) . '" target="_blank">View</a> &middot; <a href="' . esc_url(get_edit_post_link($page_id)) . '">Edit</a>';
        }
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=bhc_create_page&course_id=' . (int) $course_id),
            'bhc_create_page'
        );
        return '<a href="' . esc_url($url) . '">Create page</a>';
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

        // Real gap found live: clicking "Courses" itself (the group
        // label, not a child course) went nowhere ('#') — bh_course has
        // a real, native archive at /courses/ (has_archive => 'courses',
        // class-post-types.php) to send them to instead, the exact same
        // URL convention BHC_Gate/BHC_PortalPanel already use elsewhere.
        OUS_MenuSync::sync_group('courses', 'Courses', $items, home_url('/courses/'));
    }

    /**
     * A bh_course's own permalink renders a bare, generic single-post
     * template — no lesson list, no enroll/continue flow, nothing a
     * real visitor should land on (confirmed live: it shows a broken
     * "Written by in" byline and nothing else). The actual course
     * experience only ever lives on whichever real page embeds
     * `[bh_course id="X"]`. Courses published after maybe_create_course_page()
     * shipped have an authoritative `_bhc_page_id` link (same convention
     * bh-contest already uses) — checked first since it's a direct
     * lookup, not a scan. Falls back to a shortcode search for courses
     * that predate that feature and were hand-wrapped in a manually
     * built page (e.g. the original "Songwriting Fundamentals"). Only
     * falls back to the raw permalink if neither finds anything.
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
        // Vendored, not npm — this ecosystem's own no-build-step
        // convention (see bh-crm's kanban-board.js/.css docblocks for
        // the same reasoning). Only the lesson-order list actually
        // uses it; admin.js itself no-ops the rest of its own code
        // when it's not on a screen that needs it.
        wp_enqueue_script('sortablejs', BHC_URL . 'assets/js/vendor/sortable.min.js', [], '1.15.6', true);
        // No longer depends on jQuery — the rebuilt reorder widget is
        // plain vanilla JS + SortableJS, same as this rebuild's own
        // docblock explains.
        wp_enqueue_script('bhc-admin', BHC_URL . 'assets/js/admin.js', ['sortablejs'], BHC_VER, true);
        wp_enqueue_style('bhc-admin', BHC_URL . 'assets/css/admin.css', [], BHC_VER);
    }

    /* ---------------- course metabox ---------------- */

    public static function render_course_metabox(\WP_Post $post): void {
        wp_nonce_field('bhc_save_course', 'bhc_course_nonce');

        // "Preview as student" — the Lesson screen has had this for a
        // while (see render_steps_metabox() below); the Course screen
        // never did, a real gap. Same real-
        // permalink-or-preview-link pattern: a published course links
        // straight to its own detail page (BHC_Render_Course, the
        // catalog-entry-clicked-into page with the syllabus/enroll
        // CTA), a draft uses WordPress's own preview-link mechanism
        // since a draft's permalink 404s for anyone without edit rights.
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

        // QA rebuild: the drag-reorder list used to be native HTML5
        // drag/drop (draggable="true") — no touch support at all (a
        // real gap on an iPad, which is a completely plausible device
        // for editing a course from), no visual drop indicator, and a
        // fixed inline-styled look. Now SortableJS (assets/js/
        // vendor/sortable.min.js, forceFallback:true — same
        // touch-capable approach bh-crm's kanban board already
        // proved out), with a real drag handle instead of the whole
        // row being draggable (so clicking the row itself, or a future
        // per-lesson action, doesn't fight drag detection).
        // Prefills the new lesson's course via ?bhc_course_id=, read by
        // render_lesson_metabox() below — previously this linked to a
        // blank post-new.php and made the author re-pick the course
        // from the dropdown every single time, a real friction point.
        $add_lesson_url = admin_url('post-new.php?post_type=bh_lesson&bhc_course_id=' . (int) $post->ID);

        // Emphasizes through the UI that every lesson belongs to
        // exactly one course. A course is a real
        // COLLECTION of lessons, not just a list to drag-reorder: this
        // summary line is the "at a glance, what's this course made
        // of" a plain list never gave, and every lesson row below is
        // now a genuine management surface (a real edit link — it was
        // static text before — a step count, and a one-click way to
        // detach a lesson from this collection) rather than just an
        // orderable label.
        $published_count = count(array_filter($ordered, fn($l) => $l->post_status === 'publish'));
        $draft_count = count($ordered) - $published_count;
        $total_steps = class_exists('BHC_Steps') ? array_sum(array_map(fn($l) => BHC_Steps::count($l->ID), $ordered)) : 0;
        echo '<div class="bhc-course-stats">'
            . '<strong>' . count($ordered) . '</strong> lesson' . (count($ordered) === 1 ? '' : 's')
            . ' &middot; <strong>' . $published_count . '</strong> published'
            . ($draft_count ? ' &middot; <strong>' . $draft_count . '</strong> draft' : '')
            . ' &middot; <strong>' . $total_steps . '</strong> total step' . ($total_steps === 1 ? '' : 's')
            . '</div>';

        echo '<p class="description">Drag to reorder. Only lessons whose "Belongs to course" field (below, on the lesson itself) points here show up. <a href="' . esc_url($add_lesson_url) . '">+ Add New Lesson to this course</a></p>';
        echo '<ul id="bhc-lesson-order-list" class="bhc-order-list">';
        foreach ($ordered as $l) {
            $steps = class_exists('BHC_Steps') ? BHC_Steps::get($l->ID) : [];
            $step_count = count($steps);
            $unassign_url = wp_nonce_url(
                admin_url('admin-post.php?action=bhc_unassign_lesson&lesson_id=' . (int) $l->ID . '&course_id=' . (int) $post->ID),
                'bhc_unassign_lesson_' . $l->ID
            );
            // Real gap this closes (Tier 2 course-authoring pass,
            // 2026-08-27): before this, "5 steps" was all this row ever
            // showed — seeing WHICH five steps, and of what type, meant
            // leaving this screen entirely to open that lesson's own
            // edit screen. A native <details> disclosure, not a JS
            // widget: no conflict with SortableJS (which drags via the
            // dedicated .bhc-order-drag-handle, never a click inside
            // <summary>), no extra request (the step data's already
            // being read for the count above), and degrades to nothing
            // special with JS disabled — it's still just an <ol> a
            // screen reader or no-JS browser can read straight through.
            // Restructured as a header row + an optional full-width
            // expansion below it, rather than one long flex row — a
            // long lesson title next to a status pill in a single row
            // either collided or wrapped mid-badge. The header row now
            // always stays one line (title ellipsis-truncates instead
            // of wrapping); the step list, when present, gets the
            // entire row's width on its own line instead of being
            // squeezed into whatever space was left after the title.
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
                echo '<p class="bhc-order-no-steps">No steps yet.</p>';
            }
            echo '</li>';
        }
        echo '</ul>';
        echo '<input type="hidden" name="bhc_lesson_order" id="bhc_lesson_order" value="' . esc_attr(implode(',', array_map(fn($l) => $l->ID, $ordered))) . '">';

        // Off by default — a real decision, not a technical toggle, per
        // ROADMAP-ux-polish-and-feature-parity-2026-07.md 4d: an author
        // opts a specific course into Q&A explicitly rather than every
        // lesson silently becoming public-comment-capable the moment
        // this shipped. Visibility of existing comments (not just the
        // ability to post new ones) is gated to whoever can already see
        // the lesson content — see BHC_Comments's own docblock.
        $comments_enabled = (bool) get_post_meta($post->ID, '_bhc_comments_enabled', true);
        echo '<h4>Lesson Q&amp;A</h4>';
        echo '<p><label><input type="checkbox" name="bhc_comments_enabled" value="1"' . checked($comments_enabled, true, false) . '> <strong>Enable comments/Q&amp;A on this course\'s lessons</strong></label></p>';
        echo '<p class="description">Off by default. When on, only students who can already access a given lesson (per any supporter-tier gating and drip schedule below) can see or post in its comment thread — never open to the public just because this is checked.</p>';

        // Same off-by-default, per-course opt-in posture as Lesson Q&A
        // just above — ROADMAP-ux-polish-and-feature-parity-2026-07.md 4a.
        $certificate_enabled = (bool) get_post_meta($post->ID, '_bhc_certificate_enabled', true);
        $certificate_signature = (string) get_post_meta($post->ID, '_bhc_certificate_signature', true);
        echo '<h4>Certificate of completion</h4>';
        echo '<p><label><input type="checkbox" name="bhc_certificate_enabled" value="1" id="bhc_certificate_enabled"' . checked($certificate_enabled, true, false) . '> <strong>Offer a downloadable certificate when a student finishes this course</strong></label></p>';
        echo '<p><label>Signed by <span class="description">(optional — printed on the certificate, e.g. an instructor\'s name)</span><br><input type="text" name="bhc_certificate_signature" value="' . esc_attr($certificate_signature) . '" style="max-width:400px;width:100%;"></label></p>';

        // Always on (there's no opt-in checkbox, unlike the certificate
        // above) — the generated share-card image is harmless to offer
        // even on a course nobody ever shares; only the VISUAL style is
        // a real choice, not whether the feature exists at all. Reads
        // BH_ShareCard::STYLES rather than hardcoding a brand/poster
        // pair — a future style registered there (a fourth poster
        // variant, a custom-logo style) shows up here automatically.
        $card_style = class_exists('BH_ShareCard') && BH_ShareCard::is_valid_style(get_post_meta($post->ID, '_bhc_share_card_style', true))
            ? get_post_meta($post->ID, '_bhc_share_card_style', true) : 'brand';
        echo '<h4>Shareable completion image</h4>';
        echo '<p class="description">A "' . esc_html(get_the_title($post->ID) ?: 'course') . ' complete!" image a student can grab from the finish screen and post/attach anywhere. <strong>Brand</strong> matches this site\'s own live colors; the <strong>Poster</strong> options are bolder, stand-alone looks.</p>';
        if (class_exists('BH_ShareCard')) {
            echo '<p><label>Style<br><select name="bhc_share_card_style">';
            foreach (BH_ShareCard::STYLES as $key => $label) {
                echo '<option value="' . esc_attr($key) . '"' . selected($card_style, $key, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></label></p>';
        }

        // Depth-of-magic Phase 4 — same off-by-default, per-course
        // opt-in posture as Q&A/certificate above: a course creator
        // explicitly decides their students' quiz scores are something
        // to compare, rather than every course silently gaining a public
        // ranking the moment this shipped.
        $leaderboard_enabled = class_exists('BHC_Leaderboard') && BHC_Leaderboard::is_enabled($post->ID);
        echo '<h4>Top quiz scorers</h4>';
        echo '<p><label><input type="checkbox" name="bhc_leaderboard_enabled" value="1"' . checked($leaderboard_enabled, true, false) . '> <strong>Show a leaderboard of top quiz scorers on this course\'s page</strong></label></p>';
        echo '<p class="description">Off by default. Ranks students by their quiz average in this course (same "Mastery: N%" figure shown on the course page and used for certificate distinction) — only students who\'ve attempted at least one quiz appear.</p>';

        // Login vs. tier are different questions (OUS_Visibility's own
        // docblock) — a course defaults to requiring a logged-in
        // account to view at all, same as anything meant for an
        // audience rather than an anonymous visitor. This checkbox is
        // the explicit, deliberate opt-out for a course an artist
        // genuinely wants open to anyone, tier or no tier.
        echo '<h4>Login requirement</h4>';
        echo '<p>' . OUS_Visibility::checkbox_field($post->ID, 'Public — anyone can view without logging in') . '</p>';
        echo '<p class="description">Off by default — viewing this course requires a logged-in account, same as anything ordinarily meant for your audience rather than an anonymous visitor. Turn this on for a course you genuinely want open to the public (a free preview course, for example).</p>';

        if (class_exists('BHM_Tiers')) {
            $required = BHC_Gate::required_tier($post->ID);
            $required_benefit = BHC_Gate::required_benefit($post->ID);
            echo '<h4>Supporter access</h4><p class="description">Optional — leave both set to "open" for a course any logged-in account can view (see the login checkbox above for anonymous access). Requires BH Monetization.</p>';

            $tier_tip = class_exists('BHY_UI') ? BHY_UI::tip('Requires the tier selected here OR any higher-priced tier — a fan on a $10 tier still gets in if this is set to a $5 tier.') : '';
            echo '<p><label><strong>Gate by tier price rank</strong>' . $tier_tip . '<br><select name="bhc_required_tier"><option value="0">— Open to everyone —</option>';
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

            // One-time purchase — direct request: "Billy also would
            // prefer a one time purchase for access to the courses."
            // Deliberately independent of the tier selects above, not a
            // third mutually-exclusive radio option: a course can be
            // tier-gated, purchasable outright, both (either path
            // unlocks it — BHC_Gate::user_can_access_course() checks
            // purchase ownership FIRST, unconditionally, before either
            // tier check), or neither (open). $0/blank = no purchase
            // option offered, same "absence means off" default every
            // other optional field on this screen already uses.
            //
            // Gated on BHC_Gate::purchase_feature_enabled() — a real,
            // one-line kill switch for this whole feature
            // (`add_filter('bhc_course_purchase_enabled', '__return_
            // false')`) since it touches real money/entitlement logic.
            // Hidden entirely rather than shown-but-disabled: an author
            // seeing a price field that quietly does nothing would be a
            // worse experience than the field not being there at all.
            if (class_exists('BHC_Gate') && BHC_Gate::purchase_feature_enabled()) {
                $purchase_price_cents = (int) get_post_meta($post->ID, '_bhc_purchase_price_cents', true);
                $purchase_tip = class_exists('BHY_UI') ? BHY_UI::tip('Sells this course outright, independent of any tier above — a one-time buyer gets in even with no supporter tier at all, and a tier-gated course can ALSO offer this as an alternative for someone who just wants this one course.') : '';
                echo '<h4>One-time purchase</h4>';
                echo '<p><label><strong>Buy-once price (USD)</strong>' . $purchase_tip . '<br><input type="number" step="0.01" min="0" name="bhc_purchase_price" value="' . esc_attr($purchase_price_cents ? BHM_Money::price($purchase_price_cents) : '') . '" style="width:160px;" placeholder="0.00"></label></p>';
                echo '<p class="description">Leave blank/zero to not offer this course as a one-time purchase.</p>';
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
        update_post_meta($post_id, '_bhc_certificate_enabled', !empty($_POST['bhc_certificate_enabled']) ? 1 : 0);
        update_post_meta($post_id, '_bhc_certificate_signature', isset($_POST['bhc_certificate_signature']) ? sanitize_text_field($_POST['bhc_certificate_signature']) : '');
        $posted_style = (string) ($_POST['bhc_share_card_style'] ?? '');
        update_post_meta($post_id, '_bhc_share_card_style', (class_exists('BH_ShareCard') && BH_ShareCard::is_valid_style($posted_style)) ? $posted_style : 'brand');
        update_post_meta($post_id, '_bhc_leaderboard_enabled', !empty($_POST['bhc_leaderboard_enabled']) ? 1 : 0);
        OUS_Visibility::save_from_request($post_id);
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
        // One-time purchase price — same "only ever written if the
        // select rendered" safety as the tier fields above (a crafted
        // POST on a site without BH Monetization does nothing, since
        // BHC_Gate::user_can_access_course()'s purchase check is itself
        // guarded on class_exists('BHM_Gate')). Sync the WooCommerce
        // product in the same save, mirroring exactly how BHM_Tiers::
        // save() keeps its own tier product in sync on every save —
        // same established pattern, not a new one. Also gated on the
        // same kill-switch the admin field itself is gated on — a
        // crafted/stale POST while the feature is toggled off must not
        // silently create a new WooCommerce product behind the switch.
        if (isset($_POST['bhc_purchase_price']) && class_exists('BHM_Tiers') && class_exists('BHC_Gate') && BHC_Gate::purchase_feature_enabled()) {
            $price_cents = BHM_Money::parse($_POST['bhc_purchase_price']);
            update_post_meta($post_id, '_bhc_purchase_price_cents', $price_cents);
            if ($price_cents > 0 && class_exists('BHM_ProductSync') && class_exists('BH_Commerce') && BH_Commerce::available()) {
                BHM_ProductSync::sync_object_purchase_product($post_id, 'bh_course', $price_cents);
            }
        }

        self::maybe_create_course_page($post_id);

        // OPEN.md item 20, real OUS_Revisions consumer — same reasoning
        // bh_contest's own save already documented: a course's real
        // configuration (lesson order, tier-gating, pricing, drip/
        // certificate settings) lives entirely in postmeta, never
        // post_content/title, so the 'revisions' support already
        // declared on this post type (class-post-types.php) covers the
        // description text but captures nothing about what actually
        // MAKES this a course. get_post_meta()'s full flat dump is the
        // honest "complete current state" here, same as bh_contest's,
        // rather than hand-curating a field list that drifts out of
        // sync with this save method's own field list above. A lesson
        // does NOT get this treatment — its steps genuinely ARE
        // post_content now (BHC_ContentBridge), so native WP revisions
        // already cover it for free.
        if (class_exists('OUS_Revisions')) {
            OUS_Revisions::snapshot('bh_course', $post_id, self::course_meta_snapshot($post_id));
        }
    }

    /**
     * Same gap bh-contest already solved for itself
     * (maybe_create_contest_page()) — a bh_course's own permalink
     * renders a broken, generic single-post stub (no lesson list, no
     * enroll flow). Creates a simple page wrapping this course's
     * shortcode the first time it's published, cross-linked via
     * _bhc_page_id/_bhc_course_ref, so a brand-new course has a real
     * working public page with zero extra manual steps. Won't
     * duplicate: skipped if a live (non-trashed) page is already
     * linked, unless $force is passed (the "Create page" fallback link).
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

    // Small backlink box on the auto-created page's own edit screen —
    // same convention as bh-contest's add_page_backlink_meta_box().
    public static function add_page_backlink_meta_box(\WP_Post $post): void {
        $course_id = (int) get_post_meta($post->ID, '_bhc_course_ref', true);
        if (!$course_id || !get_post($course_id)) return;

        add_meta_box('bhc_page_backlink', 'BH Course', function () use ($course_id) {
            echo '<p>This page hosts the course:</p>';
            echo '<p><strong>' . esc_html(get_the_title($course_id)) . '</strong></p>';
            echo '<p><a href="' . esc_url(get_edit_post_link($course_id)) . '" class="button">Edit Course</a></p>';
        }, 'page', 'side', 'high');
    }

    /* ---------------- lesson metabox (course assignment) ---------------- */

    // Shared by save_course()'s snapshot call and the restore handler's
    // own re-snapshot-after-restore, so there is exactly one definition
    // of "this course's complete current state" rather than two copies
    // that can drift.
    //
    // Real bug, caught by testing the restore path against a real
    // array-valued field (_bhc_lesson_order) rather than trusting the
    // pattern this was copied from: get_post_meta($post_id) — the bulk,
    // no-$key form — returns each value as its RAW SERIALIZED STRING,
    // not auto-unserialized, unlike get_post_meta($post_id, $key, true).
    // For a scalar meta value that difference is invisible (a plain
    // string round-trips through maybe_serialize()/maybe_unserialize()
    // unchanged), which is almost certainly why this went unnoticed
    // elsewhere — but for _bhc_lesson_order (a real PHP array), passing
    // the raw serialized STRING into OUS_Revisions::snapshot() (which
    // wp_json_encode()s it) and back through update_post_meta() on
    // restore double-serializes it, corrupting the value. Verified
    // live: restoring a snapshot taken via the naive bulk-form pattern
    // turned [1,2,3] into the literal string "a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}"
    // instead of the real array. Fixed by fetching each relevant key
    // individually with $single = true.
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
            echo '<p class="description">Version history is unavailable.</p>';
            return;
        }
        OUS_Revisions::render_history_panel('bh_course', $post->ID, 'bhc_restore_course_revision', 'bhc_restore_course_' . $post->ID);
    }

    // Restores a course's own postmeta from a stored snapshot — deliberately
    // writes postmeta directly (update_post_meta() per key) rather than
    // re-simulating save_course()'s own $_POST-shaped form handling, same
    // reasoning bh_contest's own restore handler already documented: the
    // snapshot already IS the target shape, and re-simulating a fake
    // $_POST would be more fragile than just writing it back directly.
    public static function handle_restore_course_revision(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        $post_id = (int) ($_GET['object_id'] ?? 0);
        $version = (int) ($_GET['version'] ?? 0);
        if (!isset($_GET['ous_revisions_nonce']) || !wp_verify_nonce($_GET['ous_revisions_nonce'], 'bhc_restore_course_' . $post_id)) {
            wp_die('Invalid request.');
        }
        if (!$post_id || get_post_type($post_id) !== 'bh_course') wp_die('Not a course.');

        $snapshot = class_exists('OUS_Revisions') ? OUS_Revisions::get_version('bh_course', $post_id, $version) : null;
        if (!$snapshot) wp_die('That version no longer exists.');

        foreach ((array) $snapshot['data'] as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        // The restore itself is also a real save — same "undo an
        // accidental restore the same way" reasoning as bh_contest's
        // own restore handler.
        if (class_exists('OUS_Revisions')) {
            OUS_Revisions::snapshot('bh_course', $post_id, self::course_meta_snapshot($post_id), 'Restored from version #' . $version);
        }
        if (class_exists('OUS_Toast')) {
            OUS_Toast::queue('Restored version #' . $version . '.', 'success');
        }

        wp_safe_redirect(get_edit_post_link($post_id, ''));
        exit;
    }

    // A small per-type glyph for the course-screen step outline — pure
    // visual scanning aid (BHC_Steps::VALID_TYPES is the actual source
    // of truth for what's a real type; this just needs SOMETHING to
    // show, never gates behavior). Plain Unicode, not Dashicons: this
    // renders inside a plugin's own metabox, not wp-admin chrome, and
    // Dashicons' ligature-font approach needs the class present on an
    // element the current markup doesn't otherwise use.
    private static function step_type_icon(string $type): string {
        $icons = [
            'text' => '&#9776;', 'image' => '&#128247;', 'video' => '&#9654;',
            'quiz' => '&#10067;', 'resource' => '&#128206;', 'callout' => '&#128161;',
            'checklist' => '&#9745;', 'chord-chart' => '&#127925;', 'audio-compare' => '&#9878;',
        ];
        return $icons[$type] ?? '&#8226;';
    }

    // One line of real content per step, not just its type — reads
    // straight off the legacy $step array shape (BHC_Steps::get()'s own
    // return format), the same shape regardless of which authoring path
    // wrote it (BHC_ContentBridge::sync_legacy_steps() keeps _bhc_steps
    // in sync with the real editor's post_content, see that class's
    // docblock).
    /** @param array<string, mixed> $step */
    private static function describe_step($step): string {
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
            // Audit fix (2026-07-25): the four newer step types
            // (LMS depth-of-magic pass) fell through to the bare
            // ucfirst($type) default, unlike every older type above.
            case 'callout':
                $snippet = wp_trim_words(wp_strip_all_tags((string) ($step['content'] ?? '')), 10, '…');
                return 'Callout (' . ($step['variant'] ?? 'tip') . ') — ' . ($snippet !== '' ? $snippet : '(empty)');
            case 'checklist':
                $icount = count($step['items'] ?? []);
                $label = 'Checklist — ' . $icount . ' item' . ($icount === 1 ? '' : 's');
                return $step['title'] ? $label . ': ' . wp_trim_words((string) $step['title'], 8, '…') : $label;
            case 'chord-chart':
                return 'Chord chart' . ($step['title'] ? ' — ' . wp_trim_words((string) $step['title'], 8, '…') : '');
            case 'audio-compare':
                return 'Audio compare — ' . ($step['label_a'] ?? 'A') . ' vs. ' . ($step['label_b'] ?? 'B');
            default:
                return ucfirst($type);
        }
    }

    public static function save_lesson(int $post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (get_post_type($post_id) !== 'bh_lesson') return;
        if (!current_user_can('edit_post', $post_id)) return;

        // Lesson settings (course, module, drip) are all REST meta now,
        // written by the "Lesson" sidebar panel and reconciled by
        // rest_after_insert_bh_lesson. This classic hook still covers a
        // no-JS / quick-edit / programmatic save that set _bhc_course_id
        // some other way: skip during a REST request (that path has its
        // own reconcile, and the meta isn't written yet here anyway),
        // otherwise keep the inverse course<->lesson order honest.
        if (!(defined('REST_REQUEST') && REST_REQUEST)) {
            self::reconcile_lesson_placement($post_id);
        }

        // Step content is written solely by
        // BHC_ContentBridge::sync_legacy_steps() (its own
        // save_post_bh_lesson hook) — never here. Two writers on the
        // same _bhc_steps data was the dual-write hazard
        // LMS-AUTHORING-DESIGN-PLAN.md Section 6 flagged; one writer
        // only is that doc's preferred resolution.
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

    // Hooked onto before_delete_post (permanent deletion only — a
    // trashed-but-restorable course still exists as a real post, so
    // there's nothing to clean up until it's actually gone). Any lesson
    // still pointing at the deleted course via _bhc_course_id would
    // otherwise become a silent orphan referencing a post ID that no
    // longer exists — the exact risk BHC_Gate::user_can_access_course()
    // and class-render-lesson.php's own comments already acknowledge
    // tolerating; this closes it at the source instead of just
    // tolerating it everywhere that reads the meta.
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

    // The mirror-image gap the production-hardening audit flagged: a
    // permanently-deleted LESSON left its own ID sitting in its parent
    // course's _bhc_lesson_order forever — masked (every render call
    // site already filters on get_post_status() !== 'publish', so
    // nothing visibly broke) but real: the "Lessons" list-table column
    // (course_column_content() below) over-counted forever, and any
    // future code trusting lesson_order() without that same defensive
    // filter would silently include a dangling ID.
    public static function cleanup_deleted_lesson(int $post_id): void {
        if (get_post_type($post_id) !== 'bh_lesson') return;
        $course_id = BHC_PostTypes::course_for_lesson($post_id);
        if ($course_id) self::remove_lesson_from_order($course_id, $post_id);
    }

    /* ---------------- duplicate course (whole-course template/re-run) ----------------
       "Duplicate this course as a template" — the single most-flagged
       missing instructor tool in a fresh audit against Teachable/
       Thinkific/Kajabi/LearnDash/LifterLMS: every one of them supports
       whole-course duplication, and only per-LESSON duplication existed
       here before this. Deliberately built by CLONING every lesson via
       the same logic handle_duplicate_lesson() already uses (never a
       shortcut like sharing lesson IDs between two courses) — a
       template re-run needs its own independent copy of every lesson so
       editing one cohort's content never touches another's. */
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
        $actions['bhc_duplicate'] = '<a href="' . esc_url($url) . '">Duplicate</a>';
        return $actions;
    }

    public static function handle_duplicate_course(): void {
        $course_id = (int) ($_GET['course_id'] ?? 0);
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bhc_duplicate_course_' . $course_id)) wp_die('Security check failed.', '', ['response' => 403, 'back_link' => true]);
        if (!current_user_can('edit_post', $course_id)) wp_die('Not allowed.', '', ['response' => 403, 'back_link' => true]);
        $original = get_post($course_id);
        if (!$original || $original->post_type !== 'bh_course') wp_die('Course not found.', '', ['response' => 404, 'back_link' => true]);

        $new_course_id = wp_insert_post([
            'post_type' => 'bh_course',
            'post_status' => 'draft', // never auto-publish a clone — same posture as lesson duplication
            'post_title' => $original->post_title . ' (Copy)',
            'post_content' => $original->post_content,
            'post_author' => get_current_user_id(),
        ], true);
        if (is_wp_error($new_course_id)) wp_die('Could not duplicate this course.', '', ['response' => 500, 'back_link' => true]);

        // Catalog/gating/certificate/share-card meta — a flat copy list
        // rather than trying to be clever about which fields "should"
        // carry over; every one of these is a course-level SETTING, not
        // enrollment/progress data, so copying all of them is correct.
        foreach ([
            '_bhc_instructor_id', '_bhc_difficulty', '_bhc_duration_note',
            '_bhc_comments_enabled', '_bhc_certificate_enabled', '_bhc_certificate_signature', '_bhc_share_card_style',
            '_bhm_required_tier', '_bhm_required_benefit',
            '_bhc_leaderboard_enabled', // audit fix (2026-07-25): duplication was silently dropping this course-level opt-in
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

        // Every lesson gets its OWN independent clone (never shared IDs
        // between two courses) — same core steps/copy logic
        // handle_duplicate_lesson() uses, just driven from this side and
        // built up as a fresh _bhc_lesson_order for the new course
        // instead of redirecting to any one lesson's edit screen.
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
            // '_bhc_module_title' added (audit fix, 2026-07-25) — was
            // dropped on whole-course duplication, silently losing
            // module/section grouping on every cloned lesson.
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
            $tier = BHC_Gate::required_tier($post_id);
            if (!$tier || !class_exists('BHM_Tiers')) { echo 'Open'; return; }
            $t = BHM_Tiers::get($tier);
            echo esc_html($t['name'] ?? 'Gated');
        }
    }

    /* ---------------- duplicate lesson ----------------
       "Building a second similar lesson means rebuilding from scratch"
       — a real gap the deep LMS audit called out (no lesson-duplication
       anywhere). A plain row-action + admin-post handler, same pattern
       WordPress core's own "Duplicate" plugins use, not a bespoke UI. */
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
        $actions['bhc_duplicate'] = '<a href="' . esc_url($url) . '">Duplicate</a>';
        return $actions;
    }

    public static function handle_duplicate_lesson(): void {
        $lesson_id = (int) ($_GET['lesson_id'] ?? 0);
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bhc_duplicate_lesson_' . $lesson_id)) wp_die('Security check failed.', 403);
        if (!current_user_can('edit_post', $lesson_id)) wp_die('Not allowed.', 403);
        $original = get_post($lesson_id);
        if (!$original || $original->post_type !== 'bh_lesson') wp_die('Lesson not found.', 404);

        // post_content (the real Gutenberg block tree) is the actual
        // source of truth — BHC_ContentBridge's save_post_bh_lesson hook
        // regenerates _bhc_steps from it on the new lesson's own first
        // save. Also copying _bhc_steps directly below means the clone
        // renders correctly immediately, before anyone has touched it,
        // rather than showing "no steps yet" until the next save.
        $new_id = wp_insert_post([
            'post_type' => 'bh_lesson',
            'post_status' => 'draft', // never auto-publish a clone — same "review before it's live" posture as any other duplicate-content tool
            'post_title' => $original->post_title . ' (Copy)',
            'post_content' => $original->post_content,
            'post_author' => get_current_user_id(),
        ], true);
        if (is_wp_error($new_id)) wp_die('Could not duplicate this lesson.', 500);

        $course_id = (int) get_post_meta($lesson_id, '_bhc_course_id', true);
        if ($course_id) {
            update_post_meta($new_id, '_bhc_course_id', $course_id);
            self::add_lesson_to_order($course_id, $new_id); // same helper save_lesson() uses to keep the course's own order in sync
        }
        $steps = get_post_meta($lesson_id, '_bhc_steps', true);
        if (is_array($steps)) update_post_meta($new_id, '_bhc_steps', $steps);
        // '_bhc_module_title' added (audit fix, 2026-07-25) — same gap as
        // whole-course duplication above: was dropped, silently losing
        // module/section grouping on the cloned lesson.
        foreach (['_bhc_available_after_days', '_bhc_available_on_date', '_bhc_module_title'] as $key) {
            $val = get_post_meta($lesson_id, $key, true);
            if ($val !== '') update_post_meta($new_id, $key, $val);
        }

        wp_safe_redirect(get_edit_post_link($new_id, 'raw'));
        exit;
    }

    // Detaches a lesson from a course without deleting the lesson
    // itself — the "×" quick-action in render_course_metabox()'s
    // lesson list. Clears _bhc_course_id AND removes the ID from that
    // course's _bhc_lesson_order in one step (the same two-sided-
    // pointer relationship save_lesson()'s course-reassignment sync
    // already keeps consistent — this is the same operation, just
    // triggered from the course side instead of the lesson side).
    public static function handle_unassign_lesson(): void {
        $lesson_id = (int) ($_GET['lesson_id'] ?? 0);
        $course_id = (int) ($_GET['course_id'] ?? 0);
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bhc_unassign_lesson_' . $lesson_id)) wp_die('Security check failed.', '', ['response' => 403, 'back_link' => true]);
        if (!current_user_can('edit_post', $lesson_id) || !current_user_can('edit_post', $course_id)) wp_die('Not allowed.', '', ['response' => 403, 'back_link' => true]);
        $lesson = get_post($lesson_id);
        if (!$lesson || $lesson->post_type !== 'bh_lesson') wp_die('Lesson not found.', '', ['response' => 404, 'back_link' => true]);

        delete_post_meta($lesson_id, '_bhc_course_id');
        self::remove_lesson_from_order($course_id, $lesson_id);

        wp_safe_redirect(get_edit_post_link($course_id, 'raw'));
        exit;
    }

    // Surfaces the orphan/desync risk directly in the list table
    // (previously only visible by reading postmeta by hand) — a lesson
    // with no course, or one whose _bhc_course_id points at a course
    // that's since been deleted (which cleanup_deleted_course() now
    // prevents going forward, but pre-existing data or direct DB edits
    // could still produce), shows a clear flag instead of silently
    // being unreachable from any course screen.
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
     * A link from the admin list table to the live catalog a visitor sees.
     *
     * views_edit-<type> rather than a menu entry: this install has a
     * documented history of standalone admin pages breaking WordPress's own
     * page-hook resolution (see CLAUDE.md), and the status row is a native,
     * risk-free home for a link that is not a filter view. OUS_Pages resolves
     * which page hosts the shortcode, so no slug is hardcoded, and the link
     * is omitted when there is no catalog page rather than pointing nowhere.
     *
     * @param array<string, string> $views
     * @return array<string, string>
     */
    public static function add_catalog_view_link(array $views): array {
        if (!method_exists('OUS_Pages', 'url')) return $views;
        $url = OUS_Pages::url('bh_courses', 'bhc_catalog_page_id');
        if (!$url) return $views;
        $views['bh-live-catalog'] = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">'
            . esc_html__('View live catalog', 'bh-courses') . ' <span aria-hidden="true">&#8599;</span></a>';
        return $views;
    }

    /**
     * Scopes the Lessons list to one course.
     *
     * Direct feedback: "I don't need a master list of all lessons; I only
     * care about what lessons belong to the course I'm working on." The data
     * model already parents a lesson to a course (_bhc_course_id); only the
     * admin was ignoring that and showing everything at once.
     *
     * Filtered rather than removed. Lessons are still created from this
     * screen, so hiding it would strand that -- and a filter serves the same
     * need without taking a capability away.
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
        echo '<label class="screen-reader-text" for="bhc_course">' . esc_html__('Filter by course', 'bh-courses') . '</label>';
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
                esc_html__('Lessons (%d)', 'bh-courses'),
                $count
            ) . '</a>';
        return $actions;
    }
}
