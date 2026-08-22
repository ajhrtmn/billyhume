<?php
if (!defined('ABSPATH')) exit;

/**
 * Peer gossip/announce — automatic discovery propagation, layered on
 * TOP of the existing manual-submission trust model, never a
 * replacement for it. Two genuinely separate questions, kept separate
 * on purpose:
 *
 * 1. DISCOVERY — how does a candidate URL reach this site at all?
 *    Answered here: an authenticated peer POSTs a thin pointer
 *    (protocol + url, nothing else trusted) to /bhr/v1/peers/announce.
 * 2. TRUST — is that URL actually real, open, and controlled by
 *    whoever it claims? Answered EXCLUSIVELY by
 *    BHR_Verification::verify_link() — completely untouched by this
 *    class. A gossip-discovered candidate goes through the identical
 *    domain-ownership + open-protocol check any manually-submitted one
 *    does. This class never marks anything verified, never trusts a
 *    peer's claim about a candidate's status, and never lets a
 *    candidate skip the queue.
 *
 * Peering itself is always an explicit, mutual, admin-initiated action
 * (see class-peers.php) — this class never auto-discovers or
 * auto-trusts a new peer. A site with zero peers configured behaves
 * exactly as if this file didn't exist: /peers/announce always 401s
 * (no secret to authenticate against), and the fan-out loop below is
 * always empty.
 */
class BHR_Gossip {
    const MAX_HOPS_DEFAULT = 3;
    const MAX_CANDIDATES_PER_ANNOUNCE = 20;
    const MAX_BODY_BYTES = 20000; // ~20KB — a real single-verification-event announce is always tiny
    const RATE_LIMIT_PER_MINUTE = 30;

    /* ---------- inbound: receiving an announce ---------- */

    /**
     * permission_callback for POST /bhr/v1/peers/announce. Runs BEFORE
     * the main callback — rejects unauthenticated/spoofed/blocked
     * requests before any DB write or further processing happens.
     *
     * @return bool|\WP_Error
     */
    public static function check_peer_auth(\WP_REST_Request $req) {
        $origin = self::normalize_base_url((string) $req->get_param('origin_base_url'));
        $secret = (string) $req->get_header('x_bhr_peer_secret');

        if ($origin === '' || $secret === '') {
            return new WP_Error('missing_credentials', 'origin_base_url and X-BHR-Peer-Secret are both required.', ['status' => 401]);
        }

        $peer = self::find_peer_by_base_url($origin);
        // Deliberately identical error for "no such peer" and "wrong
        // secret" — a distinguishable response would let an attacker
        // enumerate which base_urls are configured as peers on this
        // site.
        if (!$peer || $peer->status !== 'active') {
            return new WP_Error('unauthorized', 'Not a recognized, active peer.', ['status' => 401]);
        }

        // hash_equals() specifically — a plain === or strpos comparison
        // here would leak the secret's correct prefix length through
        // response-timing differences (a real, well-known class of
        // attack against exactly this shape of check).
        if (!hash_equals($peer->shared_secret, $secret)) {
            return new WP_Error('unauthorized', 'Not a recognized, active peer.', ['status' => 401]);
        }

        return true;
    }

    /**
     * The actual announce receiver. By the time this runs,
     * check_peer_auth() has already confirmed the caller is a real,
     * active, correctly-authenticated peer — this method's job is
     * purely discovery bookkeeping, never trust.
     */
    public static function handle_announce(\WP_REST_Request $req): \WP_REST_Response {
        $origin = self::normalize_base_url((string) $req->get_param('origin_base_url'));
        $peer = self::find_peer_by_base_url($origin);
        // check_peer_auth() already guaranteed this exists and is
        // active, but permission_callback and callback are two separate
        // invocations — re-derive rather than trust a static between
        // them.
        if (!$peer) {
            return new WP_REST_Response(['success' => false, 'message' => 'Peer not found.'], 401);
        }

        // Per-peer rate limit, checked BEFORE touching the body or the
        // DB any further — an authenticated peer that misbehaves (bug,
        // compromise, or just a burst) shouldn't be able to hammer this
        // site's DB regardless of intent. Keyed by authenticated peer
        // identity, not IP, matching this class's own trust model
        // (peers are known/authenticated here, unlike /submissions'
        // anonymous public callers, which stay IP-throttled).
        if (class_exists('OUS_ReliableStore')) {
            $count = OUS_ReliableStore::increment('bhr_gossip_rl_' . $peer->id, MINUTE_IN_SECONDS);
            if ($count > self::RATE_LIMIT_PER_MINUTE) {
                return new WP_REST_Response(['success' => false, 'message' => 'Rate limit exceeded.'], 429);
            }
        }

        // Defense-in-depth body-size check — WP's REST server has
        // already parsed this into $req by the time we're here, but a
        // cheap raw-length check before we do any real work costs
        // nothing and blocks the crude "just send a huge body" version
        // of a DoS attempt.
        if (strlen($req->get_body()) > self::MAX_BODY_BYTES) {
            return new WP_REST_Response(['success' => false, 'message' => 'Payload too large.'], 413);
        }

        $hop_count = max(0, (int) $req->get_param('hop_count'));
        $candidates = $req->get_param('candidates');
        if (!is_array($candidates)) $candidates = [];
        $candidates = array_slice($candidates, 0, self::MAX_CANDIDATES_PER_ANNOUNCE);

        $accepted = 0;
        $deduped  = 0;
        $rejected = 0;

        foreach ($candidates as $candidate) {
            $result = self::ingest_candidate($candidate, $origin, $peer, $hop_count);
            if ($result === 'accepted') $accepted++;
            elseif ($result === 'deduped') $deduped++;
            else $rejected++;
        }

        global $wpdb;
        $wpdb->update($wpdb->prefix . 'bhr_peers', ['last_seen_at' => current_time('mysql')], ['id' => $peer->id]);

        return new WP_REST_Response(['success' => true, 'accepted' => $accepted, 'deduped' => $deduped, 'rejected' => $rejected], 200);
    }

