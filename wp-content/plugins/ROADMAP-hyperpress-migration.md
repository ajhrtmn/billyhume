# ROADMAP — Datastar migration inventory (Phase 2 of the OSS-integration master plan)

This is the tracked backlog CLAUDE.md's hard-conventions entry refers to when it says Datastar is the new default for new interactive admin/editor UI, with existing screens converted as separate, later, individually-scoped passes — not a mass rewrite. The inventory below is grep-driven (see the commands in each section), not guessed.

## 0. The honest finding this inventory produced

Before writing this doc, the assumption was "grep every `wp.element.createElement` usage, that's the migration list." Running that grep (`grep -rl "wp\.element\.createElement\|el = wp\.element\|registerBlockType" wp-content/plugins --include="*.js"`) found 10 files — and every single one of them is a genuine Gutenberg block-editor integration (`registerBlockType()` or an `editor.BlockEdit` filter):

- `bh-contest/assets/js/bh-contest-blocks.js`
- `bh-courses/assets/js/bhc-blocks.js`
- `bh-courses/assets/js/courses-studio-blocks.js`
- `bh-monetization-woo/assets/js/bhm-blocks.js`
- `bh-monetization-woo/assets/js/storefront-studio-blocks.js`
- `bh-streaming/assets/js/bhs-blocks.js`
- `own-ur-shit/assets/js/block-style-panel.js`
- `own-ur-shit/assets/js/element-prefab-block.js`
- `own-ur-shit/assets/js/page-content-block.js`
- `own-ur-shit/assets/js/studio.js`

**None of these are migration candidates.** `wp.element` is the objectively correct tool inside the Gutenberg block editor's own canvas — a block must integrate with the editor's own React-based attribute/state system, and Datastar has no role there. Converting any of these to Datastar would be a regression, not an improvement. This section exists specifically so a future pass doesn't rediscover this list and assume it's the backlog — it isn't.

## 1. Where the real opportunity actually is

Datastar's actual value is on **plain wp-admin (or front-end) screens that already hand-roll a `fetch()`-then-patch-the-DOM pattern** — that's exactly what Datastar's `data-on:click="@get(...)"` + SSE-driven `patch_elements()` replaces, usually with less client-side JS, not more. Found via `grep -rl "fetch(" wp-content/plugins --include="*.js" | grep -v vendor`, filtered to exclude the block-editor files above:

**Strong candidates — real admin/form UI doing manual fetch-and-DOM-patch today:**
- `bh-crm/assets/js/kanban-board.js` — drag/drop + fetch() saves on the project board.
- `bh-crm/assets/js/segment-builder.js` — the live "N people match" preview (`BHCRM_Segments::ajax_preview()`), currently a manual `fetch()`/`wp_ajax` round trip; a natural first real Datastar consumer since it's already doing exactly the "type something, see a live server-computed answer" pattern Datastar is built for.
- `bh-crm/assets/js/subtasks.js` — inline-edit/reorder on the nested sub-task tracker.
- `bh-monetization-woo/assets/js/storefront-filter.js` — the storefront's live product filter re-query (`ous/v1/storefront/products`).
- `own-ur-shit/assets/js/search.js` — `[ous_search]` live results.
- `own-ur-shit/assets/js/element-live.js` — worth a direct read before assuming; name suggests it may already be doing something close to what Datastar formalizes.
- `bh-contest/assets/js/portal-submissions.js`, `bh-contest/assets/js/bh-judging.js` — portal/judging forms with save-and-refresh patterns.
- `bh-courses/assets/js/courses.js` — progress/quiz interactions.
- `bh-registry/assets/js/registry.js` — submission/review list actions.

