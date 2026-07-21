<?php
if (!defined('ABSPATH')) exit;

/**
 * PRO (Performing Rights Organization) registration — a guided links-
 * plus-storage flow, scoped and named in this plugin's own README.
 *
 * Deliberately NOT the same shape as OUS_MediaWizard: that wizard can
 * do live, real credential validation (an actual S3 headBucket() call)
 * because it's wrapping an API. A PRO has no public membership-
 * verification API — ASCAP/BMI don't expose "is this IPI number real"
 * as a service, and SESAC/GMR are invitation-only and don't even have
 * a self-serve signup page to link to. So this is honestly a THINNER
 * tool than the media wizard: explain the landscape, link out to each
 * PRO's own real site (verified live before writing this, not
 * guessed), and give the artist a place to record their own
 * affiliation once they've done that elsewhere. No "test connection"
 * step exists here because there's nothing this code can verify.
 *
 * Stores a single site option (bhs_pro_affiliation) rather than a
 * per-track/per-user record — PRO affiliation is a fact about the
 * RIGHTS HOLDER (the artist running this site), not about any one
 * recording, matching how ISRC (per-track, BHS_ISRC) and PRO
 * affiliation (site-wide) are genuinely different shapes of fact.
 */
class BHS_PROWizard {
    const PROS = [
        'ascap' => [
            'name' => 'ASCAP',
            'open' => true,
            'url' => 'https://www.ascap.com',
            'note' => 'Open direct signup for songwriters — a one-time application fee applies (historically around $50, confirm current pricing on their site).',
        ],
        'bmi' => [
            'name' => 'BMI',
            'open' => true,
            'url' => 'https://www.bmi.com',
            'note' => 'Open direct signup for songwriters, free to join.',
        ],
        'sesac' => [
            'name' => 'SESAC',
            'open' => false,
            'url' => 'https://www.sesac.com',
            'note' => 'Invitation-only. SESAC states it does not accept unsolicited applications — typically a manager, lawyer, or agent has to make contact on your behalf.',
        ],
        'gmr' => [
            'name' => 'Global Music Rights (GMR)',
            'open' => false,
            'url' => 'https://globalmusicrights.com',
            'note' => 'Invitation-only, a small roster of high-profile writers. No public self-serve signup.',
        ],
        'other' => [
            'name' => 'Other / not US-based',
            'open' => true,
            'url' => '',
            'note' => 'PRS for Music (UK), SOCAN (Canada), APRA AMCOS (Australia), GEMA (Germany), and others each run their own national society — search for your own country\'s PRO directly.',
        ],
    ];

