# 06 — The Effect DSL

Card abilities are **data, not code**. An ability is a JSON tree the kernel interprets.

Why data (see [ADR-0003](adr/0003-declarative-effect-dsl.md)): abilities can be validated,
diffed, searched ("every card that deals damage to a hero"), rendered into card text,
statically analysed for balance, and safely authored by a non-programmer through a visual
builder. An embedded scripting language would be more expressive and would forfeit all of
that, plus introduce a sandbox-escape surface.

Schema: [`ability.schema.json`](../schemas/ability.schema.json).

---

## Ability shape

```jsonc
{
  "id": "a1",
  "kind": "triggered",        // triggered | activated | static | replacement | constant
  "speed": "reaction",        // action | reaction | forced | passive
  "trigger": { ... },         // required for triggered/replacement
  "cost":         [ ... ],    // paid before targets resolve; activated abilities only
  "requirements": [ ... ],    // predicates that must hold for the ability to be usable
  "targets":      [ ... ],    // chosen before effects run
  "effect":       [ ... ],    // ordered ops
  "limit": { "perRound": 1 },
  "text": "When this enters play, deal 1 damage to a character."
}
```

| `kind` | Meaning |
|---|---|
| `triggered` | Fires in response to an event |
| `activated` | A player chooses to use it, paying a cost |
| `static` | Continuously modifies game state while active (see Modifiers) |
| `replacement` | Intercepts an event and changes or prevents it |
| `constant` | Always-on rule change while the card is in a given zone (permissions, restrictions) |

| `speed` | Meaning |
|---|---|
| `action` | Usable in an open action window, costs the player's action |
| `reaction` | Usable in the response window after its trigger event |
| `forced` | Not optional — resolves automatically when triggered |
| `passive` | Never "used"; applies continuously |

---

## The four sub-languages

The DSL is deliberately four small languages that compose, rather than one big one:

1. **Selectors** — how you name things (`$self`, `$target.t1`, `$event.card`)
2. **Queries** — how you find sets of cards or players
3. **Expressions** — how you compute values and truth
4. **Ops** — how you change the game

### 1. Selectors

| Selector | Resolves to |
|---|---|
| `$self` | The card instance the ability is on |
| `$you` | The ability's controller |
| `$opponent` | The opposing player (2p); in multiplayer requires an explicit query |
| `$owner` | The player who owns `$self` (may differ from controller) |
| `$target.<id>` | A target chosen in the `targets` block |
| `$event.<field>` | A field of the triggering event (`$event.card`, `$event.amount`, `$event.player`) |
| `$each` | The current item inside `for_each` |
| `$card`, `$player` | The item under evaluation inside a query filter or state check |
| `$host` | The card `$self` is attached to (attachments only) |
| `$deck.identity` | The deck's identity card (hero/leader) |
| `$adversary` | The engine-controlled side; `$adversary.<anchor>` names one of its anchor cards ([doc 16](16-cooperative-and-adversary-games.md)) |
| `$param.<id>` | A keyword parameter value |

### 2. Queries

A query selects card instances or players.

```json
{
  "zone": "play",
  "controller": "$you",            // $you | $opponent | any | <selector>
  "types": ["character"],
  "traits": { "any": ["Soldier"] },  // any | all | none
  "keywords": { "none": ["swift"] },
  "where": { "op": "lte", "left": { "op": "attr", "of": "$card", "attr": "cost" }, "right": 3 },
  "zonePlayer": "$player",         // whose copy of a player-scoped zone — distinct from controller
  "face": "front",
  "exclude": ["$self"],
  "order": { "by": { "op": "attr", "of": "$card", "attr": "cost" }, "dir": "desc" },
  "limit": 3
}
```

Every field is optional; an empty query means "all card instances in all zones", which the
linter flags as almost certainly a mistake.

### 3. Expressions

Expressions produce a **value** (number, string, boolean, list). Everything is
`{"op": ...}` shaped, except literals which may be written bare.

**Values**

| Op | Example |
|---|---|
| `attr` | `{"op":"attr","of":"$self","attr":"attack"}` — current (modified) value |
| `baseAttr` | Printed value, ignoring modifiers |
| `counter` | `{"op":"counter","of":"$self","counter":"damage"}` |
| `resource` | `{"op":"resource","player":"$you","resource":"ember"}` |
| `count` | `{"op":"count","query":{...}}` |
| `zone_size` | `{"op":"zone_size","zone":"hand","player":"$opponent"}` |
| `round` / `phase` | current round number / phase id |
| `player_count` | Number of players in this match |
| `face` | Which side of a double-sided card is up (`front`/`back`) |
| `var` | a variable set earlier by `set_var` |
| `param` | a keyword parameter |
| `random_int` | `{"op":"random_int","min":1,"max":6}` — draws from the seeded RNG |

