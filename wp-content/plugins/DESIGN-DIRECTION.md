# Design direction — the look Billy is going for

Companion to `STYLE-SYSTEM.md`, which says *where* a style rule goes. This file says *what to reach for*.

## The brief

- **Streamline Moderne, Googie neon, Dark Deco.** Smoky grey noir is the dominant mood; neon is the accent, not the base.
- **Neon is the exception, noir is the rule.** If a screen reads as more neon than smoky grey, the balance is off. Glow goes only on things that already matter (the main button, a winner, a finished course).
- **Not "gemmy."** No decoration for its own sake.
- **Compose with light.** Depth and lighting make a surface feel like an object: nearer things are lighter and cast more shadow.

## Craft standards

- **Measure, never eyeball.** Check contrast ratios, overflow (`scrollWidth` vs `clientWidth`) and clipping with real numbers. A screenshot can't see a 5px clip or a 2.4:1 contrast ratio.
- **Usefulness beats the look.** When a color fails contrast, change the color; don't tolerate the failure.
- **State changes are value shifts, not new hues.** Hover and pressed states lighten or darken the same color.
- **Each screen size is its own layout**, not a squashed desktop. Check phone width (about 375px) every time.
- **Fix at the source, once.** One place sets a color or size; never add a second override somewhere else.
- **Respect `prefers-reduced-motion`** wherever something moves.

## What would improve the feel most (not built yet)

In rough order of payoff:

1. **Depth steps.** There's one shadow for everything; three named levels (resting, hover, pop-up) would make cards and dialogs feel like objects.
2. **Front-end polish parity.** A shared alert/notice style for the public site, and visible keyboard focus everywhere.
3. **Moments that matter.** Finishing a course, a contest reveal, joining as a supporter: build one shared, quick, unmistakable acknowledgement and reuse it, rather than one-offs per page.
