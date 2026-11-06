<?php
use PHPUnit\Framework\TestCase;

/**
 * TOTP correctness against RFC 6238 Appendix B vectors (not self round-trips).
 * The RFC key "12345678901234567890" is used via its independently published
 * base32 form, and the RFC's 8-digit codes reduce to 6 digits by taking the
 * last 6 (1e6 divides 1e8).
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

    // WHY: the ±1 window tolerates server/phone clock drift; exact-match only
    // would lock real users out.
    public function testVerifyCodeAcceptsOneStepBeforeAndAfter()
    {
        $timeslice = (int) floor(time() / 30);
        $before = bhi_2fa_call_private('totp_at', self::RFC_TEST_SECRET_B32, $timeslice - 1);
        $after  = bhi_2fa_call_private('totp_at', self::RFC_TEST_SECRET_B32, $timeslice + 1);

        $this->assertTrue(BHI_TwoFactor::verify_code(0, $before, self::RFC_TEST_SECRET_B32), 'One step in the past should still be accepted (clock drift tolerance).');
        $this->assertTrue(BHI_TwoFactor::verify_code(0, $after, self::RFC_TEST_SECRET_B32), 'One step in the future should still be accepted (clock drift tolerance).');
    }

    // Codes outside the window must be rejected.
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
        // Malformed input must be rejected without throwing (public login form).
        $this->assertFalse(BHI_TwoFactor::verify_code(0, '', self::RFC_TEST_SECRET_B32));
        $this->assertFalse(BHI_TwoFactor::verify_code(0, '12345', self::RFC_TEST_SECRET_B32));   // too short
        $this->assertFalse(BHI_TwoFactor::verify_code(0, '1234567', self::RFC_TEST_SECRET_B32)); // too long
        $this->assertFalse(BHI_TwoFactor::verify_code(0, 'abcdef', self::RFC_TEST_SECRET_B32));  // non-numeric
        $this->assertFalse(BHI_TwoFactor::verify_code(0, '123 456', self::RFC_TEST_SECRET_B32)); // whitespace
    }

    public function testVerifyCodeRejectsWhenNoSecretAvailable()
    {
        // get_user_meta() is stubbed to ''; an empty secret must never pass.
        $this->assertFalse(BHI_TwoFactor::verify_code(123, '123456', null));
    }

    /* ---------------- base32 codec, tested directly via reflection ---------------- */

    public function testBase32RoundTripsArbitraryBytes()
    {
        // A 160-bit secret (generate_secret()'s shape) must round-trip exactly.
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
        // Retyped secrets often include spaces/dashes; decode() strips them.
        $clean = bhi_2fa_call_private('base32_encode', 'test-secret-value!!');
        $withSeparators = substr($clean, 0, 4) . '-' . substr($clean, 4, 4) . ' ' . substr($clean, 8);
        $this->assertSame(
            bhi_2fa_call_private('base32_decode', $clean),
            bhi_2fa_call_private('base32_decode', $withSeparators),
            'Stray dashes/spaces a human retyping a secret would plausibly add must decode identically to the clean string.'
        );
    }
}
