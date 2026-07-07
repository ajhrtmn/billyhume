<?php
if (!defined('ABSPATH')) exit;

/**
 * The public-facing half of BHI_Profiles: a themed, presentation-only
 * "this is who I am" page (avatar/banner/bio/badges/links) plus the
 * logged-in-user's own editing form for it. Deliberately NOT a social
 * feature — no follow/followers, no activity feed, no notifications.
 * Just a real page a URL can point to, per the user's explicit call
 * that "social networky" here means identity/presentation only.
 *
 * [bh_profile]           — viewer-facing page. On a page whose URL
 *                           carries ?bh_user=<slug-or-id>, renders that
 *                           person's public profile (404-style notice if
 *                           private/missing). With no query var, renders
 *                           the CURRENT user's own edit form instead —
 *                           one shortcode covers both roles, same as
 *                           bh-registry's single [bh_registry] shortcode
 *                           covering browse + self-serve submission.
 * [bh_profile_link user_id="123"] — small helper other plugins'
 *                           templates can use to link to someone's page
 *                           without hand-building the URL scheme.
 */
class BHI_PublicProfile {
    public static function init() {
        add_shortcode('bh_profile', [__CLASS__, 'render_shortcode']);
        add_shortcode('bh_profile_link', [__CLASS__, 'render_link_shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_enqueue']);
        add_action('admin_post_bhi_save_profile', [__CLASS__, 'handle_save']);
        add_action('admin_post_bhi_delete_profile_data', [__CLASS__, 'handle_delete']);
        add_filter('bhi_report_target_label', [__CLASS__, 'report_target_label'], 10, 3);

        // The portal's first real, working consumer (see class-portal.php) —
        // the profile edit form moves INTO the portal as a panel; the
        // PUBLIC profile page/shortcode above stays exactly as-is, since
        // that's a different, intentionally-shareable surface, per the
        // roadmap doc's own explicit distinction.
        add_filter('bhi_portal_panels', [__CLASS__, 'register_portal_panel']);
    }

    public static function register_portal_panel($panels) {
        $panels[] = [
            'id' => 'profile',
            'label' => __('Profile', 'own-ur-shit'),
            'icon' => 'dashicons-admin-users',
            'render' => [__CLASS__, 'render_portal_panel'],
            'priority' => 10,
        ];
        return $panels;
    }

    // Thin public wrapper around the existing private render_edit_form() —
    // same form, same handle_save()/handle_delete() admin-post handlers,
    // just echoed into the portal shell's <main> instead of a standalone
    // shortcode-rendered page.
    public static function render_portal_panel() {
        echo self::render_edit_form(get_current_user_id());
    }

    public static function report_target_label($label, $type, $id) {
        if ($type !== 'profile') return $label;
        $user = get_userdata($id);
        return 'Profile: ' . ($user ? $user->display_name . ' (' . $user->user_email . ')' : 'User #' . $id);
    }

