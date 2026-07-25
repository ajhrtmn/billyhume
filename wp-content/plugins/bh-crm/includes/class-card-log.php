<?php
if (!defined('ABSPATH')) exit;

/**
 * PROJECT-TRACKER-TRACKIT-PARITY-PLAN.md Phase B — timestamped fixes +
 * a feedback log, per top-level kanban card. Ported from TrackIt's own
 * two distinct concepts, which this class keeps distinct rather than
 * merging into one generic "log" table, since they mean different
 * things and are read by different people:
 *
 * - A FIX is something the card's owner did — a specific change, at a
 *   specific timestamp_seconds (a literal number, e.g. seconds into a
 *   mix/recording — TrackIt's "mark the exact spot" convention, not
 *   necessarily tied to any audio player this plugin plays back), with
 *   its own resolved/unresolved state. bhcrm_project_fixes.
 * - FEEDBACK is something someone ELSE said about the card —
 *   deliberately keyed by a free-text author_name, not a user_id: real
 *   TrackIt feedback routinely comes from a client, a label contact, a
 *   collaborator with no account on this site at all. bhcrm_project_feedback.
 *
 * Both are keyed by card_placement_id (a bh/sticky-card row in
 * own-ur-shit's bhcore_element_placements table) — no FK, same
 * cross-plugin-table posture BHCRM_Projects' own card_moves table
 * (Phase C) already takes, for the same reason (that table lives in a
 * different plugin with its own engine/charset).
 *
 * Rendered on the card's own detail view (BHCRM_Subtasks::render(),
 * root level only — a sub-task node isn't itself a card and doesn't
 * get its own fixes/feedback), same "add-entry form + plain timestamped
 * list" UI shape BHCRM_Notes::render_editor() already established for
 * this plugin's per-person notes.
 */
class BHCRM_CardLog {
    const DB_VERSION = '1.0';

    public static function init() {
        self::maybe_upgrade();

        add_action('admin_post_bhcrm_card_add_fix', [self::class, 'handle_add_fix']);
        add_action('admin_post_bhcrm_card_toggle_fix', [self::class, 'handle_toggle_fix']);
        add_action('admin_post_bhcrm_card_add_feedback', [self::class, 'handle_add_feedback']);
    }

    private static function fixes_table() {
        global $wpdb;
        return $wpdb->prefix . 'bhcrm_project_fixes';
    }

    private static function feedback_table() {
        global $wpdb;
        return $wpdb->prefix . 'bhcrm_project_feedback';
    }

    public static function activate() {
        if (self::create_or_update_schema()) {
            update_option('bhcrm_card_log_db_version', self::DB_VERSION);
        }
    }

