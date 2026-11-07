# OPEN — what's unfinished

Only open items live here. Finished work is in each plugin's `CHANGELOG.md` and in git; old plans are in `docs/archive/`. Ordered roughly by value.

## Bugs (found reading the code, 2026-11; not yet fixed or runtime-verified)

- **bh-contest — judged contests announce the wrong winners.** `BH_Discord::notify_results()` and the admin "email winners" action rank by public votes even when the contest format is judges/hybrid.
- **bh-contest — round windows can disagree.** `replace_audio()`, `edit_details()`, `advance()` and `admin_live()` use `BH_Helpers::is_*_open()` while `submit()`/`vote()` use `BH_Rounds`; on multi-round contests the two can differ.
- **bh-contest — `advance_round()`** only ranks entries with votes or scores, so zero-tally entries never advance even when `cut_count` exceeds the field.
- **bh-contest — vote base/bonus:** clearing the field saves 0 instead of the site default the form promises.
- **bh-contest — `reveal.ts`:** `localStorage` calls aren't wrapped in try/catch (throws in private/blocked-storage contexts).
- **bh-contest — console** shows a draft (no audio yet) submission as "Pending".
- **bh-crm — stalled-card days** mix WordPress local time with MySQL server time in `stalled_cards_for_board()` (off by the timezone offset).
- **bh-monetization-woo —** the activator's final table check skips `$auction_bids`; `tiers_page_url()` links draft/private pages; the monthly equivalent of an annual price is truncated, not rounded.
- **bh-courses —** the course list's "Access" column only shows tier gating (benefit/purchase-gated courses show "Open").
- **bh-registry — tests:** the verification "success" tests use unresolvable `.test` URLs, which `is_safe_external_url()` now rejects, so they can't pass (likely the 10 long-standing registry failures).
- **bh-live — test** assumes `active_key()` defaults to `owncast` without clearing the stored setting.

## Self-hosting rule breaks

- **bh-contest loads Howler from cdnjs** and **bh-courses loads Bunny's `player.js` from Bunny's CDN** (the latter only when Bunny is configured). Vendor Howler; Bunny is an opt-in third-party layer by design.

## Code cleanup still open

- **23 inline `<script>`/`<style>` blocks that splice in PHP values** (~1,650 lines), led by the Design Suite scripts in `class-style-gallery.php`, the reports widget, and `class-ui.php`'s swatch script. Move each to a file with its data passed as a JSON config. Best done screen by screen when that screen is next touched.
- **Long render functions** (up to ~330 lines: `render_step()`, `render_controls()`, `OUS_Debug::render()`, the course/contest metaboxes). Split when the screen is next changed, with a before/after render check.
- **Hardcoded hex colors** — 200+ literals across plugin stylesheets (`kanban-board.css`, `bhm/frontend.css`, `feedback.css`, `registry.css`, …) that don't follow the theme tokens.
- **Bundled plugin zips** (`the-self-hosted-self/bundled/`, 3.4 MB) duplicate every peer in git; consider building them in the deploy workflow instead.
- **PHPCS blocking** (currently advisory; ~1,200 low-severity findings) and **PHPStan level 7** (~486 findings, mostly unhandled `|false`/`|WP_Error`).

## Front-end craft (see `DESIGN-CRAFT.md`)

- **Motion and feedback parity** — no loading/skeleton states in our own code; state changes are page reloads.
- **Moments that carry weight** — course completion, entitlement granted, contest reveal, first supporter: no shared celebration/acknowledgement treatment.
- **First-run experience** of a brand-new install.
- **UX audits not yet run:** the live contest voting/reveal flow, bh-crm admin person views, and the front end in both themes.

## Features not built

- **LMS authoring depth** — more block types and an LMS-specific inserter palette.
- **bh-social** — embedding a connected platform's content, and importing existing content.
- **Design passes only** (in `docs/archive/`): branching lessons + mind-map authoring, federated metrics, OBS tooling for bh-live, streaming media scope, LMS scheduling/grading, the Datastar (HyperPress) migration backlog. Plus the big-vision pillars in `VISION.md`.

## Parked

- **`self-hosted-self-admin-skin`** — experimental, deactivated. Its token system, contrast work and command palette may be worth harvesting. Lesson: to check "does this look native," turn the plugin off and compare.

## Blocked on AJ

- **Live GitHub Updates "Update now"** flow on the Wasmer preview site hasn't been click-tested (the daily canary only checks the URLs resolve).
- **bh-courses Sessions calendar blank on the deployed site** — fine locally; needs the live page's devtools to see whether the vendored FullCalendar 404s or gets rewritten by an optimizer.
- **ActivityPub relay** can't run on the Wasmer build (no openssl); no relay URL chosen yet. **Registry bootstrap seeds:** only one; want 3–4.

## Verification habits worth keeping

- Measure, don't eyeball: contrast, overlap, clipping (`scrollHeight` vs `clientHeight`), horizontal overflow.
- Reload per theme; never toggle `data-shsas-theme` and re-read in the same pass (`var()` doesn't re-resolve).
- Say plainly when something was reasoned through rather than run.
