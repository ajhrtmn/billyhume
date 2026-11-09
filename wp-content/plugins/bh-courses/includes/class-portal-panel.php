<?php
if (!defined('ABSPATH')) exit;

/**
 * "My Courses" panel for BHI_Portal (`bhi_portal_panels`): every course the user is
 * enrolled in, with completion percent and a link to continue.
 */
class BHC_PortalPanel {
    public static function init(): void {
        add_filter('bhi_portal_panels', [self::class, 'register_panel']);
        add_filter('bhi_user_bar_links', [self::class, 'register_user_bar_link']);
        add_filter('bhi_portal_default_panel', [self::class, 'default_panel_for_students'], 10, 2);
        // Both hooks come from bh-monetization-woo; they never fire when it's inactive.
        add_action('bhm_entitlement_granted', [self::class, 'enroll_on_purchase'], 10, 4);
        add_filter('bhm_post_purchase_redirect', [self::class, 'redirect_after_course_purchase'], 10, 2);
        add_filter('bhm_send_entitlement_notice', [self::class, 'skip_generic_purchase_notice'], 10, 4);
    }

    /** A course purchase already gets the "Enrolled" notice, so the generic one would repeat it. */
    public static function skip_generic_purchase_notice(bool $send, int $user_id, string $type, string $scope): bool {
        return ($type === 'purchase' && $scope === 'course') ? false : $send;
    }

    /** Anyone enrolled in a course opens /account/ on My Courses. */
    public static function default_panel_for_students(string $default, int $user_id): string {
        return $user_id && self::enrolled_course_ids($user_id) ? 'courses' : $default;
    }

    /**
     * WHY: My Courses lists enrollments, which otherwise only start on a first visit, so a
     * buyer would land on an empty tab right after paying.
     */
    public static function enroll_on_purchase(int $user_id, string $type, string $scope, int $object_id): void {
        if ($type !== 'purchase' || $scope !== 'course' || get_post_type($object_id) !== 'bh_course') return;
        if (class_exists('BHC_Progress')) BHC_Progress::enroll_if_needed($user_id, $object_id);
    }

    /** @param array<string, array<int, int>> $purchased post type => object ids */
    public static function redirect_after_course_purchase(string $target, array $purchased): string {
        if ($target !== '' || empty($purchased['bh_course']) || !class_exists('BHI_Portal')) return $target;
        return home_url('/' . BHI_Portal::REWRITE_SLUG . '/courses/');
    }

    // User-bar quick link only when there's a course in progress: the most recently
    // enrolled, incomplete one.
    /**
     * @param array<int, array<string, mixed>> $links
     * @return array<int, array<string, mixed>>
     */
    public static function register_user_bar_link($links): array {
        $user_id = get_current_user_id();
        if (!$user_id || !class_exists('BHC_Progress')) return $links;

        foreach (self::enrolled_course_ids($user_id) as $course_id) {
            $course = get_post($course_id);
            if (!$course || $course->post_status !== 'publish') continue;
            if (BHC_Progress::is_course_completed($user_id, $course_id)) continue;
            // WHY: enrollment outlives tier access, so check access before linking into a paywall.
            if (!class_exists('BHC_Gate') || !BHC_Gate::user_can_access_course($user_id, $course_id)) continue;

            $percent = BHC_Progress::course_percent($user_id, $course_id);
            $next_lesson = BHC_Progress::first_incomplete_lesson($user_id, $course_id);
            $links[] = [
                'label' => 'Continue: ' . $course->post_title,
                'url' => $next_lesson ? get_permalink($next_lesson) : get_permalink($course_id),
                'meta' => $percent . '%',
            ];
            break; // one link only
        }
        return $links;
    }

    /**
     * @param array<int, array<string, mixed>> $panels
     * @return array<int, array<string, mixed>>
     */
    public static function register_panel($panels): array {
        $panels[] = [
            'id' => 'courses',
            'label' => 'My Courses',
            'icon' => 'dashicons-welcome-learn-more',
            'render' => [self::class, 'render'],
            'priority' => 30,
        ];
        return $panels;
    }

