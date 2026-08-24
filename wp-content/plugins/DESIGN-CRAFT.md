# DESIGN-CRAFT — what would make this feel magical

**Written 2026-08-23**, after verifying the design system against code rather than docs. Companion to `STYLE-SYSTEM.md` (which says *where a rule goes*); this file says *what to reach for and why*. The creative brief itself — Streamline Moderne reasoning, Googie neon, Dark Deco, smokey-grey-noir dominant, "not gemmy," composing with light — is preserved verbatim in the design-direction memory and is not restated here.

## The honest assessment

**The vocabulary is built. The application of it is uneven.**

wp-admin has a genuinely sophisticated design system: a fluid spacing scale, an atmosphere/haze depth-of-field layer, a Wallet-stack accordion, glow-for-importance badges, a ⌘K command palette, cross-document view transitions, per-item hue wayfinding, and a token bridge that themes every peer plugin for free. That is more than most commercial products ship.

Three things hold it back from magical, in order of leverage:

### 1. Everything is at the same depth

There is exactly one elevation token. A resting card, a hovered card, and a modal all cast the identical shadow. The brief's central instruction is to **compose with light** — but light without a *gradient of distance* isn't composition, it's a flat wash. Depth is the cheapest way to make a surface feel like an object, and right now nothing is nearer to the reader than anything else.

Three named steps (`-resting` / `-hover` / `-modal`) would do more for perceived quality than any amount of new color work. This is the first thing to fix.

### 2. The front end lags the backstage — an exact inversion of the stated principle

The brief says the admin is *"the behind the scenes of the Disney experience"* and must be held to the same bar as the public site. Today the relationship runs the other way:

| | wp-admin | front end |
|---|---|---|
| Atmosphere/haze | yes | — |
| Command palette | yes | — |
| View transitions | yes | — |
| Wallet-stack density | yes | — |
| Alert component | `.bhy-alert` | **none** |
| `focus-visible` | thorough | 6 files / 14 plugins |
| Skeleton/loading states | — | — |

The backstage is beautiful and the front of house is plain. For a platform whose entire premise is an artist's relationship with the people who support them, **the fans see the weaker half.** Closing this is the single largest available improvement, and most of it is porting an existing vocabulary rather than inventing one.

### 3. Nothing is ever celebrated

This ecosystem is full of moments that carry real emotional weight — a course completed, a first supporter, an entitlement granted, a contest reveal, a purchase permanently anchored to a tamper-evident ledger. Every one of them currently renders as a table row, a redirect, or a status badge.

The wiring exists (`bhc_course_completed`, `bhm_entitlement_granted` and friends all now have listeners). What's missing is a **shared acknowledgement treatment** — one component, in the shared layer, that these moments reach for. Not confetti; the brief rules out decoration for its own sake. Something closer to how a well-made game confirms an achievement: instant, unmistakable, over quickly, never in the way. Built once in `the-self-hosted-self`, used by every plugin — the same discipline as `.bhy-table-wrap`.

Per-plugin one-offs here would be the badge-shape mistake all over again (eight hand-rolled pills before a shared primitive existed). Build it shared or don't build it.

## CSS architecture — measured 2026-08-23

All 19 stylesheets (7,104 lines) parse with **zero errors** in a real browser parser, braces balanced. Well-formed is not the problem. Two things are:

### 1. The admin skin is half `!important` — which is what makes the GUI hard to override

| Sheet | Declarations | `!important` | |
|---|---|---|---|
| `admin-skin.css` | 2,907 | **1,457** | 50% |
| `admin-bar.css` | 211 | **113** | 54% |
| every front-end sheet | 9,000+ | ~10 | ~0% |

The front-end sheets are clean; the admin skin is the outlier, and it is not lazy — a skin whose whole job is to override WordPress core's own high-specificity admin CSS genuinely needs a lot of it. But the consequence is real: **anyone wanting to customise an admin screen has to fight 1,457 `!important` declarations.**

