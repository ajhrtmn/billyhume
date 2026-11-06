<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_TestRunner suite for discovery: SSRF guard, HTTP Signatures, manifest shape, ingestion, ActivityPub.
 */
class BHR_DiscoveryTestSuite {
    const TEST_TAG = '__bhr_discovery_test__';

    // WHY: must be a resolvable host, since wp_http_validate_url() rejects unresolvable names
    // before the logic under test runs. Ingestion never actually contacts it.
    const TEST_HOST = 'https://example.com/' . self::TEST_TAG;

    public static function init(): void {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $suites
     * @return array<string, mixed>
     */
    public static function register(array $suites): array {
        $suites['bh-registry-discovery'] = ['label' => 'BH Registry — Discovery (crawl/relay/search)', 'callback' => [self::class, 'run']];
        return $suites;
    }

    /** @return array<int, array<string, mixed>> */
    public static function run(): array {
        if (!class_exists('OUS_TestRunner') || !class_exists('BHR_Crawl')) {
            return [['name' => 'Discovery classes not loaded', 'pass' => false, 'message' => 'Skipped — BHR_Crawl not found.']];
        }

        $rows = [];
        $rows = array_merge($rows, self::run_ssrf_tests());
        $rows = array_merge($rows, self::run_signature_tests());
        $rows = array_merge($rows, self::run_manifest_tests());
        $rows = array_merge($rows, self::run_ingestion_tests());
        $rows = array_merge($rows, self::run_activitypub_tests());
        self::cleanup();
        return $rows;
    }

    /* ---------- 1. SSRF guard ---------- */

    /** @return array<int, array<string, mixed>> */
    private static function run_ssrf_tests(): array {
        $rows = [];

        $must_reject = [
            'http://127.0.0.1/x'                      => 'IPv4 loopback',
            'http://localhost/x'                      => 'localhost hostname',
            'http://10.0.0.5/x'                       => 'RFC1918 10.0.0.0/8',
            'http://192.168.1.1/x'                    => 'RFC1918 192.168.0.0/16',
            'http://172.16.0.1/x'                     => 'RFC1918 172.16.0.0/12',
            'http://169.254.169.254/latest/meta-data' => 'link-local / cloud metadata endpoint',
            'http://[::1]/x'                          => 'IPv6 loopback',
            'ftp://example.com/x'                     => 'non-http(s) scheme',
            'file:///etc/passwd'                      => 'file:// scheme',
            'not-a-url'                               => 'unparseable URL',
            ''                                        => 'empty string',
        ];

        foreach ($must_reject as $url => $why) {
            $rows[] = OUS_TestRunner::assert_false(
                BHR_Crawl::is_safe_external_url($url),
                'SSRF guard rejects ' . $why . ' (' . ($url ?: '<empty>') . ')'
            );
        }

        $rows[] = OUS_TestRunner::assert_true(
            BHR_Crawl::is_safe_external_url('https://example.com/'),
            'SSRF guard allows a genuinely public, resolvable host'
        );

        return $rows;
    }

    /* ---------- 2. HTTP Signatures ---------- */

    /** @return array<int, array<string, mixed>> */
    private static function run_signature_tests(): array {
        $rows = [];
        if (!class_exists('BHR_HttpSignature')) {
            return [['name' => 'BHR_HttpSignature not loaded', 'pass' => false, 'message' => 'Skipped.']];
        }
        if (!class_exists('BHR_ActivityPub') || !BHR_ActivityPub::crypto_available()) {
            return [['name' => 'HTTP Signatures', 'pass' => true, 'message' => 'Skipped — this PHP build has no openssl, so the relay layer is correctly disabled here.']];
        }

        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (!$res) {
            return [['name' => 'OpenSSL keypair generation', 'pass' => false, 'message' => 'openssl_pkey_new() failed — cannot test signatures on this host.']];
        }
        $priv = '';
        openssl_pkey_export($res, $priv);
        $pub = openssl_pkey_get_details($res)['key'] ?? '';

        $body    = '{"type":"Announce"}';
        $date    = gmdate('D, d M Y H:i:s \G\M\T');
        $digest  = BHR_HttpSignature::digest($body);
        $headers = ['Host' => 'peer.test', 'Date' => $date, 'Digest' => $digest, 'Content-Type' => 'application/activity+json'];
        $names   = ['(request-target)', 'host', 'date', 'digest'];

        $sig = BHR_HttpSignature::sign('POST', '/bh-registry/inbox', $headers, $names, $priv, 'https://a.test/actor#main-key');
        $rows[] = OUS_TestRunner::assert_true((bool) $sig, 'HTTP Signature: sign() produces a signature header');

        $rows[] = OUS_TestRunner::assert_true(
            BHR_HttpSignature::verify('POST', '/bh-registry/inbox', $headers, $sig, $pub),
            'HTTP Signature: a correctly-signed request verifies against its own public key'
        );

        $tampered = $headers;
        $tampered['Digest'] = BHR_HttpSignature::digest($body . 'evil');
        $rows[] = OUS_TestRunner::assert_false(
            BHR_HttpSignature::verify('POST', '/bh-registry/inbox', $tampered, $sig, $pub),
            'HTTP Signature: a tampered body/digest is rejected'
        );

        $rows[] = OUS_TestRunner::assert_false(
            BHR_HttpSignature::verify('POST', '/some-other-inbox', $headers, $sig, $pub),
            'HTTP Signature: replaying a signature against a different path is rejected'
        );

        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $other_pub = $other ? (openssl_pkey_get_details($other)['key'] ?? '') : '';
        $rows[] = OUS_TestRunner::assert_false(
            BHR_HttpSignature::verify('POST', '/bh-registry/inbox', $headers, $sig, $other_pub),
            'HTTP Signature: verification against the WRONG public key is rejected'
        );

        // Mathematically valid, but not bound to a moment in time.
        $weak = BHR_HttpSignature::sign('POST', '/bh-registry/inbox', $headers, ['(request-target)', 'host'], $priv, 'k');
        $rows[] = OUS_TestRunner::assert_false(
            BHR_HttpSignature::verify('POST', '/bh-registry/inbox', $headers, $weak, $pub),
            'HTTP Signature: a signature not covering (request-target)+date is refused'
        );

        $rows[] = OUS_TestRunner::assert_false(
            BHR_HttpSignature::verify('POST', '/bh-registry/inbox', $headers, '', $pub),
            'HTTP Signature: an empty Signature header is rejected'
        );

        $rows[] = OUS_TestRunner::assert_false(
            BHR_HttpSignature::verify('POST', '/bh-registry/inbox', $headers, 'garbage-not-a-signature', $pub),
            'HTTP Signature: a malformed Signature header is rejected without throwing'
        );

        $rows[] = OUS_TestRunner::assert_true(
            BHR_HttpSignature::date_is_fresh($date),
            'HTTP Signature: a current Date header is accepted as fresh'
        );
        $rows[] = OUS_TestRunner::assert_false(
            BHR_HttpSignature::date_is_fresh(gmdate('D, d M Y H:i:s \G\M\T', time() - 8000)),
            'HTTP Signature: a Date header hours in the past is rejected (replay guard)'
        );
        $rows[] = OUS_TestRunner::assert_false(
            BHR_HttpSignature::date_is_fresh(''),
            'HTTP Signature: a missing Date header is rejected'
        );

        return $rows;
    }

    /* ---------- 3. Manifest shape ---------- */

    /** @return array<int, array<string, mixed>> */
    private static function run_manifest_tests(): array {
        $rows = [];

        $req = new WP_REST_Request('GET', '/bhr/v1/peers/manifest');
        $response = BHR_Crawl::handle_manifest($req);
        $data = $response->get_data();

        $rows[] = OUS_TestRunner::assert_same(200, $response->get_status(), 'Manifest: responds 200');
        $rows[] = OUS_TestRunner::assert_true(is_array($data) && isset($data['base_url']), 'Manifest: includes this site\'s own base_url');
        $rows[] = OUS_TestRunner::assert_true(is_array($data['verified_links'] ?? null), 'Manifest: verified_links is an array');
        $rows[] = OUS_TestRunner::assert_true(is_array($data['known_peers'] ?? null), 'Manifest: known_peers is an array');
        $rows[] = OUS_TestRunner::assert_same(2, $data['peer_protocol_version'] ?? 0, 'Manifest: advertises peer_protocol_version 2');

        $encoded = wp_json_encode($data);
        $rows[] = OUS_TestRunner::assert_true(
            strpos((string) $encoded, 'contact_email') === false,
            'Manifest: never exposes contact_email (public document)'
        );

        return $rows;
    }

    /* ---------- 4. Candidate + peer ingestion ---------- */

    /** @return array<int, array<string, mixed>> */
    private static function run_ingestion_tests(): array {
        global $wpdb;
        $rows = [];

        $peers_t = BHR_Tables::peers();
        $links_t = BHR_Tables::links();
        $seen_t  = BHR_Tables::gossip_seen();

        // Schema first, so a missing column fails clearly instead of as an opaque insert failure.
        $cols = $wpdb->get_col("SHOW COLUMNS FROM $peers_t", 0);
        $rows[] = OUS_TestRunner::assert_true(
            in_array('discovered_hop', $cols, true),
            'Schema: bhr_peers.discovered_hop exists (DB_VERSION >= 1.3 migration ran)'
        );
        $rows[] = OUS_TestRunner::assert_false(
            in_array('shared_secret', $cols, true),
            'Schema: bhr_peers.shared_secret was dropped by the 1.3 migration (dead column from the reverted push design)'
        );

        $wpdb->insert($peers_t, [
            'base_url'       => self::TEST_HOST . '/peer-a',
            'label'          => self::TEST_TAG,
            'status'         => 'active',
            'discovered_hop' => 0,
        ]);
        $peer_id = (int) $wpdb->insert_id;
        $peer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $peers_t WHERE id = %d", $peer_id));

        $rows[] = OUS_TestRunner::assert_true(
            (bool) $peer,
            'Ingestion: fixture peer row created' . ($peer ? '' : ' — DB error: ' . ($wpdb->last_error ?: 'none reported') . ' | columns: ' . implode(',', $cols))
        );
        if (!$peer) return $rows;

        $ingest = new ReflectionMethod('BHR_Crawl', 'ingest_candidate');
        $ingest->setAccessible(true);

        $candidate_url = self::TEST_HOST . '/artist/feed.xml';
        $candidate = ['protocol' => 'feed', 'url' => $candidate_url, 'display_name' => 'Test Artist ' . self::TEST_TAG];

        $ingest->invoke(null, $candidate, $peer);

        $link = $wpdb->get_row($wpdb->prepare("SELECT * FROM $links_t WHERE url = %s", $candidate_url));
        $rows[] = OUS_TestRunner::assert_true((bool) $link, 'Ingestion: a crawled candidate creates a real bhr_links row');

        if ($link) {
            // Most important assertion here: discovery must never imply trust.
            $rows[] = OUS_TestRunner::assert_same(
                'pending', $link->verification_status,
                'Ingestion: a crawled candidate is PENDING, never auto-verified (discovery != trust)'
            );
            $rows[] = OUS_TestRunner::assert_same('crawl', $link->discovered_via, 'Ingestion: provenance recorded as discovered_via=crawl');
            $rows[] = OUS_TestRunner::assert_same((string) $peer_id, (string) $link->discovered_from_peer_id, 'Ingestion: records which peer the candidate came from');

            $artist = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . BHR_Tables::artists() . " WHERE id = %d", $link->artist_id));
            $rows[] = OUS_TestRunner::assert_same(
                'pending', $artist ? $artist->status : '',
                'Ingestion: the created artist is pending, so it never shows in public browse until verified'
            );
        }

