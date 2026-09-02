# ADR-0003 — Card abilities are declarative data

**Status:** Accepted

## Context

Card abilities have to be expressed somehow. The options:

1. **Prose + hand-written code per card.** What most physical-game design tools do
   (spreadsheet plus a programmer). Maximum expressiveness, zero automation.
2. **Embedded scripting** (Lua, sandboxed JS). Very expressive, familiar to programmers.
3. **Declarative data DSL.** Structured JSON the engine interprets.

## Decision

Option 3, with a narrow, reviewed escape hatch (`{"op":"custom","handler":"..."}`) for
abilities the DSL genuinely cannot express.

## Consequences

**Good**

* **Card text is generated from behaviour**, so they cannot disagree. This kills the single
  most expensive bug class in card game design.
* Abilities are searchable and analysable: "every card that destroys a permanent", "every
  card that references Soldiers", "which triggers can loop", "what is this card's maximum
  damage output". None of this is possible with script bodies.
* A non-programmer can author abilities through a visual builder that cannot produce
  invalid structures.
* No sandbox escape surface, no untrusted code execution, no per-card review of arbitrary
  logic.
* Abilities diff meaningfully in version history.

**Bad**

* Expressiveness ceiling. Some abilities are awkward or impossible, and the DSL must grow
  new ops over time — each addition is engine work.
* Verbosity. The JSON for "deal 1 damage" is much longer than the sentence. Mitigated
  entirely by the visual builder; nobody should be hand-writing this.
* A learning curve for the op vocabulary, mitigated by inline help and generated text
  preview.

## Managing the escape hatch

`custom` is a pressure valve, not a strategy. The linter tracks the proportion of cards
using it. Above roughly 5%, the correct response is to add the missing primitive to the DSL,
not to write more handlers — otherwise the platform quietly degenerates into option 1 with
extra steps.

## Revisit if

The op catalogue grows past the point of comprehensibility, or `custom` usage stays high
despite DSL growth — either would suggest a constrained expression *language* (still
sandboxed, still analysable) is the better fit.
