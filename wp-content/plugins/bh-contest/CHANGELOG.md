# Changelog — BH Contest

Moved out of `bh-contest.php` on 2026-08-23. See `CONVENTIONS.md` for why version history lives here and in git rather than in source.

Entries are newest-first, exactly as written in-file. Nothing reworded or dropped.

---

3.8.0 — Contest Settings metabox rebuild: moved from the narrow 'side'
column to a real 'normal'-context grid (`.bhc-settings-grid`,
`auto-fit`/`minmax(230px, 1fr)`) so Submissions/Contact info/Voting/
Results/Discord render as `BHY_UI::card()` panels instead of one
cramped stacked column. Phase banner and the client-side status dot now
use real `bhy-badge` classes (variant + dot) instead of ad-hoc inline
color styles. Hit and fixed, twice, the exact "apostrophe inside a
comment inside a single-quoted PHP string" parse-error class this
repo's own CLAUDE.md documents as a historical incident — both times
caught immediately by `php -l` before reaching a live page; the
reasoning now lives in a real PHP `//` comment above the `echo
'<style>'` block, never inside the CSS string itself. Also fixed a
follow-up checkbox-rendering bug from that same pass: WP core's own
forms.css already sets `appearance: none` on `input[type="checkbox"]`
and draws the checkmark via a `::before` background-image sized to its
own default 1rem box, so the `accent-color`/explicit `width`/`height`/
`margin` overrides I'd added had zero visual effect from accent-color
and fought the checkmark's calibrated positioning from the size/margin
overrides — removed entirely rather than patched, letting WP core's own
checkbox styling apply unmodified. Verified live at 1440px/localhost:
`getComputedStyle()` confirms checkboxes inside the grid are now
byte-identical (aside from WP's own `:checked` border-color state) to a
stock WP checkbox elsewhere on the same page, and a screenshot confirms
correct blue checkmarks on checked boxes with no visual glitch. Every
original field name/id/JS hook preserved unchanged. `php -l`, PHPStan,
and `composer test` all clean.

3.7.33 — HOTFIX: live went down on every admin page with "Call to
undefined method OUS_Pages::ensure()" in bh-courses' class-activator.php.
Version skew — bh-courses deployed with the new ensure() call while
core on that install was still older — and the cross-plugin guard
couldn't see it: `class_exists('OUS_Pages')` returned true (the class
was there), only the METHOD was missing, so the call fatalled anyway.
"Is this plugin installed" and "is this plugin new enough" are
different questions, and only the second is what a call actually
depends on. Every peer call into OUS_Pages now names the method it
needs via `method_exists()`, not `class_exists()` — five sites across
bh-courses and bh-contest, covering ensure() and url(). Proven against
a simulated skew: a core exposing url() but not ensure() correctly
lets the ensure()-dependent call return early with no fatal, while the
url()-dependent calls keep working — the old class_exists guard would
have gone straight into the crash. Recorded in CONVENTIONS.md as a
standing rule, since a peer plugin deploying ahead of core is the
normal state on a host that syncs plugin folders independently.

Also in this pass: a hover bridge between a sidebar item and its
flyout — a transparent ::before spans the ~14px dead space between a
160px sidebar item (ends at 153px) and its flyout panel (starts at
167px), so crossing that gap no longer closes the flyout under the
pointer. Verified: all five probe points across the former dead zone
now hit-test inside the submenu, flyout position unchanged.

3.7.32 — One shared catalog for courses and contests, on a real
front-end elevation scale. The two catalogs (the screens a visitor
spends the most time browsing) had drifted on every axis: grid min
(220px vs 260px), gap (20px hardcoded vs a token-driven calc), radius
(10px hardcoded vs a token), depth (a hardcoded shadow vs none at
all), hover (lift + accent glow vs none at all). Two catalogs on one
site shouldn't disagree about what a card is. Foundation first:
BHY_Style emitted `--bh-radius`/`--bh-space-scale`/accent tokens but no
elevation tokens at all — exactly why one catalog hardcoded a shadow
and the other had none. `--bh-shadow-sm`/`-shadow`/`-shadow-lg` now
ship alongside the admin scale added earlier, alpha tuned between the
admin light/dark values since the front-end palette is user-
configurable.

