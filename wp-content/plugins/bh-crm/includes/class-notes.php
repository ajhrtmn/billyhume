<?php
if (!defined('ABSPATH')) exit;

/**
 * ROADMAP-ux-polish-and-feature-parity-2026-07.md Section 3: "Notes:
 * timestamped history + authorship + reminders, replacing the current
 * single-overwrite freeform textarea. This is the CRM's actual daily-
 * use loop in every reference product (Pipedrive/HubSpot) and is
 * currently the thinnest part of bh-crm."
 *
 * Was a single `_bhcrm_notes` user meta field, silently overwritten on
 * every save — no history, no author attribution (this plugin can have
 * more than one admin), no way to flag "check back on this." Now a real
 * table (bhcrm_notes), same versioned-dbDelta activation pattern
 * BHCRM_Projects already established for this plugin's own first table
 * (class-projects.php) — append-only note history, each row stamped
 * with who wrote it and when, with an optional reminder date.
 *
 * Reminders reuse this ecosystem's OWN existing infrastructure rather
 * than inventing new cron/notification plumbing: OUS_Jobs::enqueue()
 * (the shared async job queue, Action-Scheduler-backed when available)
 * schedules a one-off job for the reminder's exact moment, and that
 * job's handler calls OUS_Notifications::notify() (the shared, already-
 * built-and-working notification bell every other plugin in this
 * ecosystem already uses) — same "check for an unused/adjacent
 * extension point before building new infrastructure" principle this
 * whole roadmap doc's own cross-cutting findings called out.
 *
 * Any note written before this table existed is migrated forward
 * automatically (once, per person) as a single legacy note rather than
 * silently discarded — see migrate_legacy_meta().
 */
class BHCRM_Notes {
    const DB_VERSION = '1.0';

    public static function init() {
        // Called directly from bh-crm.php's own 'plugins_loaded'
        // bootstrap closure (not re-hooked to 'plugins_loaded' itself)
        // — this method IS already running during that hook's dispatch,
        // same cheap-version-gated-upgrade-check pattern
        // BHCRM_Projects::init() already established in this plugin.
        self::maybe_upgrade();

        if (class_exists('OUS_Jobs')) {
            OUS_Jobs::register('bhcrm_note_reminder', [self::class, 'handle_reminder_job']);
        }
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhcrm_notes';
    }

    public static function activate() {
        if (self::create_or_update_schema()) {
            update_option('bhcrm_notes_db_version', self::DB_VERSION);
        }
    }

    public static function maybe_upgrade() {
        if (version_compare(get_option('bhcrm_notes_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhcrm_notes_db_version', self::DB_VERSION);
        }
    }

    private static function create_or_update_schema() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            person_id bigint(20) unsigned NOT NULL,
            author_id bigint(20) unsigned NOT NULL,
            note longtext NOT NULL,
            reminder_at datetime DEFAULT NULL,
            reminder_dismissed tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY person_id (person_id)
        ) $charset;");

