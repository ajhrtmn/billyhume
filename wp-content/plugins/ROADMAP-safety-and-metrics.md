# Own Ur Shit ecosystem — safety/anti-fraud and fan-metrics roadmap

This covers the ideas discussed after the last security pass (bh-monetization-woo refund flagging, bh-streaming Jam kick, own-ur-shit's shared report queue). It splits them into what's realistically simple to build versus what's genuinely bigger scope — new infrastructure, legal exposure, or a real shift in this ecosystem's "presentation, not surveillance" posture.

**Status update:** every item in the "near-term implementation plan" below is now built and wired — confirmed via direct code inspection, not just assumed from this doc's own earlier wording. Kept here (rather than deleted) as a reference for where each one actually lives, since the doc previously (and wrongly) implied these were still open.

## Near-term implementation plan — all shipped

### 1. Artist-facing aggregate metrics dashboard (bh-streaming) — BUILT
`BHS_Stats` (`bh-streaming/includes/class-stats.php`) — a real `bhs_daily_stats` rollup table, referrer classification, and an admin report. Aggregate-only, no per-listener data stored.

### 2. Refund/fraud pattern: device+IP correlation — BUILT
`bh-monetization-woo/includes/class-fraud.php:36-70` — `track_refund_pattern()`, table `bhm_refund_fingerprints`, hooked to `woocommerce_order_status_refunded`/`cancelled`. Surfaced in bh-crm via `class-crm-integration.php`.

### 3. Purchase/pay-per-play velocity checks — BUILT
`bh-monetization-woo/includes/class-products.php:377,392-410` — `check_play_velocity()`. Wallet top-up velocity cap in `class-fraud.php:109-144`. Surfaced in bh-crm via `class-crm-integration.php:67,71,102-105`.

### 4. Registry: report-volume queue prioritization — BUILT
Lives in **own-ur-shit core**, not bh-registry (this doc's original wording implied the latter) — `class-reports.php:437-446` sorts by `COUNT(DISTINCT reporter_user_id) DESC` and highlights rows with 3+ reporters in red.

### 5. Jam: lightweight per-listener mute — BUILT
`bh-streaming/assets/js/player.js:1396-1438` — correctly client-side/localStorage-only, matching this doc's original scope exactly.

### 6. Jam: participant cap + invite-approval mode — BUILT
`bh-streaming/includes/class-jam.php:211-226,274,278-286`.

### 7. Duplicate-audio-hash detection across accounts — BUILT
`bh-streaming/includes/class-audio-hash.php:34-76` — `hash_and_check()` (sha1_file, cross-account match), fires `bhs_duplicate_audio_flagged`, surfaced in `class-admin.php:412`.

## Long-term vision

Bigger scope: either real infrastructure this ecosystem doesn't have yet, a genuine shift in its privacy posture, or things that need legal input before shipping.

- ~~**Two-factor authentication**~~ — **BUILT**, not long-term anymore: `own-ur-shit/includes/class-two-factor.php` (TOTP, enrollment AJAX, `authenticate` filter, mirrored in REST login).
- **Real audio fingerprinting** against known-catalog databases (Content-ID-style), almost certainly via a third-party API rather than something built in-house — the duplicate-hash check above is the practical stepping stone toward this, not a replacement for it.
- **Formal DMCA notice/counter-notice workflow** with real legal timelines and a defined process, distinct from the report-queue intake that already exists — this needs actual legal review, not just more code.
- **Per-fan demographic profiling and targeted messaging** (location/age/purchase-history segmentation, "email everyone in Chicago about a show") — genuinely useful to a working musician, but the point where a real privacy policy, consent flow, and likely GDPR/CCPA compliance work become necessary before writing any code. Should be a deliberate decision, not something that creeps in through smaller features.
- **Royalty-split payout engine** — already flagged as future work in bh-monetization-woo's own README (dividing a subscription pool by relative plays across an artist's catalog).
- **Realtime Jam transport** (WebSocket/SSE relay) to replace the current polling-based sync — the position-projection design already in place was built specifically to make this swap possible later without touching the rest of Jam.
- **Full cross-plugin GDPR/CCPA account-erasure tooling** — the self-service profile deletion that exists today only covers `BHI_Profiles`; a genuine "erase my whole account" flow would need to reach into bh-crm notes/tags, bh-registry submissions, and bh-monetization-woo's non-financial records too, coordinated through WordPress core's own personal-data-erasure hooks.
- **Native app readiness** — carried over from the original ecosystem brief: nothing here forecloses it, but actually building a native client is its own project.
