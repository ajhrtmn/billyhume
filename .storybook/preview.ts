import type { Preview } from '@storybook/html-vite';

/**
 * Loads the ACTUAL admin skin stylesheet, not a copy — components in
 * Storybook are styled by exactly the CSS that styles wp-admin.
 * `staticDirs` in main.ts maps wp-content/plugins to /plugins.
 */
const CSS = [
  '/plugins/self-hosted-self-admin-skin/assets/css/admin-skin.css',
  '/plugins/own-ur-shit/assets/css/admin.css',
];
for (const href of CSS) {
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = href;
  document.head.appendChild(link);
}

// BHY_UI::design_system_css() is PHP-generated and printed inline on
// admin_head, so it is not reachable by a <link>. `.bhy-alert` lives ONLY
// there. tools/gen-storybook-fixtures.php emits it from the same source.
import designSystemCss from './design-system.css?raw';
const styleEl = document.createElement('style');
styleEl.textContent = designSystemCss;
document.head.appendChild(styleEl);
// wp-admin markup expects these classes on <body> for the skin to apply.
document.body.classList.add('wp-admin', 'wp-core-ui');

const preview: Preview = {
  parameters: {
    layout: 'padded',
    // The same six widths the Playwright audit uses, so a finding in one
    // is reproducible in the other.
    viewport: {
      options: {
        w1440: { name: '1440 — desktop',    styles: { width: '1440px', height: '900px' } },
        w1280: { name: '1280 — laptop',     styles: { width: '1280px', height: '900px' } },
        w1024: { name: '1024 — small laptop', styles: { width: '1024px', height: '900px' } },
        w961:  { name: '961 — above fold breakpoint', styles: { width: '961px', height: '900px' } },
        w782:  { name: '782 — WP touch breakpoint',   styles: { width: '782px', height: '900px' } },
        w375:  { name: '375 — phone',       styles: { width: '375px', height: '812px' } },
      },
    },
    a11y: { test: 'todo' },
  },
  globalTypes: {
    theme: {
      description: 'Admin skin theme',
      defaultValue: 'dark',
      toolbar: {
        title: 'Theme',
        icon: 'circlehollow',
        items: [
          { value: 'dark',  title: 'Dark' },
          { value: 'light', title: 'Light' },
        ],
        dynamicTitle: true,
      },
    },
  },
  decorators: [
    (story, context) => {
      // The skin keys every token off this attribute on <html>.
      document.documentElement.setAttribute('data-shsas-theme', context.globals.theme);
      return story();
    },
  ],
};
export default preview;
