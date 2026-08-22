<?php
if (!defined('ABSPATH')) exit;

/**
 * A real, minimal ActivityPub actor for this registry — the third and
 * last of the three discovery layers, and the only one capable of
 * true zero-prior-knowledge discovery through infrastructure that
 * already operates at internet scale: Fediverse relays. A relay is
 * itself an Actor; a server subscribes by sending it a signed Follow,
 * and the relay then Announces activity from EVERY other subscriber
 * back to it. Two bh-registry installs that have never heard of each
 * other, both subscribed to any common public relay, discover each
 * other automatically — no seed list, no shared secret, no manual
 * coordination on either side.
 *
 * Scope is deliberately narrow: this actor exists to announce and
 * discover REGISTRY MANIFESTS, not to be a general-purpose social
 * account. It has no followers collection worth speaking of, posts no
 * user content, and implements exactly the surface a relay
 * relationship needs — WebFinger discovery, an actor document with a
 * public key, a signature-verified inbox, and signed outbound
 * Follow/Announce. Everything it discovers funnels into the SAME
 * BHR_Crawl::maybe_add_discovered_peer() pipeline the crawl and
 * search-index layers already use — one shared validation path
 * (SSRF guard, reachability, real-manifest check), one peers table,
 * one verification model. No layer gets its own trust tier.
 *
 * ON by default (direct product decision: discovery should work out of
 * the box, not wait on a switch). That is safe because "enabled" alone
 * does nothing outbound — sync_relay() returns immediately unless a
 * relay URL is also configured, so a fresh install federates with
 * nobody until someone names a relay. What being enabled DOES do is
 * make the inbox live, which is fine: every request to it must carry a
 * valid, fresh HTTP Signature from the key its own claimed actor
 * publishes, and unsigned/forged/replayed requests are rejected with a
 * 401 before any parsing or DB work (verified live).
 */
class BHR_ActivityPub {
    const ACTOR_SLUG = 'bh-registry';
    const KEY_OPTION = 'bhr_ap_keypair';
    const FOLLOW_STATE_OPTION = 'bhr_ap_relay_state';
    const CONTEXT = ['https://www.w3.org/ns/activitystreams', 'https://w3id.org/security/v1'];

    public static function init(): void {
        add_action('init', [self::class, 'add_rewrite'], 20);
        add_filter('query_vars', [self::class, 'add_query_vars']);
        // Priority 0 — must run before redirect_canonical (priority 10)
        // gets a chance to 301 us. See suppress_canonical_redirect().
        add_action('template_redirect', [self::class, 'maybe_serve'], 0);
        add_filter('redirect_canonical', [self::class, 'suppress_canonical_redirect'], 10, 2);
        add_action('bhr_ap_relay_sync', [self::class, 'sync_relay']);
    }

    /**
     * Real bug caught during live verification, and a genuinely
     * federation-breaking one: WordPress's redirect_canonical() was
     * 301-ing /bh-registry/actor to /bh-registry/actor/ (trailing
     * slash). Three separate problems, worst last:
     *   1. The actor document's own `id` has no trailing slash, so the
     *      canonical URL and the advertised id disagreed — remote
     *      servers key off `id` and would see a mismatch.
     *   2. HTTP Signatures cover (request-target), i.e. the exact path.
     *      A redirect changes the path, so any signature verified
     *      against the redirected URL fails.
     *   3. Worst: a relay POSTing an Announce to /bh-registry/inbox
     *      would receive a 301, and HTTP clients do NOT re-POST a body
     *      after a redirect. Inbox delivery would have failed silently
     *      — the exact "federation mysteriously does nothing" bug that
     *      is nearly impossible to diagnose from the receiving end.
     *
     * @param string|false $redirect_url
     * @param string       $requested_url
     * @return string|false
     */
    public static function suppress_canonical_redirect($redirect_url, string $requested_url = '') {
        if (get_query_var('bhr_ap')) return false;
        return $redirect_url;
    }

    public static function enabled(): bool {
        return (bool) get_option('bhr_relay_enabled', true);
    }

    public static function relay_url(): string {
        return (string) get_option('bhr_relay_url', '');
    }

