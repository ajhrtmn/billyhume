<?php
/**
 * Plugin Name: BH Contest
 * Description: Music contest voting platform with a sleek, native-feeling player.
 * Version:     3.7.11
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

// 3.6.4 — bh_contest/bh_submission were both missing
// 'edit_item'/'add_new_item' labels, so every real edit screen showed
// WordPress core's generic "Edit Post"/"Add Post" fallback instead of
// "Edit Contest"/"Review Submission" — caught live while working on
// own-ur-shit 3.5.1's wide-admin-layout fix. Added full label sets to
// both post type registrations (class-post-types.php).

// 3.6.3 — real bug, caught live while capturing screenshots of the
// "Manage my submission" link (3.6.0's own changelog): the link was
// silently NEVER rendering on any real page. BH_Auth::render() built
// it into $before, then two lines later unconditionally OVERWROTE
// $before with BH_Element::render_slot()'s own output, discarding it
// every time — confirmed live, the link never once appeared despite
// its condition (logged in, has a real submission) being met. Fixed
// by capturing the link separately and prepending it to whatever
// render_slot() returns instead of being clobbered by it. Verified
// live: the link now renders correctly above the player for a
// logged-in contestant with a real entry.

// 3.6.2 — accountability audit log wiring (own-ur-shit 3.5.0's own
// changelog has the full story). Rejecting a submission now logs to
// OUS_Audit alongside the existing BH_Event emit — the event feeds
// the contestant's own activity feed, this is the separate admin-
// accountability record of the same moderation action.

// 3.6.1 — debug-log wiring pass (own-ur-shit 3.4.91's own changelog
// has the full story). The submission-received confirmation email and
// the new rejection-notification email both now log a warning if
// wp_mail() returns false, instead of failing silently.

// 3.6.0 — the "wrong file uploaded" fix, AJ's own ask, fully scoped
// this session: admin AND the contestant themselves can replace a
// submission's audio file, any time the contest's submission window
// is still open (BH_Helpers::is_submission_open() — same gate a fresh
// submission itself respects).
//
// New: BH_API::replace_audio() (bh/v1/submissions/replace-audio, REST,
// self-service or admin) writes the new file to _bh_pending_audio_id —
// NEVER directly over _bh_audio_id — so the currently-live file keeps
// playing/being voted on unchanged until an admin reviews and approves
// the swap. Swapping more than once before review deletes the
// previous pending attachment; only the newest ever needs review.
// Admin review UI: BH_Admin::render_approval_box() (full rebuild of
// the old 100%-read-only meta box) now shows both the live file and a
// pending replacement side by side with Approve/Discard actions.
//
// New: a real 'rejected' post_status (class-post-types.php) with a
// prefab reason-code dropdown (BH_Admin::REJECTION_REASONS) plus a
// freeform note, both included in a real rejection email to the
// contestant — closing the gap where a rejected submission previously
// just sat at 'pending' forever with zero notification either way.
// Uploading a new file after a rejection automatically flips it back
// to 'pending', putting it in front of an admin again.
//
// New: a "Manage my submission" link on the contest player itself
// (BH_Auth::render()) for any logged-in contestant who's already
// entered — a real way to REACH the portal's replace-file flow from
// the page they'd actually be on when they notice the mistake, per
// AJ's own ask.
//
// Old attachments are deleted on swap-approval (AJ's own call), not
// kept around. Discord announces a file swap the same way it
// announces a first approval (AJ's own call: "you choose"), but ONLY
// when the submission was already actually published — a still-
// pending submission's file swap doesn't get a premature public
// announcement (a real bug caught live during this session's own
// verification: the first cut of this fired Discord even for a
// submission that had never been approved at all).
//
// Two more real bugs caught live during verification, both fixed:
//  1. The reject button lived inside a metabox, which renders INSIDE
//     WordPress's own outer post-edit <form> — a second, nested <form>
//     is invalid HTML, so a submit click silently resolved to the
//     OUTER form (a plain post Update) and never reached admin-post.php
//     at all. Rebuilt as plain fields + a button with no form ancestor,
//     submitting via a small inline fetch() POST instead.
//  2. register_post_status('rejected', ['exclude_from_search' => true,
//     ...]) silently broke `post_status => 'any'` everywhere else in
//     this plugin (6 call sites, including has_submitted()'s own
//     duplicate-submission check and the portal's submissions list) —
//     WordPress only respects exclude_from_search for CUSTOM statuses
//     during 'any' expansion, so a rejected submission was vanishing
//     from the contestant's own portal view entirely. Fixed by setting
//     exclude_from_search => false (safe: the post type itself is
//     already 'public' => false, so this can never surface in a real
//     front-end search regardless).
// Verified live end-to-end: rejected a real submission (reason + note
// stored, status changed), uploaded a replacement from the portal
// (status correctly flipped pending -> back to pending-with-swap,
// visible again after the exclude_from_search fix), approved the swap
// from wp-admin (old file deleted, new file promoted, no premature
// Discord post since the submission was never published).

// 3.5.3 — class-api.php's submit() now emits BH_Event
// 'bh/submission_created' after a successful submission, and both
// email send points (submission-received confirmation here, plus
// every notification-system email via own-ur-shit) now emit
// 'bhcore/email_sent' — feeding the CRM's unified per-person activity
// timeline (bh-crm 1.9.0). No change to submission/email behavior
// itself.

// 3.2.2 — 2026-07-12 — task #80 follow-up (also fixes a stale version
// mismatch found while making this change: the header above said 3.2.1
// but the BH_VER constant below was still 3.2.0 — both now agree).
//
// Three more additive 'bh_contest_player' slots, same non-load-bearing
// boundary header_extra (3.2.1) already proved out: tracklist_extra
// (above the tracklist), now_playing_extra (after the now-playing bar),
// results_modal_intro (inside the results modal, above the results
// list). None of the three empty divs renderSkeleton() now creates for
// them are read from or required by any this.q(...)-style lookup
// elsewhere in player.js — auth, voting, and playback wiring is
// untouched. class-auth.php's per-slot render+attach logic was factored
// into one shared attach_extra_zone() helper (header_extra included)
// rather than duplicated a fourth time; player.js's injectHeaderExtra()
// was generalized the same way into injectExtraZone(datasetKey,
// selector), called once per zone.
//
// Flagged honestly, not silently assumed fine: .bh-now-playing-bar is
// position:fixed, so a sibling placed after it in the DOM doesn't
// visually land "below" it the way normal flow would suggest — see
// player.css's own comment on .bh-now-playing-extra. No live browser
// this session to iterate on the actual visual placement; worth a real
// click-through before treating that specific zone as done.
//
// The player's actual interactive shell — header buttons, tabs,
// tracklist, now-playing controls, auth/submit/results modals — is
// still NOT converted to BH_Element placements, on purpose. That's the
// genuinely risky remainder of task #80 (every method in this file
// depends on exact selectors like this.q('.bh-results-btn')) and still
// needs real browser QA, not another blind pass.

// 3.2.1 — 2026-07-12 — task #80's real, safe slice: a genuinely new
// 'header_extra' zone on the 'bh_contest_player' surface (class-element-
// surface.php), landing INSIDE the header bar itself for the first time
// — next to the brand/Results/Submit/Login/Logout buttons, not replacing
// any of them. player.js's renderSkeleton() still builds the entire
// required skeleton exactly as before (every this.q('.bh-results-btn')-
// style lookup elsewhere in that file is completely untouched); the only
// change is one new '.bh-header-extra' div that starts empty and is
// filled, once, by a new injectHeaderExtra() reading class-auth.php's
// base64-encoded real render_slot() output off a data attribute.
// player.css's ':empty { display: none; }' rule means a contest with no
// header_extra content renders byte-identical to before this pass.
//
// Deliberately still NOT touched: the header's own required buttons,
// the tabs/tracklist/now-playing bar, and the auth/submit/results
// modals — those stay exactly as risky to convert as WALKTHROUGH-
// GUIDE.md already flags, unchanged by this pass. This is a real,
// additive step forward, not a reversal of that caution.

// 3.2.0 — 2026-07-12 — bh-contest's first real BH_Element surface (AJ
// named this plugin the litmus test for real content/component/widget
// authoring — see class-element-surface.php's own docblock for the full
// scope reasoning). New 'bh_contest_player' surface, two slots
// ('before_player'/'after_player'), rendered server-side in class-
// auth.php's [bh_contest_player] shortcode as real siblings of the
// player's own JS-owned mount div — NOT inside it, since player.js
// rebuilds that div's entire innerHTML on load and would silently wipe
// anything placed inside it.
//
// Deliberately NOT converted this pass: the player's actual interactive
// skeleton (header/tabs/tracklist/now-playing bar/auth+submit modals,
// assets/js/player.js's renderSkeleton()) — every other method in that
// file depends on that exact markup via this.q('.bh-results-btn')-style
// lookups for auth-state, voting, and playback. Turning that into
// BH_Element placements safely means guaranteeing every placement always
// emits those exact required classes, which is real, live-breaking risk
// to take on with no browser available this session to verify against.
// Flagged in WALKTHROUGH-GUIDE.md as real follow-up work, not silently
// deferred.

// 3.1.3 — real bug fix: Live Console's contest-picker dropdown threw
// "Sorry, you are not allowed to access this page." on selection. Root
// cause: the page is registered as a submenu of edit.php?post_type=bh_contest,
// but the dropdown's <form method="get"> only carried page=bh-console —
// a bare GET form replaces the whole query string with just its own
// fields, so post_type was silently dropped and WordPress couldn't
// resolve the submenu. Fixed in class-console.php by adding a hidden
// post_type=bh_contest field to the form. NOT yet verified against the
// live site — user reported the symptom, fix follows from reading the
// exact form-submission mechanics; please confirm the dropdown now works.

// 3.1.1 — logging depth pass: BH_Discord::send() previously returned
// false identically for "no webhook configured" (routine, most contests
// don't have one) and "webhook configured but fails URL validation" (a
// real misconfiguration silently killing every notification for that
// contest). The second case now logs a throttled warning via
// OUS_DebugLog. Standing caveat: reasoning/brace-balance-checked only,
// not run against a real WordPress+MySQL install.
//
// 3.1.2 — continuation logging pass: vote add/remove DB writes
// (class-api.php's vote()) were previously unchecked and the response
// always claimed success regardless — now logged as 'error' on a real
// failure. Submission wp_insert_post()/media_handle_sideload() failures
// now log the actual WP_Error message instead of discarding it.
// email_winners() now tracks and logs which specific winners' emails
// failed to send in a bulk announce, instead of the whole batch's
// success/failure being invisible.
// 3.1.4 — bundled zip regenerated to match installed version, no code change
// 3.1.5 — vote()'s toggle-add and toggle-remove paths (class-api.php)
// now additionally emit a BH_Event 'bh/vote' event (own-ur-shit's new
// event-tracking layer, class-event.php) after each write commits —
// fire-and-forget, never inside the vote-limit transaction itself, so
// the synchronous votes_left response this endpoint returns is
// unaffected. See EVENT-TRACKING-ARCHITECTURE-PLAN.md Section 6.
// Standing caveat: reasoning/brace-balance-checked only, not run
// against a real WordPress+MySQL install.
//
// 3.1.6 — class-debug.php's register() now sets 'group' =>
// OUS_Debug::GROUP_SEED_RESET on this plugin's Debug Tools section, part
// of own-ur-shit's Debug Tools reorganization pass. No functional change
// to this plugin itself. Standing caveat: reasoning/brace-balance-
// checked only, not run against a real WordPress+MySQL install.
// 3.7.4 — real bug, AJ's own report ("stylrs are working for the custom
// drowndowns... they dont on the live site", later narrowed to Safari):
// enhanceSelect()'s open menu (assets/js/player.js) was `position:
// absolute` inside `.bh-modal-content`, which is `overflow-y:auto` so
// long forms can scroll — any absolutely-positioned child rendering past
// that container's own visible edge gets clipped by the SAME overflow
// that lets the form scroll. Worst on a short viewport where the modal
// scrolls more and the platform-picker field sits closer to the bottom
// edge. Fixed by switching the menu to `position:fixed`, computed from
// the trigger's real screen coordinates the moment it opens (assets/css/
// player.css's z-index bumped past .bh-modal's own 10000 to match, since
// a `fixed` element stacks against the whole page, not just its local
// context). RUNTIME-VERIFIED at a 375px mobile viewport: the menu
// previously would have been clipped at the modal's scrolled bottom
// edge, now renders in full and selection still updates the real
// <select> correctly.
// 3.7.5 — real feature gap closed, named explicitly in
// ROADMAP-platform-evolution.md Section 6: the portal's Contest
// Submissions panel let a contestant replace their audio FILE
// (replace_audio(), 3.x) but had no way to fix a typo'd song/artist
// title without emailing an admin. Added a real "edit-details" REST
// route (class-api.php) and an inline edit form in the portal panel
// (class-portal-panel.php), gated the same way as the file-replace
// form (owner or admin, only while the contest's submission window is
// still open).
// 3.7.6 — first real OUS_Revisions consumer for postmeta-only config
// (ROADMAP-search-and-revisions.md Section 2, AJ's own framing:
// "versioning is most important for anything that is a post, like
// contests and lessons"). A contest's real configuration (dates,
// rounds, rubric, contact requirements, brand style) lives entirely in
// postmeta, never post_content/title — WordPress core's own native
// post-revisions (the right tool for lessons, see bh-courses' own
// changelog) would capture nothing meaningful here. save_contest_meta()
// now snapshots every _bh_*/_bhy_style_json meta key on every save; a
// new "Version History" metabox (side column) lists past versions with
// working Restore buttons.
// 3.7.7 — real OUS_Search consumer, ROADMAP-search-and-revisions.md
// Section 1 sequencing. Published contests are searchable via
// [ous_search]/ous/v1/search — deliberately public-safe: only
// published contests (never bh_submission, which holds real people's
// contact info/audio files), linking to the contest's real page.
// 3.7.8 — a contest can opt into a "Site Menu" checkbox (new metabox,
// bh_show_in_menu/_bh_menu_label) that keeps a real "Contests" submenu
// in every site Navigation menu in sync automatically (OUS_MenuSync,
// own-ur-shit core) — no manual menu-builder editing needed.
// 3.7.9 — a contest can opt into "Allow submitting without audio yet"
// (Contest Rules & Results box, off by default): a fan can reserve
// their entry with title/artist/contact info alone, then finish by
// uploading a file later from their account portal (BH_PortalPanel).
// Reuses the existing replace-audio upload form/endpoint for the
// first-time attach rather than adding a new one.
// 3.7.10 — fixed: a hybrid-format contest's Results modal only ever
// showed the People's Choice leaderboard, dropping Judges' Pick
// entirely (the REST payload always carried a second judge_results
// key, class-api.php's results(), but player.js never read it). Now
// renders both, labeled, matching Reveal Party's own convention. Also
// fixed a judges-only contest mislabeling its rubric percentage as
// "N votes" (BH_Judging::judge_results() reuses the `votes` key for
// shape compatibility).
// 3.7.11 — [bh_judge_panel] gets real ecosystem theming: previously
// enqueued zero CSS (bare unstyled browser controls) and its "Save
// draft" button referenced a bh-btn-secondary class that never existed
// in player.css. Now enqueues player.css (bh-container wrapper, same
// design-token system the contest player uses) plus a new judging.css
// for panel layout, reuses .bh-scrubber for rubric sliders, and fixes
// the button classes to the real bh-btn-outline/bh-btn-primary pair.
define('BH_VER',        '3.7.11');

