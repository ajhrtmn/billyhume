# Own Ur Shit ecosystem — code quality / readability QA pass

Session date: 2026-07-08. Scope: all seven plugins (`own-ur-shit` core + `bh-contest`, `bh-courses`,
`bh-crm`, `bh-monetization-woo`, `bh-registry`, `bh-streaming`). This is a **code-quality/DRY/SOLID/
readability/documentation** review, distinct from the prior `QA-REPORT.md` (bug fixes and live-environment
constraints) — that file was read first, in full, so nothing below re-flags an item already fixed or
already logged there as deliberately deferred (e.g. the `class_exists('WooCommerce')` repetition,
`number_format` cents-formatting duplication, and the debug-tools seed/reset triad are already named in
that report's Section 4 "deliberately not touched" list; they're only mentioned again here where new
evidence — a line count — adds something that prior pass didn't have).

**Method.** `Glob`/`find` for file counts and line counts per plugin first, to gauge where to spend time
(the largest files: `bh-contest/class-admin.php` at 1145 lines, `own-ur-shit/class-debug-log.php` at 702,
`bh-monetization-woo/class-products.php` at 673). Sampled docblocks across old and new, core and satellite
files to confirm the established convention (dense "why not what," explaining the failure mode a piece of
code prevents, cross-referencing sibling classes by name) — confirmed consistent in `class-portal.php`,
`class-people.php` (bh-crm), `class-wallet.php`, `class-jam.php`; this is the baseline the findings below
are judged against, not a generic standard. Grepped aggressively for `flush_rewrite_rules`,
`check_ajax_referer`, `current_user_can`, `wp_nonce_field`, `add_meta_box`, and raw `$wpdb->prefix . '...'`
table-name construction across all seven plugin directories before reading any file in full. Read complete
functions (not just grep hits) for every finding cited below with a line range.

---

## Cross-plugin duplication

**1. The rewrite-rule "self-heal" pattern exists in exactly two places, confirmed no third copy.**
`own-ur-shit/includes/class-portal.php` (`BHI_Portal::add_rewrite()`, lines 157–194, plus
`rewrite_rule_persisted()` at 209–213, `not_recently_attempted()` at 228–240, `force_flush_and_verify()`
at 249–271) and `bh-monetization-woo/includes/class-storefront.php` (`BHM_Storefront::add_rewrite()`,
lines 106–121, `rewrite_rule_persisted()` 124–129, `not_recently_attempted()` 131–140,
`force_flush_and_verify()` 145–156) implement the identical algorithm: a versioned rewrite rule, a
direct-to-`$wpdb` (cache-bypassing) persistence check, a throttled retry via a raw options-table
timestamp, a forced flush with explicit `wp_cache_delete()`/`wp_cache_flush()` calls, and a throttled
info/warning log trace on pass/fail. The Storefront copy's own comments (lines 82–92, 94–104) explicitly
say it was "upgraded to BHI_Portal's self-verifying shape" — i.e. it's a deliberate manual port, not
independent development. Searched for `not_recently_attempted`/`VERIFY_THROTTLE` across all seven plugins
— **no third copy exists**; `bh-registry`, `bh-contest`, `bh-streaming`, `bh-courses` all call plain
`flush_rewrite_rules()` once on activation with no self-heal logic at all. Given this is the second
hand-sync of the same ~90-line algorithm (with the code's own comments admitting the sync happened),
this is the strongest DRY candidate in the codebase — a `BHY_RewriteHealer` (or similar) helper in
`own-ur-shit` core, parameterized by rule pattern, option-name prefix, and log-context label, would let
both current call sites and any future one (e.g. `bh-registry`'s activator, which currently has no
self-heal at all) get this for free instead of a third manual port.

**2. `bh-crm` reaches directly into `own-ur-shit` core's `bhi_profiles` table, and does so twice, byte-
identically.** `bh-crm/includes/class-people.php:61` and `bh-crm/includes/class-export.php:11` both run
the exact same raw SQL:
```
SELECT user_id FROM {$wpdb->prefix}bhi_profiles WHERE real_name != '' OR discord_name != '' OR twitch_name != '' OR youtube_name != ''
```
`own-ur-shit/includes/class-profiles.php`'s `BHI_Profiles` class (lines 24–187) is the documented owner of
that table (`get()`, `get_by_slug()`, `save()`, `badges_for()`, etc.) but has no "who has a filled-in
profile" query, so `bh-crm` bypassed it and wrote raw SQL against a table it doesn't own — the exact
"reaching into another plugin's internals instead of through a documented interface" pattern the brief
asked about. This is doubly a problem: it's DRY-broken (duplicated verbatim) and SOLID-broken (encapsulation
violation on a core data table two satellite files depend on the exact column list of). Contrast with
`class-people.php`'s own docblock (lines 4–8), which explicitly claims "This plugin never queries
bh-contest's or bh-streaming's own tables directly; it only knows about two filters" — true for those two
plugins, **not true for its relationship with core's own `bhi_profiles` table**, making that docblock's
claim slightly overstated for the one dependency that actually matters most (core).

**3. `check_ajax_referer`/nonce boilerplate is NOT a significant duplication problem** — worth recording
so it isn't mis-flagged later. Only 7 call sites total (`own-ur-shit/class-two-factor.php` x3,
`class-notifications.php` x2, `bh-courses/class-progress.php` x2), all short and distinct in what they
guard. Most of the ecosystem's HTTP surface goes through the WP REST API's own `permission_callback`
mechanism (see `bh-contest/class-api.php:8-10`'s `$pub`/`$auth`/`$admin` capability-shortcut pattern,
reused consistently within that file) rather than hand-rolled AJAX handlers, so there's little "AJAX nonce
boilerplate" to extract in the first place.

