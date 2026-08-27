import { test, expect } from '@playwright/test';
import { audit, setTheme, formatFindings, WIDTHS, THEMES } from './audit';

/**
 * Admin screen audit — see wp-content/plugins/UX-AUDIT-PLAN.md for the full
 * inventory and ordering. Screens are added here as they are worked through.
 *
 * These report rather than hard-fail by default: the point is a measured,
 * repeatable inventory, not a red build on day one. Flip EXPECT_CLEAN to true
 * per screen once it has actually been brought to zero.
 */
const SCREENS: Array<{ name: string; path: string; expectClean?: boolean }> = [
  { name: 'Dashboard',      path: '/wp-admin/' },
  { name: 'Debug Tools',    path: '/wp-admin/admin.php?page=ous-debug' },
  { name: 'Ecosystem home', path: '/wp-admin/admin.php?page=ous' },
  { name: 'Metrics',        path: '/wp-admin/admin.php?page=ous-metrics' },
  { name: 'Style / Design',  path: '/wp-admin/admin.php?page=bh-style' },
  { name: 'Roles',          path: '/wp-admin/admin.php?page=ous-roles' },
  // 'API Docs' as a standalone admin.php?page=ous-api-docs screen was
  // removed here 2026-08-26, the first time this whole spec was ever
  // actually run with real credentials (WP_ADMIN_USER/PASS had never
  // been set in any prior session — the skip above meant this exact
  // entry silently never executed until now). It genuinely 403s —
  // "Sorry, you are not allowed to access this page" — but that's not
  // a live bug to fix: class-api-docs.php's own docblock documents
  // exactly this, at length (WordPress's page-hook resolution fails
  // for this specific standalone page, root cause never fully pinned
  // down even with registration/capability both confirmed correct),
  // and add_menu() is DELIBERATELY never hooked to init() as a result.
  // The real, working access point is the API Docs SECTION on the
  // 'Debug Tools' screen already above — already covered, not a gap.
  //
  // OPEN.md item 8's other remaining named gaps: Test Runner has no
  // standalone page of its own either — it's a Debug Tools SECTION
  // (this ecosystem's own standing convention, see CLAUDE.md's "New
  // dev/admin-only pages" rule), also already covered by 'Debug
  // Tools' above. "Quiz editor" is the Gutenberg post-edit screen for
  // a real bh_lesson post, not a separate admin page.
  { name: 'Quiz editor (lesson post-edit)', path: '/wp-admin/post.php?post=262&action=edit' },
  { name: 'bh-registry: Submissions', path: '/wp-admin/admin.php?page=bh-registry-review' },
  { name: 'bh-registry: Peers',       path: '/wp-admin/admin.php?page=bh-registry-peers' },
  { name: 'bh-streaming: Pro Wizard', path: '/wp-admin/admin.php?page=bhs-pro-wizard' },
  { name: 'bh-monetization-woo: Tier settings', path: '/wp-admin/admin.php?page=bhm-settings' },
  { name: 'bh-monetization-woo: Tier (post-edit)', path: '/wp-admin/post.php?post=338&action=edit' },
];

const hasCreds = !!(process.env.WP_ADMIN_USER && process.env.WP_ADMIN_PASS);

test.describe('admin UX audit', () => {
  test.skip(!hasCreds, 'WP_ADMIN_USER / WP_ADMIN_PASS not set');

  for (const screen of SCREENS) {
    for (const theme of THEMES) {
      test(`${screen.name} — ${theme}`, async ({ page }) => {
        await page.goto(screen.path, { waitUntil: 'domcontentloaded' });
        await setTheme(page, theme);

        // Confirm we're actually on an admin screen and not bounced to
        // login. NOT a #wpadminbar visibility check — the Gutenberg
        // block editor (post.php?action=edit) HIDES it by design in its
        // default fullscreen mode, a real, normal WordPress behavior,
        // not a bug; asserting visibility there is a false failure on
        // this test's own end, confirmed live (found auditing the new
        // "Quiz editor" post-edit screen). body.wp-admin is present
        // regardless of fullscreen mode and absent on the login screen.
        await expect(page.locator('body.wp-admin')).toHaveCount(1);

        const all: string[] = [];
        let total = 0;
        for (const width of WIDTHS) {
          await page.setViewportSize({ width, height: 900 });
          await page.waitForTimeout(120); // let responsive JS settle
          const findings = await audit(page);
          total += findings.length;
          all.push(formatFindings(`  ${width}px`, findings));
        }
        console.log(`\n${screen.name} [${theme}]\n${all.join('\n')}`);
        if (screen.expectClean) expect(total, `${screen.name} [${theme}] should be clean`).toBe(0);
      });
    }
  }
});
