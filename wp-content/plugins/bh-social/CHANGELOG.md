# Changelog — BH Social

Moved out of `bh-social.php` on 2026-08-25 — this plugin never got the same extraction the-self-hosted-self did on 2026-08-23, so its version history sat in source comments until now. See `CONVENTIONS.md` for why version history lives here and in git rather than in source.

Entries are newest-first, exactly as written in-file. Nothing reworded or dropped.

---

0.5.0 — Live-robustness pass: connection health tracking, prompted by a
direct request to harden third-party integrations for production. Real
gap found: `get_status()` on all four organic platforms only ever
checked "do we have a stored refresh_token" — never whether it still
actually works. A revoked or expired token left the settings page
showing a green "Connected" badge indefinitely while the twice-daily
stats-pull job quietly failed forever (visible only in the generic
Debug Tools job-failure table, which nobody checks proactively). This
is a real, live-relevant risk: a Google OAuth app left in "Testing"
publish mode (the default until submitted for verification) has
refresh tokens that expire after just 7 days of inactivity — YouTube
in particular was likely to silently die if not exercised regularly.

New `BHSO_ConnectionHealth` class, piggybacking on each platform's own
existing settings option (no new table): `track()` records the outcome
of every real API call; `is_broken()` reports true only when the most
RECENT outcome was a failure (a freshly connected platform that's never
been called yet stays optimistically 'connected', not a false
'needs_reauth'). Each platform's public `cross_post()`/`pull_stats()`
now delegate to a renamed `do_cross_post()`/`do_pull_stats()` and call
`track()` once on the way out — covers every internal failure path
(token refresh, HTTP error, non-2xx API response) without needing a
record call at each individual early return inside those methods.

Unified the resulting state under `needs_reauth` — the exact string
Meta's own token-expiry check already used, rather than inventing a
second status with the same meaning. Confirmed via `grep` that
YouTube/Twitch/TikTok's admin sections had NO branch for this status at
all before this change (only Meta did) — a future `needs_reauth` from
any of those three would have silently fallen into the "else, looks
connected" branch, defeating the entire point. All four sections now
show a distinct warning with the last recorded error message and a
one-click reconnect link.

Verified against the real local WP+MySQL install: a fresh connection
(no calls yet) correctly reads 'connected'; a tracked failure flips it
to 'needs_reauth' with the right error message; a subsequent tracked
success correctly flips it back to 'connected'.

0.4.0 — Tier 3 item 18 (social integrations), cross-posting sub-feature:
added `BHSO_AutoAnnounce`, an opt-in (default OFF), Twitch-only
auto-announce for the first publish of `bh_course` / `bh_contest` /
`bhs_release`. Scoping conclusion, after reading all four platforms'
real `cross_post()` implementations: this is NOT one generic action
across YouTube/Meta/TikTok/Twitch. YouTube/Meta/TikTok's cross_post()
each upload or attach a real media file (attachment_id/video_url/
image_url) — there's no coherent media file to hand them automatically
for a course/contest/release. Twitch's cross_post() is the one
platform whose payload is plain text (a chat announcement), so it's
the only one a generic "something new just published" event can drive
without inventing fake media. Reacts to core WordPress's own
`transition_post_status` directly (same first-publish test bh-contest's
own `BH_AdminModeration::maybe_notify_approval()` already uses) —
no `class_exists()` dependency on bh-courses/bh-contest/bh-streaming
needed, since a post_type string that doesn't exist on this install
just never matches. Settings section added to the existing BH Social
admin page, three checkboxes, all off by default.
Confirmed via `grep -rln "cross_post\|BH_SocialPlatform"` across the
whole plugins tree that these methods were, before this change, ONLY
ever called from bh-social's own manual admin UI (a textarea + button
on the settings page) — automatic cross-posting from another plugin's
publish event did not exist anywhere.
YouTube/Meta/TikTok embedding and import sub-features of item 18 not
yet scoped — separate follow-up.
NOT runtime-verified against a live connected Twitch account (same
"alpha" caveat as the rest of this plugin) — the first-publish
detection logic and settings persistence WERE verified against the
real local WP+MySQL install.

0.3.4 — Ecosystem quality Phase 2, brick 7/13: added native return/
parameter types across all 16 includes files (233 findings, both
mechanical level-6 categories) — the most repetitive brick so far.
Both interfaces (BH_SocialPlatform, BH_AdsPlatform) got typed method
contracts; all 9 implementing classes (YouTube/Twitch/Meta/TikTok,
Roku/Spotify/Amazon-DSP/Samsung/Vizio) matched to them via the same
mechanical signature pattern, since all 4 organic platforms and all
5 ad platforms genuinely share one shape each. Purely additive
typing, no behavior change. This plugin is now clean at PHPStan
level 6 in isolation.
NOT runtime-verified against a live install.

0.3.3 — This plugin's first PHPStan pass (newly added to
phpstan.neon's scanned paths this round). One finding, confirmed as a
real stub gap rather than a bug and scoped-ignored: $wpdb->last_error
has no property type declared anywhere in php-stubs/wordpress-stubs,
so a plain `if ($wpdb->last_error)` truthy check after an earlier
reset in the same file gets misread as permanently false — same root
cause as the ecosystem-wide `!== ''` ignore rule already in
phpstan.neon, this is the plain-truthy-check variant of it.
NOT runtime-verified against a live install — confirmed via a real
`vendor/bin/phpstan analyse` run. `php -l` clean.

0.3.1 — every organic-platform section and the whole ads group now
carry an OUS_Badge (the-self-hosted-self's new shared alpha/beta/experimental
helper, added this pass) flagging that none of this has been
exercised against a real connected account yet — the code is real
and correct against each platform's documented API, but "verified
live" is a distinct, not-yet-true claim this badge deliberately
avoids implying.

0.3.0 — Meta (Instagram, via a linked Facebook Page) and TikTok
added, completing the organic BH_SocialPlatform group. Also adds the
BH_AdsPlatform group (class-ads-platform.php) — deliberately a
SEPARATE interface, not one more BH_SocialPlatform implementation,
since paid ad-campaign management is a genuinely different shape
(budget/targeting/billing, not caption/attachment). Roku Ads Manager
was the platform named in the original scoping conversation; a
follow-up research pass added Spotify Ad Studio (a strong fit — $250
minimum vs Roku's $500, built around exactly this ecosystem's
music-listening audience) plus Amazon DSP/Samsung Ads/Vizio Ads for
completeness, though those three are honestly far less accessible to
an independent artist (see each class's own docblock). None of the
five have a confirmed public self-serve REST API, so each is a
draft-capture-plus-handoff tool (BHSO_RokuAds's docblock explains the
reasoning in full) rather than a simulated live integration.

0.2.0 — Twitch added (chat-announcement cross-post + follower/live
stats via Helix).

0.1.0 — scaffold (2026-08-01): YouTube decided as the first platform
specifically because it has no app-review gate (unlike Meta/TikTok) —
the OAuth app itself is created in Google Cloud Console, but using it
against your OWN channel needs no approval, making it the fastest
real thing to integrate first behind BH_SocialPlatform
(class-social-platform.php) so Twitch/Meta/TikTok are each one more
implementation class, not a rewrite.
