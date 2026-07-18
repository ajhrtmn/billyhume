# ROADMAP: unified site search + in-admin version history

Two independent features scoped together only because they were asked for in the same pass. Distinct from `ROADMAP-discoverability.md` (that doc is about being found by Google/AI answer engines/RSS — this one is about a fan finding things once they're already on the site, and an artist recovering their own past work).

## 1. Unified site-wide search

### Grounded in what's actually there today (confirmed by reading the code, not assumed)

- **No unified search exists.** No `[search]`-style shortcode, no shared search results template, no cross-plugin REST search endpoint anywhere in this codebase. WordPress core's own default `?s=` search is untouched and effectively unused by this ecosystem.
- **Search coverage is fragmented and mostly closed off by design:**
  - `bh_course`/`bh_lesson` — `'public' => true`, already in WP's default search (`bh-courses/includes/class-post-types.php`).
  - `bh_contest`/`bh_submission` — `'public' => false` (`bh-contest/includes/class-post-types.php`), invisible to `?s=`.
  - `bhs_track`/`bhs_release`/`bhs_playlist`/`bhs_feed_source` — all `'public' => false` (`bh-streaming/includes/class-post-types.php`).
  - `bhm_tier` — no explicit `public`/`exclude_from_search` key, defaults closed.
  - **bh-crm and bh-registry aren't post types at all** — both live in dedicated `$wpdb` tables, structurally invisible to WP search regardless of any flag.
- **Three isolated, ad-hoc browse/filter UIs already exist** and are the closest prior art:
  - `bh-courses/includes/class-render-catalog.php` — `[bh_courses]` catalog shortcode, a `bhc_s` GET param feeding `WP_Query`'s `'s'` arg, plus category/topic/sort filters.
  - `bh-monetization-woo/includes/class-storefront.php` — REST route `ous/v1/storefront/products`, live re-query filter UI (category/price/stock).
  - `bh-registry/includes/class-frontend.php` + `class-api.php` — a fan-facing artist-search box hitting a REST `search` param, a `$wpdb->esc_like()` query scoped to active artists.

### Why this can't just be "flip `exclude_from_search` to false"

Contests/tracks/releases/tiers don't have their own canonical single-item URL at all (per `ROADMAP-discoverability.md`'s own finding — they're shortcode-embedded on a regular page, never their own permalink), and CRM/registry aren't posts in the first place. A real unified search has to be a **dispatch layer**, not a WP_Query flag flip: one shared entry point that asks each plugin's own data store for matches and normalizes the results into one shape, the same "one shared service, zero central registration" convention `OUS_Jobs`/`OUS_Notifications` already establish.

### Proposed shape: `OUS_Search` (own-ur-shit core)

- **`apply_filters('ous_search_providers', [])`** — each plugin that wants to be searchable registers a callable: `function($query, $limit)` returning an array of normalized result rows `{type, title, excerpt, url, icon}`. Same zero-central-registration shape as `bhy_style_surfaces`/`bhi_portal_panels`/`ous_debug_tools` — `OUS_Search` never needs to know bh-crm or bh-registry exist.
- **Concrete providers, one per plugin, each a small `LIKE`/`WP_Query` lookup against that plugin's own store:**
  - bh-courses: `WP_Query` with `'s'` (already public, cheapest to wire).
  - bh-contest: a `LIKE` query against `bh_contest`/`bh_submission` titles — this does NOT change their `public`/search-engine-indexing posture, it's an internal-only lookup the front-end search box calls via REST, separate from WP's own `?s=` mechanism entirely.
  - bh-streaming: `LIKE` against track/release/artist name meta.
  - bh-crm: `LIKE` against the CRM people table (name/tags) — respecting whatever visibility a person record already has.
  - bh-registry: reuse the existing artist-search query from `class-api.php` directly as a provider.
- **`OUS_Search::run($query, $limit_per_type = 5)`** — calls every registered provider, merges, returns one flat result set grouped by `type`.
- **REST route** (`ous/v1/search?q=...`) + **a real front-end search UI** (a search box in the site header/portal, live-as-you-type results grouped by type: Courses, Contests, Tracks, People, Merch) — the actual fan-facing payoff.

### Sequencing (smallest real slice first, per this ecosystem's own "one real example, not every consumer at once" convention)

1. `OUS_Search` shell + `ous_search_providers` filter + REST route, with exactly ONE real provider (bh-courses, since it's already public/cheapest) — proves the mechanism end-to-end.
2. Front-end search UI (the actual visible feature) — wired against that one provider first, so there's something real to click through before adding more data sources.
3. Add the remaining four providers (contest, streaming, CRM, registry) one at a time, each independently testable.
4. Not in scope for v1: full-text relevance ranking/a dedicated search index — `LIKE`-based matching is the honest, correct-for-catalog-size choice here, same reasoning `BHS_Recommendations`/`BHM_Recommendations` already used for content-based scoring instead of a real ML/index-backed system.

## 2. In-admin version history for user-built content

### Grounded in what's actually there today

- **Nothing resembling full version history exists.** `OUS_Audit` (`own-ur-shit/includes/class-audit.php`) is the only related mechanism, and it's a genuinely different thing: `log_diff()` stores a **field-level diff only** (`[old_value, new_value]` per changed key, not a full object snapshot) in `bhcore_audit_log`, which is also **pruned** (`MAX_ROWS = 20000` / `KEEP_ROWS = 15000`) — both properties that rule it out as a durable revision store on its own. It's an accountability log ("who changed what"), not a restore mechanism.
- **One anticipated-but-unused seam already exists:** `wp_bhcore_element_placements.revision_of` — a real column, explicitly commented "unused seam, always 0 today" (`own-ur-shit/includes/class-element.php`). Confirms this was anticipated for placements specifically, not built generally.
- **The shared-service convention to follow** (`OUS_Jobs`/`OUS_Notifications`): a bare static class in `own-ur-shit` core, self-managed schema (`maybe_upgrade()`/`create_or_update_schema()`, same pattern `class-audit.php`/`class-activator.php` files across every plugin already use), and a small set of static verb methods any other plugin calls directly — no DI, no central registration step for a consumer to remember.

### Proposed shape: `OUS_Revisions` (own-ur-shit core)

- **Table `bhcore_revisions`**: `id, object_type varchar(40), object_id bigint, version int, data longtext (JSON — the FULL object state, not a diff), label varchar(120) NULL, user_id bigint, created_at datetime`. Unlike `bhcore_audit_log`, this table is **not pruned** by default (a revision history is the point; losing old ones defeats it) — a future retention setting is a reasonable follow-up, not a v1 requirement.
- **`OUS_Revisions::snapshot($object_type, $object_id, array $full_state, $label = null)`** — called by a consumer right before/after a save, stores the WHOLE current state as JSON, auto-incrementing `version` per `(object_type, object_id)`. Consumers decide what "full state" means for their own object (e.g. bh-crm passes the person's entire notes/tags array).
- **`OUS_Revisions::history($object_type, $object_id, $limit = 20)`** — returns past versions (id, version, label, user, timestamp) for a "Version History" panel/metabox any consumer renders.
- **`OUS_Revisions::get_version($object_type, $object_id, $version)`** — the full stored snapshot, for a diff view or direct restore.
- **`OUS_Revisions::restore($object_type, $object_id, $version)`** — does NOT write the object's live table itself (that's consumer-specific — bh-crm knows how to write a person record, `OUS_Revisions` doesn't) — instead returns the snapshot data and fires a `do_action('ous_revision_restore_requested', $object_type, $object_id, $snapshot)` the consumer listens for and applies however its own save path already works. Keeps the shared service genuinely object-agnostic, same reasoning `BH_Content`'s renderer registry stays agnostic about what a block IS.
- **A shared, reusable "Version History" admin UI fragment** (a metabox/panel renderer any consumer can drop in, listing `history()`'s rows with a "Restore" button per row) — so a consumer gets a real, consistent UI for free, not just the storage API. Matches the "one shared service, zero central registration" spirit one level further than `OUS_Jobs`/`OUS_Notifications` do (neither of those ships a reusable UI fragment, but a revisions feature is worthless without one).

### Sequencing (one real consumer first, matching this doc's own naming of candidates)

1. `OUS_Revisions` shell (table + `snapshot`/`history`/`get_version`/`restore`) + the shared Version History UI fragment — no consumer wired yet, but fully testable via Debug Tools seed actions (matching every other shared service's own debug-tools test pattern).
2. **First real consumer: bh-crm notes/tags** (named as a candidate in `ROADMAP-platform-evolution.md` Section 7a, and the most bounded/lowest-risk object shape — a person's notes array, not a complex nested tree). `BHCRM_People::save()` calls `snapshot()` after every save; the person-detail screen gets a "Version History" panel.
3. Once #2 is proven end-to-end (real save → real snapshot → real restore, verified live), extend to the other named candidates one at a time: `BHY_Style`/`BHY_UI` configs, contest configurations (bh-contest), LMS courses/lessons/quizzes (bh-courses, natural now that lesson content is real `BH_Content`/block data), portal layouts. Each is its own small, independently-shippable slice — not a big-bang rollout.
4. Not in scope for v1: a visual diff view between two versions (a real, distinct feature layered on top of `get_version()` once basic restore is proven) — flagged, not built.

## Status

**NOT YET BUILT — this document is the scope, written before any code.** Both sections above describe the plan to work from; implementation starts with Section 1's step 1 and Section 2's step 1 (the shared-service shells), since both are prerequisites for everything else in their own section.
