# Billy Hume's site — working rules

This repo is **billyhume.net**: a WordPress site where Billy sells and runs **courses** and runs **music contests**. Billy is a musician and producer, not a programmer, and works on the site with Claude on his own computer.

## Working with Billy

- **Talk plainly.** No jargon without a one-line explanation. Say what you're about to change in terms of what he'll see ("the Next button under each lesson step"), not file names alone.
- **Show, don't describe.** After a change, open the page in the browser and check it yourself, then tell him where to look. Most of his requests are visual: wording, colors, spacing, button labels, what appears when.
- **Small steps.** One change, check it, then the next. If a request is big or unclear, ask one question first.
- **Never push or publish without being asked.** Pushing `dev` updates the live site (see Shipping).

## Where things live

All of Billy's own code is in `wp-content/plugins/` (7 plugins) and `wp-content/themes/the-self-hosted-self-theme/`. Everything else (`wp-admin/`, `wp-includes/`, WooCommerce, MailPoet) is third-party: don't edit it.

| Plugin folder | What it does |
|---|---|
| `the-self-hosted-self` | **Core, required.** Accounts and the `/account/` page, the Design Suite (colors, fonts, spacing), Debug Tools, logs, tests, email, background jobs, GitHub Updates. Its admin menu is **Site Tools**. |
| `bh-courses` | Courses: lessons with video, text, quizzes, downloads, notes, sales pages, progress, certificates. |
| `bh-contest` | Contests: song submissions, voting, judging, the live Reveal Party. |
| `bh-monetization-woo` | Selling through WooCommerce: course products, supporter tiers, gifts, launch coupons, tips. |
| `bh-tickets` | The Support page contact form and private course feedback. |
| `bh-crm` | "People": everyone with an account, with notes and tags. |
| `bh-mailpoet` | Copies People into a MailPoet mailing list. |

The folder and class names (`the-self-hosted-self`, `OUS_*`, `BHI_*`, `BHY_*`) are historical. Don't rename them: the live site's update system and saved settings depend on them.

## How to change common things

| Billy wants to change… | Where |
|---|---|
| **Wording**: any button label, heading or message | The plugin's **`words.php`** (`bh-courses/words.php`, `bh-contest/words.php`, `bh-tickets/words.php`, `bh-monetization-woo/words.php`, and `the-self-hosted-self/words.php` for the account page). Edit the text on the right; never the key on the left. |
| **Colors, fonts, corner rounding, spacing** | Site-wide: **Appearance → Customize → Design Suite**, saved in the database, so no code change. In code: design tokens, see `wp-content/plugins/STYLE-SYSTEM.md`. |
| **How a course page or lesson looks** | `bh-courses/assets/css/courses.css` |
| **How the contest player looks** | `bh-contest/assets/css/player.css` |
| **Page layouts** (home page, sales pages) | Built in **Etch** (the page builder) on the live site, not in code. |
| **Course content** (lessons, videos, quizzes, downloads) | wp-admin → **Courses**. Content, not code. |

New wording goes in `words.php` too, never hard-coded: PHP uses `BHC_Words::e('key')` (courses), `BHCON_Words::e()` (contest), `BHT_Words::e()` (tickets), `BHM_Words::e()` (sales), `OUS_Words::e()` (core); JavaScript uses `bhcWord('key')` / `bhWord('key')`. Each plugin's test suite fails if code asks for a key that `words.php` doesn't have.

## Rules for code changes

1. **Plugins never require each other**, except that everything needs the core. To use another plugin, do it inside a hook and check `class_exists()` first, with something sensible if it's missing.
2. **TypeScript is the source** for `assets/ts/*.ts`; the `assets/js/*.js` files are generated from it. Edit the `.ts`, run `npm run build:<plugin>` (or `npm run build:all`), and commit both. No bundler, no npm packages at runtime: the live site is a plain file copy.
3. **No CSS or JavaScript inside PHP strings.** Put it in `assets/css/*.css` or a `.ts` file.
4. **Every change bumps the plugin's version**: the `Version:` header and the `*_VER` constant in the plugin's main file, plus a short entry at the top of its `CHANGELOG.md`. The version is also what makes browsers download the new CSS/JS instead of an old cached copy.
5. **Comments explain why, in 3 lines or fewer.** Use `// WHY:` for something a reader might "fix" and break. History goes in the commit message, not the code.
6. **New admin or developer screens go in Debug Tools** (the `ous_debug_tools` filter), not new `add_submenu_page()` pages (those have failed to load on this install).
7. **Security on every form and request**: nonce + capability check on admin-post/AJAX; a real `permission_callback` on REST routes; escape all output (`esc_html`, `esc_attr`, `esc_url`); `wp_die()` takes the status as `['response' => 403]`.
8. **Database table names** come from each plugin's tables class (`OUS_Tables`, `BHM_Tables`, …), never `$wpdb->prefix . 'x'` inline.
9. **Etch blanks `class=""`** on markup shown through `the_content` on the live site. Put state in `data-*` attributes and rebuild classes in JS (`ETCH-COMPATIBILITY-NOTES.md`).
10. **Live site realities**: shared hosting (Bluehost) with plain PHP + MySQL + WP-Cron. No Redis, Docker or Node on the server. The live theme uses Automatic.css, which gives every top-level `<section>` big padding, so plugins use `<div>` for boxes.

## Checking your work

- Browse the local copy at **http://billyhume.local** (run by the **Local** app). Look at the page you changed, at phone width too.
- `php -l <file>` on every PHP file you changed; `npm run build:<plugin>` for TypeScript; `npm run lint:css` for CSS.
- **Debug Tools → Run all tests** (wp-admin → Site Tools → Debug Tools). All tests should pass.
- Load the home page, `/account/` and `/wp-login.php`: no errors.
- If something wasn't actually tried in a browser, say so plainly.

**Debugging tools**: Debug Tools has the error log (Console & Logs), the job queue, the test runner and GitHub Updates. For a white screen, temporarily turn on `WP_DEBUG_LOG` (not `WP_DEBUG_DISPLAY`) in `wp-config.php`, read `wp-content/debug.log`, then turn it back off. Query Monitor (a free plugin) shows slow queries and PHP errors per page.

## Shipping to the live site

The live site updates its plugins from the **`dev`** branch on GitHub, through **Site Tools → Debug Tools → GitHub Updates → Update now**. So:

1. Commit on `dev` (version bump and CHANGELOG in the same commit).
2. Push `dev` **only when Billy says to**.
3. On the live site: GitHub Updates → **Check now** → **Update now**, then purge the page cache (admin bar → Caching → Purge All).

Full procedure, cache traps and rollback: `wp-content/plugins/RUNBOOK-live-updates.md`.

## Docs (in `wp-content/plugins/`)

`CODEBASE-WALKTHROUGH.md` (a plain tour of the site's code, also shown in Debug Tools) · `STYLE-SYSTEM.md` (where styles go) · `DESIGN-DIRECTION.md` (the look Billy is going for) · `OPEN.md` (known bugs and unfinished work) · `TESTS.md` · `RUNBOOK-live-updates.md` · `ETCH-COMPATIBILITY-NOTES.md` · `PRIVATE-VIDEO-SETUP.md`.
