<?php
if (!defined('ABSPATH')) exit;

/**
 * A shared reports/moderation queue any plugin's content can plug a
 * "Report" link into — abuse on a public profile, a registry link
 * someone claims isn't rightfully there, a track someone disputes
 * ownership of. This deliberately does NOT try to auto-action anything
 * (no auto-hide, no auto-ban) — it collects a real, timestamped report
 * into one admin-reviewable queue and lets a human decide, same
 * reasoning as bh-monetization-woo's refund-pattern flag: a false
 * positive here (wrongly hiding someone's real content) is worse than
 * a report sitting in a queue for a few hours.
 *
 * Any plugin wires in with one line, no dependency on this class beyond
 * a function call that's a no-op if own-ur-shit isn't active:
 *
 *     echo BHI_Reports::report_button_html('registry_link', $link_id);
 *
 * ...and, if it wants to add its OWN "what does this report mean, what
 * should an admin do about it" context to the admin queue list:
 *
 *     add_filter('bhi_report_target_label', function ($label, $type, $id) {
 *         if ($type !== 'registry_link') return $label;
 *         return 'Registry link #' . $id . ' — ' . esc_html(get_the_title($id));
 *     }, 10, 3);
 */
class BHI_Reports {
    const CATEGORIES = [
        'abuse'      => 'Harassment / abusive content',
        'ownership'  => "This isn't rightfully theirs (ownership dispute / takedown)",
        'spam'       => 'Spam or scam',
        'other'      => 'Something else',
    ];
    const RATE_LIMIT = 5;   // max reports per user per hour — a real moderation signal, not a way to bury someone in noise
    const RATE_WINDOW = HOUR_IN_SECONDS;

    public static function init() {
        add_action('admin_post_bhi_submit_report', [self::class, 'handle_submit']);
        add_action('admin_menu', [self::class, 'add_admin_page']);
    }

    // A REST twin of handle_submit() for any plugin whose front end is
    // JS-rendered rather than a server-rendered page (bh-registry's
    // browse grid is entirely built client-side from API data, so
    // there's no server-rendered card to embed the admin-post <form>
    // into). Same rate limit, same validation, same table — just a
    // different transport for the same action.
    public static function register_routes() {
        register_rest_route('bhi/v1', '/reports', [
            'methods' => 'POST', 'callback' => [self::class, 'rest_submit'], 'permission_callback' => 'is_user_logged_in',
        ]);
    }

    public static function rest_submit($req) {
        $target_type = sanitize_key((string) $req->get_param('target_type'));
        $target_id = (int) $req->get_param('target_id');
        if (!$target_type || !$target_id) return new WP_Error('invalid', 'Missing target.', ['status' => 400]);

        $uid = get_current_user_id();
        $rl_key = 'bhi_report_rl_' . $uid;
        if ((int) get_transient($rl_key) >= self::RATE_LIMIT) {
            return new WP_Error('rate_limited', 'Too many reports — please wait before submitting another.', ['status' => 429]);
        }
        set_transient($rl_key, (int) get_transient($rl_key) + 1, self::RATE_WINDOW);

        $category = array_key_exists($req->get_param('category'), self::CATEGORIES) ? $req->get_param('category') : 'other';
        $reason = sanitize_textarea_field((string) $req->get_param('reason'));

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'bhi_reports', [
            'reporter_user_id' => $uid, 'target_type' => $target_type, 'target_id' => $target_id,
            'category' => $category, 'reason' => $reason,
        ]);

