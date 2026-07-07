<?php
if (!defined('ABSPATH')) exit;

/**
 * The generic, content-type-agnostic paywall mechanism this whole
 * plugin is actually built around. NOT specific to bh-streaming's
 * tracks/releases — this is the Patreon-lite foundation: any post type,
 * from any plugin, marks itself paylocked with one postmeta key and
 * calls one static method to check access. bh-streaming is the FIRST
 * consumer, not the only intended one.
 *
 * Anticipated future consumer (per VISION.md's "artist platform" layer,
 * not built yet): the eventual learning-management/courses plugin gates
 * its own lesson/course post type the exact same way — set
 * `_bhm_required_tier` on its own CPT and call
 * `BHM_Gate::user_has_tier_access()`, or register a completely custom
 * entitlement type via the `bhm_extra_entitlement_check` filter below —
 * without this plugin requiring a single line of changes to support it,
 * and without that future plugin requiring bh-streaming to exist
 * either. This is deliberately designed now so that decision doesn't
 * need revisiting later.
 */
class BHM_Gate {
    public static function init() {
        // Nothing to hook yet — this class is a pure, stateless API
        // other code calls into (see USAGE below), not something that
        // needs its own WordPress hooks. Kept as an init() entry point
        // anyway for consistency with every other class in this
        // ecosystem, and as a natural place to add caching/warmup later
        // if entitlement checks ever become a measured hot path.
    }

    /**
     * USAGE for any content type wanting to be paylocked:
     *
     *   update_post_meta($post_id, '_bhm_required_tier', $tier_id);
     *   // ...and at render/serve time:
     *   if (!BHM_Gate::user_has_tier_access(get_current_user_id(), $tier_id)) {
     *       echo BHM_Gate::render_paywall_notice($tier_id);
     *       return;
     *   }
     *
     * A post with no `_bhm_required_tier` meta at all is simply not
     * paylocked — get_required_tier() returns 0, and
     * user_has_tier_access(..., 0) always returns true, so content stays
     * fully open by default unless something explicitly locks it.
     */
    public static function get_required_tier($post_id) {
        return (int) get_post_meta($post_id, '_bhm_required_tier', true);
    }

    // True if $user_id has an active grant at $tier_id OR ANY higher
    // tier (tiers are ordered by price — see BHM_Tiers::ordered_ids()),
    // OR a standing account-wide streaming-tier entitlement, OR a
    // one-time purchase entitlement scoped directly to this object, OR
    // anything a future plugin's own filter callback decides to grant.
    // $required_tier = 0 always passes (nothing to unlock).
    public static function user_has_tier_access($user_id, $required_tier, $object_id = null) {
        if (!$required_tier) return true;
        if (!$user_id) return apply_filters('bhm_extra_entitlement_check', false, 0, $required_tier, $object_id);

        global $wpdb;
        $t = $wpdb->prefix . 'bhm_entitlements';
        $now = current_time('mysql');

        // Any tier/subscription entitlement at or above the tiers price
        // rank, not yet expired (or a permanent — expires_at NULL — grant).
        $tier_ids = BHM_Tiers::ids_at_or_above($required_tier);
        if ($tier_ids) {
            $placeholders = implode(',', array_fill(0, count($tier_ids), '%d'));
            $sql = "SELECT COUNT(*) FROM $t WHERE user_id = %d AND type IN ('subscription','streaming_tier')
                    AND object_id IN ($placeholders) AND (expires_at IS NULL OR expires_at > %s)";
            $args = array_merge([$user_id], $tier_ids, [$now]);
            if ((int) $wpdb->get_var($wpdb->prepare($sql, $args))) return true;
        }

        // A one-time purchase of this SPECIFIC object (a track/release
        // bought outright) also satisfies its own tier requirement,
        // regardless of tier rank — buying the thing directly always
        // unlocks the thing.
        if ($object_id) {
            $owns = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $t WHERE user_id = %d AND type = 'purchase' AND object_id = %d", $user_id, $object_id
            ));
            if ($owns) return true;
        }

        // The extension point: any other plugin can grant access for
        // reasons this plugin has no way to know about in advance — a
        // future courses plugin's own "enrolled and paid" state, a
        // manually-comped account, whatever. Mirrors bh-crm's
        // bh_crm_active_user_ids/activity_summary pattern: a filter this
        // plugin defines and applies, with zero code changes required
        // here no matter what future plugin ends up calling add_filter().
        return (bool) apply_filters('bhm_extra_entitlement_check', false, $user_id, $required_tier, $object_id);
    }

    // A simple, Style-Gallery-themed "become a supporter" notice any
    // consumer can drop in wherever it would otherwise render paylocked
    // content — deliberately generic markup (no track/release-specific
    // language) so a future courses plugin's own lesson page can use the
    // exact same call.
    public static function render_paywall_notice($tier_id) {
        $tier = BHM_Tiers::get($tier_id);
        if (!$tier) return '<div class="bhm-paywall"><p>This content requires supporter access.</p></div>';

        ob_start();
        ?>
        <div class="bhm-paywall">
            <p class="bhm-paywall-title">This content is for <strong><?php echo esc_html($tier['name']); ?></strong> supporters and above.</p>
            <?php if (!empty($tier['benefits'])): ?><p class="bhm-paywall-benefits"><?php echo esc_html($tier['benefits']); ?></p><?php endif; ?>
            <a class="bhm-btn" href="<?php echo esc_url(BHM_Tiers::tiers_page_url()); ?>">Become a supporter</a>
        </div>
        <?php
        return ob_get_clean();
    }
}