        $before = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $links_t WHERE url = %s", $candidate_url));
        $ingest->invoke(null, $candidate, $peer);
        $after = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $links_t WHERE url = %s", $candidate_url));
        $rows[] = OUS_TestRunner::assert_same($before, $after, 'Ingestion: re-ingesting the same candidate is deduped (no duplicate link row)');

        // Dedup is by normalized (protocol,url) hash.
        $variant = ['protocol' => 'feed', 'url' => rtrim($candidate_url, '/') . '/', 'display_name' => 'dupe'];
        $ingest->invoke(null, $variant, $peer);
        $after_variant = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $links_t WHERE url LIKE %s", $wpdb->esc_like($candidate_url) . '%'));
        $rows[] = OUS_TestRunner::assert_same(1, $after_variant, 'Ingestion: URL normalization means a trailing-slash variant is still deduped');

        $bad_cases = [
            ['why' => 'an unknown protocol', 'candidate' => ['protocol' => 'telnet', 'url' => self::TEST_HOST . '/bad-a', 'display_name' => 'x']],
            ['why' => 'an unparseable URL',  'candidate' => ['protocol' => 'feed',   'url' => 'not-a-url',                          'display_name' => 'x']],
            ['why' => 'an empty URL',        'candidate' => ['protocol' => 'feed',   'url' => '',                                   'display_name' => 'x']],
            ['why' => 'a missing protocol',  'candidate' => ['url' => self::TEST_HOST . '/bad-b',                                   'display_name' => 'x']],
        ];
        foreach ($bad_cases as $case) {
            $count_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM $links_t");
            $ingest->invoke(null, $case['candidate'], $peer);
            $count_after = (int) $wpdb->get_var("SELECT COUNT(*) FROM $links_t");
            $rows[] = OUS_TestRunner::assert_same($count_before, $count_after, 'Ingestion: refuses ' . $case['why'] . ' (no row created)');
        }

