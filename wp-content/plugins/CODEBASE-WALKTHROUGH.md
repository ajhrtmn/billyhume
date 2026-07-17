# Codebase Walkthrough — a complete guided curriculum

You built this project's features by directing the work, not by writing the code. This doc is for the next step: actually being able to open any file in any of the seven plugins, read it, and understand what it does and why — well enough to change something yourself if you want to.

This is a genuinely comprehensive curriculum, not a highlights reel. **Module 0 and Module 1 (the shared foundations) are required reading, in order** — nearly everything in Modules 2 through 8 leans on patterns explained there. Once you've finished Module 1, the plugin-specific modules (2–8) can be read in any order, or skipped to based on whatever you're currently curious about — each one is written to stand on its own.

Line numbers throughout are approximate ("around line X") — this codebase keeps evolving, so treat them as "look nearby," not GPS coordinates.

Before you start: read `VISION.md` at the plugins root (five minutes) for the one-paragraph pitch of what this whole ecosystem is and why it's built as seven separate plugins instead of one giant one.

---

# Module 0 — WordPress concepts you'll need everywhere

You don't need to become a WordPress developer. You need five ideas, explained once, so the rest of this tour doesn't stop to re-explain them every time they show up (and they show up constantly).

**A "plugin" is just a folder of PHP files that WordPress loads.** Each of the seven plugins in this ecosystem (`own-ur-shit`, `bh-contest`, `bh-courses`, `bh-crm`, `bh-monetization-woo`, `bh-registry`, `bh-streaming`) is one folder, with one main file at its root (e.g. `bh-courses/bh-courses.php`) that WordPress reads first. That main file's job is almost always the same: load a bunch of other files, then tell WordPress "when X happens, run this code."

**"Hooks" are how WordPress lets plugins run code at the right moment, without editing WordPress itself.** There are two flavors:

- **Actions** — "when X happens, DO this." Written as `add_action('some_event_name', 'some_function_to_call')`. WordPress posts "a new comment was just submitted," and any plugin that pinned a note saying "call me when that happens" gets called.
- **Filters** — "when X is about to happen, let me CHANGE the data first." Written as `add_filter('some_data_name', 'a_function_that_returns_a_modified_version')`. Same idea, but the function receives a value, can modify it, and must hand it back.

**`$wpdb` is how PHP code talks to the actual database.** WordPress stores everything — posts, users, settings, and every custom table this ecosystem added (wallets, quiz progress, contest votes) — in a MySQL database. `$wpdb` is WordPress's built-in helper object for reading and writing that database directly. You'll see `$wpdb->get_results(...)` (read rows), `$wpdb->insert(...)` (write a row), and `$wpdb->prepare(...)` (safely build a query with user-supplied values, so someone typing SQL into a form field can't manipulate your database — this is called SQL-injection protection) throughout.

**`class_exists('SomeClassName')` is how one plugin checks "is that OTHER plugin even installed and active?"** This is the single most important pattern in this whole ecosystem, because these seven plugins are designed to work independently — `bh-courses` should work fine even if `bh-monetization-woo` (which handles payments) isn't installed at all. Every time one plugin wants to use a feature from another, it first checks `class_exists('TheOtherPlugin'sMainClass')`, and if that comes back false, it just skips that feature gracefully instead of crashing.

**A Custom Post Type (CPT) is how WordPress represents "a kind of thing" beyond blog posts.** WordPress ships knowing about "Posts" and "Pages," but plugins can register their own kinds — a course, a track, a contest submission — and they all get the same underlying machinery for free (an edit screen, a URL, the ability to attach custom fields called "post meta"). You'll see `register_post_type('some_name', [...])` constantly; whenever you do, mentally read it as "this plugin is teaching WordPress about a new kind of content."

**If you're coming from .NET, four quick mental anchors:**

- **Hooks (actions/filters)** are roughly what C# events/delegates are, or a middleware pipeline if you've used ASP.NET — "subscribe a handler, it fires when the thing happens." Filters are the closer analogy to middleware specifically, since they take a value, transform it, and pass it on.
- **`$wpdb`** is raw-SQL-first, like Dapper — not an ORM like Entity Framework. No change-tracking, no LINQ, no migrations-as-code; you write the SQL and read the rows yourself.
- **`class_exists()` guards** play the role reflection-based type checks or optional DI would in C# — "is this type even loaded/registered? If not, degrade gracefully" instead of a hard constructor-injected dependency that would throw if missing.
- **WP-Cron** is a lightweight, much less robust cousin of Hangfire or Quartz — "run this on a schedule" but driven by site traffic hitting WordPress, not a real background service.

That's it. Five ideas plus four anchors. Everything else you'll learn by seeing it in real code, starting now.

---

# Module 1 — own-ur-shit: the shared foundation

`own-ur-shit` is the plugin every other plugin depends on. It provides shared logins, a shared design system, and a set of reusable services (notifications, background jobs, error logging, etc.) that any other plugin can opt into with one line of code. This module is long because it's genuinely the most important one — once you understand these patterns, every other plugin becomes fast to read, because they're all built the same way.

## 1.1 — The bootstrap: how one plugin turns on

**Open:** `own-ur-shit/own-ur-shit.php`

**Look at the `foreach` loop, around lines 210–212:**
```php
foreach ([...long list of names...] as $f) {
    require_once OUS_PATH . "includes/class-$f.php";
}
```
This just loads every file in the list. Each name maps to a real file in the `includes/` folder — `debug-log` means `includes/class-debug-log.php`, and so on. Nothing DOES anything yet — this just makes the code available.

**Now look at the long block of `add_action('init', [...])` calls, roughly lines 224–247.** Each line says "when WordPress fires its 'init' event (which happens on every page load, very early), call this class's `init()` method." Pick one — around line 236, `add_action('init', ['OUS_DebugLog', 'init']);` means "on every page load, call `OUS_DebugLog::init()`."

**Open `own-ur-shit/includes/class-debug-log.php` and find its own `init()` method** near the top. Notice it's just MORE `add_action`/`add_filter` calls — this class is registering its OWN hooks for things it cares about. This two-layer pattern — "the bootstrap turns on every class's `init()`, and each class's `init()` registers whatever specific hooks it needs" — is used identically by every plugin in this ecosystem. Once you've seen it here, you've seen it everywhere.

## 1.2 — The peer-optionality pattern (the architecture's core idea)

**Open `bh-courses/bh-courses.php`, around lines 130–136:**
```php
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Courses</strong> requires the <strong>Own Ur Shit</strong> plugin...</p></div>';
        });
        return;
    }
    // ... the rest of the plugin only runs if we got here
