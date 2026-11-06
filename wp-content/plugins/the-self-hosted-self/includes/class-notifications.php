<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared notification inbox: any plugin calls notify() with no registration step. In-app rows
 * feed the admin-bar bell and the `[bh_notifications]` shortcode; optional email is queued
 * through OUS_Jobs so notify() never blocks on SMTP.
 *
 *   OUS_Notifications::notify($user_id, 'course_completed', 'Course complete!',
 *       'You finished "Songwriting Fundamentals".', get_permalink($course_id), 'BH Courses');
 */
class OUS_Notifications {
    public static function init(): void {
        add_action('init', [self::class, 'register_shortcode']);
        add_action('admin_bar_menu', [self::class, 'admin_bar'], 90);
        add_action('wp_ajax_bhcore_mark_notification_read', [self::class, 'ajax_mark_read']);
        add_action('wp_ajax_bhcore_mark_all_notifications_read', [self::class, 'ajax_mark_all_read']);
        add_action('wp_ajax_bhcore_save_notification_pref', [self::class, 'ajax_save_pref']);
        add_filter('bhcore_test_suites', [self::class, 'register_test_suite']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
        add_action('admin_enqueue_scripts', [self::class, 'maybe_enqueue']);

        // Portal panel reuses render_shortcode(); the portal shell calls wp_head(), so maybe_enqueue() already runs there.
        add_filter('bhi_portal_panels', [self::class, 'register_portal_panel']);

        // Job handler for queued emails, registered here where the job type is defined.
        add_action('init', function () {
            if (class_exists('OUS_Jobs')) {
                OUS_Jobs::register('bhcore_send_notification_email', [self::class, 'send_queued_email']);
            }
        });
    }

    private static function table(): string {
        return OUS_Tables::notifications();
    }

    // Size cap: cheap COUNT+DELETE on roughly 1 in 50 writes.
    const MAX_ROWS = 20000;

    private static function maybe_trim(): void {
        if (wp_rand(1, 50) !== 1) return;
        global $wpdb;
        $table = self::table();
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        if ($count > self::MAX_ROWS) {
            $excess = $count - self::MAX_ROWS;
            $wpdb->query($wpdb->prepare("DELETE FROM $table ORDER BY id ASC LIMIT %d", $excess));
        }
    }

    /**
     * $email also queues an email (respecting user_wants_email()). $source is a cosmetic label
     * only, never used for routing.
     */
    public static function notify(int $user_id, string $type, string $title, string $body = '', string $url = '', string $source = '', bool $email = true): int {
        if (!$user_id) return 0;
        global $wpdb;
        $inserted = $wpdb->insert(self::table(), [
            'user_id' => $user_id, 'type' => sanitize_key($type), 'source' => sanitize_text_field($source),
            'title' => sanitize_text_field($title), 'body' => wp_kses_post($body), 'url' => esc_url_raw($url),
        ]);
        // Stop before queuing an email for a row that was never inserted.
        if (!$inserted) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('warning', 'OUS_Notifications::notify() insert failed: ' . $wpdb->last_error, ['user_id' => $user_id, 'type' => $type], 'OUS_Notifications');
            }
            return 0;
        }
        $id = (int) $wpdb->insert_id;
        self::maybe_trim();

