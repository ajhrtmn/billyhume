import { defineConfig, devices } from '@playwright/test';

/**
 * UX audit harness — see wp-content/plugins/UX-AUDIT-PLAN.md.
 *
 * Two projects, because logged-out and logged-in are genuinely different
 * audits, not one audit with a flag:
 *
 *   public — no session. The front end as a fan actually sees it. Runs
 *            anywhere, needs no credentials.
 *   admin  — reuses a stored session written once by auth.setup.ts, so
 *            ~70 screens x 6 widths x 2 themes don't each re-authenticate.
 *            Skips cleanly when credentials aren't provided.
 */
export default defineConfig({
  testDir: './tests/ux',
  fullyParallel: false,          // one WP install, shared DB — don't race it
  workers: 1,
  reporter: [['list'], ['html', { outputFolder: 'tests/ux/report', open: 'never' }]],
  timeout: 90_000,
  use: {
    baseURL: process.env.WP_BASE_URL || 'http://localhost:10008',
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'public',
      testMatch: /public\.spec\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
    { name: 'setup', testMatch: /auth\.setup\.ts/ },
    {
      name: 'admin',
      testMatch: /admin\.spec\.ts/,
      dependencies: ['setup'],
      use: { ...devices['Desktop Chrome'], storageState: 'tests/ux/.auth/admin.json' },
    },
  ],
});
