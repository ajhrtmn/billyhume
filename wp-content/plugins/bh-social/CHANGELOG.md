# Changelog — BH Social

Moved out of `bh-social.php` on 2026-08-25 — this plugin never got the same extraction the-self-hosted-self did on 2026-08-23, so its version history sat in source comments until now. See `CONVENTIONS.md` for why version history lives here and in git rather than in source.

Entries are newest-first, exactly as written in-file. Nothing reworded or dropped.

---

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
