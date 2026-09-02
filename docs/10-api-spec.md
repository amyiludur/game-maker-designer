# 10 — API Spec

Base: `/api/v1`. JSON in, JSON out. Auth via Laravel Sanctum (session cookie for the SPA,
bearer tokens for CLI/CI). Every route is scoped to a workspace the caller is a member of.

Conventions:

* Collections return `{ data: [...], meta: { page, perPage, total } }`.
* Single resources return `{ data: {...} }`.
* Errors return `{ error: { code, message, details? } }` with an appropriate status.
* Documents (`document` fields) are validated against the JSON Schemas in `schemas/`;
  a failure returns `422` with `details.violations[]` giving JSON Pointer + message.
* `If-Match` / `expectedVersion` is used for optimistic concurrency on mutable documents.

---

## Games & versions

| Method | Path | Notes |
|---|---|---|
| `GET` | `/games` | List games in the workspace |
| `POST` | `/games` | Create; optionally `{ from: "template:duel-lcg" }` |
| `GET` | `/games/{game}` | Includes current draft version summary |
| `PATCH` | `/games/{game}` | Name, summary, settings |
| `DELETE` | `/games/{game}` | Soft delete |
| `GET` | `/games/{game}/versions` | |
| `POST` | `/games/{game}/versions` | Branch a new draft from a version |
| `GET` | `/games/{game}/versions/{v}` | Full system document |
| `PUT` | `/games/{game}/versions/{v}` | Replace the system document (draft only) |
| `PATCH` | `/games/{game}/versions/{v}` | JSON Patch (RFC 6902) for targeted edits |
| `POST` | `/games/{game}/versions/{v}/publish` | Freeze + write snapshot |
| `GET` | `/games/{game}/versions/{v}/compiled` | Card-type schemas, form descriptors, rules digest |
| `GET` | `/games/{game}/versions/{v}/lint` | Lint report |
| `GET` | `/games/{game}/versions/{v}/diff?against={v2}` | Structured diff + change classification |
| `GET` | `/games/{game}/versions/{v}/impact?against={v2}` | Cards/decks/matches invalidated by the change |
| `GET` | `/games/{game}/versions/{v}/rulebook?format=html\|md\|pdf` | Generated rulebook |

## Sets & cards

| Method | Path | Notes |
|---|---|---|
| `GET` | `/games/{game}/sets` · `POST` · `PATCH /sets/{set}` | |
| `GET` | `/games/{game}/sets/{set}/completeness` | Planned vs. authored, by type and cost |
| `GET` | `/games/{game}/cards` | Filter: `type`, `faction`, `cost`, `traits[]`, `keywords[]`, `status`, `set`, `q` (full-text), `sort`, `page` |
| `POST` | `/games/{game}/cards` | |
| `GET` | `/cards/{card}` | |
| `PUT` | `/cards/{card}` | New revision; body `{ document, message }` |
| `DELETE` | `/cards/{card}` | Retire (never hard-deleted — replays reference it) |
| `POST` | `/cards/{card}/duplicate` | |
| `GET` | `/cards/{card}/revisions` · `GET /cards/{card}/revisions/{n}` | |
| `GET` | `/cards/{card}/diff?from={n}&to={m}` | |
| `POST` | `/cards/{card}/revert` | `{ toRevision }` — creates a new revision |
| `POST` | `/games/{game}/cards/bulk` | `{ ids[], patch }` — preview with `?dryRun=1` |
| `POST` | `/games/{game}/cards/import` | CSV or JSON; returns a dry-run diff first |
| `GET` | `/games/{game}/cards/export?format=csv\|json` | |
| `GET` | `/cards/{card}/render?format=svg\|png\|pdf` | Card face render |
| `GET` | `/cards/{card}/similar` | Nearest neighbours in stat space |
| `GET` | `/cards/{card}/usage` | Decks containing it, telemetry summary |
| `POST` | `/games/{game}/cards/search/abilities` | Structural search over ability trees, e.g. `{ "op": "deal_damage" }` |

## Assets & templates

| Method | Path |
|---|---|
| `POST` | `/games/{game}/assets` (multipart) |
| `GET` | `/games/{game}/assets` · `DELETE /assets/{asset}` |
| `GET` `POST` `PUT` | `/games/{game}/card-templates[/{template}]` |

