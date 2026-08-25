import { test, expect } from '@playwright/test';

/**
 * Visual regression for the real Storybook integration (.storybook/,
 * fixtures generated from the actual BHY_UI:: renderers -- see
 * .storybook/README.md). storybook-audit.yml already builds this and
 * uploads it as a download artifact; nothing diffs it against the
 * PREVIOUS build. This does, against a committed baseline image per
 * story.
 *
 * Baselines live under storybook-visual.spec.ts-snapshots/, checked in
 * like any other Playwright snapshot test. Update them deliberately
 * (`npx playwright test storybook-visual --update-snapshots`) after a
 * real, intentional design change -- never as a way to make a failure
 * go away without looking at what it found.
 *
 * Runs against whatever STORYBOOK_URL points at (this project's own
 * webServer entry in playwright.config.ts serves the static build on
 * :6006 by default) -- a real static build, not the dev server, so this
 * tests exactly what storybook-audit.yml's own artifact contains.
 */
const STORIES = [
  'design-system-components--badge',
  'design-system-components--alert',
  'design-system-components--card',
  'design-system-components--empty-state',
  'design-system-components--wide-table',
  'design-system-components--overview',
];

for (const id of STORIES) {
  test(`story — ${id}`, async ({ page }) => {
    await page.goto(`/iframe.html?id=${id}&viewMode=story`, { waitUntil: 'networkidle' });
    // Google Fonts (Jost, Atkinson Hyperlegible) loading is the one
    // source of real flake here -- a screenshot taken before web fonts
    // swap in renders the fallback stack, a false diff against a
    // baseline captured after fonts loaded. document.fonts.ready is the
    // real signal, not a fixed sleep.
    await page.evaluate(() => document.fonts.ready);
    await expect(page).toHaveScreenshot(`${id}.png`, { fullPage: true });
  });
}