**4. Admin metabox rendering: no cross-plugin duplication found, but a real single-file readability
problem** — see bh-contest section below.

**5. Deferred items from the prior `QA-REPORT.md` re-confirmed still present, now with counts**: 21
occurrences of `number_format($x / 100, 2)`-style cents formatting in `bh-monetization-woo` alone, and 18
`class_exists('WooCommerce')` guards scattered across 7 of its 16 files (`class-frontend.php` x4,
`class-products.php` x4, `class-tiers.php` x3, `class-debug.php` x3, `class-storefront.php` x2,
`class-admin.php` x1, `class-downloads.php` x1). Both already correctly identified and deliberately
deferred in the prior report; not re-litigating, just confirming the counts for anyone deciding whether
they've grown enough to prioritize.

---

## own-ur-shit (core)

- **DRY / doc quality are strong** — `class-portal.php`'s docblock (lines 4–32) is a good example of the
  established convention: explains why the shell exists, why it's filter-based, and points at the exact
  sibling patterns (`ous_registered_plugins`, `ous_debug_tools`) it's modeled on.
- **`class-debug-log.php:479` `render_debug_section()` is ~135 lines** doing table rendering, filter-UI
  rendering, and pagination in one function. Not urgent (it's a single admin screen, read top-to-bottom,
  not called elsewhere) but a natural three-way split (`render_filters()`, `render_table()`,
  `render_pagination()`) would make it easier for a non-professional reader to find "the part that draws
  the table" without scanning the whole thing.
- **`class-dashboard.php`** is thin exactly as its own docblock (lines 4–10) promises — "doesn't contain
  business logic of its own, just the HTTP-facing plumbing" — and the code matches that claim. No stale
  docs found here.
- **`class-ui.php` and `class-style.php`** have the lowest comment-density numbers in the plugin (~13%),
  but on inspection this is CSS-string-building code (`admin_page_css()`, `design_system_css()`) — low
  comment density is appropriate there, not a documentation gap. Flagging so it isn't mistakenly "fixed"
  by someone skimming a density metric rather than the actual content.

## bh-contest

- **`class-admin.php:691` `add_meta_boxes()` is ~370 lines** (691–1062) registering 6 metaboxes, most as
  large inline closures that do capability checks, form-field rendering, and inline JS all at once (e.g.
  the `bh_approval` closure starting at line 692 and the `bh_contest_settings` closure at 708 each run
  well past 100 lines before the next `add_meta_box()` call). This is the single largest "could be split"
  readability finding in the whole codebase. Splitting each closure into a named `private static function
  render_X_metabox($post)` (mirroring how `bh-courses/class-admin.php` and `bh-streaming/class-admin.php`
  already do it with real method references instead of closures) would make each piece independently
  readable and is a pure extraction — no behavior change.
- **`class-admin.php:458` `render_results()` is ~180 lines** (458–639) mixing contest/category selection
  logic, live-poll JS setup, and results-table HTML generation. Same kind of split opportunity
  (`resolve_selected_category()`, `render_results_table()`) as above, lower priority since it's less
  extreme.
