# bh-streaming — MAGICAL UX Audit (task 15/16)

**Date:** 2026-07-25
**Model:** Claude Sonnet 5
**Scope:** `bh-streaming` user-facing surfaces — streaming front-end (tabs: All Tracks/Releases/Liked Songs/My Playlists, search + genre filter, "Import my music" CTA), the player, and `BHS_PROWizard`.
**Method / caveat:** Code-level read only (PHP render methods, JS, inline styles). **No live browser or WordPress install was used** — nothing here was clicked through in an actual page load. All findings are inferred from source and should be spot-checked live before being treated as final. Code-quality issues are explicitly out of scope (covered in a separate audit pass) — this is UX/behavior only.

---

## 1. Empty-state re-check — DEFINITIVE VERDICT: **PARTIALLY FIXED**

The 07-13 systemic finding (bare "No tracks match.", no zero-vs-filtered distinction, no CTA repeated at the point of failure) is **improved but not resolved**.

**What's fixed:**
- The bare string is gone. `includes/class-player.php:78-106` now builds server-rendered fragments via `BHY_Style::empty_state_html()` (the shared ecosystem component, `own-ur-shit/includes/class-style.php:1023`) and hands them to JS via `wp_localize_script`.
- Zero-vs-filtered is now genuinely distinguished: `class-player.php:84-92` supplies **different copy** for the two cases — `emptyStateZero` ("Your library is empty" / "Import your music to start building your streaming library.") vs. `emptyStateFiltered` ("No tracks match"). `assets/js/player.js:147-150` picks between them correctly based on live `searchInput`/`genreFilter` state.
- The pattern was also extended to two other tabs that had the same bare-string problem: Releases (`class-player.php:96-100`) and Liked Songs (`class-player.php:101-105`).

**What's still broken — the specific, named issue:**
- `BHY_Style::empty_state_html()` supports an inline CTA button (`cta_label`/`cta_url`, rendered at `own-ur-shit/includes/class-style.php:1063-1069`) and a "Clear filters" link (`clear_url`, same lines) — but **bh-streaming never passes either**. `class-player.php:84-92` only sets `reason`/`title`/`description`. So:
  - The zero-state message literally *tells the user* to "Import your music" (line 87) but does **not** repeat the "Import my music" button that already exists in the topbar (`class-player.php:136`) — the exact gap named in the original finding is still present. The user reads an instruction with no adjacent action to follow it.
  - The filtered-state message has no "Clear filters" link either, unlike the sibling fix in `bh-courses/includes/class-render-catalog.php:120`, which does pass `clear_url`. bh-streaming's fix is measurably behind bh-courses' on this exact point, not just "same pattern, different plugin."
- The Playlists tab's empty states were never migrated at all: `assets/js/player.js:216-217` still uses bare inline strings ("Log in to create playlists." / "No playlists yet — use the + button on a track while it's playing to start one."), not `BHY_Style::empty_state_html()`. Minor relative to the primary "All Tracks" fix, but it's the same systemic pattern recurring in a tab the first pass missed.

**Net:** the visual/copy half of the fix landed; the actionability half (CTA-at-point-of-failure) did not. A user who filters to zero results or opens an empty library still has to look away from the message, back up to the topbar, to find the way out.

---

## 2. BHS_PROWizard vs. the "it just works" wizard bar

File: `includes/class-pro-wizard.php` (215 lines). Judged point-by-point against the stated bar (step-by-step, one plain-language question per screen, real-time validation before continuing, explains why, links to the exact external page).

