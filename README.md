# Own Ur Shit

**Digital infrastructure you own, not rent.** Own Ur Shit is a self-hosted WordPress plugin ecosystem that gives an independent musician everything a big-tech platform normally rents them back one subscription at a time — a streaming catalog, contest tools, a learning platform, a fan CRM, monetization — as software they actually own outright, on hosting they control, with no algorithm, no account suspension, and no rented middleman standing between them and their audience.

That's the whole point, stated plainly: every rented platform this replaces makes money by inserting itself between a creator and the people who already chose to follow them, and it can change the terms, the price, or the rules at any time — or vanish. Own Ur Shit is the alternative. No ads, ever — not as a business model, not as an option a site owner could flip on. An artist sets their own terms with their own audience directly (tiers, purchases, tips — never a third-party ad network extracting value neither side asked for). It costs what hosting costs, nothing more, and it never asks anyone to trade their audience relationship for a platform's convenience. See [`VISION.md`](wp-content/plugins/VISION.md) for the full philosophy and the honest, in-progress roadmap toward becoming a genuine business-in-a-box for the same reasons.

Everything lives under [`wp-content/plugins/`](wp-content/plugins/). Each plugin depends only on `own-ur-shit` (shared identity, design tokens, and admin dashboard) — never on each other — so any subset can run alone. Install just the two plugins you actually want; nothing here requires the rest.

## The plugins

| Plugin | What it does |
| --- | --- |
| [`own-ur-shit`](wp-content/plugins/own-ur-shit/README.md) | The ecosystem core — shared accounts/login/email verification, shared design tokens with a live style gallery, and one dashboard for installing/activating every peer plugin. |
| [`bh-streaming`](wp-content/plugins/bh-streaming/README.md) | An iTunes-like personal streaming library — catalog, playlists, likes, recommendations, and an RSS/Podcasting-2.0 feed, installable as a PWA. |
| [`bh-contest`](wp-content/plugins/bh-contest/README.md) | Music contest voting — submissions, per-category voting, a live results-reveal system built for streaming, and Discord integration. |
| [`bh-courses`](wp-content/plugins/bh-courses/) | An LMS — courses made of ordered, multistep lessons (text/image/video/quiz), progress tracking, and optional supporter-tier gating. |
| [`bh-monetization-woo`](wp-content/plugins/bh-monetization-woo/README.md) | Supporter tiers, purchases, tips, and pay-per-play — backed by WooCommerce, never a parallel payments stack. |
| [`bh-crm`](wp-content/plugins/bh-crm/) | A person list built on shared identity — profiles, notes, tags, CSV export, and a unified per-person activity timeline any other plugin can contribute to. |
| [`bh-registry`](wp-content/plugins/bh-registry/README.md) | A global, decentralized artist-link registry — artists opt in with a public ActivityPub actor or RSS/Podcasting-2.0 feed link. Stores links only, never media. |

## Stack

Plain WordPress — no build step, no bundler. JS/CSS assets are either hand-written or vendored directly (e.g. SortableJS, FPDF) rather than pulled in through Composer/npm. PHP is checked with `php -l`, not a test framework, though a few plugins carry a lightweight `class-test-suite.php` runnable from their own Debug Tools screen.

## Local development

This repo is a full WordPress install (Local by Flywheel), not just the plugin source — `wp-content/plugins/` is where the actual ecosystem code lives; everything else (`wp-admin/`, `wp-includes/`, core files) is stock WordPress.
