<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared reports/moderation queue; never auto-actions, a human reviews. Plugins print
 * BHI_Reports::report_button_html($type, $id) and may label targets via bhi_report_target_label.
 */
class BHI_Reports {
    const CATEGORIES = [
        'abuse'      => 'Harassment / abusive content',
        'ownership'  => "This isn't rightfully theirs (ownership dispute / takedown)",
        'spam'       => 'Spam or scam',
        'technical'  => 'Technical difficulty / bug report',
        'other'      => 'Something else',
    ];

    // Bug reports reuse this queue; the only category allowed target_id = 0.
    const TECHNICAL_TARGET_TYPE = 'technical';
    const RATE_LIMIT = 5;   // max reports per user per hour — a real moderation signal, not a way to bury someone in noise
    const RATE_WINDOW = HOUR_IN_SECONDS;

    public static function init(): void {
        add_action('admin_post_bhi_submit_report', [self::class, 'handle_submit']);
        add_action('admin_menu', [self::class, 'add_admin_page']);
        // "Report a technical difficulty" widget: front end, logged-in only.
        add_action('wp_footer', [self::class, 'render_technical_report_widget']);
    }

    public static function render_technical_report_widget(): void {
        if (!is_user_logged_in() || is_admin()) return;
        $nonce = wp_create_nonce('wp_rest');
        ?>
        <style>
<?php echo (string) file_get_contents(OUS_PATH . 'assets/css/reports.css'); ?></style>
        <div id="bhi-tech-report" class="bhi-tech-report">
            <button type="button" class="bhi-tech-report-toggle" aria-expanded="false"><?php echo OUS_Words::get('admin.reports.report_problem'); ?></button>
            <div class="bhi-tech-report-panel" hidden>
                <p class="bhi-tech-report-intro"><?php echo OUS_Words::get('admin.reports.something_not_working_right_describe'); ?></p>
                <textarea class="bhi-tech-report-text" rows="4" maxlength="1000" placeholder="What happened? What did you expect instead?"></textarea>
                <div class="bhi-tech-report-actions">
                    <button type="button" class="bhi-tech-report-cancel"><?php echo OUS_Words::get('admin.reports.cancel'); ?></button>
                    <button type="button" class="bhi-tech-report-submit"><?php echo OUS_Words::get('admin.reports.send_report'); ?></button>
                </div>
            </div>
        </div>
        <script>
        (function () {
            // Recent-action trail for report context: the last 12 clicked labels (never
            // keystrokes or field values) in sessionStorage, recorded from page load so it
            // survives navigation and shows what happened before the report.
            var TRAIL_KEY = 'bhi_action_trail';
            var TRAIL_MAX = 12;
            function pushTrail(label) {
                if (!label) return;
                var trail;
                try { trail = JSON.parse(sessionStorage.getItem(TRAIL_KEY) || '[]'); } catch (e) { trail = []; }
                trail.push({ l: label.slice(0, 80), t: Date.now() });
                if (trail.length > TRAIL_MAX) trail = trail.slice(-TRAIL_MAX);
                try { sessionStorage.setItem(TRAIL_KEY, JSON.stringify(trail)); } catch (e) { /* storage full/disabled — trail just stays shorter, not fatal */ }
            }
            pushTrail('Loaded: ' + document.title);
            document.addEventListener('click', function (e) {
                var el = e.target.closest('button, a, [role="button"], input[type="submit"]');
                if (!el) return;
                var label = (el.getAttribute('aria-label') || el.textContent || el.value || '').trim().replace(/\s+/g, ' ');
                if (label) pushTrail('Clicked: ' + label);
            }, true);

            // Coarse feature-area guess from root markers on the page, so the triager
            // knows roughly where the report came from.
            var SURFACE_MARKERS = [
                ['.bhc-lesson', 'BH Courses — lesson/quiz step walker'],
                ['.bhc-catalog', 'BH Courses — course catalog'],
                ['.bhc-course-view', 'BH Courses — course detail page'],
                ['.bh-player-root', 'BH Contest — submission/voting player'],
                ['.bhs-player', 'BH Streaming — audio player'],
                ['.bhy-shell', 'The Self-Hosted Self — portal/account UI'],
            ];
            function detectSurface() {
                for (var i = 0; i < SURFACE_MARKERS.length; i++) {
                    if (document.querySelector(SURFACE_MARKERS[i][0])) return SURFACE_MARKERS[i][1];
                }
                return 'Unrecognized page (no known feature marker found)';
            }

            var root = document.getElementById('bhi-tech-report');
            if (!root) return;

            // Measures the real contest player bar (if present) and re-checks on resize,
            // so the report button clears it at any breakpoint.
            function repositionAboveBar() {
                var bar = document.querySelector('.bh-now-playing-bar');
                root.style.bottom = bar ? (16 + bar.getBoundingClientRect().height) + 'px' : '16px';
            }
            repositionAboveBar();
            window.addEventListener('resize', repositionAboveBar);
            // The player bar is built client-side by player.js, so watch for it once
            // and disconnect after it appears.
            if (window.MutationObserver) {
                var barWatcher = new MutationObserver(function () {
                    if (document.querySelector('.bh-now-playing-bar')) {
                        repositionAboveBar();
                        barWatcher.disconnect();
                    }
                });
                barWatcher.observe(document.body, { childList: true, subtree: true });
            }

            var toggle = root.querySelector('.bhi-tech-report-toggle');
            var panel = root.querySelector('.bhi-tech-report-panel');
            var text = root.querySelector('.bhi-tech-report-text');
            var submitBtn = root.querySelector('.bhi-tech-report-submit');
            var cancelBtn = root.querySelector('.bhi-tech-report-cancel');

            function setOpen(open) {
                panel.hidden = !open;
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) text.focus();
            }
            toggle.addEventListener('click', function () { setOpen(panel.hidden); });
            cancelBtn.addEventListener('click', function () { setOpen(false); text.value = ''; });

            submitBtn.addEventListener('click', function () {
                var reason = text.value.trim();
                if (!reason) { text.focus(); return; }
                submitBtn.disabled = true;
                submitBtn.textContent = 'Sending…';
                // Page, browser, feature area and recent clicks are captured automatically,
                // so the triager needs no back-and-forth.
                var trail;
                try { trail = JSON.parse(sessionStorage.getItem(TRAIL_KEY) || '[]'); } catch (e) { trail = []; }
                var trailLines = trail.map(function (entry) {
                    var secondsAgo = Math.round((Date.now() - entry.t) / 1000);
                    return '  - ' + entry.l + ' (' + secondsAgo + 's before report)';
                }).join('\n') || '  (none captured this session)';
                var context = 'Page: ' + window.location.href
                    + '\nFeature area (best guess): ' + detectSurface()
                    + '\nBrowser: ' + navigator.userAgent
                    + '\nRecent actions this session:\n' + trailLines
                    + '\n\n' + reason;
                // Retry only on network failure or 5xx (never 4xx), up to 3 attempts with
                // exponential backoff and jitter, so a flaky connection does not drop a report.
                submitReport(0);
                function submitReport(attempt) {
                    fetch(<?php echo wp_json_encode(esc_url_raw(rest_url('bhi/v1/reports'))); ?>, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': <?php echo wp_json_encode($nonce); ?> },
                        body: JSON.stringify({ target_type: 'technical', target_id: 0, category: 'technical', reason: context }),
                    }).then(function (r) {
                        return r.json().catch(function () { return {}; }).then(function (body) { return { ok: r.ok, status: r.status, body: body }; });
                    }).then(function (res) {
                        if (!res.ok && res.status >= 500 && attempt < 2) {
                            submitBtn.textContent = 'Retrying…';
                            setTimeout(function () { submitReport(attempt + 1); }, 500 * Math.pow(2, attempt) + Math.random() * 200);
                            return;
                        }
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Send report';
                        if (res.ok) {
                            if (typeof BHCoreToast !== 'undefined') BHCoreToast.show('Thanks — we got it.', 'success');
                            else alert('Thanks — we got it.');
                            text.value = '';
                            setOpen(false);
                        } else {
                            var msg = (res.body && res.body.message) || 'Could not send that — please try again.';
                            if (typeof BHCoreToast !== 'undefined') BHCoreToast.show(msg, 'error');
                            else alert(msg);
                        }
                    }).catch(function () {
                        if (attempt < 2) {
                            submitBtn.textContent = 'Retrying…';
                            setTimeout(function () { submitReport(attempt + 1); }, 500 * Math.pow(2, attempt) + Math.random() * 200);
                            return;
                        }
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Send report';
                        if (typeof BHCoreToast !== 'undefined') BHCoreToast.show('Could not send that — check your connection and try again.', 'error');
                    });
                }
            });
        })();
        </script>
        <?php
    }

    // REST twin of handle_submit() for JS-rendered front ends; same rate limit,
    // validation and table.
    public static function register_routes(): void {
        register_rest_route('bhi/v1', '/reports', [
            'methods' => 'POST', 'callback' => [self::class, 'rest_submit'], 'permission_callback' => 'is_user_logged_in',
        ]);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function rest_submit(\WP_REST_Request $req) {
        $target_type = sanitize_key((string) $req->get_param('target_type'));
        $target_id = (int) $req->get_param('target_id');
        // target_id = 0 is valid only for technical reports.
        if (!$target_type || (!$target_id && $target_type !== self::TECHNICAL_TARGET_TYPE)) {
            return new WP_Error('invalid', OUS_Words::get('admin.reports.missing_target'), ['status' => 400]);
        }

        $uid = get_current_user_id();
        $rl_key = 'bhi_report_rl_' . $uid;
        if ((int) get_transient($rl_key) >= self::RATE_LIMIT) {
            return new WP_Error('rate_limited', OUS_Words::get('admin.reports.too_many_reports_please_wait'), ['status' => 429]);
        }
        set_transient($rl_key, (int) get_transient($rl_key) + 1, self::RATE_WINDOW);

        $category = array_key_exists($req->get_param('category'), self::CATEGORIES) ? $req->get_param('category') : 'other';
        $reason = sanitize_textarea_field((string) $req->get_param('reason'));

        global $wpdb;
        $wpdb->insert(OUS_Tables::reports(), [
            'reporter_user_id' => $uid, 'target_type' => $target_type, 'target_id' => $target_id,
            'category' => $category, 'reason' => $reason,
        ]);

        return rest_ensure_response(['ok' => true]);
    }

    /* ---------- the button any plugin embeds ---------- */

    public static function report_button_html(string $target_type, int $target_id): string {
        if (!is_user_logged_in()) {
            return ('<span class="bhi-report-login-note">' . OUS_Words::get('admin.reports.log_report') . '</span>');
        }
        $nonce = wp_create_nonce('bhi_submit_report_' . $target_type . '_' . $target_id);
        ob_start(); ?>
        <details class="bhi-report">
            <summary><?php echo OUS_Words::get('admin.reports.report'); ?></summary>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="bhi_submit_report" />
                <input type="hidden" name="target_type" value="<?php echo esc_attr($target_type); ?>" />
                <input type="hidden" name="target_id" value="<?php echo esc_attr((string) $target_id); ?>" />
                <input type="hidden" name="bhi_report_nonce" value="<?php echo esc_attr($nonce); ?>" />
                <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url(remove_query_arg('bhi_reported')); ?>" />
                <select name="category">
                    <?php foreach (self::CATEGORIES as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <textarea name="reason" rows="2" maxlength="1000" placeholder="A few details help — optional"></textarea>
                <button type="submit"><?php echo OUS_Words::get('admin.reports.submit_report'); ?></button>
            </form>
        </details>
        <?php
        return ob_get_clean();
    }

    /* ---------- submission ---------- */

    public static function handle_submit(): void {
        if (!is_user_logged_in()) wp_die(OUS_Words::get('admin.reports.log_report_content'));

        $target_type = sanitize_key($_POST['target_type'] ?? '');
        $target_id = (int) ($_POST['target_id'] ?? 0);
        $referer = !empty($_POST['_wp_http_referer']) ? esc_url_raw($_POST['_wp_http_referer']) : home_url('/');

        if (!$target_type || !$target_id
            || !wp_verify_nonce($_POST['bhi_report_nonce'] ?? '', 'bhi_submit_report_' . $target_type . '_' . $target_id)) {
            wp_die(OUS_Words::get('admin.media_wizard.security_check_failed'));
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
        $wpdb->insert(OUS_Tables::reports(), [
            'reporter_user_id' => $uid, 'target_type' => $target_type, 'target_id' => $target_id,
            'category' => $category, 'reason' => $reason,
        ]);

        wp_safe_redirect(add_query_arg('bhi_reported', '1', $referer));
        exit;
    }

    /* ---------- admin queue ---------- */

    public static function add_admin_page(): void {
        add_submenu_page(
            'ous', 'Reports', 'Reports', 'manage_options', 'ous-reports', [self::class, 'render_admin_page']
        );
    }

    public static function render_admin_page(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');

        if (isset($_GET['resolve']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'bhi_resolve_report')) {
            self::resolve((int) $_GET['resolve'], sanitize_key($_GET['as'] ?? 'resolved'));
        }

        global $wpdb;
        $status_filter = sanitize_key($_GET['status'] ?? 'open');

        // Open queue sorts by distinct reporters (stronger signal than repeat
        // reports from one person); resolved/dismissed stay newest-first.
        $reports = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*,
                    (SELECT COUNT(DISTINCT r2.reporter_user_id) FROM " . OUS_Tables::reports() . " r2
                     WHERE r2.target_type = r.target_type AND r2.target_id = r.target_id AND r2.status = 'open') AS distinct_reporters
             FROM " . OUS_Tables::reports() . " r
             WHERE r.status = %s
             ORDER BY " . ($status_filter === 'open' ? OUS_Words::get('admin.reports.distinct_reporters_desc_created_at') : OUS_Words::get('admin.reports.created_at_desc')) . "
             LIMIT 200",
            $status_filter
        ));

        echo ('<div class="wrap"><h1>' . OUS_Words::get('admin.reports.reports') . '</h1>');
        echo '<p><a href="' . esc_url(add_query_arg('status', 'open')) . ('">' . OUS_Words::get('admin.reports.open') . '</a> &middot; ')
           . '<a href="' . esc_url(add_query_arg('status', 'resolved')) . ('">' . OUS_Words::get('admin.reports.resolved') . '</a> &middot; ')
           . '<a href="' . esc_url(add_query_arg('status', 'dismissed')) . ('">' . OUS_Words::get('admin.reports.dismissed') . '</a></p>');

        if (!$reports) { echo ('<p>' . OUS_Words::get('admin.reports.nothing_here') . '</p></div>'); return; }

        // --tall: the whole page IS this one moderation queue.
        echo '<div class="bhy-table-wrap bhy-table-wrap--tall">';
        echo ('<table class="wp-list-table widefat striped"><thead><tr><th>' . OUS_Words::get('admin.audit.when') . '</th><th>' . OUS_Words::get('admin.reports.reporter') . '</th><th>' . OUS_Words::get('admin.contrast.target') . '</th><th>' . OUS_Words::get('admin.reports.category') . '</th><th>' . OUS_Words::get('admin.reports.reason') . '</th><th>' . OUS_Words::get('admin.audit.action') . '</th></tr></thead><tbody>');
        foreach ($reports as $r) {
            $reporter = get_userdata($r->reporter_user_id);
            $default_label = $r->target_type === self::TECHNICAL_TARGET_TYPE ? OUS_Words::get('admin.reports.technical_report_no_specific_content') : $r->target_type . ' #' . $r->target_id;
            $label = apply_filters('bhi_report_target_label', $default_label, $r->target_type, $r->target_id);
            $multi = $status_filter === 'open' && (int) $r->distinct_reporters >= 3;
            echo '<tr' . ($multi ? ' style="background:#fbeaea;"' : '') . '>';
            echo '<td>' . esc_html($r->created_at) . '</td>';
            echo '<td>' . esc_html($reporter ? $reporter->display_name : OUS_Words::get('admin.public_profile.user') . $r->reporter_user_id) . '</td>';
            echo '<td>' . esc_html($label) . ($multi ? ' <strong style="color:#b32d2e;">— ' . (int) $r->distinct_reporters . ' independent reports</strong>' : '') . '</td>';
            echo '<td>' . esc_html(self::CATEGORIES[$r->category] ?? $r->category) . '</td>';
            echo '<td>' . esc_html($r->reason ?: '—') . '</td>';
            if ($r->status === 'open') {
                $resolve_url = wp_nonce_url(add_query_arg(['resolve' => $r->id, 'as' => 'resolved']), 'bhi_resolve_report');
                $dismiss_url = wp_nonce_url(add_query_arg(['resolve' => $r->id, 'as' => 'dismissed']), 'bhi_resolve_report');
                echo '<td><a href="' . esc_url($resolve_url) . ('">' . OUS_Words::get('admin.reports.mark_actioned') . '</a> &middot; <a href="') . esc_url($dismiss_url) . ('">' . OUS_Words::get('admin.reports.dismiss') . '</a></td>');
            } else {
                echo '<td>' . esc_html(ucfirst($r->status)) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function resolve(int $id, string $status): void {
        if (!in_array($status, ['resolved', 'dismissed'], true)) return;
        global $wpdb;
        $wpdb->update(OUS_Tables::reports(), [
            'status' => $status, 'resolved_at' => current_time('mysql'),
        ], ['id' => $id]);
    }
}
