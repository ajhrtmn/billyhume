# Project Tracker — TrackIt feature-parity plan

Companion to `DESIGN-SUITE-UNIFICATION-PLAN.md` (the CRM Project Tracker, `bh-crm/includes/class-projects.php`, is built ON TOP of `own-ur-shit`'s element/node-tree system — see that doc's own CRM sections and this plugin's own 1.2.0/1.3.x changelog entries for how). This doc exists because AJ named a specific reference app — **TrackIt** (a macOS task/project tracker for music producers, record labels, mastering engineers, and client work — `https://allanmorrowstudios.com/trance-music/trackit-track-task-manager-tracker/`) — and asked to duplicate its full feature set inside this ecosystem's Project Tracker. His own words: *"I basically will want to duplicate all of Track Its functionality."*

**Status as of 2026-07-12: DESIGN ONLY, NOT BUILT.** This is a detailed plan, written so the next build pass has a clear, already-thought-through target — deliberately not started tonight, per AJ's own "we are in the middle of other things though... keep moving forward on the other stuff."

## 1. What TrackIt actually does (surveyed directly from its product page)

- **Kanban board with custom, renameable, reorderable stage columns** ("Idea → Production → Mixing → Mastering → Release", or whatever the user renames them to) — a card moves through the columns as work progresses.
- **Per-card checklist with a completion percentage/progress bar** — "80% done, two jobs left" is the exact framing used to sell the feature (a psychological nudge to finish, not just a status readout).
- **Reusable checklists** — save a checklist ("go-to drum elements," "mixdown checklist," "promo and marketing list") once, drop it onto any card in a couple of clicks, rather than re-typing the same list every time.
- **Idea Drop** — a "parking lot" where audio/MIDI/sample files can be dropped onto a card and played back in place. Critically: **nothing is copied or moved** — it links to the file wherever it already lives on disk, so the user's own file organization is never disturbed.
- **Timestamped fixes** — mark an exact time-in-track ("lead too quiet at 2:34") so a specific piece of feedback points at a specific moment, not just a vague note.
- **Feedback log** — who said what about a track, logged against the card, separate from the general notes area.
- **Analytics / stall detection** — surfaces which stage tracks tend to get stuck in, and a rough finish-rate ("you start five tracks for every one you finish").
- **Separate "scenes"** — fully separate boards/workspaces so a user's own music, client work, and label releases never mix together in one view.
- **Direct DAW linking** — launches an Ableton/Logic project folder directly from a card (native-app-only; see §5's honest scoping note on this one).
- **Theme builder** — accent color, background, gradient/image customization for the app's own look.
- **BPM/key fields, per-track metadata** — genre-specific fields relevant to a music production workflow specifically.
- **One-time purchase, fully offline, local-file-only storage, manual export/import for backup** — these are TrackIt's own *distribution* model as a native Mac app, not really portable concepts to a WordPress plugin (our Project Tracker is inherently server-side/multi-user, not a single local `.app`) — noted for completeness, not carried forward as requirements.

## 2. What this ecosystem's Project Tracker already has (mapped 1:1 against the above)

Read directly from `bh-crm/includes/class-projects.php`'s current (1.2.0–1.3.x) implementation:

- **Custom, renameable, reorderable columns** — ALREADY BUILT. `columns_config` (a JSON array of column-label strings) is per-project, editable via `handle_save_columns()`. Matches TrackIt's "build your own roadmap" 1:1.
- **Per-card completion percentage** — ALREADY BUILT, via a genuinely different (and arguably better-fitted) mechanism than a flat checklist: sub-cards nested under a card via the `bh/container` → `BH_Content` bridge (`bhcrm/sub-card` block type), with `rollup_counts()` computing "N/M done" at RENDER time from the sub-card tree's own completion state — no separately-stored progress number to go stale. This is the Godot-style nested-node structure AJ asked for elsewhere in this session, applied here as the checklist mechanism, not a separate feature.
- **Everything else in §1 — NOT BUILT.** Reusable checklists, Idea Drop (file linking), timestamped fixes, a feedback log, stall analytics, separate scenes/boards, and BPM/key metadata fields are all real gaps against TrackIt's actual feature set.

## 3. Phased build plan (smallest real slice first, mirroring every other design doc in this repo)

### Phase A — Reusable checklists (sub-card templates)

The most natural next slice: this ecosystem already has a general-purpose prefab system (`BH_Element_Prefab`, built earlier this session — full-subtree snapshot/restore, deep-copy-on-instantiate, already proven end-to-end via the Gutenberg block work). Sub-cards are `BH_Content` blocks (`bhcrm/sub-card`), not `BH_Element` placements, so `BH_Element_Prefab` itself doesn't directly apply — but the SAME pattern (snapshot a set of items as a named, reusable definition; instantiate a deep copy of that definition wherever requested) is exactly right here, just scoped to `BH_Content` sub-card trees instead of `BH_Element` placement trees.

**Concrete plan:** a new `bhcrm_checklist_templates` table (id, name, definition — a JSON array of `bhcrm/sub-card` entries, same shape a card's own `content_tree` snapshot would produce), a "Save this card's sub-cards as a reusable checklist…" action next to the existing "Save this subtree as a prefab…" pattern already used elsewhere in the Design Suite, and an "Apply checklist…" action on any card that deep-copies the template's items in as fresh sub-cards (fresh ids, independent of the template afterward — same non-negotiable independence guarantee `BH_Element_Prefab::instantiate()` already established and documented).

**Judgment call to make at build time, flagged now:** should checklist templates be a NEW class (`BHCRM_Checklist_Template`) or a generalization of `BH_Element_Prefab` itself to snapshot arbitrary tree shapes (not just `BH_Element` placements)? Leaning toward a new, small, CRM-scoped class — `BH_Element_Prefab` is already load-bearing for the Design Suite/Gutenberg work and mixing two different tree shapes (placement trees vs. `BH_Content` block trees) into one class risks exactly the kind of "one class doing two jobs" issue this ecosystem's own QA passes have flagged and fixed before. Revisit if a THIRD prefab-shaped need shows up later — three real consumers is a much stronger case for a shared abstraction than two.

### Phase B — Timestamped fixes + feedback log

Both are genuinely new data, not a restructuring of anything that exists:

- **Timestamped fixes**: a new `bhcrm_project_fixes` table (id, card_placement_id, timestamp_seconds, note, resolved bool, created_at) — a flat list per card, rendered as a simple sorted list ("2:34 — lead too quiet") with a resolved/unresolved toggle. `timestamp_seconds` is a plain number (not tied to any specific audio player) so it works whether the "track" a card represents is linked from bh-streaming, an external file, or nothing playable at all — the number is just a marker the user interprets themselves, matching TrackIt's own "mark the exact spot so you know where to go" framing (it doesn't require an embedded audio player to be useful).
- **Feedback log**: a new `bhcrm_project_feedback` table (id, card_placement_id, author_name, note, created_at) — deliberately a free-text `author_name` field, not a `user_id` foreign key, since TrackIt's own use case is logging feedback from PEOPLE WHO AREN'T USERS OF THE SYSTEM AT ALL (a label contact, a client, a friend giving a listen) — forcing a real WordPress account for every piece of feedback would misrepresent how this is actually used.

Both render as simple sections on the card's own detail view (`BHCRM_Projects::render_projects_section()`'s existing per-card area), no new page/menu needed.

### Phase C — Stall analytics

Needs one new piece of real historical data neither existing table currently captures: WHEN a card entered its current column. Concretely: a new `bhcrm_project_card_moves` table (id, card_placement_id, column_label, entered_at) — one row per column transition, written whenever a card's `column` attr changes (a hook into the existing save path, not a new UI). From that raw log, a simple report (average/median time-in-column per column, across all cards ever in that project) answers TrackIt's own "where do tracks keep stalling" framing directly. A finish-rate stat ("N started, M finished") is a simple count over the same table (cards that ever reached the project's LAST configured column vs. total distinct cards). This is genuinely the most work of the three phases (a new table, a write hook, and a real report view) but also the most data-driven/differentiated feature, matching TrackIt's own framing of it as the standout feature ("this is the part I think makes a massive difference").

### Phase D — Idea Drop (linked local files)

**Honestly the hardest one to port faithfully, flagged clearly rather than hand-waved:** TrackIt is a native macOS app with direct filesystem access — dropping a file onto a card just records that file's path, no upload, no copy. A WordPress plugin running in a browser has NO equivalent capability — a browser cannot "link" to an arbitrary path on the user's local disk the way a native app can; the only web-native options are (a) a real upload into the WordPress media library (copies the file, unlike TrackIt's explicit "nothing gets moved or changed" promise — a real, honest behavior difference, not a bug to fix), or (b) if this server ever runs alongside bh-streaming's own local-file-library feature, LINKING to an already-imported streaming-library track by id (no new copy, genuinely matching TrackIt's "link, don't copy" model, but only for files already inside bh-streaming's own library, not arbitrary disk paths). **Recommendation for whoever builds this phase:** ship (b) first if bh-streaming is active (real "link, don't copy" parity, scoped to files already in the system), with (a) as a fallback/upload option when it isn't — and be upfront with AJ that "drop any file from anywhere on your Mac" specifically cannot be replicated in a browser-based plugin, full stop, regardless of implementation effort.

### Phase E — Separate scenes/boards

Partially already possible today: `BHCRM_Projects::create()` already takes a `$person_id` and boards are already independent per row in `bhcrm_projects` — multiple independent boards already exist as a DATA model. What's missing is purely UI: `render_boards()`'s current listing doesn't group/filter by any "scene" concept (own music vs. client work vs. label). **Concrete plan:** add an optional `scene` string column to `bhcrm_projects` (free-text, user-defined, same "no fixed enum" posture `columns_config` already uses for column names), and group `render_boards()`'s listing by it. Lowest-effort phase of the five — almost entirely a rendering/grouping change over data that already exists.

### Explicitly NOT ported

- **Direct DAW linking** (launching an Ableton/Logic project folder from a card) — native-app-only capability (macOS file-system + app-launching APIs), no meaningful browser-based equivalent. Not planned.
- **Theme builder** — this ecosystem already has one, ecosystem-wide (`BHY_Style`/the Design Suite), which is more capable than a single-app theme picker. No separate one needed for the Project Tracker specifically.
- **BPM/key fields** — genuinely easy (two more schema attrs on `bh/sticky-card`, same pattern the existing `data-status` structured attr already uses) but deliberately left out of the phased plan above since no phase needs it as a dependency — a trivial follow-up whenever it's actually wanted, not worth its own phase.

## 4. Build-order recommendation

A, then B, then E, then C, then D — ascending effort/risk, and E (scenes) is cheap enough to slot in early despite being listed last in AJ's own feature narrative, since it needs almost no new code. D (Idea Drop) is last because it is the least faithfully portable of the five and benefits most from bh-streaming's own local-library feature being further along first.
