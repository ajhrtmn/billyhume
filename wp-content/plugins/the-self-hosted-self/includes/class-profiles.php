<?php
if (!defined('ABSPATH')) exit;

/**
 * Participant identity data shared by every plugin: real name, platform
 * handles, optional phone (direct contact only, never shareable), a name-sharing
 * consent flag, and presentation-only public profile fields (no social graph).
 */
class BHI_Profiles {
    const TEXT_COLS = ['real_name', 'discord_name', 'twitch_name', 'youtube_name', 'phone'];
    const BOOL_COLS = ['real_name_public', 'discord_public', 'twitch_public', 'youtube_public', 'profile_public'];
    const INT_COLS = ['avatar_id', 'banner_id'];
    const PLATFORMS = ['youtube', 'twitch'];

    private static function table(): string {
        return OUS_Tables::profiles();
    }

    // Shared lookup so bh-crm need not query this table directly.
    /** @return array<int, int> */
    public static function user_ids_with_profile_data(): array {
        global $wpdb;
        $ids = $wpdb->get_col("SELECT user_id FROM " . self::table() . " WHERE real_name != '' OR discord_name != '' OR twitch_name != '' OR youtube_name != ''");
        return array_map('intval', $ids);
    }

    /** @return array<string, mixed> */
    public static function get(int $user_id): array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE user_id = %d", $user_id), ARRAY_A);
        $defaults = array_fill_keys(self::TEXT_COLS, '');
        $defaults = array_merge(
            $defaults,
            array_fill_keys(self::BOOL_COLS, 0),
            array_fill_keys(self::INT_COLS, 0),
            ['typical_platform' => '', 'bio' => '', 'profile_slug' => '', 'links' => []]
        );
        if ($row) {
            $row['links'] = self::decode_links($row['links'] ?? '');
        }
        return $row ? array_merge($defaults, $row) : $defaults;
    }

    // Link-in-bio: up to 8 {label, url} pairs stored as JSON in the links column.
    const MAX_LINKS = 8;

    /** @return array<int, mixed> */
    private static function decode_links(?string $raw): array {
        if ($raw === '' || $raw === null) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param mixed $links
     * @return array<int, array<string, string>>
     */
    private static function sanitize_links($links): array {
        if (!is_array($links)) return [];
        $out = [];
        foreach ($links as $link) {
            $label = isset($link['label']) ? sanitize_text_field($link['label']) : '';
            $url = isset($link['url']) ? esc_url_raw($link['url']) : '';
            if ($label === '' || $url === '') continue;
            $out[] = ['label' => $label, 'url' => $url];
            if (count($out) >= self::MAX_LINKS) break;
        }
        return $out;
    }

    // Lookup by profile_slug; only returns profiles with profile_public = 1.
    public static function get_by_slug(string $slug): int {
        global $wpdb;
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM " . self::table() . " WHERE profile_slug = %s AND profile_public = 1", $slug
        ));
        return $user_id ? (int) $user_id : 0;
    }

    // Merge-save: only fields present in $data are written.
    /**
     * @param array<string, mixed> $data
     * @return \WP_Error|void
     */
    public static function save(int $user_id, array $data) {
        global $wpdb;
        $row = ['user_id' => $user_id];
        $formats = ['%d'];

        foreach (self::TEXT_COLS as $col) {
            if (array_key_exists($col, $data)) { $row[$col] = sanitize_text_field($data[$col]); $formats[] = '%s'; }
        }
        foreach (self::BOOL_COLS as $col) {
            if (array_key_exists($col, $data)) { $row[$col] = $data[$col] ? 1 : 0; $formats[] = '%d'; }
        }
        foreach (self::INT_COLS as $col) {
            if (array_key_exists($col, $data)) { $row[$col] = (int) $data[$col]; $formats[] = '%d'; }
        }
        // Bio is the one multi-line field.
        if (array_key_exists('bio', $data)) { $row['bio'] = sanitize_textarea_field($data['bio']); $formats[] = '%s'; }
        if (array_key_exists('typical_platform', $data) && in_array($data['typical_platform'], self::PLATFORMS, true)) {
            $row['typical_platform'] = $data['typical_platform'];
            $formats[] = '%s';
        }
        if (array_key_exists('links', $data)) {
            $row['links'] = wp_json_encode(self::sanitize_links($data['links']));
            $formats[] = '%s';
        }
        if (array_key_exists('profile_slug', $data)) {
            $slug = sanitize_title($data['profile_slug']);
            // Uniqueness checked here for a clean validation error. Empty is stored
            // as NULL, since multiple NULLs are allowed under a UNIQUE index.
            if ($slug !== '') {
                $taken_by = $wpdb->get_var($wpdb->prepare(
                    "SELECT user_id FROM " . self::table() . " WHERE profile_slug = %s AND user_id != %d", $slug, $user_id
                ));
                if ($taken_by) return new WP_Error('slug_taken', 'That profile URL is already taken.');
            }
            $row['profile_slug'] = $slug !== '' ? $slug : null;
            $formats[] = '%s';
        }

        if (count($row) === 1) return; // nothing to actually save

        $exists = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM " . self::table() . " WHERE user_id = %d", $user_id));
        if ($exists) {
            unset($row['user_id']);
            array_shift($formats);
            $wpdb->update(self::table(), $row, ['user_id' => $user_id], $formats, ['%d']);
        } else {
            $wpdb->insert(self::table(), $row, $formats);
        }

        // WHY: the pre-check above has a race; the UNIQUE KEY is the real backstop,
        // and a rejected write must not be reported as saved.
        if ($wpdb->last_error) {
            return new WP_Error('save_failed', 'That profile URL was just taken by someone else — pick another and save again.');
        }
    }

    // Parses profile fields from a REST request, shared by every plugin's forms.
    /**
     * @param \WP_REST_Request $req
     * @return array<string, mixed>
     */
    public static function from_request($req): array {
        $out = [];
        foreach (self::TEXT_COLS as $col) {
            $val = $req->get_param($col);
            if ($val !== null) $out[$col] = $val;
        }
        foreach (self::BOOL_COLS as $col) {
            if ($req->get_param($col) !== null) $out[$col] = (bool) $req->get_param($col);
        }
        foreach (self::INT_COLS as $col) {
            if ($req->get_param($col) !== null) $out[$col] = (int) $req->get_param($col);
        }
        if ($req->get_param('bio') !== null) $out['bio'] = $req->get_param('bio');
        if ($req->get_param('profile_slug') !== null) $out['profile_slug'] = $req->get_param('profile_slug');
        if ($req->get_param('links') !== null) $out['links'] = $req->get_param('links');
        $platform = $req->get_param('typical_platform');
        if ($platform !== null) $out['typical_platform'] = $platform;
        return $out;
    }

    // Self-service deletion of this table's row only. WHY: financial/order
    // records (bh-monetization-woo, WooCommerce) have their own retention rules
    // and are deliberately not touched.
    public static function delete_personal_data(int $user_id): void {
        global $wpdb;
        $wpdb->delete(self::table(), ['user_id' => $user_id]);
    }

    /**
     * Extension point for public-profile badges ['label' => string, 'url' => string|null]:
     *
     *     add_filter('bhi_profile_badges', function ($badges, $user_id) {
     *         $badges[] = ['label' => 'Supporter', 'url' => null];
     *         return $badges;
     *     }, 10, 2);
     */
    /** @return array<int, array<string, mixed>> */
    public static function badges_for(int $user_id): array {
        return apply_filters('bhi_profile_badges', [], $user_id);
    }
}
