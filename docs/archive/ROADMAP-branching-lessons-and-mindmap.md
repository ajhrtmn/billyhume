# Branching lesson paths + mind-map authoring — design doc

Written 2026-08-26, item 19 of the Tier 3 backlog. This is a design
pass only — no code — per explicit instruction. Both ideas got a joint
pass because they turned out to share the same underlying primitive
(see Section 3): a directed graph of content nodes, which the LMS-only
"branching lessons" idea is really just one consumer of.

## 1. What exists today, read from the real code

`BHC_Progress` (`bh-courses/includes/class-progress.php`) tracks
completion at `(user_id, lesson_id, step_index, sub_index)` — `sub_index`
was added this session for in-video annotations, but `step_index` itself
is a plain integer position in a **linear** array. `BHC_PostTypes::lesson_order($course_id)`
returns a flat, ordered list of lesson IDs — a course is an ordered
list of lessons, a lesson is an ordered list of steps, full stop. There
is no concept anywhere in this schema of "step 4 leads to step 6 OR
step 9 depending on how step 4 was answered," and no concept of a
lesson having more than one possible successor.

This is a real, structural constraint, not a UI gap — `step_status()`,
`is_step_complete()`, `next incomplete step` logic, and the lesson-
sidebar rendering all assume "position N in a flat sequence" as the
unit of progress. Branching can't be bolted onto this schema as an
extra column; it needs a different shape for "where is this learner
right now" and "what's next."

## 2. Branching lesson paths

### 2.1 The real design question

Two genuinely different things get called "branching" and they need
different data models. Pick one, or explicitly build a small kernel
that supports both — don't let this stay ambiguous into the build:

- **Remediation branching** — a quiz step's outcome sends the learner
  to a DIFFERENT next step (extra practice on a miss, skip-ahead on a
  clean pass), but the course is still fundamentally one lesson-order
  spine with detours. Smaller change: `BHC_Steps` gains an optional
  `next_step_map` per step (`{on_pass: N, on_fail: M}` or a single
  `next_step_index` override), and `BHC_Progress` starts tracking "the
  actual path taken" as an ordered list of step_indexes visited, not
  just "how far in the array am I."
- **Structural branching** — the course itself forks into genuinely
  different lesson sequences (a "choose your track: mixing vs.
  mastering" moment), which is a real graph: lessons are nodes, a
  learner's position is "which node am I on," and "next" is "which
  edge did I take out of this node." This needs `_bhc_lesson_order`
  replaced (or joined) with a real edge list —
  `(from_lesson_id, to_lesson_id, condition)` — and `BHC_Progress`
  needs a `current_lesson_id` concept per (user, course) instead of
  relying purely on lesson_order's flat position.

**Recommendation:** build the small kernel once, scoped as structural
branching (it's the strictly more general case — remediation branching
is a structural graph with a very short, two-node fork), so remediation
isn't a second thing to build later. Concretely: a `bhc_course_graph`
table, `(course_id, from_node, to_node, condition_json)`, where a node
is `lesson:{id}` or `step:{lesson_id}:{index}` (both node types
supported from day one — a course-level fork and a within-lesson
remediation fork are the same primitive at two granularities).
`condition_json` starts with exactly one shape — `{quiz_step: N, min_score: X}`
— and stays a tagged, versioned shape so a second condition type
(e.g. "learner selected option B") is additive, not a rewrite.

### 2.2 What actually has to change, concretely

- `BHC_Progress` needs a new "current position in the graph" concept
  per `(user_id, course_id)` — not just per-lesson step tracking, which
  still works unchanged WITHIN a node. A `bhc_course_position` row
  (`user_id, course_id, current_node`) is the smallest addition that
  doesn't touch the existing step-tracking table at all.
- The lesson sidebar / "what's next" UI (`BHC_Render_Course`) currently
  assumes "next" is "the next array index" — this becomes "evaluate
  the outgoing edges of the current node against the learner's own
  progress, pick the first matching one (or show a real choice UI if
  more than one edge has no condition — a genuine fork the learner
  picks, not just a remediation branch)."
- Authoring: `BHC_Admin`'s existing lesson-order UI (drag-to-reorder,
  already built) stops being sufficient once a course isn't one line —
  needs a real graph-editing UI. This is where mind-map authoring
  (Section 3) becomes the SAME UI problem, not a second one.
- `OUS_Revisions` (already wired to `bh_course` this session) needs
  its snapshot to include the graph, not just lesson_order — a natural,
  small addition to `BHM_Admin::course_meta_snapshot()`'s equivalent in
  bh-courses once this exists.

### 2.3 What does NOT need to change

