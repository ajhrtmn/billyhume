import { defineConfig } from 'vitest/config';

/**
 * Unit tests for this repo's client-side logic.
 *
 * Deliberately excludes tests/ux — those are Playwright specs driving a real
 * browser against a live WordPress install, a different runner and a different
 * kind of test. `npm run audit:ux` owns those.
 */
export default defineConfig({
  test: {
    include: ['tests/unit/**/*.test.ts'],
    exclude: ['**/node_modules/**', 'tests/ux/**'],
    environment: 'node',
  },
});
