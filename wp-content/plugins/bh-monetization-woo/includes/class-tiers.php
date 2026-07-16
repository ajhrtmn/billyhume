<?php
if (!defined('ABSPATH')) exit;

/**
 * Supporter tiers — the Patreon-lite foundation. A tier is a real
 * bh_tier post (title = tier name, ordered by price via _bhm_price_cents
 * meta, a benefits description, and a link to whatever WooCommerce
 * product actually sells it). Deliberately a CPT, not an option array —
 * an artist wants the same familiar "add new, edit, reorder" admin
 * experience as everything else in this ecosystem (bhs_release, bhs_feed_source),
 * not a bespoke settings-page form builder.
 *
 * Each tier's WooCommerce product is created/kept in sync automatically
 * (see sync_wc_product() below) — the artist edits price/name here,
 * never in WooCommerce's own product screens directly, keeping the
 * "wrap it" promise: WooCommerce runs underneath, mostly invisible.
 *
 * Recurring billing note: if WooCommerce Subscriptions is active
 * (detected, never required — see bh-monetization-woo.php's own
 * docblock), a tier's product is a real subscription product with
 * actual recurring billing. If it ISN'T active, a tier's product is a
 * plain one-time WooCommerce product, and the admin UI says so plainly
 * ("one-time support — no recurring billing without WooCommerce
 * Subscriptions") rather than pretending to offer recurring billing
 * this plugin doesn't actually have the machinery to enforce.
 */
class BHM_Tiers {
    const CPT = 'bhm_tier';

    // Fine-grained tier depth (ROADMAP-platform-evolution.md Section 4):
    // before this, the ONLY access model was "is this tier's price rank
    // at or above the required tier's" (ids_at_or_above(), used by
    // BHM_Gate::user_has_tier_access()) — fine for a single linear
    // ladder, wrong the moment two tiers grant genuinely different
    // THINGS rather than strictly nested access (a cheap "courses only"
    // tier and a pricier "streaming only" tier, neither a strict superset
    // of the other). Benefit keys are that second, orthogonal axis — a
    // tier can grant any combination, independent of price rank.
    // Filterable so bh-courses/bh-streaming/a future plugin can register
    // their own key + label without editing this file, same zero-
    // central-registration shape as everything else in this ecosystem.
    public static function benefit_registry() {
        return apply_filters('bhm_benefit_registry', [
            'streaming' => 'Streaming library access',
            'downloads' => 'Downloadable audio',
            'courses'   => 'Course/LMS access',
            'merch_discount' => 'Storefront discount',
        ]);
    }

    public static function init() {
        self::register_post_type();
        add_action('save_post_' . self::CPT, [self::class, 'save']);
        add_action('add_meta_boxes', [self::class, 'add_meta_box']);
        add_action('admin_enqueue_scripts', [self::class, 'maybe_enqueue_admin_assets']);
        add_action('before_delete_post', [self::class, 'log_deletion']);
    }

