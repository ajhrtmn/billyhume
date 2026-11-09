<?php
if (!defined('ABSPATH')) exit;

/**
 * Course downloads: files Billy attaches to a course (Course edit screen → Downloads box,
 * each for the whole course or one lesson), plus every lesson Resource step. Students get
 * them on the course's Downloads view (course URL + ?view=downloads) through an access-checked
 * link, and as one zip when the server can build it.
 * WHY: uploads are public URLs, so the checked link is the student-facing one; the raw
 * /wp-content/uploads/ URL still works for anyone who already has it.
 */
class BHC_Downloads {
    const META = '_bhc_downloads';
    const ZIP_LIMIT_BYTES = 400 * 1024 * 1024; // past this, a zip risks timing out on shared hosting

    /** @var array<int, array<int, array<string, mixed>>> per-request cache */
    private static array $cache = [];

    public static function init(): void {
        add_action('template_redirect', [self::class, 'maybe_serve']);
    }

    public static function is_requested(): bool {
        return isset($_GET['view']) && $_GET['view'] === 'downloads';
    }

    public static function url(int $course_id): string {
        return add_query_arg('view', 'downloads', (string) get_permalink($course_id));
    }

    public static function file_url(int $course_id, int $attachment_id): string {
        return add_query_arg(['bhc_file' => $attachment_id, 'course' => $course_id], home_url('/'));
    }

    /* ---------- data ---------- */