3.7.31 — Single contest and course pages get a way back to their
catalog. A single course or contest was a dead end — nothing on the
page led back to the catalog it came from, only the browser Back
button or the site menu. Three partial answers had grown up
independently for "which page is the catalog": bh-contest stored a
`bh_archive_page_id` option at activation AND separately ran a
`get_posts()` search for the literal shortcode; bh-monetization-woo
remembered its pages on `save_post`; bh-courses had no way to find its
own catalog at all — each misses a real case (an option only exists if
activation created the page, a save_post hook only learns about a page
when someone re-saves it, neither knows about a page an author made by
hand). New `OUS_Pages` tries the recorded option first (verifying it
still exists and is published), falls back to searching page content
for the shortcode or an equivalent block, then caches for a day and
flushes on any page save/delete. Both call sites are
`class_exists()`-guarded at hook-call time.

3.7.30 — Same SEO-timing bug found and fixed on bh-courses this
session, same fix here: BH_Auth::render()'s SEO block only ever ran
during the_content() (the [bh_contest_player] shortcode's own
render), always after wp_head — where BH_SEO actually echoes its
tags — has already fired. Extracted the SEO-setting logic into its
own set_seo_data($atts) method and added a template_redirect hook
that looks up the shortcode's own attributes on the current page
early, via BH_SEO::shortcode_atts_on_current_page() (the-self-hosted-self) —
confirmed live, a real contest-embedding page now renders a real
Event schema.org JSON-LD and meta description instead of nothing.

3.7.29 — Not a behavior change: live-verified the Reveal Party's
already-shipped anime.js v4 integration (reveal.js) against the
exact vendored bundle rather than trusting the doc-inference the
comment there had flagged as unconfirmed. Loaded the real file in a
real browser and ran the exact calls this file makes in isolation:
opacity/y/scale all interpolate correctly frame-to-frame, 'outCubic'
and 'inOutQuad' produce real eased curves (not a silent fallback to
linear), and anime.stagger(90) returns a real per-index delay
function (staggered elements sampled mid-animation showed the first
already animating while later ones hadn't started, exactly as
intended). The file's own "not independently verified" caveat is
resolved and updated to record what was actually checked.

3.7.28 — Real UX gap found live: clicking "Contests" itself (the
synced menu group's own label, not a child contest) went nowhere —
OUS_MenuSync::sync_group() (the-self-hosted-self 3.10.23) now takes an
optional real URL for the group parent. Unlike bh-courses, this CPT
has no native archive URL — BH_Archive's own docblock deliberately
treats [bh_archive] as a single unified library spanning every
contest, placed on whatever real page an admin chooses, not an
auto-created page the way an individual contest gets one. New
BH_AdminMenus::archive_page_url() (class-admin-menus.php) finds a
real, published page actually using that shortcode and links there
if one exists; leaves the parent at '#' (no worse than before this
fix) if none does, rather than guessing a URL that might not exist
on a given install.

php -l clean, scoped PHPStan level 6 clean. NOT runtime-verified
against a live install by this commit alone.

3.7.27 — New "Access" metabox (class-admin-metaboxes.php, side column
next to Site Menu): a real, explicit per-contest opt-IN for
restricting a contest to logged-in members only, via the-self-hosted-self's
new OUS_Visibility (3.10.22, class-visibility.php)
can_view_open_by_default()/members_only_checkbox_field()/
save_members_only_from_request(). Off by default — a contest stays
exactly as publicly viewable/shareable as it's always been (that's
how voting/engagement is meant to work; voting itself already
requires an account regardless of this setting), the opposite
default polarity from bh-courses' new login-required-by-default
posture, a deliberate difference explained in OUS_Visibility's own
docblock.

Real security completeness, not just a UI toggle: BH_Auth::render()
(class-auth.php) now checks the same gate before building the player
markup at all, AND class-api.php's tracks()/results() REST endpoints
(the actual data the player fetches after loading) refuse a
members-only contest's submissions/results to a logged-out request —
gating only the shortcode's initial HTML would have left the real
data reachable by hitting those endpoints directly.

