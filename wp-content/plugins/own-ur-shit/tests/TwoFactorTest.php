<?php
use PHPUnit\Framework\TestCase;

/**
 * Real correctness tests for the TOTP implementation — this is the one
 * piece of code in the whole ecosystem where "looks right" isn't good
 * enough, since a subtle bug either locks real people out of their
 * accounts or, worse, makes the codes guessable/predictable. Verified
 * against RFC 6238's own published Appendix B test vectors, not just
 * "does encode(decode(x)) == x" round-tripping against itself (which
 * would happily pass even if the WHOLE algorithm were wrong, as long as
 * it were wrong consistently in both directions).
 *
 * RFC 6238 Appendix B uses the literal 20-byte ASCII string
 * "12345678901234567890" as the raw HMAC-SHA1 key, and publishes
 * 8-DIGIT codes. Our implementation always base32-decodes whatever
 * secret it's given first (since a real secret is generated as base32
 * — see generate_secret()) and truncates to 6 digits, not 8. Two
 * reconciliations, both worth stating explicitly rather than leaving
 * implicit:
 *
 *   1. The base32 encoding of that RFC test key is the well-known,
 *      independently-published constant "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ"
 *      (this exact secret/base32 pairing shows up across many
 *      independent TOTP library test suites — it is NOT something
 *      derived by running our own base32_encode() on the key, which
 *      would make this test circular).
 *   2. Truncating to 6 digits instead of 8 is just "the last 6 digits
 *      of the 8-digit code" mathematically (x mod 1e8, then mod 1e6, is
 *      the same as x mod 1e6 directly, since 1e6 evenly divides 1e8) —
 *      so RFC's 8-digit vectors are re-derived to 6-digit expected
 *      values by simply taking their last 6 digits.
 */
final class TwoFactorTest extends TestCase
{
    // RFC 6238 Appendix B's test key, base32-encoded — an independently
    // published constant, not derived from the code under test.
    const RFC_TEST_SECRET_B32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * @dataProvider rfcVectors
     */
    public function testTotpMatchesRfc6238Vectors($unixTime, $expectedLast6Digits)
    {
        $timeslice = (int) floor($unixTime / 30);
        $code = bhi_2fa_call_private('totp_at', self::RFC_TEST_SECRET_B32, $timeslice);
        $this->assertSame($expectedLast6Digits, $code, "TOTP at unix time $unixTime should match RFC 6238's published vector.");
    }

    public function rfcVectors(): array
    {
        // [unix_time, RFC's published 8-digit OTP, truncated here to its last 6 digits]
        return [
            'RFC vector, T=1 (time 59)'                => [59, '287082'],           // RFC: 94287082
            'RFC vector, T=0x23523EC (time 1111111109)' => [1111111109, '081804'],  // RFC: 07081804
            'RFC vector, T=0x23523ED (time 1111111111)' => [1111111111, '050471'],  // RFC: 14050471
            'RFC vector, T=0x273EF07 (time 1234567890)' => [1234567890, '005924'],  // RFC: 89005924
            'RFC vector, T=0x3F940AA (time 2000000000)' => [2000000000, '279037'],  // RFC: 69279037
        ];
    }

    public function testVerifyCodeAcceptsCurrentWindow()
    {
        $timeslice = (int) floor(time() / 30);
        $code = bhi_2fa_call_private('totp_at', self::RFC_TEST_SECRET_B32, $timeslice);
        $this->assertTrue(BHI_TwoFactor::verify_code(0, $code, self::RFC_TEST_SECRET_B32));
    }

    // The whole POINT of the ±1 window is tolerating clock drift between
    // server and phone — if this regresses to an exact-match-only check,
    // real users start getting locked out by ordinary clock skew, not a
    // security improvement, just a support-ticket generator.
    public function testVerifyCodeAcceptsOneStepBeforeAndAfter()
    {
        $timeslice = (int) floor(time() / 30);
        $before = bhi_2fa_call_private('totp_at', self::RFC_TEST_SECRET_B32, $timeslice - 1);
        $after  = bhi_2fa_call_private('totp_at', self::RFC_TEST_SECRET_B32, $timeslice + 1);

        $this->assertTrue(BHI_TwoFactor::verify_code(0, $before, self::RFC_TEST_SECRET_B32), 'One step in the past should still be accepted (clock drift tolerance).');
        $this->assertTrue(BHI_TwoFactor::verify_code(0, $after, self::RFC_TEST_SECRET_B32), 'One step in the future should still be accepted (clock drift tolerance).');
    }

