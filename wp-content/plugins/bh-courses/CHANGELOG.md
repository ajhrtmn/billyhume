# Changelog — BH Courses

Moved out of `bh-courses.php` on 2026-08-23. See `CONVENTIONS.md` for why version history lives here and in git rather than in source.

Entries are newest-first, exactly as written in-file. Nothing reworded or dropped.

---

0.14.1 — Course single page (/courses/<slug>/) was rendering
full-bleed into Etch's <main> with no width cap, so the header/hero and
lesson list sprawled edge to edge while the lesson and catalog views
next to it were capped at 1200px and centred. `.bhc-course-view` now
gets the same `max-width: 1200px; margin-inline: auto; padding-inline`
treatment; the "All courses" back-link above it gets a matching left
offset. Mobile keeps a 16px gutter.

0.14.0 — In-lesson-editor Bunny Stream workflow, so an author never
leaves the editor (needs the Bunny API key in Media & CDN Setup;
without it the step still takes a pasted GUID):

- **Choose from Bunny library** — a modal browser (`BHC_Bunny` REST
  routes proxy Bunny's video API; the API key stays server-side),
  searchable, thumbnails, click to select.
- **Upload new video** — picks a file, creates the Bunny video, then
  `tus-js-client` (vendored, UMD) resumably uploads *straight to Bunny*
  with a presigned signature; progress bar; GUID auto-fills on success.
- **Signed preview** — the same signed iframe the front end shows,
  driven by player.js, so "+ Add chapter / overlay at 0:42" reads the
  real playhead instead of asking for a typed number.

New `class-bunny.php` (`bhc/v1/bunny/{videos,video,upload-signature,embed}`,
all `edit_posts` + Bunny-configured gated). tus-js-client 3.7.5 vendored
into `assets/js/vendor/`. NOT runtime-verified against a live library.

0.13.0 — Two new private video-step sources, both via `BHY_MediaToken`
(the-self-hosted-self 3.16.0):

- **Bunny Stream (private)** — stores just the video GUID; the player is
  Bunny's iframe, but courses.js drives it through Bunny's player.js
  postMessage API, so **chapters (list + active highlight + click to
  seek), pausing overlays, and the watch threshold all still work**. The
  one thing it can't do is paint markers on Bunny's own scrub bar.
  player.js is only enqueued on a lesson that actually has a Bunny step.
- **Cloudflare R2 (private, signed)** — stores just the object key;
  renders as a real same-origin `<video>` (the Worker streams with range
  support), so it behaves *exactly* like an uploaded file — every
  feature, on-scrubber chapter markers included.

Both options only appear in the step editor once configured in Media &
CDN Setup (`window.bhcMediaSigned`), same rule as the Cloudflare Stream
source. Step schema stores `{source, bunny_guid|r2_key}` flat — no URLs.

0.12.2 — The lesson template renders straight into a full-bleed <main>
on a block/builder theme (Etch — no prose container to inherit a width
from), so on desktop the two-column layout sprawled edge to edge with the
readable content jammed against the left viewport edge and dead space
filling the right half. `.bhc-lesson-layout` / `.bhc-lesson-breadcrumb`
now self-constrain: `max-width: 1200px; margin-inline: auto;
padding-inline: 20px` — the same treatment `.bhc-catalog-wrap` already
uses, so the catalog and the lesson align. Removed the dead
`body.single-bh_lesson .oust-container-narrow` rule (that container only
exists on the old the-self-hosted-self theme).

0.12.1 — Front-end styling fixes found while verifying the ecosystem
against real (Billy Hume) content on a staging copy.

- Lesson sidebar links rendered in the browser-default #00e blue on the
  dark theme: the persistent lesson-nav <nav> sits outside the
  `.bhc-lesson` element, so the file's own theme-isolation
  `a { color: inherit }` reset never reached it. `.bhc-sidebar-lesson-list
  li a` now sets `color: var(--bh-text-dim)` (and a `--bh-text` hover)
  explicitly.
- The first element on each front-end template (catalog kicker, lesson
  breadcrumb, "All courses" back-link) tucked under builder themes that
  render the site header `position: absolute` (Etch), colliding with the
  header menu/hamburger. `--bhc-header-clearance` now chains to the
  shared `--bh-header-clearance` token (the-self-hosted-self / bh-contest
  read the same one; default 72px, set to 0 for static-header themes),
  applied to `.bhc-archive-header` and `.bhc-lesson-breadcrumb`. The
  "All courses" back-link is covered by `.ous-back-link` in front-nav.css.
- The lesson template renders full-bleed into `<main>`, so on phones the
  step cards / sidebar / banners sat flush against both viewport edges.
  Added a 16px `padding-inline` gutter to `.bhc-lesson-layout` /
  `.bhc-lesson-breadcrumb` under 600px, matching the archive header.

0.12.0 — A real design pass over the lesson-building editor (AJ: "make
the currently showing gui more magical, clean and organized" →
"the course/lesson creation tools should all be enhanced to feel
designed and thought about"), plus two confirmed live bugs found while
verifying it: one front-end layout overflow, one broken quiz retry.

Editor GUI. All nine step blocks (text/image/video/callout/checklist/
chord-chart/resource/audio-compare/quiz) now share one authoring shell
— a labelled header with the step's own icon and an at-a-glance
summary ("3 chapters", "Pass 70%", "4 items"), real thumbnails/file
names instead of a bare "File selected (#268)", and a shared empty
state — replacing nine independent stacks of unstyled form fields that
read as one undifferentiated column with no way to tell a Quiz from a
Callout while scrolling. Quiz questions get the same treatment: a real
numbered "QUESTION 1/2/3" card instead of an anonymous stack, the
number recovered live from the block's actual sibling position
(getBlockOrder()) so it stays correct across reorders. The old
`paddingTop: 32px` toolbar-collision hack, repeated inline in all nine
blocks, is gone — the shared header band gives Gutenberg's floating
toolbar something intentional to dock against instead.

Chapters and overlays moved OUT of the Settings sidebar and directly
into the canvas (AJ: "can they not be part of lesson building rather"
— asked live after the previous redesign pass made every other video
setting visible in-canvas except these two, which stayed buried behind
a gear icon a course author had no reason to open). They're real
`<details>` sections under the video picker now, pre-expanded whenever
they already hold content.

Both rows were then streamlined and made more explicit (two more live
asks, back to back): a chapter used to show its timestamp TWICE — a
read-only badge up top and a separately-labelled "Start (seconds)"
field below — collapsed into one editable field. Four stacked,
fully-labelled rows (time / grab-preview button / type / remove) above
any real content shrank to one compact "chrome" row per chapter/
overlay. Every icon-only control in that row (previously relying on a
hover tooltip alone) now pairs its icon with a real word — "Now",
"Remove" — so the row reads correctly at a glance, not just on hover.
Adding a chapter/overlay still captures the live preview's current
playhead in one click ("+ Add chapter at 0:42"), mirrored into React
state so that label never goes stale while scrubbing.

Real contrast bug, caught live via getComputedStyle() rather than
assumed from a screenshot: the post editor's canvas is an IFRAME that
loads the active theme's own front-end stylesheet for true WYSIWYG —
this theme sets a cream/tan body text color for its own dark hero
look, and since nothing in the new shell set an explicit `color`,
every label, paragraph, and timestamp inside it was silently
inheriting that tan against the shell's own white card
(rgb(237,223,203) on rgb(255,255,255) — nearly invisible). One
`color: #1e1e1e` on the shared shell class fixed every descendant that
didn't set its own, including WordPress's own
`.components-base-control__label` (the "TITLE"/"CAPTION" field
labels), which carries no color rule of its own either.

Front-end layout bug, found while saving real demo content into every
step type to verify front-end rendering (AJ: "check width"). The
lesson page's two-column layout (`.bhc-lesson-layout`, step content +
sidebar) carried an `alignwide` class on the assumption it would get
the theme's generic wide-content treatment. This theme instead has its
own bespoke `.oust-prose .alignwide` rule — a
margin-left:50%/transform:translateX(-50%)/width:100vw bleed trick
built for real Gutenberg content sitting directly in prose. Applied to
this bespoke flex layout instead, `100vw` (which includes the
scrollbar gutter) and the percentage margin (which doesn't) disagreed
by the scrollbar's width, rendering the element ~7.5px past the
viewport's actual left edge on every lesson page — confirmed via
`getBoundingClientRect()`, not just eyeballed. Fixed by dropping the
borrowed class entirely and instead widening `.oust-container-narrow`
specifically for this template via its own stable `body.single-bh_lesson`
class — a plain max-width bump inherits the container's already-correct
centering with no transform or viewport-unit math to get wrong.
Verified at both the pane's default width and a 375px mobile emulation:
`document.documentElement.scrollWidth` now equals `clientWidth` exactly
at both.

Quiz retry bug, caught live (AJ: "it seems like if you fail a quiz, it
breaks"). Failing a quiz with attempts remaining (or unlimited
attempts) left the form permanently stuck: the submit button was
disabled and relabelled "Submitting…" before the request went out, but
NO code path ever reset it back — only the login-required, server-
error, exhausted-attempts, and network-error branches did that. A
plain "you got some wrong, try again" result fell through all four and
left a student staring at a "Take another look and give it another
shot" message with a dead, disabled button and no way to act on it
(the answer inputs were also unconditionally disabled the same way).
Fixed with a real `resetQuizForm()` — removes the answer breakdown,
re-shows the question fields, re-enables and un-checks every input,
restores the submit button — wired to a new "Try again" button that
appears exactly where the old submit button silently died. Verified
end to end in a live session, no reload: fail with 0/2 → "Try again" →
confirmed every input/button reset → resubmit with correct answers →
100% pass → "Continue" appears, exactly the flow that was broken
before.

`npx tsc`, `php -l`, PHPStan, and `composer test` all clean.

---

0.11.0 — The second half of "conquer the limitations" (AJ): chapters,
overlays AND watch-progress now all work on YouTube and Vimeo steps,
not just an uploaded file or direct URL. Plus a real pass over the
chapter-authoring GUI.

Provider embeds. A YouTube/Vimeo URL no longer renders as an opaque
<iframe> — it renders as a Plyr provider embed
(`<div data-plyr-provider data-plyr-embed-id>`, resolved by the new
BHC_Render_Lesson::to_plyr_provider()). Plyr mounts the provider's own
SDK against it and exposes the SAME instance API (currentTime,
duration, play, 'timeupdate') it does for an HTML5 video. Verified
live against a real YouTube video: Plyr read its true duration (03:34)
so markers landed at genuinely correct percentages, clicking a chapter
seeked the actual YouTube player to 01:00, and an overlay annotation
fired and paused it — all three features working on an embed, which
this file's own comments previously documented as impossible.

The trade-off is real and was AJ's explicit call: controlling a
YouTube/Vimeo embed is impossible without loading that provider's
script, so those two step types do reach a third-party origin —
unlike every other asset here. Deliberately scoped: an author who
wants zero third-party contact still has the uploaded-file and
direct-URL sources, which stay fully local, and a provider this
plugin has no SDK for still renders as a plain iframe with
chapters/overlays/progress withheld rather than shown as controls
that would silently do nothing.

To make that possible without duplicating logic three ways, courses.ts
now builds ONE media adapter per video step up front (BHCMedia —
currentTime/seek/duration/play/pause/on) and the watch-progress,
overlay and chapter blocks all drive that instead of reaching for a
raw <video> element. When Plyr is present everything routes through
it (uniform across html5 and providers); the raw-element path is only
the fallback for a genuine <video> when Plyr's script failed to load.
Re-ran the full live regression after that refactor — chapters,
annotations and watch-threshold auto-complete all still behave
identically on an uploaded file.

Authoring GUI, rebuilt (AJ: "make that gui a little more magical,
clean and organized"). Each chapter is now a real card instead of a
cramped control row: a badge showing its true PLAYBACK position (not
its array index — rows stay put while you edit, so silently
reordering them under the cursor would be worse), the timestamp
formatted mm:ss, title first, and a Remove aligned right. Panel
headers carry live counts ("Chapters (3)"). Real empty states replace
a bare button under a heading. The preview player moved into its own
always-open panel shared by both chapters and overlays — one <video>,
one ref, instead of two competing for the same one.

The magical bit: the primary action reads "+ Add chapter at 0:42" and
captures wherever the preview is sitting, so the workflow is scrub →
add → title, instead of add → read the time off the player → retype
it as a number. That required mirroring the playhead into React state
(a ref alone renders the label once and then quietly lies, since
scrubbing changes no state). Same for overlays. Two honest inline
warnings added: an untitled chapter says it will be discarded on save
(sanitize_chapters() drops it), and one starting past the video's real
end says so and names the duration, since it renders no marker at all.

Front end: chapter list gained a quiet "CHAPTERS" label and the active
row now gets the accent rail + tint treatment the lesson sidebar's
current-lesson row already uses — one visual language for "you are
here". 1 new PHPUnit test (chapters survive on a url-source step).
`npx tsc`, `php -l`, PHPStan and `composer test` all clean.

0.10.0 — Chapter markers now render ON the seek bar itself, conquering
the limitation 0.9.0 shipped with (AJ: "I'd love to conquer the
limitations"). Native `<video controls>` is drawn by the browser
engine and is unreachable from CSS/JS, so the only way to paint
markers on a scrubber is to supply the control bar — which is exactly
what YouTube/Vimeo do. Vendored Plyr 3.8.4 (MIT, ~113KB min,
assets/js/vendor/) and switched video lesson steps to it.

Deliberately low-risk by construction: Plyr WRAPS the existing
`<video>` rather than replacing it — `player.media` is the same
element, still inside the same `.bhc-video-wrap`, so the annotations
and watch-threshold code needed literally zero changes. Verified that
claim rather than assuming it: added a real watch_threshold (50%) and
a real note annotation to a live lesson, played it through, and
confirmed the annotation still paused playback at its timestamp and
the step still auto-completed at threshold — both under Plyr, then
removed those QA artifacts. Also confirmed the same `<video>` element
survives in the DOM with a settable currentTime.

Self-hosted posture preserved: Plyr's own shipped defaults point
iconUrl and blankVideo at cdn.plyr.io. Both are overridden to
vendored local copies (plyr.svg fetched from the same MIT release;
plyr-blank.mp4 generated locally with ffmpeg as a 2x2px/0.1s silent
clip, 1.6KB) so a lesson page never contacts a third-party CDN, per
CLAUDE.md's standing rule. Plyr is enqueued ONLY on singular
bh_lesson screens, and courses.js declares a real dependency on it.

Degrades honestly: if Plyr's script fails to load, a `window.Plyr`
guard leaves native controls untouched and the chapter LIST still
renders and still seeks — only the on-scrubber markers are lost,
being the one piece that genuinely cannot exist without a custom bar.

One real CSS bug found live while building this: markers were in the
DOM at correct percentages but invisible, because Plyr ships
`.plyr button { width: auto }` — specificity (0,1,1), which beats a
bare `.bhc-plyr-chapter-marker` (0,1,0) and collapsed the empty
marker buttons to 0px wide. Every marker rule is now scoped inside
`.plyr` (0,2,0) to win cleanly without `!important`. Verified live:
3 markers at 0% / 37.66% / 75.31%, each 3x12px, click-to-seek jumps
to the exact chapter start (not wherever the pointer landed — the
handler stops propagation so Plyr's own bar doesn't also scrub), and
both the marker and its list row highlight as the active chapter.
Player accent bound to --bh-accent so it matches the site theme.
Checked at 375px and desktop; no horizontal overflow.

0.9.0 — YouTube-style chapters for video lesson steps, direct request
(AJ: "video courses need chapters, and a visual representation and
navigation via them in the video player, like YouTube"). New
`chapters` attribute on bhc/video ([{ time, title }]), authored via a
repeater panel in the block Inspector (mirrors the existing video-
overlays UI: a shared live preview player, "Use preview's time" per
row to grab the exact timestamp instead of typing it blind). Rendered
as a segmented strip (each chapter's real proportional share of the
runtime, click-to-seek, current chapter highlighted live via
timeupdate) plus a clickable timestamped list beneath it — a
companion to the video, not drawn on the native seek bar itself:
`<video controls>` gives no way to paint markers onto the browser's
own scrubber, and replacing native controls entirely with a custom bar
was a much bigger, riskier rebuild (losing native fullscreen/
accessibility/mobile handling) than this feature warranted. Same
trackable-real-<video>-tag-only constraint as watch_threshold/
annotations — a Cloudflare Stream or oEmbed iframe embed can't be
seeked by this plugin's own JS, so chapters only apply to an uploaded
file or a direct video URL.

Two real bugs found and fixed live while building this, both from the
same root cause (a schema/allowlist this ecosystem validates against
in more than one place, and I only updated one of them at first): (1)
BH_Content's own tree-validator only keeps attributes present in the
schema BH_ContentBridge registers for bhc/video server-side — adding
`chapters` to the JS block's attributes alone let it save into
post_content fine but silently vanish by the time _bhc_steps was
resynced, since that PHP-side schema never knew the key existed. (2)
BHC_Steps::save()'s own explicit per-field allowlist for the video
step type needed the same addition, plus a new sanitize_chapters()
(sorts by time, drops a chapter with no title, clamps a negative time
to zero) mirroring sanitize_annotations()'s existing shape. Also fixed
a third, unrelated pre-existing bug this feature's real content
happened to be tall enough to expose: a mobile-only CSS rule
(`.bhc-step-video { max-height: 220px }`) was unscoped and matched
BOTH the actual <video> tag AND the whole step's own outer wrapper div
(which carries the identical literal class via the bhc-step-{type}
naming convention) — capping the entire step's height on mobile
instead of just the video, with the overflow (caption, Mark Complete
button, and now the chapter strip/list) rendering outside its own
box's contribution to page layout and visually overlapping whatever
came next on the page (confirmed live: the site footer). Tag-qualified
to `video.bhc-step-video`, consistent with an identical fix already
documented elsewhere in this same stylesheet for the desktop rule.

7 new PHPUnit tests (happy path, sort-by-time, empty-title dropped,
negative-time clamped, markup stripped from title, default-empty-array
for a video with no chapters, and confirmed absent entirely on a
Cloudflare Stream step). Verified live end-to-end: authored 3 chapters
in the real block editor, confirmed correct segment proportions
(37.66/37.66/24.69% summing to 100), confirmed click-to-seek and
active-chapter highlighting during real playback, confirmed no
horizontal overflow and no overlap at 375px and 1280px. `php -l`,
PHPStan, and `composer test` all clean.

0.8.2 — bhc/catalog and bhc/course (bhc-blocks.ts) were still
registered as block API version 1, a real WordPress deprecation
("may work as a non-iframe editor") — added `apiVersion: 3` to both.
Found while diagnosing a separate, much bigger live bug (a completely
broken block-editor canvas on bh_lesson/bh_course — see
the-self-hosted-self 3.15.7's changelog for the actual root cause,
which turned out to be unrelated to this). Fixed regardless since it's
a real, easy warning to clear. `npx tsc` recompiled clean.

---

0.8.1 — Real bug found spot-checking the student experience live (as
an actual enrolled subscriber, not an admin bypassing gates): a student
whose tier access lapsed after enrolling still saw a "Continue" button
— both on the account-overview "Continue learning" widget and on the
My Courses portal panel — pointing straight at a course they could no
longer open, landing on that course's own paywall despite the widget
framing it as ready to resume with a real progress percent.
Enrollment and ongoing tier/purchase access are tracked independently
(BHC_Tables::enrollments() vs BHC_Gate::user_can_access_course()), and
nothing checked the second before offering the first. Fixed in both
BHC_PortalPanel (register_user_bar_link() now skips an inaccessible
course entirely; the full My Courses list shows "Access has lapsed —
view options" instead of a dead-end Continue button) and the core
account-overview widget in the-self-hosted-self's class-portal.php
(hides the "Continue learning" section entirely rather than linking
into it, matching that widget's own already-stated "obvious or gone"
rule). Verified live end-to-end as the uxaudit_subscriber fixture:
before the fix, /account/ showed "Continue learning ... 17% complete"
leading to the course's Become a Supporter paywall; after, the widget
is gone and /account/courses/ shows the locked framing instead. `php
-l`, PHPStan, and `composer test` all clean.

0.8.0 — Course-authoring UX pass: closed the real gap a research pass
found in the course edit screen's lesson-order list — "5 steps" was
all a course author could see per lesson without leaving the screen
entirely to open that lesson's own edit page. Each lesson row is now a
native `<details>` disclosure showing the real step list (type icon +
content snippet per step, reusing the existing `describe_step()`
formatter this plugin's own lesson metabox already had) plus a direct
"Edit this lesson" link — no extra request, since the step data was
already being read to compute the existing step-count number.
Deliberately plain HTML/CSS, not a JS widget or Datastar: this is
purely a client-side reveal of already-rendered content, not server
state changing over time, so the lighter tool is the correct one per
this ecosystem's own rendering-layer conventions.

Restructured the row layout after an initial version had a long
lesson title collide with the status pill when it wrapped — the header
(drag handle, title, status, remove) is now a fixed one-line row with
ellipsis truncation, and the step-list disclosure lives in its own
full-width area below it, never squeezed into leftover flex space.

Verified live in a real browser (not just reasoned through) at both
1280px and 375px: real lesson content renders correctly, no horizontal
overflow at either width, title truncation and the disclosure's own
open/close caret work as expected.

0.7.0 — OPEN.md item 20, resolved: it turned out to be a stale tracker
entry, not unstarted work. OUS_Revisions (the-self-hosted-self) already
exists, fully built, with real consumers (bh-contest, bh-monetization-
woo tiers) -- OPEN.md's "not scoped, not started, no data-model
decision" was simply wrong, never updated after that shipped. bh-courses
just wasn't a consumer yet.

Wired bh_course (not bh_lesson -- a lesson's steps genuinely ARE
post_content now via BHC_ContentBridge, so native WP revisions already
cover it for free; a course's real configuration -- lesson order,
tier-gating, pricing, drip/certificate settings -- lives entirely in
postmeta, exactly the same "native revisions capture nothing
meaningful" situation bh_contest already solved for itself). Same
pattern: snapshot the full flat postmeta dump on every save_course(),
a "Version History" side metabox rendering OUS_Revisions' own
ready-made history panel, and a real restore handler.

Real bug caught by testing the restore path against an actual
array-valued field (_bhc_lesson_order) rather than trusting the
pattern copied from bh_contest: get_post_meta($post_id) -- the bulk,
no-$key form -- returns each value as its RAW SERIALIZED STRING, not
auto-unserialized, unlike the single-key form. Invisible for a scalar
meta value (which is probably why bh_contest's own copy of this never
surfaced it), but for a real array it double-serializes on restore,
corrupting the value. Verified live: the naive copy turned a restored
[1,2,3] into the literal string "a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}". Fixed
by fetching each key individually with $single = true, factored into
one shared course_meta_snapshot() helper used by both the save and
restore-then-resnapshot call sites.

0.6.0 — OPEN.md item 21, both halves.

1. Interactive-video variants: 'question' annotations (class-steps.php's
   ANNOTATION_TYPES) were "self-check only, never persisted" -- the
   instant client-side right/wrong reveal is unchanged, but the answer
   now also persists server-side via a new bhc_mark_annotation AJAX
   action, using item 22's sub_index (annotation's own position + 1).
   Deliberately re-scores server-side from the annotation's own stored
   correct_index rather than trusting a client-submitted "correct"
   boolean -- persisting an unverified client claim would let a
   request simply assert it got every question right. Verified the
   core scoring/persistence logic directly against a real lesson with
   a real question annotation (wrong answer -> not complete, correct
   answer -> complete, step's own row untouched either way).

2. Curated LMS inserter palette: a lesson's block editor showed
   WordPress's full native inserter -- paragraph, gallery, columns,
   embed, dozens of core blocks with no place in a lesson step, since
   every real step type is already one of the bhc/* blocks. Now scoped
   via allowed_block_types_all to ONLY bh_lesson (every other post
   type, including this ecosystem's own bh_course/bh_contest, is
   untouched) and returns just the 10 bhc/* blocks -- no core-block
   allowlist bleed-through, since bhc/text and bhc/image already cover
   plain prose/pictures. Verified live: a lesson's
   getSettings().allowedBlockTypes is exactly the 10 bhc/* names; a
   regular Post's is still `true` (unrestricted).

Also fixed in passing, found only because editing the file invalidated
PHPStan's result cache and surfaced it fresh: add_studio_block_editor_
styles() had untyped parameters/return, a pre-existing gap unrelated
to either change above.

0.5.0 — OPEN.md item 22, resolved: in-video annotations get their own
completion tracking (sub_index), not just the step they live in --
AJ's call. bhc_progress gained a sub_index column (DB_VERSION 1.6),
default 0 (the step's own row, exactly what every existing row already
is). Every BHC_Progress method threads an optional $sub_index = 0
parameter, so every existing call site keeps working completely
unchanged -- purely additive. New completed_annotations() is the
per-annotation counterpart to the existing completed_steps().

Real bug caught by testing the migration against an actual database
rather than trusting dbDelta(): widening the UNIQUE KEY from 3 columns
to 4 isn't something dbDelta can do -- it saw the key name already
existed and tried to ADD a duplicate, a genuine SQL error ("Duplicate
key name") that would have silently failed on every real upgrade.
Fixed with an explicit DROP INDEX step (checked via
information_schema, not a version flag, so it's safe to call
unconditionally) before dbDelta recreates it correctly. Verified live:
ran the broken version first and watched it fail exactly as described,
then confirmed the fix runs clean, is idempotent on a second run, and
that annotations complete/fail independently of the step and of each
other while completed_steps() correctly ignores annotation rows.

Also resolves the CHANGELOG.md drift flagged in OPEN.md (header was
0.4.91, this file's newest entry was 0.4.86) -- three intervening
version bumps (0.4.87-0.4.91) still have no recorded entries; not
backfilled here, since reconstructing them needs the real history
behind each bump, which no session recorded at the time.

0.4.86 — Real SEO timing bug, found live during a production-
readiness audit: BH_SEO::set_page_data() was only ever called from
inside the `the_content` filter (a course's single-view render, or
the [bh_course] shortcode/block) — but BH_SEO echoes its tags at
wp_head priority 1, which fires before the_content() ever runs on a
normal page load. Confirmed live: a real course detail page had
zero meta description, zero OG tags, zero JSON-LD despite this
exact code "setting" them every render. Extracted the SEO-setting
logic into BHC_Render_Course::set_seo_data() so it can also be
called from a new template_redirect hook (fires before headers) for
a course's own single-view page — confirmed live afterward: real
meta description, og:title, and Course schema.org JSON-LD all now
render. The existing the_content-time call is left in place for the
shortcode/block-embedded-elsewhere case, where this fix doesn't
apply the same way.

0.4.85 — Real content-integrity bug, found live: several lessons
showed Gutenberg's "Block contains unexpected or invalid content"
error with an "Attempt recovery" button. Root cause: bhc/text and
bhc/quiz's own save() functions (courses-studio-blocks.ts) had both
been changed at some point from producing no static markup to
wrapping their content in a real <div class="wp-block-...">, but any
lesson saved BEFORE that change kept the old, wrapper-less
serialization — which no longer matches what the CURRENT save()
produces, so the client-side validator flags a mismatch. For
bhc/quiz specifically this was a real data-loss risk, not just
cosmetic: its questions are stored as nested child blocks (not an
attribute), and "Attempt recovery" would have discarded them.

Fixed on this install by hand via the REST API (content-only, no
data lost — the existing content/child-blocks were re-wrapped in the
element the current save() expects, nothing regenerated or
discarded), then turned into a real, version-gated migration
(BHC_Activator::maybe_migrate_content(), own option/version counter,
separate from the schema DB_VERSION above) so the same fix reaches
any other install still running old lesson content, automatically,
on next load — not just this one database. Confirmed idempotent
against already-fixed content (re-running produces zero further
changes, no double-wrapping) before wiring it into plugins_loaded.

NOT runtime-verified beyond this session's own live browser checks
(this exact install, this exact content) — the migration's general
correctness against a genuinely different install's stale content
shapes is reasoned through, not separately tested.

0.4.84 — The real gap flagged in 0.4.83's own changelog: a purchase-
only course (the main case the whole feature exists for) had no
actual checkout button anywhere. The catalog badge was discovery
only, and BHC_Gate::render_paywall_notice() delegated entirely to
BHM_Gate::render_paywall_notice($tier_id) — which, when no tier is
set at all, falls all the way through to a bare "This content
requires supporter access" line with zero CTA. BHC_Gate now builds
a real "Buy once — $X" notice with a genuine add-to-cart link
directly, shown alongside the tier notice when both apply ("Or buy
this course outright...") or alone when the course is purchase-only.

Two real bugs caught by checking the live rendered page rather than
trusting the code on paper:
  - BHM_Money::price() was the wrong function for user-facing prose
    — it deliberately returns a bare decimal with no currency symbol
    (built for a labeled form input's value=""), so both the
    catalog badge and the new paywall notice rendered "$29.00" as
    "29.00" with no dollar sign. Fixed in both places to use
    wc_price(), WooCommerce's real formatter, echoed unescaped per
    the standard WC convention.
  - .bhc-paywall had no CSS at all — a pre-existing, previously
    harmless gap (it was only ever a rare "BH Monetization not
    installed" fallback) that became load-bearing the moment a
    purchase-only course started hitting this path as its NORMAL
    paywall. Now styled to match bh-monetization-woo's own
    .bhm-paywall card treatment exactly, so the two notice types
    look like one designed system when they render stacked together.

Verified live end-to-end, not just reasoned through: real add-to-
cart click through the actual rendered button, confirmed the
correct product ("Mastering for Bedroom Producers (Course
Purchase)", $29.00) landed in a real WooCommerce cart, then removed
it again to leave no test data behind.

0.4.83 — Per-course one-time purchase, direct request: "Billy also
would prefer a one time purchase for access to the courses." A
course can now be tier-gated, sold as a one-time purchase, both
(either path unlocks it), or neither (open) — set independently, no
forced either/or.

New: `_bhc_purchase_price_cents` course meta + admin UI field
("One-time purchase" section, class-admin.php), auto-syncing a real
WooCommerce simple product on save via bh-monetization-woo's
existing BHM_ProductSync::sync_object_purchase_product() (the same
generic one-time-purchase sync track/release purchases already use
— genuinely reused, not duplicated). BHC_Gate::user_can_access_
course() checks purchase ownership FIRST, unconditionally, before
either tier check — real bug avoided by reading BHM_Gate::user_has_
tier_access() before assuming this "just worked": that function
returns true immediately when no tier is required, meaning it never
reaches its own purchase-fallback check for a course sold purely as
a one-time purchase with no tier at all, which is the main case this
feature exists for. A new BHM_Gate::user_owns_object() helper (also
DRYing up duplicated SQL that used to be inlined twice in that
class) makes the check reachable independent of tier state.

A real kill switch, not just a feature: BHC_Gate::purchase_feature_
enabled() (filter `bhc_course_purchase_enabled`, default true) — one
`add_filter(..., '__return_false')` call hides the admin field,
stops the save handler from syncing a new WC product, and stops the
catalog "Buy once" badge, without touching an entitlement anyone has
already legitimately paid for (the toggle controls new exposure, not
existing access). Verified LIVE end to end, not just reasoned
through: saved a real course with a $29.00 price through the actual
wp-admin block-editor save flow, confirmed the meta persisted across
a reload, confirmed a real WooCommerce product ("... (Course
Purchase)", virtual, correctly priced) was auto-created, and
confirmed the kill-switch filter correctly hides the field and
restores it cleanly with the saved value intact.

A catalog "Buy once — $X" hint now shows on a locked course card
that has a purchase option (class-render-catalog.php) — discovery
signal only; the actual checkout button belongs on the course detail
page and is the natural next piece of this feature, not yet built.

NOT runtime-verified: an actual completed WooCommerce order granting
the entitlement end-to-end (bh-monetization-woo's on_order_completed()
scope-mapping fix, same commit) — that side was code-reviewed against
the existing, already-proven track/release purchase path (same
function, same SQL shape, a small targeted fix extending a binary
ternary to a real map) but not exercised through a real checkout in
this session.

0.4.82 — Card-group focus mode: hovering a course card in .bhc-
catalog recedes its SIBLINGS into haze (desaturated/dimmed/softly
blurred) while the hovered card stays exactly as sharp as its own
:hover rule already makes it — the third and last mirror of a rule
shipped simultaneously in the admin skin (.ous-cards) and the theme
(WooCommerce product grid), same "Half-Blood Prince" depth-of-field
language, :has()-driven, zero JS, unsupported browsers just don't
get the recede effect. Reduced-motion override written at matching
specificity from the start (a real bug was caught and fixed in the
other two mirrors first — a lower-specificity override there would
have silently lost the cascade).

0.4.81 — Direct request: "replace the letters with icons in the
courses as well. Update sizes as necessary and add subtle hue hints
for the user depending upon lesson type." The lesson stepper's dots
rendered single letters via CSS content (T/I/V/Q/R/!/C, plus 'A/B'
at 8px) — a real legibility problem at 26px, and letters only work
if you already know the legend. Now real Lucide icons (ISC,
vendored to assets/icons/ with its LICENSE) masked via
mask-image, dot sized 26px -> 30px so a 16px icon has room without
crowding the border ring. mask-image (not background-image)
specifically because background-color then drives the color — which
is what makes the second half possible: a per-type hue hint
(--bhc-step-hue) drawn from the site's own themeable --bh-cat-*
palette rather than new invented literals, so a re-themed site
re-themes these too. Deliberately subtle — the hue tints only the
small icon INSIDE the dot, never the dot's fill/border, so the
existing done/current/disabled states stay the primary, unambiguous
progress signal and type stays a secondary at-a-glance hint. Text
steps keep the neutral default (most common type by far; coloring
them too would make the whole stepper read as noise). No
accessibility regression: class-render-lesson.php already emits a
real aria-label AND title ("Step N: Type") per dot independent of
the visual glyph, verified before changing anything.

0.4.80 — Real UX gap found live: clicking "Courses" itself (the
synced menu group's own label, not a child course) went nowhere —
OUS_MenuSync::sync_group() (the-self-hosted-self 3.10.23) now takes an
optional real URL for the group parent; resync_course_menu()
(class-admin.php) passes home_url('/courses/'), this CPT's own
native archive (has_archive => 'courses', class-post-types.php),
the same URL convention BHC_Gate/BHC_PortalPanel already used
elsewhere.

php -l clean, scoped PHPStan level 6 clean. NOT runtime-verified
against a live install by this commit alone.

0.4.79 — Real product decision, caught live: a course with no
required tier was fully viewable by a logged-OUT visitor — clicking
"Mark complete" or a quiz submit button just failed with a confusing
generic error, because the tier gate (BHM_Gate::user_has_tier_access)
only asks "is the tier requirement satisfied," which is vacuously
true when no tier is set at all. Login and tier are different
questions this plugin was conflating.

BHC_Render_Course::render_course() and BHC_Render_Lesson::
render_lesson_steps() now check the-self-hosted-self's new OUS_Visibility
(3.10.22) FIRST, separately from BHC_Gate's tier check — a course
defaults to requiring a logged-in account to view at all, same as
anything ordinarily meant for an audience rather than an anonymous
visitor. A new "Public — anyone can view without logging in"
checkbox on the course's own Login requirement metabox section is
the explicit per-course opt-out (class-admin.php,
OUS_Visibility::checkbox_field()/save_from_request()). Deliberately
NOT applied to bh-contest — a contest's whole design depends on
being publicly viewable/shareable; that's a separate, explicit
product decision left for later, not a side effect of this fix.

Also fixed, found while double-checking the "Mark complete and
continue" flow specifically wasn't ALSO broken in some other way:
courses.ts's bhc_mark_complete/bhc_submit_quiz response handlers only
special-cased a bare "-1" (a stale-nonce wp_die()) with a clear "log
in" message — admin-ajax.php's actual response for a logged-out
visitor hitting an action with no wp_ajax_nopriv_* handler is a bare
"0", which fell through to a generic "Something went wrong." Now
treated the same as "-1". Mostly defense-in-depth now that the lesson
itself requires login to reach at all, but still a real gap for a
session that expires mid-lesson. Recompiled via `npx tsc`.

php -l clean, scoped PHPStan level 6 clean. NOT runtime-verified
against a live install by this commit alone — verify by viewing an
ungated course/lesson while logged out (should show a login prompt,
not the real content), then toggling "Public" on and confirming it
opens back up.

0.4.78 — Added a help tooltip (BHY_UI::tip(), the-self-hosted-self 3.10.15) to
the "Gate by tier price rank" select on a course's Supporter access
metabox, clarifying the price-rank rule: selecting a tier here grants
access to that tier AND every higher-priced tier, not just the exact
one picked — not obvious from the select alone. Part of this
session's first pass at in-context tooltips, not a full sweep.

0.4.77 — Fixed the course seed's placeholder video URL. Found live on
billyhume.wasmer.app while verifying the video step end-to-end:
Google's old public sample bucket
(commondatastorage.googleapis.com/gtv-videos-bucket) now returns 403,
so the seeded video step rendered a real <video> element with real
controls (confirmed the actual bh-courses video-step renderer and
player chrome are correct) but the source itself failed to load.
Swapped to w3schools.com/html/mov_bbb.mp4, confirmed loadable
(loadedmetadata fires, duration=10.03s) directly from the deployed
site before committing. Not this plugin's bug — a dead third-party
URL — but worth fixing since a demo course with a broken video looks
like a real defect to anyone clicking through it.

0.4.76 — Follow-up to 0.4.75's fleshed-out course seed: two display
strings in BHC_Debug (the seed button's label and its post-click
confirmation message) still hardcoded "2 lessons" from before that
change, even though seed_course() itself was already correctly
building all 5. Caught live on billyhume.wasmer.app right after
deploying 0.4.75 — the seeded course itself was correct (verified:
all 5 real lessons existed), only the two UI strings were stale.
NOT a functional bug, cosmetic only.

0.4.75 — Fleshed out the Debug Tools course seed (BHC_Debug::
seed_course()) from a thin 2-lesson/4-step demo into a real 5-lesson
showcase course exercising most of the step-type vocabulary: text,
image, a direct-URL video step (real public sample MP4 — this was
the only seeded content that actually exercised the video step
renderer end-to-end before now), quiz, callout (all three variants),
checklist, and a chord-chart. Deliberately did NOT seed 'resource' or
'audio-compare' steps — both require a real, non-zero attachment_id
(BHC_Steps::save() silently drops the whole step otherwise, by
design, since a resource/comparison with no real file "has nothing
to offer"), and this seed tool has no real media library asset to
attach without also faking an upload. Left as a known gap rather
than seeding a placeholder id that would just get silently dropped.
NOT runtime-verified against a live install by this commit alone —
verify by clicking "Seed a complete test course" on a real site and
confirming all 5 lessons/every step type renders.

0.4.74 — Dead-code sweep (Phase 4, shipmonk/dead-code-detector v0.5.1
against the full ecosystem, manually triaged finding-by-finding
before deleting anything). Removed BHC_PostTypes::step_count() — a
genuinely uncalled aggregation helper (lesson_count() nearby is real
and used; step_count() had no caller anywhere, only a comment
reference in class-progress-admin.php). Confirmed a look-alike
candidate, BHC_Render::render_quiz_review(), is NOT dead despite no
current caller — its own docblock explicitly documents it as a
deliberate one-line backward-compat delegate preserving this class's
public API surface post-refactor (0.4.8), kept on purpose. NOT
runtime-verified against a live install; this is a pure removal of
unreferenced code, same risk shape as the rest of this sweep.

0.4.73 — Real bug fix surfaced by the-self-hosted-self's own final PHPStan
level 6 brick (typing OUS_Debug::button() with a real `: void`
return): class-debug.php here was calling it as `echo
OUS_Debug::button(...)` at 5 call sites, double-printing every debug-
tools button on this plugin's own Debug Tools section — button()
already echoes its own markup internally, the wrapping `echo` was
pure extraneous output. Fixed by dropping the `echo`. Also fixed:
class-content-bridge.php's migrate_lesson() was declared `: bool`
but returned BH_Content::save()'s real array result unchanged (a
dangling type mismatch from the bh-courses PHPStan brick, 0.4.72,
only surfaced once the-self-hosted-self's BH_Content::save() itself got a
precise array-shape return type) — cast to `(bool)` at the return,
matching its one caller's actual ignored-return-value usage. NOT
runtime-verified against a live install; smoke-test the Debug Tools
page to confirm buttons render once, not twice.

0.4.72 — Ecosystem quality Phase 2, brick 12/13: bh-courses is now
clean at PHPStan level 6 (native return/parameter types + precise
array-shape PHPDoc throughout every file in includes/, no shortcuts).
32 files, ~584 findings. Covers class-progress.php (the largest single
file in this brick — enrollment, per-step completion, quiz scoring/
answer snapshots, course-completion detection), class-admin.php
(course/lesson authoring, duplication, list-table columns), class-
render-course.php, class-gate.php (tier gating + drip scheduling),
class-sessions.php (instructor availability/booking), class-
reviews.php, class-render.php, class-post-types.php, class-
achievements.php, class-progress-admin.php (batched Student Progress
N+1 fix), class-content-bridge.php (the BH_Content block-tree bridge
for lesson authoring), class-debug.php, class-steps.php (step
sanitization/quiz scoring), class-render-catalog.php, class-test-
suite.php, class-comments.php, class-render-lesson.php, class-
privacy.php, class-certificates.php, class-video-settings.php,
class-portal-panel.php, class-crm-integration.php, class-blocks.php,
class-sessions-admin.php, class-leaderboard.php, class-instructor-
notes.php, class-sessions-portal.php, class-style-surface.php,
class-share-cards.php, class-nudges.php, class-drip-nudges.php,
class-activator.php, class-lesson-surface.php. No behavior changes —
a handful of esc_html()/get_posts() call sites needed an explicit
(string) cast once their param picked up a native type, and one dead-
code simplification (a redundant `count($steps) > 0` check where
$steps was already provably non-empty). Scoped bh-courses PHPStan
level 6 check and the full 12-plugin level 5 ecosystem check both
come back clean.
NOT runtime-verified against a live WordPress+MySQL install.
0.4.71 — TypeScript pilot: converted the two remaining large/risky
files that were deliberately deferred in the previous round —
courses-studio-blocks.js (Gutenberg block registration for the
lesson-authoring block types; `wp` typed loosely as `any` given the
size of the wp.components/blockEditor surface it touches, real types
everywhere else) and courses.js (the full lesson stepper: step
navigation, video watch-progress, interactive video annotations,
quiz submission — real types throughout, no @ts-nocheck escape
hatch, since a build-time check that doesn't actually check
anything defeats the point of doing this). Added "DOM.Iterable" to
this plugin's tsconfig.json lib list (needed for `for...of
formData.entries()`, a real gap the previous tsconfig didn't need
until this file). Every compiled .js diff was reviewed line-by-line
against the original — the only behavioral deltas are type-safety
shims (`?? fallback` on dataset reads, explicit String() coercion
into URLSearchParams, which already stringified those values at
runtime either way) — no logic changed.
NOT runtime-verified against a live browser this session.
0.4.70 — TypeScript pilot: this plugin's FIRST pass (no assets/ts/
existed before this pass) — added tsconfig.json (identical shape to
every other plugin's) and build:bh-courses/watch:bh-courses npm
scripts in the repo-root package.json, then converted admin.ts
(lesson-order drag-reorder — also deleted ~220 lines of dead legacy
multistep lesson-builder code that self-guarded on a container that's
been absent since lesson authoring moved to the real Gutenberg block
editor), sessions-admin.ts (FullCalendar month-view render), and
bhc-blocks.ts (bhc/catalog, bhc/course block registration). Same
posture as every other plugin's TS pilot entry this session: plain
`tsc`, no bundler, compiled .js committed, run
`npm run build:bh-courses` after editing any .ts file.
courses.js (755 lines) and courses-studio-blocks.js (708 lines)
deliberately NOT converted this pass — flagged for a dedicated future
pass with real browser verification, not attempted blind.
NOT runtime-verified against a live browser this session.
0.4.69 — PHPStan round 2 (this plugin went from 38 errors to 0). 37 of
the 38 were the same one cause: FPDF (the-self-hosted-self/vendor/fpdf/fpdf.php,
used by class-certificates.php for certificate-of-completion PDFs) is
a real, vendored library, just not composer-installed, so PHPStan
couldn't resolve it at all — added to phpstan.neon's scanFiles so it's
now actually type-checked instead of reported as one giant unknown-
class block. The other two: a redundant `?? []` on WP_Query::$posts
(non-nullable per the stub) in class-render-catalog.php, and the same
redundant class_exists('BH_ShareCard') re-check pattern already fixed
in bh-contest this same pass — an earlier wp_die() a few lines above
already guarantees the class exists.
NOT runtime-verified against a live install — confirmed via a real
`vendor/bin/phpstan analyse` run. `php -l` clean.

0.4.68 — Real bugs found by a proper `composer install && vendor/bin/
phpstan analyse` run (repo-root phpstan.neon, level 5 — this codebase's
PHPStan/TS pilot bootstrap was written in a sandbox with no GitHub
access to actually run it; this is the first real run). (1) class-
content-bridge.php's Debug Tools "rebuild lesson content" action called
`check_admin_referer($action, $query_arg, false)` — that function only
takes 2 params and, unlike check_ajax_referer(), has no non-dying mode:
an invalid nonce always hard wp_die()s regardless of the (silently
ignored) third argument. Switched to wp_verify_nonce() so an invalid/
missing nonce is a graceful no-op instead of a hard site error. (2)
class-debug.php's three seed helpers (seed_course/seed_lesson and the
tier-seeding branch) checked `is_wp_error($id)` on wp_insert_post()'s
return value — wp_insert_post() only returns WP_Error when called with
$wp_error=true (4th arg), which none of these calls do; it actually
returns 0 on failure, so the error branch could never fire. Changed to
a falsy check. `php -l` clean on both files. Runtime-verified live
against localhost:10008: the Debug Tools "populate lesson content
from steps" action now shows "Rebuilt 7 lesson(s)" instead of a hard
nonce-failure die.

0.4.67 — OSS-integration master plan Phase 6 follow-up: Cloudflare
Stream wired into the video step as a real third source alongside
upload/url (class-steps.php's own comments had already named this as
the intended use case for the 'url' branch — this gives it a real,
separate source value instead, since a Stream video UID and a raw
embed URL are different enough shapes to validate/render distinctly).
A step gains 'cloudflare_stream'/'stream_uid' (class-steps.php,
validated as a real 32-char hex UID, never trusted free text);
class-render-lesson.php renders Cloudflare Stream's own iframe embed
(the simple, zero-extra-JS first cut — an hls.js-backed <video> via
OUS_MediaWizard::enqueue_hls_js() can follow once this is proven);
courses-studio-blocks.js's Source picker only offers the option when
Tier B is actually enabled (OUS_MediaWizard::tier_b_enabled(),
localized via class-content-bridge.php's new wp_localize_script()
call as window.bhcMediaTierB) — an install that never opted into
Tier B never sees it. class-content-bridge.php's bhc/video schema
gained the matching 'stream_uid' key so it round-trips through the
block-tree<->legacy-steps conversion.
Explicitly NOT built this pass: an in-plugin "upload straight to
Cloudflare Stream" flow — v1 requires pasting back a UID from a
manual upload via Cloudflare's own dashboard/API. A real upload flow
(Stream's TUS-resumable-upload protocol, progress UI) is a separate,
bigger piece, flagged honestly rather than attempted here.
bh-video and bh-streaming remain explicitly out of scope for this
pass too (see ROADMAP-hyperpress-migration.md's sibling plan doc /
this session's own research: bh-courses was the only plugin with an
existing source-discriminator concept to extend; the other two would
each need that introduced from scratch).
NOT runtime-verified against a live WordPress+MySQL install this
session, and specifically never tested against a real Cloudflare
Stream account/UID. `php -l` clean; `node -c` clean on
courses-studio-blocks.js.

0.4.66 — Phase 5 of the OSS-integration master plan: 1:1 session
scheduling, the "smallest real version" from ROADMAP-lms-instructor-
student-depth.md §1 — an instructor publishes open time slots
(class-sessions-admin.php, new "Sessions" submenu under Courses), a
student books one from a new "Sessions" portal panel (class-sessions-
portal.php). New bhc_sessions table (class-sessions.php,
BHC_Sessions::activate()/maybe_upgrade()) — a slot's lifecycle
(open -> booked -> completed/cancelled) is its own small state
machine, same "a table when it doesn't fit post/meta" convention
bh-crm's bhcrm_notes/bhcrm_projects already established. Booking uses
the exact same one-row-conditional-UPDATE claim idiom as bh-feedback's
BHF_Queue::claim() — status flips open -> booked only if it's STILL
open right then, so two students can't double-book the same slot.
Decisions locked in this session (AJ): single-instructor v1
(instructor_id defaults to whoever holds bhcore_manage_students, no
picker UI); real OUS_Notifications on booking AND cancellation; a
slot CAN be tied to a course (optional picker in the admin create-
slot form, per the roadmap doc's data model); student self-cancel is
allowed but blocked within a configurable cutoff (default 24h,
'bhc_session_cancel_cutoff_hours' filter) — staff cancellation has no
such restriction.
New vendored dependency: FullCalendar v7.0.2 (MIT, real bytes from its
official GitHub release, assets/js/vendor/fullcalendar.global.js) —
the free Standard tier's all-in-one global bundle only, deliberately
no resource/timeline views (those need a paid Premium license, and
aren't needed for a single-instructor calendar). Renders a read-only
month view on the Sessions admin screen from server-rendered JSON —
plain vanilla JS (assets/js/sessions-admin.js), not Datastar, since
there's no live server round-trip involved in that render.
NOT runtime-verified against a live WordPress+MySQL install this
session. `php -l` clean on every touched/new PHP file; the vendored
FullCalendar bundle's JS syntax was checked with `node -c`.

0.4.38 — ecosystem depth-pass Tier 1c: BHC_PortalPanel registers the
first real bhi_user_bar_links contributor (the-self-hosted-self's new
class-user-bar.php) — "Continue: <course title>" with a live percent
micro-state, only when there's an actual in-progress enrolled course
to continue, never a placeholder.

0.4.37 — LMS depth-of-magic Phase 4 (final phase): ecosystem-wide
achievement surfacing. BHC_Achievements now feeds the real
bhi_profile_badges filter (the-self-hosted-self's public-profile page), and a
new opt-in BHC_Leaderboard shows a course's top quiz scorers —
rank/name/score rows with emoji medals for the top 3, mirroring
bh-contest's own reveal display without sharing code with it. Off by
default per course, same posture as Lesson Q&A/certificates.

0.4.36 — LMS depth-of-magic Phase 2c: three new step types (checklist,
chord/tab chart, audio A/B compare), scoped directly from AJ's own
answer on what's actually missing for THIS content (music production/
songwriting courses), not a generic "add more block types" guess. All
three non-blocking, same Mark-complete-and-continue pattern as every
other non-quiz step.

0.4.35 — LMS depth-of-magic Phase 3: cross-course mastery. A new
bhc_achievements table (BHC_Activator 1.5) and BHC_Achievements class
award a small, fixed set of real, persistent badges — first quiz
aced, completed a course with distinction, 3 courses mastered —
hooked off events that already exist (mark_step_complete()'s quiz-
score path, the bhc_course_completed action), surfaced on the My
Courses portal panel. First genuinely new schema this plugin's
depth-of-magic pass has needed.

0.4.34 — LMS depth-of-magic Phase 2b: a real hero treatment for a
course's own landing page. A cover image now earns a full-width banner
with the title overlaid on a gradient scrim (only when a cover is
actually set — obvious-or-gone, no hero styling forced on a plain
title); the instructor moved out of the flat meta line into its own
pulled-forward row with a larger avatar. Also fixes a real, caught-live
duplicate: the theme's own core/post-featured-image block was still
printing the same cover image, undecorated, directly above this new
hero.

0.4.33 — LMS depth-of-magic Phase 2a: a new `bhc/callout` step type for
visual density within a lesson (a "here's the key idea" / "watch out for
this" moment), three fixed variants (tip/note/warning) rather than a
free-text style field, same non-blocking Mark-complete-and-continue
pattern as every other non-quiz step.

0.4.14 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 4b: real video
progress tracking. A course creator can now set a per-video-step "require N%
watched" threshold (bhc/video's new watch_threshold attribute, Studio block
RangeControl) — 0 keeps today's behavior (any playback + a manual click
completes it) unchanged.

0.4.13 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 4a: certificate of
completion. Studied LifterLMS's own Achievements/ Engagements architecture
first (trigger→handler dispatch table) before writing anything — concluded
WordPress's own `bhc_course_completed` action (already fired exactly once per
user/course by class-progress.php's maybe_fire_course_completed()) already IS
that extension point, so no bespoke "engine"/registry class was added; see
class-certificates.php's own docblock for the full reasoning.

0.4.8 — 2026-07-12 — SOLID/SRP QA pass on class-render.php: a single 589-line
class was rendering the catalog, the course detail page, AND the lesson step-
walker/quiz UI — three genuinely separate concerns. Split into new class-
render-catalog.php (BHC_Render_Catalog), class-render-course.php
(BHC_Render_Course), and class-render-lesson.php (BHC_Render_Lesson) — pure
moves, byte-for-byte identical logic, no behavior change.

0.4.2 — BHC_TestSuite gained real DB-backed coverage for quiz answer storage
(mark_step_complete()/stored_answers() round-trip, latest- attempt-only retry
semantics, the NULL-vs-0 sanitization behavior) and the course catalog's
search/sort (real fixture posts, cleaned up after each run) — both previously
untested. Verified 2026-07-19: all 36 assertions across this suite (the two
pure-logic tests/ files plus these DB-backed ones) pass against a real
install — the earlier "not yet executed" caveat is resolved.

0.4.1 — first OUS_DebugLog call anywhere in this plugin:
BHC_Progress::mark_step_complete()'s DB write is now checked — a failed write
previously still let the student-facing flow report "step complete" with the
failure completely invisible. Standing caveat: reasoning/brace-balance-checked
only.

0.3.0 — LMS lesson-flow authoring wired onto BH_Studio/BH_Content (see LMS-
AUTHORING-DESIGN-PLAN.md): bhc/* block types registered with the Studio
canvas, bhc/quiz promoted to a real container of bhc/quiz-question child
blocks, and the legacy steps-repeater metabox replaced with a link into
Content Studio (closing the dual-write hazard the design doc flagged — see
class-content-bridge.php and class-admin.php). 0.3.1 — six queued LMS UX fixes
from an honest-assessment pass, all additive/routine (no architectural
changes): a course-level "Continue/ Start/Review" CTA on the catalog card +
course page (BHC_Progress::first_incomplete_lesson(), class-render.php); "Next
Lesson →" navigation once a lesson's last step completes, instead of silently
stranding the student (class-render.php + courses.js); a step-walker back
button, including revisiting a passed quiz in a read-only review state (note:
this reviews PASS/FAIL + question list only, not the student's exact original
answer choices — bhc_progress never stored the submitted-answers array, and
adding that is a real schema addition deliberately left out of this pass);
per-step content labels replacing the type-only summary in the lesson metabox
(BHC_Admin::describe_step()); a "Preview as student" link next to the Studio
button; and a manual-override "mark complete" action on the Student Progress
admin page for the ordinary support-request case
(BHC_ProgressAdmin::maybe_handle_override()).
QA fix (2026-07-21, caught live during Phase 1 LMS-v3 video-overlay
verification): this constant is what actually cache-busts every
enqueued JS/CSS file (wp_enqueue_script/style's $ver arg) — the
"Version:" header comment at the top of this file is a SEPARATE
string that WordPress reads for the plugin list/updates, not this.
The two drifted across this entire session's LMS depth-of-magic
pass: the header comment was bumped at every phase (0.4.33-0.4.37),
this constant was not, so every JS/CSS change since 0.4.32 was
silently served stale from any browser that had already cached the
old file — confirmed live (a shipped courses.js feature simply
didn't run, traced to the enqueued <script> tag still reading
?ver=0.4.32). bh-contest's BH_VER/the-self-hosted-self's OUS_VER don't have
this problem only because they happened to stay in sync by
discipline, not because either is derived from the header
automatically — same manual-duplicate-constant convention, same
risk. Bump this constant in the SAME edit as the header from now on,
not as an afterthought.

0.4.28 — retry-audit pass, AJ's own standing ask (assets/js/courses.js): (1)
"Mark complete" step-completion now has real retry-with-backoff (matching own-
ur-shit's class-reports.php reference pattern) — previously had NO .catch() at
all, so a dropped connection silently failed with zero feedback. Safe to
retry: the server side is an upsert on lesson_id+step_index, not an insert-
only log. (2) Quiz submission gets the OPPOSITE fix — the submit button is now
disabled the instant the form submits (re-enabled only on a real failure),
since a quiz submission burns a real attempt server-side per call and was
previously vulnerable to a double-submit (double-click, or a slow connection)
silently costing a student an attempt.

0.4.27 — ROADMAP-discoverability.md Section 3's own per-content-type
schema.org plan: BHC_Render_Course::render_course() now calls
BH_SEO::set_page_data() with a real Course/CourseInstance JSON-LD block (name,
description, image, provider, instructor) — the second real BH_SEO consumer
after BHI_PublicProfile's Person block, and the first for actual content
rather than an identity page. class_exists()- guarded; does nothing if own-ur-
shit's BH_SEO isn't present. Verified live: a real published course rendered
exactly one JSON-LD Course block and one canonical tag (no duplicate-canonical
regression).

0.4.26 — First real contributor to the-self-hosted-self's new shared Metrics dashboard
(OUS_Metrics, class-metrics.php): three widgets in includes/class-crm-
integration.php (Enrollments, Course completions, Avg. quiz score), built in
tandem with that dashboard per AJ's own "foundational infrastructure, not a
bolt-on" instruction. Reads bhc/enroll and bhc/course_completed events already
flowing — no new instrumentation added. class_exists()-guarded; does nothing
if the-self-hosted-self's metrics class isn't present.

0.4.25 — Whole-course duplication ("Duplicate this course as a template") — a
fresh audit against Teachable/Thinkific/Kajabi/ LearnDash/LifterLMS flagged
this as the most-common missing instructor tool: only per-lesson duplication
existed before this. New "Duplicate" row action on the Courses list
(course_row_actions()/ handle_duplicate_course()) clones the course post, its
catalog/ gating/certificate/share-card meta, its categories/topics/featured
image, and every one of its lessons — each lesson gets its own independent
clone (same core copy logic handle_duplicate_lesson() already uses, never
shared IDs between two courses), rebuilt into a fresh _bhc_lesson_order for
the new course.

0.4.15 — ROADMAP-ux-polish-and-feature-parity-2026-07.md 5a: WYSIWYG
shortcode-to-block conversion, completing the pass across all four plugins
(bh-monetization-woo 0.4.9-0.4.11, bh-contest 3.5.0, bh-streaming 0.5.4). Two
new blocks via wp.serverSideRender (class-blocks.php, assets/js/bhc-
blocks.js): 'bhc/catalog' ([bh_courses], no attributes) and 'bhc/course'
([bh_course], an Inspector course picker).
