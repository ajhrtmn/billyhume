<?php
if (!defined('ABSPATH')) exit;

/**
 * Automatic discovery — an open, unauthenticated PULL/crawl model.
 * Replaces an earlier push+shared-secret design (shipped briefly as
 * 0.1.18, then redesigned): that design required two site admins to
 * manually exchange a secret before anything could flow, which not
 * only reintroduced the manual coordination this whole feature exists
 * to remove, but had a real, undiscovered bug (no way to make two
 * independently-generated secrets match) that would have kept it from
 * ever actually working bidirectionally. This design has no secret,
 * no privileged inbound endpoint, and no per-relationship setup step
 * at all beyond knowing a peer's base_url.
 *
 * Three ways a candidate base_url can enter this site's bhr_peers
 * table — a manually-added genesis peer, an ActivityPub relay hit, or
 * a search-index hit (both of the latter still real, separate,
 * larger pieces of work, not built here) — all funnel into the exact
 * same table and the exact same crawl/ingest/verify loop below. No
 * layer gets its own parallel trust tier.
 *
 * Trust model, unchanged from the original design and from manual
 * submission before it: this class NEVER marks anything verified and
 * NEVER trusts a peer's claim about a candidate's status. A crawled
 * candidate is a thin pointer, inserted through the exact same
 * pending-row shape POST /submissions already writes, then queued for
 * this site's own independent BHR_Verification::verify_link() check —
 * completely unchanged, never bypassed, never sped up.
 */
class BHR_Crawl {
    const MAX_LINKS_PER_MANIFEST = 50;
    const MAX_KNOWN_PEERS_PER_MANIFEST = 50;
    const MAX_TOTAL_PEERS = 200;
    const MAX_HOPS_DEFAULT = 3;
    const LIVENESS_FAIL_THRESHOLD = 5;

    /* ---------- inbound: serving our own manifest ---------- */

    // Open, __return_true — matches every other read route in this
    // namespace. Nothing here is privileged: it's this site's own
    // already-public verified-artist data, plus the base_urls of peers
    // this site has already chosen to crawl (themselves public
    // information once you're crawling this site at all).
    public static function handle_manifest(\WP_REST_Request $req): \WP_REST_Response {
        global $wpdb;

        $links = $wpdb->get_results($wpdb->prepare(
            "SELECT l.protocol, l.url, l.verified_at, a.display_name
             FROM {$wpdb->prefix}bhr_links l
             JOIN {$wpdb->prefix}bhr_artists a ON a.id = l.artist_id
             WHERE l.verification_status = 'verified' AND a.status = 'active'
             ORDER BY l.verified_at DESC LIMIT %d",
            self::MAX_LINKS_PER_MANIFEST
        ));

        $peer_urls = $wpdb->get_col($wpdb->prepare(
            "SELECT base_url FROM {$wpdb->prefix}bhr_peers WHERE status = 'active' ORDER BY last_seen_at DESC LIMIT %d",
            self::MAX_KNOWN_PEERS_PER_MANIFEST
        ));

        return new WP_REST_Response([
            'base_url' => home_url(),
            'registry_version' => defined('BHR_VER') ? BHR_VER : '',
            'peer_protocol_version' => 2, // bumped from 1 — this is a new, incompatible wire shape (manifest, not announce)
            'verified_links' => array_map(function ($l) {
                return [
                    'protocol'      => $l->protocol,
                    'url'           => $l->url,
                    'display_name'  => $l->display_name,
                    'verified_at'   => $l->verified_at,
                ];
            }, $links),
            'known_peers' => array_values($peer_urls),
        ], 200);
    }

    /* ---------- outbound: crawling known peers ---------- */

    // Daily cron target — enqueues one job per active peer rather than
    // crawling all of them inline, same staggering convention the rest
    // of this ecosystem uses for fan-out-shaped work.
    /**
     * @param bool $immediate Run every peer inline instead of queueing.
     *        The scheduled daily run queues (so one slow/hanging peer
     *        can't stall the rest, and the queue's own retry/backoff
     *        applies). An admin clicking "Crawl peers now" means NOW —
     *        queueing there would show "ran" while nothing visibly
     *        changed until the next cron tick, which is exactly the
     *        kind of quietly-lying UI this project tries not to ship.
     */
    public static function crawl_all_peers(bool $immediate = false): void {
        global $wpdb;
        $peers = $wpdb->get_results("SELECT id FROM {$wpdb->prefix}bhr_peers WHERE status = 'active'");

        if ($immediate || !class_exists('OUS_Jobs')) {
            foreach ($peers as $peer) self::crawl_one_peer(['peer_id' => (int) $peer->id]);
            return;
        }

        foreach ($peers as $i => $peer) {
            OUS_Jobs::enqueue('bhr_crawl_one_peer', ['peer_id' => (int) $peer->id], $i * 5);
        }
    }

