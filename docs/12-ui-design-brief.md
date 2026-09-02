# 12 — UI Design Brief

> **Six screens from this brief have been designed** — see [`design/`](../design/) and
> [`design/HANDOFF.md`](../design/HANDOFF.md) for tokens, per-screen specs and known gaps.
> They cover the competitive duel shape; the cooperative play table (§8, and
> [doc 16](16-cooperative-and-adversary-games.md)) is still open.
>
> **This document is the brief for the UI design pass.** It describes what each screen must
> do, what data it has, and the constraints that matter. It does not prescribe visual style
> beyond the principles below — that's the design work.

Read [11 — Frontend architecture](11-frontend-architecture.md) for the technical shape, and
[`examples/emberfall/`](../examples/emberfall/) for realistic data to design against.

---

## Who is using this, and how

**The designer, mid-flow.** Long sessions. Keyboard-heavy. Constantly moving between "edit
this card" and "see what it does". The enemy is anything that breaks the loop: modal
dialogs that lose context, saves that navigate away, panels that collapse when you switch
tabs.

**The playtester, occasionally.** Drops in for an hour. Needs to understand a game they
have never seen from the interface alone, and needs to report what went wrong without
writing an essay.

Design for the first; don't lock out the second.

## Design principles

1. **The data is the interface.** Almost every screen is generated from the game system
   document. Layouts must survive a game with 3 card attributes and one with 14, four
   zones or nine, one resource or three.
2. **Author and result, side by side.** Editing a card should show the rendered card. Editing
   an ability should show the generated text. Never make someone save-and-navigate to see
   consequences.
3. **Density is a feature.** This is a professional tool, not a landing page. Show more,
   scroll less. Compact rows, tight tables, real information density — with enough breathing
   room that it doesn't read as noise.
4. **The game gets the colour.** Chrome is neutral and quiet so that faction colours, card
   art and board state are the only saturated things on screen. The tool should recede.
5. **Nothing is lost.** Autosave with visible state, undo everywhere, revision history one
   click away. A designer must never hesitate before making a change.
6. **Legality is explained, never just enforced.** Every "you can't do that" comes with
   "because…", stated in the game's own vocabulary.

## Foundations to define

* **Two themes, dark default.** Long editing sessions and a game board both favour dark;
  light must be equally complete, not an afterthought.
* **Token layer**: surface (4 elevations), border, text (primary/secondary/tertiary),
  accent, and semantic status (success/warn/error/info). Faction colours come from *game
  data* and must sit legibly on any surface — the palette needs a documented rule for that.
* **Type**: one UI sans (dense, excellent at 12–14px, tabular numerals mandatory — stats
  and metrics are everywhere), plus one display face available for card faces only.
* **Spacing**: 4px base grid.
* **Icons**: one consistent set. Cards, zones, phases, keywords and counters all need
  glyphs; games supply their own icons for factions and counters, so the set must coexist
  with unknown third-party glyphs.
* **Density modes**: comfortable / compact, user-selectable, honoured across all tables and
  grids.

## Global shell

* **Left rail** — workspace switcher, then game nav: Overview · System · Cards · Sets ·
  Decks · Playtest · Simulate · Versions. Collapsible to icons.
* **Top bar** — game name, **version selector** (draft/published, with a clear "you are
  editing a draft" state), global search (`⌘K`), lint badge (count of errors/warnings,
  click to open), user menu.
* **Right panel** — a persistent, resizable, dockable inspector. Its content is contextual
  (card preview, validation, revision history, notes). Collapsible; state remembered per
  route.
* **Command palette** (`⌘K`) — go to card, create card, run simulation, open replay, switch
  version. The primary navigation method for experienced users.

---

## Screens

### 1 · Dashboard

Games as cards: name, card count, version, last activity, a sparkline of recent simulation
win rates. Recent activity feed (card edits, published versions, completed batches,
playtest notes needing triage). Primary action: New game — from blank or from a starter
template.

### 2 · Game overview

Health at a glance: card count by type and status, set completeness bars, open lint issues,
recent playtest results, latest balance findings. This is the "what should I work on"
screen; it should answer that in one look.

### 3 · System editor  ⭐ *hardest layout problem*

Tabbed editing of the game system document: **Zones · Phases & Steps · Resources ·
Counters · Card Types · Keywords · Actions · State Checks · Win Conditions · Deckbuilding ·
Board Layout · Rules Text**.