    /** @return array<int, array{attachment_id:int, label:string, lesson_id:int}> as Billy ordered them */
    public static function course_files(int $course_id): array {
        $rows = get_post_meta($course_id, self::META, true);
        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * Everything downloadable in the course, grouped: key 0 = whole-course files, then lesson
     * ids in course order. Each file: attachment_id, label, description.
     * @return array<int, array<int, array{attachment_id:int, label:string, description:string}>>
     */
    public static function for_course(int $course_id): array {
        if (isset(self::$cache[$course_id])) return self::$cache[$course_id];
        $groups = [];
        foreach (self::course_files($course_id) as $f) {
            if (!get_post($f['attachment_id'])) continue;
            $groups[(int) $f['lesson_id']][] = ['attachment_id' => (int) $f['attachment_id'], 'label' => (string) $f['label'], 'description' => ''];
        }
        $out = isset($groups[0]) ? [0 => $groups[0]] : [];
        foreach (BHC_PostTypes::lesson_order($course_id) as $lesson_id) {
            if (get_post_status($lesson_id) !== 'publish') continue;
            $files = $groups[$lesson_id] ?? [];
            foreach (BHC_Steps::get($lesson_id) as $s) {
                if (($s['type'] ?? '') === 'resource' && get_post($s['attachment_id'])) {
                    $files[] = ['attachment_id' => (int) $s['attachment_id'], 'label' => (string) $s['label'], 'description' => (string) ($s['description'] ?? '')];
                }
            }
            if ($files) $out[(int) $lesson_id] = $files;
        }
        return self::$cache[$course_id] = $out;
    }

    private static function course_has_file(int $course_id, int $attachment_id): ?int {
        foreach (self::for_course($course_id) as $lesson_id => $files) {
            foreach ($files as $f) if ($f['attachment_id'] === $attachment_id) return (int) $lesson_id;
        }
        return null;
    }

    public static function display_name(array $file): string {
        if ($file['label'] !== '') return $file['label'];
        // Unlabelled: tidy the file's own name ("bass-stem_v2" → "Bass stem v2").
        $raw = get_the_title($file['attachment_id']) ?: pathinfo((string) get_attached_file($file['attachment_id']), PATHINFO_FILENAME);
        $tidy = trim(preg_replace('/[\s_-]+/', ' ', html_entity_decode($raw)));
        return $tidy !== '' ? ucfirst($tidy) : 'Download';
    }

    /** @return array{ext:string, size:string, bytes:int} */
    public static function file_meta(int $attachment_id): array {
        $path = (string) get_attached_file($attachment_id);
        $bytes = ($path && file_exists($path)) ? (int) filesize($path) : 0;
        return ['ext' => strtoupper(pathinfo($path, PATHINFO_EXTENSION)), 'size' => $bytes ? size_format($bytes, $bytes < 1048576 ? 0 : 1) : '', 'bytes' => $bytes];
    }

    /* ---------- serving ---------- */

    // A checked link for one file, or ?bhc_zip=course for all the student can open.
    public static function maybe_serve(): void {
        $file = isset($_GET['bhc_file']) ? (int) $_GET['bhc_file'] : 0;
        $zip = isset($_GET['bhc_zip']) ? (int) $_GET['bhc_zip'] : 0;
        if (!$file && !$zip) return;
        $course_id = $zip ?: (int) ($_GET['course'] ?? 0);
        $uid = get_current_user_id();
        $back = $course_id ? (string) get_permalink($course_id) : home_url('/');
        if (get_post_type($course_id) !== 'bh_course' || !$uid || !BHC_Gate::user_can_access_course($uid, $course_id)) {
            wp_safe_redirect($uid ? $back : wp_login_url(self::url($course_id)));
            exit;
        }
        if ($file) {
            $lesson_id = self::course_has_file($course_id, $file);
            if ($lesson_id === null || ($lesson_id && !BHC_Gate::lesson_is_open($uid, $lesson_id))) wp_die('This file is not available yet.', '', ['response' => 403]);
            $path = (string) get_attached_file($file);
            if (!$path || !file_exists($path)) wp_die('This file is missing. Please let us know through Support.', '', ['response' => 404]);
            self::stream($path, basename($path), (string) (get_post_mime_type($file) ?: 'application/octet-stream'));
        }
        self::serve_zip($course_id, $uid);
    }

    private static function stream(string $path, string $name, string $mime): void {
        while (ob_get_level()) ob_end_clean();
        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        if (function_exists('set_time_limit')) @set_time_limit(0);
        readfile($path);
        exit;
    }

    /** @return array<int, array{path:string, name:string}> files this user can open, with zip-safe names */
    private static function zip_entries(int $course_id, int $uid): array {
        $entries = [];
        $used = [];
        foreach (self::for_course($course_id) as $lesson_id => $files) {
            if ($lesson_id && !BHC_Gate::lesson_is_open($uid, $lesson_id)) continue;
            $folder = $lesson_id ? self::zip_safe(get_the_title($lesson_id)) . '/' : '';
            foreach ($files as $f) {
                $path = (string) get_attached_file($f['attachment_id']);
                if (!$path || !file_exists($path)) continue;
                $name = $folder . basename($path);
                if (isset($used[$name])) continue;
                $used[$name] = true;
                $entries[] = ['path' => $path, 'name' => $name];
            }
        }
        return $entries;
    }

    // Titles arrive entity-encoded (&#038;); decode before making a file name of them.
    private static function zip_safe(string $title): string {
        return sanitize_file_name(html_entity_decode($title, ENT_QUOTES, 'UTF-8')) ?: 'files';
    }

    public static function can_zip(int $course_id, int $uid): bool {
        if (!class_exists('ZipArchive')) return false;
        $entries = self::zip_entries($course_id, $uid);
        if (count($entries) < 2) return false;
        return array_sum(array_map(fn($e) => (int) filesize($e['path']), $entries)) <= self::ZIP_LIMIT_BYTES;
    }

    private static function serve_zip(int $course_id, int $uid): void {
        if (!self::can_zip($course_id, $uid)) wp_die('Please download these files one at a time.', '', ['response' => 400]);
        require_once ABSPATH . 'wp-admin/includes/file.php'; // wp_tempnam() on the front end
        $tmp = wp_tempnam('bhc-downloads');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) wp_die('Could not build the zip. Please download the files one at a time.', '', ['response' => 500]);
        foreach (self::zip_entries($course_id, $uid) as $e) {
            $zip->addFile($e['path'], $e['name']);
            // Audio and video are already compressed; storing is much faster on small hosts.
            if (method_exists($zip, 'setCompressionName')) $zip->setCompressionName($e['name'], ZipArchive::CM_STORE);
        }
        $zip->close();
        register_shutdown_function(fn() => @unlink($tmp));
        self::stream($tmp, self::zip_safe(get_the_title($course_id)) . '-downloads.zip', 'application/zip');
    }

