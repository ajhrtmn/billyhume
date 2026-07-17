# Own Ur Shit

A self-hosted, no-vendor-lock-in platform for musicians — a WordPress plugin ecosystem that replaces a stack of third-party SaaS tools (streaming pages, contest tools, LMS platforms, fan CRMs, link registries) with one set of plugins an artist actually owns.

Everything lives under [`wp-content/plugins/`](wp-content/plugins/). Each plugin depends only on `own-ur-shit` (shared identity, design tokens, and admin dashboard) — never on each other — so any subset can run alone.

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