php -l clean, scoped PHPStan level 6 clean (full-ecosystem PHPStan
level 6 also re-run clean after this change, zero errors, zero new
ignores). NOT runtime-verified against a live install by this commit
alone — verify by toggling "Members only" on a test contest and
confirming a logged-out visitor sees a login prompt instead of the
player, with the same result hitting bh/v1/tracks directly.

3.7.26 — Added a help tooltip (BHY_UI::tip(), the-self-hosted-self 3.10.15) to
a round's "Cut to" field on the Rounds (elimination format) metabox,
clarifying that entries which don't advance are never deleted — they
stay in Submissions, just excluded from later rounds' voting. A real
point of anxiety for a contest admin cutting a round for the first
time. Part of this session's first pass at in-context tooltips, not
a full sweep.

3.7.25 — Real bug fix found while investigating a Phase 4 dead-code-
triage flag (the-self-hosted-self BH_Rounds::is_new_submission_allowed() had
no caller anywhere). class-rounds.php's own docblock names this
method "the real gate" for whether a NEW track can be submitted, but
class-api.php's submit() REST handler was calling the round-unaware
BH_Helpers::is_submission_open() instead — which only ever checks
the contest's original _bh_sub_start/_bh_sub_end window. For a
multi-round contest sitting in round 1+, that meant a brand-new
submission could sneak in through the original window even though
round 2+'s "entrants" are supposed to be round-1 survivors only, not
open enrollment (see class-rounds.php's is_new_submission_allowed()
docblock). Fixed submit() to use
`class_exists('BH_Rounds') ? BH_Rounds::is_new_submission_allowed($cid)
: BH_Helpers::is_submission_open($cid)`, matching the same
class_exists()-guarded pattern vote() already uses for
BH_Rounds::is_voting_open(). replace_audio()/edit_details() were
deliberately left on the plain BH_Helpers gate — those edit an
EXISTING submission, not create a new entrant, so round-awareness
doesn't apply the same way. NOT runtime-verified against a live
install; this only matters for contests that actually configure 2+
rounds (ROADMAP-ux-polish-and-feature-parity-2026-07.md 2b), which
per this repo's own docs is a newer, less-exercised feature.

3.7.24 — TypeScript pilot: converted player.js (the embedded contest
player — track list/voting/audio playback, sign-up/login, submission
upload, share cards, results), the first of the two large/risky
files deliberately deferred from the earlier pilot rounds. Real
typed instance properties on `class BHPlayer`, real method
signatures throughout, no @ts-nocheck. Howler.js gets a minimal
local `declare class Howl` (just the constructor options/instance
methods this file actually calls), not a full @types/howler
pull-in, matching this pilot's existing "declare only what's used"
convention. The REST responses this file reads (bh/v1 tracks/vote/
submit/results, bhi/v1 login/register/profile) are typed per-
endpoint from what the code actually reads off each response.
Every compiled assets/js/player.js diff was reviewed line-by-line
against the pre-conversion file — the only behavioral deltas are
type-safety-driven casts that don't change runtime behavior
(isNaN(d.getTime()) instead of isNaN(d) on a Date, explicit
String() coercion on numeric values assigned to input.value, which
JS already coerced implicitly either way) — no logic changed.
bh-streaming's player.js remains deferred — a materially harder
conversion (one flat IIFE, no class to hang types on) getting its
own dedicated pass.
NOT runtime-verified against a live browser this session.