    /**
     * One candidate from an announce body. Returns 'accepted' |
     * 'deduped' | 'rejected'. Never verifies inline — a candidate is
     * only ever queued for this site's OWN independent verification,
     * never trusted as-is, and never itself triggers a synchronous
     * outbound fetch (that would turn an inbound POST into a way to
     * make this site perform slow outbound requests on the caller's
     * own timer).
     *
     * @param mixed $candidate
     */
    private static function ingest_candidate($candidate, string $origin, \stdClass $peer, int $hop_count): string {
        if (!is_array($candidate)) return 'rejected';

        $protocol = sanitize_text_field((string) ($candidate['protocol'] ?? ''));
        $url      = esc_url_raw((string) ($candidate['url'] ?? ''));
        $display_name = sanitize_text_field((string) ($candidate['display_name'] ?? ''));

        if (!in_array($protocol, ['activitypub', 'feed'], true) || !$url || !wp_http_validate_url($url)) {
            return 'rejected';
        }

        $hash = self::candidate_hash($protocol, $url);

        global $wpdb;
        $seen_table = $wpdb->prefix . 'bhr_gossip_seen';
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $seen_table WHERE seen_hash = %s", $hash));

        if ($existing) {
            $wpdb->update($seen_table, [
                'last_seen_at' => current_time('mysql'),
                'min_hop_seen' => min((int) $existing->min_hop_seen, $hop_count),
            ], ['id' => $existing->id]);
            return 'deduped';
        }

        $wpdb->insert($seen_table, [
            'seen_hash'       => $hash,
            'origin_base_url' => $origin,
            'candidate_url'   => $url,
            'protocol'        => $protocol,
            'min_hop_seen'    => $hop_count,
        ]);

        // Insert through the SAME shape create_submission() already
        // writes — a real pending artist + link row, nothing shortcut.
        // Gossip never carries contact_email (see this file's own
        // docblock — thin, a pointer never a trusted fact), so unlike
        // create_submission() there's no email-based dedup: every
        // accepted candidate is its own new artist row.
        $artists_t = $wpdb->prefix . 'bhr_artists';
        $links_t   = $wpdb->prefix . 'bhr_links';

        if (!$wpdb->insert($artists_t, [
            'display_name' => $display_name ?: 'Unknown artist',
            'status'       => 'pending',
        ])) {
            return 'rejected';
        }
        $artist_id = (int) $wpdb->insert_id;

        $token = class_exists('BHR_Verification') ? BHR_Verification::generate_token() : wp_generate_password(32, false, false);
        if (!$wpdb->insert($links_t, [
            'artist_id'                => $artist_id,
            'protocol'                 => $protocol,
            'url'                      => $url,
            'verification_token'       => $token,
            'verification_status'      => 'pending',
            'discovered_via'           => 'gossip',
            'discovered_from_peer_id'  => $peer->id,
            'discovered_hop_count'     => $hop_count,
        ])) {
            $wpdb->delete($artists_t, ['id' => $artist_id]);
            return 'rejected';
        }
        $link_id = (int) $wpdb->insert_id;

        if (class_exists('OUS_Jobs')) {
            OUS_Jobs::enqueue('bhr_verify_gossip_candidate', ['link_id' => $link_id]);
        } elseif (class_exists('BHR_Links')) {
            $link = BHR_Links::find($link_id);
            if ($link) BHR_Verification::verify_link($link);
        }

        return 'accepted';
    }

    /* ---------- outbound: announcing our own verified links ---------- */

