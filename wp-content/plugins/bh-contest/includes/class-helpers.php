<?php
if (!defined('ABSPATH')) exit;

class BH_Helpers {
    public static function table(): string {
        return BHCON_Tables::votes();
    }

    // Newest published contest: the fallback when no contest is specified.
    public static function active_contest(): int {
        $q = new WP_Query([
            'post_type'      => 'bh_contest',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        return $q->posts[0] ?? 0;
    }

    // All published contests, newest first.
    /** @return array<int, \WP_Post> */
    public static function all_contests(): array {
        $q = new WP_Query([
            'post_type'      => 'bh_contest',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        return $q->posts;
    }

    public static function find_by_slug(string $slug): int {
        $q = new WP_Query([
            'post_type'      => 'bh_contest',
            'post_status'    => 'publish',
            'name'           => sanitize_title($slug),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        return $q->posts[0] ?? 0;
    }

    // ID, slug, or empty -> a published contest ID (0 if invalid). Empty falls
    // back to active_contest().
    /** @param mixed $raw */
    public static function resolve_contest($raw): int {
        $raw = is_string($raw) ? trim($raw) : $raw;
        if ($raw === '' || $raw === null) return self::active_contest();

        if (is_numeric($raw)) {
            $id = (int) $raw;
            if (get_post_type($id) === 'bh_contest' && get_post_status($id) === 'publish') return $id;
            return 0;
        }
        return self::find_by_slug($raw);
    }

    public static function shortcode_for(int $cid): string {
        $post = get_post($cid);
        $ref  = ($post && $post->post_name) ? $post->post_name : $cid;
        return '[bh_contest_player contest="' . $ref . '"]';
    }

    // upcoming | open | closed | unscheduled — drives the admin status pill.
    public static function contest_status(int $cid): string {
        $start = self::normalize_dt(get_post_meta($cid, '_bh_start', true));
        $end   = self::normalize_dt(get_post_meta($cid, '_bh_end', true));
        if (!$start || !$end) return 'unscheduled';
        $now = current_time('mysql');
        if ($now < $start) return 'upcoming';
        if ($now > $end)   return 'closed';
        return 'open';
    }

    // Like contest_status(), for the submission window. 'unscheduled' counts as open.
    public static function submission_status(int $cid): string {
        $start = self::normalize_dt(get_post_meta($cid, '_bh_sub_start', true));
        $end   = self::normalize_dt(get_post_meta($cid, '_bh_sub_end', true));
        if (!$start || !$end) return 'unscheduled';
        $now = current_time('mysql');
        if ($now < $start) return 'upcoming';
        if ($now > $end)   return 'closed';
        return 'open';
    }

    public static function is_submission_open(int $cid): bool {
        $status = self::submission_status($cid);
        return $status === 'open' || $status === 'unscheduled';
    }

    // WHY: datetime-local values ("...T10:00") don't string-compare correctly
    // against current_time('mysql') ("... 10:00:00"); normalize before comparing.
    /** @param mixed $raw */
    private static function normalize_dt($raw): string {
        if (!$raw) return '';
        $raw = str_replace('T', ' ', trim($raw));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) $raw .= ':00';
        return $raw;
    }

    public static function is_voting_open(int $cid): bool {
        return self::contest_status($cid) === 'open';
    }

    // One-line lifecycle label (draft -> submissions -> voting -> results) for status pills.
    /** @return array{label:string, color:string} */
    public static function contest_phase_summary(int $cid): array {
        if (get_post_status($cid) !== 'publish') {
            return ['label' => 'Draft — not published yet', 'color' => '#8a8a8a'];
        }

        $sub_status = self::submission_status($cid);
        $vote_status = self::contest_status($cid);
        $published = get_post_meta($cid, '_bh_results_published', true) === '1';

        if ($sub_status === 'open' || $sub_status === 'unscheduled') {
            return ['label' => 'Accepting submissions', 'color' => '#1DB954'];
        }
        if ($sub_status === 'upcoming') {
            return ['label' => 'Published — submissions open soon', 'color' => '#8a8a8a'];
        }
        // Submissions have closed from here down.
        if ($vote_status === 'open') {
            return ['label' => 'Voting open', 'color' => '#1DB954'];
        }
        if ($vote_status === 'upcoming') {
            return ['label' => 'Submissions closed — voting opens soon', 'color' => '#8a8a8a'];
        }
        if ($vote_status === 'unscheduled') {
            return ['label' => 'Submissions closed — voting not scheduled yet', 'color' => '#b3261e'];
        }
        // Voting has closed from here down.
        if ($published) {
            return ['label' => 'Complete — results published', 'color' => '#1DB954'];
        }
        return ['label' => 'Voting closed — awaiting results', 'color' => '#b3261e'];
    }

    // Flags users with many votes in a short window, for manual review only.
    /** @return array<int, object> */
    public static function suspicious_voters(int $cid, int $min_votes = 8, int $window_seconds = 120): array {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, COUNT(*) AS vote_count, MIN(created_at) AS first_vote, MAX(created_at) AS last_vote,
                    TIMESTAMPDIFF(SECOND, MIN(created_at), MAX(created_at)) AS span_seconds
             FROM $t WHERE contest_id = %d
             GROUP BY user_id
             HAVING vote_count >= %d AND span_seconds <= %d
             ORDER BY vote_count DESC",
            $cid, $min_votes, $window_seconds
        ));
    }

    // Several accounts voting from one IP in a short window, for manual review only.
    // same_fingerprint marks clusters where one browser cookie spans the accounts.
    /** @return array<int, object> */
    public static function suspicious_ip_clusters(int $cid, int $min_accounts = 2, int $window_seconds = 300): array {
        global $wpdb;
        $t = self::table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ip_address, COUNT(DISTINCT user_id) AS account_count, COUNT(*) AS vote_count,
                    GROUP_CONCAT(DISTINCT user_id) AS user_ids, GROUP_CONCAT(DISTINCT voter_fp) AS fingerprints,
                    MIN(created_at) AS first_vote, MAX(created_at) AS last_vote,
                    TIMESTAMPDIFF(SECOND, MIN(created_at), MAX(created_at)) AS span_seconds
             FROM $t WHERE contest_id = %d AND ip_address != '' AND ip_address != '0.0.0.0'
             GROUP BY ip_address
             HAVING account_count >= %d AND span_seconds <= %d
             ORDER BY account_count DESC",
            $cid, $min_accounts, $window_seconds
        ));
        foreach ($rows as $r) {
            $r->user_ids = array_map('intval', explode(',', $r->user_ids));
            $fps = array_filter(explode(',', (string) $r->fingerprints));
            $r->same_fingerprint = count($fps) === 1 && count($r->user_ids) > 1;
        }
        return $rows;
    }

    // Per-contest contact fields shown/required at submission.
    const CONTACT_FIELDS = ['real_name', 'discord_name', 'twitch_name', 'youtube_name', 'typical_platform', 'phone'];

    /** @return array<string, mixed> */
    public static function contact_config(int $cid): array {
        $defaults = [
            'show' => self::CONTACT_FIELDS,
            'require_real_name' => true,
            'require_handle' => true,
            'require_phone' => false,
        ];
        $saved = json_decode((string) get_post_meta($cid, '_bh_contact_config', true), true);
        if (!is_array($saved)) return $defaults;
        $cfg = array_merge($defaults, $saved);
        // WHY: a hidden field can never be required, or submitters get locked out.
        if (!in_array('real_name', $cfg['show'], true)) $cfg['require_real_name'] = false;
        if (!in_array('phone', $cfg['show'], true)) $cfg['require_phone'] = false;
        if (!array_intersect(['discord_name', 'twitch_name', 'youtube_name'], $cfg['show'])) {
            $cfg['require_handle'] = false;
        }
        return $cfg;
    }

    // Required contact fields still missing from the user's BHI_Profiles data (submission only).
    /** @return array<int, string> */
    public static function missing_for_submission(int $user_id, int $cid): array {
        $cfg = self::contact_config($cid);
        $p = BHI_Profiles::get($user_id);
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

    // 'public' (default), 'judges' (rubric scores replace the vote leaderboard),
    // or 'hybrid' (two separate leaderboards, never blended).
    public static function contest_format(int $cid): string {
        $format = get_post_meta($cid, '_bh_contest_format', true);
        return in_array($format, ['judges', 'hybrid'], true) ? $format : 'public';
    }

    /** @return array<int, array<string, string>> */
    public static function categories(int $cid): array {
        $raw = get_post_meta($cid, '_bh_categories', true);
        $list = $raw ? json_decode($raw, true) : [];
        return is_array($list) ? $list : [];
    }

    // First category slug, or '' for an uncategorized contest.
    public static function default_category(int $cid): string {
        $cats = self::categories($cid);
        return $cats ? $cats[0]['slug'] : '';
    }

    // A category value is valid if it's '' on a contest with no categories,
    // or matches one of the contest's defined category slugs.
    public static function is_valid_category(int $cid, string $slug): bool {
        $cats = self::categories($cid);
        if (!$cats) return $slug === '';
        foreach ($cats as $c) if ($c['slug'] === $slug) return true;
        return false;
    }

    // Turns free-text (one category name per line) into a slug+name list,
    // deduping slugs so two similarly-named categories don't collide.
    /**
     * @param mixed $text
     * @return array<int, array<string, string>>
     */
    public static function parse_categories_input($text): array {
        $out = [];
        $seen = [];
        foreach (preg_split('/[\r\n]+/', (string) $text) as $line) {
            $name = trim($line);
            if ($name === '') continue;
            $slug = sanitize_title($name);
            if (!$slug || isset($seen[$slug])) continue;
            $seen[$slug] = true;
            $out[] = ['slug' => $slug, 'name' => $name];
        }
        return $out;
    }

    // Counts pending submissions too: submitting earns the bonus vote and
    // blocks a second entry the moment it is sent, before admin approval.
    public static function has_submitted(int $uid, int $cid): bool {
        $q = new WP_Query([
            'post_type'      => 'bh_submission',
            'author'         => $uid,
            'post_status'    => 'any',
            'meta_key'       => '_bh_contest_id',
            'meta_value'     => $cid,
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);
        return !empty($q->posts);
    }

    // ID of the user's no-audio 'draft' submission for this contest, or 0.
    public static function draft_submission_for(int $uid, int $cid): int {
        $q = new WP_Query([
            'post_type'      => 'bh_submission',
            'author'         => $uid,
            'post_status'    => 'draft',
            'meta_key'       => '_bh_contest_id',
            'meta_value'     => $cid,
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);
        return $q->posts ? (int) $q->posts[0] : 0;
    }

    // WHY: only an approved ('publish') submission earns the bonus vote, so junk
    // entries can't gain votes before review. Not the same as has_submitted().
    public static function has_approved_submission(int $uid, int $cid): bool {
        $q = new WP_Query([
            'post_type'      => 'bh_submission',
            'author'         => $uid,
            'post_status'    => 'publish',
            'meta_key'       => '_bh_contest_id',
            'meta_value'     => $cid,
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);
        return !empty($q->posts);
    }

    // Per-category vote limit: base + bonus for an approved submission in THIS
    // contest only. Blank per-contest values fall back to the plugin defaults.
    public static function vote_limit(int $uid, int $cid): int {
        $base  = get_post_meta($cid, '_bh_vote_base', true);
        $bonus = get_post_meta($cid, '_bh_vote_bonus', true);
        $base  = ($base === '' || $base === false) ? BH_VOTE_BASE : max(0, (int) $base);
        $bonus = ($bonus === '' || $bonus === false) ? BH_VOTE_BONUS : max(0, (int) $bonus);
        return $base + (self::has_approved_submission($uid, $cid) ? $bonus : 0);
    }

    // $round null counts votes across every round.
    public static function user_vote_count(int $uid, int $cid, string $category = '', ?int $round = null): int {
        global $wpdb;
        $t = self::table();
        $round_sql = $round !== null ? $wpdb->prepare('AND round = %d', (int) $round) : '';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE user_id = %d AND contest_id = %d AND category = %s $round_sql", $uid, $cid, $category
        ));
    }

    public static function submission_count(int $cid, string $status = 'publish'): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE p.post_type = 'bh_submission' AND p.post_status = %s
               AND m.meta_key = '_bh_contest_id' AND m.meta_value = %d",
            $status, $cid
        ));
    }

