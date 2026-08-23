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
  { name: 'API Docs',       path: '/wp-admin/admin.php?page=ous-api-docs' },
];

const hasCreds = !!(process.env.WP_ADMIN_USER && process.env.WP_ADMIN_PASS);

test.describe('admin UX audit', () => {
  test.skip(!hasCreds, 'WP_ADMIN_USER / WP_ADMIN_PASS not set');

  for (const screen of SCREENS) {
    for (const theme of THEMES) {
      test(`${screen.name} — ${theme}`, async ({ page }) => {
        await page.goto(screen.path, { waitUntil: 'domcontentloaded' });
        await setTheme(page, theme);

        // Confirm we're actually on an admin screen and not bounced to login.
        await expect(page.locator('#wpadminbar')).toBeVisible();

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
