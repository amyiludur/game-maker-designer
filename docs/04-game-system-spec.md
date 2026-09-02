# 04 — Game System Spec

A **game system** is one JSON document that fully describes a game's rules structure. It
is what makes the platform multi-game: the engine has no knowledge of any specific game,
only of this format.

Machine-readable contract: [`schemas/game-system.schema.json`](../schemas/game-system.schema.json).
Worked example: [`examples/emberfall/game-system.json`](../examples/emberfall/game-system.json).

## Top-level shape

```jsonc
{
  "schemaVersion": "1.0.0",     // format version, not game version
  "id": "emberfall",
  "version": "0.4.0",           // this game system's semver
  "name": "Emberfall",
  "summary": "A two-player duel of ember and ash.",

  "players":      { ... },      // seat count and mode
  "vocabularies": { ... },      // controlled lists: traits, factions, rarities
  "resources":    [ ... ],      // per-player economies
  "counters":     [ ... ],      // markers that sit on cards
  "zones":        [ ... ],      // where cards live and who can see them
  "cardTypes":    [ ... ],      // the attribute schema for each kind of card
  "keywords":     [ ... ],      // named reusable abilities
  "setup":        [ ... ],      // how a match begins
  "round":        { ... },      // phase and step structure
  "actions":      [ ... ],      // what players may do in an open window
  "stateChecks":  [ ... ],      // continuously enforced rules (death, limits)
  "winConditions":[ ... ],
  "deckbuilding": { ... },
  "ui":           { ... },      // presentation hints for the play table
  "rulesText":    { ... }       // authored prose for rulebook generation
}
```

Every section below is declarative. Nothing here is code.

---

## `players`

```json
{ "min": 2, "max": 2, "mode": "competitive", "teams": false }
```

`mode` is one of `competitive`, `cooperative`, `solo`. Co-op games get a shared "encounter"
side driven by automatic actions rather than a player.

## `vocabularies`

Controlled lists that the card editor turns into dropdowns and the linter enforces.

```json
{
  "traits":    ["Soldier", "Beast", "Spell", "Relic"],
  "rarities":  ["common", "uncommon", "rare"],
  "factions":  [
    { "id": "ember", "name": "Ember", "color": "#c0392b", "icon": "flame" },
    { "id": "ash",   "name": "Ash",   "color": "#5d6d7e", "icon": "cinder" }
  ]
}
```

Adding a trait to a card that isn't in the vocabulary is a lint **error**, not a warning.
This is the single cheapest defence against "Soldier" / "soldier" / "Solider" drift.

## `resources`

Per-player economies. Most LCGs have exactly one.

```json
[{
  "id": "ember",
  "name": "Ember",
  "start": 0,
  "min": 0,
  "max": null,
  "perRound": { "gain": 3, "mode": "set" },   // "set" | "add"
  "carryOver": false
}]
```

`mode: "set"` gives you Netrunner-style "you have N this round"; `mode: "add"` with
`carryOver: true` gives you an accumulating pool.

## `counters`

Markers that live on card instances.

```json
[
  { "id": "damage",  "name": "Damage",  "visual": "pip-red" },
  { "id": "charge",  "name": "Charge",  "visual": "pip-blue", "max": 5 }
]
```

Damage is a counter like any other; nothing about it is special-cased in the kernel. What
makes it lethal is a `stateCheck` (below) that destroys cards whose damage meets their
health.

## `zones`

```json
[
  { "id": "deck",    "name": "Deck",    "scope": "player", "visibility": "none",   "ordered": true,  "faceDown": true },
  { "id": "hand",    "name": "Hand",    "scope": "player", "visibility": "owner",  "ordered": false },
  { "id": "play",    "name": "In Play", "scope": "player", "visibility": "public", "ordered": false, "supportsAttachments": true },
  { "id": "discard", "name": "Discard", "scope": "player", "visibility": "public", "ordered": true },
  { "id": "removed", "name": "Removed", "scope": "shared", "visibility": "public", "ordered": false }
]
```

| Field | Meaning |
|---|---|
| `scope` | `player` (one per seat) or `shared` (one for the table) |
| `visibility` | `none` (nobody), `owner`, `controller`, `public` |
| `ordered` | Does position matter? Decks and discards yes, hands no |
| `faceDown` | Default facing for cards entering |
| `supportsAttachments` | Whether cards here can host attached cards |
| `maxSize` | Optional cap; paired with a `stateCheck` for enforcement (e.g. hand size) |

Visibility drives redaction: `Kernel::view()` replaces hidden cards with
`{ hidden: true, id: <stable instance id> }`, so the client can render a card back and
animate it without ever receiving its identity.

## `cardTypes`

The heart of multi-game support. Each card type declares its own attributes; the compiler
turns these into a JSON Schema **and** a form descriptor, so the card editor builds itself.

