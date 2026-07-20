<?php
if (!defined('ABSPATH')) exit;

/**
 * Split out of class-admin.php (DRY/SOLID audit Phase 3b) — submission
 * moderation: the approval-announcement hook, file-swap approve/discard,
 * reject-with-reason, and the AJAX round-advance action. No metabox
 * rendering, list-table columns, or CSV/results reporting here.
 */
class BH_AdminModeration {
    public static function init() {
        add_action('transition_post_status', [self::class, 'maybe_notify_approval'], 10, 3);

        // Submission review actions — file-replace workflow (a pending
        // swap needs its own explicit approve/discard, since the swap
        // doesn't go through a post_status transition the way a
        // first-time approval does) plus a real reject path with a
        // reason.
        add_action('admin_post_bh_approve_swap', [self::class, 'handle_approve_swap']);
        add_action('admin_post_bh_discard_swap', [self::class, 'handle_discard_swap']);
        add_action('admin_post_bh_reject_submission', [self::class, 'handle_reject_submission']);

        add_action('wp_ajax_bh_advance_round', [self::class, 'ajax_advance_round']);
    }

    // Fires the public "new entry approved" Discord notification at the
    // moment an admin actually approves a submission (changes its status
    // to Published), not when it was first submitted — this webhook is
    // public-facing, so announcing something before anyone's reviewed it
    // would mean the whole channel sees every submission, including any
    // that get rejected. Guarded to the actual off-to-on transition so
    // re-saving an already-published submission (editing its title,
    // fixing a typo, etc.) doesn't re-announce it every time.
    public static function maybe_notify_approval($new_status, $old_status, $post) {
        if ($post->post_type !== 'bh_submission') return;
        if ($new_status !== 'publish' || $old_status === 'publish') return;

        // A contestant may have swapped their file before this
        // submission was ever reviewed (still 'pending' at the time) —
        // that swap sits in _bh_pending_audio_id same as it would on an
        // already-published submission. Promote it here so a
        // first-time approval always announces whatever's actually the
        // reviewed, current file, not a stale first upload.
        self::promote_pending_audio($post->ID);

        $cid = (int) get_post_meta($post->ID, '_bh_contest_id', true);
        if (!$cid) return;

        $artist = get_post_meta($post->ID, '_bh_artist_name', true);
        $aid    = (int) get_post_meta($post->ID, '_bh_audio_id', true);
        BH_Discord::notify_submission($cid, $post->post_title, $artist, $aid ? wp_get_attachment_url($aid) : '');
    }

    /** Promotes a pending file swap to live: deletes the old attachment, moves pending -> live, clears pending meta. */
    private static function promote_pending_audio($post_id) {
        $old_audio = (int) get_post_meta($post_id, '_bh_audio_id', true);
        $pending = (int) get_post_meta($post_id, '_bh_pending_audio_id', true);
        if (!$pending) return;
        if ($old_audio && $old_audio !== $pending) wp_delete_attachment($old_audio, true);
        update_post_meta($post_id, '_bh_audio_id', $pending);
        delete_post_meta($post_id, '_bh_pending_audio_id');
        delete_post_meta($post_id, '_bh_pending_replaced_by');
        delete_post_meta($post_id, '_bh_pending_replaced_at');
    }

