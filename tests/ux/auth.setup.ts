import { test as setup, expect } from '@playwright/test';
import fs from 'node:fs';

const AUTH_FILE = 'tests/ux/.auth/admin.json';

/**
 * Logs in once and stores the session for every other spec.
 *
 * Credentials come from the environment only — never committed. Set
 * WP_ADMIN_USER / WP_ADMIN_PASS locally, or as repository secrets in CI.
 */
setup('authenticate', async ({ page }) => {
  const user = process.env.WP_ADMIN_USER;
  const pass = process.env.WP_ADMIN_PASS;
  if (!user || !pass) {
    setup.skip(true, 'WP_ADMIN_USER / WP_ADMIN_PASS not set — admin specs will be skipped.');
    return;
  }
  await page.goto('/wp-login.php');
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await page.click('#wp-submit');
  await expect(page.locator('#wpadminbar')).toBeVisible({ timeout: 15_000 });
  fs.mkdirSync('tests/ux/.auth', { recursive: true });
  await page.context().storageState({ path: AUTH_FILE });
});
