# MAGICAL UX Audit — bh-registry (admin) + bh-feedback (user-facing)

**Date:** 2026-07-25
**Model:** Claude Sonnet 5
**Task:** 16 of 16, final in the granular per-task ecosystem audit
**Scope:** bh-registry's admin surface (submissions review, `OUS_Search` integration); bh-feedback's user-facing surfaces (submission flow, portal panel).

## Caveats

- **No live browser/WordPress install available for this task.** This is a code-level UX read only — PHP render methods, inline HTML/CSS, and JS were read directly; nothing was clicked or rendered in an actual browser. Layout, real spacing, responsive behavior, and JS runtime state are inferred from source, not observed.
- **STATUS.md correction:** bh-feedback is documented in the ecosystem's STATUS.md (and was originally briefed to this task) as "entirely unbuilt, still just a plan." That is stale. The code-quality task that ran immediately before this one found — and this audit confirms by direct read — a working v1: activation (`class-activator.php`), a CPT (`class-post-types.php`), two-tier pricing (`class-pricing.php`), a wallet-charged submission flow (`class-requests.php`), an atomic claim/release/complete reviewer queue (`class-queue.php`), and a portal panel (`class-portal-panel.php`). This audit treats it as real, shipped user-facing surface, not a stub to wave past.

---

## bh-registry — admin surface

Files read: `includes/class-admin.php`, `includes/class-frontend.php`, `assets/js/registry.js`.

This is a single admin page (`Registry Submissions`, relocated into the ecosystem's shared menu via `ous_registered_plugins`) plus the public submit/verify modal on the front end. It's small and mostly clean.

**Findings:**

1. **Inline hex colors bypass the design system.** `status_badge()` in `bh-registry/includes/class-admin.php:105-109` builds badges with `style="color:#1DB954;..."` etc. hardcoded per-call, rather than using a shared `BHY_UI` badge component. The brief's own convention is plain WP admin styling by default, with deviations required to be shared reusable `BHY_UI` pieces — this is a bespoke one-off inline-styled control instead. Low severity (it's a single small page), but it's exactly the kind of drift that produces inconsistent badge colors/shapes across plugins if repeated.
2. **Delete has a JS `confirm()`, but Reject does not.** `class-admin.php:97-99` — `reject`, `unreject`, and `reverify_link` all fire immediately on click via a plain link with no confirmation, while `delete` alone gets `confirm()` (line 99, using the `$confirm` param that only `delete` passes). Rejecting hides an artist from public search/browse instantly with one accidental click and no undo prompt (it is reversible via "Restore," but the admin has no signal at click-time that anything happened beyond the page reload).
3. **No feedback on `reverify_link` while it's running.** `class-admin.php:92,147-152` — clicking "Re-check now" does a full page navigation via `admin-post.php` and reloads with no loading state; if the domain check involves a real outbound HTTP fetch (verification logic lives in `BHR_Verification`, not read for this task) this could be a silent multi-second hang with no indication to the admin that anything is happening.

**Confirmed good:**
- The public submit → verify flow (`class-frontend.php:137-158`, `assets/js/registry.js:159-230`) is well-built: submit button disables and relabels while in-flight (`registry.js:172-173`), errors are shown inline without losing form state, and the verify-success path shows a toast, refreshes the grid, and auto-closes the modal after a beat (`registry.js:222-226`) rather than leaving a stale dialog sitting over the thing it just confirmed. Comments in the code (`registry.js:168-171, 216-221`) indicate these were deliberate fixes for exactly the kind of "did my click register?" gap this audit looks for — good sign the pattern is being watched.
- The admin page states plainly, up front, that review is for abuse handling and not a required approval gate (`class-admin.php:14-19,71`) — no ambiguity for the admin about what the page does or doesn't control.
- Empty-state and zero-artist day-one messaging is handled via the shared `BHY_Style::empty_state_html()` component (`class-frontend.php:91-95`) rather than a bare fallback string.

---

## bh-feedback — user-facing surfaces

Files read: `includes/class-portal-panel.php`, `includes/class-requests.php`, `includes/class-queue.php`, `includes/class-post-types.php`, `includes/class-pricing.php`, `assets/css/feedback.css`.

### Submission flow — is it clear what's being submitted and what happens next?

The submit form (`class-requests.php:53-84`, shortcode `[bhf_submit]`) shows: title field, audio file field, a tier picker with price and a one-line description per tier (`class-pricing.php:14-15`, rendered at `class-requests.php:70-76`), and current wallet balance. That much is clear — the user knows what they're paying and how much.

What is **not** communicated anywhere in the submission flow:

1. **No turnaround-time expectation at all.** Neither tier description (`class-pricing.php:14-15` — "A short, honest first-impression review" / "A full written breakdown") nor the post-submit confirmation notice (`class-requests.php:47`, "You'll see the review in your account once a reviewer claims it") states how long a submitter should expect to wait. There's no SLA, no "usually within X days," nothing. A user paying $5–$15 has no way to judge whether "still open" after an hour vs. a week is normal.
2. **The confirmation message is subtly wrong about the next visible event.** `class-requests.php:47` promises "You'll see the review in your account once a reviewer claims it" — but per the portal panel's actual render logic (`class-portal-panel.php:60-65`), nothing new appears when a request is claimed; the review body only renders once status is `completed`. Claiming is invisible to the submitter (see below). The copy overpromises what "claimed" surfaces.
3. **No indication of who might review it** (a named team, "our reviewers," anonymous, etc.) — not necessarily required, but combined with the missing timeline, the whole submission is a bit of a black box at the moment of paying.

