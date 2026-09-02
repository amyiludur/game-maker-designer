# Warden's Hollow — cooperative worked example

1–4 players against an engine-controlled adversary and its encounter deck. The same shape
as Marvel Champions, Arkham Horror LCG and LOTR LCG.

Where [`emberfall/`](../emberfall/) proves the competitive-duel case, this proves the
cooperative one. Both are configurations of the same format, validated by the same schemas,
run by the same kernel — see [doc 16](../../docs/16-cooperative-and-adversary-games.md).

```
game-system.json                  Zones, the warden adversary and its activation script,
                                  card types, the Watch phase, scenario building
sets/core.json                    15 cards: a double-sided identity, player cards,
                                  the Warden, the main scheme, minions and treacheries
encounter-sets/hollow.json        The scenario encounter set
scenarios/the-warden.json         The adversary's "deck": anchors + encounter sets
decks/nightjar-starter.json       A 21-card player deck
```

```bash
npm run validate      # from the repo root
```

---

## The game in one page

**Goal.** Defeat the Warden in its final stage. You lose if threat on the main scheme
reaches its threshold, if any Watcher is defeated, or if round 20 ends.

**Round structure**

| Phase | What happens |
|---|---|
| Watch | Each player takes a full turn in order, then refills to 6 cards |
| Warden | The adversary's activation script runs (below) |
| Cleanup | Ready everything, expire round-duration modifiers |

The first-player token rotates each round (`firstPlayer.rule: "rotate"`).

**No resource pool.** To play a card you discard cards from your hand equal to its cost.
This is deliberately different from Emberfall's Ember economy — same format, different
economy, no engine changes.

**Two forms.** Your identity card is double-sided. As a **Citizen** you can Recover; as a
**Guardian** you can Attack and Thwart. You may change form once per turn. Which side you
are on decides what the Warden does to you in its phase — that trade-off is the whole game.

**The Warden's activation** is not an AI. It is a script in the system document:

1. For each player in turn order: if they are a **Guardian**, the Warden attacks them for
   its Attack; if they are a **Citizen**, it places threat equal to its Thwart on the main
   scheme.
2. The main scheme gains threat equal to its Acceleration.
3. Each player is dealt one encounter card, which engages or resolves against them.

---

## What each part demonstrates

| File / card | Demonstrates |
|---|---|
| `adversaries[0].activation` | An automated opponent as a **script**, not a bot — deterministic and reproducible |
| `adversaries[0].anchors` | `$adversary.boss` and `$adversary.mainScheme` addressable from any card, so player cards don't need to know the scenario |
| `zones` with `scope: "adversary"` | An encounter deck belonging to a non-player side |
| `round.firstPlayer.rule: "rotate"` | A first-player token at 3–4 players |
| `watch.turn` with `repeatPerPlayer` | Full turns in order rather than alternating priority |
| **Aria Vance / Nightjar** (`wh-001`) | A double-sided identity where each face has its own **type**, attributes and abilities |
| **The Warden** (`wh-100`) | Per-player health (9 printed → 27 at three players), and stage advance as a `flip_card` from a state check |
| **The Hollow Deepens** (`wh-110`) | Per-player threshold against flat acceleration — the difficulty dial |
| **Rally the Watch** (`wh-021`) | `player_count` used directly in an ability |
| **Drive Them Back** (`wh-022`) | `zonePlayer` — only enemies engaged with **you** are legal targets |
| **Lantern Snuffer** (`wh-121`) | A When Revealed on a minion, targeting the revealing player's cards |
| **Grasping Roots** (`wh-131`) | A treachery that scales with player count, on purpose, so the sim can catch it |
| `winConditions` | `allWin` / `allLose`, plus a note on the one-field change to the Arkham elimination model |
| `scenarioBuilding` + `scenarios/` | The adversary's side assembled from anchors and encounter sets, versioned like a deck |

## Engine behaviours this example pins

1. **A flip changes base characteristics at layer 0.** Aria (11 health, 4 recover) and
   Nightjar (11 health, 2 attack, 2 thwart) are different types with different attributes.
   Damage already marked persists across the flip — a Watcher who flips down at 5 damage is
   still at 5 damage.
2. **A modifier can name an attribute that only exists on one face.** Hollow Lantern gives
   +1 Thwart; the Citizen side has no Thwart. The modifier is inert on that face rather than
   an error.
3. **`zonePlayer` is not `controller`.** A Hollow Hound engaged with player 2 lives in
   `p1.engaged` and is controlled by `warden`. Three concepts — location, control, ownership
   — that a duel collapses into one.
4. **Per-player scaling happens at setup, not at read time.** The Warden's effective health
   is fixed once the player count is known, so a player leaving mid-match doesn't change it.
5. **The adversary never gets a `pendingChoice`.** Everything it does is scripted; where a
   genuine choice exists it is either an expression or a choice given to a *player*.
6. **Encounter deck reshuffles** when empty, via `reveal_encounter`'s `shuffleOnEmpty`. With
   only 10 cards at 4 players the deck cycles roughly every 2.5 rounds, which makes the
   behaviour easy to observe in testing.

## Notes and known rough edges

* **Defence is stubbed.** `deal_damage` carries `defendable: true` and the `guard_post`
  keyword grants `may_defend`, but the defence window itself (a reaction step where a player
  may exhaust an ally to absorb an attack) is not yet in the round structure. It needs a
  `reaction` window type in the phase model — the closest thing to a real format gap this
  example surfaced.
* **Minion attacks are not modelled.** In the reference games, engaged minions attack during
  the villain phase. Adding it is two lines in the activation script; it is left out to keep
  the example legible.
* **`stage` as an enum attribute** works, but a villain with three stages across two physical
  cards would need `replace_card` rather than `flip_card`. Both ops exist; only `flip_card`
  is exercised here.
* **Balance is unexamined.** `scenarios/the-warden.json` states *target* win rates per player
  count. Whether the scenario hits them is a simulation question and cannot be answered until
  the kernel runs. The most likely finding is that flat acceleration against a per-player
  threshold makes the 1-player game harder than intended.