3.7.23 — Ecosystem quality Phase 2, brick 10/13: bh-contest is now
clean at PHPStan level 6 (native return/parameter types + precise
array-shape PHPDoc throughout every file in includes/, no shortcuts).
The largest single brick so far — 26 files, ~430 findings. Covers
class-helpers.php (the shared contest/vote/category helper layer),
class-api.php (every REST endpoint: tracks/play/vote/submit/replace-
audio/edit-details/results/admin-live), class-debug.php, class-
judging.php (rubric scoring), class-admin-menus.php, class-reveal.php
(the medal-ceremony reveal controller/display pair), class-rounds.php
(multi-round elimination), class-discord.php, class-admin-reports.php,
class-admin-list-tables.php, class-activator.php (schema migrations),
class-share-cards.php, class-blocks.php, class-admin-moderation.php,
class-auth.php, class-admin-metaboxes.php, class-style-surfaces.php,
class-crm-integration.php, class-test-suite.php, class-portal-panel.php,
class-console.php, class-archive.php, class-contest-wizard.php,
class-element-surface.php, class-post-types.php, class-admin.php. A
handful of PHPStan-flagged get_posts()/get_the_author_meta() call
sites needed an explicit (string)/(int) cast at the call site once
their surrounding parameter picked up a native int type — no behavior
change, WordPress's own meta_value/author args accept either. No
behavior changes otherwise — every edit is a type declaration or an
array-shape PHPDoc block; this plugin's own PHPStan level-6 scoped
check and the full 12-plugin level-5 ecosystem check both come back
clean.
NOT runtime-verified against a live WordPress+MySQL install.
3.7.22 — TypeScript pilot, continued: converted this plugin's five
remaining plain-JS files (bh-common.ts, archive.ts, bh-judging.ts,
portal-submissions.ts, bh-contest-blocks.ts) — every file in this
plugin's assets/js/ is now tsc-compiled (reveal.ts was the first,
last pass). Same posture throughout: plain `tsc`, no bundler,
compiled output committed, `npm run build:bh-contest` after editing.
Purely a type-safety/authoring-layer change — every compiled .js file
was diffed against its original for identical runtime shape, verified
with `node --check`, and grepped for CommonJS exports/require()
artifacts (all clean). No runtime behavior was touched.
player.js (1107 lines) deliberately NOT converted this pass — a real,
safety-critical audio player engine with no live-browser verification
available in this session; converting it blind is a real risk for no
verifiable benefit. Flagged for a dedicated future pass with real
browser testing, not attempted here.
NOT runtime-verified against a live browser this session.

3.7.21 — PHPStan round 2 (this plugin went from 19 errors to 2, both
the deliberately-unstubbed COOKIEPATH/COOKIE_DOMAIN constants). Real
fixes: get_userdata() needs an int (three call sites were passing a
WP_Post's post_author/post_author-adjacent property directly, which
the stub types as string); get_posts()'s meta_value needs a string
(three call sites passed a bare int contest ID); esc_attr() needs a
string, not the numeric $pct it was given directly. Also removed a
genuinely redundant class_exists('BH_ShareCard') re-check in
class-share-cards.php — an earlier wp_die() a few lines above already
guarantees it. Two findings were confirmed real PHPStan/stub false
positives, not bugs, and scoped-ignored instead: add_option()'s 4th
arg accepting the legacy 'no'/'yes' string convention (confirmed real
WP-core behavior by reading wp_determine_option_autoload_value()'s
actual source, just stricter-typed in the stub than reality), and a
deliberately forward-looking pluralization ternary that's only
"always true" because BH_VOTE_BASE happens to be defined as the
literal 1 today.
NOT runtime-verified against a live install — confirmed via a real
`vendor/bin/phpstan analyse` run. `php -l` clean.

