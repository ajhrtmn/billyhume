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
        'technical'  => 'Technical difficulty / bug report',
        'other'      => 'Something else',
    ];

    // Reused, not reinvented, for "report a technical difficulty" — AJ's
    // own ask this session. Every other category here reports a specific
    // piece of CONTENT (target_type + target_id both required); a bug
    // report has no content to point at, so this is the one category
    // allowed a zero target_id (see rest_submit()'s relaxed check below)
    // rather than standing up a second, parallel queue/admin screen for
    // what is functionally the same "collect it, let a human triage it"
    // workflow this class already provides.
    const TECHNICAL_TARGET_TYPE = 'technical';
    const RATE_LIMIT = 5;   // max reports per user per hour — a real moderation signal, not a way to bury someone in noise
    const RATE_WINDOW = HOUR_IN_SECONDS;

    public static function init() {
        add_action('admin_post_bhi_submit_report', [self::class, 'handle_submit']);
        add_action('admin_menu', [self::class, 'add_admin_page']);
        // Ecosystem-wide "report a technical difficulty" widget — AJ's
        // own ask. Front-end only (a logged-in admin already has this
        // plugin's own Debug Tools/error logs; a confused site VISITOR
        // hitting something broken is who this is actually for), and
        // logged-in only, matching report_button_html()'s own existing
        // "log in to report" posture rather than accepting anonymous
        // reports this queue has no way to follow up on.
        add_action('wp_footer', [self::class, 'render_technical_report_widget']);
    }

    public static function render_technical_report_widget() {
        if (!is_user_logged_in() || is_admin()) return;
        $nonce = wp_create_nonce('wp_rest');
        ?>
        <style>
            /* AJ's own ask: this widget was colliding with bh-contest's
               fixed bottom player bar on contest pages. Rather than this
               plugin (own-ur-shit core, no dependency on bh-contest)
               detecting that bar's presence in JS, it reads the SAME
               --bh-bar-height custom property bh-contest's own player.css
               already sets on :root for exactly this purpose (its own
               .bh-toast component already positions itself above the bar
               this same way — see player.css). CSS custom properties
               cascade through :root regardless of which stylesheet
               defined them, so this works with zero coupling: on a page
               where that property isn't defined at all (no player bar),
               the var() fallback (0px) makes this behave exactly as
               before. */
            .bhi-tech-report { position: fixed; right: 16px; bottom: calc(16px + var(--bh-bar-height, 0px)); z-index: 99998; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
            .bhi-tech-report-toggle {
                background: var(--bhy-surface, #fff); color: var(--bhy-ink-dim, #646970);
                border: 1px solid var(--bhy-border, #dcdcde); border-radius: 999px;
                padding: 8px 16px; font-size: 12px; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,.12);
            }
            .bhi-tech-report-toggle:hover { color: var(--bhy-accent, #2271b1); border-color: var(--bhy-accent, #2271b1); }
            .bhi-tech-report-panel {
                position: absolute; right: 0; bottom: calc(100% + 8px); width: 280px;
                background: var(--bhy-surface, #fff); border: 1px solid var(--bhy-border, #dcdcde);
                border-radius: 8px; padding: 14px; box-shadow: 0 4px 16px rgba(0,0,0,.16);
            }
            .bhi-tech-report-intro { margin: 0 0 8px; font-size: 12px; color: var(--bhy-ink-dim, #646970); }
            .bhi-tech-report-text {
                width: 100%; box-sizing: border-box; font-size: 13px; padding: 8px;
                border: 1px solid var(--bhy-border, #dcdcde); border-radius: 6px; resize: vertical;
            }
            .bhi-tech-report-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 10px; }
            .bhi-tech-report-cancel { background: none; border: none; color: var(--bhy-ink-dim, #646970); cursor: pointer; font-size: 13px; }
            .bhi-tech-report-submit {
                background: var(--bhy-accent, #2271b1); color: #fff; border: none; border-radius: 6px;
                padding: 6px 14px; font-size: 13px; cursor: pointer;
            }
            .bhi-tech-report-submit:disabled { opacity: .6; cursor: default; }
            @media (max-width: 480px) { .bhi-tech-report-panel { width: calc(100vw - 32px); } }
        </style>
        <div id="bhi-tech-report" class="bhi-tech-report">
            <button type="button" class="bhi-tech-report-toggle" aria-expanded="false">Report a problem</button>
            <div class="bhi-tech-report-panel" hidden>
                <p class="bhi-tech-report-intro">Something not working right? Describe it below — we'll see exactly what page you were on.</p>
                <textarea class="bhi-tech-report-text" rows="4" maxlength="1000" placeholder="What happened? What did you expect instead?"></textarea>
                <div class="bhi-tech-report-actions">
                    <button type="button" class="bhi-tech-report-cancel">Cancel</button>
                    <button type="button" class="bhi-tech-report-submit">Send report</button>
                </div>
            </div>
        </div>
        <script>
        (function () {
            // Recent-action trail — AJ's own ask: "recent user actions
            // taken or other things like that" as extra report context.
            // A capped (last 12), sessionStorage-backed log of clicked
            // interactive elements' visible labels — never keystrokes,
            // never field VALUES/PII, just "what did they click and
            // roughly when" — so whoever triages a report can see the
            // path that led there, not just the final page. Lives at
            // module scope (not inside the widget's own IIFE below) so
            // it starts recording from page load, before the widget is
            // ever opened — the whole point is capturing what happened
            // BEFORE the user realized something was wrong. sessionStorage
            // (not a variable) so the trail survives a real navigation
            // between pages within the same tab/session, since a report
            // is very often filed one or two pages after whatever
            // actually broke.
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

            // Coarse "which feature area" guess from known root markers
            // already on the page — not a claim about which FILE is
            // involved (this is client-side, it can't know that), but a
            // real hint that saves the triager from guessing "was this
            // even in the LMS?" from the URL alone.
            var SURFACE_MARKERS = [
                ['.bhc-lesson', 'BH Courses — lesson/quiz step walker'],
                ['.bhc-catalog', 'BH Courses — course catalog'],
                ['.bhc-course-view', 'BH Courses — course detail page'],
                ['.bh-player-root', 'BH Contest — submission/voting player'],
                ['.bhs-player', 'BH Streaming — audio player'],
                ['.bhy-shell', 'Own Ur Shit — portal/account UI'],
            ];
            function detectSurface() {
                for (var i = 0; i < SURFACE_MARKERS.length; i++) {
                    if (document.querySelector(SURFACE_MARKERS[i][0])) return SURFACE_MARKERS[i][1];
                }
                return 'Unrecognized page (no known feature marker found)';
            }

            var root = document.getElementById('bhi-tech-report');
            if (!root) return;
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
                // Auto-captured context, not something the reporter has
                // to think to include — the whole point of this widget
                // over a generic "email us" link is that whoever
                // triages it in the admin queue sees exactly what page,
                // browser, feature area, and recent click path led here
                // without a back-and-forth. Extended per AJ's own ask
                // ("recent user actions... other valuable context") on
                // top of the page/browser info already captured.
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
                // Elegant retry, AJ's own ask — the first real one
                // anywhere in this ecosystem's JS (checked: nothing else
                // has actual retry/backoff logic today). Retries ONLY on
                // network failure (fetch() itself rejecting — offline, a
                // dropped connection, a timeout) or a 5xx server error —
                // never on a 4xx (bad nonce, rate-limited, validation
                // failure), since retrying those just repeats the same
                // failure and wastes the user's wait. Exponential backoff
                // with jitter (500ms, ~1.2s, ~2.6s) across up to 3
                // attempts total — a report is exactly the kind of
                // "silently dropped on a flaky connection" failure that
                // matters, since the whole point is capturing a problem
                // the user is already frustrated by.
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
        // A technical-difficulty report has no piece of content to
        // point at — target_id = 0 is only valid for that one category,
        // never silently accepted for a content report missing its ID.
        if (!$target_type || (!$target_id && $target_type !== self::TECHNICAL_TARGET_TYPE)) {
            return new WP_Error('invalid', 'Missing target.', ['status' => 400]);
        }

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
            $default_label = $r->target_type === self::TECHNICAL_TARGET_TYPE ? 'Technical report (no specific content)' : $r->target_type . ' #' . $r->target_id;
            $label = apply_filters('bhi_report_target_label', $default_label, $r->target_type, $r->target_id);
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