// 3.7.3 — Design Suite gallery gap closed: registered the guided
// "New Contest" wizard (BH_ContestWizard) as its own surface
// (class-style-surfaces.php), previously entirely invisible to the
// token editor. Same contrast bug found and fixed as own-ur-shit's
// 3.6.5 Media wizard surface — this preview's genuinely light
// wp-admin-style page was inheriting the dark brand theme's light
// :host text color, rendering unreadable light-on-light text; fixed
// with an explicit text color on this preview specifically.

// 3.7.2 — retry-audit pass, AJ's own standing ask (assets/js/bh-judging.js):
// judge score save had NO .catch() at all — a dropped connection
// silently failed with zero feedback, and a judge could reasonably
// believe a submitted score went through when it never left the
// browser. Added retry-with-backoff (verified BH_Judging::save_score()
// is a real ON DUPLICATE KEY UPDATE upsert keyed on judge+submission+
// category before adding this — a retry here can't create a
// duplicate row) and, if retries are exhausted, an explicit "your
// score was NOT saved" message rather than silence.

// 3.7.1 — new BH_ContestWizard (includes/class-contest-wizard.php): a
// guided "New Contest" flow, the "it just works" design principle
// applied to the single most confusing screen in this ecosystem (the
// real edit screen spans 7 metaboxes, 30+ interdependent fields —
// confirmed by direct code read, not guessed). Covers name/submission
// window/voting window/categories/judging format only; rounds,
// Discord, contact-field customization, and branding stay on the real
// edit screen with sensible defaults. Reuses the real save path —
// populates $_POST with that screen's own field names and lets
// wp_insert_post() fire the real save_post_bh_contest hook — rather
// than duplicating BH_Admin::save_contest_meta()'s validation logic.
// Real bug caught and fixed during live verification: the save
// handler checks isset($_POST['bh_results_published']), not
// truthiness, so the wizard's first draft (setting it to '') still
// saved results as published — fixed by unset() instead.