**Arithmetic** — `add`, `sub`, `mul`, `div` (integer, floor), `mod`, `min`, `max`, `abs`,
`clamp`. Binary ops take `left`/`right`; variadic ops take `of: [...]`.

**Conditional** — `{"op":"if","cond":<pred>,"then":<expr>,"else":<expr>}`

**Predicates** (produce booleans)

| Op | Meaning |
|---|---|
| `eq` `ne` `lt` `lte` `gt` `gte` | Comparison |
| `and` `or` `not` | Boolean logic (`and`/`or` take `of: [...]`) |
| `has_trait` `has_keyword` `is_type` | Card characteristics |
| `in_zone` | `{"op":"in_zone","card":"$self","zone":"play"}` |
| `controlled_by` `owned_by` | |
| `is_exhausted` `is_face_down` `is_attached` | Instance state |
| `entered_this_round` | Whether a card entered its current zone this round (summoning sickness) |
| `has_permission` `has_restriction` | Whether a named permission/restriction currently applies to a card |
| `exists` | `{"op":"exists","query":{...}}` — query matches ≥1 |
| `all` | Every card matching `of` also matches `match` |
| `can_pay` | Could this cost be paid right now |

### 4. Ops (effects)

Ops mutate state. Every op emits at least one event, which is what other abilities can
trigger on.

**Card movement**

| Op | Params |
|---|---|
| `draw` | `player`, `count` |
| `move_card` | `card`, `to` (zone), `position` (`top\|bottom\|index`), `controller`, `facing` |
| `move_cards` | `query` or `from` + `count` + `select`, `to`, … |
| `discard` | `player`, `count`, `chooser`, `query` |
| `destroy` | `card` — moves to discard, emits `card.destroyed` (replaceable) |
| `put_into_play` | `card`, `controller`, `ready` |
| `create_token` | `token` (card code), `controller`, `count` |
| `shuffle` | `zone`, `player` |
| `search` | `zone`, `query`, `count`, `then` (ops), `shuffleAfter` |
| `reveal` / `look_at` | `query` or `count` from a zone, `to` (which players see it) |
| `attach` / `detach` | `card`, `to` |

**State**

| Op | Params |
|---|---|
| `exhaust` / `ready` | `card` or `query` |
| `add_counter` / `remove_counter` | `card`, `counter`, `amount` |
| `deal_damage` | `target`, `amount`, `source` (defaults `$self`) |
| `heal` | `target`, `amount` |
| `gain_resource` / `pay_resource` | `player`, `resource`, `amount`, `mode` |
| `gain_control` | `card`, `player`, `duration` |
| `modify` | Apply a continuous modifier — see below |
| `grant_ability` | `card`, `ability`, `duration` |
| `set_flag` | `scope`, `flag`, `value`, `duration` |

**Control flow**

| Op | Params |
|---|---|
| `sequence` | `do: [ops]` |
| `if` | `cond`, `then`, `else` |
| `for_each` | `query` or `list`, `do` — binds `$each` |
| `for_each_player` | `order` (`turn\|seat`), `do` — binds `$player` |
| `repeat` | `count`, `do` |
| `choose_one` | `chooser`, `options: [{ text, effect }]` — modal cards |
| `choose_cards` | `chooser`, `query`, `count`, `optional`, `then` — `then` binds each chosen card as `$each` |
| `choose_number` | `chooser`, `min`, `max`, `then` |
| `prompt_yes_no` | `chooser`, `text`, `then`, `else` |
| `set_var` | `id`, `value` |

**Game flow**

`end_step`, `end_phase`, `extra_turn`, `win_game`, `lose_game`, `draw_game`.

**Parameterised built-ins**

A few ops are engine-provided routines configured by the system document rather than
compositions of primitives:

| Op | Configured by |
|---|---|
| `resolve_combat` | `model` (`simultaneous_strike`, `attacker_first`, `no_combat`), `damageAttr`, `healthAttr`, `damageCounter`, `unblockedTarget` |
| `enforce_hand_size` | `max` |
| `expire_modifiers` | `duration` |
| `set_first_player` | `rule` |

Combat *can* be expressed in primitive ops. It comes out long, unreadable and near-identical
across every game this platform targets, so it is a configured routine instead. The test is
whether a new game can be served by a new `model` value; if a game needs combat that no model
covers, add a model — do not fork the kernel.

**Escape hatch**

```json
{ "op": "custom", "handler": "emberfall.cascade_burn", "params": { "depth": 3 } }
```

`custom` invokes a PHP handler registered by the game's extension package. It is code,
reviewed and versioned like code, and it must remain deterministic (no clock, no
`rand()`, RNG only via the injected seeded source). The linter reports the proportion of
cards using `custom`; if it climbs above ~5%, the DSL is missing a primitive and should
grow one rather than letting the escape hatch become the norm.

