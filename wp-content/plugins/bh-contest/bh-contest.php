<?php
/**
 * Plugin Name: BH Contest
 * Description: Music contest voting platform with a sleek, native-feeling player.
 * Version:     3.7.11
 * Requires PHP: 7.4
 * Requires Plugins: own-ur-shit
 */
if (!defined('ABSPATH')) exit;

// 3.6.4 — Added missing 'edit_item'/'add_new_item' labels to
// bh_contest/bh_submission post type registrations (class-post-types.php).
// 3.6.3 — Fixed "Manage my submission" link never rendering: BH_Auth::render()
// built it into $before, then unconditionally overwrote $before with
// BH_Element::render_slot()'s output, discarding it. Now prepended instead.
// 3.6.2 — Rejecting a submission now also logs to OUS_Audit (admin
// accountability record, separate from the contestant-facing BH_Event).
// 3.6.1 — Submission-received and rejection-notification emails now log a
// warning if wp_mail() returns false instead of failing silently.
// 3.6.0 — Submission audio file replacement: admin or contestant can swap a
// submission's audio file while the submission window is open. New file goes
// to _bh_pending_audio_id (never overwrites _bh_audio_id directly) so the
// live file stays playable/votable until an admin approves the swap via
// BH_Admin::render_approval_box(). New 'rejected' post_status with reason-code
// dropdown + freeform note, included in a rejection email; re-uploading after
// rejection flips status back to 'pending'. New "Manage my submission" link
// on the player (BH_Auth::render()) for logged-in contestants. Old
// attachments are deleted on swap-approval. Discord only announces a file
// swap if the submission was already published, to avoid a premature public
// announcement for a still-pending submission.
// Fixed two bugs found during verification: (1) the reject button lived
// inside a metabox nested in WP's own post-edit <form> — a nested <form> is
// invalid HTML, so submits silently resolved to the outer form. Rebuilt as
// plain fields + fetch() POST with no form ancestor. (2)
// register_post_status('rejected', ['exclude_from_search' => true]) broke
// `post_status => 'any'` everywhere (WP only respects exclude_from_search for
// custom statuses during 'any' expansion), making rejected submissions vanish
// from the portal. Fixed by setting exclude_from_search => false (safe since
// the post type itself is already non-public).

// 3.5.3 — submit() emits BH_Event 'bh/submission_created'; email send points
// emit 'bhcore/email_sent' — feeds the CRM's unified per-person activity
// timeline (bh-crm 1.9.0).

// 3.2.2 — Three more additive 'bh_contest_player' slots (tracklist_extra,
// now_playing_extra, results_modal_intro), same pattern as header_extra
// (3.2.1). Per-slot render+attach logic factored into shared
// attach_extra_zone()/injectExtraZone() helpers instead of duplicating.
// Note: .bh-now-playing-bar is position:fixed, so a sibling placed after it
// in the DOM doesn't visually land "below" it the way normal flow suggests —
// see player.css's comment on .bh-now-playing-extra.

// 3.2.1 — New 'header_extra' zone on the 'bh_contest_player' surface
// (class-element-surface.php), inside the header bar next to the existing
// buttons. player.css's ':empty { display: none; }' rule means a contest with
// no header_extra content renders identically to before this pass.

// 3.2.0 — bh-contest's first BH_Element surface: new 'bh_contest_player'
// surface with 'before_player'/'after_player' slots, rendered server-side in
// class-auth.php's [bh_contest_player] shortcode as siblings of the player's
// JS-owned mount div — not inside it, since player.js rebuilds that div's
// entire innerHTML on load and would wipe anything placed inside it.
// Deliberately not converted: the player's interactive skeleton
// (header/tabs/tracklist/now-playing/modals) — every method in player.js
// depends on that exact markup via this.q('.bh-results-btn')-style lookups.

