<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_SetupWizard: guided first-run sequence (welcome, activate ecosystem,
 * brand basics, get paid, done) reusing the existing install/activate
 * mechanics. Steps are computed from real current state, so satisfied steps
 * are skipped and the wizard stays useful to revisit.
 */
class OUS_SetupWizard {
    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_ous_wizard_activate_all', [self::class, 'handle_activate_all']);
        add_action('admin_post_ous_wizard_save_brand', [self::class, 'handle_save_brand']);
    }

    public static function add_menu(): void {
        // A real submenu item so it can be revisited later.
        add_submenu_page('ous', 'Guided Setup', 'Guided Setup', 'manage_options', 'ous-setup-wizard', [self::class, 'render']);
    }

    /* ---------------- step detection ---------------- */

    private static function ecosystem_fully_active(): bool {
        foreach (array_keys(OUS_Registry::all()) as $key) {
            if (OUS_Registry::status($key) !== 'active') return false;
        }
        return true;
    }

    // True while the wordmark still reads the "Your"/"Brand" defaults.
    private static function brand_still_default(): bool {
        $s = class_exists('BHY_Style') ? BHY_Style::get() : [];
        return ($s['brand_part1'] ?? '') === BHY_Style::DEFAULTS['brand_part1']
            && ($s['brand_part2'] ?? '') === BHY_Style::DEFAULTS['brand_part2']
            && empty($s['brand_logo_id']);
    }

    private static function current_step(): int {
        $requested = isset($_GET['step']) ? max(1, min(4, (int) $_GET['step'])) : 0;
        if ($requested) return $requested; // explicit navigation (Back/Next) always wins over auto-detection

        if (!self::ecosystem_fully_active()) return 2;
        if (self::brand_still_default()) return 3;
        return 4;
    }

    /* ---------------- rendering ---------------- */

    public static function render(): void {
        $step = self::current_step();
        echo '<div class="wrap ous-setup-wizard">';
        echo '<h1>Guided Setup</h1>';

        if (isset($_GET['ous_wizard_error'])) {
            echo '<div class="bhy-alert bhy-alert-danger"><p>Something didn\'t work — you can always finish this from the main <a href="' . esc_url(admin_url('admin.php?page=ous')) . '">The Self-Hosted Self dashboard</a> instead.</p></div>';
        }
        if (isset($_GET['ous_wizard_saved'])) {
            echo '<div class="bhy-alert bhy-alert-success"><p>Saved.</p></div>';
        }

        echo '<div class="bhy-card" style="max-width:640px;">';
        switch ($step) {
            case 2: self::render_step_activate(); break;
            case 3: self::render_step_brand(); break;
            case 4: self::render_step_done(); break;
            default: self::render_step_welcome(); break;
        }
        echo '</div>';
        echo '</div>';
    }

    private static function step_nav(int $current): void {
        echo '<p class="description" style="margin-top:24px;">Step ' . (int) $current . ' of 4';
        if ($current > 1) {
            echo ' — <a href="' . esc_url(admin_url('admin.php?page=ous-setup-wizard&step=' . ($current - 1))) . '">Back</a>';
        }
        echo '</p>';
    }

    private static function render_step_welcome(): void {
        echo '<h2>Welcome</h2>';
        echo '<p>This walks you through getting the whole The Self-Hosted Self ecosystem running on a fresh install: activating every piece, setting your brand basics, and confirming you\'re ready to get paid. Takes a few minutes — nothing here is a one-way door, you can change any of it later from the main dashboard or Design Suite.</p>';
        echo '<p><a class="button button-primary button-hero" href="' . esc_url(admin_url('admin.php?page=ous-setup-wizard&step=2')) . '">Get started</a></p>';
    }

    private static function render_step_activate(): void {
        $registry = OUS_Registry::visible_cards();
        $active = 0;
        $total = count($registry);
        foreach (array_keys($registry) as $key) {
            if (OUS_Registry::status($key) === 'active') $active++;
        }

        echo '<h2>Activate the ecosystem</h2>';
        if ($active >= $total) {
            echo '<p class="bhy-alert bhy-alert-success"><strong>All set —</strong> every registered piece is already active.</p>';
        } else {
            echo '<p>' . (int) $active . ' of ' . (int) $total . ' pieces are active. One click installs and activates everything else, in the right dependency order (e.g. WooCommerce before Supporter Tiers).</p>';
            $url = wp_nonce_url(admin_url('admin-post.php?action=ous_wizard_activate_all'), 'ous_wizard_activate_all');
            echo '<p><a class="button button-primary button-hero" href="' . esc_url($url) . '">Install &amp; activate everything</a></p>';
            echo '<p class="description">Prefer to pick pieces individually? Use the <a href="' . esc_url(admin_url('admin.php?page=ous')) . '">full dashboard</a> instead, then come back here.</p>';
        }
        echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=ous-setup-wizard&step=3')) . '">Continue</a></p>';
        self::step_nav(2);
    }

    private static function render_step_brand(): void {
        $s = class_exists('BHY_Style') ? BHY_Style::get() : BHY_Style::DEFAULTS;
        $logo_id = (int) ($s['brand_logo_id'] ?? 0);
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';

        echo '<h2>Brand basics</h2>';
        echo '<p>Just the essentials — full color/typography/scale control lives in <a href="' . esc_url(admin_url('admin.php?page=bh-design')) . '">Design Suite</a>, reachable any time.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ous_wizard_save_brand');
        echo '<input type="hidden" name="action" value="ous_wizard_save_brand">';

        // Explain "wordmark" and why it has two parts (two-tone rendering).
        $wordmark_tip = class_exists('BHY_UI') ? BHY_UI::tip('Your site name as styled text, no logo file needed. Split into two parts so they can render in different colors/weights everywhere your brand name shows up — leave the second part blank for one plain name.') : '';
        echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Wordmark' . $wordmark_tip . '</label>';
        echo '<span style="display:flex;gap:8px;max-width:400px;">';
        echo '<input type="text" name="brand_part1" value="' . esc_attr($s['brand_part1'] ?? '') . '" placeholder="First part" style="flex:1;">';
        echo '<input type="text" name="brand_part2" value="' . esc_attr($s['brand_part2'] ?? '') . '" placeholder="Accent part" style="flex:1;">';
        echo '</span></p>';

        echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Logo <span class="description">(optional)</span></label>';
        echo '<div style="display:flex;align-items:center;gap:12px;">';
        echo '<div id="ous-wizard-logo-preview" style="width:64px;height:64px;border:1px solid var(--bhy-border,#dcdcde);border-radius:6px;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:0 0 auto;">' . ($logo_url ? '<img src="' . esc_url($logo_url) . '" style="width:100%;height:100%;object-fit:contain;">' : '<span class="description" style="font-size:11px;">None</span>') . '</div>';
        echo '<input type="hidden" id="ous_wizard_brand_logo_id" name="brand_logo_id" value="' . esc_attr((string) $logo_id) . '">';
        echo '<button type="button" class="button" id="ous-wizard-logo-upload">' . ($logo_id ? 'Change logo' : 'Upload logo') . '</button>';
        echo '</div></p>';

        echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Accent color</label>';
        echo '<input type="color" name="color_accent" value="' . esc_attr($s['color_accent'] ?? BHY_Style::DEFAULTS['color_accent']) . '" style="width:60px;height:36px;padding:2px;"></p>';

        echo '<p><button type="submit" class="button button-primary button-hero">Save &amp; continue</button></p>';
        echo '</form>';
        self::step_nav(3);

        wp_enqueue_media();
        ?>
        <script>
