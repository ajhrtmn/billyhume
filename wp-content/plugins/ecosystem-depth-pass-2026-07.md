# Own Ur Shit — Ecosystem Depth Pass (2026-07-21): what to build, what makes it magical, how we execute

**Status (doc-cleanup pass, 2026-07-26): Tiers 1–3 are all shipped, confirmed directly against code** — role-assignment admin UI (`class-role-assignment.php`), per-notification-type email preferences, the front-end user bar (`class-user-bar.php`), BH Feedback (real plugin), the bh-courses remaining-gaps items, all four Tier 2 marketing items (SEO coverage, link-in-bio, `bh-monetization-woo/includes/class-referrals.php`, email campaigns), and all of Tier 3's Project Tracker phases B–E (`bh-crm/includes/class-projects.php`'s own status comment confirms B/C/D/E all built). Trimmed the doc down to just Tier 4, the one genuinely open item — the original Context, the Tier 0–3 sections, and the original sequencing/verification notes have been removed; see `STATUS.md` for current state.

## Tier 4 — Design Suite Page Manager (the "no special-cased pages" architecture, finale piece)

Confirmed still fully unbuilt (no `Page_Manager`/page-manager class anywhere in `own-ur-shit/includes/`) — genuinely the one item left from this pass. Note: the plan doc this section originally pointed to (`DESIGN-SUITE-PAGE-MANAGER-PLAN.md`) no longer exists in the repo, so the phase breakdown below (data model → post-edit metabox → context-picker tree UX → default-on for new pages → delete-cleanup hook) is now the only surviving record of that design — treat it as the spec of record if this gets picked up.

This is real, valuable architectural work (the last piece of "collapse every hand-written template into one node tree"), but it's also the most speculative/UI-heavy item in this whole plan and the least tied to a concrete near-term user-facing win. Start with Phase 1 (data model) directly — the phase breakdown above is detailed enough to execute against as-is, no new design pass needed.

## Verification approach (once built)

Same discipline used all session: lint every touched file, live-browser-verify the actual user-facing flow (not just code review), add `OUS_TestRunner` coverage for any real branching/edge-case logic, run `./run-all-tests.sh` before every commit, propagate through dev → master → stable with lint checks at each stage.