3.7.20 — Real bugs found by a proper `composer install && vendor/bin/
phpstan analyse` run (repo-root phpstan.neon, level 5; the pilot's
original sandbox had no GitHub access to actually run this).
class-debug.php's live-reveal test-submission seeder checked
`is_wp_error($pid)` on wp_insert_post()'s return — that function only
returns WP_Error with $wp_error=true (4th arg, not passed here); it
returns 0 on failure, changed to a falsy check. Two esc_attr()/
esc_html() call sites (class-admin-metaboxes.php's logo-ID hidden
field, class-console.php's vote-count cell) passed an int directly
where both functions expect a string (PHP 8.1+ deprecation) — added
explicit (string) casts. `php -l` clean on all three files. Runtime-
verified indirectly against localhost:10008 as part of the same
verification pass that ran the other plugins' Debug Tools actions
live — this plugin's own admin-metaboxes/console pages weren't
separately exercised this session.

3.7.19 — reveal.js converted to TypeScript (assets/ts/reveal.ts), this
plugin's first TS-pilot file, following the-self-hosted-self's established
pattern (plain `tsc`, module: none, compiled output committed since
the live site runs no build step — new bh-contest/tsconfig.json,
`npm run build:bh-contest`). bhEsc (bh-common.js) and anime (vendored)
are declared as loose untyped globals rather than pulling in real type
packages for either. Compiled assets/js/reveal.js verified with
`node --check` and grepped for CommonJS `exports`/`require(` artifacts
(the class of bug 3.10.5's the-self-hosted-self pilot caught) — clean. Purely a
type-safety/authoring-layer change; no runtime behavior was touched.
NOT runtime-verified against a live browser this session.

3.7.18 — anime.js (vendored, assets/js/vendor/anime.min.js v4.5.0,
MIT, real bytes downloaded from its official GitHub release, UMD
bundle — confirmed via ecosystem survey this session as the clearest
anime.js fit anywhere in the ecosystem) now drives the live Reveal
Party's actual motion (reveal.js's new animateReveal()) — a staggered
entry animation per leaderboard row plus a bigger flourish on
.bh-reveal-entry-winner, replacing the single blunt CSS keyframe
(bh-reveal-pop) that was the only animation on what used to be a
plain innerHTML swap. The sequencing/pacing clock (poll()/catchUp(),
unchanged) already had real timing logic to hand off to — this only
replaces the motion itself. v4's shorthand property API (x/y instead
of translateX/translateY, 'ease' instead of 'easing') was confirmed
against the real v4.5.0 source/README before use; the exact easing
string name and whether 'opacity'/'scale' are literally valid v4
property names were inferred from one confirmed README example, not
independently doc-verified — flagging honestly, worth a real-browser
check before relying on this.
NOT runtime-verified against a live install this session. `php -l`
clean; `node -c` clean on the vendored bundle and reveal.js.

3.6.4 — Added missing 'edit_item'/'add_new_item' labels to
bh_contest/bh_submission post type registrations (class-post-types.php).
3.6.3 — Fixed "Manage my submission" link never rendering: BH_Auth::render()
built it into $before, then unconditionally overwrote $before with
BH_Element::render_slot()'s output, discarding it. Now prepended instead.
3.6.2 — Rejecting a submission now also logs to OUS_Audit (admin
accountability record, separate from the contestant-facing BH_Event).
3.6.1 — Submission-received and rejection-notification emails now log a
warning if wp_mail() returns false instead of failing silently.
3.6.0 — Submission audio file replacement: admin or contestant can swap a
submission's audio file while the submission window is open. New file goes
to _bh_pending_audio_id (never overwrites _bh_audio_id directly) so the
live file stays playable/votable until an admin approves the swap via
BH_Admin::render_approval_box(). New 'rejected' post_status with reason-code
dropdown + freeform note, included in a rejection email; re-uploading after
rejection flips status back to 'pending'. New "Manage my submission" link
on the player (BH_Auth::render()) for logged-in contestants. Old
attachments are deleted on swap-approval. Discord only announces a file
swap if the submission was already published, to avoid a premature public
announcement for a still-pending submission.
Fixed two bugs found during verification: (1) the reject button lived
inside a metabox nested in WP's own post-edit <form> — a nested <form> is
invalid HTML, so submits silently resolved to the outer form. Rebuilt as
plain fields + fetch() POST with no form ancestor. (2)
register_post_status('rejected', ['exclude_from_search' => true]) broke
`post_status => 'any'` everywhere (WP only respects exclude_from_search for
custom statuses during 'any' expansion), making rejected submissions vanish
from the portal. Fixed by setting exclude_from_search => false (safe since
the post type itself is already non-public).

3.5.3 — submit() emits BH_Event 'bh/submission_created'; email send points
emit 'bhcore/email_sent' — feeds the CRM's unified per-person activity
timeline (bh-crm 1.9.0).

3.2.2 — Three more additive 'bh_contest_player' slots (tracklist_extra,
now_playing_extra, results_modal_intro), same pattern as header_extra
(3.2.1). Per-slot render+attach logic factored into shared
attach_extra_zone()/injectExtraZone() helpers instead of duplicating.
Note: .bh-now-playing-bar is position:fixed, so a sibling placed after it
in the DOM doesn't visually land "below" it the way normal flow suggests —
see player.css's comment on .bh-now-playing-extra.