// 3.7.0 — ROADMAP-discoverability.md Section 3's own per-content-type
// schema.org plan: BH_Auth::render() now calls BH_SEO::set_page_data()
// with a real Event JSON-LD block (name, description, startDate/
// endDate from _bh_start/_bh_end, organizer) for any resolved contest
// — 'eventAttendanceMode' deliberately set to OnlineEventAttendanceMode
// and 'location' deliberately omitted, since a music-contest vote has
// no physical venue to report. class_exists()-guarded. Verified live
// on a real published contest: correct Event block, correct dates,
// single canonical tag.

// 3.6.9 — real cross-browser gap, caught by a grounded browser-quirk
// audit of every first-party .css/.js file in the ecosystem: .bh-modal
// used unprefixed `backdrop-filter: blur(2px)` with no
// `-webkit-backdrop-filter` fallback, so older Safari silently drops
// the blur (flat overlay, no glass effect) instead of degrading
// gracefully. Added the prefixed declaration alongside the standard
// one.

// 3.6.8 — First real contributor to own-ur-shit's new shared Metrics
// dashboard (OUS_Metrics, class-metrics.php): two widgets in
// includes/class-crm-integration.php (Submissions, Votes cast), same
// "tandem infrastructure" pass as bh-courses' own version of this
// registration. Reads bh/submission_created and bh/vote events already
// flowing — see class-api.php's own emit() call sites. class_exists()-
// guarded; does nothing if own-ur-shit's metrics class isn't present.