The fix is *not* to strip them (that breaks the skin). It is to give consumers a documented seam that wins without a specificity war. `@layer` is the modern answer, but note the subtlety before reaching for it: `!important` **reverses** layer precedence, so wrapping the skin in a layer does not by itself make important declarations overridable. A safe path is an explicit, last-loading override stylesheet/hook that the skin guarantees loads after everything else. Design this deliberately — it changes cascade semantics across 3,491 lines, so it needs its own pass and real verification, not a side-effect of a cleanup.

### 2. Hardcoded hex outside the token system

Literal hex counts where tokens should be: `kanban-board.css` 42, `bhm/frontend.css` 34, `feedback.css` 31, `registry.css` 30, `storefront.css` 23, `the-self-hosted-self/admin.css` 22, `bh-courses/admin.css` 21. (`admin-skin.css`'s 96 are mostly the token *definitions* — correct.) Every one of these bypasses `--bh-*`/`--bhy-*`, so they don't follow the theme and can't be re-skinned. This is the Layer-1/Layer-4 violation `STYLE-SYSTEM.md` already names.

### 3. Cross-plugin component duplication — one real bug found and fixed

`bh-streaming/player.css` styled `.bhm-paywall` and `.bhm-btn` — components whose markup only **bh-monetization-woo** renders. The copies had already drifted (no `var()` fallbacks, no `color`, no `.bhm-btn-secondary`).

The interesting part: the duplicate was **load-bearing**. BHM's `frontend.css` only enqueued on pages carrying one of four `bhm_*` shortcodes — but a tier-gated post carries none, so on a gated track or lesson the paywall would have rendered **completely unstyled** if not for bh-streaming's private copy. Fixed at the root: BHM now enqueues on tier-gated posts too, and the duplicate is gone. A component's owner must ship its styles everywhere that component renders.

**Check this before adding any cross-plugin CSS:** if you're styling a selector another plugin renders, the bug is that plugin's asset loading, not your missing rule.

## Craft standards worth holding

Carried forward from the visual-execution principles that earned their place, plus what this pass added:

- **Measure, never eyeball.** A screenshot cannot see a 5px clip or a 2.4:1 ratio. This is not optional rigor — a visibly broken screen passed an eyeball audit, and separately a *fabricated* set of 39 contrast failures nearly got reported as real. Both were caught only by measuring. Method lives in the audit-method memory.
- **Usefulness beats the look, every time.** Already settled in practice: when light-mode cyan measured 3.95:1, the token was deepened rather than the failure tolerated or patched around. That is the precedent.
- **Value shifts, not hue shifts, for state.** Hover/press are `--shsas-hover-veil` / `--shsas-press-veil` — never a new color. Established directly and repeatedly.
- **Neon is the exception, noir is the rule.** If a screen reads as more neon than smokey-grey, the balance is wrong. Glow attaches only to things already carrying semantic weight.
- **Each breakpoint is its own considered layout**, not a scaled desktop. Wallet-stack is the reference: collapse-and-peek small, fan out large.
- **Fix at the source, once.** An inline `style="background:#fff"` in PHP-echoed markup cannot be reached by any stylesheet — migrate it to `var(--bhy-surface, #fff)` and keep the literal as the bare-WordPress fallback. Never add a second override elsewhere; two places setting one color is how the drift started.
- **Respect `prefers-reduced-motion`** everywhere motion is added. Reasonably good coverage today (13 files); keep it at 100% for anything new.

## Suggested order

Roughly ascending cost, descending certainty of payoff:

1. Elevation scale (Tier 1 in `OPEN.md`) — hours, transforms perceived depth everywhere at once.
2. Front-end `.bh-alert` + `focus-visible` sweep — closes a named Layer-3 gap and a real accessibility hole.
3. Systematic front-end audit — establishes the same measured baseline wp-admin already has.
4. Port haze + motion vocabulary to the front end — the parity fix.
5. The shared acknowledgement component — the actual "magic," built once the foundation can carry it.

Do 1–3 before 4–5. Celebration layered on top of flat depth and missing focus states reads as noise; layered on a solid foundation it reads as craft.
