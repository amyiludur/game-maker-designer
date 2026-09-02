# Handoff: Game Maker Designer — designer-facing UI

## Overview

Six high-fidelity screen designs for the LCG authoring platform described in
`docs/12-ui-design-brief.md`. They cover the authoring loop end to end: browse a set,
edit a card, edit the game system, build a deck, play a test match, read a balance batch.

Every screen is designed against the real Emberfall example data in
`examples/emberfall/` — no invented cards, stats, keywords, phases or op names. Where a
number appears on screen it can be traced to a file in this repo; the `Data provenance`
section below records where.

## About the design files

The `.dc.html` files in this bundle are **design references written in HTML**. They are
prototypes showing intended layout, density, typography and behaviour — not production
code to copy.

The target environment is stated in `docs/11-frontend-architecture.md`: **Vue 3
(`<script setup>`, Composition API) + TypeScript + Vite, Pinia, Vue Router, Tailwind over
a CSS-custom-property token layer.** Recreate these designs as Vue components following
that document's directory layout (`components/primitives/`, `components/card/`,
`components/editor/`, `components/system/`, `components/deck/`, `components/table/`,
`components/sim/`). Do not port the HTML.

Two constraints from the brief that the HTML deliberately encodes and that must survive
the port:

1. **Nothing is hardcoded per game.** Attribute forms, card faces, phase rails, board rows
   and table columns are all generated from the game system document. The designs use
   Emberfall as a fixture; a game with 14 card attributes and nine zones must render in
   the same layouts.
2. **The client never decides legality.** Every "you can't" state in these screens is
   presented as text the server supplied, in the game's own vocabulary.

## Fidelity

