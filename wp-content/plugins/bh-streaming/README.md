# BH Streaming

An iTunes-like personal streaming library for one artist's own catalog
— releases, genres, playlists, likes, a content-based recommendation
engine, and a gatekept RSS aggregator. Installable as a PWA with real
Media Session API support for lock-screen playback controls.

## What it does

- **Catalog**: tracks, grouped into releases (albums/EPs/singles),
  tagged with genres (a plain WordPress taxonomy — no custom admin UI
  needed for that part).
- **Listener features**: search, genre filtering, likes, playlists, a
  real queue/up-next panel, and "related tracks" recommendations (same
  artist / same release / shared genre — explicitly content-based, not
  a machine-learning claim this doesn't back up).
- **The aggregator**: an admin can feature another artist's feed by
  pasting its URL — uses WordPress's own `fetch_feed()`, not custom XML
  parsing. This site's own catalog also exports as a standard
  Podcasting-2.0-style feed at `/wp-json/bhs/v1/feed.xml`, so another
  `bh-streaming` site (or any podcast app) can subscribe to it. Nothing
  gets aggregated without an admin explicitly adding that feed first.
- **PWA foundations**: a dynamically generated manifest, a minimal
  app-shell service worker, and one-upload icon generation (upload a
  single source image, every size iOS/PWA actually need gets generated
  automatically).

## Requirements

- **Own Ur Shit** (the ecosystem core — shared accounts/profiles for
  likes/playlists, and the design tokens its own stylesheet is built on)

Needs to be installed and active first.

## Installation

1. Install and activate Own Ur Shit.
2. Install and activate this plugin.
3. Add tracks under **Tracks** in wp-admin — audio + optional artwork,
   both via the normal media uploader.
4. Add the `[bh_streaming]` shortcode to any page.

## Known, honest limitations

- iOS has a documented, currently-open bug where paused background
  audio can go unresponsive after ~30 seconds until the app is
  foregrounded again — not something pure web APIs can fully solve.
  Everything built here (the Media Session wiring especially) carries
  forward directly once a native wrapper exists on top.
- Volume control only meaningfully works on desktop — mobile browsers
  largely defer to hardware volume buttons instead.
- The recommendation engine iterates the whole catalog per request
  (an N+1-ish query pattern) — fine at the scale this is likely to see
  for a while, worth revisiting if the catalog gets genuinely large.

## Not built yet: rights/registration metadata beyond ISRC

A track can carry a real ISRC now (`_bhs_isrc`, on the track edit
screen — see `BHS_Admin::render_track_metabox()`), surfaced as
schema.org's actual `MusicRecording.isrcCode` property wherever
`[bh_streaming track="..."]` sets SEO data (`BHS_Player::
maybe_set_seo_data()`). AJ's own next ask, not yet scoped or built:
PRO affiliation (ASCAP/BMI/SESAC/etc.) and publishing-split management
— a real songwriter/publisher/split data model this plugin doesn't
have today, closer in shape to bh-monetization-woo's own
purchase/entitlement records than to anything currently on the track
CPT. Worth a real design pass (who are the parties, what's the split
shape, does a track need MULTIPLE writers/publishers with different
percentages) rather than a couple of guessed text fields.

Separately, AJ also raised something YouTube-Content-ID-shaped —
automated matching against other audio to catch unauthorized use
elsewhere. That's a fundamentally different, much larger piece of
infrastructure (audio fingerprinting + a matching pipeline, not a
metadata field) and isn't new: it's already named in
`ROADMAP-safety-and-metrics.md`'s long-term legal/safety section
("real audio fingerprinting"). Restating the pointer here so it isn't
lost as a "wait, did we already think about this" moment later —
still unscoped, still a real future project, not attempted this pass.