    public static function vote_count(int $cid, ?string $category = null): int {
        global $wpdb;
        if ($category === null) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::table() . " WHERE contest_id = %d", $cid
            ));
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE contest_id = %d AND category = %s", $cid, $category
        ));
    }

    public static function voter_count(int $cid): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM " . self::table() . " WHERE contest_id = %d", $cid
        ));
    }

    public static function user_total_votes(int $uid): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d", $uid
        ));
    }

    /** @return array<string, string> */
    public static function allowed_audio(): array {
        return ['mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4'];
    }

    // Competition ranking (10,7,7,5 -> 1,2,2,4) over values sorted highest-first.
    // The one shared tie rule for every leaderboard.
    /**
     * @param array<int, int|float> $votes_desc
     * @return array<int, int>
     */
    public static function competition_ranks($votes_desc): array {
        $ranks = [];
        $prev_votes = null;
        $prev_rank = null;
        foreach ($votes_desc as $i => $v) {
            $position = $i + 1; // 1-indexed
            $rank = ($prev_votes !== null && $v === $prev_votes) ? $prev_rank : $position;
            $ranks[] = $rank;
            $prev_votes = $v;
            $prev_rank = $rank;
        }
        return $ranks;
    }

    public static function artist_for(\WP_Post $post): string {
        return get_post_meta($post->ID, '_bh_artist_name', true)
            ?: get_the_author_meta('display_name', (int) $post->post_author);
    }
}