// 3.6.7 — Contract-drift fix in player.js, flagged by an audit run
// right after the quiz-shuffle bug this same session (the same failure
// mode: a field list that SHOULD be single-sourced, independently
// duplicated across multiple call sites, currently in sync by luck
// rather than by construction). own-ur-shit's BHI_Profiles::TEXT_COLS
// is already correctly single-sourced on the PHP side; this was the
// JS-side counterpart — appendProfileFields()'s field/DOM-class map,
// prefillSubmitProfile()'s separately-typed copy of the identical map,
// and applyContactFields()/contactFields.show's own hardcoded field-
// name array were three independent literals. Collapsed into one
// PROFILE_FIELDS/CONTACT_FIELD_KEYS pair at module scope; all three
// consumers now read off it. Verified live: submit modal renders every
// field correctly, prefill still works, zero console errors.
// 3.6.6 — Production-hardening pass, from a fresh audit ahead of real
// users: two real data-integrity/UX bugs, closed.
// (1) Trapped vote slot: class-api.php's vote() gated its TOGGLE-OFF
// path behind the same "submission still belongs to this contest and
// is still published" check the toggle-ON path needs. An admin
// rejecting an already-published, already-voted-on submission (the
// Reject UI is available at any pre-rejected status, confirmed live)
// permanently trapped every affected voter's vote — they could never
// free that slot again, and the track vanishes from the public /tracks
// list the same moment, so there was no UI path to even notice why.
// Fixed two ways: the API now only enforces that gate on new votes
// (an existing vote can always be freed regardless of the submission's
// current status), and handle_reject_submission() (class-admin.php)
// now deletes that submission's vote rows at the moment of rejection,
// auto-refunding every affected voter rather than relying on each of
// them separately hitting a now-fixed toggle-off request.
// (2) New before_delete_post cleanup (cleanup_deleted_contest()) —
// this plugin had ZERO cleanup anywhere for a permanently-deleted
// contest; every submission and vote row referencing it became a
// silent, undiscoverable orphan. Submissions are trashed (not hard-
// deleted) to preserve a real recovery window.
// Also: wp_die() calls across admin-post handlers and share-card/
// certificate endpoints now pass back_link => true instead of dead-
// ending with no way back except the browser Back button.
// Verified live: submission edit screen and reject flow render with no
// fatal errors after these changes.

// 3.6.5 — "Anything fun for social sharing?" — AJ's own ask this
// session. New class-share-cards.php: "Now Entered"/"Vote Now" share
// cards (?bh_share_entered={id} / ?bh_share_vote={id}, public/no-login
// — a submission's own audio/notes/contact info stays locked down as
// before, this card renders only a title/artist/contest name), via the
// new shared BH_ShareCard engine (own-ur-shit 3.5.2). No per-submission
// public page exists to deep-link to (bh_submission is 'public' =>
// false, no single template) — the "vote" card instead pairs with the
// contest's own auto-created page URL (_bh_page_id), returned alongside
// the card URLs on the submit API's success response and surfaced by a
// new share modal in player.js, shown right after a successful upload.
// New per-contest "Brand"/"Poster" card-style radio (a new, separate
// meta box from the existing style-override one — this picks a card
// TEMPLATE, not a color override) saved to _bh_share_card_style.
// Verified live against real seeded submission data (not just a
// synthetic test): both card endpoints render the correct artist/song/
// contest name pulled from actual postmeta.