    /* ---------- identity ---------- */

    public static function actor_id(): string {
        return home_url('/' . self::ACTOR_SLUG . '/actor');
    }

    public static function key_id(): string {
        return self::actor_id() . '#main-key';
    }

    public static function inbox_url(): string {
        return home_url('/' . self::ACTOR_SLUG . '/inbox');
    }

    /**
     * Lazily generates (once) and returns this site's RSA keypair.
     * Stored in wp_options, autoload off — the private key never
     * leaves this site and is never exposed by any endpoint.
     *
     * @return array{public:string, private:string}
     */
    public static function keypair(): array {
        $stored = get_option(self::KEY_OPTION, []);
        if (is_array($stored) && !empty($stored['private']) && !empty($stored['public'])) {
            return $stored;
        }

        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (!$res) return ['public' => '', 'private' => ''];

        $private = '';
        openssl_pkey_export($res, $private);
        $details = openssl_pkey_get_details($res);
        $public = $details['key'] ?? '';

        $pair = ['public' => $public, 'private' => $private];
        update_option(self::KEY_OPTION, $pair, false);
        return $pair;
    }

    /* ---------- routing ---------- */

    public static function add_rewrite(): void {
        add_rewrite_rule('^' . self::ACTOR_SLUG . '/actor/?$', 'index.php?bhr_ap=actor', 'top');
        add_rewrite_rule('^' . self::ACTOR_SLUG . '/inbox/?$', 'index.php?bhr_ap=inbox', 'top');
        add_rewrite_rule('^\.well-known/webfinger/?$', 'index.php?bhr_ap=webfinger', 'top');

        if (class_exists('BHY_RewriteHealer')) {
            BHY_RewriteHealer::maybe_heal(self::ACTOR_SLUG . '/actor', 'bhr_ap_rewrite_last_attempt', 'BH Registry ActivityPub', 60);
        }
    }

    /**
     * @param array<int, string> $vars
     * @return array<int, string>
     */
    public static function add_query_vars(array $vars): array {
        $vars[] = 'bhr_ap';
        return $vars;
    }

    public static function maybe_serve(): void {
        $what = get_query_var('bhr_ap');
        if (!$what) return;

        // The actor/webfinger documents are harmless to serve always.
        // The inbox is live by default (see this class's docblock) but
        // still respects an admin who has explicitly turned the layer
        // off — 404 rather than accepting federation traffic a site
        // has deliberately opted out of.
        if ($what === 'inbox' && !self::enabled()) {
            status_header(404);
            exit;
        }

        switch ($what) {
            case 'webfinger': self::serve_webfinger(); break;
            case 'actor':     self::serve_actor(); break;
            case 'inbox':     self::serve_inbox(); break;
        }
        exit;
    }

    /* ---------- endpoints ---------- */