**High fidelity.** Final colours, type, spacing and interaction states. Recreate
pixel-accurately using the token layer below. All six screens are designed at
**1280 × 880** (the brief's minimum working width); they are not yet specced at 1600/1920
or tablet landscape.

Interactions implemented in the prototypes are limited to tab switching, panel toggles,
hover states and one collapse. Everything else is a static state.

## Design tokens

Dark theme only so far. A light theme is required by the brief and is **not yet designed**.

### Surfaces

| Token | Value | Use |
|---|---|---|
| `surface-0` | `#0b0f13` | Input wells, deepest recesses, card-face panes |
| `surface-1` | `#0d1114` | Board background, toolbars |
| `surface-2` | `#0e1216` | App background |
| `surface-3` | `#11171c` | Panels, rails, ability-builder bodies |
| `surface-4` | `#141a20` | Raised rows, table headers, clause containers |
| `surface-5` | `#1a222a` | Active nav, headers within panels |
| `surface-6` | `#212b34` | Chips, selected states |

### Borders

| Token | Value | Use |
|---|---|---|
| `border-faint` | `#1a2127` | Row separators |
| `border-subtle` | `#1e262e` | Panel separators |
| `border-default` | `#26313b` | Panel and control borders |
| `border-strong` | `#2f3b45` | Interactive control borders |
| `border-hover` | `#3c4a57` | Hover border |

### Text

| Token | Value | Contrast on `surface-2` | Use |
|---|---|---|---|
| `text-1` | `#e2e8ee` | 13.6:1 | Primary |
| `text-2` | `#a8b6c2` | 7.6:1 | Secondary |
| `text-3` | `#7c8b98` | 4.6:1 | Tertiary, monospace labels |
| `text-4` | `#6c7c8a` | 3.6:1 | Faintest — non-interactive hints only |

`text-4` is the floor. Anything clickable uses `text-2` or lighter: the brief cites
WCAG 2.2 AA as a requirement, and interactive affordances were measured to clear 4.5:1.

### Semantic

| Token | Value | Use |
|---|---|---|
| `error` | `#d05c4c` | Errors, lint errors |
| `error-text` | `#f0b4a0` | Error text on error surfaces |
| `error-surface` | `#1c1512` | Error panel background |
| `error-border` | `#4a2620` | Error panel border |
| `warn` | `#d1913c` | Warnings |
| `warn-text` | `#c98a3c` | Warning labels |
| `warn-surface` | `#1a1611` | Warning panel background |
| `warn-border` | `#40331c` | Warning panel border |
| `ok` | `#6f9e7c` | Valid, healthy |
| `ok-text` | `#a8d4b0` | Health values, positive deltas |
| `ok-surface` | `#101614` | Valid panel background |
| `info` | `#5b8cae` | Charts, focus rings |
| `info-text` | `#a6b6ce` | Op names in the ability builder |
| `token-ref` | `#c98a3c` | `$self`, `$target.x`, `$event.card` — DSL selectors |

### Game colours (from data, not tokens)

Read from `game-system.json` → `vocabularies.factions[].color`:
Ember `#c0392b`, Ash `#5d6d7e`, Neutral `#8d8378`. `ui.theme.accent` `#c0392b`,
`ui.theme.surface` `#191512`.

The chrome is deliberately desaturated so these are the only saturated things on screen
(brief principle 4). The card-face surfaces (`#191512` frame, `#3a2a24` inner borders,
`#f3e7dd` card text) come from `ui.theme.surface` and are game-supplied, not chrome.

Faction colour must sit legibly on any surface: the rule used here is that the faction
colour is only ever a fill behind white text or a small chip/swatch — never body text on a
dark surface. Factions also always carry a shape (chip, swatch, dot) alongside the colour,
per the accessibility requirement that state is never colour alone.

### Type

| Role | Family | Notes |
|---|---|---|
| UI sans | `Archivo` | 400/500/600/700. `font-feature-settings: 'tnum' 1` set on the app root — tabular numerals are mandatory per the brief |
| Mono | `IBM Plex Mono` | 400/500/600. Field labels, ids, schema paths, all metrics |
| Card display | `Bitter` | 500/600/700. Card faces only, never chrome |

Scale in use: 8.5, 9, 9.5, 10, 10.5, 11, 11.5, 12, 12.5, 13, 13.5, 14, 15, 16, 17, 18, 20,
21, 23, 32 px. Monospace labels are 9–9.5px uppercase with `letter-spacing: .08–.09em`.
Body copy is 12–12.5px. Section headers 13px/600.

### Spacing, radius, elevation

4px base grid. Gaps in use: 2, 3, 4, 5, 6, 7, 8, 9, 10, 12, 14, 16px.
Control heights: 18, 20, 22, 24, 26 (standard), 28, 30, 34, 38, 44 (top bar).
Radius: 2px (chips, inner rows), 3px (controls, panels), 4px (cards), 5–8px (card faces),
50% (cost badges, status dots).
Elevation: card faces `0 12px 34px -10px rgba(0,0,0,.9)`; in-play cards
`0 6px 18px -8px rgba(0,0,0,.9)`. Chrome panels have no shadow — borders only.

Density: the designs are drawn at **comfortable**. A compact mode is required by the brief
and is not yet specced; the intended mechanism is reducing row heights (30 → 26) and
section gaps (14 → 10) while leaving type sizes alone.

## Global shell

Present on all six screens. Build once.

- **Top bar**, 44px, `surface-4`, `border-default` bottom. Left to right: game mark
  (16px `#c0392b` rounded square) + game name 13.5px/600; version selector (24px pill,
  `surface-0`, amber dot for draft, `v0.4.0` + `draft` in `text-3`); 1px divider;
  breadcrumb in mono 11px; spacer; global search (200px, `⌘K` right-aligned in mono 10px);
  lint badge (`◆ 3` in `error`, `▲ 12` in `warn`); 24px avatar.
- **Left rail**, 56px, `surface-3`, collapsed to icons. Eight items in the brief's order:
  Overview, System, Cards, Sets, Decks, Playtest, Simulate, Versions. Each 38×34px, 3px
  radius. Placeholder glyphs are two-letter mono monograms (`OV`, `SY`, `CA`…) — **these
  are placeholders**; substitute the real icon set. Active item: `surface-6` fill plus a
  2px `#c0392b` inset left bar, so the active state is not colour-only.
- **Right panel**, 250–318px depending on screen, `surface-3`, `border-subtle` left. Tab
  strip 28–34px; active tab marked by a 2px `#c0392b` underline (not a colour change), so
  it reads without colour. Collapse affordance `→|` at the strip's right.
- **Footer bar**, 28–30px, `surface-1`, showing state on the left and keyboard hints on
  the right in mono 10px.

`⌘K` command palette is referenced in every top bar but **not designed**.

## Screens

### 1. Card editor — `Card Editor.dc.html`

The file holds **three visual treatments** of the same screen, laid out as an options
canvas (`design_doc_mode: canvas`). Treatment **1a "Instrument"** was chosen and is the
basis for every other screen. 1b ("Studio", warm near-black, recessed wells, IM Fell
English card face) and 1c ("Bench", achromatic, raised panels) are kept for reference and
should not be built.

Purpose: the most-used screen. Author a card and see the consequences without navigating.

Layout: rail 56 | form column (flex) | card-face pane 296 | context panel 296.
Above the columns, a 40px sub-header: card position (`Card 12 / 20`), `[` `]` prev/next
buttons, the active filter, autosave state (`Saved 2s ago · rev 14`), and
`Playtest this card`.

Form column, top to bottom:
- Card name as a borderless 21px/600 input; bottom border appears on hover
  (`border-default`) and focus (`#5b8cae`).
- Meta line: code, set, design status.
- **Identity** — Type, Faction (with faction swatch), Rarity, in a 3-column grid.
- **Attributes** — labelled `from cardTypes.character`, with each field's constraint
  printed in the label (`int 0–10`, `int ≥0`, `int ≥1`). Integer steppers are a 26px row
  with the value left and stacked ▾/▴ buttons right. The traits field is a tagList bound
  to the `traits` vocabulary, showing the unused vocabulary members as an add affordance.
  **This whole section is generated from the card type — never hardcoded.**
- **Keywords** — dashed add button listing the system's keywords including the
  parameterised `Bolster N`.
- **Abilities** — see below.
- **Generated text** — the card's rendered text, read-only, in Bitter 13.5px on
  `surface-0` with a 2px `#c0392b` left border, captioned `read-only · from a1`.

Footer tabs: Form / JSON / Text, with `⌘S save`, `⌘K palette`, `[ ] prev/next` hints.

#### Ability builder — the hard part

Doc 12 calls this out as where a naive design falls apart. The shape used:

- One card per ability, `surface-3` with a `surface-5` header. Header carries collapse ▾,
  the ability id as a mono chip (`a1`), kind and speed (`triggered · forced`), validity,
  and an overflow menu.
- The body is **sentence-shaped clause rows**: `WHEN`, `IF`, `CHOOSE`, `THEN`, each a mono
  10px uppercase `#c0392b` label in a fixed 56px gutter, with the clause content in a
  `surface-4` container.
- Nesting is expressed three ways at once so it survives depth: a **6px indent**, a **2px
  `#2a3742` left rule**, and a **background step** (`surface-4` → `#0f1418` → `#0b0f13`).
- Optional **depth badges** (`L1` `L2` `L3`) in the left gutter, toggleable via the
  `showDepthBadges` prop. Worth keeping as a user preference.
- Every value is a chip: enum/selector chips are `surface-0` with `border-strong` and a ▾,
  hovering to `#5b8cae`; op names are `surface-5` with `info-text`; DSL selectors
  (`$self`, `$event.card`, `$target.victim`) are `token-ref` amber so they read as
  references, not literals.
- Live feedback inline: the target query row shows `4 legal now`, and a missing
  `controller` is rendered as a dashed amber `any` chip that doubles as the lint anchor.

Worked example is `core-012` Flamecaller Adept: `card.entered_zone` / `after`, an `and` of
two `eq` predicates (level 3), a target with a query and prompt, and a `deal_damage`
effect. The system-editor screen shows the same recursive component rendering an
expression (`min(add(round(), 1), 6)`) rather than an effect — one component, both cases.

Context panel tabs: **Lint** (default), Similar, Revisions, Notes. Lint shows two real
warnings — `art.assetId` is null, and the target query has no `controller` — each with the
schema path and a fix action, plus a `valid` row and a balance-context block.

### 2. Play table — `Play Table.dc.html`

Purpose: the showcase screen. Must feel like a game, not a form.

Layout: identity/resource column 126 | board (flex) | log/inspector panel 288.
Board rows come from `ui.board.rows`, top to bottom: opponent play, shared out-of-play
(collapsed), own play. **The design is a system for N rows, not a fixed picture** — each
row is a labelled band with a header line (label, card count, state note) and a
horizontal card strip.

- **Phase rail**, 46px, generated from `round.phases`. Each phase is a column showing its
  name and its steps; completed steps are struck through, the current step is lit with a
  dot and 600 weight, inactive phases sit at 50% opacity. The current phase gets a
  `surface-5` fill and a 2px `#c0392b` bottom bar. At the right, a distinct
  `#191316` panel states whose decision it is.
- **Cards in play**, 88px wide. Exhausted cards are rotated 90° **and** carry an
  `exhausted` badge — the brief forbids encoding exhaustion in rotation alone. Damage
  counters are `#c0392b` 5px pips bottom-right (`counters.damage`, `visual: pip-red`).
  Attachments are tucked under the host as a small labelled strip. Attackers carry an
  `ATTACKING` badge plus a `#6b3327` border and red glow.
- **Modified values** are shown with a delta: `4` with a small amber `+1` beside it, so
  printed and current values are distinguishable at a glance.
- **Hand dock**, fanned with ±3° rotations, unplayable cards at 42% opacity, with a
  `why?` affordance. Deck and discard sit bottom-right per `ui.board.docks`.
- **Choice prompt** — the key design problem. It is **not a modal**. It is a docked bar
  directly above the action bar, `#1a1512` with a 3px `#c0392b` left bar, carrying the
  prompt id (`declare_block · blocker`), the server's own prompt text, a plain-language
  clarification, and one button per legal choice plus `Esc cancel`. Legal targets on the
  board wear a 2px `#c98a3c` ring with a 3px glow; illegal cards drop to 40% opacity. The
  board stays the context for the decision.
- **Action bar**, 26px controls, numbered hotkeys (`1`, `2`), Pass, Undo, End phase.
- **Right panel** — Log / Card / Notes. The log is generated prose grouped by round and
  phase with card links underlined in `border-hover`, the current waiting state pinned at
  the bottom. The Card tab is the **modifier breakdown**: `Printed 3` / `Warhorn Bearer ·
  static +1` / `Current 4`, plus the layer and the query that produced it
  (`while_source_in_play · query traits.any Soldier, exclude $self`). Design this well and
  it becomes the tool designers live in.

### 3. System editor, Phases & Steps — `System Editor.dc.html`

Layout: rail 56 | editor column (flex) | impact panel 318.
A 34px horizontal tab strip carries all twelve system tabs; a 38px settings row holds
`structure`, `first player`, `trigger order`, the schema-validity indicator, and the
**Editor / JSON** toggle required on every tab.

- **Round structure**: four equal phase columns, each a `surface-3` card with a draggable
  `surface-5` header. Steps are ordered cards inside, numbered, badged `auto` (green) or
  `window` (blue) with their ops or window type printed underneath. `+ step` and `+ phase`
  are dashed affordances.
- **Step detail** below the board: name, an `auto`/`window` segmented control, and the
  automatic effect rendered by the same recursive node component as the ability builder —
  `for_each_player` → `gain_resource` → the `min(add(round(), 1), 6)` expression at L3 —
  followed by a live evaluation strip (`r1 → 2`, `r3 → 4`, `r9 → 6`) and the generated
  rulebook line.
- **Impact panel** — the other hard problem. A step marked for deletion is struck through
  and outlined in `#c0392b` in the board, and the panel opens **before anything is
  committed**: a header stating the consequence in the game's own terms ("Nothing else in
  the system grants Ember"), then the enumerated evidence — 3 actions unpayable with their
  `pay_resource` costs, 16 of 18 cards carrying an Ember cost with a type breakdown bar,
  2 hero abilities, 3 replays and saved matches that would stop reproducing, 1 bot profile
  to retune — then a cheaper alternative, an acknowledgement checkbox, `Delete anyway` /
  `Discard change`, and the version consequence (`0.4.0 → 0.5.0 (major)`).

### 4. Card browser — `Card Browser.dc.html`

Layout: rail 56 | facet sub-rail 184 | table (flex) | completeness panel 250.

- **Facets**: type, faction, cost (as a histogram with a range readout), traits, keywords,
  design status, set. Every facet carries a live count. Checked facets use a filled
  `#c0392b` box with a check glyph.
- **Toolbar**: Grid/Table toggle, the structural query rendered as chips
  (`cost<=3`, `trait:Soldier`, `type:character`) labelled shareable, result count,
  compact/comfy density toggle, `columns`, `New card`.
- **Table**: 26px header, 30px rows, columns drawn from the game's own attributes
  (Code, Name, Type, Faction, Cost, Atk, Hp, Traits, Keywords, Ab, Status). Cards outside
  the active filter appear below a labelled divider at 62% opacity with a `show 14`
  affordance, so filtering never hides that more exists.
- **Bulk selection**: a 34px `#1a1512` bar replaces the toolbar's role when rows are
  selected, offering `Set status…`, `Move to set…` and a stat patch. Choosing the patch
  opens a **diff preview above the table** — one card per column, old value struck
  through, new value in `ok-text`, plus a per-card consequence note — with
  `Apply to 3 cards` / `Cancel`. Nothing is written until Apply.
- **Completeness panel**: authored-vs-planned bars per type from the set's `design.budget`,
  an authored-by-cost histogram, and the set's `design.goals` as a checklist. It surfaces a
  real gap: the budget asks for 11 characters and 9 exist.

### 5. Deck builder — `Deck Builder.dc.html`

Layout: rail 56 | pool 466 | deck list 396 | analysis panel 306.

- **Pool**: a 4-up grid of card thumbs with a faction stripe at the bottom edge and a
  **copy-limit indicator** top-right (`3/3` red, `2/3` amber). Illegal factions are struck
  through in the filter row, and the 7 withheld Ash cards are explained in a panel at the
  end of the grid rather than silently absent.
- **Deck list**: grouped by card type with per-group counts, each row carrying quantity,
  a faction-coloured cost badge, name, a stat or status chip, and `−`/`+` steppers that
  disable at the copy limit. The illegal card is highlighted with a `#d05c4c` inset bar.
- **Analysis panel**: Legality / Shape / Versions.
  - Legality leads with the violation stated in the game's own words — "Every card must
    match your hero's faction or be neutral." — followed by the offending card with the
    reason (`Ash ≠ Ember`) and two ways out. Below it, the four deckbuilding rules from
    `deckbuilding` with pass/fail marks that use a glyph as well as colour.
  - Shape: avg cost, count, top end, curve, type split, trait density, and one written
    observation.
  - Versions: `+1 Ashen Vanguard` style diffs against previous deck versions.
- Footer: `Playtest vs bot` / `Simulate vs…`.

### 6. Simulation report — `Simulation Report.dc.html`

Layout: rail 56 | report column (flex) | findings panel 318.

- **Headline**: the win rate at 32px with the interval at 15px beside it in `warn-text`,
  then the interval plotted on a 40–70% axis with the 50% line marked. Doc 09 requires the
  interval to be as prominent as the number; a sentence states what it means. Alongside:
  first-player win rate, delta against the previous batch, and the deck × deck matrix with
  a CI in every cell and a note that mirrored seeds already cancel first-player advantage.
- **Distributions**: game length histogram with mean/p10/p90 and a unimodal marker; end
  reasons named after the real win conditions (`hero_burned`, `deck_out`, `round_limit`)
  with the round-cap rate checked against the 5% `STALL` threshold.
- **Card telemetry**: doc 09's six metrics as sortable columns, with outliers flagged by a
  glyph in a leading gutter plus a tinted row — never colour alone. A footnote explains
  why a wide interval is not evidence.
- **Findings panel**: one card per finding using doc 09's codes (`OVERPERFORMER`,
  `DEAD_CARD`, `COST_REGRESSION`), each an explained statement with its evidence and named
  replay links. A `CLEARED` card states what did **not** fire, and the panel closes with
  the guardrail that a bot is not a player. Setup and Runs tabs carry the pinned
  `(game version, deck versions, bot profile)` triple and the mirrored-seed run pairs.