// 3.1.3 — Fixed Live Console's contest-picker dropdown throwing a
// permissions error on selection: the page is a submenu of
// edit.php?post_type=bh_contest, but the dropdown's <form method="get"> only
// carried page=bh-console — a bare GET form replaces the whole query string,
// dropping post_type. Fixed by adding a hidden post_type field.

// 3.1.1 — BH_Discord::send() previously returned false identically for "no
// webhook configured" and "webhook configured but fails URL validation" (a
// real misconfiguration). The second case now logs a throttled warning.
// 3.1.2 — vote()'s DB writes are now checked and logged on failure instead of
// always claiming success. Submission upload failures now log the actual
// WP_Error. email_winners() now tracks per-recipient send failures.
// 3.1.4 — bundled zip regenerated to match installed version, no code change.
// 3.1.5 — vote()'s toggle paths now emit a BH_Event 'bh/vote' after each
// write commits (fire-and-forget, outside the vote-limit transaction).
// 3.1.6 — class-debug.php's register() now sets 'group' =>
// OUS_Debug::GROUP_SEED_RESET, part of the Debug Tools reorganization.

// 3.7.4 — Fixed enhanceSelect()'s open menu (player.js) getting clipped in
// Safari: it was `position: absolute` inside `.bh-modal-content`
// (overflow-y:auto), so it got clipped by the same overflow that lets the
// form scroll. Switched to `position:fixed`, computed from the trigger's
// screen coordinates (player.css z-index bumped past .bh-modal's 10000).
// 3.7.5 — Added an "edit-details" REST route + inline edit form in the
// portal panel so a contestant can fix a typo'd song/artist title without
// emailing an admin, gated the same way as the file-replace form.
// 3.7.6 — First OUS_Revisions consumer for postmeta-only config: a contest's
// configuration lives entirely in postmeta, so WP's native post-revisions
// would capture nothing meaningful. save_contest_meta() now snapshots every
// _bh_*/_bhy_style_json key on save; new "Version History" metabox with
// Restore buttons.
// 3.7.7 — Published contests are searchable via [ous_search]/ous/v1/search —
// only published contests, never bh_submission (holds contact info/audio).
// 3.7.8 — A contest can opt into a "Site Menu" checkbox that keeps a
// "Contests" submenu in sync via OUS_MenuSync.
// 3.7.9 — A contest can opt into "Allow submitting without audio yet": a fan
// reserves an entry with title/artist/contact alone, then uploads later via
// the portal, reusing the replace-audio endpoint for the first-time attach.
// 3.7.10 — Fixed hybrid-format Results modal dropping the Judges' Pick
// leaderboard (player.js never read the judge_results REST key). Also fixed
// a judges-only contest mislabeling its rubric percentage as "N votes".
// 3.7.11 — [bh_judge_panel] now enqueues player.css + new judging.css instead
// of rendering unstyled, and fixes button classes that referenced a
// nonexistent bh-btn-secondary class.
define('BH_VER',        '3.7.11');

// 3.7.3 — Registered the "New Contest" wizard (BH_ContestWizard) as its own
// Design Suite style surface (class-style-surfaces.php), previously invisible
// to the token editor. Fixed the same contrast bug as own-ur-shit 3.6.5: this
// preview's light wp-admin-style page inherited the dark brand theme's light
// :host text color, rendering unreadable light-on-light text.

// 3.7.2 — Judge score save (assets/js/bh-judging.js) had no .catch() at all —
// a dropped connection failed silently with no feedback. Added
// retry-with-backoff (safe: BH_Judging::save_score() is an ON DUPLICATE KEY
// UPDATE upsert, so a retry can't create a duplicate row) and an explicit
// "not saved" message if retries are exhausted.

// 3.7.1 — New BH_ContestWizard (includes/class-contest-wizard.php): a guided
// "New Contest" flow covering name/submission window/voting window/
// categories/judging format only (rounds, Discord, contact-field
// customization, and branding stay on the full edit screen with sensible
// defaults). Populates $_POST with the real edit screen's field names and
// lets wp_insert_post() fire the normal save_post_bh_contest hook, rather
// than duplicating BH_Admin::save_contest_meta()'s validation logic.