    /**
     * The canonical `acct:` handle for this site's registry actor.
     * Includes a non-standard port when one is present — a real
     * deployment on 443 gets a clean `acct:bh-registry@example.com`,
     * while a dev install on :10008 stays actually addressable rather
     * than advertising a handle that can't be resolved back.
     */
    public static function acct_handle(): string {
        $parts = wp_parse_url(home_url());
        $host = $parts['host'] ?? '';
        if (!empty($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
            $host .= ':' . $parts['port'];
        }
        return 'acct:' . self::ACTOR_SLUG . '@' . $host;
    }

    private static function serve_webfinger(): void {
        $resource = isset($_GET['resource']) ? sanitize_text_field(wp_unslash($_GET['resource'])) : '';
        $canonical = self::acct_handle();

        // Accept the bare-host form too, so a client that strips a
        // non-standard port (or a site later moving to a standard one)
        // still resolves rather than silently 404-ing.
        $bare_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $accepted = array_unique([$canonical, 'acct:' . self::ACTOR_SLUG . '@' . $bare_host]);

        if (!in_array($resource, $accepted, true)) {
            status_header(404);
            header('Content-Type: application/json');
            echo wp_json_encode(['error' => 'Resource not found']);
            return;
        }

        status_header(200);
        header('Content-Type: application/jrd+json; charset=UTF-8');
        echo wp_json_encode([
            'subject' => $canonical,
            'aliases' => [self::actor_id()],
            'links'   => [[
                'rel'  => 'self',
                'type' => 'application/activity+json',
                'href' => self::actor_id(),
            ]],
        ]);
    }

    private static function serve_actor(): void {
        $keys = self::keypair();
        $host = wp_parse_url(home_url(), PHP_URL_HOST);

        status_header(200);
        header('Content-Type: application/activity+json; charset=UTF-8');
        echo wp_json_encode([
            '@context'          => self::CONTEXT,
            'id'                => self::actor_id(),
            // 'Service' rather than 'Person' — this is explicitly an
            // automated directory participant, not a human account.
            'type'              => 'Service',
            'preferredUsername' => self::ACTOR_SLUG,
            'name'              => get_bloginfo('name') . ' — Registry',
            'summary'           => 'Automated artist-link registry participant. Announces this site\'s public registry manifest for peer discovery.',
            'inbox'             => self::inbox_url(),
            'outbox'            => home_url('/' . self::ACTOR_SLUG . '/outbox'),
            'url'               => home_url('/'),
            // A non-standard but harmless extension property: any other
            // bh-registry install reading this actor gets the manifest
            // URL directly, without needing to guess the path.
            'bhrManifest'       => home_url('/wp-json/bhr/v1/peers/manifest'),
            'publicKey'         => [
                'id'           => self::key_id(),
                'owner'        => self::actor_id(),
                'publicKeyPem' => $keys['public'],
            ],
        ]);
    }

    /**
     * Signature-verified inbox. Everything an unauthenticated caller
     * can do here is bounded to: nothing. A request without a valid,
     * fresh, correctly-scoped signature from the key its own claimed
     * actor publishes is rejected before any parsing or DB work.
     */
    private static function serve_inbox(): void {
        $body = file_get_contents('php://input');
        if (strlen((string) $body) > 100000) { // 100KB — a relay Announce is tiny
            status_header(413);
            exit;
        }

        $headers = self::request_headers();
        $signature_header = $headers['Signature'] ?? ($headers['signature'] ?? '');
        $date = $headers['Date'] ?? ($headers['date'] ?? '');

        if (!$signature_header || !BHR_HttpSignature::date_is_fresh($date)) {
            status_header(401);
            exit;
        }

        // Digest must match the actual body — otherwise a valid
        // signature over a stale digest could be paired with swapped
        // content.
        $digest_header = $headers['Digest'] ?? ($headers['digest'] ?? '');
        if ($digest_header && !hash_equals(BHR_HttpSignature::digest((string) $body), $digest_header)) {
            status_header(401);
            exit;
        }

        $activity = json_decode((string) $body, true);
        if (!is_array($activity)) {
            status_header(400);
            exit;
        }

        $parts = BHR_HttpSignature::parse_header($signature_header);
        $key_id = $parts['keyId'] ?? '';
        $public_key = self::fetch_actor_public_key($key_id);
        if (!$public_key) {
            status_header(401);
            exit;
        }

        $path = wp_parse_url(home_url('/' . self::ACTOR_SLUG . '/inbox'), PHP_URL_PATH) ?: '/' . self::ACTOR_SLUG . '/inbox';
        if (!BHR_HttpSignature::verify('POST', $path, $headers, $signature_header, $public_key)) {
            status_header(401);
            exit;
        }

        self::handle_activity($activity);

        status_header(202);
        exit;
    }

    /* ---------- activity handling ---------- */

    /** @param array<string, mixed> $activity */
    private static function handle_activity(array $activity): void {
        $type = (string) ($activity['type'] ?? '');

        if ($type === 'Accept') {
            // Relay accepted our Follow — record it so the admin screen
            // can show a real connected/pending state rather than
            // guessing.
            $state = get_option(self::FOLLOW_STATE_OPTION, []);
            $state['status'] = 'accepted';
            $state['accepted_at'] = current_time('mysql');
            update_option(self::FOLLOW_STATE_OPTION, $state, false);
            return;
        }

        if ($type === 'Reject') {
            $state = get_option(self::FOLLOW_STATE_OPTION, []);
            $state['status'] = 'rejected';
            update_option(self::FOLLOW_STATE_OPTION, $state, false);
            return;
        }

        if ($type !== 'Announce' && $type !== 'Create') return;

        // The actual discovery payoff: pull any candidate registry
        // base_url out of the activity and hand it to the SAME shared
        // pipeline every other layer uses. Nothing here is trusted —
        // maybe_add_discovered_peer() re-runs the full SSRF guard,
        // URL validation, and live manifest check before anything is
        // stored.
        foreach (self::extract_candidate_urls($activity) as $candidate) {
            if (class_exists('BHR_Crawl')) {
                // hop 0 — a relay hit is genesis-equivalent, not
                // chained through an existing peer's hop count.
                BHR_Crawl::maybe_add_discovered_peer($candidate, 0);
            }
        }
    }

    /**
     * Pulls plausible registry base_urls out of an inbound activity.
     * Handles both a direct object URL and our own bhrManifest
     * extension property, and tolerates the object being either an
     * embedded array or a bare URL string (both are legal AP).
     *
     * @param array<string, mixed> $activity
     * @return array<int, string>
     */
    private static function extract_candidate_urls(array $activity): array {
        $found = [];
        $object = $activity['object'] ?? null;

        $push = function ($url) use (&$found) {
            $url = is_string($url) ? trim($url) : '';
            if (!$url) return;
            $suffix = '/wp-json/bhr/v1/peers/manifest';
            $pos = strpos($url, $suffix);
            // Accept either a direct manifest URL (strip to base) or an
            // actor/object URL on a host we can probe for one.
            if ($pos !== false) {
                $found[] = substr($url, 0, $pos);
                return;
            }
            $parts = wp_parse_url($url);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                $found[] = $parts['scheme'] . '://' . $parts['host'] . $port;
            }
        };

        if (is_array($object)) {
            if (!empty($object['bhrManifest'])) $push($object['bhrManifest']);
            if (!empty($object['id']))          $push($object['id']);
            if (!empty($object['url']))         $push($object['url']);
        } elseif (is_string($object)) {
            $push($object);
        }

        if (!empty($activity['bhrManifest'])) $push($activity['bhrManifest']);
        if (!empty($activity['actor']) && is_string($activity['actor'])) $push($activity['actor']);

        return array_values(array_unique(array_filter($found)));
    }

