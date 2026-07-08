# Own Ur Shit

**Self-hosted infrastructure for owning your audience, your data, and your relationship with the people who support you — without renting any of it from a big tech platform.**

Own Ur Shit is a WordPress plugin ecosystem: one required core plus genuine peer feature plugins, each optional, each installable on its own. Together they cover the tools an independent creator actually needs to run a real business — streaming, contests, courses, a CRM, monetization, and a decentralized artist-link directory — all on infrastructure the creator controls outright, hosted for the cost of ordinary shared hosting.

## Why this exists

Modern creative and business life requires tools for reaching an audience, running commerce, building community, and teaching. Right now, almost everyone rents those tools from a small number of platforms that own the relationship, the data, and the terms — one algorithm change or account suspension away from losing everything. That's not acceptable as a permanent default.

Own Ur Shit exists so that's optional. Install it on hosting you control, and you own your audience and data outright, pay only for hosting, and never depend on a company's continued goodwill to keep what you built. This should be a right, freely available to anyone willing to self-host — not a subscription, not a walled garden.

Every design decision in this codebase gets measured against that: **self-hosted, no vendor lock-in, no quiet dependency on a paid third-party service where an owned equivalent will do.** See [`VISION.md`](VISION.md#mission--read-this-before-anything-else) for the full framing — it's the first thing worth reading in this repo.

## What's here

| Plugin | What it does |
|---|---|
| **`own-ur-shit`** *(core, required)* | Shared accounts/profiles, design tokens, moderation queue, install/activate dashboard, and the cross-cutting shared services every other plugin builds on — see below. |
| **`bh-streaming`** | A personal streaming library — releases, playlists, shuffle/queue, shared-listening Jam sessions, an RSS aggregator, and an aggregate (privacy-flat) plays/metrics dashboard. |
| **`bh-contest`** | Music contest voting, reveal, and results, with a real per-category vote-limit and fraud-resistant tallying. |
| **`bh-crm`** | A person list and activity view built on the ecosystem's shared identity — any other plugin can contribute an activity line to a contact's timeline. |
| **`bh-registry`** | A decentralized, anonymous artist-link directory — no account required to list or browse. |
| **`bh-monetization-woo`** | Supporter tiers, purchases, tips, pay-per-play — all backed by WooCommerce, never a parallel payments stack, plus a generic paywall any content type can use. |
| **`bh-courses`** | An LMS — multistep lessons (text/image/video/quiz), progress tracking, drip scheduling, optional tier-gating. |

## The architecture, in one paragraph

A peer plugin depends only on the core's presence, and treats every other peer plugin as entirely optional — checked at runtime via `class_exists()`, never at file-parse time, always with a working fallback if the other plugin isn't installed. `bh-streaming` doesn't know `bh-monetization-woo` exists except through a documented filter contract; `bh-courses` doesn't know `bh-streaming` exists at all. This is what makes "install just the two plugins you actually want" a real, working claim rather than marketing copy — and it's the single most important convention to preserve as this ecosystem grows.

The core provides real shared infrastructure once, centrally, so no feature plugin reinvents it: a WP-Cron-driven async job queue (`OUS_Jobs`), an aggregate error/exception console (`OUS_DebugLog`), an in-browser test runner for a hosting environment with no CLI (`OUS_TestRunner`), an auto-generated OpenAPI spec of the ecosystem's own REST surface (`OUS_ApiDocs`), a shared in-app notification/email system (`OUS_Notifications`), and — most recently — a self-hosted, GA/Segment-equivalent event-tracking layer (`BH_Event`/`BH_Identity`) that joins a contest vote, a track play, a CRM contact, and a course enrollment on one identity, without ever leaving this ecosystem's own database. Full technical detail on all of it lives in [`VISION.md`](VISION.md).

## Getting started (self-hosted)

1. A WordPress install on ordinary shared hosting (PHP 7.4+, MySQL, WP-Cron enabled — no Redis, no external queue, no special infrastructure required by design).
2. Install and activate `own-ur-shit` first — every other plugin here requires it.
3. From the Own Ur Shit dashboard (`wp-admin` → Own Ur Shit), install and activate whichever peer plugins you actually want. Nothing here forces an all-or-nothing install.

## Documentation map

- [`VISION.md`](VISION.md) — the mission, the current state of the ecosystem, and the near-term roadmap. Start here.
- [`ROADMAP-platform-evolution.md`](ROADMAP-platform-evolution.md) — the next scale of ambition: a full storefront/merchandising layer, an auction mechanism, deeper monetization tiers, a visual content-block builder, a fully custom user-facing portal, and roadmap-only research into social/marketing platform integrations.
- [`ROADMAP-safety-and-metrics.md`](ROADMAP-safety-and-metrics.md) — fraud/abuse detection, moderation, and long-term safety/legal items (audio fingerprinting, DMCA, GDPR/CCPA erasure, royalty-split payouts).
- [`ROADMAP-feedback-and-courses-v2.md`](ROADMAP-feedback-and-courses-v2.md) — the BH Feedback plan and BH Courses' remaining gap list, in full technical detail.
- [`EVENT-TRACKING-ARCHITECTURE-PLAN.md`](EVENT-TRACKING-ARCHITECTURE-PLAN.md) — the design of the self-hosted event-tracking/identity layer described above.

## License

Licensed under the GNU Affero General Public License v3.0 (AGPL-3.0). This copyleft license was chosen deliberately to match the project's stated purpose: if anyone runs a modified version of this ecosystem as a network service, the AGPL requires them to make that modified source available too, so improvements stay available to the self-hosting community instead of disappearing behind a SaaS fork. See [`LICENSE`](LICENSE) for the full text.