**Explicitly NOT good Datastar fits — genuinely continuous, stateful media/live-signal handling, not request/response fragments:**
- `bh-streaming/assets/js/player.js` (the Howler-backed playback engine itself — transport state, seek position, queue advancement), `bh-video/assets/js/video-player.js`, `bh-live/assets/js/live-player.js`, `bh-live/assets/js/chat.js`, `bh-live/assets/js/obs-bridge.js`, `bh-live/assets/worker/chat-worker.js`, `bh-contest/assets/js/player.js` (same reasoning — this is bh-contest's own audio player, not a list/browse screen). These stay exactly as they are — SSE/hypermedia patching is the wrong model for continuous media playback or a live chat's own message stream, which already have purpose-built patterns (native media APIs, WebSocket/worker messaging) that shouldn't be disturbed.

## 1a. User-facing surfaces worth exploring directly (AJ, this session)

AJ flagged streaming, contests, and the portal specifically as good Datastar exploration targets — worth separating from the playback-engine exclusion above, since "the streaming plugin" and "the audio player inside it" aren't the same surface:

- **The portal (`BHI_Portal`, `own-ur-shit/includes/class-portal.php`/`class-portal-layout.php`)** — genuinely has NO JS today at all (grep-confirmed: no `portal`-named file under `own-ur-shit/assets/js/`); it's plain server-rendered PHP panels reached via full-page navigation. This is arguably the single best Datastar opportunity in the whole codebase precisely because there's nothing to migrate AWAY from — panel switching, a live notification-bell count, wallet-balance refresh after a purchase, could all become real Datastar-driven fragments without displacing any existing pattern. Recommend this as a strong second (or even first) real consumer alongside `segment-builder.js`.
- **Contests, the browse/list/reveal surfaces specifically (not the audio player)** — `bh-contest/assets/js/archive.js` (the archive grid) and `bh-contest/assets/js/reveal.js` (the live reveal-party sequencing) are closer to "list/browse/sequence UI" than "continuous playback," so they're moved here rather than the exclusion list above — genuinely worth a real look, though `reveal.js`'s live-tally-during-a-reveal-event behavior should be read closely first to confirm it isn't relying on tight client-side timing Datastar's request/response model can't match.
- **Streaming, the library/queue/browse UI (not `player.js`'s own playback engine)** — bh-streaming's front-end has more surface than just the player (library browsing, playlist management); worth a direct read of what else exists under `bh-streaming/assets/js/` beyond `player.js` before scoping a real conversion, not assumed here.

None of this section is started — it's AJ's direction captured for the next pass to actually scope, not a claim any of it has been read closely enough yet to commit to a real design.

## 2. Suggested first real conversion

**`bh-crm/assets/js/segment-builder.js`'s live preview** is still the recommended smallest first real Datastar consumer, once Phase 2's core plumbing (`OUS_Hypermedia`) has landed: smallest real surface area, already does the exact "compute something server-side, show it live" thing Datastar exists for, and a clean way to prove `OUS_Hypermedia::sse_headers()`/`patch_elements()` actually work end-to-end before anything bigger depends on them.

**The portal (§1a) is the recommended next, larger consumer** once that first small conversion is proven — highest-value target since it has zero existing JS to displace, and "live notification/wallet-balance updates without a full page reload" is a real, concretely useful improvement to the account area every fan sees. Not started — this is the recommendation, not a claim it's done.

## 3. Status

**Portal (§1a) — DONE, own-ur-shit 3.10.5.** The notification badge and wallet-balance chip in the account portal nav (class-portal.php) are now live Datastar signals, polled every 30s via a new `wp_ajax_ous_portal_live_status` handler rather than only updating on a full reload. NOT runtime-verified against a live install — `php -l` clean, and the Datastar attribute syntax used was checked against its own reference docs, but no browser has actually exercised this yet.

**`segment-builder.js`'s live preview (§2) — DONE, bh-crm 2.4.15.** Converted after the portal (order flipped from this doc's original recommendation, per direction given this session). Condition-row add/remove JS untouched; only the preview trigger/response now rides Datastar (`{contentType:'form'}`, no signals-array restructuring needed). Same NOT-runtime-verified caveat as the portal.

**Everything else — NOT STARTED.** Every remaining conversion should still be its own separate, small, individually-verified pass (own changelog entry, own `php -l` + real-browser check against a live install), not bundled into a larger unrelated change.