---

## Targets

```json
"targets": [
  {
    "id": "victim",
    "query": { "zone": "play", "types": ["character"], "controller": "$opponent" },
    "count": 1,
    "optional": false,
    "chooser": "$you",
    "prompt": "Choose an enemy character"
  }
]
```

Rules:

* Targets are chosen **when the ability is put on the stack**, not when it resolves.
* If a required target has no legal choices, the ability cannot be activated (activated) or
  is removed from the queue (triggered). This falls out of legality checking for free.
* On resolution, each target is re-checked; targets that became illegal are dropped, and
  if *all* required targets are gone the ability does nothing ("fizzles"). This is standard
  and prevents a large family of exploits.
* `count` may be an expression, and may be `{"min":1,"max":3}` for "up to N".

---

## Triggers

```json
"trigger": {
  "event": "card.entered_zone",
  "filter": {
    "op": "and",
    "of": [
      { "op": "eq", "left": "$event.card", "right": "$self" },
      { "op": "eq", "left": "$event.to", "right": "play" }
    ]
  },
  "window": "after"        // before | after | instead (instead = replacement)
}
```

**Core event catalogue.** Generated from `EventCatalogue::EVENTS` in the kernel by
`npm run events`, and checked in CI — trigger filters read these payload fields by name, so a
doc that disagrees with the code teaches authors to write filters that never match.

<!-- generated: event-catalogue -->

| Event | `$event.*` payload |
|---|---|
| `match.began` | — |
| `round.began` | `round` |
| `round.ended` | `round` |
| `phase.began` | `phase` |
| `phase.ended` | `phase` |
| `step.began` | `phase`, `step` |
| `step.ended` | `phase`, `step` |
| `turn.began` | `player` |
| `turn.ended` | `player` |
| `card.played` | `card`, `player`, `action` |
| `card.entered_zone` | `card`, `from`, `to`, `controller`, `position` |
| `card.left_zone` | `card`, `from`, `to`, `controller` |
| `card.destroyed` | `card`, `from`, `source` |
| `card.exhausted` | `card` |
| `card.readied` | `card` |
| `card.revealed` | `card`, `from`, `to`, `player` |
| `card.attached` | `card`, `host` |
| `damage.dealt` | `target`, `amount`, `source` |
| `damage.prevented` | `target`, `amount`, `source` |
| `counter.added` | `card`, `counter`, `amount` |
| `counter.removed` | `card`, `counter`, `amount` |
| `resource.gained` | `player`, `resource`, `amount` |
| `resource.paid` | `player`, `resource`, `amount` |
| `cards.drawn` | `player`, `count`, `cards` |
| `attack.declared` | `attacker`, `defender`, `target` |
| `block.declared` | `blocker`, `attacker` |
| `combat.resolved` | `attacks` |
| `ability.resolved` | `card`, `ability` |
| `player.lost` | `player`, `reason` |
| `game.ended` | `winners`, `losers`, `reason` |
| `card.flipped` | `card`, `from`, `to` |
| `card.replaced` | `card`, `with` |
| `deck.exhausted` | `player` |
| `zone.shuffled` | `zone`, `player` |
| `modifiers.expired` | `duration`, `count` |
| `first_player.set` | `player` |

<!-- /generated: event-catalogue -->

Games may declare additional events in the system document; the linter checks that every
`trigger.event` is either core or declared.

---

## Modifiers and the layer system

Continuous effects are the part of every card game engine that rots first, because "what
is this creature's attack?" stops having one obvious answer once four cards are arguing
about it. We handle it explicitly.

A `modify` op creates a **modifier**:

```json
{
  "op": "modify",
  "query": { "zone": "play", "traits": { "any": ["Soldier"] }, "controller": "$you" },
  "changes": [{ "attr": "attack", "mode": "add", "value": 1 }],
  "duration": "round",           // instant | round | turn | while_source_in_play | permanent
  "source": "$self"
}
```

`modify` takes either a `query` (affect every matching card) or a `target` (affect one
named card, e.g. `"$host"` or `"$target.ally"`).

Derived characteristics are **never stored**. They are recomputed from base values plus
all active modifiers, applied in a fixed layer order:

| Layer | Applies |
|---|---|
| 0 | Base (printed) characteristics |
| 1 | Copy effects ("this becomes a copy of…") |
| 2 | Control changes |
| 3 | Type / trait / subtype changes |
| 4 | Ability add & remove |
| 5 | Attribute **set** (`mode: "set"`, e.g. "base attack becomes 1") |
| 6 | Attribute **add/sub** (`mode: "add"`) |
| 7 | Attribute **multiply** |
| 8 | Counters (e.g. +1/+1 counters, damage) |
| 9 | Restrictions & permissions ("can't attack", "may attack while exhausted") |

