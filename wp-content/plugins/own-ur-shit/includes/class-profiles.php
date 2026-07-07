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
 *
 * avatar_id/banner_id/bio/profile_slug/profile_public (added alongside
 * the public profile page — see class-public-profile.php) are
 * deliberately presentation-only, not a social graph: no follow/
 * follower relationships, no activity-feed mechanics. Just a real,
 * viewable "this is who I am" page any account can have, themed with
 * the same BHY_Style tokens as everything else. If follow/followers or
 * an activity feed ever get built, they're a clean addition on top of
 * this, not a rework of it.
 */
class BHI_Profiles {
    const TEXT_COLS = ['real_name', 'discord_name', 'twitch_name', 'youtube_name', 'phone'];
    const BOOL_COLS = ['real_name_public', 'discord_public', 'twitch_public', 'youtube_public', 'profile_public'];
    const INT_COLS = ['avatar_id', 'banner_id'];
    const PLATFORMS = ['youtube', 'twitch'];

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bhi_profiles';
    }

    public static function get($user_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE user_id = %d", $user_id), ARRAY_A);
        $defaults = array_fill_keys(self::TEXT_COLS, '');
        $defaults = array_merge(
            $defaults,
            array_fill_keys(self::BOOL_COLS, 0),
            array_fill_keys(self::INT_COLS, 0),
            ['typical_platform' => '', 'bio' => '', 'profile_slug' => '']
        );
        return $row ? array_merge($defaults, $row) : $defaults;
    }

    // Same lookup as get(), keyed by profile_slug instead of user_id —
    // what the public profile page (class-public-profile.php) actually
    // routes on. Only ever returns a profile with profile_public = 1;
    // a slug existing at all doesn't mean the profile behind it is
    // meant to be publicly viewable.
    public static function get_by_slug($slug) {
        global $wpdb;
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM " . self::table() . " WHERE profile_slug = %s AND profile_public = 1", $slug
        ));
        return $user_id ? (int) $user_id : 0;
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
        foreach (self::INT_COLS as $col) {
            if (array_key_exists($col, $data)) { $row[$col] = (int) $data[$col]; $formats[] = '%d'; }
        }
        // sanitize_textarea_field (not sanitize_text_field) — a bio is
        // the one field here that legitimately spans multiple lines.
        if (array_key_exists('bio', $data)) { $row['bio'] = sanitize_textarea_field($data['bio']); $formats[] = '%s'; }
        if (array_key_exists('typical_platform', $data) && in_array($data['typical_platform'], self::PLATFORMS, true)) {
            $row['typical_platform'] = $data['typical_platform'];
            $formats[] = '%s';
        }
        if (array_key_exists('profile_slug', $data)) {
            $slug = sanitize_title($data['profile_slug']);
            // Uniqueness enforced here (not just the DB's UNIQUE KEY) so
            // a collision comes back as a normal, actionable validation
            // error rather than a raw SQL failure bubbling up to the
            // caller. Empty string is stored as NULL — multiple NULLs
            // are fine under a UNIQUE index, multiple ''s are not (see
            // class-identity-activator.php's own note on this).
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

        // The pre-check above for a taken slug narrows the window but
        // doesn't close it — two people saving the same slug at nearly
        // the same instant can both pass that check before either write
        // lands. The table's own UNIQUE KEY is the real backstop; if it
        // fires, the whole row write fails as one atomic statement, so
        // surface that explicitly rather than telling the caller
        // "saved" when the DB actually rejected it (a slug collision
        // silently discarding bio/avatar/etc. changes too, with no
        // indication anything went wrong, would be a confusing way to
        // lose someone's edits).
        if ($wpdb->last_error) {
            return new WP_Error('save_failed', 'That profile URL was just taken by someone else — pick another and save again.');
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
        foreach (self::INT_COLS as $col) {
            if ($req->get_param($col) !== null) $out[$col] = (int) $req->get_param($col);
        }
        if ($req->get_param('bio') !== null) $out['bio'] = $req->get_param('bio');
        if ($req->get_param('profile_slug') !== null) $out['profile_slug'] = $req->get_param('profile_slug');
        $platform = $req->get_param('typical_platform');
        if ($platform !== null) $out['typical_platform'] = $platform;
        return $out;
    }

    // Self-service data deletion: wipes the identity/presentation
    // fields this class owns — real name, platform handles, phone, bio,
    // avatar/banner, slug, public/consent flags — back to nothing, by
    // deleting the row entirely rather than blanking each column (same
    // end state, simpler statement, and get() already returns sane
    // defaults for a user with no row at all).
    //
    // Deliberately scoped to ONLY this table. It does NOT touch
    // bhm_entitlements/bhm_wallet_ledger/bhm_play_log (bh-monetization-
    // woo) or anything WooCommerce order-related — those are financial
    // and tax records with their own legal retention requirements
    // independent of what a person wants their public profile to show,
    // and a plugin that isn't even active shouldn't be reached into
    // from here regardless. A genuine full-account-erasure tool (WP
    // core's own "erase personal data" privacy-request flow, extended
    // per-plugin) is real, separate scope from "let someone clear the
    // profile page they can see and control themselves right now."
    public static function delete_personal_data($user_id) {
        global $wpdb;
        $wpdb->delete(self::table(), ['user_id' => $user_id]);
    }

    /**
     * Extension point: any plugin adds a small badge (a label + optional
     * URL) to a person's public profile without this class ever needing
     * to know that plugin exists — same one-directional pattern as
     * bh-crm's bh_crm_activity_summary. Consumers so far: bh-monetization-
     * woo (active supporter tier), bh-streaming (playlist/like counts via
     * its existing CRM-integration data), bh-contest (past wins). A
     * badge is [ 'label' => string, 'url' => string|null ].
     *
     *     add_filter('bhi_profile_badges', function ($badges, $user_id) {
     *         $badges[] = ['label' => 'Supporter', 'url' => null];
     *         return $badges;
     *     }, 10, 2);
     */
    public static function badges_for($user_id) {
        return apply_filters('bhi_profile_badges', [], $user_id);
    }
}
