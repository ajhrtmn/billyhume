# Changelog — BH CRM

Moved out of `bh-crm.php` on 2026-08-23. See `CONVENTIONS.md` for why version history lives here and in git rather than in source.

Entries are newest-first, exactly as written in-file. Nothing reworded or dropped.

---

2.4.22 — Bug fix found while seeding the project tracker against real
content on a staging copy.

- `BHCRM_Projects::on_placement_saved()` cast `config.attrs.column`
  straight to string, but BH_Element stores that attr as the
  `{"literal": "..."}` binding wrapper (the render path at ~line 750
  only sees it already resolved). Result: a PHP "Array to string
  conversion" warning on every card save/seed, and the literal text
  "Array" logged as the column name for time-in-column tracking. New
  `column_from_config()` helper unwraps both shapes.


2.4.21 — Real bug fix surfaced by the-self-hosted-self's own final PHPStan
level 6 brick (typing OUS_Debug::button() with a real `: void`
return): class-debug.php here was calling it as `echo
OUS_Debug::button(...)`, double-printing that debug-tools button on
this plugin's own Debug Tools section — button() already echoes its
own markup internally, the wrapping `echo` was pure extraneous
output. Fixed by dropping the `echo`. NOT runtime-verified against a
live install; smoke-test the Debug Tools page to confirm the button
renders once, not twice.

2.4.20 — Ecosystem quality Phase 2, brick 9/13: bh-crm is now clean at
PHPStan level 6 (native return/parameter types + precise array-shape
PHPDoc throughout, no @ts-nocheck-equivalent shortcuts). All 14 files
in includes/ typed: class-subtasks.php (the recursive nested sub-task
tree — the two reference-returning helpers &find_node()/&children_at()
got PHPDoc-only return types since PHP reference-return syntax doesn't
combine with a native return type), class-projects.php (1153 lines,
REST routes, schema, stall analytics, BH_Element/BH_Content surface
registration — fixed a pre-existing misattached @param on
rollup_counts()'s docblock along the way), class-card-log.php (Fixes/
Feedback/Idea Drop sub-features), class-links.php, class-segments.php,
class-notes.php, class-tags.php, class-people.php, class-event-
activity.php, class-test-suite.php, class-debug.php, class-style-
surface.php, class-hub.php, class-export.php. No behavior changes —
every edit is a type declaration or an array-shape PHPDoc block; this
plugin's own PHPStan level-6 scoped check and the full 12-plugin
level-5 ecosystem check both come back clean.
NOT runtime-verified against a live WordPress+MySQL install.
2.4.19 — TypeScript pilot: converted kanban-board.js (the project
board — SortableJS multi-column drag/drop, full-slot placement
upsert via the BH_Element REST bridge), the last deferred large/risky
file in this plugin. Real types throughout (BHPlacement/
BHCrmKanbanConfig interfaces), no @ts-nocheck. Shares subtasks.ts's
SortableApi/BHCoreToast global declarations (same tsc program, same
global scope) rather than redeclaring them.
NOT runtime-verified against a live browser this session.
2.4.18 — TypeScript pilot, continued: converted bulk-select.ts,
tag-chips.ts, and subtasks.ts (Sortable drag/drop + inline editing +
arm/disarm delete). Same posture as every other plugin's TS pilot
entry this session: plain `tsc`, no bundler, compiled .js committed,
`npm run build:bh-crm` after editing. kanban-board.js (492 lines)
deliberately NOT converted this pass — flagged for a dedicated future
pass with real browser verification, not attempted blind.
NOT runtime-verified against a live browser this session.
2.4.17 — PHPStan round 2 (this plugin went from 12 errors to 0).
Real bug fixed: BHCRM_Projects::progress_percent()'s @return
array{0:int,1:int} docblock had ended up misattached above the wrong
method — the intervening "Audit fix" comment block broke the natural
docblock-immediately-precedes-function association, so it ended up
documenting progress_percent() (which genuinely returns a plain int)
instead of rollup_counts() (whose actual return shape it describes).
PHPStan trusted the wrong type and propagated it to every real call
site. Not a runtime bug (the real code was always correct — $pct was
always a plain int at runtime), but a real documentation error, now
moved to its correct place above rollup_counts(). Every other finding
(class-test-suite.php's ReflectionMethod::invokeArgs(null, [&$tree])
mutation tests) was confirmed as a well-known PHPStan by-reference-
mutation limitation — see phpstan.neon's own comment — not a bug.
NOT runtime-verified against a live install — confirmed via a real
`vendor/bin/phpstan analyse` run. `php -l` clean.

