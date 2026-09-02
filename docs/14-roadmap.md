# 14 — Roadmap

Ordered so that each milestone is independently useful and de-risks the next. The riskiest
thing in this project is the rules engine, so it arrives early and gets proven against a
real game before any polish is spent.

---

## M0 · Foundations

**Goal:** the skeleton runs, deploys, and has one end-to-end vertical slice.

* Laravel 11 + Postgres + Redis, Docker Compose dev environment
* Vue 3 + Vite + Tailwind + Pinia, auth, workspaces, memberships
* Migrations for games, game_versions, sets, cards, card_revisions
* JSON Schema validation wired on both sides, types generated from schemas
* CI: lint, unit, schema validation
* Vertical slice: create a game → paste a system JSON → save → see it validated

**Exit:** a game system document round-trips through the API with validation errors
surfaced in the UI.

---

## M1 · Authoring

**Goal:** you can define a game and author its cards without touching a file.

* System compiler: system JSON → per-card-type schemas, form descriptors, rules digest, lint
* System editor UI (zones, resources, card types, keywords first; phases tab in M2)
* Card browser (grid + table, facets, search)
* Card editor with schema-driven attribute forms
* Ability builder v1 — triggered and activated abilities, the core op set
* Revisions and diffs
* Sets and set completeness

**Exit:** the Emberfall core set is authored entirely through the UI, and the tool has no
Emberfall-specific code in it.

---

## M2 · The Kernel  ⭐ *highest risk*

**Goal:** a real match can be played headlessly, end to end.

* `GameState`, seeded RNG, event bus, trigger queue, stack
* Effect interpreter: the core op set
* Expression + predicate evaluator (shared by abilities, constraints, bot features)
* Modifier layer system
* Phase/step machine, priority windows, state checks
* `legalActions`, `apply`, `settle`, `view`
* Random bot + fuzz harness
* Golden replay format and the first conformance fixtures
* Phases tab in the system editor

**Exit:** two random bots play 10,000 Emberfall matches with zero invariant violations, and
a golden replay reproduces bit-identically on two machines.

*This milestone is where the project succeeds or fails. Do not compress it, and do not
start M3 until the fuzz run is clean.*

---

## M3 · Play in the browser

**Goal:** a human plays a match through the site.

* Match lifecycle API, action intake, redaction, snapshots
* Play table UI: board, hand, zones, action bar, phase rail, event log
* Choice prompts (all kinds), targeting mode
* Animation from the event stream
* Card inspector with modifier breakdown
* Solo (vs. bot), hotseat, sandbox mode
* Undo via truncate-and-replay
* Deck builder with live legality

**Exit:** a designer builds a deck and plays a complete solo match against a bot without
reading documentation.

---

## M3.5 · Cooperative & adversary games

**Goal:** 1–4 players play a scenario against an engine-controlled adversary.

* Side ids in state (`p0`, `villain`), replacing seat integers on instances
* Adversary registration, anchors, and `run_activation`
* Ops: `reveal_encounter`, `flip_card`, `replace_card`, `engage`
* `player_count` expression and `perPlayer` attribute scaling at setup
* `allWin` / `allLose` / `eliminate` outcomes
* Rotating first-player token; `repeatPerPlayer` turn steps
* Scenario and encounter-set documents, scenario builder UI
* Co-op board layout: adversary area, threat track, per-player engagement rows
* Defence windows (a `reaction` window type in the phase model)

**Exit:** a four-player Warden's Hollow scenario plays end to end, and the same kernel still
passes every Emberfall conformance replay unchanged.

*Sequenced here, not later, because it is the shape the platform is most likely to be used
for — and because retrofitting side ids after the play table is built is far more expensive
than doing it before.*

---

## M4 · Automation & balance

**Goal:** questions get answered by data.

* Heuristic bot with JSON-configured features and weights
* Simulation runner: queued batches, workers, progress, cancel
* Metrics collection and aggregation, card telemetry
* Balance report with automated findings and replay links
* Simulation lab UI, batch comparison
* Static analysis: curve, outliers, orphans, cost regression
* Co-op difficulty metrics: scenario win rate per player count against its stated target,
  threat gained vs. removed per round, per-encounter-card severity

**Exit:** a card change can be evaluated with a 2,000-match batch in under five minutes,
with a report that names what changed.

---

## M5 · Collaboration & versioning

**Goal:** more than one person can use it safely.

* Publish/freeze semantics, game snapshots, impact reports
* Version diff UI and generated changelogs
* Online multiplayer matches over Reverb, invite links, reconnection
* Playtest notes, anchored to game state, with a triage view
* Note → regression test in one action
* Roles and permissions enforced end to end

**Exit:** an external playtester joins a match, files a note, and it becomes a passing
regression test.

---

## M6 · Production & export

**Goal:** the game leaves the building.

* Card renderer + template editor
* Print-and-play PDF proofs, change sheets
* Rulebook generator
* Bundle import/export
* MCTS bot for deep matchup analysis
* Performance pass, observability, backups

**Exit:** a complete print-and-play kit and rulebook exports from a published version.

---

## Deliberately deferred

| Thing | Why |
|---|---|
| TypeScript kernel for optimistic play | Only if latency proves to be a real problem; gated on the conformance suite ([ADR-0002](adr/0002-single-authoritative-kernel.md)) |
| Multiplayer duels (3+ competitive seats) | The format supports it; the priority model gets much harder. Co-op multiplayer (M3.5) is the priority, since it needs turn order rather than interleaved priority. |
| Real-time collaborative editing | Revisions + optimistic locking are sufficient for a small team |
| Public sharing / community browsing | Not until the core loop is genuinely good |
| Mobile app | Responsive web first |

## Sequencing rules

1. **The kernel before the UI polish.** A beautiful table over a wrong engine is worthless.
2. **The example game leads.** Every milestone is proven against Emberfall before it counts
   as done.
3. **No game-specific code, ever.** Any `if ($game === 'emberfall')` is a design failure and
   should be a system-document feature instead.
4. **Determinism is not deferrable.** It is cheap to build in and effectively impossible to
   retrofit.