    /**
     * Real-time listener target for 'bh_event_emitted' (bh-registry.php
     * wires this up), fired synchronously the instant
     * BHR_Verification::verify_link() succeeds — for ANY link,
     * regardless of whether it was submitted manually or arrived via
     * gossip. Never does the outbound HTTP itself; only enqueues one
     * staggered job per active peer, so a verification request's own
     * response time is never affected by peer fan-out.
     */
    public static function announce_verified_link(int $link_id, string $discovered_via, int $hop_count, ?int $received_from_peer_id): void {
        if (!class_exists('OUS_Jobs')) return;
        if ($hop_count >= self::max_hops()) return; // already at our ceiling — accept locally, never re-propagate further

        $i = 0;
        foreach (self::active_peers_excluding($received_from_peer_id) as $peer) {
            OUS_Jobs::enqueue('bhr_gossip_announce_to_peer', [
                'peer_id'   => $peer->id,
                'link_id'   => $link_id,
                'hop_count' => $hop_count,
            ], $i * 5); // staggered — avoids a thundering-herd burst of outbound POSTs
            $i++;
        }
    }

    /**
     * OUS_Jobs handler for 'bhr_gossip_announce_to_peer'. The one place
     * in this whole codebase performing an outbound wp_remote_post() —
     * everything else here is GET/HEAD only, so this gets its own
     * explicit, careful convention rather than improvising ad hoc.
     *
     * @param array<string, mixed> $args
     */
    public static function send_announce_to_peer(array $args): void {
        $peer_id   = (int) ($args['peer_id'] ?? 0);
        $link_id   = (int) ($args['link_id'] ?? 0);
        $hop_count = (int) ($args['hop_count'] ?? 0);
        if (!$peer_id || !$link_id) return;

        $peer = self::find_peer($peer_id);
        $link = class_exists('BHR_Links') ? BHR_Links::find($link_id) : null;
        if (!$peer || $peer->status !== 'active' || !$link) return;

        global $wpdb;
        $artist = $wpdb->get_row($wpdb->prepare("SELECT display_name FROM {$wpdb->prefix}bhr_artists WHERE id = %d", $link->artist_id));

        $body = wp_json_encode([
            'origin_base_url' => home_url(),
            'hop_count'       => $hop_count + 1,
            'max_hops'        => self::max_hops(),
            'sent_at'         => current_time('c'),
            'candidates'      => [[
                'display_name'         => $artist->display_name ?? '',
                'protocol'             => $link->protocol,
                'url'                  => $link->url,
                'verified_elsewhere_at' => $link->verified_at,
            ]],
        ]);

        $res = wp_remote_post(rtrim($peer->base_url, '/') . '/wp-json/bhr/v1/peers/announce', [
            'timeout'     => 6,
            'redirection' => 2,
            'headers'     => [
                'Content-Type'      => 'application/json',
                'X-BHR-Peer-Secret' => $peer->shared_secret,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('info', 'Gossip announce to peer failed.', [
                    'peer_id'     => $peer_id,
                    'peer_url'    => $peer->base_url,
                    'wp_error'    => is_wp_error($res) ? $res->get_error_message() : null,
                    'http_status' => is_wp_error($res) ? null : wp_remote_retrieve_response_code($res),
                ], 'BH Registry Gossip');
            }
            // Retry is OUS_Jobs' own job, not reimplemented here — its
            // documented MAX_ATTEMPTS=5 exponential backoff already
            // covers a transient failure without a second hand-rolled
            // retry loop.
            throw new \RuntimeException('Peer announce failed: ' . (is_wp_error($res) ? $res->get_error_message() : (string) wp_remote_retrieve_response_code($res)));
        }

        $wpdb->update($wpdb->prefix . 'bhr_peers', ['last_announced_at' => current_time('mysql')], ['id' => $peer_id]);
    }

    /* ---------- inbound: handshake ---------- */

    public static function handshake(\WP_REST_Request $req): \WP_REST_Response {
        return new WP_REST_Response([
            'base_url'             => home_url(),
            'registry_version'     => defined('BHR_VER') ? BHR_VER : '',
            'peer_protocol_version' => 1,
        ], 200);
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

    private static function normalize_base_url(string $url): string {
        $url = trim($url);
        return $url === '' ? '' : rtrim($url, '/');
    }

    public static function max_hops(): int {
        return max(1, (int) get_option('bhr_gossip_max_hops', self::MAX_HOPS_DEFAULT));
    }

    private static function find_peer_by_base_url(string $base_url): ?\stdClass {
        if ($base_url === '') return null;
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bhr_peers WHERE base_url = %s", $base_url));
    }

    private static function find_peer(int $id): ?\stdClass {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bhr_peers WHERE id = %d", $id));
    }

    /** @return array<int, \stdClass> */
    private static function active_peers_excluding(?int $exclude_peer_id): array {
        global $wpdb;
        if ($exclude_peer_id) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}bhr_peers WHERE status = 'active' AND id != %d", $exclude_peer_id
            ));
        }
        return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}bhr_peers WHERE status = 'active'");
    }
}
