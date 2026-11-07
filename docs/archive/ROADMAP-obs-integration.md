# ROADMAP — OBS integration for bh-live

Design pass only. Nothing in this document is built. Scoped from a live conversation with AJ 2026-08-01: AJ wants something in the shape of StreamElements — chat aggregation, overlays, scene automation — but built to this ecosystem's own standing discipline (self-hosted where possible, no forced vendor lock-in, every cross-plugin dependency `class_exists()`-guarded and optional).

## What exists today, confirmed by direct code check

`bh-live` (v0.9.0) already has the load-bearing pieces this integration builds on top of, not from scratch:

- **`BHL_StreamEngine` interface** ([class-stream-engine.php](bh-live/includes/class-stream-engine.php)) — two implementations, self-hosted Owncast and managed Cloudflare Stream Live. Both already hand a broadcaster a real RTMP ingest URL + stream key ([class-cloudflare-engine.php:83-103](bh-live/includes/class-cloudflare-engine.php#L83-L103)) meant to be pasted into OBS — the "OBS as an RTMP source" half of this integration is already live. This roadmap is about the other direction: OBS pulling *from*, and being driven *by*, the ecosystem.
- **`BHL_Chat` interface** ([class-chat.php](bh-live/includes/class-chat.php)) — deliberately abstracted separate from the video engine, three implementations already exist: Owncast's bundled iframe chat, a free polling-based `BHL_PollingChat`, and a real-time `BHL_WorkersChat` (Cloudflare Worker + Durable Object).
- **`BHL_HostProvisioner` interface** with a real Fly.io Machines implementation — precedent for "broadcaster enters credentials once, we hold a persistent external connection on their behalf."
- **`bhl/v1` REST namespace** ([class-api.php](bh-live/includes/class-api.php)) — currently just `/status` and `/replays`.
- **No overlay pages, no obs-websocket bridge, and no chat aggregation across external platforms anywhere in the ecosystem.** This is genuinely new surface, not a gap-fill.

## Competitive analysis — what to take, what to avoid

Three products define this space, and each has a specific, well-documented failure mode worth designing away from rather than a generic "learn from competitors" gesture:

**StreamElements** (cloud-rendered overlays pulled into OBS via Browser Source URLs) — strong because nothing runs on the streamer's machine (3-5% CPU vs. Streamlabs' 15-25%), and overlays are just web pages so they work with any broadcast software. Weak in two specific, recurring ways: the overlay editor causes analysis-paralysis (too many knobs, no defaults), and alert delivery fails silently — an event fires, nothing shows on stream, and there's no diagnostic telling the streamer why. Support docs are dominated by "copy the URL wrong" and "Browser Source didn't refresh" tickets.

**Streamlabs** (bundled desktop app, own OBS fork, overlays/alerts run locally) — strong on one-app onboarding, weak on exactly what StreamElements solved: real CPU/RAM cost, crashes on older hardware, features paywalled behind a subscription.

**Streamer.bot** (free, local automation engine — not an overlay renderer at all) — strong because it's genuinely unbounded: any event (chat, subs, raids, custom triggers) can drive any OBS action via obs-websocket v5, plus C# scripting and integrations with a dozen other tools. This is where real scene-automation power lives in this space today. Weak because that power is the whole product — no built-in visual layer, aimed at technically-inclined streamers willing to assemble rules from primitives.

**The actual opening**: StreamElements vs. Streamer.bot is a real, unclosed trade-off in the market — easy-but-shallow vs. powerful-but-DIY. This ecosystem can close it in a way neither can, because bh-live isn't a generic tool reverse-engineering chat text or exposing raw webhooks — it already has a first-party event bus (`BH_Event::emit()`, wired through bh-crm/bh-contest/bh-monetization-woo) and known, structured data sources (contest votes, now-playing, course-live-sessions). "Contest round starts → OBS switches scenes" can ship as a **named, pre-built automation** a broadcaster toggles on, not a rule they assemble from an event/action primitive list.

## Design principles carried into every phase below

1. **No silent failure.** Every overlay/automation surface gets a real status check visible in the bh-live admin, not just "the URL is correct, hope it renders" — directly targets StreamElements' most common complaint.
2. **Sane defaults, not a blank canvas.** Overlay pages ship pre-styled from `BHY_Style` tokens (matching whatever the broadcaster's ecosystem site already looks like) so a broadcaster gets a working, on-brand overlay with zero configuration, only overriding if they want to.
3. **Optional and failure-isolated per source**, same `class_exists()` discipline already standing across every peer plugin — an expired Twitch token degrades that one chat source, not the whole overlay.
4. **Power without a rules-builder UI in v1.** Match Streamer.bot's actual capability (arbitrary ecosystem event → OBS action) but expose it as pre-wired, toggleable presets tied to real bh-contest/bh-courses/bh-monetization-woo events, not a generic automation-builder a broadcaster has to construct from scratch.

## Phasing

Each phase is independently shippable and useful on its own; nothing in an earlier phase is thrown away by a later one.

### Phase 1 — Browser Source overlays (small, front-end, do first)

New `BHL_Overlay` class serving stable, unauthenticated, transparent-background HTML pages under `bhl/v1/overlay/{stream_id}/{type}` that OBS adds as Browser Sources:

- **Chat overlay** — reuses the existing `BHL_Chat` polling/Workers implementations for rendering. Owncast's bundled chat has no overlay-safe embed mode, so streams on the Owncast engine fall back to the polling widget specifically for overlay purposes (video/ingest still comes from Owncast; only the overlay chat source differs).
- **Now-playing overlay** — pulls from `bh-streaming` if active (`class_exists('BHS_...')` guard), otherwise omits the widget entirely rather than erroring.
- **Contest vote-count overlay** — pulls from `bh-contest` if active, same optional-dependency pattern.
- **Health check** — `bhl/v1/overlay/{id}/health` reports last-polled timestamp / config status, surfaced in bh-live's admin screen so "is OBS actually pulling this right now" is answered in WordPress, not guessed at.

No persistent connections, no new credentials to collect — this phase is pure read-only rendering on top of data that already exists.

### Phase 2 — obs-websocket bridge for scene automation (bigger, stateful)

New `BHL_OBSBridge`: a broadcaster enters their local OBS's WebSocket host/port/password once (same credential-entry shape as the existing Cloudflare/Fly.io settings in `class-admin.php`), held for the duration of a broadcast session — closer in shape to `BHL_HostProvisioner` than to Phase 1's stateless pages.

v1 ships a small, fixed set of **pre-wired, toggleable automations** rather than a generic rule builder:
- Contest round start/end → scene switch
- New highest bid (bh-monetization-woo, if active) → scene switch or source toggle
- A course-live-session starting (bh-courses, if active) → scene switch

Each mapping is a real `BH_Event` listener translated into an obs-websocket v5 call (switch scene / toggle source visibility / start-stop recording — the three actions that cover the automations above, not the full obs-websocket surface). A custom event→action rule builder is an explicit v2 idea, not part of this scope, so v1 doesn't try to out-build Streamer.bot on generality — it wins on zero-assembly setup for the events this ecosystem already knows about.

### Phase 3 — Chat aggregation ("StreamElements-lite")

Extends `BHL_Chat` so external chat sources (YouTube, Twitch — whichever platforms a broadcaster supplies API keys for) merge into the same normalized feed the on-page widget and Phase 1's overlay already render. Read-only pull, no posting back out to those platforms — narrows the scope and sidesteps most of the OAuth/rate-limit fragility that would otherwise be the highest-risk part of this whole roadmap. Each external source fails independently (principle 3 above): a dead Twitch token degrades to "no Twitch messages," never a broken overlay.

Sequenced last because it's the most externally-dependent and the least novel relative to what `BHL_Chat`'s polling implementation already proves out in Phases 1-2.

## Open questions for AJ before Phase 2 code starts

Phase 1 needs no new decisions — it's additive rendering on existing data. Phase 2 does:

1. Which three automations above are actually the ones worth shipping first, or is there a different top pick (e.g. a specific bh-courses live-session trigger)?
2. Should the OBS WebSocket credential live per-broadcaster (bh-live already has per-user broadcast setup) or per-site — matters for anyone besides Billy running bh-live.
3. Any appetite for the v2 custom-rule-builder now, or confirmed fine deferring it indefinitely until real usage shows the fixed presets aren't enough?