<?php echo (string) file_get_contents(OUS_PATH . 'assets/inline/setup-wizard.js'); ?></script>
        <?php
    }

    private static function render_step_done(): void {
        echo '<h2>You\'re set up</h2>';
        echo '<p>The ecosystem is active and your brand basics are in. A few places worth a look next:</p>';
        echo '<ul style="list-style:disc;margin-left:20px;">';
        echo '<li><a href="' . esc_url(home_url('/account/')) . '" target="_blank">Your account portal</a> — what a fan/supporter sees.</li>';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=bh-design')) . '">Design Suite</a> — full color/typography/scale control.</li>';
        if (class_exists('BHM_Tiers') && post_type_exists('bhm_tier')) {
            $gateways = class_exists('BH_Commerce') ? BH_Commerce::get_available_payment_gateways() : [];
            echo '<li><a href="' . esc_url(admin_url('edit.php?post_type=bhm_tier')) . '">Supporter Tiers</a>'
               . ($gateways ? ' — payments are configured and ready.' : ' — <strong>no payment method is active yet</strong>, set one up in <a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout')) . '">WooCommerce → Payments</a> before selling anything.')
               . '</li>';
        }
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=ous')) . '">The Self-Hosted Self dashboard</a> — the full ecosystem overview.</li>';
        echo '</ul>';
        self::step_nav(4);
    }

    /* ---------------- handlers ---------------- */

    // Same activation loop as OUS_Dashboard::handle_activate_all(), duplicated
    // because it redirects back to this wizard instead.
    public static function handle_activate_all(): void {
        if (!current_user_can('activate_plugins') || !current_user_can('install_plugins')
            || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'ous_wizard_activate_all')) {
            wp_safe_redirect(admin_url('admin.php?page=ous-setup-wizard&step=2&ous_wizard_error=1'));
            exit;
        }

        $all_ok = true;
        foreach (array_keys(OUS_Registry::all()) as $key) {
            if (OUS_Registry::status($key) !== 'active') {
                if (!OUS_ActivationManager::activate_with_dependencies($key)) $all_ok = false;
            }
        }
        wp_safe_redirect($all_ok
            ? admin_url('admin.php?page=ous-setup-wizard&step=3')
            : admin_url('admin.php?page=ous-setup-wizard&step=2&ous_wizard_error=1'));
        exit;
    }

    public static function handle_save_brand(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('ous_wizard_save_brand')) {
            wp_die('Not allowed.');
        }

        // WHY: merge onto the full settings array; a partial write would wipe the
        // Design Suite fields this smaller form never shows.
        $s = class_exists('BHY_Style') ? BHY_Style::get() : BHY_Style::DEFAULTS;
        $s['brand_part1'] = sanitize_text_field($_POST['brand_part1'] ?? BHY_Style::DEFAULTS['brand_part1']);
        $s['brand_part2'] = sanitize_text_field($_POST['brand_part2'] ?? BHY_Style::DEFAULTS['brand_part2']);
        $s['brand_logo_id'] = isset($_POST['brand_logo_id']) ? (int) $_POST['brand_logo_id'] : 0;
        $s['color_accent'] = BHY_Style::safe_color(sanitize_text_field($_POST['color_accent'] ?? $s['color_accent']));

        update_option(BHY_Style::OPTION, $s);
        if (class_exists('OUS_Revisions')) {
            OUS_Revisions::snapshot('bhy_style', 1, $s, 'Saved from Guided Setup');
        }

        wp_safe_redirect(admin_url('admin.php?page=ous-setup-wizard&step=4'));
        exit;
    }
}
