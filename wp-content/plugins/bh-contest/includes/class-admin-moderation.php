<?php
if (!defined('ABSPATH')) exit;

/**
 * Submission moderation: approval announcements, audio-swap approve/discard,
 * reject-with-reason (single and bulk), and the AJAX round advance.
 */
class BH_AdminModeration {
    public static function init(): void {
        add_action('transition_post_status', [self::class, 'maybe_notify_approval'], 10, 3);

        // An audio swap has no status transition, so it gets explicit approve/discard.
        add_action('admin_post_bh_approve_swap', [self::class, 'handle_approve_swap']);
        add_action('admin_post_bh_discard_swap', [self::class, 'handle_discard_swap']);
        add_action('admin_post_bh_reject_submission', [self::class, 'handle_reject_submission']);

        add_action('wp_ajax_bh_advance_round', [self::class, 'ajax_advance_round']);

        // Bulk Approve publishes; bulk Reject uses reject_submission() with reason 'other'.
        add_filter('bulk_actions-edit-bh_submission', [self::class, 'add_bulk_actions']);
        add_filter('handle_bulk_actions-edit-bh_submission', [self::class, 'handle_bulk_actions'], 10, 3);
        add_action('admin_notices', [self::class, 'bulk_action_notice']);

        add_filter('post_row_actions', [self::class, 'add_quick_approve_row_action'], 10, 2);
        add_action('admin_post_bh_quick_approve', [self::class, 'handle_quick_approve']);

        // Custom 'rejected' status gets no list-table state label from core.
        add_filter('display_post_states', [self::class, 'add_rejected_post_state'], 10, 2);
    }

    /**
     * @param array<string,string> $actions
     * @return array<string,string>
     */
    public static function add_bulk_actions(array $actions): array {
        $actions['bh_approve'] = 'Approve';
        $actions['bh_reject']  = 'Reject';
        return $actions;
    }

    /** @param array<int,int> $post_ids */
    public static function handle_bulk_actions(string $redirect_to, string $action, array $post_ids): string {
        if ($action !== 'bh_approve' && $action !== 'bh_reject') return $redirect_to;
        if (!current_user_can('manage_options')) return $redirect_to;

        $done = 0;
        foreach ($post_ids as $pid) {
            $pid = (int) $pid;
            $post = get_post($pid);
            if (!$post || $post->post_type !== 'bh_submission') continue;

            if ($action === 'bh_approve') {
                wp_update_post(['ID' => $pid, 'post_status' => 'publish']);
            } else {
                self::reject_submission($pid, 'other', '');
            }
            $done++;
        }

        return add_query_arg($action === 'bh_approve' ? 'bh_bulk_approved' : 'bh_bulk_rejected', $done, $redirect_to);
    }

    public static function bulk_action_notice(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'edit-bh_submission') return;