    const STATUSES = [
        'not_started' => 'Not started',
        'applied' => 'Applied / waiting to hear back',
        'affiliated' => 'Affiliated — membership confirmed',
    ];

    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_bhs_pro_wizard_save', [self::class, 'handle_save']);
    }

    public static function add_menu() {
        // Was parented under 'own-ur-shit' — same reasoning as ISRC
        // Registrant's own move (class-isrc.php): a rights-registration
        // tool specific to this plugin's own tracks belongs with the
        // rest of Streaming's own admin surface, not the cross-cutting
        // ecosystem hub.
        add_submenu_page(BHS_PostTypes::MENU_PARENT, 'PRO Registration', 'PRO Registration', 'manage_options', 'bhs-pro-wizard', [self::class, 'render']);
    }

    public static function render() {
        if (!current_user_can('manage_options')) wp_die('Not allowed.', '', ['response' => 403, 'back_link' => true]);

        $current = get_option('bhs_pro_affiliation', ['pro' => '', 'name' => '', 'ipi' => '', 'status' => 'not_started']);

        echo '<div class="wrap"><h1>PRO Registration</h1>';
        echo '<p class="description">Part of the Own Ur Shit ecosystem — see bh-streaming/README.md for the full plan this implements.</p>';

        echo '<div class="bhy-alert" style="border-left:3px solid #2271b1;background:#f6f7f7;padding:14px 16px;margin:16px 0;max-width:760px;">';
        echo '<p><strong>What a PRO actually does, briefly:</strong> a Performing Rights Organization (PRO) collects royalties when your SONGWRITING (the composition, not the recording) gets performed publicly — radio, streaming, live venues, TV. It\'s a separate thing from the ISRC on your track (which identifies the recording, not the composition) — a PRO assigns your composition its own ISWC once you\'re registered and the work is logged. You can only be affiliated with ONE PRO at a time as a songwriter.</p>';
        echo '</div>';

        if ($current['status'] !== 'not_started' && $current['pro']) {
            $label = self::PROS[$current['pro']]['name'] ?? $current['pro'];
            echo '<div class="notice notice-success" style="padding:12px;"><p><strong>On file:</strong> ' . esc_html($label) . ' — ' . esc_html(self::STATUSES[$current['status']] ?? $current['status']) . (($current['ipi'] ?? '') ? ' (IPI/CAE: ' . esc_html($current['ipi']) . ')' : '') . '</p></div>';
        }

        echo '<h2>1. Pick a PRO</h2>';
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;max-width:760px;">';
        foreach (self::PROS as $key => $p) {
            echo '<div style="border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;background:#fff;">';
            echo '<strong>' . esc_html($p['name']) . '</strong>' . ($p['open'] ? ' <span style="background:#1DB954;color:#fff;font-size:11px;padding:2px 8px;border-radius:999px;">Open signup</span>' : ' <span style="background:#787c82;color:#fff;font-size:11px;padding:2px 8px;border-radius:999px;">Invitation-only</span>');
            echo '<p class="description" style="margin:6px 0;">' . esc_html($p['note']) . '</p>';
            if ($p['url']) echo '<p><a class="button" href="' . esc_url($p['url']) . '" target="_blank" rel="noopener">&rarr; ' . esc_html($p['name']) . '</a></p>';
            echo '</div>';
        }
        echo '</div>';

        echo '<h2>2. Once you\'ve registered, record it here</h2>';
        echo '<p class="description">No PRO exposes a way to verify this automatically — this is just your own record, same way you\'d jot it in a notes app, but somewhere the rest of this site can eventually reference it.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:520px;">';
        wp_nonce_field('bhs_pro_wizard_save', 'bhs_pro_wizard_nonce');
        echo '<input type="hidden" name="action" value="bhs_pro_wizard_save">';

        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label>PRO</label></th><td><select name="bhs_pro">';
        foreach (self::PROS as $key => $p) {
            echo '<option value="' . esc_attr($key) . '"' . selected($current['pro'], $key, false) . '>' . esc_html($p['name']) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th><label>Status</label></th><td><select name="bhs_pro_status">';
        foreach (self::STATUSES as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($current['status'], $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th><label>IPI/CAE number</label></th><td><input type="text" name="bhs_pro_ipi" value="' . esc_attr($current['ipi'] ?? '') . '" style="width:100%;" placeholder="Shown on your PRO membership confirmation, optional until affiliated"></td></tr>';
        echo '</tbody></table>';

        echo '<p><button type="submit" class="button button-primary">Save</button></p>';
        echo '</form>';

        echo '</div>';
    }

    public static function handle_save() {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_POST['bhs_pro_wizard_nonce'] ?? '', 'bhs_pro_wizard_save')) {
            wp_die('Security check failed.', '', ['response' => 403, 'back_link' => true]);
        }

        $pro = sanitize_key($_POST['bhs_pro'] ?? '');
        if (!isset(self::PROS[$pro])) $pro = '';
        $status = sanitize_key($_POST['bhs_pro_status'] ?? 'not_started');
        if (!isset(self::STATUSES[$status])) $status = 'not_started';

        update_option('bhs_pro_affiliation', [
            'pro' => $pro,
            'name' => self::PROS[$pro]['name'] ?? '',
            'status' => $status,
            'ipi' => sanitize_text_field($_POST['bhs_pro_ipi'] ?? ''),
            'updated_at' => current_time('mysql'),
        ]);

        wp_safe_redirect(admin_url('admin.php?page=bhs-pro-wizard'));
        exit;
    }
}