// 3.5.2 — QA fix, part of the same ecosystem-wide ordering-tiebreaker
// sweep as bh-crm 1.4.0/own-ur-shit 3.4.86/bh-monetization-woo 0.4.12.
// The votes CSV export (class-admin.php) had no id tiebreaker on its
// ORDER BY created_at ASC — real voting windows routinely land many
// votes in the same second, so the exported audit-trail order was
// non-deterministic intra-second. Fixed with `, id ASC`.

// 3.5.1 — QA fix, caught live via WP_DEBUG_LOG: BH_Blocks::init() (new
// in 3.5.0) was called directly from this file's plugins_loaded
// closure rather than hooked onto 'init' — safe against the earlier
// nested-hook bug class (it doesn't re-register a second 'init'
// callback), but wp_register_script() inside it still ran too early
// for WordPress's own timing rules, logging a real "called incorrectly"
// notice. Fixed by hooking add_action('init', ['BH_Blocks', 'init'])
// normally at the top level instead — the same correct pattern
// BHM_Blocks (bh-monetization-woo) already used. Confirmed the notice
// is gone and all three blocks still register correctly.

// 3.5.0 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 5a: WYSIWYG
// shortcode-to-block conversion continues into bh-contest, following
// bh-monetization-woo's three easy conversions (0.4.9-0.4.11). Three
// new blocks via wp.serverSideRender (class-blocks.php, assets/js/
// bh-contest-blocks.js): 'bh/contest-player' ([bh_contest_player], a
// contest-picker Inspector control), 'bh/results-reveal'
// ([bh_results_reveal], same picker), 'bh/archive' ([bh_archive], no
// attributes — always every past contest). All three old shortcodes
// stay registered and untouched.
// A real, worth-noting distinction from the monetization blocks: this
// plugin's shortcodes only ever render a static mount div — the actual
// interactive behavior (voting, playback, the reveal sequence, the
// archive grid) is entirely player.js/reveal.js/archive.js hydrating
// that div on a REAL front-end page load, not something ServerSideRender
// previews inside the editor canvas. What IS fixed: the original AJ
// complaint (a shortcode rendering as raw bracket text with zero visual
// feedback) — the canvas now shows the real, correctly-styled container.
// Real regression caught and fixed BEFORE it shipped, not after: this
// plugin's own front-end asset-enqueue gate (the wp_enqueue_scripts
// callback near the bottom of this file) only ever checked
// has_shortcode() against post_content — a page authored with the new
// block instead has none of that literal bracket text, so without a
// fix a block-authored page would have rendered the mount div via
// render_callback but NEVER actually enqueued player.js/reveal.js/
// archive.js, leaving a permanently inert container. Fixed by adding
// has_block() alongside each has_shortcode() check.
// Scoping boundary, disclosed rather than silently left: class-debug.
// php's player_page_url() (a Debug Tools convenience link, "find the
// page where this contest's player lives") still only scans
// post_content for the literal [bh_contest_player] shortcode string —
// a contest embedded only via the new block won't be found by that
// specific helper and it'll fall back to the site home instead. Purely
// a debug-convenience-link degradation, not a functional break; not
// fixed this pass (would mean parsing block attributes out of
// post_content, real but small extra scope).
// RUNTIME-VERIFIED end to end on this actual install: confirmed all
// three blocks registered, confirmed via the real REST block-renderer
// endpoint that an explicit contest slug correctly resolves to
// data-contest="<real id>" on both bh/contest-player and bh/results-
// reveal, confirmed has_block() correctly detects a block-authored
// page, and — the real proof — built an actual page with the
// bh/contest-player block, loaded it in a live browser, and watched
// the FULL interactive player (header, Submit a Song, Log Out, the
// track list, the now-playing bar) load and hydrate correctly with
// zero console errors, confirming player.js/player.css both actually
// enqueued via the has_block() fix. Test contest/page cleaned up
// afterward.

