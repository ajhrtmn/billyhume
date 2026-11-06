<?php
if (!defined('ABSPATH')) exit;

/**
 * Instructor availability + booking: an instructor publishes open slots, a student books
 * one. FullCalendar (vendored) renders them, but the data model doesn't depend on it.
 * Single-instructor for now (see default_instructor_id()). Booking is an atomic conditional
 * UPDATE: the WHERE on the expected prior state is the concurrency guard.
 */
class BHC_Sessions {
    const STATUSES = ['open' => 'Open', 'booked' => 'Booked', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

    // Students can't self-cancel within this many hours of the start. Filterable.
    public static function cancel_cutoff_hours(): int {
        return (int) apply_filters('bhc_session_cancel_cutoff_hours', 24);
    }

    private static function table(): string {
        return BHC_Tables::sessions();
    }

    const DB_VERSION = '1.0';

    public static function activate(): void {
        if (self::create_or_update_schema()) update_option('bhc_sessions_db_version', self::DB_VERSION);
    }

    // Runs on load, since a file-replace deploy never fires the activation hook.
    public static function maybe_upgrade(): void {
        if (version_compare(get_option('bhc_sessions_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) update_option('bhc_sessions_db_version', self::DB_VERSION);
    }

    private static function create_or_update_schema(): bool {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta('CREATE TABLE ' . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            instructor_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NOT NULL DEFAULT 0,
            course_id bigint(20) unsigned NOT NULL DEFAULT 0,
            starts_at datetime NOT NULL,
            duration_minutes smallint(5) unsigned NOT NULL DEFAULT 30,
            status varchar(20) NOT NULL DEFAULT 'open',
            notes text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY instructor_lookup (instructor_id, starts_at),
            KEY student_lookup (student_id),
            KEY status_lookup (status)
        ) $charset;");

        if ($wpdb->last_error) return false;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::table())) === self::table();
    }

    /** Single-instructor: the first account holding bhcore_manage_students. */
    public static function default_instructor_id(): int {
        $users = get_users(['role__in' => ['administrator'], 'number' => 1, 'fields' => 'ID']);
        $cap_users = get_users(['capability' => 'bhcore_manage_students', 'number' => 1, 'fields' => 'ID']);
        return $cap_users ? (int) $cap_users[0] : ((int) ($users[0] ?? 0));
    }

    /** @return int|\WP_Error */
    public static function create_slot(string $starts_at, int $duration_minutes = 30, int $course_id = 0, string $notes = '', int $instructor_id = 0) {
        global $wpdb;
        $instructor_id = (int) $instructor_id ?: self::default_instructor_id();
        if (!$instructor_id) return new WP_Error('no_instructor', 'No instructor account found.');

        $wpdb->insert(self::table(), [
            'instructor_id' => $instructor_id,
            'course_id' => (int) $course_id,
            'starts_at' => sanitize_text_field($starts_at),
            'duration_minutes' => max(5, (int) $duration_minutes),
            'status' => 'open',
            'notes' => sanitize_textarea_field($notes),
            'created_at' => current_time('mysql'),
        ]);
        return $wpdb->insert_id ? (int) $wpdb->insert_id : new WP_Error('db_error', 'Could not create the slot.');
    }

    /** @return array<string, mixed>|null */
    public static function get(int $id): ?array {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id), ARRAY_A);
    }

    /** @return array<int, array<string, mixed>> */
    public static function open_slots(): array {
        global $wpdb;
        return $wpdb->get_results(
            'SELECT * FROM ' . self::table() . " WHERE status = 'open' AND starts_at >= NOW() ORDER BY starts_at ASC", ARRAY_A
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function for_instructor(int $instructor_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE instructor_id = %d ORDER BY starts_at DESC', (int) $instructor_id
        ), ARRAY_A);
    }

    /** @return array<int, array<string, mixed>> */
    public static function for_student(int $student_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE student_id = %d ORDER BY starts_at DESC', (int) $student_id
        ), ARRAY_A);
    }

    /**
     * Flips open -> booked only if still open; rows_affected === 1 is the only source of truth.
     */
    /** @return bool */
    public static function claim(int $slot_id, int $student_id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status = 'booked', student_id = %d WHERE id = %d AND status = 'open' AND starts_at >= NOW()",
            (int) $student_id, (int) $slot_id
        ));
        if ($wpdb->rows_affected !== 1) return false;

        $slot = self::get($slot_id);
        if (class_exists('BH_Event')) {
            BH_Event::emit('bhc/session_booked', [
                'user_id' => (int) $student_id, 'subject_type' => 'bhc_session', 'subject_id' => (int) $slot_id,
                'payload' => ['starts_at' => $slot['starts_at'] ?? '', 'instructor_id' => (int) ($slot['instructor_id'] ?? 0)],
            ]);
        }
        if (class_exists('OUS_Notifications') && $slot) {
            OUS_Notifications::notify(
                (int) $student_id, 'session_booked', 'Session booked',
                'Your session is confirmed for ' . $slot['starts_at'] . '.',
                home_url('/account/?panel=sessions'), 'BH Courses'
            );
            OUS_Notifications::notify(
                (int) $slot['instructor_id'], 'session_booked', 'A student booked a session',
                'A session on ' . $slot['starts_at'] . ' was just booked.',
                admin_url('admin.php?page=bhc-sessions'), 'BH Courses'
            );
        }
        return true;
    }

    /**
     * Student self-cancel: only the booking student, in the same atomic statement, and only
     * outside the cutoff. Staff cancellation (BHC_SessionsAdmin) is unrestricted.
     */
    /** @return bool|\WP_Error */
    public static function self_cancel(int $slot_id, int $student_id) {
        global $wpdb;
        $slot = self::get($slot_id);
        if (!$slot || (int) $slot['student_id'] !== (int) $student_id || $slot['status'] !== 'booked') return false;

        $hours_until_start = (strtotime($slot['starts_at']) - time()) / HOUR_IN_SECONDS;
        if ($hours_until_start < self::cancel_cutoff_hours()) {
            return new WP_Error('too_late', 'This session starts within ' . self::cancel_cutoff_hours() . ' hours and can no longer be self-cancelled — contact your instructor.');
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status = 'open', student_id = 0 WHERE id = %d AND student_id = %d AND status = 'booked'",
            (int) $slot_id, (int) $student_id
        ));
        if ($wpdb->rows_affected !== 1) return false;

        if (class_exists('OUS_Notifications')) {
            OUS_Notifications::notify(
                (int) $slot['instructor_id'], 'session_cancelled', 'A session was cancelled',
                'The session on ' . $slot['starts_at'] . ' was cancelled by the student and is open again.',
                admin_url('admin.php?page=bhc-sessions'), 'BH Courses'
            );
        }
        return true;
    }

    public static function set_status(int $slot_id, string $status): bool {
        global $wpdb;
        if (!isset(self::STATUSES[$status])) return false;
        return $wpdb->update(self::table(), ['status' => $status], ['id' => (int) $slot_id]) !== false;
    }

    /**
     * Feed for a FullCalendar month view: {id, title, start} events.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function as_calendar_events($rows): array {
        $events = [];
        foreach ($rows as $r) {
            $label = $r['status'] === 'open' ? 'Open' : ($r['status'] === 'booked' ? 'Booked' : ucfirst($r['status']));
            $events[] = [
                'id' => (int) $r['id'],
                'title' => $label,
                'start' => str_replace(' ', 'T', $r['starts_at']),
                'color' => $r['status'] === 'open' ? '#2271b1' : ($r['status'] === 'booked' ? '#d63638' : '#8c8f94'),
            ];
        }
        return $events;
    }
}
