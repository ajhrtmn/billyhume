# AUDIT SYNTHESIS — Ecosystem quality audit, 2026-07-25

Reconciles the 16 individual task reports (`AUDIT-2026-07-*.md` at this root) produced by the granular per-task audit run this session. This is the cross-plugin summary — see each individual report for full file:line detail. Not a re-audit; pure reconciliation of what the 16 tasks already found.

## The one finding that changes how you should read STATUS.md

**`bh-feedback` is not "entirely unbuilt, still just a plan."** It's a real, working v1: activation, CPT, pricing, submission flow, an atomic claim/release/complete queue with concurrency guards, a portal panel, and a real test suite. `bhcore_review_submissions` (the capability STATUS.md said was "scaffolded, used by nothing") is actively wired in 4 places. **Action: `STATUS.md`'s bh-feedback section needs a correction pass** — this is exactly the kind of stale "confirmed unbuilt" claim STATUS.md's own "second correction pass" note already warned about recurring.

## Cross-plugin patterns (things that showed up more than once)

1. **Backend state that isn't surfaced to the person who cares about it, 2 instances.** bh-feedback's claim/release/complete queue is solid underneath but invisible to the submitter (no distinct "open" vs. "claimed" styling, claim timestamp never shown). bh-crm's Activity section similarly has real underlying data (event timeline + filter summaries) that reads as two disconnected silos rather than one feed. Different plugins, same underlying failure mode: real backend work that stops one layer short of actually reaching the user.

2. **The "it just works" wizard principle is unevenly applied to newer code.** `bh-streaming`'s `BHS_PROWizard` is a real counterexample — despite being modeled on `OUS_MediaWizard` in its own doc comments, it's a single flat page, not a step-by-step wizard, and links to PRO homepages rather than actual signup pages. This is worth flagging ecosystem-wide: the principle is well-articulated in `VISION.md` but isn't yet a reflex every new integration follows.

3. **Doc-hygiene debt recurs on the same files.** `bh-crm/class-projects.php`'s top-of-file status comment was already corrected once (per STATUS.md 07-21) and has drifted stale again in a new way (claims Phases B-E unbuilt; they shipped). Worth a standing habit: when a status comment gets corrected, that file is evidently a magnet for the problem recurring — maybe worth a code comment pointing at STATUS.md as the source of truth instead of a local claim that can drift again.

4. **Empty-state fixes are landing unevenly across the three plugins originally flagged (07-13).** bh-courses: mostly fixed (shared component, filtered-vs-zero distinction; only the admin-CTA-on-zero-state is still missing). bh-streaming: partially fixed (shared component now used, but the specific named "repeat the CTA" gap is still open, and the Playlists tab wasn't migrated at all). Net: real progress, but bh-courses is measurably ahead of bh-streaming on the identical fix.

5. **Two real security/correctness bugs, both isolated (not a pattern, but both worth prioritizing over the polish items above):** the CSV-injection risk in `bh-streaming`'s PRO Wizard royalty export, and the dead server-side video-size enforcement in `bh-courses` (`class-video-settings.php:89`, keys off the wrong array key).

## What actually got fixed since the last full audit (07-08/07-13) — don't re-litigate these

- `bh-contest`'s 1,145-line `class-admin.php` God-class: fully resolved, split into 5 focused classes.
- `bh-crm`'s raw-SQL reach into `bhi_profiles`: fixed, now routes through `BHI_Profiles`.
- `bh-contest`'s branding-override metabox visual-weight gap: fixed, more thoroughly than originally suggested.
- `bh-streaming`'s two prior bug fixes (unconditional `inline_css()` call, recommendations monetization-bypass): both confirmed still in place, no regression.
- `bh-courses`' `BHC_VER`/header version-sync regression: confirmed not recurred.

## Still-open items worth prioritizing next (pulled from all 16 punch-lists, ranked)

1. **Security**: CSV injection in `bh-streaming`'s PRO Wizard export.
2. **Correctness**: dead video-size enforcement in `bh-courses` (one-line fix, `$block['type']` not `$block['blockName']`).
3. **Money-adjacent fragility**: `bh-monetization-woo`'s uncoupled 30-day magic-literal across 4 files (silent wallet-credit miscalculation risk if only one is changed).
4. **UX regression**: `bh-crm`'s nested-kanban drag-and-drop full-page-reloads, unlike the top-level board it's supposed to mirror.
5. **UX gap, long-standing**: `bh-contest`'s missing votes-remaining counter (flagged since before 07-13, still not built).
6. **Backend-not-surfaced**: bh-feedback's claim-state visibility to submitters.
7. **Doc hygiene**: correct `STATUS.md`'s bh-feedback section and re-fix `bh-crm/class-projects.php`'s status comment (this time maybe pointing at STATUS.md rather than repeating a local claim).

## Model-spend note (for future runs)

Fable was unavailable (out of credits) for its one assigned task (core: style/Design Suite) — fell back to Opus per the plan's guardrail, and that task still produced the single most thorough report of the 16 (the largest subsystem, read most deeply). Worth trying Fable again on a future run once credits are available, but this run didn't suffer for the substitution.
