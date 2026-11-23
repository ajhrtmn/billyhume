# Changelog — Site Core (the-self-hosted-self)

Newest first. Recent releases have their own line; older ones are summarized. The full detail of each change is in git.

3.22.3 - The Design Suite is a second BH_Props provider (`style`): global colors, fonts, scales and every plugin's component tokens (56 today) can be listed and changed with `wp bh`, REST or Debug Tools, e.g. `wp bh set style:radius 16`. Values stay in the same bhy_style_settings option the Design Suite page and Customizer use, and are checked by type (colors, number limits, font list, on/off). Providers can now keep their own storage (current/write/validate/meta). The Debug Tools inspector is editable: click a property, change it, Save or Back to default; the same checks apply. 8 new tests.

3.22.2 - New BH_Props: a property registry where code files (each words.php) are the defaults and changes are stored as overrides, made only through commands: `wp bh providers|list|get|set|reset|export|import|bake`, the same over REST (bh/v1/props) for a future admin screen, and a read-only inspector in Debug Tools → Properties. Placeholders are checked, HTML is limited to safe inline tags, and every change is audit-logged. Tested end to end with WP-CLI on a lesson page.

3.22.1 - GitHub Updates can read a private repository: paste a read-only fine-grained token in Debug Tools → GitHub Updates (or define BH_GITHUB_TOKEN); without one it works as before. Every admin-screen label and message moved into `words.php` (512 entries, grouped by screen; output unchanged, checked by diffing 32 pages before and after). Browser pages receive only the non-admin words (`for_browser()`). Fixed every PHPStan finding (types and three always-true checks), condensed long comments, expanded one-line CSS blocks so stylelint is clean, and condensed the changelog.

3.22.0 - Rescoped to Billy's site. The admin menu is now **Site Tools**, listing the site's own plugins with status, version and update state. Removed the multi-plugin installer, "Install & Activate Everything", the setup wizard, the cross-plugin banner, the bundled plugin zips and their freshness panel, DMCA notices, the Storybook panel, the feedback reviewer role and the live-streaming steps of Media & CDN Setup. GitHub Updates registers plugins marked `github` (older peers marked `bundled_zip` keep updating). Account-page wording moved into `words.php` (`OUS_Words`).

3.21.55 - User bar labels shrink with an ellipsis instead of wrapping on phones.
3.21.51–3.21.53 - Account page: choose which tab opens first; the wallet chip only shows with money in it; members aren't bounced from front-end form posts.
3.21.47–3.21.50 - Security fix in the codebase-docs snippet endpoint; removed Timber/Twig (390 vendored files); dead-code pass; admin screens share one page layout.
3.21.43–3.21.46 - Large CSS blocks moved out of PHP into stylesheets; sign-up/login hardening; retired unused parts of the element system.
3.21.30–3.21.42 - Design Suite in the Customizer: grouped component tokens, a font extension point, a context-aware color system with contrast audit, click-to-select and right-click picking on the live page, and a visual Custom CSS rule editor.
3.21.0–3.21.29 - Standalone page chrome for course and contest pages; member account hardening; public profile pages hidden; Bunny player sizing.

## Earlier history, in brief

- 3.20: GitHub Updates (install plugin updates from the `dev` branch in wp-admin); account page polish and mobile pass; "logs in but stays on the login page" fix.
- 3.13–3.19: systematic front-end audits; ecosystem pages use the host theme's typeface; block-editor canvas sizing fix.
- 3.10: contrast fixes found by measuring; Streamline Moderne / Googie design direction and font choices; in-context help tooltips; first PHPStan run and dead-code sweep; TypeScript conversion; Cloudflare Stream as an optional video layer.
- 3.4–3.9: role assignment (Instructor, Designer, CRM Manager); Metrics dashboard; share cards; real Action Scheduler for background jobs; the Design Suite library and inspector; Debug Tools logging depth; `/account/` rewrite healing.
- Before 3.4: shared accounts and the account page, design tokens, notifications, jobs, the debug log and Test Runner, and the plugin dashboard.
