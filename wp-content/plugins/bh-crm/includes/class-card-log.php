<?php
if (!defined('ABSPATH')) exit;

/**
 * Per-card log for top-level kanban cards, rendered at the root of BHCRM_Subtasks::render().
 *
 * - Fixes: changes made, at an optional timestamp_seconds (e.g. a spot in a mix), resolvable.
 * - Feedback: notes from others, keyed by free-text author_name (authors often have no account).
 * - Idea Drop attachments: a link to the person's own bh-streaming track (no copy), or a media upload (a copy).
 * All keyed by card_placement_id (core placement id, no FK).
 */
class BHCRM_CardLog {
    const DB_VERSION = '1.1';

    public static function init(): void {
        self::maybe_upgrade();

        add_action('admin_post_bhcrm_card_add_fix', [self::class, 'handle_add_fix']);
        add_action('admin_post_bhcrm_card_toggle_fix', [self::class, 'handle_toggle_fix']);
        add_action('admin_post_bhcrm_card_add_feedback', [self::class, 'handle_add_feedback']);
        add_action('admin_post_bhcrm_card_link_track', [self::class, 'handle_link_track']);
        add_action('admin_post_bhcrm_card_upload_file', [self::class, 'handle_upload_file']);
        add_action('admin_post_bhcrm_card_remove_attachment', [self::class, 'handle_remove_attachment']);
    }

    private static function fixes_table(): string {
        return BHCRM_Tables::project_fixes();
    }

    private static function feedback_table(): string {
        return BHCRM_Tables::project_feedback();
    }

    private static function attachments_table(): string {
        return BHCRM_Tables::project_attachments();
    }

    public static function activate(): void {
        if (self::create_or_update_schema()) {
            update_option('bhcrm_card_log_db_version', self::DB_VERSION);
        }
    }

