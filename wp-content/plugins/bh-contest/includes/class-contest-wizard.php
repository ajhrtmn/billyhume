<?php
if (!defined('ABSPATH')) exit;

/**
 * "It just works" guided contest creation — VISION.md's own design
 * principle (see own-ur-shit's OUS_MediaWizard for the reference
 * implementation this follows) applied to the single most confusing
 * screen in this whole ecosystem: the real "Add New Contest" edit
 * screen spans 7 metaboxes and 30+ interdependent fields (submission
 * window, voting window, per-category votes, contact-info rules,
 * judging format, elimination rounds, Discord webhook, branding).
 * Confirmed by direct code read, not guessed.
 *
 * This wizard deliberately covers only the fields a contest ALWAYS
 * needs to actually run (name, submission window, voting window,
 * categories, vote counts, judging format) and leaves the genuinely
 * advanced/rare stuff — elimination rounds, Discord notifications,
 * branding overrides, contact-field customization — on the real edit
 * screen, reached via "Advanced settings" immediately after creation.
 * That split is deliberate, not a shortcut: those fields have sensible
 * defaults a first-time contest doesn't need to touch.
 *
 * Does NOT duplicate BH_Admin::save_contest_meta()'s validation/
 * sanitization logic (categories parsing, rubric parsing, contact-
 * field defaults) — it populates $_POST with the exact same field
 * names that screen posts and creates the contest via wp_insert_post(),
 * which fires the real save_post_bh_contest hook and runs the real
 * save handler. One save path, one place bugs get fixed, not two
 * copies that can drift.
 */