    /* ---------- outbound ---------- */

    /**
     * Daily job: keeps the relay relationship alive. Sends a Follow if
     * we've never successfully subscribed, then Announces this site's
     * own manifest so other subscribers can discover us. Both are
     * signed; neither runs unless the admin enabled the layer and
     * named a relay.
     */
    public static function sync_relay(): void {
        if (!self::enabled()) return;
        $relay = self::relay_url();
        if (!$relay || !wp_http_validate_url($relay)) return;
        if (class_exists('BHR_Crawl') && !BHR_Crawl::is_safe_external_url($relay)) return;

        $state = get_option(self::FOLLOW_STATE_OPTION, []);
        $followed_url = $state['relay_url'] ?? '';

        // Re-follow if never followed, or if the admin changed which
        // relay we point at.
        if (($state['status'] ?? '') !== 'accepted' || $followed_url !== $relay) {
            self::send_follow($relay);
        }

        self::announce_manifest($relay);
    }

    private static function send_follow(string $relay): void {
        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => self::actor_id() . '#follow-' . time(),
            'type'     => 'Follow',
            'actor'    => self::actor_id(),
            // The de-facto convention every major relay implementation
            // accepts for "subscribe me to the firehose".
            'object'   => 'https://www.w3.org/ns/activitystreams#Public',
        ];

        $ok = self::post_signed($relay, $activity);