## Interactions & behaviour

Implemented in the prototypes:
- Right-panel tab switching on all six screens (React `useState` equivalent — one `tab`
  string per screen).
- Ability-card collapse in the card editor (`openA`/`openB`/`openC`).
- Bulk-patch diff preview toggle in the card browser (`showPatch`).
- Hover states on every control, via `style-hover`.

Specified but not implemented — build these:
- Drag to reorder phases and steps; drag to reorder effects within a `sequence`.
- Targeting mode: selecting an action that needs targets rings legal targets and dims
  everything else; `Esc` cancels. Legality comes from `legalActions` or a lazy
  `legal-targets` fetch — never computed client-side.
- Keyboard: `⌘S`, `⌘K`, `[` / `]` for prev/next card, `N` to file a note, `?` for the
  hotkey sheet, number keys for the action bar, `Tab`/arrows to traverse zones.
- Autosave with the visible `Saved · rev N` indicator, and undo offered in a toast rather
  than a confirm dialog.
- Animation: the event queue drains to transitions (`card.entered_zone` → FLIP move,
  `damage.dealt` → hit flash + counter tick); board state commits only after the queue
  drains. Respect `prefers-reduced-motion` by making transitions instant while the event
  log still narrates. **Durations and easing are not yet specced** — the brief asks for a
  motion spec covering move, draw, damage, exhaust, destroy.