3.2.1 — New 'header_extra' zone on the 'bh_contest_player' surface
(class-element-surface.php), inside the header bar next to the existing
buttons. player.css's ':empty { display: none; }' rule means a contest with
no header_extra content renders identically to before this pass.

3.2.0 — bh-contest's first BH_Element surface: new 'bh_contest_player'
surface with 'before_player'/'after_player' slots, rendered server-side in
class-auth.php's [bh_contest_player] shortcode as siblings of the player's
JS-owned mount div — not inside it, since player.js rebuilds that div's
entire innerHTML on load and would wipe anything placed inside it.
Deliberately not converted: the player's interactive skeleton
(header/tabs/tracklist/now-playing/modals) — every method in player.js
depends on that exact markup via this.q('.bh-results-btn')-style lookups.

3.1.3 — Fixed Live Console's contest-picker dropdown throwing a
permissions error on selection: the page is a submenu of
edit.php?post_type=bh_contest, but the dropdown's <form method="get"> only
carried page=bh-console — a bare GET form replaces the whole query string,
dropping post_type. Fixed by adding a hidden post_type field.

3.1.1 — BH_Discord::send() previously returned false identically for "no
webhook configured" and "webhook configured but fails URL validation" (a
real misconfiguration). The second case now logs a throttled warning.
3.1.2 — vote()'s DB writes are now checked and logged on failure instead of
always claiming success. Submission upload failures now log the actual
WP_Error. email_winners() now tracks per-recipient send failures.
3.1.4 — bundled zip regenerated to match installed version, no code change.
3.1.5 — vote()'s toggle paths now emit a BH_Event 'bh/vote' after each
write commits (fire-and-forget, outside the vote-limit transaction).
3.1.6 — class-debug.php's register() now sets 'group' =>
OUS_Debug::GROUP_SEED_RESET, part of the Debug Tools reorganization.

