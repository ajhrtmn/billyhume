<?php
if (!defined('ABSPATH')) exit;

/**
 * Tier gating for plain posts/pages: a metabox writing `_bhm_required_tier`, enforced on the_content.
 */
class BHM_PostGate {
    const GATED_POST_TYPES = ['post', 'page'];

    public static function init(): void {
        add_action('add_meta_boxes', [self::class, 'add_metabox']);
        add_action('save_post', [self::class, 'save_metabox']);
        // Priority 20: after blocks/shortcodes/wpautop, so the paywall replaces the fully rendered HTML.
        add_filter('the_content', [self::class, 'maybe_gate_content'], 20);
    }

    public static function add_metabox(): void {
        if (!self::available()) return;
        foreach (self::GATED_POST_TYPES as $post_type) {
            add_meta_box('bhm_post_gate', 'Supporter access', [self::class, 'render_metabox'], $post_type, 'side', 'default');
        }
    }

    public static function render_metabox(\WP_Post $post): void {
        wp_nonce_field('bhm_post_gate_save', 'bhm_post_gate_nonce');
        $required_tier = (int) get_post_meta($post->ID, '_bhm_required_tier', true);
        $tiers = BHM_Tiers::all();
        echo '<p><label>Require a supporter tier to read this' . (class_exists('BHY_UI') ? BHY_UI::tip('Requires the tier selected here OR any higher-priced tier — same price-rank rule bh-courses\' own tier gate uses.') : '') . '<br>';
        echo '<select name="bhm_post_required_tier" style="width:100%;"><option value="0">— Open to everyone —</option>';
        foreach ($tiers as $t) {
            echo '<option value="' . esc_attr($t['id']) . '" ' . selected($required_tier, $t['id'], false) . '>' . esc_html($t['name']) . ' ($' . BHM_Money::display($t['price_cents']) . '/mo or equivalent)</option>';
        }
        echo '</select></label></p>';
        if (empty($tiers)) echo '<p class="description">No tiers created yet — see Supporter Tiers.</p>';
        if ($required_tier > 0) echo '<p class="description">A visitor without this tier sees a paywall notice instead of the real content.</p>';
    }

    public static function save_metabox(int $post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhm_post_gate_nonce']) || !wp_verify_nonce($_POST['bhm_post_gate_nonce'], 'bhm_post_gate_save')) return;
        if (!in_array(get_post_type($post_id), self::GATED_POST_TYPES, true)) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (!self::available()) return;

        $required_tier = isset($_POST['bhm_post_required_tier']) ? (int) $_POST['bhm_post_required_tier'] : 0;
        update_post_meta($post_id, '_bhm_required_tier', $required_tier);
    }

    // Main-loop singular render only; excerpts, widgets and previews are left alone.
    public static function maybe_gate_content(string $content): string {
        if (!self::available()) return $content;
        if (!is_singular(self::GATED_POST_TYPES) || !in_the_loop() || !is_main_query()) return $content;

        $post_id = get_the_ID();
        if (!$post_id) return $content;

        $required_tier = BHM_Gate::get_required_tier((int) $post_id);
        if ($required_tier <= 0) return $content;
        if (BHM_Gate::user_has_tier_access(get_current_user_id(), $required_tier, (int) $post_id)) return $content;

        return BHM_Gate::render_paywall_notice($required_tier);
    }

    private static function available(): bool {
        return class_exists('BHM_Tiers') && class_exists('BH_Commerce') && BH_Commerce::available();
    }
}
