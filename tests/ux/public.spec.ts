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
  // 2026-08-25: the four above were the whole audit -- generic theme
  // surfaces only, none of this ecosystem's OWN front end, which is
  // exactly the gap OPEN.md item 7 names ("Systematic front-end audit,
  // plugin by plugin. Never done."). Paths verified against real
  // published content on this install rather than assumed.
  { name: 'Courses catalog',   path: '/courses/' },
  { name: 'Course (single)',   path: '/courses/mastering-for-bedroom-producers/' },
  { name: 'Course (long title)', path: '/courses/style-system-verification-test-course-with-a-genuinely-absurdly-long-title-to-trigger-the-line-clamp/' },
  { name: 'Lesson (gated)',    path: '/lesson/lesson-2-compression-and-glue/' },
  { name: 'Shop',              path: '/shop/' },
  { name: 'Product (course)',  path: '/product/mastering-for-bedroom-producers-course-purchase/' },
  { name: 'Cart (empty)',      path: '/cart/' },
  { name: 'Blog post',         path: '/medium-title-test/' },
  { name: 'Search (results)',  path: '/?s=test' },
  // 2026-08-26: continuing OPEN.md item 7 -- contest, streaming single,
  // storefront checkout/account were still unaudited after the first
  // pass.
  { name: 'Contest (single)',  path: '/bh_contest/fall-anthem-showdown/' },
  { name: 'Streaming track',   path: '/bhs_track/midnight-static/' },
  { name: 'Streaming library', path: '/streaming/' },
  { name: 'Checkout',          path: '/checkout/' },
  { name: 'My account',        path: '/my-account/' },
  { name: 'Portal / account (logged out)', path: '/account/' },
  // A prior pass here noted CRM's public profile (class-public-profile.php)
  // was "a logged-in portal panel, not a standalone public page" -- that
  // was wrong, or at least described a different real bug: BHI_PublicProfile
  // ::profile_url() pointed straight at home_url('/') regardless of whether
  // the home page actually hosted [bh_profile] anywhere, so the link this
  // ecosystem itself generates (bh-crm's own "View public profile page"
  // link) was genuinely dead on an install with no matching home page --
  // confirmed live, landing on the ordinary blog index. Fixed via OUS_Pages
  // (the-self-hosted-self 3.15.4) the same way bh-contest/bh-courses/
  // bh-monetization-woo already resolve their own catalog pages; this now
  // audits the real, rendering page that fix creates.
  { name: 'CRM public profile', path: '/profile/?bh_user=1' },
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
