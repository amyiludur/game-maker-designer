# ADR-0001 — JSON documents are the source of truth

**Status:** Accepted

## Context

Every game has a different card shape. One game's characters have cost/attack/health; the
next has influence/loyalty/upkeep/faction-pips. A conventional normalised schema would need
either a table per game (unworkable) or an EAV attribute table (queryable but miserable to
work with, and it loses structure entirely for nested things like abilities).

Meanwhile abilities are trees, not rows. There is no comfortable relational encoding of
"deal damage equal to the number of Soldiers you control, then draw a card".

## Decision

The canonical representation of every game-defining object — system, card, set, deck, bot
profile, card template — is a JSON document stored in a `jsonb` column. Alongside each
document we store generated index columns (name, type, cost, traits, keywords, tsvector)
whose only purpose is query performance.

Three rules make this safe:

1. Writes go to the document; index columns are populated by one `Projector` per table, in
   the same transaction.
2. The kernel never reads an index column. Wrong index columns would produce wrong *search
   results*, never wrong *matches*.
3. Index columns must be fully rebuildable from documents, proven by an idempotent
   `reproject` command and a test that asserts it.

## Consequences

**Good**

* Arbitrary per-game card shapes with no schema migration
* Abilities keep their tree structure and can be searched with `jsonb` containment
* Diffing, versioning and export are trivial — they're just documents
* One validation contract (JSON Schema) shared by client, server and CI

**Bad**

* No foreign keys inside documents. A card referencing a deleted keyword is caught by lint,
  not by the database. We accept this and invest in linting.
* Large documents are awkward to update partially; we use JSON Patch on the API for
  targeted edits and rewrite whole documents otherwise.
* `jsonb` indexes are larger and slower to write than plain columns. At our scale
  (thousands of cards, not millions) this is irrelevant.
* Developers must resist the temptation to "just add a column and write to it directly".
  This is the real risk, and it's why the reproject test exists.

## Revisit if

Card counts reach a scale where `jsonb` query performance dominates, or a game shape
stabilises so completely across all games that a normalised schema becomes obviously
correct.