Requirements:

* Each tab is a structured editor over a slice of the document (list + detail).
* **Phases & Steps** needs a visual round-structure editor: phases as columns, steps as
  ordered cards within them, drag to reorder, click a step to edit its automatic effect
  script or window type. This is the game's spine — make it legible at a glance.
* **Card Types** is a schema builder: add attributes, pick types, set ranges, choose where
  they appear on the card face. A live mini card preview showing where each attribute lands
  is very valuable here.
* **Keywords** pairs an ability builder with reminder text and a live "cards using this: 14"
  count.
* A **JSON view** toggle on every tab, editable, with a persistent validity indicator.
* Persistent **impact warnings**: "removing this zone would invalidate 12 cards and 3 saved
  matches" — shown *before* the change is committed, with a list.

### 4 · Card browser

* Two view modes: **grid** (rendered card faces) and **table** (dense, sortable, with
  configurable columns drawn from the game's attributes).
* Faceted filters in a left sub-rail: type, faction, cost range, traits, keywords, status,
  set. Filters compose and are reflected in the URL (shareable).
* Full-text + structural search: `cost<=3 trait:Soldier has:deal_damage`.
* Multi-select → bulk actions (change status, move set, apply a stat patch) with a **diff
  preview before applying**.
* Set completeness widget: authored vs. planned per type and cost.
* Inline quick-edit of simple attributes directly in the table.

### 5 · Card editor  ⭐ *the most-used screen*

Three-pane: **form** (left/centre) · **live card render** (right) · **validation & context**
(right panel, tabbed: Lint · Similar cards · Revisions · Notes · Telemetry).

* Attribute form is generated from the card type — never hardcoded.
* **Ability builder** (see [doc 06](06-effect-dsl.md)) as nested, sentence-shaped rows:
  `WHEN … IF … COST … CHOOSE … THEN …`. Add/remove/reorder effects, collapse deep nodes.
  Needs to stay readable three levels deep — that is the real design challenge here.
* Generated card text renders live beneath the abilities, so text and behaviour are visibly
  one thing.
* JSON tab for the whole card, round-trip lossless.
* Autosave with a clear indicator; every save is a revision with an optional message.
* Keyboard: `⌘S` save, `⌘K` palette, `[` / `]` previous/next card in the current filter — a
  designer should be able to sweep a whole set without touching the mouse.
* **Playtest this card** button → opens a sandbox table with the card in hand.

### 6 · Deck builder

Split view: **card pool** (left, same filters as the browser) · **deck list** (right,
grouped by type, with counts and quick +/−).

* Live legality panel: violations listed in plain language, each linking to the offending
  cards.
* Curve chart, type split, trait density, average cost — updating as you build.
* Copy-limit indicators on pool cards (`2/3 used`).
* Deck versions with diff ("+2 Vanguard, −1 Cinderpriest").
* One-click: playtest this deck vs. a bot, or simulate it against another deck.

### 7 · Playtest setup

Choose game version, mode (solo/hotseat/online/sandbox), seats (human or bot profile), decks
per seat, optional seed, and options (mulligan, reveal-all, animation speed). Prominent
**"Reuse seed from…"** so a designer can re-test the exact same opening after a card change.
Online mode produces an invite link.

### 8 · Play table  ⭐ *the showcase screen*

The screen that has to feel like a game, not a form. Layout comes from `ui.board`, so the
design must be a *system* for arranging N zone rows, not a fixed picture.

Must show, at all times: whose decision it is, what phase/step we're in, what the player can
do, and what just happened.

* Board rows with cards in play (exhaust rotation, damage/counter pips, attachments tucked
  under hosts, controller indication).
* Hand dock, fanned, with unplayable cards clearly dimmed.
* Resource tray and identity/hero card per player.
* Phase rail generated from the game's own round structure, current step lit.
* Action bar: contextual actions, Pass, End phase, Undo.
* Choice prompts as a focused overlay that **does not hide the board** — the board is the
  context for the decision. This tension is the key design problem of the screen.
* Event log: generated prose with card links, filterable, click to scrub.
* Card inspector: full render plus a **modifier breakdown** ("Attack 3 = 2 base + 1 from
  Warhorn"). Design this well and it becomes the debugging tool designers live in.
* Playtester tools: `N` to file an anchored note, "why can't I play this?" on right-click,
  reveal-all in sandbox.
* Needs to work at 1280px wide and stay usable on a tablet in landscape.

**Cooperative layout is a second, harder case.** The same screen must also render 1–4
players against an adversary area (villain, main scheme with a threat track, engaged enemies
per player). At four players there is far more board than fits, so this needs a focus /
overview mode rather than a bigger grid; the threat track is the game's clock and must be
readable at a glance, including how much lands next villain phase; and the villain phase
executes a script against several players in sequence, which needs pacing and narration so
players can follow what just happened to them. See
[doc 16](16-cooperative-and-adversary-games.md#what-changes-in-the-ui).

### 9 · Replay viewer

The play table in read-only mode plus a **scrubber**: timeline of actions with round/phase
markers, keyboard stepping, jump-to-event, filter the log to one card's involvement, and
"fork from here". Share produces a read-only link — this is the bug report format.

### 10 · Simulation lab

* **Setup**: matchups (deck × bot per seat), run count, seed strategy, round cap, log
  retention. Presets for common runs.
* **Running**: live progress, throughput, partial win rates with widening/narrowing
  confidence intervals, cancel.
* **Report**:
  * headline win-rate matrix (deck × deck heatmap, with CIs)
  * game-length distribution
  * first-player advantage
  * **card telemetry table** — play rate, win-rate-when-played vs. baseline, mean first
    round played, discarded-unplayed; sortable, with outliers visually flagged
  * **findings list** — each an explained statement with evidence and replay links
  * compare against another batch (before/after a change) with deltas

Charts must read at a glance and survive dark and light. Confidence intervals are not
optional decoration — a win rate without one invites the wrong conclusion, so the design
must make the interval as prominent as the number.

### 11 · Version history & diff

Timeline of system versions and publishes. Structured diff view: system changes grouped by
section, card changes grouped by card with before/after card faces side by side. Change
classification badges (patch/minor/major) and the impact report. Generated changelog,
copyable.

### 12 · Notes & triage

All playtest notes across matches: filter by kind, card, status. Each note shows its
anchored game state (thumbnail of the board at that moment) and jumps to the replay. Convert
a note into a card edit or a regression test in one action.

---

## Cross-cutting patterns to design once

| Pattern | Notes |
|---|---|
| **Card face** | Three sizes (thumb/standard/full). Must render from arbitrary attribute sets and templates. |
| **Card grid / card table** | Reused in browser, deck builder, pool, search results |
| **Ability node** | Recursive; must stay legible when nested |
| **Expression editor** | Small inline builder for values and predicates; appears inside abilities, bot features and constraints |
| **Diff view** | Cards, decks, system sections — one visual language for "what changed" |
| **Legality / lint panel** | Errors vs. warnings, grouped, each linking to its source |
| **Empty states** | Every list needs one that teaches the next action |
| **Loading** | Skeletons matching final layout; never a spinner that shifts content |
| **Toasts & undo** | Destructive actions offer undo in the toast rather than a confirm dialog |

## Design deliverables

1. Token set (colour, type, spacing, elevation, motion) in both themes.
2. Core component sheet: buttons, inputs, selects, tags, tables, tabs, panels, dialogs,
   toasts, empty states.
3. High-fidelity screens: **card editor**, **play table (duel)**, **play table (4-player
   co-op)**, **simulation report**, **system editor (phases tab)**, **deck builder**,
   **card browser**.
4. The card face component with a worked Emberfall example.
5. The ability builder at three nesting depths (this is where a naive design falls apart).
6. Responsive behaviour: 1280 / 1600 / 1920, plus tablet landscape for the play table.
7. Motion spec for the table: card move, draw, damage, exhaust, destroy — durations and
   easing, plus the reduced-motion variants.

## Constraints

* Vue 3; tokens as CSS custom properties so themes swap without a rebuild — and so `--accent`
  can be set from the loaded game's `ui.theme.accent` at runtime, which is the one thing in
  these mockups that must not be hardcoded.
* No design that requires the client to know game rules.
* Every screen works with **any** game defined in the JSON format — nothing Emberfall-specific
  in the chrome.
* Accessibility targets in [doc 11](11-frontend-architecture.md#accessibility) are
  requirements, not aspirations. In particular: never encode state in colour alone, and
  never encode exhaustion in rotation alone.
