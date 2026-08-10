<?php
if (!defined('ABSPATH')) exit;

/**
 * DMCA takedown-notice intake and tracking — admin-entered, not a
 * public form. A real DMCA notice arrives however the site owner's
 * registered agent (see class-dmca.php) receives it — email, in most
 * cases — and this is where they log it and track it through to
 * resolution. Deliberately NOT the report-queue (class-reports.php):
 * a copyright claim needs claimant identity, a sworn attestation, and
 * a counter-notice deadline as real fields, not a generic reason/
 * category pair.
 *
 * Deliberately does NOT generate notice language, determine legal
 * validity, or compute a "real" counter-notice deadline — those need
 * actual legal review (17 U.S.C. §512), not code written without a
 * lawyer's input. This is intake/tracking scaffolding only: log what
 * came in, track status, note a deadline the site owner determines
 * themselves. See the admin notice on the page itself.
 */
class OUS_DMCA_Notices {
    const STATUSES = [
        'received'                => 'Received',
        'action_taken'            => 'Content actioned',
        'counter_notice_received' => 'Counter-notice received',
        'reinstated'              => 'Reinstated',
        'rejected'                => 'Rejected / no action',
    ];

    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_ous_add_dmca_notice', [self::class, 'handle_add']);
        add_action('admin_post_ous_update_dmca_notice', [self::class, 'handle_update']);
    }

    public static function add_menu(): void {
        add_submenu_page('own-ur-shit', 'DMCA Notices', 'DMCA Notices', 'manage_options', 'ous-dmca-notices', [self::class, 'render_admin_page']);
    }

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'bhcore_dmca_notices';
    }

    public static function render_admin_page(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');

        global $wpdb;
        $editing = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

        echo '<div class="wrap"><h1>DMCA Notices</h1>';
        echo '<div class="notice notice-warning"><p><strong>This screen does not determine legal validity or generate notice language.</strong> ';
        echo 'It only logs and tracks notices you\'ve received. Whether a notice is valid, what counts as "expeditious" action, ';
        echo 'and what your actual counter-notice deadline is under 17 U.S.C. §512 should come from your own legal review, not this tool.</p></div>';

        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
        }

        if ($editing) {
            self::render_edit_form($editing);
        } else {
            self::render_add_form();
        }

        $status_filter = sanitize_key($_GET['status'] ?? '');
        $where = $status_filter ? $wpdb->prepare('WHERE status = %s', $status_filter) : '';
        $notices = $wpdb->get_results("SELECT * FROM " . self::table() . " $where ORDER BY created_at DESC LIMIT 200");

        echo '<h2>Notices</h2>';
        echo '<p>';
        $links = ['' => 'All'] + self::STATUSES;
        $parts = [];
        foreach ($links as $key => $label) {
            $url = $key ? add_query_arg(['status' => $key], remove_query_arg('edit')) : remove_query_arg(['status', 'edit']);
            $parts[] = '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo implode(' &middot; ', $parts) . '</p>';

        if (!$notices) { echo '<p>Nothing here.</p></div>'; return; }

        echo '<div class="bhy-table-wrap bhy-table-wrap--tall">';
        echo '<table class="wp-list-table widefat striped"><thead><tr><th>Received</th><th>Claimant</th><th>Target</th><th>Status</th><th>Counter-notice deadline</th><th>Action</th></tr></thead><tbody>';
        foreach ($notices as $n) {
            echo '<tr>';
            echo '<td>' . esc_html($n->created_at) . '</td>';
            echo '<td>' . esc_html($n->claimant_name) . '<br><a href="mailto:' . esc_attr($n->claimant_email) . '">' . esc_html($n->claimant_email) . '</a></td>';
            echo '<td>' . esc_html($n->target_type . ($n->target_id ? ' #' . $n->target_id : '')) . '</td>';
            echo '<td>' . esc_html(self::STATUSES[$n->status] ?? $n->status) . '</td>';
            echo '<td>' . esc_html($n->counter_notice_deadline ?: '—') . '</td>';
            echo '<td><a href="' . esc_url(add_query_arg('edit', $n->id)) . '">View / Update</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function render_add_form(): void {
        echo '<h2>Log a New Notice</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ous_add_dmca_notice">';
        wp_nonce_field('ous_add_dmca_notice', 'ous_dmca_notice_nonce');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="claimant_name">Claimant name</label></th><td><input type="text" id="claimant_name" name="claimant_name" class="regular-text" required></td></tr>';
        echo '<tr><th><label for="claimant_email">Claimant email</label></th><td><input type="email" id="claimant_email" name="claimant_email" class="regular-text" required></td></tr>';
        echo '<tr><th><label for="claimant_address">Claimant address</label></th><td><textarea id="claimant_address" name="claimant_address" rows="2" class="large-text"></textarea></td></tr>';
        echo '<tr><th><label for="target_type">Target type</label></th><td><input type="text" id="target_type" name="target_type" class="regular-text" placeholder="e.g. bhs_track, registry_link"></td></tr>';
        echo '<tr><th><label for="target_id">Target ID</label></th><td><input type="number" id="target_id" name="target_id" class="small-text"></td></tr>';
        echo '<tr><th><label for="target_url">Target URL</label></th><td><input type="url" id="target_url" name="target_url" class="large-text"></td></tr>';
        echo '<tr><th><label for="work_description">Copyrighted work claimed infringed</label></th><td><textarea id="work_description" name="work_description" rows="3" class="large-text"></textarea></td></tr>';
        echo '<tr><th><label for="infringing_description">Description of infringing material</label></th><td><textarea id="infringing_description" name="infringing_description" rows="3" class="large-text"></textarea></td></tr>';
        echo '<tr><th>Sworn statement</th><td><label><input type="checkbox" name="sworn_statement" value="1"> Notice includes the required good-faith belief and accuracy-under-penalty-of-perjury statements</label></td></tr>';
        echo '</tbody></table>';
        echo '<p class="submit"><button type="submit" class="button button-primary">Log Notice</button></p>';
        echo '</form><hr>';
    }

    private static function render_edit_form(int $id): void {
        global $wpdb;
        $n = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $id));
        if (!$n) { echo '<p>Not found.</p>'; return; }

        echo '<h2>Notice #' . (int) $n->id . '</h2>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Claimant</th><td>' . esc_html($n->claimant_name) . ' — <a href="mailto:' . esc_attr($n->claimant_email) . '">' . esc_html($n->claimant_email) . '</a></td></tr>';
        if ($n->claimant_address) echo '<tr><th>Address</th><td>' . nl2br(esc_html($n->claimant_address)) . '</td></tr>';
        echo '<tr><th>Target</th><td>' . esc_html($n->target_type . ($n->target_id ? ' #' . $n->target_id : '')) . ($n->target_url ? ' — <a href="' . esc_url($n->target_url) . '" target="_blank" rel="noopener">' . esc_html($n->target_url) . '</a>' : '') . '</td></tr>';
        if ($n->work_description) echo '<tr><th>Work claimed infringed</th><td>' . nl2br(esc_html($n->work_description)) . '</td></tr>';
        if ($n->infringing_description) echo '<tr><th>Infringing material</th><td>' . nl2br(esc_html($n->infringing_description)) . '</td></tr>';
        echo '<tr><th>Sworn statement included</th><td>' . ($n->sworn_statement ? 'Yes' : 'No') . '</td></tr>';
        echo '</tbody></table>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ous_update_dmca_notice">';
        echo '<input type="hidden" name="id" value="' . (int) $n->id . '">';
        wp_nonce_field('ous_update_dmca_notice_' . $n->id, 'ous_dmca_notice_nonce');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="status">Status</label></th><td><select id="status" name="status">';
        foreach (self::STATUSES as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($n->status, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th><label for="counter_notice_deadline">Counter-notice deadline</label></th><td><input type="date" id="counter_notice_deadline" name="counter_notice_deadline" value="' . esc_attr($n->counter_notice_deadline ? substr($n->counter_notice_deadline, 0, 10) : '') . '"> <span class="description">You determine this — not auto-calculated.</span></td></tr>';
        echo '<tr><th><label for="admin_note">Internal notes</label></th><td><textarea id="admin_note" name="admin_note" rows="4" class="large-text">' . esc_textarea($n->admin_note) . '</textarea></td></tr>';
        echo '</tbody></table>';
        echo '<p class="submit"><button type="submit" class="button button-primary">Update</button> ';
        echo '<a href="' . esc_url(remove_query_arg('edit')) . '" class="button">Back to list</a></p>';
        echo '</form><hr>';
    }

    public static function handle_add(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('ous_add_dmca_notice', 'ous_dmca_notice_nonce')) {
            wp_die('Invalid request.');
        }

        global $wpdb;
        $wpdb->insert(self::table(), [
            'claimant_name' => sanitize_text_field(wp_unslash($_POST['claimant_name'] ?? '')),
            'claimant_email' => sanitize_email(wp_unslash($_POST['claimant_email'] ?? '')),
            'claimant_address' => sanitize_textarea_field(wp_unslash($_POST['claimant_address'] ?? '')),
            'target_type' => sanitize_text_field(wp_unslash($_POST['target_type'] ?? '')),
            'target_id' => (int) ($_POST['target_id'] ?? 0),
            'target_url' => esc_url_raw(wp_unslash($_POST['target_url'] ?? '')),
            'work_description' => sanitize_textarea_field(wp_unslash($_POST['work_description'] ?? '')),
            'infringing_description' => sanitize_textarea_field(wp_unslash($_POST['infringing_description'] ?? '')),
            'sworn_statement' => !empty($_POST['sworn_statement']) ? 1 : 0,
            'status' => 'received',
            'created_by' => get_current_user_id(),
        ]);

        wp_safe_redirect(add_query_arg('updated', '1', admin_url('admin.php?page=ous-dmca-notices')));
        exit;
    }

    public static function handle_update(): void {
        $id = (int) ($_POST['id'] ?? 0);
        if (!current_user_can('manage_options') || !check_admin_referer('ous_update_dmca_notice_' . $id, 'ous_dmca_notice_nonce')) {
            wp_die('Invalid request.');
        }

        global $wpdb;
        $status = sanitize_key($_POST['status'] ?? 'received');
        if (!isset(self::STATUSES[$status])) $status = 'received';

        $deadline_raw = sanitize_text_field(wp_unslash($_POST['counter_notice_deadline'] ?? ''));
        $deadline = $deadline_raw !== '' ? $deadline_raw . ' 00:00:00' : null;

        $data = [
            'status' => $status,
            'counter_notice_deadline' => $deadline,
            'admin_note' => sanitize_textarea_field(wp_unslash($_POST['admin_note'] ?? '')),
        ];
        if (in_array($status, ['reinstated', 'rejected'], true)) {
            $data['resolved_at'] = current_time('mysql');
        }
        if ($status === 'counter_notice_received') {
            $data['counter_notice_received_at'] = current_time('mysql');
        }

        $wpdb->update(self::table(), $data, ['id' => $id]);

        wp_safe_redirect(add_query_arg('updated', '1', admin_url('admin.php?page=ous-dmca-notices&edit=' . $id)));
        exit;
    }
}