        if ($wpdb->last_error) return false;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    // A person whose old freeform `_bhcrm_notes` meta still has real
    // content, and who has never had a row in the new table, gets that
    // content copied forward as one legacy-labeled note — run lazily,
    // once per person, the first time their note history is actually
    // viewed (no need for a slow one-time site-wide migration pass over
    // every person up front; most CRMs have far more people than anyone
    // will ever open the notes tab for on a given day).
    private static function migrate_legacy_meta($person_id) {
        $legacy = get_user_meta($person_id, '_bhcrm_notes', true);
        if (!$legacy || trim($legacy) === '') return;

        global $wpdb;
        $already = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table() . " WHERE person_id = %d", $person_id));
        if ($already > 0) return; // already migrated, or genuinely new notes already exist

        $wpdb->insert(self::table(), [
            'person_id' => $person_id,
            'author_id' => 0, // unknown — the old field never recorded authorship
            'note' => "(Migrated from the old single-note field)\n\n" . $legacy,
        ], ['%d', '%d', '%s']);

        delete_user_meta($person_id, '_bhcrm_notes');
    }

    public static function list_for_person($person_id) {
        self::migrate_legacy_meta($person_id);
        global $wpdb;
        // id DESC as a tiebreaker — created_at only has 1-second
        // resolution, and a migrated legacy note plus a genuinely new
        // note can land in the same second (confirmed live, not
        // theoretical: exactly this happened while verifying this
        // feature), which makes ORDER BY created_at DESC alone
        // non-deterministic about which one sorts first.
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE person_id = %d ORDER BY created_at DESC, id DESC",
            $person_id
        ), ARRAY_A);
    }

    // $reminder_at: a 'Y-m-d\TH:i' datetime-local string, or ''/null for
    // no reminder. Schedules a real OUS_Jobs job for that exact moment
    // when set — a reminder in the past (or for "right now") still
    // schedules, just with delay_seconds clamped to 0, so it fires on
    // the next cron tick rather than being silently dropped.
    public static function add($person_id, $author_id, $text, $reminder_at = '') {
        global $wpdb;
        $text = sanitize_textarea_field($text);
        if ($text === '') return false;

        $reminder_mysql = null;
        if ($reminder_at) {
            $ts = strtotime($reminder_at);
            if ($ts) $reminder_mysql = date('Y-m-d H:i:s', $ts);
        }

        $inserted = $wpdb->insert(self::table(), [
            'person_id' => $person_id,
            'author_id' => $author_id,
            'note' => $text,
            'reminder_at' => $reminder_mysql,
        ], ['%d', '%d', '%s', $reminder_mysql ? '%s' : null]);

        if ($inserted === false) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('error', 'CRM note DB write failed.', [
                    'person_id' => $person_id, 'author_id' => $author_id, 'db_error' => $wpdb->last_error,
                ], 'BH CRM Notes');
            }
            return false;
        }

        $note_id = $wpdb->insert_id;
        if ($reminder_mysql && class_exists('OUS_Jobs')) {
            $delay = max(0, strtotime($reminder_mysql) - time());
            OUS_Jobs::enqueue('bhcrm_note_reminder', ['note_id' => $note_id], $delay);
        }

        return $note_id;
    }

    // The queued job's handler (registered in init() above) — fires at
    // the reminder's own moment, real Action-Scheduler-driven timing
    // when available (OUS_Jobs falls back to its own cron-polled table
    // otherwise, same degrade-gracefully posture every OUS_Jobs consumer
    // already gets for free). Notifies the note's ORIGINAL AUTHOR, not
    // every admin — "remind me" is personal, not a broadcast.
    public static function handle_reminder_job($args) {
        global $wpdb;
        $note_id = (int) ($args['note_id'] ?? 0);

        // QA fix, caught live: an UPDATE ... WHERE reminder_dismissed = 0
        // is the actual idempotency guard — the old code only ever READ
        // reminder_dismissed and never SET it, so a reminder that fired
        // more than once (confirmed live: it genuinely did, once via a
        // manual test call and once via Action Scheduler's own real
        // background processing of the scheduled job) sent the same
        // notification twice. Marking it dismissed atomically, in the
        // same query that checks it's not already dismissed, closes the
        // gap a plain SELECT-then-UPDATE would still race on if two
        // workers ever picked up the same due job at once.
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET reminder_dismissed = 1 WHERE id = %d AND reminder_dismissed = 0",
            $note_id
        ));
        if (!$claimed) return; // already fired, or the note no longer exists

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $note_id), ARRAY_A);
        if (!$row || !$row['author_id'] || !class_exists('OUS_Notifications')) return;

        $person = get_userdata($row['person_id']);
        $person_name = $person ? ($person->display_name ?: $person->user_login) : ('#' . $row['person_id']);
        $snippet = mb_strimwidth($row['note'], 0, 80, '…');

        OUS_Notifications::notify(
            (int) $row['author_id'],
            'bhcrm_note_reminder',
            'Reminder: ' . $person_name,
            $snippet,
            admin_url('admin.php?page=bh-crm&user_id=' . (int) $row['person_id'])
        );
    }

    public static function render_editor($user_id) {
        $notes = self::list_for_person($user_id);

        echo '<h3>Notes</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bhcrm-note-form">';
        wp_nonce_field('bhcrm_save_note');
        echo '<input type="hidden" name="action" value="bhcrm_save_note">';
        echo '<input type="hidden" name="user_id" value="' . (int) $user_id . '">';
        echo '<textarea name="note" rows="4" placeholder="Add a note…" style="width:100%;max-width:600px;display:block;"></textarea>';
        echo '<p><label>Remind me <input type="datetime-local" name="reminder_at" style="max-width:220px;"></label> ';
        echo '<span class="description">Optional — leave blank for a plain note with no follow-up.</span></p>';
        echo '<p><button class="button button-primary">Add note</button></p>';
        echo '</form>';

        if (!$notes) {
            echo '<p class="description">No notes yet.</p>';
            return;
        }

        echo '<ul class="bhcrm-note-list" style="list-style:none;margin:16px 0 0;padding:0;">';
        foreach ($notes as $n) {
            $author = $n['author_id'] ? get_userdata($n['author_id']) : null;
            $author_name = $author ? ($author->display_name ?: $author->user_login) : 'Unknown';
            $when = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $n['created_at']);
            echo '<li style="border-bottom:1px solid #dcdcde;padding:10px 0;">';
            echo '<div style="font-size:12px;color:#646970;">' . esc_html($author_name) . ' &middot; ' . esc_html($when) . '</div>';
            echo '<div style="white-space:pre-wrap;">' . esc_html($n['note']) . '</div>';
            if ($n['reminder_at']) {
                $due = strtotime($n['reminder_at']) <= time();
                $reminder_when = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $n['reminder_at']);
                $dismissed = (int) $n['reminder_dismissed'] === 1;
                echo '<div style="margin-top:4px;font-size:12px;">'
                   . '<span class="bhy-badge' . ($due && !$dismissed ? ' bhy-badge-warning' : '') . '">'
                   . ($dismissed ? 'Reminder (done): ' : ($due ? 'Reminder due: ' : 'Reminder set for: '))
                   . esc_html($reminder_when) . '</span></div>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }

    public static function handle_save() {
        // QA fix: this required manage_options while the CRM menu
        // itself only requires bhcore_manage_crm (granted to editor and
        // the new Studio Manager role) -- editors/managers could see
        // the note form but every save silently died. Adding a note
        // isn't a destructive action, so it now matches the page's own
        // access level instead of being accidentally admin-only.
        if (!current_user_can('bhcore_manage_crm') || !check_admin_referer('bhcrm_save_note')) wp_die('Not allowed.');

        $user_id = (int) ($_POST['user_id'] ?? 0);
        $note_id = self::add($user_id, get_current_user_id(), wp_unslash($_POST['note'] ?? ''), $_POST['reminder_at'] ?? '');

        // CRM-native event, additive only — doesn't affect the save
        // above in any way, just gives this note a row in the shared
        // activity stream (see class-event-activity.php). The note text
        // itself is never put in the payload — it's freeform admin-only
        // content with no business duplicated into a table other
        // plugins may read.
        if ($user_id && $note_id && class_exists('BH_Event')) {
            BH_Event::emit('bhcrm/note_saved', [
                'user_id' => $user_id,
                'subject_type' => 'user', 'subject_id' => $user_id,
            ]);
        }

        if (class_exists('OUS_Toast')) {
            OUS_Toast::queue($note_id ? 'Note added.' : 'Could not save note — please try again.', $note_id ? 'success' : 'error');
        }

        wp_safe_redirect(add_query_arg(['page' => 'bh-crm', 'user_id' => $user_id, 'bhcrm_msg' => rawurlencode($note_id ? 'Note added.' : 'Could not save note.')], admin_url('admin.php')));
        exit;
    }
}
