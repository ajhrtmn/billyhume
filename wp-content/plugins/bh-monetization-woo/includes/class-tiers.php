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
        add_action('admin_post_bhm_restore_tier_revision', [self::class, 'handle_restore']);

        // Real gap an ecosystem audit flagged: price_cents is the ONLY
        // hierarchy signal ids_at_or_above() has to work with (there's
        // no separate rank/level field), but it was completely invisible
        // on the tier list screen — an admin had to open every tier
        // individually just to see which one was priced above another,
        // with no way to catch a misconfigured (e.g. accidentally
        // cheaper) "premium" tier at a glance.
        // DRY/SOLID audit Phase 4: was 4 hand-rolled hooks
        // (add_price_column/render_price_column/make_price_column_sortable/
        // sort_by_price, ~30 lines) — now the shared OUS_ListTable helper
        // every CPT-owning plugin in this ecosystem migrated to. Same
        // exact column position (right after Title — price is the single
        // most decision-relevant fact about a tier row), same sort
        // behavior.
        OUS_ListTable::register(self::CPT, ['bhm_price' => 'Price'], function ($column, $post_id) {
            $price = (int) get_post_meta($post_id, '_bhm_price_cents', true);
            $annual = (int) get_post_meta($post_id, '_bhm_annual_price_cents', true);
            if (!$price) { echo '&#8212;'; return; }
            echo '$' . esc_html(number_format($price / 100, 2)) . '/mo';
            if ($annual) echo '<br><span class="description">$' . esc_html(number_format($annual / 100, 2)) . '/yr</span>';
        }, ['bhm_price' => '_bhm_price_cents']);
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
            'public' => false, 'show_ui' => true, 'show_in_menu' => (class_exists('BH_Commerce') ? BH_Commerce::available() : class_exists('WooCommerce')) ? 'woocommerce' : true,
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
        // Routed through BH_Commerce, same fix applied to class-admin.php
        // and class-frontend.php this same audit pass — these two calls
        // were missed when the rest of this plugin migrated onto the
        // abstraction.
        $has_subs = class_exists('BH_Commerce') ? BH_Commerce::has_subscriptions() : class_exists('WC_Subscriptions');
        $has_wc = class_exists('BH_Commerce') ? BH_Commerce::available() : class_exists('WooCommerce');

        if (!$has_wc) {
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

        // Free trial — ROADMAP-platform-evolution.md Section 4's one
        // remaining open item besides gifting/promo codes (promo codes
        // already work today via WooCommerce's own native checkout
        // coupon field, no code needed there). Days rather than a
        // period+length pair: "N days" is what an artist actually reaches
        // for, and any week/month/year trial is exactly representable as
        // a day count.
        $trial_days = (int) get_post_meta($post->ID, '_bhm_trial_days', true);
        echo '<p><label><strong>Free trial (days, optional)</strong> <span class="description">— 0 = no trial</span><br><input type="number" step="1" min="0" name="bhm_trial_days" value="' . esc_attr($trial_days) . '" style="width:100px;"></label>';
        if (!$has_subs) {
            echo '<br><span class="description">Requires WooCommerce Subscriptions to actually delay the first charge — without it this stores the value but the one-time fallback purchase still charges immediately.</span>';
        }
        echo '</p>';

        // Real gap an ecosystem audit flagged: three similarly-named
        // fields ("Benefits" / "Benefits list" / "Grants access to")
        // sat as three consecutive, visually identical <p> blocks — an
        // admin skimming labels rather than reading the small help text
        // under each could easily believe filling in the free-text
        // fields is what actually grants access. It isn't:
        // BHM_Gate::user_has_benefit() only ever reads the checkboxes
        // below. Two real headed sections now make the distinction
        // structural, not just a sentence of italic text to notice.
        $granted = (array) get_post_meta($post->ID, '_bhm_benefit_keys', true);
        echo '<div style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px;margin:16px 0 12px;">';
        echo '<h4 style="margin:0 0 4px;">What fans see</h4>';
        echo '<p class="description" style="margin:0 0 10px;">Marketing copy only — describes the tier, but grants nothing by itself.</p>';
        echo '<p><label><strong>Benefits</strong> <span class="description">(free text — shown on the tier picker and on paywall notices)</span><br><textarea name="bhm_benefits" rows="3" style="width:100%;">' . esc_textarea($benefits) . '</textarea></label></p>';
        // Structured benefits list — one bullet per line, rendered as a
        // real <ul> on the tier picker instead of free-flowing paragraph
        // text (Patreon's own tier cards show an explicit bulleted list).
        // A plain one-per-line textarea rather than a repeater UI is the
        // deliberately small version of this: it gets the structured-list
        // OUTPUT the roadmap asks for without a new JS builder, and the
        // roadmap's own suggested "natural BH_Content block-list" upgrade
        // path stays open later (each line becomes a child block) without
        // this format needing to change shape.
        echo '<p style="margin-bottom:0;"><label><strong>Benefits list</strong> <span class="description">(one item per line — rendered as a bulleted list; separate from the free-text field above)</span><br><textarea name="bhm_benefits_list" rows="4" style="width:100%;" placeholder="Early access to new releases&#10;Monthly Q&amp;A&#10;Discord role">' . esc_textarea(implode("\n", $benefits_list)) . '</textarea></label></p>';
        echo '</div>';

        echo '<div style="background:#fcf9e8;border:1px solid #dba617;border-radius:6px;padding:14px 16px;margin-bottom:12px;">';
        echo '<h4 style="margin:0 0 4px;">What actually gets enforced</h4>';
        echo '<p class="description" style="margin:0 0 10px;">Machine-checked — this is what <code>BHM_Gate::user_has_benefit()</code> evaluates at gate time. If nothing here is checked, this tier charges money but unlocks nothing anywhere else in the ecosystem, regardless of what the fields above say.</p>';
        foreach (self::benefit_registry() as $key => $label) {
            echo '<label style="display:inline-block;margin:0 16px 4px 0;"><input type="checkbox" name="bhm_benefit_keys[]" value="' . esc_attr($key) . '"' . (in_array($key, $granted, true) ? ' checked' : '') . '> ' . esc_html($label) . '</label>';
        }
        // Live warning, not just the paragraph above — a checkbox state
        // an admin might still miss on a long page gets a real, visible
        // notice the moment every box is unchecked (tier-admin.js wires
        // this to update on every checkbox change, not just on load).
        $price_is_paid = $price > 0;
        echo '<p id="bhm-no-benefits-warning" style="display:' . ($price_is_paid && !$granted ? 'block' : 'none') . ';color:#b32d2e;font-weight:600;margin:10px 0 0;">&#9888; This tier charges money but has no benefit checked above — fans who pay for it will get nothing enforced anywhere.</p>';
        echo '</div>';

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

        if (class_exists('OUS_Revisions')) {
            echo '<h3 style="margin-top:24px;">Version History</h3>';
            OUS_Revisions::render_history_panel('bhm_tier', $post->ID, 'bhm_restore_tier_revision', 'bhm_restore_tier_' . $post->ID);
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

        $trial_days = isset($_POST['bhm_trial_days']) ? max(0, (int) $_POST['bhm_trial_days']) : 0;
        update_post_meta($post_id, '_bhm_trial_days', $trial_days);

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

        if (class_exists('BH_Commerce') ? BH_Commerce::available() : class_exists('WooCommerce')) {
            BHM_Products::sync_tier_wc_product($post_id, get_the_title($post_id), $price_cents, $annual_price_cents, $trial_days);
        }

        if (class_exists('OUS_Audit')) {
            OUS_Audit::log_diff('tier_saved', 'bhm_tier', $post_id, $before, [
                'price_cents' => $price_cents, 'annual_price_cents' => $annual_price_cents,
            ], ['name' => get_the_title($post_id)]);
        }

        // ROADMAP-search-and-revisions.md's first real OUS_Revisions
        // consumer — a tier's full field set is a clean fit: it's a
        // genuinely overwrite-on-save single object (unlike bh-crm's
        // own notes, which are already append-only history and don't
        // need a SECOND history mechanism layered on top). Full current
        // state, not a diff (that's what OUS_Audit already does) — this
        // is the "restore an earlier configuration" tool.
        if (class_exists('OUS_Revisions')) {
            OUS_Revisions::snapshot('bhm_tier', $post_id, self::get($post_id));
        }
    }

    // OUS_Revisions::render_history_panel()'s Restore button posts here.
    // Re-applies a stored snapshot's fields exactly the way a normal
    // save would (including re-syncing the WooCommerce product), rather
    // than writing raw postmeta directly — so a restored tier is
    // indistinguishable from one an admin just re-saved by hand.
    public static function handle_restore() {
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

        if (class_exists('BH_Commerce') ? BH_Commerce::available() : class_exists('WooCommerce')) {
            BHM_Products::sync_tier_wc_product($post_id, get_the_title($post_id), (int) ($data['price_cents'] ?? 0), (int) ($data['annual_price_cents'] ?? 0), (int) ($data['trial_days'] ?? 0));
        }

        // The restore ITSELF is also a real save — future-you can undo
        // an accidental restore the same way, not a dead end.
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

    public static function get($tier_id) {
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