        $count_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM $links_t");
        $ingest->invoke(null, 'a bare string, not an object', $peer);
        $count_after = (int) $wpdb->get_var("SELECT COUNT(*) FROM $links_t");
        $rows[] = OUS_TestRunner::assert_same($count_before, $count_after, 'Ingestion: a non-array candidate is ignored without throwing');

        $rows[] = OUS_TestRunner::assert_false(
            BHR_Crawl::maybe_add_discovered_peer('http://169.254.169.254/', 1),
            'Peer auto-add: refuses a link-local/metadata address (SSRF guard applied to discovered peers)'
        );
        $rows[] = OUS_TestRunner::assert_false(
            BHR_Crawl::maybe_add_discovered_peer('http://127.0.0.1:8080/', 1),
            'Peer auto-add: refuses a loopback address'
        );

        $rows[] = OUS_TestRunner::assert_false(
            BHR_Crawl::maybe_add_discovered_peer(self::TEST_HOST . '/peer-a', 1),
            'Peer auto-add: an already-known peer is not duplicated'
        );

        $rows[] = OUS_TestRunner::assert_true(
            BHR_Crawl::max_hops() >= 1,
            'Hop limit: max_hops() returns a sane positive bound'
        );

        return $rows;
    }

    /* ---------- 5. ActivityPub actor ---------- */

    /** @return array<int, array<string, mixed>> */
    private static function run_activitypub_tests(): array {
        $rows = [];
        if (!class_exists('BHR_ActivityPub')) {
            return [['name' => 'BHR_ActivityPub not loaded', 'pass' => false, 'message' => 'Skipped.']];
        }

        $keys = BHR_ActivityPub::keypair();
        $rows[] = OUS_TestRunner::assert_true(
            strpos($keys['public'], 'BEGIN PUBLIC KEY') !== false,
            'ActivityPub: a real RSA public key is generated/stored'
        );
        $rows[] = OUS_TestRunner::assert_true(
            strpos($keys['private'], 'PRIVATE KEY') !== false,
            'ActivityPub: a real RSA private key is generated/stored'
        );

        // Regenerating per request would break every federation relationship.
        $again = BHR_ActivityPub::keypair();
        $rows[] = OUS_TestRunner::assert_same(
            $keys['public'], $again['public'],
            'ActivityPub: the keypair is stable across calls (not regenerated per request)'
        );

        $rows[] = OUS_TestRunner::assert_true(
            strpos(BHR_ActivityPub::key_id(), '#main-key') !== false,
            'ActivityPub: key_id() is the actor URL plus a #main-key fragment (standard convention)'
        );

        $extract = new ReflectionMethod('BHR_ActivityPub', 'extract_candidate_urls');
        $extract->setAccessible(true);

        $announce = [
            'type'   => 'Announce',
            'actor'  => 'https://relay.test/actor',
            'object' => ['id' => 'https://found.test/bh-registry/actor', 'bhrManifest' => 'https://found.test/wp-json/bhr/v1/peers/manifest'],
        ];
        $urls = $extract->invoke(null, $announce);
        $rows[] = OUS_TestRunner::assert_true(
            in_array('https://found.test', $urls, true),
            'ActivityPub: a relay Announce carrying bhrManifest yields the correct base_url candidate'
        );

        $empty = $extract->invoke(null, ['type' => 'Announce']);
        $rows[] = OUS_TestRunner::assert_true(
            is_array($empty) && count($empty) === 0,
            'ActivityPub: an Announce with no usable object yields no candidates (no crash, no junk)'
        );

        return $rows;
    }

    /* ---------- cleanup ---------- */

    private static function cleanup(): void {
        global $wpdb;
        $like = '%' . $wpdb->esc_like(self::TEST_TAG) . '%';

        $link_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM " . BHR_Tables::links() . " WHERE url LIKE %s", $like));
        foreach ($link_ids as $lid) {
            $artist_id = $wpdb->get_var($wpdb->prepare("SELECT artist_id FROM " . BHR_Tables::links() . " WHERE id = %d", $lid));
            $wpdb->delete(BHR_Tables::links(), ['id' => $lid]);
            if ($artist_id) $wpdb->delete(BHR_Tables::artists(), ['id' => $artist_id]);
        }
        $wpdb->query($wpdb->prepare("DELETE FROM " . BHR_Tables::artists() . " WHERE display_name LIKE %s", $like));
        $wpdb->query($wpdb->prepare("DELETE FROM " . BHR_Tables::peers() . " WHERE base_url LIKE %s OR label LIKE %s", $like, $like));
        $wpdb->query($wpdb->prepare("DELETE FROM " . BHR_Tables::gossip_seen() . " WHERE candidate_url LIKE %s OR origin_base_url LIKE %s", $like, $like));
    }
}
