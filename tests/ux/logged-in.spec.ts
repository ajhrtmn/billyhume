import { test } from '@playwright/test';
import { audit, formatFindings, WIDTHS } from './audit';

/**
 * Logged-in front end — OPEN.md item 7's remaining gap: the earlier
 * logged-out-only pass (public.spec.ts) explicitly couldn't cover
 * "courses in progress, CRM admin views, portal panels" per its own
 * closing note. Authenticates via the real wp-login.php form against a
 * throwaway 'subscriber'-role test user (bhcore_is_test tagged, same
 * convention BHM_TestSuite/BHC_TestSuite already use for their own
 * fixtures) — never the site owner's real account, and subscriber can't
 * reach wp-admin at all (WordPress core's own capability model), so
 * this can never accidentally exercise admin screens.
 *
 * Real state was seeded on this fixture before this file was written
 * (not faked at test time): enrolled in a real course with one lesson
 * step marked complete, so "My Account"/course pages reflect a genuine
 * in-progress state rather than a first-time-visitor's empty one.
 *
 * This user is meant to PERSIST across runs/sessions, same as this
 * ecosystem's other tagged test fixtures (bhcore_is_test) — do not
 * delete it as end-of-task cleanup the way a throwaway admin account
 * (item 8's wp-admin audit) should be. If it's ever missing, recreate
 * with the exact login/password above, subscriber role, then re-run
 * the enroll_if_needed()/mark_step_complete() seed shown in this
 * file's own commit history rather than guessing at new values.
 */
const TEST_USER = 'uxaudit_subscriber';
const TEST_PASS = 'UxAudit-Test-Pass-2026!';

const PAGES: Array<{ name: string; path: string }> = [
  { name: 'My account (logged in)', path: '/my-account/' },
  { name: 'Portal / account (logged in)', path: '/account/' },
  { name: 'Course (enrolled, in progress)', path: '/courses/mastering-for-bedroom-producers/' },
  { name: 'Contest (logged in)', path: '/bh_contest/fall-anthem-showdown/' },
  { name: 'Streaming track (logged in)', path: '/bhs_track/midnight-static/' },
];

test.describe('logged-in front end', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
    await page.fill('#user_login', TEST_USER);
    await page.fill('#user_pass', TEST_PASS);
    await page.click('#wp-submit');
    await page.waitForLoadState('domcontentloaded');
  });

  for (const p of PAGES) {
    test(`front end — ${p.name}`, async ({ page }) => {
      await page.goto(p.path, { waitUntil: 'domcontentloaded' });
      const lines: string[] = [];
      for (const width of WIDTHS) {
        await page.setViewportSize({ width, height: 900 });
        await page.waitForTimeout(100);
        lines.push(formatFindings(`  ${width}px`, await audit(page)));
      }
      console.log(`\n${p.name}\n${lines.join('\n')}`);
    });
  }
});