## Decks

| Method | Path | Notes |
|---|---|---|
| `GET` `POST` | `/games/{game}/decks` | |
| `GET` `PATCH` `DELETE` | `/decks/{deck}` | |
| `GET` `POST` | `/decks/{deck}/versions` | |
| `POST` | `/decks/{deck}/validate` | Legality + stats without saving |
| `GET` | `/decks/{deck}/stats` | Curve, type split, trait density |
| `GET` | `/decks/{deck}/export?format=json\|text` | |

## Matches (playtest)

| Method | Path | Notes |
|---|---|---|
| `POST` | `/matches` | `{ gameVersionId, mode, seats[], seed?, config }` |
| `GET` | `/matches` | Filter by game, mode, status, participant |
| `GET` | `/matches/{match}` | Metadata + result |
| `POST` | `/matches/{match}/join` | `{ seat }` |
| `POST` | `/matches/{match}/start` | |
| `GET` | `/matches/{match}/view` | Redacted `PlayerView` for the caller's seat |
| `GET` | `/matches/{match}/legal-actions` | Current legal action list |
| `GET` | `/matches/{match}/legal-targets?actionId=&targetId=` | Lazy target enumeration |
| `POST` | `/matches/{match}/actions` | `{ actionId, params, expectedVersion }` → `{ view, events }`; `409` on version mismatch |
| `POST` | `/matches/{match}/choice` | Answer a `pendingChoice` |
| `POST` | `/matches/{match}/explain` | `{ actionId }` → why an action is unavailable, in words |
| `POST` | `/matches/{match}/undo` | Truncate + replay; requires consent in online mode |
| `POST` | `/matches/{match}/sandbox` | God-mode action (sandbox mode only) |
| `POST` | `/matches/{match}/fork` | `{ atSequence }` → new match |
| `POST` | `/matches/{match}/concede` | |
| `GET` | `/matches/{match}/log?from=&to=` | Action + event log |
| `GET` | `/matches/{match}/state-at/{sequence}` | Reconstructed state for the scrubber |
| `GET` | `/matches/{match}/replay` | `replay.json` export |
| `POST` | `/matches/{match}/notes` · `GET` | Anchored playtest notes |

## Simulation & balance

| Method | Path | Notes |
|---|---|---|
| `GET` `POST` | `/games/{game}/bot-profiles[/{profile}]` | |
| `POST` | `/simulations` | Queue a batch |
| `GET` | `/simulations` · `GET /simulations/{batch}` | Includes progress |
| `POST` | `/simulations/{batch}/cancel` | |
| `GET` | `/simulations/{batch}/metrics` | Aggregated match + card metrics |
| `GET` | `/simulations/{batch}/report` | Automated findings with replay links |
| `GET` | `/simulations/{batch}/runs?filter=` | Individual runs; `filter=errors` for failures |
| `GET` | `/simulations/{batch}/compare?against={batch2}` | Side-by-side metric delta |
| `GET` | `/games/{game}/analysis/static` | Curve, outliers, orphans, cost regression |

## Export

| Method | Path |
|---|---|
| `GET` | `/games/{game}/export/bundle` — zip: system, cards, decks, assets, manifest |
| `POST` | `/games/{game}/import/bundle` |
| `GET` | `/games/{game}/export/proofs?set={set}` — print-and-play PDF |
| `GET` | `/games/{game}/export/changesheet?since={version}` |

## WebSocket channels (Reverb)

| Channel | Payload |
|---|---|
| `private-match.{id}.seat.{n}` | `match.updated { view, events, version }`, `match.choice`, `match.ended` |
| `presence-match.{id}` | seat presence, `opponent.thinking` |
| `private-user.{id}` | `simulation.progress`, `simulation.complete`, `card.commented` |
| `private-game.{id}` | `card.updated`, `version.published` — live refresh for open editors |

## Rate limits & sizes

| Concern | Limit |
|---|---|
| Authoring writes | 120/min per user |
| Match actions | 300/min per seat |
| Simulation batches | 5 concurrent per workspace, max 100,000 runs each |
| Document upload | 8 MB per document, 64 MB per bundle |