`BHC_Progress`'s existing per-step tracking (`step_status`,
`mark_step_complete`, the `sub_index` annotation dimension) stays
exactly as-is — branching is a layer ABOVE step tracking, not a
replacement for it. A learner is always "on some node," and everything
that already works today (step completion within a lesson, in-video
annotations) keeps working unchanged once they're on it.

## 3. Mind-map authoring — the generalization

The reason this got a joint design pass with branching: authoring a
branching course graph and authoring a general "mind map" are the same
UI (nodes + directed edges, laid out spatially, edited by drag/connect)
wearing two different domain skins. Building the graph-editing UI
`BHC_Admin` needs for 2.2 as a **generic, LMS-agnostic component** means
it's not thrown away or duplicated the day someone wants a mind-map
tool for something else.

### 3.1 What a node actually is, generically

The project's own standing rule (`CLAUDE.md`'s portability rule) is
that stored content should stay plain and WP-agnostic; WP-specific
attachment is a separate layer. Applied here: a mind-map's node data
shape should be `{id, label, x, y, ref: {type, id}}` where `ref` is
*optional* — a node can be a pure organizational label with no
attached content (a real mind-map use case: "just a topic bubble"), OR
point at something real:

- `ref: {type: 'lesson', id: N}` — a `bh_course`'s own lesson node
  (Section 2's graph).
- `ref: {type: 'element_placement', id: N}` — a `BH_Element` placement
  (`render_slot()`'s own unit, already used across bh-contest/bh-crm/
  bh-courses).
- `ref: {type: 'block', id: N}` — a `BH_Content` block-tree document.
- `ref: null` — a bare idea/label node, no attached content at all.

This is exactly the same shape `BH_Element_Data`/`BHY_BlockStyle`
already use for "the stored shape is plain, the WP mechanics are a thin
attachment layer" — nothing new architecturally, just applying the
existing convention to a new consumer.

### 3.2 Storage

A `bh_mindmap` post type (one per map, not per node) with a single JSON
postmeta blob: `{nodes: [...], edges: [...]}`. Deliberately NOT a
node-per-row table — a mind map is edited as one whole graph in one
sitting (drag nodes, draw edges), never queried node-by-node
independently the way `bhc_progress` rows genuinely are queried
per-user-per-step. One JSON blob is the honest match for "read the
whole thing, edit the whole thing, save the whole thing," same
reasoning `BH_Content`'s own block-tree storage already uses.

The course-graph table from Section 2.1 (`bhc_course_graph`) is a
SEPARATE, LMS-specific runtime table — it's read on every lesson-
completion check, at a frequency and access pattern nothing like
opening an authoring UI. The relationship: `bh_mindmap`'s JSON blob is
the AUTHORING representation a course creator edits visually;
publishing/saving a course-shaped mind map is what writes rows into
`bhc_course_graph`. Two representations of related information, kept
separate because they're read at wildly different frequencies by
wildly different code paths — exactly the same reasoning that already
keeps `bhc_progress` (hot, per-user, per-step) separate from
`bh_course` postmeta (cold, authoring-time).

### 3.3 The authoring UI itself

Canvas-based (not a `wp.element` form, not Datastar — this is
freeform 2D drag/connect, a genuinely different UI class than anything
else in this ecosystem). `GrapesJS` was already surveyed and rejected
for the page-builder use case (see the page-builder-saga section of
`CLAUDE.md` — deliberately not revisiting that here, since a mind-map
canvas is nodes-and-edges, not a drag/drop page layout, a different
enough problem that GrapesJS's rejection there doesn't automatically
apply here). No existing vendored dependency covers this; a real
evaluation of a small, MIT/BSD-licensed graph-canvas library (candidates
worth a real look when this gets built: Cytoscape.js, or a from-scratch
canvas since the node/edge model here is deliberately simple) is
future work, not resolved by this doc.

**Explicitly NOT a page-builder revival** (per the standing decision on
this exact question) — this is authoring a graph of REFERENCES to
existing content (lessons, element placements, blocks), never a tool
for building the content itself in-place. A node's `ref` points at a
lesson/placement/block that's still authored wherever it's normally
authored today; the mind-map only arranges and connects those existing
things spatially.

## 4. Sequencing recommendation

Build Section 2's course-graph kernel FIRST, with the plainest possible
authoring UI (even a simple "add edge: from X to Y, condition Z" admin
form, no canvas) — this proves the runtime model (`bhc_course_graph`,
the position-tracking table, the "what's next" evaluation logic) works
end-to-end without also taking on canvas-UI risk in the same pass.
Section 3's generic mind-map canvas is the natural SECOND pass once the
plain-form version is proven — replacing the plain form with a real
visual editor is a UI-layer swap, not a data-model change, if the
`{nodes, edges}` shape from 3.2 was used for the graph kernel's own
authoring representation from the start.
