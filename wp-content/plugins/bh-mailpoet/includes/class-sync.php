<?php
if (!defined('ABSPATH')) exit;

/**
 * The actual bh-crm -> MailPoet mapping. Every public entry point here
 * is guarded by class_exists('\MailPoet\API\API') so this class is a
 * harmless no-op with MailPoet absent — same posture as every other
 * class_exists()-guarded cross-plugin touch in this ecosystem.
 *
 * Runtime-verified against a real, official MailPoet install (5.36.0)
 * as of bh-mailpoet 1.1.4 — see that version's changelog entry for the
 * full verification (every method signature below matched exactly
 * against MailPoet's own installed source, and a real Debug Tools sync
 * run produced 92 synced/0 failed with subscriber rows confirmed
 * directly in MailPoet's own database). \MailPoet\API\API::MP('v1')'s
 * method names/signatures used below (getLists, addList, getSubscriber,
 * addSubscriber, subscribeToList, unsubscribeFromLists) are MailPoet's
 * documented public API surface, now confirmed to match the real
 * installed plugin too.
 */
class BHMP_Sync {
    public static function init(): void {
        // Bug fix (Phase 4 dead-code triage): remove_contact() existed,
        // fully implemented and documented as "the account deletion...
        // path," but was never actually wired to anything — WordPress's
        // own delete_user fires before the user row is removed (so
        // get_userdata() inside remove_contact() still resolves), and
        // nothing in this ecosystem currently listens for it at all.
        // Deleting a WP account left that person's MailPoet subscription
        // untouched indefinitely. remove_contact() itself no-ops
        // harmlessly (class_exists('\MailPoet\API\API') guard) when
        // MailPoet isn't installed, so this hook is safe unconditionally.
        add_action('delete_user', static function (int $user_id): void {
            self::remove_contact($user_id);
        });
    }

