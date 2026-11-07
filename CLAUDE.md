# The Self-Hosted Self — working rules

Billy Hume's site (billyhume.net): a WordPress install whose real code is a self-hosted plugin ecosystem under `wp-content/plugins/` plus the theme `wp-content/themes/the-self-hosted-self-theme/`. Everything else (`wp-admin/`, `wp-includes/`, WooCommerce, MailPoet) is third-party: don't edit it.

**Mission (non-negotiable, see `wp-content/plugins/VISION.md`):** an artist owns their audience and data on ordinary shared hosting. No ads, ever. No required third-party service where an owned equivalent will do (a third-party integration is an optional layer behind a built-in default). No central server. Assume plain PHP + MySQL + WP-Cron: no Redis, Docker, queues or Node at runtime.

## Layout

- **`the-self-hosted-self`** — required core: identity/auth/portal (`BHI_*`), design tokens + Design Suite (`BHY_*`), Debug Tools (`OUS_Debug`), jobs (`OUS_Jobs`), logs (`OUS_DebugLog`), tests (`OUS_TestRunner`), notifications, roles, events (`BH_Event`), mail (`BH_Mail`), commerce interface (`BH_Commerce`), block content (`BH_Content`/`BH_Studio`), table names (`OUS_Tables`). Use these; don't rebuild them.
- **Peers** (each depends only on core): bh-contest, bh-courses, bh-crm, bh-feedback, bh-live, bh-mailpoet, bh-monetization-woo, bh-registry, bh-social, bh-streaming, bh-tickets, bh-video. `self-hosted-self-admin-skin` is shelved and stays deactivated.
- `BH_Element` is only the placement storage + REST behind the bh-crm project board. Don't build new features on it.

## Hard rules

1. **Peers never require each other.** Touch another peer only inside a hook callback, guarded by `class_exists()` (or `method_exists()` when calling a method newer than the class), with a working fallback. Never at file-parse time.
2. **TypeScript is the source for `assets/ts/*.ts`; `assets/js/*.js` is generated** — edit the `.ts`, run `npm run build:<plugin>` (or `npm run build:all`), commit both. Hand-written JS lives in `assets/inline/` or in plugins without a `tsconfig.json`. No bundler and no runtime npm dependencies: the live site is a verbatim file sync.
3. **CSS and JS don't go in PHP strings.** Put them in `assets/css/*.css` / `assets/inline/*.js` (linted) and enqueue or read them.
4. **Every real change bumps the plugin's `Version:` header and its `*_VER` constant**, with a one-paragraph entry at the top of that plugin's `CHANGELOG.md`. The full story goes in the commit message, never in source comments.
5. **Comments explain why, in ≤3 lines.** Use `// WHY:` for constraints a reader might "fix" and break. No dates, versions or bug stories in code.
6. **New admin/dev screens are Debug Tools sections** (`ous_debug_tools` filter), not standalone `add_submenu_page()` pages (those have failed WordPress's page-hook lookup on this install).
7. **Security on every write path:** nonce + capability check on admin-post/AJAX; a real `permission_callback` on REST routes (cookie REST needs `X-WP-Nonce`); public auth endpoints call `BHI_Auth`'s same-origin guard; escape all output (`esc_html`/`esc_attr`/`esc_url`, or an `esc()` helper in JS before `innerHTML`); state changes that must happen once use one conditional `UPDATE ... WHERE` and check rows affected. `wp_die()` takes the status as `['response' => 403]`, not as the second argument.
8. **Physical names live in one owner** (`OUS_Tables`, `BHM_Tables`, ...). No inline `$wpdb->prefix . 'x'`.
9. **Wide admin tables** use `.bhy-table-wrap`. New style rules follow `STYLE-SYSTEM.md`'s four layers (tokens → utilities → components → plugin-local).
10. **Etch (the live theme builder) blanks `class=""` on markup injected through `the_content`** — mirror state into `data-*` and rebuild classes in JS (`ETCH-COMPATIBILITY-NOTES.md`).

## Before calling work done

- `php -l` every changed PHP file; `npx tsc -p wp-content/plugins/<plugin>/tsconfig.json` for TS changes; `npm run lint:css` for CSS.
- Load the front page, `/account/` and `/wp-login.php` with no PHP errors; run the Test Runner (Debug Tools → Run all tests, or `OUS_TestRunner::run_all()` via WP-CLI).
- If something wasn't exercised against a running site, say so plainly in the changelog and to the user.
- To debug a fatal, temporarily set `WP_DEBUG_LOG` (not `WP_DEBUG_DISPLAY`) in `wp-config.php`, read `wp-content/debug.log`, then revert.

## Shipping

The live site pulls core and peer updates from the **`dev`** branch through the in-admin GitHub Updates panel. Pushing `dev` ships; never push without being asked. Procedure, cache traps and rollback: `wp-content/plugins/RUNBOOK-live-updates.md`.

## Docs (in `wp-content/plugins/`)

`VISION.md` (why) · `OPEN.md` (backlog and known bugs) · `CONVENTIONS.md` (code style detail) · `STYLE-SYSTEM.md` · `DESIGN-CRAFT.md` · `TESTS.md` · `RUNBOOK-live-updates.md` · `ETCH-COMPATIBILITY-NOTES.md` · `PRIVATE-VIDEO-SETUP.md` · `CODEBASE-WALKTHROUGH.md` (guided tour, also shown in Debug Tools). Old plans and history are in `docs/archive/`: don't load them unless asked.
