<?php
if (!defined('ABSPATH')) exit;

/**
 * ISRC issuance and placeholder recognition, so the metabox and SEO output
 * share one pattern check. Real codes need the site owner to be a registered
 * ISRC registrant; until then, mock codes use country "ZZ", which ISO 3166-1
 * reserves as user-assigned, so they can never collide with a real ISRC.
 */
class BHS_ISRC {
    const MOCK_PATTERN = '/^ZZOUS\d{7}$/';

    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_bhs_isrc_registrant_save', [self::class, 'handle_registrant_save']);
    }

    public static function add_menu(): void {
        // Lives under Streaming's admin menu, alongside PRO Registration.
        add_submenu_page(BHS_PostTypes::MENU_PARENT, 'ISRC Registrant', 'ISRC Registrant', 'manage_options', 'bhs-isrc-registrant', [self::class, 'render_registrant_page']);
    }

    public static function render_registrant_page(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.', '', ['response' => 403, 'back_link' => true]);
        $r = self::registrant();

        echo '<div class="wrap"><h1>ISRC Registrant</h1>';
        echo '<p class="description">Part of the Self-Hosted Self ecosystem — see bh-streaming/README.md.</p>';

        echo '<div class="bhy-alert bhy-alert-info" style="max-width:760px;">';
        echo '<p><strong>What this actually is:</strong> every real ISRC starts with a "registrant code" — a 2-letter country code plus a 3-character registrant identifier — issued to ONE organization by a national ISRC agency after a real, offline application. This page has nowhere to click "apply" from, on purpose: the current live application process for each country wasn\'t independently re-verified in this session, so rather than link to a URL that might be stale or wrong, the honest thing is to say plainly — search "[your country] ISRC registrant application" for your own national agency, complete that application yourself, and come back here once you have a real registrant code to enter below. Until then, every track keeps using the clearly-fake placeholder codes (BHS_ISRC::issue()\'s mock path).</p>';
        echo '</div>';

        if ($r['status'] === 'registered' && $r['country'] && $r['registrant']) {
            echo '<div class="notice notice-success" style="padding:12px;"><p><strong>Real registrant on file:</strong> ' . esc_html($r['country'] . $r['registrant']) . ' — new "Generate ISRC" clicks now issue real, sequential codes under this prefix instead of placeholders.</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:480px;">';
        wp_nonce_field('bhs_isrc_registrant_save', 'bhs_isrc_registrant_nonce');
        echo '<input type="hidden" name="action" value="bhs_isrc_registrant_save">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label>Status</label></th><td><select name="bhs_isrc_status">';
        foreach (['not_registered' => 'Not registered yet', 'applied' => 'Applied — waiting to hear back', 'registered' => 'Registered — I have a real registrant code'] as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($r['status'], $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th><label>Country code</label></th><td><input type="text" name="bhs_isrc_country" value="' . esc_attr($r['country']) . '" maxlength="2" style="width:60px;text-transform:uppercase;" placeholder="US"></td></tr>';
        echo '<tr><th><label>Registrant code</label></th><td><input type="text" name="bhs_isrc_registrant" value="' . esc_attr($r['registrant']) . '" maxlength="3" style="width:80px;text-transform:uppercase;" placeholder="ABC"></td></tr>';
        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-primary">Save</button></p>';
        echo '</form></div>';
    }

    public static function handle_registrant_save(): void {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_POST['bhs_isrc_registrant_nonce'] ?? '', 'bhs_isrc_registrant_save')) {
            wp_die('Security check failed.', '', ['response' => 403, 'back_link' => true]);
        }
        $status = sanitize_key($_POST['bhs_isrc_status'] ?? 'not_registered');
        if (!in_array($status, ['not_registered', 'applied', 'registered'], true)) $status = 'not_registered';
        $country = strtoupper(preg_replace('/[^A-Za-z]/', '', $_POST['bhs_isrc_country'] ?? ''));
        $registrant = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['bhs_isrc_registrant'] ?? ''));

        update_option('bhs_isrc_registrant', [
            'country' => substr($country, 0, 2),
            'registrant' => substr($registrant, 0, 3),
            'status' => $status,
        ]);

        wp_safe_redirect(admin_url('admin.php?page=bhs-isrc-registrant'));
        exit;
    }

    /** @param mixed $isrc */
    public static function is_mock($isrc): bool {
        return (bool) preg_match(self::MOCK_PATTERN, (string) $isrc);
    }

    /**
     * Registrant record, filled in after the offline application to a
     * national ISRC agency.
     */
    /** @return array{country:string, registrant:string, status:string} */
    public static function registrant(): array {
        $default = ['country' => '', 'registrant' => '', 'status' => 'not_registered'];
        $saved = get_option('bhs_isrc_registrant', []);
        return is_array($saved) ? array_merge($default, $saved) : $default;
    }

    public static function is_real_registrant_configured(): bool {
        $r = self::registrant();
        return $r['status'] === 'registered' && strlen($r['country']) === 2 && preg_match('/^[A-Za-z0-9]{3}$/', $r['registrant']);
    }

    /**
     * Issues one ISRC: real if a registrant is configured, mock otherwise.
     * The designation counter is a plain option; enough for one catalog.
     */
    public static function issue(): string {
        if (self::is_real_registrant_configured()) {
            $r = self::registrant();
            $year = gmdate('y');
            $seq = (int) get_option('bhs_isrc_sequence_' . $year, 0) + 1;
            update_option('bhs_isrc_sequence_' . $year, $seq);
            return strtoupper($r['country']) . strtoupper($r['registrant']) . $year . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
        }

        // Mock path: collision-checked against existing rows, with a few retries.
        global $wpdb;
        for ($i = 0; $i < 20; $i++) {
            $candidate = 'ZZOUS' . gmdate('y') . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_bhs_isrc' AND meta_value = %s", $candidate
            ));
            if (!$exists) return $candidate;
        }
        // Practically unreachable; fall back to a timestamp suffix, not a failure.
        return 'ZZOUS' . gmdate('y') . substr((string) time(), -5);
    }
}
