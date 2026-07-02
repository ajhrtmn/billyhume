<?php
if (!defined('ABSPATH')) exit;

/**
 * Participant identity data — real name and platform handles collected
 * alongside registration and/or submission, plus a per-field consent flag
 * for whether that name could be shared publicly if BillyHume ever
 * features participants elsewhere.
 *
 * Lives in its own table (one row per wp_users.ID) rather than user meta
 * so the shape is explicit and directly queryable from the Participants
 * admin screen, instead of scattered meta_key lookups.
 *
 * IMPORTANT: none of this data is ever exposed on any public-facing
 * output. It is read only by the Participants admin screen (manage_options
 * capability). Artist name and song title, stored per-submission as
 * before, remain the only participant-facing names shown publicly.
 */
class BH_Profiles {
    const PLATFORMS  = ['youtube', 'twitch'];
    const TEXT_COLS  = ['real_name', 'discord_name', 'twitch_name', 'youtube_name'];
    const BOOL_COLS  = ['real_name_public', 'discord_public', 'twitch_public', 'youtube_public'];

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bh_participant_profiles';
    }

    public static function get($user_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE user_id = %d", $user_id
        ), ARRAY_A);
        return $row ? self::cast($row) : self::empty_profile($user_id);
    }

    private static function cast($row) {
        foreach (self::BOOL_COLS as $c) $row[$c] = (int) $row[$c];
        $row['user_id'] = (int) $row['user_id'];
        return $row;
    }

    private static function empty_profile($user_id) {
        $row = ['user_id' => (int) $user_id, 'typical_platform' => ''];
        foreach (self::TEXT_COLS as $c) $row[$c] = '';
        foreach (self::BOOL_COLS as $c) $row[$c] = 0;
        return $row;
    }

    // Merge-save: a field is only written when the caller actually passed
    // it as a key in $fields. This lets the registration form and the
    // submit form both call this independently — whichever one a person
    // fills in first "wins" that field, and the other form filling in the
    // rest later never wipes it back out.
    public static function save($user_id, array $fields) {
        global $wpdb;
        $current = self::get($user_id);
        $data    = ['user_id' => (int) $user_id];
        $formats = ['%d'];

        foreach (self::TEXT_COLS as $col) {
            $data[$col] = array_key_exists($col, $fields) ? sanitize_text_field((string) $fields[$col]) : $current[$col];
            $formats[]  = '%s';
        }

        $data['typical_platform'] = (array_key_exists('typical_platform', $fields) && in_array($fields['typical_platform'], self::PLATFORMS, true))
            ? $fields['typical_platform']
            : (array_key_exists('typical_platform', $fields) ? '' : $current['typical_platform']);
        $formats[] = '%s';

        foreach (self::BOOL_COLS as $col) {
            $data[$col] = array_key_exists($col, $fields) ? (empty($fields[$col]) || $fields[$col] === '0' || $fields[$col] === 'false' ? 0 : 1) : $current[$col];
            $formats[]  = '%d';
        }

        $wpdb->replace(self::table(), $data, $formats);
    }

    // Pulls whichever profile fields are present on a REST request (used
    // by both /register and /submit, which each optionally carry a subset
    // of these) and returns them shaped for save(). A field is included
    // only when the request actually sent that key — see save()'s
    // merge behavior.
    public static function from_request($req) {
        $fields = [];
        foreach (self::TEXT_COLS as $col) {
            $v = $req->get_param($col);
            if ($v !== null) $fields[$col] = sanitize_text_field((string) $v);
        }
        $platform = $req->get_param('typical_platform');
        if ($platform !== null) $fields['typical_platform'] = sanitize_key((string) $platform);
        foreach (self::BOOL_COLS as $col) {
            $v = $req->get_param($col);
            if ($v !== null) $fields[$col] = $v;
        }
        return $fields;
    }

    // The bar for "ready to submit a track": a real name plus at least one
    // way to reach the person (Discord, Twitch, or YouTube). Voters never
    // have to clear this — only checked at submission time.
    public static function missing_for_submission($user_id) {
        $p = self::get($user_id);
        $missing = [];
        if ($p['real_name'] === '') $missing[] = 'real_name';
        if ($p['discord_name'] === '' && $p['twitch_name'] === '' && $p['youtube_name'] === '') $missing[] = 'platform_handle';
        return $missing;
    }
}
