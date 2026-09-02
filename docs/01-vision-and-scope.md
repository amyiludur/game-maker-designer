# 01 — Vision & Scope

## The problem

Designing an LCG is not primarily a card-writing problem, it is a **systems** problem. The
things that actually go wrong:

* You can't tell whether a card is broken until you've played it fifty times.
* Card text drifts out of sync with what the rules actually do, because the rules live in
  your head and the text lives in a spreadsheet.
* Every balance change means re-testing everything by hand.
* Starting a *second* game means starting over, because the first one was never separable
  from its own rules.

## The thesis

**Model the game as data, and the rest becomes tractable.**

If zones, phases, resources, card types, keywords, deckbuilding rules and win conditions
are all declared in JSON, then:

* a single engine can play *any* game defined in the format,
* card abilities become structured, queryable, lintable objects rather than prose,
* "is this card legal / does this card do what it says" becomes a validation pass,
* balance testing becomes 10,000 headless matches overnight instead of a weekend,
* the card text on the printed face can be *generated from* the ability data, so it can
  never drift.

## Who it's for

| Persona | Needs |
|---|---|
| **Designer** (primary — you) | Author card systems and cards fast, see the consequences, iterate |
| **Playtester** | Join a table, play a match in a browser, file a note against a specific card and game state |
| **Balancer** | Run simulations, read metrics, spot outliers |
| **Developer** | Extend a game with custom ability handlers when the DSL isn't enough |

## Product pillars

1. **Multi-game by construction.** Nothing about one game is hard-coded. The platform
   holds many games; each game holds many versions.
2. **The JSON is the source of truth.** The database stores it, indexes it, and versions
   it — but never becomes a second, conflicting definition of the game.
3. **Determinism everywhere.** Same initial state + same seed + same actions = same
   result, always. This is what makes replays, bug reports, regression tests and
   simulation all possible from one mechanism.
4. **The engine is the referee, not the client.** The server decides what's legal. The
   client renders and asks.
5. **Author → play → measure is one loop.** Editing a card and re-testing it should take
   seconds, not a rebuild.

## In scope

* Game system authoring (zones, phases, resources, card types, keywords, win conditions)
* Card authoring with dynamic, system-driven forms and a visual ability builder
* Set / expansion management and card pool organisation
* Deckbuilding with live legality checking
* **Cooperative and solo-vs-the-game play**: 1–4 players against an engine-controlled
  adversary with its own encounter deck (the Marvel Champions / Arkham Horror shape) —
  see [doc 16](16-cooperative-and-adversary-games.md)
* Browser playtesting: solo, hotseat, and multiplayer over WebSocket
* Bot opponents and headless batch simulation
* Balance metrics and reporting
* Versioning, diffing and changelog generation
* Card rendering for proofing and print-and-play PDF export
* Rulebook generation from the system definition

## Out of scope (deliberately, for now)

* **Being a commercial game client.** No accounts-with-collections, matchmaking ranks,
  monetisation, or anti-cheat beyond server authority.
* **Arbitrary code execution from the browser.** Abilities are data. The escape hatch for
  genuinely exotic cards is a server-side registered handler written by a developer, not
  a script pasted into a card.
* **Real-time / hidden-simultaneous action games.** The timing model assumes discrete,
  sequential priority windows. Trick-taking and dexterity games are not targets.
* **Legacy / campaign persistence.** Carrying state between scenarios (XP, permanent
  injuries, an evolving campaign log) is a natural extension of the scenario model but is
  not designed yet.
* **Physical print fulfilment.** We export print-ready PDFs and stop there.
* **Perfect AI.** Bots exist to find degenerate lines and generate statistics, not to be
  a fun opponent. (A good bot is a happy side effect.)

## Definition of success

The platform is working when:

1. A brand new game system can be defined without touching application code.
2. A card can go from idea to played-in-a-match in under five minutes.
3. A balance question ("is 3 cost too cheap for this?") can be answered with a
   simulation run rather than an argument.
4. A playtester's bug report is a replay file that reproduces exactly.
