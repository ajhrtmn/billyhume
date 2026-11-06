<?php
if (!defined('ABSPATH')) exit;

/**
 * The [bh_courses] catalog shortcode and bh_course archive content: browse, search,
 * filter, sort.
 */
class BHC_Render_Catalog {
    const PER_PAGE = 12;

    // Own GET params (bhc_s/bhc_category/bhc_topic/bhc_sort/bhc_paged), not WP's 's'/'paged',
    // so an embedded catalog can't collide with the main query.
    public static function render_catalog(): string {
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
            // Rating lives in bhc_reviews, so resolve IDs, sort in PHP, re-query with post__in.
            // Unreviewed courses sort last rather than being excluded.
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

        // Catalog-level SEO: CollectionPage/ItemList, since this page lists courses.
        if (class_exists('BH_SEO')) {
            BH_SEO::set_page_data([
                'title' => 'Courses — ' . get_bloginfo('name'),
                'description' => 'Browse courses on ' . get_bloginfo('name') . '.',
                'url' => remove_query_arg(['bhc_s', 'bhc_category', 'bhc_topic', 'bhc_paged', 'bhc_sort']),
                'type' => 'website',
                'schema' => [
                    '@context' => 'https://schema.org',
                    '@type' => 'CollectionPage',
                    'name' => 'Courses',
                    'url' => remove_query_arg(['bhc_s', 'bhc_category', 'bhc_topic', 'bhc_paged', 'bhc_sort']),
                    'itemListElement' => array_values(array_filter(array_map(function ($course) {
                        return get_post_type($course->ID) === 'bh_course' ? get_permalink($course->ID) : null;
                    }, $query->posts))),
                ],
            ]);
        }

        $uid = get_current_user_id();
        ob_start();

        // Kicker + title only on the designated catalog page; an embedding page has its own <h1>.
        $catalog_page_id = (int) get_option('bhc_catalog_page_id', 0);
        if ($catalog_page_id && get_queried_object_id() === $catalog_page_id && is_main_query()) {
            echo '<header class="bhc-archive-header">'
                . '<span class="bhc-archive-kicker">' . esc_html__('Course Catalog', 'bh-courses') . '</span>'
                . '<h1 class="bhc-archive-title">' . esc_html__('Courses', 'bh-courses') . '</h1>'
                . '<div class="bhc-archive-rule" aria-hidden="true"></div>'
                . '</header>';
        }

        echo '<div class="bhc-catalog-wrap">';
        echo self::render_catalog_filters($search, $category, $topic, $sort);

        if (!$query->have_posts()) {
            // Shared empty-state component, with a different CTA for filtered vs. empty.
            $is_filtered = (bool) ($search || $category || $topic);
            // "Create your first course" CTA only for users who can.
            $zero_args = [
                'reason' => 'zero',
                'title' => 'No courses published yet',
                'description' => 'Check back soon — new courses will show up here as they\'re published.',
            ];
            if (current_user_can('edit_posts')) {
                $zero_args['cta_label'] = 'Create your first course';
                $zero_args['cta_url'] = admin_url('post-new.php?post_type=bh_course');
            }
            echo class_exists('BHY_Style') ? BHY_Style::empty_state_html($is_filtered ? [
                'reason' => 'filtered',
                'title' => 'No courses match your filters',
                'clear_url' => remove_query_arg(['bhc_s', 'bhc_category', 'bhc_topic', 'bhc_paged']),
            ] : $zero_args) : '<p class="bhc-empty">No courses found' . ($is_filtered ? ' matching your filters.' : ' yet.') . '</p>';
        } else {
            echo '<div class="bhc-catalog ous-catalog-grid">';
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

    private static function render_course_card(\WP_Post $course, int $uid): string {
        $locked = !BHC_Gate::user_can_access_course($uid, $course->ID);
        $percent = $uid ? BHC_Progress::course_percent($uid, $course->ID) : 0;
        $difficulty_label = BHC_PostTypes::difficulty_label($course->ID);
        $instructor = BHC_PostTypes::instructor($course->ID);
        $lesson_count = BHC_PostTypes::lesson_count($course->ID);

        ob_start();
        echo '<div class="bhc-course-card ous-catalog-card' . ($locked ? ' bhc-locked' : '') . '">';
        // Every card gets the same fixed-ratio image slot (thumbnail or accent gradient).
        // WHY: titles are also capped at 45 chars server-side, since line-clamp proved
        // unreliable in this overlay and still painted past the clip.
        $course_title = get_the_title($course->ID);
        $title_display = $course_title;
        if (mb_strlen($course_title) > 45) {
            $title_display = mb_substr($course_title, 0, 45);
            $last_space = mb_strrpos($title_display, ' ');
            if ($last_space !== false) $title_display = mb_substr($title_display, 0, $last_space);
            $title_display .= '…';
        }
        $title_html = '<h3 class="bh-clamp-2 bhc-card-thumb-title"><a href="' . esc_url(get_permalink($course->ID)) . '" title="' . esc_attr($course_title) . '">' . esc_html($title_display) . '</a>' . ($locked ? ' <span class="bhc-lock">&#128274;</span>' : '') . '</h3>';

        // "Buy once" price hint on locked cards; checkout itself lives on the course page.
        $buy_once_badge = '';
        if ($locked && class_exists('BHC_Gate') && BHC_Gate::has_purchase_option($course->ID)) {
            // wc_price(), not BHM_Money::price(), which has no currency symbol. Safe to echo.
            $price_display = function_exists('wc_price') ? wc_price(BHC_Gate::purchase_price_cents($course->ID) / 100) : '$' . number_format(BHC_Gate::purchase_price_cents($course->ID) / 100, 2);
            $buy_once_badge = '<span class="bhc-buy-once-badge">Buy once — ' . $price_display . '</span>';
        }

        // Title overlays the thumbnail; the scrim is a gradient for legibility.
        if (has_post_thumbnail($course->ID)) {
            echo '<div class="bhc-card-thumb">' . get_the_post_thumbnail($course->ID, 'medium')
                . '<div class="bhc-card-thumb-scrim" aria-hidden="true"></div>' . $title_html . '</div>';
        } else {
            echo '<div class="bhc-card-thumb bhc-card-thumb-placeholder">'
                . '<div class="bhc-card-thumb-scrim" aria-hidden="true"></div>' . $title_html . '</div>';
        }

        echo '<div class="bhc-card-meta">';
        if ($difficulty_label) echo '<span class="bh-badge bhc-badge bhc-badge-difficulty bhc-difficulty-' . esc_attr(BHC_PostTypes::difficulty($course->ID)) . '">' . esc_html($difficulty_label) . '</span>';
        echo '<span class="bhc-card-lesson-count">' . (int) $lesson_count . ' lesson' . ($lesson_count === 1 ? '' : 's') . '</span>';
        echo $buy_once_badge;
        if (class_exists('BHC_Reviews')) {
            $rating = BHC_Reviews::average_rating($course->ID);
            if ($rating['count'] > 0) {
                echo '<span class="bhc-card-rating">&#9733; ' . esc_html((string) $rating['average']) . ' <span class="bhc-card-rating-count">(' . (int) $rating['count'] . ')</span></span>';
            }
        }
        echo '</div>';

        if ($instructor) echo '<div class="bhc-card-instructor">' . get_avatar($instructor->ID, 20) . ' <span>' . esc_html($instructor->display_name ?: $instructor->user_login) . '</span></div>';

        // rich_excerpt(): bh_course has no excerpt support, and the auto excerpt strips all markup.
        echo '<div class="bhc-excerpt bhc-step-text">' . self::rich_excerpt($course->ID) . '</div>';
        // The spacer's margin-top:auto pins footer content to the card bottom so cards match.
        echo '<div class="bhc-footer-spacer">';
        if ($uid && !$locked) {
            echo '<div class="bhc-progress-bar"><div class="bhc-progress-fill" style="width:' . (int) $percent . '%"></div></div><p class="bhc-progress-label">' . (int) $percent . '% complete</p>';
            echo BHC_Render_Course::render_continue_cta($uid, $course->ID, $percent);
        }
        echo '</div>';
        echo '</div>';
        return ob_get_clean();
    }

    /**
     * Truncated description preview that keeps basic formatting: wp_kses() allowlist, then a
     * DOMDocument walk so truncation lands on text and every open tag gets closed.
     */
    private static function rich_excerpt(int $course_id, int $max_chars = 170): string {
        $raw = get_post_field('post_content', $course_id);
        if ($raw === '' || $raw === false) return '';

        $html = apply_filters('the_content', $raw);
        $allowed = ['p' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'br' => []];
        $html = wp_kses($html, $allowed);
        if (mb_strlen(wp_strip_all_tags($html)) <= $max_chars) return $html;

        // Display nicety: fall back to a plain trim without ext-dom.
        if (!class_exists('DOMDocument')) return wp_trim_words(wp_strip_all_tags($html), 26);

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $root = $doc->getElementsByTagName('div')->item(0);
        if (!$root) return wp_trim_words(wp_strip_all_tags($html), 26);

        $budget = $max_chars;
        $truncated = false;
        $walk = function (\DOMNode $node) use (&$walk, &$budget, &$truncated) {
            foreach (iterator_to_array($node->childNodes) as $child) {
                if ($truncated) { $node->removeChild($child); continue; }
                if ($child instanceof \DOMText) {
                    $len = mb_strlen($child->textContent);
                    if ($len <= $budget) { $budget -= $len; continue; }
                    $cut = mb_substr($child->textContent, 0, max(0, $budget));
                    $last_space = mb_strrpos($cut, ' ');
                    if ($last_space !== false) $cut = mb_substr($cut, 0, $last_space);
                    $child->textContent = rtrim($cut) . '…';
                    $budget = 0;
                    $truncated = true;
                } else {
                    $walk($child);
                    if ($budget <= 0) $truncated = true;
                }
            }
        };
        $walk($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) $out .= $doc->saveHTML($child);
        return $out;
    }

    // Plain GET form (courses.js auto-submits selects). Active filters are kept as hidden fields.
    private static function render_catalog_filters(string $search, string $category, string $topic, string $sort): string {
        $categories = get_terms(['taxonomy' => 'bhc_course_category', 'hide_empty' => true]);
        $topics = get_terms(['taxonomy' => 'bhc_course_topic', 'hide_empty' => true]);

        ob_start();
        echo '<form class="bhc-catalog-filters" method="get">';
        // Preserve non-bhc_ query args already on the URL.
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

    private static function render_pagination(int $page, int $max_pages, string $search, string $category, string $topic, string $sort): string {
        if ($max_pages <= 1) return '';
        $base = remove_query_arg('bhc_paged');
        ob_start();
        echo '<nav class="bhc-pagination">';
        if ($page > 1) {
            echo '<a class="bhc-btn bhc-btn-secondary" href="' . esc_url(add_query_arg('bhc_paged', $page - 1, $base)) . '">&larr; Previous</a>';
        }
        // Windowed page links (current ±2, first/last) once there are more than 5 pages.
        if ($max_pages > 5) {
            echo '<span class="bhc-pagination-numbers">';
            $window = 2;
            $shown_ellipsis_before = false;
            $shown_ellipsis_after = false;
            for ($i = 1; $i <= $max_pages; $i++) {
                $in_window = abs($i - $page) <= $window || $i === 1 || $i === $max_pages;
                if (!$in_window) {
                    if ($i < $page && !$shown_ellipsis_before) {
                        echo '<span class="bhc-pagination-ellipsis">&hellip;</span>';
                        $shown_ellipsis_before = true;
                    } elseif ($i > $page && !$shown_ellipsis_after) {
                        echo '<span class="bhc-pagination-ellipsis">&hellip;</span>';
                        $shown_ellipsis_after = true;
                    }
                    continue;
                }
                if ($i === $page) {
                    echo '<span class="bhc-pagination-number bhc-pagination-current">' . (int) $i . '</span>';
                } else {
                    echo '<a class="bhc-pagination-number" href="' . esc_url(add_query_arg('bhc_paged', $i, $base)) . '">' . (int) $i . '</a>';
                }
            }
            echo '</span>';
        } else {
            echo '<span class="bhc-pagination-status">Page ' . (int) $page . ' of ' . (int) $max_pages . '</span>';
        }
        if ($page < $max_pages) {
            echo '<a class="bhc-btn bhc-btn-secondary" href="' . esc_url(add_query_arg('bhc_paged', $page + 1, $base)) . '">Next &rarr;</a>';
        }
        echo '</nav>';
        return ob_get_clean();
    }
}