3.7.4 — Fixed enhanceSelect()'s open menu (player.js) getting clipped in
Safari: it was `position: absolute` inside `.bh-modal-content`
(overflow-y:auto), so it got clipped by the same overflow that lets the
form scroll. Switched to `position:fixed`, computed from the trigger's
screen coordinates (player.css z-index bumped past .bh-modal's 10000).
3.7.5 — Added an "edit-details" REST route + inline edit form in the
portal panel so a contestant can fix a typo'd song/artist title without
emailing an admin, gated the same way as the file-replace form.
3.7.6 — First OUS_Revisions consumer for postmeta-only config: a contest's
configuration lives entirely in postmeta, so WP's native post-revisions
would capture nothing meaningful. save_contest_meta() now snapshots every
_bh_*/_bhy_style_json key on save; new "Version History" metabox with
Restore buttons.
3.7.7 — Published contests are searchable via [ous_search]/ous/v1/search —
only published contests, never bh_submission (holds contact info/audio).
3.7.8 — A contest can opt into a "Site Menu" checkbox that keeps a
"Contests" submenu in sync via OUS_MenuSync.
3.7.9 — A contest can opt into "Allow submitting without audio yet": a fan
reserves an entry with title/artist/contact alone, then uploads later via
the portal, reusing the replace-audio endpoint for the first-time attach.
3.7.10 — Fixed hybrid-format Results modal dropping the Judges' Pick
leaderboard (player.js never read the judge_results REST key). Also fixed
a judges-only contest mislabeling its rubric percentage as "N votes".
3.7.11 — [bh_judge_panel] now enqueues player.css + new judging.css instead
of rendering unstyled, and fixes button classes that referenced a
nonexistent bh-btn-secondary class.

3.7.3 — Registered the "New Contest" wizard (BH_ContestWizard) as its own
Design Suite style surface (class-style-surfaces.php), previously invisible
to the token editor. Fixed the same contrast bug as the-self-hosted-self 3.6.5: this
preview's light wp-admin-style page inherited the dark brand theme's light
:host text color, rendering unreadable light-on-light text.

3.7.2 — Judge score save (assets/js/bh-judging.js) had no .catch() at all —
a dropped connection failed silently with no feedback. Added
retry-with-backoff (safe: BH_Judging::save_score() is an ON DUPLICATE KEY
UPDATE upsert, so a retry can't create a duplicate row) and an explicit
"not saved" message if retries are exhausted.

3.7.1 — New BH_ContestWizard (includes/class-contest-wizard.php): a guided
"New Contest" flow covering name/submission window/voting window/
categories/judging format only (rounds, Discord, contact-field
customization, and branding stay on the full edit screen with sensible
defaults). Populates $_POST with the real edit screen's field names and
lets wp_insert_post() fire the normal save_post_bh_contest hook, rather
than duplicating BH_Admin::save_contest_meta()'s validation logic.

3.7.0 — BH_Auth::render() now calls BH_SEO::set_page_data() with an Event
JSON-LD block (name, description, start/end dates, organizer) for any
resolved contest. 'eventAttendanceMode' is OnlineEventAttendanceMode and
'location' is omitted since a vote has no physical venue. class_exists()-
guarded.

3.6.9 — .bh-modal used unprefixed `backdrop-filter: blur(2px)` with no
`-webkit-backdrop-filter` fallback, so older Safari silently dropped the
blur. Added the prefixed declaration alongside the standard one.

3.6.8 — Registered two widgets (Submissions, Votes cast) with the-self-hosted-self's
shared Metrics dashboard (OUS_Metrics), reading the bh/submission_created
and bh/vote events already emitted by class-api.php. class_exists()-guarded.

3.6.7 — player.js had the submit-modal's profile field list independently
duplicated across three call sites (appendProfileFields(),
prefillSubmitProfile(), applyContactFields()/contactFields.show), in sync
by luck rather than by construction — the JS-side counterpart to
BHI_Profiles::TEXT_COLS being single-sourced on the PHP side. Collapsed
into one PROFILE_FIELDS/CONTACT_FIELD_KEYS pair at module scope.

3.6.6 — Production-hardening pass: two data-integrity/UX bugs fixed.
(1) Trapped vote slot: vote()'s toggle-OFF path was gated behind the same
"submission still published" check the toggle-ON path needs, so rejecting
an already-published, already-voted-on submission permanently trapped
every affected voter's vote with no way to free it. Fixed by only
enforcing that gate on new votes, and by having
handle_reject_submission() delete the submission's vote rows at rejection
time to auto-refund voters.
(2) New before_delete_post cleanup (cleanup_deleted_contest()) — deleting
a contest previously left every submission/vote row referencing it as a
silent orphan. Submissions are trashed (not hard-deleted) for a recovery
window.
Also: wp_die() calls across admin-post handlers now pass back_link => true.

3.6.5 — New class-share-cards.php: "Now Entered"/"Vote Now" public/no-login
share cards (?bh_share_entered={id} / ?bh_share_vote={id}) rendering only
title/artist/contest name (submission audio/notes/contact stay locked
down), via the shared BH_ShareCard engine (the-self-hosted-self 3.5.2). Since
bh_submission has no public single template to deep-link to, the "vote"
card instead pairs with the contest's own page URL (_bh_page_id). New
per-contest card-style radio (_bh_share_card_style) picks a card template,
separate from the existing color-override meta box.

3.5.2 — Votes CSV export (class-admin.php) had no id tiebreaker on
ORDER BY created_at ASC; many votes routinely land in the same second, so
export order was non-deterministic intra-second. Fixed with `, id ASC`.

3.5.1 — BH_Blocks::init() (new in 3.5.0) was called directly from
plugins_loaded rather than hooked onto 'init', so wp_register_script()
inside it ran before WordPress's own timing rules allow, logging a "called
incorrectly" notice. Fixed by hooking add_action('init', ...) normally,
matching BHM_Blocks' (bh-monetization-woo) pattern.

3.5.0 — Three new blocks via wp.serverSideRender (class-blocks.php,
assets/js/bh-contest-blocks.js): 'bh/contest-player', 'bh/results-reveal',
'bh/archive'. All three old shortcodes stay registered. These blocks only
ever render a static mount div — the actual interactive behavior (voting,
playback, reveal, archive grid) is player.js/reveal.js/archive.js
hydrating that div on a real front-end page load, not something
ServerSideRender previews in the editor canvas.
Fixed a related regression before it shipped: the front-end asset-enqueue
gate only checked has_shortcode() against post_content, so a
block-authored page (no literal bracket text) would render the mount div
but never enqueue player.js/reveal.js/archive.js. Fixed by adding
has_block() alongside each has_shortcode() check.
Known gap: class-debug.php's player_page_url() (a Debug Tools convenience
link) still only scans post_content for the literal shortcode string, so
a block-only contest falls back to the site home — a debug-convenience
degradation, not a functional break.

3.4.0 — Multi-round/elimination format. A contest gets an optional
`_bh_rounds` config (name + submission window + voting window + cut count,
1-4 rounds); a contest that never sets this behaves exactly as before
(BH_Rounds falls back to single-window logic when `_bh_rounds` is empty).
bh_votes and bh_judge_scores both gained a `round` column
(class-activator.php, DB_VERSION 1.6 → 1.7) — each round's votes/scores
are independent rows, so a round-2 re-vote doesn't inherit round-1's
tally. `_bh_round_reached` post meta tracks how far a submission has
survived; vote()/judge scoring reject submissions that didn't make the
current round's cut. New admin action ("Close round N", class-admin.php's
ajax_advance_round() → BH_Rounds::advance_round()) tallies the active
round and opens the next round for survivors only. class-reveal.php's
build_sequence() reveals only the active round's tally for a multi-round
contest (a cross-round "Overall" reveal wouldn't be coherent once rounds'
votes are independent).
Fixed during implementation: dbDelta() attempts adding a
same-named-but-different-columns unique key as a bare ADD rather than
replacing the existing one, failing with "Duplicate key name" before this
migration's own DROP+ADD index-rebuild code could run. Fixed by moving
that rebuild to run before dbDelta() on both tables.
Known gap: no dynamic add/remove UI for rounds beyond a plain "1-4" count
select; player.js's front-end results widget doesn't render round-scoped
results yet (only Reveal Party and the raw REST response do).

