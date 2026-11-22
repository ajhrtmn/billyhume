# Tests and checks

Run things rather than reasoning about them: the local site (in the Local app) has real PHP, MySQL and WordPress.

## Before calling work done

1. **Test Runner**: wp-admin → Site Tools → Debug Tools → **Run all tests** (or `wp eval 'echo json_encode(OUS_TestRunner::run_all());'`). Every plugin registers a suite via `bhcore_test_suites`; the courses and contest suites also fail if code uses a wording key `words.php` doesn't have. Last full run (2026-11-22): 482/482.
2. **Syntax**: `php -l` on every PHP file you changed *or generated*. An unescaped apostrophe inside a single-quoted string has taken the whole site down before.
3. **Static analysis**: `vendor/bin/phpstan analyse --memory-limit=3G` must report no errors (run `composer install` once first; it's dev-only).
4. **TypeScript and CSS**: `npm run build:all` and `npm run lint:css` (both clean).
5. **The site loads**: open `/`, `/account/` and `/wp-login.php` and check for errors. Static checks passing doesn't prove the site boots.

If a page shows a critical error: set `WP_DEBUG_LOG` true (and `WP_DEBUG_DISPLAY` false) in `wp-config.php`, reload, read `wp-content/debug.log`, then revert `wp-config.php`.

## Standalone PHPUnit tests

No WordPress or database needed (`vendor/bin/phpunit wp-content/plugins/the-self-hosted-self/tests`, and the same for `bh-courses/tests`):

- Two-factor codes against the RFC 6238 published vectors.
- Quiz scoring (rounding, missing answers, exact pass mark).
- Lesson step sanitization (bad input is dropped or clamped, never stored broken).

`bh-monetization-woo/tests-integration/` runs money paths against a real database in CI.

## What's worth a test

Logic where a subtle bug has real consequences: access, money, scoring, sanitization. Cover happy, unhappy and edge cases, not one passing case. Skip trivial getters.

Browser-visible changes need measured checks (computed styles, `scrollWidth` vs `clientWidth`, contrast ratios), not a glance at a screenshot. If something wasn't actually run, say so in the changelog and to the person reading.