```
This is `bh-courses` checking "is the core plugin actually active?" If not, it shows a friendly admin warning and stops. This is a **hard requirement** — bh-courses genuinely cannot work without the core.

**Now open `bh-courses/includes/class-gate.php`, around lines 39–46:**
```php
public static function user_can_access_course($user_id, $course_id) {
    if (!class_exists('BHM_Gate')) return true; // bh-monetization-woo not active: nothing gates anything
    ...
```
This is a **soft, optional** dependency. `BHM_Gate` belongs to `bh-monetization-woo` (the payments plugin). If that plugin isn't installed, this function just says "everyone can access everything" — courses work fine with zero payment features. Install `bh-monetization-woo` later, and this same function starts actually checking payment status, with no code changes needed anywhere.

This is THE defining idea of this ecosystem's architecture. Every plugin is useful alone, and gains new behavior automatically when a sibling plugin is also active — never by editing existing code, only by that existing code already having a `class_exists()` check waiting.

## 1.3 — Two shapes a shared service takes

**Shape A — pure functions, no hooks.** **Open `own-ur-shit/includes/class-reliable-store.php` in full** (it's short, under 100 lines). `OUS_ReliableStore` has four plain functions: `set()`, `get()`, `delete()`, `increment()`. Read the big comment at the top — it explains a real bug found this session: WordPress's normal "temporary storage" feature (called a transient) turned out to be unreliable on this specific site, so this class was built as a hand-rolled, more dependable replacement that talks directly to the database instead of trusting WordPress's caching layer. Then open `own-ur-shit/includes/class-auth.php` and search for `OUS_ReliableStore` — you'll find it used for login-attempt lockouts and registration rate-limiting, a real caller using this small utility to solve a real problem.

**Shape B — hook-driven.** **Open `own-ur-shit/includes/class-jobs.php`.** This is a "do this later, not right now" queue — useful when a plugin wants something to happen without making the visitor wait for it. Read the usage comment near the top (roughly lines 13–22) for the two-line pattern any plugin uses to register a handler and queue up work. Its `init()` (around line 36) hooks itself onto WordPress's Cron system — "run this on a schedule," a time-triggered cousin of the event-triggered hooks you already know. `enqueue()` writes a row to a jobs table; `run_due_jobs()` (fired by the cron hook) picks up pending rows and executes them, retrying failed ones up to `MAX_ATTEMPTS` (a constant near the top of the class) before giving up.

Nearly every other shared service in `own-ur-shit/includes/` is one of these two shapes — pure functions, or hook-driven.

## 1.4 — Notifications: a real consumer of the "zero registration" idea

**Open `own-ur-shit/includes/class-notifications.php`.** Read the doc-comment (roughly lines 4–26): any plugin can fire a notification with one call — `OUS_Notifications::notify($user_id, $type, $title, $body, $url, $source, $email)` (the method itself is around line 69) — with no setup required anywhere else. It writes to a database table (`table()`, around line 56) so the notification always appears in-app, and optionally queues an email through `OUS_Jobs` from Section 1.3 (so `notify()` never has to wait around for an email server to respond). Three delivery surfaces read from that same table: an admin-bar bell icon (`admin_bar()`, ~line 143), a `[bh_notifications]` shortcode any page can embed (`register_shortcode()`, ~line 170), and a panel inside the user account portal (Section 1.6 below).

## 1.5 — Debug Tools: the page built for you

You've already used this page directly. Now read the code behind it.

**Console & Logs** (`own-ur-shit/includes/class-debug-log.php`) — any plugin calls `OUS_DebugLog::log($level, $message, $context, $source)` to record "something happened," almost always "something went wrong that a visitor wouldn't otherwise see." Search the whole plugin tree for `OUS_DebugLog::log(` to see dozens of real examples. This is the single most useful file for you to understand day-to-day, because it's how you'll diagnose a real bug report going forward.

**Test Runner** (`own-ur-shit/includes/class-test-runner.php`) — any plugin registers a "suite" of checks via `add_filter('bhcore_test_suites', ...)`. Clicking "Run all tests" runs every registered suite live, on your actual site. `bh-courses/includes/class-test-suite.php` is a good one to skim — mostly plain, readable lines like `OUS_TestRunner::assert_same(100, $r['score'], 'All correct scores 100')`.

**API Docs** (`own-ur-shit/includes/class-api-docs.php`) — this one's a neat trick: it reads WordPress's own live list of registered API routes (`rest_get_server()->get_routes()`) and automatically turns them into readable documentation (`generate_spec()`, ~line 145) — nobody hand-writes or maintains this, it's always in sync with the actual code because it's generated FROM the actual code, filtered down to just this ecosystem's own routes (`is_relevant()`, ~line 132, checks for the `ous/`, `bhi/`, `bh` namespace prefixes).

## 1.6 — Roles, 2FA, and the account portal

**OUS_Roles** (`own-ur-shit/includes/class-roles.php`) — defines a small set of granular *capabilities* (not new roles) — look at the `DEFAULT_CAPS` constant near the top. `bhcore_manage_students` is one example, used by bh-courses' Student Progress admin page. The idea: instead of inventing a whole new "Course Manager" role, this lets a site owner grant one specific ability to an existing account.

**BHI_TwoFactor** (`own-ur-shit/includes/class-two-factor.php`) — a real TOTP (Time-based One-Time Password, the same standard behind most "6-digit code from an authenticator app" flows) implementation. `totp_at()` (~line 97) is the actual code-generation math, worth a skim purely to see that "2FA" isn't magic — it's a documented, standard algorithm (RFC 6238) computing a 6-digit code from a shared secret and the current time. `verify_code()` (~line 113) checks the submitted code against the current time window (and one window on either side, to tolerate small clock drift). `gate_login()` (~line 138) is where this plugs into WordPress's own login process — it hooks onto the `authenticate` filter at a priority number specifically chosen to run AFTER WordPress's own password check, so this code never has to re-implement password verification itself.

**BHI_Portal** (`own-ur-shit/includes/class-portal.php`) — the custom, branded `/account/` area (as opposed to the default WordPress admin dashboard). `add_rewrite()` (~line 157) teaches WordPress that URLs like `/account/` and `/account/something/` should be handled by this plugin — this is called a "rewrite rule," WordPress's mechanism for mapping a pretty URL to the actual code that should run for it. Any plugin contributes a panel to this portal via `apply_filters('bhi_portal_panels', [])` (read near ~line 342) — the same "contribute to a shared list" idea you'll see again in Section 1.7. **Worth knowing:** this class's rewrite rule has an active, not-yet-resolved bug on this specific site (it isn't reliably "sticking" in WordPress's own rewrite table) — a good real-world example that not everything in a codebase is finished or working, and that's normal, not a sign you're missing something.

## 1.7 — BH_Content / BH_Studio: the block-authoring system

This is a genuinely more advanced piece — the system that lets content (course lessons, and potentially other things later) be built from reusable, nested "blocks" (a text block, an image block, a quiz block) instead of one fixed form.

**Open `own-ur-shit/includes/class-content.php` and find `register_block_type()`** (~line 61):
```php
public static function register_block_type($type, array $schema, callable $renderer) {
    self::$types[$type] = ['schema' => $schema, 'renderer' => $renderer];
}
```
This is the whole idea in one function: any plugin can say "here's a new kind of block, here's what data it needs (`$schema`), here's how to turn it into HTML (`$renderer`)." `BH_Content` itself doesn't know or care what a "quiz block" actually is — it just keeps a list of block types other plugins hand it.

**Now open `own-ur-shit/includes/class-studio.php` and find the calls to `BH_Content::register_block_type('bh/...`** (~lines 127–160) — this is where the actual default block types (container, heading, text, image, button) get registered, using the function you just read. This is the same "shared service, other plugins contribute to it" idea from the notifications/portal sections, just for content types instead of notifications or account panels.

## 1.8 — BH_Commerce: a "swap the backend later" interface

**Open `own-ur-shit/includes/class-commerce.php`** — the doc-comment at the top and the first two methods, `available()` and `has_subscriptions()` (~lines 40–46).

The big idea, in the comment's own words: other plugins ask `BH_Commerce` to create a product, check an order, etc., instead of calling WooCommerce (the actual payments plugin) directly. Today, `BH_Commerce`'s methods are thin wrappers that immediately turn around and call WooCommerce. But because every OTHER plugin only ever talks to `BH_Commerce`, replacing WooCommerce with something else later would mean rewriting this one file, not every plugin that sells something. You'll see this pattern used for real in Module 8 (bh-monetization-woo), where it was actually migrated onto — worth returning to this section after reading that module, to see the theory made concrete.

## 1.9 — BHY_UI and the design-token system

Three files work together here, each with a distinct job:

**`class-ui.php`** (`BHY_UI`) is a PHP helper library for consistent admin markup — `design_system_css()` (~line 342) holds the actual CSS, `shell_open()`/`shell_close()` (~line 517) wrap every admin page in the same consistent chrome, and small helpers like `swatch_field()`/`slider_row()` render reusable settings controls so every plugin's settings screens look and behave the same without copy-pasting HTML.

**`class-style.php`** (`BHY_Style`) owns the actual *values* — colors, fonts, spacing — as "design tokens." `inline_css()` (~line 285) emits these as CSS custom properties (variables), which is how a single color change in one place can affect every plugin's front-end rendering at once.

**`class-style-gallery.php`** (`BHY_Gallery`) is a live admin page (`add_menu()`, ~line 49) showing every design element in one place, so you can see what changing a token would actually look like before touching anything. Other plugins register their own preview sections into this gallery via a documented filter, the same contribute-to-a-shared-list pattern one more time.

**Why Module 1 matters before moving on:** you've now seen every repeatable shape this codebase uses — the bootstrap/`init()` two-layer pattern, hard vs. soft dependencies via `class_exists()`, pure-function vs. hook-driven shared services, and three different "any plugin can contribute to a shared list" mechanisms (notifications delivery, portal panels, block types, style gallery sections). Every module from here on is just those same shapes, wearing a different plugin's clothes.

---

# Module 2 — bh-courses: a real feature, end to end

This is likely the feature you understand best already from directing its build, which makes it a good place to connect "the feature I asked for" to "the actual code that implements it."

**Open these four files in `bh-courses/includes/`, in order, reading just the top doc-comment of each first:**

1. **`class-post-types.php`** — defines two content types (Module 0's CPT idea in action): "course" and "lesson," and how a course knows which lessons belong to it (an ordered list of lesson IDs stored as post metadata — WordPress's mechanism for attaching arbitrary extra data to a post).
2. **`class-steps.php`** — a lesson isn't one blob of content, it's a sequence of "steps" (text, image, video, quiz), stored as one JSON array on the lesson. This file owns saving/validating that array and scoring quiz attempts.
3. **`class-progress.php`** — tracks, per student, which steps are done. Read the top comment: a plain step completes on a click, a quiz step only completes by passing.
4. **`class-render.php`** — turns all of the above into actual HTML a visitor sees: the course catalog (with search/filter/sort), the course detail page, and the step-by-step "lesson player."

**Then open `class-progress.php` again and read `step_status()`** (~line 26) — a real, simple `$wpdb` query: given a user, a lesson, and a step number, what does the database say about it? This is Module 0's `$wpdb` idea doing real work for a feature you know.

**Worth a look while you're in `class-progress.php`:** `mark_step_complete()` gained an `$answers_json` parameter this session, storing exactly what a student answered on a quiz (not just pass/fail) so a later review can show "here's what you picked vs. the right answer." Search for `stored_answers()` to see the read side of that — it decodes the stored JSON back into a usable array, returning `null` cleanly if a row predates this feature, rather than erroring on old data.

**Why this matters:** you now have a mental map for the biggest, most complex single feature in this ecosystem — four files, each with one clear job, that together make "a course" work. This same four-piece shape (post types → content/data logic → progress/state tracking → rendering) is worth watching for as you read the other plugin modules; it doesn't repeat identically everywhere, but the instinct to ask "which file owns WHAT is stored, which file owns WHAT COUNTS as done, which file owns turning it into HTML" will serve you in any of them.

---

# Module 3 — bh-streaming: a personal streaming library

This plugin has the most genuinely different feature shapes in the ecosystem — worth reading even if music streaming isn't your main interest, because it introduces ideas (real-time-ish sync, content recommendation, external-service health monitoring) that don't show up elsewhere.

## 3.1 — The content model

**Open `bh-streaming/includes/class-post-types.php`.** Four content types: `bhs_track` (~line 40), `bhs_release` (~line 56), `bhs_playlist` (~line 65), `bhs_feed_source` (~line 77) — plus one taxonomy, `bhs_genre` (~line 86), attached to tracks. Notice artist/release relationships are stored as post-meta links rather than a second taxonomy — a design choice you'll also recognize from bh-courses' lesson-order list, a recurring "just store an ID or a list of IDs as meta" pattern for relationships that don't need WordPress's full taxonomy machinery.

## 3.2 — Feed aggregation and the health-check you've already seen fixed

**Open `bh-streaming/includes/class-feeds.php`.** `sync_all()`/`sync_one()` (~lines 179–283) pull tracks in from external feed sources on a schedule. `check_external_track_health()` (~lines 230–271) is worth real attention: it does a cheap `wp_remote_head()` request first (asking "are you there?" without downloading the whole file), and falls back to a small ranged download if a host doesn't support that (a real-world compatibility note, not paranoia). It tracks consecutive failures per track and only flags a track as truly "down" after three in a row (avoiding false alarms from one blip). This session added logging that fires ONLY when a track's health status actually *changes* — not on every single check, which runs on a schedule and would otherwise flood the log with "still fine" restatements. This is a good concrete example of "log the transition, not the check" as a design principle worth recognizing elsewhere.

## 3.3 — Jam sessions: the shared-listening feature

**Open `bh-streaming/includes/class-jam.php` and read the doc-comment at the top in full** (roughly lines 5–29) — it's a genuinely good example of documented engineering tradeoffs. The core decision: this feature lets multiple people listen in sync, and the "obvious" way to build that is a WebSocket (a permanently-open connection between browser and server for instant updates). The comment explains why that was deliberately NOT used — most ordinary web hosting doesn't allow long-lived socket servers, so building on one would mean this feature simply couldn't run on the hosting this ecosystem targets. Instead, it polls: clients check in roughly every two seconds and ask "what's the current state?"

**The actual mechanism:** `push_host_state()` (~line 363) is how the session host's playback position gets written; `get_state()` (~line 342) is how every other participant's browser reads it back. Notice the stored state includes both a position AND a timestamp of when that position was recorded — that lets each listener's browser locally estimate "it's probably 1.3 seconds further along now" between polls, instead of visibly jumping every two seconds. Two control models exist: `host` (only the session creator can skip/pause) and `vote_skip` (~line 394, a majority vote among participants) — the doc-comment explicitly frames this second mode as a deliberate middle ground between two real products (Spotify Jam's host-only model, and a fully democratic free-for-all).

## 3.4 — The recommendation engine

**Open `bh-streaming/includes/class-recommendations.php` and read `get_related()`** (~lines 21–55). The doc-comment states plainly this is deliberately content-based, not a machine-learning/collaborative-filtering system (the kind that says "people who liked this also liked..."). The actual scoring is simple and readable: +3 points for the same artist, +4 for the same release, +1 per shared genre tag — then sort by score and take the top 10. Worth reading end to end specifically because it demonstrates that "recommendation engine" doesn't have to mean anything exotic — a short, explainable scoring function can be a completely legitimate, honest implementation of the idea, and the doc-comment is upfront that this is a deliberate choice, not a placeholder for something fancier later.

## 3.5 — Stats and the artist-facing dashboard

**Open `bh-streaming/includes/class-stats.php`.** `record_play()`/`record_skip()` (~lines 33–41) write events to a dedicated daily-stats table (`table()`, ~line 26) — kept separate from the main post/postmeta tables because this is high-volume, append-only event data, a different shape than "the current state of a course's progress" from Module 2. `add_admin_page()`/`render()` (~lines 82–88) turn that raw event data into the dashboard an artist actually sees.

---

# Module 4 — bh-contest: a music contest voting platform

## 4.1 — The voting system and its race-condition protection

**Open `bh-contest/includes/class-api.php` and find `vote()`** (~lines 108–201). This is worth reading slowly — it's the clearest real example in the whole codebase of a genuinely tricky problem: what happens if the same person clicks "vote" twice in the same fraction of a second (a double-click, or a retried network request)? Without protection, both requests could each check "have I hit my vote limit yet?", both see "no," and both proceed — letting someone vote twice when the limit was one.

The fix: `START TRANSACTION` (~line 175) begins a database transaction (a way of grouping several database operations so they either all happen or none do), then `SELECT COUNT(*) ... FOR UPDATE` (~lines 177–179) counts existing votes WHILE placing a lock on those rows — `FOR UPDATE` is the specific instruction that says "nobody else can touch these rows until I'm done," closing the exact gap the double-click could exploit. If the count is already at the limit, it `ROLLBACK`s (undoes everything, ~line 183); otherwise it inserts the new vote and `COMMIT`s (~line 200). This is the pattern to recognize any time you see "how do we stop two things from happening at once when only one should" — a transaction plus a row lock, not a simple "check first" that leaves a gap.

## 4.2 — Results and the reveal system

**Open `bh-contest/includes/class-api.php`'s `category_results()`** (~lines 351–372) — a straightforward `GROUP BY`/`COUNT`/`ORDER BY` query turning raw vote rows into a ranked list, with ties handled via a shared ranking helper (`BH_Helpers::competition_ranks()`) so two submissions tied for 2nd place both show "2," and the next one shows "4," not "3" (this is called "competition ranking," the same style used in actual sports standings).

**Open `bh-contest/includes/class-reveal.php` and read its top comment.** "Reveal" here means a live, on-stream results ceremony — counting up from 3rd to 1st place per category, then an overall reveal, styled after an awards-ceremony pacing. Notice the design has two genuinely separate pieces: a private, capability-gated admin control panel (Next/Previous buttons) and a public, no-login results DISPLAY meant to be captured by streaming software (OBS) — and the doc-comment explains why they're separate: the display polls its own state independently, so the person clicking "next" and the computer capturing the video feed for a livestream can be two entirely different machines.

## 4.3 — The submission flow

**Open `class-api.php`'s `submit()`** (~lines 210–300). A good file to read for "here's everything a real, production form-submission handler actually has to check": is the email verified, is the submission window currently open, has this person already submitted, is their profile complete enough, is a file actually attached, is it under the size limit, is it actually an audio file (checked server-side, since a browser's file-picker filter is easy to bypass). Notice the duplicate-prevention mechanism (~lines 262–267) uses `add_option()` rather than a simple database check — the comment explains why: WordPress options are guaranteed unique at the database level, so this closes the same kind of double-submission race condition Section 4.1 solved for votes, just using a different (simpler, appropriate-for-this-case) tool.

---

# Module 5 — bh-crm: shared identity, and an honest wrinkle

## 5.1 — The People page

**Open `bh-crm/includes/class-people.php`.** `render()` (~lines 72–78) dispatches to either a roster list or one person's detail view. `active_user_ids()` (~lines 59–68) decides who even shows up on the list — anyone with real identity data on file, plus anyone any other active plugin flags as "active" via a filter (`bh_crm_active_user_ids`) — the same "contribute to a shared list" idea from Module 1, now used to build a roster instead of a notification or a portal panel. The detail view pulls together identity fields, tags, notes, and — via another filter (`bh_crm_activity_summary`) — space for other plugins to contribute a summary of that person's activity (contest entries, course progress, etc.), though as of this writing no other plugin actually hooks into that filter yet — a real example of an extension point that exists and is documented, but doesn't have a live user. That's normal in a growing codebase; not every hook gets used the moment it's built.

## 5.2 — The wrinkle: an exception to the rule you just learned

Module 1.2 taught you that plugins talk to each other through `class_exists()` checks and public methods, never by reaching directly into another plugin's database tables. Until this session, `bh-crm` broke that rule.

**Open `bh-crm/includes/class-people.php` around line 65, and `bh-crm/includes/class-export.php` around line 14.** Both now call `BHI_Profiles::user_ids_with_profile_data()` — a real method on the core plugin's own `BHI_Profiles` class. Read the comment above each call site: both explicitly cite a code-quality audit finding, and both explain that the OLD code ran identical raw SQL directly against the core plugin's `bhi_profiles` table from two separate files — a genuine encapsulation violation (bypassing the class that's supposed to own and control access to that data), made worse by being duplicated rather than shared.

This is worth understanding specifically because it's an HONEST wrinkle, not a hidden one — the fix, and the comments explaining why it was a problem, are right there in the code. A codebase that never has anything like this is either very young or not being looked at critically enough; recognizing "here's a place where the stated rule was broken, and here's the trail of someone noticing and fixing it" is itself a useful reading skill.

## 5.3 — Tags and notes

**`class-tags.php`** stores a small set of free-text tags per person as a JSON array in user metadata. **`class-notes.php`** stores one freeform admin note per person, also in user metadata. Both are intentionally simple — worth noting as a contrast to the more elaborate systems elsewhere in this tour, since not every feature needs a dedicated database table or complex logic.

---

# Module 6 — bh-registry: an anonymous link directory

## 6.1 — The core concept

This plugin is deliberately unlike every other one in this tour: **there are no user accounts involved at all.** It's a directory of artists' public links (their own website, RSS feed, ActivityPub profile — a decentralized-social-web identity), submitted and verified without anyone needing to log in or create an account on this site. **Open `bh-registry/includes/class-activator.php`, around lines 75–109**, to see the two real database tables this is built on (`bhr_artists`, `bhr_links`) — notice this plugin uses plain custom tables rather than a WordPress Custom Post Type, unlike every content type you've seen so far. The reason (worth reading the surrounding comment for) is that this data is fundamentally relational — searching by protocol type, joining an artist to their links, filtering to verified-only — in a way that's more natural to model as real database tables than as WordPress posts.

## 6.2 — BHR_Links: a small, deliberately minimal data-access class

**Open `bh-registry/includes/class-links.php`.** `table()` and `find($link_id)` are short, simple methods. Read the doc-comment above the class — it explains this was extracted specifically to eliminate three nearly-identical "fetch one link by its ID" database queries that used to be scattered across several different files. Notice it's deliberately NOT a full data-access framework (an "ORM," if you ever hear that term) — just enough shared code to remove real duplication, without building more machinery than the plugin actually needs. This is a useful contrast to Module 1's bigger shared services: not every reusable piece of code needs to be a whole subsystem.

## 6.3 — Verification: proving you own a link without an account

**Open `bh-registry/includes/class-verification.php`.** Three independent check types, each answering "is this really yours?" a different way:
- `check_domain_ownership()` (~lines 114–143) — asks the artist to upload a specific text file to their own website at a known address, then checks it's actually there.
- `check_open_feed()` (~lines 151–175) — confirms a submitted RSS feed is real and has actual audio content (not just a URL that returns nothing useful).
- `check_activitypub_actor()` (~lines 186–216) — confirms a submitted ActivityPub profile URL returns a real, spec-shaped profile document.

All three make outbound web requests to someone else's server, which can fail for reasons that have nothing to do with whether the artist is legitimate (their server could be slow, down, or misconfigured) — this session added logging to all three specifically so a failed verification shows WHY it failed (a timeout? a wrong response? actually invalid?) instead of just "not verified" with no trail to investigate.

---

# Module 7 — bh-monetization-woo: payments, tiers, and the wallet

This is the plugin every payment-related feature elsewhere in the ecosystem depends on through `class_exists()` checks (you saw one in Module 1.2, from bh-courses' gate). It's built on top of WooCommerce (a separate, very large e-commerce plugin this ecosystem doesn't try to replace) rather than handling payments itself.

## 7.1 — Tiers

**Open `bh-monetization-woo/includes/class-tiers.php`.** A supporter tier is its own Custom Post Type (`bhm_tier`), with metadata for price, an optional annual price, a benefits list, and — importantly — a synced WooCommerce product ID. `save()` calls `BHM_Products::sync_tier_wc_product()`, which is the actual bridge: whenever a tier is created or edited here, a matching real product gets created or updated in WooCommerce automatically, so the two systems never drift out of sync with each other.

## 7.2 — The wallet, and the same "atomic write" idea from Module 4

**Open `bh-monetization-woo/includes/class-wallet.php`.** `debit()` (~lines 40–82) and the private `apply_delta()` (~lines 94–127) use the exact same principle you saw in bh-contest's vote-limit logic (Section 4.1): a single atomic database statement instead of "check the balance, then separately write the new balance" — read the doc-comment (~lines 34–39) for the plugin's own explanation of why a check-then-write would be a race condition (two near-simultaneous spends could both pass the balance check before either write lands, driving the balance negative). This is a genuinely useful pattern to recognize: any time money, votes, or anything else with a hard limit is involved, look for this "the check and the write are the same statement" shape — it's how this codebase consistently avoids that entire class of bug.

This session added error logging to both methods (~lines 60–64, 77–79, 108–111, 123–125) — a real gap that existed before: a failed wallet write used to fail completely silently, meaning a customer's balance and the wallet's own transaction history could quietly disagree with each other with nothing telling anyone it had happened.

## 7.3 — The gate: deciding who can see what

**Open `bh-monetization-woo/includes/class-gate.php`.** `user_has_tier_access()` (~lines 58–96) and `user_has_benefit()` (~lines 116–146) are the two methods every other plugin's own gating code calls (recall bh-courses' `class-gate.php` from Module 1.2 — it calls straight into this). They check a real entitlements table for an active subscription or a one-time purchase, and fall through to a filter (`bhm_extra_entitlement_check`) so a future integration can add its own access rule without editing this file.

## 7.4 — Fraud detection, extracted into its own file

**Open `bh-monetization-woo/includes/class-fraud.php`.** `track_refund_pattern()` (~lines 35–72) flags an account with three or more refunds in a 30-day window, and separately looks for the same payment device being used across suspiciously many different accounts. This class used to be part of the much larger `class-products.php` file — it was pulled out into its own file in an earlier code-quality pass specifically because "handling refunds" and "detecting refund abuse" are different jobs that happened to be tangled together, and separating them made both easier to read and change independently. Worth remembering as an example of what a good "split this file up" refactor actually looks like, since Module 5.2 showed you what happens when a similar kind of tangling ISN'T caught.

## 7.5 — The BH_Commerce migration, made concrete

Back in Module 1.8, you read `BH_Commerce`'s interface idea in the abstract. Here's where it's actually used.

**Open `bh-monetization-woo/includes/class-products.php` and find `on_order_completed()`** (~lines 414–479), specifically around line 424:
```php
$order = class_exists('BH_Commerce') ? BH_Commerce::get_order($order_id) : self::legacy_get_order_array($order_id);
```
Read the comment just above it — it explicitly notes this method "no longer touches a WC_Order object directly." Before this migration, this code would have called WooCommerce's own `wc_get_order()` function straight from here; now it asks `BH_Commerce` for the order instead, and `BH_Commerce` is the only place that still knows WooCommerce exists. The old direct call is kept only as a fallback inside `legacy_get_order_array()`, for the edge case where `BH_Commerce` somehow isn't available. This is Module 1.8's abstract idea, now something you can point to and say "that's the actual migration, right there" — the clearest single before/after moment in the whole codebase for understanding what "build an interface, then migrate onto it" really means in practice.

---

# Where to go from here

You've now read, with real depth: the entire shared foundation (bootstrap, peer-optionality, shared services, the debugging tools, the block-authoring system, the commerce interface, the design system) and all seven plugins — courses, streaming (including its real-time-ish sync and recommendation engine), contest voting (including its race-condition protections), a CRM with an honestly-documented past mistake, an account-free verified link directory, and the payments/wallet system tying several other plugins together.

From here, the fastest way to keep learning is need-driven, not systematic: next time you want to understand or change something specific, use what you now know to navigate directly — find the plugin, find the file whose name matches what you're curious about, find its `init()`, and read outward from there.

Two documents worth reading once you're comfortable navigating on your own:
- **`QA-REPORT.md`** and **`QA-REPORT-code-quality.md`** (plugins root) — written as "here's what's rough about this codebase and why," a genuinely good way to deepen your understanding of a system you can already read.
- **`ROADMAP-platform-evolution.md`** and the other `ROADMAP-*.md` files — show you where this whole ecosystem is deliberately heading, and which parts (like `BH_Commerce`) were built as interfaces specifically to make that future easier.