3.3.1 — In-house IP+cookie anti-fraud signal, no third-party CAPTCHA
vendor. bh_votes gained ip_address/voter_fp columns (class-activator.php,
DB_VERSION 1.5 → 1.6); voter_fp is a long-lived first-party httponly
cookie identifying a browser independent of which account is logged in.
New BH_Helpers::suspicious_ip_clusters(): flags several different
accounts voting from the same IP within a short window (a shared IP alone
is normal, not itself the signal), and separately notes when every
account in a cluster also shares the same fingerprint. Manual-review-only,
same posture as the existing suspicious_voters() check — never blocks a
vote or auto-flags an account, only surfaces a cluster on the Results
console. Privacy note: ip_address is personal data under most privacy
regimes — a site publishing a privacy policy should mention IP retention
for anti-fraud review.

3.3.0 — Judge/rubric scoring mode. A contest gets a Format setting
(public/judges/hybrid — public is the existing default), an admin-defined
rubric (criteria + max score), and a per-contest judge list (plain WP user
IDs, not a new capability/role, since most judges are guest volunteers
with no wp-admin access). New bh_judge_scores table (class-activator.php,
DB_VERSION 1.4 → 1.5), deliberately separate from bh_votes since a judge
score is multi-criterion with an editable draft-then-submit state a public
vote's shape has no room for. New BH_Judging (class-judging.php): a
front-end [bh_judge_panel] shortcode gated on the contest's judge list.
judge_results() normalizes each judge's per-criterion scores to 0-100 and
averages across judges, returned in the same ranked shape
category_results()/overall_results() already use, so BH_Reveal's existing
medal/tier logic needed no changes. class-reveal.php's build_sequence()
branches on format: 'judges' swaps the tally source, 'hybrid' runs both as
two separate labeled leaderboards (not a blended score). The public
/bh/v1/results REST endpoint got the same branching (a 'judge_results' key
only appears for judges/hybrid contests).
Known gap: the Discord results-announcement still reads the public vote
tally only — a pure-judges contest's announcement will show an empty
tally until that integration is updated separately.