    public static function maybe_upgrade() {
        if (version_compare(get_option('bhcrm_card_log_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhcrm_card_log_db_version', self::DB_VERSION);
        }
    }

    private static function create_or_update_schema() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $fixes = self::fixes_table();
        dbDelta("CREATE TABLE $fixes (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            card_placement_id bigint(20) unsigned NOT NULL,
            timestamp_seconds int(10) unsigned NOT NULL DEFAULT 0,
            note text,
            resolved tinyint(1) unsigned NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY card_placement_id (card_placement_id)
        ) $charset;");

        $feedback = self::feedback_table();
        dbDelta("CREATE TABLE $feedback (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            card_placement_id bigint(20) unsigned NOT NULL,
            author_name varchar(190) NOT NULL DEFAULT '',
            note text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY card_placement_id (card_placement_id)
        ) $charset;");

        if ($wpdb->last_error) return false;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $fixes)) === $fixes
            && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $feedback)) === $feedback;
    }

    /* =================================================================
     * Fixes
     * ================================================================= */

    public static function add_fix($card_id, $timestamp_seconds, $note) {
        global $wpdb;
        $note = sanitize_textarea_field((string) $note);
        if ($note === '') return false;
        $ok = $wpdb->insert(self::fixes_table(), [
            'card_placement_id' => (int) $card_id,
            'timestamp_seconds' => max(0, (int) $timestamp_seconds),
            'note'              => $note,
        ]);
        return $ok ? (int) $wpdb->insert_id : false;
    }

    public static function toggle_fix_resolved($fix_id) {
        global $wpdb;
        $fix_id = (int) $fix_id;
        $current = (int) $wpdb->get_var($wpdb->prepare("SELECT resolved FROM " . self::fixes_table() . " WHERE id = %d", $fix_id));
        return (bool) $wpdb->update(self::fixes_table(), ['resolved' => $current ? 0 : 1], ['id' => $fix_id]);
    }

    public static function list_fixes($card_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::fixes_table() . " WHERE card_placement_id = %d ORDER BY timestamp_seconds ASC, id ASC",
            (int) $card_id
        ), ARRAY_A);
    }

    /* =================================================================
     * Feedback
     * ================================================================= */

    public static function add_feedback($card_id, $author_name, $note) {
        global $wpdb;
        $note = sanitize_textarea_field((string) $note);
        if ($note === '') return false;
        $author_name = sanitize_text_field((string) $author_name);
        if ($author_name === '') $author_name = 'Anonymous';
        $ok = $wpdb->insert(self::feedback_table(), [
            'card_placement_id' => (int) $card_id,
            'author_name'       => $author_name,
            'note'              => $note,
        ]);
        return $ok ? (int) $wpdb->insert_id : false;
    }

    public static function list_feedback($card_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::feedback_table() . " WHERE card_placement_id = %d ORDER BY created_at ASC, id ASC",
            (int) $card_id
        ), ARRAY_A);
    }

    /* =================================================================
     * Render — called from BHCRM_Subtasks::render(), root level only
     * ================================================================= */

    private static function format_timestamp($seconds) {
        $seconds = (int) $seconds;
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    public static function render($project_id, $uid, $card_id) {
        self::render_fixes($project_id, $uid, $card_id);
        self::render_feedback($project_id, $uid, $card_id);
    }

    private static function render_fixes($project_id, $uid, $card_id) {
        $fixes = self::list_fixes($card_id);

        echo '<details class="bhcrm-card-log" open><summary style="cursor:pointer;"><strong>Fixes</strong>' . ($fixes ? ' (' . count($fixes) . ')' : '') . '</summary>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:10px 0;">';
        wp_nonce_field('bhcrm_card_add_fix_' . $card_id);
        echo '<input type="hidden" name="action" value="bhcrm_card_add_fix">';
        echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '">';
        echo '<input type="hidden" name="user_id" value="' . (int) $uid . '">';
        echo '<input type="hidden" name="card_id" value="' . (int) $card_id . '">';
        echo '<label>Timestamp (mm:ss, optional) <input type="text" name="timestamp" placeholder="1:23" style="width:70px;"></label> ';
        echo '<input type="text" name="note" placeholder="What did you fix?" style="width:320px;"> ';
        echo '<button class="button">Add fix</button>';
        echo '</form>';

        if (!$fixes) {
            echo '<p class="description">No fixes logged yet.</p>';
        } else {
            echo '<ul class="bhcrm-card-log-list" style="list-style:none;margin:0;padding:0;">';
            foreach ($fixes as $fix) {
                $resolved = (int) $fix['resolved'] === 1;
                echo '<li style="border-bottom:1px solid #dcdcde;padding:8px 0;' . ($resolved ? 'opacity:0.6;' : '') . '">';
                echo '<span class="bhy-badge">' . esc_html(self::format_timestamp($fix['timestamp_seconds'])) . '</span> ';
                echo '<span style="' . ($resolved ? 'text-decoration:line-through;' : '') . '">' . esc_html($fix['note']) . '</span>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;margin-left:8px;">';
                wp_nonce_field('bhcrm_card_toggle_fix_' . $fix['id']);
                echo '<input type="hidden" name="action" value="bhcrm_card_toggle_fix">';
                echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '">';
                echo '<input type="hidden" name="user_id" value="' . (int) $uid . '">';
                echo '<input type="hidden" name="card_id" value="' . (int) $card_id . '">';
                echo '<input type="hidden" name="fix_id" value="' . (int) $fix['id'] . '">';
                echo '<button class="button button-small">' . ($resolved ? 'Reopen' : 'Mark resolved') . '</button>';
                echo '</form>';
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</details>';
    }

    private static function render_feedback($project_id, $uid, $card_id) {
        $feedback = self::list_feedback($card_id);

        echo '<details class="bhcrm-card-log"><summary style="cursor:pointer;"><strong>Feedback</strong>' . ($feedback ? ' (' . count($feedback) . ')' : '') . '</summary>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:10px 0;">';
        wp_nonce_field('bhcrm_card_add_feedback_' . $card_id);
        echo '<input type="hidden" name="action" value="bhcrm_card_add_feedback">';
        echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '">';
        echo '<input type="hidden" name="user_id" value="' . (int) $uid . '">';
        echo '<input type="hidden" name="card_id" value="' . (int) $card_id . '">';
        echo '<input type="text" name="author_name" placeholder="From (e.g. a client\'s name)" style="width:180px;"> ';
        echo '<input type="text" name="note" placeholder="Feedback…" style="width:300px;"> ';
        echo '<button class="button">Add feedback</button>';
        echo '</form>';

        if (!$feedback) {
            echo '<p class="description">No feedback logged yet.</p>';
        } else {
            echo '<ul class="bhcrm-card-log-list" style="list-style:none;margin:0;padding:0;">';
            foreach ($feedback as $entry) {
                $when = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $entry['created_at']);
                echo '<li style="border-bottom:1px solid #dcdcde;padding:8px 0;">';
                echo '<div style="font-size:12px;color:#646970;">' . esc_html($entry['author_name']) . ' &middot; ' . esc_html($when) . '</div>';
                echo '<div>' . esc_html($entry['note']) . '</div>';
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</details>';
    }

    /* =================================================================
     * admin-post handlers — same bhcore_manage_crm gate every other
     * project-tracker mutation in this plugin already uses (adding a
     * fix/feedback note isn't destructive).
     * ================================================================= */

    private static function redirect_to_card($project_id, $uid, $card_id) {
        wp_safe_redirect(add_query_arg([
            'page' => 'bh-crm', 'user_id' => (int) $uid, 'project_id' => (int) $project_id, 'card_id' => (int) $card_id,
        ], admin_url('admin.php')));
        exit;
    }

    private static function parse_timestamp($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') return 0;
        if (preg_match('/^(\d+):(\d{1,2})$/', $raw, $m)) {
            return ((int) $m[1]) * 60 + (int) $m[2];
        }
        return max(0, (int) $raw);
    }

    public static function handle_add_fix() {
        if (!current_user_can('bhcore_manage_crm')) wp_die('Not allowed.');
        $card_id = (int) ($_POST['card_id'] ?? 0);
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhcrm_card_add_fix_' . $card_id)) wp_die('Bad nonce.');

        self::add_fix($card_id, self::parse_timestamp($_POST['timestamp'] ?? ''), wp_unslash($_POST['note'] ?? ''));
        self::redirect_to_card((int) ($_POST['project_id'] ?? 0), (int) ($_POST['user_id'] ?? 0), $card_id);
    }

    public static function handle_toggle_fix() {
        if (!current_user_can('bhcore_manage_crm')) wp_die('Not allowed.');
        $fix_id = (int) ($_POST['fix_id'] ?? 0);
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhcrm_card_toggle_fix_' . $fix_id)) wp_die('Bad nonce.');

        self::toggle_fix_resolved($fix_id);
        self::redirect_to_card((int) ($_POST['project_id'] ?? 0), (int) ($_POST['user_id'] ?? 0), (int) ($_POST['card_id'] ?? 0));
    }

    public static function handle_add_feedback() {
        if (!current_user_can('bhcore_manage_crm')) wp_die('Not allowed.');
        $card_id = (int) ($_POST['card_id'] ?? 0);
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhcrm_card_add_feedback_' . $card_id)) wp_die('Bad nonce.');

        self::add_feedback($card_id, wp_unslash($_POST['author_name'] ?? ''), wp_unslash($_POST['note'] ?? ''));
        self::redirect_to_card((int) ($_POST['project_id'] ?? 0), (int) ($_POST['user_id'] ?? 0), $card_id);
    }
}
