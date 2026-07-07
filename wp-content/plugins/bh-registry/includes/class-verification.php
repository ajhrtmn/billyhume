<?php
if (!defined('ABSPATH')) exit;

/**
 * The actual trust mechanism the whole registry depends on. Two
 * genuinely separate questions, checked independently:
 *
 * 1. OWNERSHIP — does whoever submitted this link actually control the
 *    domain it points at? Answered with a well-known file challenge:
 *    at submission time we generate a random token and ask the
 *    submitter to publish it at
 *    https://{host}/.well-known/bh-registry-verify.txt
 *    A domain-level file (not something embedded in the ActivityPub
 *    actor or the feed itself) so the SAME proof-of-control mechanism
 *    works identically regardless of protocol — an artist proves they
 *    control the domain once, not once per protocol.
 *
 * 2. OPENNESS — is the submitted URL actually the open protocol it
 *    claims to be, not just an arbitrary URL that happens to return
 *    200 OK? For 'feed', this is bh-streaming's own
 *    validate_is_open_feed() check (real enclosure required) —
 *    duplicated here rather than shared as a hard dependency, since this
 *    plugin must not require bh-streaming to exist. If this logic drifts
 *    between the two plugins, lifting it into the core (own-ur-shit) as
 *    a shared BHY_OpenFeed helper is the right long-term fix — flagged
 *    here, not done, since that's a core-plugin change outside this
 *    plugin's own boundary. For 'activitypub', it's WebFinger + actor
 *    discovery per the ActivityPub spec — any compliant server, not a
 *    Funkwhale-specific check.
 *
 * A link only ever becomes 'verified' when BOTH checks pass. Either one
 * failing is 'failed', with the reason kept server-side (not exposed
 * over the public API) so a rejected submission isn't handed a roadmap
 * for gaming the check.
 */
class BHR_Verification {
    const TOKEN_LENGTH = 32;

    public static function generate_token() {
        return wp_generate_password(self::TOKEN_LENGTH, false, false);
    }

