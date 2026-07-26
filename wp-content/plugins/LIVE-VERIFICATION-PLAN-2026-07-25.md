# Live verification plan — 2026-07-25 audit fixes

This is the handoff doc for the session that has access to the live site. The 2026-07-25 audit (`AUDIT-2026-07-*.md` + `AUDIT-2026-07-SYNTHESIS.md`) found ~87 issues across all 8 plugins; every one was fixed in a prior session with **no live PHP/MySQL/WordPress/browser environment available** — every fix was written, lint-checked (`php -l` / `node --check`), and in a few cases unit-tested via direct PHP execution, but **none of it has been click-tested in a real browser against a real database**. This doc is the punch-list for that missing step.

Work through this in the priority order below. For each item: do the action, confirm the expected result, and if something's broken, note the file/line and what you observed — don't silently patch around it without understanding why a fix that lint-checked clean still misbehaves live.

## Priority 1 — things that touch real data or money, verify these first

1. **`BHY_Style::save_from_input()` (Design Suite save-path fix).** Go to the Design Suite / Style Gallery admin screen, change a color/font/spacing value, save via the normal admin form. Then do the same save via the REST path if there's a GUI entry point that uses it (the page-builder's site-token editor). Confirm: (a) both saves actually persist, (b) a custom slider value (if any are registered) survives a REST-path save without being wiped, (c) `OUS_Revisions` shows a new snapshot after the REST save (it didn't before this fix).
2. **`BHM_Money` helper rollout (bh-monetization-woo).** Visit every screen that displays a dollar amount: wallet balance (portal panel), tier prices (frontend + admin), tip jar, wallet top-up options, purchase-ledger CSV/admin tables, CRM activity wallet lines. Confirm every price still displays correctly (no `$NAN`, no missing decimals, no thousands-separator ending up in a WooCommerce price field). Specifically check a WooCommerce product's price field after a tier is saved/synced — `BHM_Money::price()` must produce a plain decimal WC can parse.
3. **`BH_Commerce::available()` guard normalization (bh-monetization-woo).** With WooCommerce active, confirm tiers/checkout/wallet top-up/subscriptions all still work — this was a ~33-site mechanical sed replacement across 10 files and deserves a real smoke test, not just a diff read.
4. **`OUS_Audit` UTC timestamp change.** Trigger an audited action (e.g. reject a contest submission, delete a bh-crm segment) and check the Audit Log admin table — confirm the displayed timestamp matches your site's actual local time (not off by your UTC offset). This is the one change most likely to visibly regress if `get_date_from_gmt()` wasn't the right fix.
5. **`OUS_Jobs` / `OUS_Notifications` bounded-growth additions.** Trigger a background job (any `OUS_Jobs::enqueue()` caller — e.g. submit a contest entry to fire the confirmation-email job) and confirm it still completes normally. These changes only *add* a trim call after existing success/failure paths, but confirm nothing throws.
6. **bh-contest winner-notification emails now queued, not synchronous.** Publish contest results and click "Send Winner Notifications." Confirm: (a) the admin request returns quickly (no long hang), (b) winner emails actually arrive (check your mail catcher/log — they now go through `OUS_Jobs`, so if your install's job runner isn't actually processing the queue, emails will queue but never send — this is the one item most likely to expose an infrastructure gap rather than a code bug).
7. **bh-contest submission-confirmation email**, same queuing change — submit a test contest entry, confirm the "we got your submission" email still arrives.

## Priority 2 — real UX behavior changes, need eyes-on

8. **bh-crm nested kanban drag-and-drop.** Open a project's sticky card's sub-task board, drag a card between non-Done columns — confirm it does NOT reload the page (this was the fix) and the card visibly stays where you dropped it. Then drag a card INTO the Done column — confirm it DOES reload (this is intentional, since ancestor progress bars elsewhere on the page need a real re-render).
9. **bh-crm nested-board delete button.** Click Delete once — confirm it relabels to "Really delete?" and does NOT submit. Click it again — confirm it actually deletes. Click Delete then click/type elsewhere on the card — confirm it disarms (relabels back to "Delete") without submitting.
10. **bh-streaming video upload (new wp.media picker).** Edit a release, click "Choose video," pick or upload a video file via the media modal, save the release. Confirm a real `bhs_video` post got created wrapping that attachment, and that re-opening the release still shows the picked video. Re-picking the SAME file on a second save should reuse the existing `bhs_video` post, not create a duplicate.
11. **bh-streaming PRO Wizard's new step flow.** Visit the PRO Registration screen fresh (no affiliation on file) — confirm you see "1. Pick a PRO" only. Pick one — confirm you land on "2. Register with X" with a real outbound link. Click "I've registered — continue" — confirm you land on "3. Record your affiliation" pre-filled with the picked PRO. Save — confirm you land on the status/settings view with "Edit affiliation" / "Change PRO" links that jump back into the right step.
12. **bh-contest votes-remaining counter.** Vote on a track in a contest with a category. Confirm a persistent counter (not just the toast) shows near the category tabs and updates after each vote/un-vote, and switching categories shows that category's own count.
13. **bh-contest "All results" tab**, specifically on a judged or hybrid-format contest with 2+ categories — confirm scores read as "%" not "votes" in the flattened All tab.
14. **bh-courses orientation screen** — enroll a fresh test student in a course, confirm the syllabus/orientation card shows ONCE (not the syllabus card followed immediately by the full ordinary lesson list underneath it).
15. **bh-courses catalog empty states** — with zero courses published, confirm you (as an admin) see a "Create your first course" button; log out or use a non-admin account and confirm that CTA is absent.
16. **bh-courses numbered pagination** — this only appears with 6+ pages of courses; if you don't have enough test courses, seed some (BH Courses' own Debug Tools seeder, or just publish several) and confirm the numbered pager (with `…` collapsing) appears and each page link actually navigates.
17. **bh-streaming empty states** — with an empty library, confirm the "Import my music" button inside the empty-state message actually opens the import modal (not just the one above the library). With an active filter that matches nothing, confirm "Clear filters" actually clears search + genre and re-renders. Check the Playlists tab's own two empty states (logged-out, zero-playlists) render via the shared component now, not the old bare strings.
18. **bh-feedback new admin screen** (`BH Feedback → Feedback Requests`, or find it via the Own Ur Shit dashboard's plugin card) — confirm it now appears in the dashboard/plugin registry at all (this didn't exist before), and that the request list renders with correct status badges.
19. **bh-feedback claim-state visibility** — submit a feedback request, have a reviewer claim it, check the submitter's portal view shows a distinct "Claimed X ago" note and a visually distinct badge color from "open."

