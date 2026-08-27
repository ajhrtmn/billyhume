<?php
if (!defined('ABSPATH')) exit;

/**
 * This plugin's contribution to BHI_Portal (the-self-hosted-self's `bhi_portal_panels`
 * filter — see class-portal.php over there) — access to a fan's own LMS
 * enrollment. Lists every course the current user is actually
 * enrolled in (bhc_enrollments — see BHC_Progress::enroll_if_needed()),
 * not every published course, with a real completion percent per course
 * and a direct link back into the course itself to continue.
 */
class BHC_PortalPanel {
    public static function init(): void {
        add_filter('bhi_portal_panels', [self::class, 'register_panel']);
        add_filter('bhi_user_bar_links', [self::class, 'register_user_bar_link']);
    }

    // Ecosystem depth-pass Tier 1c — the front-end user bar's own
    // "obvious or gone" rule: only contribute a quick-link when there's
    // an ACTUAL course in progress to continue, never a placeholder
    // "browse courses" link for someone with nothing enrolled (that's
    // what the My Courses portal panel's own empty state already
    // covers). Picks the most recently enrolled course that isn't
    // already complete — the one a student most plausibly wants to
    // pick back up right now.
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
            // Real bug found by spot-checking the student experience live:
            // enrollment is recorded independently of ongoing tier access
            // (a supporter tier can lapse, or this enrollment could predate
            // a course that later became paid), so a course a student was
            // once enrolled in isn't necessarily one they can still open.
            // Without this check, this link sent a student with real
            // recorded progress straight into a paywall the moment they
            // clicked "Continue" from their own account/portal — the exact
            // kind of confusing dead end BHC_Render_Lesson::
            // render_lesson_steps() already avoids by checking access
            // before rendering lesson content.
            if (!class_exists('BHC_Gate') || !BHC_Gate::user_can_access_course($user_id, $course_id)) continue;

            $percent = BHC_Progress::course_percent($user_id, $course_id);
            $next_lesson = BHC_Progress::first_incomplete_lesson($user_id, $course_id);
            $links[] = [
                'label' => 'Continue: ' . $course->post_title,
                'url' => $next_lesson ? get_permalink($next_lesson) : get_permalink($course_id),
                'meta' => $percent . '%',
            ];
            break; // one link, the single most relevant course — not a whole list
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

    // LMS depth-of-magic Phase 3 — real cross-course mastery badges
    // (BHC_Achievements). Obvious-or-gone: a student with none earned yet
    // sees nothing here at all, not a row of greyed-out locked badges —
    // this section only exists once there's something real to show.
    private static function render_achievements(int $user_id): void {
        if (!class_exists('BHC_Achievements')) return;
        $earned = BHC_Achievements::all_for_user($user_id);
        if (!$earned) return;

        // Audit fix (2026-07-25): the badge row had no heading — its
        // meaning lived entirely in a `title` tooltip attribute, invisible
        // on touch devices (no hover). A visible label makes the section
        // legible without depending on tooltip support at all.
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

            // Audit fix (2026-07-25): the line above already calls
            // BHC_Progress::course_percent() with no guard at all — this
            // is a same-plugin class, always loaded, so the
            // class_exists()/method_exists() fallback here was
            // inconsistent with that and dead in practice.
            $percent = BHC_Progress::course_percent($user_id, $course_id);
            $completed = BHC_Progress::is_course_completed($user_id, $course_id);
            // Same real bug fixed in the quick-link above: enrollment and
            // ongoing tier access are tracked independently, so a course
            // this student has real recorded progress in isn't necessarily
            // one they can still open (a lapsed tier, or a course that
            // became paid after they enrolled). Rather than a "Continue"
            // button that dead-ends at a paywall, show the same locked
            // framing the drip/tier gates already use elsewhere.
            $accessible = !class_exists('BHC_Gate') || BHC_Gate::user_can_access_course($user_id, $course_id);

            echo '<div class="bhi-portal-course-card">';
            echo '<h3>' . esc_html($course->post_title) . '</h3>';
            echo '<div class="bhi-portal-progress-bar"><div class="bhi-portal-progress-fill" style="width:' . (int) $percent . '%;"></div></div>';
            echo '<p>' . (int) $percent . '% complete' . ($completed ? ' — <strong>Completed</strong>' : '') . '</p>';
            if (!$accessible) {
                echo '<p class="bhi-portal-course-locked">&#128274; Access has lapsed — <a href="' . esc_url((string) get_permalink($course_id)) . '">view options</a></p>';
            } else {
                // Audit fix (2026-07-25): this used to always link to the
                // course page, unlike the quick-link above (line ~36) which
                // already does the smarter thing — jump straight to the next
                // incomplete lesson. Now consistent.
                $continue_lesson = !$completed ? BHC_Progress::first_incomplete_lesson($user_id, $course_id) : 0;
                $continue_url = $continue_lesson ? get_permalink($continue_lesson) : get_permalink($course_id);
                echo '<p><a class="button" href="' . esc_url($continue_url) . '">' . ($completed ? 'Review' : 'Continue') . '</a></p>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
}
