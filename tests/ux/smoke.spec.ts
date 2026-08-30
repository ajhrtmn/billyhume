import { test, expect } from '@playwright/test';

/**
 * Post-deploy smoke canary — the fast "is the site catastrophically
 * broken" check the tech-debt audit (2026-08-30) named as the single
 * highest-value CI gap: `master` auto-deploys to live, and every
 * regression that reached a human this session (a PHP fatal from an
 * unescaped quote in a CSS-in-PHP string, a white-screen step
 * container, a stale-cached bundle) would have shown up here first.
 *
 * Deliberately NOT the same thing as tests/ux/public.spec.ts — that
 * one MEASURES design quality (contrast, clipping, overflow) on the
 * dev install. This one only asks the crudest possible question against
 * the REAL LIVE SITE: did WordPress render a page at all, or did it
 * die? Read-only by construction — page.goto + text assertions, never
 * a form submit, never a login. Same posture as the ux-audit job
 * (storybook-audit.yml): the instance already exists in production.
 *
 * It is a CANARY, not a pre-deploy gate — by the time this runs on a
 * `master` push the FTP sync has usually already happened. A true gate
 * needs @wordpress/env with the plugins activated and pages seeded
 * (the db-integration-tests level of effort); that's the follow-up.
 *
 * Override the target with WP_BASE_URL / SMOKE_BASE_URL.
 */

// Fatal / broken-render signatures. `page.goto` already throws on a
// hard network failure; these catch the "HTTP 200 with a corpse in the
// body" cases WordPress specialises in.
const FATAL_SIGNATURES = [
  'There has been a critical error',   // WP_DEBUG off — the generic white screen
  'There has been a critical error on this website',
  'Fatal error:',
  'Parse error:',
  'syntax error, unexpected',
  'Warning: require',
  'Warning: include',
  "Uncaught Error:",
  'Error establishing a database connection',
];

/**
 * Stable, ecosystem-owned paths. Each is created by an activator /
 * OUS_Pages::ensure(), not hand-authored, so the slugs don't move:
 *   /                 — the theme renders at all
 *   /account/         — BHI_Portal (rewrite-owned); login form when logged out
 *   /courses/         — bhc_catalog_page_id
 *   /contests/        — bh_contest_library_page_id
 *   /wp-login.php     — core baseline; if THIS 500s, PHP itself is down
 *
 * A 404 on one of the ecosystem pages is a soft warning (that install
 * may simply not run that plugin). A 5xx or a fatal signature on ANY
 * reachable path is a hard failure.
 */
const PATHS: Array<{ name: string; path: string; required?: boolean }> = [
  { name: 'Home', path: '/', required: true },
  { name: 'wp-login.php', path: '/wp-login.php', required: true },
  { name: 'Account portal (logged out)', path: '/account/' },
  { name: 'Courses catalog', path: '/courses/' },
  { name: 'Contest library', path: '/contests/' },
];

const EXTRA = (process.env.SMOKE_EXTRA_PATHS || '')
  .split(',')
  .map((s) => s.trim())
  .filter(Boolean)
  .map((path) => ({ name: path, path }));

for (const p of [...PATHS, ...EXTRA]) {
  test(`smoke — ${p.name}`, async ({ page }) => {
    const resp = await page.goto(p.path, { waitUntil: 'domcontentloaded', timeout: 45_000 });

    // A soft-skip only for the optional ecosystem pages: a genuine 404
    // means "this install doesn't publish that page", not "the site is
    // broken". Anything 5xx is always a failure.
    const status = resp?.status() ?? 0;
    if (!p.required && status === 404) {
      test.info().annotations.push({ type: 'warning', description: `${p.path} → 404 (page not published on this install)` });
      return;
    }
    expect(status, `${p.path} returned HTTP ${status}`).toBeLessThan(500);
    expect(status, `${p.path} returned HTTP ${status}`).toBeGreaterThan(0);

    const body = (await page.content()) || '';
    for (const sig of FATAL_SIGNATURES) {
      expect(body, `${p.path} body contains fatal signature: "${sig}"`).not.toContain(sig);
    }

    // A live page has a real <body> with actual content. A truncated
    // response (string terminated mid-render by a parse error further
    // down the file) shows up as a suspiciously short document.
    const textLen = (await page.locator('body').innerText().catch(() => '')).trim().length;
    expect(textLen, `${p.path} rendered a near-empty <body> (${textLen} chars)`).toBeGreaterThan(40);
  });
}

// One assertion that the ecosystem is actually wired, not just that
// WordPress is up: the portal page must render EITHER its login form
// (logged out) or the portal shell — never the raw "[bhi_portal]"
// shortcode text, which is what a deactivated core plugin leaves behind.
test('smoke — portal shortcode actually resolved', async ({ page }) => {
  const resp = await page.goto('/account/', { waitUntil: 'domcontentloaded', timeout: 45_000 });
  if ((resp?.status() ?? 0) === 404) {
    test.info().annotations.push({ type: 'warning', description: '/account/ → 404, portal page not published' });
    return;
  }
  const body = (await page.content()) || '';
  expect(body, 'raw [bhi_portal] shortcode text is on the page — core plugin inactive?').not.toContain('[bhi_portal]');
  const hasPortal = await page
    .locator('form[action*="bhi"], .bhi-portal, .bhi-login, input[name="log"], input[type="password"]')
    .first()
    .count();
  expect(hasPortal, 'no login form or portal shell found at /account/').toBeGreaterThan(0);
});
