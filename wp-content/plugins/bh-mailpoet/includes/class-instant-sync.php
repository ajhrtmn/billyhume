<?php
if (!defined('ABSPATH')) exit;

/**
 * Best-effort real-time sync on top of BHMP_ScheduledSync's daily sweep: native WP/Woo/bh-monetization
 * actions plus selected BH_Event types (via 'bh_event_emitted'). Every hook just calls
 * BHMP_Sync::sync_contact(); MailPoet's own automations react to list membership.
 */
class BHMP_InstantSync {
    // BH_Event types worth an immediate sync; filterable so other plugins can opt in.
    /** @return string[] */
    private static function watched_event_types(): array {
        return apply_filters('bhmp_watched_event_types', [
            'bh/vote',
            'bh/submission_created',
            'bhc/enroll',
            'bhc/course_completed',
            'bhm/wallet_credit',
            'bhm/referral_credited',
            'bhcrm/tags_saved',
        ]);
    }

    public static function init(): void {
        add_action('user_register', [self::class, 'sync_from_user_id']);
        add_action('profile_update', [self::class, 'sync_from_user_id']);
        add_action('woocommerce_order_status_completed', [self::class, 'sync_from_order']);
        add_action('bhm_entitlement_granted', [self::class, 'sync_from_entitlement'], 10, 1);
        add_action('bh_event_emitted', [self::class, 'sync_from_event'], 10, 3);
    }

    /** @param mixed $user_id hook-supplied — always cast before use, never trusted as already-int */
    public static function sync_from_user_id($user_id): void {
        if ($user_id) BHMP_Sync::sync_contact((int) $user_id);
    }

    /** @param mixed $order_id hook-supplied order ID */
    public static function sync_from_order($order_id): void {
        if (!function_exists('wc_get_order')) return;
        $order = wc_get_order($order_id);
        if (!$order) return;
        $user_id = $order->get_customer_id();
        if ($user_id) BHMP_Sync::sync_contact((int) $user_id); // guest checkouts (customer_id 0) have no account to sync
    }

    /**
     * bhm_entitlement_granted($user_id, $type, $scope, $object_id) — only the first arg is needed here.
     * @param mixed $user_id hook-supplied
     */
    public static function sync_from_entitlement($user_id): void {
        if ($user_id) BHMP_Sync::sync_contact((int) $user_id);
    }

    /**
     * bh_event_emitted($type, $job_args, $args) — see the-self-hosted-self's class-event.php.
     * @param mixed $type
     * @param array<string, mixed> $job_args
     * @param mixed $args
     */
    public static function sync_from_event($type, $job_args, $args): void {
        if (!in_array($type, self::watched_event_types(), true)) return;
        $user_id = (int) ($job_args['user_id'] ?? 0);
        if (!$user_id) return;

        BHMP_Sync::sync_contact($user_id);

        // bhcrm/tags_saved carries the full tag list in its payload.
        if ($type === 'bhcrm/tags_saved') {
            $tags = $job_args['payload']['tags'] ?? [];
            if (is_array($tags)) BHMP_Sync::sync_tags($user_id, $tags);
        }
    }
}
