<?php
if (!defined('ABSPATH')) exit;

/**
 * Private per-lesson notes, optionally pinned to a moment in one of the lesson's videos.
 * Stored as one user-meta list per lesson; only the owner ever reads or writes them.
 * WHY: markup keys on data-* only, because Etch blanks class="" on the_content output.
 */
class BHC_Notes {
    const META_PREFIX = '_bhc_notes_';
    const MAX_PER_LESSON = 200;
    const MAX_LENGTH = 2000;

    public static function init(): void {
        add_action('wp_ajax_bhc_add_note', [self::class, 'ajax_add']);
        add_action('wp_ajax_bhc_delete_note', [self::class, 'ajax_delete']);
    }

    /** @return array<int, array{id:string, step:int, t:int, body:string, at:int}> oldest first */
    public static function get(int $user_id, int $lesson_id): array {
        $notes = get_user_meta($user_id, self::META_PREFIX . $lesson_id, true);
        return is_array($notes) ? array_values($notes) : [];
    }

    /**
     * $step and $t are -1 for a plain note with no video moment.
     * @return array{id:string, step:int, t:int, body:string, at:int}|\WP_Error
     */
    public static function add(int $user_id, int $lesson_id, int $step, int $t, string $body) {
        $body = trim(mb_substr(sanitize_textarea_field($body), 0, self::MAX_LENGTH));
        if ($body === '') return new WP_Error('empty', 'Write something first.');
        $notes = self::get($user_id, $lesson_id);
        if (count($notes) >= self::MAX_PER_LESSON) return new WP_Error('full', 'This lesson has the maximum number of notes. Delete some first.');
        $note = ['id' => wp_generate_password(12, false, false), 'step' => $step, 't' => $step >= 0 ? max(0, $t) : -1, 'body' => $body, 'at' => time()];
        $notes[] = $note;
        update_user_meta($user_id, self::META_PREFIX . $lesson_id, $notes);
        return $note;
    }

    public static function delete(int $user_id, int $lesson_id, string $note_id): bool {
        $notes = self::get($user_id, $lesson_id);
        $kept = array_values(array_filter($notes, fn($n) => $n['id'] !== $note_id));
        if (count($kept) === count($notes)) return false;
        $kept ? update_user_meta($user_id, self::META_PREFIX . $lesson_id, $kept) : delete_user_meta($user_id, self::META_PREFIX . $lesson_id);
        return true;
    }

    /** Removes every note a user has; used by the privacy eraser. */
    public static function erase_all(int $user_id): int {
        global $wpdb;
        $removed = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
            $user_id, $wpdb->esc_like(self::META_PREFIX) . '%'
        ));
        wp_cache_delete($user_id, 'user_meta'); // the raw DELETE bypasses the meta cache
        return $removed;
    }

    private static function guard(): array {
        check_ajax_referer('bhc_progress', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error(['message' => 'Log in required.'], 401);
        $lesson_id = (int) ($_POST['lesson_id'] ?? 0);
        if (get_post_type($lesson_id) !== 'bh_lesson' || !BHC_Gate::user_can_access_lesson($user_id, $lesson_id)) {
            wp_send_json_error(['message' => 'Access required.'], 403);
        }
        return [$user_id, $lesson_id];
    }

    public static function ajax_add(): void {
        [$user_id, $lesson_id] = self::guard();
        $step = (int) ($_POST['step_index'] ?? -1);
        // A moment only counts against a real video step of this lesson.
        if ($step >= 0) {
            $s = BHC_Steps::get_step($lesson_id, $step);
            if (!$s || $s['type'] !== 'video') $step = -1;
        }
        $note = self::add($user_id, $lesson_id, $step, (int) ($_POST['t'] ?? -1), wp_unslash((string) ($_POST['body'] ?? '')));
        if (is_wp_error($note)) wp_send_json_error(['message' => $note->get_error_message()], 400);
        wp_send_json_success(['note' => $note]);
    }

    public static function ajax_delete(): void {
        [$user_id, $lesson_id] = self::guard();
        $deleted = self::delete($user_id, $lesson_id, preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['note_id'] ?? '')));
        $deleted ? wp_send_json_success() : wp_send_json_error(['message' => 'Note not found.'], 404);
    }

    public static function format_time(int $t): string {
        $h = intdiv($t, 3600);
        $m = intdiv($t % 3600, 60);
        $s = $t % 60;
        return $h ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    /** @param array{id:string, step:int, t:int, body:string, at:int} $note */
    public static function render_note(array $note): string {
        $moment = $note['step'] >= 0
            ? '<button type="button" data-bhc-note-seek data-step="' . (int) $note['step'] . '" data-t="' . (int) $note['t'] . '">' . esc_html(self::format_time((int) $note['t'])) . '</button>'
            : '';
        return '<li data-bhc-note="' . esc_attr($note['id']) . '">' . $moment
            . '<p>' . nl2br(esc_html($note['body'])) . '</p>'
            . '<button type="button" data-bhc-note-delete aria-label="Delete note">&times;</button></li>';
    }

    public static function render(int $user_id, int $lesson_id, bool $has_video): string {
        if (!$user_id) return '';
        $notes = self::get($user_id, $lesson_id);
        ob_start();
        echo '<div data-bhc-notes data-lesson-id="' . (int) $lesson_id . '">';
        echo '<h2>My notes</h2>';
        echo '<p data-bhc-notes-hint>Only you can see these.' . ($has_video ? ' Bookmarked notes jump back to that moment in the video.' : '') . '</p>';
        echo '<form data-bhc-note-form>';
        echo '<label for="bhc-note-body-' . (int) $lesson_id . '" class="screen-reader-text">New note</label>';
        echo '<textarea id="bhc-note-body-' . (int) $lesson_id . '" name="body" rows="3" maxlength="' . self::MAX_LENGTH . '" placeholder="Write a note…" required></textarea>';
        echo '<div data-bhc-note-actions>';
        if ($has_video) echo '<label><input type="checkbox" name="stamp" checked> Bookmark the current video time</label>';
        echo '<button type="submit" class="bhc-btn bhc-btn-secondary">Save note</button>';
        echo '</div><p data-bhc-note-error role="alert" hidden></p></form>';
        echo '<ol data-bhc-note-list>';
        foreach ($notes as $note) echo self::render_note($note);
        echo '</ol>';
        echo '</div>';
        return (string) ob_get_clean();
    }
}
