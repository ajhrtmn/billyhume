import type { StorybookConfig } from '@storybook/html-vite';
import path from 'node:path';

const config: StorybookConfig = {
  stories: ['./stories/**/*.stories.ts'],
  addons: ['@storybook/addon-a11y'],
  framework: { name: '@storybook/html-vite', options: {} },
  // Serve the plugin tree so stories can load the REAL stylesheets rather
  // than a copy that would drift.
  staticDirs: [{ from: path.resolve('wp-content/plugins'), to: '/plugins' }],
  core: { disableTelemetry: true },
};
export default config;
