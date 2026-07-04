<?php
if (!defined('ABSPATH')) exit;

/**
 * Participant identity data — real name, platform handles, and an
 * optional phone number collected alongside registration and/or
 * submission, plus a per-field consent flag for whether a name could be
 * shared publicly if BillyHume ever features participants elsewhere.
 * Phone has no such consent flag at all — see TEXT_COLS below — it's
 * collected purely for prize-coordination purposes and is never treated
 * as shareable, unlike everything else in this table.
 *
 * Lives in its own table (one row per wp_users.ID) rather than user meta
 * so the shape is explicit and directly queryable from the Participants
 * admin screen, instead of scattered meta_key lookups.
 *
 * IMPORTANT: none of this data is ever exposed on any public-facing
 * output. It is read only by the Participants and Live Console admin
 * screens (both manage_options capability). Artist name and song title,
 * stored per-submission as before, remain the only participant-facing
 * names shown publicly.
 */
class BH_Profiles {
    const PLATFORMS  = ['youtube', 'twitch'];
    // phone deliberately has no *_public counterpart in BOOL_COLS below —
    // unlike Discord/Twitch/YouTube, there's no "OK to share" version of
    // this field. It's collected purely so an admin has a way to reach a
    // winner directly (prize coordination) and is never shown anywhere
    // outside the private Live Console, full stop.
    const TEXT_COLS  = ['real_name', 'discord_name', 'twitch_name', 'youtube_name', 'phone'];
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

    // The bar for "ready to submit a track" — now driven by the specific
    // contest's own contact-field configuration (see
    // BH_Helpers::contact_config()) rather than one fixed rule shared by
    // every contest. Voters never have to clear this — only checked at
    // submission time.
    public static function missing_for_submission($user_id, $cid) {
        $cfg = BH_Helpers::contact_config($cid);
        $p = self::get($user_id);
        $missing = [];

        if (!empty($cfg['require_real_name']) && $p['real_name'] === '') {
            $missing[] = 'real_name';
        }
        if (!empty($cfg['require_handle'])) {
            $handle_fields = array_intersect(['discord_name', 'twitch_name', 'youtube_name'], $cfg['show']);
            $has_handle = false;
            foreach ($handle_fields as $f) {
                if ($p[$f] !== '') { $has_handle = true; break; }
            }
            if (!$has_handle) $missing[] = 'platform_handle';
        }
        if (!empty($cfg['require_phone']) && $p['phone'] === '') {
            $missing[] = 'phone';
        }

        return $missing;
    }
}