        if ($email && self::user_wants_email($user_id, $type)) {
            if (class_exists('OUS_Jobs')) {
                OUS_Jobs::enqueue('bhcore_send_notification_email', ['notification_id' => $id, 'user_id' => $user_id, 'title' => $title, 'body' => $body, 'url' => $url]);
            } else {
                // No job queue: send inline rather than drop the email.
                self::send_email_now($user_id, $title, $body, $url);
            }
        }
        return $id;
    }

    // The profile-level email opt-out overrides everything (in-app always happens); then
    // per-type prefs via type_wants_email().
    public static function user_wants_email(int $user_id, string $type): bool {
        $opt_out = get_user_meta($user_id, 'bhcore_notifications_email_optout', true);
        $wants = $opt_out ? false : self::type_wants_email($user_id, $type);
        return apply_filters('bhcore_notification_should_email', $wants, $user_id, $type);
    }

    // A type with no stored preference defaults to on.
    public static function type_wants_email(int $user_id, string $type): bool {
        $prefs = get_user_meta($user_id, 'bhcore_notification_email_prefs', true);
        if (!is_array($prefs) || !array_key_exists($type, $prefs)) return true;
        return (bool) $prefs[$type];
    }

    /**
     * Notification types this user has actually received (only those get a preference toggle).
     * Labels come from the `bhcore_notification_type_labels` filter, else a humanized type string.
     *
     * @return array<int, string>
     */
    public static function distinct_types_for_user(int $user_id): array {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT type FROM " . self::table() . " WHERE user_id = %d ORDER BY type ASC", $user_id
        ));
    }

    public static function type_label(string $type): string {
        $labels = apply_filters('bhcore_notification_type_labels', []);
        if (isset($labels[$type])) return $labels[$type];
        return ucfirst(str_replace(['_', '-'], ' ', $type));
    }

    /**
     * WHY: the same queued job can fire twice, so the notification row is claimed atomically
     * before mailing. Jobs without notification_id just send once with no dedup.
     *
     * @param array<string, mixed> $args
     */
    public static function send_queued_email($args): void {
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

    private static function send_email_now(int $user_id, string $title, string $body, string $url): void {
        $message = wp_strip_all_tags($body);
        if ($url) $message .= "\n\n" . $url;

        // Falls back to raw wp_mail() only if BH_Mail isn't loaded.
        if (class_exists('BH_Mail')) {
            BH_Mail::send([
                'user_id' => $user_id,
                'subject' => wp_specialchars_decode($title),
                'body' => $message,
                'source' => 'OUS Notifications',
                'log_context' => ['title' => $title],
            ]);
            return;
        }

        $user = get_userdata($user_id);
        if (!$user || !$user->user_email) return;
        wp_mail($user->user_email, wp_specialchars_decode($title), $message);
    }

    /* ---------------- reading ---------------- */

    public static function unread_count(int $user_id): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d AND read_at IS NULL", $user_id
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public static function for_user(int $user_id, int $limit = 20): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE user_id = %d ORDER BY created_at DESC LIMIT %d", $user_id, $limit
        ), ARRAY_A);
    }

    public static function mark_read(int $user_id, int $notification_id): void {
        global $wpdb;
        $wpdb->update(self::table(), ['read_at' => current_time('mysql')], ['id' => $notification_id, 'user_id' => $user_id]);
    }

    public static function mark_all_read(int $user_id): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET read_at = %s WHERE user_id = %d AND read_at IS NULL", current_time('mysql'), $user_id
        ));
    }

    /* ---------------- UI: admin bar bell (wp-admin and front end) ---------------- */

    public static function admin_bar(\WP_Admin_Bar $wp_admin_bar): void {
        if (!is_user_logged_in()) return;
        $user_id = get_current_user_id();
        $count = self::unread_count($user_id);

        $wp_admin_bar->add_node([
            'id' => 'bhcore-notifications',
            // WHY: a dashicon .ab-icon span, not an emoji — WP rewrites emoji text into a raster <img>
            // that doesn't match core toolbar icons. Glyph comes from the .ab-icon:before rule below.
            'title' => '<span class="ab-icon" aria-hidden="true"></span>' . ($count ? ' <span class="bhcore-notif-count">' . (int) $count . '</span>' : ''),
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

    public static function register_shortcode(): void {
        add_shortcode('bh_notifications', [self::class, 'render_shortcode']);
    }

    /**
     * @param array<int, array<string, mixed>> $panels
     * @return array<int, array<string, mixed>>
     */
    public static function register_portal_panel($panels): array {
        $panels[] = [
            'id' => 'notifications',
            'label' => 'Notifications',
            'icon' => 'dashicons-bell',
            'render' => [self::class, 'render_portal_panel'],
            'priority' => 50,
        ];
        return $panels;
    }

    public static function render_portal_panel(): void {
        echo '<h1>Notifications</h1>';
        echo self::render_shortcode(); // phpcs:ignore -- render_shortcode() already returns fully-escaped markup
    }

    // Closed <details> disclosure; renders nothing for a user who has never received a notification.
    private static function render_email_prefs(int $user_id): string {
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

    public static function render_shortcode(): string {
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

    // WHY: our own no-source handle rather than core's 'admin-bar', which isn't enqueued when the
    // toolbar is hidden; the shortcode's mark-all-read must work regardless.
    public static function maybe_enqueue(): void {
        if (!is_user_logged_in()) return;

        wp_register_style('bhcore-notifications', false);
        wp_enqueue_style('bhcore-notifications');
        // --bh-* tokens with the admin-bar colors as fallbacks; this markup renders in wp-admin and the portal.
        wp_add_inline_style('bhcore-notifications', '
            .bhcore-notif-count { background: #d63638; color: #fff; border-radius: 9px; font-size: 11px; padding: 0 5px; margin-left: 2px; }
            .bhcore-notifications-list { max-width: 600px; }
            .bhcore-notification { border: 1px solid var(--bh-border, #dcdcde); border-radius: var(--bh-radius-sm, 6px); padding: 10px 14px; margin-bottom: 8px; background: var(--bh-surface, #fff); }
            /* Unread used --bh-accent-soft as a flat fill. Despite the name that
               token is a solid, fairly saturated color (#E0A184 on the live
               warm-noir theme), not a tint -- so the card turned into a light
               coral block while every text token on it (--bh-text,
               --bh-text-dim) stayed the theme DARK-mode foreground. Measured
               on a real phone: the whole unread card was light-on-light and
               effectively unreadable. --bh-accent-muted-bg is the low-alpha
               treatment class-portal.php already switched its active nav item
               to for exactly this reason; same fix, same token. */
            .bhcore-notification.bhcore-unread { background: var(--bh-accent-muted-bg, color-mix(in srgb, var(--bh-accent, #72aee6) 14%, transparent)); border-color: var(--bh-accent, #72aee6); }
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

    public static function ajax_mark_read(): void {
        check_ajax_referer('bhcore_notifications', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error([], 401);
        self::mark_read($user_id, (int) ($_POST['notification_id'] ?? 0));
        wp_send_json_success();
    }

    public static function ajax_mark_all_read(): void {
        check_ajax_referer('bhcore_notifications', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error([], 401);
        self::mark_all_read($user_id);
        wp_send_json_success();
    }

    // Read-modify-write of only the toggled type, so another tab's settings aren't clobbered.
    public static function ajax_save_pref(): void {
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

    /**
     * @param array<string, mixed> $suites
     * @return array<string, mixed>
     */
    public static function register_test_suite($suites): array {
        $suites['the-self-hosted-self-notifications'] = ['label' => 'The Self-Hosted Self (Notifications)', 'callback' => [self::class, 'run_tests']];
        return $suites;
    }

    /**
     * Default-on for untouched types, per-type toggle respected, full opt-out overrides all.
     * Uses a tagged fixture user, cleaned up afterward.
     *
     * @return array<int, mixed>
     */
    public static function run_tests(): array {
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

        delete_user_meta($uid, 'bhcore_notification_email_prefs');
        delete_user_meta($uid, 'bhcore_notifications_email_optout');

        return $rows;
    }
}