```json
{
  "id": "character",
  "name": "Character",
  "playableTo": ["play"],
  "attributes": [
    { "id": "cost",   "name": "Cost",   "type": "integer", "min": 0, "max": 12, "required": true, "showOnCard": "top-left" },
    { "id": "attack", "name": "Attack", "type": "integer", "min": 0, "required": true, "showOnCard": "bottom-left" },
    { "id": "health", "name": "Health", "type": "integer", "min": 1, "required": true, "showOnCard": "bottom-right" },
    { "id": "traits", "name": "Traits", "type": "tagList", "vocabulary": "traits" }
  ],
  "modifiableAttributes": ["attack", "health"],
  "abilitySlots": { "max": 3 },
  "unique": false
}
```

Attribute `type` is one of: `integer`, `decimal`, `string`, `text`, `boolean`, `enum`
(with `options`), `tagList` (with `vocabulary`), `reference` (to another card).

`modifiableAttributes` tells the modifier engine which attributes continuous effects are
allowed to change — a guardrail that catches "why is this card's *cost* being buffed by a
+1 attack effect" bugs at lint time.

## `keywords`

Named, reusable abilities. A keyword is defined once and referenced by many cards, so
changing what "Swift" means is a one-line edit rather than a card sweep.

```json
{
  "id": "swift",
  "name": "Swift",
  "reminder": "This character may attack the round it enters play.",
  "parameters": [],
  "grants": [
    { "kind": "permission", "permission": "attack_while_summoning_sick", "value": true }
  ]
}
```

Parameterised keywords (`Bolster 2`) declare parameters and reference them in the ability
body with `{"op":"param","id":"n"}`:

```json
{
  "id": "bolster",
  "name": "Bolster",
  "reminder": "When this enters play, give another friendly character +N attack this round.",
  "parameters": [{ "id": "n", "type": "integer", "required": true }],
  "grants": [{ "kind": "ability", "ability": { /* full ability object, see doc 06 */ } }]
}
```

## `setup`

An ordered effect script run once at match start. Uses the same op vocabulary as card
abilities.

```json
[
  { "op": "for_each_player", "do": [
      { "op": "move_cards", "from": "identity_pool", "to": "play", "count": 1, "select": "deck.identity" },
      { "op": "shuffle", "zone": "deck" },
      { "op": "draw", "count": 5 }
  ]},
  { "op": "set_first_player", "rule": "random" }
]
```

## `round`

The turn/phase state machine.

```json
{
  "structure": "phased",
  "firstPlayer": { "rule": "alternate" },
  "phases": [
    {
      "id": "refresh", "name": "Refresh",
      "steps": [
        { "id": "ready",   "auto": [{ "op": "for_each_player", "do": [{ "op": "ready_all", "zone": "play" }] }] },
        { "id": "income",  "auto": [{ "op": "for_each_player", "do": [{ "op": "gain_resource", "resource": "ember", "amount": 3, "mode": "set" }] }] },
        { "id": "draw",    "auto": [{ "op": "for_each_player", "do": [{ "op": "draw", "count": 1 }] }] }
      ]
    },
    {
      "id": "action", "name": "Action",
      "steps": [
        { "id": "main", "window": { "type": "alternating", "endOn": "consecutive_passes" } }
      ]
    },
    {
      "id": "combat", "name": "Combat",
      "steps": [
        { "id": "declare_attackers", "window": { "type": "active_player", "actions": ["declare_attack"] } },
        { "id": "declare_blockers",  "window": { "type": "defending_player", "actions": ["declare_block"] } },
        { "id": "resolve",           "auto": [{ "op": "resolve_combat" }] }
      ]
    },
    {
      "id": "end", "name": "End",
      "steps": [
        { "id": "cleanup", "auto": [{ "op": "expire_modifiers", "duration": "round" }, { "op": "enforce_hand_size" }] }
      ]
    }
  ]
}
```

A **step** is either automatic (`auto`: an effect script the engine runs, then moves on) or
a **window** in which players may take actions. Window types:

| Type | Behaviour |
|---|---|
| `active_player` | Only the active player acts, then the step ends |
| `alternating` | Priority passes back and forth; ends after consecutive passes |
| `simultaneous` | All players submit; engine resolves in seat order (used for setup choices) |
| `defending_player` | Only the player being attacked |

Each window may restrict which action ids are available via `actions`.

## `actions`

Player-initiated action templates. These are what `Kernel::legalActions()` enumerates.

```json
{
  "id": "play_card",
  "name": "Play a card",
  "windows": ["action.main"],
  "targets": [
    { "id": "card", "query": { "zone": "hand", "controller": "$you" }, "count": 1 }
  ],
  "cost": [
    { "op": "pay_resource", "resource": "ember", "amount": { "op": "attr", "of": "$target.card", "attr": "cost" } }
  ],
  "requirements": [
    { "op": "can_enter_play", "card": "$target.card" }
  ],
  "effect": [
    { "op": "move_card", "card": "$target.card", "to": "play", "controller": "$you" }
  ]
}
```

An action may also declare `emits: ["card.played"]`, which fires named events on top of
whatever its effect ops emit. This is how "when you play a card" triggers work without every
play action having to emit events by hand.

Costs are checked *and* paid by the engine; if any cost cannot be paid, the action is not
offered. This is why `legalActions()` can be trusted by the UI to grey out unplayable
cards without duplicating any logic.

