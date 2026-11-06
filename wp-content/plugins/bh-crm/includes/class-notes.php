<?php
if (!defined('ABSPATH')) exit;

/**
 * Append-only per-person notes with author, timestamp and an optional reminder.
 * Reminders are OUS_Jobs one-off jobs that notify the note's author via OUS_Notifications.
 */
class BHCRM_Notes {
    const DB_VERSION = '1.0';

    public static function init(): void {
        self::maybe_upgrade();

        if (class_exists('OUS_Jobs')) {
            OUS_Jobs::register('bhcrm_note_reminder', [self::class, 'handle_reminder_job']);
        }
    }

    private static function table(): string {
        return BHCRM_Tables::notes();
    }

    public static function activate(): void {
        if (self::create_or_update_schema()) {
            update_option('bhcrm_notes_db_version', self::DB_VERSION);
        }
    }

    public static function maybe_upgrade(): void {
        if (version_compare(get_option('bhcrm_notes_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhcrm_notes_db_version', self::DB_VERSION);
        }
    }

    private static function create_or_update_schema(): bool {
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

    // Lazily moves the legacy `_bhcrm_notes` user meta into one note, the first time a person's notes are read.
    private static function migrate_legacy_meta(int $person_id): void {
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

    /** @return array<int, array<string, mixed>> */
    public static function list_for_person(int $person_id): array {
        self::migrate_legacy_meta($person_id);
        global $wpdb;
        // WHY: id DESC tiebreaker; created_at has 1-second resolution.
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE person_id = %d ORDER BY created_at DESC, id DESC",
            $person_id
        ), ARRAY_A);
    }

    // $reminder_at: a datetime-local string or ''. Past reminders still schedule, with delay clamped to 0.
    /** @return int|false */
    public static function add(int $person_id, int $author_id, string $text, string $reminder_at = '') {
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

    // Reminder job handler: notifies the note's author only.
    /** @param array<string, mixed> $args */
    public static function handle_reminder_job(array $args): void {
        global $wpdb;
        $note_id = (int) ($args['note_id'] ?? 0);

        // WHY: atomic claim (UPDATE ... WHERE reminder_dismissed = 0) so a job run twice notifies once.
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

    public static function render_editor(int $user_id): void {
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

    public static function handle_save(): void {
        // Matches the CRM page's own bhcore_manage_crm gate; adding a note isn't destructive.
        if (!current_user_can('bhcore_manage_crm') || !check_admin_referer('bhcrm_save_note')) wp_die('Not allowed.');

        $user_id = (int) ($_POST['user_id'] ?? 0);
        $note_id = self::add($user_id, get_current_user_id(), wp_unslash($_POST['note'] ?? ''), $_POST['reminder_at'] ?? '');

        // WHY: the note text never goes in the event payload; other plugins can read the events table.
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