## State management

Per `docs/11-frontend-architecture.md`, the Pinia stores already define what these screens
need. Nothing in these designs requires state beyond them:

- `game` — the compiled schema/form-descriptor bundle. This is what makes the attribute
  form, the card face, the phase rail and the table columns self-building.
- `cards` — index, filters, selection, draft edits. The browser's facets and the editor's
  `[` `]` navigation both read the same filter.
- `decks` — current deck version and live legality.
- `match` — `view`, `legalActions`, `pendingChoice`, `eventQueue`. The play table's dimming,
  rings, action bar and choice prompt are all projections of these. No local legality.
- `simulation` — batches, progress, metrics.
- `ui` — theme, panel layout per route, density, recent items, hotkey scope. Persisted.

## Assets

**None.** No icons, images or fonts are bundled, because the repository has no asset
directory and every `art.assetId` in `sets/core.json` is null.

Placeholders that must be replaced:
- **Nav icons** — two-letter mono monograms (`OV`, `SY`, `CA`, `SE`, `DE`, `PL`, `SI`,
  `VE`). Substitute the real icon set. The brief requires one consistent set that can
  coexist with unknown third-party glyphs for factions and counters.
- **Card art** — `repeating-linear-gradient(135deg, …)` stripes at 6px/12px with a mono
  caption (`card art · 4:3`). Replace with the lazy-loaded WebP + blurhash pipeline.