- **`class-api.php`'s own docblock convention (lines 12–17) is a genuine highlight**, not a finding —
  worth citing as the model other files should match: it explains *why* the sanitize-callback is wrapped
  in a closure (`sanitize_title()`'s arity clashing with the REST framework's 3-arg convention) rather than
  just stating what the code does.

## bh-courses

- **`class-render.php:367` `render_lesson_steps()` (~82 lines) and `class-render.php:451`
  `render_step()` (~84 lines)** are both borderline-over-80-line functions that each handle multiple step
  types (`text`, `image`, `quiz`, etc.) via if/elseif chains inline. Not severe, but a step-type-to-renderer
  map (`['text' => 'render_text_step', 'quiz' => 'render_quiz_step', ...]`) would flatten the branching and
  make adding a new step type in the future a one-line registration instead of another `elseif`.
- **`class-content-bridge.php`** is well-documented (32% comment density) and its docblock's claims about
  "one writer per field" were checked against `class-admin.php`'s lesson-steps metabox and `class-steps.php`
  — consistent, no staleness found.
- **No god-class problem here** — `class-progress.php`, `class-gate.php`, `class-steps.php` are each scoped
  to one responsibility, unlike `bh-monetization-woo/class-products.php` below.

## bh-crm

- **See cross-plugin finding #2 above** (`class-people.php:61`, `class-export.php:11` — direct raw-SQL
  read of core's `bhi_profiles` table, duplicated verbatim in two files). This is the plugin's one
  significant finding; everything else here (`class-notes.php`, `class-tags.php`) is small, correctly thin,
  and appropriately commented for its size — not padding the report with findings this plugin doesn't have.

## bh-monetization-woo

- **`class-products.php` is the clearest god-class in the ecosystem.** It's a single ~673-line, 24-method
  class doing five distinct jobs: WooCommerce product sync (`sync_tier_wc_product` 83–170,
  `sync_object_purchase_product` 171–208), admin-UI rendering (`render_object_ui` 240–273), streaming-gate
  access checks (`track_access_allowed` 304, `track_play_allowed` 325–369, `check_play_velocity` 370–390),
  order/subscription billing (`on_order_completed` 414–484, `on_order_reversed` 505–572,
  `on_subscription_active`/`on_subscription_ended` 573–602), and entitlement granting
  (`grant_entitlement` 603–end). The prior `QA-REPORT.md` already extracted fraud detection into
  `class-fraud.php` (confirmed present and correctly wired — `BHM_Fraud::fingerprint_for()` at line 89 is
  now private and encapsulated as that report describes; no stale-doc issue there). The remaining four
  responsibility groups are real candidates for a further split — `class-products-sync.php` (WC product
  sync + admin UI) vs. `class-billing.php` (order/subscription handling + entitlement granting) is a
  plausible seam, since gating (`track_play_allowed`) is the one piece genuinely coupled to both
  (`is_non_catalog_track()` at line 236 is shared by both the UI-render and the gate-check paths).
- **`class-wallet.php` is a strong documentation example** — every non-obvious decision (why credit/debit
  aren't a read-then-write, why wallet is a separate table from `bhm_entitlements`, why ledger-insert
  failure is logged at `'error'` but insufficient-balance is `'info'`) is explained inline. Cited here as
  the baseline other files in this plugin should be measured against — `class-products.php`'s god-class
  problem is a structural issue, not a documentation one; its individual methods are commented about as
  well as `class-wallet.php`'s.

## bh-registry

