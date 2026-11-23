# billyhume.net

Billy Hume's website: a WordPress site for **courses** (video lessons, quizzes, downloads, sales pages) and **music contests** (submissions, voting, the live Reveal Party), with checkout through WooCommerce.

This folder is the whole WordPress install. Billy's own code is in [`wp-content/plugins/`](wp-content/plugins/) (7 plugins) and the theme in [`wp-content/themes/the-self-hosted-self-theme/`](wp-content/themes/the-self-hosted-self-theme/). Everything else is standard WordPress or third-party plugins.

## Start here (Billy)

You work on the site by asking Claude, on your own computer, with a local copy of the site running in the **Local** app.

1. Open **Local** and start the **billyhume** site. It runs at **http://billyhume.local**.
2. Open Claude in this folder and say what you want in plain words, for example:
   - "Change the 'Next Lesson →' button to say 'Keep going →'."
   - "Make the course cards less rounded."
   - "The contest vote button should say 'Pick this one'."
3. Claude makes the change, checks it in the browser, and tells you where to look.
4. When you're happy, ask Claude to **commit**. When you want it on the real site, ask Claude to **push dev**, then in the live site's wp-admin go to **Site Tools → Debug Tools → GitHub Updates**, click **Check now**, then **Update now**, then **Caching → Purge All**.

Nothing reaches the real site until step 4.

## Where things are

| You want to change… | It lives in… |
|---|---|
| Words on buttons and messages | Each plugin's `words.php` file (front end at the top, admin screens below). Claude can also change one without touching the file: `wp bh set words.courses:lesson.next_lesson "Keep going →"` (see `wp help bh`). |
| Colors, fonts, rounding, spacing | wp-admin → **Appearance → Customize → Design Suite** (no code needed), or ask Claude: `wp bh set style:radius 16` |
| Lessons, videos, quizzes, downloads | wp-admin → **Courses** |
| Contests | wp-admin → **Contests** |
| Page layouts (home page, sales pages) | **Etch** on the live site |
| Errors, logs, tests | wp-admin → **Site Tools → Debug Tools** |

## The plugins

| Plugin | What it does |
| --- | --- |
| `the-self-hosted-self` | The core everything else needs: accounts and the `/account/` page, the Design Suite, Debug Tools, logs, tests, email, background jobs, GitHub Updates. Shown in wp-admin as **Site Tools**. |
| `bh-courses` | Courses, lessons, quizzes, video, notes, downloads, sales pages, certificates. |
| `bh-contest` | Contests: submissions, voting, judging, the live results reveal. |
| `bh-monetization-woo` | Selling through WooCommerce: course products, supporter tiers, gifts, launch coupons, tips. |
| `bh-tickets` | The Support page contact form and private course feedback. |
| `bh-crm` | People: everyone with an account, with notes and tags. |
| `bh-mailpoet` | Copies People into a MailPoet mailing list. |

## For developers

- **Plain WordPress on the server.** The live site is a straight copy of these files: PHP and MySQL only, no build step on the server.
- **TypeScript at build time.** Browser code is written in `assets/ts/*.ts` and compiled into the committed `assets/js/*.js` with `npm run build:all` (run `npm ci` once first).
- **Checks:** `npm run check`, `php -l`, and **Debug Tools → Run all tests**. See [`TESTS.md`](wp-content/plugins/TESTS.md).
- **Rules** for changing the code: [`CLAUDE.md`](CLAUDE.md). A plain tour of the code: [`CODEBASE-WALKTHROUGH.md`](wp-content/plugins/CODEBASE-WALKTHROUGH.md). Shipping: [`RUNBOOK-live-updates.md`](wp-content/plugins/RUNBOOK-live-updates.md).

License: AGPL-3.0 (see [`wp-content/plugins/LICENSE`](wp-content/plugins/LICENSE)).