    // Achievement badges; nothing rendered until one is earned.
    private static function render_achievements(int $user_id): void {
        if (!class_exists('BHC_Achievements')) return;
        $earned = BHC_Achievements::all_for_user($user_id);
        if (!$earned) return;

        echo '<h3 class="bhi-portal-achievements-heading">Achievements</h3>';
        echo '<div class="bhi-portal-achievements">';
        foreach ($earned as $row) {
            $meta = BHC_Achievements::LABELS[$row['achievement_key']] ?? null;
            if (!$meta) continue;
            echo '<span class="bhi-achievement-badge" title="' . esc_attr($meta['description']) . '">'
               . '<span class="dashicons dashicons-awards"></span> ' . esc_html($meta['label'])
               . '</span>';
        }
        echo '</div>';
    }

    /** @return array<int, int> */
    private static function enrolled_course_ids(int $user_id): array {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT course_id FROM " . BHC_Tables::enrollments() . " WHERE user_id = %d ORDER BY enrolled_at DESC",
            $user_id
        ));
    }

    public static function render(): void {
        $user_id = get_current_user_id();
        echo '<h1>My Courses</h1>';

        if (!class_exists('BHC_Progress')) {
            echo '<p>Course progress is unavailable right now.</p>';
            return;
        }

        self::render_achievements($user_id);

        $course_ids = self::enrolled_course_ids($user_id);
        if (!$course_ids) {
            echo '<div class="bhi-portal-empty">'
               . '<span class="dashicons dashicons-welcome-learn-more"></span>'
               . '<p>You\'re not enrolled in any courses yet.</p>'
               . '<a class="button" href="' . esc_url(home_url('/courses/')) . '">Browse courses &rarr;</a>'
               . '</div>';
            return;
        }

        echo '<div class="bhi-portal-course-list">';
        foreach ($course_ids as $course_id) {
            $course = get_post($course_id);
            if (!$course || $course->post_status !== 'publish') continue;

            $percent = BHC_Progress::course_percent($user_id, $course_id);
            $completed = BHC_Progress::is_course_completed($user_id, $course_id);
            // Enrollment outlives tier access, so show locked framing instead of a dead-end Continue.
            $accessible = !class_exists('BHC_Gate') || BHC_Gate::user_can_access_course($user_id, $course_id);

            echo '<div class="bhi-portal-course-card">';
            // Featured image or placeholder, same as the catalog card.
            if (has_post_thumbnail($course_id)) {
                echo '<div class="bhi-portal-course-thumb">' . get_the_post_thumbnail($course_id, 'medium') . '</div>';
            } else {
                echo '<div class="bhi-portal-course-thumb bhi-portal-course-thumb-placeholder" aria-hidden="true"></div>';
            }
            echo '<h3>' . esc_html($course->post_title) . '</h3>';
            // The % is a designed .bhi-datum figure at the end of the track.
            echo '<div class="bhi-progress-row">';
            echo '<div class="bhi-portal-progress-bar"><div class="bhi-portal-progress-fill" style="width:' . (int) $percent . '%;"></div></div>';
            echo '<span class="bhi-datum bhi-progress-figure"><span class="bhi-datum-value">' . (int) $percent . '%</span>'
                . '<span class="bhi-datum-label">' . ($completed ? '&#10003; complete' : 'done') . '</span></span>';
            echo '</div>';
            if (!$accessible) {
                echo '<p class="bhi-portal-course-locked">&#128274; Access has lapsed — <a href="' . esc_url((string) get_permalink($course_id)) . '">view options</a></p>';
            } else {
                // Same Start / Continue / Review rule as the catalog card.
                $cta = class_exists('BHC_Render_Course') ? BHC_Render_Course::render_continue_cta($user_id, $course_id, (int) $percent) : '';
                echo '<p>' . ($cta !== '' ? $cta : '<a class="bhc-btn bhc-continue-btn" href="' . esc_url((string) get_permalink($course_id)) . '">View course &rarr;</a>') . '</p>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
}
