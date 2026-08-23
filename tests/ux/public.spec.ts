import { test } from '@playwright/test';
import { audit, formatFindings, WIDTHS } from './audit';

/**
 * Logged-out front end — no credentials needed, so this runs anywhere.
 *
 * This is the half of the ecosystem that fans actually see, and the half
 * DESIGN-CRAFT.md identifies as lagging the admin. Front-end pages don't
 * carry the admin skin's theme toggle, so there's no theme dimension here.
 */
const PAGES: Array<{ name: string; path: string }> = [
  { name: 'Home',        path: '/' },
  { name: 'Login',       path: '/wp-login.php' },
  { name: 'Search (0 results)', path: '/?s=zzzzz-no-such-thing' },
  { name: '404',         path: '/this-page-does-not-exist' },
];

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