    /** @return \MailPoet\API\MP\v1\API|null */
    private static function api() {
        if (!class_exists('\MailPoet\API\API')) return null;
        try {
            return \MailPoet\API\API::MP('v1');
        } catch (\Throwable $e) {
            self::log('error', 'Could not get a MailPoet API handle.', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Finds (or creates) the one list this plugin syncs into, by name —
     * cached for the request since sync_all() calls this once per
     * contact otherwise. Name is filterable (bhmp_list_name) so a future
     * pass mapping bh-crm segments to distinct lists doesn't need this
     * method to change shape, just what it's called with.
     */
    /** @var array<string, int|string> */
    private static $list_id_cache = [];

    /** @return int|string|null MailPoet's own list id shape (varies by version) */
    public static function get_or_create_list_id(?string $name = null) {
        $api = self::api();
        if (!$api) return null;

        $name = $name ?? apply_filters('bhmp_list_name', BHMP_DEFAULT_LIST_NAME);
        if (isset(self::$list_id_cache[$name])) return self::$list_id_cache[$name];

        try {
            foreach ($api->getLists() as $list) {
                if (($list['name'] ?? '') === $name) {
                    return self::$list_id_cache[$name] = $list['id'];
                }
            }
            $created = $api->addList(['name' => $name]);
            return self::$list_id_cache[$name] = $created['id'];
        } catch (\Throwable $e) {
            self::log('error', 'Could not find or create the MailPoet list.', ['list_name' => $name, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Upserts one bh-crm contact into MailPoet, on whatever list
     * get_or_create_list_id() resolves to. Pulls email/display name from
     * WP_User (the only place either genuinely lives) and first/last
     * name from BHI_Profiles' real_name field if set (split on the
     * first space — a real name field, not separate first/last columns,
     * so this is a best-effort split, not authoritative).
     */
    public static function sync_contact(int $user_id): bool {
        $api = self::api();
        if (!$api) return false;

        $user = get_userdata((int) $user_id);
        if (!$user || !$user->user_email) return false;

        $list_id = self::get_or_create_list_id();
        if (!$list_id) return false;

        $real_name = class_exists('BHI_Profiles') ? (BHI_Profiles::get($user_id)['real_name'] ?? '') : '';
        $parts = $real_name !== '' ? preg_split('/\s+/', trim($real_name), 2) : [];
        $first_name = $parts[0] ?? $user->display_name;
        $last_name  = $parts[1] ?? '';

        $subscriber = [
            'email'      => $user->user_email,
            'first_name' => $first_name,
            'last_name'  => $last_name,
        ];

        try {
            $existing = null;
            try {
                $existing = $api->getSubscriber($user->user_email);
            } catch (\Throwable $e) {
                $existing = null; // not found — normal for a first-time sync, not an error
            }

            if ($existing) {
                // BUG FIX (2026-08-26): subscribeToList() unconditionally
                // moves a subscriber back to 'subscribed' regardless of
                // their PRIOR status — confirmed by reading MailPoet's
                // own Subscribers::subscribeToLists() (lib/API/MP/v1/
                // Subscribers.php): it only skips the status write if
                // the subscriber is ALREADY 'subscribed', never checks
                // for 'unsubscribed'. This plugin calls sync_contact()
                // constantly (7 BH_Event types, WooCommerce order
                // completion, entitlement grants, every profile update,
                // the daily full resync) — every single one of those
                // was silently re-subscribing anyone who had ever
                // clicked unsubscribe, the moment they next logged in,
                // voted, bought something, or got a wallet credit. A
                // real, ongoing consent violation, not a one-time bug.
                // Fix: never call subscribeToList() for a subscriber
                // whose global status (or, more narrowly, whose status
                // on THIS specific list) is already 'unsubscribed' —
                // contact info (name) still gets nothing to update here
                // since MailPoet has no separate "update without
                // resubscribing" call, but that's an acceptable trade
                // vs. silently overriding an explicit opt-out.
                if (self::is_unsubscribed($existing, $list_id)) {
                    self::log('info', 'Skipped resubscribing a contact who previously unsubscribed.', ['user_id' => $user_id]);
                    return true; // not an error — respecting an explicit unsubscribe is success, not failure
                }
                $api->subscribeToList($existing['id'], $list_id, ['send_confirmation_email' => false]);
            } else {
                $api->addSubscriber($subscriber, [$list_id], ['send_confirmation_email' => false]);
            }
            return true;
        } catch (\Throwable $e) {
            self::log('error', 'Failed to sync a contact to MailPoet.', ['user_id' => $user_id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @param array<string, mixed> $subscriber MailPoet's own getSubscriber() response shape.
     * @param int|string $list_id
     */
    private static function is_unsubscribed(array $subscriber, $list_id): bool {
        if (($subscriber['status'] ?? '') === 'unsubscribed') return true; // global unsubscribe (the "unsubscribe from everything" link)
        foreach ($subscriber['subscriptions'] ?? [] as $sub) {
            if ((string) ($sub['segment_id'] ?? '') === (string) $list_id) {
                return ($sub['status'] ?? '') === 'unsubscribed'; // per-list unsubscribe on this specific list
            }
        }
        return false;
    }

    /**
     * Full resync — every bh-crm "active" contact (BHCRM_People's own
     * aggregation, already merges profile-having users plus whatever any
     * plugin contributes via bh_crm_active_user_ids; reused rather than
     * reimplemented). Used by BHMP_ScheduledSync's daily sweep and the
     * Debug Tools "Sync now" button.
     *
     * @return array{synced:int, failed:int, skipped_no_mailpoet:bool}
     */
    public static function sync_all() {
        if (!self::api()) return ['synced' => 0, 'failed' => 0, 'skipped_no_mailpoet' => true];
        if (!class_exists('BHCRM_People')) return ['synced' => 0, 'failed' => 0, 'skipped_no_mailpoet' => false];

        $ids = BHCRM_People::active_user_ids();
        $synced = 0;
        $failed = 0;
        foreach ($ids as $user_id) {
            if (self::sync_contact($user_id)) {
                $synced++;
            } else {
                $failed++;
            }
            // Same safety-net reasoning as sync_contact() itself getting
            // a daily resync — a tag edit made through some path other
            // than BHCRM_Tags::handle_save() (bulk-tag action, a future
            // API) might not always reach bhcrm/tags_saved.
            if (class_exists('BHCRM_Tags')) self::sync_tags($user_id, BHCRM_Tags::get($user_id));
        }

        update_option('bhmp_last_sync_at', time());
        update_option('bhmp_last_sync_counts', ['synced' => $synced, 'failed' => $failed]);

        return ['synced' => $synced, 'failed' => $failed, 'skipped_no_mailpoet' => false];
    }

    // Namespaced so this plugin only ever adds/removes tags IT applied —
    // a tag a human added directly in MailPoet's own UI (for MailPoet-
    // only purposes, unrelated to bh-crm) is never touched by the diff
    // in sync_tags() below, since it won't start with this prefix.
    const TAG_PREFIX = 'BH-CRM: ';

    /**
     * Mirrors bh-crm's free-text tags (BHCRM_Tags) onto the same
     * MailPoet subscriber sync_contact() maintains, via MailPoet's own
     * Tags API (tagSubscriber()/untagSubscriber()) — lets a MailPoet
     * campaign/automation segment by bh-crm tag (e.g. "contest winner",
     * "vinyl backer") without this plugin inventing its own segment
     * concept. Driven by the bhcrm/tags_saved BH_Event's own payload
     * (see BHMP_InstantSync::sync_from_event()) — no extra DB read of
     * bh-crm's tag storage needed, the event already carries the full
     * current tag list for that person.
     *
     * @param string[] $tags The person's full current bh-crm tag list (not a delta) — same shape bhcrm/tags_saved's payload carries.
     */
    public static function sync_tags(int $user_id, array $tags): bool {
        $api = self::api();
        if (!$api) return false;

        $user = get_userdata($user_id);
        if (!$user || !$user->user_email) return false;

        try {
            $existing = null;
            try {
                $existing = $api->getSubscriber($user->user_email);
            } catch (\Throwable $e) {
                $existing = null;
            }
            if (!$existing) return false; // not a MailPoet subscriber yet — sync_contact() creates them; nothing to tag until then

            $current_tag_names = array_map(static fn($t) => (string) ($t['name'] ?? ''), $existing['tags'] ?? []);
            $current_managed = array_values(array_filter($current_tag_names, static fn($n) => str_starts_with($n, self::TAG_PREFIX)));
            $wanted = array_values(array_unique(array_map(
                static fn($t) => self::TAG_PREFIX . $t,
                array_filter(array_map('strval', $tags), static fn($t) => $t !== '')
            )));

            foreach (array_diff($wanted, $current_managed) as $to_add) {
                $api->tagSubscriber($existing['id'], $to_add);
            }
            foreach (array_diff($current_managed, $wanted) as $to_remove) {
                $api->untagSubscriber($existing['id'], $to_remove);
            }
            return true;
        } catch (\Throwable $e) {
            self::log('error', 'Failed to sync bh-crm tags to MailPoet.', ['user_id' => $user_id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** Account deletion / explicit "stop syncing this person" path. */
    public static function remove_contact(int $user_id): bool {
        $api = self::api();
        if (!$api) return false;

        $user = get_userdata((int) $user_id);
        if (!$user) return false;

        try {
            $existing = $api->getSubscriber($user->user_email);
            if ($existing) {
                $api->unsubscribeFromLists($existing['id'], [self::get_or_create_list_id()]);
            }
            return true;
        } catch (\Throwable $e) {
            return false; // already absent, or MailPoet-side error — either way, nothing more to do
        }
    }

    /** @param array<string, mixed> $context */
    private static function log(string $level, string $message, array $context = []): void {
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log($level, $message, $context, 'BH MailPoet');
        }
    }
}
