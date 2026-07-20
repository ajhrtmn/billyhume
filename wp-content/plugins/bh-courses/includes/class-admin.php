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
    public static function add_meta_boxes() {
        add_meta_box('bhc_course_details', 'Course Details', [self::class, 'render_course_metabox'], 'bh_course', 'normal', 'high');
        // Separate box, not folded into Course Details above — this is
        // purely catalog/browse metadata (instructor, difficulty,
        // duration), a genuinely different concern from lesson ordering
        // and tier-gating, and keeping it separate matches how bh-streaming
        // splits its own catalog-facing fields from its own
        // access/monetization ones across separate metaboxes.
        add_meta_box('bhc_course_catalog', 'Catalog Details', [self::class, 'render_catalog_metabox'], 'bh_course', 'side', 'default');
        add_meta_box('bhc_course_site_menu', 'Site Menu', [self::class, 'render_site_menu_metabox'], 'bh_course', 'side', 'default');
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

    public static function render_site_menu_metabox($post) {
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
    private static function page_link_html($course_id) {
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

    public static function save_site_menu_settings($post_id) {
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
    public static function resync_course_menu() {
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

        OUS_MenuSync::sync_group('courses', 'Courses', $items);
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
    private static function menu_url_for_course($course_id) {
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

    public static function maybe_resync_menu_for_post($post_id) {
        if (get_post_type($post_id) === 'bh_course') self::resync_course_menu();
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

    public static function render_course_metabox($post) {
        wp_nonce_field('bhc_save_course', 'bhc_course_nonce');

        // "Preview as student" — the Lesson screen has had this for a
        // while (see render_steps_metabox() below); the Course screen
        // never did, a real gap AJ's own audit caught. Same real-
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
            'meta_key' => '_bhc_course_id', 'meta_value' => $post->ID,
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
        // from the dropdown every single time, a real friction point
        // AJ's own audit caught.
        $add_lesson_url = admin_url('post-new.php?post_type=bh_lesson&bhc_course_id=' . (int) $post->ID);

        // "Emphasize through the UI that every lesson belongs to
        // exactly one course" — AJ's own ask. A course is a real
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
            $step_count = class_exists('BHC_Steps') ? BHC_Steps::count($l->ID) : 0;
            $unassign_url = wp_nonce_url(
                admin_url('admin-post.php?action=bhc_unassign_lesson&lesson_id=' . (int) $l->ID . '&course_id=' . (int) $post->ID),
                'bhc_unassign_lesson_' . $l->ID
            );
            echo '<li class="bhc-order-item" data-id="' . (int) $l->ID . '">'
               . '<span class="bhc-order-drag-handle" title="Drag to reorder">&#8942;&#8942;</span>'
               . '<a class="bhc-order-title" href="' . esc_url(get_edit_post_link($l->ID, 'raw')) . '">' . esc_html($l->post_title) . '</a>'
               . '<span class="bhc-order-steps">' . (int) $step_count . ' step' . ($step_count === 1 ? '' : 's') . '</span>'
               . '<em class="bhc-order-status bhc-order-status-' . esc_attr($l->post_status) . '">' . esc_html($l->post_status) . '</em>'
               . '<a class="bhc-order-unassign" href="' . esc_url($unassign_url) . '" onclick="return confirm(\'Remove ' . esc_js($l->post_title) . ' from this course? The lesson itself isn\\\'t deleted.\');" title="Remove from this course">&times;</a>'
               . '</li>';
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
        update_post_meta($post_id, '_bhc_comments_enabled', !empty($_POST['bhc_comments_enabled']) ? 1 : 0);
        update_post_meta($post_id, '_bhc_certificate_enabled', !empty($_POST['bhc_certificate_enabled']) ? 1 : 0);
        update_post_meta($post_id, '_bhc_certificate_signature', isset($_POST['bhc_certificate_signature']) ? sanitize_text_field($_POST['bhc_certificate_signature']) : '');
        $posted_style = (string) ($_POST['bhc_share_card_style'] ?? '');
        update_post_meta($post_id, '_bhc_share_card_style', (class_exists('BH_ShareCard') && BH_ShareCard::is_valid_style($posted_style)) ? $posted_style : 'brand');
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

        self::maybe_create_course_page($post_id);
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
    public static function maybe_create_course_page($course_id, $force = false) {
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

    public static function create_course_page_action() {
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
    public static function add_page_backlink_meta_box($post) {
        $course_id = (int) get_post_meta($post->ID, '_bhc_course_ref', true);
        if (!$course_id || !get_post($course_id)) return;

        add_meta_box('bhc_page_backlink', 'BH Course', function () use ($course_id) {
            echo '<p>This page hosts the course:</p>';
            echo '<p><strong>' . esc_html(get_the_title($course_id)) . '</strong></p>';
            echo '<p><a href="' . esc_url(get_edit_post_link($course_id)) . '" class="button">Edit Course</a></p>';
        }, 'page', 'side', 'high');
    }

    /* ---------------- lesson metabox (course assignment) ---------------- */

    public static function render_lesson_metabox($post) {
        wp_nonce_field('bhc_save_lesson', 'bhc_lesson_nonce');
        $current_course = BHC_PostTypes::course_for_lesson($post->ID);
        // A brand-new lesson started from the course screen's "+ Add
        // New Lesson to this course" link (see render_course_metabox()
        // above) arrives with ?bhc_course_id= set — pre-select it here
        // rather than making the author pick it again from the dropdown.
        if (!$current_course && !empty($_GET['bhc_course_id'])) {
            $current_course = (int) $_GET['bhc_course_id'];
        }
        $courses = get_posts(['post_type' => 'bh_course', 'numberposts' => -1, 'post_status' => ['publish', 'draft']]);

        // "Every lesson belongs to exactly one course" — AJ's own ask
        // to emphasize this relationship in the UI, not just enforce it
        // in the data model. This box led with a plain unlabeled select
        // before; now it's framed as the defining fact about what a
        // lesson IS, with the lesson's actual position inside that
        // course's own order shown right here (previously only visible
        // by going to the course screen and counting).
        echo '<div class="bhc-lesson-course-box">';
        echo '<p class="bhc-lesson-course-label">This lesson belongs to</p>';
        echo '<select name="bhc_course_id" class="bhc-lesson-course-select">';
        echo '<option value="0">— No course yet —</option>';
        foreach ($courses as $c) {
            echo '<option value="' . (int) $c->ID . '"' . selected($current_course, $c->ID, false) . '>' . esc_html($c->post_title) . '</option>';
        }
        echo '</select>';
        if ($current_course) {
            $position = BHC_PostTypes::lesson_position($post->ID);
            $lesson_count = BHC_PostTypes::lesson_count($current_course);
            echo '<p class="description">';
            if ($position !== null) {
                echo 'Lesson ' . ($position + 1) . ' of ' . $lesson_count . ' &mdash; ';
            }
            echo '<a href="' . esc_url(get_edit_post_link($current_course)) . '">&larr; Back to ' . esc_html(get_the_title($current_course)) . '</a> to reorder or manage the full lesson list.</p>';
        } else {
            echo '<p class="description">After saving, go to that course\'s own edit screen to place this lesson in the lesson order.</p>';
        }
        echo '</div>';

        // Module/section grouping — purely a display label, walked at
        // render time (BHC_Render_Course) to bucket consecutive lessons
        // sharing the same title into a collapsible section. Blank = the
        // lesson renders standalone exactly as before this field existed,
        // so no existing course changes until an author opts in. Free
        // text, not a taxonomy/CPT, to keep authoring as simple as "type
        // the section name" — grouping is inferred from lesson order
        // (already author-controlled via drag-drop) plus this label,
        // never a second ordering system to keep in sync.
        $module_title = get_post_meta($post->ID, '_bhc_module_title', true);
        echo '<p><label>Module / section (optional): <input type="text" name="bhc_module_title" value="' . esc_attr($module_title) . '" placeholder="e.g. Module 1: Foundations" style="width:100%;max-width:320px;"></label></p>';
        echo '<p class="description">Lessons in a row sharing the same module name are grouped under one collapsible heading in the course sidebar. Leave blank to keep this lesson ungrouped.</p>';

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

    /* ---------------- lesson metabox (steps summary, read-only) ---------------- */

    // A lesson's steps are now authored directly in this screen's real
    // main content area (bh_lesson has real 'editor' support as of the
    // real-post-editor migration — see BHC_ContentBridge's own
    // docblock) — this metabox is no longer an editor of its own, just
    // a read-only "what's actually in _bhc_steps right now" summary
    // (useful since that's the array BHC_Render/BHC_Progress/BHC_Gate
    // actually read, one save-cycle behind the block editor's own
    // canvas) plus a "preview as student" link. It never writes step
    // content — BHC_ContentBridge's save_post_bh_lesson hook is the
    // only writer, see that class's own docblock.
    public static function render_steps_metabox($post) {
        $steps = BHC_Steps::get($post->ID);

        // Per-step content labels rather than just a comma-separated list
        // of TYPES — describe_step() below pulls an actual snippet from
        // each step's own stored content.
        if ($steps) {
            echo '<p class="description">Current steps (' . count($steps) . '):</p>';
            echo '<ol class="bhc-steps-summary" style="margin:0 0 12px 22px;padding:0;">';
            foreach ($steps as $s) {
                echo '<li>' . esc_html(self::describe_step($s)) . '</li>';
            }
            echo '</ol>';
        } else {
            echo '<p class="description">No steps yet — add Lesson blocks (Text/Image/Video/Quiz) in the editor above.</p>';
        }

        // "Preview as student" — the actual public permalink, not an
        // editor-internal preview, since the whole point is seeing the
        // real step-walker (BHC_Render::render_lesson_steps()) a student
        // gets, including gating/drip state. Published posts link
        // straight to the permalink; a draft lesson (the common case
        // while an instructor is still building it out) uses
        // WordPress's own preview-link mechanism instead, since a
        // draft's permalink 404s for anyone without edit rights —
        // get_preview_post_link() handles the preview nonce/query args
        // this needs.
        $preview_url = get_post_status($post->ID) === 'publish' ? get_permalink($post->ID) : get_preview_post_link($post->ID);
        echo '<p><a class="button button-primary" href="' . esc_url($preview_url) . '" target="_blank" rel="noopener">Preview as student &rarr;</a></p>';
        echo '<p class="description">A lesson is a sequence of steps — mix Lesson: Text/Image/Video/Quiz blocks in any order above. Students see one step at a time and move forward as they complete each one.</p>';
    }

    // One line of real content per step, not just its type — reads
    // straight off the legacy $step array shape (BHC_Steps::get()'s own
    // return format), the same shape regardless of which authoring path
    // wrote it (BHC_ContentBridge::sync_legacy_steps() keeps _bhc_steps
    // in sync with the real editor's post_content, see that class's
    // docblock).
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
            if (isset($_POST['bhc_course_id'])) {
                $new_course_id = (int) $_POST['bhc_course_id'];
                // Validated against a real, non-trashed bh_course rather
                // than trusted as-posted — a crafted or stale POST
                // (e.g. a course deleted in another tab since this
                // screen loaded) would otherwise leave the lesson
                // pointing at nothing, exactly the desync class of bug
                // AJ's own audit flagged.
                if ($new_course_id && get_post_type($new_course_id) !== 'bh_course') $new_course_id = 0;

                $old_course_id = BHC_PostTypes::course_for_lesson($post_id);
                update_post_meta($post_id, '_bhc_course_id', $new_course_id);

                // Keep the course's own _bhc_lesson_order (the inverse,
                // independently-stored pointer) in sync automatically
                // instead of relying on the author to separately drag
                // this lesson into place on the course screen — the
                // exact "two pointers, nothing keeps them in sync" gap
                // the audit called out. Order-list edits made from the
                // course screen itself still win on that screen's own
                // save (save_course() below), this only keeps a lesson
                // reassigned from ITS OWN screen from vanishing off its
                // old course or failing to appear on its new one.
                if ($old_course_id !== $new_course_id) {
                    if ($old_course_id) self::remove_lesson_from_order($old_course_id, $post_id);
                    if ($new_course_id) self::add_lesson_to_order($new_course_id, $post_id);
                }
            }

            $module_title = sanitize_text_field($_POST['bhc_module_title'] ?? '');
            if ($module_title !== '') {
                update_post_meta($post_id, '_bhc_module_title', $module_title);
            } else {
                delete_post_meta($post_id, '_bhc_module_title');
            }

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
        // render_steps_metabox() above) — BHC_ContentBridge::sync_legacy_steps()
        // (a save_post_bh_lesson hook, fired after this same save cycle)
        // is now the ONLY writer of a lesson's step content. Two writers
        // pointed at the same _bhc_steps data was the exact "dual-write
        // divergence" hazard LMS-AUTHORING-DESIGN-PLAN.md Section 6
        // flagged; closing it by removing the second writer outright
        // (rather than adding reconciliation logic) is that doc's own
        // preferred resolution.
    }

    private static function remove_lesson_from_order($course_id, $lesson_id) {
        $order = BHC_PostTypes::lesson_order($course_id);
        $order = array_values(array_diff($order, [(int) $lesson_id]));
        update_post_meta($course_id, '_bhc_lesson_order', $order);
    }

    private static function add_lesson_to_order($course_id, $lesson_id) {
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
    public static function cleanup_deleted_course($post_id) {
        if (get_post_type($post_id) !== 'bh_course') return;
        $lessons = get_posts([
            'post_type' => 'bh_lesson', 'numberposts' => -1, 'post_status' => 'any',
            'meta_key' => '_bhc_course_id', 'meta_value' => $post_id,
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
    public static function cleanup_deleted_lesson($post_id) {
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
    public static function course_row_actions($actions, $post) {
        if ($post->post_type !== 'bh_course' || !current_user_can('edit_post', $post->ID)) return $actions;
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=bhc_duplicate_course&course_id=' . (int) $post->ID),
            'bhc_duplicate_course_' . $post->ID
        );
        $actions['bhc_duplicate'] = '<a href="' . esc_url($url) . '">Duplicate</a>';
        return $actions;
    }

    public static function handle_duplicate_course() {
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
            foreach (['_bhc_available_after_days', '_bhc_available_on_date'] as $key) {
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

    public static function course_column_content($col, $post_id) {
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
    public static function lesson_row_actions($actions, $post) {
        if ($post->post_type !== 'bh_lesson' || !current_user_can('edit_post', $post->ID)) return $actions;
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=bhc_duplicate_lesson&lesson_id=' . (int) $post->ID),
            'bhc_duplicate_lesson_' . $post->ID
        );
        $actions['bhc_duplicate'] = '<a href="' . esc_url($url) . '">Duplicate</a>';
        return $actions;
    }

    public static function handle_duplicate_lesson() {
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
        foreach (['_bhc_available_after_days', '_bhc_available_on_date'] as $key) {
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
    public static function handle_unassign_lesson() {
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
    public static function lesson_column_content($col, $post_id) {
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
}