- **Faction and counter icons** — `vocabularies.factions[].icon` names `flame`, `cinder`,
  `circle`, and `counters[].visual` names `pip-red`, `pip-blue`. The designs render
  factions as coloured chips and counters as coloured pips; the named glyphs are not drawn.

Fonts are Google Fonts (`Archivo`, `IBM Plex Mono`, `Bitter`). Self-host them.

## Data provenance

Every value on screen traces to one of these:

| File | What the designs take from it |
|---|---|
| `docs/12-ui-design-brief.md` | Screen requirements, shell, principles, accessibility |
| `docs/11-frontend-architecture.md` | Component tree, stores, schema-driven forms, targeting |
| `docs/09-automation-and-balance.md` | Metric names, finding codes, seed strategy, guardrails |
| `examples/emberfall/game-system.json` | Zones, phases and steps, resources, counters, card types and their attribute constraints, keywords, actions and costs, state checks, win conditions, deckbuilding rules, `ui.board`, `ui.theme`, faction colours |
| `examples/emberfall/sets/core.json` | All 18 cards, their stats, abilities, keywords, design status, and the set's `design.budget` and `design.goals` |
| `examples/emberfall/decks/ember-aggro.json` | The 24-card deck list and its note |
| `examples/emberfall/bots/heuristic-aggro.json` | The `unspentEmber` feature and its weight |
| `examples/emberfall/replays/round-one-opening.json` | Referenced as the conformance replay |