    public static function maybe_upgrade(): void {
        if (version_compare(get_option('bhcrm_card_log_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bhcrm_card_log_db_version', self::DB_VERSION);
        }
    }

    private static function create_or_update_schema(): bool {
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

        // Exactly one of track_post_id (kind='track_link') or wp_attachment_id (kind='upload') is set; enforced in code.
        $attachments = self::attachments_table();
        dbDelta("CREATE TABLE $attachments (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            card_placement_id bigint(20) unsigned NOT NULL,
            kind varchar(20) NOT NULL,
            track_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            wp_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            added_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY card_placement_id (card_placement_id)
        ) $charset;");

        if ($wpdb->last_error) return false;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $fixes)) === $fixes
            && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $feedback)) === $feedback
            && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $attachments)) === $attachments;
    }

    /* =================================================================
     * Fixes
     * ================================================================= */

    /** @return int|false */
    public static function add_fix(int $card_id, int $timestamp_seconds, string $note) {
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

    public static function toggle_fix_resolved(int $fix_id): bool {
        global $wpdb;
        $fix_id = (int) $fix_id;
        $current = (int) $wpdb->get_var($wpdb->prepare("SELECT resolved FROM " . self::fixes_table() . " WHERE id = %d", $fix_id));
        return (bool) $wpdb->update(self::fixes_table(), ['resolved' => $current ? 0 : 1], ['id' => $fix_id]);
    }

    /** @return array<int, array<string, mixed>> */
    public static function list_fixes(int $card_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::fixes_table() . " WHERE card_placement_id = %d ORDER BY timestamp_seconds ASC, id ASC",
            (int) $card_id
        ), ARRAY_A);
    }

    /* =================================================================
     * Feedback
     * ================================================================= */

    /** @return int|false */
    public static function add_feedback(int $card_id, string $author_name, string $note) {
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

    /** @return array<int, array<string, mixed>> */
    public static function list_feedback(int $card_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::feedback_table() . " WHERE card_placement_id = %d ORDER BY created_at ASC, id ASC",
            (int) $card_id
        ), ARRAY_A);
    }

    /* =================================================================
     * Idea Drop (track links + uploads)
     * ================================================================= */

    /** @return int|false */
    public static function add_track_link(int $card_id, int $track_post_id, int $added_by) {
        global $wpdb;
        $track_post_id = (int) $track_post_id;
        if (!$track_post_id || get_post_type($track_post_id) !== 'bhs_track') return false;
        $ok = $wpdb->insert(self::attachments_table(), [
            'card_placement_id' => (int) $card_id, 'kind' => 'track_link',
            'track_post_id' => $track_post_id, 'added_by' => (int) $added_by,
        ]);
        return $ok ? (int) $wpdb->insert_id : false;
    }

    /** @return int|false */
    public static function add_upload(int $card_id, int $wp_attachment_id, int $added_by) {
        global $wpdb;
        $wp_attachment_id = (int) $wp_attachment_id;
        if (!$wp_attachment_id) return false;
        $ok = $wpdb->insert(self::attachments_table(), [
            'card_placement_id' => (int) $card_id, 'kind' => 'upload',
            'wp_attachment_id' => $wp_attachment_id, 'added_by' => (int) $added_by,
        ]);
        return $ok ? (int) $wpdb->insert_id : false;
    }

    /** @return array<int, array<string, mixed>> */
    public static function list_attachments(int $card_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::attachments_table() . " WHERE card_placement_id = %d ORDER BY created_at ASC, id ASC",
            (int) $card_id
        ), ARRAY_A);
    }

    public static function remove_attachment(int $attachment_id): bool {
        global $wpdb;
        return (bool) $wpdb->delete(self::attachments_table(), ['id' => (int) $attachment_id]);
    }

    // WHY: a bhcore_manage_crm user may lack upload_files; grant it for this one call only, never persisted.
    /** @return int|\WP_Error */
    private static function handle_file_upload(string $field) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        if (empty($_FILES[$field]['name'])) return new WP_Error('no_file', 'No file chosen.');
        if ($_FILES[$field]['size'] > 100 * 1024 * 1024) return new WP_Error('too_big', 'File must be smaller than 100MB.');

        $grant_upload_cap = function ($allcaps) {
            $allcaps['upload_files'] = true;
            return $allcaps;
        };
        add_filter('user_has_cap', $grant_upload_cap);
        $attachment_id = media_handle_upload($field, 0);
        remove_filter('user_has_cap', $grant_upload_cap);

        return $attachment_id;
    }

    /* =================================================================
     * Render — called from BHCRM_Subtasks::render(), root level only
     * ================================================================= */

    private static function format_timestamp(int $seconds): string {
        $seconds = (int) $seconds;
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    public static function render(int $project_id, int $uid, int $card_id): void {
        self::render_attachments($project_id, $uid, $card_id);
        self::render_fixes($project_id, $uid, $card_id);
        self::render_feedback($project_id, $uid, $card_id);
    }

    private static function render_attachments(int $project_id, int $uid, int $card_id): void {
        $attachments = self::list_attachments($card_id);

        echo '<details class="bhcrm-card-log" open><summary style="cursor:pointer;"><strong>Idea Drop</strong>' . ($attachments ? ' (' . count($attachments) . ')' : '') . '</summary>';

        if (!$attachments) {
            echo '<p class="description">No files linked or uploaded yet.</p>';
        } else {
            echo '<ul class="bhcrm-card-log-list" style="list-style:none;margin:0 0 10px;padding:0;">';
            foreach ($attachments as $a) {
                echo '<li style="border-bottom:1px solid #dcdcde;padding:8px 0;">';
                if ($a['kind'] === 'track_link') {
                    $track = get_post((int) $a['track_post_id']);
                    $label = $track ? $track->post_title : ('Track #' . $a['track_post_id'] . ' (deleted)');
                    echo '&#127925; ' . esc_html($label) . ' <span class="description">(linked from your streaming library — no copy made)</span>';
                } else {
                    $url = wp_get_attachment_url((int) $a['wp_attachment_id']);
                    $filename = $url ? basename($url) : ('Attachment #' . $a['wp_attachment_id'] . ' (deleted)');
                    echo '&#128206; ' . ($url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($filename) . '</a>' : esc_html($filename));
                    echo ' <span class="description">(uploaded copy)</span>';
                }
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;margin-left:8px;" onsubmit="return confirm(\'Remove this from the card? This does not delete the underlying track/file itself.\');">';
                wp_nonce_field('bhcrm_card_remove_attachment_' . $a['id']);
                echo '<input type="hidden" name="action" value="bhcrm_card_remove_attachment">';
                echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '">';
                echo '<input type="hidden" name="user_id" value="' . (int) $uid . '">';
                echo '<input type="hidden" name="card_id" value="' . (int) $card_id . '">';
                echo '<input type="hidden" name="attachment_id" value="' . (int) $a['id'] . '">';
                echo '<button class="button button-small">Remove</button>';
                echo '</form>';
                echo '</li>';
            }
            echo '</ul>';
        }

        // Track picker scoped to this person's own imports; needs bh-streaming and a non-zero $uid.
        if (class_exists('BHS_Import') && $uid) {
            $tracks = BHS_Import::imports_for_user($uid);
            if ($tracks) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:10px;">';
                wp_nonce_field('bhcrm_card_link_track_' . $card_id);
                echo '<input type="hidden" name="action" value="bhcrm_card_link_track">';
                echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '">';
                echo '<input type="hidden" name="user_id" value="' . (int) $uid . '">';
                echo '<input type="hidden" name="card_id" value="' . (int) $card_id . '">';
                echo '<select name="track_post_id"><option value="">Link one of their tracks…</option>';
                foreach ($tracks as $t) {
                    echo '<option value="' . (int) $t->ID . '">' . esc_html($t->post_title) . '</option>';
                }
                echo '</select> <button class="button">Link track</button>';
                echo '</form>';
            } else {
                echo '<p class="description">This person has no tracks imported into the streaming library yet to link.</p>';
            }
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
        wp_nonce_field('bhcrm_card_upload_file_' . $card_id);
        echo '<input type="hidden" name="action" value="bhcrm_card_upload_file">';
        echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '">';
        echo '<input type="hidden" name="user_id" value="' . (int) $uid . '">';
        echo '<input type="hidden" name="card_id" value="' . (int) $card_id . '">';
        echo '<label>Or upload a file directly (a real copy, not a link): <input type="file" name="upload_file"></label> ';
        echo '<button class="button">Upload</button>';
        echo '</form>';

        echo '</details>';
    }

    private static function render_fixes(int $project_id, int $uid, int $card_id): void {
        $fixes = self::list_fixes($card_id);

        echo '<details class="bhcrm-card-log" open><summary style="cursor:pointer;"><strong>Fixes</strong>' . ($fixes ? ' (' . count($fixes) . ')' : '') . '</summary>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:10px 0;">';
        wp_nonce_field('bhcrm_card_add_fix_' . $card_id);
        echo '<input type="hidden" name="action" value="bhcrm_card_add_fix">';
        echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '">';
        echo '<input type="hidden" name="user_id" value="' . (int) $uid . '">';
        echo '<input type="hidden" name="card_id" value="' . (int) $card_id . '">';
        echo '<label>Timestamp (mm:ss, optional) <input type="text" name="timestamp" placeholder="1:23" style="width:70px;max-width:100%;"></label> ';
        echo '<input type="text" name="note" placeholder="What did you fix?" style="width:320px;max-width:100%;"> ';
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

    private static function render_feedback(int $project_id, int $uid, int $card_id): void {
        $feedback = self::list_feedback($card_id);

        echo '<details class="bhcrm-card-log"><summary style="cursor:pointer;"><strong>Feedback</strong>' . ($feedback ? ' (' . count($feedback) . ')' : '') . '</summary>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:10px 0;">';
        wp_nonce_field('bhcrm_card_add_feedback_' . $card_id);
        echo '<input type="hidden" name="action" value="bhcrm_card_add_feedback">';
        echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '">';
        echo '<input type="hidden" name="user_id" value="' . (int) $uid . '">';
        echo '<input type="hidden" name="card_id" value="' . (int) $card_id . '">';
        echo '<input type="text" name="author_name" placeholder="From (e.g. a client\'s name)" style="width:180px;max-width:100%;"> ';
        echo '<input type="text" name="note" placeholder="Feedback…" style="width:300px;max-width:100%;"> ';
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
     * admin-post handlers (bhcore_manage_crm)
     * ================================================================= */

    private static function redirect_to_card(int $project_id, int $uid, int $card_id): void {
        wp_safe_redirect(add_query_arg([
            'page' => 'bh-crm', 'user_id' => (int) $uid, 'project_id' => (int) $project_id, 'card_id' => (int) $card_id,
        ], admin_url('admin.php')));
        exit;
    }

    /** @param mixed $raw */
    private static function parse_timestamp($raw): int {
        $raw = trim((string) $raw);
        if ($raw === '') return 0;
        if (preg_match('/^(\d+):(\d{1,2})$/', $raw, $m)) {
            return ((int) $m[1]) * 60 + (int) $m[2];
        }
        return max(0, (int) $raw);
    }

    public static function handle_add_fix(): void {
        if (!current_user_can('bhcore_manage_crm')) wp_die('Not allowed.');
        $card_id = (int) ($_POST['card_id'] ?? 0);
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhcrm_card_add_fix_' . $card_id)) wp_die('Bad nonce.');

        self::add_fix($card_id, self::parse_timestamp($_POST['timestamp'] ?? ''), wp_unslash($_POST['note'] ?? ''));
        self::redirect_to_card((int) ($_POST['project_id'] ?? 0), (int) ($_POST['user_id'] ?? 0), $card_id);
    }

    public static function handle_toggle_fix(): void {
        if (!current_user_can('bhcore_manage_crm')) wp_die('Not allowed.');
        $fix_id = (int) ($_POST['fix_id'] ?? 0);
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhcrm_card_toggle_fix_' . $fix_id)) wp_die('Bad nonce.');

        self::toggle_fix_resolved($fix_id);
        self::redirect_to_card((int) ($_POST['project_id'] ?? 0), (int) ($_POST['user_id'] ?? 0), (int) ($_POST['card_id'] ?? 0));
    }

    public static function handle_add_feedback(): void {
        if (!current_user_can('bhcore_manage_crm')) wp_die('Not allowed.');
        $card_id = (int) ($_POST['card_id'] ?? 0);
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhcrm_card_add_feedback_' . $card_id)) wp_die('Bad nonce.');

        self::add_feedback($card_id, wp_unslash($_POST['author_name'] ?? ''), wp_unslash($_POST['note'] ?? ''));
        self::redirect_to_card((int) ($_POST['project_id'] ?? 0), (int) ($_POST['user_id'] ?? 0), $card_id);
    }

    public static function handle_link_track(): void {
        if (!current_user_can('bhcore_manage_crm')) wp_die('Not allowed.');
        $card_id = (int) ($_POST['card_id'] ?? 0);
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhcrm_card_link_track_' . $card_id)) wp_die('Bad nonce.');

        $track_post_id = (int) ($_POST['track_post_id'] ?? 0);
        if ($track_post_id) self::add_track_link($card_id, $track_post_id, get_current_user_id());
        self::redirect_to_card((int) ($_POST['project_id'] ?? 0), (int) ($_POST['user_id'] ?? 0), $card_id);
    }

    public static function handle_upload_file(): void {
        if (!current_user_can('bhcore_manage_crm')) wp_die('Not allowed.');
        $card_id = (int) ($_POST['card_id'] ?? 0);
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhcrm_card_upload_file_' . $card_id)) wp_die('Bad nonce.');

        $attachment_id = self::handle_file_upload('upload_file');
        if (!is_wp_error($attachment_id) && $attachment_id) {
            self::add_upload($card_id, $attachment_id, get_current_user_id());
        }
        self::redirect_to_card((int) ($_POST['project_id'] ?? 0), (int) ($_POST['user_id'] ?? 0), $card_id);
    }

    public static function handle_remove_attachment(): void {
        if (!current_user_can('bhcore_manage_crm')) wp_die('Not allowed.');
        $attachment_id = (int) ($_POST['attachment_id'] ?? 0);
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhcrm_card_remove_attachment_' . $attachment_id)) wp_die('Bad nonce.');

        self::remove_attachment($attachment_id);
        self::redirect_to_card((int) ($_POST['project_id'] ?? 0), (int) ($_POST['user_id'] ?? 0), (int) ($_POST['card_id'] ?? 0));
    }
}
