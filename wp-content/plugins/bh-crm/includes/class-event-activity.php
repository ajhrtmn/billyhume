<?php
if (!defined('ABSPATH')) exit;

/**
 * BH_Event's activity-stream consumer for the People/CRM detail page —
 * this plugin's own contribution to the bh_crm_activity_summary filter
 * documented in class-people.php's docblock, just applied from inside
 * this same plugin rather than from an external one, since bh-crm is
 * the natural place to show "everything this person has done across
 * the ecosystem" in one place.
 *
 * Reads directly from {$wpdb->prefix}bhcore_events (own-ur-shit's
 * BH_Event, see own-ur-shit/includes/class-event.php) filtered by
 * `WHERE user_id = %d`. Deliberately does NOT go through
 * BH_Identity::client_ids_for_user() to widen the query to matching
 * client_ids — there's no separate identity/stitching table (see that
 * method's own docblock), so the only client_id values it could ever
 * return are ones already present on rows that already carry this
 * user_id via BH_Event::backfill_user_id()'s one-shot UPDATE. Those
 * rows are already included by the plain user_id filter below, so
 * widening the query would only add redundant OR clauses, not new
 * rows.
 *
 * Read-only — this class emits nothing itself (see class-notes.php and
 * class-tags.php for bh-crm's own emit() call sites).
 */
class BHCRM_Event_Activity {
    // How many of a person's most recent events to show in the
    // detail-page render() table — a person-level activity feed, not a
    // report, so a small fixed window is the right shape; the summary
    // line uses a separate, cheap COUNT(*) rather than counting this
    // capped list.
    const DETAIL_LIMIT = 25;

    public static function init() {
        add_filter('bh_crm_activity_summary', [self::class, 'contribute_summary'], 10, 2);
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhcore_events';
    }

    // Total event count for this user, all types, all time — cheap
    // single COUNT(*) against the indexed `user` key (see
    // class-identity-activator.php's bhcore_events KEY user (user_id)).
    private static function total_count($user_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d",
            $user_id
        ));
    }

    // Most recent DETAIL_LIMIT events for this user, newest first —
    // bounded and prepared, matching every other bounded query in this
    // ecosystem's admin views.
    private static function recent_events($user_id) {
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

    /**
     * Raw event `type` -> human-readable label. Every type any active
     * plugin has actually registered via BH_Event::register_event_type()
     * as of this pass is mapped explicitly; anything unrecognized
     * (future type, or a type registered by a plugin this list doesn't
     * know about) falls back to a readable-enough default derived from
     * the raw string rather than showing nothing.
     */
    public static function type_label($type) {
        $labels = [
            'bhs/play'            => 'Played a track',
            'bhs/skip'            => 'Skipped a track',
            'bh/vote'             => 'Cast a contest vote',
            'bhc/enroll'          => 'Enrolled in a course',
            'bhc/step_completed'  => 'Completed a lesson step',
            'bhc/course_completed'=> 'Completed a course',
            'bhcrm/note_saved'    => 'CRM note updated',
            'bhcrm/tags_saved'    => 'CRM tags updated',
            'bhcrm/link_created'  => 'Linked to a project',
            'bh/submission_created' => 'Submitted a contest entry',
            'bhm/wallet_credit'   => 'Wallet credited',
            'bhm/wallet_debit'    => 'Wallet debited (play)',
            'bhcore/email_sent'   => 'Received an email',
        ];
        if (isset($labels[$type])) return $labels[$type];

        // Fallback: "bhx/some_thing" -> "Some thing" — strips the
        // namespace prefix and turns underscores into spaces, honest
        // about being a generic guess rather than a curated label.
        $bare = strpos($type, '/') !== false ? substr($type, strpos($type, '/') + 1) : $type;
        $bare = str_replace(['_', '-'], ' ', $bare);
        return $bare !== '' ? ucfirst($bare) : $type;
    }

    /* ---------------- bh_crm_activity_summary contribution ---------------- */

    public static function contribute_summary($sections, $user_id) {
        $user_id = (int) $user_id;
        if (!$user_id) return $sections;

        $count = self::total_count($user_id);
        if (!$count) return $sections;

        $sections[] = [
            'plugin'  => 'Event Tracking',
            'summary' => sprintf('%d recorded event%s', $count, $count === 1 ? '' : 's'),
            'render'  => fn() => self::render_detail($user_id),
        ];
        return $sections;
    }

    // Expanded detail table shown under the "Event Tracking" heading on
    // the person's detail page — most recent DETAIL_LIMIT events, type
    // label, subject, and a couple of the more useful payload fields
    // when present (best-effort only; payload shape varies per type,
    // so this doesn't attempt a schema-aware render).
    public static function render_detail($user_id) {
        $rows = self::recent_events($user_id);
        if (!$rows) {
            echo '<p><em>No events recorded.</em></p>';
            return;
        }

        echo '<div class="bhy-table-wrap"><table class="wp-list-table widefat striped"><thead><tr>'
           . '<th>When</th><th>Event</th><th>Subject</th><th>Details</th>'
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
            echo '<p class="description">Showing the ' . (int) self::DETAIL_LIMIT . ' most recent events. Older events aren\'t shown here — see the Event Tracking Debug Tools section (Own Ur Shit) for aggregate counts.</p>';
        }
    }
}