    /**
     * OUS_Jobs handler for 'bhr_crawl_one_peer'. Fetches one peer's
     * manifest, ingests its verified links as candidates, and follows
     * its known_peers list onward (hop-limited, capped, SSRF-guarded).
     *
     * @param array<string, mixed> $args
     */
    public static function crawl_one_peer(array $args): void {
        $peer_id = (int) ($args['peer_id'] ?? 0);
        if (!$peer_id) return;

        global $wpdb;
        $peers_table = $wpdb->prefix . 'bhr_peers';
        $peer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $peers_table WHERE id = %d", $peer_id));
        if (!$peer || $peer->status !== 'active') return;

        if (!self::is_safe_external_url($peer->base_url)) {
            // A peer's own base_url should have been validated at
            // add-time, but re-check on every crawl too — DNS can
            // change after the fact (a real, if unlikely, way a
            // previously-safe URL could start resolving to a private
            // address later).
            self::record_crawl_failure($peer);
            return;
        }

        $res = wp_remote_get(rtrim($peer->base_url, '/') . '/wp-json/bhr/v1/peers/manifest', ['timeout' => 10, 'redirection' => 2]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            self::record_crawl_failure($peer, $res);
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($body)) {
            self::record_crawl_failure($peer);
            return;
        }

        $links = is_array($body['verified_links'] ?? null) ? array_slice($body['verified_links'], 0, self::MAX_LINKS_PER_MANIFEST) : [];
        $known_peers = is_array($body['known_peers'] ?? null) ? array_slice($body['known_peers'], 0, self::MAX_KNOWN_PEERS_PER_MANIFEST) : [];

        foreach ($links as $candidate) {
            self::ingest_candidate($candidate, $peer);
        }

        $max_hops = self::max_hops();
        if ((int) $peer->discovered_hop < $max_hops) {
            foreach ($known_peers as $known_url) {
                self::maybe_add_discovered_peer((string) $known_url, (int) $peer->discovered_hop + 1);
            }
        }

        $wpdb->update($peers_table, ['last_crawled_at' => current_time('mysql'), 'last_seen_at' => current_time('mysql'), 'fail_count' => 0], ['id' => $peer_id]);
    }

