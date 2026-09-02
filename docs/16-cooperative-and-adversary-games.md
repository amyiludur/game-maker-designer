# 16 — Cooperative & Adversary Games

Games where 1–4 players play *together* against an automated opponent: Marvel Champions,
Arkham Horror LCG, LOTR LCG, Spirit Island. The opponent is not a bot — it is a deck plus a
set of deterministic rules, and it must be defined in data like everything else.

This document specifies what the platform needs beyond the competitive-duel model in
[doc 04](04-game-system-spec.md).

> **Status.** This is a first-class supported shape, not an afterthought. The schemas carry
> it and [`examples/wardens-hollow/`](../examples/wardens-hollow/) proves it. The competitive
> duel ([`examples/emberfall/`](../examples/emberfall/)) and the co-op scenario are two
> configurations of one format.

---

## The shape of the problem

Take Marvel Champions as the reference. What it needs that a duel does not:

| Requirement | Why the duel model can't express it |
|---|---|
| An opponent with no seat | `players[]` is seats; `owner`/`controller` were seat integers |
| A villain that acts by rulebook, not by choosing | Bots *choose among legal actions*; a villain *executes a script* |
| An encounter deck dealt to each player | No concept of a deck belonging to a non-player |
| Cards with two faces (hero ↔ alter-ego, villain stage I ↔ II) | One card, one flat attribute set |
| Values that scale with player count (villain health "×N") | Attributes are literal integers |
| Everyone loses together | `outcome` was `{winner, loser, draw}` — pairwise |
| Enemies engaged with a *specific* player | An enemy is in a player's area but controlled by the villain |
| A first-player token rotating around the table | `firstPlayer.rule: "alternate"` is two-player |
| Scenario setup from modular encounter sets | `deckbuilding` describes player decks only |

Nine concrete gaps. None of them are architectural — the kernel's event bus, stack, modifier
layers, state checks and determinism are all indifferent to who is acting. They are all
*format* gaps, and they are closed by the additions below.

---

## 1. Sides: generalising "player"

The single change that unlocks most of the rest.

`GameState.instances[].owner` and `.controller` are **side ids** (strings), not seat
integers. A side is any participant that can own cards, hold zones and act:

| Side id | Kind |
|---|---|
| `p0`, `p1`, … | Human or bot players, one per seat |
| `villain`, `encounter`, … | Adversaries, declared in the system document |

Player seats remain integers in `players[]`; their side id is `p{seat}`. Everything that
took a seat now takes a side id, and zone keys stay as they were (`p0.hand`,
`villain.deck`, `shared.removed`).

This is a **deliberate revision** to [doc 07](07-rules-engine.md)'s state schema. It is
strictly more general and costs nothing in the duel case.

### Declaring adversaries

```json
"adversaries": [
  {
    "id": "villain",
    "name": "Villain",
    "controlledBy": "engine",
    "zones": ["villain_deck", "encounter_deck", "encounter_discard"],
    "anchors": [
      { "id": "boss",       "type": "villain",     "required": true },
      { "id": "mainScheme", "type": "main_scheme", "required": true }
    ],
    "activation": [ /* effect script — see §3 */ ]
  }
]
```

**Anchors** are named, persistent instances the adversary always has: the villain card
itself, the main scheme. They are addressable as `$adversary.boss` and
`$adversary.mainScheme` from any ability, which is how a player card says "deal 2 damage to
the villain" or "remove 1 threat from the main scheme" without knowing the scenario.

`controlledBy: "engine"` means no `pendingChoice` is ever raised for this side. When the
adversary must decide something, the rule is in its script — that is what makes an
automated opponent reproducible rather than an opinion.

---

## 2. The adversary is a script, not a bot

The most important design point in this document.

A **bot** (doc 09) receives a `PlayerView` and a list of legal actions and *chooses*. It is
a strategy, and two bots may play the same board differently.

An **adversary** executes a fixed script. The villain phase in Marvel Champions is not a
decision: the villain attacks each hero-form player, schemes against each alter-ego-form
player, and each player is dealt an encounter card. That is a rulebook procedure, and it
belongs in the system document, in the same effect DSL as everything else:

```json
"activation": [
  { "op": "for_each_player", "order": "turn", "do": [
      { "op": "if",
        "cond": { "op": "eq",
                  "left": { "op": "attr", "of": "$player.identity", "attr": "form" },
                  "right": "hero" },
        "then": [
          { "op": "deal_damage",
            "target": "$player.identity",
            "amount": { "op": "attr", "of": "$adversary.boss", "attr": "attack" },
            "source": "$adversary.boss",
            "defendable": true }
        ],
        "else": [
          { "op": "add_counter",
            "card": "$adversary.mainScheme",
            "counter": "threat",
            "amount": { "op": "attr", "of": "$adversary.boss", "attr": "scheme" } }
        ] }
  ]},
  { "op": "for_each_player", "order": "turn", "do": [
      { "op": "reveal_encounter", "player": "$player", "count": 1 }
  ]}
]
```

Consequences worth being explicit about:

