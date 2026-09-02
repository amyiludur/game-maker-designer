/**
 * The shapes the API returns.
 *
 * Hand-written for the API envelopes and generated for the documents: `npm run types`
 * regenerates `documents.gen.ts` from the repository's JSON Schemas, so a card's shape here
 * is the same contract the server validates against and the kernel plays. Editing the
 * generated file by hand would break that, which is why CI checks it is up to date.
 */

export type {
  AbilityEffectDSL as AbilityDocument,
  BotProfile as BotProfileDocument,
  Card as CardDocument,
  Deck as DeckDocument,
  EncounterSet as EncounterSetDocument,
  GameSystem as GameSystemDocument,
  Scenario as ScenarioDocument,
  Set as SetDocument,
} from './documents.gen'

export type Severity = 'error' | 'warning' | 'info'

export interface GameSummary {
  id: string
  slug: string
  name: string
  summary: string | null
  cardCount: number
  version: {
    id: string
    semver: string
    status: 'draft' | 'published' | 'archived'
    lintErrors: number
  } | null
}

export interface CardSummary {
  id: string
  code: string
  name: string | null
  type: string | null
  faction: string | null
  cost: number | null
  traits: string[]
  keywords: string[]
  status: string
  setId: string | null
  abilityCount: number
}

export interface CardDetail extends CardSummary {
  document: Record<string, unknown>
  revisions: { revision: number; message: string | null; createdAt: string }[]
}

/** A starter system a new game can be built on. `templates/*.json`, as the picker needs it. */
export interface GameTemplate {
  id: string
  name: string
  summary: string | null
  cardTypes: number
  phases: number
}

export interface SetSummary {
  id: string
  code: string
  name: string
  releaseOrder: number
  status: string
  summary: string | null
  cardCount: number
  budget: Record<string, number>
  goals: string[]
}

/** One version of a game's rules — the document the system editor edits. */
export interface VersionDetail {
  id: string
  semver: string
  status: 'draft' | 'published' | 'archived'
  document: Record<string, unknown>
}

/** What one collection of the system document gained and lost. */
export interface ImpactChange {
  removed: string[]
  added: string[]
  changed: string[]
}

/**
 * One consequence of a proposed system change, with the evidence for it.
 *
 * `evidence` is card codes, deck names or the places in the system that still name the thing
 * being removed — the panel exists so a designer can go and look at them.
 */
export interface ImpactFinding {
  severity: Severity
  rule: string
  subject: 'system' | 'cards' | 'decks' | 'matches'
  message: string
  count: number
  evidence: string[]
  fix?: string
}

export interface ImpactReport {
  compiles: boolean
  error: { message?: string } | null
  changes: Record<string, ImpactChange>
  findings: ImpactFinding[]
  version: { from: string; suggested: string; classification: 'major' | 'minor' | 'patch' | 'none' }
}

/** One field of a card type, as the compiler describes it. The editor renders from this. */
export interface FormField {
  id: string
  name: string
  type: 'integer' | 'decimal' | 'string' | 'text' | 'boolean' | 'enum' | 'tagList' | 'reference'
  required?: boolean
  default?: unknown
  min?: number
  max?: number
  options?: string[]
  vocabulary?: string
  perPlayer?: boolean
  constraint?: string
  help?: string
}

export interface CompiledCardType {
  id: string
  name: string
  fields: FormField[]
  modifiableAttributes: string[]
  playableTo: string[]
  doubleSided: boolean
  isIdentity: boolean
  schema: Record<string, unknown>
}

export interface CompiledBundle {
  digest: string
  cardTypes: Record<string, CompiledCardType>
  vocabularies: {
    traits?: string[]
    rarities?: string[]
    factions?: { id: string; name: string; color?: string; icon?: string }[]
  }
  keywords: Record<string, { id: string; name: string; reminder: string | null; parameters: unknown[] }>
  zones: Record<string, { id: string; name: string; scope: string; visibility: string }>
  phases: { id: string; name: string; steps: { id: string; name: string; kind: 'auto' | 'window' }[] }[]
  ui: { board?: BoardLayout; theme?: { accent?: string; surface?: string } }
}

export interface BoardLayout {
  layout?: string
  rows?: { id: string; zone: string; player: string; collapsed?: boolean }[]
  docks?: Record<string, string>
}

export interface LintFinding {
  severity: Severity
  rule: string
  message: string
  where?: string
  fix?: string
}

/** A card as one side sees it. Hidden cards carry an id and nothing else. */
export interface ViewCard {
  id: string
  hidden?: true
  code?: string
  name?: string
  owner?: string
  controller?: string
  face?: string
  exhausted?: boolean
  counters?: Record<string, number>
  attachedTo?: string | null
  attachments?: string[]
  types?: string[]
  traits?: string[]
  keywords?: string[]
  attributes?: Record<string, unknown>
  modified?: Record<string, ModifiedAttribute>
}

/** Why a value is not its printed value: "Attack 3 = 2 base +1 from Warhorn". */
export interface ModifiedAttribute {
  printed: unknown
  current: unknown
  from: { source: string; mode: string; amount: unknown; layer: number }[]
}

export interface PendingChoice {
  id: string
  key: string
  kind: string
  seat: number
  prompt?: string
  options?: { cards?: string[]; players?: string[]; items?: string[]; min?: number; max?: number }
  count?: number | { min: number; max: number }
  optional?: boolean
  source?: { instance?: string; ability?: string }
}

export interface PlayerView {
  side: string
  viewVersion: number
  round: number
  phase: string
  step: string
  activeSide: string
  zones: Record<string, ViewCard[]>
  players: {
    seat: number
    side: string
    resources: Record<string, number>
    identityInstance: string | null
    status: string
  }[]
  pendingChoice?: PendingChoice | null
  result?: { winners: string[]; losers: string[]; reason: string; rounds: number; draw?: boolean } | null
  log?: { seq: number; type: string; payload: Record<string, unknown> }[]
}

export interface LegalAction {
  key: string
  actionId: string
  params: Record<string, string>
  label: string
}

export interface GameEvent {
  seq: number
  type: string
  payload: Record<string, unknown>
}

export interface MatchEnvelope {
  match: {
    id: string
    mode: string
    status: string
    seed: number
    actionCount: number
    result: Record<string, unknown> | null
  }
  version: number
  stateHash: string
  view: PlayerView
  legalActions: LegalAction[]
  events: GameEvent[]
}

export interface DeckLegality {
  valid: boolean
  violations: { constraint: string; message: string; cards?: string[]; severity: Severity }[]
  stats: {
    total: number
    byType: Record<string, number>
    curve: Record<string, number>
    traits: Record<string, number>
    averageCostTenths: number
  }
}

export interface ApiError {
  code: string
  message: string
  details?: Record<string, unknown>
}
