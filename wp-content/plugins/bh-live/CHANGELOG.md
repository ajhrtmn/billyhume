# Changelog — BH Live

Moved out of `bh-live.php` on 2026-08-25 — this plugin never got the same extraction the-self-hosted-self did on 2026-08-23, so its version history sat in source comments until now. See `CONVENTIONS.md` for why version history lives here and in git rather than in source.

Entries are newest-first, exactly as written in-file. Nothing reworded or dropped.

---

0.9.4 — Ecosystem quality Phase 2, brick 6/13: added native return/
parameter types across all 20 includes files (211 findings, both
mechanical level-6 categories) — the largest brick so far. Two real
interfaces (BHL_StreamEngine, BHL_HostProvisioner, BHL_Chat) got
typed method contracts, with every implementing class (Owncast/
Cloudflare engines, Fly provisioner, polling/workers/Owncast chat)
matched to them. One deliberate exception: BHL_FlyProvisioner::
settings()'s return type is `array<string, string>`, not a precise
shape — the-self-hosted-self's class-media-wizard.php also reads this
method's return value with its own `?? ''` fallbacks, and a precise
shape there made those fallbacks flag as "always exists," a
cross-plugin false positive a same-plugin fix can't clean up
without touching the-self-hosted-self (a separate, later brick). Purely
additive typing otherwise, no behavior change.
NOT runtime-verified against a live install.

0.9.3 — This plugin's first PHPStan pass (newly added to
phpstan.neon's scanned paths this round — the-self-hosted-self's own
class-media-wizard.php interaction with this plugin's BHL_* classes
is also now for real type-checked instead of unresolved noise, and
came back clean). Fixed esc_attr() needing a string, not the int $vid
it was given directly (class-admin.php); dropped an unnecessary
accepted_args count on 3 mock closures in class-test-suite.php that
never used their args (same pattern already fixed in bh-registry).
NOT runtime-verified against a live install — confirmed via a real
`vendor/bin/phpstan analyse` run. `php -l` clean.

0.1.0 — scaffold (2026-07-26, wondrous-mixing-forest.md): Owncast
decided for v1 specifically because it bundles chat + a web player +
RTMP ingest in one deployable unit, making it the easiest real thing
to integrate first — behind bh-live's own BHL_StreamEngine interface
(class-stream-engine.php) so a later OvenMediaEngine implementation
is a second class, not a rewrite.

Since grown well beyond that first scaffold, same session: chat IS
now abstracted (class-chat.php's BHL_Chat interface), with three
real implementations — Owncast's own bundled chat, a free polling-
based BHL_PollingChat (matching bh-streaming's own Jam sessions'
proven pattern), and a real-time BHL_WorkersChat via a Cloudflare
Worker + Durable Object — plus BHL_CloudflareStreamEngine as a
second BHL_StreamEngine, and BHL_HostProvisioner/BHL_FlyProvisioner
for deploying the Owncast box directly from the wizard. See
STATUS.md for the full current picture.

A live stream genuinely cannot run on ordinary shared hosting —
real-time RTMP ingest/transcoding needs its own dedicated box. This
plugin is intentionally just the thin WordPress-side integration
layer (read live status, embed the player, manage the connection
settings) — see BHL_OwncastEngine's own docblock for exactly what it
does and doesn't do.
