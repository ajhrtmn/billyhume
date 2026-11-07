# Codebase Walkthrough — a complete guided curriculum

You built this project's features by directing the work, not by writing the code. This doc is the next step: being able to open any file in the ecosystem, read it, and understand what it does and why — well enough to change something yourself.

**Module 0 and Module 1 (the shared foundations) are required reading, in order** — nearly everything in Modules 2 through 7 leans on patterns explained there. After Module 1, the plugin modules can be read in any order; each stands on its own.

Pointers name a class and method ("find `BHC_Progress::step_status()`") rather than a line number, because line numbers drift. Open the file and search for the method name.

Before you start: read `VISION.md` at the plugins root (five minutes) for the pitch of what this ecosystem is and why it's built as separate plugins instead of one giant one.

---

# Module 0 — WordPress concepts you'll need everywhere

You don't need to become a WordPress developer. You need five ideas, explained once, so the rest of this tour doesn't stop to re-explain them.

**A "plugin" is just a folder of PHP files that WordPress loads.** This tour covers the core (`the-self-hosted-self`) and six peer plugins (`bh-contest`, `bh-courses`, `bh-crm`, `bh-monetization-woo`, `bh-registry`, `bh-streaming`). Newer peers such as `bh-video`, `bh-live` and `bh-tickets` follow the same shapes. Each plugin is one folder with one main file at its root (e.g. `bh-courses/bh-courses.php`) that WordPress reads first. That main file's job is almost always the same: load a bunch of other files, then tell WordPress "when X happens, run this code."

**"Hooks" are how WordPress lets plugins run code at the right moment, without editing WordPress itself.** Two flavors:

- **Actions** — "when X happens, DO this." Written as `add_action('some_event_name', 'some_function_to_call')`.
- **Filters** — "when X is about to happen, let me CHANGE the data first." Written as `add_filter('some_data_name', 'a_function_that_returns_a_modified_version')`. The function receives a value, can modify it, and must hand it back.

**`$wpdb` is how PHP code talks to the database.** WordPress stores everything — posts, users, settings, and every custom table this ecosystem added (wallets, quiz progress, contest votes) — in MySQL. You'll see `$wpdb->get_results(...)` (read rows), `$wpdb->insert(...)` (write a row), and `$wpdb->prepare(...)` (safely build a query with user-supplied values, so someone typing SQL into a form can't manipulate your database — SQL-injection protection) throughout.

**`class_exists('SomeClassName')` is how one plugin checks "is that OTHER plugin even active?"** This is the most important pattern in the ecosystem, because the plugins are designed to work independently — `bh-courses` works fine even if `bh-monetization-woo` (payments) isn't installed. Every time one plugin wants a feature from another, it first checks `class_exists(...)` on the other plugin's class, and if that's false, it skips the feature gracefully instead of crashing.

**A Custom Post Type (CPT) is how WordPress represents "a kind of thing" beyond blog posts.** Plugins can register their own kinds — a course, a track, a contest submission — and they all get the same machinery for free (an edit screen, a URL, custom fields called "post meta"). Whenever you see `register_post_type('some_name', [...])`, read it as "this plugin is teaching WordPress about a new kind of content."

**If you're coming from .NET, four quick mental anchors:**

- **Hooks (actions/filters)** are roughly C# events/delegates, or an ASP.NET middleware pipeline. Filters are the closer analogy to middleware, since they take a value, transform it, and pass it on.
- **`$wpdb`** is raw-SQL-first, like Dapper — not an ORM like Entity Framework.
- **`class_exists()` guards** play the role of optional DI — "is this type even loaded? If not, degrade gracefully" instead of a hard dependency that would throw.
- **WP-Cron** is a lightweight cousin of Hangfire or Quartz — "run this on a schedule," but driven by site traffic, not a real background service.

Five ideas plus four anchors. Everything else you'll learn by seeing it in real code.

---

# Module 1 — the-self-hosted-self: the shared foundation

`the-self-hosted-self` is the plugin every other plugin depends on. It provides shared logins, a shared design system, and reusable services (notifications, background jobs, error logging, etc.) that any other plugin can opt into with one line of code. Once you understand these patterns, every other plugin becomes fast to read, because they're all built the same way.

## 1.1 — The bootstrap: how one plugin turns on