2.4.16 — segment-builder.js converted to TypeScript (assets/ts/
segment-builder.ts), this plugin's first TS-pilot file, following
the-self-hosted-self's established pattern (plain `tsc`, module: none,
compiled output committed since the live site runs no build step —
new bh-crm/tsconfig.json, `npm run build:bh-crm`). Needed one real
null-narrowing fix strict mode caught (the condition-container element
lookup) that plain JS let pass silently. Compiled assets/js/
segment-builder.js verified with `node --check` and grepped for
CommonJS `exports`/`require(` artifacts — clean. Purely a type-safety/
authoring-layer change; no runtime behavior was touched. NOT
runtime-verified against a live browser this session.

2.4.15 — Saved-segment builder's live "N of M people match" preview
(class-people.php's render_segments_panel(), class-segments.php's
ajax_preview()) is now Datastar-driven on the-self-hosted-self 3.10+ — the
recommended first real conversion from ROADMAP-hyperpress-migration.md
§2. The condition-row add/remove JS (segment-builder.js) is completely
unchanged; only the preview trigger/response mechanism moved. Uses
Datastar's {contentType:'form'} option (serializes the closest
enclosing <form>'s real named fields — confirmed against Datastar's
own reference docs before use) so the existing conditions[i][field]/
conditions[i][value] form inputs needed no restructuring into a
signals array. Falls back cleanly to the original fetch()/JSON path
on an the-self-hosted-self core older than 3.10 (no OUS_Hypermedia) — both
render_segments_panel() and segment-builder.js branch on that.
NOT runtime-verified against a live WordPress+MySQL install this
session — the Datastar attribute syntax (data-signals/data-on:input/
data-on:change/data-show/data-text, the __debounce modifier, and
{contentType:'form'}'s exact serialization behavior) was each checked
against Datastar's own reference documentation before use, not
guessed, but no browser has actually exercised this. `php -l` clean.

2.4.14 — BHCRM_Segments::register_campaign_segments() (class-segments.php)
now bridges every saved CRM list, plus a built-in "everyone active in the
CRM" segment, into the-self-hosted-self's OUS_Campaigns via the existing
'bhcore_campaign_segments' filter. Direct motivation: CLAUDE.md's new
standing rule ("critical infrastructure always ships with a minimal,
self-hosted, built-in default — a third-party integration is an
enhancement, never the only implementation"), applied concretely.
OUS_Campaigns is already a complete, working broadcast tool on top of
BH_Mail/wp_mail() alone — no MailPoet or any other third party required —
but until this change, its only real audience option was the built-in
"everyone with an account" segment; every saved bh-crm list (tag/date/
project conditions, already fully built) was invisible to it. No new UI,
no new data: this reuses BHCRM_Segments::apply()/BHCRM_People::
active_user_ids() exactly as the CRM's own list page and live-preview
already call them, so a genuinely targeted broadcast never requires
installing anything beyond the-self-hosted-self + bh-crm. Harmless if OUS_Campaigns
is never active — an add_filter() on a filter nobody applies just sits
unused, same posture every other optional cross-plugin filter here takes.
NOT runtime-verified against a live WordPress+MySQL install this session;
`php -l` clean on class-segments.php and bh-crm.php.

2.4.0 — mobile-responsive kanban layout (782px breakpoint), auto-mark-done
when a card is dropped in the last ("done") column (one-directional — moving
a card back out does not un-check it, so completions can't be silently lost),
and a top-level board rollup showing each card's full recursive sub-task
tally via new BHCRM_Projects::rest_rollups() (bh-crm/v1/rollups).

2.3.0 — inline-editable title/description on sub-task cards (saves on blur,
no page reload) and a real progress bar (BHCRM_Subtasks::render_progress_bar())
replacing the old plain "X/Y done" text, at both board level and per-card.

2.2.0 — rebuilt BHCRM_Subtasks so every nesting level renders as a full
multi-column kanban board (reusing kanban-board.css/.js) instead of the
flat checklist 2.0.0/2.1.0 had built. New 'column' schema attr on
'bhcrm/sub-card'; all nesting levels share one column vocabulary (the
parent project's own columns_config) rather than a per-level column set.

2.1.0 — four UI improvements to the nested sub-task tracker: breadcrumb
collapse past 5 segments, a non-blocking size warning past 50 nodes in a
card's whole tree, sibling drag-reorder at any level (via fetch() to an
admin-post handler rather than a <form>, since nested <form> elements
break inside certain WP admin contexts), and bulk "one per line" add.

2.0.0 — real nested sub-task tracking view (class-subtasks.php), replacing
Content Studio for this purpose: breadcrumb trail, recursive done/total
rollup at every level, add/toggle/edit/delete at any depth. New 'uid' attr
on 'bhcrm/sub-card' gives each nested sub-task a stable identifier that
survives a reorder or sibling edit elsewhere in the tree — needed since
linking a sub-task to a person/project (BHCRM_Links) requires a stable id.
Note: a placement's config stores each attr as {literal: 'x'}, not a flat
{title: 'x'} map — read card titles through BHCRM_Subtasks::card_title().

1.9.3 — segment/project deletion now logs to OUS_Audit before the row is
gone, and both delete handlers route their capability check through
OUS_Audit::require_cap() instead of a bare current_user_can().

1.9.2 — BHCRM_Links::link()'s insert now logs an error if the write fails,
instead of silently proceeding with a bogus $link_id.

1.9.1 — permissions audit fixes: (1) BHCRM_People::render_profile()'s phone
number line now requires bhcore_view_crm_sensitive (admin-only), not just
bhcore_manage_crm. (2) Every non-destructive CRM admin-post handler (save
note, save/bulk tag, export, save segment, project create/save-columns/
link/unlink) switched from manage_options to bhcore_manage_crm, since these
were wp_die()'ing for any editor/manager who could see the CRM menu but
not use it. Segment/project delete intentionally stay manage_options.

1.9.0 — unified per-person activity timeline: BH_Event::emit() calls added
at project links, contest submissions, wallet activity, and outbound email
write points, with labels in BHCRM_Event_Activity::type_label(). (Surfaced
a pre-existing ecosystem-wide event-ingest data-loss bug, fixed separately
in the-self-hosted-self 3.4.89.)

1.8.1 — project creation no longer requires a person in context. Added a
"Create project" form to the Project Tracker index; fixed board dispatch
and render_board()'s "back" link, which previously hard-required a truthy
$uid to open a project's board (an unowned project couldn't be viewed).

1.8.0 — projects<->people relationship redesign: bhcrm_projects.crm_person_id
was a hard single-owner column with no room for a collaborator/watcher and
no way to extend to other entity pairs. New class-links.php: a generic
typed relationship table (bhcrm_links: from_type, from_id, to_type, to_id,
relation, created_at) supporting any number of people per project under a
typed relation, and reusable for any future entity pair with zero schema
changes. crm_person_id is kept as a legacy fallback (still written on
create()) but no longer read as source of truth anywhere; migrate_legacy_
project_owners() backfills existing projects into real 'owner' links once,
idempotently.

1.7.2 — wrapped each section of the person detail page (Profile, Tags,
Notes, Projects, Activity) in .bhy-card for visual separation — previously
bare h2/h3/p elements with no card grouping.

1.7.1 — wrapped the People/CRM list page toolbar in one flex column with a
consistent --bhy-space-3 gap, replacing loose <p>/<input> elements that
relied on inconsistent default paragraph margins. Widened the Activity
column (min-width:220px) to stop its summary text wrapping awkwardly.

1.3.6 — kanban-board.js's hand-rolled HTML5 drag-and-drop (which only
supported dropping at the end of a column) replaced with SortableJS
(assets/js/vendor/sortable.min.js, vendored). reorderFromDom() rebuilds
state.placements from the post-drop DOM and re-saves through the same
saveSlot() every other edit uses. Drag now only initiates from a small
handle (⋮⋮) since cards contain interactive controls that would otherwise
fight with drag detection. forceFallback:true forces SortableJS's pointer-
event simulation instead of the native HTML5 draggable API, which some
automated-drag tooling can't trigger and which has generally weaker
cross-browser/touch support.
2.4.6 — the kanban preview's Design Suite entry (class-style-surface.php)
inherited the gallery's brand font-family token, so a Typography pick
restyled this fake wp-admin screen too. Fixed with an explicit
system-font-stack override.

2.4.5 — registered the kanban Project Tracker board as its own Design
Suite surface (class-style-surface.php) — previously the gallery only
showed the CRM profile page. Fixed a light-on-light text-contrast bug:
kanban-board.css expects a real light wp-admin background.

2.4.4 — live "N of M people match" preview for the segment builder
(BHCRM_Segments::ajax_preview(), segment-builder.js's debounced
runPreview()), using the same sanitize_conditions()/apply() pair the save
path uses so preview and save can never drift apart.

2.4.3 — subtask-board reorder save's failure handler called
window.location.reload() unconditionally, silently discarding a drag-drop
on network failure with no error shown. Now retries with backoff (a
full-layout save is idempotent) and only reloads, with a visible error
toast, once retries are exhausted. saveField() gets the same treatment.

2.4.2 — first contributor to OUS_Metrics dashboard (People tracked,
Relationship links widgets in class-people.php), using
event_trend_monthly() rather than the 30-day event_trend() since a
relationship graph moves slower than votes/enrollments and a daily
sparkline would mostly be noise. class_exists()-guarded.

2.4.1 — class-hub.php's log_result() now only logs a registration
FAILURE, not every successful admin-menu registration on every page load.


1.7.0 — saved smart lists/segments. New bhcrm_segments table storing a name
+ flat, AND-only list of conditions — deliberately not a nested AND/OR
group tree, since this CRM's person list tops out at a few hundred people
and flat AND covers the real use cases. Four condition types (tag,
registered after/before, has an active project), validated server-side
against BHCRM_Segments::FIELDS so the UI can never offer something the
server would reject. A segment filter AND-combines with the existing
tag-filter query arg rather than replacing it.

1.6.0 — bulk actions on the person list: checkbox per row + header
select-all in one <form> with two submit buttons (each targeting a
different admin-post action via formaction). New BHCRM_Tags::add_tag()/
handle_bulk_tag() ADDS one tag to each selected person's existing list
rather than replacing it. class-export.php's handle() intersects a POSTed
bulk_ids[] against the existing active/tag-filtered id set rather than
trusting it outright. Fixed a latent bug found while building this:
class-export.php still called the since-removed BHCRM_Notes::get() (1.4.0
replaced it with timestamped history) — would have fatal-errored the next
export attempt; replaced with a new notes_summary() helper.

1.5.0 — tag chips + autocomplete in the person editor, replacing the plain
comma-separated text input. Storage/handle_save()/BH_Event payload
unchanged. New assets/js/tag-chips.js is progressive enhancement — the
original plain text input stays in the DOM (hidden) as the real form field
handle_save() reads, so a JS-off browser degrades to the old behavior.

1.4.0 — notes rebuilt as timestamped history + authorship + reminders,
replacing the single-overwrite freeform `_bhcrm_notes` user meta field.
New bhcrm_notes table — every note is its own row, appended not
overwritten; pre-existing notes are migrated forward once, lazily, as a
single legacy-labeled note. Reminders schedule via OUS_Jobs::enqueue() and
notify through OUS_Notifications::notify(), addressed to the note's
original author. Fixed two bugs found during verification: (1)
list_for_person()'s ORDER BY created_at DESC had no id tiebreaker, so
display order was non-deterministic when two notes landed in the same
second. (2) handle_reminder_job() checked its own reminder_dismissed flag
but never set it, so a reminder could fire twice; fixed with an atomic
UPDATE ... WHERE reminder_dismissed = 0 claim-check before notifying.
