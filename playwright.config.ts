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
    // Logged-in FRONT END (not wp-admin) as an ordinary subscriber —
    // courses in progress, portal panels, contest/streaming while
    // authenticated. Distinct from 'admin' (wp-admin screens, needs a
    // real credentialed account) — this authenticates inline against a
    // throwaway subscriber-role test fixture each run, no stored
    // session or real credentials needed, since subscriber can't reach
    // wp-admin at all regardless.
    {
      name: 'logged-in',
      testMatch: /logged-in\.spec\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
    // Post-deploy smoke canary — see tests/ux/smoke.spec.ts. Crudest
    // possible "did the site render or die" check against WP_BASE_URL,
    // no credentials, no measurement. Separate project because it's a
    // deploy gate concern, not a UX audit, and wants to run alone/fast.
    {
      name: 'smoke',
      testMatch: /smoke\.spec\.ts/,
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