    public static function handle_approve_swap() {
        $pid = (int) ($_GET['submission_id'] ?? 0);
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bh_approve_swap_' . $pid)) wp_die('Bad nonce.', '', ['back_link' => true]);
        if (!current_user_can('manage_options')) wp_die('Not allowed.', '', ['back_link' => true]);
        $post = get_post($pid);
        if (!$post || $post->post_type !== 'bh_submission') wp_die('Submission not found.', '', ['back_link' => true]);

        // QA fix, caught live: this fired the Discord "entry file
        // updated" announcement unconditionally, even for a submission
        // that was never actually approved (still 'pending') —
        // "Approve replacement" and the real first-time Publish
        // approval are two independent actions, and a still-pending
        // submission has nothing to publicly re-announce yet (its
        // first REAL approval, whenever that happens, already
        // announces fresh via maybe_notify_approval(), which now also
        // calls promote_pending_audio() itself). Only announce here
        // when this submission was already publicly live.
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

    public static function handle_discard_swap() {
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

    /**
     * Real reject path — AJ's own ask: "some real reasoning behind
     * it." Sets the 'rejected' post_status (registered in
     * class-post-types.php), stores the prefab reason + freeform note,
     * and emails the contestant with both — closing the gap where a
     * rejected submission previously just sat at 'pending' forever
     * with no notification either way.
     */
    public static function handle_reject_submission() {
        $pid = (int) ($_POST['submission_id'] ?? 0);
        if (!check_admin_referer('bh_reject_submission_' . $pid)) wp_die('Bad nonce.', '', ['back_link' => true]);
        if (!current_user_can('manage_options')) wp_die('Not allowed.', '', ['back_link' => true]);
        $post = get_post($pid);
        if (!$post || $post->post_type !== 'bh_submission') wp_die('Submission not found.', '', ['back_link' => true]);

        $reason_code = sanitize_key($_POST['reason_code'] ?? 'other');
        if (!isset(BH_Admin::REJECTION_REASONS[$reason_code])) $reason_code = 'other';
        $note = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));

        // Real bug this closes: this Reject action is available for a
        // submission at ANY pre-rejected status, including 'publish' —
        // an admin can reject an entry that already collected real
        // votes. Without this, every voter who'd already voted for it
        // was permanently unable to free that vote slot: class-api.php's
        // vote() toggle-off used to be gated behind the SAME "belongs to
        // this contest + still published" check the toggle-ON path
        // needs, so once this submission left 'publish' status, the
        // voter's own request to un-vote it hit that same gate and
        // failed — a trapped, permanently-consumed vote with no UI path
        // to notice why (the track also vanishes from the public
        // /tracks list the instant it's rejected). Deleting the rows
        // here, at the moment of rejection, refunds every affected
        // voter automatically rather than relying on each of them to
        // separately discover and retry a now-fixed toggle-off request.
        global $wpdb;
        $wpdb->delete(BH_Helpers::table(), ['submission_id' => $pid], ['%d']);

        update_post_meta($pid, '_bh_rejection_reason_code', $reason_code);
        update_post_meta($pid, '_bh_rejection_note', $note);
        wp_update_post(['ID' => $pid, 'post_status' => 'rejected']);

        $author = get_userdata($post->post_author);
        $cid = (int) get_post_meta($pid, '_bh_contest_id', true);
        if ($author && $author->user_email) {
            $contest_title = $cid ? get_the_title($cid) : 'the contest';
            $subject = 'About your submission — ' . get_bloginfo('name');
            $reason_label = BH_Admin::REJECTION_REASONS[$reason_code];
            $body = "Hi {$author->user_login},\n\nYour submission \"{$post->post_title}\" for {$contest_title} wasn't accepted this time.\n\nReason: {$reason_label}"
                  . ($note ? "\n\nNote from the team: {$note}" : '')
                  . "\n\nIf this was a mistake (e.g. the wrong file got attached), you can upload a replacement from your account portal while submissions are still open, and it'll be reviewed again.";
            $sent = wp_mail($author->user_email, $subject, $body);
            if ($sent && class_exists('BH_Event')) {
                BH_Event::emit('bhcore/email_sent', ['user_id' => (int) $post->post_author, 'subject_type' => 'email', 'subject_id' => 0, 'payload' => ['title' => $subject]]);
            } elseif (!$sent && class_exists('OUS_DebugLog')) {
                // Debug-log wiring pass — a rejection notice failing to
                // send is worth knowing about: the contestant would
                // otherwise have no idea their submission was rejected
                // at all.
                OUS_DebugLog::log('warning', 'Rejection notification email failed to send (wp_mail() returned false).', [
                    'user_id' => (int) $post->post_author, 'submission_id' => $pid,
                ], 'BH Contest Submission');
            }
        }
        if (class_exists('BH_Event')) {
            BH_Event::emit('bh/submission_rejected', ['user_id' => (int) $post->post_author, 'subject_type' => 'bh_submission', 'subject_id' => $pid, 'payload' => ['contest_id' => $cid, 'reason_code' => $reason_code]]);
        }
        // Accountability log, AJ's own ask — a real moderation action (BH_Event above is the contestant's own activity feed, this is the admin-accountability side of the same action).
        if (class_exists('OUS_Audit')) {
            OUS_Audit::log('submission_rejected', 'bh_submission', $pid, ['reason_code' => $reason_code, 'contest_id' => $cid]);
        }

        wp_safe_redirect(get_edit_post_link($pid, ''));
        exit;
    }

    // ROADMAP-ux-polish-and-feature-parity-2026-07.md 2b — the admin
    // action that actually closes out a round: capability-gated
    // (edit_post on the contest) and per-contest nonce'd, since this is
    // a real, one-way state change (eliminated entries don't come back
    // from this screen).
    public static function ajax_advance_round() {
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