**Open:** `the-self-hosted-self/the-self-hosted-self.php`

**Find the `foreach` loop near the top:**
```php
foreach (['tables', 'pages', 'registry', ...long list of names...] as $f) {
    require_once OUS_PATH . "includes/class-$f.php";
}
```
This loads every file in the list. Each name maps to a file in `includes/` — `debug-log` means `class-debug-log.php`, and so on. Nothing DOES anything yet; this just makes the code available.

**Below it is a long block of `add_action('init', [...])` calls.** Each says "when WordPress fires its `init` event (very early, on every page load), call this class's `init()` method." For example, `add_action('init', ['OUS_DebugLog', 'init']);` means "on every page load, call `OUS_DebugLog::init()`."

**Open `the-self-hosted-self/includes/class-debug-log.php` and find its `init()` method.** It's just MORE `add_action`/`add_filter` calls — the class registering its OWN hooks. This two-layer pattern — "the bootstrap turns on every class's `init()`, and each `init()` registers the hooks it needs" — is used identically by every plugin. Once you've seen it here, you've seen it everywhere.

## 1.2 — The peer-optionality pattern (the architecture's core idea)

**Open `bh-courses/bh-courses.php` and find the `plugins_loaded` callback that checks `BHCORE_LOADED`:**
```php
add_action('plugins_loaded', function () {
    if (!defined('BHCORE_LOADED')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>BH Courses</strong> requires <strong>The Self-Hosted Self</strong> plugin...</p></div>';
        });
        return;
    }
    // ... the rest of the plugin only runs if we got here
```
This is `bh-courses` checking "is the core plugin active?" If not, it shows an admin warning and stops. This is a **hard requirement** — bh-courses cannot work without the core.

**Now open `bh-courses/includes/class-gate.php` and find `user_can_access_course()`:**
```php
public static function user_can_access_course(int $user_id, int $course_id): bool {
    if (!class_exists('BHM_Gate')) return true; // bh-monetization-woo not active
    ...
```
This is a **soft, optional** dependency. `BHM_Gate` belongs to `bh-monetization-woo`. If that plugin isn't installed, this function says "everyone can access everything" — courses work fine with zero payment features. Install `bh-monetization-woo` later and the same function starts checking purchases and tiers, with no code changes anywhere.

This is THE defining idea of the architecture. Every plugin is useful alone, and gains behavior automatically when a sibling is also active — never by editing existing code, only because that code already has a `class_exists()` check waiting.

## 1.3 — Two shapes a shared service takes

