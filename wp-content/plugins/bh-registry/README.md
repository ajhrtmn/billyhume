# BH Registry

A global, decentralized artist-link registry. Any artist can submit
their public ActivityPub actor or open RSS/Podcasting-2.0 feed link;
anyone (fans, other artists, a WordPress streaming app, or a future
native app) can browse and search for artists who've opted in.

Stores links and metadata only — **never media**. Media always stays
hosted wherever the artist actually controls it (their own Funkwhale
instance, another ActivityPub server, their own site's RSS export,
etc.).

Depends only on `own-ur-shit` (the core). Deliberately has **no**
dependency on `bh-streaming` — a site can install just this plugin to
run a bare directory, or a fan can use the public browse page with no
other Own Ur Shit plugin installed at all. If `bh-streaming` happens to
also be active, `class-streaming-bridge.php` adds a small one-directional
convenience (search-and-fill on the Feed Sources screen); nothing here
requires it.

## Trust model

Two independent checks, both required before a link is `verified`:

1. **Ownership** — a well-known file challenge. At submission time a
   random token is generated; the submitter publishes it at
   `https://{their-domain}/.well-known/bh-registry-verify.txt`. Proves
   domain control, independent of protocol.
2. **Openness** — the URL must actually be the open protocol it claims:
   for `feed`, at least one item with a real `<enclosure>` (same check
   as `bh-streaming`'s `class-feeds.php`); for `activitypub`, a
   spec-shaped actor document (`type` + `outbox`) fetched with
   `Accept: application/activity+json`.

Verified links are re-checked daily (`bhr_recheck_links` cron) since
control can lapse.

An artist becomes publicly visible (`status = active`) automatically
the moment it has at least one verified link — no manual admin approval
gate. The admin review queue (**Own Ur Shit → Registry Submissions**)
exists for abuse handling (reject/restore/delete), not as a required
step.

## REST API — `bhr/v1`

One contract, three consumers: a WordPress streaming app adding a feed
source, a future native app's "add an artist" flow, and a plain
fan-facing browse/search page. All three read the same endpoints below.

| Method | Route | Auth | Purpose |
|---|---|---|---|
| GET | `/bhr/v1/artists?search=&protocol=` | none | Browse/search active (verified) artists |
| GET | `/bhr/v1/artists/{id}` | none | Full artist profile + verified links |
| GET | `/bhr/v1/artists/{id}/feed-url` | none | The one resolved, verified feed URL — hand straight to an importer |
| POST | `/bhr/v1/submissions` | none, rate-limited | Start a submission; returns the verification challenge |
| POST | `/bhr/v1/submissions/{link_id}/verify` | none | Re-run both checks for one link |

`contact_email` is stored but never returned by any public endpoint.

**CORS**: every `bhr/v1` route sends `Access-Control-Allow-Origin: *` (see `BHR_API::add_cors_headers()`) — this is the entire point of the API existing, so a native app or another site's browser-side JS can call it directly. Safe to leave wide open since none of these routes use WordPress's cookie/nonce auth in the first place.

## Extension points used

- `ous_registered_plugins` — dashboard card + admin menu relocation.
- `bhy_style_surfaces` — browse/search preview in the Style gallery.
- `ous_debug_tools` — seed/reset fake verified artists for testing.

## Not built here (by design)

Native app UI, ActivityPub Follow/Accept federation (this registry is a
directory, not a federated social graph), and any media hosting
whatsoever.
