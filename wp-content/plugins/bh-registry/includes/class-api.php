<?php
if (!defined('ABSPATH')) exit;

/**
 * bhr/v1 — the one well-specified API every consumer shares: the WP
 * streaming app adding a new artist's feed as a source, a future native
 * app's own "add an artist" flow, and a plain fan-facing search/browse
 * page. All three read the same GET endpoints below; nothing here is
 * shaped around any one of them.
 *
 * Never returns contact_email — that field exists purely for admin
 * review/abuse-handling (see class-admin.php) and is never part of the
 * public contract.
 */
class BHR_API {
    public static function register_routes() {
        register_rest_route('bhr/v1', '/artists', [
            'methods' => 'GET', 'callback' => [self::class, 'list_artists'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('bhr/v1', '/artists/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [self::class, 'get_artist'], 'permission_callback' => '__return_true',
        ]);
        // The entire integration surface bh-streaming (or anything else
        // that wants to add a feed source) needs: one resolved, already-
        // validated feed URL, ready to hand straight to an importer.
        // 404s if this artist has no verified feed link — a consumer
        // should never have to know or care about verification/protocol
        // internals beyond that.
        register_rest_route('bhr/v1', '/artists/(?P<id>\d+)/feed-url', [
            'methods' => 'GET', 'callback' => [self::class, 'get_feed_url'], 'permission_callback' => '__return_true',
        ]);

        register_rest_route('bhr/v1', '/submissions', [
            'methods' => 'POST', 'callback' => [self::class, 'create_submission'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('bhr/v1', '/submissions/(?P<link_id>\d+)/verify', [
            'methods' => 'POST', 'callback' => [self::class, 'trigger_verify'], 'permission_callback' => '__return_true',
        ]);
    }

    // Unlike bh-streaming's REST API (which stays same-origin by
    // default, since it mostly serves that site's own player page), this
    // WHOLE namespace exists specifically to be called cross-origin —
    // another WordPress site's "add an artist" flow, a native app, or a
    // browse page hosted somewhere else entirely (see README.md's "one
    // API, three consumers"). None of these routes rely on WordPress's
    // cookie/nonce auth (every permission_callback above is either
    // __return_true or a plain IP-based rate limit), so there's no
    // credentialed-request risk in widening this to every origin —
    // unlike enabling CORS on an authenticated endpoint, there's nothing
    // here a malicious third-party page could trick a logged-in
    // visitor's browser into doing on their behalf.
    public static function add_cors_headers() {
        add_filter('rest_pre_serve_request', function ($served, $result, $request) {
            if (strpos($request->get_route(), '/bhr/v1/') === 0) {
                header('Access-Control-Allow-Origin: *');
                header('Access-Control-Allow-Methods: GET, POST');
                header('Access-Control-Allow-Headers: Content-Type');
            }
            return $served;
        }, 10, 3);
    }

    /* ---------- payload shaping ---------- */

    private static function artist_payload($artist, $links) {
        return [
            'id'           => (int) $artist->id,
            'display_name' => $artist->display_name,
            'bio'          => $artist->bio,
            'avatar_url'   => $artist->avatar_url,
            'status'       => $artist->status,
            'links'        => array_map([self::class, 'link_payload'], $links),
        ];
    }

    private static function link_payload($link) {
        $meta = json_decode((string) $link->metadata, true);
        return [
            'id'                  => (int) $link->id,
            'protocol'            => $link->protocol,
            'url'                 => $link->url,
            'verification_status' => $link->verification_status,
            'verified_at'         => $link->verified_at,
            'metadata'            => is_array($meta) ? $meta : [],
        ];
    }

    /* ---------- browse / search ---------- */

    public static function list_artists($req) {
        global $wpdb;
        $artists_t = $wpdb->prefix . 'bhr_artists';
        $links_t   = $wpdb->prefix . 'bhr_links';

        $search   = sanitize_text_field((string) $req->get_param('search'));
        $protocol = sanitize_text_field((string) $req->get_param('protocol'));

        // Public browse/search only ever surfaces 'active' artists —
        // i.e. at least one verified link. Pending/rejected are never
        // visible over this endpoint at all, regardless of query.
        $sql = "SELECT * FROM $artists_t WHERE status = 'active'";
        $args = [];
        if ($search !== '') {
            $sql .= ' AND (display_name LIKE %s OR bio LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $args[] = $like; $args[] = $like;
        }
        $sql .= ' ORDER BY display_name ASC LIMIT 100';

        $artists = $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);

        $out = [];
        foreach ($artists as $artist) {
            // Built and prepare()'d in one pass — never chain two
            // prepare() calls on the same string (a %-containing escaped
            // literal from the first pass can corrupt the second).
            if ($protocol !== '') {
                $link_query = $wpdb->prepare(
                    "SELECT * FROM $links_t WHERE artist_id = %d AND verification_status = 'verified' AND protocol = %s",
                    $artist->id, $protocol
                );
            } else {
                $link_query = $wpdb->prepare(
                    "SELECT * FROM $links_t WHERE artist_id = %d AND verification_status = 'verified'",
                    $artist->id
                );
            }
            $links = $wpdb->get_results($link_query);
            if ($protocol !== '' && !$links) continue; // filtered out — no verified link of that protocol
            $out[] = self::artist_payload($artist, $links);
        }

        return new WP_REST_Response(['success' => true, 'artists' => $out], 200);
    }

    public static function get_artist($req) {
        global $wpdb;
        $artist_id = (int) $req->get_param('id');
        $artist = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bhr_artists WHERE id = %d AND status = 'active'", $artist_id));
        if (!$artist) return new WP_Error('not_found', 'Artist not found.', ['status' => 404]);

        $links = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bhr_links WHERE artist_id = %d AND verification_status = 'verified'", $artist_id
        ));
        return new WP_REST_Response(['success' => true, 'artist' => self::artist_payload($artist, $links)], 200);
    }

    public static function get_feed_url($req) {
        global $wpdb;
        $artist_id = (int) $req->get_param('id');
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bhr_links
             WHERE artist_id = %d AND protocol = 'feed' AND verification_status = 'verified'
             ORDER BY verified_at DESC LIMIT 1", $artist_id
        ));
        if (!$link) return new WP_Error('not_found', 'No verified feed link for this artist.', ['status' => 404]);
        return new WP_REST_Response(['success' => true, 'feed_url' => $link->url], 200);
    }