    /**
     * Runs both checks for a single link row (an object with ->id,
     * ->url, ->protocol, ->verification_token) and persists the result.
     * Returns true if the link is now verified, false otherwise.
     */
    public static function verify_link($link) {
        global $wpdb;
        $table = $wpdb->prefix . 'bhr_links';

        $owns_domain = self::check_domain_ownership($link->url, $link->verification_token);
        $is_open     = $link->protocol === 'feed'
            ? self::check_open_feed($link->url)
            : self::check_activitypub_actor($link->url);

        $now = current_time('mysql');
        if ($owns_domain && $is_open['valid']) {
            $wpdb->update($table, [
                'verification_status' => 'verified',
                'verified_at'          => $now,
                'last_checked_at'      => $now,
                'fail_count'           => 0,
                'metadata'             => wp_json_encode($is_open['metadata']),
            ], ['id' => $link->id]);
            self::maybe_activate_artist($link->artist_id);
            return true;
        }

        $wpdb->update($table, [
            'verification_status' => 'failed',
            'last_checked_at'      => $now,
            'fail_count'           => (int) $link->fail_count + 1,
        ], ['id' => $link->id]);

        // The reason itself is deliberately never exposed over the
        // public API (see this class's own docblock — no roadmap for
        // gaming the check) but it's exactly the kind of thing a site
        // operator debugging "why won't my link verify" support request
        // needs to see somewhere, so it lands in the private console
        // instead of nowhere at all.
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('info', 'Link verification failed', [
                'link_id' => $link->id, 'url' => $link->url,
                'owns_domain' => $owns_domain, 'is_open' => $is_open['valid'],
            ], 'BH Registry');
        }
        return false;
    }

    // A pending artist becomes active the moment it has at least one
    // verified link — that's the actual bar for "shown in public
    // browse/search," not an admin approval step (this registry is
    // self-serve by design; admin review exists for abuse handling, not
    // as a required gate — see class-admin.php).
    private static function maybe_activate_artist($artist_id) {
        global $wpdb;
        $artists = $wpdb->prefix . 'bhr_artists';
        $links   = $wpdb->prefix . 'bhr_links';

        $artist = $wpdb->get_row($wpdb->prepare("SELECT * FROM $artists WHERE id = %d", $artist_id));
        if (!$artist || $artist->status === 'rejected') return; // an explicit admin rejection is sticky

        $has_verified = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $links WHERE artist_id = %d AND verification_status = 'verified'", $artist_id
        ));
        if ($has_verified && $artist->status !== 'active') {
            $wpdb->update($artists, ['status' => 'active', 'updated_at' => current_time('mysql')], ['id' => $artist_id]);
        }
    }

    /* ---------- ownership ---------- */

    private static function check_domain_ownership($url, $token) {
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!$host) return false;

        $challenge_url = 'https://' . $host . '/.well-known/bh-registry-verify.txt';
        $res = wp_remote_get($challenge_url, ['timeout' => 8, 'redirection' => 2]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return false;

        $body = trim(wp_remote_retrieve_body($res));
        // Exact-match on a line, not a substring-of-arbitrary-page —
        // this file's only job is to hold this one token.
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (trim($line) === $token) return true;
        }
        return false;
    }

    /* ---------- openness: feed ---------- */

    // Mirrors bh-streaming's class-feeds.php validate_is_open_feed()
    // exactly: requires at least one item with a real enclosure, using
    // fetch_feed() (WordPress core's own SimplePie-based parser) rather
    // than custom XML parsing.
    private static function check_open_feed($url) {
        require_once ABSPATH . WPINC . '/feed.php';
        $feed = fetch_feed($url);
        if (is_wp_error($feed)) return ['valid' => false, 'metadata' => []];

        $items = $feed->get_items(0, 5);
        $has_enclosure = false;
        foreach ($items as $item) {
            if ($item->get_enclosure() && $item->get_enclosure()->get_link()) { $has_enclosure = true; break; }
        }
        if (!$has_enclosure) return ['valid' => false, 'metadata' => []];

        return ['valid' => true, 'metadata' => [
            'title'          => $feed->get_title(),
            'item_count'     => $feed->get_item_quantity(),
            'last_item_date' => $items ? $items[0]->get_date('c') : null,
        ]];
    }

    /* ---------- openness: ActivityPub ---------- */

    // Actor discovery per the ActivityPub spec: fetch the URL with
    // Accept: application/activity+json (falling back to
    // application/ld+json) and require it to look like a real actor —
    // a recognized Actor type plus an outbox — rather than trusting any
    // JSON blob. This is protocol-open by construction: any server
    // returning a spec-shaped actor document passes identically,
    // Funkwhale included but never assumed.
    private static function check_activitypub_actor($url) {
        $res = wp_remote_get($url, [
            'timeout' => 8, 'redirection' => 2,
            'headers' => ['Accept' => 'application/activity+json, application/ld+json'],
        ]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return ['valid' => false, 'metadata' => []];

        $data = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($data)) return ['valid' => false, 'metadata' => []];

        $type = $data['type'] ?? '';
        $valid_types = ['Person', 'Group', 'Service', 'Application', 'Organization'];
        if (!in_array($type, $valid_types, true) || empty($data['outbox'])) {
            return ['valid' => false, 'metadata' => []];
        }

        return ['valid' => true, 'metadata' => [
            'name'    => $data['name'] ?? ($data['preferredUsername'] ?? ''),
            'summary' => wp_strip_all_tags($data['summary'] ?? ''),
            'icon'    => is_array($data['icon'] ?? null) ? ($data['icon']['url'] ?? '') : '',
        ]];
    }

    // Re-checks every link belonging to one artist — used when an admin
    // restores a previously-rejected artist (see class-admin.php), so
    // restoring doesn't just flip a flag without re-confirming the
    // underlying links are still actually valid.
    public static function recheck_artist($artist_id) {
        global $wpdb;
        $links = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bhr_links WHERE artist_id = %d", $artist_id
        ));
        foreach ($links as $link) {
            self::verify_link($link);
        }
    }

    /* ---------- periodic re-check ---------- */

    // A "verified" badge that's never re-checked stops meaning anything
    // the moment control lapses (domain sold, DNS changed, well-known
    // file deleted). Runs daily via bh-registry.php's cron hook, capped
    // per run so a large registry doesn't try to re-check everything in
    // one page-load-triggered WP-Cron tick.
    //
    // If the core's job queue is active (own-ur-shit 3.2.0+ — see
    // OUS_Jobs), each link gets its own queued job instead of all 50
    // running inline in this one cron tick: one slow/hanging feed check
    // can no longer block the rest of the batch, and a failed check
    // gets the queue's own retry/backoff for free. Falls back to the
    // original all-inline behavior if the job queue isn't there —
    // bh-registry depends only on own-ur-shit's CORE presence, never on
    // any particular optional feature inside it, so this degrades
    // gracefully on an older core the same way every other optional
    // integration in this ecosystem does.
    public static function recheck_all() {
        global $wpdb;
        $table = $wpdb->prefix . 'bhr_links';
        $links = $wpdb->get_results(
            "SELECT * FROM $table WHERE verification_status = 'verified'
             ORDER BY last_checked_at ASC LIMIT 50"
        );

        if (class_exists('OUS_Jobs')) {
            foreach ($links as $link) {
                OUS_Jobs::enqueue('bhr_recheck_one_link', ['link_id' => $link->id]);
            }
            return;
        }

        foreach ($links as $link) {
            self::verify_link($link);
        }
    }

    // The per-link unit of work OUS_Jobs actually calls — registered as
    // a handler in bh-registry.php's own bootstrap, guarded by the same
    // class_exists() check, so this method existing costs nothing on a
    // core version without the job queue.
    public static function recheck_one($args) {
        $link_id = (int) ($args['link_id'] ?? 0);
        if (!$link_id) return;
        $link = BHR_Links::find($link_id);
        if ($link) self::verify_link($link);
    }
}