**Shape A — pure functions, no hooks.** **Open `the-self-hosted-self/includes/class-reliable-store.php` in full** (it's about 60 lines). `OUS_ReliableStore` has four plain functions: `set()`, `get()`, `delete()`, `increment()`. The comment at the top explains why it exists: WordPress's normal temporary storage (transients) can silently lose writes when a persistent object cache is unreliable, so this class reads and writes the options table directly for values that must not be lost. Then open `the-self-hosted-self/includes/class-auth.php` and search for `OUS_ReliableStore` — it backs the login lockouts and registration rate limits, where a counter that silently fails to persist would let an attacker keep guessing.

**Shape B — hook-driven.** **Open `the-self-hosted-self/includes/class-jobs.php`.** This is a "do this later, not right now" queue. The comment at the top shows the two-line pattern any plugin uses: `OUS_Jobs::register()` a handler, then `OUS_Jobs::enqueue()` work for it. Its `init()` hooks onto WP-Cron ("run this on a schedule"). `enqueue()` either hands the job to Action Scheduler (a well-known job library, installable on demand from Debug Tools) or, without it, writes a row to the ecosystem's own jobs table; `run_due_jobs()` (fired by the cron hook every minute) picks up pending rows and runs them, retrying failures up to `MAX_ATTEMPTS`. Notice the pattern from the project's standing rules: a self-hosted built-in default, with a third-party library layered on top as an enhancement, behind the same public API.

Nearly every other shared service in `the-self-hosted-self/includes/` is one of these two shapes.

## 1.4 — Notifications: a real consumer of the "zero registration" idea

**Open `the-self-hosted-self/includes/class-notifications.php`.** The comment at the top shows that any plugin can fire a notification with one call — `OUS_Notifications::notify($user_id, $type, $title, $body, $url, $source, $email)` — with no setup elsewhere. It writes to a database table so the notification always appears in-app, and optionally queues an email through `OUS_Jobs` from Section 1.3 (so `notify()` never waits on a mail server). Three delivery surfaces read from that same table: an admin-bar bell (`admin_bar()`), a `[bh_notifications]` shortcode (`register_shortcode()` / `render_shortcode()`), and a panel in the account portal (`register_portal_panel()`, see Section 1.6).

## 1.5 — Debug Tools: the page built for you

You've already used this page. Now read the code behind it. Every section on it is contributed through the `ous_debug_tools` filter — including the page you're reading this walkthrough on.

**Console & Logs** (`the-self-hosted-self/includes/class-debug-log.php`) — any plugin calls `OUS_DebugLog::log($level, $message, $context, $source)` to record "something happened," almost always "something went wrong that a visitor wouldn't otherwise see." Search the plugin tree for `OUS_DebugLog::log(` to see dozens of real examples. This is the most useful file to understand day-to-day, because it's how you'll diagnose a bug report.

**Test Runner** (`the-self-hosted-self/includes/class-test-runner.php`) — any plugin registers a "suite" of checks via `add_filter('bhcore_test_suites', ...)`. "Run all tests" runs every registered suite live, on your actual site. `bh-courses/includes/class-test-suite.php` is a good one to skim — mostly plain lines like `OUS_TestRunner::assert_same(100, $r['score'], 'All correct scores 100')`.

**API Docs** (`the-self-hosted-self/includes/class-api-docs.php`) — reads WordPress's live list of registered API routes (`rest_get_server()->get_routes()`) and turns them into documentation (`generate_spec()`). Nobody hand-maintains it; it's generated FROM the code, filtered to this ecosystem's own routes (`is_relevant()` checks the `RELEVANT_PREFIXES` constant: `ous/`, `bhi/`, `bh`).

## 1.6 — Roles, auth, 2FA, and the account portal

**OUS_Roles** (`the-self-hosted-self/includes/class-roles.php`) — defines a small set of granular *capabilities* (not new roles) in the `DEFAULT_CAPS` constant. `bhcore_manage_students` is one example, used by bh-courses' student-progress and session screens. Instead of inventing a "Course Manager" role, a site owner grants one specific ability to an existing account.

**BHI_Auth** (`the-self-hosted-self/includes/class-auth.php`) — the `bhi/v1` login/register/logout REST routes behind the account portal's sign-in form. Worth reading for what a real login endpoint has to defend against: `guard_origin()` / `is_same_origin_request()` reject requests that didn't come from this site (cross-site request forgery), `strip_foreign_cors_headers()` stops other origins reading responses, `login()` locks out repeated failures per account and per IP (the `LOGIN_MAX_FAILS*` and `LOCKOUT_SECONDS` constants), and `registration_open()` reads the sign-up on/off switch that `render_settings_card()` puts on the core dashboard. The browser side of that form is `the-self-hosted-self/assets/ts/portal-auth.ts` (compiled to `assets/js/portal-auth.js`).

**BHI_TwoFactor** (`the-self-hosted-self/includes/class-two-factor.php`) — a real TOTP implementation (the standard behind "6-digit code from an authenticator app"). `totp_at()` is the code-generation math — worth a skim to see that 2FA isn't magic, just a documented algorithm (RFC 6238) computing a code from a shared secret and the current time. `verify_code()` checks the submitted code against the current time window and one on either side, to tolerate clock drift. `gate_login()` plugs into WordPress's own `authenticate` filter at priority 30, chosen to run AFTER WordPress's password check, so this code never re-implements password verification.

**BHI_Portal** (`the-self-hosted-self/includes/class-portal.php`) — the branded `/account/` area (as opposed to wp-admin). `add_rewrite()` teaches WordPress that `/account/` and `/account/something/` should be handled here — a "rewrite rule," WordPress's mechanism for mapping a pretty URL to code. Any plugin contributes a panel through the `bhi_portal_panels` filter (read in `get_panels()`) — the same "contribute to a shared list" idea you'll see again in Section 1.7. Rewrite rules on this site have a history of not persisting, so `add_rewrite()` also calls `BHY_RewriteHealer::maybe_heal()` (`the-self-hosted-self/includes/class-rewrite-healer.php`), which checks the rule actually landed and re-flushes on a throttle if it didn't — a good example of code built around a real-world failure rather than the happy path.

## 1.7 — BH_Content / BH_Studio: the block-authoring system

This is the system that lets content (course lessons, and the CRM board's cards) be built from reusable, nested "blocks" (text, image, quiz) instead of one fixed form.

**Open `the-self-hosted-self/includes/class-content.php` and find `register_block_type()`:**
```php
public static function register_block_type(string $type, array $schema, callable $renderer): void {
    self::$types[$type] = ['schema' => $schema, 'renderer' => $renderer];
}
```
This is the whole idea in one function: any plugin can say "here's a new kind of block, here's what data it needs (`$schema`), here's how to turn it into HTML (`$renderer`)." `BH_Content` doesn't know what a "quiz block" is — it just keeps the list other plugins hand it.

**Now open `the-self-hosted-self/includes/class-studio.php` and find `register_content_block_types()`** — the `BH_Content::register_block_type('bh/...')` calls there register the default types (container, heading, text, image, button). `BH_Studio` itself is an authoring canvas built on WordPress's own block-editor packages. For a plugin contributing its own types, open `bh-courses/includes/class-content-bridge.php` and search for `BH_Content::register_block_type('bhc/` — quiz, video, callout, chord-chart and more, all through the same function.

## 1.8 — BH_Commerce: a "swap the backend later" interface

**Open `the-self-hosted-self/includes/class-commerce.php`** — read the comment at the top and the first few methods (`available()`, `has_subscriptions()`, `upsert_product()`).

The big idea: other plugins ask `BH_Commerce` to create a product, load an order, etc., instead of calling WooCommerce directly. Each `BH_Commerce` method forwards to whichever provider is active. The contract a provider must implement is the `BH_CommerceProvider` interface (`the-self-hosted-self/includes/class-commerce-provider.php`); the registry that picks the active one is `BH_CommerceProviders` (`the-self-hosted-self/includes/class-commerce-providers.php`); and the only real implementation today is `BH_WooCommerceProvider` (`the-self-hosted-self/includes/class-commerce-provider-woocommerce.php`). Because the other plugins only talk to `BH_Commerce`, replacing WooCommerce later means writing one new provider class, not rewriting every plugin that sells something. You'll see this used for real in Module 7.

## 1.9 — BHY_UI and the design-token system

Several pieces work together here, each with a distinct job:

**`the-self-hosted-self/includes/class-ui.php`** (`BHY_UI`) is a PHP helper library for consistent admin markup. `shell_open()`/`shell_close()` wrap admin pages in the same chrome; `badge()`, `alert()`, `card()` and `table_wrap()` return small, consistent pieces of HTML in plain PHP; `swatch_field()`/`slider_row()` render reusable settings controls. The CSS itself no longer lives in PHP strings: `design_system_css()` and `admin_page_css()` just read `the-self-hosted-self/assets/css/design-system.css` and `the-self-hosted-self/assets/css/admin-page.css` from disk.

**`the-self-hosted-self/includes/class-style.php`** (`BHY_Style`) owns the *values* — colors, fonts, spacing — as "design tokens." `inline_css()` emits them as CSS custom properties (variables), which is how one color change can affect every plugin's front end at once. `PROPERTY_MAP` and `scoped_inline_style()` define the vocabulary of style properties that `BHY_BlockStyle` (`the-self-hosted-self/includes/class-block-style.php`) exposes as an "Advanced Styles" panel on every native Gutenberg block.

**`the-self-hosted-self/includes/class-style-gallery.php`** (`BHY_Gallery`) is the live Styles page (`add_menu()`), showing every design element in one place so you can see what changing a token would look like. Other plugins register their own preview surfaces via the `bhy_style_surfaces` filter — the contribute-to-a-shared-list pattern one more time.

**Why Module 1 matters before moving on:** you've now seen every repeatable shape this codebase uses — the bootstrap/`init()` two-layer pattern, hard vs. soft dependencies via `class_exists()`, pure-function vs. hook-driven shared services, an interface with swappable providers, and several "any plugin can contribute to a shared list" mechanisms (Debug Tools sections, portal panels, block types, style surfaces). Every module from here on is those same shapes in a different plugin's clothes.

---

# Module 2 — bh-courses: a real feature, end to end

This is likely the feature you understand best from directing its build, which makes it a good place to connect "the feature I asked for" to "the code that implements it."

**Open these files in `bh-courses/includes/`, in order, reading just the top comment of each first:**

1. **`bh-courses/includes/class-post-types.php`** — defines two content types (Module 0's CPT idea in action): `bh_course` and `bh_lesson`, and how a course knows its lessons (an ordered list of lesson IDs in the `_bhc_lesson_order` post meta).
2. **`bh-courses/includes/class-steps.php`** — a lesson isn't one blob of content, it's a sequence of "steps" (text, image, video, quiz), stored as one array in the `_bhc_steps` post meta. This file owns saving/validating that array and scoring quiz attempts. (`class-content-bridge.php` from Section 1.7 keeps that array in sync with the lesson's block content.)
3. **`bh-courses/includes/class-progress.php`** — tracks, per student, which steps are done. Read the top comment: a plain step completes on a click, a quiz step only by passing. It also owns enrollment and course completion.
4. **Rendering** is split across three classes: `bh-courses/includes/class-render-catalog.php` (`BHC_Render_Catalog` — the catalog with search/filter/sort), `bh-courses/includes/class-render-course.php` (`BHC_Render_Course` — the course detail page), and `bh-courses/includes/class-render-lesson.php` (`BHC_Render_Lesson` — the step-by-step lesson player). `bh-courses/includes/class-render.php` (`BHC_Render`) only registers hooks and enqueues assets.

**Then go back to `class-progress.php` and read `step_status()`** — a simple `$wpdb` query: given a user, a lesson, and a step number, what does the database say about it? Module 0's `$wpdb` idea doing real work.

**While you're there:** `mark_step_complete()` takes an `$answers_json` parameter, storing exactly what a student answered on a quiz (not just pass/fail) so a later review can show "here's what you picked vs. the right answer." `stored_answers()` is the read side — it decodes that JSON back into an array and returns `null` cleanly for rows that have none, rather than erroring.

**Why this matters:** you now have a mental map for the biggest single feature in the ecosystem — a few files, each with one clear job. The shape (post types → content/data logic → progress/state tracking → rendering) is worth watching for elsewhere; asking "which file owns WHAT is stored, which owns WHAT COUNTS as done, which owns turning it into HTML" will serve you in any plugin.

---

# Module 3 — bh-streaming: a personal streaming library

This plugin has the most varied feature shapes in the ecosystem — worth reading even if streaming isn't your main interest, because it introduces ideas (near-real-time sync, recommendation, external-service health monitoring) that don't show up elsewhere.

## 3.1 — The content model

**Open `bh-streaming/includes/class-post-types.php`.** Four content types — `bhs_track`, `bhs_release`, `bhs_playlist`, `bhs_feed_source` — plus one taxonomy, `bhs_genre`, attached to tracks. Notice a track's artist and release are stored as post meta (`_bhs_artist`, `_bhs_release_id`) rather than a second taxonomy — the same "just store an ID or a list of IDs as meta" pattern as bh-courses' lesson order.

## 3.2 — Feed aggregation and source health

**Open `bh-streaming/includes/class-feeds.php`.** `sync_all()` pulls tracks in from external feed sources on a schedule — queuing one `OUS_Jobs` job per feed when the job queue is available (`sync_one_job()`), or syncing inline when it isn't (a `class_exists()` fallback in action). `check_external_track_health()` is worth real attention: it does a cheap `wp_remote_head()` request first (asking "are you there?" without downloading the file), and falls back to a tiny ranged download for hosts that mishandle HEAD. It counts consecutive failures per track and only marks a track "down" after `HEALTH_DOWN_AFTER_FAILS` (three) in a row, avoiding false alarms from one blip. It logs only when a track's status *changes*, not on every check — "log the transition, not the check" is a principle worth recognizing elsewhere.

## 3.3 — Jam sessions: the shared-listening feature

**Open `bh-streaming/includes/class-jam.php` and read the comment at the top.** The feature lets several people listen in sync, and the "obvious" way to build that is a WebSocket (a permanently-open connection for instant updates). The comment explains why it deliberately isn't: ordinary shared hosting can't run a socket server. Instead, clients poll roughly every two seconds.

**The mechanism:** `push_host_state()` is how the host's playback position gets written; `get_state()` is how every participant reads it back. The stored state includes both a position AND a server timestamp, so each listener's browser can estimate "it's probably 1.3 seconds further along now" between polls instead of visibly jumping. Two control modes exist: `host` (only the session creator controls playback) and `vote_skip` (see `vote_skip()` — a majority of participants can skip, with the threshold set by `SKIP_VOTE_RATIO`).

## 3.4 — The recommendation engine

**Open `bh-streaming/includes/class-recommendations.php` and read `get_related()`.** The comment states plainly this is content-based, not collaborative filtering ("people who liked this also liked..."), which would need real usage data at scale. The scoring is simple and readable: +3 for the same artist, +4 for the same release, +1 per shared genre — then sort and take the top 10. Results go through `BHS_API::track_payload()`, so a locked track never leaks its audio URL. Worth reading end to end because it shows "recommendation engine" doesn't have to mean anything exotic — a short, explainable scoring function is a legitimate implementation.

## 3.5 — Stats and the artist-facing dashboard

**Open `bh-streaming/includes/class-stats.php`.** `record_play()`/`record_skip()` write to a dedicated daily-stats table (`table()`) — kept separate from posts and post meta because this is high-volume, append-only event data, a different shape from "the current state of a course's progress" in Module 2. `add_admin_page()`/`render()` turn that data into the dashboard an artist sees.

---

# Module 4 — bh-contest: a music contest voting platform

## 4.1 — The voting system and its race-condition protection

**Open `bh-contest/includes/class-api.php` and find `vote()`.** Read it slowly — it's the clearest example in the codebase of a tricky problem: what if the same person clicks "vote" twice in the same fraction of a second (a double-click, or a retried request)? Without protection, both requests could each check "have I hit my limit?", both see "no," and both proceed.

The fix, in the "Toggle on" branch: `START TRANSACTION` begins a database transaction (a group of operations that either all happen or none do), then `SELECT COUNT(*) ... FOR UPDATE` counts existing votes WHILE locking those rows — `FOR UPDATE` is what says "nobody else touches these rows until I'm done," closing the gap the double-click could exploit. If the count is already at the limit, it `ROLLBACK`s (undoes everything); otherwise it inserts the vote and `COMMIT`s. If the insert itself fails, it rolls back and logs the database error. This is the pattern to recognize any time "only one of these should happen": a transaction plus a row lock, not a "check first" that leaves a gap.

## 4.2 — Results and the reveal system

**In the same file, read `category_results()`** — a `GROUP BY`/`COUNT`/`ORDER BY` query turning raw vote rows into a ranked list, with ties handled by `BH_Helpers::competition_ranks()`, so two submissions tied for 2nd both show "2" and the next shows "4," not "3" ("competition ranking," as in sports standings).

**Open `bh-contest/includes/class-reveal.php` and read its top comment.** "Reveal" is a live, on-stream results ceremony — counting up from 3rd to 1st per category, then an overall reveal. There are two separate pieces: an admin controller (Next/Previous) and a public display (the `[bh_results_reveal]` shortcode) meant to be captured by streaming software like OBS. The display polls the server for its own state, so the person clicking "next" and the computer capturing the livestream can be different machines.

## 4.3 — The submission flow

**In `class-api.php`, read `submit()`.** It's a good list of what a real form-submission handler has to check: is the email verified, is the submission window open, does this person already have a draft or a submission, is their profile complete, is a file attached (unless the contest allows adding audio later), is it under the size limit, is it actually an audio file (checked server-side, since a browser's file-picker filter is easy to bypass). Notice the duplicate guard uses `add_option()` rather than only a database check — the `WHY:` comment explains that WordPress option names are unique at the database level, so `add_option()` is atomic and closes the same double-submit race Section 4.1 closed for votes, with a simpler tool.

---

# Module 5 — bh-crm: shared identity, and how plugins feed it

## 5.1 — The People page

**Open `bh-crm/includes/class-people.php` and read the top comment.** It documents two filters other plugins hook to feed the CRM. `active_user_ids()` decides who shows up on the list — anyone with profile data in core's `BHI_Profiles`, plus anyone another plugin reports via `bh_crm_active_user_ids`. A person's detail page gathers identity fields, tags, notes, and — via `bh_crm_activity_summary` — sections other plugins contribute about that person's activity. These are live: search for `bh_crm_active_user_ids` and you'll find `class-crm-integration.php` files in bh-contest, bh-courses and bh-monetization-woo, plus bh-crm's own `bh-crm/includes/class-event-activity.php` turning core's event log into a timeline.

`render()` dispatches the page: the roster, one person's detail view, or a project board (Section 5.3).

## 5.2 — Respecting the boundary

Module 1.2 taught you that plugins talk through `class_exists()` checks and public methods, never by reaching into another plugin's database tables. bh-crm's top comment says so explicitly: it "never queries another plugin's tables."

**Look at `active_user_ids()` in `class-people.php`, and the matching line in `bh-crm/includes/class-export.php`.** Both call `BHI_Profiles::user_ids_with_profile_data()` — a public method on the core class that owns the profiles table — instead of running SQL against that table directly. When you're tempted to "just query their table," this is the shape to reach for instead: ask the owning class for a method.

## 5.3 — Tags, notes, and project boards

**`bh-crm/includes/class-tags.php`** stores free-text tags per person as a JSON array in user meta — intentionally simple. **`bh-crm/includes/class-notes.php`** stores append-only notes with author and timestamp in their own table; a note can carry a reminder, which is an `OUS_Jobs` one-off job that notifies the note's author through `OUS_Notifications` — two Module 1 services composed in a few lines.

**`bh-crm/includes/class-projects.php`** is a kanban project tracker. Read its top comment for the data model: each card is a placement stored by core's `BH_Element` (`the-self-hosted-self/includes/class-element.php`), and a card's nested sub-cards are a `BH_Content` block tree. `BH_Element` is now nothing more than this board's placement storage plus its REST routes (`register_routes()`); the board renders cards in the browser from that REST data.

---

# Module 6 — bh-registry: an anonymous link directory

## 6.1 — The core concept

This plugin is unlike every other one in this tour: **no user accounts are involved.** It's a directory of artists' public links (their own website, RSS feed, ActivityPub profile — a decentralized-social-web identity), submitted and verified without anyone logging in. **Open `bh-registry/includes/class-activator.php` and find `create_or_update_schema()`** to see the tables it's built on: `bhr_artists` and `bhr_links`, plus tables for peers (other bh-registry sites this one crawls, so directories can discover each other) and a dedup ledger for that crawl. This plugin uses plain custom tables rather than Custom Post Types because the data is relational — searching by link type, joining an artist to their links, filtering to verified-only — which is more natural as real tables than as WordPress posts.

## 6.2 — BHR_Links: a deliberately minimal data-access class

**Open `bh-registry/includes/class-links.php`.** Two short methods, `table()` and `find($link_id)`, and a one-line comment: "deliberately not a full repository." It exists to remove repeated "fetch one link by ID" queries, without building more machinery (an ORM) than the plugin needs. A useful contrast to Module 1's bigger services: not every reusable piece of code needs to be a subsystem.

## 6.3 — Verification: proving you own a link without an account

**Open `bh-registry/includes/class-verification.php`.** Three independent checks, each answering "is this really yours?" differently:
- `check_domain_ownership()` — asks the artist to place a specific text file at `/.well-known/bh-registry-verify.txt` on their own site, then checks it's there.
- `check_open_feed()` — confirms a submitted RSS feed is real and has actual audio content.
- `check_activitypub_actor()` — confirms an ActivityPub profile URL returns a real, spec-shaped profile document.

All three fetch from someone else's server, which can fail for reasons unrelated to whether the artist is legitimate (slow, down, misconfigured). Each one logs through `OUS_DebugLog` when a fetch fails, so a failed verification shows WHY (timeout? wrong response? actually invalid?) instead of just "not verified." Notice too the `WHY:` comment about SSRF: because the URL comes from an anonymous submission, every outbound fetch is guarded so it can't be pointed at the server's own internal network.

---

# Module 7 — bh-monetization-woo: payments, tiers, and the wallet

This is the plugin other plugins' payment features check for with `class_exists()` (you saw one in Module 1.2, from bh-courses' gate). It's built on WooCommerce (a separate, very large e-commerce plugin this ecosystem doesn't try to replace) rather than handling payments itself.

## 7.1 — Tiers

**Open `bh-monetization-woo/includes/class-tiers.php`.** A supporter tier is its own Custom Post Type (`bhm_tier`), with meta for price, an optional annual price, a benefits list, and a synced WooCommerce product ID. `save()` calls `BHM_ProductSync::sync_tier_wc_product()` (`bh-monetization-woo/includes/class-product-sync.php`), the bridge: whenever a tier is created or edited, a matching product is created or updated in WooCommerce, so the two never drift apart.

## 7.2 — The wallet, and the "atomic write" idea from Module 4

**Open `bh-monetization-woo/includes/class-wallet.php`.** `debit()` and the private `apply_delta()` solve the same problem as bh-contest's vote limit (Section 4.1) with a different tool: instead of a transaction and lock, the check and the write are ONE statement — `UPDATE ... SET balance_cents = balance_cents - X WHERE ... AND balance_cents >= X` — and success means "exactly one row changed." Read the `WHY:` comment above `debit()`: a separate check-then-write would let two near-simultaneous spends both pass the balance check, driving the balance negative. Any time money, votes, or anything with a hard limit is involved, look for "the check and the write are the same statement" (or a transaction with a lock).

Both methods also log through `OUS_DebugLog` when a write fails — including the specific case where the balance moved but the ledger row didn't insert, which would otherwise leave a customer's balance and history quietly disagreeing.

## 7.3 — The gate: deciding who can see what

**Open `bh-monetization-woo/includes/class-gate.php`.** `user_has_tier_access()` and `user_has_benefit()` (plus `user_owns_object()` for one-time purchases) are what other plugins' gating code calls — recall bh-courses' `user_can_access_course()` from Module 1.2. They check the entitlements table for an active subscription or purchase, and fall through to a filter (`bhm_extra_entitlement_check`) so another integration can add its own access rule without editing this file.

## 7.4 — Fraud detection in its own file

**Open `bh-monetization-woo/includes/class-fraud.php`.** `track_refund_pattern()` flags an account with three or more refunds in a 30-day window (`REFUND_ABUSE_THRESHOLD`, `REFUND_ABUSE_WINDOW`), and separately looks for the same browser fingerprint (a hash of IP plus a first-party cookie; raw IPs are never stored) refunding across multiple accounts. Read the top comment: it only flags for human review, never auto-restricts, because a false positive would lock out a real fan. "Handling refunds" and "detecting refund abuse" are different jobs, so they live in different files — worth remembering as what a good "split this file up" looks like.

## 7.5 — The BH_Commerce interface, made concrete

Back in Module 1.8, you read `BH_Commerce`'s interface idea in the abstract. Here's where it's used.

**Open `bh-monetization-woo/includes/class-entitlements.php` and find `on_order_completed()`:**
```php
// Plain array: id/status/customer_id/total_cents/items[{product_id,quantity}].
$order = BH_Commerce::get_order($order_id);
```
This is the code that runs when someone pays: it turns a completed order into entitlements (tier access, a track purchase, a wallet top-up). Notice it never touches a WooCommerce order object — it asks `BH_Commerce` for a plain array, and only the WooCommerce provider from Section 1.8 knows WooCommerce exists. `BHM_ProductSync` (Section 7.1) creates products the same way, through `BH_Commerce::upsert_product()`. A few spots still call WooCommerce directly (search the plugin for `wc_get_order`), which is honest evidence that a migration onto an interface usually happens one caller at a time.

---

# Where to go from here

You've now read: the shared foundation (bootstrap, peer-optionality, shared services, Debug Tools, auth, the block-authoring system, the commerce interface, the design system) and six peer plugins — courses, streaming (near-real-time sync and recommendations), contest voting (race-condition protection), a CRM fed by other plugins, an account-free verified link directory, and the payments/wallet system tying several others together.

From here, learn by need: next time you want to understand or change something, find the plugin, find the file whose name matches, find its `init()`, and read outward.

Documents worth reading once you're comfortable (all at the plugins root):
- **`VISION.md`** — where the ecosystem is deliberately heading, and why pieces like `BH_Commerce` were built as interfaces.
- **`OPEN.md`** — the backlog and known bugs.
- **`CONVENTIONS.md`** and **`STYLE-SYSTEM.md`** — how code and styles are written here.

Older roadmaps and audits live in `docs/archive/` at the repository root.