    /* ---------- student view ---------- */

    public static function render_link(int $course_id, string $class = 'bhc-btn bhc-btn-secondary'): string {
        if (!self::for_course($course_id)) return '';
        return '<a class="' . esc_attr($class) . ' bhc-downloads-link" href="' . esc_url(self::url($course_id)) . '">&#8681; Downloads</a>';
    }

    /** One download row, also used by lesson Resource steps so both look the same. */
    public static function render_file(int $course_id, array $file, bool $open): string {
        $meta = self::file_meta($file['attachment_id']);
        $facts = trim($meta['ext'] . ($meta['size'] ? ' · ' . $meta['size'] : ''));
        $name = self::display_name($file);
        $out = '<li class="bhc-file' . ($open ? '' : ' bhc-file-locked') . '">';
        $out .= '<span class="bhc-file-icon" aria-hidden="true">' . esc_html($meta['ext'] ?: 'FILE') . '</span>';
        $out .= '<span class="bhc-file-body"><span class="bhc-file-name">' . esc_html($name) . '</span>';
        if ($facts !== '') $out .= '<span class="bhc-file-facts">' . esc_html($facts) . '</span>';
        if (!empty($file['description'])) $out .= '<span class="bhc-file-desc">' . wp_kses_post($file['description']) . '</span>';
        $out .= '</span>';
        $out .= $open
            ? '<a class="bhc-btn bhc-file-button" href="' . esc_url(self::file_url($course_id, $file['attachment_id'])) . '" aria-label="Download ' . esc_attr($name) . '">Download</a>'
            : '<span class="bhc-file-button bhc-file-button-locked">&#128274; Locked</span>';
        return $out . '</li>';
    }

