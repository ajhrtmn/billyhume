<?php
if (!defined('ABSPATH')) exit;

/**
 * Ecosystem depth-pass Tier 2 — email broadcast/campaigns, the last of
 * the four marketing gaps VISION.md named. OUS_Notifications' email
 * channel is one-to-one/event-triggered (a course finished, an order
 * refunded) — wrong shape for "email everyone in tier X," which is what
 * this class is for.
 *
 * Segments are LIVE queries, evaluated at send time, not a snapshot list
 * frozen at creation — same "authoritative source, no denormalized
 * counter that can drift" convention this ecosystem already uses for
 * enrollment counts and wallet balances (AJ's explicit call on this one,
 * ecosystem depth-pass Tier 2). A campaign scheduled for later sends to
 * whoever is ACTUALLY in the segment when it goes out.
 *
 * Segments are zero-central-registration, same shape as
 * `bhi_portal_panels`/`bhcore_metrics_widgets`: any plugin adds one via
 * the `bhcore_campaign_segments` filter —
 *
 *     add_filter('bhcore_campaign_segments', function ($segments) {
 *         $segments['my_segment'] = [
 *             'label' => 'People who did X',
 *             'user_ids' => function () { return [...]; },
 *         ];
 *         return $segments;
 *     });
 *
 * Sending reuses OUS_Jobs (one job per recipient) rather than a
 * synchronous mail loop in the request that clicks "Send" — the exact
 * bug class this session's own event-tracking work already fixed once
 * in bh-contest.
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
            'label' => 'Everyone with an account',
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
        global $wpdb;
        return $wpdb->prefix . 'bhcore_campaigns';
    }

    public static function add_menu(): void {
        // Same working parent ('own-ur-shit') the Roles/Metrics/Security
        // submenus already use without issue — VISION.md's own documented
        // get_plugin_page_hook() failure was isolated to submenus
        // registered under the 'ous-debug' parent specifically.
        add_submenu_page('own-ur-shit', 'Campaigns', 'Campaigns', 'manage_options', 'ous-campaigns', [self::class, 'render']);
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

    // The live recipient list is computed HERE, at send time, and never
    // stored — only the resulting per-recipient jobs are. This is what
    // makes the segment a live query rather than a snapshot: rerunning
    // send_now() later (a different campaign, or a resend) would compute
    // a different, currently-accurate list.
    /** @return true|\WP_Error */
    public static function send_now(int $campaign_id) {
        global $wpdb;
        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $campaign_id), ARRAY_A);
        if (!$campaign || $campaign['status'] !== 'draft') return new WP_Error('bad_state', 'This campaign has already been sent or does not exist.');

        $segments = self::segments();
        $segment = $segments[$campaign['segment_key']] ?? null;
        if (!$segment || !is_callable($segment['user_ids'])) return new WP_Error('bad_segment', 'That segment no longer exists.');

        $user_ids = array_unique(array_map('intval', call_user_func($segment['user_ids'])));
        $user_ids = array_filter($user_ids); // drop any 0/falsy entries a segment callback might return

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
            // No job queue active — fail toward sending anyway (same
            // posture OUS_Notifications::notify() takes) rather than
            // silently dropping a campaign an admin explicitly sent.
            foreach ($user_ids as $user_id) {
                self::send_one(['campaign_id' => $campaign_id, 'user_id' => $user_id]);
            }
        }
        return true;
    }

    // The job queue's own per-recipient send. A user who globally opted
    // out of email (the same 'bhcore_notifications_email_optout' flag
    // Tier 1b's per-notification-type preferences already respect) is
    // honored here too — one "stop emailing me entirely" switch, not two
    // separate unsubscribe mechanisms a person has to find and use twice.
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
                    'source' => 'OUS Campaigns', 'log_context' => ['campaign_id' => $campaign_id],
                ]);
            } else {
                $user = get_userdata($user_id);
                if ($user && $user->user_email) {
                    wp_mail($user->user_email, $campaign['subject'], wpautop(wp_kses_post($campaign['body'])), ['Content-Type: text/html; charset=UTF-8']);
                }
            }
        }

        // sent_count still increments for an opted-out recipient — this
        // is "processed," not "delivered," so total_recipients ==
        // sent_count still means the send run is genuinely finished, not
        // stuck forever waiting for a count that opted-out users will
        // never contribute to.
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
                $message = 'Subject and body are both required.';
            } elseif (!isset(self::segments()[$segment_key])) {
                $message = 'Pick a valid segment.';
            } else {
                $id = self::create($subject, $body, $segment_key, get_current_user_id());
                $message = 'Campaign saved as a draft.';
                if (isset($_POST['ous_campaign_send_now'])) {
                    $result = self::send_now($id);
                    $message = is_wp_error($result) ? $result->get_error_message() : 'Campaign is sending now.';
                }
            }
        } elseif (isset($_POST['ous_campaign_send_id']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ous_campaign_send_nonce'] ?? '')), 'ous_campaign_send')) {
            $result = self::send_now((int) $_POST['ous_campaign_send_id']);
            $message = is_wp_error($result) ? $result->get_error_message() : 'Campaign is sending now.';
        }

        echo '<div class="wrap"><h1>Campaigns</h1>';
        if ($message) echo '<div class="notice notice-info"><p>' . esc_html($message) . '</p></div>';

        echo '<h2>New campaign</h2>';
        echo '<form method="post">';
        wp_nonce_field('ous_campaign_save', 'ous_campaign_nonce');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="segment_key">Send to</label></th><td><select name="segment_key" id="segment_key">';
        foreach (self::segments() as $key => $segment) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($segment['label']) . '</option>';
        }
        echo '</select><p class="description">Evaluated live, right when you send — not a frozen list.</p></td></tr>';
        echo '<tr><th><label for="subject">Subject</label></th><td><input type="text" class="regular-text" name="subject" id="subject" required></td></tr>';
        echo '<tr><th><label for="body">Message</label></th><td><textarea name="body" id="body" rows="8" class="large-text" required></textarea></td></tr>';
        echo '</tbody></table>';
        echo '<p><button type="submit" name="ous_campaign_action" value="save" class="button">Save as draft</button> ';
        echo '<button type="submit" name="ous_campaign_send_now" value="1" class="button button-primary" onclick="return confirm(\'Send this campaign now to everyone currently in that segment?\');">Save and send now</button></p>';
        echo '</form>';

        echo '<h2>Past campaigns</h2>';
        $campaigns = self::all_campaigns();
        if (!$campaigns) {
            echo '<p>No campaigns yet.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Subject</th><th>Segment</th><th>Status</th><th>Recipients</th><th>Sent</th><th></th></tr></thead><tbody>';
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
        $suites['own-ur-shit-campaigns'] = ['label' => 'The Self-Hosted Self (Campaigns)', 'callback' => [self::class, 'run_tests']];
        return $suites;
    }

    /** @return array<int, mixed> */
    public static function run_tests(): array {
        if (!class_exists('OUS_Debug')) return [['name' => 'OUS_Debug not loaded', 'pass' => false, 'message' => 'Skipped.']];
        global $wpdb;
        $rows = [];

        $recipient = OUS_Debug::get_or_create_test_user('ous_campaign_suite_recipient', false);
        delete_user_meta($recipient, 'bcore_notifications_email_optout'); // typo-guard: ensure the REAL key below starts clean
        delete_user_meta($recipient, 'bhcore_notifications_email_optout');

        // A fake, single-user segment registered ONLY for this test run —
        // proves the filter-based extension mechanism actually works
        // end-to-end, not just that the built-in segment does.
        $capture = ['sent' => 0];
        $add_segment = function ($segments) use ($recipient) {
            $segments['ous_campaign_suite_segment'] = [
                'label' => 'Test segment',
                'user_ids' => function () use ($recipient) { return [$recipient]; },
            ];
            return $segments;
        };
        add_filter('bhcore_campaign_segments', $add_segment);

        $campaign_id = self::create('Test subject', 'Test body', 'ous_campaign_suite_segment', $recipient);
        $rows[] = OUS_TestRunner::assert_true($campaign_id > 0, 'create() inserts a new campaign row and returns its id');

        $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $campaign_id), ARRAY_A);
        $rows[] = OUS_TestRunner::assert_same('draft', $before['status'], 'A new campaign starts as a draft');

        $result = self::send_now($campaign_id);
        $rows[] = OUS_TestRunner::assert_true($result === true, 'send_now() succeeds against a real registered segment');

        $enqueued = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $campaign_id), ARRAY_A);
        $rows[] = OUS_TestRunner::assert_same(1, (int) $enqueued['total_recipients'], 'send_now() resolves the live segment to exactly 1 recipient');
        $rows[] = OUS_TestRunner::assert_same('sending', $enqueued['status'], 'send_now() only ENQUEUES the per-recipient job and moves to "sending" — it never sends synchronously in the request that clicked Send');

        // send_one() is the job queue's own per-recipient handler —
        // whether OUS_Jobs' actual queue is backed by Action Scheduler
        // (async, processed on its own schedule) or the plain wpdb
        // fallback, that's OUS_Jobs' OWN concern to guarantee, already
        // covered by its own test suite. What THIS suite verifies is
        // send_one()'s contract once it does run: does it actually
        // deliver, count correctly, and flip the campaign to "sent" —
        // so it's called directly here rather than depending on a real
        // queue draining on this exact request.
        self::send_one(['campaign_id' => $campaign_id, 'user_id' => $recipient]);
        $after = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $campaign_id), ARRAY_A);
        $rows[] = OUS_TestRunner::assert_same('sent', $after['status'], 'Status becomes "sent" once every enqueued recipient has actually been processed');
        $rows[] = OUS_TestRunner::assert_same(1, (int) $after['sent_count'], 'sent_count reflects exactly one processed recipient');

        // Draft-only guard — a campaign already sent (or sending) can't
        // be re-triggered by calling send_now() again.
        $resend = self::send_now($campaign_id);
        $rows[] = OUS_TestRunner::assert_true(is_wp_error($resend), 'send_now() on an already-sent campaign is rejected, not silently resent');

        // Opt-out is honored — recipient still gets counted as
        // "processed" but wp_mail() is skipped (can't assert wp_mail was
        // NOT called without a mock; assert the counting contract
        // instead, which is the part this class actually controls).
        update_user_meta($recipient, 'bhcore_notifications_email_optout', '1');
        $campaign_id_2 = self::create('Test subject 2', 'Test body 2', 'ous_campaign_suite_segment', $recipient);
        self::send_now($campaign_id_2);
        self::send_one(['campaign_id' => $campaign_id_2, 'user_id' => $recipient]);
        $after2 = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $campaign_id_2), ARRAY_A);
        $rows[] = OUS_TestRunner::assert_same('sent', $after2['status'], 'An opted-out recipient is still counted as processed, so the campaign still reaches "sent" rather than hanging');
        delete_user_meta($recipient, 'bhcore_notifications_email_optout');

        remove_filter('bhcore_campaign_segments', $add_segment);
        $wpdb->delete(self::table(), ['id' => $campaign_id]);
        $wpdb->delete(self::table(), ['id' => $campaign_id_2]);

        return $rows;
    }
}
