<?php
if (!defined('ABSPATH')) exit;

/**
 * Extracted from the old monolithic class-render.php (SRP QA pass,
 * bh-courses 0.4.8) — that one file was handling catalog browse/search/
 * sort, a course detail page, AND the lesson step-walker/quiz UI all in
 * one 589-line class, three genuinely separate concerns with no overlap
 * beyond a couple of shared helpers. This class owns exactly one of
 * them: the [bh_courses] catalog shortcode and the bh_course post-type
 * archive template's real content (browse/search/filter/sort,
 * QUIZ-AND-CATALOG-DESIGN-PLAN.md Part 2.4/2.5).
 *
 * Pure move, not a rewrite — every method here is byte-for-byte the same
 * logic that used to live in BHC_Render, just relocated. BHC_Render
 * itself (class-render.php) still owns the shortcode/hook registration
 * and still exposes a public render_catalog() that delegates straight
 * here — every existing external call site (class-test-suite.php,
 * templates/archive-bh_course.php) keeps working with ZERO changes.
 */
class BHC_Render_Catalog {
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
        if (!in_array($sort, ['newest', 'alpha', 'popular', 'top_rated'], true)) $sort = 'newest';
        $page = max(1, (int) ($_GET['bhc_paged'] ?? 1));

        $base_args = ['post_type' => 'bh_course', 'post_status' => 'publish'];
        if ($search !== '') $base_args['s'] = $search;
        $tax_query = [];
        if ($category) $tax_query[] = ['taxonomy' => 'bhc_course_category', 'field' => 'slug', 'terms' => $category];
        if ($topic) $tax_query[] = ['taxonomy' => 'bhc_course_topic', 'field' => 'slug', 'terms' => $topic];
        if ($tax_query) $base_args['tax_query'] = $tax_query;

        if ($sort === 'popular' || $sort === 'top_rated') {
            // Same "resolve IDs, sort in PHP, re-query with post__in" shape
            // as the popular-sort branch below — a rating average lives in
            // bhc_reviews, not postmeta, so WP_Query has no native orderby
            // for it either. Courses with zero approved reviews sort last
            // (average 0), never excluded — a course simply not yet
            // reviewed shouldn't vanish from a "highest rated" browse.
            $all_ids = get_posts(array_merge($base_args, ['fields' => 'ids', 'posts_per_page' => -1]));
            if ($sort === 'popular') {
                $counts = BHC_Progress::enrollment_counts();
            } else {
                $ratings = class_exists('BHC_Reviews') ? BHC_Reviews::average_ratings() : [];
                $counts = [];
                foreach ($ratings as $cid => $r) $counts[$cid] = $r['average'];
            }
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
            // UX-AUDIT-2026-07.md's top recommendation — the shared
            // BHY_Style::empty_state_html() component instead of a bare
            // one-line message with no next step. The filtered/zero
            // distinction already existed here (this file's own prior
            // ternary); this just gives that distinction a real CTA on
            // each side of it, matching what WooCommerce's own default
            // empty state (one plugin away) already does correctly.
            $is_filtered = (bool) ($search || $category || $topic);
            echo class_exists('BHY_Style') ? BHY_Style::empty_state_html([
                'reason' => $is_filtered ? 'filtered' : 'zero',
                'title' => $is_filtered ? 'No courses match your filters' : 'No courses published yet',
                'description' => $is_filtered ? '' : 'Check back soon — new courses will show up here as they\'re published.',
                'clear_url' => $is_filtered ? remove_query_arg(['bhc_s', 'bhc_category', 'bhc_topic', 'bhc_paged']) : '',
            ]) : '<p class="bhc-empty">No courses found' . ($is_filtered ? ' matching your filters.' : ' yet.') . '</p>';
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
        if (class_exists('BHC_Reviews')) {
            $rating = BHC_Reviews::average_rating($course->ID);
            if ($rating['count'] > 0) {
                echo '<span class="bhc-card-rating">&#9733; ' . esc_html($rating['average']) . ' <span class="bhc-card-rating-count">(' . (int) $rating['count'] . ')</span></span>';
            }
        }
        echo '</div>';

        if ($instructor) echo '<div class="bhc-card-instructor">' . get_avatar($instructor->ID, 20) . ' <span>' . esc_html($instructor->display_name ?: $instructor->user_login) . '</span></div>';

        echo '<div class="bhc-excerpt">' . wp_kses_post(get_the_excerpt($course->ID)) . '</div>';
        if ($uid && !$locked) {
            echo '<div class="bhc-progress-bar"><div class="bhc-progress-fill" style="width:' . (int) $percent . '%"></div></div><p class="bhc-progress-label">' . (int) $percent . '% complete</p>';
            echo BHC_Render_Course::render_continue_cta($uid, $course->ID, $percent);
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
        foreach (['newest' => 'Newest', 'alpha' => 'A–Z', 'popular' => 'Most popular', 'top_rated' => 'Highest rated'] as $key => $label) {
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
}
