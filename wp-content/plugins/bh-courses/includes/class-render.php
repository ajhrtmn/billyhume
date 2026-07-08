<?php
if (!defined('ABSPATH')) exit;

/**
 * Front end: a catalog shortcode and a single-lesson step-walker.
 * Kept deliberately simple/server-rendered (one step visible at a
 * time, plain form posts to AJAX for quiz submit / mark-complete) —
 * matches this ecosystem's general "plain PHP + a little JS," not a
 * SPA rebuild of bh-streaming's player.
 */
class BHC_Render {
    public static function init() {
        add_shortcode('bh_courses', [self::class, 'render_catalog']);
        add_shortcode('bh_course', [self::class, 'render_course']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
        add_filter('template_include', [self::class, 'maybe_use_archive_template']);
    }

    // A real, themeable /courses/ archive ('has_archive' => 'courses',
    // class-post-types.php) rather than the catalog only ever being
    // reachable via the [bh_courses] shortcode — the same "public CPT
    // with its own view" instinct render_lesson_steps()'s own docblock
    // already states for lessons. Respects a theme's own
    // archive-bh_course.php if one exists (WordPress's normal template
    // hierarchy already resolves that BEFORE template_include fires —
    // this filter only supplies the fallback the theme didn't provide),
    // same "degrade to a sane default, never fight a real override"
    // posture as everything else in this ecosystem.
    public static function maybe_use_archive_template($template) {
        if (!is_post_type_archive('bh_course')) return $template;
        if ($template && strpos(basename($template), 'archive-bh_course') !== false) return $template;
        return BHC_PATH . 'templates/archive-bh_course.php';
    }

    public static function maybe_enqueue() {
        // Extended to also cover the bh_course post-type ARCHIVE
        // ('has_archive' => 'courses', class-post-types.php) — the
        // catalog rebuild below is a real enough surface (search/filter/
        // sort) that it needs its assets there too, not just on a page
        // carrying the [bh_courses] shortcode explicitly. The is_singular()
        // branch (shortcode-embedded catalog, a course/lesson page) is
        // unchanged.
        if (is_post_type_archive('bh_course')) {
            self::enqueue_assets();
            return;
        }
        if (!is_singular()) return;
        global $post;
        if (!$post || !(has_shortcode($post->post_content, 'bh_courses') || has_shortcode($post->post_content, 'bh_course') || $post->post_type === 'bh_lesson')) return;
        self::enqueue_assets();
    }

    private static function enqueue_assets() {
        wp_enqueue_style('bhc-front', BHC_URL . 'assets/css/courses.css', [], BHC_VER);
        if (class_exists('BHY_Style')) wp_add_inline_style('bhc-front', BHY_Style::inline_css());
        wp_enqueue_script('bhc-front', BHC_URL . 'assets/js/courses.js', [], BHC_VER, true);
        wp_localize_script('bhc-front', 'BHCData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('bhc_progress'),
        ]);
    }

    /* ---------------- catalog ---------------- */

    const PER_PAGE = 12;