        return rest_ensure_response(['ok' => true]);
    }

    /* ---------- the button any plugin embeds ---------- */

    public static function report_button_html($target_type, $target_id) {
        if (!is_user_logged_in()) {
            return '<span class="bhi-report-login-note">Log in to report this.</span>';
        }
        $nonce = wp_create_nonce('bhi_submit_report_' . $target_type . '_' . $target_id);
        ob_start(); ?>
        <details class="bhi-report">
            <summary>Report</summary>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="bhi_submit_report" />
                <input type="hidden" name="target_type" value="<?php echo esc_attr($target_type); ?>" />
                <input type="hidden" name="target_id" value="<?php echo esc_attr($target_id); ?>" />
                <input type="hidden" name="bhi_report_nonce" value="<?php echo esc_attr($nonce); ?>" />
                <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url(remove_query_arg('bhi_reported')); ?>" />
                <select name="category">
                    <?php foreach (self::CATEGORIES as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <textarea name="reason" rows="2" maxlength="1000" placeholder="A few details help — optional"></textarea>
                <button type="submit">Submit report</button>
            </form>
        </details>
        <?php
        return ob_get_clean();
    }

    /* ---------- submission ---------- */

    public static function handle_submit() {
        if (!is_user_logged_in()) wp_die('Log in to report content.');

        $target_type = sanitize_key($_POST['target_type'] ?? '');
        $target_id = (int) ($_POST['target_id'] ?? 0);
        $referer = !empty($_POST['_wp_http_referer']) ? esc_url_raw($_POST['_wp_http_referer']) : home_url('/');

        if (!$target_type || !$target_id
            || !wp_verify_nonce($_POST['bhi_report_nonce'] ?? '', 'bhi_submit_report_' . $target_type . '_' . $target_id)) {
            wp_die('Security check failed.');
        }

        $uid = get_current_user_id();
        $rl_key = 'bhi_report_rl_' . $uid;
        if ((int) get_transient($rl_key) >= self::RATE_LIMIT) {
            wp_safe_redirect(add_query_arg('bhi_reported', 'throttled', $referer));
            exit;
        }
        set_transient($rl_key, (int) get_transient($rl_key) + 1, self::RATE_WINDOW);

        $category = array_key_exists($_POST['category'] ?? '', self::CATEGORIES) ? $_POST['category'] : 'other';
        $reason = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'bhi_reports', [
            'reporter_user_id' => $uid, 'target_type' => $target_type, 'target_id' => $target_id,
            'category' => $category, 'reason' => $reason,
        ]);

        wp_safe_redirect(add_query_arg('bhi_reported', '1', $referer));
        exit;
    }

    /* ---------- admin queue ---------- */

    public static function add_admin_page() {
        add_submenu_page(
            'own-ur-shit', 'Reports', 'Reports', 'manage_options', 'ous-reports', [self::class, 'render_admin_page']
        );
    }

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');

        if (isset($_GET['resolve']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'bhi_resolve_report')) {
            self::resolve((int) $_GET['resolve'], sanitize_key($_GET['as'] ?? 'resolved'));
        }

        global $wpdb;
        $status_filter = sanitize_key($_GET['status'] ?? 'open');

        // Report-volume prioritization: a target with several
        // INDEPENDENT reporters is a stronger signal than the same
        // number of reports from one person repeating themselves (the
        // per-user rate limit above already caps that anyway, but this
        // is explicit about counting distinct reporters, not raw report
        // rows). Sorted to the top of the OPEN queue specifically —
        // resolved/dismissed views stay newest-first since there's no
        // "urgency" left to signal there.
        $reports = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*,
                    (SELECT COUNT(DISTINCT r2.reporter_user_id) FROM {$wpdb->prefix}bhi_reports r2
                     WHERE r2.target_type = r.target_type AND r2.target_id = r.target_id AND r2.status = 'open') AS distinct_reporters
             FROM {$wpdb->prefix}bhi_reports r
             WHERE r.status = %s
             ORDER BY " . ($status_filter === 'open' ? 'distinct_reporters DESC, created_at DESC' : 'created_at DESC') . "
             LIMIT 200",
            $status_filter
        ));

        echo '<div class="wrap"><h1>Reports</h1>';
        echo '<p><a href="' . esc_url(add_query_arg('status', 'open')) . '">Open</a> &middot; '
           . '<a href="' . esc_url(add_query_arg('status', 'resolved')) . '">Resolved</a> &middot; '
           . '<a href="' . esc_url(add_query_arg('status', 'dismissed')) . '">Dismissed</a></p>';

        if (!$reports) { echo '<p>Nothing here.</p></div>'; return; }

        // --tall: the whole page IS this one moderation queue.
        echo '<div class="bhy-table-wrap bhy-table-wrap--tall">';
        echo '<table class="wp-list-table widefat striped"><thead><tr><th>When</th><th>Reporter</th><th>Target</th><th>Category</th><th>Reason</th><th>Action</th></tr></thead><tbody>';
        foreach ($reports as $r) {
            $reporter = get_userdata($r->reporter_user_id);
            $label = apply_filters('bhi_report_target_label', $r->target_type . ' #' . $r->target_id, $r->target_type, $r->target_id);
            $multi = $status_filter === 'open' && (int) $r->distinct_reporters >= 3;
            echo '<tr' . ($multi ? ' style="background:#fbeaea;"' : '') . '>';
            echo '<td>' . esc_html($r->created_at) . '</td>';
            echo '<td>' . esc_html($reporter ? $reporter->display_name : 'User #' . $r->reporter_user_id) . '</td>';
            echo '<td>' . esc_html($label) . ($multi ? ' <strong style="color:#b32d2e;">— ' . (int) $r->distinct_reporters . ' independent reports</strong>' : '') . '</td>';
            echo '<td>' . esc_html(self::CATEGORIES[$r->category] ?? $r->category) . '</td>';
            echo '<td>' . esc_html($r->reason ?: '—') . '</td>';
            if ($r->status === 'open') {
                $resolve_url = wp_nonce_url(add_query_arg(['resolve' => $r->id, 'as' => 'resolved']), 'bhi_resolve_report');
                $dismiss_url = wp_nonce_url(add_query_arg(['resolve' => $r->id, 'as' => 'dismissed']), 'bhi_resolve_report');
                echo '<td><a href="' . esc_url($resolve_url) . '">Mark actioned</a> &middot; <a href="' . esc_url($dismiss_url) . '">Dismiss</a></td>';
            } else {
                echo '<td>' . esc_html(ucfirst($r->status)) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function resolve($id, $status) {
        if (!in_array($status, ['resolved', 'dismissed'], true)) return;
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'bhi_reports', [
            'status' => $status, 'resolved_at' => current_time('mysql'),
        ], ['id' => $id]);
    }
}
