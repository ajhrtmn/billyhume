<?php
if (!defined('ABSPATH')) exit;

/**
 * Participant identity data — real name, platform handles, and an
 * optional phone number, plus a per-field consent flag for whether a
 * name could be shared publicly. Phone has no such flag — it's for
 * direct contact only (e.g. prize coordination in bh-contest) and is
 * never treated as shareable.
 *
 * This is the direct extraction of what used to be bh-contest's own
 * BH_Profiles — same fields, same behavior, now the one copy every
 * ecosystem plugin shares instead of each maintaining its own.
 */
class BHI_Profiles {
    const TEXT_COLS = ['real_name', 'discord_name', 'twitch_name', 'youtube_name', 'phone'];
    const BOOL_COLS = ['real_name_public', 'discord_public', 'twitch_public', 'youtube_public'];
    const PLATFORMS = ['youtube', 'twitch'];

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhi_profiles';
    }

    public static function get($user_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE user_id = %d", $user_id), ARRAY_A);
        $defaults = array_fill_keys(self::TEXT_COLS, '');
        $defaults = array_merge($defaults, array_fill_keys(self::BOOL_COLS, 0), ['typical_platform' => '']);
        return $row ? array_merge($defaults, $row) : $defaults;
    }

    // Merge-save: only writes fields actually present in $data, so a
    // partial update (e.g. bh-streaming only ever touching real_name)
    // never clobbers fields another plugin already collected.
    public static function save($user_id, $data) {
        global $wpdb;
        $row = ['user_id' => $user_id];
        $formats = ['%d'];

        foreach (self::TEXT_COLS as $col) {
            if (array_key_exists($col, $data)) { $row[$col] = sanitize_text_field($data[$col]); $formats[] = '%s'; }
        }
        foreach (self::BOOL_COLS as $col) {
            if (array_key_exists($col, $data)) { $row[$col] = $data[$col] ? 1 : 0; $formats[] = '%d'; }
        }
        if (array_key_exists('typical_platform', $data) && in_array($data['typical_platform'], self::PLATFORMS, true)) {
            $row['typical_platform'] = $data['typical_platform'];
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
    }

    // Pulls whichever profile fields are present in a REST request —
    // shared by any plugin's registration/submission form so the exact
    // same field-parsing logic isn't reimplemented per plugin.
    public static function from_request($req) {
        $out = [];
        foreach (self::TEXT_COLS as $col) {
            $val = $req->get_param($col);
            if ($val !== null) $out[$col] = $val;
        }
        foreach (self::BOOL_COLS as $col) {
            if ($req->get_param($col) !== null) $out[$col] = (bool) $req->get_param($col);
        }
        $platform = $req->get_param('typical_platform');
        if ($platform !== null) $out['typical_platform'] = $platform;
        return $out;
    }
}