    /** Accountability log, AJ's own ask: "who changed what tier" — deletion is the other half of that. */
    public static function log_deletion($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== self::CPT || !class_exists('OUS_Audit')) return;
        OUS_Audit::log('tier_deleted', 'bhm_tier', $post_id, [
            'name' => $post->post_title,
            'price_cents' => (int) get_post_meta($post_id, '_bhm_price_cents', true),
        ]);
    }

    // Only the tier edit screen needs the media picker (cover image) —
    // no reason to load wp.media on every wp-admin page just because
    // this plugin is active, same "only where actually used" discipline
    // as bh-courses' own admin.js enqueue.
    public static function maybe_enqueue_admin_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== self::CPT) return;
        wp_enqueue_media();
        wp_enqueue_script('bhm-tier-admin', BHM_URL . 'assets/js/tier-admin.js', ['jquery'], BHM_VER, true);
    }

    public static function register_post_type() {
        register_post_type(self::CPT, [
            'labels' => [
                'name' => 'Supporter Tiers', 'singular_name' => 'Tier', 'add_new_item' => 'Add New Tier',
                'edit_item' => 'Edit Tier', 'all_items' => 'All Tiers',
            ],
            // Nested under WooCommerce's OWN top-level menu when it's
            // active — the same "lives where a WooCommerce admin
            // already expects money-adjacent settings to live" instinct
            // WooCommerce's own extensions (Subscriptions, Bookings,
            // etc.) follow, rather than a bare top-level "Supporter
            // Tiers" item competing for sidebar space against both
            // WooCommerce and Own Ur Shit. Falls back to a real
            // top-level menu if WooCommerce isn't active yet (this
            // plugin's whole premise needs it eventually, but an admin
            // configuring things BEFORE installing WooCommerce — or
            // during the brief window this plugin's own admin notice
            // is asking them to — must still be able to find this
            // screen at all, not have it silently vanish into a parent
            // menu slug that doesn't exist yet).
            'public' => false, 'show_ui' => true, 'show_in_menu' => class_exists('WooCommerce') ? 'woocommerce' : true,
            'menu_icon' => 'dashicons-star-filled', 'supports' => ['title'], 'capability_type' => 'post',
        ]);
    }

    public static function add_meta_box() {
        add_meta_box('bhm_tier_details', 'Tier Details', [self::class, 'render_metabox'], self::CPT, 'normal', 'high');
    }

    public static function render_metabox($post) {
        wp_nonce_field('bhm_save_tier', 'bhm_tier_nonce');
        $price = (int) get_post_meta($post->ID, '_bhm_price_cents', true);
        $annual_price = (int) get_post_meta($post->ID, '_bhm_annual_price_cents', true);
        $benefits = (string) get_post_meta($post->ID, '_bhm_benefits', true);
        $benefits_list = (array) get_post_meta($post->ID, '_bhm_benefits_list', true);
        $cover_image_id = (int) get_post_meta($post->ID, '_bhm_cover_image_id', true);
        $wc_product_id = (int) get_post_meta($post->ID, '_bhm_wc_product_id', true);
        $has_subs = class_exists('WC_Subscriptions');

        if (!class_exists('WooCommerce')) {
            echo '<p class="description">WooCommerce isn\'t active yet — this tier will start selling automatically once it is. See <strong>Own Ur Shit → Monetization Settings</strong> to install it.</p>';
        }

        // Cover image — plain attachment-ID meta + a wp.media picker
        // (tier-admin.js), the same pattern bh-courses' own step-image
        // picker uses. Deliberately NOT a BH_Content block yet: Studio
        // doesn't have a tier-authoring surface wired up (see
        // LMS-AUTHORING-DESIGN-PLAN.md's sequencing — block-level
        // consumers land plugin-by-plugin, not all at once), so a plain
        // attachment field is the honest right-sized implementation
        // today, upgradeable later without a data-migration problem
        // (an attachment ID is trivially wrappable in a future
        // bhm/tier-cover block).
        echo '<p><strong>Cover image</strong><br>';
        echo '<img id="bhm-tier-cover-preview" src="' . esc_url($cover_image_id ? (wp_get_attachment_image_url($cover_image_id, 'medium') ?: '') : '') . '" style="max-width:200px;max-height:120px;display:' . ($cover_image_id ? 'block' : 'none') . ';margin-bottom:6px;">';
        echo '<input type="hidden" id="bhm_cover_image_id" name="bhm_cover_image_id" value="' . esc_attr($cover_image_id) . '">';
        echo '<button type="button" class="button" id="bhm-tier-cover-choose">' . ($cover_image_id ? 'Change image' : 'Choose image') . '</button> ';
        echo '<button type="button" class="button" id="bhm-tier-cover-remove" style="display:' . ($cover_image_id ? 'inline-block' : 'none') . ';">Remove</button></p>';

        echo '<p><label><strong>Monthly price (USD)</strong><br><input type="number" step="0.01" min="0.50" name="bhm_price" value="' . esc_attr($price ? number_format($price / 100, 2, '.', '') : '') . '" style="width:160px;"></label></p>';

        // Annual pricing — optional, on top of the always-present monthly
        // price rather than replacing it (Patreon itself offers annual as
        // an alternative billing cadence for the SAME tier, not a
        // separate tier). Left blank = no annual option offered, matching
        // this ecosystem's "don't claim a capability that isn't actually
        // configured" convention (same posture as the Subscriptions
        // detection message below).
        echo '<p><label><strong>Annual price (USD, optional)</strong> <span class="description">— leave blank to offer monthly billing only</span><br><input type="number" step="0.01" min="0.50" name="bhm_annual_price" value="' . esc_attr($annual_price ? number_format($annual_price / 100, 2, '.', '') : '') . '" style="width:160px;"></label>';
        if (!$has_subs) {
            echo '<br><span class="description">Requires WooCommerce Subscriptions to actually bill annually — without it this stores the price but the tier still sells as the one-time fallback below.</span>';
        }
        echo '</p>';

        echo '<p><label><strong>Benefits</strong> <span class="description">(free text — shown to fans on the tier picker and on paywall notices)</span><br><textarea name="bhm_benefits" rows="3" style="width:100%;">' . esc_textarea($benefits) . '</textarea></label></p>';

        // Structured benefits list — one bullet per line, rendered as a
        // real <ul> on the tier picker instead of free-flowing paragraph
        // text (Patreon's own tier cards show an explicit bulleted list).
        // A plain one-per-line textarea rather than a repeater UI is the
        // deliberately small version of this: it gets the structured-list
        // OUTPUT the roadmap asks for without a new JS builder, and the
        // roadmap's own suggested "natural BH_Content block-list" upgrade
        // path stays open later (each line becomes a child block) without
        // this format needing to change shape.
        echo '<p><label><strong>Benefits list</strong> <span class="description">(one item per line — rendered as a bulleted list; separate from the free-text field above)</span><br><textarea name="bhm_benefits_list" rows="4" style="width:100%;" placeholder="Early access to new releases&#10;Monthly Q&amp;A&#10;Discord role">' . esc_textarea(implode("\n", $benefits_list)) . '</textarea></label></p>';

        $granted = (array) get_post_meta($post->ID, '_bhm_benefit_keys', true);
        echo '<p><strong>Grants access to</strong> <span class="description">(machine-checked — this is what BHM_Gate::user_has_benefit() actually evaluates; the free-text field above is just what fans see)</span></p>';
        echo '<p>';
        foreach (self::benefit_registry() as $key => $label) {
            echo '<label style="display:inline-block;margin:0 16px 4px 0;"><input type="checkbox" name="bhm_benefit_keys[]" value="' . esc_attr($key) . '"' . (in_array($key, $granted, true) ? ' checked' : '') . '> ' . esc_html($label) . '</label>';
        }
        echo '</p>';

        if ($has_subs) {
            echo '<p class="description">Real recurring billing via WooCommerce Subscriptions.</p>';
        } else {
            echo '<p class="description"><strong>WooCommerce Subscriptions isn\'t active</strong> — this tier will sell as a one-time purchase (30 days of access, no automatic renewal) until it is. Fans will see this plainly at checkout; nothing here silently promises recurring billing it can\'t enforce.</p>';
        }

        // BH_Commerce::product_exists()/get_edit_url() rather than a
        // direct wc_get_product() existence check — same BH_Commerce
        // migration pass as class-products.php/class-frontend.php.
        $product_exists = $wc_product_id && (class_exists('BH_Commerce') ? BH_Commerce::product_exists($wc_product_id) : (function_exists('wc_get_product') && wc_get_product($wc_product_id)));
        if ($product_exists) {
            $edit_url = class_exists('BH_Commerce') ? BH_Commerce::get_edit_url($wc_product_id) : get_edit_post_link($wc_product_id);
            echo '<p><a href="' . esc_url($edit_url) . '" target="_blank">View the underlying WooCommerce product &rarr;</a></p>';
        }
    }

    public static function save($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhm_tier_nonce']) || !wp_verify_nonce($_POST['bhm_tier_nonce'], 'bhm_save_tier')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // Accountability log, AJ's own ask: "who changed what tier" —
        // a granular before/after diff on the fields that actually
        // matter (price is the one that affects real money moving).
        $before = class_exists('OUS_Audit') ? [
            'price_cents' => (int) get_post_meta($post_id, '_bhm_price_cents', true),
            'annual_price_cents' => (int) get_post_meta($post_id, '_bhm_annual_price_cents', true),
        ] : [];

        $price_cents = isset($_POST['bhm_price']) ? (int) round(((float) $_POST['bhm_price']) * 100) : 0;
        update_post_meta($post_id, '_bhm_price_cents', $price_cents);

        $annual_price_cents = isset($_POST['bhm_annual_price']) ? (int) round(((float) $_POST['bhm_annual_price']) * 100) : 0;
        update_post_meta($post_id, '_bhm_annual_price_cents', $annual_price_cents);

        if (isset($_POST['bhm_benefits'])) update_post_meta($post_id, '_bhm_benefits', sanitize_textarea_field($_POST['bhm_benefits']));

        // One-per-line -> a clean string array, dropping blank lines so a
        // stray trailing newline doesn't render as an empty bullet.
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

        if (class_exists('WooCommerce')) {
            BHM_Products::sync_tier_wc_product($post_id, get_the_title($post_id), $price_cents, $annual_price_cents);
        }

        if (class_exists('OUS_Audit')) {
            OUS_Audit::log_diff('tier_saved', 'bhm_tier', $post_id, $before, [
                'price_cents' => $price_cents, 'annual_price_cents' => $annual_price_cents,
            ], ['name' => get_the_title($post_id)]);
        }
    }

    /* ---------- read helpers used by BHM_Gate and the fan-facing tier picker ---------- */

    public static function get($tier_id) {
        $post = get_post((int) $tier_id);
        if (!$post || $post->post_type !== self::CPT || $post->post_status !== 'publish') return null;
        return [
            'id' => $post->ID, 'name' => $post->post_title,
            'price_cents' => (int) get_post_meta($post->ID, '_bhm_price_cents', true),
            'annual_price_cents' => (int) get_post_meta($post->ID, '_bhm_annual_price_cents', true),
            'benefits' => (string) get_post_meta($post->ID, '_bhm_benefits', true),
            'benefits_list' => (array) get_post_meta($post->ID, '_bhm_benefits_list', true),
            'cover_image_id' => (int) get_post_meta($post->ID, '_bhm_cover_image_id', true),
            'benefit_keys' => (array) get_post_meta($post->ID, '_bhm_benefit_keys', true),
            'wc_product_id' => (int) get_post_meta($post->ID, '_bhm_wc_product_id', true),
            'wc_product_id_annual' => (int) get_post_meta($post->ID, '_bhm_wc_product_id_annual', true),
        ];
    }

    // Every published tier that grants a given benefit key, regardless
    // of price rank — the direct query BHM_Gate::user_has_benefit()
    // needs, and the one thing ids_at_or_above() structurally can't
    // answer (it only ever knows about price order).
    public static function ids_granting_benefit($benefit_key) {
        $ids = [];
        foreach (self::all() as $t) {
            if (in_array($benefit_key, $t['benefit_keys'], true)) $ids[] = $t['id'];
        }
        return $ids;
    }

    public static function all() {
        $posts = get_posts(['post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => -1]);
        $tiers = array_map(function ($p) { return self::get($p->ID); }, $posts);
        usort($tiers, function ($a, $b) { return $a['price_cents'] <=> $b['price_cents']; });
        return $tiers;
    }

    // Every tier ID priced at or above $tier_id's own price — this is
    // what makes a $10/mo tier's supporters also count as satisfying a
    // $5/mo paywall, without hardcoding a fixed tier count/order.
    public static function ids_at_or_above($tier_id) {
        $target = self::get($tier_id);
        if (!$target) return [];
        $ids = [];
        foreach (self::all() as $t) {
            if ($t['price_cents'] >= $target['price_cents']) $ids[] = $t['id'];
        }
        return $ids;
    }

    public static function tiers_page_url() {
        $page_id = (int) get_option('bhm_tiers_page_id', 0);
        return $page_id ? get_permalink($page_id) : home_url('/');
    }
}
