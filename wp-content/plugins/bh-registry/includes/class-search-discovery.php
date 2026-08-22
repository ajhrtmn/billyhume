<?php
if (!defined('ABSPATH')) exit;

/**
 * Search-index discovery layer — one of two mechanisms capable of
 * true zero-prior-knowledge discovery (the other is the ActivityPub
 * relay layer). ON by default, like the relay layer, so discovery
 * works without setup; also like the relay layer, being enabled alone
 * causes no outbound traffic at all — run() returns immediately
 * unless a search endpoint has actually been configured. This one is
 * the simpler of the two: query a configured search
 * endpoint for other public installs exposing the same well-known
 * manifest URL pattern, then feed any confirmed-real hit into the
 * exact same peer pipeline BHR_Crawl already uses for everything else
 * — no separate trust tier, no parallel storage.
 *
 * Settings (toggle + endpoint + optional key) live on BHR_Peers'
 * admin screen, following own-ur-shit's own established Tier B
 * pattern (class-media-wizard.php). Recommended default target is a
 * self-hostable SearXNG instance rather than a paid vendor API — an
 * admin can point at their own, genuinely avoiding a silent
 * third-party dependency, while a commercial API still works if
 * that's what they'd rather configure.
 */
class BHR_SearchDiscovery {
    const MAX_RESULTS_PER_QUERY = 20;
    const QUERY = '"/wp-json/bhr/v1/peers/manifest"';

    public static function init(): void {
        add_action('bhr_search_discovery_run', [self::class, 'run']);
    }

    public static function enabled(): bool {
        return (bool) get_option('bhr_search_enabled', true);
    }

    // Weekly cadence, deliberately less frequent than the daily peer
    // crawl — this makes real outbound calls to third-party
    // infrastructure the admin configured, and its whole purpose is
    // finding NEW peers, which is inherently a slow-moving thing once
    // a network has any real size.
    public static function run(): void {
        if (!self::enabled()) return;

        $endpoint = (string) get_option('bhr_search_endpoint_url', '');
        if (!$endpoint || !wp_http_validate_url($endpoint)) return;
        if (class_exists('BHR_Crawl') && !BHR_Crawl::is_safe_external_url($endpoint)) return;

        $creds = get_option('bhr_search_credentials', ['api_key' => '']);
        $api_key = (string) ($creds['api_key'] ?? '');

        $url = add_query_arg([
            'q'      => self::QUERY,
            'format' => 'json',
        ], $endpoint);

        $headers = ['Accept' => 'application/json'];
        if ($api_key) $headers['Authorization'] = 'Bearer ' . $api_key;

        $res = wp_remote_get($url, ['timeout' => 15, 'redirection' => 2, 'headers' => $headers]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('info', 'Search-index discovery query failed.', [
                    'endpoint' => $endpoint,
                    'wp_error' => is_wp_error($res) ? $res->get_error_message() : null,
                    'http_status' => is_wp_error($res) ? null : wp_remote_retrieve_response_code($res),
                ], 'BH Registry Discovery');
            }
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($body)) return;

        // SearXNG's own JSON shape: {results: [{url: "..."}]}. Generic
        // enough to also work against most other search APIs that
        // return a "results"/"url" pair — a genuinely different
        // provider's shape can be normalized here later without
        // touching anything downstream of $result_urls.
        $results = is_array($body['results'] ?? null) ? $body['results'] : [];
        $results = array_slice($results, 0, self::MAX_RESULTS_PER_QUERY);

        foreach ($results as $result) {
            $result_url = (string) ($result['url'] ?? '');
            if (!$result_url) continue;

            // A result URL points AT the manifest endpoint itself
            // (that's what was searched for) — derive the site's real
            // base_url by stripping the known suffix, rather than
            // guessing from the host alone (handles subpath installs).
            $suffix = '/wp-json/bhr/v1/peers/manifest';
            $pos = strpos($result_url, $suffix);
            if ($pos === false) continue;
            $base_url = substr($result_url, 0, $pos);

            if (class_exists('BHR_Crawl')) {
                // discovered_hop=0 — a search hit is genesis-equivalent,
                // not chained through an existing peer's own hop count.
                // maybe_add_discovered_peer() already re-validates
                // (SSRF guard, reachability, real manifest shape) before
                // ever inserting — a search result is exactly as
                // untrusted as anything else found automatically.
                BHR_Crawl::maybe_add_discovered_peer($base_url, 0);
            }
        }
    }
}
