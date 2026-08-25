# Changelog — BH Video

Moved out of `bh-video.php` on 2026-08-25 — this plugin never got the same extraction the-self-hosted-self did on 2026-08-23, so its version history sat in source comments until now. See `CONVENTIONS.md` for why version history lives here and in git rather than in source.

Entries are newest-first, exactly as written in-file. Nothing reworded or dropped.

---

0.4.3 — Real gap found in a functional-depth audit ("does this
plugin actually do the job it's supposed to, not just have the
pieces present"): unlike every sibling plugin with a public
shortcode-driven browse page (bh-registry's Artist Registry,
bh-streaming's own catalog page), this plugin never auto-created a
landing page for [bh_video] on activation — confirmed live, this
install had zero bhv_video posts and no page anywhere referencing
the shortcode, despite the plugin's own description promising "a
standalone video catalog and player... browse/playback SPA." The
SPA and its REST API were both fully real and working, just
undiscoverable. Added BHV_Activator::maybe_create_default_pages(),
same version-gated pattern as BHR_Activator's own (a manually-
trashed page isn't silently recreated), hooked to admin_init.
Verified live: a real "Videos" page (containing [bh_video]) now
exists after visiting any wp-admin screen once.

0.4.2 — Ecosystem quality Phase 2, brick 2/13: added native return
types and parameter types across all 7 includes files (class-
activator, class-admin, class-api, class-chapters, class-post-types,
class-test-suite, class-video-player — 48 findings, all the two
mechanical PHPStan level-6 categories). One small real fix surfaced
along the way: BHV_API::video_url_for() declared a `string` return
but wp_get_attachment_url() can return `false` for a missing
attachment — added a `?: ''` fallback so the declared type is
actually honest, not just asserted. Everything else is purely
additive typing, no behavior change. This plugin is now clean at
PHPStan level 6 in isolation; phpstan.neon itself stays at level 5
ecosystem-wide until all 13 bricks land.
NOT runtime-verified against a live install.

0.4.1 — This plugin's first PHPStan pass (newly added to phpstan.neon's
scanned paths this round). Two findings: esc_attr() needed a string,
not the int $vid it was given directly. Real, if minor, bug in
class-post-types.php: register_taxonomy()'s 'show_in_menu' is a plain
bool for taxonomies (unlike post types, which do support a string
parent-slug) — confirmed by reading wp-admin/menu.php's real
consumption of it directly. Passing self::MENU_PARENT there just
evaluated truthy; changed to `true`, which achieves the exact same
real placement (WP already nests a taxonomy under its associated post
type's own menu automatically), correctly this time.
NOT runtime-verified against a live install — confirmed via a real
`vendor/bin/phpstan analyse` run and by reading WP core source
directly. `php -l` clean.

0.1.0 — scaffold. Video/live-streaming scoping pass (2026-07-26): a
standalone video platform, not tied to a track/release the way
bh-streaming's own bhs_video (class-video-post-types.php) is — that
CPT stays exactly what it always was, a 1:1 promotional-video
wrapper for a release. bh-video is the real catalog: its own
top-level admin menu, its own taxonomy, its own REST-backed
browse/playback SPA, following bhs_track's shape
(bh-streaming/includes/class-post-types.php) as the closest existing
reference.