class BH_ContestWizard {
    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_bh_contest_wizard_save', [self::class, 'handle_save']);
        add_action('admin_notices', [self::class, 'maybe_show_created_notice']);
    }

    public static function maybe_show_created_notice() {
        if (empty($_GET['bh_wizard_created']) || get_current_screen()->id !== 'bh_contest') return;
        echo '<div class="notice notice-success is-dismissible"><p><strong>Contest created.</strong> The basics are set — rounds, Discord notifications, contact-field customization, and branding are all below with sensible defaults if you want to go further.</p></div>';
    }

    public static function add_menu() {
        add_submenu_page('edit.php?post_type=bh_contest', 'New Contest (Guided)', 'New Contest (Guided)', 'edit_posts', 'bh-contest-wizard', [self::class, 'render']);
    }

    public static function render() {
        if (!current_user_can('edit_posts')) wp_die('Not allowed.', '', ['response' => 403, 'back_link' => true]);

        echo '<div class="wrap"><h1>New Contest — Guided Setup</h1>';
        echo '<p class="description">Covers what every contest needs to run. Rounds, Discord notifications, contact-field customization, and branding all stay on the real edit screen with sensible defaults — reachable right after this, or any time later.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:640px;">';
        wp_nonce_field('bh_contest_wizard_save', 'bh_contest_wizard_nonce');
        echo '<input type="hidden" name="action" value="bh_contest_wizard_save">';

        echo '<h2>1. Name</h2>';
        echo '<p><input type="text" name="wiz_title" style="width:100%;" placeholder="e.g. Summer Anthem Contest" required></p>';

        echo '<h2>2. Submissions</h2>';
        echo '<p><label><input type="checkbox" name="wiz_sub_always_open" value="1" checked id="wiz_sub_always"> Open the moment this contest is published (recommended)</label></p>';
        echo '<div id="wiz_sub_dates" style="display:none;">';
        echo '<p>Opens: <input type="datetime-local" name="wiz_sub_start"></p>';
        echo '<p>Closes: <input type="datetime-local" name="wiz_sub_end" id="wiz_sub_end"></p>';
        echo '</div>';

        echo '<h2>3. Voting</h2>';
        echo '<p>Opens: <input type="datetime-local" name="wiz_vote_start" id="wiz_vote_start"> <button type="button" class="button button-small" id="wiz_vote_start_now">When submissions close</button></p>';
        echo '<p>Closes: <input type="datetime-local" name="wiz_vote_end"></p>';
        echo '<p class="description">Every voter gets ' . (int) BH_VOTE_BASE . ' base vote' . (BH_VOTE_BASE === 1 ? '' : 's') . ' per category, +' . (int) BH_VOTE_BONUS . ' more if they submitted a track — the site-wide default. Change per-contest any time from the real edit screen.</p>';

        echo '<h2>4. Categories <span class="description">(optional)</span></h2>';
        echo '<p class="description">One per line, e.g. "Best Vocals". Leave blank for a single, ordinary vote.</p>';
        echo '<textarea name="wiz_categories" rows="4" style="width:100%;font-family:inherit;"></textarea>';

        echo '<h2>5. Judging format</h2>';
        echo '<p><select name="wiz_format" id="wiz_format">';
        echo '<option value="public">Public voting (default)</option>';
        echo '<option value="judges">Judges only</option>';
        echo '<option value="hybrid">Hybrid — both, shown as two leaderboards</option>';
        echo '</select></p>';
        echo '<div id="wiz_judges_fields" style="display:none;">';
        echo '<p class="description">Rubric criteria, one per line — "Originality" (max 10) or "Originality:20" for a custom max.</p>';
        echo '<textarea name="wiz_rubric" rows="3" style="width:100%;font-family:inherit;"></textarea>';
        echo '<p class="description">Judges, one WordPress username per line — each needs an existing account.</p>';
        echo '<textarea name="wiz_judges" rows="3" style="width:100%;font-family:inherit;"></textarea>';
        echo '</div>';

        echo '<p style="margin-top:20px;"><button type="submit" class="button button-primary button-hero">Create contest</button></p>';
        echo '</form>';

        echo '<script>
        (function () {
            var always = document.getElementById("wiz_sub_always");
            var dates = document.getElementById("wiz_sub_dates");
            always.addEventListener("change", function () { dates.style.display = always.checked ? "none" : ""; });

            var startNow = document.getElementById("wiz_vote_start_now");
            startNow.addEventListener("click", function () {
                var subEnd = document.getElementById("wiz_sub_end").value;
                var voteStart = document.getElementById("wiz_vote_start");
                if (subEnd) voteStart.value = subEnd;
                else alert("Set a submissions close date first, or enter the voting start time directly.");
            });

            var format = document.getElementById("wiz_format");
            var judgesFields = document.getElementById("wiz_judges_fields");
            format.addEventListener("change", function () {
                judgesFields.style.display = format.value === "public" ? "none" : "";
            });
        })();
        </script>';

        echo '</div>';
    }

    public static function handle_save() {
        if (!current_user_can('edit_posts') || !isset($_POST['bh_contest_wizard_nonce']) || !wp_verify_nonce($_POST['bh_contest_wizard_nonce'], 'bh_contest_wizard_save')) {
            wp_die('Security check failed.', '', ['response' => 403, 'back_link' => true]);
        }

        $title = sanitize_text_field(wp_unslash($_POST['wiz_title'] ?? ''));
        if ($title === '') wp_die('A contest name is required.', '', ['response' => 400, 'back_link' => true]);

        // Same real save path the raw edit screen uses (BH_Admin::
        // save_contest_meta(), hooked on save_post_bh_contest) — this
        // class's own docblock explains why populating $_POST with that
        // screen's own field names and letting wp_insert_post() fire the
        // real hook is deliberate, not a shortcut.
        $_POST['bh_contest_nonce'] = wp_create_nonce('bh_save_contest');
        $_POST['bh_share_card_style'] = 'brand';
        // Never published from creation, always an explicit later step
        // — the real handler checks isset(), not truthiness
        // (`isset($_POST['bh_results_published']) ? '1' : '0'`), so this
        // has to be UNSET, not set to '' or false. Caught live: setting
        // it to '' still saved _bh_results_published as '1'.
        unset($_POST['bh_results_published']);
        $_POST['bh_vote_base'] = BH_VOTE_BASE;
        $_POST['bh_vote_bonus'] = BH_VOTE_BONUS;

        // The documented default contact-info config (BH_Helpers::
        // contact_config()'s own $defaults) written explicitly rather
        // than left to "no meta yet" — save_contest_meta() unconditionally
        // writes _bh_contact_config on every save, so leaving this unset
        // would silently persist an EMPTY shown-fields list instead of
        // preserving the intended default.
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
            wp_die('Could not create the contest: ' . esc_html($new_id->get_error_message()), '', ['response' => 500, 'back_link' => true]);
        }

        wp_safe_redirect(admin_url('post.php?post=' . $new_id . '&action=edit&bh_wizard_created=1'));
        exit;
    }
}
