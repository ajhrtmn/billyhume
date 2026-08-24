# Test suite — how to run, and what these are (and aren't)

> **Corrected 2026-08-23.** This file previously opened with "I don't have a PHP runtime available… none of the suites has actually been executed BY ME." That is no longer true and had become actively harmful — it told every subsequent session that verification was impossible, which is a large part of why bugs shipped reasoned-through rather than run. A real PHP 8.5 runtime, MySQL, PHPUnit, PHPStan, and a live WordPress install are all available. **Run things.**

## The four gates, in the order to run them

**1. Test Runner (the primary one).** The Self-Hosted Self → Debug Tools → **Run all tests**. Executes every registered suite on the site's own PHP — no CLI, no Composer, no PHPUnit binary — because the live WordPress install already *is* a working PHP environment. Suites register via the `bhcore_test_suites` filter.

Last full run (2026-08-23): **635 tests across 19 suites — 634 passed, 1 failed.** The single failure is `bh-tickets` → `for_user() includes the requester's own ticket` (expected true, got false). Pre-existing and long-standing; unrelated to recent work, but real and worth fixing.

**2. Static analysis.** Must stay at zero before any commit:

```bash
vendor/bin/phpstan analyse --memory-limit=4G
```

**3. Syntax, on every touched file.** This one is not optional — an unescaped apostrophe inside a comment inside a large single-quoted CSS string once took the whole site down with a fatal parse error, invisible to brace-counting and only found by reading `debug.log`. `php -l` catches that class instantly:

```bash
php -l path/to/changed-file.php
```

Whole-ecosystem sweep (~7,806 files, currently clean):

```bash
find wp-content/plugins -name '*.php' -not -path '*/vendor/*' -print0 | xargs -0 -n1 -P8 php -l | grep -v '^No syntax errors'
```

Do not resurrect the hand-rolled Python syntax checkers referenced in old changelogs — they were a workaround for having no interpreter.

## Gate 4 — the site actually loads (30 seconds, catches what the others miss)

```bash
for u in / /wp-admin/ /wp-login.php; do curl -s -o /tmp/r.html -w "$u %{http_code}\n" "http://localhost:10008$u"; grep -qi "critical error" /tmp/r.html && echo "  ^ FATAL"; done
```

Run this after any structural change. It is not redundant with `php -l`:
on 2026-08-23 a refactor took the whole site to HTTP 500 while both
static gates reported clean — a code-generation script linted the files
it *edited* but not the files it *wrote*, so a parse error in a generated
class was never checked. This curl loop found it in seconds.

**If it 500s**, temporarily enable logging in `wp-config.php`
(`WP_DEBUG` + `WP_DEBUG_LOG` true, `WP_DEBUG_DISPLAY` **false**), re-request,
read `wp-content/debug.log` for the real file and line — then **revert
`wp-config.php`**. Never leave debug display on.

Two corollaries worth internalising: **lint every file you generate, not
just every file you edit**, and a green static gate is not evidence the
site boots.

## The standalone PHPUnit suites

Separate from the Test Runner, self-contained, no WordPress or database needed:

- `own-ur-shit/tests/TwoFactorTest.php` — the TOTP algorithm against RFC 6238 Appendix B's published vectors, the base32 codec, and ±1 time-step drift (both accept-real-drift and reject-too-much-drift).
- `bh-courses/tests/QuizScoringTest.php` — pass/fail scoring: rounding, missing answers, exact-threshold boundary.
- `bh-courses/tests/StepsSanitizationTest.php` — the sanitization boundary between a raw admin POST and what gets stored/rendered; dropped-vs-clamped edge cases.

```bash
vendor/bin/phpunit wp-content/plugins/own-ur-shit/tests
```

## Why these areas specifically

Not blanket coverage — the places where a subtle bug has real consequences *and* the logic is isolable from a full WordPress bootstrap:

- **2FA/TOTP** — a bug either locks real people out or makes codes predictable, and it can be checked against an independent published standard rather than "looks right."
- **Quiz scoring** — decides whether someone passes a possibly-paid course step. Rounding and off-by-one errors are obvious in a test and easy to miss in code.
- **Step sanitization** — the real data-integrity boundary for lesson content. Malformed input must drop or clamp predictably, never crash the renderer or silently corrupt storage.

**Deliberately not covered here:** anything touching `$wpdb` directly (`BHM_Wallet`'s atomic debit, `BHC_Progress` completion). A hand-rolled `$wpdb` stub mostly tests the stub. That is genuine integration-test territory — `wp-phpunit`/`WP_UnitTestCase` against real MySQL. Worth doing; a heavier setup than this. In the meantime, DB-backed logic is better exercised end-to-end by a Debug Tools seed/reset action (see `BHM_Debug`'s tier/entitlement/wallet buttons) than by forcing a fixture-heavy fake unit test.

## What to add, and what not to

The bar is **"does this let a person verify or diagnose something real,"** not "does every function have a test." A trivial getter or a one-line wrapper around a core call does not need a suite entry — that is coverage theater, and it buries the real signal.

When you do write tests, **cover many cases — happy, unhappy, and edge — not one minimal passing case.** Pure functions with genuine edge cases (rounding, boundaries, coercion) are the highest-value and cheapest target.

## Verification honesty

If a change was reasoned through but never actually executed against a live install, **say so explicitly** — in the changelog and to whoever is reading the output. Do not imply something is confirmed working when it has only been argued for. Equally: browser-visible changes need measured verification (computed styles, `scrollHeight` vs `clientHeight`), not a screenshot glance. See `DESIGN-CRAFT.md` and the audit-method memory.