// 3.4.0 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 2b: multi-
// round/elimination format, "the single largest architectural item in
// this whole doc," built last per that doc's own sequencing note (needs
// judge scoring's data shape to build against, since a round can cut by
// public vote OR judge score depending on the contest's own format).
// A contest gets an optional `_bh_rounds` config (name + submission
// window + voting window + cut count, per round, 1-4 rounds via the new
// admin metabox) — round 2+'s submission window is left blank in the
// normal case (its "entrants" are the survivors of round 1, not new
// submissions), but can be set if a contest genuinely wants fresh
// entries each round. A contest that never touches this (every contest
// that predates this feature) behaves byte-for-byte as it always has —
// every round-aware method (new BH_Rounds, class-rounds.php) falls back
// to the pre-existing single-window logic when `_bh_rounds` is empty.
// bh_votes and bh_judge_scores both gained a `round` column
// (class-activator.php, DB_VERSION 1.6 → 1.7) — each round's votes/
// scores are independent rows, never combined, so a round-2 re-vote on
// a survivor doesn't inherit its round-1 tally. A submission's own
// `_bh_round_reached` post meta (0 by default) tracks how far it has
// survived; vote()/judge/score both now reject a submission that didn't
// make the current round's cut. New admin action ("Close round N &
// advance to round N+1", class-admin.php's ajax_advance_round() →
// BH_Rounds::advance_round()) tallies the active round (by public vote
// or judge score, per the contest's own format), keeps the configured
// cut count, and opens the next round for survivors only — one-way,
// nonce'd, capability-gated. class-reveal.php's build_sequence() now
// reveals the ACTIVE round's own tally for a multi-round contest
// (skipping the cross-round "Overall" reveal, which wouldn't mean
// anything coherent once different rounds' votes are genuinely
// independent) — every other reveal mechanic (medal tiers, hybrid's
// two-leaderboard pass) is unchanged and composes with rounds directly.
// Real bug caught and fixed mid-implementation, not just reasoned
// through: dbDelta() itself was found (via a live migration run, not
// static reading) to attempt adding a same-NAMED-but-different-COLUMNS
// unique key as a bare ADD rather than leaving an existing same-named
// index alone or safely replacing it — it fails with "Duplicate key
// name" and poisons $wpdb->last_error BEFORE this migration's own
// (correct) DROP+ADD index-rebuild code ever got a chance to run
// afterward. Fixed by moving that rebuild to run BEFORE dbDelta(), on
// both bh_votes and bh_judge_scores, so dbDelta() never sees a
// conflicting index in the first place. Scoping boundary, disclosed
// rather than silently left: no dynamic add/remove UI for rounds beyond
// a plain "1-4" count select (right-sized rather than building a full
// JS repeater for what's expected to be a rare, admin-only setup
// action), and — same as bh-contest 3.3.0's own disclosed boundary —
// player.js's front-end results widget doesn't yet render round-scoped
// results, only the Reveal Party and the raw REST responses do today.
// RUNTIME-VERIFIED end to end on this actual install, including the
// dbDelta bug fix itself (ran the live migration, confirmed both
// tables' unique keys land on the correct 5-column definition, and
// confirmed the migration is idempotent on a second run with zero
// errors): built a real 2-round contest, confirmed round-0 votes tally
// independently of round-1 votes for the same entry, confirmed
// advance_round() correctly keeps the top cut_count and marks the rest
// eliminated without deleting anything, confirmed a real vote() REST
// call against an eliminated entry is rejected while the same call
// against a survivor succeeds and is tagged with the correct round,
// confirmed the "close final round" admin action correctly refuses to
// advance past the last configured round, and confirmed
// BH_Reveal::build_sequence()/render_step() correctly reveal only the
// active round's isolated tally with zero PHP warnings/errors
// throughout (WP_DEBUG_LOG on). Test contests/submissions/votes cleaned
// up afterward.

// 3.3.1 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 2c: the in-
// house IP+cookie anti-fraud signal, no third-party CAPTCHA vendor (a
// direct decision from that doc). bh_votes gained ip_address/voter_fp
// columns (class-activator.php, DB_VERSION 1.5 → 1.6) captured by
// class-api.php's vote() handler — voter_fp is a long-lived first-party
// httponly cookie identifying a BROWSER independent of which account is
// logged into it. New BH_Helpers::suspicious_ip_clusters(): flags
// several DIFFERENT ACCOUNTS voting from the same IP within a short
// window (a shared IP alone — a household, campus, VPN exit node — is
// normal and NOT itself the signal), and separately notes when every
// account in a cluster also shares the identical browser fingerprint,
// the strongest evidence this table can offer. Same manual-review-only
// posture as the existing timestamp-clustering check
// (suspicious_voters()) — this never blocks a vote or auto-flags an
// account, it only surfaces a cluster on the Results console
// (class-console.php) for a human to look at. Privacy note (flagged per
// the roadmap doc's own compliance callout): ip_address is real
// personal data under most privacy regimes — if this site publishes a
// privacy policy, it should mention IP retention for anti-fraud review;
// see class-activator.php's own comment on the column for the full
// note. RUNTIME-VERIFIED end to end on this actual install: ran the DB
// migration live (1.5 → 1.6, confirmed both new columns), cast a real
// vote through the actual vote() REST handler and confirmed the stored
// row has a real IP and a freshly-generated fingerprint, seeded 3
// distinct accounts voting from one IP with the same fingerprint plus a
// 4th vote from an unrelated IP and confirmed suspicious_ip_clusters()
// correctly flags only the real 3-account cluster with same_fingerprint
// = true, and confirmed zero PHP warnings/errors with WP_DEBUG_LOG on
// throughout. Test contest/submission/votes cleaned up afterward.

