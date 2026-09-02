# 15 — Glossary

## LCG terms

| Term | Meaning |
|---|---|
| **LCG** | *Living Card Game* — a card game sold in fixed, non-random expansions. Everyone who buys a pack gets the same cards. No collectible randomisation. |
| **Card pool** | Every card a player legally owns and may build from |
| **Identity / Hero / Leader** | A special card that defines a deck's faction and starting conditions, in play from the start |
| **Exhaust / Ready** | Rotating a card to mark it as used; readying restores it (equivalent to tap/untap) |
| **Attachment** | A card that attaches to another card and modifies it |
| **Trait** | A descriptive tag (`Soldier`, `Beast`) with no inherent rules meaning, referenced by other cards |
| **Keyword** | A named ability with defined rules meaning (`Swift`, `Bolster 2`) |
| **Reminder text** | The italic restatement of what a keyword does |
| **Curve** | The distribution of cards by cost across a deck or set |
| **Tempo** | Efficiency in board development relative to resources spent |
| **Card advantage** | Having more usable resources/cards than the opponent |
| **Fizzle** | An ability that does nothing because all its targets became illegal |
| **Golden rule** | Card text overrides the rulebook where they conflict |
| **APNAP** | *Active Player, Non-Active Player* — the standard ordering for simultaneous effects |
| **State-based actions** | Rules checked continuously and enforced automatically (here: `stateChecks`) |

## Platform terms

| Term | Meaning |
|---|---|
| **Game system** | The JSON document defining a game's rules structure ([doc 04](04-game-system-spec.md)) |
| **Compiled bundle** | Derived artifacts from a system: per-card-type schemas, form descriptors, rules digest, lint |
| **Card document** | The JSON defining one card's design ([doc 05](05-card-set-deck-spec.md)) |
| **Card instance** | One physical copy of a card within a match, with its own state |
| **Kernel** | The pure, deterministic rules engine ([doc 07](07-rules-engine.md)) |
| **GameState** | The complete, serialisable state of a match |
| **PlayerView** | A `GameState` redacted to what one seat may see |
| **Action** | A player-initiated move; the unit of the append-only match log |
| **Op** | One node in the effect DSL |
| **Selector** | A `$`-prefixed reference (`$self`, `$target.t1`) |
| **Query** | A declarative description of a set of cards or players |
| **Modifier** | A continuous effect changing derived characteristics, applied in layers |
| **Layer** | A stage of modifier application, fixing the order of conflicting effects |
| **Pending choice** | A decision the engine is waiting on from a player or bot |
| **Settle** | Running all automatic processing until a decision is needed or the game ends |
| **Window** | A step in which players may act |
| **Stack** | LIFO list of resolving abilities |
| **Trigger queue** | Triggered abilities waiting to be placed on the stack |
| **State check** | A continuously enforced rule |
| **Replay** | `(initial state, seed, actions[])` — reproduces a match exactly |
| **Golden replay** | A replay used as a conformance test with expected state hashes |
| **Snapshot** | A stored `GameState` at a sequence number, for fast seeking |
| **Fork** | A new match branched from a point in an existing one |
| **Sandbox** | A match mode with god-mode actions, excluded from statistics |
| **Agent / Bot** | An automated player choosing from legal actions |
| **Bot profile** | A named bot strategy plus its JSON configuration |
| **Batch / Run** | A simulation job of N matches; one match within it |
| **Telemetry** | Per-card statistics gathered during simulation |
| **Finding** | An automated balance observation with supporting evidence |
| **Projection** | An index column derived from a JSON document |
| **Impact report** | What a system change would invalidate |
