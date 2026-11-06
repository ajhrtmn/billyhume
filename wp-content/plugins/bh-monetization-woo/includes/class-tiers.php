<?php
if (!defined('ABSPATH')) exit;

/**
 * Supporter tiers as a bhm_tier CPT, ranked by price; each tier's WooCommerce product is kept in sync
 * (BHM_ProductSync::sync_tier_wc_product()). Without WC Subscriptions a tier sells as a one-time purchase.
 */
class BHM_Tiers {
    const CPT = 'bhm_tier';

    // Benefit keys: an access axis independent of price rank (ids_at_or_above()).
    // Filterable so other plugins can register their own keys.
    /** @return array<string, string> */
    public static function benefit_registry(): array {
        return apply_filters('bhm_benefit_registry', [
            'streaming' => 'Streaming library access',
            'downloads' => 'Downloadable audio',
            'courses'   => 'Course/LMS access',
            'merch_discount' => 'Storefront discount',
        ]);
    }

    public static function init(): void {
        self::register_post_type();
        add_action('save_post_' . self::CPT, [self::class, 'save']);
        add_action('add_meta_boxes', [self::class, 'add_meta_box']);
        add_action('admin_enqueue_scripts', [self::class, 'maybe_enqueue_admin_assets']);
        add_action('before_delete_post', [self::class, 'log_deletion']);
        add_action('admin_post_bhm_restore_tier_revision', [self::class, 'handle_restore']);

        // Sortable price column: price is the only tier-ranking signal.
        OUS_ListTable::register(self::CPT, ['bhm_price' => 'Price'], function ($column, $post_id) {
            $price = (int) get_post_meta($post_id, '_bhm_price_cents', true);
            $annual = (int) get_post_meta($post_id, '_bhm_annual_price_cents', true);
            if (!$price) { echo '&#8212;'; return; }
            echo '$' . esc_html(BHM_Money::display($price)) . '/mo';
            if ($annual) echo '<br><span class="description">$' . esc_html(BHM_Money::display($annual)) . '/yr</span>';
        }, ['bhm_price' => '_bhm_price_cents']);
    }