- **`class-links.php` data-access class (added in the prior QA pass) is correctly used** —
  confirmed its docblock's claim (line 8, "reached directly into `$wpdb->prefix . 'bhr_links'`") accurately
  describes the *prior* state, and the class itself (`table()`, `find()`) is real and not a stale
  aspirational comment. However, five other files (`class-admin.php`, `class-activator.php`,
  `class-debug.php`, `class-verification.php`, `class-api.php`) still hand-build
  `$wpdb->prefix . 'bhr_artists'`/`'bhr_links'` table names independently at 12 call sites — matching
  exactly what the prior report said it deliberately left alone ("filtered lists, bulk deletes... left
  where they are"). Not a new finding, just confirming that decision's scope is still accurate.
- **No self-heal pattern for its own rewrite rule** (`class-activator.php:25` is a plain one-shot
  `flush_rewrite_rules()` on activation) — if the `BHY_RewriteHealer` extraction in finding #1 above ever
  happens, this plugin's registry-submission route would be a natural first adopter, since it currently has
  none of the self-heal robustness Portal/Storefront do.

## bh-streaming

- **`class-jam.php`'s docblock (lines 4–26) is a strong example of "why not what"** — explains the
  polling-vs-WebSocket tradeoff by naming the actual hosting constraint (shared hosting rarely allows a
  long-lived socket server), and explains the `'host'` vs `'vote_skip'` control-model choice by naming the
  product it's differentiating from (Spotify Jam). No staleness found — checked `SKIP_VOTE_RATIO`'s
  documented "ceil(participant_count * ratio), min 1" behavior against the actual vote-tally code and it
  matches.
- **`class-player.php` and `class-style-surface.php`'s low comment-density numbers (4.6%, 7.4%) are not a
  real finding** — both files are almost entirely HTML template strings and CSS/color maps, where dense
  prose comments would be noise, not signal. Flagging this explicitly so it isn't "fixed" by someone
  chasing a density metric without reading the content, the same caveat as for `own-ur-shit/class-ui.php`
  above.

---

## Recommended sequencing

### SAFE-TO-FIX-DIRECTLY (pure extraction/renaming, no behavior change, low risk)

- Add a `who_has_profile_data()` (or similar) method to `own-ur-shit/includes/class-profiles.php`'s
  `BHI_Profiles` class, and point `bh-crm/includes/class-people.php:61` and
  `bh-crm/includes/class-export.php:11` at it instead of their identical raw SQL. Purely mechanical — same
  query, same return shape, just moved behind the interface that already owns the table.
- Split `bh-contest/includes/class-admin.php:691`'s six inline metabox closures (691–1062) into named
  `private static function render_X_metabox($post)` methods, matching the pattern
  `bh-courses/includes/class-admin.php` and `bh-streaming/includes/class-admin.php` already use. Each
  closure body moves verbatim; only the registration call sites change from inline closures to method
  references.
- Split `bh-contest/includes/class-admin.php:458` `render_results()` (458–639) into a
  `resolve_selected_category()` helper and a `render_results_table($rows, $cats)` helper — the latter
  overlaps meaningfully with the existing `results_rows_html()` at line 645, so this is closer to
  consolidation than a fresh split.
- Split `own-ur-shit/includes/class-debug-log.php:479` `render_debug_section()` into
  `render_filters()`/`render_table()`/`render_pagination()`. Same caveat: verify each extracted piece
  still receives the exact variables it used inline before, since none of this was executed against a live
  install.

### NEEDS-A-DESIGN-PASS (touches shared data models, cross-plugin interfaces, or requires an architectural
decision)

- **The rewrite-rule self-heal duplication (cross-plugin finding #1).** Extracting a shared
  `BHY_RewriteHealer` helper into `own-ur-shit` core is the right direction, but it's a real design
  decision, not a mechanical move: it changes a currently-static-method, hardcoded-constant pattern
  (`BHI_Portal::REWRITE_VERSION`, `BHM_Storefront::REWRITE_VERSION`) into a parameterized/reusable one,
  which means deciding the helper's calling convention (constructor args? a config array? a trait?) in a
  way that doesn't regress either existing caller's behavior — and neither caller's behavior has been
  exercised against a real WordPress install in any session so far (per the prior `QA-REPORT.md`'s Section
  5). Do this only with a live environment to verify both Portal's `/account/` and Storefront's collection
  routes still resolve correctly after the extraction.
- **Splitting `bh-monetization-woo/includes/class-products.php`.** The four remaining responsibility
  groups (product sync, admin UI, gating, billing/entitlements) are genuinely coupled through
  `is_non_catalog_track()` (line 236) and `WC()`/order-object access patterns threaded through several
  methods — a blind split risks breaking a call site that assumes all of this lives on one class. This is
  exactly the kind of "money-handling path with no live environment to test against" the prior report
  flagged as its highest-risk deferred category; a split here should happen alongside (not before) getting
  a real WooCommerce checkout flow to test against.
- **Whether `bh-registry` should adopt the rewrite self-heal pattern at all.** Its registry-submission route
  is lower-traffic and lower-consequence than Portal's account shell or Storefront's checkout-adjacent
  collection pages if a rewrite rule silently fails to persist — this is a product decision (is the extra
  complexity worth it for this route) as much as a technical one, not a pure refactor.