### Portal panel — is queue state actually surfaced to the submitter?

This is the core question this task was asked to check, since the code-quality pass confirmed a real atomic open → claimed → completed state machine exists (`class-queue.php:56-119`).

**Verdict: the state machine exists but is only partially surfaced, and the most informative half of it never reaches the submitter.**

`render_my_requests()` (`class-portal-panel.php:46-69`) is the entirety of what a submitter sees:
- A badge showing raw status text: `ucfirst($status)` → "Open", "Claimed", or "Completed" (line 59).
- The tier label.
- If (and only if) status is `completed`, the actual review body (lines 60-65).

That's it. Specifically missing, despite the data existing:

1. **`_bhf_claimed_at` is recorded (`class-queue.php:65`, documented in `class-post-types.php:19`) but never read or displayed to the submitter.** A user whose request has sat in "Claimed" for 5 minutes vs. 5 days sees an identical badge either way — the one piece of state that would answer "is this actually progressing?" is captured and then thrown away for this audience.
2. **No reviewer identity or count of who's ahead of them in the queue.** This may be intentional (reviewer anonymity, no public queue depth), but it means "Open" gives zero sense of position — a user has no way to distinguish "next in line" from "50 requests away."
3. **CSS has no styling for the `open` or `claimed` badge states.** `assets/css/feedback.css` defines `.bhf-badge` (generic) and `.bhf-badge-completed` (line 12-13 of the CSS, accent-colored) — there is no `.bhf-badge-open` or `.bhf-badge-claimed` rule. Both non-completed states render as the same flat grey `.bhf-badge` default, meaning **"Open" and "Claimed" are visually indistinguishable from each other** in the UI; a submitter has to read the word, not glance at a color/state cue, to tell whether their request has even been picked up yet. This directly undercuts the value of having claim state at all.
4. **No timestamp of any kind on the request card** — not submitted-at, not claimed-at, not any relative "3 days ago." The card shows title, badge, and tier only until completion.

Net effect: the atomic claim/release/complete machinery the code-quality task praised is real and safe on the backend, but from the submitter's chair it collapses to a two-state signal ("still waiting" vs. "done") with a third label in between that carries no additional visible information. The brief's "it just works" bar implies a paying user should be able to tell where they stand without asking; right now they can't tell if "Claimed" happened one minute or one week ago, or whether the platform is even actively working on their track.

**Confirmed good (bh-feedback):**
- The claim/release/complete queue itself is genuinely well-engineered for correctness: atomic conditional UPDATE-on-postmeta for claim/complete (`class-queue.php:56-67, 86-95`), ownership-checked release (`class-queue.php:72-84`), and an explicit fix for a WP object-cache staleness bug documented right in the code (`class-queue.php:43-55`) — this reflects real rigor, just not yet extended to the submitter-facing read side.
- Wallet debit is atomic and reversed cleanly if post creation fails after charge (`class-requests.php:112-137`) — a submitter can't be charged for a request that silently doesn't exist.
- Reviewer-side duplicate-file detection surfaces as a visible flag to the reviewer (`class-portal-panel.php:90-93`), a real, low-cost "someone already reviewed this" catch.
- On completion, `OUS_Notifications` fires to the submitter (`class-queue.php:110-117`) — so at minimum the "your feedback is ready" moment does get pushed to the user rather than requiring them to keep polling the portal page.
- Submission-side error handling (bad file type/size, insufficient balance, empty title/tier) all round-trips cleanly with inline messaging and no silent failures (`class-requests.php:97-121`).

---

## Prioritized punch-list

1. **(bh-feedback, high)** Surface claim state meaningfully to the submitter: show `_bhf_claimed_at` (e.g. "Claimed 2 days ago") on the request card in `class-portal-panel.php:57-59`, and add distinct CSS for `.bhf-badge-open` / `.bhf-badge-claimed` in `assets/css/feedback.css` so the three states are visually distinguishable, not just textually.
2. **(bh-feedback, high)** State a turnaround-time expectation somewhere in the submission flow — tier description, confirmation notice, or both (`class-pricing.php:14-15`, `class-requests.php:47`) — so a paying user has a baseline for what "normal wait" looks like.
3. **(bh-feedback, medium)** Fix the confirmation copy at `class-requests.php:47` — it currently claims the account view updates "once a reviewer claims it," which per the actual render logic isn't true (only `completed` surfaces new content). Either make claim actually visible (per #1) so the copy becomes true, or change the copy to describe what really happens.
4. **(bh-registry, low)** Move `status_badge()`'s inline hex-color styling (`class-admin.php:105-109`) into a shared `BHY_UI` badge component per the ecosystem's stated design-system convention.
5. **(bh-registry, low)** Add a `confirm()` prompt to the "Reject" action (`class-admin.php:97`) to match the pattern already used for "Delete" — rejecting is reversible but currently fires with zero click-time confirmation.
6. **(bh-registry, low)** Add an in-flight loading indicator to "Re-check now" (`class-admin.php:92, 147-152`) if the underlying verification call involves outbound HTTP (unverified in this task — `BHR_Verification` wasn't read).
