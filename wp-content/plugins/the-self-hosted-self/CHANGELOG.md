# Changelog — The Self-Hosted Self (the-self-hosted-self)

Moved out of `the-self-hosted-self.php` on 2026-08-23. That file was 2,555 lines
of which only 115 were code; the rest was this. See `CONVENTIONS.md` for
why version history lives here and in git rather than in source.

Entries are newest-first, exactly as they were written in-file. Nothing
has been reworded or dropped.

---

3.15.10 — Refreshed bundled/bh-courses.zip (0.12.2) and
bundled/bh-contest.zip (3.12.4) after their full-bleed-layout fix, so a
dashboard install ships the centred templates, not the sprawling ones.

3.15.9 — `bundled/*.zip` (the peer-plugin copies the core dashboard
installs from) had drifted badly — bh-courses 0.4.80 vs live 0.12.1,
bh-contest 3.7.28 vs 3.12.3, bh-feedback 0.1.5 vs 0.2.0, and so on for
all 13 — so a one-click install from the dashboard was shipping
months-old code. Rebuilt every one from live source (matching how
deploy-ftp.yml ships a plugin folder).

`OUS_Registry::regenerate_bundled_zip()` also now skips `node_modules/`
and `vendor/` (and keeps skipping `.git/` / `.DS_Store`). It excluded
only OS/VCS clutter before, so running it against a working checkout
that still had a Composer-installed test `vendor/` on disk produced a
1.6 MB bh-monetization-woo bundle full of test-harness dependencies. A
clean FTP-deployed site has none of those dirs, so this changes nothing
there — it just stops a dev-machine regenerate from bloating the bundle.

3.15.8 — Front-end responsive/overlap fixes found doing a full-width
sweep of the ecosystem pages against real content on a staging copy.

- `.ous-back-link` (the shared "← All courses / All contests" affordance
  on bh-courses and bh-contest child pages) is the first thing rendered
  into <main>, so under builder themes that float the site header
  (position:absolute — Etch) it landed under the header, overlapping the
  nav/hamburger. Now clears it via `margin-top: var(--bh-header-clearance,
  72px)` — the same one token bh-courses and bh-contest already read.
- User bar (`class-user-bar.php`): a long "Continue: <course title>" meta
  pushed Account / Log out off the right edge on phones, forcing a
  horizontal scroll of the bar to reach them. The meta is now clamped
  (`max-width: 40vw`, ellipsis) under 480px.

3.15.7 — CRITICAL fix: the block editor's canvas was rendering at a
real, measured 0px height on any post-edit screen with metaboxes tall
enough to fill the available space — confirmed on bh_course, bh_lesson,
AND a stock WooCommerce Product with none of this ecosystem's own JS
involved, so this was never about Lit, the admin skin, or anything
plugin-specific: WP core's resizable "Meta Boxes" panel has no min-
height reserved for the canvas above it (only a min-width on the same
inline style), so it can default to giving metaboxes 100% of the space
and the canvas 0%. The block editor was fully intact and fully
functional the whole time — this is why "building lessons" looked like
a missing feature: the real Lesson: Text/Image/Video/Quiz block editor
was rendering at zero pixels tall, hidden above a "Meta Boxes" bar that
had silently claimed the entire screen. Fixed with one unscoped,
always-loaded rule (OUS_UI::print_block_editor_metabox_fix(), hooked
alongside the existing design-system CSS print) — `.editor-resizable-
editor { min-height: 300px !important; }` — loaded on every admin
screen, not just this ecosystem's own, since the bug isn't ecosystem-
specific. Verified live: collapsing the "Meta Boxes" toggle already
proved the canvas rendered correctly the instant it got any room at
all; after this fix a fresh page load never starts at zero. Confirmed
on Product, bh_course, and bh_lesson edit screens. `php -l`, PHPStan,
and `composer test` all clean.

3.15.6 — Fixed the account-overview "Continue learning" widget
(class-portal.php) linking a student straight into a course whose
tier/purchase access had lapsed since they enrolled — enrollment and
ongoing access are tracked independently (bh-courses' own
BHC_Progress vs BHC_Gate), and this widget only checked the first,
landing a student on that course's own paywall despite framing it as
ready to resume with a real progress percent. Now hides the section
entirely when the most-recent in-progress course isn't currently
accessible, consistent with the widget's own "obvious or gone" rule.
Companion fix in bh-courses 0.8.1 (My Courses panel + the same-bug
user-bar quick link). Verified live as a real enrolled subscriber
fixture. `php -l`, PHPStan, and `composer test` all clean.

3.15.5 — OPEN.md item 8 (unaudited admin screens): ran `tests/ux/
admin.spec.ts` for the first time ever with real credentials — the
`test.skip(!hasCreds, ...)` guard meant it had silently never executed
in any prior session (`WP_ADMIN_USER`/`WP_ADMIN_PASS` had never been
set). Added the remaining named screens from item 8 (bh-registry
Submissions/Peers, bh-streaming Pro Wizard, bh-monetization-woo tier
settings + a real tier's post-edit screen, a real lesson's post-edit
screen for "quiz editor") using a scoped, throwaway administrator test
account, deleted immediately after this pass — never the site owner's
real account.

