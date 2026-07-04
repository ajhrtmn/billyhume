<?php
if (!defined('ABSPATH')) exit;

class BH_Activator {
    const DB_VERSION = '1.4';

    public static function activate() {
        if (self::create_or_update_schema()) {
            update_option('bh_db_version', self::DB_VERSION);
        }
        self::maybe_create_default_pages();
        flush_rewrite_rules();
    }

    // Runs on every page load (cheap early-return via version check) rather
    // than only on activation — AJ's workflow is replacing plugin files
    // directly over FTP without deactivating/reactivating, so a schema
    // change can't rely on the activation hook firing again.
    //
    // Only persists bh_db_version if the migration actually succeeded
    // (checked via $wpdb->last_error at each step) — marking it "done"
    // unconditionally would mean a migration that fails partway through
    // a flaky/cold database connection gets silently recorded as
    // complete and never retried, leaving the schema stuck half-updated
    // with nothing to surface that until something downstream breaks on
    // a missing column or table much later.
    public static function maybe_upgrade() {
        if (version_compare(get_option('bh_db_version', '0'), self::DB_VERSION, '>=')) return;
        if (self::create_or_update_schema()) {
            update_option('bh_db_version', self::DB_VERSION);
        }
    }

    // Same idea as DB_VERSION above: rather than re-checking both page
    // options on literally every wp-admin load forever, one version flag
    // gates the whole thing. After the pages exist, every subsequent
    // admin_init pays for exactly one get_option() string comparison and
    // returns — not two separate lookups that never had a reason to run
    // again. Bump this only if the page-creation logic itself changes
    // (e.g. a third singleton page gets added) and needs to re-run once;
    // maybe_create_singleton_page() stays individually idempotent either
    // way, so re-running only ever creates what's actually missing.
    const PAGES_VERSION = '1';

    // Listening Party ([bh_listening_party]) and Reveal Party
    // ([bh_results_reveal]) are singleton, site-wide pages — unlike the
    // per-contest player page, there's only ever one of each, so the
    // "has it already been created" check is a simple stored option
    // rather than per-contest post meta.
    //
    // Hooked to admin_init (see billyhume-contest.php), not the
    // plugins_loaded-based maybe_upgrade() above — schema migrations
    // genuinely need to run on every request (a front-end visitor could
    // hit a broken query before an admin ever loads wp-admin again after
    // a deploy), but page creation isn't blocking anything and only
    // matters to whoever's running the site, so there's no reason to
    // make every public page view pay for a check that's only ever
    // useful to an admin. AJ's deploy workflow is replacing plugin files
    // over FTP without deactivating/reactivating, so the real WordPress
    // activation hook alone won't reach an already-installed site after
    // a version bump — admin_init reliably fires the next time he's in
    // wp-admin after a deploy, which he will be.
    public static function maybe_create_default_pages() {
        if (get_option('bh_pages_version') === self::PAGES_VERSION) return;

        self::maybe_create_singleton_page('bh_listening_page_id', 'Listening Party', '[bh_listening_party]');
        self::maybe_create_singleton_page('bh_reveal_page_id', 'Reveal Party', '[bh_results_reveal]');

        update_option('bh_pages_version', self::PAGES_VERSION);
    }

    // No status/existence check needed here anymore — the version gate
    // above already ensures this only ever runs once per PAGES_VERSION,
    // so there's nothing left to optimize at this level.
    private static function maybe_create_singleton_page($option_key, $title, $shortcode) {
        if ((int) get_option($option_key, 0)) return;

        $new_id = wp_insert_post([
            'post_title'   => $title,
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => $shortcode,
        ], true);
        if (is_wp_error($new_id)) return;

        update_option($option_key, $new_id);
    }

    // Returns true only if every step here actually succeeded — see the
    // note on maybe_upgrade() above for why this matters. $wpdb never
    // throws on a failed query; it returns false/null and records the
    // problem in $wpdb->last_error, so that's what's checked at each
    // step rather than assuming success.
    private static function create_or_update_schema() {
        global $wpdb;
        $table   = $wpdb->prefix . 'bh_votes';
        $charset = $wpdb->get_charset_collate();

        // `category` defaults to '' — every vote cast before this column
        // existed automatically becomes a vote in the implicit "general"
        // category once contests start defining named categories. No data
        // loss, no migration script needed for existing votes.
        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            contest_id bigint(20) unsigned NOT NULL,
            category varchar(64) NOT NULL DEFAULT '',
            submission_id bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_user_track (user_id, contest_id, category, submission_id),
            KEY contest_idx (contest_id),
            KEY user_contest_idx (user_id, contest_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $wpdb->last_error = '';
        dbDelta($sql); // reliably adds the missing `category` column on upgrade
        if ($wpdb->last_error !== '') return false;

        // Confirm the table is actually queryable before going any
        // further — dbDelta can report no error while still leaving the
        // table missing/inaccessible if the connection dropped partway
        // through on a flaky database.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return false;

        // dbDelta doesn't reliably rewrite an existing UNIQUE KEY's column
        // list, so the old 3-column key (if present from before category
        // support) is dropped and replaced explicitly.
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'uniq_user_track' AND COLUMN_NAME = 'submission_id' AND SEQ_IN_INDEX = 3",
            DB_NAME, $table
        ));
        if ($wpdb->last_error !== '') return false;

        if ($exists) {
            $wpdb->query("ALTER TABLE $table DROP INDEX uniq_user_track");
            if ($wpdb->last_error !== '') return false;
            $wpdb->query("ALTER TABLE $table ADD UNIQUE KEY uniq_user_track (user_id, contest_id, category, submission_id)");
            if ($wpdb->last_error !== '') return false;
        }

        return self::create_or_update_profiles_table($charset);
    }

    // One row per wp_users.ID — real name, platform handles, and a
    // per-field "OK to share publicly" consent flag. See BH_Profiles for
    // how this is read and written; this table is never queried directly
    // outside that class.
    private static function create_or_update_profiles_table($charset) {
        global $wpdb;
        $table = $wpdb->prefix . 'bh_participant_profiles';

        $sql = "CREATE TABLE $table (
            user_id bigint(20) unsigned NOT NULL,
            real_name varchar(190) NOT NULL DEFAULT '',
            discord_name varchar(190) NOT NULL DEFAULT '',
            twitch_name varchar(190) NOT NULL DEFAULT '',
            youtube_name varchar(190) NOT NULL DEFAULT '',
            phone varchar(30) NOT NULL DEFAULT '',
            typical_platform varchar(20) NOT NULL DEFAULT '',
            real_name_public tinyint(1) unsigned NOT NULL DEFAULT 0,
            discord_public tinyint(1) unsigned NOT NULL DEFAULT 0,
            twitch_public tinyint(1) unsigned NOT NULL DEFAULT 0,
            youtube_public tinyint(1) unsigned NOT NULL DEFAULT 0,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (user_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $wpdb->last_error = '';
        dbDelta($sql);
        if ($wpdb->last_error !== '') return false;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}
