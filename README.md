# The Self-Hosted Self

**Digital infrastructure you own, not rent.** A self-hosted WordPress plugin ecosystem that gives an independent musician what big platforms rent back one subscription at a time — a streaming catalog, contests, courses, a fan CRM, monetization — as software they own outright, on hosting they control. No ads, ever, and no middleman between an artist and their audience. The full philosophy is in [`VISION.md`](wp-content/plugins/VISION.md).

This repository is Billy Hume's live site (billyhume.net): a complete WordPress install. The ecosystem's own code is under [`wp-content/plugins/`](wp-content/plugins/) and [`wp-content/themes/the-self-hosted-self-theme/`](wp-content/themes/the-self-hosted-self-theme/); everything else is stock WordPress or third-party plugins.

## The plugins

One required core plus optional peers. Each peer depends only on the core, never on another peer, so any subset works on its own.

| Plugin | What it does |
| --- | --- |
| [`the-self-hosted-self`](wp-content/plugins/the-self-hosted-self/README.md) | Core: accounts, login and the fan portal; design tokens and the Design Suite; Debug Tools, jobs, logs and the Test Runner; one dashboard to install and switch on everything else. |
| [`bh-streaming`](wp-content/plugins/bh-streaming/README.md) | Personal streaming library: catalog, playlists, likes, lyrics, an RSS/Podcasting 2.0 feed, installable as an app. |
| [`bh-contest`](wp-content/plugins/bh-contest/README.md) | Music contests: submissions, voting, judging, and a live results reveal for streaming. |
| `bh-courses` | Courses of multistep lessons (text, media, quizzes) with progress tracking and optional supporter-tier gating. |
| [`bh-monetization-woo`](wp-content/plugins/bh-monetization-woo/README.md) | Supporter tiers, purchases, tips, gifts and pay-per-play, backed by WooCommerce. |
| `bh-crm` | People list on shared identity: notes, tags, CSV export, activity timeline, and a project kanban board. |
| [`bh-registry`](wp-content/plugins/bh-registry/README.md) | Decentralized artist-link directory (ActivityPub / RSS links only, never media). |
| `bh-live` | Live streaming via self-hosted Owncast or Cloudflare Stream, with chat and OBS overlays. |
| `bh-video` | Standalone video catalog and player. |
| `bh-feedback` | Paid track feedback from reviewers, paid with wallet credit. |
| `bh-tickets` | Support tickets opened from the fan portal. |
| `bh-social` | Cross-posting and stats for social platforms. |
| `bh-mailpoet` | Syncs CRM contacts into MailPoet lists (inert without MailPoet). |

`self-hosted-self-admin-skin` is experimental and stays deactivated.

## How it's built

- **Plain WordPress at runtime.** The live site is a verbatim file sync: PHP and WordPress only, no Composer install, no npm, no bundler. FPDF is the one vendored library.
- **TypeScript at build time.** Browser code is written in `assets/ts/*.ts` and compiled with `tsc` into the committed `assets/js/*.js` (`npm run build:all`). Styles are plain CSS in `assets/css/`.
- **Checks:** `npm run check` (TypeScript build, Stylelint, accent-contrast check, Vitest), `php -l`, and the in-admin Test Runner. See [`TESTS.md`](wp-content/plugins/TESTS.md).

## Working on it locally

1. Create a site in [Local](https://localwp.com/) and replace its `app/public` folder with this repository (keep Local's own `wp-config.php`).
2. `npm ci` for the build and check tools.
3. Work on the `dev` branch. Pushing `dev` is what reaches the live site; see [`RUNBOOK-live-updates.md`](wp-content/plugins/RUNBOOK-live-updates.md).

Rules for changing the code (for people and AI assistants alike) are in [`CLAUDE.md`](CLAUDE.md). A guided tour of the code is in [`CODEBASE-WALKTHROUGH.md`](wp-content/plugins/CODEBASE-WALKTHROUGH.md).

## License

AGPL-3.0: anyone who runs a modified version as a network service must publish their changes, so improvements stay with the self-hosting community. See [`wp-content/plugins/README.md`](wp-content/plugins/README.md#license).
