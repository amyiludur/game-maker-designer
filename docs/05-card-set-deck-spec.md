# 05 — Card, Set & Deck Spec

Cards are authored *against* a game system version. Their attribute shape is not fixed by
the platform — it comes from the system's `cardTypes`.

Schemas: [`card.schema.json`](../schemas/card.schema.json),
[`set.schema.json`](../schemas/set.schema.json),
[`deck.schema.json`](../schemas/deck.schema.json).

---

## Card document

```jsonc
{
  "schemaVersion": "1.0.0",
  "code": "core-012",              // stable, human-readable, unique per game
  "gameId": "emberfall",
  "setId": "core",
  "number": 12,                    // collector number within the set
  "name": "Ashen Vanguard",
  "type": "character",             // must be a cardType id from the system
  "faction": "ash",
  "rarity": "common",
  "quantity": 3,                   // copies in the physical product (LCG fixed distribution)

  "attributes": {                  // validated against the compiled schema for `type`
    "cost": 3,
    "attack": 2,
    "health": 3,
    "traits": ["Soldier"]
  },

  "keywords": [
    { "id": "swift" },
    { "id": "bolster", "params": { "n": 1 } }
  ],

  "abilities": [ /* ability objects — see doc 06 */ ],

  "text": null,                    // null = generate from keywords + abilities
  "textOverride": null,            // set only when generated text reads badly
  "flavor": "The last line holds, or nothing does.",

  "art": { "assetId": "01H...", "artist": "R. Vance", "focus": "center" },
  "template": "standard-portrait",

  "design": {
    "status": "approved",          // draft | review | approved | retired
    "notes": "Deliberately the floor for 3-drops. Do not buff without re-simming aggro.",
    "tags": ["archetype:midrange", "benchmark"],
    "intendedRole": "Curve filler that trades up against 2-drops."
  }
}
```

### Generated card text

`text` is normally `null` and rendered from the ability data:

* each keyword contributes its `name` (plus params) and, in reminder mode, its reminder text
* each ability contributes text from its own `text` field, or from a generated rendering
  of its trigger + effect

This means the card face **cannot** disagree with the card's behaviour — the most common
and most expensive class of card-game bug simply cannot occur. `textOverride` exists for
the cases where generated phrasing is clumsy; setting it raises a lint **warning** that
shows a side-by-side diff of override vs. generated, so an override can never silently
drift from behaviour either.

### Card identity vs. card instance

A **card** is a design object (`core-012`). A **card instance** is a physical copy in a
match, with its own instance id, counters, attachments, facing and ready/exhausted state.
Two copies of `core-012` in play are two instances sharing one definition. The kernel
never mutates definitions.

---

## Set document

```json
{
  "schemaVersion": "1.0.0",
  "code": "core",
  "gameId": "emberfall",
  "name": "Emberfall Core Set",
  "releaseOrder": 1,
  "status": "draft",
  "summary": "The two founding houses and the basic tempo of the duel.",
  "design": {
    "goals": ["Teach ready/exhaust", "Establish the 2/3/4 curve", "One combo seed per faction"],
    "budget": { "character": 24, "event": 14, "attachment": 8, "hero": 4 }
  }
}
```

`design.budget` drives a **set completeness view**: the card browser shows planned vs.
authored counts per type and per cost, which is how you notice you have eleven 3-drops and
no 5-drops before playtesting tells you the hard way.

---

## Deck document

```json
{
  "schemaVersion": "1.0.0",
  "gameId": "emberfall",
  "gameVersion": "0.4.0",
  "name": "Ember Aggro",
  "identity": "core-001",
  "cards": [
    { "code": "core-012", "count": 3 },
    { "code": "core-014", "count": 2 }
  ],
  "sideboard": [],
  "notes": "Testing whether 3x Vanguard is too defensive for the aggro shell."
}
```

Legality is computed by the deckbuilding constraint evaluator and cached on the deck
version:

```json
{
  "valid": false,
  "violations": [
    { "constraint": "deckSize", "message": "28 cards, minimum is 30", "severity": "error" },
    { "constraint": "faction_lock", "cards": ["core-031"], "message": "Cards must match your hero's faction or be neutral.", "severity": "error" }
  ],
  "stats": { "total": 28, "byType": { "character": 18, "event": 10 }, "curve": { "1": 2, "2": 6, "3": 7, "4": 3 } }
}
```

`stats` is what the deck builder's curve chart and type breakdown render from, so the UI
never recomputes deck maths itself.

---

## Authoring ergonomics

These are product requirements, not nice-to-haves — they are what makes the difference
between a tool you use and a tool you abandon:

| Feature | Why it matters |
|---|---|
| **Duplicate as template** | Most cards are variations. Clone, retitle, tweak. |
| **Bulk edit** | Select 12 cards, shift every cost by −1, preview the diff, apply. |
| **CSV import/export** | Designers think in spreadsheets. Round-trip must be lossless for attributes; abilities export as a reference column. |
| **Ability library** | Save an ability as a named snippet, reuse across cards. Editing the snippet offers to propagate. |
| **Cost suggestion** | Given a card's stats and abilities, suggest a cost from a regression over already-simulated cards. Advisory, never enforced. |
| **Similar cards** | Live "cards near this one in stat-space" panel while editing, to catch accidental duplicates and power creep. |
| **Playtest from editor** | One click: open a sandbox table with this card in hand and full resources. |

## Print & proof export

* **Card renderer** — a card + a `card_template` document renders to SVG, then to PNG/PDF.
* **Proof sheet** — one A4/Letter page per 9 cards, with cut marks, for print-and-play.
* **Change sheet** — since version X, every card whose gameplay-relevant fields changed,
  formatted for reprinting only the deltas.
* **Bundle export** — a single zip: system JSON, all card JSON, decks, assets, a manifest.
  This is the backup format and the "give it to someone else" format.