// 3.7.0 — BH_Auth::render() now calls BH_SEO::set_page_data() with an Event
// JSON-LD block (name, description, start/end dates, organizer) for any
// resolved contest. 'eventAttendanceMode' is OnlineEventAttendanceMode and
// 'location' is omitted since a vote has no physical venue. class_exists()-
// guarded.

// 3.6.9 — .bh-modal used unprefixed `backdrop-filter: blur(2px)` with no
// `-webkit-backdrop-filter` fallback, so older Safari silently dropped the
// blur. Added the prefixed declaration alongside the standard one.

// 3.6.8 — Registered two widgets (Submissions, Votes cast) with own-ur-shit's
// shared Metrics dashboard (OUS_Metrics), reading the bh/submission_created
// and bh/vote events already emitted by class-api.php. class_exists()-guarded.

// 3.6.7 — player.js had the submit-modal's profile field list independently
// duplicated across three call sites (appendProfileFields(),
// prefillSubmitProfile(), applyContactFields()/contactFields.show), in sync
// by luck rather than by construction — the JS-side counterpart to
// BHI_Profiles::TEXT_COLS being single-sourced on the PHP side. Collapsed
// into one PROFILE_FIELDS/CONTACT_FIELD_KEYS pair at module scope.

// 3.6.6 — Production-hardening pass: two data-integrity/UX bugs fixed.
// (1) Trapped vote slot: vote()'s toggle-OFF path was gated behind the same
// "submission still published" check the toggle-ON path needs, so rejecting
// an already-published, already-voted-on submission permanently trapped
// every affected voter's vote with no way to free it. Fixed by only
// enforcing that gate on new votes, and by having
// handle_reject_submission() delete the submission's vote rows at rejection
// time to auto-refund voters.
// (2) New before_delete_post cleanup (cleanup_deleted_contest()) — deleting
// a contest previously left every submission/vote row referencing it as a
// silent orphan. Submissions are trashed (not hard-deleted) for a recovery
// window.
// Also: wp_die() calls across admin-post handlers now pass back_link => true.

// 3.6.5 — New class-share-cards.php: "Now Entered"/"Vote Now" public/no-login
// share cards (?bh_share_entered={id} / ?bh_share_vote={id}) rendering only
// title/artist/contest name (submission audio/notes/contact stay locked
// down), via the shared BH_ShareCard engine (own-ur-shit 3.5.2). Since
// bh_submission has no public single template to deep-link to, the "vote"
// card instead pairs with the contest's own page URL (_bh_page_id). New
// per-contest card-style radio (_bh_share_card_style) picks a card template,
// separate from the existing color-override meta box.

// 3.5.2 — Votes CSV export (class-admin.php) had no id tiebreaker on
// ORDER BY created_at ASC; many votes routinely land in the same second, so
// export order was non-deterministic intra-second. Fixed with `, id ASC`.

// 3.5.1 — BH_Blocks::init() (new in 3.5.0) was called directly from
// plugins_loaded rather than hooked onto 'init', so wp_register_script()
// inside it ran before WordPress's own timing rules allow, logging a "called
// incorrectly" notice. Fixed by hooking add_action('init', ...) normally,
// matching BHM_Blocks' (bh-monetization-woo) pattern.

// 3.5.0 — Three new blocks via wp.serverSideRender (class-blocks.php,
// assets/js/bh-contest-blocks.js): 'bh/contest-player', 'bh/results-reveal',
// 'bh/archive'. All three old shortcodes stay registered. These blocks only
// ever render a static mount div — the actual interactive behavior (voting,
// playback, reveal, archive grid) is player.js/reveal.js/archive.js
// hydrating that div on a real front-end page load, not something
// ServerSideRender previews in the editor canvas.
// Fixed a related regression before it shipped: the front-end asset-enqueue
// gate only checked has_shortcode() against post_content, so a
// block-authored page (no literal bracket text) would render the mount div
// but never enqueue player.js/reveal.js/archive.js. Fixed by adding
// has_block() alongside each has_shortcode() check.
// Known gap: class-debug.php's player_page_url() (a Debug Tools convenience
// link) still only scans post_content for the literal shortcode string, so
// a block-only contest falls back to the site home — a debug-convenience
// degradation, not a functional break.