// 3.3.0 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 2a: judge/
// rubric scoring mode, the "give me all of it, and make it a real
// choice" contest-format work. A contest now gets a real Format setting
// (public/judges/hybrid — public unchanged, the default for every
// existing contest), an admin-defined rubric (criteria + max score,
// same free-text-per-line authoring pattern as voting categories), and
// a per-contest judge list (a plain list of WP user IDs, not a new
// capability/role — most judges here are guest volunteers with no
// wp-admin access). New bh_judge_scores table (class-activator.php,
// DB_VERSION 1.4 → 1.5) — deliberately its own table, not overloaded
// onto bh_votes, since a judge score is multi-criterion with an
// editable draft-then-submit state a public vote's shape has no room
// for. New BH_Judging (class-judging.php): a front-end [bh_judge_panel]
// shortcode (gated on the contest's own judge list, not wp-admin) where
// a judge scores every entry per rubric criterion via a slider, saves a
// draft or submits — only submitted scores ever count. judge_results()
// normalizes each judge's per-criterion scores to 0-100 and averages
// across judges, returned in the exact same ranked shape (rank/id/
// title/artist/'votes') category_results()/overall_results() already
// use, so BH_Reveal's existing medal_slice()/tier logic needed zero
// changes to consume it. class-reveal.php's build_sequence() now
// branches on format: 'judges' swaps the tally source, 'hybrid' runs
// BOTH as two clearly-labeled leaderboards (Judges' Pick / People's
// Choice) — two separate leaderboards, not a blended score, a direct
// decision from the roadmap doc. The public /bh/v1/results REST
// endpoint got the same branching (a 'judge_results' key only appears
// for judges/hybrid contests, so nothing already reading 'results'
// breaks). Scoping boundary, disclosed rather than silently left: the
// Discord results-announcement (class-discord.php) still reads the
// public vote tally only — a pure-judges contest's Discord announcement
// will show an empty tally until that integration is updated
// separately; the player.js front-end results widget likewise doesn't
// yet render a judges/hybrid leaderboard, only the Reveal Party and the
// raw REST response do today.
// RUNTIME-VERIFIED end to end on this actual install: ran the DB
// migration live (1.4 → 1.5, confirmed the table exists), created a
// real hybrid-format contest with a 2-criterion rubric and a real judge
// user, confirmed a draft score does NOT count toward judge_results()
// but an identical submitted one does, confirmed the normalized-score
// ranking math is correct (a 2-criterion 8/10+15/20 entry correctly
// ranks above a 5/10+10/20 entry at 77.5 vs 50), confirmed
// build_sequence() emits the right two-pass "People's Choice" then
// "Judges' Pick" sequence for a hybrid contest with both a real vote
// and a real judge score present, confirmed the REST /results endpoint
// returns both 'results' and 'judge_results' for hybrid, confirmed the
// judge-scoring REST endpoint correctly rejects a non-judge and a
// submission from a different contest, and rendered both the real
// [bh_judge_panel] shortcode and the real [bh_results_reveal] display
// shortcode with WP_DEBUG_LOG on — zero PHP warnings/errors from any of
// this pass's code. Test contest/submissions/scores/votes cleaned up
// afterward.
define('BH_PATH',       plugin_dir_path(__FILE__));
define('BH_URL',        plugin_dir_url(__FILE__));
define('BH_VOTE_BASE',  1);                 // votes every user gets
define('BH_VOTE_BONUS', 1);                 // extra votes earned by submitting a track
define('BH_MAX_BYTES',  20 * 1024 * 1024);  // max upload size
define('BH_REG_THROTTLE', 3);               // max registrations per IP per hour
define('BH_LOGIN_MAX_FAILS', 5);            // failed logins (per username+IP) before a 15-minute lockout

foreach (['activator', 'post-types', 'helpers', 'auth', 'api', 'admin', 'contest-wizard', 'debug', 'crm-integration', 'console', 'reveal', 'discord', 'archive', 'style-surfaces', 'element-surface', 'portal-panel', 'judging', 'rounds', 'share-cards', 'blocks'] as $f) {
    require_once BH_PATH . "includes/class-$f.php";
}

// Safe to register unconditionally — activation only creates this
// plugin's own table/default pages, neither of which touches the
// identity/style classes this plugin depends on for its actual
// features, so there's nothing here that can fatal-error even if the
// dependency below turns out to be missing.
register_activation_hook(__FILE__, ['BH_Activator', 'activate']);