    /* ---------- submission ---------- */

    // Deliberately unauthenticated (anyone can submit their own public
    // link — that's the whole "voluntary, self-serve" point) but rate-
    // limited via a transient keyed on IP, since an open POST endpoint
    // with no auth is the obvious spam vector otherwise. This is a
    // basic first line of defense, not the actual trust mechanism — the
    // real trust mechanism is verification (class-verification.php);
    // this just keeps the submission queue from being flooded before a
    // human or the daily re-check ever looks at it.
    public static function create_submission($req) {
        $display_name = sanitize_text_field((string) $req->get_param('display_name'));
        $bio          = sanitize_textarea_field((string) $req->get_param('bio'));
        $email        = sanitize_email((string) $req->get_param('contact_email'));
        $protocol     = sanitize_text_field((string) $req->get_param('protocol'));
        $url          = esc_url_raw((string) $req->get_param('url'));

        if (!$display_name || !$url || !in_array($protocol, ['activitypub', 'feed'], true)) {
            return new WP_Error('invalid_request', 'display_name, protocol (activitypub|feed), and url are required.', ['status' => 400]);
        }
        if ($email && !is_email($email)) {
            return new WP_Error('invalid_email', 'contact_email is not a valid address.', ['status' => 400]);
        }

        $ip = self::client_ip();
        $rl_key = 'bhr_submit_' . md5($ip);
        if (get_transient($rl_key)) {
            return new WP_Error('rate_limited', 'Too many submissions from this address recently — try again in a few minutes.', ['status' => 429]);
        }
        set_transient($rl_key, 1, 5 * MINUTE_IN_SECONDS);

        global $wpdb;
        $artists_t = $wpdb->prefix . 'bhr_artists';
        $links_t   = $wpdb->prefix . 'bhr_links';

        // Match on contact_email if given, so the same artist adding a
        // second link doesn't create a duplicate profile — otherwise
        // every submission is its own new artist row.
        $artist_id = null;
        if ($email) {
            $artist_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $artists_t WHERE contact_email = %s", $email));
        }
        if (!$artist_id) {
            $wpdb->insert($artists_t, [
                'display_name'  => $display_name,
                'bio'           => $bio,
                'contact_email' => $email,
                'status'        => 'pending',
            ]);
            $artist_id = $wpdb->insert_id;
        }

        $token = BHR_Verification::generate_token();
        $wpdb->insert($links_t, [
            'artist_id'           => $artist_id,
            'protocol'            => $protocol,
            'url'                 => $url,
            'verification_token'  => $token,
            'verification_status' => 'pending',
        ]);
        $link_id = $wpdb->insert_id;

        $host = wp_parse_url($url, PHP_URL_HOST);

        return new WP_REST_Response([
            'success'   => true,
            'artist_id' => (int) $artist_id,
            'link_id'   => (int) $link_id,
            'verification' => [
                'method'          => 'well-known-file',
                'challenge_url'   => 'https://' . $host . '/.well-known/bh-registry-verify.txt',
                'expected_content' => $token,
                'instructions'    => 'Publish a plain-text file at the challenge_url above containing exactly the token in expected_content (one line, nothing else), then POST to /bhr/v1/submissions/' . $link_id . '/verify to confirm.',
            ],
        ], 201);
    }

    // Rate-limited the same way create_submission is — this endpoint
    // triggers real outbound HTTP requests (well-known fetch, feed
    // fetch or ActivityPub actor fetch) against whatever host the link
    // points at, so an unthrottled public POST here is both a DoS
    // vector against this site and an amplification vector against
    // arbitrary third-party hosts.
    public static function trigger_verify($req) {
        $ip = self::client_ip();
        $rl_key = 'bhr_verify_' . md5($ip);
        if (get_transient($rl_key)) {
            return new WP_Error('rate_limited', 'Too many verification checks from this address recently — try again in a minute.', ['status' => 429]);
        }
        set_transient($rl_key, 1, MINUTE_IN_SECONDS);

        global $wpdb;
        $link_id = (int) $req->get_param('link_id');
        $link = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bhr_links WHERE id = %d", $link_id));
        if (!$link) return new WP_Error('not_found', 'Submission not found.', ['status' => 404]);

        $verified = BHR_Verification::verify_link($link);
        return new WP_REST_Response([
            'success'  => true,
            'verified' => $verified,
            'message'  => $verified
                ? 'Verified — this link is now live in the public registry.'
                : 'Not verified yet. Double-check the well-known file is published and reachable, and that the URL is a real ActivityPub actor or open podcast/RSS feed with audio enclosures.',
        ], 200);
    }

    private static function client_ip() {
        return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
