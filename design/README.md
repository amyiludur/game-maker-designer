# Design

High-fidelity screen designs for the designer-facing UI, produced from
[`docs/12-ui-design-brief.md`](../docs/12-ui-design-brief.md) and drawn against the real
[`examples/emberfall/`](../examples/emberfall/) data.

| | |
|---|---|
| **[HANDOFF.md](HANDOFF.md)** | The design pass's own handoff document, verbatim: tokens, per-screen specs, interaction notes, data provenance and known gaps. **Read this before building anything.** |
| `screens/*.dc.html` | Six self-contained screen prototypes. Open directly in a browser. |
| `screens/support.js` | Generated preview runtime (React 18.3.1 + Babel from unpkg). Not design content; it exists so the `.dc.html` files render. Opening a screen needs network access. |

## The screens

| File | Screen | Status |
|---|---|---|
| `Card Editor.dc.html` | Card editor + ability builder | Three visual treatments — **build 1a "Instrument" only**, the other two are reference |
| `Play Table.dc.html` | Play table (Emberfall duel) | |
| `System Editor.dc.html` | System editor, Phases & Steps tab | Other eleven tabs not yet designed |
| `Card Browser.dc.html` | Card browser | |
| `Deck Builder.dc.html` | Deck builder | |
| `Simulation Report.dc.html` | Simulation batch report | |

## How to use these

They are **design references written in HTML, not production code**. The target is Vue 3 +
TypeScript + Tailwind over a CSS-custom-property token layer, with the component tree in
[`docs/11-frontend-architecture.md`](../docs/11-frontend-architecture.md). Recreate them as
Vue components; do not port the markup.

Two constraints from the brief that the prototypes encode and that must survive the port:

1. **Nothing is hardcoded per game.** Attribute forms, card faces, phase rails, board rows
   and table columns are all generated from the game system document. Emberfall is a
   fixture, not a spec.
2. **The client never decides legality.** Every "you can't" state on screen is text the
   server supplied, in the game's own vocabulary.

## Status

Designed at 1280×880, dark theme, comfortable density. `HANDOFF.md` lists ten known gaps in
priority order; the ones that block implementation soonest are the light theme, compact
density, responsive breakpoints and the motion spec.

**Not yet designed at all:** dashboard, game overview, set manager, playtest setup, replay
viewer, version history & diff, notes & triage, simulation setup, and the `⌘K` command
palette.

**Not yet designed, and now a known omission:** the cooperative play table — 1–4 players
against an adversary area with a threat track and per-player engagement rows. The brief
gained that requirement in [doc 16](../docs/16-cooperative-and-adversary-games.md) after
this design pass was briefed, so these six screens cover the competitive duel shape only.
It is the largest open design problem, because at four players there is far more board than
fits and it needs a focus/overview mode rather than a bigger grid.