    public static function render(int $course_id, int $uid): string {
        ob_start();
        echo '<div class="bhc-downloads">';
        echo '<a class="ous-back-link" href="' . esc_url((string) get_permalink($course_id)) . '"><span aria-hidden="true">&larr;</span> ' . esc_html(get_the_title($course_id)) . '</a>';
        echo '<div class="bhc-downloads-head"><h1>Downloads</h1>';
        if ($uid && self::can_zip($course_id, $uid)) {
            echo '<a class="bhc-btn" href="' . esc_url(add_query_arg('bhc_zip', $course_id, home_url('/'))) . '">&#8681; Download all (.zip)</a>';
        }
        echo '</div>';
        $groups = self::for_course($course_id);
        if (!$groups) {
            echo '<p class="bhc-empty">This course has no downloads yet.</p></div>';
            return (string) ob_get_clean();
        }
        foreach ($groups as $lesson_id => $files) {
            $open = !$lesson_id || BHC_Gate::lesson_is_open($uid, $lesson_id);
            echo '<div class="bhc-downloads-group">';
            if ($lesson_id) {
                echo '<h2><a href="' . esc_url((string) get_permalink($lesson_id)) . '">' . esc_html(get_the_title($lesson_id)) . '</a></h2>';
                if (!$open) echo '<p class="bhc-drip-notice">&#128274; ' . BHC_Gate::drip_notice($uid, $lesson_id) . '</p>'; // already escaped
            } else {
                echo '<h2>Course files</h2>';
            }
            echo '<ul class="bhc-file-list">';
            foreach ($files as $f) echo self::render_file($course_id, $f, $open);
            echo '</ul></div>';
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    /* ---------- admin: Course edit → Downloads box ---------- */

    public static function render_admin_box(\WP_Post $post): void {
        $lessons = array_values(array_filter(BHC_PostTypes::lesson_order($post->ID), fn($id) => get_post($id)));
        $options = [['id' => 0, 'title' => 'Whole course']];
        foreach ($lessons as $id) $options[] = ['id' => (int) $id, 'title' => get_the_title($id)];
        echo '<p class="description">Files students can download from this course\'s Downloads page. Pick several at once; give each a friendly name and choose whether it belongs to the whole course or one lesson. Drag to reorder. Resource steps inside lessons are listed there automatically too.</p>';
        echo '<ul class="bhc-dl-rows" id="bhc-dl-rows" data-lessons="' . esc_attr((string) wp_json_encode($options)) . '">';
        foreach (self::course_files($post->ID) as $i => $f) {
            if (!get_post($f['attachment_id'])) continue;
            self::render_admin_row($i, (int) $f['attachment_id'], (string) $f['label'], (int) $f['lesson_id'], $options);
        }
        echo '</ul>';
        echo '<p><button type="button" class="button" id="bhc-dl-add">Add files&hellip;</button> <span class="description" id="bhc-dl-count"></span></p>';
        echo '<input type="hidden" name="bhc_downloads_present" value="1">';
    }

    /** @param array<int, array{id:int, title:string}> $options */
    private static function render_admin_row(int $i, int $attachment_id, string $label, int $lesson_id, array $options): void {
        $meta = self::file_meta($attachment_id);
        $file = basename((string) get_attached_file($attachment_id));
        echo '<li class="bhc-dl-row" data-id="' . (int) $attachment_id . '">';
        echo '<span class="bhc-order-drag-handle dashicons dashicons-menu" aria-hidden="true" title="Drag to reorder"></span>';
        echo '<span class="bhc-dl-file"><strong>' . esc_html($file) . '</strong> <span class="description">' . esc_html(trim($meta['ext'] . ' ' . $meta['size'])) . '</span></span>';
        echo '<input type="hidden" name="bhc_downloads[' . $i . '][id]" value="' . (int) $attachment_id . '">';
        echo '<label class="screen-reader-text" for="bhc-dl-label-' . $i . '">Name shown to students</label>';
        echo '<input type="text" id="bhc-dl-label-' . $i . '" name="bhc_downloads[' . $i . '][label]" value="' . esc_attr($label) . '" placeholder="Name shown to students (optional)">';
        echo '<label class="screen-reader-text" for="bhc-dl-lesson-' . $i . '">Shows with</label>';
        echo '<select id="bhc-dl-lesson-' . $i . '" name="bhc_downloads[' . $i . '][lesson]">';
        foreach ($options as $o) echo '<option value="' . (int) $o['id'] . '"' . selected($lesson_id, $o['id'], false) . '>' . esc_html($o['title']) . '</option>';
        echo '</select>';
        echo '<button type="button" class="button-link bhc-dl-remove" aria-label="Remove ' . esc_attr($file) . '">Remove</button>';
        echo '</li>';
    }

    // Called from BHC_Admin::save_course(), after its nonce and capability checks.
    public static function save_admin(int $course_id): void {
        if (empty($_POST['bhc_downloads_present'])) return; // box not on this form
        $lessons = array_map('intval', BHC_PostTypes::lesson_order($course_id));
        $rows = [];
        $seen = [];
        foreach ((array) ($_POST['bhc_downloads'] ?? []) as $r) {
            $id = (int) ($r['id'] ?? 0);
            if (!$id || isset($seen[$id]) || get_post_type($id) !== 'attachment') continue;
            $seen[$id] = true;
            $lesson = (int) ($r['lesson'] ?? 0);
            $rows[] = [
                'attachment_id' => $id,
                'label' => sanitize_text_field(wp_unslash((string) ($r['label'] ?? ''))),
                'lesson_id' => in_array($lesson, $lessons, true) ? $lesson : 0,
            ];
        }
        $rows ? update_post_meta($course_id, self::META, $rows) : delete_post_meta($course_id, self::META);
    }
}
