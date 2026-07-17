# Own Ur Shit ecosystem — safety/anti-fraud and fan-metrics roadmap

This covers the ideas discussed after the last security pass (bh-monetization-woo refund flagging, bh-streaming Jam kick, own-ur-shit's shared report queue). It splits them into what's realistically simple to build next versus what's genuinely bigger scope — new infrastructure, legal exposure, or a real shift in this ecosystem's "presentation, not surveillance" posture. Nothing here is built yet; this is the plan to work from next.

## Near-term implementation plan

These all reuse data and patterns that already exist in the codebase — no new consent/privacy infrastructure, no third-party integrations, no legal review needed to ship them.

### 1. Artist-facing aggregate metrics dashboard (bh-streaming)
A new admin page reusing data that already exists: `bhm_play_log` (bh-monetization-woo, if active) and `_bhs_play_count`/`bhs_likes` (bh-streaming's own tables) for plays over time and top tracks/releases; Jam's `skip_votes` history for a skip-rate signal per track (a track that gets voted off constantly is a real, actionable signal an artist doesn't currently see anywhere); country-level geo from IP, bucketed into a daily aggregate count at write time and never stored per-listener; referrer source (registry vs. shared playlist link vs. Jam invite vs. direct) captured the same way. Nothing here is per-fan — it's all aggregate counts, so it ships with zero new privacy surface.
**Scope:** one new admin page in bh-streaming, a couple of new aggregate-only DB columns/tables (e.g. a `bhs_daily_stats` rollup), no new PII collected.

### 2. Refund/fraud pattern: device+IP correlation
Extends the refund-flagging from the last pass. Right now `_bhm_refund_log` is purely per-account; add a lightweight per-request fingerprint (IP + a persistent, non-tracking cookie id) recorded alongside each refund/order, so a flagged pattern can also surface "this same device/IP is behind N different accounts with refund patterns" — catching the "same person, new username" evasion without building real fingerprinting.
**Scope:** a new column on the existing refund-log meta, a correlation query surfaced in the existing bh-crm activity view (no new UI surface, just a richer flag).

### 3. Purchase/pay-per-play velocity checks
A simple rate-based flag: N wallet debits or purchase attempts from one account within a short window (e.g. 10 pay-per-plays in under a minute) sets the same kind of review flag the refund pattern already does. Useful both for a compromised account being drained and for someone testing stolen payment methods.
**Scope:** reuses `bhm_play_log`/order data already being written; one new threshold check in `BHM_Products`, surfaced the same way refund flags are.

### 4. Registry: report-volume queue prioritization
The `bhi_reports` table already exists. Add a simple rule: if a single `target_type`+`target_id` gets 3+ independent reports (different reporters) within a short window, bump it to the top of the admin Reports queue rather than sorting purely by created_at. No new data collection, just a better sort/highlight on data already being captured.
**Scope:** one query change in `BHI_Reports::render_admin_page()`, plus a visual "multiple reports" flag.

### 5. Jam: lightweight per-listener mute
Cheaper than a report or a host kick — any participant can locally mute (hide badges/hide from "who's here") a specific other user, entirely client-side/local preference, no admin involvement, no data sent anywhere. Distinct from kick (host-only, removes someone from the session) — this just lets an individual listener curate who they see.
**Scope:** a small addition to the Jam front-end only (localStorage-backed mute list, filtered into `jamRenderParticipants`); no server changes.

### 6. Jam: participant cap + invite-approval mode
A max-participants setting on session creation (protects against a leaked invite code turning into an open room), plus an optional "host must approve joins" mode as an alternative to open-by-code joining.
**Scope:** one new field on `bhs_jam_sessions.state_json`, a join-request/approve step added to the existing join flow.

### 7. Duplicate-audio-hash detection across accounts
On local-import and feed-sync (the two "not vetted" content paths already excluded from monetization), compute a content hash of the uploaded/aggregated audio file and flag when the same hash appears under a different account/artist — a strong signal of scraping or someone re-uploading another artist's catalog as their own. This is a cheaper, real first step toward the bigger fingerprinting idea below, without needing a third-party service.
**Scope:** a hash column on `bh_track` posts, a lookup on import/sync, surfaced as a flag in the existing admin catalog view (not auto-blocked — same "flag for a human" philosophy as everything else here).

## Long-term vision

Bigger scope: either real infrastructure this ecosystem doesn't have yet, a genuine shift in its privacy posture, or things that need legal input before shipping.

- **Real audio fingerprinting** against known-catalog databases (Content-ID-style), almost certainly via a third-party API rather than something built in-house — the duplicate-hash check above is the practical stepping stone toward this, not a replacement for it.
- **Two-factor authentication** for accounts, especially artist accounts that control payout-adjacent settings — closes the account-takeover gap flagged earlier.
- **Formal DMCA notice/counter-notice workflow** with real legal timelines and a defined process, distinct from the report-queue intake that already exists — this needs actual legal review, not just more code.
- **Per-fan demographic profiling and targeted messaging** (location/age/purchase-history segmentation, "email everyone in Chicago about a show") — genuinely useful to a working musician, but the point where a real privacy policy, consent flow, and likely GDPR/CCPA compliance work become necessary before writing any code. Should be a deliberate decision, not something that creeps in through smaller features.
- **Royalty-split payout engine** — already flagged as future work in bh-monetization-woo's own README (dividing a subscription pool by relative plays across an artist's catalog).
- **Realtime Jam transport** (WebSocket/SSE relay) to replace the current polling-based sync — the position-projection design already in place was built specifically to make this swap possible later without touching the rest of Jam.
- **Full cross-plugin GDPR/CCPA account-erasure tooling** — the self-service profile deletion that exists today only covers `BHI_Profiles`; a genuine "erase my whole account" flow would need to reach into bh-crm notes/tags, bh-registry submissions, and bh-monetization-woo's non-financial records too, coordinated through WordPress core's own personal-data-erasure hooks.
- **Native app readiness** — carried over from the original ecosystem brief: nothing here forecloses it, but actually building a native client is its own project.