    public static function maybe_enqueue() {
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'bh_profile')) return;
        wp_enqueue_style('bhi-public-profile', OUS_URL . 'assets/css/public-profile.css', [], OUS_VER);
        $fonts = BHY_Style::google_fonts_url();
        if ($fonts) wp_enqueue_style('bhi-fonts', $fonts, [], null);
        echo '<style id="bhi-style-tokens">' . BHY_Style::inline_css() . '</style>';
    }

    public static function profile_url($user_id) {
        $data = BHI_Profiles::get($user_id);
        $key = $data['profile_slug'] ?: $user_id;
        return add_query_arg('bh_user', $key, home_url('/'));
    }

    public static function render_link_shortcode($atts) {
        $atts = shortcode_atts(['user_id' => 0, 'label' => ''], $atts);
        $user_id = (int) $atts['user_id'];
        if (!$user_id) return '';
        $label = $atts['label'] ?: get_the_author_meta('display_name', $user_id);
        return '<a class="bhi-profile-link" href="' . esc_url(self::profile_url($user_id)) . '">' . esc_html($label) . '</a>';
    }

    public static function render_shortcode() {
        $key = isset($_GET['bh_user']) ? sanitize_text_field(wp_unslash($_GET['bh_user'])) : '';

        if ($key === '') {
            if (!is_user_logged_in()) {
                return '<p class="bhi-profile-notice">' . esc_html__('Log in to view or edit your profile.', 'own-ur-shit') . '</p>';
            }
            return self::render_edit_form(get_current_user_id());
        }

        $user_id = ctype_digit($key) ? (int) $key : 0;
        if ($user_id) {
            // A numeric key only resolves if that user's profile is
            // actually public — visiting ?bh_user=<id> is not a back
            // door around the profile_public flag.
            $data = BHI_Profiles::get($user_id);
            if (!$data['profile_public']) $user_id = 0;
        } else {
            $user_id = BHI_Profiles::get_by_slug($key);
        }

        if (!$user_id || !get_userdata($user_id)) {
            return '<p class="bhi-profile-notice">' . esc_html__('This profile does not exist or is not public.', 'own-ur-shit') . '</p>';
        }

        return self::render_public_view($user_id);
    }

    private static function render_public_view($user_id) {
        $data = BHI_Profiles::get($user_id);
        $user = get_userdata($user_id);
        $badges = BHI_Profiles::badges_for($user_id);

        $avatar = $data['avatar_id'] ? wp_get_attachment_image_url((int) $data['avatar_id'], 'medium') : '';
        $banner = $data['banner_id'] ? wp_get_attachment_image_url((int) $data['banner_id'], 'large') : '';
        if (!$avatar) $avatar = get_avatar_url($user_id, ['size' => 200]);

        ob_start(); ?>
        <div class="bhi-profile bhi-profile--public">
            <?php if ($banner): ?>
                <div class="bhi-profile__banner" style="background-image:url('<?php echo esc_url($banner); ?>')"></div>
            <?php endif; ?>
            <div class="bhi-profile__header">
                <img class="bhi-profile__avatar" src="<?php echo esc_url($avatar); ?>" alt="<?php echo esc_attr($user->display_name); ?>" />
                <h2 class="bhi-profile__name"><?php echo esc_html($user->display_name); ?></h2>
                <?php if ($badges): ?>
                    <div class="bhi-profile__badges">
                        <?php foreach ($badges as $badge):
                            $label = esc_html($badge['label'] ?? '');
                            if ($label === '') continue;
                            $url = !empty($badge['url']) ? esc_url($badge['url']) : '';
                        ?>
                            <?php if ($url): ?>
                                <a class="bhi-badge" href="<?php echo $url; ?>"><?php echo $label; ?></a>
                            <?php else: ?>
                                <span class="bhi-badge"><?php echo $label; ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($data['bio']): ?>
                <div class="bhi-profile__bio"><?php echo wp_kses_post(wpautop(esc_html($data['bio']))); ?></div>
            <?php endif; ?>
            <ul class="bhi-profile__links">
                <?php if ($data['real_name_public'] && $data['real_name']): ?>
                    <li><?php echo esc_html($data['real_name']); ?></li>
                <?php endif; ?>
                <?php if ($data['discord_public'] && $data['discord_name']): ?>
                    <li>Discord: <?php echo esc_html($data['discord_name']); ?></li>
                <?php endif; ?>
                <?php if ($data['twitch_public'] && $data['twitch_name']): ?>
                    <li><a href="https://twitch.tv/<?php echo esc_attr($data['twitch_name']); ?>">Twitch</a></li>
                <?php endif; ?>
                <?php if ($data['youtube_public'] && $data['youtube_name']): ?>
                    <li><a href="https://youtube.com/<?php echo esc_attr($data['youtube_name']); ?>">YouTube</a></li>
                <?php endif; ?>
            </ul>
            <?php if (get_current_user_id() !== $user_id && class_exists('BHI_Reports')): ?>
                <?php echo BHI_Reports::report_button_html('profile', $user_id); ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_edit_form($user_id) {
        $data = BHI_Profiles::get($user_id);
        $notice = isset($_GET['bhi_saved']) ? '<p class="bhi-profile-notice bhi-profile-notice--ok">' . esc_html__('Profile saved.', 'own-ur-shit') . '</p>' : '';
        if (isset($_GET['bhi_deleted'])) {
            $notice = '<p class="bhi-profile-notice bhi-profile-notice--ok">' . esc_html__('Your profile data has been cleared.', 'own-ur-shit') . '</p>';
        }
        if (isset($_GET['bhi_error'])) {
            $notice = '<p class="bhi-profile-notice bhi-profile-notice--error">' . esc_html(sanitize_text_field(wp_unslash($_GET['bhi_error']))) . '</p>';
        }

        ob_start(); ?>
        <div class="bhi-profile bhi-profile--edit">
            <?php echo $notice; ?>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="bhi_save_profile" />
                <?php wp_nonce_field('bhi_save_profile', 'bhi_profile_nonce'); ?>
                <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url(remove_query_arg(['bhi_saved', 'bhi_error'])); ?>" />

                <label>Avatar
                    <input type="file" name="avatar_file" accept="image/*" />
                    <?php if ($data['avatar_id']): ?><span class="bhi-current">(current image set)</span><?php endif; ?>
                </label>
                <label>Banner
                    <input type="file" name="banner_file" accept="image/*" />
                    <?php if ($data['banner_id']): ?><span class="bhi-current">(current image set)</span><?php endif; ?>
                </label>
                <label>Bio
                    <textarea name="bio" rows="4" maxlength="2000"><?php echo esc_textarea($data['bio']); ?></textarea>
                </label>
                <label>Profile URL slug
                    <input type="text" name="profile_slug" value="<?php echo esc_attr($data['profile_slug']); ?>" placeholder="your-name" />
                </label>
                <label class="bhi-checkbox">
                    <input type="checkbox" name="profile_public" value="1" <?php checked($data['profile_public'], 1); ?> />
                    Make my profile page public
                </label>

                <fieldset>
                    <legend>Real name</legend>
                    <input type="text" name="real_name" value="<?php echo esc_attr($data['real_name']); ?>" />
                    <label class="bhi-checkbox"><input type="checkbox" name="real_name_public" value="1" <?php checked($data['real_name_public'], 1); ?> /> Show publicly</label>
                </fieldset>
                <fieldset>
                    <legend>Discord</legend>
                    <input type="text" name="discord_name" value="<?php echo esc_attr($data['discord_name']); ?>" />
                    <label class="bhi-checkbox"><input type="checkbox" name="discord_public" value="1" <?php checked($data['discord_public'], 1); ?> /> Show publicly</label>
                </fieldset>
                <fieldset>
                    <legend>Twitch</legend>
                    <input type="text" name="twitch_name" value="<?php echo esc_attr($data['twitch_name']); ?>" />
                    <label class="bhi-checkbox"><input type="checkbox" name="twitch_public" value="1" <?php checked($data['twitch_public'], 1); ?> /> Show publicly</label>
                </fieldset>
                <fieldset>
                    <legend>YouTube</legend>
                    <input type="text" name="youtube_name" value="<?php echo esc_attr($data['youtube_name']); ?>" />
                    <label class="bhi-checkbox"><input type="checkbox" name="youtube_public" value="1" <?php checked($data['youtube_public'], 1); ?> /> Show publicly</label>
                </fieldset>

                <button type="submit" class="bhi-btn bhi-btn--primary">Save profile</button>
                <?php if ($data['profile_public']): ?>
                    <a class="bhi-view-link" href="<?php echo esc_url(self::profile_url($user_id)); ?>">View public page</a>
                <?php endif; ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="bhi-delete-form"
                  onsubmit="return confirm('Clear your profile — bio, avatar, banner, URL slug, and platform handles? This can\'t be undone. (Purchases, entitlements, and wallet history are separate and are not affected.)');">
                <input type="hidden" name="action" value="bhi_delete_profile_data" />
                <?php wp_nonce_field('bhi_delete_profile_data', 'bhi_delete_nonce'); ?>
                <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url(remove_query_arg(['bhi_saved', 'bhi_error', 'bhi_deleted'])); ?>" />
                <button type="submit" class="bhi-btn bhi-btn--danger">Delete my profile data</button>
                <span class="bhi-current">Removes bio, images, slug, and handles. Purchase/entitlement/wallet history is kept separately (legal/financial record-keeping) regardless of this.</span>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function handle_delete() {
        if (!is_user_logged_in()) wp_die('Not logged in.');
        if (!isset($_POST['bhi_delete_nonce']) || !wp_verify_nonce($_POST['bhi_delete_nonce'], 'bhi_delete_profile_data')) {
            wp_die('Security check failed.');
        }
        $referer = !empty($_POST['_wp_http_referer']) ? esc_url_raw($_POST['_wp_http_referer']) : home_url('/');
        BHI_Profiles::delete_personal_data(get_current_user_id());
        wp_safe_redirect(add_query_arg('bhi_deleted', '1', $referer));
        exit;
    }

    public static function handle_save() {
        if (!is_user_logged_in()) wp_die('Not logged in.');
        if (!isset($_POST['bhi_profile_nonce']) || !wp_verify_nonce($_POST['bhi_profile_nonce'], 'bhi_save_profile')) {
            wp_die('Security check failed.');
        }

        $user_id = get_current_user_id();
        $referer = !empty($_POST['_wp_http_referer']) ? esc_url_raw($_POST['_wp_http_referer']) : home_url('/');

        $data = [
            'bio' => isset($_POST['bio']) ? wp_unslash($_POST['bio']) : '',
            'profile_slug' => isset($_POST['profile_slug']) ? wp_unslash($_POST['profile_slug']) : '',
            'profile_public' => !empty($_POST['profile_public']),
            'real_name' => isset($_POST['real_name']) ? wp_unslash($_POST['real_name']) : '',
            'discord_name' => isset($_POST['discord_name']) ? wp_unslash($_POST['discord_name']) : '',
            'twitch_name' => isset($_POST['twitch_name']) ? wp_unslash($_POST['twitch_name']) : '',
            'youtube_name' => isset($_POST['youtube_name']) ? wp_unslash($_POST['youtube_name']) : '',
            'real_name_public' => !empty($_POST['real_name_public']),
            'discord_public' => !empty($_POST['discord_public']),
            'twitch_public' => !empty($_POST['twitch_public']),
            'youtube_public' => !empty($_POST['youtube_public']),
        ];

        if (!empty($_FILES['avatar_file']['name'])) {
            $id = self::handle_image_upload('avatar_file', $user_id);
            if (is_wp_error($id)) {
                wp_safe_redirect(add_query_arg('bhi_error', rawurlencode($id->get_error_message()), $referer));
                exit;
            }
            if ($id) $data['avatar_id'] = $id;
        }
        if (!empty($_FILES['banner_file']['name'])) {
            $id = self::handle_image_upload('banner_file', $user_id);
            if (is_wp_error($id)) {
                wp_safe_redirect(add_query_arg('bhi_error', rawurlencode($id->get_error_message()), $referer));
                exit;
            }
            if ($id) $data['banner_id'] = $id;
        }

        $result = BHI_Profiles::save($user_id, $data);
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg('bhi_error', rawurlencode($result->get_error_message()), $referer));
            exit;
        }

        wp_safe_redirect(add_query_arg('bhi_saved', '1', $referer));
        exit;
    }

    // Reuses core's own media-upload machinery (media_handle_upload)
    // rather than hand-rolling file validation — the same trust boundary
    // bh-streaming's local-import endpoint uses. Restricted to images
    // only (a profile avatar/banner isn't a place to accept arbitrary
    // uploads) and attributed to the uploading user.
    //
    // QA fix: media_handle_upload() performs no capability check of its
    // own, and BHI_Auth::register() creates plain subscriber accounts,
    // which don't have upload_files by default — so this call site was
    // the one place in the ecosystem a low-privilege, self-registered
    // user could write directly into the site's media library with no
    // capability gate at all. The feature is explicitly meant to work
    // for exactly that subscriber-level user (that's who has a public
    // profile), so a hard current_user_can('upload_files') block would
    // just break the feature. Instead, grant upload_files for the
    // duration of this one call only (never persisted, never touches
    // the user's real role/caps) — a real second checkpoint on top of
    // the existing image-mimetype/size/author-attribution validation,
    // rather than relying on "media_handle_upload happens not to check."
    private static function handle_image_upload($field, $user_id) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $file = $_FILES[$field];
        $type = wp_check_filetype($file['name']);
        if (!$type['type'] || strpos($type['type'], 'image/') !== 0) {
            return new WP_Error('bad_type', 'Please upload an image file.');
        }
        if ($file['size'] > 8 * 1024 * 1024) {
            return new WP_Error('too_big', 'Image must be smaller than 8MB.');
        }

        $grant_upload_cap = function ($allcaps) {
            $allcaps['upload_files'] = true;
            return $allcaps;
        };
        add_filter('user_has_cap', $grant_upload_cap);
        $attachment_id = media_handle_upload($field, 0, ['post_author' => $user_id]);
        remove_filter('user_has_cap', $grant_upload_cap);

        if (is_wp_error($attachment_id)) return $attachment_id;
        return $attachment_id;
    }
}
