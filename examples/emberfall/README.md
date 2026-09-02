# Emberfall — worked example

A small, complete two-player duel LCG defined entirely in the platform's data formats.

It exists to make the specification concrete: every claim in [`docs/`](../../docs/) is backed
by a file here, the UI can be designed against realistic data, and the rules kernel has a
first conformance target.

```
game-system.json              The whole game: zones, phases, resources, card types,
                              keywords, actions, state checks, win conditions, deckbuilding
sets/core.json                18 cards (2 heroes, 9 characters, 5 events, 2 attachments)
decks/ember-aggro.json        24-card aggro deck
decks/ash-control.json        24-card control deck
bots/heuristic-aggro.json     A heuristic agent, tuned in JSON
replays/round-one-opening.json  An illustrative replay
```

Validate everything (schemas plus cross-document integrity):

```bash
npm install
npm run validate
```

---

## The game in one page

Two heroes, 20 health each. Reduce the opponent's hero to zero health, or make them draw
from an empty deck.

**Round structure**

| Phase | Steps |
|---|---|
| Refresh | ready all cards → gain Ember (`min(round + 1, 6)`, does not carry over) → each player draws 1 |
| Action | alternating priority; play characters, attachments and events until both pass |
| Combat | declare attackers → declare blockers → simultaneous strike |
| End | round-duration modifiers expire → hand size enforced to 7 |

**Factions.** Ember is tempo and reach (cheap bodies, `Swift`, burn). Ash is attrition
(`Guard` walls, card draw, removal). Neutral cards are legal in both.

**Keywords**

| Keyword | Meaning |
|---|---|
| `Swift` | May attack the round it enters play (grants the `attack_while_summoning_sick` permission) |
| `Guard` | While ready, enemy characters must attack it first (the `must_be_attacked_first` restriction) |
| `Bolster N` | On entering play, another friendly character gets +N attack this round (a parameterised keyword) |

---

## What each part of the example demonstrates

| File / card | Demonstrates |
|---|---|
| `game-system.json` → `round.phases` | Auto steps vs. player windows; expressions in an auto step (`min(round+1, 6)`) |
| `game-system.json` → `deckbuilding.constraints` | The same expression language used for deck legality as for abilities |
| `game-system.json` → `stateChecks` | Lethal damage as data, not a hard-coded engine rule |
| **Ignis** (`core-001`) | An activated ability with a compound cost and a once-per-round limit |
| **Cinder Scout** (`core-010`) | A keyword granting a permission |
| **Flamecaller Adept** (`core-012`) | An enters-play trigger with a required target |
| **Warhorn Bearer** (`core-013`) | A static modifier over a query, with `exclude: ["$self"]` |
| **Scorch** (`core-014`) | An event: ability triggered by `card.played` on itself |
| **Ember Brand** (`core-016`) | An attachment modifying its host via the `$host` selector |
| **Dust Weaver** (`core-023`) | A parameterised keyword, and an *optional* target that resolves silently when nothing is eligible |
| **Smother** (`core-024`) | A query with a `where` predicate reading a **modified** attribute |
| **Grey Tide** (`core-025`) | `for_each` over a query |
| **Warding Cloak** (`core-026`) | A health modifier interacting with damage already marked |
| **Salvage** (`core-031`) | `choose_cards` from a hidden-ish zone with a follow-up effect |

## Engine behaviours the example deliberately pins

These are the interactions that a card game engine gets wrong first, so the example is
built to exercise them:

1. **Modified vs. printed values.** Smother destroys "a character with 2 or less attack".
   A Cinder Scout (printed 2 attack) carrying an Ember Brand has 4 attack and is **not** a
   legal target. Queries read current values, from the modifier layer stack.
2. **Health modifiers and existing damage.** Warding Cloak raises health while damage stays
   marked on the card, so the `lethal_damage` state check re-evaluates rather than the
   engine "healing" anything.
3. **Optional targets with no candidates.** Dust Weaver's Bolster raises no prompt when it
   is the only friendly character — it must not stall waiting for an impossible choice.
4. **Summoning sickness as a permission.** Nothing in the kernel knows about "the round a
   card entered play" as a rule; it is a requirement on `declare_attack`, and `Swift` grants
   the permission that bypasses it.
5. **Simultaneous strike.** Combat damage is dealt in one event batch, so mutual destruction
   resolves through one pass of the state checks.
6. **Round-duration modifiers.** Bolster and Second Wind expire in the End phase, not when
   their source leaves play.

## Notes and known rough edges

* **Events and `card.played`.** Playing an event moves it to the discard pile and emits
  `card.played`; the event's own ability triggers on that event and resolves afterwards. So
  an event's ability resolves while the card is already in the discard pile. This is a
  deliberate simplification — a "resolving" pseudo-zone would be more faithful, and is a
  candidate for the format's 1.1.
* **`resolve_combat` is a parameterised built-in**, not a composition of primitive ops. Full
  combat *can* be written in primitives, but it is long and unreadable, and combat is
  structurally similar across the games this platform targets. The system document
  configures it (`model`, `damageAttr`, `healthAttr`, `damageCounter`, `unblockedTarget`)
  rather than reimplementing it. If a game needs combat this doesn't cover, that's a signal
  to add another `model`, not to fork the kernel.
* **The replay carries no `expected` hashes yet.** They get filled in by
  `php artisan test:add-replay` once the kernel exists. The replay deliberately omits
  `initialState` too: given the game version, the decks and the seed, the initial state is
  *reconstructible* — which is the determinism guarantee stated in
  [ADR-0005](../../docs/adr/0005-determinism-and-replay.md), demonstrated rather than
  described.
* **Balance is unexamined.** The numbers here are plausible, not tested. Working out
  whether Ember Aggro actually beats Ash Control is exactly the job of
  [doc 09](../../docs/09-automation-and-balance.md), and it cannot be done until the kernel
  runs.
