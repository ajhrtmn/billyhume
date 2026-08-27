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
    { name: 'setup', testMatch: /auth\.setup\.ts/ },
    {
      name: 'admin',
      testMatch: /admin\.spec\.ts/,
      dependencies: ['setup'],
      use: { ...devices['Desktop Chrome'], storageState: 'tests/ux/.auth/admin.json' },
    },
    // Screenshot diffing against the real Storybook build -- see
    // storybook-visual.spec.ts's own docblock. A separate project, not
    // folded into 'public', because it needs its own baseURL (the
    // static build server below, not the WordPress site) and doesn't
    // touch WP at all.
    {
      name: 'storybook',
      testMatch: /storybook-visual\.spec\.ts/,
      use: { ...devices['Desktop Chrome'], baseURL: process.env.STORYBOOK_URL || 'http://localhost:6006' },
    },
  ],
  // Only the storybook project needs a server started for it — public/
  // admin assume a WordPress install is already running (WP_BASE_URL),
  // same as before this project existed. reuseExistingServer means a
  // developer who already ran `npm run storybook` locally on :6006
  // doesn't get a second one fighting it for the port.
  // Gated behind PW_STORYBOOK: Playwright's webServer is GLOBAL, not
  // per-project, so an unconditional entry here tried to build Storybook
  // before the `public`/`admin` audits too -- which broke them outright
  // (a native-binding failure in the build toolchain took the whole run
  // down with "Process from config.webServer was not able to start").
  // Found 2026-08-25 the first time the public audit was run after this
  // block was added. The storybook project is the only one that needs a
  // server started for it; public/admin assume a WordPress install is
  // already running (WP_BASE_URL), same as before Storybook existed.
  ...(process.env.PW_STORYBOOK
    ? {
        webServer: {
          command: 'npm run storybook:fixtures && npx storybook build -o storybook-static && npx http-server storybook-static -p 6006 -s',
          url: 'http://localhost:6006',
          reuseExistingServer: !process.env.CI,
          timeout: 180_000,
        },
      }
    : {}),
});