    // Real browse/search/filter/sort (QUIZ-AND-CATALOG-DESIGN-PLAN.md
    // Part 2.4/2.5), replacing the old unfiltered get_posts() dump. Own
    // GET params (bhc_s/bhc_category/bhc_topic/bhc_sort/bhc_paged)
    // rather than WP's own 's'/'paged' — this can be embedded via
    // shortcode on any page, possibly alongside other content/queries,
    // so it needs params that can't collide with the main query's own.
    public static function render_catalog() {
        $search = isset($_GET['bhc_s']) ? sanitize_text_field(wp_unslash($_GET['bhc_s'])) : '';
        $category = isset($_GET['bhc_category']) ? sanitize_title(wp_unslash($_GET['bhc_category'])) : '';
        $topic = isset($_GET['bhc_topic']) ? sanitize_title(wp_unslash($_GET['bhc_topic'])) : '';
        $sort = isset($_GET['bhc_sort']) ? sanitize_key($_GET['bhc_sort']) : 'newest';
        if (!in_array($sort, ['newest', 'alpha', 'popular'], true)) $sort = 'newest';
        $page = max(1, (int) ($_GET['bhc_paged'] ?? 1));

        $base_args = ['post_type' => 'bh_course', 'post_status' => 'publish'];
        if ($search !== '') $base_args['s'] = $search;
        $tax_query = [];
        if ($category) $tax_query[] = ['taxonomy' => 'bhc_course_category', 'field' => 'slug', 'terms' => $category];
        if ($topic) $tax_query[] = ['taxonomy' => 'bhc_course_topic', 'field' => 'slug', 'terms' => $topic];
        if ($tax_query) $base_args['tax_query'] = $tax_query;

        if ($sort === 'popular') {
            // WP_Query has no native orderby for a signal that lives in
            // bhc_enrollments, not postmeta (QUIZ-AND-CATALOG-DESIGN-PLAN.md
            // Part 2.5's option (b), chosen for a catalog this size):
            // resolve every course ID matching search+filters first
            // (ids-only, cheap), sort THOSE by enrollment count
            // (courses with zero enrollments still included, just sorted
            // last), then re-query that exact ordered ID list with
            // pagination via post__in + orderby=post__in.
            $all_ids = get_posts(array_merge($base_args, ['fields' => 'ids', 'posts_per_page' => -1]));
            $counts = BHC_Progress::enrollment_counts();
            usort($all_ids, function ($a, $b) use ($counts) {
                $ca = $counts[$a] ?? 0; $cb = $counts[$b] ?? 0;
                if ($ca === $cb) return $a <=> $b; // stable tiebreak
                return $cb <=> $ca;
            });
            $total = count($all_ids);
            $ids_page = array_slice($all_ids, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
            $query = $ids_page
                ? new WP_Query(['post_type' => 'bh_course', 'post_status' => 'publish', 'post__in' => $ids_page, 'orderby' => 'post__in', 'posts_per_page' => self::PER_PAGE])
                : new WP_Query(['post__in' => [0]]); // deliberately-empty query, avoids post__in=[] matching everything
            $max_pages = (int) ceil($total / self::PER_PAGE);
        } else {
            $query_args = array_merge($base_args, ['posts_per_page' => self::PER_PAGE, 'paged' => $page]);
            $query_args['orderby'] = $sort === 'alpha' ? 'title' : 'date';
            $query_args['order'] = $sort === 'alpha' ? 'ASC' : 'DESC';
            $query = new WP_Query($query_args);
            $max_pages = (int) $query->max_num_pages;
        }

        $uid = get_current_user_id();
        ob_start();
        echo '<div class="bhc-catalog-wrap">';
        echo self::render_catalog_filters($search, $category, $topic, $sort);

        if (!$query->have_posts()) {
            echo '<p class="bhc-empty">No courses found' . ($search || $category || $topic ? ' matching your filters.' : ' yet.') . '</p>';
        } else {
            echo '<div class="bhc-catalog">';
            foreach ($query->posts as $course) {
                echo self::render_course_card($course, $uid);
            }
            echo '</div>';
            echo self::render_pagination($page, $max_pages, $search, $category, $topic, $sort);
        }
        wp_reset_postdata();
        echo '</div>';
        return ob_get_clean();
    }

    private static function render_course_card($course, $uid) {
        $locked = !BHC_Gate::user_can_access_course($uid, $course->ID);
        $percent = $uid ? BHC_Progress::course_percent($uid, $course->ID) : 0;
        $difficulty_label = BHC_PostTypes::difficulty_label($course->ID);
        $instructor = BHC_PostTypes::instructor($course->ID);
        $lesson_count = BHC_PostTypes::lesson_count($course->ID);

        ob_start();
        echo '<div class="bhc-course-card' . ($locked ? ' bhc-locked' : '') . '">';
        if (has_post_thumbnail($course->ID)) echo get_the_post_thumbnail($course->ID, 'medium');
        echo '<h3><a href="' . esc_url(get_permalink($course->ID)) . '">' . esc_html(get_the_title($course->ID)) . '</a>' . ($locked ? ' <span class="bhc-lock">&#128274;</span>' : '') . '</h3>';

        echo '<div class="bhc-card-meta">';
        if ($difficulty_label) echo '<span class="bhc-badge bhc-badge-difficulty bhc-difficulty-' . esc_attr(BHC_PostTypes::difficulty($course->ID)) . '">' . esc_html($difficulty_label) . '</span>';
        echo '<span class="bhc-card-lesson-count">' . (int) $lesson_count . ' lesson' . ($lesson_count === 1 ? '' : 's') . '</span>';
        echo '</div>';

        if ($instructor) echo '<div class="bhc-card-instructor">' . get_avatar($instructor->ID, 20) . ' <span>' . esc_html($instructor->display_name ?: $instructor->user_login) . '</span></div>';

        echo '<div class="bhc-excerpt">' . wp_kses_post(get_the_excerpt($course->ID)) . '</div>';
        if ($uid && !$locked) {
            echo '<div class="bhc-progress-bar"><div class="bhc-progress-fill" style="width:' . (int) $percent . '%"></div></div><p class="bhc-progress-label">' . (int) $percent . '% complete</p>';
            echo self::render_continue_cta($uid, $course->ID, $percent);
        }
        echo '</div>';
        return ob_get_clean();
    }

    // A plain GET form (works with zero JS — courses.js progressively
    // enhances it with auto-submit-on-change, see that file) — search
    // box, category/topic dropdowns sourced from the real taxonomies
    // (class-post-types.php), and the three sorts Part 2.5 settled on.
    // Preserves whichever filters are already active as hidden fields so
    // changing one doesn't clear the others.
    private static function render_catalog_filters($search, $category, $topic, $sort) {
        $categories = get_terms(['taxonomy' => 'bhc_course_category', 'hide_empty' => true]);
        $topics = get_terms(['taxonomy' => 'bhc_course_topic', 'hide_empty' => true]);

        ob_start();
        echo '<form class="bhc-catalog-filters" method="get">';
        // Preserve any non-bhc_ query args already on the URL (e.g. a
        // page builder's own ?preview=true) rather than dropping them.
        foreach ($_GET as $key => $value) {
            if (strpos($key, 'bhc_') === 0 || !is_scalar($value)) continue;
            echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
        }
        echo '<input type="search" name="bhc_s" value="' . esc_attr($search) . '" placeholder="Search courses…" class="bhc-filter-search">';

        if (!is_wp_error($categories) && $categories) {
            echo '<select name="bhc_category" class="bhc-filter-select"><option value="">All categories</option>';
            foreach ($categories as $t) {
                echo '<option value="' . esc_attr($t->slug) . '"' . selected($category, $t->slug, false) . '>' . esc_html($t->name) . '</option>';
            }
            echo '</select>';
        }
        if (!is_wp_error($topics) && $topics) {
            echo '<select name="bhc_topic" class="bhc-filter-select"><option value="">All topics</option>';
            foreach ($topics as $t) {
                echo '<option value="' . esc_attr($t->slug) . '"' . selected($topic, $t->slug, false) . '>' . esc_html($t->name) . '</option>';
            }
            echo '</select>';
        }

        echo '<select name="bhc_sort" class="bhc-filter-select">';
        foreach (['newest' => 'Newest', 'alpha' => 'A–Z', 'popular' => 'Most popular'] as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($sort, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';

        echo '<button type="submit" class="bhc-btn bhc-btn-secondary">Filter</button>';
        echo '</form>';
        return ob_get_clean();
    }

    private static function render_pagination($page, $max_pages, $search, $category, $topic, $sort) {
        if ($max_pages <= 1) return '';
        $base = remove_query_arg('bhc_paged');
        ob_start();
        echo '<nav class="bhc-pagination">';
        if ($page > 1) {
            echo '<a class="bhc-btn bhc-btn-secondary" href="' . esc_url(add_query_arg('bhc_paged', $page - 1, $base)) . '">&larr; Previous</a>';
        }
        echo '<span class="bhc-pagination-status">Page ' . (int) $page . ' of ' . (int) $max_pages . '</span>';
        if ($page < $max_pages) {
            echo '<a class="bhc-btn bhc-btn-secondary" href="' . esc_url(add_query_arg('bhc_paged', $page + 1, $base)) . '">Next &rarr;</a>';
        }
        echo '</nav>';
        return ob_get_clean();
    }

    // Shared between the catalog card and the course page itself — both
    // need the exact same "where should this student go next" decision,
    // just rendered at different sizes. $percent is passed in rather
    // than recomputed since both call sites already have it (or compute
    // it themselves right before calling this).
    private static function render_continue_cta($uid, $course_id, $percent) {
        $target_lesson = BHC_Progress::first_incomplete_lesson($uid, $course_id);
        if ($target_lesson) {
            $label = $percent > 0 ? 'Continue' : 'Start';
            return '<a class="bhc-btn bhc-continue-btn" href="' . esc_url(get_permalink($target_lesson)) . '">' . esc_html($label) . ' &rarr;</a>';
        }
        // Nothing left to resume into — either genuinely 100% done, or
        // every lesson is either empty or drip-locked (first_incomplete_lesson()
        // can't tell those apart, and neither needs a CTA either way).
        // Only show "Review" once actually complete, not for the
        // ambiguous "nothing open yet" case.
        if ($percent >= 100) {
            $lesson_ids = BHC_PostTypes::lesson_order($course_id);
            if ($lesson_ids) {
                return '<a class="bhc-btn bhc-btn-secondary bhc-continue-btn" href="' . esc_url(get_permalink($lesson_ids[0])) . '">Review course</a>';
            }
        }
        return '';
    }

    /* ---------------- single course (lesson list) ---------------- */

    private static function render_course_header($course_id, $uid, $locked) {
        $difficulty_label = BHC_PostTypes::difficulty_label($course_id);
        $instructor = BHC_PostTypes::instructor($course_id);
        $lesson_count = BHC_PostTypes::lesson_count($course_id);
        $duration = BHC_PostTypes::duration_note($course_id);
        $categories = get_the_terms($course_id, 'bhc_course_category');
        $topics = get_the_terms($course_id, 'bhc_course_topic');

        ob_start();
        echo '<div class="bhc-course-header">';
        if (has_post_thumbnail($course_id)) echo '<div class="bhc-course-cover">' . get_the_post_thumbnail($course_id, 'large') . '</div>';
        echo '<h1 class="bhc-course-title">' . esc_html(get_the_title($course_id)) . '</h1>';

        echo '<div class="bhc-course-meta">';
        if ($difficulty_label) echo '<span class="bhc-badge bhc-badge-difficulty bhc-difficulty-' . esc_attr(BHC_PostTypes::difficulty($course_id)) . '">' . esc_html($difficulty_label) . '</span>';
        echo '<span>' . (int) $lesson_count . ' lesson' . ($lesson_count === 1 ? '' : 's') . '</span>';
        if ($duration) echo '<span>' . esc_html($duration) . '</span>';
        if ($instructor) echo '<span class="bhc-course-instructor">' . get_avatar($instructor->ID, 24) . ' Taught by ' . esc_html($instructor->display_name ?: $instructor->user_login) . '</span>';
        echo '</div>';

        if (!empty($categories) && !is_wp_error($categories)) {
            echo '<div class="bhc-course-terms">';
            foreach ($categories as $t) echo '<span class="bhc-term bhc-term-category">' . esc_html($t->name) . '</span>';
            if (!empty($topics) && !is_wp_error($topics)) {
                foreach ($topics as $t) echo '<span class="bhc-term bhc-term-topic">' . esc_html($t->name) . '</span>';
            }
            echo '</div>';
        }

        $content = get_post_field('post_content', $course_id);
        if ($content) echo '<div class="bhc-course-description">' . apply_filters('the_content', $content) . '</div>';

        if ($uid && !$locked) {
            $percent = BHC_Progress::course_percent($uid, $course_id);
            $cta = self::render_continue_cta($uid, $course_id, $percent);
            if ($cta) echo '<div class="bhc-course-header-cta">' . $cta . '</div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    public static function render_course($atts) {
        $course_id = (int) ($atts['id'] ?? get_the_ID());
        if (!$course_id || get_post_type($course_id) !== 'bh_course') return '';

        $uid = get_current_user_id();
        $locked = !BHC_Gate::user_can_access_course($uid, $course_id);
        $lesson_ids = BHC_PostTypes::lesson_order($course_id);

        // The moment a logged-in student is confirmed to have real
        // access, start their drip-scheduling clock for this course —
        // see BHC_Progress::enroll_if_needed()'s docblock for why this
        // is the one place that's safe/correct to call it from (access
        // just got confirmed, not merely attempted).
        if ($uid && !$locked) BHC_Progress::enroll_if_needed($uid, $course_id);

        ob_start();
        echo '<div class="bhc-course-view">';
        // Detail-page header (QUIZ-AND-CATALOG-DESIGN-PLAN.md Section
        // 2.7): wraps the existing lesson-list output below rather than
        // replacing it — full cover, description, instructor,
        // difficulty, duration, category/topic terms, enrollment CTA.
        // Ratings/reviews deliberately deferred (design doc scoped them
        // out of this pass — no data model for them exists yet).
        echo self::render_course_header($course_id, $uid, $locked);
        if ($locked) {
            echo BHC_Gate::render_paywall_notice($course_id);
            // Even locked, a prospective student can see what they'd be
            // signing up for — a syllabus of lesson TITLES only (no
            // content, no permalink), same "tease, don't leak" posture
            // the drip-lock notice already takes for individual lessons.
            if ($lesson_ids) {
                echo '<div class="bhc-syllabus-preview"><h4>What you\'ll learn</h4><ol class="bhc-lesson-list bhc-lesson-list-preview">';
                foreach ($lesson_ids as $lesson_id) {
                    if (get_post_status($lesson_id) !== 'publish') continue;
                    echo '<li><span>' . esc_html(get_the_title($lesson_id)) . '</span></li>';
                }
                echo '</ol></div>';
            }
        } else {
            // Enrollment/continue CTA now lives in render_course_header()
            // above (shown for every locked/unlocked state consistently)
            // — not duplicated here anymore.
            echo '<ol class="bhc-lesson-list">';
            foreach ($lesson_ids as $i => $lesson_id) {
                if (get_post_status($lesson_id) !== 'publish') continue;
                $open = BHC_Gate::lesson_is_open($uid, $lesson_id);
                $step_count = BHC_Steps::count($lesson_id);
                $done_count = $uid ? count(BHC_Progress::completed_steps($uid, $lesson_id)) : 0;
                $complete = $step_count > 0 && $done_count >= $step_count;
                echo '<li class="' . ($complete ? 'bhc-lesson-done' : '') . ($open ? '' : ' bhc-lesson-locked') . '">';
                if ($open) {
                    echo '<a href="' . esc_url(get_permalink($lesson_id)) . '">' . esc_html(get_the_title($lesson_id)) . '</a>';
                } else {
                    echo '<span>' . esc_html(get_the_title($lesson_id)) . '</span> <span class="bhc-drip-notice">&#128274; ' . BHC_Gate::drip_notice($uid, $lesson_id) . '</span>';
                }
                if ($open && $uid && $step_count) echo ' <span class="bhc-lesson-progress">(' . (int) $done_count . '/' . (int) $step_count . ')</span>';
                if ($complete) echo ' <span class="bhc-check">&#10003;</span>';
                echo '</li>';
            }
            echo '</ol>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    /* ---------------- single lesson (the step walker) ---------------- */

    // Hooked onto the_content for bh_lesson so a plain lesson permalink
    // just works with zero shortcode needed — same "public CPT with its
    // own single view" approach bh-streaming doesn't use (it's an SPA)
    // but bh-contest's/most plain WP content does.
    public static function render_lesson_steps($lesson_id) {
        $uid = get_current_user_id();
        $course_id = BHC_PostTypes::course_for_lesson($lesson_id);

        // Tier access and drip scheduling are checked (and reported)
        // separately, not through the combined user_can_access_lesson()
        // check — a paid-up student hitting a not-yet-open lesson should
        // see "opens in 3 days," not a confusing "become a supporter"
        // prompt for something they've already paid for.
        if ($course_id && !BHC_Gate::user_can_access_course($uid, $course_id)) {
            return BHC_Gate::render_paywall_notice($course_id);
        }
        // Same enrollment recording as render_course() — a student who
        // deep-links straight to a lesson (never visiting the course
        // page first) still needs their drip clock started.
        if ($uid && $course_id) BHC_Progress::enroll_if_needed($uid, $course_id);
        if (!BHC_Gate::lesson_is_open($uid, $lesson_id)) {
            return '<div class="bhc-drip-locked"><p>&#128274; ' . BHC_Gate::drip_notice($uid, $lesson_id) . '</p></div>';
        }

        $steps = BHC_Steps::get($lesson_id);
        if (!$steps) return '<p class="bhc-empty">This lesson has no content yet.</p>';

        $completed = $uid ? BHC_Progress::completed_steps($uid, $lesson_id) : [];
        // First not-yet-completed step, so a returning student lands
        // where they left off rather than back at step 1 every time.
        $start_index = 0;
        foreach ($steps as $i => $step) {
            if (!in_array($i, $completed, true)) { $start_index = $i; break; }
            $start_index = $i + 1;
        }
        $start_index = min($start_index, count($steps) - 1);

        // Where "Next Lesson →" should go once the last step here is
        // cleared — the course-level sibling of the step-level "what's
        // next" logic above. null if this lesson is orphaned (no
        // course) or is the course's last lesson; the JS decides what
        // to show based on which of the two is true (see
        // .bhc-lesson-next below).
        $next_lesson_id = null;
        if ($course_id) {
            $order = BHC_PostTypes::lesson_order($course_id);
            $pos = array_search((int) $lesson_id, $order, true);
            if ($pos !== false && isset($order[$pos + 1])) $next_lesson_id = $order[$pos + 1];
        }

        ob_start();
        echo '<div class="bhc-lesson" data-lesson-id="' . (int) $lesson_id . '" data-step-count="' . count($steps) . '" data-start-index="' . (int) $start_index . '">';
        echo '<div class="bhc-step-progress">Step <span class="bhc-step-current">' . ($start_index + 1) . '</span> of ' . count($steps) . '</div>';

        foreach ($steps as $i => $step) {
            $is_done = in_array($i, $completed, true);
            $visible = $i === $start_index ? '' : ' style="display:none;"';
            echo '<div class="bhc-step bhc-step-' . esc_attr($step['type']) . ($is_done ? ' bhc-step-done' : '') . '" data-step-index="' . (int) $i . '"' . $visible . '>';
            echo self::render_step($lesson_id, $i, $step, $is_done);
            // Revisiting an earlier (already-rendered, already-completed)
            // step is just showing a different one of these divs — no
            // server round trip, no completion-state change. Omitted on
            // the first step (nothing behind it).
            if ($i > 0) echo '<button type="button" class="bhc-btn bhc-btn-secondary bhc-step-back" data-target-index="' . (int) ($i - 1) . '">&larr; Back</button>';
            echo '</div>';
        }

        // Hidden until the JS reveals it, the moment the LAST step is
        // marked complete/passed — see courses.js's advance(). Rendered
        // unconditionally (not just when start_index is already at the
        // last step) so a student who completes the lesson mid-session
        // sees it appear without a page reload.
        echo '<div class="bhc-lesson-next" style="display:none;">';
        if (count($steps) > 0) {
            echo '<button type="button" class="bhc-btn bhc-btn-secondary bhc-step-back" data-target-index="' . (int) (count($steps) - 1) . '">&larr; Back to lesson</button>';
        }
        if ($next_lesson_id) {
            echo '<a class="bhc-btn" href="' . esc_url(get_permalink($next_lesson_id)) . '">Next Lesson &rarr;</a>';
        } elseif ($course_id) {
            echo '<p class="bhc-course-complete">&#127881; You\'ve completed this course!</p>';
            echo '<a class="bhc-btn" href="' . esc_url(get_permalink($course_id)) . '">Back to course</a>';
        }
        echo '</div>';

        echo '</div>';
        return ob_get_clean();
    }

    private static function render_step($lesson_id, $index, $step, $is_done) {
        ob_start();
        if ($step['type'] === 'text') {
            echo '<div class="bhc-step-text">' . wp_kses_post($step['content']) . '</div>';
            echo '<button type="button" class="bhc-btn bhc-mark-complete"' . ($is_done ? ' disabled' : '') . '>' . ($is_done ? 'Completed' : 'Mark complete &amp; continue') . '</button>';
        } elseif ($step['type'] === 'image') {
            foreach ($step['attachment_ids'] as $attachment_id) {
                echo wp_get_attachment_image($attachment_id, 'large', false, ['class' => 'bhc-step-image']);
            }
            if (!empty($step['caption'])) echo '<p class="bhc-step-caption">' . esc_html($step['caption']) . '</p>';
            echo '<button type="button" class="bhc-btn bhc-mark-complete"' . ($is_done ? ' disabled' : '') . '>' . ($is_done ? 'Completed' : 'Mark complete &amp; continue') . '</button>';
        } elseif ($step['type'] === 'video') {
            if ($step['source'] === 'upload') {
                // wp_get_attachment_url() is the one API surface an
                // offload plugin (see Own Ur Shit's dashboard entry for
                // Advanced Media Offloader) rewrites transparently —
                // this plain <video> tag needs zero changes whether the
                // file is on this server's disk or Cloudflare R2.
                $url = wp_get_attachment_url($step['attachment_id']);
                if ($url) {
                    echo '<video class="bhc-step-video" controls preload="metadata" src="' . esc_url($url) . '"></video>';
                } else {
                    echo '<p class="bhc-empty">Video file not found.</p>';
                }
            } else {
                // A plain external URL — Cloudflare Stream/Bunny Stream
                // iframe embeds and most other "give me embed code"
                // platforms hand you either a direct file URL (works in
                // a <video> tag) or their own iframe embed URL. Since we
                // can't tell which without knowing the provider, embed
                // via <iframe> when the URL looks like one of the common
                // *embed*/*iframe* patterns, otherwise treat it as a
                // direct video URL — good enough for v1 without needing
                // provider-specific integration code.
                $url = $step['video_url'];
                if (preg_match('#(iframe|embed|player)#i', $url)) {
                    echo '<iframe class="bhc-step-video-embed" src="' . esc_url($url) . '" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>';
                } else {
                    echo '<video class="bhc-step-video" controls preload="metadata" src="' . esc_url($url) . '"></video>';
                }
            }
            if (!empty($step['caption'])) echo '<p class="bhc-step-caption">' . esc_html($step['caption']) . '</p>';
            echo '<button type="button" class="bhc-btn bhc-mark-complete"' . ($is_done ? ' disabled' : '') . '>' . ($is_done ? 'Completed' : 'Mark complete &amp; continue') . '</button>';
        } elseif ($step['type'] === 'quiz') {
            $uid = get_current_user_id();
            $max_attempts = (int) ($step['max_attempts'] ?? 0);
            $attempts_used = $uid ? BHC_Progress::attempts($uid, $lesson_id, $index) : 0;
            $already_passed = $is_done; // is_step_complete()'s rule: a quiz row only reads "done" once passed
            $exhausted = !$already_passed && $max_attempts > 0 && $attempts_used >= $max_attempts;

            // Revisiting a passed quiz (via the back button) now renders
            // the REAL stored snapshot — QUIZ-AND-CATALOG-DESIGN-PLAN.md
            // Part 1, closing the gap this file used to flag in a comment
            // right here. A pre-migration row (passed before the answers
            // column existed) has no snapshot; that one honest case falls
            // back to the old aggregate-only "you passed" note rather
            // than fabricating a breakdown that was never recorded.
            $snapshot = ($already_passed && $uid) ? BHC_Progress::stored_answers($uid, $lesson_id, $index) : null;

            if ($snapshot && !empty($snapshot['questions'])) {
                echo self::render_quiz_review($snapshot);
            } else {
                $disable_inputs = $exhausted || $already_passed;
                echo '<form class="bhc-quiz-form" data-max-attempts="' . $max_attempts . '" data-attempts-used="' . $attempts_used . '">';
                foreach ($step['questions'] as $qi => $q) {
                    echo '<fieldset class="bhc-quiz-question" data-question-index="' . (int) $qi . '"><legend>' . esc_html($q['question']) . '</legend>';
                    foreach ($q['choices'] as $ci => $choice) {
                        echo '<label class="bhc-quiz-choice"><input type="radio" name="q' . (int) $qi . '" value="' . (int) $ci . '"' . ($disable_inputs ? ' disabled' : '') . '> <span class="bhc-choice-text">' . esc_html($choice) . '</span></label>';
                    }
                    echo '</fieldset>';
                }
                if ($already_passed) {
                    echo '<p class="bhc-attempts-note bhc-quiz-passed-note">&#10003; You already passed this quiz.</p>';
                } elseif ($max_attempts > 0) {
                    echo '<p class="bhc-attempts-note">' . ($exhausted ? 'No attempts remaining (' . $max_attempts . ' allowed).' : ($max_attempts - $attempts_used) . ' of ' . $max_attempts . ' attempts remaining.') . '</p>';
                }
                if (!$already_passed) {
                    echo '<button type="submit" class="bhc-btn bhc-submit-quiz"' . ($exhausted ? ' disabled' : '') . '>Submit answers</button>';
                }
                echo '<div class="bhc-quiz-result" style="display:' . ($exhausted && !$already_passed ? '' : 'none') . '">' . ($exhausted && !$already_passed ? 'No attempts remaining.' : '') . '</div>';
                echo '</form>';
            }
        }
        return ob_get_clean();
    }

    // Static, non-interactive breakdown of a stored quiz snapshot — used
    // both for the passed-quiz review above (server-rendered on page
    // load) and mirrored client-side by courses.js right after a fresh
    // submission (same visual language, built from the same 'questions'
    // shape BHC_Steps::score_quiz() returns — see that method's docblock).
    // Marks the student's chosen choice and, when they got it wrong, the
    // actually-correct one too — the "better visual states" half of the
    // quiz UX ask, matching (B) end-of-submission review rather than
    // (A) per-question-as-you-go (QUIZ-AND-CATALOG-DESIGN-PLAN.md Part
    // 1.5 — (A) would let a student game the max_attempts budget one
    // question at a time; (B) can't, since the attempt is already spent
    // and scored before any correctness is shown).
    public static function render_quiz_review($snapshot) {
        $score = (int) ($snapshot['score'] ?? 0);
        ob_start();
        echo '<div class="bhc-quiz-review">';
        foreach ((array) ($snapshot['questions'] ?? []) as $qi => $q) {
            $chosen = (int) ($q['chosen_index'] ?? -1);
            $correct_index = (int) ($q['correct_index'] ?? -1);
            $q_correct = $chosen === $correct_index;
            echo '<fieldset class="bhc-quiz-question bhc-quiz-question-review ' . ($q_correct ? 'bhc-q-correct' : 'bhc-q-incorrect') . '"><legend>' . esc_html($q['q'] ?? '') . '</legend>';
            foreach ((array) ($q['choices'] ?? []) as $ci => $choice) {
                $classes = ['bhc-quiz-choice', 'bhc-quiz-choice-review'];
                if ($ci === $correct_index) $classes[] = 'bhc-correct';
                if ($ci === $chosen && !$q_correct) $classes[] = 'bhc-choice-incorrect';
                $marker = '';
                if ($ci === $correct_index) $marker = ' <span class="bhc-choice-marker">&#10003; Correct answer</span>';
                elseif ($ci === $chosen) $marker = ' <span class="bhc-choice-marker">&#10007; Your answer</span>';
                echo '<div class="' . esc_attr(implode(' ', $classes)) . '"><span class="bhc-choice-text">' . esc_html($choice) . '</span>' . $marker . '</div>';
            }
            echo '</fieldset>';
        }
        echo '<p class="bhc-attempts-note bhc-quiz-passed-note">&#10003; You passed this quiz — score: ' . $score . '%.</p>';
        echo '</div>';
        return ob_get_clean();
    }
}