## Priority 3 — lower-stakes but worth a quick look

20. Gift-redemption page/status visibility (bh-monetization-woo Settings page) — confirm the new "Not set yet" line for the gift-redeem page and the new "Recent gift redemptions" table both render without errors.
21. Subscription pause confirm dialog — click Pause on an active test subscription, confirm the browser `confirm()` prompt appears before it actually pauses.
22. bh-crm People-detail identity header — eyeball it in both light/dark (if your theme supports it) to confirm the `--bhy-*` token swap didn't visually break anything.
23. bh-crm Done-column info icon (nested kanban) — hover it, confirm the tooltip text appears.
24. bh-courses `audio-compare` step — view a lesson with this step type, confirm the new "Play each and compare:" prompt shows above the two players.
25. bh-registry admin screen — confirm the Reject action now shows a confirm dialog, and the status badges (Active/Pending/etc.) render as colored pills, not plain colored text.

## Deliberately NOT re-litigated (already reasoned through, don't second-guess without new evidence)

- The REST permission-callback "centralization" in bh-contest — investigated and declined; not a real duplication.
- The bh-monetization-woo gifting `<details>` visibility question — left as-is; the styling is already real button-weight, just verify it visually reads as prominent enough per your own judgment (this was explicitly flagged as needing eyes, not code).
- `bh-registry`'s "Re-check now" loading indicator — confirmed it's a plain page-navigation link, not an AJAX call; the browser's own loading indicator already covers this. No action expected here unless you find it actually IS AJAX-driven somewhere I missed.
- `BH_Element`'s renderer/trust-boundary split (own-ur-shit core) — deliberately deferred as too large a refactor for this pass; not part of this verification round.

## If something's broken

Note it plainly: file, what you did, what you expected, what actually happened. Don't silently work around a broken fix — a lint-clean but functionally-wrong fix is exactly the gap this verification pass exists to catch, and the fix likely needs to go back for a real second look rather than a live patch-over.