Two real, previously-invisible bugs surfaced immediately: the
pre-existing 'API Docs' screen entry 403s outright ("Sorry, you are
not allowed to access this page") — but that's not a live bug,
`class-api-docs.php`'s own docblock already documents this exact,
extensively-investigated WordPress page-hook-resolution failure at
length and deliberately never hooks `add_menu()` because of it; removed
the stale test entry pointing at that known-dead URL in favor of the
real, working access point (the API Docs section on 'Debug Tools',
already audited). Separately, the Gutenberg block editor legitimately
hides `#wpadminbar` in its default fullscreen mode — a real WordPress
behavior, not a bug — so the harness's own "are we logged in, not
bounced to login" check was asserting the wrong thing for post-edit
screens; switched to checking for `body.wp-admin` instead, which is
present regardless of fullscreen mode.

With the harness itself fixed, this same first real run surfaced two
genuine AA contrast failures on 'Debug Tools' that had been there the
whole time, at every screen width: `.ous-log-level-pill[data-level=
"warning"]` measured 2.81:1 (needs 4.5) — `--bhy-accent-text` only
ever gets a real value from the currently-deactivated admin skin, so
this always fell through to a `#1e1e1e` fallback that assumed a much
LIGHTER warning fill than what actually renders here; changed the
fallback to `#fff`, matching the same fallback error/info already use
successfully, now 5.93:1. `.ous-log-meta` measured 4.41:1, barely under
AA, against `--bhy-ink-dim`'s shared value — fixed with a LOCAL
override (not a change to the shared token, which passes fine
elsewhere against different backgrounds nobody measured) to `#5a5f66`,
5.15:1.

Also fixed the logged-in FRONT END side of item 7 (not wp-admin): new
`tests/ux/logged-in.spec.ts` authenticates as a real, persistent
subscriber-role test fixture (never the site owner's account — a
subscriber can't reach wp-admin at all, so this project's own "only
admins should ever see wp-admin" concern doesn't apply here) with real
seeded state (enrolled in a course, one lesson step complete). Found
and fixed one more real AA failure this way: the portal's active-nav
tab used raw `--bh-accent` as TEXT color on a dark surface (3.68:1) —
`--bh-accent-contrast` is the wrong token for this (that one is for
ink ON TOP OF an accent-filled background, the reverse situation);
fixed with the same `color-mix(65% accent, 35% text)` blend
`.oust-card-readmore` already uses elsewhere for exactly this
"accent-tinted but still readable" need, now 5.61:1.

All fixes verified against the real local install via the actual
Playwright harness (not reasoned through) before and after.

3.15.4 — Tier 2 item 7 (systematic front-end audit): found and fixed a
real dead link while adding CRM public profiles to the audit's page
list. `BHI_PublicProfile::profile_url()` — the URL bh-crm's own admin
person view links to as "View public profile page" — pointed straight
at `home_url('/')` regardless of whether the home page actually hosted
`[bh_profile]` anywhere. On this install (no static front page
configured, nothing embedding that shortcode) it was a genuinely dead
link: visiting it landed on the ordinary blog index with an unused
`?bh_user=` query string, confirmed live. `OUS_Pages` already exists
for exactly this — bh-contest/bh-courses/bh-monetization-woo each grew
their own private "find or create the page hosting my shortcode"
before it consolidated that pattern — this class just never got the
same treatment. Now calls `OUS_Pages::ensure('bh_profile',
'bhi_profile_page_id', 'Profile')`, adopting a hand-authored page if
one exists or creating a real one if not, same as every other
consumer.

Verified end-to-end against the real local install: deleted the
option to simulate a fresh install, called `profile_url()`, confirmed
a real "Profile" page was created hosting `[bh_profile]`, confirmed a
second call adopts the same page rather than creating a duplicate, and
loaded the resulting URL in a real browser — a genuine profile page
renders (avatar, achievements, activity), not a blank blog index.

Auditing that new page surfaced a second real bug: a long achievement
title (e.g. "Completed with Distinction: Mixing Basics for Bedroom
Producers") pushed the whole page into horizontal scroll at 375px
(confirmed: `html` scrollWidth 384 > innerWidth 375). Root cause,
found by walking the actual flex ancestor chain in a real browser, not
guessed: `.bhi-profile__header` is `flex-direction:column` with
`align-items:center` (not `stretch`), so its child
`.bhi-profile__badges` sizes to its own content's natural width and is
then centered — nothing capped it at the parent's real width, so one
long badge's `nowrap` text grew the whole badges row to 392px inside a
343px parent. A first attempt fixing only `.bhi-badge` itself
(`max-width:100%` + ellipsis) did nothing, because `100%` was
resolving against that already-oversized container — real lesson
worth keeping: `max-width:100%` on a flex item is only as good as its
own containing block's width, and a `flex-direction:column` container
with `align-items:center` won't constrain that for you. Fixed at the
actual source (`.bhi-profile__badges` itself), plus kept the
`.bhi-badge` ellipsis treatment as a second layer so an individual
badge that's still too long for its row truncates gracefully rather
than wrapping into a multi-line blob inside a rounded pill.

Also fixed a related false-positive in this session's own audit
tooling (`tests/ux/audit.ts`): its touch-target check only ever read
`getBoundingClientRect()` on the real element, with no way to see an
invisible `::before`/`::after` hit-area extension — exactly the
technique this codebase's own `.oust-site-brand`/`.oust-card-readmore`
already use (see 3.15.3). Every page audited was reporting those two
as failing 44px minimums that a real browser measurement had already
confirmed pass. Now checks computed pseudo-element size too.

Two new pages added to `tests/ux/public.spec.ts`'s audited list this
pass (streaming library, and the CRM public profile above); all 20
audited pages pass with the corrected tooling.

3.15.3 — Closed OPEN.md's remaining "found by tooling, not yet fixed"
items. "The Door — Day" theme preset's `color_accent` was still
`#C1503A` (measured 3.92:1 on its `#F4E9DC` background, 3.48:1 on
`#EADCC8` surface — both fail AA's 4.5:1 text minimum); Night's own
earlier fix (lightening to `#C85C48`) doesn't apply here since Day's
ground is already light and lightening further would make it worse.
Changed to `#A83D1A` instead — same warm terracotta hue, deepened, not
hue-shifted — measured via the same WCAG relative-luminance math this
class already uses elsewhere: 5.24:1 on background, 4.65:1 on surface,
both real AA passes. The live site is currently on "The Door — Night"
(confirmed by reading the actual `bhy_style_settings` option), so this
is a preset-catalog fix for whenever Day gets selected, not an
immediate visual change to the live site today.

Also fixed the three still-open touch targets under 44px, all
confirmed via direct browser measurement (`getBoundingClientRect()`),
not assumed: `.oust-nav-toggle` (40x40, a real square icon button) grown
directly to 44x44; `.oust-site-brand` (202x30) and `.oust-card-readmore`
(61x18) — both real independently-focusable links, not decorative
riders on a larger click area — given an invisible `::after` hit-area
extension to 44px tall instead of visible padding, since padding would
have grown the header/footer rows they sit in and shifted their
siblings. Verified live in a browser: all three now measure a true
44px (or taller) hit area with zero visible layout change.

3.15.2 — Companion wizard changes for bh-live 0.9.6's continued live-
robustness pass: `class-media-wizard.php` now shows an "orphaned
deployment" notice with a removal button whenever a Workers Chat
deployment exists but isn't the site's current active chat engine
(previously invisible the moment you switched away), and gained a
standalone "Remove live input" action for Cloudflare Stream Live to
match the "Create a new live input" one that already existed.

3.15.1 — Small companion fix to bh-live 0.9.5's `BHL_FlyProvisioner`
robustness pass: `class-media-wizard.php`'s provisioner section used to
silently render nothing at all when `get_status()` failed on the
currently-stored machine (most commonly because it had been deleted
directly via Fly's own dashboard, outside this plugin) — an admin saw a
bare "Destroy machine" button with zero context. Now shows the actual
error message, and points out that "Destroy machine" will correctly
clear the stale reference either way (bh-live's own fix makes a 404
there a success, not another dead end).

3.15.0 — Live-robustness pass: `OUS_GithubUpdates::update()` gained a
manual pre-overwrite backup/restore, closing a real gap found by
reading WP core's own upgrader source directly rather than assuming.
This class's self-update mechanism calls `Plugin_Upgrader::install()`
(needed for its `overwrite_package` support against an arbitrary local
zip), NOT `Plugin_Upgrader::upgrade()` — and `install()`'s own
`hook_extra` never includes the `temp_backup` key WP core's real
automatic-rollback-on-failure safety net (added 6.3.0) checks for.
Confirmed by reading `class-plugin-upgrader.php::install()` directly:
its hook_extra is only `['type', 'action']`. Meaning a corrupted
download, or a mid-extraction disk-full/permissions failure, would
leave the live site with a half-deleted, broken plugin/theme and no
way back except a manual FTP restore — for a mechanism whose entire
purpose is being safe enough to click without SSH access.

Fixed by manually copying the live plugin/theme directory to a temp
location (via WP core's own `copy_dir()`) immediately before the
overwrite, then restoring it if the install fails. Also fixed a related
PHPStan-stub gap while verifying this: `copy_dir()` is typed as
returning plain `bool` in `php-stubs/wordpress-stubs`, but reading the
real core function shows it returns a real `WP_Error` on several
failure paths (`dirlist_failed_copy_dir`, `mkdir_destination_failed_
copy_dir`, `copy_failed_copy_dir`, `mkdir_failed_copy_dir`) — an
incomplete stub, not a bug in the code checking for it; added a scoped
`phpstan.neon` ignore with the full reasoning rather than removing the
correct defensive check.

Verified the exact backup/restore mechanism end-to-end against the
real local WP+MySQL install (via `copy_dir()` against a throwaway test
directory, not a real plugin — this class's actual `update()` wasn't
exercised against a live GitHub repo in this pass, which would have
genuinely overwritten a real plugin on this install): backed up a
directory, simulated a failed install by wiping it, restored from the
backup, confirmed the restored content matched the original exactly.

3.14.0 — OPEN.md item 15: per-card GitHub status, and the convention
that formalizes it. Ecosystem dashboard cards now show a live status
line under the version (reading OUS_GithubUpdates' own cached
check-status option, not a per-card live API call -- see
render_github_status()'s own comment for why): "Update available
(x.y.z)", "up to date", "check failed", or "not checked yet" right
after activation. Verified all four states render correctly by
seeding the option directly. Also: CONVENTIONS.md now states the
"every new plugin gets a card" rule as a first-build-step convention,
not just an idea -- this has already been missed twice for real
(bh-streaming, self-hosted-self-admin-skin both shipped a full session
before someone noticed).

3.13.1 — Front-end audit continued to the pages item 7 still missed
(contest single, streaming track, checkout, my-account, and the
portal's logged-out /account/ page — CRM's own public-facing surface
turned out to be a logged-in portal panel, not a standalone public
page, so /account/ is the real public entry point that exercises it).

One real finding: .bhi-login-submit (class-portal.php) hardcoded
`color:#fff` on the accent-fill button background — same class of bug
as 3.13.0's --bh-accent-contrast fix, just a literal instead of a
fallback this time (4.13:1, needs 4.5). Now uses
var(--bh-accent-contrast, #fff), the token built specifically for this
job. Clean across all 18 audited pages after.

3.13.0 — First real ecosystem-wide front-end audit (OPEN.md item 7,
"Never done"), and the defects it found. The audit itself only covered
4 generic theme pages before this; expanded to 13 including the
ecosystem's own surfaces (courses catalog/single/long-title, gated
lesson, shop, product, cart, blog post, search results), paths verified
against real published content rather than assumed.

Three real defects, all fixed:

1. **`--bh-accent-contrast` was consumed in 15 places and DEFINED
   NOWHERE.** Every use fell through to its own hardcoded fallback, and
   the fallbacks disagreed -- 11 saying dark (#150705), 4 saying white
   (#fff). Both cannot be right on the same fill. Now derived in
   inline_css() by measuring which of the theme's own ink colours has
   more real WCAG contrast against the CHOSEN accent, so it stays
   correct for any accent an admin picks. Exactly the lesson the admin
   skin already learned with --shsas-accent-text (its token bridge
   mapped every FILL colour but never the foreground meant to sit on
   one).
2. **`color_accent` #C1503A -> #C85C48** (~4% lighter, same hue), AJ's
   call, same precedent as --shsas-neon-cyan on 2026-08-23: move the
   token rather than patch around it. Fixed .oust-btn-primary (4.20:1)
   and bh-courses' .bhc-archive-kicker (4.17:1), both needing 4.5. The
   button failure had been sitting in OPEN.md unfixed since the tooling
   adoption pass.
3. Two audit FALSE POSITIVES calibrated rather than "fixed" in the CSS
   -- deliberate `-webkit-line-clamp` truncation (bh-courses' .bhc-excerpt)
   and image-replacement text (WP core's own login logo) both read as
   sheared content. Calibrating the checker instead of the design is the
   same discipline TOOLING-EVALUATION.md already records for
   check-accent-on-tint.

Result: zero contrast/clipping/overflow findings across 13 pages x 6
widths, verified by deliberately reverting the accent and watching 166
failures return, then restoring.

**Still open, deliberately not fixed here:** the light "The Door — Day"
theme's accent already fails (3.92:1 as text on its own ground, 3.48:1
on surface) and always did -- the audit only exercises the dark theme,
so it never surfaced. Lightening helps dark grounds and HURTS light
ones, so Day needs its own DARKER accent, which is a separate call.
Recorded in OPEN.md rather than guessed at.

3.12.0 (follow-up fix) — BH_Storybook_Panel::redirect()'s exit() call
declared `never`, not `void`, found by finally running the whole
project through PHPStan level 6 rather than only php -l: without it,
PHPStan couldn't see that the OUS_GITHUB_ACTIONS_TOKEN definedness
guard in handle_gha_trigger() actually halts execution, and flagged
the constant's later use as possibly undefined. Confirmed clean by
rerunning the whole suite, not just the one file.

3.12.0 — Design Suite gets the real Storybook integration
(BH_Storybook_Panel): a "Rebuild Storybook" / "Run UX audit" panel that
shell_execs locally (hard-locked to non-production environments via
OUS_Debug::is_locked(), the same rule every other "does real work"
Debug Tools section follows), plus a "Trigger via GitHub Actions"
button for running the same two actions from anywhere, including
production, via a new storybook-audit.yml workflow. Verified end-to-end
through the real admin form, not just reasoned through — see this
session's own record for the three-pass debugging story getting
shell_exec to actually find npm from PHP-FPM's own stripped-down PATH.

---


3.10.42 — Real contrast bug on Debug Tools, found by the first
systematic multi-width/both-theme measured audit of this screen
(computed styles, not screenshots): the sticky "jump to" quicknav
carries a hardcoded inline `background:#fff` plus a #dcdcde border,
and its group labels/section headings a hardcoded #646970 — markup
that predates the --bhy-* token system and was never swept. In dark
mode that renders a genuinely white bar across the top of the page
with this ecosystem's periwinkle link colour on it: measured 2.39:1
across all 40 of its links, against a page whose actual surface is
#1e1b15. It survived earlier passes because it is the one element
on the screen that is inline-styled rather than stylesheet-styled,
so nothing in the admin skin could reach it — a stylesheet override
there would have had nothing to override.

Fixed at the source rather than in the skin, following 3.10.32's
precedent exactly: the literals become var(--bhy-surface, #fff) /
var(--bhy-border, #dcdcde) / var(--bhy-ink-dim, #646970), keeping
the original value as the fallback so a bare WordPress install with
no skin active renders precisely as before, while the admin skin's
existing token bridge (confirmed live: --bhy-surface resolves to
#1e1b15, --bhy-border to #45402f, --bhy-ink-dim to #b3ab97 in dark)
now reaches it for free. Deliberately NOT adding a matching
override to the skin — two places setting the same colour is the
exact inconsistency 3.10.32 removed.

Verified live on localhost, logged in, both themes at 1440 and 375:
the quicknav resolves to the themed surface and all 40 links clear
AA. NOT verified against the live wasmer.app build.

3.10.41 — Direct request: "Make sure no latent 'own ur shit's exist,
like in urls, fold it in." The plugin's internal codename was
genuinely visible to every admin, every time, in the browser address
bar — the top-level admin menu slug was the literal string
'the-self-hosted-self' (admin.php?page=the-self-hosted-self), and every one of its 11
submenus (Reports, Media & CDN Setup, Metrics, Security, DMCA Agent/
Notices, Roles, Campaigns, Guided Setup, Portal Layout, plus the
dashboard itself) was parented under that exact same slug via
add_submenu_page('the-self-hosted-self', ...). Renamed the slug to 'ous' —
matching the short-code convention every submenu's own slug already
uses (ous-metrics, ous-security, etc.), so this isn't a new
convention, just closing the one place the ecosystem's public-facing
name ("The Self-Hosted Self") and internal short-code both existed
while the crude working codename alone leaked into the URL bar.
Touched: class-dashboard.php (the add_menu_page()/add_submenu_page()
registration itself, plus the enqueue_assets() hook-suffix check),
class-banner.php (screen-id check), class-menu-merge.php (default
parent for every OTHER plugin's admin_menus entries — bh-registry,
bh-tickets, bh-crm, etc. all inherit this automatically, no changes
needed in those plugins themselves), and every file with its own
add_submenu_page('the-self-hosted-self', ...) call (class-campaigns,
class-dmca, class-dmca-notices, class-media-wizard, class-metrics,
class-portal-layout, class-reports, class-role-assignment,
class-setup-wizard, class-two-factor). Text-domain strings
(__('...', 'the-self-hosted-self')) and the plugin folder name itself were
deliberately left untouched — the text domain isn't URL-visible, and
renaming the folder is materially riskier (it's how WordPress tracks
"which plugin is this" — a live production deploy on
billyhume.wasmer.app makes that not worth the risk for a URL-only
concern). Verified live: the dashboard and every submenu (13 items,
spot-checked via a real DOM read of the rendered sidebar) resolve
correctly under the new admin.php?page=ous URL and its ous-*
children; old the-self-hosted-self URLs simply no longer exist (no redirect
needed — nothing bookmarks these, they're wp-admin-only).

3.10.40 — Two real, live-caught bugs in the front-end portal shell
(class-portal.php), both direct field reports while verifying the
bh-tickets functional-depth audit's new-ticket-notification fix
live in the portal.
  1. "The styling on forms and such is bad": .bhi-portal-main (the
     wrapper around every peer plugin's actual panel content — bh-
     tickets' New Ticket form is what surfaced this, but it reaches
     any panel using plain HTML form elements) never had ANY generic
     input/textarea/select/button styling. Only the LOGIN page's own
     .bhi-login-field input had a themed treatment; the portal
     SHELL's actual panel content fell through to unstyled native
     browser chrome — a stark white box on this theme's dark
     surface, exactly the "orphaned white box" failure mode this
     whole ecosystem has been sweeping for, just never reached here
     before since this portal-shell CSS predates that sweep. Added a
     real .bhi-portal-main-scoped block covering every standard
     input type, textarea, select, and .button/button[type=submit],
     reusing the same --bh-* tokens and visual language the login
     page's own fields already established.
  2. "The menus selected active contrast is ugly": .bhi-portal-nav
     a.is-active used var(--bh-accent-soft, ...) as a flat
     background fill — but --bh-accent-soft (#E0A184 on the live
     warm-noir theme) is actually a solid, fairly saturated color
     despite its name, not a real soft tint, so the active nav item
     rendered as a jarring coral block. This directly violates this
     project's own standing design brief (smokey-grey-noir as the
     rule, neon/accent color as a restrained afterthought/glow, never
     a flat saturated block) — confirmed via computed style before
     fixing (bg rgb(224,161,132), a real solid fill, not a tint).
     Switched to var(--bh-accent-muted-bg, ...) — the same low-alpha
     translucent token theme.css already uses elsewhere for exactly
     this "accent as a glow, not a block" treatment — with an
     accent-colored left border and text instead of a filled
     background, matching the restrained active-state language the
     wp-admin sidebar's own per-item wayfinding highlight already
     uses.
Verified live: bh-tickets' New Ticket form now themed correctly (dark
surface, themed borders/focus state, styled Submit button); the
Overview panel's "Continue →"/"View submissions →" buttons (same
.button class, previously also unstyled) now render correctly too;
active nav state now a subtle accent glow on both Overview and
Support panels, no regression. Full contrast sweep on both pages:
zero findings.

3.10.39 — Two real bugs found in class-share-card.php
(BH_ShareCard, the shared social-share-image generator behind both
bh-courses' course-completion card and bh-contest's submission
cards), both surfaced by finally activating every installed plugin
locally rather than testing against a partial subset — Advanced
Media Offloader activation for the previous version's fix turned up
the second gap below purely as a side effect of poking at a nearby
live share-card URL.
  1. Direct field report: a broken-image icon in place of the share
     card on the deployed environment, not reproducible on this
     local install. Root cause: output_png() called generate()
     (which unconditionally calls imagettftext()/imagettfbbox())
     BEFORE sending the image/png header — on any host whose GD
     build lacks FreeType support (common on locked-down shared
     hosting; confirmed this local install's own GD build DOES have
     it, which is exactly why it never reproduced here), that call
     either fatals or emits a warning into the response body,
     corrupting the PNG bytes the browser then fails to decode.
     Added a gd_capable() guard (checks imagettftext/imagettfbbox
     exist, gd_info()'s 'FreeType Support', and the two vendored
     font files are readable) plus a try/catch safety net around
     generate() itself, both falling back to a new render_fallback()
     — a real, valid, still-on-brand PNG using only
     imagefilledrectangle() (no text, so nothing in the fallback
     path can hit the same FreeType-dependent call), instead of a
     broken response.
  2. Found live while verifying fix #1 (screenshotting a real
     generated card to confirm the normal path was unchanged): every
     card style's bottom-corner wordmark was hardcoded to 'OWN UR
     SHIT' — this ecosystem's own pre-rebrand software name, not the
     artist's actual site brand the surrounding comment says it's
     meant to identify ("identifies which site/brand this came from
     once it's out in a social feed on its own"). Every share card
     generated since the rebrand has been silently advertising the
     wrong name. Added site_mark() (reads get_bloginfo('name'),
     uppercased) and replaced all 4 hardcoded instances.
Verified live: gd_capable()'s normal-path behavior confirmed
unchanged (a real completed course's share card still renders
correctly, screenshotted); the wordmark fix confirmed via the same
live screenshot, now reading "BILLY HUME BLANK TEST" instead of "OWN
UR SHIT". The FreeType-missing fallback path itself could not be
exercised live (this install's GD has FreeType) — logic reviewed
carefully instead (gd_capable() returning false is the only way
into render_fallback(), which uses no TTF-dependent calls at all).

3.10.38 — Real gap, direct field report: self-hosted-self-admin-skin
was never registered in OUS_Registry at all, despite being ours to
author/bundle exactly like every peer plugin already listed there —
invisible on the ecosystem dashboard's "Install & Activate
Everything" list and its own plugin card, and (since GitHub Updates'
load_sources() auto-derives its own source list from any entry here
with a 'bundled_zip') never checked for updates either. Added the
registry entry (no 'check_class' — this plugin is deliberately
plain procedural PHP, matching the existing Advanced Media Offloader
precedent for "no check_class needed") and generated its own bundled
zip (previously didn't exist). Verified live: now shows Active on
the ecosystem dashboard and appears in the GitHub Updates table.

3.10.37 — Real bug, direct field report: "Check now button not
working on dev." Root cause, found by actually reproducing it live
rather than guessing: check_all() ran up to ~13 sequential
wp_remote_get() calls (10s timeout each) inside ONE queued job — on
a real deployed host (as opposed to this local dev environment) a
few slow responses can exceed PHP's max_execution_time and get the
whole job silently killed mid-loop. A hard execution-time kill isn't
a catchable PHP exception, so run_one()'s own try/catch never sees
it, nothing gets logged as failed anywhere, and the job's row is
just left orphaned — exactly matching the reported symptom (every
row stuck on "not checked yet" with zero visible error). Fixed two
separate things:
  1. Fanned out to one independent queued job per source instead of
     one job looping all of them, so a slow/stuck source can't block
     or corrupt the others' state, and whatever doesn't finish in
     one pass genuinely gets retried on the next cron tick or button
     click instead of being silently stuck forever.
  2. A real UX gap on top of the reliability bug: "Check now" only
     ever QUEUED its job and told the user, in prose, to go click a
     SEPARATE "Run due jobs now" button elsewhere on the page. Now
     auto-submits that exact existing button as a real second
     request the instant the page reloads, so it feels like one
     click — without recombining the two calls into one request,
     which is exactly the site-breaking synchronous-timeout bug this
     async design was already built to fix once before (see this
     file's own earlier changelog: "Check now ran synchronously,
     timed out the whole site").
Verified live: before this fix, one "Check now" click left 10 of 13
sources stuck on "not checked yet" with zero errors logged; after,
all 13 completed in a single pass.

3.10.36 — A SIXTH hardcoded-styling instance, found by the admin
skin's continuing contrast sweep: OUS_Revisions' own version-history
card (a genuinely shared service — CRM, Courses, anywhere else with
object history) rendered with a raw inline style="...background:#fff"
and zero token backing. The bare "Version #N" line had no color of
its own at all, measuring 1.17 contrast once the card's own solid-
white background sat against the admin skin's dark theme. Fixed by
reusing the existing .bhy-card class (already covered by admin-
skin.css's own class-based !important overrides) instead of adding
var(--bhy-*)-backed inline styles — those custom properties are only
ever defined with light-mode literals in class-ui.php; admin-skin.css
themes shared cards by CLASS NAME, not by redefining the tokens
themselves, so a plain var() fallback here would have stayed
light-mode-only regardless of theme.

3.10.35 — Real follow-up bug in 3.10.33's own log-level pill fix,
found by the admin skin's systematic reconciliation sweep: the pill
used one hardcoded white text color (var(--bhy-danger-contrast,
#fff) — a token that was never actually defined anywhere in
class-ui.php, so it always fell through to its own #fff default) for
all three levels. White reads fine against the danger (red) and info
(blue) background colors, but the admin skin's own --bhy-warning
bridges to a bright neon yellow (#f5d90a) — white-on-yellow measured
roughly 1.4:1, functionally invisible. Each level now sets its own
paired text color (--ous-log-level-text) alongside its own
background color, rather than one shared assumption that never held
for all three.

3.10.34 — Task #8 (in-context tooltips), judicious pass: added a
real BHY_UI::tip() to Guided Setup's "Wordmark" field (step 3, Brand
basics) — real graphic-design jargon on the very first onboarding
screen a brand-new operator sees, for an ecosystem whose actual
target user is an independent musician, not a designer. Also
explains the unexplained two-input split (first part / accent part)
so it reads as an intentional two-tone brand mechanism rather than a
confusing duplicate field.

3.10.33 — The FIFTH hardcoded-styling mechanism, found by grepping
admin-facing PHP for literal `color:#`/`background:#` inside echoed
markup after the .ous-card/.ous-metrics-card token migration
(3.10.32) closed the first four. class-debug-log.php's Console &
Logs table was styling log-level pills, the file:line pointer, the
request-ID chip, and the expandable detail row with literal INLINE
style="" attributes (color:#d63638, background:#f0f0f1, etc.) — a
genuinely worse case than a hardcoded stylesheet, since an inline
style beats any external CSS rule outright short of !important. No
theme, including the admin skin, could have restyled this screen
without injecting its own inline styles back in via JS.

Fixed with real CSS classes (print_log_styles_once(), same
print-once pattern as this file's own copy/expand <script> helpers)
reading --bhy-* tokens with the previous literals kept as var()
fallbacks. The level pill's color is now driven by a
data-level="error|warning|info" attribute + three tiny selector
rules rather than a per-row inline background.

One real bug caught by checking computed style rather than assuming
the class alone would work: the request-ID chip is an <a>, and the
admin skin's global `body.wp-admin a { color: var(--shsas-accent); }`
rule (3 simple selectors) beat a bare single-class `.ous-log-req-chip`
rule (2 simple selectors) on specificity, so the chip rendered full
link-blue instead of the quiet muted tone intended for a small
metadata affordance. Fixed by doubling the class in the selector
(`.ous-log-req-chip.ous-log-req-chip`) to raise specificity past the
skin's rule, rather than reaching for !important. Verified live via
getComputedStyle on the real rendered rows after the fix: pill
resolves to the accent token, chip to ink-dim, "ok" text to the
success token — all through the bridge, not a hardcoded literal.

3.10.32 — Code-quality pass, not a visual change: migrated this
plugin's two remaining hardcoded-CSS surfaces onto the shared
--bhy-* token system (BHY_UI::print_design_system_css()).
assets/css/admin.css (.ous-card and the status/maturity badges) and
class-metrics.php's inline <style> block both predated that system
and hardcoded WP core's default light palette (#fff, #dcdcde,
#646970, #1d2327) with no custom property anywhere — which is
exactly why the admin-skin plugin had to override those class names
one by one from the outside in order to theme them. Every value now
reads a token with its previous literal kept as the var() fallback,
so a stock install renders identically while any consumer that
redefines the tokens re-themes these screens for free.

Also: OUS_Metrics::sparkline_svg()'s $color default is now
'var(--bhy-accent, #2271b1)' rather than the bare literal. An SVG
stroke attribute accepts a var() reference like any CSS value, and
esc_attr() passes it through unchanged (verified) — confirmed live
that the polyline's computed stroke now resolves through the token
rather than needing the skin's own !important override.

Net effect beyond tidiness: the admin skin deleted a whole block of
now-dead colour overrides in the same pass, so these colours are
defined in ONE place instead of two. Two places setting the same
colour is precisely how the original inconsistency arose. Real
behaviour check done live on both screens (Metrics + the ecosystem
dashboard) before and after: pixel-identical, and the "Installed,
inactive" badges actually IMPROVED — they now pick up the real
--bhy-warning amber instead of a one-off cream literal.

3.10.31 — Front-end portal icons: dashicons -> Lucide, completing
the "replace all stock icons everywhere" sweep across the third and
last surface (wp-admin sidebar and admin bar were done in the admin
skin). 20 Lucide icons vendored to assets/icons/ (ISC, LICENSE
included), applied as CSS masks in class-portal.php's own front-end
style block.

Deliberately CSS-only rather than a PHP rewrite across nine plugins:
every portal panel (this plugin plus bh-contest, bh-courses,
bh-feedback, bh-monetization-woo, bh-tickets, bh-registry,
bh-streaming) registers its icon through the SAME shared
'icon' => 'dashicons-NAME' panel key and renders it as
<span class="dashicons dashicons-NAME">. That shared convention
means one mask rule per name re-skins every plugin at once, with
zero peer-plugin PHP changes and no new cross-plugin coupling —
exactly what the ecosystem's "peers depend only on the core" rule is
for. A peer registering some other dashicon keeps its font glyph
(graceful, not broken) until a mask is added here.

One real bug caught while writing it, by working out specificity
rather than trusting source order: the default size started life in
a trailing `.bhi-portal .dashicons:not([style*="width"])` catch-all
at (0,3,0), which would have silently BEATEN the three
context-specific size rules at (0,2,0) and collapsed the wallet
chip / achievement badge / empty-state icons to the wrong size.
Default moved into the base rule; the three specific rules prefixed
with .bhi-portal to tie at (0,3,0) and win on order. Verified live:
9 icons on the portal, all masked, wallet chip correctly 16px while
the rest hold 20px.

3.10.30 — One more font correction, direct feedback: "I want less
kitschy fonts and still more diversity." Josefin Sans (3.10.29) is
real period reasoning but its tall, idiosyncratic proportions still
read as costume-y at display sizes. Swapped display font to Jost —
same 1920s German geometric-sans lineage (Erbar/Kabel-influenced,
the same family Futura itself grew from) but genuinely restrained,
professional proportions. Added to BHY_Style::FONT_OPTIONS and set
as the new font_display default; Josefin Sans and Righteous both
stay in the option list, neither removed, just no longer default.
font_body (Atkinson Hyperlegible) unchanged.

3.10.29 — Direct correction on 3.10.27's font choice: "I don't want
a cutesy version of Streamline Moderne... think like the great
designers of the time would when approaching problem solving and
design sensibility, just with modern wisdom of how people use
things." Righteous (a 1970s-80s bubble-letter novelty face) was the
wrong era AND the wrong sensibility for that brief — decoration
wearing a retro label, not real period design reasoning. Replaced
the ecosystem-wide font_display default with Josefin Sans, which is
explicitly modeled on Rudolf Koch's Kabel (1927) and Paul Renner's
Futura (1927) — genuine 1920s-30s geometric-sans construction, not a
pastiche of it — while staying legible at real UI sizes a 1927
display face wouldn't necessarily have been designed for (the
"modern wisdom" half of the brief). Both Righteous and the earlier
Space Grotesk stay in FONT_OPTIONS as real, selectable alternatives.
font_body (Atkinson Hyperlegible) is unchanged — that choice was
never about the retro aesthetic, it was a straight accessibility
pick, and stands on its own reasoning either way.

3.10.28 — Real, site-breaking bug caught live while auditing
WooCommerce's Orders screen for the admin-skin design pass: opening
ANY single order (the "Edit" action on the Orders list, or
admin.php?page=wc-orders&action=edit) fataled with "Argument #2
($post) must be of type WP_Post,
Automattic\WooCommerce\Admin\Overrides\Order given" —
OUS_PageSurface::add_meta_boxes() (class-page-surface.php) was typed
to require a real \WP_Post, but WordPress core's own add_meta_boxes
action legitimately fires with WHATEVER object a given admin screen
hooks it with — WooCommerce's HPOS order screens pass their own
Order object, not a WP_Post, and always have (this is documented
core behavior, not a WooCommerce bug). The old strict type hint
crashed before the method's own post-type filter even got to run,
since PHP validates a class-typed parameter before executing the
function body. Loosened to `object` + a real instanceof \WP_Post
guard as the first line of the method — every legitimate post-edit
screen this was built for is completely unaffected, every screen
that fires this hook with something else (WooCommerce orders, and
potentially other plugins/screens this ecosystem hasn't hit yet)
now degrades to a no-op instead of a fatal error. Confirmed live:
the single-order screen now loads instead of showing a raw PHP
error stack.

3.10.27 — Direct design-direction request (Streamline Moderne
architecture + Googie neon, applied ecosystem-wide): added Righteous
(a genuine 1970s-80s retro-futurist display face) and Atkinson
Hyperlegible (Braille Institute, SIL OFL, purpose-built for low-
vision legibility) to BHY_Style::FONT_OPTIONS and set them as the new
site-wide font_display/font_body defaults (class-style.php) — applied
live via the Design Suite and confirmed saved (was Space Grotesk /
Inter, both kept as selectable alternatives, not removed).

Fixing that surfaced a real, pre-existing gap, not something this
change introduced: BHY_Style::google_fonts_url() — the thing that
actually fetches the webfont FILE a --bh-font-* variable references —
was only ever called from class-public-profile.php, never hooked
globally, even though the CSS variables THEMSELVES were already
correctly printed site-wide via print_global_css()'s existing wp_head
hook. Every ordinary front-end page (homepage, any post) had
--bh-font-display pointing at a font that was never actually
fetched, silently falling back to the browser default — invisible
with a subtle font choice, glaring the moment a distinctive display
face was set as the real default (confirmed live: the homepage h1
reported computed font-family "Righteous, sans-serif" while visually
still rendering plain sans-serif, and zero fonts.googleapis.com
network requests fired on that page at all). Fixed by echoing the
same google_fonts_url() output as a <link> inside print_global_css()
itself, right alongside the CSS variables it's needed for — one
mechanism, one hook, matches the pattern this method's own doc
comment already established for the token-availability fix.
NOT yet verified on every front-end page template (only the homepage
and Design Suite's own live preview so far) or against the block-
editor iframe's separate font-loading path — worth a wider check.

3.10.26 — Display-only rebrand: "Own Ur Shit" -> "The Self-Hosted
Self" everywhere a user or admin actually sees the name (Plugin Name
header — cascades automatically to every peer plugin's "Requires:"
line in the Plugins list, since that's rendered from this plugin's
own Name header, not a hardcoded string — the shared ecosystem
banner in class-banner.php, the theme's own Theme Name header, the
Element Prefab block's title). Deliberately NOT a technical rename —
class prefixes (OUS_*), file/folder names, text domains, DB option
keys, and the git repo name are all unchanged; only what's actually
displayed changed. Local-only change, not yet deployed.

3.10.25 — Real, site-breaking bug caught live on the very first
click of 3.10.24's new "Check now" button: OUS_GithubUpdates::
handle_check_now() called self::check_all() SYNCHRONOUSLY, inline,
in the admin request — up to 13 sequential wp_remote_get() calls (10s
timeout each) against every registered plugin/theme source. This
install's Wasmer hosting enforces a hard request-timeout, and the
click reliably took the ENTIRE SITE down with a critical error, not
just the one admin screen — confirmed by the site staying down for
every request (including a fresh, cookie-less tab) until the
timed-out request was killed server-side.

Fixed by routing through OUS_Jobs instead — handle_check_now() now
enqueues the class's own existing run_check() job for immediate
background processing (OUS_Jobs::enqueue(self::JOB_HOOK, [], 0))
rather than running the network-heavy work inline, matching this
ecosystem's own standing convention that anything talking to a
remote resource goes through the job queue, not a blocking request.
Debug Tools' own "Run due jobs now" button (Job Queue section)
processes it immediately instead of waiting for the next real cron
tick. Falls back to the old inline check_all() only if OUS_Jobs
somehow isn't active, with an explanatory comment on why that path
is still a real (smaller) risk rather than silently pretending it's
safe.

php -l clean, scoped PHPStan level 6 clean. NOT runtime-verified
against a live install by this commit alone — the bug it fixes WAS
runtime-verified (it broke the live site), but this fix itself needs
a real click-through to confirm the site survives "Check now" now.

3.10.24 — Two real gaps found live minutes after 3.10.23 (which
wired the companion theme into OUS_GithubUpdates) actually deployed:
(1) a genuine chicken-and-egg deadlock — the theme's own
registration (the-self-hosted-self-theme/functions.php, the documented
decentralized "any theme opts in" pattern) can't take effect until
the theme's code has already deployed successfully, which is
precisely the problem this mechanism exists to solve for a host
(like this install's Wasmer hosting) whose git-deploy only syncs
wp-content/plugins/. OUS_GithubUpdates::load_sources() now also
registers this ecosystem's own named companion theme directly, from
a plugin guaranteed to deploy — breaks the deadlock; harmless once
the theme's own copy also runs (register() overwrites, not
duplicates). Scoped to this one specific theme by name, not a
general "plugins know about themes" precedent.

(2) The debug section's own copy admitted outright: "a manual
'check now' pass isn't wired up yet" — the daily scheduled job was
the only way the status table ever refreshed, so anything just
registered (like the theme moments ago) sat at "Not checked yet"
with no path to an "Update now" button short of a real cron tick up
to 24h later. New handle_check_now() (admin_post_ous_github_check_
now) + a real "Check now" button run check_all() synchronously on
click — cheap (raw-file fetches, no zip downloads), fine to run
on-demand.

php -l clean, scoped PHPStan level 6 clean. NOT runtime-verified
against a live install by this commit alone — verify by clicking
"Check now" on Debug Tools -> GitHub Updates and confirming the
theme row populates with a real remote version instead of staying
at "Not checked yet".

3.10.23 — Real UX gap found live: clicking a menu group's own label
(e.g. "Courses", not one of its child items) went nowhere — every
group parent OUS_MenuSync::sync_group() creates was hardcoded to
menu-item-url '#'. sync_group() now takes an optional 4th arg,
$group_url, threaded through both sync_classic_menus() and
sync_block_navigations() as the parent item's real link — defaults
to '#' (unchanged behavior) when a caller has no real destination to
offer. bh-courses (0.4.80, same release) passes its native
/courses/ archive; bh-contest (3.7.28) looks for a real page using
[bh_archive] and links there if one exists.

Also: the seeded Account/Log-In link's on-page "Log In"/"Go to
Portal" swap only worked for items OUS_MenuSync itself creates (its
own ACCOUNT_LINK_META_KEY tag) — a menu whose "Primary Menu" term
already existed before this feature landed never got that tag
retroactively. Not a code fix (nothing to change — the tagging only
happens at real creation time, correctly), but worth noting here:
re-seeding a pre-existing menu (delete + let ensure_default_menu_
exists() recreate it) is the way to pick up the tag on an older
install, confirmed live on billyhume.wasmer.app.

php -l clean, scoped PHPStan level 6 clean. NOT runtime-verified
against a live install by this commit alone — verify by re-saving a
course/contest and confirming its group's own label (not a child
item) links to a real catalog page instead of doing nothing.

3.10.22 — New shared service: OUS_Visibility (class-visibility.php),
the same shared-service shape as Notifications/Jobs/Roles/Events.
Built for a real product decision, caught live: bh-courses' tier gate
(BHM_Gate::user_has_tier_access) only ever asks "is the tier
requirement satisfied" — for a course with no tier set, that's
vacuously true regardless of login state, so an ungated course was
fully viewable by a logged-OUT visitor. Login and tier turned out to
be two genuinely different questions this ecosystem was conflating:
"can anyone with an account see this" vs. "does seeing it cost
something."

OUS_Visibility::can_view($post_id) is the shared answer: logged in,
OR the post is explicitly marked public via a shared `_ous_public_
access` postmeta key — plus render_login_notice() (routes through
BHI_Portal's branded login screen when installed, falls back to core
wp_login_url() otherwise) and checkbox_field()/save_from_request()
so a contributing plugin's own metabox can offer the toggle with
zero duplicated meta-key logic.

Deliberately NOT wired up as an ecosystem-wide default — bh-contest's
whole design depends on a contest being publicly viewable/shareable
(login is required to VOTE, not to see the contest), so this is
opt-in per plugin/CPT. bh-courses (0.4.79, same release) is the first
adopter: courses now default to requiring login, contests are
untouched. A future pass may add the INVERTED default to bh-contest
(public unless explicitly marked members-only) — a deliberately
separate, later product decision, not a side effect of this one.

php -l clean, scoped PHPStan level 6 clean. NOT runtime-verified
against a live install by this commit alone.

3.10.21 — Real regression caused by 3.10.20, caught live the moment
Billy actually looked at the front-end nav after it deployed: the
site's real "Contests"/"Courses" submenus were there, but every link
that used to be in the nav (Home, a handful of Pages, an Account/Log
In link) had vanished. Root cause: those links were NEVER a real
menu — they were the-self-hosted-self-theme's own no-menu-assigned fallback
(oust_default_menu() in header.php), which only renders when the
theme's primary location has nothing assigned. The instant
ensure_default_menu_exists() (3.10.20) auto-creates a real menu and
assigns it, that fallback stops firing — and the brand-new menu was
otherwise EMPTY apart from whatever OUS_MenuSync::sync_group() had
just synced into it, so those links silently disappeared with
nothing wrong actually reported anywhere.

OUS_MenuSync::seed_default_menu_content() (new) — called from
ensure_default_menu_exists() ONLY the moment a menu is genuinely
brand-new (never on a pre-existing "Primary Menu" term, which may
already hold Billy's own real content) — recreates the same Home +
up to 6 Pages + Account/Log In link the fallback used to show, but
now as real, editable nav_menu_items instead of a hardcoded fallback
dump. Deliberately built plugin-side rather than deferring to
oust_default_menu()'s own logic: this plugin is the one guaranteed to
actually deploy (the-self-hosted-self-theme's files have a known, separate,
unresolved gap where they don't reliably sync to this install's
Wasmer hosting), and the Account/Log In link is a real ecosystem
feature that must show up regardless of which theme is active — the
same "plugins and theme fully independent" posture 3.10.19/3.10.20
were already built on.

Doesn't touch Billy's actual live site retroactively — its "Primary
Menu" term already exists from 3.10.20's rollout, so this seed only
fires for a menu created from here forward. The already-created menu
needs a one-time manual backfill (done directly via wp-admin's own
Add-to-Menu UI, not a second code path) to get the same Home/Pages/
Account links added to it.

php -l clean, scoped PHPStan level 6 clean. NOT runtime-verified
against a live install by this commit alone — verify by deleting a
test site's "Primary Menu" entirely, re-triggering a resync (re-save
any contest/course), and confirming the fresh menu shows Home +
Pages + Account alongside the synced Contests/Courses groups.

3.10.20 — Second half of the OUS_MenuSync fix (3.10.19), found
immediately after deploying it and toggling "show in site menu" on
for the demo contests/course: still nothing appeared. Root cause
wasn't the wp_navigation-vs-classic-menu bug 3.10.19 already fixed —
it's that Appearance > Menus had NEVER been set up on this install
at all. wp_get_nav_menus() returns genuinely zero menu terms on a
site like that, not "a menu with nothing in it" — there's no real
menu object for sync_classic_menus() to write into. the-self-hosted-self-
theme's own no-menu-assigned fallback (oust_default_menu(), a naive
get_pages() dump) was rendering something, which is exactly what
made this look deceptively like a working, manually-curated menu —
what Billy was actually seeing was arbitrary Pages, never anything
this system synced.

OUS_MenuSync::ensure_default_menu_exists() (new) auto-creates one
real "Primary Menu" nav_menu term and assigns it to every registered
nav menu location that doesn't already have one, the first time
sync_classic_menus() ever runs into a completely menu-less site —
never overwrites an existing assignment, only fills genuinely empty
slots. No theme-side setup step required either way, same
"plugins and theme fully independent" posture as 3.10.19.
NOT runtime-verified against a live install by this commit alone —
verify on a site with zero existing menus: toggle a contest/course's
"show in site menu" on, confirm Appearance > Menus now shows a real
"Primary Menu" with the item's submenu group in it, AND that it
actually renders in the front-end nav.

3.10.19 — Real bug fix, found while investigating why Billy never
saw contests/courses auto-appear in the site menu despite the
per-item "show in site menu" checkboxes both bh-contest and
bh-courses have long had: OUS_MenuSync::sync_group() only ever wrote
to `wp_navigation` posts — the storage a BLOCK theme's Navigation
block reads from. the-self-hosted-self-theme is a classic theme (header.php
calls the classic wp_nav_menu()/register_nav_menus() API, no
theme.json) — `wp_navigation` posts exist in the database but
nothing on this site ever renders them. Every sync_group() call
before this fix silently wrote into a menu system this theme
doesn't use; the checkbox saved correctly with no error, but nothing
ever actually appeared. The contest links Billy DID see in the menu
were added there manually, not by this system.

Fixed generically, not theme-specifically (plugins and the theme
must work fully independently of each other): sync_group() now
writes to BOTH real menu systems unconditionally — every classic
wp_nav_menu() on the site (via wp_get_nav_menus()/
wp_update_nav_menu_item(), tagged with a real postmeta key so a
resync can find and replace exactly its own group without touching
manually-added items) and every wp_navigation block-theme post (the
original, correct-but-incomplete code path, kept as-is). Neither
path references the-self-hosted-self-theme or any specific theme_location —
this works correctly on any classic theme, any block theme, or a
site running both simultaneously, with zero theme-side code required
either way.
NOT runtime-verified against a live install by this commit alone —
verify by re-saving an existing contest/course with "show in site
menu" checked and confirming a real "Contests"/"Courses" submenu
appears in the actual rendered nav, not just in the database.

3.10.18 — Two real gaps, found in the same pass:

1. Broader tooltip rollout: the Roles page's per-job column headers
   used a bare native `title="..."` attribute instead of BHY_UI::tip()
   — inconsistent with the rest of this ecosystem's admin UI, delayed
   to show, and invisible on touch. Upgraded to the real component.

2. Bundled-zip / dashboard-registry coverage gap: OUS_Registry::
   DEFAULTS only ever listed 7 of the 13 real ecosystem plugins
   (bh-crm/bh-contest/bh-streaming/bh-courses/bh-registry/
   bh-feedback/bh-monetization-woo). bh-live, bh-social, and bh-video
   had NO 'ous_registered_plugins' self-registration at all — an
   inactive copy of any of the three was invisible to the ecosystem
   dashboard (couldn't be installed/activated from there) and none of
   the three ever had a bundled zip to regenerate, despite zip FILES
   for all three already sitting in bundled/ (stale, unreachable from
   any UI). bh-mailpoet and bh-tickets DO self-register, but only
   once already active — same chicken-and-egg gap already fixed for
   bh-courses/bh-registry/bh-feedback in an earlier pass, just never
   extended to these two. All five hardcoded into DEFAULTS now, same
   pattern as the existing seven. bh-tickets' own self-registration
   already declared a bundled_zip filename that never existed on
   disk; bh-mailpoet's didn't declare one at all (added). Real zip
   files for bh-mailpoet and bh-tickets were generated via Debug
   Tools -> Bundled Zip Freshness -> Regenerate, alongside refreshing
   the seven that were already stale from this session's many version
   bumps. deploy-ftp.yml already covered all 13 plugin folders
   (fixed in an earlier pass) — confirmed unchanged and correct.
NOT runtime-verified beyond php -l/PHPStan — confirm the Bundled Zip
Freshness report shows every one of the 13 as non-stale, and that a
fresh bh-live/bh-social/bh-video/bh-mailpoet/bh-tickets install
actually extracts and activates correctly from its regenerated zip.

3.10.17 — Turned BH_Commerce from a single hard-wired WooCommerce
implementation into a real, swappable provider registry (task: abstract
bh-monetization-woo behind a payment-provider contract). BH_Commerce
already WAS this ecosystem's commerce interface across two prior
migration passes, but its own method bodies had the WooCommerce logic
written directly inside them — a contract in spirit, not yet an
actually swappable one.

New: BH_CommerceProvider (class-commerce-provider.php) — the real
interface, every method BH_Commerce's callers already depend on.
BH_WooCommerceProvider (class-commerce-provider-woocommerce.php) — a
pure move of BH_Commerce's old method bodies, no logic changes.
BH_CommerceProviders (class-commerce-providers.php) — the registry
(same keyed-registry shape bh-social's BHSO_PlatformRegistry already
uses), with 'woocommerce' the only real registered provider today and
'shopify'/'stripe'/'squarespace' reserved, documented, NOT-implemented
slots — writing fake adapters against three real payment APIs with no
account to test against would be exactly the unverified-code problem
this ecosystem's own "NOT runtime-verified" convention exists to flag.
A future adapter for any of them is one new class + one register()
call, class_exists()-guarded like every other cross-plugin touch here.
BH_Commerce itself (class-commerce.php) is now a thin dispatcher —
every public method is a one-line delegate to
BH_CommerceProviders::active(); every existing call site across
bh-monetization-woo/bh-streaming/etc. is completely unchanged, since
this refactor only changed what's INSIDE these methods, never
BH_Commerce's public API. Registered with OUS_Integration
('commerce_provider' key) for the same status-report visibility every
other contract gets, and with its own Debug Tools section (Commerce
Providers) showing the full multi-provider registry state.

Full ecosystem PHPStan (283 files) clean, both PHPUnit suites (14+34
tests) pass unchanged — this was a structural move, not a logic
change, and both verify that held. NOT runtime-verified against a
live install beyond the automated test suites — no behavior should
have changed for an existing WooCommerce-only install, but confirm a
real checkout/entitlement-grant flow still works end-to-end before
relying on this in production.

3.10.16 — Real bug fix, caught visually right after deploying
3.10.15's tooltips: both .bhy-tip-bubble (admin) and .bhi-tip-bubble
(portal) used background:var(--bhy-text/--bh-text, ...) — but that
token is the theme's FOREGROUND text color, not a fixed dark chrome
color, so on this site's real dark theme the tooltip bubble rendered
as a washed-out light-cream box instead of a proper dark tooltip
chip. Fixed both to a fixed dark background regardless of the
current color scheme, matching how a transient overlay like this is
normally styled (same appearance in light or dark mode, since it's
chrome, not page content). Caught fixing class-ui.php's own comment
wording: writing "the admin design system's" inside the single-
quoted PHP string that design_system_css() returns needed the exact
same apostrophe-escaping discipline this codebase's own documented
BHY_UI::admin_page_css() incident (see CLAUDE.md) already warns
about — php -l caught it immediately, exactly as that incident's own
lesson says it would.

3.10.15 — First real pass at in-context help tooltips (a small "?"
badge, hover OR keyboard-focus reveals a positioned bubble — never
hover-only, since a hover-only tooltip is unreachable without a
mouse). New shared component: BHY_UI::tip($text) for admin screens
(.bhy-tip/.bhy-tip-bubble in design_system_css(), positioning JS in
print_design_system_js()) plus a duplicated-but-matching .bhi-tip
component inline in BHI_Portal's own render_shell()/render_login()
output, since the portal is a standalone document that never loads
wp-admin's enqueued assets. Applied to two places this pass: the
portal Overview tier badge's "renews" date (genuinely ambiguous
copy — clarified that without a real subscription mode, this is a
fixed-length grant that just ends, not an auto-recharge), and two
admin fields in bh-courses/bh-contest (see their own changelogs).
Deliberately NOT an ecosystem-wide sweep in one pass — same
incremental-rollout posture this codebase already uses for the TS
migration (ROADMAP-hyperpress-migration.md): more screens get tips
as separate, later, individually-scoped passes. NOT runtime-verified
against a live install by this commit alone — verify by hovering
AND tabbing to a "?" badge on both an admin metabox and the portal
Overview tab.

3.10.14 — Real, branded portal login screen (BHI_Portal::
render_login()), replacing the previous behavior of bouncing a
logged-out /account/ visitor to WordPress's own generic wp-login.php.
Posts to the existing bhi/v1/login and bhi/v1/register REST routes
(BHI_Auth) — the same endpoints the contest player's own embedded
auth form already uses — so this is a second front-end onto proven
auth logic (brute-force lockout, 2FA challenge, registration
throttling), never a parallel reimplementation of any of it.
wp_signon() inside BHI_Auth::login() already sets the real auth
cookies server-side, so a successful REST call just does a plain
full-page redirect back to the portal. Styled entirely off the same
--bh-* front-end brand tokens (BHY_Style::inline_css()) the rest of
the portal shell already uses, so it matches Billy's actual live
design rather than introducing a second visual language. "Forgot
your password?" still points at WordPress's own native
wp_lostpassword_url() flow rather than reinventing password reset.
NOT runtime-verified against a live install by this commit alone —
verify by logging out and hitting /account/ directly, confirm the
styled page renders (not wp-login.php), and that both login and
register actually authenticate.

3.10.13 — Phase 4 follow-up: investigated the "BH_Event::for_user()"
gap flagged during the dead-code triage. Confirmed it's stale
documentation, not a real bug — its docblock claimed to back
bh-crm's per-person activity timeline (BHCRM_People::
render_timeline()), but that method doesn't even exist in bh-crm
anymore; the real timeline is BHCRM_Event_Activity (class-event-
activity.php), which already does its own direct bhcore_events query
via the bh_crm_activity_summary filter, for documented reasons (no
identity-stitching table, so widening by client_id would add no real
rows). No other caller existed anywhere. Deleted for_user() as
genuinely dead rather than leaving a duplicate, unused, and now
factually-wrong-docblocked read path sitting next to the
implementation that actually ships. NOT runtime-verified against a
live install.

3.10.12 — Dead-code sweep (Phase 4): installed shipmonk/dead-code-
detector v0.5.1 as a PHPStan extension (pinned below the v1.x line
this repo's phpstan/phpstan ^1.12 already runs, deliberately avoiding
an unprompted PHPStan 1.x→2.x major bump; wired via a separate,
non-CI-gating phpstan-deadcode.neon that includes the real
phpstan.neon plus the detector's rules.neon), ran it across the full
279-file/12-plugin ecosystem (40 raw findings), and manually triaged
every finding against real call-site evidence before deleting
anything, per standing instruction. Removed BHY_UI::range_fill_js() —
unlike its sibling swatch_js() (genuinely echoed on the Styles page),
no template anywhere actually renders an `input.bhy-range`-classed
element (slider_row()'s own <input type="range"> carries no class at
all), so this JS had no live target to wire into; reads as leftover
debris rather than a wireable gap. Also removed bh-courses'
BHC_PostTypes::step_count() (bh-courses.php 0.4.74 changelog has the
detail).

Real limitation confirmed during triage, worth recording: this
detector cannot reliably trace calls made through an interface-typed
variable, `$this->method()` inside a class implementing an
interface/abstract base, or `(new self())->method()` dispatch back to
an interface/abstract declaration — produced a full cluster of false
positives across bh-live's BHL_HostProvisioner/BHL_StreamEngine and
bh-social's BH_SocialPlatform + ~10 concrete subclasses. Left
untouched. Also confirmed one false positive caused by this repo's
own phpstan.neon excludePaths gap (OUS_DebugLog::request_buffer()'s
only real caller lives inside the QM-integration file excluded from
analysis for unrelated reasons) and several deliberate,
already-documented "kept unused on purpose" methods (BHI_Portal::
register_elements_panel(), OUS_Hypermedia::patch_elements(),
OUS_MediaWizard::cloudflare_stream_credentials()/enqueue_hls_js()) —
none of these were touched.

Two apparent real functional gaps surfaced incidentally during triage
(NOT fixed here — flagging only, each needs its own investigation):
BH_Event::for_user() is documented as the read side backing bh-crm's
per-person activity timeline, but BHCRM_People::render_timeline()
does its own direct query instead and never calls it — the CRM
timeline may not actually be sourcing from bhcore_events at all.
BH_Rounds::is_new_submission_allowed() (bh-contest) is documented as
"the real gate" for multi-round submission but has no caller anywhere.
BHMP_Sync::remove_contact() (bh-mailpoet) is documented as the
account-deletion sync-removal path but isn't wired to any deletion
hook. NOT runtime-verified against a live install.

3.10.11 — PHPStan level 6 pass, FINAL brick of the ecosystem-wide
Phase 2 effort: all 68 files under the-self-hosted-self/includes/ now carry
real native return/parameter types and precise array-shape PHPDoc —
no @ts-nocheck-equivalent shortcuts, no blanket `mixed`/`array`
where a real shape was knowable from the method body or its call
sites. The largest files (class-element.php, 107 findings;
class-style.php, 60; class-notifications.php, 54; class-debug-log.php,
50) got the same file-by-file read-then-type treatment as every
smaller file — nothing was mechanically bulk-typed without checking
the real method body first.

Real, non-annotation bugs found and fixed along the way (not just
type declarations):
- class-element.php: five methods (register_type(), delete_context(),
  reorder(), render_debug_op_form(), move_placement()) were briefly
  mistyped `: void` by an early bulk pass despite having real `return`
  statements deep in their bodies — caught immediately by `php -l`'s
  own "void method must not return a value" fatal, corrected to their
  real types (bool/bool/bool/string/bool).
- class-element.php's save_placement(): docblock widened from
  `@return int|\WP_Error` to the type that actually matches its real
  behavior, `@return int|false` — confirmed via `grep` that every
  real call site across the-self-hosted-self/bh-crm treats the return as a
  plain int/falsy check, never `is_wp_error()`; the method's own body
  never constructs a WP_Error anywhere.
- class-element.php's build_html_attrs(): return-type docblock
  corrected from `array<string,string>` to `array<int,string>` — the
  method builds a plain list via `$out[] = ...`, never string keys.
- class-element.php's safe_enum_fallback(): return type widened to
  `?string` (was bare `string`) — its own `in_array()` branch can
  genuinely return null, which PHPStan flagged as a
  guaranteed-false `=== null` comparison at every call site.
- class-element.php's move_placement(): `array_search()`'s return is
  `int|string|false`, not narrowed to `int` by the existing `=== false`
  guard — added an explicit `(int)` cast before the `$index + $direction`
  arithmetic PHPStan flagged as "Binary operation + between int and
  string".
- class-debug-log.php's capture_uncaught_exception(): simplified a
  redundant `self::$previous_exception_handler && is_callable(...)`
  check to just `is_callable(...)` once the property's own `@var
  callable|null` docblock made the left half of that check provably
  always-true whenever the right half is true.
- class-commerce.php/class-content.php/class-registry.php and others:
  several `int`-typed ID parameters needed the same `get_posts()`
  `meta_value`-string-vs-int and `esc_attr()`/`esc_html()`
  string-vs-scalar cast fixes that recurred throughout every earlier
  brick this session.

Ecosystem-wide fallout this brick's own typing surfaced (fixed here,
not in those plugins' own version bumps, since the root cause is
this file): `OUS_Debug::button()` picked up a real `: void` return
type this pass (it already echoes its own output internally) — this
exposed that bh-courses/bh-crm/bh-monetization-woo/bh-registry's own
class-debug.php files had all been calling it as `echo
OUS_Debug::button(...)`, double-printing every debug-tools button on
their own Debug Tools sections the entire time. Fixed at every call
site (the `echo` was simply extraneous — button() never returns
anything to print). Also caught: bh-courses' class-content-bridge.php
migrate_lesson() was declared `: bool` but returned
`BH_Content::save()`'s real array result unchanged — cast to `(bool)`
at the one return statement, matching its only caller's actual
(ignored-return-value) usage.

This is also the final commit of the whole Phase 2 (PHPStan level
5→6) multi-session effort: phpstan.neon's `level:` key is now
PERMANENTLY 6 (not reverted, unlike every prior brick's own
changelog note) — the full 12-plugin, 279-file `composer phpstan`
run at level 6 comes back `[OK] No errors` ecosystem-wide.

NOT runtime-verified against a live WordPress+MySQL install — no
PHP/MySQL/network access exists in this environment. Every touched
file passed `php -l` and the full-plugin/full-ecosystem PHPStan
scoped checks; the double-echo fix in particular should be smoke-
tested on a live Debug Tools page before assuming the visual output
is now correct (single button per row instead of a doubled one).

3.10.10 — PHPStan pass, continued: expanded phpstan.neon's scanned
`paths` to include bh-mailpoet and bh-tickets (both built earlier this
session with zero static analysis ever run against them — bh-tickets
came back completely clean, bh-mailpoet's 8 findings were all real
class_exists()-guarded MailPoet API calls, now a scoped ignore rather
than noise). Installed the real php-stubs/woocommerce-stubs package
(composer.json) and wired it in via phpstan.neon's new `scanFiles` —
this alone dropped the ecosystem-wide error count by 25 with zero
manual fixes, replacing the old ad-hoc "WC_* not found" noise with
actual type checking against WooCommerce's real API.
Net result for this plugin: 68 errors -> 2 (both the deliberately-
unstubbed COOKIEPATH/COOKIE_DOMAIN constants, unchanged from
3.10.9's own documented reasoning). Real bugs fixed, not just noise
suppressed:
- class-github-updates.php's update(): $skin->get_errors() was
  calling a method that doesn't exist on Automatic_Upgrader_Skin (or
  its parent WP_Upgrader_Skin) at all — confirmed by reading both
  classes' real source directly (wp-admin/includes/class-{wp-
  upgrader-skin,automatic-upgrader-skin}.php). This would have
  fataled with "Call to undefined method" on every single real
  update attempt, success or failure. Fixed to use $result (which
  WP_Upgrader::run() already returns as a real WP_Error on failure)
  directly instead.
- class-installer.php: request_filesystem_credentials()'s real
  signature (wp-admin/includes/file.php) types its 4th positional arg
  ($context) as string — both call sites were passing false. Fixed to
  '' (empty string, the function's own documented default).
- class-commerce.php: get_order()'s foreach over $order->get_items()
  called get_product_id() on the base WC_Order_Item type, which
  doesn't have that method (it's WC_Order_Item_Product-specific) —
  harmless in practice today (get_items() with no type filter
  defaults to 'line_item', always Product in practice), but now
  guarded with a real instanceof check so a future type-filter change
  here can't silently start calling it on a shipping/fee/coupon line
  item and fatal.
Several other findings across this plugin were confirmed genuine
PHPStan false positives, not bugs, and scoped-ignored with an
explanation rather than "fixed" — see phpstan.neon's own comments:
a well-known PHPStan limitation with by-reference closures mutated
only via WordPress's indirect hook-call mechanism (class-codebase-
docs.php, class-core-test-suite.php); bh-live's BHL_* classes and
Advanced Media Offloader (neither in this repo's own scanned paths,
both real class_exists()-guarded optional integrations per this
ecosystem's own standing convention); BH_Element_Prefab (deliberately
deleted along with the rest of the old page-builder, per CLAUDE.md's
"page-builder saga" — this file's own pre-existing comment already
said so); add_submenu_page(null, ...)'s deliberate, CLAUDE.md-
documented hidden-page pattern (WP core's own function has no real
type enforcement on this param despite its docblock, and this is
proven-working, deliberately-chosen behavior, not something to change
on a docblock mismatch alone without live-install verification);
Query Monitor's own base classes (no public stub package exists for
QM the way it does for WordPress/WooCommerce, and every class this
integration defines is itself wrapped in class_exists() so it can
never even be declared without QM actually installed) — whole-file
excluded rather than several narrow ignores chasing the same root
cause.
Runtime-verified: none of this — no live WordPress+MySQL install in
this session. Every fix above was reasoned through by reading the
real, actual source of the WordPress core files/stub packages
involved (not guessed), and confirmed via a REAL phpstan analyse run
(this session finally has working composer/PHPStan, unlike most of
this repo's prior history) — that's a meaningfully stronger
verification bar than most of this session's other work, but it is
still not the same as exercising this code in a browser.

3.10.9 — First real `composer install && vendor/bin/phpstan analyse`
run against this repo (repo-root phpstan.neon/composer.lock; the
PHPStan/TS pilot bootstrap in 3.10.5 was written in a sandbox with no
GitHub access, so it had never actually been run until now). Two
structural fixes plus several real bugs it surfaced:
(1) Memory: a plain `vendor/bin/phpstan analyse` was crashing every
parallel worker past a 128M/1G PHP memory limit. Root cause was
unrelated to the codebase's actual size — no code change needed there,
just `composer.json`'s new `phpstan` script running the same command
with `--memory-limit=2G`, which completes cleanly.
(2) Constant-resolution noise: PHPStan couldn't see each plugin's own
_URL/_PATH/_VER constants (defined via plugin_dir_url()/
plugin_dir_path() in each plugin's real main file) when analysing
includes/*.php in isolation, flooding the report with ~80 false-
positive "Constant X not found" errors. Fixed via a new
phpstan-bootstrap.php (repo root, referenced by phpstan.neon's
bootstrapFiles) with dummy define()'d values for every analysed
plugin's constants — NOT by including each plugin's real main file as
a bootstrap, which would have actually executed it against undefined
WP functions and fataled.
(3) Real bugs, this plugin's files: class-role-assignment.php/
class-campaigns.php/class-banner.php called
check_admin_referer($action, $query_arg, false) — that function only
takes 2 params and, unlike check_ajax_referer(), has no non-dying
mode; an invalid nonce always hard wp_die()s regardless of the
(silently ignored) third argument. class-role-assignment.php and
class-campaigns.php clearly wanted a graceful "just don't apply this
POST" fallback (their surrounding if/elseif structure only makes sense
if the nonce check can return false without dying) — switched both to
wp_verify_nonce(). class-banner.php's dismiss handler DOES want a hard
die on an invalid nonce, so that one just lost its dead third
argument. class-portal.php's maybe_redirect_login() had a redundant
`|| is_wp_error($user)` after an `instanceof \WP_User` check that
already excludes WP_Error — removed (dead code, not a behavior change).
class-setup-wizard.php/class-style-gallery.php had one esc_attr() call
each passing an attachment ID (int) directly where esc_attr() expects
a string (PHP 8.1+ deprecation) — added explicit (string) casts.
`php -l` clean on every touched file. Runtime-verified live against
localhost:10008: Roles page save now shows "Updated 1 capability
grant(s)" instead of a hard nonce-failure die. Peer plugins bh-contest/
bh-courses/bh-monetization-woo/bh-streaming got their own matching
version bumps for the same PHPStan pass's findings in their own files.

3.10.8 — Merged the `dev` branch (portal live-status Datastar work,
bh-courses/bh-crm/bh-streaming/bh-contest feature commits, the OSS-
integration master plan) into this TypeScript-pilot branch. The two
branches had each independently stamped a "3.10.5" entry below for
unrelated changes (this branch's is the PHPStan/TS-pilot bootstrap;
dev's is the first real Datastar consumer, the portal's live
notification badge/wallet chip) — both are kept as-is rather than
renumbered, since neither shipped standalone and the merge itself is
what reconciles the version line to 3.10.8. Follow-up: newly-touched
plain vanilla-JS files pulled in from dev (bh-contest/assets/js/
reveal.js, bh-courses/assets/js/courses-studio-blocks.js, bh-crm/
assets/js/segment-builder.js, bh-streaming/assets/js/stats-charts.js)
are candidates for the same TypeScript pilot treatment as
the-self-hosted-self/assets/js/ — tracked as separate, individually-scoped
conversion passes, not done in this merge commit.

3.10.7 — TypeScript pilot completed for the whole of assets/js/: the
three remaining files (element-prefab-block.ts, block-style-panel.ts,
studio.ts — the heaviest wp.element/Gutenberg-editor files, deferred
out of 3.10.6) are now converted, plus a new shared assets/ts/wp-
globals.d.ts ambient-types file so each file no longer redeclares its
own WpGlobal interface (page-content-block.ts was refactored to use it
too). Types are loose by design (Record<string, unknown>/unknown for
most Gutenberg component props) — the goal is catching mistakes in
THIS ecosystem's own code (wrong wp.* member name, wrong argument
shape, wrong local variable type), not fully modeling core's React
tree; a real @wordpress/* type package was deliberately not installed
(see wp-globals.d.ts's own docblock). block-style-panel.ts needed two
explicit `as (...args: unknown[]) => unknown` casts where
createHigherOrderComponent's return value feeds addFilter() — a real,
if narrow, gap in how loosely compose/hooks are typed here; noted in
case a tighter WpComposeApi/WpHooksApi signature is worth writing
later. All seven compiled outputs (assets/js/*.js) reverified this
pass: `node --check` (syntax) plus a grep for CommonJS `exports`/
`require(` artifacts (the exact bug class 3.10.5 caught in
tsconfig.json) on every file, not just the three new ones. This
closes out the "no mass rewrite, one file at a time" pilot noted in
3.10.5/3.10.6 — assets/js/ in this plugin is now entirely TS-sourced.

3.10.6 — Three more files converted to the TypeScript pilot started in
3.10.5: toast.ts (BHCoreToast + the cross-plugin modal scroll-lock),
element-live.ts (Design Suite runtime re-resolution poller), and
page-content-block.ts (the bh/page-content Gutenberg block). The first
two are plain vanilla scripts, same shape as search.ts. The third is
this pilot's first wp.element/Gutenberg-registration file — rather than
pull in an @types/wordpress__* package (a new npm dependency this
ecosystem doesn't otherwise need), it declares a small hand-written
`WpGlobal` interface covering only the wp.blocks/wp.element/
wp.blockEditor/wp.i18n surface this one file actually calls. Widen
that interface (or introduce a shared ambient .d.ts if a third
wp.element file gets converted) rather than reaching for a full
WordPress type package by default. All four compiled outputs
(assets/js/{toast,element-live,page-content-block,search}.js) were
verified free of CommonJS module artifacts (grepped for `exports`/
`require(`) before committing — the same class of bug 3.10.5 caught
and fixed in tsconfig.json. Remaining assets/js/ files (studio.js,
element-prefab-block.js, block-style-panel.js) are heavier
wp.element/Gutenberg-editor files left for later, separately-scoped
passes — not converted in this pass.

3.10.5 — Type-safety tooling pass, AJ's own ask ("make the PHP type safe,
switch to TypeScript, dev-only build step is fine now"). Two separate,
dev-only additions — neither changes anything the live site executes:
(1) PHPStan (repo-root composer.json, new phpstan.neon) with the
szepeviktor/phpstan-wordpress stub package, scoped at level 5 across
the-self-hosted-self + the peer plugins already whitelisted in .gitignore.
Level 5 (not a stricter level) deliberately, to catch real bugs — wrong
argument types, undefined methods, null dereferences — without fighting
WordPress's own loosely-typed core APIs from day one. NOT actually run
in this session: this sandbox's egress proxy returned a hard 403 on
api.github.com zipball downloads (org network policy, not a transient
failure — confirmed via three retries and a -vvv trace showing the
proxy's own 403, not a timeout), so `composer install` could not
complete here. composer.json/phpstan.neon are believed correct by
inspection but UNVERIFIED — run `composer install && vendor/bin/phpstan
analyse` somewhere with real GitHub access before trusting this is
wired correctly. (2) A TypeScript pilot for this plugin's JS: a root
package.json + this plugin's own tsconfig.json (rootDir assets/ts,
outDir assets/js, `module: none` since every script here is a plain
enqueued IIFE, never an ES module — module resolution isn't the point,
type-checking is), with search.js rewritten as assets/ts/search.ts and
recompiled via `npm run build:the-self-hosted-self`. Deliberately plain `tsc`,
not a bundler — matches the ecosystem's existing "no npm/webpack build"
posture for anything actually enqueued: each .ts file still becomes one
plain, independently-enqueued .js file, no import/export graph, no
Datastar/wp.element behavior change. The compiled .js IS committed
(not gitignored) since the live FTP-deployed site runs no build step
at all — see .gitignore's own comment on why vendor/ can be
regenerated by Wasmer but this repo's FTP path can't assume any build
step ever runs. Caught one real bug in the process: an initial
`moduleDetection: "force"` setting made tsc emit a CommonJS
`Object.defineProperty(exports, "__esModule", ...)` header even under
`module: "none"` — which would have thrown "exports is not defined" the
moment a browser loaded the plain <script> tag. Removed; verified the
recompiled output is a clean, module-free script. This is a pilot on
ONE file, not a mass conversion — the rest of assets/js/ stays as
hand-written vanilla JS until each file gets its own scoped conversion
pass, same "no mass rewrite" precedent as
ROADMAP-hyperpress-migration.md's Datastar migration backlog.

3.10.5 — First real Datastar consumer (ROADMAP-hyperpress-migration.md
§1a's recommended target): the account portal's notification badge and
wallet-balance chip (class-portal.php, render_shell()) are now
Datastar-bound signals, kept current by a periodic poll
(data-on-interval__duration.30s -> new wp_ajax_ous_portal_live_status
handler, BHI_Portal::ajax_live_status()) instead of only updating on a
full page reload. Deliberately a bounded, request-per-poll GET every
30s rather than a held-open SSE stream — this ecosystem targets
ordinary shared hosting, where holding a connection open per visitor
tab is a real resource-exhaustion risk on a small PHP-FPM worker pool;
a short poll is the honest tradeoff given that constraint, not an
oversight. Both the initial page-load values and every subsequent
poll compute through the exact same two calls (OUS_Notifications::
unread_count()/BHM_Wallet::balance_cents()), so a poll can never show
a different number than a fresh reload would. class_exists()-guarded
throughout — BHM_Wallet absent (bh-monetization-woo inactive) just
means the walletBalance signal is never set, no error.
NOT runtime-verified against a live WordPress+MySQL install this
session; `data-on-interval`/`data-signals`/`data-text`/`data-show`
syntax was verified against Datastar's own reference docs before use
(not guessed) — actual browser behavior still unconfirmed. `php -l`
clean.

3.10.4 — Phase 6 (final phase) of the OSS-integration master plan:
Tier B, Cloudflare Stream, added to the existing Media & CDN Setup
wizard (class-media-wizard.php's new render_tier_b_section()) —
strictly opt-in, Tier A (object storage + CDN via Advanced Media
Offloader) stays the default recommendation. Real, live-tested
connection (a GET against the account's own Stream endpoint, same
"validate in real time" posture Tier A's ADVMO checkConnection() call
already takes), not a format-only credential check.
Fixed a real bug surfaced while adding this: the wizard page
(add_menu()) was only ever registered when Advanced Media Offloader
OR bh-live's engine registry was active — Tier B doesn't depend on
either, so on an install with neither, the whole page (Tier B
included) would have been unreachable. Now always registered.
Deliberately scoped to settings + a real connection test only, NOT
the per-video "upload to Cloudflare Stream, swap the player" wiring
into bh-video/bh-courses/bh-streaming's own separate upload flows —
see render_tier_b_section()'s own docblock for why that's a real,
separate follow-up rather than attempted here. New public accessors
(tier_b_enabled(), cloudflare_stream_credentials(), enqueue_hls_js())
are what that follow-up would build on.
New vendored dependency: hls.js v1.6.16 (Apache-2.0 — NOT MIT, a
claim made and then verified wrong earlier this session before
vendoring; corrected here — real bytes from its official GitHub
release, assets/js/vendor/hls.min.js), ready for whichever plugin's
Tier B integration needs HLS playback outside Safari.
No OUS_Wizard framework built for this pass — this is still a single
settings form, not a genuine multi-step interview yet; matches this
plan's own "build the concrete thing first, extract a framework
after" precedent (OUS_Integration).
NOT runtime-verified against a live WordPress+MySQL install this
session — the Cloudflare API call shape was reasoned from Cloudflare's
own public API docs, not exercised against a real account. `php -l`
clean; a real brace-mismatch bug was caught and fixed via `php -l`
during this same edit (see class-media-wizard.php's own history).

3.10.3 — Phase 3 of the OSS-integration master plan: new
'bhcore_manage_tickets' capability (class-roles.php DEFAULT_CAPS),
granted to administrator/editor/bhcore_studio_manager — same trusted-
staff tier as bhcore_manage_crm/bhcore_design_site. Backs the new
bh-tickets peer plugin's admin page and portal-panel gating.
OUS_Roles::init() already re-applies DEFAULT_CAPS idempotently on
every 'init', so this takes effect on existing installs with no
separate migration step. NOT runtime-verified this session.

3.10.2 — Phase 4 of the OSS-integration master plan: new
OUS_GithubUpdates (class-github-updates.php) — self-hosted "check
GitHub for a newer version, install it in one click" for this
ecosystem's own plugins (and, since the mechanism is fully generic,
any plugin or theme). Explicitly NOT built on the third-party
plugin-update-checker library after investigating it directly: that
library's real download path fetches a zipball of the WHOLE repo
(GitHub's zipball API can't scope to a subdirectory), which would
have silently tried to install this entire monorepo as one plugin.
Instead: download the repo archive at the configured branch, extract
just the configured subdirectory, rebuild a clean single-plugin zip
(same ZipArchive-from-a-directory approach OUS_Registry::
regenerate_bundled_zip() already uses locally), then install it via
WP core's own Plugin_Upgrader/Theme_Upgrader with 'overwrite_package'
=> true — confirmed by reading wp-admin/includes/class-wp-upgrader.php
directly that download_package() accepts an already-local file path
unchanged, and that both upgraders support 'overwrite_package' (the
same mechanism core itself uses to let a plugin be reinstalled over
its own files) — this is real, current WP-core behavior, verified
against this install's own wp-admin/ source, not assumed.
Every source is {type, label, file/stylesheet, repo, branch, path} —
every ecosystem plugin already in OUS_Registry auto-registers
(repo/branch default to a filterable pair, 'ajhrtmn/billyhume'/'dev'
on this install), and a completely separate plugin or theme, from any
repo, registers itself via the 'ous_github_updates_register' action —
not hardcoded to this install's own repo shape. Daily self-
rescheduling OUS_Jobs check (same pattern as bh-courses'
BHC_DripNudges); Debug Tools section shows installed-vs-remote
version per source with a real "Update now" button once one's behind.
NOT runtime-verified against a live WordPress+MySQL install this
session — the WP-core upgrader behavior this depends on was confirmed
by reading the actual installed wp-admin/ source directly (not
guessed), but the full download-extract-rezip-install flow has never
been exercised against a real GitHub repo end-to-end. `php -l` clean.

3.10.1 — Phase 2 of the OSS-integration master plan: Datastar (v1.0.2,
MIT, real bytes downloaded directly from its official jsDelivr release
bundle — not hand-written) vendored at assets/js/vendor/datastar.js,
wired up by new OUS_Hypermedia (class-hypermedia.php). This becomes
the new DEFAULT tool for interactive admin/editor UI going forward —
CLAUDE.md's "no build step, vanilla JS everywhere" rule is unchanged,
this just names which no-build tool new work should reach for first;
wp.element.createElement stays valid for anything simple enough not
to need server-driven reactivity (a plain form, a static settings
screen). Deliberately NOT a dependency on the separate HyperPress
WordPress plugin — confirmed by reading its actual repo structure
that it's a full standalone plugin/Composer library (its own admin
page, HyperFields/HyperBlocks systems, a whole REST namespace), a
materially bigger dependency than this ecosystem needs just to get
Datastar itself running; HyperPress's approach informed
OUS_Hypermedia's shape but isn't installed or required by it.
OUS_Hypermedia::enqueue() loads the vendored script (with the
required type="module" tag, added via a script_loader_tag filter
scoped to this one handle); ::sse_headers()/::patch_elements()/
::patch_signals() write the exact wire-protocol SSE events Datastar's
own reference documents (event: datastar-patch-elements / datastar-
patch-signals, data: lines, blank-line terminator) — the one
genuinely reusable low-level primitive every future consumer would
otherwise reimplement, kept deliberately minimal rather than building
a speculative framework around it before real consumers exist.
See ROADMAP-hyperpress-migration.md (new) for the inventory of
existing wp.element-based admin/editor screens and which are actually
worth converting — that migration happens as separate, later,
individually-scoped passes, not as part of this change.
NOT runtime-verified against a live WordPress+MySQL install this
session — the vendored datastar.js is real, verified bytes from its
official CDN, but OUS_Hypermedia's own PHP (enqueue/SSE headers/event
formatting) has only been reasoned through and `php -l`-checked, never
exercised against a real browser actually consuming an SSE response.

3.10.0 — New OUS_Integration (class-integration.php), Phase 1 of the
OSS-integration master plan: a light registry for CLAUDE.md's new
standing rule ("critical infrastructure always ships a minimal,
self-hosted, built-in default; a third-party integration is an
enhancement, never the only implementation"). OUS_Integration::
register($key, $args) records a {builtin_class, enhancer_class}
pair; active_implementation($key) reports which one is actually live
right now (enhancer if its class_exists(), builtin otherwise) — pure
visibility, no auto-switching or enforcement. New Debug Tools section
("Integration Contracts") lists every registered pair.
Deliberately thin and retrofitted from real, already-shipped examples
rather than designed blind, matching how BH_Content/BH_Commerce
themselves got built: this plugin registers the first contract here
(bh_mail -> BH_Mail, no enhancer yet); bh-crm 2.4.14's campaign-
segments bridge and bh-mailpoet's MailPoet sync become the second and
third registrations in their own respective changelogs.
NOT runtime-verified against a live WordPress+MySQL install this
session; `php -l` clean on class-integration.php and this file.

3.9.9 — BH_Event::emit() (class-event.php) now fires a real
do_action('bh_event_emitted', $type, $job_args, $args) synchronously,
alongside its existing async OUS_Jobs ingest enqueue. Every event this
ecosystem already emits (bh/vote, bh/submission_created, bhc/enroll,
bhc/course_completed, bhm/wallet_credit, bhm/referral_credited,
bhcrm/tags_saved, etc.) is now something a peer plugin can react to in
real time, not just read back later via for_user()/the Debug Tools
metrics view. Direct motivation: the new bh-mailpoet plugin needs to
sync a contact to MailPoet the moment they enroll in a course or get
a wallet credit, not on a once-daily poll. No behavior change for any
existing emit() caller — this only adds a hook nobody was listening to
before. NOT runtime-verified against a live WordPress+MySQL install
this session; reasoned through against the existing emit()/OUS_Jobs
code, and `php -l` clean on class-event.php.

3.9.3 — OUS_Badge (class-badge.php): a small reusable status-badge
helper for flagging a specific FEATURE (not a whole plugin) as
alpha/beta/experimental, added when bh-social needed a way to mark
its untested-against-a-live-account platform integrations. See
class-badge.php's own docblock for why this exists as a render
helper rather than a registry-tracked flag.

3.7.8 — ecosystem depth-pass Tier 1c: OUS_UserBar
(class-user-bar.php), the front-end user bar VISION.md names
directly — a bottom-docked bar, styled with this site's own --bh-*
brand tokens (deliberately NOT a copy of wp-admin's dark toolbar,
AJ's own explicit call), always visible to a logged-in front-end
user regardless of which plugin they're using. Notification bell +
a `bhi_user_bar_links` filter any plugin can contribute a live
micro-state quick-link to (the-self-hosted-self's own Account/Log out links
ship as the first registrant).

3.7.7 — ecosystem depth-pass Tier 1b: per-notification-type email
preferences (class-notifications.php). Layers on top of the existing
single all-or-nothing opt-out — a per-type usermeta map, absent-key
means default-on, so nobody's existing email behavior changes until
they actually open the new "Manage what you get emailed about"
disclosure. Progressive disclosure by design: only shows the
notification TYPES a user has actually ever received, closed by
default, no settings grid dumped on anyone who's never asked for
finer control.

3.7.6 — ecosystem depth-pass Tier 1a: OUS_RoleAssignment
(includes/class-role-assignment.php), the role-assignment admin UI
OUS_Roles' own docblock has always flagged as separate, unbuilt
scope. Framed around roles-as-jobs (Instructor/Reviewer/Designer/CRM
Manager) rather than raw capability checkboxes, with an Advanced
section for the two deliberately-more-restricted capabilities and
anything a future plugin registers. New submenu under 'the-self-hosted-self'
(the working parent — see VISION.md's own get_plugin_page_hook()
finding, isolated to submenus under 'ous-debug' specifically).

3.5.1 — a shared, opt-in wide-layout fix for custom post-edit screens, AJ's
own ask after looking at the contest edit screen specifically: "many admin
pages suffer from the same issues." New OUS_AdminLayout (class-admin-
layout.php): several post types in this ecosystem (bh_contest, bh_submission,
bhm_tier, bh_course) were still on WordPress's default two-column post-edit
chrome — one narrow stacked column of meta boxes plus a fixed ~280px sidebar —
wasting real horizontal space on a wide screen. Confirmed live: the sidebar's
own "Contest Rules & Results" box was visibly overflowing its column with real
content, while the main column sat mostly empty beside it.

3.5.0 — new accountability audit log, AJ's own ask: "audit, do everything
important, and anything those important things touch... yes granular diffs...
admin only." New OUS_Audit (class-audit.php): a synchronous accountability log
(bhcore_audit_log) distinct from BH_Event's per-person ACTIVITY timeline —
that answers "what did this person do," this answers "who changed WHAT to WHAT
on a thing that isn't necessarily their own" (a tier's price, a segment
someone else built, another user's role). log_diff() stores granular
before/after field diffs; log() covers plain "X happened" actions (deletions,
rejections). require_cap() is a drop-in for the `if (!current_user_can($cap))
wp_die(...)` pattern used everywhere in this ecosystem's admin-post handlers —
it does NOT log every single denial (pure noise), only once a per-user denial
count crosses a concerning threshold within a short window (AJ's own "log
denies and fails if they exceed a concerning amount"). Also hooks WordPress's
own set_user_role action, so granting/revoking any role (including the new
Studio Manager) from the Users screen is tracked for free, no bespoke UI
needed.

3.4.91 — debug-log wiring pass (AJ's own ask: "wire any of those new events
into the debug log... that would be useful and helpful for future dev").
Rather than mirroring every routine BH_Event emission into OUS_DebugLog
(that's what the activity timeline is for),
OUS_Notifications::send_email_now() now logs a warning specifically when
wp_mail() returns false — previously a queued notification email failing to
send was completely silent, with nothing anywhere telling a dev it happened.

3.4.90 — permissions audit follow-through (AJ's own ask: "audit user roles and
permissions... admins and site managers should have access to a good chunk of
this... user-owned relationships where admin sees all might be a little more
restrictive to non-admin managers"). A prior background audit found: no custom
role existed at all (only capability grants on the built-in 'editor' role),
and bhcore_manage_ crm gated a person's phone number/wallet balance/purchase
history/ refund-fraud flags identically to the plain person list — no split
between "can see the roster" and "can see private/financial data." New:
OUS_Roles::MANAGER_ROLE ('bhcore_studio_manager', label "Studio Manager") —
this ecosystem's FIRST real custom WordPress role (register_role(), not just a
capability grant on an existing role), cloned from editor's own capability set
at registration time so it can manage bh_contest/bh_course/bh_lesson content
(all use the default 'post' capability_type) plus bhcore_design_site/
bhcore_manage_crm.

3.4.89 — real bug, caught live while wiring more emitters into the CRM's
unified activity timeline (bh-crm 1.9.0's own changelog):
BH_Event::handle_ingest_job()'s INSERT used $wpdb->prepare()'s %s placeholder
for dedup_key, which silently casts a PHP null to an empty string, not SQL
NULL. dedup_key carries a UNIQUE key, so EVERY event emitted without an
explicit dedup_key (the common, "append-only" case — most emit() call sites
across this whole ecosystem) collided with the very first such row ever
inserted and was silently dropped by INSERT IGNORE, ever since this table
existed. Confirmed directly against the live table: dedup_key was stored as ''
rather than NULL, and only ONE non-deduped event had ever actually landed.

3.4.88 — portal styling QA pass, AJ's "wrap up the CRM, then make sure styles
look sleek and professional on desktop and mobile, not clunky/cramped"
request. Three real bugs found and fixed against the live front-end portal
(/account/): 1. class-portal.php's own inline <style> block referenced a
fictional, never-defined token scheme (--bhy-color-bg etc) that doesn't exist
anywhere in this codebase — every declaration silently fell through to
hardcoded generic-WP-blue fallbacks, so the portal NEVER showed the real site
brand (warm cream/ terracotta, --bh-* tokens from class-style.php) on any
load, ever.

3.4.85 — real bug sweep, not a feature pass: while building bh- monetization-
woo's first ServerSideRender block this session, hit a confirmed WordPress bug
pattern — a class's own init() method, itself only ever invoked AS an 'init'
hook callback, was internally registering a SECOND add_action('init', ...) of
its own. Since WP_Hook never revisits a priority bucket it has already passed
in the same request, that inner registration silently never fires, ever, with
zero error anywhere — confirmed directly against a minimal WP_Hook
reproduction, not assumed.

3.4.84 — vendor/fpdf/fpdf.php was committed on its own in the previous pass
without the font metric files (font/*.json) FPDF's core fonts (Helvetica,
Times, Courier) actually load at render time — a gap only surfaced once bh-
courses' new certificate-of-completion feature (class-certificates.php) tried
to actually render a PDF and hit "file_get_contents(.../font/helvetica.json):
Failed to open stream" live, caught via a temporary WP_DEBUG_LOG flip. Fixed
by vendoring the four Helvetica metric files (helvetica.json/b/i/bi.json) from
the same upstream (setasign/fpdf) fpdf.php itself was pulled from — RUNTIME-
VERIFIED end to end on this install: generated a real single-page PDF via
BHC_Certificates against a real course/user/completion row, the output file
identified as a genuine "PDF document, version 1.3.".

3.4.71 — 2026-07-12 — three more rounds of direct live feedback, all addressed
in one pass: (1) "bloated, poorly proportioned... good gaps/ padding/margins"
+ "all three need to feel cohesive" — the Library rail's list rows now reuse
.bhy-rail-item/.bhy-rail-subheading VERBATIM (the exact classes Live Views'
own story-button list already uses in the same rail) instead of a parallel
bhds-library-item class with slightly different numbers; the canvas
toolbar/state-strip/Controls panel were re-measured against tokens already
used elsewhere in this rail (7px/14px row padding, 11px uppercase headings)
instead of inventing a new scale; the background-toggle went from three
separately -bordered boxes to one connected segmented control; canvas
padding/min- height reduced; the Controls panel heading now reuses .bhy-
controls h3 verbatim. (2) "This is kinda my dream" — a real Storybook
screenshot showing NESTED, disclosure-triangle story trees (states nested
inside their component, not a separate tab strip) and a SOLID PILL selected-
row highlight, not a left-border tint. Named fixture states are now tree rows
nested under their Component/Primitive (renderNestedStates()), disclosure-
triangle expandable, lazily fetched and cached per item; the separate state-
tab strip above the canvas is GONE (its markup, CSS, and
renderStateTabs()/loadStates() are removed outright) — the canvas toolbar is
now purely the light/dark/grid background toggle, matching what that position
actually is in real Storybook.

3.4.77 — 2026-07-12 — REAL, LIVE-CONFIRMED BUG FIX: 3.4.76 broke
admin.php?page=bh-design itself — a logged-in admin got WordPress core's own
"Sorry, you are not allowed to access this page" wp_die(), immediately after
this plugin gained a new page. Root cause: class- component-studio.php's
add_menu() registered its Components list with a REAL parent slug
(add_submenu_page('bh-design', ...)) — this is a known, ALREADY-DOCUMENTED
footgun in this exact codebase (see class- style-gallery.php's own 3.4.31
changelog note): WordPress implicitly pairs a top-level menu's bare slug with
its first-registered submenu's own capability/callback, and adding another
real submenu under the same parent can disturb that pairing depending on
admin_menu hook registration order.

3.4.75 — 2026-07-12 — REAL, LIVE-CONFIRMED FIX: "Close, they jump to the
start, not back one level" (the 3.4.74 breadcrumb/back-button work, tested
live). Root cause: class-element.php's get_placements() only ever cast
library_component_id to a real int — id and parent_placement_id came back as
plain STRINGS (wpdb ARRAY_A over MySQL's text protocol), which JSON-encodes as
quoted strings.

3.4.68 — 2026-07-12 — LIBRARY-STRUCTURE-HYBRID-DESIGN-PLAN.md Phase 4: linked
instances, AJ's confirmed scope of leaf-value overrides only (no per-instance
structural changes — anything beyond attrs/style requires editing the master
Component or detaching). New bhcore_element_placements column
library_component_id (class-identity-activator.php DB_VERSION 1.12): 0 = an
ordinary placement (every pre-existing row, unchanged behavior); non-zero = a
linked instance — ONE row whose 'config' is repurposed as an index => {attrs,
style} leaf-override map, no real child placement rows, structure entirely
virtual.

3.4.67 — 2026-07-12 — LIBRARY-STRUCTURE-HYBRID-DESIGN-PLAN.md Phase 3: the
add-child picker (element-builder.js) turns out to already have been the
Library, largely built in an earlier pass as the "Prefabs" palette section —
instantiatePrefab() already gives exactly the detached-copy semantics
§5.3/Phase 3 calls for (a fresh, independent set of placement rows every
insert, editing the copy never touches the saved Component). Renamed that
section's header "Prefabs" -> "Components" for terminology consistency with
the Library tab (no schema/route change — the table is still literally
bhcore_element_prefabs).

3.4.66 — 2026-07-12 — LIBRARY-STRUCTURE-HYBRID-DESIGN-PLAN.md Phase 2: named
fixture states — the Storybook Default/Empty/Viral-style variant tabs, per
AJ's own "fixture/mock data per state" answer. New table bhcore_element_states
(class-identity-activator.php DB_VERSION 1.11) and a new BH_Element_State
class (class-element-state.php) hold them — one shared table for both a
Library Component (owner_kind 'component', owner_key its prefab id) and a
code-registered Primitive type (owner_kind 'type', owner_key its type slug),
per the design doc's own §4.2 call. register_type() gained an optional
'states' manifest key so a type's author can ship default states inline;
BH_Element:: maybe_seed_default_states() lazily inserts any that don't already
exist the first time a type's states are actually requested, and never
overwrites a row someone has since edited by hand.

3.4.65 — 2026-07-12 — LIBRARY-STRUCTURE-HYBRID-DESIGN-PLAN.md Phase 1: the
Library tab stops being read-only. "New Component" and "Edit this Component"
now open a real authoring session — a new internal '__library' sandbox surface
(class-element.php's register_library_ surface(), excluded from the ordinary
Structure boot-load and Preview- surface list via a new 'internal' surface
flag) reuses the EXISTING tree/inspector/add-child/reorder/save machinery
unchanged, just pointed at (surface='__library', context_id=that Component's
own id) instead of a live page — per the design doc's own "one editor, two
modes" decision, this is a bridge (window.bhElementLibrary in element-
builder.js: enterEdit/exitEdit/publish), not a second editor. Editing an
existing Component hydrates its sandbox from the currently-published
definition the first time (via the existing prefab instantiate route, now also
usable against the sandbox), and "Publish" snapshots the sandbox back into the
real Component via a new nested-aware definition_from_slot() helper (class-
element-prefab.php) — a real capability fix over the old save_from_slot(),
which silently dropped nested children; both a Component's root slot
supporting more than one top-level sibling and genuine parent/child nesting
now round-trip correctly. rest_update() gained a surface+slot re-derive mode
alongside its existing raw-definition mode.

3.4.64 — 2026-07-12 — LIBRARY-STRUCTURE-HYBRID-DESIGN-PLAN.md Phase 0: the
first real slice of the Library/Structure rebuild AJ asked for. A top-level
"Library | Structure" tab switch now sits above the Design Suite shell (class-
style-gallery.php), localStorage-persisted (bhdsActiveMode).

3.4.63 — 2026-07-12 — AJ's own ask: "delete individual logs, hide or mute
specific log codes... like Visual Studio" for the Console & Logs section
(OUS_DebugLog). This schema has no discrete error-code field (levels are only
error/warning/info, by design), so the practical equivalent of "mute this
diagnostic" is muting by the exact (source, message) a row actually has — read
server-side from the row being muted, never trusted from a round-tripped form
field.

3.4.62 — 2026-07-12 — AJ's own explicit visual reference: storybook.js's
Controls panel (a fixed Name column, one clean row per property, thin row
dividers, no per-field label-above-input stacking) — NOT a request to embed
Storybook's actual runtime/build step, which would conflict directly with this
ecosystem's "no build pipeline assumed, runs on ordinary shared hosting"
standing architecture. Scoped as a pure CSS/ markup pass on the inspector's
Style — Advanced property rows and the Custom class/CSS rows (element-
builder.js's renderStylePropertyField() now wraps its select/color-
popup/custom-input together in one .bhel-field-controls container so the grid
table works even for a property with more than one control; element-
builder.css's new ".bhel-style-group-body > div.bhel-field-row" grid rules do
the actual visual work).

3.4.61 — 2026-07-12 — two fixes/completions picked up after a real site-down
incident: (1) THE FATAL: class-ui.php's admin_page_css() returns one long
plain single-quoted PHP string (not a heredoc). Two comments added in 3.4.60's
own contain:layout fix contained unescaped apostrophes ("story's", "bh-
contest's") — exactly the recurring "unescaped apostrophe silently terminates
a long single-quoted string" bug class this ecosystem has hit before (see
VISION.md).

3.4.60 — 2026-07-12 — two live-confirmed fixes, straight off AJ's own
screenshot: "Live View tree isnt showing the selected tree." (1) Real bug: TWO
separate click listeners were bound to the same .bhy-story-btn buttons — one
(registered first) dispatched 'bhel:select-surface' to sync the tree/outline,
one (registered second) toggled which .bhy-story-frame carried the 'active'
class. Listeners on the same element/event fire in registration order, so the
sync dispatch fired and rebuilt element-builder.js's outline BEFORE the active
class had actually moved — renderDemoOutline() reads '.bhy-story-frame.active'
directly, so it was always one click behind, showing the PREVIOUS surface's
markup over the NEW surface's canvas (exactly the screenshot: contest player
on screen, CRM profile markup in the outline).

3.4.59 — 2026-07-12 — AJ's own ask, folded into the bh-contest conversion work
rather than deferred as a separate pass: "is there a way to... litterally do
it all via the builder instead of hard coded files" for JS specifically, plus
"easy ways to wire up UI events to actions... 'On click' could trigger UI and
server side stuff via fetch." Two genuinely different features, two genuinely
different trust levels: (1) "On click" ACTIONS (p.config.actions, any
placement) — a plain, codeless list builder in the inspector (element-
builder.js's new renderActionsSection()): trigger
(click/mouseenter/mouseleave/submit) + kind (toggle a CSS class / call a URL
via fetch / navigate to a URL) + that kind's own params. class-element.php's
new build_actions_js() maps each entry to a small, FIXED, reviewed JS snippet
server-side — never raw script — so this needs no capability gate; anyone who
can edit a placement at all can wire one up. (2) Custom JS
(p.config.custom_js) — real, raw JavaScript, rendered scoped to one
placement's own DOM element (wrap_placement_html()). This one IS dangerous
(arbitrary code on the live site for every visitor), so it's gated for real: a
new administrator-only capability
(OUS_Roles::DEFAULT_CAPS['bhcore_author_custom_js']), enforced at
save_placement() — the ONE write path every caller (REST, Debug Tools,
prefabs) funnels through, not just checked in the GUI — plus a client-side "I
understand this runs unreviewed" confirmation checkbox before the field is
even usable.

3.4.58 — 2026-07-12 — AJ's own ask, framed as core debug-tooling work
deliberately done BEFORE the bh-contest conversion starts (not after): "good
use of Query Monitor where needed." New includes/class-qm- integration.php
registers a real QM_Collector + QM_Output pair — Query Monitor's own admin-
toolbar panel now gets an "The Self-Hosted Self" tab showing THIS request's own
OUS_DebugLog entries (errors/warnings/info, same fields Debug Tools' Console &
Logs table already shows), so triaging a bug while actively building bh-
contest's real surface doesn't mean bouncing between QM and a separate admin
screen. Backed by a new zero-extra-query in-memory buffer (class-debug-
log.php's request_buffer(), appended to at the end of the existing log()
method) — entirely additive, no change to what already gets logged or how.

3.4.57 — 2026-07-12 — direct UX follow-up: "move the Live view tree up so you
don't have to scroll to the bottom just to edit the thing you want, and make
it not shitty looking." The Live View outline section (#bhy-rail-demo-outline-
section) previously sat BELOW the real Site tree in the Structure rail pane —
a real problem since the Site tree is a permanent, often-long fixture, meaning
reaching a Live View's outline meant scrolling past all of it first. Reordered
above it instead (class-style-gallery.php's render_left_rail()).

3.4.56 — 2026-07-12 — three more same-session follow-ups on the demo
outline/style feature, in order: (1) "add arbitrary class names and custom CSS
to things as needed" — both the session-only demo-element style panel AND (the
real, persisted version) the real placement's own "Style — Advanced" section
(renderStyleAdvancedSection()) gained an "Extra CSS class(es)" + "Custom CSS"
pair. For real placements this round-trips through
p.config.style.custom_class/custom_css exactly like every other style field —
class-element.php's wrap_placement_html() now reads both at render time
(appended onto the class="..." attribute build_html_attrs() already builds,
and onto whatever BHY_Style::scoped_inline_style() resolved), so it applies on
the real front-end too, not just the live preview. (2)/(3) a genuine
overcorrection, caught immediately by AJ ("Dipshit, the styles still stay in
the inspector, the tree just gets naturally folded into the rail like the
other shit"): an earlier edit this same pass moved BOTH the read-only outline
tree AND its style panel into the left rail.

3.4.55 — 2026-07-12 — live-confirmed fix, straight off AJ's own screenshot
right after 3.4.54 shipped: "styles are not doing their thing" — the canvas
was rendering fully unstyled (black bg, default font, overlapping text). Root
cause: TWO CSS selectors this whole gallery depends on only make sense inside
a real Document, and 3.4.54 swapped every canvas story from a real iframe
document to a shadow root, which has neither a root element nor a <body>
element: (1) BHY_Style::inline_css() prints `:root{--bh-bg:...}` — inside a
shadow root, `:root` matches nothing, so every `var(--bh-*)` reference in
every surface's own CSS silently resolved to nothing.

3.4.54 — 2026-07-12 — two more direct follow-ups on the same demo- outline
feature from 3.4.53, both same-session: (1) "the read only tree should be for
structure of the thing only, we still need to edit the styles of each thing" —
renderDemoOutline() (element-builder.js) keeps the outline tree itself read-
only/structure- only (confirmed correct), but clicking a row now also opens a
style panel for that exact element (background/text color, padding, border-
radius, font size), writing LIVE, SESSION-ONLY inline styles directly to that
DOM node. Explicitly not persisted — these demo mockups have no backing
placement row to save to; the panel says so plainly rather than pretending to
save.

3.4.53 — 2026-07-12 — two pieces, both direct live-feedback follow-ups on the
SAME screenshot: "still not doing what it's supposed to" (picking a demo-only
Live View left the inspector showing a stale, unrelated CRM placement), then
"can we still have 'trees' for the plugin live views?" once the first fix
explained there's genuinely no editable tree for a hand-authored mockup. (1)
element-builder.js's 'bhel:select-surface' listener now sets a new
state.selection.type === 'demo' when the clicked story's surface slug ISN'T a
real registered BH_Element surface (was previously a silent no-op, leaving
stale content on screen) — renderInspector() gained a matching branch that
clearly explains what's being shown and why there's nothing to edit, instead
of just looking broken/unresponsive. (2) new renderDemoOutline() — since these
mockups have no real placement/tree DATA, this builds a genuinely useful
substitute: a READ-ONLY outline tree parsed straight from the canvas iframe's
actual DOM (tag/id/class per row), click-to-scroll+highlight the matching
element in the canvas. class-style-gallery.php's preview_doc() gained one
small injected <style> (.bhel-outline-highlight) INSIDE each iframe's own
document for the highlight to be visible at all — this page's own CSS can
never reach inside an iframe, by design (see the iframe-isolation reasoning
flagged directly to AJ this same pass, in response to "is this shit really
using iframes?" — yes, deliberately, for real style isolation between this
admin page and N different plugins' own real front-end stylesheets; the real
cost of that choice is exactly this class of extra cross-document plumbing).

3.4.52 — 2026-07-12 — direct response to AJ's own "let's be smart about tests"
ask, right after a run of THREE real bugs in the BH_Element/Design Suite
canvas layer were each only caught by a live screenshot round-trip tonight
(the empty-slot wrapper, the doubled REST preview path, the surface-key
mismatch). New class-element-test- suite.php (BH_Element_TestSuite) — same
"runs from Debug Tools, no CLI/PHPUnit needed" pattern every other *_TestSuite
class here already uses — adds regression coverage for the two of those three
bugs that ARE testable from a pure PHP assertion (render_slot()'s empty-slot
wrapper; the color-token schema's colorTokens values being real CSS vars, not
bare names — the shape the new swatch dropdown depends on).

3.4.28 — 2026-07-11 — element-builder.js/.css UX pass over the 3.4.27
inspector: the "Style — Advanced" property groups (§2.6) are now collapsible
disclosures (collapsed by default, auto-open only when a group already carries
an active override) instead of ~11 always-open fieldsets stacked in a row, so
the panel reads as "what's actually customized" at a glance. Added the §2.5
responsive behavior the prior pass's plain 1100px block-stack didn't cover: a
real 3-tier layout (>=1200px full three-pane, 783-1199px palette-as-overlay
behind a toggle button mirroring WP admin's own folded-sidebar convention,
<=782px single-column stack with the inspector as a bottom sheet — drag
handle, "Done" dismiss, slide-up transform — matching WP admin's own 782px
breakpoint) plus a ~44px minimum touch target on every interactive control at
that narrowest width.

3.4.27 — 2026-07-11 — DESIGN-SUITE-UNIFICATION-PLAN.md Phase 2's inspector UI
(§2.6), the one piece 3.4.26 deliberately left unshipped: class-element-
builder.php's GUI (assets/js/element-builder.js/.css) gained a "Style —
Advanced" section (every §2.6 property group as a dynamic preset picker +
custom-value escape hatch) and an "HTML Attributes" section (tag picker, per-
type attr fields, repeatable custom data-* row editor) — both built entirely
from REST-exposed manifests (GET .../elements/types' existing attrs/tags keys,
plus a new GET .../elements/style-schema route backed by BHY_Style::
style_schema_for_js(), class-style.php), never hardcoded per element type or
property group client-side. Both sections write into
config.style/config.htmlAttrs and save through the EXISTING POST
.../placements route unmodified — no REST route changed shape.

3.4.26 — 2026-07-11 — DESIGN-SUITE-UNIFICATION-PLAN.md Phase 2, ALL property
groups shipped in one pass (no [core]/[adv] deferral, per AJ's explicit
instruction): BHY_Style::scoped_inline_style() (class-style.php) resolves a
placement's config.style map — bare --bh-* token keys (§2.3, unchanged) PLUS
new namespaced "group.property" keys (§2.6:
sizing/spacing/background/typography/
border/display+flex+grid/position/effects+transforms/overflow+ visibility) —
into an inline style="" attribute; new safe_length()/ safe_enum() validators
added alongside the existing safe_color()/ safe_number().
BH_Element::render_placement() (class-element.php) now builds each placement's
OWN wrapper element (tag + class + data- placement-id/data-type + the resolved
style + a strictly-allowlisted htmlAttrs set — id/class/title/aria-
label/href+target+rel-when-tag-is- 'a'/custom data-*), moved out of
render_slot() so REST preview (rest_preview()) gets an identical wrapper.
register_type()'s $args contract gained 'attrs' (per-attribute allowlist) and
'tags' (allowed semantic tags, first = default, defaults to ['div']) — GET
/elements/types now exposes both so inspector JS can build controls per-type
dynamically.

3.4.25 — 2026-07-11 — DESIGN-SUITE-UNIFICATION-PLAN.md Phase 1 (menu
restructure only — no inspector unification): new top-level "Design Suite"
menu (bh-design, new class-design-suite.php / BH_Design_Suite) and, in bh-crm,
a new top-level "CRM" menu (bh-crm-hub, new bh-crm/includes/class-hub.php /
BHCRM_Hub). BH_Design_Suite's own top-level/first-submenu callback
deliberately REUSES BHY_Gallery:: render() (the real, working Style page)
rather than a placeholder — there is no unified inspector shell yet (that's
Phase 3), so pointing the new landing page at the one real screen that already
exists is more honest than a stub.

3.4.24 — 2026-07-11 — Remaining ELEMENT-BUILDER-DESIGN-PLAN.md §6 phases: the
Portal registered as a real bh_element_surfaces contributor with one new
element-composed panel (BHI_Portal::
register_element_surface()/register_elements_panel(), class-portal.php); a
real container element type (bh/container, class-element.php) whose content is
an embedded BH_Content subtree — the §1.1 hybrid-nesting bridge into the
EXISTING BH_Studio canvas, not a second tree editor — with
BH_Element::save_placement() auto-assigning content_context_id = the
placement's own id for container types on first save; and a real DELETE
/elements/placements/{id} REST route (class-element.php), closing the one gap
that route's own docblock previously named. Also ships a genuinely NEW
addition beyond the design doc's own scope, per AJ's mid-build request: the
prefab system (new BH_Element_Prefab, class-element-prefab.php; new
bhcore_element_prefabs table, class-identity-activator.php DB_VERSION 1.10) —
named, reusable, deep- copyable saved compositions of placements, with "Save
as Prefab" / prefab-picker controls added to BH_Element_Builder's existing GUI
(assets/js/element-builder.js, assets/css/element-builder.css) — see ELEMENT-
BUILDER-DESIGN-PLAN.md's own trailing status note for the honest "this wasn't
in the original doc" framing.

3.4.23 — 2026-07-11 — Element builder, §4/§6-step-2 GUI phase of ELEMENT-
BUILDER-DESIGN-PLAN.md: new BH_Element_Builder (class-element-builder.php) — a
three-pane visual builder (palette / canvas / inspector) cloned from
BHY_Gallery's Storybook layout, shipped as a NEW, additive Debug Tools section
("Element Builder (Visual)") rather than a standalone admin page, per this
install's documented hook-resolution bug affecting standalone/submenu-of- ous-
debug pages (see class-api-docs.php's docblock and this class's own docblock
for the incident). New assets/js/element-builder.js + assets/css/element-
builder.css (no build step, vanilla JS, enqueued only on the Debug Tools
screen).

3.4.19 — Debug Tools reorganization pass: added an optional 'group' key to the
ous_debug_tools registration array shape (self::GROUP_* constants in class-
debug.php) so sections render bucketed by purpose (Monitoring & Health /
Reference & Docs / Seed & Reset Tools / Diagnostics & Tools default) instead
of flat registration order, with a grouped "Jump to" quicknav to match. Purely
additive — no existing add_filter('ous_debug_tools', ...) call site had to
change shape to keep working; every current registrant was also updated to set
an explicit 'group' so the new grouping takes effect immediately.

3.4.15 — confirmed via Query Monitor (capability-checks + admin-screen panels,
installed temporarily on the live site): the standalone admin.php?page=ous-
api-docs / ous-codebase-docs pages fail because WordPress's own
get_current_screen()/hook_suffix resolution falls back to the PARENT page's
hook instead of the submenu's, on every request, regardless of correct
registration/capability — a genuine WordPress-core page-hook lookup issue, not
caching or capabilities (the two things chased hardest earlier). Since the
Debug Tools SECTION versions of both pages are confirmed working end to end,
the two standalone add_menu() registrations are now unhooked entirely (methods
left defined, just not called) rather than left as dead, permanently-broken
links sitting in the sidebar.

3.4.14 — Stopped chasing the standalone-page access-denial bug (registration
and capability both confirmed correct via logging, yet WordPress still blocked
admin.php?page=ous-api-docs / ous-codebase-docs every time — root cause never
found despite five diagnostic passes) and sidestepped it instead: both API
Docs and Codebase Docs now render their REAL content as sections directly on
the Debug Tools page (ous-debug), the one page that has never once failed to
load all session. class-api-docs.php's render_debug_section() (previously just
a diagnostic panel) and class-codebase-docs.php's new render_section() both
call a shared render_content() method, factored out of each class's standalone
render() so neither duplicates the actual body markup. Debug Tools' own "API
Docs"/"Codebase Docs" buttons now jump to these sections (#ous-section-api-
docs / #ous-section-codebase-docs) instead of linking to the still-broken
standalone pages, which remain registered as a secondary access point but
should not be relied on.

3.4.13 — CONFIRMED via 3.4.12's render()-entry log: render() never runs at all
for Codebase Docs — WordPress is blocking the request at its own core dispatch
level (the $_wp_submenu_nopriv mechanism: add_submenu_page() checks
current_user_can() at the MOMENT it's called, on that specific request, and
silently marks the page no-priv if it fails then — separate from the page
callback entirely). Un-throttled the registration log and added the exact
request URI + a same-request current_user_can() reading, specifically so the
entry from the real failing click (not a nearby unrelated page load) is
unambiguous.

3.4.12 — 3.4.11's is_locked()-gate removal confirmed NOT the fix (user reports
no change in behavior). Added the one truly decisive diagnostic left: a log
line as the literal first statement inside render() itself for both classes —
this settles, once and for all, whether WordPress is blocking the request
before OUR code ever runs (a genuine core-level gate this session hasn't found
the cause of yet) or whether the callback IS running and something inside it
is the actual problem.

3.4.11 — API Docs / Codebase Docs "not allowed" bug, actual fix (not another
diagnostic pass): found that both were the ONLY two admin pages anywhere in
this ecosystem that wrapped their own add_submenu_page() call in an
is_locked() check before registering. Every other page (Debug Tools itself,
Job Queue, every peer plugin's screens) registers unconditionally —
is_locked() exists to gate DESTRUCTIVE seed/reset actions, not a read-only
viewer page's mere existence in the menu, so conditionally skipping
registration was the wrong design from the start, independent of whatever
is_locked() itself was actually evaluating to on any given request.

3.4.10 — PHP restart on the live site confirmed OPcache was serving stale
compiled code (explains several earlier "this fix didn't seem to take effect"
moments this session) — after restarting, add_submenu_page() for both API Docs
and Codebase Docs now confirmed returning a real hook_suffix, not FALSE.
Registration is NOT the problem.

3.4.9 — API Docs / Codebase Docs still 404 with is_locked() confirmed NOT the
cause (zero log entries even from the locked-branch logging 3.4.5/this pass
added, meaning that branch never ran — but that was ambiguous, since the
SUCCESS branch had no logging either, so "no log" couldn't distinguish "never
called" from "ran fine"). Added logging to the success path too: both
add_menu() methods now log whatever add_submenu_page() actually returned (a
real hook suffix string, or FALSE on a genuine registration failure) every
time they run, closing that ambiguity for the next reload.

3.4.8 — 3.4.7's own Portal fix had a real side effect: calling add_rewrite()
synchronously at 'init' priority 10 meant its force_flush_and_verify() could
run before other plugins' own default-priority rewrite registrations, and its
unconditional wp_cache_flush() wiped the WHOLE object cache mid-request — very
likely why API Docs started intermittently 404ing right after 3.4.7 shipped
(is_locked()'s cached host checks, read later in the same request, got yanked
out from under it). Fixed two ways: add_rewrite() is now deferred to 'init'
priority 20 (still the same request/pass, just after other plugins' default-
priority rewrite rules have registered), and wp_cache_flush() is now an
ESCALATION only reached if the cheaper targeted cache evictions didn't already
fix it, not called unconditionally on every throttled self-heal attempt.

3.4.7 — Portal's /account/ 404, finally actually found (not another caching-
layer guess): class-portal.php's own init() was hooking add_rewrite() onto
'init' FROM INSIDE a callback that is itself currently running as part of
'init' (the-self-hosted-self.php's own add_action('init', ['BHI_Portal','init']) at
default priority 10). PHP's foreach over that priority's callback array is a
snapshot taken when iteration starts; a handler appended to the SAME priority
after iteration has already begun isn't picked up until 'init' fires again —
which, on a normal page load, never happens in that request.

3.4.6 — OUS_Jobs can now run on the REAL Action Scheduler library (Apache-2.0,
github.com/woocommerce/action-scheduler — the same library WooCommerce itself
bundles) instead of only its own hand-rolled wpdb-table queue. A one-click
"Install Action Scheduler" button on Debug Tools -> Job Queue downloads the
actual official release directly from GitHub onto the LIVE site (this dev
sandbox has no outbound network access at all, confirmed by testing — so the
library could not be vendored directly from here; fabricating placeholder code
under a real project's name would be dishonest, so a real installer was built
instead, same download_url()/unzip_file() mechanism OUS_Registry already uses
for WooCommerce). register()/ enqueue() delegate to Action Scheduler's native
add_action()/ as_enqueue_async_action() once installed, with ZERO call-site
changes needed anywhere bh-registry/bh-streaming/etc. already call OUS_Jobs —
until installed, every existing call transparently keeps using the original
table-backed implementation exactly as before.

3.4.5 — real bug fix + new feature. (1) bh-contest's Live Console dropdown
403'd because its GET form dropped post_type on submit — see bh-contest 3.1.3
for the fix; the-self-hosted-self itself was audited alongside it (bh-contest, BHY_*
styles, bh-crm, Debug Tools) for the same bug class and no other instance was
found. (2) New OUS_CodebaseDocs (class-codebase-docs.php, "The Self-Hosted Self →
Codebase Docs"): renders CODEBASE-WALKTHROUGH.md as real in-admin HTML, and
turns every file-path mention in that doc into a "View live code" toggle that
fetches the file's ACTUAL current contents via a locked-down AJAX endpoint
(realpath()-verified inside the plugins root, manage_options- gated, nonce-
checked) — so the walkthrough can never silently drift from the real code the
way a pasted-in snippet would. Deliberately left OUS_ApiDocs' existing
dependency-free viewer alone rather than swapping in a Swagger-UI bundle, to
keep this ecosystem's own "no external JS/CDN" viewer convention intact; the
two pages cross-link instead.

3.6.6 — Design Suite cleanup pass, AJ's own "bloated weird GUI and remnants of
stuff" report: (1) Real leftover test data found and deleted directly from
wp_bhcore_element_placements (id 3, a stray "bh/note" placement with literal
text "rety78" styled in the accent color) — it was rendering live inside the
bh_crm_profile surface's Design Suite preview since that surface renders REAL
placements, not a mockup, and context 0 is the preview-only default context no
real user profile ever uses. (2) Two real dead links fixed (class-
dashboard.php, class-portal.php): both pointed at admin.php?page=bh-element-
builder, a page deleted in an earlier cleanup pass and never replaced. The
dashboard one now correctly points at Debug Tools' own real, functioning
Element Builder section (a genuine add/remove/ reorder list, just scoped to
dashboard/main); the portal one honestly states no admin UI exists for that
surface/slot, since Debug Tools' section doesn't cover it. (3) Inspector UX
fix, AJ's own follow-up ask: the "Live token preview" panel never had any real
connection to whichever surface was selected in the canvas (correctly so —
these are genuine GLOBAL tokens, one theme applied everywhere, never per-
surface theming) but the UI never SAID that, reading as if something was
broken/disconnected.


3.6.5 — new OUS_StyleSurface (includes/class-style-surface.php): registers the
Media & CDN Setup wizard into the Design Suite gallery — the-self-hosted-self had ZERO
bhy_style_surfaces of its own before this, so its own "it just works" wizard
was invisible to the token editor. Real contrast bug caught live and fixed in
the same pass: preview_doc()'s own `:host{color:var(--bh-text)}` (correct for
every OTHER surface, which uses the dark brand theme) left this wizard's
genuinely light wp-admin-style preview with light-on-light text, since --bh-
text is a light color on the default dark theme — fixed by setting this
preview's own explicit text color rather than inheriting the brand theme's.


3.6.4 — real "wonky character" bug in the Design Suite gallery, caught live:
em-dashes/curly-quotes in a surface's preview HTML (e.g. bh-crm's own live-
slot instructional text) rendered as garbled characters ("â€" instead of "—").
Root cause: class- style-gallery.php's own JS decoded each surface's
base64-encoded preview document with plain atob(), which returns a raw binary
string (one JS character per BYTE, not a properly UTF-8-decoded string) — any
multi-byte character came through as 2-3 separate mis-rendered characters the
moment DOMParser parsed that raw byte string as text.

3.6.3 — real production fatal, caught live on the billyhume.wasmer.app deploy:
"Uncaught Error: Class ActionScheduler not found" in class-jobs.php, site-wide
500 on every request. Root cause: action- scheduler.php's own bootstrap
doesn't define the ActionScheduler facade class synchronously — it defers to a
'plugins_loaded' priority-1 callback it registers itself.

3.6.2 — new OUS_Metrics (includes/class-metrics.php): the shared creator-
dashboard VISION.md's own roadmap has named since before this pass, built now
as real foundational infrastructure rather than a later bolt-on — AJ's own
explicit ask to grow this in tandem with bh-courses/bh-contest/bh-crm. Pure
read/aggregate layer over bhcore_events (BH_Event's own table); writes nothing
new.

3.6.1 — Slice 1 of ROADMAP-discoverability.md: new BH_SEO (includes/class-
seo.php), a shared meta/OG/Twitter-Card/JSON-LD renderer plus an /llms.txt
endpoint — a full grep beforehand confirmed zero meta/OG/schema.org output
existed anywhere in this ecosystem. Reference consumer: BHI_PublicProfile's
public profile view now calls BH_SEO::set_page_data() with a real schema.org
Person block.


3.6.0 — Tier A of ROADMAP-guided-setup-wizards.md, built for real:
OUS_MediaWizard (new includes/class-media-wizard.php), a guided media/CDN
setup screen wrapping the already-installed Advanced Media Offloader plugin —
six providers (Cloudflare R2 recommended by default, Amazon S3, Backblaze B2,
DigitalOcean Spaces, Wasabi, and generic S3-compatible), each with plain-
language tradeoffs, a direct deep link to that provider's own credentials
dashboard, and a REAL live connection test (reuses ADVMO's own provider
classes' checkConnection() — an actual headBucket() API call, never a format-
only check) on save. Writes directly into ADVMO's own
advmo_settings/advmo_credentials option shape, confirmed correct by reading
GeneralSettings::sanitize()/sanitize_credentials() first.


3.5.9 — Real bug in 3.5.8's own player-bar fix, caught live by AJ ("definitely
isn't at the bottom when the player bar is gone"): the --bh-bar-height CSS-
variable approach was wrong because bh-contest's player.css also loads on
Archive/Results-Reveal-only pages (shared fonts/theme vars) that never render
the actual .bh-now-playing-bar element — :root still defined the property
regardless, leaving a phantom ~84px gap under the button on pages with no bar
to justify it. Replaced with real DOM detection: JS checks for .bh-now-
playing-bar's actual presence and measured height (not a hardcoded number),
re-checked on resize and via a MutationObserver (the bar is built client-side
by player.js, not server-rendered, so script-order timing isn't guaranteed).


3.5.8 — Two AJ-flagged fixes to the technical-report widget. (1) It was
colliding with bh-contest's fixed bottom player bar on contest pages. Fixed
via CSS only, zero JS/DOM-detection needed: reads the same --bh-bar-height
custom property bh-contest's own player.css already sets on :root (its .bh-
toast component already positions itself above the bar the identical way) —
cascades globally regardless of which stylesheet defined it, and the var()
fallback (0px) means pages without that property behave exactly as before.


3.5.7 — Admin-menu-cleanup pass, item 1: Debug Tools' per-user "developer
mode" gate. The audit's #1 flagged organizational problem — ~17 accumulated
sections always visible to any manage_options user, mixing genuinely useful
monitoring tools with pure dev/QA scaffolding.


3.5.6 — Log-pollution fix, flagged by AJ directly ("I just notice the logs get
polluted quickly right now"). Traced to the exact same pattern copied across 5
files (class-menu-merge.php, class-hub.php [bh-crm], class-studio.php, class-
design-suite.php, class-style- gallery.php): every one of them logged an INFO
row for a SUCCESSFUL admin-menu registration, throttled only to once per 60
SECONDS, on every single admin page load — with OUS_DebugLog::MAX_ROWS capped
at 1000, this filled the whole log within a handful of admin page visits,
crowding out genuinely rare warning/error rows.


3.5.5 — Enriched the "report a technical difficulty" widget with real
diagnostic context, per AJ's own follow-up ask. Two additions on top of the
existing page-URL/browser capture: (1) a coarse "feature area" guess (BH
Courses lesson/catalog, BH Contest player, BH Streaming player, portal UI)
from known DOM root markers already present on the page — not a claim about
which FILE is involved (this is client-side, it can't know that), but a real
triage hint instead of making an admin guess from the URL alone. (2) A capped
(last 12), sessionStorage-backed recent-action trail — every clicked
button/link's visible label plus a relative timestamp, recording from page
load (not just from when the widget opens), so a report filed a page or two
after the actual problem still shows the path that led there.

3.5.4 — Ecosystem-wide "report a technical difficulty" widget, AJ's own ask.
Reuses the existing BHI_Reports moderation queue (a new 'technical' category +
the existing bhi/v1/reports REST endpoint) instead of standing up a second,
parallel admin screen — every other report category requires a real
target_type+target_id (a piece of content to point at); a bug report has none,
so rest_submit() now allows target_id=0 specifically for the 'technical'
category, and the admin queue's Target column shows a real label for it
instead of a bare "technical #0".

3.5.3 — Two more BH_ShareCard styles: 'poster-frame' (centered type, bordered
inset frame with corner registration-mark ticks) and 'poster-block' (a solid
color block with a reversed-color eyebrow tag and a big single-letter
monogram, title continuing onto the dark remainder) — genuinely distinct
compositions, not recolors of the existing diagonal-band 'poster'. New STYLES
const is the one place a style gets registered/labeled now, so consuming
plugins' picker UIs read off it instead of each hardcoding their own copy of
"which styles exist." Verified by rendering both to real PNGs and looking at
them.

3.5.2 — New shared BH_ShareCard engine (includes/class-share-card.php):
server-side generated (PHP GD, no headless browser/external service) 1200x630
social-share PNG cards, two selectable visual styles — 'brand' reads the
site's live BH_Style palette; 'poster' is a deliberately louder, stand-alone
look (Bebas Neue on a diagonal accent band) independent of whatever theme
preset is active. Two new vendored OFL-licensed fonts (assets/fonts/:
BebasNeue-Regular.ttf, WorkSans-Variable.ttf — the latter fetched as Google
Fonts' current variable-font release since the old static-weight files were
removed upstream; GD renders its default instance fine, faux-bold via a 1px-
offset double-draw where a bolder weight is wanted).

3.4.87 — QA fix: a full ecosystem-wide re-audit of every hook-timing fix
claimed this session (both the "nested init callback silently never fires" bug
class and the "wp_register_script() called too early" bug class) found one
genuine incomplete-fix regression — the 3.4.85 changelog claimed
OUS_Gutenberg_Block::init() was fixed alongside
BH_Event/BH_Identity/OUS_Toast, and the METHOD BODY fix was real (init() calls
register_block() directly, no nested 'init' hook), but the actual call site
that invokes init() was never added anywhere — the class was still required
via this file's own foreach loop, but nothing ever called
OUS_Gutenberg_Block::init(), so register_block() never ran at all. Currently a
double no-op either way (its own class_exists('BH_Element_Prefab') guard is
false post-page-builder- delete), but wrong regardless, and would have
silently stayed unregistered with zero error if that class ever came back.

3.4.86 — QA fix, part of an ecosystem-wide sweep for the same
idempotency/ordering bug classes just caught live in bh-crm's new notes-with-
reminders feature (this same session). bhcore_notifications gained an
email_sent column (class-identity-activator.php, DB_VERSION 1.12 -> 1.13) and
OUS_Notifications::send_queued_email() now claims it atomically (UPDATE ...
WHERE email_sent = 0) before actually mailing — the queued email job can
genuinely fire more than once (confirmed for the near-identical bh-crm
reminder job: a manual test call plus Action Scheduler's own real background
processing of the same scheduled job both ran it), and without this fix that
would have meant a duplicate email with zero guard against it.

3.4.18 — new ecosystem-wide toast notification system: OUS_Toast (class-
toast.php, new) + assets/js/toast.js + assets/css/toast.css. A real, no-build-
step, dependency-free BHCoreToast.show(message, type) JS renderer (fixed top-
right stack, auto-dismiss + manual close, role="status"/aria-live="polite"),
enqueued globally on both admin_enqueue_scripts and wp_enqueue_scripts so any
plugin in the ecosystem can call it from its own JS with zero setup.

3.4.17 — added BH_Identity::client_ids_for_user() (class-identity.php), a
reverse lookup from user_id to the distinct client_id values already stamped
on that user's own bhcore_events rows (there is no separate stitching table —
see that class's docblock for why this is NOT a join against dedicated
storage). Added to support bh-crm's new event-activity consumer (bh-
crm/includes/class-event-activity.php, bh-crm 1.0.0 -> 1.1.0), which was wired
to bh_crm_activity_summary this pass.

3.4.16 — hardened OUS_Jobs::handle_install_action_scheduler() (the "Install
Action Scheduler" Debug Tools button, which a user reported did nothing when
clicked): WP_Filesystem() and wp_mkdir_p() return values are now checked
instead of ignored, download_url()'s full WP_Error message is surfaced
verbatim (was already true before, kept so on purpose — helps diagnose Local-
by-Flywheel's known outbound-SSL quirks), and success is no longer declared
unless file_exists(OUS_Jobs::vendor_path()) is genuinely true after the move.
Also added OUS_Dashboard's new Job Queue + Query Monitor status block (class-
dashboard.php render()) and the new BH_Event/BH_Identity event-tracking layer
(class-event.php, class-identity.php — see EVENT-TRACKING-ARCHITECTURE-
PLAN.md, previously designed but never implemented) wired into the require-
loop/init hooks below.

superseded — kept only so a stray duplicate define() below this point
(a recurring mistake this session) is easy to spot if it recurs:
3.4.4 — new OUS_ReliabilityTestSuite (class-reliability-test-suite.php), the
first test coverage for OUS_ReliableStore and OUS_DebugLog::log_throttled() —
both previously untested despite now being load-bearing (BHI_Auth's security
throttles, the whole diagnostic-logging pipeline this session built out). Runs
against the real options table with tagged/prefixed keys, cleaned up at the
end of every run.

3.4.3 — continuation logging pass (per audit): BHI_Auth::register()'s
wp_create_user() failure now logs the real WP_Error instead of discarding it.
Standing caveat: reasoning/brace-balance-checked only.

3.4.2 — Portal's /account/ 404 is still unresolved on the live install per
direct user report (rewrite rule confirmed missing every reload, but ZERO
Portal log entries at all — not even the throttled "still broken" warning that
should have fired at least once by now). Per explicit user direction, NOT
chasing this further right now (it's not blocking other work) and NOT treating
BHI_Portal's fix as a working reference elsewhere — but added one cheap,
always-throttled diagnostic breadcrumb at the very top of add_rewrite() so the
next person looking at this (me or the user) can tell in one page load whether
the method is even being entered, rather than re-deriving that from scratch.

3.4.1 — Debug Tools sections are now real <details>/<summary> collapsibles,
closed by default (the page is long enough with a dozen-plus registered
sections that scrolling past all of them to find one is real friction), with
each section's open/closed state remembered per-browser via localStorage so it
doesn't reset every page load. Deliberately localStorage, not a server-side
per-user option — this is cosmetic UI state, not anything that needs to
survive across devices or matters if lost, and per this session's whole
object-cache saga, sidestepping server-side persistence entirely for something
this low-stakes is the more robust choice on an install whose cache layer has
already proven unreliable more than once.

3.4.0 — a real, live-reported bug ("nothing is displayed with the tests")
traced to the same root cause as this whole session's Portal/ API-Docs saga:
set_transient()/get_transient() are backed entirely by this install's
persistent object cache when one is active, and that cache is unreliable here
— a transient write can report success while the very next request's read sees
nothing. New class-reliable-store.php (OUS_ReliableStore) consolidates the
direct-DB-bypass-the-cache pattern this session kept hand-rolling ad-hoc
(BHI_Portal's throttle, OUS_TestRunner's first fix) into one shared,
documented utility.

3.3.9 — two things: (1) real bug found in 3.3.8's own anchor-scroll fix — the
sticky admin bar + this page's own sticky quick-nav both cover the top of the
viewport, so a native browser anchor-jump landed the target section's heading
BEHIND them, which looked identical to "still stuck at the top" (exactly what
got reported after 3.3.8 shipped). Fixed with scroll-margin-top on every
section plus a JS scrollIntoView + brief highlight flash as a second,
independent safety net. (2) Added BHI_Profiles::user_ids_with_profile_data()
per QA-REPORT-code-quality.md's cross-plugin finding #2 — bh-crm's class-
people.php and class-export.php both ran identical raw SQL against this table
directly instead of through the class that owns it; a pure extraction, no
behavior change.

3.3.8 — Debug Tools page UX fix (explicit user report: running a test or
clicking any button jumped back to the page TOP instead of staying near the
result, and the page is long enough that this meant re-scrolling every single
time). OUS_Debug::redirect() now carries a per-section anchor (every section
already has/gained a stable 'ous-section-{key}' id) so a button click lands
you back exactly where you clicked from — results were already rendered
colocated inside their own section (Test Runner's own transient-backed report,
e.g.), the only missing piece was the redirect itself dropping the anchor.

3.3.7 — request-correlation IDs shipped end to end: bhcore_debug_log gained a
request_id column (BHI_Activator::DB_VERSION 1.6 -> 1.7),
OUS_DebugLog::request_id() generates one short ID per PHP request and stamps
it onto every log() call automatically (no call-site changes needed anywhere
in the ecosystem), and Console & Logs gained a Request ID filter plus a
clickable chip on every row that jumps straight to "everything else that
happened during this exact request." Degrades safely on an install that hasn't
migrated yet (has_request_id_column() checks the live schema, not just the
stored DB_VERSION, before including the column in any insert — a not-yet-
migrated install keeps logging, just without correlation IDs, rather than
every log() call failing on an unknown-column error).

3.3.6 — first slice of a deliberately larger, ongoing logging-depth push
(explicit user direction: debugging/logging needs to be "airtight" across the
whole ecosystem, not just the Portal/API Docs incident that started this).
This pass: BHI_Two_Factor::ajax_disable() now logs a security-relevant account
change (2FA disabled) that previously left zero audit trail;
BHI_Two_Factor::gate_login() now logs a real wrong- code attempt (throttled
per-user), previously invisible.

3.3.5 — closes the real diagnostic gap the 3.3.3/3.3.4 back-and-forth exposed:
both fixes only logged on FAILURE, so an empty Console & Logs table was
ambiguous between "checked every request and genuinely fine" and "stopped
running/self-healing entirely" — precisely the state the 3.3.4 throttle bug
produced and that made it undiagnosable from log data alone. Added
OUS_DebugLog::log_throttled() (logs at most once per N seconds per key,
regardless of outcome) and wired it into OUS_Debug::is_locked() and
BHI_Portal::add_rewrite() so a PASSING check now also leaves a periodic trace,
and a check that's sitting out a throttle window while still broken logs THAT
state explicitly (at 'warning') instead of silently doing nothing. "No log
entries for this key in the last several minutes" is now itself a real,
actionable signal — the check isn't running at all — rather than an empty
table meaning nothing in particular. (see class-debug-log.php's own docblock
for log_throttled() usage — intended for any check that runs on every request
across this ecosystem, not just these two.).

3.3.4 — real bug found in 3.3.3's own fix: BHI_Portal's rewrite self-heal
throttle used get_transient()/set_transient(), which on an install with a
persistent object cache active stores the transient IN that same cache —
exactly the layer this whole fix exists to not trust. A stuck/broken cache
could make the throttle read "already attempted" forever, silently skipping
the self-heal on every request with zero log trace, which is indistinguishable
from "working, just waiting" from the outside.

3.3.3 — fixed the real reported bug: BHI_Portal's /account/ 404 and API Docs'
"not allowed to access this page" both came from user-facing symptoms of the
SAME underlying pattern (a persistent object cache serving stale option reads
across requests — confirmed on this specific install via each class's own
Debug Tools diagnostic). Both previously relied on a one-shot "did this
already run" flag that could mark itself successful without the write actually
having persisted, requiring a manual Settings -> Permalinks -> Save to fix.