    /** @param array<string, mixed>|\WP_Error|null $res */
    private static function record_crawl_failure(\stdClass $peer, $res = null): void {
        global $wpdb;
        $fails = (int) $peer->fail_count + 1;
        $update = ['fail_count' => $fails];
        if ($fails >= self::LIVENESS_FAIL_THRESHOLD && $peer->status === 'active') {
            $update['status'] = 'paused';
        }
        $wpdb->update($wpdb->prefix . 'bhr_peers', $update, ['id' => $peer->id]);

        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('info', 'Peer crawl failed.', [
                'peer_id' => $peer->id, 'base_url' => $peer->base_url, 'fail_count' => $fails,
                'wp_error' => $res && is_wp_error($res) ? $res->get_error_message() : null,
            ], 'BH Registry Crawl');
        }
    }

    /**
     * One candidate from a fetched manifest. Never verifies inline —
     * only ever queues this site's own independent verification, same
     * as before.
     *
     * @param mixed $candidate
     */
    private static function ingest_candidate($candidate, \stdClass $peer): void {
        if (!is_array($candidate)) return;

        $protocol = sanitize_text_field((string) ($candidate['protocol'] ?? ''));
        $url      = esc_url_raw((string) ($candidate['url'] ?? ''));
        $display_name = sanitize_text_field((string) ($candidate['display_name'] ?? ''));

        if (!in_array($protocol, ['activitypub', 'feed'], true) || !$url || !wp_http_validate_url($url)) return;

        $hash = self::candidate_hash($protocol, $url);

        global $wpdb;
        $seen_table = $wpdb->prefix . 'bhr_gossip_seen';
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $seen_table WHERE seen_hash = %s", $hash));

        if ($existing) {
            $wpdb->update($seen_table, [
                'last_seen_at' => current_time('mysql'),
                'min_hop_seen' => min((int) $existing->min_hop_seen, (int) $peer->discovered_hop),
            ], ['id' => $existing->id]);
            return;
        }

        $wpdb->insert($seen_table, [
            'seen_hash'       => $hash,
            'origin_base_url' => $peer->base_url,
            'candidate_url'   => $url,
            'protocol'        => $protocol,
            'min_hop_seen'    => (int) $peer->discovered_hop,
        ]);

        $artists_t = $wpdb->prefix . 'bhr_artists';
        $links_t   = $wpdb->prefix . 'bhr_links';

        // Gossip never carries contact_email, so unlike create_submission()
        // there's no email-based artist dedup — every newly-seen
        // candidate is its own new artist row.
        if (!$wpdb->insert($artists_t, ['display_name' => $display_name ?: 'Unknown artist', 'status' => 'pending'])) return;
        $artist_id = (int) $wpdb->insert_id;

        $token = class_exists('BHR_Verification') ? BHR_Verification::generate_token() : wp_generate_password(32, false, false);
        if (!$wpdb->insert($links_t, [
            'artist_id'                => $artist_id,
            'protocol'                 => $protocol,
            'url'                      => $url,
            'verification_token'       => $token,
            'verification_status'      => 'pending',
            'discovered_via'           => 'crawl',
            'discovered_from_peer_id'  => $peer->id,
            'discovered_hop_count'     => (int) $peer->discovered_hop,
        ])) {
            $wpdb->delete($artists_t, ['id' => $artist_id]);
            return;
        }
        $link_id = (int) $wpdb->insert_id;

        if (class_exists('OUS_Jobs')) {
            OUS_Jobs::enqueue('bhr_verify_gossip_candidate', ['link_id' => $link_id]);
        } elseif (class_exists('BHR_Links') && class_exists('BHR_Verification')) {
            $link = BHR_Links::find($link_id);
            if ($link) BHR_Verification::verify_link($link);
        }
    }

    /* ---------- peer auto-discovery (any layer feeds this one function) ---------- */

    /**
     * Called with a candidate base_url discovered via ANY of the three
     * layers (a peer's own known_peers list, an ActivityPub relay
     * Announce, or a search-index hit) — one shared entry point, one
     * shared set of safety checks, regardless of source.
     */
    public static function maybe_add_discovered_peer(string $base_url, int $discovered_hop): bool {
        $base_url = self::normalize_base_url($base_url);
        if (!$base_url || !wp_http_validate_url($base_url)) return false;
        if (!self::is_safe_external_url($base_url)) return false;

        global $wpdb;
        $peers_table = $wpdb->prefix . 'bhr_peers';

        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $peers_table WHERE base_url = %s", $base_url));
        if ($existing) return false;

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $peers_table");
        if ($total >= self::MAX_TOTAL_PEERS) return false;

        // Confirm it's actually a live, real bh-registry install before
        // adding — same "fail closed" principle the old manual Add Peer
        // flow already used, now applied automatically instead of by an
        // admin clicking a button.
        $res = wp_remote_get($base_url . '/wp-json/bhr/v1/peers/manifest', ['timeout' => 8, 'redirection' => 2]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return false;
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($body) || !isset($body['base_url'])) return false;

        return (bool) $wpdb->insert($peers_table, [
            'base_url'       => $base_url,
            'status'         => 'active',
            'discovered_hop' => $discovered_hop,
        ]);
    }

    /* ---------- shared helpers ---------- */

    public static function candidate_hash(string $protocol, string $url): string {
        return hash('sha256', $protocol . '|' . self::normalize_candidate_url($url));
    }

    private static function normalize_candidate_url(string $url): string {
        $parts = wp_parse_url(strtolower(trim($url)));
        if (!$parts || empty($parts['host'])) return strtolower(trim($url));
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'];
        $port   = isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true) ? ':' . $parts['port'] : '';
        $path   = rtrim($parts['path'] ?? '', '/');
        return $scheme . '://' . $host . $port . $path;
    }

    public static function normalize_base_url(string $url): string {
        $url = trim($url);
        return $url === '' ? '' : rtrim($url, '/');
    }

    public static function max_hops(): int {
        return max(1, (int) get_option('bhr_crawl_max_hops', self::MAX_HOPS_DEFAULT));
    }

    /**
     * SSRF guard — the real, new attack surface this pull/crawl
     * redesign introduces versus the old design: this class now
     * fetches URLs a REMOTE PEER merely claims exist (its known_peers
     * list), not just URLs a local admin chose. Resolves the host and
     * rejects anything in a private/loopback/link-local/reserved
     * range, plus any non-http(s) scheme. Checked before every single
     * outbound request this class makes to a peer-supplied URL.
     */
    public static function is_safe_external_url(string $url): bool {
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return false;
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return false;

        $host = $parts['host'];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $host;
        } else {
            $resolved = gethostbyname($host);
            // gethostbyname() returns the original hostname unchanged
            // on resolution failure — treat that as unsafe rather than
            // silently letting an unresolvable host through.
            if ($resolved === $host) return false;
            $ip = $resolved;
        }

        // FILTER_FLAG_NO_PRIV_RANGE + FILTER_FLAG_NO_RES_RANGE together
        // reject RFC1918 private ranges, loopback, link-local, and
        // other reserved blocks for both IPv4 and IPv6 in one call —
        // exactly the set that matters for SSRF (internal services,
        // cloud metadata endpoints like 169.254.169.254, etc.).
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