/**
 * Everything else is gated behind plugins_loaded rather than checked
 * directly here at file-parse time. That distinction matters: WordPress
 * loads active plugins' files in alphabetical folder order, so a direct
 * class_exists() check at the top of this file could run BEFORE the
 * dependency's own file has even been read yet on a given request,
 * regardless of whether that dependency is genuinely active — a real,
 * previously-shipped bug, not a hypothetical one. plugins_loaded is a
 * hard WordPress guarantee: it only ever fires after EVERY active
 * plugin's main file has already been fully loaded, so by the time this
 * callback runs, the check is reliable no matter which letter either
 * plugin's folder happens to start with.
 */
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Contest</strong> requires the <strong>Own Ur Shit</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    // One-time migration of existing profile data into the (now merged)
    // core plugin's identity table. The two schemas are identical (this
    // plugin's table was the original source that table was extracted
    // from), so this is a single direct copy rather than field-by-field
    // remapping — INSERT IGNORE means it's safe to run more than once.
    if (get_option('bh_identity_migration_done') !== '1') {
        global $wpdb;
        $old = $wpdb->prefix . 'bh_participant_profiles';
        $new = $wpdb->prefix . 'bhi_profiles';
        $old_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $old)) === $old;
        $new_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $new)) === $new;

        if (!$old_exists) {
            update_option('bh_identity_migration_done', '1');
        } elseif ($new_exists) {
            $wpdb->query(
                "INSERT IGNORE INTO $new
                    (user_id, real_name, discord_name, twitch_name, youtube_name, phone, typical_platform, real_name_public, discord_public, twitch_public, youtube_public, updated_at)
                 SELECT user_id, real_name, discord_name, twitch_name, youtube_name, phone, typical_platform, real_name_public, discord_public, twitch_public, youtube_public, updated_at
                 FROM $old"
            );
            if (!$wpdb->last_error) update_option('bh_identity_migration_done', '1');
        }
        // Neither branch taken (old exists, new doesn't yet) means the
        // core plugin's own table isn't ready yet — leave the flag unset
        // so this retries on a later request instead of silently giving
        // up on a migration that should have happened.
    }

    BH_Activator::maybe_upgrade();
    BH_Activator::maybe_migrate_style_meta_keys();

    add_action('admin_init',    ['BH_Activator', 'maybe_create_default_pages']);
    add_action('init',          ['BH_PostTypes', 'register']);
    // BH_Event registration (own-ur-shit's event-tracking layer) — see
    // class-api.php's vote handler for the actual emit() call, fired
    // additively after the vote's own transaction commits. Per
    // EVENT-TRACKING-ARCHITECTURE-PLAN.md Section 6.
    add_action('init', function () {
        if (class_exists('BH_Event')) {
            BH_Event::register_event_type('bh/vote', ['contest_id' => 'int', 'category' => 'string', 'submission_id' => 'int', 'action' => 'string']);
        }
    });
    add_action('init',          ['BH_Auth', 'init']);
    add_action('rest_api_init', ['BH_API', 'register_routes']);
    add_action('init',          ['BH_Admin', 'init']);
    add_action('init',          ['BH_ContestWizard', 'init']);
    add_action('before_delete_post', ['BH_Admin', 'cleanup_deleted_contest']);
    add_action('init',          ['BH_CRMIntegration', 'init']);
    add_action('init',          ['BH_StyleSurfaces', 'init']);
    add_action('init',          ['BH_ElementSurface', 'init']);
    add_action('init',          ['BH_Console', 'init']);
    add_action('init',          ['BH_Reveal', 'init']);
    add_action('init',          ['BH_Judging', 'init']);
    // QA fix, caught live via WP_DEBUG_LOG: calling BH_Blocks::init()
    // directly here (at plugins_loaded time) ran wp_register_script()
    // before WordPress's own 'init'/'wp_enqueue_scripts' timing,
    // triggering a real "called incorrectly" notice — BH_Blocks::init()
    // itself doesn't nest a second add_action('init', ...) the way the
    // ORIGINAL bug class did (see class-blocks.php — register_block()
    // is called directly from init(), not re-hooked), so this is safe
    // to hook normally at the top level, same pattern BHM_Blocks
    // (bh-monetization-woo) already uses correctly.
    add_action('init',          ['BH_Blocks', 'init']);
    add_action('init',          ['BH_Discord', 'init']);
    add_action('init',          ['BH_Archive', 'init']);
    add_action('init',          ['BH_ShareCards', 'init']);

    // Registers this plugin's seeding/reset actions into the shared
    // Debug Tools page (see OUS_Debug in the core plugin) — the
    // production-safety check (OUS_Debug::is_locked()) is centralized
    // there now, checked once for every registered plugin's actions.
    add_action('init', ['BH_Debug', 'init']);
    add_action('init', ['BH_PortalPanel', 'init']);

    // Load assets only on pages that actually use the player, and hand
    // the front end everything it needs up front (REST base, a fresh
    // nonce, auth state) so there is no extra round trip before first
    // paint.
    add_action('wp_enqueue_scripts', function () {
        if (!is_singular()) return;
        global $post;
        if (!$post) return;
        // ROADMAP-ux-polish-and-feature-parity-2026-07.md 5a: has_shortcode()
        // only ever detects the literal [bh_contest_player]-style bracket
        // text — a page authored with the new bh/contest-player (etc.)
        // BLOCK instead has none of that in post_content, so without the
        // has_block() checks alongside these, a block-authored page would
        // render the block's own static container HTML (via its
        // render_callback) but never actually enqueue player.js/reveal.js/
        // archive.js — the mount div would sit there permanently inert,
        // a real, silent regression this fix closes before it ships.
        $has_player   = has_shortcode($post->post_content, 'bh_contest_player') || has_block('bh/contest-player', $post);
        $has_reveal   = has_shortcode($post->post_content, 'bh_results_reveal') || has_block('bh/results-reveal', $post);
        $has_archive  = has_shortcode($post->post_content, 'bh_archive') || has_block('bh/archive', $post);
        if (!$has_player && !$has_reveal && !$has_archive) return;

        // Shared by all three front-end shortcodes — same fonts, same
        // stylesheet, same theme variables (including any per-contest
        // override), so a Results Reveal or Archive page always matches
        // whatever look the main player has, automatically.
        $font_url = BHY_Style::google_fonts_url();
        if ($font_url) wp_enqueue_style('bh-fonts', $font_url, [], null);
        wp_enqueue_style('bh-player', BH_URL . 'assets/css/player.css', $font_url ? ['bh-fonts'] : [], BH_VER);
        wp_add_inline_style('bh-player', BHY_Style::inline_css());

        if ($has_player) {
            wp_enqueue_script('howler', 'https://cdnjs.cloudflare.com/ajax/libs/howler/2.2.4/howler.min.js', [], '2.2.4', true);
            wp_enqueue_script('bh-player', BH_URL . 'assets/js/player.js', ['howler'], BH_VER, true);
            $brand = BHY_Style::get();
            wp_localize_script('bh-player', 'BHData', [
                'rest'     => esc_url_raw(rest_url('bh/v1/')),
                'identity' => esc_url_raw(rest_url('bhi/v1/')),
                'nonce'    => wp_create_nonce('wp_rest'),
                'loggedIn' => is_user_logged_in(),
                'maxBytes' => BH_MAX_BYTES,
                'brand'    => ['part1' => $brand['brand_part1'], 'part2' => $brand['brand_part2'], 'logoUrl' => BHY_Style::logo_url($brand)],
            ]);
        }

        if ($has_reveal) {
            wp_enqueue_script('bh-common', BH_URL . 'assets/js/bh-common.js', [], BH_VER, true);
            wp_enqueue_script('bh-reveal', BH_URL . 'assets/js/reveal.js', ['bh-common'], BH_VER, true);
            wp_localize_script('bh-reveal', 'BHData', [
                'rest' => esc_url_raw(rest_url('bh/v1/')),
            ]);
        }

        if ($has_archive) {
            wp_enqueue_script('bh-common', BH_URL . 'assets/js/bh-common.js', [], BH_VER, true);
            wp_enqueue_script('bh-archive', BH_URL . 'assets/js/archive.js', ['bh-common'], BH_VER, true);
            wp_localize_script('bh-archive', 'BHData', [
                'rest' => esc_url_raw(rest_url('bh/v1/')),
            ]);
        }
    });
});