    // Equally important as accepting valid drift: NOT accepting codes
    // outside that window — a ±1-step tolerance that quietly became
    // ±10 steps (5 minutes) would make a leaked/observed code usable
    // for far longer than anyone signing off on "clock drift tolerance"
    // actually intended.
    public function testVerifyCodeRejectsTwoStepsAway()
    {
        $timeslice = (int) floor(time() / 30);
        $tooOld = bhi_2fa_call_private('totp_at', self::RFC_TEST_SECRET_B32, $timeslice - 2);
        $this->assertFalse(BHI_TwoFactor::verify_code(0, $tooOld, self::RFC_TEST_SECRET_B32));
    }

    public function testVerifyCodeRejectsWrongCode()
    {
        $this->assertFalse(BHI_TwoFactor::verify_code(0, '000000', self::RFC_TEST_SECRET_B32));
    }

    public function testVerifyCodeRejectsMalformedInput()
    {
        // Real inputs a user's browser autofill or a bot could plausibly
        // send — none of these should ever reach hash_hmac() as a "valid
        // 6-digit code," and none should throw either (this runs on a
        // public, unauthenticated-until-verified login form).
        $this->assertFalse(BHI_TwoFactor::verify_code(0, '', self::RFC_TEST_SECRET_B32));
        $this->assertFalse(BHI_TwoFactor::verify_code(0, '12345', self::RFC_TEST_SECRET_B32));   // too short
        $this->assertFalse(BHI_TwoFactor::verify_code(0, '1234567', self::RFC_TEST_SECRET_B32)); // too long
        $this->assertFalse(BHI_TwoFactor::verify_code(0, 'abcdef', self::RFC_TEST_SECRET_B32));  // non-numeric
        $this->assertFalse(BHI_TwoFactor::verify_code(0, '123 456', self::RFC_TEST_SECRET_B32)); // whitespace
    }

    public function testVerifyCodeRejectsWhenNoSecretAvailable()
    {
        // get_user_meta() is stubbed in bootstrap.php to always return
        // '' — this exercises the real "account never enrolled" path,
        // not a stub artifact: an empty secret must never be treated as
        // "any code passes."
        $this->assertFalse(BHI_TwoFactor::verify_code(123, '123456', null));
    }

    /* ---------------- base32 codec, tested directly via reflection ---------------- */

    public function testBase32RoundTripsArbitraryBytes()
    {
        // A real 160-bit secret, the exact shape generate_secret()
        // produces (random_bytes(20)) — round-tripping through
        // encode/decode must reproduce the original bytes exactly, or
        // every TOTP code computed from the DECODED secret would be
        // silently wrong despite the ENCODED secret (the one actually
        // shown to the user to scan/type) looking completely normal.
        $original = random_bytes(20);
        $encoded = bhi_2fa_call_private('base32_encode', $original);
        $decoded = bhi_2fa_call_private('base32_decode', $encoded);
        $this->assertSame($original, $decoded);
    }

    public function testBase32EncodeUsesOnlyValidAlphabet()
    {
        $encoded = bhi_2fa_call_private('base32_encode', random_bytes(20));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $encoded, 'Every authenticator app expects strict RFC 4648 base32 — a lowercase letter or an 8/9/0/1 slipping in would make a QR-scanned secret silently different from the one this server has stored.');
    }

    public function testBase32DecodeStripsInvalidCharactersRatherThanCorrupting()
    {
        // A user manually retyping a secret plausibly adds a stray space
        // or dash (most authenticator apps display secrets in 4-char
        // groups) — decode() should just ignore those characters rather
        // than either erroring out or silently shifting every subsequent
        // bit, which is why base32_decode() strips non-alphabet
        // characters before decoding.
        $clean = bhi_2fa_call_private('base32_encode', 'test-secret-value!!');
        $withSeparators = substr($clean, 0, 4) . '-' . substr($clean, 4, 4) . ' ' . substr($clean, 8);
        $this->assertSame(
            bhi_2fa_call_private('base32_decode', $clean),
            bhi_2fa_call_private('base32_decode', $withSeparators),
            'Stray dashes/spaces a human retyping a secret would plausibly add must decode identically to the clean string.'
        );
    }
}
