/**
 * Entry point for the ecosystem's Lit components.
 *
 * Bundled by `npm run build:components` into one self-contained ES module
 * (~19KB minified, Lit runtime included) that WordPress loads via its native
 * script-modules API. Bundling is deliberate here even though the rest of this
 * codebase uses plain `tsc` with no bundler: Lit's own ES modules use bare
 * specifiers, so without a bundle the browser needs an import map to resolve
 * them. One committed, self-contained file keeps the deployment property that
 * matters — the shipped artifact runs with nothing but PHP and WordPress.
 *
 * Register each component here.
 */
export * from './bhy-copy-button';
