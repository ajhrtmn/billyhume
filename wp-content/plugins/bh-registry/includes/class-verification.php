<?php
if (!defined('ABSPATH')) exit;

/**
 * The registry's trust mechanism: a link is verified only when BOTH checks pass.
 *  1. Ownership: the token is published at https://{host}/.well-known/bh-registry-verify.txt (protocol-independent).
 *  2. Openness: a real feed with an enclosure, or a spec-shaped ActivityPub actor.
 * WHY: failure reasons stay server-side so rejected submitters get no roadmap for gaming the check.
 */
class BHR_Verification {
    const TOKEN_LENGTH = 32;

    public static function generate_token(): string {
        return wp_generate_password(self::TOKEN_LENGTH, false, false);
    }

    /** Runs both checks for one link row, persists the result, and returns whether it is now verified. */
    public static function verify_link(\stdClass $link): bool {
        global $wpdb;
        $table = BHR_Tables::links();

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

        // Private console only; never exposed over the public API.
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('info', 'Link verification failed', [
                'link_id' => $link->id, 'url' => $link->url,
                'owns_domain' => $owns_domain, 'is_open' => $is_open['valid'],
            ], 'BH Registry');
        }
        return false;
    }

    // One verified link activates an artist; there is no admin approval gate (self-serve by design).
    private static function maybe_activate_artist(int $artist_id): void {
        global $wpdb;
        $artists = BHR_Tables::artists();
        $links   = BHR_Tables::links();

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

    private static function check_domain_ownership(string $url, string $token): bool {
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!$host) return false;

        $challenge_url = 'https://' . $host . '/.well-known/bh-registry-verify.txt';

        // WHY: the URL comes from an unauthenticated submission, so SSRF-guard every outbound fetch.
        if (!class_exists('BHR_Crawl') || !BHR_Crawl::is_safe_external_url($challenge_url)) return false;

        $res = wp_remote_get($challenge_url, ['timeout' => 8, 'redirection' => 2]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            // Logged so an admin can tell a missing challenge file from a network error.
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('info', 'Domain ownership challenge fetch failed.', [
                    'challenge_url' => $challenge_url,
                    'wp_error' => is_wp_error($res) ? $res->get_error_message() : null,
                    'http_status' => is_wp_error($res) ? null : wp_remote_retrieve_response_code($res),
                ], 'BH Registry Verification');
            }
            return false;
        }

        $body = trim(wp_remote_retrieve_body($res));
        // Exact line match, not a substring of an arbitrary page.
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (trim($line) === $token) return true;
        }
        return false;
    }

    /* ---------- openness: feed ---------- */

    // Mirrors bh-streaming's validate_is_open_feed() (duplicated, not depended on); keep the two in sync.
    /** @return array{valid:bool, metadata:array<string, mixed>} */
    private static function check_open_feed(string $url): array {
        if (!class_exists('BHR_Crawl') || !BHR_Crawl::is_safe_external_url($url)) return ['valid' => false, 'metadata' => []];

        require_once ABSPATH . WPINC . '/feed.php';
        $feed = fetch_feed($url);
        if (is_wp_error($feed)) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('info', 'Open-feed check: fetch_feed() failed.', [
                    'url' => $url, 'wp_error' => $feed->get_error_message(),
                ], 'BH Registry Verification');
            }
            return ['valid' => false, 'metadata' => []];
        }

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

    // Requires a spec-shaped actor (a recognized Actor type plus an outbox), from any compliant server.
    /** @return array{valid:bool, metadata:array<string, mixed>} */
    private static function check_activitypub_actor(string $url): array {
        if (!class_exists('BHR_Crawl') || !BHR_Crawl::is_safe_external_url($url)) return ['valid' => false, 'metadata' => []];

        $res = wp_remote_get($url, [
            'timeout' => 8, 'redirection' => 2,
            'headers' => ['Accept' => 'application/activity+json, application/ld+json'],
        ]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('info', 'ActivityPub actor check: fetch failed.', [
                    'url' => $url,
                    'wp_error' => is_wp_error($res) ? $res->get_error_message() : null,
                    'http_status' => is_wp_error($res) ? null : wp_remote_retrieve_response_code($res),
                ], 'BH Registry Verification');
            }
            return ['valid' => false, 'metadata' => []];
        }

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

    // Re-checks all of an artist's links, e.g. when an admin restores a rejected artist.
    public static function recheck_artist(int $artist_id): void {
        global $wpdb;
        $links = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . BHR_Tables::links() . " WHERE artist_id = %d", $artist_id
        ));
        foreach ($links as $link) {
            self::verify_link($link);
        }
    }

    /* ---------- periodic re-check ---------- */

    // Daily re-check so a lapsed domain loses its badge; capped at the 50 oldest per run.
    // One OUS_Jobs job per link when available (a hanging check can't block the batch), else inline.
    public static function recheck_all(): void {
        global $wpdb;
        $table = BHR_Tables::links();
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

    // OUS_Jobs handler for 'bhr_recheck_one_link'.
    /** @param array<string, mixed> $args */
    public static function recheck_one(array $args): void {
        $link_id = (int) ($args['link_id'] ?? 0);
        if (!$link_id) return;
        $link = BHR_Links::find($link_id);
        if ($link) self::verify_link($link);
    }
}