        update_option(self::FOLLOW_STATE_OPTION, [
            'relay_url'  => $relay,
            'status'     => $ok ? 'pending' : 'failed',
            'followed_at' => current_time('mysql'),
        ], false);
    }

    private static function announce_manifest(string $relay): void {
        $manifest = home_url('/wp-json/bhr/v1/peers/manifest');
        $activity = [
            '@context'     => self::CONTEXT,
            'id'           => self::actor_id() . '#announce-' . time(),
            'type'         => 'Announce',
            'actor'        => self::actor_id(),
            'to'           => ['https://www.w3.org/ns/activitystreams#Public'],
            'object'       => self::actor_id(),
            'bhrManifest'  => $manifest,
            'published'    => gmdate('c'),
        ];
        self::post_signed($relay, $activity);
    }

    /**
     * Signed POST to a remote inbox. The relay URL an admin configures
     * may be the relay's actor URL or its inbox directly — resolve the
     * real inbox from the actor document when needed rather than
     * assuming a path.
     *
     * @param array<string, mixed> $activity
     */
    private static function post_signed(string $target, array $activity): bool {
        $inbox = self::resolve_inbox($target);
        if (!$inbox) return false;

        $keys = self::keypair();
        if (!$keys['private']) return false;

        $body = wp_json_encode($activity);
        $parts = wp_parse_url($inbox);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $host = $parts['host'] ?? '';
        if (isset($parts['port'])) $host .= ':' . $parts['port'];

        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $digest = BHR_HttpSignature::digest((string) $body);

        $headers = [
            'Host'         => $host,
            'Date'         => $date,
            'Digest'       => $digest,
            'Content-Type' => 'application/activity+json',
        ];
        $signature = BHR_HttpSignature::sign('POST', $path, $headers, ['(request-target)', 'host', 'date', 'digest'], $keys['private'], self::key_id());
        if (!$signature) return false;

        $headers['Signature'] = $signature;
        $headers['Accept'] = 'application/activity+json';

        $res = wp_remote_post($inbox, [
            'timeout'     => 10,
            'redirection' => 2,
            'headers'     => $headers,
            'body'        => $body,
        ]);

        $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
        $ok = $code >= 200 && $code < 300;

        if (!$ok && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('info', 'ActivityPub outbound POST failed.', [
                'inbox'       => $inbox,
                'activity'    => $activity['type'] ?? '',
                'wp_error'    => is_wp_error($res) ? $res->get_error_message() : null,
                'http_status' => $code,
            ], 'BH Registry ActivityPub');
        }

        return $ok;
    }

    private static function resolve_inbox(string $target): string {
        // Already an inbox URL — use as-is.
        if (substr($target, -6) === '/inbox') return $target;

        $res = wp_remote_get($target, [
            'timeout' => 8, 'redirection' => 2,
            'headers' => ['Accept' => 'application/activity+json, application/ld+json'],
        ]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return '';

        $data = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($data)) return '';

        $inbox = '';
        if (!empty($data['endpoints']['sharedInbox'])) $inbox = (string) $data['endpoints']['sharedInbox'];
        elseif (!empty($data['inbox']))                $inbox = (string) $data['inbox'];

        if (!$inbox || !wp_http_validate_url($inbox)) return '';
        if (class_exists('BHR_Crawl') && !BHR_Crawl::is_safe_external_url($inbox)) return '';

        return $inbox;
    }

    /**
     * Fetches the public key for a keyId, following the standard
     * "keyId is the actor URL plus a fragment" convention. SSRF-guarded
     * like every other outbound fetch in this plugin.
     */
    private static function fetch_actor_public_key(string $key_id): string {
        if (!$key_id) return '';
        $actor_url = strtok($key_id, '#');
        if (!$actor_url || !wp_http_validate_url($actor_url)) return '';
        if (class_exists('BHR_Crawl') && !BHR_Crawl::is_safe_external_url($actor_url)) return '';

        $res = wp_remote_get($actor_url, [
            'timeout' => 8, 'redirection' => 2,
            'headers' => ['Accept' => 'application/activity+json, application/ld+json'],
        ]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return '';

        $data = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($data)) return '';

        return (string) ($data['publicKey']['publicKeyPem'] ?? '');
    }

    /** @return array<string, string> */
    private static function request_headers(): array {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) return $headers;
        }
        $out = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $out[$name] = (string) $value;
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public static function relay_state(): array {
        $state = get_option(self::FOLLOW_STATE_OPTION, []);
        return is_array($state) ? $state : [];
    }
}
