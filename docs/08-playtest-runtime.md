# 08 — Playtest Runtime

How you actually play the game through the site.

## Modes

| Mode | Seats | Transport | Use |
|---|---|---|---|
| **Solo** | you + bot(s) | REST | Fast iteration on a new card |
| **Hotseat** | all seats, one browser | REST | Designing on your own, both sides |
| **Online** | one browser per seat | WebSocket | Real playtests with testers |
| **Sandbox** | you, god mode | REST | "What if the board looked like this?" |
| **Simulation** | bots only, headless | none | [Doc 09](09-automation-and-balance.md) |

All five drive the same kernel. Solo and simulation differ only in whether a human sits in
one of the seats.

## Session lifecycle

```
create   POST /api/v1/matches  { gameVersionId, mode, seats[], seed?, config }
         → builds initial GameState by running the system's `setup` script
         → persists matches + match_players + initial_state
lobby    seats fill (invite link for online mode); ready-up
start    POST /api/v1/matches/{id}/start → settle() → first pendingChoice
play     loop of actions (below)
end      terminal state → result written, log sealed
review   replay scrubber, notes, "fork from here"
```

`seed` is optional; if omitted, one is generated and stored. Supplying a seed reproduces a
previous match's shuffles exactly, which is how a designer re-tests a specific opening hand
after changing a card.

## The action loop

```
1. Client renders PlayerView + legal action list
2. Player clicks a card or an action button
3. Client sends { matchId, actionId, params, expectedVersion }
4. Server:
     a. authorise seat
     b. optimistic lock: expectedVersion === state.version, else 409 with fresh view
     c. Kernel::apply → Kernel::settle
     d. append to match_actions (immutable); snapshot every 20 actions
     e. broadcast per-seat redacted views + the event list produced
5. Client animates from the event list, then commits to the new state
```

The **event list is the animation script**. `card.entered_zone`, `damage.dealt`,
`card.exhausted` etc. each map to a visual transition. This means the animation layer never
diffs two states to guess what happened — it is told, in order.

### Pending choices

When the kernel needs a decision it sets `pendingChoice`:

```json
{
  "seat": 1,
  "kind": "choose_cards",
  "prompt": "Choose an enemy character",
  "options": { "cards": ["i-011", "i-014"] },
  "count": 1,
  "optional": false,
  "source": { "instance": "i-022", "ability": "a1" }
}
```

Choice kinds: `choose_cards`, `choose_players`, `choose_option` (modal), `choose_number`,
`yes_no`, `order_items` (for trigger ordering and deck-top arrangement),
`distribute` (spread N damage across targets).

The UI has one component per kind, driven entirely by this object. Adding a new choice kind
is a kernel change plus one component — no per-game work, ever.

`source` is what lets the UI say *"Ashen Vanguard is asking"* and highlight the card
responsible, which is the single biggest clarity win in a playtest client.

## The table UI

Regions, all driven by `ui.board` from the system document:

* **Board rows** — one per configured row, cards laid out in play. Attachments render
  tucked under their host. Exhausted cards rotate 90°.
* **Hand dock** — fanned, hover-to-enlarge. Unplayable cards dimmed (from the legal action
  list — the client never decides this itself).
* **Zone piles** — deck/discard with counts; click to inspect if visibility allows.
* **Resource tray** — per-player resource counters and identity card.
* **Action bar** — contextual: available actions, "Pass", "End phase", undo.
* **Phase rail** — the round structure from the system document with the current step lit.
  Because it's generated, it is always correct for the game being played.
* **Event log** — human-readable stream generated from events, with card links. Filterable.
  Clicking a log line scrubs the replay to that point.
* **Inspector** — click any card: full render, current *modified* values with a breakdown
  ("Attack 3 = 2 base +1 from Warhorn"). This breakdown is generated from the modifier
  layer stack and is the fastest way to debug a rules interaction.

### Playtester affordances

* **Note anchoring.** `N` at any moment files a note pinned to the current action sequence.
  Reopening it restores that exact state.
* **"Why can't I play this?"** Right-click an unplayable card → the kernel returns the
  failed requirement or unpayable cost, in words.
* **Reveal-all toggle** (solo/sandbox only) — see hidden zones while debugging.
* **Fork from here** — branch a new match from any point in a replay to explore an
  alternative line. Forks record their parent, so a branching tree of "what ifs" is
  browsable.

## Transport

**REST** for solo/hotseat/sandbox — simplest thing that works, no connection state.

**WebSocket (Laravel Reverb)** for online:

* Private channel per match per seat: `private-match.{id}.seat.{n}` (redacted view).
* Presence channel `presence-match.{id}` for who's connected, plus "opponent is thinking".
* On reconnect the client sends its last known `version`; the server replies with either a
  delta of events or a full fresh view if too far behind.
* Server is authoritative; a client that sends a stale `expectedVersion` gets a 409 and a
  resync rather than a silent overwrite.

## Reconnection & durability

Live state lives in Redis with the DB action log as the durable record. If Redis is lost
mid-match, state is rebuilt from `initial_state` + `match_actions`. Because replay is exact,
this is a transparent recovery rather than a lost game.

Matches idle for 24h are archived (state evicted from Redis, resumable from the log).

## Replay & review

A completed match is a first-class artifact:

* **Scrubber** — step through action by action, forwards and backwards, with the board
  reconstructing at each point (nearest snapshot + replay).
* **Event filter** — show only damage, only draws, only a specific card's involvement.
* **Card timeline** — for a chosen card: when it was drawn, played, and what it did.
* **Share link** — a read-only replay URL. This is the bug report format: not "it crashed",
  but "here is the exact sequence".
* **Export** — `replay.json` conforming to [`replay.schema.json`](../schemas/replay.schema.json),
  which can be attached to a regression test in one command
  (`php artisan test:add-replay <file>`). A playtester's bug becomes a permanent test.
