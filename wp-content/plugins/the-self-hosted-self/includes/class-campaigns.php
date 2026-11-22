<?php
if (!defined('ABSPATH')) exit;

/**
 * Email broadcasts ("email everyone in segment X"); one-to-one emails are OUS_Notifications.
 * Segments are live queries added via bhcore_campaign_segments; sending is one OUS_Jobs
 * job per recipient.
 */
class OUS_Campaigns {
    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_filter('bhcore_campaign_segments', [self::class, 'register_builtin_segment']);
        add_filter('bhcore_test_suites', [self::class, 'register_test_suite']);
        add_action('init', function () {
            if (class_exists('OUS_Jobs')) {
                OUS_Jobs::register('bhcore_send_campaign_email', [self::class, 'send_one']);
            }
        });
    }

    /**
     * @param array<string, mixed> $segments
     * @return array<string, mixed>
     */
    public static function register_builtin_segment($segments): array {
        $segments['all_subscribers'] = [
            'label' => OUS_Words::get('admin.campaigns.everyone_with_account'),
            'user_ids' => function () {
                return get_users(['fields' => 'ID']);
            },
        ];
        return $segments;
    }

    /** @return array<string, mixed> */
    public static function segments(): array {
        return apply_filters('bhcore_campaign_segments', []);
    }

    private static function table(): string {
        return OUS_Tables::campaigns();
    }

    public static function add_menu(): void {
        // WHY: stays under the 'ous' parent; submenus under 'ous-debug' have
        // hit get_plugin_page_hook() failures.
        add_submenu_page('ous', 'Campaigns', 'Campaigns', 'manage_options', 'ous-campaigns', [self::class, 'render']);
    }

    private static function create(string $subject, string $body, string $segment_key, int $created_by): int {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'subject' => sanitize_text_field($subject),
            'body' => wp_kses_post($body),
            'segment_key' => sanitize_key($segment_key),
            'status' => 'draft',
            'created_by' => $created_by,
        ]);
        return (int) $wpdb->insert_id;
    }

    // Recipients are computed here at send time and never stored.
    /** @return true|\WP_Error */
    public static function send_now(int $campaign_id) {
        global $wpdb;
        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $campaign_id), ARRAY_A);
        if (!$campaign || $campaign['status'] !== 'draft') return new WP_Error('bad_state', OUS_Words::get('admin.campaigns.campaign_has_already_been_sent'));

        $segments = self::segments();
        $segment = $segments[$campaign['segment_key']] ?? null;
        if (!$segment || !is_callable($segment['user_ids'])) return new WP_Error('bad_segment', OUS_Words::get('admin.campaigns.segment_no_longer_exists'));

        $user_ids = array_unique(array_map('intval', call_user_func($segment['user_ids'])));
        $user_ids = array_filter($user_ids);

        $wpdb->update(self::table(), [
            'status' => 'sending', 'total_recipients' => count($user_ids), 'sent_at' => current_time('mysql'),
        ], ['id' => $campaign_id]);

        if (!$user_ids) {
            $wpdb->update(self::table(), ['status' => 'sent'], ['id' => $campaign_id]);
            return true;
        }

        if (class_exists('OUS_Jobs')) {
            foreach ($user_ids as $user_id) {
                OUS_Jobs::enqueue('bhcore_send_campaign_email', ['campaign_id' => $campaign_id, 'user_id' => $user_id]);
            }
        } else {
            // No job queue: send inline rather than drop an explicit send.
            foreach ($user_ids as $user_id) {
                self::send_one(['campaign_id' => $campaign_id, 'user_id' => $user_id]);
            }
        }
        return true;
    }

    // Per-recipient job. Honors the global email opt-out flag shared with
    // OUS_Notifications.
    /** @param array<string, mixed> $args */
    public static function send_one($args): void {
        global $wpdb;
        $campaign_id = (int) ($args['campaign_id'] ?? 0);
        $user_id = (int) ($args['user_id'] ?? 0);
        if (!$campaign_id || !$user_id) return;

        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $campaign_id), ARRAY_A);
        if (!$campaign) return;

        $opted_out = get_user_meta($user_id, 'bhcore_notifications_email_optout', true);
        if (!$opted_out) {
            if (class_exists('BH_Mail')) {
                BH_Mail::send([
                    'user_id' => $user_id, 'subject' => $campaign['subject'],
                    'body' => wpautop(wp_kses_post($campaign['body'])), 'html' => true,
                    'source' => OUS_Words::get('admin.campaigns.ous_campaigns'), 'log_context' => ['campaign_id' => $campaign_id],
                ]);
            } else {
                $user = get_userdata($user_id);
                if ($user && $user->user_email) {
                    wp_mail($user->user_email, $campaign['subject'], wpautop(wp_kses_post($campaign['body'])), ['Content-Type: text/html; charset=UTF-8']);
                }
            }
        }

        // Counts "processed", not "delivered", so opted-out users still let
        // the run reach total_recipients.
        $wpdb->query($wpdb->prepare("UPDATE " . self::table() . " SET sent_count = sent_count + 1 WHERE id = %d", $campaign_id));

        $updated = $wpdb->get_row($wpdb->prepare("SELECT sent_count, total_recipients FROM " . self::table() . " WHERE id = %d", $campaign_id), ARRAY_A);
        if ($updated && (int) $updated['sent_count'] >= (int) $updated['total_recipients']) {
            $wpdb->update(self::table(), ['status' => 'sent'], ['id' => $campaign_id]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function all_campaigns(): array {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM " . self::table() . " ORDER BY id DESC", ARRAY_A);
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) return;

        $message = '';
        if (isset($_POST['ous_campaign_action']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ous_campaign_nonce'] ?? '')), 'ous_campaign_save')) {
            $subject = wp_unslash($_POST['subject'] ?? '');
            $body = wp_unslash($_POST['body'] ?? '');
            $segment_key = wp_unslash($_POST['segment_key'] ?? '');
            if (trim($subject) === '' || trim($body) === '') {
                $message = OUS_Words::get('admin.campaigns.subject_body_are_both_required');
            } elseif (!isset(self::segments()[$segment_key])) {
                $message = OUS_Words::get('admin.campaigns.pick_valid_segment');
            } else {
                $id = self::create($subject, $body, $segment_key, get_current_user_id());
                $message = OUS_Words::get('admin.campaigns.campaign_saved_as_draft');
                if (isset($_POST['ous_campaign_send_now'])) {
                    $result = self::send_now($id);
                    $message = is_wp_error($result) ? $result->get_error_message() : OUS_Words::get('admin.campaigns.campaign_sending_now');
                }
            }
        } elseif (isset($_POST['ous_campaign_send_id']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ous_campaign_send_nonce'] ?? '')), 'ous_campaign_send')) {
            $result = self::send_now((int) $_POST['ous_campaign_send_id']);
            $message = is_wp_error($result) ? $result->get_error_message() : OUS_Words::get('admin.campaigns.campaign_sending_now');
        }

        echo ('<div class="wrap"><h1>' . OUS_Words::get('admin.campaigns.campaigns') . '</h1>');
        if ($message) echo '<div class="notice notice-info"><p>' . esc_html($message) . '</p></div>';

        echo ('<h2>' . OUS_Words::get('admin.campaigns.new_campaign') . '</h2>');
        echo '<form method="post">';
        wp_nonce_field('ous_campaign_save', 'ous_campaign_nonce');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="segment_key">Send to</label></th><td><select name="segment_key" id="segment_key">';
        foreach (self::segments() as $key => $segment) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($segment['label']) . '</option>';
        }
        echo ('</select><p class="description">' . OUS_Words::get('admin.campaigns.evaluated_live_right_when_send') . '</p></td></tr>');
        echo ('<tr><th><label for="subject">' . OUS_Words::get('admin.campaigns.subject') . '</label></th><td><input type="text" class="regular-text" name="subject" id="subject" required></td></tr>');
        echo ('<tr><th><label for="body">' . OUS_Words::get('admin.campaigns.message') . '</label></th><td><textarea name="body" id="body" rows="8" class="large-text" required></textarea></td></tr>');
        echo '</tbody></table>';
        echo ('<p><button type="submit" name="ous_campaign_action" value="save" class="button">' . OUS_Words::get('admin.campaigns.save_as_draft') . '</button> ');
        echo ('<button type="submit" name="ous_campaign_send_now" value="1" class="button button-primary" onclick="return confirm(\'Send this campaign now to everyone currently in that segment?\');">' . OUS_Words::get('admin.campaigns.save_send_now') . '</button></p>');
        echo '</form>';

        echo ('<h2>' . OUS_Words::get('admin.campaigns.past_campaigns') . '</h2>');
        $campaigns = self::all_campaigns();
        if (!$campaigns) {
            echo ('<p>' . OUS_Words::get('admin.campaigns.no_campaigns_yet') . '</p>');
        } else {
            echo ('<table class="widefat striped"><thead><tr><th>' . OUS_Words::get('admin.campaigns.subject') . '</th><th>' . OUS_Words::get('admin.campaigns.segment') . '</th><th>' . OUS_Words::get('admin.campaigns.status') . '</th><th>' . OUS_Words::get('admin.campaigns.recipients') . '</th><th>' . OUS_Words::get('admin.campaigns.sent') . '</th><th></th></tr></thead><tbody>');
            $segments = self::segments();
            foreach ($campaigns as $c) {
                echo '<tr>';
                echo '<td>' . esc_html($c['subject']) . '</td>';
                echo '<td>' . esc_html($segments[$c['segment_key']]['label'] ?? $c['segment_key']) . '</td>';
                echo '<td>' . esc_html($c['status']) . '</td>';
                echo '<td>' . (int) $c['total_recipients'] . '</td>';
                echo '<td>' . (int) $c['sent_count'] . '</td>';
                echo '<td>';
                if ($c['status'] === 'draft') {
                    echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Send this campaign now?\');">';
                    wp_nonce_field('ous_campaign_send', 'ous_campaign_send_nonce');
                    echo '<input type="hidden" name="ous_campaign_send_id" value="' . (int) $c['id'] . '">';
                    echo '<button type="submit" class="button button-small">Send now</button>';
                    echo '</form>';
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        echo '</div>';
    }

    /**
     * @param array<string, mixed> $suites
     * @return array<string, mixed>
     */
    public static function register_test_suite($suites): array {
        $suites['the-self-hosted-self-campaigns'] = ['label' => OUS_Words::get('admin.campaigns.self_hosted_self_campaigns'), 'callback' => [self::class, 'run_tests']];
        return $suites;
    }

    /** @return array<int, mixed> */
    public static function run_tests(): array {
        if (!class_exists('OUS_Debug')) return [['name' => OUS_Words::get('admin.campaigns.ous_debug_not_loaded'), 'pass' => false, 'message' => OUS_Words::get('admin.campaigns.skipped')]];
        global $wpdb;
        $rows = [];

        $recipient = OUS_Debug::get_or_create_test_user('ous_campaign_suite_recipient', false);
        delete_user_meta($recipient, 'bhcore_notifications_email_optout');

        // Test-only segment, proving the filter extension point end to end.
        $add_segment = function ($segments) use ($recipient) {
            $segments['ous_campaign_suite_segment'] = [
                'label' => 'Test segment',
                'user_ids' => function () use ($recipient) { return [$recipient]; },
            ];
            return $segments;
        };
        add_filter('bhcore_campaign_segments', $add_segment);

        $campaign_id = self::create(OUS_Words::get('admin.campaigns.test_subject'), OUS_Words::get('admin.campaigns.test_body'), 'ous_campaign_suite_segment', $recipient);
        $rows[] = OUS_TestRunner::assert_true($campaign_id > 0, 'create() inserts a new campaign row and returns its id');

        $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $campaign_id), ARRAY_A);
        $rows[] = OUS_TestRunner::assert_same('draft', $before['status'], OUS_Words::get('admin.campaigns.new_campaign_starts_as_draft'));

        $result = self::send_now($campaign_id);
        $rows[] = OUS_TestRunner::assert_true($result === true, 'send_now() succeeds against a real registered segment');

        $enqueued = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $campaign_id), ARRAY_A);
        $rows[] = OUS_TestRunner::assert_same(1, (int) $enqueued['total_recipients'], 'send_now() resolves the live segment to exactly 1 recipient');
        $rows[] = OUS_TestRunner::assert_same('sending', $enqueued['status'], 'send_now() only ENQUEUES the per-recipient job and moves to "sending" — it never sends synchronously in the request that clicked Send');

        // send_one() is called directly to test its contract without
        // depending on the queue draining in this request.
        self::send_one(['campaign_id' => $campaign_id, 'user_id' => $recipient]);
        $after = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $campaign_id), ARRAY_A);
        $rows[] = OUS_TestRunner::assert_same('sent', $after['status'], OUS_Words::get('admin.campaigns.status_becomes_sent_once_every'));
        $rows[] = OUS_TestRunner::assert_same(1, (int) $after['sent_count'], 'sent_count reflects exactly one processed recipient');

        $resend = self::send_now($campaign_id);
        $rows[] = OUS_TestRunner::assert_true(is_wp_error($resend), 'send_now() on an already-sent campaign is rejected, not silently resent');

        // Opt-out: can't assert wp_mail() was skipped without a mock, so
        // assert the counting contract instead.
        update_user_meta($recipient, 'bhcore_notifications_email_optout', '1');
        $campaign_id_2 = self::create(OUS_Words::get('admin.campaigns.test_subject_2'), OUS_Words::get('admin.campaigns.test_body_2'), 'ous_campaign_suite_segment', $recipient);
        self::send_now($campaign_id_2);
        self::send_one(['campaign_id' => $campaign_id_2, 'user_id' => $recipient]);
        $after2 = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $campaign_id_2), ARRAY_A);
        $rows[] = OUS_TestRunner::assert_same('sent', $after2['status'], OUS_Words::get('admin.campaigns.opted_out_recipient_still_counted'));
        delete_user_meta($recipient, 'bhcore_notifications_email_optout');

        remove_filter('bhcore_campaign_segments', $add_segment);
        $wpdb->delete(self::table(), ['id' => $campaign_id]);
        $wpdb->delete(self::table(), ['id' => $campaign_id_2]);

        return $rows;
    }
}