// 3.4.0 — Multi-round/elimination format. A contest gets an optional
// `_bh_rounds` config (name + submission window + voting window + cut count,
// 1-4 rounds); a contest that never sets this behaves exactly as before
// (BH_Rounds falls back to single-window logic when `_bh_rounds` is empty).
// bh_votes and bh_judge_scores both gained a `round` column
// (class-activator.php, DB_VERSION 1.6 → 1.7) — each round's votes/scores
// are independent rows, so a round-2 re-vote doesn't inherit round-1's
// tally. `_bh_round_reached` post meta tracks how far a submission has
// survived; vote()/judge scoring reject submissions that didn't make the
// current round's cut. New admin action ("Close round N", class-admin.php's
// ajax_advance_round() → BH_Rounds::advance_round()) tallies the active
// round and opens the next round for survivors only. class-reveal.php's
// build_sequence() reveals only the active round's tally for a multi-round
// contest (a cross-round "Overall" reveal wouldn't be coherent once rounds'
// votes are independent).
// Fixed during implementation: dbDelta() attempts adding a
// same-named-but-different-columns unique key as a bare ADD rather than
// replacing the existing one, failing with "Duplicate key name" before this
// migration's own DROP+ADD index-rebuild code could run. Fixed by moving
// that rebuild to run before dbDelta() on both tables.
// Known gap: no dynamic add/remove UI for rounds beyond a plain "1-4" count
// select; player.js's front-end results widget doesn't render round-scoped
// results yet (only Reveal Party and the raw REST response do).

// 3.3.1 — In-house IP+cookie anti-fraud signal, no third-party CAPTCHA
// vendor. bh_votes gained ip_address/voter_fp columns (class-activator.php,
// DB_VERSION 1.5 → 1.6); voter_fp is a long-lived first-party httponly
// cookie identifying a browser independent of which account is logged in.
// New BH_Helpers::suspicious_ip_clusters(): flags several different
// accounts voting from the same IP within a short window (a shared IP alone
// is normal, not itself the signal), and separately notes when every
// account in a cluster also shares the same fingerprint. Manual-review-only,
// same posture as the existing suspicious_voters() check — never blocks a
// vote or auto-flags an account, only surfaces a cluster on the Results
// console. Privacy note: ip_address is personal data under most privacy
// regimes — a site publishing a privacy policy should mention IP retention
// for anti-fraud review.

// 3.3.0 — Judge/rubric scoring mode. A contest gets a Format setting
// (public/judges/hybrid — public is the existing default), an admin-defined
// rubric (criteria + max score), and a per-contest judge list (plain WP user
// IDs, not a new capability/role, since most judges are guest volunteers
// with no wp-admin access). New bh_judge_scores table (class-activator.php,
// DB_VERSION 1.4 → 1.5), deliberately separate from bh_votes since a judge
// score is multi-criterion with an editable draft-then-submit state a public
// vote's shape has no room for. New BH_Judging (class-judging.php): a
// front-end [bh_judge_panel] shortcode gated on the contest's judge list.
// judge_results() normalizes each judge's per-criterion scores to 0-100 and
// averages across judges, returned in the same ranked shape
// category_results()/overall_results() already use, so BH_Reveal's existing
// medal/tier logic needed no changes. class-reveal.php's build_sequence()
// branches on format: 'judges' swaps the tally source, 'hybrid' runs both as
// two separate labeled leaderboards (not a blended score). The public
// /bh/v1/results REST endpoint got the same branching (a 'judge_results' key
// only appears for judges/hybrid contests).
// Known gap: the Discord results-announcement still reads the public vote
// tally only — a pure-judges contest's announcement will show an empty
// tally until that integration is updated separately.
define('BH_PATH',       plugin_dir_path(__FILE__));
define('BH_URL',        plugin_dir_url(__FILE__));
define('BH_VOTE_BASE',  1);                 // votes every user gets
define('BH_VOTE_BONUS', 1);                 // extra votes earned by submitting a track
define('BH_MAX_BYTES',  20 * 1024 * 1024);  // max upload size
define('BH_REG_THROTTLE', 3);               // max registrations per IP per hour
define('BH_LOGIN_MAX_FAILS', 5);            // failed logins (per username+IP) before a 15-minute lockout

