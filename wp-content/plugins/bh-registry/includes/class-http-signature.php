<?php
if (!defined('ABSPATH')) exit;

/**
 * HTTP Signatures (draft-cavage-http-signatures-12), RSA-SHA256, authenticating the ActivityPub inbox.
 * Only the canonical-string handling is ours; openssl_sign()/openssl_verify() do the cryptography.
 */
class BHR_HttpSignature {
    /**
     * Builds the canonical signing string; order must match exactly between signer and verifier.
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
            // Header lookup is case-insensitive per RFC.
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
        if (!function_exists('openssl_sign') || !function_exists('openssl_pkey_get_private')) return '';

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
        // Values never contain an unescaped double-quote in this spec, so a regex suffices.
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
        // Fail closed when crypto is unavailable.
        if (!function_exists('openssl_verify') || !function_exists('openssl_pkey_get_public')) return false;

        $parts = self::parse_header($signature_header);
        if (empty($parts['signature']) || empty($parts['headers'])) return false;

        // WHY: only RSA-SHA256; never let the attacker-chosen algorithm field pick the path (downgrade).
        if (isset($parts['algorithm']) && stripos($parts['algorithm'], 'rsa-sha256') === false) return false;

        $header_names = preg_split('/\s+/', trim($parts['headers'])) ?: [];
        // Must cover (request-target) and date, or it could be replayed elsewhere.
        $lower_names = array_map('strtolower', $header_names);
        if (!in_array('(request-target)', $lower_names, true) || !in_array('date', $lower_names, true)) return false;

        $signing_string = self::signing_string($method, $path, $headers, $header_names);

        $key = openssl_pkey_get_public($public_key_pem);
        if (!$key) return false;

        $signature = base64_decode($parts['signature'], true);
        if ($signature === false) return false;

        return openssl_verify($signing_string, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /** Replay guard: rejects a Date header too far from now in either direction. */
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
