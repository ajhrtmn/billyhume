<?php
if (!defined('ABSPATH')) exit;

/**
 * Per-person event history (bhcore_events) as a bh_crm_activity_summary section. Read-only.
 * Filters by user_id alone; client_id widening would only repeat rows already backfilled with this user_id.
 */
class BHCRM_Event_Activity {
    // Rows shown in the detail table; the summary uses a separate COUNT(*).
    const DETAIL_LIMIT = 25;

    public static function init(): void {
        add_filter('bh_crm_activity_summary', [self::class, 'contribute_summary'], 10, 2);
    }

    private static function table(): string {
        return OUS_Tables::events();
    }

    // Total event count for this user (indexed on user_id).
    private static function total_count(int $user_id): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d",
            $user_id
        ));
    }

    // Most recent DETAIL_LIMIT events, newest first.
    /** @return array<int, array<string, mixed>> */
    private static function recent_events(int $user_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT type, subject_type, subject_id, payload, occurred_at
             FROM " . self::table() . "
             WHERE user_id = %d
             ORDER BY occurred_at DESC
             LIMIT %d",
            $user_id, self::DETAIL_LIMIT
        ), ARRAY_A);
    }

    /** Event type -> label; unknown types fall back to a label derived from the raw string. */
    public static function type_label(string $type): string {
        $labels = [
            'bhs/play'            => BHCRM_Words::get('admin.event_activity.played_track'),
            'bhs/skip'            => BHCRM_Words::get('admin.event_activity.skipped_track'),
            'bh/vote'             => BHCRM_Words::get('admin.event_activity.cast_contest_vote'),
            'bhc/enroll'          => BHCRM_Words::get('admin.event_activity.enrolled_course'),
            'bhc/step_completed'  => BHCRM_Words::get('admin.event_activity.completed_lesson_step'),
            'bhc/course_completed'=> BHCRM_Words::get('admin.event_activity.completed_course'),
            'bhcrm/note_saved'    => BHCRM_Words::get('admin.event_activity.crm_note_updated'),
            'bhcrm/tags_saved'    => BHCRM_Words::get('admin.event_activity.crm_tags_updated'),
            'bhcrm/link_created'  => BHCRM_Words::get('admin.event_activity.linked_project'),
            'bh/submission_created' => BHCRM_Words::get('admin.event_activity.submitted_contest_entry'),
            'bhm/wallet_credit'   => BHCRM_Words::get('admin.event_activity.wallet_credited'),
            'bhm/wallet_debit'    => BHCRM_Words::get('admin.event_activity.wallet_debited_play'),
            'bhcore/email_sent'   => BHCRM_Words::get('admin.event_activity.received_email'),
        ];
        if (isset($labels[$type])) return $labels[$type];

        // "bhx/some_thing" -> "Some thing".
        $bare = strpos($type, '/') !== false ? substr($type, strpos($type, '/') + 1) : $type;
        $bare = str_replace(['_', '-'], ' ', $bare);
        return $bare !== '' ? ucfirst($bare) : $type;
    }

    /* ---------------- bh_crm_activity_summary contribution ---------------- */

    /**
     * @param array<int, array<string, mixed>> $sections
     * @param mixed $user_id
     * @return array<int, array<string, mixed>>
     */
    public static function contribute_summary($sections, $user_id): array {
        $user_id = (int) $user_id;
        if (!$user_id) return $sections;

        $count = self::total_count($user_id);
        if (!$count) return $sections;

        $sections[] = [
            'plugin'  => BHCRM_Words::get('admin.event_activity.event_tracking'),
            'summary' => sprintf(BHCRM_Words::get('admin.event_activity.d_recorded_event_s'), $count, $count === 1 ? '' : 's'),
            'render'  => fn() => self::render_detail($user_id),
        ];
        return $sections;
    }

    // Detail table: recent events with type, subject and a few best-effort payload fields.
    public static function render_detail(int $user_id): void {
        $rows = self::recent_events($user_id);
        if (!$rows) {
            echo ('<p><em>' . BHCRM_Words::get('admin.event_activity.no_events_recorded') . '</em></p>');
            return;
        }

        // Wallet events are also rolled up by bh-monetization-woo; note that so they don't read as duplicates.
        $has_wallet_rows = false;
        foreach ($rows as $r) { if (strpos($r['type'], 'bhm/wallet_') === 0) { $has_wallet_rows = true; break; } }
        if ($has_wallet_rows && class_exists('BHM_Wallet')) {
            echo ('<p class="description">' . BHCRM_Words::get('admin.event_activity.wallet_events_below_are_also') . '</p>');
        }

        echo '<div class="bhy-table-wrap"><table class="wp-list-table widefat striped"><thead><tr>'
           . ('<th>' . BHCRM_Words::get('admin.event_activity.when') . '</th><th>' . BHCRM_Words::get('admin.event_activity.event') . '</th><th>' . BHCRM_Words::get('admin.event_activity.subject') . '</th><th>' . BHCRM_Words::get('admin.event_activity.details') . '</th>')
           . '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $payload = json_decode($r['payload'] ?? '', true);
            $payload = is_array($payload) ? $payload : [];
            $details = [];
            foreach ($payload as $k => $v) {
                if (is_scalar($v)) $details[] = $k . ': ' . (string) $v;
            }
            $subject = $r['subject_type'] !== '' ? esc_html($r['subject_type']) . ' #' . (int) $r['subject_id'] : '—';

            echo '<tr>';
            echo '<td>' . esc_html(mysql2date('M j, Y g:ia', $r['occurred_at'])) . '</td>';
            echo '<td>' . esc_html(self::type_label($r['type'])) . '</td>';
            echo '<td>' . $subject . '</td>';
            echo '<td>' . ($details ? esc_html(implode(', ', $details)) : '—') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        if (self::total_count($user_id) > self::DETAIL_LIMIT) {
            echo '<p class="description">Showing the ' . (int) self::DETAIL_LIMIT . (' ' . BHCRM_Words::get('admin.event_activity.most_recent_events_older_events') . '</p>');
        }
    }
}