| Bar element | Verdict | Evidence |
|---|---|---|
| **One plain-language question per screen** | **NOT MET** | This is not a multi-screen wizard at all — it's a single flat admin page (`render()`, lines 131-192) that dumps PRO selection, status, IPI number, and a CSV export link into one form-table (`class-pro-wizard.php:165-177`) with no step progression, no "next" button, no progress indicator. Despite being named `BHS_PROWizard` and modeled explicitly on `OUS_MediaWizard` (per its own doc comment, lines 4-25), it doesn't share that wizard's step-by-step shape. |
| **Real-time validation before continuing** | **Honestly omitted, not silently skipped** | The class's own doc comment (lines 8-18) explains this directly: no PRO exposes a membership-verification API, so there is nothing to validate live against. This is a defensible exception to the bar rather than a violation — the code is self-aware about it ("this is honestly a THINNER tool than the media wizard... No 'test connection' step exists here because there's nothing this code can verify"). Worth noting: this reasoning only justifies skipping *validation*, not skipping the step-by-step structure — those are two separate parts of the bar and only one has a real excuse. |
| **Explains WHY the step matters** | **MET** | `class-pro-wizard.php:139-141` gives a genuinely good plain-language explainer distinguishing a PRO (collects royalties for the composition) from the ISRC (identifies the recording), including the ISWC concept and the one-PRO-at-a-time rule. This is real, non-boilerplate context. |
| **Links directly to the exact external page where the credential lives** | **NOT MET** | Every PRO entry links to the org's bare homepage, not the actual join/signup page: `'url' => 'https://www.ascap.com'` (line 31), `'https://www.bmi.com'` (line 37), `'https://www.sesac.com'` (line 43), `'https://globalmusicrights.com'` (line 49) — despite the accompanying `note` text describing specific signup mechanics ("Open direct signup for songwriters — a one-time application fee applies", line 32). This is exactly the "get your X from the provider" anti-pattern the bar calls out, just with better prose around it. A user still has to self-navigate BMI's or ASCAP's own site to find the actual membership application. |
| **Raw settings still available underneath for experts** | **N/A / trivially true** | Since there's no wizard flow to hide anything behind, the "raw settings" (PRO, status, IPI number) are just... always visible. Not a violation, but also not evidence of following the pattern — there's no guided layer to have an "underneath" relative to. |

**Bottom line:** `BHS_PROWizard` is a reasonably well-written admin settings page with unusually good explanatory copy, but it is not a wizard by the definition this audit is checking against. Two of five bar elements are genuinely met or defensibly excused (WHY-it-matters, and the honest no-live-validation call); the step-by-step structure and the deep-link-to-exact-page requirement are both missed, and neither miss has the same "nothing to verify against" excuse that covers the validation gap — both are achievable with the exact same PRO landscape data already in `PROS` (lines 27-58). This is a concrete counterexample to the audit's hypothesis that the wizard principle is followed by newer code: the newest wizard in this plugin explicitly opts out of the multi-screen shape for reasons (no API) that don't actually justify skipping the multi-screen shape.

**Secondary note — design-system convention:** the page uses a `bhy-alert` class (`class-pro-wizard.php:139`) that does not exist in `own-ur-shit/includes/class-style.php` (grep confirms no `bhy-alert` rule defined there) — the class name is vestigial, since all of that box's actual styling is inline (`style="border-left:3px solid #2271b1;background:#f6f7f7;padding:14px 16px;margin:16px 0;max-width:760px;"`, same line). Same pattern repeats throughout `render()` (lines 149-156 grid layout, badges) — entirely hand-rolled inline CSS in wp-admin rather than either plain default WP admin chrome or a real shared `BHY_UI`/`BHY_Style` component. Low severity (it's an admin-only page, not a public surface), but it's the same "silent design-system deviation" shape flagged elsewhere in this ecosystem's admin surfaces.

---

## 3. Other findings — streaming front-end & player