Numbers computed rather than read (curve, type split, trait density, averages, completeness
percentages) are derived from those files and are stated in the screens they appear on.

## Known gaps

Ordered by how much they block implementation:

1. **Light theme.** Required by the brief as equally complete. Not designed.
2. **Compact density mode.** Required and user-selectable. Only comfortable is drawn.
3. **Responsive behaviour.** Only 1280 is designed; 1600, 1920 and tablet landscape for the
   play table are not.
4. **Motion spec.** Durations and easing for card move, draw, damage, exhaust and destroy,
   plus reduced-motion variants.
5. **Command palette (`⌘K`).** Referenced everywhere, designed nowhere.
6. **Component sheet.** The brief asks for buttons, inputs, selects, tags, tables, tabs,
   panels, dialogs, toasts and empty states as a standalone deliverable. These screens
   imply all of them but do not enumerate them.
7. **Empty states and skeletons.** Every list needs an empty state that teaches the next
   action, and skeletons matching final layout.
8. **Screens not yet designed:** dashboard, game overview, set manager, playtest setup,
   replay viewer, version history & diff, notes & triage, simulation setup and progress.
9. **Ability builder beyond three levels.** Three is proven; four is not tested.
10. **Card face at thumb and standard sizes.** Only `full` is drawn in detail; the browser
    and pool use simplified thumbs.

## Files

| File | Screen |
|---|---|
| `Card Editor.dc.html` | Card editor, three visual treatments (build **1a** only) |
| `Play Table.dc.html` | Play table |
| `System Editor.dc.html` | System editor, Phases & Steps tab |
| `Card Browser.dc.html` | Card browser |
| `Deck Builder.dc.html` | Deck builder |
| `Simulation Report.dc.html` | Simulation batch report |

Each is a self-contained HTML file: open it directly in a browser. They are written as
"Design Components" — a `<x-dc>` template plus a small logic class, wired by `support.js`.
`support.js` is a preview runtime and is **not** part of the design; ignore it.

To read a screen's structure, read the template markup inside `<x-dc>`. Styling is entirely
inline, deliberately, so every value is visible at the element that uses it.
