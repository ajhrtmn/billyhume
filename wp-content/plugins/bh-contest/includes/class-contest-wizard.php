<?php
if (!defined('ABSPATH')) exit;

/**
 * Guided contest creation for the essentials (name, windows, categories, votes, judging).
 * WHY: fills $_POST with the edit screen field names and calls wp_insert_post(), so
 * save_contest_meta() stays the single save path.
 */
class BH_ContestWizard {
    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_bh_contest_wizard_save', [self::class, 'handle_save']);
        add_action('admin_notices', [self::class, 'maybe_show_created_notice']);
    }

    public static function maybe_show_created_notice(): void {
        if (empty($_GET['bh_wizard_created']) || get_current_screen()->id !== 'bh_contest') return;
        echo ('<div class="notice notice-success is-dismissible"><p><strong>' . BHCON_Words::get('admin.contest_wizard.contest_created') . '</strong> ' . BHCON_Words::get('admin.contest_wizard.basics_are_set_rounds_discord') . '</p></div>');
    }

    public static function add_menu(): void {
        add_submenu_page('edit.php?post_type=bh_contest', 'New Contest (Guided)', 'New Contest (Guided)', 'edit_posts', 'bh-contest-wizard', [self::class, 'render']);
    }

    public static function render(): void {
        if (!current_user_can('edit_posts')) wp_die('Not allowed.', '', ['response' => 403, 'back_link' => true]);

        echo ('<div class="wrap"><h1>' . BHCON_Words::get('admin.contest_wizard.new_contest_guided_setup') . '</h1>');
        echo ('<p class="description">' . BHCON_Words::get('admin.contest_wizard.covers_what_every_contest_needs') . '</p>');

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:640px;">';
        wp_nonce_field('bh_contest_wizard_save', 'bh_contest_wizard_nonce');
        echo '<input type="hidden" name="action" value="bh_contest_wizard_save">';

        echo ('<h2>' . BHCON_Words::get('admin.contest_wizard.1_name') . '</h2>');
        echo '<p><input type="text" name="wiz_title" style="width:100%;" placeholder="e.g. Summer Anthem Contest" required></p>';

        echo ('<h2>' . BHCON_Words::get('admin.contest_wizard.2_submissions') . '</h2>');
        echo ('<p><label><input type="checkbox" name="wiz_sub_always_open" value="1" checked id="wiz_sub_always"> ' . BHCON_Words::get('admin.contest_wizard.open_moment_contest_published_recommende') . '</label></p>');
        echo '<div id="wiz_sub_dates" style="display:none;">';
        echo ('<p>' . BHCON_Words::get('admin.admin_metaboxes.opens') . ' <input type="datetime-local" name="wiz_sub_start"></p>');
        echo ('<p>' . BHCON_Words::get('admin.contest_wizard.closes') . ' <input type="datetime-local" name="wiz_sub_end" id="wiz_sub_end"></p>');
        echo '</div>';

        echo ('<h2>' . BHCON_Words::get('admin.contest_wizard.3_voting') . '</h2>');
        echo ('<p>' . BHCON_Words::get('admin.admin_metaboxes.opens') . ' <input type="datetime-local" name="wiz_vote_start" id="wiz_vote_start"> <button type="button" class="button button-small" id="wiz_vote_start_now">' . BHCON_Words::get('admin.admin_metaboxes.when_submissions_close') . '</button></p>');
        echo ('<p>' . BHCON_Words::get('admin.contest_wizard.closes') . ' <input type="datetime-local" name="wiz_vote_end"></p>');
        echo ('<p class="description">' . BHCON_Words::get('admin.contest_wizard.every_voter_gets') . ' ') . (int) BH_VOTE_BASE . ' base vote' . (BH_VOTE_BASE === 1 ? '' : 's') . ' ' . BHCON_Words::get('admin.contest_wizard.per_category') . (int) BH_VOTE_BONUS . (' ' . BHCON_Words::get('admin.contest_wizard.more_if_they_submitted_track') . '</p>');

        echo ('<h2>' . BHCON_Words::get('admin.contest_wizard.4_categories') . ' <span class="description">(optional)</span></h2>');
        echo ('<p class="description">' . BHCON_Words::get('admin.contest_wizard.one_per_line_e_g') . '</p>');
        echo '<textarea name="wiz_categories" rows="4" style="width:100%;font-family:inherit;"></textarea>';

        echo ('<h2>' . BHCON_Words::get('admin.contest_wizard.5_judging_format') . '</h2>');
        echo '<p><select name="wiz_format" id="wiz_format">';
        echo ('<option value="public">' . BHCON_Words::get('admin.contest_wizard.public_voting_default') . '</option>');
        echo ('<option value="judges">' . BHCON_Words::get('admin.contest_wizard.judges_only') . '</option>');
        echo ('<option value="hybrid">' . BHCON_Words::get('admin.contest_wizard.hybrid_both_shown_as_two') . '</option>');
        echo '</select></p>';
        echo '<div id="wiz_judges_fields" style="display:none;">';
        echo ('<p class="description">' . BHCON_Words::get('admin.contest_wizard.rubric_criteria_one_per_line') . '</p>');
        echo '<textarea name="wiz_rubric" rows="3" style="width:100%;font-family:inherit;"></textarea>';
        echo ('<p class="description">' . BHCON_Words::get('admin.contest_wizard.judges_one_wordpress_username_per') . '</p>');
        echo '<textarea name="wiz_judges" rows="3" style="width:100%;font-family:inherit;"></textarea>';
        echo '</div>';

        echo ('<p style="margin-top:20px;"><button type="submit" class="button button-primary button-hero">' . BHCON_Words::get('admin.contest_wizard.create_contest') . '</button></p>');
        echo '</form>';

        echo '<script>
' . (string) file_get_contents(BH_PATH . 'assets/inline/contest-wizard.js') . '</script>';

        echo '</div>';
    }

    public static function handle_save(): void {
        if (!OUS_AdminGuard::verify_nonce_and_cap('edit_posts', $_POST['bh_contest_wizard_nonce'] ?? '', 'bh_contest_wizard_save')) {
            wp_die(BHCON_Words::get('admin.contest_wizard.security_check_failed'), '', ['response' => 403, 'back_link' => true]);
        }

        $title = sanitize_text_field(wp_unslash($_POST['wiz_title'] ?? ''));
        if ($title === '') wp_die(BHCON_Words::get('admin.contest_wizard.contest_name_required'), '', ['response' => 400, 'back_link' => true]);

        // Feeds save_contest_meta() through $_POST (see class docblock).
        $_POST['bh_contest_nonce'] = wp_create_nonce('bh_save_contest');
        $_POST['bh_share_card_style'] = 'brand';
        // WHY: must be unset, not '': the save handler checks isset().
        unset($_POST['bh_results_published']);
        $_POST['bh_vote_base'] = BH_VOTE_BASE;
        $_POST['bh_vote_bonus'] = BH_VOTE_BONUS;

        // WHY: save_contest_meta() always writes _bh_contact_config, so posting
        // nothing would save an empty shown-fields list instead of the defaults.
        $_POST['bh_contact_show'] = BH_Helpers::CONTACT_FIELDS;
        $_POST['bh_require_real_name'] = '1';
        $_POST['bh_require_handle'] = '1';

        if (!empty($_POST['wiz_sub_always_open'])) {
            $_POST['bh_sub_always_open'] = '1';
        } else {
            $_POST['bh_sub_start'] = sanitize_text_field($_POST['wiz_sub_start'] ?? '');
            $_POST['bh_sub_end']   = sanitize_text_field($_POST['wiz_sub_end'] ?? '');
        }
        $_POST['bh_start'] = sanitize_text_field($_POST['wiz_vote_start'] ?? '');
        $_POST['bh_end']   = sanitize_text_field($_POST['wiz_vote_end'] ?? '');
        $_POST['bh_categories'] = wp_unslash($_POST['wiz_categories'] ?? '');

        $format = in_array($_POST['wiz_format'] ?? '', ['judges', 'hybrid'], true) ? $_POST['wiz_format'] : 'public';
        $_POST['bh_contest_format'] = $format;
        if ($format !== 'public') {
            $_POST['bh_rubric'] = wp_unslash($_POST['wiz_rubric'] ?? '');
            $_POST['bh_judges'] = wp_unslash($_POST['wiz_judges'] ?? '');
        }

        $new_id = wp_insert_post([
            'post_type'   => 'bh_contest',
            'post_status' => 'publish',
            'post_title'  => $title,
        ], true);

        if (is_wp_error($new_id)) {
            wp_die(BHCON_Words::get('admin.contest_wizard.could_not_create_contest') . ' ' . esc_html($new_id->get_error_message()), '', ['response' => 500, 'back_link' => true]);
        }

        wp_safe_redirect(admin_url('post.php?post=' . $new_id . '&action=edit&bh_wizard_created=1'));
        exit;
    }
}
