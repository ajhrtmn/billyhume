<?php
if (!defined('ABSPATH')) exit;

/**
 * One tiny, honest piece: recognizing a PLACEHOLDER ISRC so the rest of
 * the plugin (the admin metabox's mock-flag persistence, class-
 * player.php's SEO output) never has to duplicate the pattern check.
 *
 * Real ISRC issuance needs Own Ur Shit itself to be a registered ISRC
 * registrant with a national ISRC agency — a real institutional
 * application, not something this class can do. Until that exists,
 * this only recognizes/generates a clearly-fake placeholder so a
 * track's rights metadata field can be exercised (UI, storage, schema
 * suppression) ahead of the real thing — AJ's own ask: build against
 * the shape now so swapping in a real issuer later is a small change,
 * not a rewrite.
 *
 * Country code "ZZ" is deliberate, not arbitrary: ISO 3166-1 formally
 * reserves ZZ (along with AA, QM-QZ, XA-XZ) as "user-assigned" — never
 * allocated to a real country — so a "ZZ..." code can never collide
 * with or be mistaken for a real-world ISRC once real issuance exists.
 */
class BHS_ISRC {
    const MOCK_PATTERN = '/^ZZOUS\d{7}$/';

    public static function is_mock($isrc) {
        return (bool) preg_match(self::MOCK_PATTERN, (string) $isrc);
    }
}
