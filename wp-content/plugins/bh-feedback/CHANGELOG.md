# Changelog — BH Feedback

Moved out of `bh-feedback.php` on 2026-08-25 — this plugin never got the same extraction the-self-hosted-self did on 2026-08-23, so its version history sat in source comments until now. See `CONVENTIONS.md` for why version history lives here and in git rather than in source.

Entries are newest-first, exactly as written in-file. Nothing reworded or dropped.

---

0.2.1 — The submit form's "Wallet balance: $0.00" line is a
.bhi-datum--inline figure (value + caption) instead of a bare bold
sentence. The tier picker inherits the portal's shared choice-row
and fieldset styling.

0.2.0 — Tier 3 item 16: timestamped waveform audio annotations, the
"third tier" this plugin's own docblock had deferred since v1. Shipped
as a FEATURE of the existing 'detailed' tier (explicit decision) rather
than a new priced tier — a detailed review can now carry timestamp-
anchored notes on the waveform in addition to its plain-text body, same
price. Authorship rule (explicit decision): only the request's current
reviewer can drop a new top-level marker; the submitter can reply
under an existing marker but can't start new ones — a paid, one-expert
relationship, not an open thread.

New `bh_feedback_annotations` table (DB_VERSION 1.1) — top-level rows
carry their own timestamp, replies (parent_id set) inherit the
parent's for display and store none of their own. New `BHF_Annotations`
class owns all authorization (tier check, reviewer-of-record check,
parent-ownership check) behind one `create()` entry point, called from
a new `wp_ajax_bhf_add_annotation` handler.

Front end: first JS this plugin has ever shipped (`assets/ts/
feedback.ts` -> `assets/js/feedback.js`, plain `tsc`, no bundler — same
convention as bh-courses). Decodes the submitted track client-side via
the Web Audio API purely to draw a peaks waveform (no server-side peak
generation, no new dependency); markers render as an absolutely-
positioned button overlay for real keyboard-focusable hit targets
rather than raw canvas coordinate hit-testing. Enhancement-only: no
Web Audio API support (or a decode failure) just leaves the canvas
blank — the existing plain `<audio controls>` element on the same card
is the real fallback either way.

Real bug caught by this class's own real-DB verification pass, not by
reasoning: `create()` read `$wpdb->insert_id` AFTER calling
`OUS_Notifications::notify()`, which does its own internal INSERT —
silently clobbering `$wpdb->insert_id` before this method's own return
statement read it. A reviewer's first marker was being handed back
with the *notification* table's row id, not its own — invisible until a
reply was attempted against that wrong id and failed with "marker no
longer exists." Fixed by capturing `$wpdb->insert_id` into a local
variable immediately after this table's own INSERT, before anything
else touches `$wpdb`.

Verified end-to-end against the real local WP+MySQL install: schema
migration (DB_VERSION 1.0 -> 1.1), the full authorization matrix
(wrong tier rejected, non-reviewer top-level marker rejected, reviewer
marker accepted, submitter reply accepted, unrelated user's reply
rejected, reply against a nonexistent parent rejected), and the
resulting thread tree shape. `npx tsc --noEmit` clean; the committed
`feedback.js` is a fresh, unedited compile of `feedback.ts`.

0.1.5 — Ecosystem quality Phase 2, brick 3/13: added native return
types and parameter types across all 9 includes files (60 findings,
both mechanical level-6 categories). One small real fix: render_
shortcode()'s `ob_get_clean()` can return `false` (empty output
buffer stack); cast to `(string)` so the declared `: string` return
type is actually honest rather than just asserted. Everything else
purely additive typing, no behavior change. This plugin is now
clean at PHPStan level 6 in isolation.
NOT runtime-verified against a live install.

0.1.4 — This plugin's first PHPStan pass (newly added to
phpstan.neon's scanned paths this round). One finding: get_userdata()
needed an int, not the string post_author property it was given
directly (class-admin.php).
NOT runtime-verified against a live install — confirmed via a real
`vendor/bin/phpstan analyse` run. `php -l` clean.

0.1.0 — first build. Ecosystem depth-pass Tier 1d, per
ROADMAP-feedback-and-courses-v2.md's own scoping: v1 ships quick-take
+ detailed written feedback only; the timestamped-audio-annotation
tier is explicitly deferred (architecturally the same "pause a step,
show an overlay" mechanic already shipped for bh-courses' video
annotations this session — worth reusing that UI pattern directly
when that tier is actually built, not inventing a second one).

Reviewer model (AJ's own call, ecosystem depth-pass Tier 1d design
pass): a SELF-SERVE CLAIM QUEUE, not admin-assigned — any account
holding the `bhcore_review_submissions` capability can browse open
requests and claim one. That capability GATES THE WHOLE REVIEWER
ROLE (claiming, reviewing, and seeing the queue are one bundle), not
just queue visibility — matches the existing Roles-as-jobs UI
(the-self-hosted-self's OUS_RoleAssignment): granting the "Reviewer" job is
granting everything reviewing needs, nothing more to wire up
separately.
