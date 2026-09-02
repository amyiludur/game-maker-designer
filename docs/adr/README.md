# Architecture Decision Records

Each record states the decision, what it costs, and what would make us revisit it.

| # | Decision | Status |
|---|---|---|
| [0001](0001-json-source-of-truth.md) | JSON documents are the source of truth; DB columns are indexes | Accepted |
| [0002](0002-single-authoritative-kernel.md) | One authoritative PHP rules kernel; no client-side rules | Accepted |
| [0003](0003-declarative-effect-dsl.md) | Card abilities are declarative data, not embedded script | Accepted |
| [0004](0004-layered-modifiers.md) | Continuous effects resolve through a fixed layer system | Accepted |
| [0005](0005-determinism-and-replay.md) | Determinism is a hard requirement; replays are the universal mechanism | Accepted |
