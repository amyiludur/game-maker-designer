# ADR-0009 — Bot seats are driven by the server

**Status:** Accepted

## Context

[Doc 08](../08-playtest-runtime.md) lists solo as "you + bot(s)" and says the five match modes
"all drive the same kernel — solo and simulation differ only in whether a human sits in one of
the seats". It does not say *where* the bot runs, and there are two plausible answers: the
browser drives the opponent between the human's moves, or the server does.

The question is not academic. Emberfall's setup script ends with
`set_first_player {"rule": "random"}`, so roughly half of all matches open on the opponent's
turn. Until this was decided, `bot_profile_id` was stored on `match_players` and never read
for a decision, and those matches dealt an opening and then sat there — the human had no legal
actions and nothing was going to give them any.

## Decision

The server plays bot seats, inside the same request that carries the human's move.

`MatchService::driveBots()` runs after the human's action has been applied and settled, and
keeps going while the seat with the move is a bot's — the pending choice's seat if one is
open, otherwise the side with priority. It uses `Gmd\Harness\Agent\RandomAgent` and the same
loop `MatchRunner` uses in the fuzzer: legal actions, choose, apply, settle. A bot at the live
table and a bot in a 10,000-match fuzz run take the same path through the same engine, or the
thing that was fuzzed is not the thing being played.

**A bot's move is an ordinary entry in the action log.** It goes through the same
`advance()` as a human's, carries its seat, and nothing marks it as a bot's. That is the whole
trick: undo ([ADR-0008](0008-undo-is-recorded.md)), reconstruction, snapshots and the replay
export needed no changes and contain no branch on whether a player is a person.

**The bot's RNG is a separate stream** from the game's, seeded from the match seed and
advanced by the action count. So a decision is a pure function of `(seed, seat, sequence)` —
the same seed replays the same solo match — without adding anything to the hashed state
([ADR-0006](0006-canonical-state-hash.md)). A bot drawing from the game's generator would
change the shuffle by thinking, and two bots would interfere with each other's decisions.

This is [ADR-0002](0002-single-authoritative-kernel.md) applied to players who are not human:
a bot is a player, and a player's decisions are not the client's to make.

## Consequences

**Good.** One request returns the human's move and the opponent's reply together, so the
browser animates both from the event stream it already drains, and there is no second
round-trip and no "waiting for the bot" state to design. A client cannot cheat by driving the
opponent badly, because it cannot drive the opponent at all. Simulation batches (M4) reuse
this path rather than growing a parallel one.

**The cost.** A request now takes as long as the bot's whole turn, and a bot with a slow
strategy — MCTS, eventually — would be felt as latency by the person who moved. The loop is
capped at 100 moves per request and raises rather than holding the connection open. When a
slow strategy arrives it moves to a queued job and the browser is told to wait, which is a
change to *when* the client is told, not to who decides.

**What would make us revisit it.** Online multiplayer (M5) puts a real transport in place; a
bot filling an empty seat in a live game is more naturally a queue worker publishing to the
same channel than a synchronous step inside someone's move.