        if (!empty($_GET['bh_bulk_approved'])) {
            $n = (int) $_GET['bh_bulk_approved'];
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(_n('%d submission approved.', '%d submissions approved.', $n), $n)) . '</p></div>';
        }
        if (!empty($_GET['bh_bulk_rejected'])) {
            $n = (int) $_GET['bh_bulk_rejected'];
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(_n('%d submission rejected and notified.', '%d submissions rejected and notified.', $n), $n)) . '</p></div>';
        }
    }

    /**
     * @param array<string,string> $actions
     * @return array<string,string>
     */
    public static function add_quick_approve_row_action(array $actions, \WP_Post $post): array {
        if ($post->post_type !== 'bh_submission' || $post->post_status === 'publish') return $actions;
        if (!current_user_can('manage_options')) return $actions;

        $url = wp_nonce_url(
            admin_url('admin-post.php?action=bh_quick_approve&submission_id=' . $post->ID),
            'bh_quick_approve_' . $post->ID
        );
        $actions['bh_approve'] = '<a href="' . esc_url($url) . '">Approve</a>';
        return $actions;
    }

    public static function handle_quick_approve(): void {
        $pid = (int) ($_GET['submission_id'] ?? 0);
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bh_quick_approve_' . $pid)) wp_die('Bad nonce.', '', ['back_link' => true]);
        if (!current_user_can('manage_options')) wp_die('Not allowed.', '', ['back_link' => true]);
        $post = get_post($pid);
        if (!$post || $post->post_type !== 'bh_submission') wp_die('Submission not found.', '', ['back_link' => true]);

        wp_update_post(['ID' => $pid, 'post_status' => 'publish']);
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=bh_submission'));
        exit;
    }

    /**
     * @param array<string,string> $states
     * @return array<string,string>
     */
    public static function add_rejected_post_state(array $states, \WP_Post $post): array {
        if ($post->post_type === 'bh_submission' && $post->post_status === 'rejected') {
            $states['bh_rejected'] = 'Rejected';
        }
        return $states;
    }

    // Public Discord announcement on first approval only, never on submit or re-save.
    public static function maybe_notify_approval(string $new_status, string $old_status, \WP_Post $post): void {
        if ($post->post_type !== 'bh_submission') return;
        if ($new_status !== 'publish' || $old_status === 'publish') return;

        // A swap made before first review is promoted, so the current file goes live.
        self::promote_pending_audio($post->ID);

        $cid = (int) get_post_meta($post->ID, '_bh_contest_id', true);
        if (!$cid) return;

        $artist = get_post_meta($post->ID, '_bh_artist_name', true);
        $aid    = (int) get_post_meta($post->ID, '_bh_audio_id', true);
        BH_Discord::notify_submission($cid, $post->post_title, $artist, $aid ? wp_get_attachment_url($aid) : '');
    }

    /** Promotes a pending file swap to live: deletes the old attachment, moves pending -> live, clears pending meta. */
    private static function promote_pending_audio(int $post_id): void {
        $old_audio = (int) get_post_meta($post_id, '_bh_audio_id', true);
        $pending = (int) get_post_meta($post_id, '_bh_pending_audio_id', true);
        if (!$pending) return;
        if ($old_audio && $old_audio !== $pending) wp_delete_attachment($old_audio, true);
        update_post_meta($post_id, '_bh_audio_id', $pending);
        delete_post_meta($post_id, '_bh_pending_audio_id');
        delete_post_meta($post_id, '_bh_pending_replaced_by');
        delete_post_meta($post_id, '_bh_pending_replaced_at');
    }

    public static function handle_approve_swap(): void {
        $pid = (int) ($_GET['submission_id'] ?? 0);
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bh_approve_swap_' . $pid)) wp_die('Bad nonce.', '', ['back_link' => true]);
        if (!current_user_can('manage_options')) wp_die('Not allowed.', '', ['back_link' => true]);
        $post = get_post($pid);
        if (!$post || $post->post_type !== 'bh_submission') wp_die('Submission not found.', '', ['back_link' => true]);

        // Announce only if already published; first approval announces separately.
        $was_published = $post->post_status === 'publish';

        self::promote_pending_audio($pid);

        $cid = (int) get_post_meta($pid, '_bh_contest_id', true);
        $artist = get_post_meta($pid, '_bh_artist_name', true);
        $aid = (int) get_post_meta($pid, '_bh_audio_id', true);
        if ($was_published && $cid && class_exists('BH_Discord')) {
            BH_Discord::notify_submission($cid, $post->post_title, $artist, $aid ? wp_get_attachment_url($aid) : '', true);
        }
        if (class_exists('BH_Event')) {
            BH_Event::emit('bh/submission_swap_approved', ['user_id' => (int) $post->post_author, 'subject_type' => 'bh_submission', 'subject_id' => $pid, 'payload' => ['contest_id' => $cid]]);
        }

        wp_safe_redirect(get_edit_post_link($pid, ''));
        exit;
    }

    public static function handle_discard_swap(): void {
        $pid = (int) ($_GET['submission_id'] ?? 0);
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bh_discard_swap_' . $pid)) wp_die('Bad nonce.', '', ['back_link' => true]);
        if (!current_user_can('manage_options')) wp_die('Not allowed.', '', ['back_link' => true]);
        $post = get_post($pid);
        if (!$post || $post->post_type !== 'bh_submission') wp_die('Submission not found.', '', ['back_link' => true]);

        $pending = (int) get_post_meta($pid, '_bh_pending_audio_id', true);
        if ($pending) wp_delete_attachment($pending, true);
        delete_post_meta($pid, '_bh_pending_audio_id');
        delete_post_meta($pid, '_bh_pending_replaced_by');
        delete_post_meta($pid, '_bh_pending_replaced_at');

        wp_safe_redirect(get_edit_post_link($pid, ''));
        exit;
    }

    /** Single-submission reject form handler. */
    public static function handle_reject_submission(): void {
        $pid = (int) ($_POST['submission_id'] ?? 0);
        if (!check_admin_referer('bh_reject_submission_' . $pid)) wp_die('Bad nonce.', '', ['back_link' => true]);
        if (!current_user_can('manage_options')) wp_die('Not allowed.', '', ['back_link' => true]);
        $post = get_post($pid);
        if (!$post || $post->post_type !== 'bh_submission') wp_die('Submission not found.', '', ['back_link' => true]);

        $reason_code = sanitize_key($_POST['reason_code'] ?? 'other');
        $note = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));
        self::reject_submission($pid, $reason_code, $note);

        wp_safe_redirect(get_edit_post_link($pid, ''));
        exit;
    }

    /**
     * Rejects a submission: refunds its votes, sets 'rejected', stores the
     * reason + note, and emails the contestant. Shared by single and bulk reject.
     */
    public static function reject_submission(int $pid, string $reason_code, string $note): void {
        $post = get_post($pid);
        if (!$post || $post->post_type !== 'bh_submission') return;
        if (!isset(BH_Admin::REJECTION_REASONS[$reason_code])) $reason_code = 'other';

        // WHY: delete its votes so every voter gets the slot back; a rejected
        // track vanishes from /tracks, so voters couldn't free it themselves.
        global $wpdb;
        $wpdb->delete(BH_Helpers::table(), ['submission_id' => $pid], ['%d']);

        update_post_meta($pid, '_bh_rejection_reason_code', $reason_code);
        update_post_meta($pid, '_bh_rejection_note', $note);
        wp_update_post(['ID' => $pid, 'post_status' => 'rejected']);

        $author = get_userdata((int) $post->post_author);
        $cid = (int) get_post_meta($pid, '_bh_contest_id', true);
        if ($author && $author->user_email) {
            $contest_title = $cid ? get_the_title($cid) : 'the contest';
            $subject = 'About your submission — ' . get_bloginfo('name');
            $reason_label = BH_Admin::REJECTION_REASONS[$reason_code];
            $body = "Hi {$author->user_login},\n\nYour submission \"{$post->post_title}\" for {$contest_title} wasn't accepted this time.\n\nReason: {$reason_label}"
                  . ($note ? "\n\nNote from the team: {$note}" : '')
                  . "\n\nIf this was a mistake (e.g. the wrong file got attached), you can upload a replacement from your account portal while submissions are still open, and it'll be reviewed again.";
            if (class_exists('BH_Mail')) {
                BH_Mail::send([
                    'to' => $author->user_email, 'user_id' => (int) $post->post_author, 'subject' => $subject, 'body' => $body,
                    'source' => 'BH Contest Submission', 'log_context' => ['submission_id' => $pid],
                ]);
            } else {
                wp_mail($author->user_email, $subject, $body);
            }
        }
        if (class_exists('BH_Event')) {
            BH_Event::emit('bh/submission_rejected', ['user_id' => (int) $post->post_author, 'subject_type' => 'bh_submission', 'subject_id' => $pid, 'payload' => ['contest_id' => $cid, 'reason_code' => $reason_code]]);
        }
        // Admin audit trail (BH_Event above is the contestant's activity feed).
        if (class_exists('OUS_Audit')) {
            OUS_Audit::log('submission_rejected', 'bh_submission', $pid, ['reason_code' => $reason_code, 'contest_id' => $cid]);
        }
    }

    // Closes the active round. One-way, so capability- and per-contest-nonce-gated.
    public static function ajax_advance_round(): void {
        $cid = (int) ($_POST['contest_id'] ?? 0);
        if (!$cid || !current_user_can('edit_post', $cid)) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bh_advance_round_' . $cid)) {
            wp_send_json_error(['message' => 'Security check failed — reload and try again.'], 403);
        }
        $result = BH_Rounds::advance_round($cid);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }
        wp_send_json_success($result);
    }
}