## `stateChecks`

Rules the engine enforces continuously — after every effect resolution and before handing
priority back. This is the "state-based actions" concept from MtG, generalised.

```json
[
  {
    "id": "lethal_damage",
    "when": { "op": "gte", "left": { "op": "counter", "of": "$card", "counter": "damage" },
                            "right": { "op": "attr", "of": "$card", "attr": "health" } },
    "scope": { "zone": "play", "types": ["character"] },
    "then": [{ "op": "destroy", "card": "$card" }]
  },
  {
    "id": "hand_size",
    "when": { "op": "gt", "left": { "op": "zone_size", "zone": "hand", "player": "$player" }, "right": 7 },
    "scope": { "players": "all" },
    "phase": "end",
    "then": [{ "op": "choose_and_discard", "player": "$player", "count": { "op": "sub", "left": { "op": "zone_size", "zone": "hand", "player": "$player" }, "right": 7 } }]
  }
]
```

State checks loop until no check fires (with a cycle guard — see [07](07-rules-engine.md)).

## `winConditions`

```json
[
  { "id": "objective_burned", "check": { "op": "eq", "left": { "op": "counter", "of": "$player.identity", "counter": "damage" }, "right": 20 },
    "outcome": { "loser": "$player" }, "text": "A player whose Heart takes 20 damage loses." },
  { "id": "deck_out", "check": { "op": "eq", "left": { "op": "zone_size", "zone": "deck", "player": "$player" }, "right": 0 },
    "trigger": "on_draw_from_empty", "outcome": { "loser": "$player" } },
  { "id": "round_limit", "check": { "op": "gt", "left": { "op": "round" }, "right": 25 },
    "outcome": { "draw": true } }
]
```

`round_limit` isn't flavour — it is what stops simulation runs from hanging on a
degenerate stall loop. Every game system should have one.

## `deckbuilding`

```json
{
  "deckSize":   { "min": 30, "max": 45 },
  "maxCopies":  3,
  "identity":   { "type": "hero", "count": 1, "zone": "play" },
  "constraints": [
    { "id": "faction_lock",
      "rule": { "op": "all", "of": { "zone": "deck" },
                "match": { "op": "or", "of": [
                  { "op": "eq", "left": { "op": "attr", "of": "$card", "attr": "faction" }, "right": { "op": "attr", "of": "$deck.identity", "attr": "faction" } },
                  { "op": "eq", "left": { "op": "attr", "of": "$card", "attr": "faction" }, "right": "neutral" } ] } },
      "message": "Cards must match your hero's faction or be neutral." }
  ]
}
```

Constraints are evaluated by the same predicate evaluator as abilities, so there is one
expression language for the whole platform.

## `ui`

Presentation hints. The client falls back to a generic layout if absent.

```json
{
  "board": {
    "layout": "duel-mirrored",
    "rows": [
      { "id": "opponent-play", "zone": "play", "player": "$opponent" },
      { "id": "shared",        "zone": "removed", "player": "$shared", "collapsed": true },
      { "id": "own-play",      "zone": "play", "player": "$you" }
    ],
    "docks": { "hand": "bottom", "deck": "bottom-right", "discard": "bottom-right" }
  },
  "cardTemplate": "standard-portrait",
  "theme": { "accent": "#c0392b", "surface": "#1a1614" }
}
```

## `rulesText`

Authored prose keyed to system concepts. The rulebook generator interleaves it with
generated sections (a phase-by-phase walkthrough derived from `round`, a keyword glossary
derived from `keywords`), so the rulebook can never describe a phase the game doesn't have.

```json
{
  "sections": [
    { "id": "overview", "title": "Overview", "body": "Two rival houses..." },
    { "id": "golden-rule", "title": "The Golden Rule", "body": "If a card contradicts these rules, the card wins." }
  ],
  "generate": ["phases", "keywords", "zones", "deckbuilding"]
}
```

---

## Compilation

On save, the system document is compiled. The compiler produces:

1. **Per-card-type JSON Schema** — used by both the client (instant validation) and the
   server (authoritative validation).
2. **Form descriptors** — ordered field list with widget hints, so the card editor renders
   the right controls with no per-game frontend code.
3. **A rules digest** — resolved keyword bodies, an index of every ability op used, the
   phase graph.
4. **A lint report** — see [13 — Validation & testing](13-validation-and-testing.md).

Compilation is pure and cached in `game_versions.compiled`.

## Changing a system safely

The compiler classifies each change against the previous version:

| Change | Classification |
|---|---|
| Add a card type, keyword, trait, action | **minor** |
| Add an optional attribute | **minor** |
| Change reminder text, names, UI hints | **patch** |
| Remove/rename a zone, phase, attribute, keyword | **major** |
| Change an attribute's type or make it required | **major** |
| Change a resource's economy | **major** |

A major bump runs an **impact report**: which cards, decks and saved matches would be
invalidated, listed before you commit. Published versions are frozen, so old matches keep
replaying against the definition they were played under.