Within a layer, modifiers apply in **timestamp order** (creation sequence). Dependency
between modifiers in the same layer is resolved by iterating to a fixed point, capped at
8 passes; exceeding the cap raises a `ModifierCycle` diagnostic naming the cards involved,
rather than hanging.

Results are memoised per `(instance, attribute)` and invalidated by a state version
counter, so a board with 30 modifiers doesn't recompute quadratically.

---

## Worked examples

**"When this enters play, deal 1 damage to a character."**

```json
{
  "id": "a1", "kind": "triggered", "speed": "forced",
  "trigger": { "event": "card.entered_zone",
    "filter": { "op": "and", "of": [
      { "op": "eq", "left": "$event.card", "right": "$self" },
      { "op": "eq", "left": "$event.to", "right": "play" } ] } },
  "targets": [{ "id": "victim", "query": { "zone": "play", "types": ["character"] }, "count": 1, "chooser": "$you" }],
  "effect": [{ "op": "deal_damage", "target": "$target.victim", "amount": 1 }],
  "text": "When this enters play, deal 1 damage to a character."
}
```

**"Exhaust: draw a card. Limit once per round."**

```json
{
  "id": "a1", "kind": "activated", "speed": "action",
  "cost": [{ "op": "exhaust", "card": "$self" }],
  "effect": [{ "op": "draw", "player": "$you", "count": 1 }],
  "limit": { "perRound": 1 },
  "text": "Exhaust: Draw a card. Limit once per round."
}
```

**"Other friendly Soldiers get +1 attack."** (static, no trigger)

```json
{
  "id": "a1", "kind": "static", "speed": "passive",
  "activeWhile": { "op": "in_zone", "card": "$self", "zone": "play" },
  "effect": [{
    "op": "modify",
    "query": { "zone": "play", "controller": "$you", "traits": { "any": ["Soldier"] }, "exclude": ["$self"] },
    "changes": [{ "attr": "attack", "mode": "add", "value": 1 }],
    "duration": "while_source_in_play"
  }],
  "text": "Other friendly Soldiers get +1 attack."
}
```

**"If damage would be dealt to your hero, prevent 1 of it."** (replacement)

```json
{
  "id": "a1", "kind": "replacement", "speed": "passive",
  "trigger": { "event": "damage.dealt", "window": "instead",
    "filter": { "op": "eq", "left": "$event.target", "right": "$you.identity" } },
  "effect": [{ "op": "modify_event", "field": "amount",
    "value": { "op": "max", "of": [0, { "op": "sub", "left": "$event.amount", "right": 1 }] } }],
  "text": "Prevent 1 damage dealt to your hero."
}
```

**Modal: "Choose one — draw 2 cards; or deal 2 damage to a character."**

```json
{
  "id": "a1", "kind": "triggered", "speed": "forced",
  "trigger": { "event": "card.played", "filter": { "op": "eq", "left": "$event.card", "right": "$self" } },
  "effect": [{
    "op": "choose_one", "chooser": "$you",
    "options": [
      { "text": "Draw 2 cards.", "effect": [{ "op": "draw", "player": "$you", "count": 2 }] },
      { "text": "Deal 2 damage to a character.",
        "targets": [{ "id": "v", "query": { "zone": "play", "types": ["character"] }, "count": 1 }],
        "effect": [{ "op": "deal_damage", "target": "$target.v", "amount": 2 }] }
    ]
  }]
}
```

---

## The visual ability builder

Designers should not hand-write this JSON. The card editor presents the same tree as a
sentence-shaped, nested form:

```
WHEN  [this card enters play          ▾]
IF    [ + add condition ]
COST  [ + add cost ]
CHOOSE [1 ▾] [enemy character         ▾]  as  "victim"
THEN  [deal damage ▾] to [victim ▾] amount [1        ]
      [ + add effect ]
```

* Each dropdown is populated from the compiled system (only zones/types/traits that exist).
* A live "reads as" preview renders generated card text under the form.
* A **JSON tab** shows the underlying document, editable by power users, with schema-aware
  autocomplete. Round-tripping between form and JSON is lossless.
* Invalid states are unrepresentable where possible: you cannot select a target type the
  effect can't accept.

## Static analysis the DSL enables

Because abilities are data, the platform can answer questions no prose-based tool can:

* "Which cards can remove a card from play?" — search the op tree for `destroy`/`move_card`
* "Which cards care about Soldiers?" — search queries for the trait
* "What's the maximum damage this card can deal in one resolution?" — bounded symbolic
  evaluation over the effect tree
* "Which cards have no interaction with any other card in the set?" — orphan detection
* "Which triggers can loop?" — build the trigger→event graph and look for cycles

These power the balance and lint tooling in [09](09-automation-and-balance.md) and
[13](13-validation-and-testing.md).
