import { setActivePinia, createPinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError_ } from '@/api/client'
import type { MatchEnvelope } from '@/api/types'
import { useMatchStore } from './match'

vi.mock('@/api/client', async () => {
  const actual = await vi.importActual<typeof import('@/api/client')>('@/api/client')
  return { ...actual, api: { match: vi.fn(), act: vi.fn(), choose: vi.fn(), undo: vi.fn() } }
})

const { api } = await import('@/api/client')

function envelope(overrides: Partial<MatchEnvelope> = {}): MatchEnvelope {
  return {
    match: { id: 'm1', status: 'active', actionCount: 3, mode: 'solo', seed: 48 },
    waitingOn: 'p0',
    version: 7,
    stateHash: 'abc',
    view: {
      side: 'p0',
      round: 2,
      phase: 'action',
      step: 'main',
      activeSide: 'p0',
      priority: 'p0',
      players: {},
      zones: { 'p0.hand': [{ id: 'i-p0-3', code: 'EMB-004', name: 'Scout' }] },
      log: [],
      pendingChoice: null,
      result: null,
    },
    legalActions: [
      { actionId: 'play_character', key: 'k1', params: { card: 'i-p0-3' }, label: 'Play Scout' },
      { actionId: 'pass', key: 'k2', params: {}, label: 'Pass' },
    ],
    events: [{ type: 'card_drawn', seq: 1, data: {} }],
    ...overrides,
  } as unknown as MatchEnvelope
}

describe('match store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('takes its whole position from the envelope', () => {
    const store = useMatchStore()
    store.absorb(envelope())

    expect(store.matchId).toBe('m1')
    expect(store.version).toBe(7)
    expect(store.stateHash).toBe('abc')
    expect(store.round).toBe(2)
    expect(store.step).toBe('action.main')
    expect(store.legalActions).toHaveLength(2)
  })

  it('rings only what the server says is choosable', () => {
    const store = useMatchStore()
    store.absorb(envelope())

    // Derived from legalActions' params — never from a rule the client re-implements.
    expect([...store.targetable]).toEqual(['i-p0-3'])
  })

  it('prefers the pending choice over the action list when one is open', () => {
    const store = useMatchStore()
    const base = envelope()
    base.view.pendingChoice = {
      id: 'c1',
      kind: 'select_target',
      side: 'p0',
      prompt: 'Choose',
      options: { cards: ['i-p1-9'] },
    }
    store.absorb(base)

    expect([...store.targetable]).toEqual(['i-p1-9'])
  })

  it('moves to the seat the server is waiting on at a hotseat table', async () => {
    // A cooperative table is all-human — the adversary is a script, so there is no other
    // agent to hand the turn to. Without this the board sits on p0 showing an empty action
    // bar for three players out of four, which looks exactly like a game that has hung.
    const store = useMatchStore()
    vi.mocked(api.match).mockResolvedValue(
      envelope({ match: { ...envelope().match, mode: 'hotseat' }, waitingOn: 'p2' }),
    )

    await store.open('m1', 'p0')

    // Fetched again as p2: the first response said the game was waiting on that seat, and a
    // p0 view cannot show p2's hand.
    expect(api.match).toHaveBeenLastCalledWith('m1', 'p2')
    expect(store.side).toBe('p2')
  })

  it('stays put when another agent holds the turn', async () => {
    // A solo match has a bot in the other seat, and the server drives it. Following it would
    // show the human the bot's hand.
    const store = useMatchStore()
    vi.mocked(api.match).mockResolvedValue(envelope({ waitingOn: 'p1' }))

    await store.open('m1', 'p0')

    expect(api.match).toHaveBeenCalledTimes(1)
    expect(store.side).toBe('p0')
  })

  it('sends the version it is showing so a stale action is refused', async () => {
    const store = useMatchStore()
    store.absorb(envelope())
    vi.mocked(api.act).mockResolvedValue(envelope({ version: 8 }))

    await store.act(store.legalActions[0]!)

    expect(api.act).toHaveBeenCalledWith('m1', {
      side: 'p0',
      actionId: 'play_character',
      params: { card: 'i-p0-3' },
      expectedVersion: 7,
    })
    expect(store.version).toBe(8)
  })

  it('takes the fresh position handed back with a 409 rather than arguing with it', async () => {
    const store = useMatchStore()
    store.absorb(envelope())

    const fresh = envelope({ version: 12, stateHash: 'def' })
    vi.mocked(api.act).mockRejectedValue(
      new ApiError_(409, { code: 'stale_version', message: 'The match moved on.' }, fresh),
    )

    await store.act(store.legalActions[1]!)

    expect(store.version).toBe(12)
    expect(store.stateHash).toBe('def')
    expect(store.problem).toMatch(/moved on/)
  })

  it('reports any other failure without touching the position', async () => {
    const store = useMatchStore()
    store.absorb(envelope())
    vi.mocked(api.act).mockRejectedValue(
      new ApiError_(422, { code: 'illegal_action', message: 'Not a legal action.' }),
    )

    await store.act(store.legalActions[1]!)

    expect(store.version).toBe(7)
    expect(store.problem).toBe('Not a legal action.')
  })

  it('refuses to send a second action while one is in flight', async () => {
    const store = useMatchStore()
    store.absorb(envelope())

    let release: (value: MatchEnvelope) => void = () => {}
    vi.mocked(api.act).mockReturnValue(
      new Promise((resolve) => {
        release = resolve
      }),
    )

    const first = store.act(store.legalActions[0]!)
    await store.act(store.legalActions[1]!)
    expect(api.act).toHaveBeenCalledTimes(1)

    release(envelope({ version: 8 }))
    await first
  })

  it('drains the event queue exactly once', () => {
    const store = useMatchStore()
    store.absorb(envelope())

    expect(store.drain()).toHaveLength(1)
    expect(store.drain()).toHaveLength(0)
  })

  it('undoes back to the action before the last one', async () => {
    const store = useMatchStore()
    store.absorb(envelope())
    vi.mocked(api.undo).mockResolvedValue(envelope({ version: 9 }))

    await store.undo()

    expect(api.undo).toHaveBeenCalledWith('m1', 2)
  })

  it('does not undo an empty match', async () => {
    const store = useMatchStore()
    const base = envelope()
    base.match.actionCount = 0
    store.absorb(base)

    await store.undo()

    expect(api.undo).not.toHaveBeenCalled()
  })
})