    /** Audit-log tier deletions. */
    public static function log_deletion(int $post_id): void {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== self::CPT || !class_exists('OUS_Audit')) return;
        OUS_Audit::log('tier_deleted', 'bhm_tier', $post_id, [
            'name' => $post->post_title,
            'price_cents' => (int) get_post_meta($post_id, '_bhm_price_cents', true),
        ]);
    }

    // Media picker only on the tier edit screen.
    public static function maybe_enqueue_admin_assets(string $hook): void {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== self::CPT) return;
        wp_enqueue_media();
        wp_enqueue_script('bhm-tier-admin', BHM_URL . 'assets/js/tier-admin.js', ['jquery'], BHM_VER, true);
    }

    public static function register_post_type(): void {
        register_post_type(self::CPT, [
            'labels' => [
                'name' => 'Supporter Tiers', 'singular_name' => 'Tier', 'add_new_item' => 'Add New Tier',
                'edit_item' => 'Edit Tier', 'all_items' => 'All Tiers',
            ],
            // Under WooCommerce's menu when active; top-level otherwise so the screen never vanishes.
            'public' => false, 'show_ui' => true, 'show_in_menu' => (BH_Commerce::available()) ? 'woocommerce' : true,
            'menu_icon' => 'dashicons-star-filled', 'supports' => ['title'], 'capability_type' => 'post',
        ]);
    }

    public static function add_meta_box(): void {
        add_meta_box('bhm_tier_details', 'Tier Details', [self::class, 'render_metabox'], self::CPT, 'normal', 'high');
    }

    public static function render_metabox(\WP_Post $post): void {
        wp_nonce_field('bhm_save_tier', 'bhm_tier_nonce');
        $price = (int) get_post_meta($post->ID, '_bhm_price_cents', true);
        $annual_price = (int) get_post_meta($post->ID, '_bhm_annual_price_cents', true);
        $benefits = (string) get_post_meta($post->ID, '_bhm_benefits', true);
        $benefits_list = (array) get_post_meta($post->ID, '_bhm_benefits_list', true);
        $cover_image_id = (int) get_post_meta($post->ID, '_bhm_cover_image_id', true);
        $wc_product_id = (int) get_post_meta($post->ID, '_bhm_wc_product_id', true);
        $has_subs = BH_Commerce::has_subscriptions();
        $has_wc = BH_Commerce::available();

        if (!$has_wc) {
            echo '<p class="description">WooCommerce isn\'t active yet — this tier will start selling automatically once it is. See <strong>The Self-Hosted Self → Monetization Settings</strong> to install it.</p>';
        }

        // Cover image: attachment-ID meta + wp.media picker (tier-admin.js).
        echo '<p><strong>Cover image</strong><br>';
        echo '<img id="bhm-tier-cover-preview" src="' . esc_url($cover_image_id ? (wp_get_attachment_image_url($cover_image_id, 'medium') ?: '') : '') . '" style="max-width:200px;max-height:120px;display:' . ($cover_image_id ? 'block' : 'none') . ';margin-bottom:6px;">';
        echo '<input type="hidden" id="bhm_cover_image_id" name="bhm_cover_image_id" value="' . esc_attr((string) $cover_image_id) . '">';
        echo '<button type="button" class="button" id="bhm-tier-cover-choose">' . ($cover_image_id ? 'Change image' : 'Choose image') . '</button> ';
        echo '<button type="button" class="button" id="bhm-tier-cover-remove" style="display:' . ($cover_image_id ? 'inline-block' : 'none') . ';">Remove</button></p>';

        // Price is also the tier's gating rank ("this tier or higher"), so say so here.
        $price_rank_tip = class_exists('BHY_UI') ? BHY_UI::tip('This price also sets this tier\'s rank for access gating — a supporter tier "requires" here means this price or higher, so raising or lowering it can change who already qualifies.') : '';
        echo '<p><label><strong>Monthly price (USD)</strong>' . $price_rank_tip . '<br><input type="number" step="0.01" min="0.50" name="bhm_price" value="' . esc_attr($price ? BHM_Money::price($price) : '') . '" style="width:160px;"></label></p>';

        // Optional annual cadence for the same tier; blank = monthly only.
        echo '<p><label><strong>Annual price (USD, optional)</strong> <span class="description">— leave blank to offer monthly billing only</span><br><input type="number" step="0.01" min="0.50" name="bhm_annual_price" value="' . esc_attr($annual_price ? BHM_Money::price($annual_price) : '') . '" style="width:160px;"></label>';
        if (!$has_subs) {
            echo '<br><span class="description">Requires WooCommerce Subscriptions to actually bill annually — without it this stores the price but the tier still sells as the one-time fallback below.</span>';
        }
        echo '</p>';

        // Free trial, in days.
        $trial_days = (int) get_post_meta($post->ID, '_bhm_trial_days', true);
        echo '<p><label><strong>Free trial (days, optional)</strong> <span class="description">— 0 = no trial</span><br><input type="number" step="1" min="0" name="bhm_trial_days" value="' . esc_attr((string) $trial_days) . '" style="width:100px;"></label>';
        if (!$has_subs) {
            echo '<br><span class="description">Requires WooCommerce Subscriptions to actually delay the first charge — without it this stores the value but the one-time fallback purchase still charges immediately.</span>';
        }
        echo '</p>';

        // Two sections: marketing copy vs. the benefit checkboxes BHM_Gate::user_has_benefit() actually reads.
        $granted = (array) get_post_meta($post->ID, '_bhm_benefit_keys', true);
        echo '<div style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px;margin:16px 0 12px;">';
        echo '<h4 style="margin:0 0 4px;">What fans see</h4>';
        echo '<p class="description" style="margin:0 0 10px;">Marketing copy only — describes the tier, but grants nothing by itself.</p>';
        echo '<p><label><strong>Benefits</strong> <span class="description">(free text — shown on the tier picker and on paywall notices)</span><br><textarea name="bhm_benefits" rows="3" style="width:100%;">' . esc_textarea($benefits) . '</textarea></label></p>';
        // One bullet per line, rendered as a <ul> on the tier picker.
        echo '<p style="margin-bottom:0;"><label><strong>Benefits list</strong> <span class="description">(one item per line — rendered as a bulleted list; separate from the free-text field above)</span><br><textarea name="bhm_benefits_list" rows="4" style="width:100%;" placeholder="Early access to new releases&#10;Monthly Q&amp;A&#10;Discord role">' . esc_textarea(implode("\n", $benefits_list)) . '</textarea></label></p>';
        echo '</div>';

        echo '<div style="background:#fcf9e8;border:1px solid #dba617;border-radius:6px;padding:14px 16px;margin-bottom:12px;">';
        echo '<h4 style="margin:0 0 4px;">What actually gets enforced</h4>';
        echo '<p class="description" style="margin:0 0 10px;">Machine-checked — this is what <code>BHM_Gate::user_has_benefit()</code> evaluates at gate time. If nothing here is checked, this tier charges money but unlocks nothing anywhere else in the ecosystem, regardless of what the fields above say.</p>';
        foreach (self::benefit_registry() as $key => $label) {
            echo '<label style="display:inline-block;margin:0 16px 4px 0;"><input type="checkbox" name="bhm_benefit_keys[]" value="' . esc_attr($key) . '"' . (in_array($key, $granted, true) ? ' checked' : '') . '> ' . esc_html($label) . '</label>';
        }
        // Paid tier with no benefits checked; tier-admin.js toggles this live.
        $price_is_paid = $price > 0;
        echo '<p id="bhm-no-benefits-warning" style="display:' . ($price_is_paid && !$granted ? 'block' : 'none') . ';color:#b32d2e;font-weight:600;margin:10px 0 0;">&#9888; This tier charges money but has no benefit checked above — fans who pay for it will get nothing enforced anywhere.</p>';
        echo '</div>';

        if ($has_subs) {
            echo '<p class="description">Real recurring billing via WooCommerce Subscriptions.</p>';
        } else {
            echo '<p class="description"><strong>WooCommerce Subscriptions isn\'t active</strong> — this tier will sell as a one-time purchase (30 days of access, no automatic renewal) until it is. Fans will see this plainly at checkout; nothing here silently promises recurring billing it can\'t enforce.</p>';
        }

        $product_exists = $wc_product_id && (BH_Commerce::product_exists($wc_product_id));
        if ($product_exists) {
            $edit_url = BH_Commerce::get_edit_url($wc_product_id);
            echo '<p><a href="' . esc_url($edit_url) . '" target="_blank">View the underlying WooCommerce product &rarr;</a></p>';
        }

        if (class_exists('OUS_Revisions')) {
            echo '<h3 style="margin-top:24px;">Version History</h3>';
            OUS_Revisions::render_history_panel('bhm_tier', $post->ID, 'bhm_restore_tier_revision', 'bhm_restore_tier_' . $post->ID);
        }
    }

    public static function save(int $post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhm_tier_nonce']) || !wp_verify_nonce($_POST['bhm_tier_nonce'], 'bhm_save_tier')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // Audit-log a before/after diff of the price fields.
        $before = class_exists('OUS_Audit') ? [
            'price_cents' => (int) get_post_meta($post_id, '_bhm_price_cents', true),
            'annual_price_cents' => (int) get_post_meta($post_id, '_bhm_annual_price_cents', true),
        ] : [];

        $price_cents = isset($_POST['bhm_price']) ? BHM_Money::parse($_POST['bhm_price']) : 0;
        update_post_meta($post_id, '_bhm_price_cents', $price_cents);

        $annual_price_cents = isset($_POST['bhm_annual_price']) ? BHM_Money::parse($_POST['bhm_annual_price']) : 0;
        update_post_meta($post_id, '_bhm_annual_price_cents', $annual_price_cents);

        $trial_days = isset($_POST['bhm_trial_days']) ? max(0, (int) $_POST['bhm_trial_days']) : 0;
        update_post_meta($post_id, '_bhm_trial_days', $trial_days);

        if (isset($_POST['bhm_benefits'])) update_post_meta($post_id, '_bhm_benefits', sanitize_textarea_field($_POST['bhm_benefits']));

        // Blank lines dropped so a trailing newline isn't an empty bullet.
        if (isset($_POST['bhm_benefits_list'])) {
            $lines = preg_split('/\r\n|\r|\n/', (string) $_POST['bhm_benefits_list']);
            $clean_lines = array_values(array_filter(array_map(function ($l) {
                return sanitize_text_field(trim($l));
            }, $lines), function ($l) { return $l !== ''; }));
            update_post_meta($post_id, '_bhm_benefits_list', $clean_lines);
        }

        if (isset($_POST['bhm_cover_image_id'])) {
            update_post_meta($post_id, '_bhm_cover_image_id', (int) $_POST['bhm_cover_image_id']);
        }

        $known_keys = array_keys(self::benefit_registry());
        $submitted = isset($_POST['bhm_benefit_keys']) && is_array($_POST['bhm_benefit_keys']) ? $_POST['bhm_benefit_keys'] : [];
        $clean_keys = array_values(array_intersect($known_keys, array_map('sanitize_key', $submitted)));
        update_post_meta($post_id, '_bhm_benefit_keys', $clean_keys);

        if (BH_Commerce::available()) {
            BHM_ProductSync::sync_tier_wc_product($post_id, get_the_title($post_id), $price_cents, $annual_price_cents, $trial_days);
        }

        if (class_exists('OUS_Audit')) {
            OUS_Audit::log_diff('tier_saved', 'bhm_tier', $post_id, $before, [
                'price_cents' => $price_cents, 'annual_price_cents' => $annual_price_cents,
            ], ['name' => get_the_title($post_id)]);
        }

        // Full-state snapshot for restore (OUS_Audit above keeps the diff).
        if (class_exists('OUS_Revisions')) {
            OUS_Revisions::snapshot('bhm_tier', $post_id, self::get($post_id));
        }
    }

    // Restore button target: re-applies a snapshot like a normal save, including the WooCommerce product sync.
    public static function handle_restore(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        $post_id = (int) ($_GET['object_id'] ?? 0);
        $version = (int) ($_GET['version'] ?? 0);
        if (!isset($_GET['ous_revisions_nonce']) || !wp_verify_nonce($_GET['ous_revisions_nonce'], 'bhm_restore_tier_' . $post_id)) {
            wp_die('Invalid request.');
        }
        if (!$post_id || get_post_type($post_id) !== self::CPT) wp_die('Not a tier.');

        $snapshot = class_exists('OUS_Revisions') ? OUS_Revisions::get_version('bhm_tier', $post_id, $version) : null;
        if (!$snapshot) wp_die('That version no longer exists.');
        $data = $snapshot['data'];

        update_post_meta($post_id, '_bhm_price_cents', (int) ($data['price_cents'] ?? 0));
        update_post_meta($post_id, '_bhm_annual_price_cents', (int) ($data['annual_price_cents'] ?? 0));
        update_post_meta($post_id, '_bhm_trial_days', (int) ($data['trial_days'] ?? 0));
        update_post_meta($post_id, '_bhm_benefits', (string) ($data['benefits'] ?? ''));
        update_post_meta($post_id, '_bhm_benefits_list', (array) ($data['benefits_list'] ?? []));
        update_post_meta($post_id, '_bhm_cover_image_id', (int) ($data['cover_image_id'] ?? 0));
        update_post_meta($post_id, '_bhm_benefit_keys', (array) ($data['benefit_keys'] ?? []));

        if (BH_Commerce::available()) {
            BHM_ProductSync::sync_tier_wc_product($post_id, get_the_title($post_id), (int) ($data['price_cents'] ?? 0), (int) ($data['annual_price_cents'] ?? 0), (int) ($data['trial_days'] ?? 0));
        }

        // Snapshot the restore too, so it can itself be undone.
        if (class_exists('OUS_Revisions')) {
            OUS_Revisions::snapshot('bhm_tier', $post_id, self::get($post_id), 'Restored from version #' . $version);
        }
        if (class_exists('OUS_Toast')) {
            OUS_Toast::queue('Restored version #' . $version . '.', 'success');
        }

        wp_safe_redirect(get_edit_post_link($post_id, ''));
        exit;
    }

    /* ---------- read helpers used by BHM_Gate and the fan-facing tier picker ---------- */

    /** @return array<string, mixed>|null */
    public static function get(int $tier_id): ?array {
        $post = get_post((int) $tier_id);
        if (!$post || $post->post_type !== self::CPT || $post->post_status !== 'publish') return null;
        return [
            'id' => $post->ID, 'name' => $post->post_title,
            'price_cents' => (int) get_post_meta($post->ID, '_bhm_price_cents', true),
            'annual_price_cents' => (int) get_post_meta($post->ID, '_bhm_annual_price_cents', true),
            'trial_days' => (int) get_post_meta($post->ID, '_bhm_trial_days', true),
            'benefits' => (string) get_post_meta($post->ID, '_bhm_benefits', true),
            'benefits_list' => (array) get_post_meta($post->ID, '_bhm_benefits_list', true),
            'cover_image_id' => (int) get_post_meta($post->ID, '_bhm_cover_image_id', true),
            'benefit_keys' => (array) get_post_meta($post->ID, '_bhm_benefit_keys', true),
            'wc_product_id' => (int) get_post_meta($post->ID, '_bhm_wc_product_id', true),
            'wc_product_id_annual' => (int) get_post_meta($post->ID, '_bhm_wc_product_id_annual', true),
        ];
    }

    // Published tiers granting $benefit_key, regardless of price rank.
    /** @return array<int, int> */
    public static function ids_granting_benefit(string $benefit_key): array {
        $ids = [];
        foreach (self::all() as $t) {
            if (in_array($benefit_key, $t['benefit_keys'], true)) $ids[] = $t['id'];
        }
        return $ids;
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(): array {
        $posts = get_posts(['post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => -1]);
        $tiers = array_map(function ($p) { return self::get($p->ID); }, $posts);
        usort($tiers, function ($a, $b) { return $a['price_cents'] <=> $b['price_cents']; });
        return $tiers;
    }

    // Tiers priced at or above $tier_id, so a $10 supporter satisfies a $5 paywall.
    /** @return array<int, int> */
    public static function ids_at_or_above(int $tier_id): array {
        $target = self::get($tier_id);
        if (!$target) return [];
        $ids = [];
        foreach (self::all() as $t) {
            if ($t['price_cents'] >= $target['price_cents']) $ids[] = $t['id'];
        }
        return $ids;
    }

    // WHY: a stale page ID gives get_permalink() false, i.e. an empty href that loops to the current page.
    public static function tiers_page_url(): string {
        $page_id = (int) get_option('bhm_tiers_page_id', 0);
        $status  = $page_id ? get_post_status($page_id) : false;
        return ($page_id && $status && $status !== 'trash') ? (string) get_permalink($page_id) : home_url('/');
    }
}
