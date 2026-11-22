<?php
if (!defined('ABSPATH')) exit;

/**
 * [bht_support]: the contact form for a Support page. The FAQ is the page's own content,
 * written above the shortcode. Signed-in messages become tickets; guest messages notify
 * ticket staff directly (a ticket needs an account).
 */
class BHT_Support {
    const GUEST_LIMIT = 3;            // guest messages per IP per hour
    const MAX_LENGTH = 5000;

    public static function init(): void {
        add_shortcode('bht_support', [self::class, 'render']);
        add_action('admin_post_bht_support_message', [self::class, 'handle']);
        add_action('admin_post_nopriv_bht_support_message', [self::class, 'handle']);
    }

    /** @param mixed $atts */
    public static function render($atts = []): string {
        // Enqueued from the shortcode so it loads wherever the form appears (block, Etch, widget).
        wp_enqueue_style('bht-support', BHT_URL . 'assets/css/support.css', [], BHT_VER);
        $logged_in = is_user_logged_in();
        $status = isset($_GET['bht_support']) ? sanitize_key(wp_unslash($_GET['bht_support'])) : '';
        $messages = [
            'sent' => BHT_Words::get($logged_in ? 'support.sent_member' : 'support.sent_guest'),
            'invalid' => BHT_Words::get('support.invalid'),
            'limit' => BHT_Words::get('support.limit'),
            'error' => BHT_Words::get('support.error'),
        ];

        ob_start();
        echo '<div class="bht-support" id="bht-support">';
        echo '<h2>' . BHT_Words::e('support.heading') . '</h2>';
        if (isset($messages[$status])) {
            echo '<p class="bht-support-result" role="status">' . esc_html($messages[$status]) . '</p>';
        }
        echo '<form class="bht-support-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="bht_support_message">';
        echo '<input type="hidden" name="return_to" value="' . esc_attr((string) get_permalink()) . '">';
        wp_nonce_field('bht_support_message');
        if (!$logged_in) {
            echo '<p><label>' . BHT_Words::e('support.name') . '<br><input type="text" name="name" maxlength="100" required autocomplete="name"></label></p>';
            echo '<p><label>' . BHT_Words::e('support.email') . '<br><input type="email" name="email" maxlength="200" required autocomplete="email"></label></p>';
            // WHY: honeypot; hidden from people, filled in by most bots.
            echo ('<p class="bht-support-hp" aria-hidden="true" style="position:absolute;left:-9999px;"><label>' . BHT_Words::get('admin.support.website') . '<input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>');
        }
        echo '<p><label>' . BHT_Words::e('support.subject') . '<br><input type="text" name="subject" maxlength="150" required></label></p>';
        echo '<p><label>' . BHT_Words::e('support.message') . '<br><textarea name="body" rows="6" maxlength="' . self::MAX_LENGTH . '" required></textarea></label></p>';
        echo '<p><button type="submit" class="button">' . BHT_Words::e('support.send') . '</button></p>';
        echo '</form>';
        if ($logged_in) {
            echo '<p class="bht-support-tickets"><a href="' . esc_url(add_query_arg('panel', 'tickets', home_url('/account/'))) . '">' . BHT_Words::e('support.past_messages') . '</a></p>';
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    public static function handle(): void {
        $back = wp_validate_redirect(esc_url_raw(wp_unslash($_POST['return_to'] ?? '')), home_url('/'));
        $done = function (string $status) use ($back): void {
            wp_safe_redirect(add_query_arg('bht_support', $status, $back) . '#bht-support');
            exit;
        };

        if (!wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'bht_support_message')) $done('error');
        $origin_ok = !class_exists('BHI_Auth') || BHI_Auth::is_same_origin_request($_SERVER['HTTP_ORIGIN'] ?? null, $_SERVER['HTTP_REFERER'] ?? null);
        if (!$origin_ok) wp_die(BHT_Words::get('admin.support.request_must_come_from_site'), '', ['response' => 403]);

        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
        $body = trim(mb_substr(sanitize_textarea_field(wp_unslash($_POST['body'] ?? '')), 0, self::MAX_LENGTH));
        if ($subject === '' || $body === '') $done('invalid');
        $html_body = nl2br(esc_html($body));

        $user_id = get_current_user_id();
        if ($user_id) {
            $result = BHT_Tickets::create($user_id, $subject, $html_body, 'support');
            $done(is_wp_error($result) ? 'error' : 'sent');
        }

        // Guests: pretend success to bots so the honeypot isn't revealed.
        if (!empty($_POST['website'])) $done('sent');
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if ($name === '' || !is_email($email)) $done('invalid');

        $key = 'bht_support_ip_' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $count = (int) get_transient($key);
        if ($count >= self::GUEST_LIMIT) $done('limit');
        set_transient($key, $count + 1, HOUR_IN_SECONDS);

        self::notify_staff($name, $email, $subject, $html_body);
        $done('sent');
    }

    private static function notify_staff(string $name, string $email, string $subject, string $html_body): void {
        if (!class_exists('OUS_Notifications')) return;
        $from = esc_html($name) . ' (<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>)';
        foreach (get_users(['capability' => 'bhcore_manage_tickets', 'fields' => 'ID']) as $staff_id) {
            OUS_Notifications::notify((int) $staff_id, 'support_message', BHT_Words::get('admin.support.support_message') . ' ' . $subject,
                $from . (' ' . BHT_Words::get('admin.support.wrote_no_account_reply_by') . '<br><br>') . $html_body, '', BHT_Words::get('admin.replies.bh_tickets'));
        }
    }
}