* **The scenario is deterministic given the seed.** Two runs of the same scenario with the
  same seed and the same player actions are identical. Simulation measures the *scenario's*
  difficulty, not a bot's competence at playing it.
* **There is no adversary AI to tune, and no adversary AI to blame.** If the villain feels
  wrong, the script or the numbers are wrong.
* **Difficulty is data.** Expert mode is a different encounter set and a different
  activation script, not a smarter opponent.

A phase step invokes the script with `{ "op": "run_activation", "adversary": "villain" }`.
It is a first-class op, not a `custom` handler — a core mechanism of a supported game shape
belongs in the DSL, which is exactly the test [ADR-0003](adr/0003-declarative-effect-dsl.md)
sets for when to grow the op set rather than reach for the escape hatch.

Adversaries *may* still need a decision (e.g. "the villain attacks the player with the most
damage" — fine, that's an expression; "the villain attacks a player of the defender's
choice" — that's a `pendingChoice` raised against a *player*). Genuinely arbitrary choices
resolve by the seeded RNG so they stay reproducible.

---

## 3. Encounter decks

New parameterised built-in, because "reveal one, reshuffling the discard if the deck runs
out" recurs in every game of this shape and is fiddly to get right:

```json
{ "op": "reveal_encounter",
  "from": "villain.encounter_deck",
  "discard": "villain.encounter_discard",
  "to": "$player.engaged",
  "count": 1,
  "shuffleOnEmpty": true }
```

It emits `card.revealed` before the card lands, so "when revealed" abilities — the
signature encounter-card mechanic — are ordinary triggered abilities:

```json
{ "id": "a1", "kind": "triggered", "speed": "forced",
  "trigger": { "event": "card.revealed",
               "filter": { "op": "eq", "left": "$event.card", "right": "$self" } },
  "effect": [ { "op": "add_counter", "card": "$adversary.mainScheme",
                "counter": "threat", "amount": 2 } ],
  "text": "When Revealed: Place 2 threat on the main scheme." }
```

Nothing new in the trigger model. `card.revealed` already existed.

---

## 4. Double-sided cards

Hero ↔ alter-ego, villain stage I ↔ II, main scheme 1A ↔ 1B. A card document may declare
`sides` instead of a flat `name`/`type`/`attributes`/`abilities`:

```json
{
  "code": "wh-001",
  "sides": {
    "front": { "name": "Aria Vance", "type": "alter_ego",
               "attributes": { "health": 11, "recover": 4, "form": "alter_ego" },
               "abilities": [ … ] },
    "back":  { "name": "Nightjar",   "type": "hero",
               "attributes": { "health": 11, "attack": 2, "thwart": 1, "form": "hero" },
               "abilities": [ … ] }
  }
}
```

Instance state gains `"face": "front" | "back"`. Two new ops:

| Op | Meaning |
|---|---|
| `flip_card` | Turn a card to its other side. Counters, attachments and damage persist by default; `carry` lists exceptions. |
| `replace_card` | Swap in a *different* card (villain stage III is usually a separate card), transferring what `carry` names. |

Modifier layers already handle the rest: flipping changes base characteristics at layer 0,
and everything above it recomputes. Damage stays marked, so a hero who flips to alter-ego
with 5 damage still has 5 damage — which is the correct and frequently-mishandled behaviour.

---

## 5. Player-count scaling

Two mechanisms, because two different things need scaling.

**Printed values that scale.** A card type attribute declares `perPlayer: true`; the printed
number is per player, and the effective value is multiplied by the player count at setup:

```json
{ "id": "health", "name": "Health", "type": "integer", "perPlayer": true, "required": true }
```

A villain printed at 15 health has 30 in a two-player game. The card face shows "15 per
player", generated from the attribute declaration.

**Ad-hoc scaling in abilities.** A new expression op:

```json
{ "op": "mul", "left": { "op": "player_count" }, "right": 3 }
```

Both are needed: the first keeps card faces honest, the second lets a scheme threshold or a
"deal 1 damage per player" effect say what it means.

---

## 6. Shared outcomes and elimination

`winConditions[].outcome` gains three members:

| Member | Meaning |
|---|---|
| `allWin: true` | Every player wins together |
| `allLose: true` | Every player loses together |
| `eliminate: "$player"` | That player is out; the game continues for the others |

Which lets both traditions be expressed:

```json
{ "id": "villain_defeated",
  "check": { "op": "and", "of": [
    { "op": "eq", "left": { "op": "attr", "of": "$adversary.boss", "attr": "stage" }, "right": "final" },
    { "op": "gte", "left": { "op": "counter", "of": "$adversary.boss", "counter": "damage" },
                   "right": { "op": "attr", "of": "$adversary.boss", "attr": "health" } } ] },
  "outcome": { "allWin": true } },

{ "id": "scheme_completed",
  "check": { "op": "gte",
             "left":  { "op": "counter", "of": "$adversary.mainScheme", "counter": "threat" },
             "right": { "op": "attr", "of": "$adversary.mainScheme", "attr": "threshold" } },
  "outcome": { "allLose": true } },

{ "id": "hero_defeated",
  "scope": { "players": "all" },
  "check": { "op": "gte", "left": { "op": "counter", "of": "$player.identity", "counter": "damage" },
                          "right": { "op": "attr", "of": "$player.identity", "attr": "health" } },
  "outcome": { "allLose": true },
  "text": "If any hero is defeated, all players lose." }
```

Swapping that last outcome to `{ "eliminate": "$player" }` gives you the Arkham Horror
model instead, where a defeated investigator is out and the others play on. One field.

---

## 7. Engagement: enemies in a player's area

A minion engaged with player 2 is *located* in that player's area but *controlled* by the
villain. With side ids (§1) this needs no new machinery — the instance sits in zone
`p1.engaged` with `controller: "villain"`. Queries gain one field to express it:

```json
{ "zone": "engaged", "zonePlayer": "$player", "controller": "$adversary" }
```

`zonePlayer` names whose copy of a player-scoped zone to look in, which is distinct from
`controller` (who commands the card) and `owner` (whose deck it came from). Those three were
the same thing in a duel and are three different things here.

---

## 8. Turn order

`round.firstPlayer.rule` gains `"rotate"`: the first-player token passes to the next seat
each round. `for_each_player` with `order: "turn"` already resolves from the current first
player, so scripts written for two players work unchanged at four.

Player-phase structure in these games is usually "each player takes a complete turn in
order" rather than "alternating priority". That's the existing `active_player` window type
with a step marked `repeatPerPlayer: true`, which was already in the format.

---

## 9. Scenario building

The co-op equivalent of an opponent's deck. `deckbuilding` describes what a *player* brings;
`scenarioBuilding` describes how the adversary's side is assembled:

```json
"scenarioBuilding": {
  "requires": [
    { "anchor": "boss",       "from": "villainStages" },
    { "anchor": "mainScheme", "from": "schemes" }
  ],
  "encounterSets": { "required": ["scenario"], "modular": { "min": 1, "max": 2 } },
  "perHeroAdditions": [
    { "from": "nemesis", "matching": "hero", "into": "encounter_deck" }
  ],
  "difficulties": [
    { "id": "standard", "name": "Standard", "encounterSets": ["standard"] },
    { "id": "expert",   "name": "Expert",   "encounterSets": ["standard", "expert"] }
  ]
}
```

A **scenario** is then a content document ([`scenario.schema.json`](../schemas/scenario.schema.json)),
versioned and playtested exactly like a deck — and simulated the same way. `perHeroAdditions`
is what pulls each hero's nemesis set into the encounter deck based on who is playing.

---

## What changes in the kernel

Very little, which is the point:

| Area | Change |
|---|---|
| State | `owner`/`controller` become side ids; `players[]` gains `eliminated`; instances gain `face` |
| Sides | Adversary sides registered at setup with their zones and anchors |
| Settle | An adversary step runs its activation script; it never raises a `pendingChoice` for the adversary itself |
| Ops | `reveal_encounter`, `flip_card`, `replace_card`, `engage`, `run_activation` |
| Expressions | `player_count`; queries gain `zonePlayer` |
| Win conditions | `allWin`, `allLose`, `eliminate`; check after every state-check pass as before |
| Redaction | Adversary hidden zones (encounter deck) are hidden from *everyone*, including the engine's own log until revealed |

Everything else — the stack, trigger ordering, modifier layers, state checks, determinism,
replays — is unchanged. A co-op match is still `(initial state, seed, actions[])`, and the
adversary's behaviour is part of the deterministic replay because it is a script rather than
a strategy.

## What changes in the UI

More than in the kernel, and this belongs in the design brief ([doc 12](12-ui-design-brief.md)):

* **The board is asymmetric.** A villain area (villain card, main scheme with a threat
  track, side schemes) plus one area per player, each with their own engaged enemies,
  identity card, and play area. At four players this is a lot of board — it needs a
  focus/overview mode, not just a bigger grid.
* **The threat track** is the game's clock and must be readable at a glance, including
  "how much threat lands next villain phase".
* **Turn ownership** matters more: with four players it must be unmistakable whose turn it
  is and what the other three are waiting on.
* **The villain phase needs narration.** A script executes several effects against several
  players in order; players must be able to follow what just happened to them. The event log
  and the animation queue already carry this, but the pacing needs designing.
* **Encounter card reveals** are the dramatic beat of the genre. They deserve a real moment.

## What changes in balance measurement

Co-op inverts the question. There is no matchup win rate to equalise at 50%; there is a
*difficulty target*:

| Metric | Target |
|---|---|
| Scenario win rate at N players | Whatever the designer intends (commonly 40–70%) |
| Win rate by player count | Should not swing wildly — this is what `perPlayer` scaling is *for*, and the first thing to check |
| Rounds to victory / defeat | A defeat that always arrives on the same round means the clock, not the difficulty, is deciding |
| Threat gained vs. removed per round | The core economy of a scheme-race game |
| Encounter card severity | Win rate when each encounter card is revealed — finds the one card that decides games |
| Hero win rate spread | Any hero far off the others is over- or under-tuned |

Doc 09's machinery all applies; the findings are just different questions.