- **Search/filter is synchronous and client-side** (`assets/js/player.js:100-104`, `147`) — filtering re-runs on every keystroke against an already-loaded `allTracks` array, no debounce needed since there's no network round-trip. Good: no janky loading spinner for a filter that should feel instant.
- **Deep-link handling is honest about failure**: `maybeOpenTrackDeepLink()` (`assets/js/player.js:268-279`) shows `"That track isn't available."` rather than a blank screen or silent redirect when a `?bhs_track=` id doesn't resolve — reasonable, though this message doesn't offer a way back to the full library (no link/button back to "All Tracks" from that dead-end state, unlike `showLockNotice()` at line 195-202 and `openRelease()` at line 204-213, which both include a `&larr; Back` control).
- **Locked-track UX is real, not a dead click**: `bindCardClicks()` (`assets/js/player.js:178-193`) intercepts locked tracks before attempting playback and routes to `showLockNotice()`, which renders whatever paywall copy `bh-monetization-woo` supplied (`t.lock_notice`) rather than a generic "upgrade" wall — track-specific messaging, a good example of the ecosystem's cross-plugin integration working as intended.
- **Source-health badges are honest**: `trackCardHtml()` (`assets/js/player.js:107-128`) surfaces `'Unavailable'`/`'Unreliable'` badges for externally-hosted tracks whose source host is down/degraded, rather than hiding them or letting the user discover it by hitting play into silence (comment at lines 109-115 states this explicitly as the design intent). Good "no accept-and-hope" instinct applied to a different kind of integration (external feed health) than the wizard pattern, worth noting as a positive counterexample to the PRO Wizard finding above.
- **Import modal** (`class-player.php:140-151`) is a single-step file+metadata form, not a wizard — appropriate here since it's a simple upload, not a technical/credential integration, so it isn't held to the wizard bar.

---

## 4. Confirmed good

- Shared empty-state component (`BHY_Style::empty_state_html()`) is correctly wired for the zero/filtered distinction on the primary "All Tracks" tab and extended to Releases/Liked — real, verifiable progress since 07-13, not just a comment claiming it.
- PRO Wizard's explanatory copy (composition vs. recording, ISWC vs. ISRC, one-PRO-at-a-time) is accurate, plain-language, and non-boilerplate.
- PRO Wizard is honest about the limits of what it can automate (no live PRO membership API exists) rather than faking a "verify" step — this is the right instinct even though it's applied to the wrong half of the wizard-shape gap (see Section 2).
- Locked-track and degraded-source-health handling both avoid silent failure — the player tells the listener what's wrong before they hit play, not after.
- CSV royalty export (`class-pro-wizard.php:81-120`) is correctly gated behind an actual data check (ledger table existence, line 184) rather than always showing a button that 404s with nothing to export.

---

## 5. Prioritized punch-list

1. **[High]** Pass `cta_label`/`cta_url` into `emptyStateZero` in `class-player.php:84-88` so the "Import my music" CTA is repeated inside the empty-state message itself, not just the topbar — this is the exact gap the original 07-13 finding named and it is still open.
2. **[Medium]** Pass `clear_url` into `emptyStateFiltered` in `class-player.php:89-92` (mirror `bh-courses/includes/class-render-catalog.php:120`) so filtered-to-zero has a one-click way out, matching the sibling plugin's already-shipped fix.
3. **[Medium]** Restructure `BHS_PROWizard::render()` into an actual multi-step flow (pick PRO → confirm → record affiliation → optional royalty export) even without live validation — the "no verification API" justification in the class doc comment only excuses skipping real-time checks, not skipping step-by-step screens.
4. **[Medium]** Replace the PRO homepage URLs (`class-pro-wizard.php:31,37,43,49`) with direct links to each org's actual membership/signup page where one exists (ASCAP, BMI both have open self-serve signup per the plugin's own `note` text) — closes the "link to the exact page, not just 'go check their site'" gap.
5. **[Low]** Migrate the Playlists tab's two bare empty-state strings (`assets/js/player.js:216-217`) onto `BHY_Style::empty_state_html()` for consistency with the rest of the tabs.
6. **[Low]** Add a "back to library" control to the failed-deep-link state (`assets/js/player.js:275`), matching the pattern already used by `showLockNotice()` and `openRelease()`.
7. **[Low]** Replace `BHS_PROWizard`'s inline-styled admin markup (`class-pro-wizard.php:139,149-156`) with either plain WP admin defaults or a real shared `BHY_UI` component — the `bhy-alert` class currently used doesn't exist anywhere in `own-ur-shit/includes/class-style.php`.
