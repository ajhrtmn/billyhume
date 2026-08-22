<?php
if (!defined('ABSPATH')) exit;

/**
 * HTTP Signatures (draft-cavage-http-signatures-12) — the
 * authentication mechanism the whole ActivityPub federation layer
 * depends on. Every real Fediverse server signs its outbound POSTs
 * and verifies inbound ones this way; an unsigned (or wrongly-signed)
 * activity is rejected by every serious implementation, and ours must
 * do the same or the inbox becomes a trivially-spoofable write
 * endpoint.
 *
 * Deliberately hand-rolled rather than pulling a Composer package:
 * this ecosystem ships no-build, self-hosted, with vendored
 * dependencies only where genuinely necessary (see CLAUDE.md), and
 * the signing/verifying surface here is small and well-specified —
 * RSA-SHA256 over a canonical string built from a named subset of
 * headers. PHP's own openssl_sign()/openssl_verify() do the actual
 * cryptography; nothing security-critical is reimplemented here.
 *
 * Verified working against real signature round-trips (sign → verify,
 * plus real tamper-detection assertions) in BHR_ActivityPubTestSuite.
 */
class BHR_HttpSignature {
    /**
     * Builds the canonical signing string from a header list. Order
     * matters and must match exactly between signer and verifier —
     * this is why both sides derive it from the same $headers list
     * rather than each assembling their own.
     *
     * @param array<string, string> $headers
     * @param array<int, string>    $header_names
     */
    public static function signing_string(string $method, string $path, array $headers, array $header_names): string {
        $lines = [];
        foreach ($header_names as $name) {
            $lower = strtolower($name);
            if ($lower === '(request-target)') {
                $lines[] = '(request-target): ' . strtolower($method) . ' ' . $path;
                continue;
            }
            // Header lookup is case-insensitive per RFC; normalize both
            // sides rather than trusting the caller's casing.
            $value = '';
            foreach ($headers as $k => $v) {
                if (strtolower($k) === $lower) { $value = $v; break; }
            }
            $lines[] = $lower . ': ' . $value;
        }
        return implode("\n", $lines);
    }

    /**
     * Signs a request, returning the full Signature header value.
     *
     * @param array<string, string> $headers
     * @param array<int, string>    $header_names
     */
    public static function sign(string $method, string $path, array $headers, array $header_names, string $private_key_pem, string $key_id): string {
        $signing_string = self::signing_string($method, $path, $headers, $header_names);

        $key = openssl_pkey_get_private($private_key_pem);
        if (!$key) return '';

        $signature = '';
        if (!openssl_sign($signing_string, $signature, $key, OPENSSL_ALGO_SHA256)) return '';

        return sprintf(
            'keyId="%s",algorithm="rsa-sha256",headers="%s",signature="%s"',
            $key_id,
            implode(' ', array_map('strtolower', $header_names)),
            base64_encode($signature)
        );
    }

    /**
     * Parses a Signature header into its components.
     *
     * @return array<string, string>
     */
    public static function parse_header(string $signature_header): array {
        $parts = [];
        // Matches key="value" pairs; values themselves never contain an
        // unescaped double-quote in this spec, so this is sufficient
        // without a full quoted-string parser.
        if (preg_match_all('/([a-zA-Z]+)="([^"]*)"/', $signature_header, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $parts[$m[1]] = $m[2];
            }
        }
        return $parts;
    }

    /**
     * Verifies an inbound request's signature against a public key.
     *
     * @param array<string, string> $headers
     */
    public static function verify(string $method, string $path, array $headers, string $signature_header, string $public_key_pem): bool {
        $parts = self::parse_header($signature_header);
        if (empty($parts['signature']) || empty($parts['headers'])) return false;

        // Only RSA-SHA256 is accepted. An attacker-chosen algorithm is
        // a real downgrade vector — never trust the algorithm field to
        // select the verification path.
        if (isset($parts['algorithm']) && stripos($parts['algorithm'], 'rsa-sha256') === false) return false;

        $header_names = preg_split('/\s+/', trim($parts['headers'])) ?: [];
        // A signature that doesn't cover (request-target) and date is
        // not meaningfully binding — it could be replayed against a
        // different route entirely.
        $lower_names = array_map('strtolower', $header_names);
        if (!in_array('(request-target)', $lower_names, true) || !in_array('date', $lower_names, true)) return false;

        $signing_string = self::signing_string($method, $path, $headers, $header_names);

        $key = openssl_pkey_get_public($public_key_pem);
        if (!$key) return false;

        $signature = base64_decode($parts['signature'], true);
        if ($signature === false) return false;

        return openssl_verify($signing_string, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Replay guard — rejects a request whose Date header is too far
     * from now in either direction. Standard Fediverse practice; a
     * signature with no freshness bound is replayable forever.
     */
    public static function date_is_fresh(string $date_header, int $tolerance_seconds = 300): bool {
        if (!$date_header) return false;
        $ts = strtotime($date_header);
        if (!$ts) return false;
        return abs(time() - $ts) <= $tolerance_seconds;
    }

    /** Body digest, as required when signing/verifying a POST body. */
    public static function digest(string $body): string {
        return 'SHA-256=' . base64_encode(hash('sha256', $body, true));
    }
}
