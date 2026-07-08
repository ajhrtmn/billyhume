# Test suite — how to run, and what these are (and aren't)

I don't have a PHP runtime available in the environment I'm working in (no root to install one, and outbound network is allowlisted against package/binary downloads) — so none of the PHPUnit suites below has actually been executed BY ME. What follows is written and reasoned through carefully (the TOTP tests are checked against RFC 6238's own published test vectors by hand, not just internal consistency), but you should run these yourself before trusting them as "passing."

**There's now a second, no-CLI way to actually run these on the real site**, addressing that gap directly: Own Ur Shit → Debug Tools → **Test Runner**. Every case below also exists as a plain PHP suite (`own-ur-shit/includes/class-core-test-suite.php`, `bh-courses/includes/class-test-suite.php`) registered via the `bhcore_test_suites` filter, runnable with one click on the deployed site's own PHP — no CLI, no Composer, no PHPUnit binary needed, because the live WordPress install already IS a working PHP environment. The PHPUnit files below remain the ones to extend for a real future CI pipeline; the Test Runner versions are the "right now, on this exact site" complement, not a replacement.

## What's here

Two test suites, one per plugin, each self-contained (no WordPress install, no database):

- `own-ur-shit/tests/` — `TwoFactorTest.php`. Tests the TOTP algorithm itself against RFC 6238 Appendix B's published reference vectors, plus the base32 codec and the ±1 time-step drift tolerance (both the "accepts real drift" and "rejects too much drift" directions).
- `bh-courses/tests/` — `QuizScoringTest.php` (quiz pass/fail scoring — rounding, missing answers, exact-threshold boundary, all pure logic) and `StepsSanitizationTest.php` (the sanitization boundary between a raw admin-form POST and what actually gets stored/rendered — dropped-vs-clamped edge cases).

## Why these three areas specifically

Not an attempt at blanket coverage — these are the places where a subtle bug has real consequences and the logic is *isolable* from a full WordPress+database bootstrap:

- **2FA/TOTP**: a bug here either locks real people out of their accounts or makes codes predictable. Security-critical, and checkable against an independent published standard rather than just "does it look right."
- **Quiz scoring**: decides whether a student passes a (possibly paid) course step. Rounding and off-by-one boundary bugs are exactly the kind of thing that's obvious in a test and easy to miss reading the code.
- **Step sanitization**: the actual security/data-integrity boundary for lesson content. Malformed input needs to be dropped or clamped predictably, not crash the renderer or silently corrupt stored content.

Things deliberately NOT tested here, and why: anything touching `$wpdb` directly (BHM_Wallet's atomic debit, BHC_Progress's completion tracking) needs either a real database or a real mocking layer to test meaningfully — a hand-rolled `$wpdb` stub would mostly just test the stub. That's real, proper integration-test territory (the official `wp-phpunit`/`WP_UnitTestCase` scaffolding, run against an actual MySQL instance) — a good next step, but a different, heavier kind of test setup than what's here.

## Running these yourself

You'll need PHP (7.4+) and PHPUnit. The easiest path with no Composer setup:

```bash
curl -O -L https://phar.phpunit.de/phpunit-9.6.phar   # PHPUnit 9.6 supports PHP 7.4+
chmod +x phpunit-9.6.phar

# from inside own-ur-shit/tests/
php ../../phpunit-9.6.phar -c phpunit.xml

# from inside bh-courses/tests/
php ../../phpunit-9.6.phar -c phpunit.xml
```

If you're already set up with `wp-env`, Local, or a real WordPress dev install with Composer, a `composer require --dev phpunit/phpunit` and pointing it at the same `phpunit.xml` files works too.

## Honest gaps this doesn't cover

Everything flagged in VISION.md's gap list still applies — this is a start on "no automated tests anywhere," not a finished test suite. Real candidates for the next pass, once a proper WP-integration harness exists: `BHM_Wallet`'s atomic debit/credit (the TOCTOU-avoidance logic is exactly the kind of thing worth a concurrency-flavored test), `BHC_Gate`'s drip-scheduling date math, and `OUS_Jobs`'s retry/backoff sequencing.

## Related: the Console & Logs debug section

Separate from tests, but built in the same pass and worth knowing about: Own Ur Shit → Debug Tools now also has a **Console & Logs** section (`OUS_DebugLog`) — an aggregate log capturing PHP fatals, WordPress's own `doing_it_wrong`/deprecated notices, admin-only JS errors, and anything any plugin explicitly logs via `OUS_DebugLog::log()`. This is the "no tailable error log file, no CLI" answer for day-to-day debugging, separate from the Test Runner's "did the logic I already wrote still work" job.
