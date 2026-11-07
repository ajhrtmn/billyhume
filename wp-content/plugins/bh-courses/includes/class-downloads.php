<?php
if (!defined('ABSPATH')) exit;

/**
 * A course's Downloads view (course permalink + ?view=downloads): every Resource step
 * across its lessons, grouped by lesson. Reached only through render_course(), so
 * course access is already checked; drip-locked lessons list their files without links.
 */
class BHC_Downloads {
    /** @var array<int, array<int, array<int, array<string, mixed>>>> per-request cache */
    private static array $cache = [];

    public static function is_requested(): bool {
        return isset($_GET['view']) && $_GET['view'] === 'downloads';
    }

    public static function url(int $course_id): string {
        return add_query_arg('view', 'downloads', (string) get_permalink($course_id));
    }

    /** @return array<int, array<int, array<string, mixed>>> lesson id => resource steps, in course order */
    public static function for_course(int $course_id): array {
        if (isset(self::$cache[$course_id])) return self::$cache[$course_id];
        $out = [];
        foreach (BHC_PostTypes::lesson_order($course_id) as $lesson_id) {
            if (get_post_status($lesson_id) !== 'publish') continue;
            $files = array_values(array_filter(BHC_Steps::get($lesson_id), fn($s) => ($s['type'] ?? '') === 'resource'));
            if ($files) $out[(int) $lesson_id] = $files;
        }
        return self::$cache[$course_id] = $out;
    }

    public static function render_link(int $course_id, string $class = 'bhc-btn bhc-btn-secondary'): string {
        if (!self::for_course($course_id)) return '';
        return '<a class="' . esc_attr($class) . ' bhc-downloads-link" href="' . esc_url(self::url($course_id)) . '">&#8681; Downloads</a>';
    }

    public static function render(int $course_id, int $uid): string {
        ob_start();
        echo '<div class="bhc-downloads">';
        echo '<a class="ous-back-link" href="' . esc_url((string) get_permalink($course_id)) . '"><span aria-hidden="true">&larr;</span> ' . esc_html(get_the_title($course_id)) . '</a>';
        echo '<h1>Downloads</h1>';
        $lessons = self::for_course($course_id);
        if (!$lessons) {
            echo '<p class="bhc-empty">This course has no downloads yet.</p></div>';
            return (string) ob_get_clean();
        }
        echo '<p class="description">Every file from this course, by lesson.</p>';
        foreach ($lessons as $lesson_id => $files) {
            $open = BHC_Gate::lesson_is_open($uid, $lesson_id);
            echo '<section class="bhc-downloads-lesson"><h2>' . esc_html(get_the_title($lesson_id)) . '</h2>';
            if (!$open) {
                echo '<p class="bhc-drip-notice">&#128274; ' . BHC_Gate::drip_notice($uid, $lesson_id) . '</p>'; // already escaped
            }
            echo '<ul>';
            foreach ($files as $file) {
                $url = wp_get_attachment_url((int) $file['attachment_id']);
                $label = $file['label'] !== '' ? $file['label'] : basename(get_attached_file((int) $file['attachment_id']) ?: 'Download');
                echo '<li>';
                echo ($open && $url)
                    ? '<a class="bhc-btn bhc-resource-download" href="' . esc_url($url) . '" download>&#8681; ' . esc_html($label) . '</a>'
                    : '<span>' . esc_html($label) . '</span>';
                if (!empty($file['description'])) echo '<div class="bhc-step-caption">' . wp_kses_post($file['description']) . '</div>';
                echo '</li>';
            }
            echo '</ul></section>';
        }
        echo '</div>';
        return (string) ob_get_clean();
    }
}
