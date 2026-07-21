<?php
if (!defined('ABSPATH')) exit;

class BH_Activator {
    const DB_VERSION = '1.7'; // 1.5 added bh_judge_scores (ROADMAP-ux-polish-and-feature-parity-2026-07.md 2a, judge/rubric scoring mode) — see that table's own comment below. 1.6 added bh_votes.ip_address/voter_fp (2c, in-house IP+cookie fraud signal) — see the ALTER below. 1.7 added a `round` column to both bh_votes and bh_judge_scores (2b, multi-round/elimination format) — each round's votes/scores are tracked and tallied independently.

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

    // Per-contest style overrides used to live under _bh_style_override/
    // _bh_style_json; bh-style's generic entity_overrides() reads
    // _bhy_style_override/_bhy_style_json instead (bhy_-prefixed since
    // it's not contest-specific vocabulary anymore). Post meta, not a
    // table, so this is a straightforward per-post copy rather than a
    // dbDelta migration — versioned and idempotent the same way
    // everything else in this project is, via its own "done" flag
    // rather than piggybacking on DB_VERSION above (that one's
    // specifically for table schema, this isn't).
    public static function maybe_migrate_style_meta_keys() {
        if (get_option('bh_style_meta_migrated') === '1') return;

        $contests = get_posts(['post_type' => 'bh_contest', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']);
        foreach ($contests as $cid) {
            if (get_post_meta($cid, '_bhy_style_override', true) !== '') continue; // already migrated (or never had an override)
            $old_on = get_post_meta($cid, '_bh_style_override', true);
            if ($old_on === '') continue; // nothing to migrate for this contest

            update_post_meta($cid, '_bhy_style_override', $old_on);
            $old_json = get_post_meta($cid, '_bh_style_json', true);
            if ($old_json !== '') update_post_meta($cid, '_bhy_style_json', $old_json);
        }

        update_option('bh_style_meta_migrated', '1');
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
    const PAGES_VERSION = '2';

    // Reveal Party ([bh_results_reveal]) and Archive ([bh_archive]) are
    // singleton, site-wide pages — unlike the per-contest player page,
    // there's only ever one of each, so the "has it already been
    // created" check is a simple stored option rather than per-contest
    // post meta.
    //
    // Hooked to admin_init (see bh-contest.php), not the
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

        self::maybe_create_singleton_page('bh_reveal_page_id', 'Reveal Party', '[bh_results_reveal]');
        self::maybe_create_singleton_page('bh_archive_page_id', 'Archive', '[bh_archive]');

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
        //
        // ip_address/voter_fp (ROADMAP-ux-polish-and-feature-parity-
        // 2026-07.md 2c): a PURELY ADDITIONAL signal for
        // BH_Helpers::suspicious_ip_clusters() below — never read
        // anywhere near the vote-counting/limit-enforcement logic above,
        // same manual-review-only posture the existing timestamp-
        // clustering check (suspicious_voters()) already has. Direct
        // decision from the roadmap doc: no third-party CAPTCHA vendor,
        // no automated blocking — this only ever surfaces a stronger
        // "look at this cluster" signal for a human to review.
        // PRIVACY NOTE (flagged per the roadmap doc's own compliance
        // callout, not an afterthought): ip_address is real personal
        // data under most privacy regimes (GDPR/CCPA) — if this site
        // publishes a privacy policy, it should mention that a vote's IP
        // is retained for anti-fraud review. This plugin does not
        // (deliberately, out of scope here) wire a WP
        // core privacy-export/erasure integration for it; if that's
        // ever required, bh_votes.ip_address/voter_fp are the two
        // columns to cover.
        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            contest_id bigint(20) unsigned NOT NULL,
            category varchar(64) NOT NULL DEFAULT '',
            submission_id bigint(20) unsigned NOT NULL,
            ip_address varchar(45) NOT NULL DEFAULT '',
            voter_fp varchar(64) NOT NULL DEFAULT '',
            round int(11) unsigned NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_user_track (user_id, contest_id, category, submission_id, round),
            KEY contest_idx (contest_id),
            KEY user_contest_idx (user_id, contest_id),
            KEY ip_idx (ip_address)
        ) $charset;";
        // round (ROADMAP-ux-polish-and-feature-parity-2026-07.md 2b,
        // multi-round/elimination format): 0 for every contest that
        // never configures rounds — the exact same single "general"
        // bucket every vote has always landed in, so a non-round contest
        // is byte-for-byte unaffected. Added to the UNIQUE KEY (not just
        // as a plain column) so a voter can cast a fresh vote once a new
        // round opens without it silently colliding with their round-1
        // vote for the same submission — each round's votes are
        // genuinely independent rows.

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // QA fix: dbDelta() itself will attempt to add an
        // index whose COLUMN LIST differs from what's on disk even when
        // the NAME is already taken — it doesn't drop-then-recreate, it
        // just tries a bare ADD, which fails with "Duplicate key name"
        // and poisons $wpdb->last_error before this method ever reaches
        // its own (correct) DROP+ADD rebuild below. So that rebuild has
        // to run BEFORE dbDelta(), not after — by the time dbDelta()
        // runs, the on-disk index already matches the CREATE TABLE SQL
        // above and there's nothing left for it to collide with. Table-
        // doesn't-exist-yet (fresh install) is the one case this skips
        // itself on — SHOW TABLES first avoids trying to touch an index
        // that has nothing to attach to.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $key_columns = $wpdb->get_col($wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'uniq_user_track' ORDER BY SEQ_IN_INDEX",
                DB_NAME, $table
            ));
            $target_columns = ['user_id', 'contest_id', 'category', 'submission_id', 'round'];
            if ($key_columns && $key_columns !== $target_columns) {
                $wpdb->query("ALTER TABLE $table DROP INDEX uniq_user_track");
                if ($wpdb->last_error !== '') return false;
                // A column this key needs might not exist yet on a very
                // old install (pre-category, pre-round) — add whichever
                // of the target columns are actually missing before
                // trying to index them.
                $existing_cols = $wpdb->get_col("SHOW COLUMNS FROM $table");
                if (!in_array('category', $existing_cols, true)) $wpdb->query("ALTER TABLE $table ADD COLUMN category varchar(64) NOT NULL DEFAULT ''");
                if (!in_array('round', $existing_cols, true)) $wpdb->query("ALTER TABLE $table ADD COLUMN round int(11) unsigned NOT NULL DEFAULT 0");
                if ($wpdb->last_error !== '') return false;
                $wpdb->query("ALTER TABLE $table ADD UNIQUE KEY uniq_user_track (user_id, contest_id, category, submission_id, round)");
                if ($wpdb->last_error !== '') return false;
            }
        }

        $wpdb->last_error = '';
        dbDelta($sql); // adds any still-missing columns (ip_address, voter_fp, etc.) and creates the table outright on a fresh install
        if ($wpdb->last_error !== '') return false;

        // Confirm the table is actually queryable before going any
        // further — dbDelta can report no error while still leaving the
        // table missing/inaccessible if the connection dropped partway
        // through on a flaky database.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return false;

        if (!self::create_or_update_profiles_table($charset)) return false;
        return self::create_or_update_judge_scores_table($charset);
    }

    // ROADMAP-ux-polish-and-feature-parity-2026-07.md 2a: a genuinely
    // different shape from bh_votes above, not overloaded onto it — a
    // public vote is one binary row per (voter, category, submission); a
    // judge score is multi-criterion and needs an editable draft-then-
    // submit state (Devpost's own "save progress" pattern), so this is
    // one row per (judge, submission, category) with the whole rubric's
    // scores stored as a JSON snapshot, same "self-contained blob, not a
    // per-criterion history table" convention bh-courses' bhc_progress.
    // answers column already established for quiz snapshots. status
    // distinguishes a judge's in-progress draft from a scored, counted
    // submission — only 'submitted' rows are ever read by
    // BH_Judging::judge_results()'s aggregate.
    private static function create_or_update_judge_scores_table($charset) {
        global $wpdb;
        $table = $wpdb->prefix . 'bh_judge_scores';

        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            judge_id bigint(20) unsigned NOT NULL,
            contest_id bigint(20) unsigned NOT NULL,
            submission_id bigint(20) unsigned NOT NULL,
            category varchar(64) NOT NULL DEFAULT '',
            round int(11) unsigned NOT NULL DEFAULT 0,
            scores longtext DEFAULT NULL,
            status varchar(16) NOT NULL DEFAULT 'draft',
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_judge_track (judge_id, contest_id, submission_id, category, round),
            KEY contest_idx (contest_id)
        ) $charset;";
        // round: same meaning and same 0-default-for-a-non-round-contest
        // convention as bh_votes.round above (1.7) — a judge's round-1
        // score and round-2 score for the same entry are independent
        // rows, not an overwrite.

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Same fix, same reason as bh_votes' identical rebuild above
        // (create_or_update_schema()'s own comment has the full
        // explanation of why this has to run BEFORE dbDelta(), not
        // after) — dbDelta() itself fails trying to add a same-named,
        // different-column index rather than leaving it alone.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $key_columns = $wpdb->get_col($wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'uniq_judge_track' ORDER BY SEQ_IN_INDEX",
                DB_NAME, $table
            ));
            $target_columns = ['judge_id', 'contest_id', 'submission_id', 'category', 'round'];
            if ($key_columns && $key_columns !== $target_columns) {
                $wpdb->query("ALTER TABLE $table DROP INDEX uniq_judge_track");
                if ($wpdb->last_error !== '') return false;
                $existing_cols = $wpdb->get_col("SHOW COLUMNS FROM $table");
                if (!in_array('round', $existing_cols, true)) $wpdb->query("ALTER TABLE $table ADD COLUMN round int(11) unsigned NOT NULL DEFAULT 0");
                if ($wpdb->last_error !== '') return false;
                $wpdb->query("ALTER TABLE $table ADD UNIQUE KEY uniq_judge_track (judge_id, contest_id, submission_id, category, round)");
                if ($wpdb->last_error !== '') return false;
            }
        }

        $wpdb->last_error = '';
        dbDelta($sql);
        if ($wpdb->last_error !== '') return false;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    // One row per wp_users.ID — real name, platform handles, and a
    // per-field "OK to share publicly" consent flag. Superseded by the
    // core plugin's shared bhi_profiles table (see BHI_Profiles); this
    // table is only ever READ once, as the source of the one-time
    // migration in bh-contest.php's plugins_loaded callback —
    // nothing writes to it anymore. Only maintained here for sites
    // upgrading from a pre-core-merge install where it already has real
    // historical data to migrate forward; a genuinely fresh install has
    // no such data, so there's nothing to preserve and creating an
    // empty, permanently-unused table would just be dead weight.
    private static function create_or_update_profiles_table($charset) {
        global $wpdb;
        $table = $wpdb->prefix . 'bh_participant_profiles';

        $already_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if (!$already_exists) return true; // fresh install — nothing to migrate, nothing to create

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
