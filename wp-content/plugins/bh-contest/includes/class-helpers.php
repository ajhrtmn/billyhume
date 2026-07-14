<?php
if (!defined('ABSPATH')) exit;

class BH_Helpers {
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bh_votes';
    }

    // Legacy single-contest fallback: newest published contest. Used when a
    // shortcode or API call doesn't specify which contest it means — keeps
    // old embeds working exactly as before multi-contest support existed.
    public static function active_contest() {
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

    // All published contests, newest first — used to populate admin
    // contest pickers (Results, Debug Tools) and the [bh_contest_player]
    // slug lookup.
    public static function all_contests() {
        $q = new WP_Query([
            'post_type'      => 'bh_contest',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        return $q->posts;
    }

    public static function find_by_slug($slug) {
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

    // Turns a raw "contest" value from a shortcode attribute or REST param
    // (numeric ID, slug, or empty) into a validated, published contest ID.
    // Empty/invalid falls back to active_contest() so untargeted embeds and
    // older cached front-end code keep working.
    public static function resolve_contest($raw) {
        $raw = is_string($raw) ? trim($raw) : $raw;
        if ($raw === '' || $raw === null) return self::active_contest();

        if (is_numeric($raw)) {
            $id = (int) $raw;
            if (get_post_type($id) === 'bh_contest' && get_post_status($id) === 'publish') return $id;
            return 0;
        }
        return self::find_by_slug($raw);
    }

    // [bh_contest_player contest="..."] string an admin can copy-paste to
    // embed this specific contest anywhere.
    public static function shortcode_for($cid) {
        $post = get_post($cid);
        $ref  = ($post && $post->post_name) ? $post->post_name : $cid;
        return '[bh_contest_player contest="' . $ref . '"]';
    }

    // upcoming | open | closed | unscheduled — drives the admin status pill.
    public static function contest_status($cid) {
        $start = self::normalize_dt(get_post_meta($cid, '_bh_start', true));
        $end   = self::normalize_dt(get_post_meta($cid, '_bh_end', true));
        if (!$start || !$end) return 'unscheduled';
        $now = current_time('mysql');
        if ($now < $start) return 'upcoming';
        if ($now > $end)   return 'closed';
        return 'open';
    }

    // Same shape as contest_status(), but for the separate submission
    // window. Unset (either date blank) means "no window configured" —
    // treated as always-open, matching the plugin's original behavior
    // from before submission windows existed, so older contests that
    // never set these fields keep accepting submissions exactly as they
    // always did.
    public static function submission_status($cid) {
        $start = self::normalize_dt(get_post_meta($cid, '_bh_sub_start', true));
        $end   = self::normalize_dt(get_post_meta($cid, '_bh_sub_end', true));
        if (!$start || !$end) return 'unscheduled';
        $now = current_time('mysql');
        if ($now < $start) return 'upcoming';
        if ($now > $end)   return 'closed';
        return 'open';
    }

    public static function is_submission_open($cid) {
        $status = self::submission_status($cid);
        return $status === 'open' || $status === 'unscheduled';
    }

    // <input type="datetime-local"> submits "YYYY-MM-DDTHH:MM" (a literal T,
    // no seconds). current_time('mysql') returns "YYYY-MM-DD HH:MM:SS" (a
    // space, with seconds). Those two formats don't compare correctly as
    // plain strings — on any day matching the stored date, the T sorts
    // after the space regardless of actual time, which was making contests
    // read as permanently "Upcoming". Normalize to the same shape before
    // any comparison, here and wherever a raw value might have been saved
    // in the old format already.
    private static function normalize_dt($raw) {
        if (!$raw) return '';
        $raw = str_replace('T', ' ', trim($raw));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) $raw .= ':00';
        return $raw;
    }

    public static function is_voting_open($cid) {
        return self::contest_status($cid) === 'open';
    }

    // One friendly sentence describing exactly where a contest currently
    // sits across its whole lifecycle (draft → submissions → voting →
    // results) — the single source of truth for the progress indicator
    // in the metabox and (eventually) anywhere else that wants it,
    // rather than each place re-deriving its own read of the same dates.
    public static function contest_phase_summary($cid) {
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

    // Categories are stored on the contest as a simple JSON list — no new
    // post type/taxonomy needed. Empty/absent = single implicit "general"
    // category (slug ''), which is also what every vote cast before this
    // feature existed already uses, so nothing needs migrating.
    // Basic flag, not fraud detection: a user who cast a lot of votes
    // packed into an implausibly short window. Deliberately simple —
    // this uses only the created_at timestamps already in the votes
    // table (no IP tracking exists in this schema, and adding it would
    // mean a migration for a "nice to have" rather than a core need), so
    // it can't catch someone spacing out fake votes deliberately. It's
    // meant to surface the obvious case (a script, or someone mashing
    // the vote button across many tabs) for a human to actually look at,
    // not to make an automated call.
    public static function suspicious_voters($cid, $min_votes = 8, $window_seconds = 120) {
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

    // ROADMAP-ux-polish-and-feature-parity-2026-07.md 2c — the IP+cookie
    // half of the in-house anti-fraud signal, alongside
    // suspicious_voters() above. A shared IP alone isn't suspicious (a
    // household, a campus, a VPN exit node routinely puts many genuine
    // voters behind one address); the actual signal is SEVERAL DIFFERENT
    // ACCOUNTS voting from that IP within a short window — sock puppets
    // sharing a connection, not a coincidence of geography. Same manual-
    // review-only posture as suspicious_voters(): this surfaces a
    // cluster for a human to look at, never blocks or auto-flags a vote.
    // voter_fp is included per-row (not grouped on) so the admin view
    // can additionally note whether the SAME browser fingerprint shows
    // up under multiple accounts in a cluster — the strongest single
    // signal this table can offer, short of an actual CAPTCHA vendor
    // (explicitly not adopted — see this method's own roadmap doc
    // section for that direct decision).
    public static function suspicious_ip_clusters($cid, $min_accounts = 2, $window_seconds = 300) {
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
            // A cluster where every account also shares the SAME browser
            // fingerprint is a much stronger signal than one where each
            // account has a distinct fingerprint (which just means
            // several genuine devices happen to share a NAT'd IP).
            $fps = array_filter(explode(',', (string) $r->fingerprints));
            $r->same_fingerprint = count($fps) === 1 && count($r->user_ids) > 1;
        }
        return $rows;
    }

    // Which contact-info fields a contest asks for at submission, and
    // which of those are actually required — configurable per contest
    // (see the Contest Rules metabox) rather than one fixed rule for
    // every contest. Defaults reproduce the plugin's original behavior
    // exactly (all fields shown, real name + at least one platform
    // handle required, phone optional) so a contest that's never touched
    // this setting works exactly as it always has.
    const CONTACT_FIELDS = ['real_name', 'discord_name', 'twitch_name', 'youtube_name', 'typical_platform', 'phone'];

    public static function contact_config($cid) {
        $defaults = [
            'show' => self::CONTACT_FIELDS,
            'require_real_name' => true,
            'require_handle' => true,
            'require_phone' => false,
        ];
        $saved = json_decode((string) get_post_meta($cid, '_bh_contact_config', true), true);
        if (!is_array($saved)) return $defaults;
        $cfg = array_merge($defaults, $saved);
        // A field that isn't shown can't sensibly be required — this is
        // enforced here too, not just nudged toward in the admin UI, so
        // a stale/hand-edited config can't quietly lock submitters out
        // over a field they were never even shown a way to fill in.
        if (!in_array('real_name', $cfg['show'], true)) $cfg['require_real_name'] = false;
        if (!in_array('phone', $cfg['show'], true)) $cfg['require_phone'] = false;
        // Same idea for the handle rule specifically: it's impossible to
        // satisfy "at least one of Discord/Twitch/YouTube" if none of
        // those three are even shown — that's not just meaningless, it's
        // an unsatisfiable requirement that would block every submitter
        // outright, so it's defused the same way as the two checks above.
        if (!array_intersect(['discord_name', 'twitch_name', 'youtube_name'], $cfg['show'])) {
            $cfg['require_handle'] = false;
        }
        return $cfg;
    }

    // The bar for "ready to submit a track" — driven by the specific
    // contest's own contact-field configuration above, checked against
    // profile data from the Own Ur Shit core plugin (BHI_Profiles) now
    // that identity is shared across the ecosystem rather than owned by
    // this plugin. Voters never have to clear this — only checked at
    // submission time.
    public static function missing_for_submission($user_id, $cid) {
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

    // ROADMAP-ux-polish-and-feature-parity-2026-07.md 2a — a genuine
    // per-contest format choice, not a single ecosystem-wide behavior.
    // 'public' (the pre-existing, unchanged voting model) is the default
    // for every contest that never sets this, so nothing already running
    // changes shape. 'judges' replaces public voting's leaderboard with
    // BH_Judging::judge_results() everywhere a leaderboard is read.
    // 'hybrid' keeps both running side by side, surfaced as two SEPARATE
    // leaderboards (Judges' Pick / People's Choice) — a direct decision
    // from the roadmap doc rather than inventing a blending formula.
    public static function contest_format($cid) {
        $format = get_post_meta($cid, '_bh_contest_format', true);
        return in_array($format, ['judges', 'hybrid'], true) ? $format : 'public';
    }

    public static function categories($cid) {
        $raw = get_post_meta($cid, '_bh_categories', true);
        $list = $raw ? json_decode($raw, true) : [];
        return is_array($list) ? $list : [];
    }

    // '' if the contest has no named categories, otherwise the first
    // defined category — used as the default when a request doesn't
    // specify one.
    public static function default_category($cid) {
        $cats = self::categories($cid);
        return $cats ? $cats[0]['slug'] : '';
    }

    // A category value is valid if it's '' on a contest with no categories,
    // or matches one of the contest's defined category slugs.
    public static function is_valid_category($cid, $slug) {
        $cats = self::categories($cid);
        if (!$cats) return $slug === '';
        foreach ($cats as $c) if ($c['slug'] === $slug) return true;
        return false;
    }

    // Turns free-text (one category name per line) into a slug+name list,
    // deduping slugs so two similarly-named categories don't collide.
    public static function parse_categories_input($text) {
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
    public static function has_submitted($uid, $cid) {
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

    // Deliberately NOT the same check as has_submitted() above. A pending
    // (unapproved) submission still correctly blocks a second entry via
    // has_submitted(), but it must NOT earn the bonus vote — otherwise
    // anyone could submit junk, get the bonus instantly, and vote with
    // it before an admin ever reviews the track. Only a post_status of
    // 'publish' (i.e. actually approved — see class-admin.php) counts
    // here.
    public static function has_approved_submission($uid, $cid) {
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

    // Vote limit applies per category independently — an APPROVED
    // submission earns the bonus vote in every category, not just one.
    // A contest can override the global base/bonus (see the Contest
    // Rules metabox); leaving those fields blank falls back to the
    // plugin-wide default.
    //
    // Deliberately scoped to THIS contest only ($cid is part of both
    // queries above) — a user's submission history in other contests,
    // or across their lifetime on the site, has no bearing on their vote
    // count here. If a future feature ever wants a cross-contest loyalty
    // bonus, that would need to be a new, explicitly-named function
    // (e.g. lifetime_bonus()) added deliberately — not something that
    // should ever fall out of quietly removing the $cid scoping below.
    public static function vote_limit($uid, $cid) {
        $base  = get_post_meta($cid, '_bh_vote_base', true);
        $bonus = get_post_meta($cid, '_bh_vote_bonus', true);
        $base  = ($base === '' || $base === false) ? BH_VOTE_BASE : max(0, (int) $base);
        $bonus = ($bonus === '' || $bonus === false) ? BH_VOTE_BONUS : max(0, (int) $bonus);
        return $base + (self::has_approved_submission($uid, $cid) ? $bonus : 0);
    }

    // $round: ROADMAP-ux-polish-and-feature-parity-2026-07.md 2b — null
    // (every pre-existing call site) counts votes across every round, a
    // no-op distinction for a single-round contest since every one of
    // its votes carries round = 0.
    public static function user_vote_count($uid, $cid, $category = '', $round = null) {
        global $wpdb;
        $t = self::table();
        $round_sql = $round !== null ? $wpdb->prepare('AND round = %d', (int) $round) : '';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE user_id = %d AND contest_id = %d AND category = %s $round_sql", $uid, $cid, $category
        ));
    }

    public static function submission_count($cid, $status = 'publish') {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE p.post_type = 'bh_submission' AND p.post_status = %s
               AND m.meta_key = '_bh_contest_id' AND m.meta_value = %d",
            $status, $cid
        ));
    }

    public static function vote_count($cid, $category = null) {
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

    public static function user_total_votes($uid) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d", $uid
        ));
    }

    public static function allowed_audio() {
        return ['mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4'];
    }

    // Standard "competition ranking": ties share a rank, and the next
    // distinct value skips ahead to reflect how many entries are ahead
    // of it — 10,7,7,5 votes ranks as 1,2,2,4, same convention as an
    // Olympic medal tie (two silvers means no bronze at all, not two
    // silvers plus an extra bronze). $votes_desc must already be sorted
    // highest-first; returns one rank per input position, same length
    // and order. The one canonical implementation of this — every place
    // that builds a ranked leaderboard (category results, overall
    // results, the reveal sequence) calls this rather than each
    // re-deriving its own notion of what a tie means.
    public static function competition_ranks($votes_desc) {
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

    public static function artist_for($post) {
        return get_post_meta($post->ID, '_bh_artist_name', true)
            ?: get_the_author_meta('display_name', $post->post_author);
    }
}
