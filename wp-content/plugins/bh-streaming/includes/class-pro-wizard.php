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
            // Audit fix (2026-07-25): tried to verify a direct join-page
            // URL the same way bmi.com's was confirmed below — ascap.com
            // blocks automated fetches (403), so this stays the homepage
            // rather than risk shipping a guessed deep link. The note
            // text below points to where to look instead.
            'url' => 'https://www.ascap.com',
            'note' => 'Open direct signup for songwriters — look for "Join" or "Membership" in their site nav. A one-time application fee applies (historically around $50, confirm current pricing on their site).',
        ],
        'bmi' => [
            'name' => 'BMI',
            'open' => true,
            // Verified live 2026-07-25 — bmi.com's own nav labels this exact URL "Join BMI".
            'url' => 'https://www.bmi.com/creators#join',
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

    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_bhs_pro_wizard_save', [self::class, 'handle_save']);
        add_action('admin_post_bhs_export_royalty_report', [self::class, 'handle_export_royalty_report']);
    }

    // A CSV export for a PRO/MLC royalty claim, built from
    // bh-monetization-woo's anchored purchase ledger (ROADMAP-
    // streaming-media-scope-and-blockchain.md Part 2's "the real
    // integration is a report, not an API" conclusion — no PRO exposes
    // a submission API, they all just want a usage report attached to a
    // manual claim). Deliberately guarded, not a hard dependency: this
    // whole page already works with no bh-monetization-woo installed,
    // this export just doesn't appear until there's ledger data to
    // export.
    /**
     * bh-monetization-woo is optional to this plugin, so every read of its
     * ledger is guarded at call time, never at file-parse time.
     */
    private static function ledger_available(): bool {
        return class_exists('BHM_PurchaseLedger') && BHM_PurchaseLedger::is_available();
    }

    public static function handle_export_royalty_report(): void {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_GET['_wpnonce'] ?? '', 'bhs_export_royalty_report')) {
            wp_die('Security check failed.', '', ['response' => 403, 'back_link' => true]);
        }
        if (!self::ledger_available()) {
            wp_die('No purchase ledger found — BH Monetization isn\'t active or no purchases have been anchored yet.', '', ['back_link' => true]);
        }
        $rows = BHM_PurchaseLedger::confirmed_purchases();

        $affiliation = get_option('bhs_pro_affiliation', ['pro' => '', 'name' => '', 'ipi' => '']);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="royalty-report-' . gmdate('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['PRO/registrant', self::csv_safe($affiliation['name'] ?? ''), 'IPI/CAE', self::csv_safe($affiliation['ipi'] ?? '')]);
        fputcsv($out, []);
        fputcsv($out, ['Track', 'ISRC', 'Purchase date', 'Price', 'Anchor status', 'Record hash']);
        foreach ($rows as $row) {
            $track_title = $row->track_id ? (get_the_title($row->track_id) ?: ('#' . $row->track_id)) : 'Unknown';
            $isrc = $row->track_id ? get_post_meta($row->track_id, '_bhs_isrc', true) : '';
            fputcsv($out, [
                self::csv_safe($track_title), self::csv_safe($isrc), mysql2date('Y-m-d', $row->created_at),
                number_format($row->price_cents / 100, 2), $row->anchor_status, $row->record_hash,
            ]);
        }
        fclose($out);
        exit;
    }

    // Guards against CSV/formula injection (a track title or affiliation
    // name starting with =, +, -, @, tab, or CR would otherwise be
    // interpreted as a formula by Excel/Sheets when this file is opened) —
    // this export is explicitly meant to be forwarded to a third-party
    // PRO/MLC, so a malicious track title is a real cross-trust-boundary risk.
    /** @param mixed $value */
    private static function csv_safe($value): string {
        $value = (string) $value;
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
            return "'" . $value;
        }
        return $value;
    }

    public static function add_menu(): void {
        // Was parented under 'own-ur-shit' — same reasoning as ISRC
        // Registrant's own move (class-isrc.php): a rights-registration
        // tool specific to this plugin's own tracks belongs with the
        // rest of Streaming's own admin surface, not the cross-cutting
        // ecosystem hub.
        add_submenu_page(BHS_PostTypes::MENU_PARENT, 'PRO Registration', 'PRO Registration', 'manage_options', 'bhs-pro-wizard', [self::class, 'render']);
    }

    // Audit fix (2026-07-25) — restructured from one long page into a
    // real step-by-step flow (pick PRO -> confirm/register elsewhere ->
    // record affiliation), matching this ecosystem's "it just works"
    // wizard convention: one plain-language question per screen. The "no
    // verification API" limitation documented in this class's own
    // docblock only excuses skipping a live "test connection" step, not
    // skipping step-by-step structure entirely — those are two separate
    // things this used to conflate. An already-affiliated artist (or one
    // who wants to skip straight to the raw settings, per this
    // ecosystem's own "wizard is an on-ramp, not a wall" rule) lands on
    // the status/settings view directly rather than being forced back
    // through steps 1-2 every time.
    public static function render(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.', '', ['response' => 403, 'back_link' => true]);

        $current = get_option('bhs_pro_affiliation', ['pro' => '', 'name' => '', 'ipi' => '', 'status' => 'not_started']);
        $step = isset($_GET['step']) ? (int) $_GET['step'] : 0;
        $picked_pro = isset($_GET['pro']) && isset(self::PROS[sanitize_key($_GET['pro'])]) ? sanitize_key($_GET['pro']) : '';

        echo '<div class="wrap"><h1>PRO Registration</h1>';
        echo '<p class="description">Part of the The Self-Hosted Self ecosystem — see bh-streaming/README.md for the full plan this implements.</p>';

        echo '<div class="bhy-alert bhy-alert-info" style="max-width:760px;">';
        echo '<p><strong>What a PRO actually does, briefly:</strong> a Performing Rights Organization (PRO) collects royalties when your SONGWRITING (the composition, not the recording) gets performed publicly — radio, streaming, live venues, TV. It\'s a separate thing from the ISRC on your track (which identifies the recording, not the composition) — a PRO assigns your composition its own ISWC once you\'re registered and the work is logged. You can only be affiliated with ONE PRO at a time as a songwriter.</p>';
        echo '</div>';

        $has_affiliation = $current['status'] !== 'not_started' && $current['pro'];

        if ($step === 1 || (!$has_affiliation && $step === 0)) {
            self::render_step_pick_pro();
        } elseif ($step === 2 && $picked_pro) {
            self::render_step_confirm($picked_pro, $current);
        } elseif ($step === 3 && $picked_pro) {
            self::render_step_record($picked_pro, $current);
        } else {
            // Landing view for someone who already has an affiliation on
            // file — the settings underneath the wizard, reachable
            // directly, plus a way back into the guided flow to change it.
            self::render_status_and_settings($current);
        }

        echo '</div>';
    }

    // Step 1: "Where do you want your PRO affiliation?" — one plain
    // question, pick a card, nothing else on screen yet.
    private static function render_step_pick_pro(): void {
        $base = remove_query_arg(['step', 'pro'], admin_url('admin.php?page=bhs-pro-wizard'));
        echo '<h2>1. Pick a PRO</h2>';
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;max-width:760px;">';
        foreach (self::PROS as $key => $p) {
            echo '<div style="border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;background:#fff;">';
            echo '<strong>' . esc_html($p['name']) . '</strong>' . ($p['open'] ? ' <span style="background:#1DB954;color:#fff;font-size:11px;padding:2px 8px;border-radius:999px;">Open signup</span>' : ' <span style="background:#787c82;color:#fff;font-size:11px;padding:2px 8px;border-radius:999px;">Invitation-only</span>');
            echo '<p class="description" style="margin:6px 0;">' . esc_html($p['note']) . '</p>';
            echo '<p><a class="button button-primary" href="' . esc_url(add_query_arg(['step' => 2, 'pro' => $key], $base)) . '">This is mine &rarr;</a></p>';
            echo '</div>';
        }
        echo '</div>';
    }

    // Step 2: "Go register with them" — explains what to do next and why
    // (this tool can't do it for you), before asking for anything back.
    /** @param array<string, mixed> $current */
    private static function render_step_confirm(string $pro_key, array $current): void {
        $p = self::PROS[$pro_key];
        $base = remove_query_arg(['step', 'pro'], admin_url('admin.php?page=bhs-pro-wizard'));
        echo '<h2>2. Register with ' . esc_html($p['name']) . '</h2>';
        echo '<p class="description">' . esc_html($p['note']) . '</p>';
        if ($p['url']) {
            echo '<p><a class="button button-primary" href="' . esc_url($p['url']) . '" target="_blank" rel="noopener">&rarr; Open ' . esc_html($p['name']) . '</a></p>';
        }
        echo '<p class="description">No PRO exposes a way to verify this automatically — once you\'ve applied (or already have an account), come back and record it here.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(add_query_arg(['step' => 3, 'pro' => $pro_key], $base)) . '">I\'ve registered (or started) — continue &rarr;</a> ';
        echo '<a href="' . esc_url(add_query_arg(['step' => 1], $base)) . '">&larr; Pick a different PRO</a></p>';
    }

    // Step 3: the actual record-affiliation form, pre-filled with the
    // PRO picked in step 1 — the one place this screen asks for anything.
    /** @param array<string, mixed> $current */
    private static function render_step_record(string $pro_key, array $current): void {
        $base = remove_query_arg(['step', 'pro'], admin_url('admin.php?page=bhs-pro-wizard'));
        echo '<h2>3. Record your affiliation</h2>';
        echo '<p class="description">Your own record, same way you\'d jot it in a notes app, but somewhere the rest of this site can eventually reference it.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:520px;">';
        wp_nonce_field('bhs_pro_wizard_save', 'bhs_pro_wizard_nonce');
        echo '<input type="hidden" name="action" value="bhs_pro_wizard_save">';
        echo '<input type="hidden" name="bhs_pro" value="' . esc_attr($pro_key) . '">';

        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label>PRO</label></th><td>' . esc_html(self::PROS[$pro_key]['name']) . '</td></tr>';
        echo '<tr><th><label>Status</label></th><td><select name="bhs_pro_status">';
        foreach (self::STATUSES as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($current['pro'] === $pro_key ? $current['status'] : 'applied', $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th><label>IPI/CAE number</label></th><td><input type="text" name="bhs_pro_ipi" value="' . esc_attr($current['pro'] === $pro_key ? ($current['ipi'] ?? '') : '') . '" style="width:100%;" placeholder="Shown on your PRO membership confirmation, optional until affiliated"></td></tr>';
        echo '</tbody></table>';

        echo '<p><button type="submit" class="button button-primary">Save</button> ';
        echo '<a href="' . esc_url(add_query_arg(['step' => 2, 'pro' => $pro_key], $base)) . '">&larr; Back</a></p>';
        echo '</form>';
    }

    // Landing view once an affiliation is already on file — the raw
    // settings underneath the wizard, reachable directly (this
    // ecosystem's "wizard is an on-ramp, not a wall" rule), plus the
    // royalty export, plus a way back into the guided flow to change PRO.
    /** @param array<string, mixed> $current */
    private static function render_status_and_settings(array $current): void {
        $base = remove_query_arg(['step', 'pro'], admin_url('admin.php?page=bhs-pro-wizard'));
        $label = self::PROS[$current['pro']]['name'] ?? $current['pro'];
        echo '<div class="notice notice-success" style="padding:12px;"><p><strong>On file:</strong> ' . esc_html($label) . ' — ' . esc_html(self::STATUSES[$current['status']] ?? $current['status']) . (($current['ipi'] ?? '') ? ' (IPI/CAE: ' . esc_html($current['ipi']) . ')' : '') . '</p></div>';
        echo '<p><a class="button" href="' . esc_url(add_query_arg(['step' => 3, 'pro' => $current['pro']], $base)) . '">Edit affiliation</a> ';
        echo '<a class="button" href="' . esc_url(add_query_arg(['step' => 1], $base)) . '">Change PRO</a></p>';

        if (self::ledger_available()) {
            echo '<h2>Royalty report export</h2>';
            echo '<p class="description">A CSV of your anchored track/release sales — attach this to a manual royalty claim with your PRO or the MLC. Not a submission API (none of them offer one); this just saves compiling the report by hand.</p>';
            $url = wp_nonce_url(admin_url('admin-post.php?action=bhs_export_royalty_report'), 'bhs_export_royalty_report');
            echo '<p><a class="button" href="' . esc_url($url) . '">Download royalty report (CSV)</a></p>';
        }
    }

    public static function handle_save(): void {
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
