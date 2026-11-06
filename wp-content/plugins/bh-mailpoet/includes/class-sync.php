<?php
if (!defined('ABSPATH')) exit;

/**
 * bh-crm -> MailPoet mapping via MailPoetAPIAPI::MP('v1'). Every entry point is a no-op without MailPoet.
 */
class BHMP_Sync {
    public static function init(): void {
        // delete_user fires before the row is removed, so get_userdata() still resolves in remove_contact().
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

    /** Finds or creates the sync list by name (filterable via bhmp_list_name), cached per request. */
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

    /** Upserts one contact; first/last name is a best-effort split of BHI_Profiles' real_name. */
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
                $existing = null; // normal for a first-time sync
            }

            if ($existing) {
                // WHY: subscribeToList() re-subscribes anyone previously unsubscribed, and sync runs constantly,
                // so never call it for an unsubscribed subscriber (global or on this list). Consent wins.
                if (self::is_unsubscribed($existing, $list_id)) {
                    self::log('info', 'Skipped resubscribing a contact who previously unsubscribed.', ['user_id' => $user_id]);
                    return true; // respecting an unsubscribe counts as success
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
     * Full resync of every BHCRM_People::active_user_ids() contact.
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
            // Tags can change by paths that skip bhcrm/tags_saved (e.g. bulk tagging).
            if (class_exists('BHCRM_Tags')) self::sync_tags($user_id, BHCRM_Tags::get($user_id));
        }

        update_option('bhmp_last_sync_at', time());
        update_option('bhmp_last_sync_counts', ['synced' => $synced, 'failed' => $failed]);

        return ['synced' => $synced, 'failed' => $failed, 'skipped_no_mailpoet' => false];
    }

    // WHY: prefix marks tags this plugin applied, so sync_tags() never touches tags added in MailPoet directly.
    const TAG_PREFIX = 'BH-CRM: ';

    /**
     * Mirrors bh-crm tags onto the MailPoet subscriber via MailPoet's Tags API.
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
            if (!$existing) return false; // not a subscriber yet; sync_contact() creates them

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
            return false; // already absent, or a MailPoet error
        }
    }

    /** @param array<string, mixed> $context */
    private static function log(string $level, string $message, array $context = []): void {
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log($level, $message, $context, 'BH MailPoet');
        }
    }
}
