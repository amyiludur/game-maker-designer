import type {
  ApiError,
  CardDetail,
  CardSummary,
  CompiledBundle,
  DeckLegality,
  GameSummary,
  LintFinding,
  MatchEnvelope,
} from './types'

/**
 * The typed HTTP client.
 *
 * Every call goes through one place so that a 409 — the match moved on — is handled the same
 * way everywhere: the caller is handed the fresh state that came back with it rather than
 * being left to guess.
 */
export class ApiError_ extends Error {
  constructor(
    public readonly status: number,
    public readonly error: ApiError,
    public readonly data?: unknown,
  ) {
    super(error.message)
    this.name = 'ApiError'
  }

  /** The position moved under us; `data` carries the current one. */
  get isStale(): boolean {
    return this.status === 409 && this.error.code === 'stale_version'
  }

  /** The document was refused. `violations` says where, by JSON Pointer. */
  get violations(): { pointer: string; message: string }[] {
    return (this.error.details?.violations as { pointer: string; message: string }[]) ?? []
  }
}

const base = '/api/v1'

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`${base}${path}`, {
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    ...init,
  })

  const body = await response.json().catch(() => ({}))

  if (!response.ok) {
    throw new ApiError_(
      response.status,
      body.error ?? { code: 'http_' + response.status, message: response.statusText },
      body.data,
    )
  }

  return body.data as T
}

function query(params: Record<string, unknown>): string {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue
    if (Array.isArray(value)) {
      for (const item of value) search.append(`${key}[]`, String(item))
    } else {
      search.set(key, String(value))
    }
  }
  const rendered = search.toString()
  return rendered === '' ? '' : `?${rendered}`
}

export interface Paged<T> {
  data: T[]
  meta: { page: number; perPage: number; total: number }
}

async function paged<T>(path: string): Promise<Paged<T>> {
  const response = await fetch(`${base}${path}`, { headers: { Accept: 'application/json' } })
  const body = await response.json()
  if (!response.ok) {
    throw new ApiError_(response.status, body.error ?? { code: 'http', message: response.statusText })
  }
  return body as Paged<T>
}

export const api = {
  games: () => request<GameSummary[]>('/games'),

  game: (game: string) =>
    request<GameSummary & { sets: { id: string; code: string; name: string; cardCount: number }[] }>(
      `/games/${game}`,
    ),

  compiled: (game: string, version: string) =>
    request<CompiledBundle>(`/games/${game}/versions/${version}/compiled`),

  lint: (game: string, version: string) =>
    request<{ compiled: boolean; errors?: number; findings: LintFinding[] }>(
      `/games/${game}/versions/${version}/lint`,
    ),

  cards: (game: string, filters: Record<string, unknown> = {}) =>
    paged<CardSummary>(`/games/${game}/cards${query(filters)}`),

  card: (code: string) => request<CardDetail>(`/cards/${code}`),

  saveCard: (code: string, document: unknown, message?: string) =>
    request<CardDetail>(`/cards/${code}`, {
      method: 'PUT',
      body: JSON.stringify({ document, message }),
    }),

  completeness: (game: string, set: string) =>
    request<{
      set: { id: string; code: string; name: string }
      byType: { type: string; planned: number | null; authored: number }[]
      byCost: Record<string, number>
      goals: string[]
    }>(`/games/${game}/sets/${set}/completeness`),

  decks: (game: string) =>
    request<
      {
        id: string
        headVersionId: string | null
        name: string
        archetype: string | null
        cardCount: number
        valid: boolean | null
      }[]
    >(`/games/${game}/decks`),

  deck: (id: string) =>
    request<{ id: string; name: string; document: Record<string, unknown>; legality: DeckLegality }>(
      `/decks/${id}`,
    ),

  validateDeck: (game: string, document: unknown) =>
    request<DeckLegality>(`/games/${game}/decks/validate`, {
      method: 'POST',
      body: JSON.stringify({ document }),
    }),

  botProfiles: (game: string) =>
    request<{ id: string; name: string; strategy: string; implemented: boolean }[]>(
      `/games/${game}/bot-profiles`,
    ),

  createMatch: (payload: {
    gameVersionId: string
    seats: { seat: number; deckVersionId?: string; botProfileId?: string }[]
    seed?: number
    mode?: string
  }) => request<MatchEnvelope>('/matches', { method: 'POST', body: JSON.stringify(payload) }),

  match: (id: string, side: string) => request<MatchEnvelope>(`/matches/${id}${query({ side })}`),

  act: (
    id: string,
    payload: { side: string; actionId: string; params?: Record<string, string>; expectedVersion?: number },
  ) => request<MatchEnvelope>(`/matches/${id}/actions`, { method: 'POST', body: JSON.stringify(payload) }),

  choose: (
    id: string,
    payload: { side: string; choiceId: string; selection?: string[]; expectedVersion?: number },
  ) => request<MatchEnvelope>(`/matches/${id}/choice`, { method: 'POST', body: JSON.stringify(payload) }),

  undo: (id: string, toSequence: number) =>
    request<MatchEnvelope>(`/matches/${id}/undo`, { method: 'POST', body: JSON.stringify({ toSequence }) }),
}
