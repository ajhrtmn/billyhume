<?php
if (!defined('ABSPATH')) exit;

/**
 * One tiny, honest piece: recognizing a PLACEHOLDER ISRC so the rest of
 * the plugin (the admin metabox's mock-flag persistence, class-
 * player.php's SEO output) never has to duplicate the pattern check.
 *
 * Real ISRC issuance needs Own Ur Shit itself to be a registered ISRC
 * registrant with a national ISRC agency — a real institutional
 * application, not something this class can do. Until that exists,
 * this only recognizes/generates a clearly-fake placeholder so a
 * track's rights metadata field can be exercised (UI, storage, schema
 * suppression) ahead of the real thing — AJ's own ask: build against
 * the shape now so swapping in a real issuer later is a small change,
 * not a rewrite.
 *
 * Country code "ZZ" is deliberate, not arbitrary: ISO 3166-1 formally
 * reserves ZZ (along with AA, QM-QZ, XA-XZ) as "user-assigned" — never
 * allocated to a real country — so a "ZZ..." code can never collide
 * with or be mistaken for a real-world ISRC once real issuance exists.
 */
class BHS_ISRC {
    const MOCK_PATTERN = '/^ZZOUS\d{7}$/';

    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_bhs_isrc_registrant_save', [self::class, 'handle_registrant_save']);
    }

    public static function add_menu() {
        // Was parented under 'own-ur-shit' — a music-metadata/rights-
        // registration tool specific to this plugin's own tracks has no
        // business sitting in the cross-cutting ecosystem hub next to
        // Reports/Security/Metrics; it belongs with the rest of
        // Streaming's own admin surface, same as PRO Registration
        // (class-pro-wizard.php) right alongside it.
        add_submenu_page(BHS_PostTypes::MENU_PARENT, 'ISRC Registrant', 'ISRC Registrant', 'manage_options', 'bhs-isrc-registrant', [self::class, 'render_registrant_page']);
    }

    public static function render_registrant_page() {
        if (!current_user_can('manage_options')) wp_die('Not allowed.', '', ['response' => 403, 'back_link' => true]);
        $r = self::registrant();

        echo '<div class="wrap"><h1>ISRC Registrant</h1>';
        echo '<p class="description">Part of the Own Ur Shit ecosystem — see bh-streaming/README.md.</p>';

        echo '<div class="bhy-alert" style="border-left:3px solid #2271b1;background:#f6f7f7;padding:14px 16px;margin:16px 0;max-width:760px;">';
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

    public static function handle_registrant_save() {
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

    public static function is_mock($isrc) {
        return (bool) preg_match(self::MOCK_PATTERN, (string) $isrc);
    }

    /**
     * The registrant record an artist fills in once they've actually
     * completed the real, offline application to a national ISRC
     * agency — this class has no way to do that application itself,
     * only to generate correctly-shaped codes once it's done. Shape:
     * ['country' => 'US', 'registrant' => 'ABC', 'status' =>
     * 'not_registered'|'applied'|'registered'].
     */
    public static function registrant() {
        $default = ['country' => '', 'registrant' => '', 'status' => 'not_registered'];
        $saved = get_option('bhs_isrc_registrant', []);
        return is_array($saved) ? array_merge($default, $saved) : $default;
    }

    public static function is_real_registrant_configured() {
        $r = self::registrant();
        return $r['status'] === 'registered' && strlen($r['country']) === 2 && preg_match('/^[A-Za-z0-9]{3}$/', $r['registrant']);
    }

    /**
     * Issues one new ISRC — a REAL, correctly-shaped code if a
     * registrant is configured, a mock/placeholder one otherwise. This
     * is the one place either kind of code gets generated, server-
     * side, specifically so real issuance can enforce real uniqueness
     * (a real ISRC's designation code is THIS registrant's own
     * responsibility to keep unique — get_option()+update_option() as
     * a simple atomic-enough counter is the right weight for the
     * volume one artist's own catalog will ever hit, not a reason to
     * add a database table for a single incrementing integer).
     */
    public static function issue() {
        if (self::is_real_registrant_configured()) {
            $r = self::registrant();
            $year = gmdate('y');
            $seq = (int) get_option('bhs_isrc_sequence_' . $year, 0) + 1;
            update_option('bhs_isrc_sequence_' . $year, $seq);
            return strtoupper($r['country']) . strtoupper($r['registrant']) . $year . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
        }

        // Mock path — collision-checked against real existing rows
        // rather than trusting Math.random() alone (the previous,
        // client-only version of this had no such check). A handful of
        // retries is more than enough headroom for one artist's own
        // catalog size; this isn't guarding against adversarial load.
        global $wpdb;
        for ($i = 0; $i < 20; $i++) {
            $candidate = 'ZZOUS' . gmdate('y') . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_bhs_isrc' AND meta_value = %s", $candidate
            ));
            if (!$exists) return $candidate;
        }
        // Practically unreachable (100,000 designation codes per year,
        // one artist's own catalog) — a timestamp-suffixed fallback
        // rather than a hard failure either way.
        return 'ZZOUS' . gmdate('y') . substr((string) time(), -5);
    }
}
