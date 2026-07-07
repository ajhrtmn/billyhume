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
                OUS_Jobs::enqueue('bhcore_send_notification_email', ['user_id' => $user_id, 'title' => $title, 'body' => $body, 'url' => $url]);
            } else {
                // No job queue active for some reason (shouldn't happen —
                // same plugin, same activation — but fail toward "still
                // deliver the email" rather than silently dropping it).
                self::send_email_now($user_id, $title, $body, $url);
            }
        }
        return $id;
    }

    // A user can opt out of EMAIL entirely (in-app notifications always
    // still happen — that's just this plugin's own inbox, not an
    // external send) via a simple user meta checkbox on their profile.
    // No per-TYPE granularity in v1 — deliberately simple; a future
    // preferences UI can add that without changing this call site.
    public static function user_wants_email($user_id, $type) {
        $opt_out = get_user_meta($user_id, 'bhcore_notifications_email_optout', true);
        return apply_filters('bhcore_notification_should_email', !$opt_out, $user_id, $type);
    }

    public static function send_queued_email($args) {
        self::send_email_now((int) ($args['user_id'] ?? 0), $args['title'] ?? '', $args['body'] ?? '', $args['url'] ?? '');
    }

    private static function send_email_now($user_id, $title, $body, $url) {
        $user = get_userdata($user_id);
        if (!$user || !$user->user_email) return;
        $message = wp_strip_all_tags($body);
        if ($url) $message .= "\n\n" . $url;
        wp_mail($user->user_email, wp_specialchars_decode($title), $message);
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

    public static function render_shortcode() {
        if (!is_user_logged_in()) return '<p class="bhcore-notif-empty">Log in to see your notifications.</p>';
        $user_id = get_current_user_id();
        $items = self::for_user($user_id, 30);
        if (!$items) return '<p class="bhcore-notif-empty">Nothing here yet.</p>';

        ob_start();
        echo '<div class="bhcore-notifications-list">';
        echo '<button type="button" class="bhcore-btn bhcore-mark-all-read" data-nonce="' . esc_attr(wp_create_nonce('bhcore_notifications')) . '">Mark all read</button>';
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
        wp_add_inline_style('bhcore-notifications', '
            .bhcore-notif-count { background: #d63638; color: #fff; border-radius: 9px; font-size: 11px; padding: 0 5px; margin-left: 2px; }
            .bhcore-notifications-list { max-width: 600px; }
            .bhcore-notification { border: 1px solid #dcdcde; border-radius: 6px; padding: 10px 14px; margin-bottom: 8px; }
            .bhcore-notification.bhcore-unread { background: #f0f6fc; border-color: #72aee6; }
            .bhcore-notif-source { font-size: 11px; text-transform: uppercase; color: #646970; letter-spacing: .04em; }
            .bhcore-notif-title { font-weight: 600; }
            .bhcore-notif-body { font-size: 13px; color: #50575e; }
            .bhcore-notif-time { font-size: 11px; color: #8c8f94; margin-top: 4px; }
            .bhcore-btn.bhcore-mark-all-read { margin-bottom: 10px; }
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
                    fetch(BHCoreAjax.ajaxUrl, {
                        method: "POST",
                        body: new URLSearchParams({ action: "bhcore_mark_all_notifications_read", nonce: markAll.dataset.nonce })
                    }).then(function () { document.querySelectorAll(".bhcore-notification.bhcore-unread").forEach(function (el) { el.classList.remove("bhcore-unread"); }); });
                }
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
}
