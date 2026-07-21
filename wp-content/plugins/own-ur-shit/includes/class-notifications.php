<?php
if (!defined('ABSPATH')) exit;

/**
 * A shared notification inbox any plugin can write into with ONE static
 * call — `OUS_Notifications::notify()` — and zero registration step,
 * zero central config, zero awareness of what any other plugin is
 * doing with the same system. This lives in the core specifically so
 * every feature plugin (which already depends only on the core, never
 * on each other) gets it for free the moment it's active, the same
 * relationship BHM_Gate or bhi_reports already has.
 *
 * Two delivery channels: in-app (always written to bhcore_notifications
 * — an admin-bar bell + a `[bh_notifications]` front-end shortcode both
 * read from it) and email, which is OPTIONAL per call and routed
 * through the job queue (class-jobs.php) rather than sent inline, so a
 * plugin calling notify() during a request never blocks on an SMTP
 * round-trip.
 *
 * USAGE, from any plugin that depends on this one:
 *
 *   OUS_Notifications::notify($user_id, 'course_completed', 'Course complete!',
 *       'You finished "Songwriting Fundamentals".', get_permalink($course_id), 'BH Courses');
 *
 * That's the entire contract. No filter to hook, no class to extend.
 */
class OUS_Notifications {
    public static function init() {
        add_action('init', [self::class, 'register_shortcode']);
        add_action('admin_bar_menu', [self::class, 'admin_bar'], 90);
        add_action('wp_ajax_bhcore_mark_notification_read', [self::class, 'ajax_mark_read']);
        add_action('wp_ajax_bhcore_mark_all_notifications_read', [self::class, 'ajax_mark_all_read']);
        add_action('wp_ajax_bhcore_save_notification_pref', [self::class, 'ajax_save_pref']);
        add_filter('bhcore_test_suites', [self::class, 'register_test_suite']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
        add_action('admin_enqueue_scripts', [self::class, 'maybe_enqueue']);

        // This plugin's own contribution to BHI_Portal — reuses the exact
        // same render_shortcode() output the [bh_notifications] shortcode
        // already produces (see below) rather than a second, parallel
        // rendering path. maybe_enqueue() above already fires on the
        // portal's own page for free: render_shell() (class-portal.php)
        // calls wp_head(), and WordPress core fires 'wp_enqueue_scripts'
        // from wp_head itself — no extra enqueue wiring needed here.
        add_filter('bhi_portal_panels', [self::class, 'register_portal_panel']);

        // The job queue's own registered handler for actually sending a
        // queued email — see class-jobs.php. Registered here (where the
        // job TYPE is defined) rather than in class-jobs.php itself,
        // which has no opinion about what any given job hook means.
        add_action('init', function () {
            if (class_exists('OUS_Jobs')) {
                OUS_Jobs::register('bhcore_send_notification_email', [self::class, 'send_queued_email']);
            }
        });
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhcore_notifications';
    }

    /**
     * The one call any plugin needs. $email: true to also queue an
     * email (respecting the recipient's own opt-out — see
     * user_wants_email() below), false for in-app-only. Source is a
     * plain human label ("BH Courses") shown next to the notification,
     * purely cosmetic — never used to gate or route anything, so a
     * typo'd source string can't break another plugin's notifications.
     */
    public static function notify($user_id, $type, $title, $body = '', $url = '', $source = '', $email = true) {
        if (!$user_id) return 0;
        global $wpdb;
        $wpdb->insert(self::table(), [
            'user_id' => $user_id, 'type' => sanitize_key($type), 'source' => sanitize_text_field($source),
            'title' => sanitize_text_field($title), 'body' => wp_kses_post($body), 'url' => esc_url_raw($url),
        ]);
        $id = (int) $wpdb->insert_id;

        if ($email && self::user_wants_email($user_id, $type)) {
            if (class_exists('OUS_Jobs')) {
                OUS_Jobs::enqueue('bhcore_send_notification_email', ['notification_id' => $id, 'user_id' => $user_id, 'title' => $title, 'body' => $body, 'url' => $url]);
            } else {
                // No job queue active (shouldn't happen — same plugin,
                // same activation) — fail toward still delivering the
                // email rather than silently dropping it.
                self::send_email_now($user_id, $title, $body, $url);
            }
        }
        return $id;
    }

    // A user can opt out of EMAIL entirely (in-app notifications always
    // still happen — that's just this plugin's own inbox, not an
    // external send) via a simple user meta checkbox on their profile —
    // an absolute override, checked first. Layered on top: per-TYPE
    // preferences (bhcore_notification_email_prefs, a serialized
    // type => bool map, absent-or-missing-key means default-on) — see
    // type_wants_email() below. Existing call sites needed zero changes;
    // this filter's own signature already carried $type before this,
    // it just wasn't consulted per-type until now.
    public static function user_wants_email($user_id, $type) {
        $opt_out = get_user_meta($user_id, 'bhcore_notifications_email_optout', true);
        $wants = $opt_out ? false : self::type_wants_email($user_id, $type);
        return apply_filters('bhcore_notification_should_email', $wants, $user_id, $type);
    }

    // Absent key (a type the user has never seen a preference toggle
    // for, or a brand-new type a plugin just started sending) means
    // "on" — a user who has never touched the preferences UI keeps
    // getting exactly the emails they always did, same as before this
    // existed.
    public static function type_wants_email($user_id, $type) {
        $prefs = get_user_meta($user_id, 'bhcore_notification_email_prefs', true);
        if (!is_array($prefs) || !array_key_exists($type, $prefs)) return true;
        return (bool) $prefs[$type];
    }

    // Progressive disclosure, not a settings grid dumped on everyone:
    // only the notification TYPES this specific user has actually ever
    // received — a type they've never seen has no business appearing as
    // a togglable preference (obvious-or-gone). A plugin can optionally
    // register a human label via the `bhcore_notification_type_labels`
    // filter (type => label); an unregistered type falls back to a
    // humanized version of its own raw string rather than requiring
    // every emitter to register just to be readable here.
    public static function distinct_types_for_user($user_id) {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT type FROM " . self::table() . " WHERE user_id = %d ORDER BY type ASC", $user_id
        ));
    }

    public static function type_label($type) {
        $labels = apply_filters('bhcore_notification_type_labels', []);
        if (isset($labels[$type])) return $labels[$type];
        return ucfirst(str_replace(['_', '-'], ' ', $type));
    }

    // The same queued job can fire more than once (e.g. a manual test
    // call plus Action Scheduler's own background processing of the
    // same scheduled job both running it), which would otherwise mean a
    // silent duplicate email. notification_id (present for every call
    // site going forward — see notify() above) lets this claim the row
    // atomically before mailing; a call with no notification_id (an old
    // queued job predating this fix) just sends once with no dedup,
    // same as before.
    public static function send_queued_email($args) {
        $notification_id = (int) ($args['notification_id'] ?? 0);
        if ($notification_id) {
            global $wpdb;
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE " . self::table() . " SET email_sent = 1 WHERE id = %d AND email_sent = 0",
                $notification_id
            ));
            if (!$claimed) return; // already sent, or the notification row is gone
        }
        self::send_email_now((int) ($args['user_id'] ?? 0), $args['title'] ?? '', $args['body'] ?? '', $args['url'] ?? '');
    }

    private static function send_email_now($user_id, $title, $body, $url) {
        $user = get_userdata($user_id);
        if (!$user || !$user->user_email) return;
        $message = wp_strip_all_tags($body);
        if ($url) $message .= "\n\n" . $url;
        $sent = wp_mail($user->user_email, wp_specialchars_decode($title), $message);

        // Feeds the CRM's unified per-person activity timeline (BH_Event
        // -> bh-crm's BHCRM_Event_Activity) — this is the single choke
        // point every queued notification email sends through, so this
        // one emit() covers every email this ecosystem sends via its
        // own notification system (course/contest/CRM reminders, etc.).
        // Does NOT cover ad hoc wp_mail() calls made directly elsewhere
        // (e.g. bh-contest's submission-received confirmation) — those
        // aren't routed through this class.
        if ($sent && class_exists('BH_Event')) {
            BH_Event::emit('bhcore/email_sent', [
                'user_id' => $user_id, 'subject_type' => 'email', 'subject_id' => 0,
                'payload' => ['title' => $title],
            ]);
        } elseif (!$sent && class_exists('OUS_DebugLog')) {
            // wp_mail() returning false (misconfigured mail transport,
            // rejected recipient, etc.) previously meant a queued
            // notification email silently never arrived. Routine sends
            // feed the activity timeline above; a failure is worth
            // surfacing in Debug Tools instead.
            OUS_DebugLog::log('warning', 'Queued notification email failed to send (wp_mail() returned false).', [
                'user_id' => $user_id, 'title' => $title,
            ], 'OUS Notifications');
        }
    }

    /* ---------------- reading ---------------- */

    public static function unread_count($user_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d AND read_at IS NULL", $user_id
        ));
    }

    public static function for_user($user_id, $limit = 20) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE user_id = %d ORDER BY created_at DESC LIMIT %d", $user_id, $limit
        ), ARRAY_A);
    }

    public static function mark_read($user_id, $notification_id) {
        global $wpdb;
        $wpdb->update(self::table(), ['read_at' => current_time('mysql')], ['id' => $notification_id, 'user_id' => $user_id]);
    }

    public static function mark_all_read($user_id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET read_at = %s WHERE user_id = %d AND read_at IS NULL", current_time('mysql'), $user_id
        ));
    }

    /* ---------------- UI: admin bar bell (works in wp-admin AND front end) ---------------- */

    public static function admin_bar($wp_admin_bar) {
        if (!is_user_logged_in()) return;
        $user_id = get_current_user_id();
        $count = self::unread_count($user_id);

        $wp_admin_bar->add_node([
            'id' => 'bhcore-notifications',
            'title' => '&#128276;' . ($count ? ' <span class="bhcore-notif-count">' . (int) $count . '</span>' : ''),
            'href' => '#',
            'meta' => ['class' => 'bhcore-notif-bar-item'],
        ]);

        foreach (self::for_user($user_id, 8) as $n) {
            $wp_admin_bar->add_node([
                'id' => 'bhcore-notif-' . $n['id'],
                'parent' => 'bhcore-notifications',
                'title' => ($n['read_at'] ? '' : '&#9679; ') . esc_html($n['title']),
                'href' => $n['url'] ?: '#',
            ]);
        }
        if (!self::for_user($user_id, 1)) {
            $wp_admin_bar->add_node(['id' => 'bhcore-notif-empty', 'parent' => 'bhcore-notifications', 'title' => 'No notifications yet']);
        }
    }

    /* ---------------- UI: front-end shortcode ---------------- */

    public static function register_shortcode() {
        add_shortcode('bh_notifications', [self::class, 'render_shortcode']);
    }

    public static function register_portal_panel($panels) {
        $panels[] = [
            'id' => 'notifications',
            'label' => 'Notifications',
            'icon' => 'dashicons-bell',
            'render' => [self::class, 'render_portal_panel'],
            'priority' => 50,
        ];
        return $panels;
    }

    public static function render_portal_panel() {
        echo '<h1>Notifications</h1>';
        echo self::render_shortcode(); // phpcs:ignore -- render_shortcode() already returns fully-escaped markup, same output the [bh_notifications] shortcode itself echoes on the front end
    }

    // A plain <details> disclosure, closed by default — the whole point
    // is that a user who's never wanted finer control than the existing
    // single opt-out never sees a settings grid at all (VISION.md's own
    // "it just works" section: a wall of options is a wall for the
    // person who doesn't already know what every one of them means).
    // Nothing renders here at all if this user has never received any
    // notification yet — obvious-or-gone, never an empty preferences
    // panel for a brand-new account with nothing to configure.
    private static function render_email_prefs($user_id) {
        $types = self::distinct_types_for_user($user_id);
        if (!$types) return '';

        ob_start(); ?>
        <details class="bhcore-notif-prefs">
            <summary>Manage what you get emailed about</summary>
            <div class="bhcore-notif-prefs-body" data-nonce="<?php echo esc_attr(wp_create_nonce('bhcore_notifications')); ?>">
                <?php foreach ($types as $type): ?>
                    <label class="bhcore-notif-pref-row">
                        <input type="checkbox" class="bhcore-notif-pref-toggle" data-type="<?php echo esc_attr($type); ?>" <?php checked(self::type_wants_email($user_id, $type)); ?>>
                        <?php echo esc_html(self::type_label($type)); ?>
                    </label>
                <?php endforeach; ?>
                <p class="bhcore-notif-prefs-status" aria-live="polite"></p>
            </div>
        </details>
        <?php
        return ob_get_clean();
    }

    public static function render_shortcode() {
        if (!is_user_logged_in()) return '<p class="bhcore-notif-empty">Log in to see your notifications.</p>';
        $user_id = get_current_user_id();
        $items = self::for_user($user_id, 30);
        if (!$items) return '<p class="bhcore-notif-empty">Nothing here yet.</p>';

        ob_start();
        echo '<div class="bhcore-notifications-list">';
        echo '<button type="button" class="bhcore-btn bhcore-mark-all-read" data-nonce="' . esc_attr(wp_create_nonce('bhcore_notifications')) . '">Mark all read</button>';
        echo self::render_email_prefs($user_id);
        foreach ($items as $n) {
            echo '<div class="bhcore-notification' . ($n['read_at'] ? '' : ' bhcore-unread') . '" data-id="' . (int) $n['id'] . '">';
            echo '<div class="bhcore-notif-source">' . esc_html($n['source']) . '</div>';
            echo '<div class="bhcore-notif-title">' . ($n['url'] ? '<a href="' . esc_url($n['url']) . '">' . esc_html($n['title']) . '</a>' : esc_html($n['title'])) . '</div>';
            if ($n['body']) echo '<div class="bhcore-notif-body">' . wp_kses_post($n['body']) . '</div>';
            echo '<div class="bhcore-notif-time">' . esc_html(human_time_diff(strtotime($n['created_at']), current_time('timestamp'))) . ' ago</div>';
            echo '</div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    // A dedicated handle of our own, registered with no source file
    // (register a no-op script/style, then attach everything via
    // wp_add_inline_*) rather than piggybacking on WordPress core's
    // 'admin-bar' handle — that handle is only actually enqueued when
    // the toolbar is showing, which a user can turn off in their own
    // profile ("Show Toolbar when viewing site") or a theme can
    // suppress entirely. Piggybacking on it would make the
    // `[bh_notifications]` shortcode's mark-all-read button silently do
    // nothing for exactly the visitors who disabled the admin bar —
    // this way the bell (which DOES live in the admin bar) and the
    // shortcode (which doesn't) both work regardless of that setting.
    public static function maybe_enqueue() {
        if (!is_user_logged_in()) return;

        wp_register_style('bhcore-notifications', false);
        wp_enqueue_style('bhcore-notifications');
        // Uses --bh-* tokens (own-ur-shit's front-end brand vars, see
        // class-style.php) with the original hardcoded WP-admin-bar
        // colors (admin blue #72aee6, etc.) kept as fallbacks — this
        // same markup renders both in the admin-bar dropdown and, via
        // render_portal_panel(), in the front-end portal/shortcode,
        // where hardcoded admin blue clashed against the site's warm
        // cream/terracotta brand.
        wp_add_inline_style('bhcore-notifications', '
            .bhcore-notif-count { background: #d63638; color: #fff; border-radius: 9px; font-size: 11px; padding: 0 5px; margin-left: 2px; }
            .bhcore-notifications-list { max-width: 600px; }
            .bhcore-notification { border: 1px solid var(--bh-border, #dcdcde); border-radius: var(--bh-radius-sm, 6px); padding: 10px 14px; margin-bottom: 8px; background: var(--bh-surface, #fff); }
            .bhcore-notification.bhcore-unread { background: var(--bh-accent-soft, #f0f6fc); border-color: var(--bh-accent, #72aee6); }
            .bhcore-notif-source { font-size: 11px; text-transform: uppercase; color: var(--bh-text-dim, #646970); letter-spacing: .04em; }
            .bhcore-notif-title { font-weight: 600; color: var(--bh-text, inherit); }
            .bhcore-notif-body { font-size: 13px; color: var(--bh-text-dim, #50575e); }
            .bhcore-notif-time { font-size: 11px; color: var(--bh-text-dim, #8c8f94); margin-top: 4px; }
            .bhcore-btn.bhcore-mark-all-read { margin-bottom: 10px; }
            .bhcore-notif-prefs { margin-bottom: 14px; }
            .bhcore-notif-prefs summary { cursor: pointer; font-size: 13px; color: var(--bh-text-dim, #646970); }
            .bhcore-notif-prefs-body { padding: 10px 0 4px 4px; display: flex; flex-direction: column; gap: 6px; }
            .bhcore-notif-pref-row { display: flex; align-items: center; gap: 8px; font-size: 13px; }
            .bhcore-notif-prefs-status { font-size: 12px; color: var(--bh-text-dim, #646970); margin: 4px 0 0; min-height: 1em; }
        ');

        wp_register_script('bhcore-notifications', false, [], OUS_VER, true);
        wp_enqueue_script('bhcore-notifications');
        wp_localize_script('bhcore-notifications', 'BHCoreAjax', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
        ]);
        wp_add_inline_script('bhcore-notifications', '
            document.addEventListener("click", function (e) {
                var markAll = e.target.closest(".bhcore-mark-all-read");
                if (markAll) {
                    var originalLabel = markAll.textContent;
                    markAll.disabled = true;
                    markAll.textContent = "Marking…";
                    fetch(BHCoreAjax.ajaxUrl, {
                        method: "POST",
                        body: new URLSearchParams({ action: "bhcore_mark_all_notifications_read", nonce: markAll.dataset.nonce })
                    }).then(function (r) { return r.json(); }).then(function (res) {
                        markAll.disabled = false;
                        markAll.textContent = originalLabel;
                        if (!res || !res.success) {
                            if (typeof BHCoreToast !== "undefined") { BHCoreToast.show("Could not mark notifications read — try again.", "error"); }
                            else { alert("Could not mark notifications read — try again."); }
                            return;
                        }
                        document.querySelectorAll(".bhcore-notification.bhcore-unread").forEach(function (el) { el.classList.remove("bhcore-unread"); });
                    }).catch(function () {
                        markAll.disabled = false;
                        markAll.textContent = originalLabel;
                        if (typeof BHCoreToast !== "undefined") { BHCoreToast.show("Could not reach the server — check your connection and try again.", "error"); }
                        else { alert("Could not reach the server — check your connection and try again."); }
                    });
                }
            });
            document.addEventListener("change", function (e) {
                var toggle = e.target.closest(".bhcore-notif-pref-toggle");
                if (!toggle) return;
                var body = toggle.closest(".bhcore-notif-prefs-body");
                var status = body ? body.querySelector(".bhcore-notif-prefs-status") : null;
                if (status) status.textContent = "Saving…";
                fetch(BHCoreAjax.ajaxUrl, {
                    method: "POST",
                    body: new URLSearchParams({
                        action: "bhcore_save_notification_pref",
                        nonce: body ? body.dataset.nonce : "",
                        type: toggle.dataset.type,
                        wants: toggle.checked ? "1" : "",
                    }),
                }).then(function (r) { return r.json(); }).then(function (res) {
                    if (!status) return;
                    status.textContent = (res && res.success) ? "Saved." : "Could not save — try again.";
                    setTimeout(function () { status.textContent = ""; }, 2000);
                }).catch(function () {
                    if (status) status.textContent = "Could not reach the server — try again.";
                });
            });
        ');
    }

    public static function ajax_mark_read() {
        check_ajax_referer('bhcore_notifications', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error([], 401);
        self::mark_read($user_id, (int) ($_POST['notification_id'] ?? 0));
        wp_send_json_success();
    }

    public static function ajax_mark_all_read() {
        check_ajax_referer('bhcore_notifications', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error([], 401);
        self::mark_all_read($user_id);
        wp_send_json_success();
    }

    // Only ever writes the ONE type being toggled — reads the user's
    // existing prefs map first rather than replacing it wholesale, so
    // toggling one type in a fresh page load can't accidentally clobber
    // a preference set from a different browser tab/session.
    public static function ajax_save_pref() {
        check_ajax_referer('bhcore_notifications', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error([], 401);

        $type = sanitize_key($_POST['type'] ?? '');
        if (!$type) wp_send_json_error(['message' => 'Missing notification type.'], 400);
        $wants = !empty($_POST['wants']);

        $prefs = get_user_meta($user_id, 'bhcore_notification_email_prefs', true);
        if (!is_array($prefs)) $prefs = [];
        $prefs[$type] = $wants;
        update_user_meta($user_id, 'bhcore_notification_email_prefs', $prefs);

        wp_send_json_success();
    }

    public static function register_test_suite($suites) {
        $suites['own-ur-shit-notifications'] = ['label' => 'Own Ur Shit (Notifications)', 'callback' => [self::class, 'run_tests']];
        return $suites;
    }

    // The one piece of real branching logic in this pass worth pinning
    // down: default-on for an absent/never-toggled type, per-type
    // toggle respected once set, and the full opt-out overriding
    // everything regardless of any per-type preference. Runs against a
    // real, tagged fixture user, cleaned up afterward.
    public static function run_tests() {
        if (!class_exists('OUS_TestRunner') || !class_exists('OUS_Debug')) return [];
        $rows = [];

        $uid = OUS_Debug::get_or_create_test_user('ous_notifications_suite', false);
        delete_user_meta($uid, 'bhcore_notification_email_prefs');
        delete_user_meta($uid, 'bhcore_notifications_email_optout');

        $rows[] = OUS_TestRunner::assert_true(self::type_wants_email($uid, 'course_completed'), 'type_wants_email(): a type never toggled defaults to on');

        update_user_meta($uid, 'bhcore_notification_email_prefs', ['course_completed' => false]);
        $rows[] = OUS_TestRunner::assert_false(self::type_wants_email($uid, 'course_completed'), 'type_wants_email(): an explicitly-off type is respected');
        $rows[] = OUS_TestRunner::assert_true(self::type_wants_email($uid, 'order_refunded'), 'type_wants_email(): a DIFFERENT type not present in the prefs map still defaults to on');

        $rows[] = OUS_TestRunner::assert_false(self::user_wants_email($uid, 'course_completed'), 'user_wants_email(): the per-type off preference is honored when not globally opted out');
        $rows[] = OUS_TestRunner::assert_true(self::user_wants_email($uid, 'order_refunded'), 'user_wants_email(): a type set to on (by default) still emails when not globally opted out');

        update_user_meta($uid, 'bhcore_notifications_email_optout', '1');
        $rows[] = OUS_TestRunner::assert_false(self::user_wants_email($uid, 'order_refunded'), 'user_wants_email(): the global opt-out overrides an otherwise-on per-type preference');

        $rows[] = OUS_TestRunner::assert_same('Course completed', self::type_label('course_completed'), 'type_label(): an unregistered type humanizes its own raw string');

        // Cleanup.
        delete_user_meta($uid, 'bhcore_notification_email_prefs');
        delete_user_meta($uid, 'bhcore_notifications_email_optout');

        return $rows;
    }
}
