# 09 — Automation & Balance

Playtesting by hand finds *fun* problems. Simulation finds *statistical* problems. You need
both, and only one of them scales.

## Agents

Every bot implements one interface:

```php
interface Agent {
    public function chooseAction(PlayerView $view, ActionList $legal): Action;
    public function resolveChoice(PlayerView $view, PendingChoice $choice): ChoiceResponse;
}
```

Agents see a **`PlayerView`, not `GameState`** — the same redacted object a human gets. A
bot cannot cheat by construction, so bot-derived statistics mean something.

### Strategies

Only **Random** is built. It backs the fuzz harness and the solo table's opponent
([ADR-0009](adr/0009-server-driven-bot-seats.md)); the rest are specified and waiting for
M4. A bot profile whose strategy has no implementation is listed by the API with
`implemented: false` rather than hidden, so an authored tuning is not silently dropped.

| Strategy | What it does | Good for |
|---|---|---|
| **Random** ✓ | Uniform choice among legal actions | Fuzzing: finds crashes, infinite loops, illegal states. Run it first, always. |
| **Scripted** | Fixed action sequence, fails loudly on divergence | Regression tests, reproducing a specific line |
| **Heuristic** | Scores each legal action with a weighted feature sum | The workhorse — fast, tunable per game in JSON, ~thousands of matches/minute |
| **MCTS** | Monte Carlo tree search with determinization for hidden info | Finding degenerate lines a human wouldn't; slow, used on suspect matchups |
| **Human-replay** | Replays a recorded human match | Comparing bot decisions against human ones |

### Heuristic bot configuration

Tuning is data, so a designer can tune bots without a developer:

```json
{
  "name": "Emberfall Aggro Baseline",
  "strategy": "heuristic",
  "config": {
    "weights": {
      "boardPresence": 1.0,
      "cardAdvantage": 0.8,
      "tempo": 1.2,
      "opponentHealthDelta": 2.5,
      "resourceEfficiency": 0.6,
      "keepResourcesOpen": -0.2
    },
    "features": [
      { "id": "boardPresence", "expr": { "op": "sub",
          "left":  { "op": "count", "query": { "zone": "play", "controller": "$you",      "types": ["character"] } },
          "right": { "op": "count", "query": { "zone": "play", "controller": "$opponent", "types": ["character"] } } } }
    ],
    "lookahead": 1,
    "randomTiebreak": true
  }
}
```

Features are written in the **same expression language as card abilities** ([doc 06](06-effect-dsl.md)).
There is one expression evaluator in the platform, used by abilities, deckbuilding
constraints, state checks and bot features alike.

`lookahead: 1` means the bot applies each candidate action to a copy of the state, scores
the result, and picks the best — possible only because the kernel is pure and cheap to
fork.

### MCTS and hidden information

Standard determinization: sample K consistent hidden states (shuffle the unseen cards
consistently with everything observed), run a search in each, vote across the results.
Cheating-by-peeking is explicitly disallowed; the sampler is given only the `PlayerView`.

Budget is per-decision (`iterations` or `milliseconds`), configurable per batch.

## Simulation runner

```
POST /api/v1/simulations
{
  "gameVersionId": "...",
  "label": "v0.4 aggro vs control, post-Vanguard-nerf",
  "matchups": [
    { "seats": [ { "deckVersionId": "A", "botProfileId": "heuristic-aggro" },
                 { "deckVersionId": "B", "botProfileId": "heuristic-control" } ], "runs": 2000 }
  ],
  "seedStrategy": "mirrored",
  "keepLogs": "errors_and_sample",
  "roundCap": 30
}
```

* **`seedStrategy: "mirrored"`** runs each seed twice with seats swapped. This cancels
  first-player advantage out of the deck comparison, which otherwise dominates the signal
  in most LCGs. It is the default for a reason.
* Jobs are queued to Redis; each worker runs matches in a tight loop with no I/O per match.
* `keepLogs` options: `none`, `errors_and_sample` (default — every errored run plus a 1%
  sample), `all` (expensive; for deep investigation).
* Progress is streamed to the UI; a batch is resumable and cancellable.

Throughput target: ~5,000 heuristic matches/minute/core, so 10,000 runs is a coffee break,
not an overnight job.

## Metrics

### Match-level

| Metric | Reading it |
|---|---|
| Win rate (with 95% CI) | The headline. Always shown with the interval — 52% over 100 games means nothing. |
| First-player win rate | Should sit near 50%. Persistent skew means the round structure or income curve is off. |
| Mean / p10 / p90 game length in rounds | Bimodal distributions usually mean a "win or lose by round 4" degenerate line. |
| End reason distribution | Objective vs. deck-out vs. round cap. A high round-cap rate means games stall. |
| Mulligan / opening hand keep rate | Flags unplayable opening hands. |

### Card-level telemetry

Recorded per run, aggregated after:

| Metric | What it catches |
|---|---|
| **Play rate** — drawn vs. played | Cards that are drawn and never played are dead weight |
| **Win rate when played** vs. baseline | The single best "is this card too strong" signal |
| **Mean round first played** | A card that only ever appears on round 8 in a 6-round game is unplayable |
| **Discarded-unplayed rate** | Cost or timing is wrong |
| **Board survival time** | Whether a body actually does anything |
| **Damage/value contribution** | Attributed via effect events tagged with their source |
| **Co-occurrence lift** | Which pairs of cards win more together than apart — finds combos you didn't design |

### Automated findings

The balance reporter turns metrics into statements, each with the evidence attached:

* `OVERPERFORMER` — win-rate-when-played more than 2σ above the deck's baseline
* `DEAD_CARD` — play rate < 40% while in the deck
* `UNREACHABLE` — mean first-played round > 80th percentile game length
* `DOMINANT_MATCHUP` — matchup win rate outside 40–60% with a tight CI
* `DEGENERATE_LINE` — a repeated action pattern preceding wins before the game's expected
  midpoint (surfaced with a replay link)
* `STALL` — round-cap rate above 5%
* `SWING` — variance in game length or damage far above the set's norm

Every finding links to representative replays. "Ashen Vanguard is overperforming" is an
opinion; "here are five replays where it decided the game by round 3" is a design meeting.

### Static analysis (no simulation needed)

Cheap checks run on every save:

* **Curve coverage** — cards per cost per type against the set's `design.budget`
* **Stat-space outliers** — a card whose (cost, attack, health, ability weight) is far
  outside the cloud of its peers
* **Cost regression** — fit cost against stats and simulated performance over existing
  cards; report predicted vs. actual cost per card. Advisory, and often wrong in
  interesting ways — which is itself informative.
* **Orphans** — cards no other card interacts with
* **Trigger cycle detection** — pairs of cards whose triggers can feed each other

## The iteration loop this enables

```
edit a card
  → static checks (instant)
  → solo playtest against a bot (seconds)
  → 2,000-match batch on the affected matchups (minutes)
  → read the findings, look at two replays
  → edit again
```

The goal is that the *slowest* part of a balance change is deciding what to do, not
finding out what happened.

## Guardrails

* Simulation results are always pinned to `(game version, deck versions, bot profile)`.
  Comparing across bot versions without saying so is how teams fool themselves.
* Matches containing sandbox actions are excluded from all statistics.
* A bot is not a player. Findings describe what a *heuristic agent* does; a strong human
  will find lines it won't. Treat simulation as a filter for the obviously broken and a
  generator of hypotheses, not a verdict.