foreach (['activator', 'post-types', 'helpers', 'auth', 'api', 'admin', 'contest-wizard', 'debug', 'crm-integration', 'console', 'reveal', 'discord', 'archive', 'style-surfaces', 'element-surface', 'portal-panel', 'judging', 'rounds', 'share-cards', 'blocks', 'test-suite'] as $f) {
    require_once BH_PATH . "includes/class-$f.php";
}

// Safe to register unconditionally — activation only touches this plugin's
// own table/default pages, not the identity/style classes it depends on.
register_activation_hook(__FILE__, ['BH_Activator', 'activate']);

/**
 * Gated behind plugins_loaded rather than checked directly here: WordPress
 * loads active plugins' files in alphabetical folder order, so a direct
 * class_exists() check at file-parse time could run before the dependency's
 * file has been read yet. plugins_loaded always fires after every active
 * plugin's main file has loaded, regardless of folder name order.
 */
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Contest</strong> requires the <strong>Own Ur Shit</strong> plugin to be installed and active.</p></div>';
        });
        return;
    }

    // One-time migration of profile data into the core plugin's identity
    // table (schemas are identical; INSERT IGNORE makes this safe to re-run).
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
        // If old exists but new doesn't yet, leave the flag unset so this
        // retries on a later request instead of giving up silently.
    }

    BH_Activator::maybe_upgrade();
    BH_Activator::maybe_migrate_style_meta_keys();

    add_action('admin_init',    ['BH_Activator', 'maybe_create_default_pages']);
    add_action('init',          ['BH_PostTypes', 'register']);
    // Registers the 'bh/vote' event type; class-api.php's vote handler emits it.
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
    add_action('init',          ['BH_Blocks', 'init']);
    add_action('init',          ['BH_Discord', 'init']);
    add_action('init',          ['BH_Archive', 'init']);
    add_action('init',          ['BH_ShareCards', 'init']);

    // Registers this plugin's seeding/reset actions into the shared Debug
    // Tools page; production-safety checks are centralized in OUS_Debug.
    add_action('init', ['BH_Debug', 'init']);
    add_action('init', ['BH_PortalPanel', 'init']);
    if (class_exists('OUS_TestRunner')) add_action('init', ['BH_TestSuite', 'init']);

    // Load assets only on pages that actually use the player.
    add_action('wp_enqueue_scripts', function () {
        if (!is_singular()) return;
        global $post;
        if (!$post) return;
        // has_block() checks are needed alongside has_shortcode(): a
        // block-authored page has none of the literal bracket text, so
        // without this the mount div would render but never get its JS.
        $has_player   = has_shortcode($post->post_content, 'bh_contest_player') || has_block('bh/contest-player', $post);
        $has_reveal   = has_shortcode($post->post_content, 'bh_results_reveal') || has_block('bh/results-reveal', $post);
        $has_archive  = has_shortcode($post->post_content, 'bh_archive') || has_block('bh/archive', $post);
        if (!$has_player && !$has_reveal && !$has_archive) return;

        // Shared across all three shortcodes so Reveal/Archive pages match
        // the player's look automatically, including per-contest overrides.
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
