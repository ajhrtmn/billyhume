<?php
if (!defined('ABSPATH')) exit;

/**
 * The submitter side — upload a track, pick a tier, pay via wallet
 * credit, get a bh_feedback_request post. [bhf_submit] renders the
 * form; handle_submit() does the actual charge + post creation.
 *
 * Duplicate detection is its OWN self-contained sha1-file-hash check
 * against this plugin's OWN prior requests (_bhf_audio_hash meta on
 * other bh_feedback_request posts) — deliberately NOT a call into
 * bh-streaming's BHS_AudioHash, which is hardcoded to compare against
 * `bhs_track` posts specifically and can't be reused here. The actual
 * "has this exact file already been submitted for feedback before"
 * question this plugin cares about is scoped to ITS OWN request
 * history, not the streaming catalog — a real, cheap magic moment
 * (flagging a resubmission before a reviewer wastes time on a track
 * they've already reviewed), same sha1-of-file-bytes approach
 * BHS_AudioHash pioneered, applied to a different table.
 */
class BHF_Requests {
    public static function init() {
        add_shortcode('bhf_submit', [self::class, 'render_shortcode']);
        add_action('admin_post_bhf_submit_request', [self::class, 'handle_submit']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
    }

    // Enqueued unconditionally on the front end (small stylesheet, no
    // JS) — same low-cost-of-always-loading posture own-ur-shit's
    // public-profile CSS takes, rather than trying to detect every
    // possible page this shortcode/the portal panel might render on.
    public static function maybe_enqueue() {
        wp_enqueue_style('bhf-feedback', BHF_URL . 'assets/css/feedback.css', [], BHF_VER);
    }

    public static function render_shortcode() {
        if (!is_user_logged_in()) {
            return '<p class="bhf-notice">' . esc_html__('Log in to submit a track for feedback.', 'bh-feedback') . '</p>';
        }
        if (!class_exists('BHM_Wallet')) {
            return '<p class="bhf-notice">' . esc_html__('Feedback requests aren\'t available on this site right now.', 'bh-feedback') . '</p>';
        }

        $balance = BHM_Wallet::balance_cents(get_current_user_id());
        $notice = '';
        if (isset($_GET['bhf_submitted'])) {
            // Audit fix (2026-07-25): the old copy said the review appears
            // "once a reviewer claims it," but per the actual render logic
            // (BHF_PortalPanel::render_my_requests()) only a COMPLETED
            // request shows review text — claiming just changes the badge.
            // Now accurate to what actually happens at each stage.
            $notice = '<p class="bhf-notice bhf-notice--ok">' . esc_html__('Submitted! You\'ll see the status update once a reviewer claims it, and the full written review once it\'s complete.', 'bh-feedback') . '</p>';
        }
        if (isset($_GET['bhf_error'])) {
            $notice = '<p class="bhf-notice bhf-notice--error">' . esc_html(sanitize_text_field(wp_unslash($_GET['bhf_error']))) . '</p>';
        }

        ob_start(); ?>
        <div class="bhf-submit-form">
            <?php echo $notice; ?>
            <p class="bhf-balance"><?php echo esc_html(sprintf(__('Wallet balance: $%s', 'bh-feedback'), number_format($balance / 100, 2))); ?></p>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="bhf_submit_request" />
                <?php wp_nonce_field('bhf_submit_request', 'bhf_nonce'); ?>
                <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url(remove_query_arg(['bhf_submitted', 'bhf_error'])); ?>" />

                <label>Track title
                    <input type="text" name="title" maxlength="190" required />
                </label>
                <label>Audio file
                    <input type="file" name="audio_file" accept="audio/*" required />
                </label>
                <fieldset class="bhf-tier-picker">
                    <legend>Feedback tier</legend>
                    <?php foreach (BHF_Pricing::TIERS as $key => $tier): ?>
                        <label class="bhf-tier-option">
                            <input type="radio" name="tier" value="<?php echo esc_attr($key); ?>" <?php checked($key === array_key_first(BHF_Pricing::TIERS)); ?> />
                            <strong><?php echo esc_html($tier['label']); ?></strong> — $<?php echo esc_html(number_format(BHF_Pricing::cents_for($key) / 100, 2)); ?>
                            <span class="bhf-tier-description"><?php echo esc_html($tier['description']); ?> Typically within <?php echo (int) BHF_Pricing::turnaround_days_for($key); ?> days.</span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>

                <button type="submit" class="bhf-btn bhf-btn--primary">Submit for feedback</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function handle_submit() {
        if (!is_user_logged_in()) wp_die('Not logged in.');
        if (!isset($_POST['bhf_nonce']) || !wp_verify_nonce($_POST['bhf_nonce'], 'bhf_submit_request')) {
            wp_die('Security check failed.');
        }
        $referer = !empty($_POST['_wp_http_referer']) ? esc_url_raw($_POST['_wp_http_referer']) : home_url('/');
        $user_id = get_current_user_id();

        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $tier = isset($_POST['tier']) ? sanitize_key($_POST['tier']) : '';

        if ($title === '' || !BHF_Pricing::is_valid_tier($tier)) {
            self::redirect_error($referer, 'Please enter a title and pick a valid tier.');
        }
        if (empty($_FILES['audio_file']['name'])) {
            self::redirect_error($referer, 'Please choose an audio file.');
        }
        if (!class_exists('BHM_Wallet')) {
            self::redirect_error($referer, 'Feedback requests aren\'t available on this site right now.');
        }

        $attachment_id = self::handle_audio_upload('audio_file', $user_id);
        if (is_wp_error($attachment_id)) {
            self::redirect_error($referer, $attachment_id->get_error_message());
        }

        $cents = BHF_Pricing::cents_for($tier);
        // The atomic-UPDATE-guarded debit is the actual charge — this
        // fails cleanly (false) on insufficient balance rather than
        // ever creating a request nobody paid for.
        $charged = BHM_Wallet::debit($user_id, $cents, null, 'feedback_request');
        if (!$charged) {
            wp_delete_attachment($attachment_id, true);
            self::redirect_error($referer, 'Not enough wallet balance for that tier — top up and try again.');
        }

        $request_id = wp_insert_post([
            'post_type' => 'bh_feedback_request',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_author' => $user_id,
        ], true);
        if (is_wp_error($request_id)) {
            // The charge already went through — reverse it rather than
            // leaving a fan out the cost of a request that was never
            // actually created (the same "take back what was granted"
            // posture BHM_Entitlements' own order-reversal handler takes
            // on a refund).
            BHM_Wallet::apply_ledger_delta($user_id, $cents, 'feedback_request_reversal', null, null);
            wp_delete_attachment($attachment_id, true);
            self::redirect_error($referer, 'Something went wrong creating your request — your wallet was not charged.');
        }

        update_post_meta($request_id, '_bhf_tier', $tier);
        update_post_meta($request_id, '_bhf_price_cents', $cents);
        update_post_meta($request_id, '_bhf_attachment_id', $attachment_id);
        update_post_meta($request_id, '_bhf_status', BHF_PostTypes::STATUS_OPEN);
        update_post_meta($request_id, '_bhf_reviewer_id', 0);
        update_post_meta($request_id, '_bhf_duplicate_of', self::check_duplicate($request_id, $attachment_id));

        wp_safe_redirect(add_query_arg('bhf_submitted', '1', $referer));
        exit;
    }

    private static function redirect_error($referer, $message) {
        wp_safe_redirect(add_query_arg('bhf_error', rawurlencode($message), $referer));
        exit;
    }

    // Same trust-boundary pattern own-ur-shit's class-public-profile.php
    // uses for avatar/banner uploads: media_handle_upload() performs no
    // capability check of its own, and a plain subscriber account
    // (which submitting a feedback request should work for) doesn't
    // have upload_files by default — grant it for the duration of this
    // one call only, never persisted to the user's real role/caps.
    private static function handle_audio_upload($field, $user_id) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $file = $_FILES[$field];
        $type = wp_check_filetype($file['name']);
        if (!$type['type'] || strpos($type['type'], 'audio/') !== 0) {
            return new WP_Error('bad_type', 'Please upload an audio file.');
        }
        if ($file['size'] > 50 * 1024 * 1024) {
            return new WP_Error('too_big', 'Audio file must be smaller than 50MB.');
        }

        $grant_upload_cap = function ($allcaps) {
            $allcaps['upload_files'] = true;
            return $allcaps;
        };
        add_filter('user_has_cap', $grant_upload_cap);
        $attachment_id = media_handle_upload($field, 0, ['post_author' => $user_id]);
        remove_filter('user_has_cap', $grant_upload_cap);

        return $attachment_id;
    }

    // sha1-of-file-bytes duplicate check, scoped to THIS plugin's own
    // request history — see this class's own docblock for why this
    // isn't a call into bh-streaming's BHS_AudioHash. Returns the prior
    // request's post ID if a same-file match is found, else 0. Doesn't
    // block submission either way — this is a surfaced signal for the
    // reviewer (see class-queue.php's render of it), never an auto-reject.
    public static function check_duplicate($request_id, $attachment_id) {
        $path = get_attached_file($attachment_id);
        if (!$path || !file_exists($path)) return 0;
        $hash = sha1_file($path);
        if (!$hash) return 0;
        update_post_meta($request_id, '_bhf_audio_hash', $hash);

        global $wpdb;
        $match = $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_bhf_audio_hash' AND m.meta_value = %s
             WHERE p.post_type = 'bh_feedback_request' AND p.ID != %d
             ORDER BY p.ID ASC LIMIT 1",
            $hash, $request_id
        ));
        return $match ? (int) $match : 0;
    }
}
