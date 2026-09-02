import { setActivePinia, createPinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useGameStore } from './game'

vi.mock('@/api/client', () => ({
  api: { games: vi.fn(), game: vi.fn(), compiled: vi.fn(), lint: vi.fn() },
}))

const { api } = await import('@/api/client')

const summary = {
  id: 'g1',
  slug: 'emberfall',
  name: 'Emberfall',
  summary: null,
  cardCount: 42,
  version: { id: 'v1', semver: '0.3.0', status: 'draft' as const, lintErrors: 0 },
}

const compiled = {
  digest: 'd',
  cardTypes: {
    character: {
      id: 'character',
      name: 'Character',
      fields: [],
      modifiableAttributes: ['attack', 'health', 'cost'],
      playableTo: ['battlefield'],
      doubleSided: false,
      isIdentity: false,
      schema: {},
    },
  },
  vocabularies: {
    traits: ['beast', 'ember'],
    rarities: ['common'],
    factions: [{ id: 'ember', name: 'Ember', color: '#c0392b' }],
  },
  keywords: {},
  zones: {},
  phases: [],
  ui: { theme: { accent: '#c0392b' } },
}

describe('game store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    document.documentElement.style.removeProperty('--accent')

    vi.mocked(api.game).mockResolvedValue(summary as never)
    vi.mocked(api.compiled).mockResolvedValue(compiled as never)
    vi.mocked(api.lint).mockResolvedValue({
      compiled: true,
      findings: [
        { severity: 'error', code: 'unknown_op', message: 'no such op', path: '/a' },
        { severity: 'warning', code: 'orphan', message: 'unused keyword', path: '/b' },
      ],
    } as never)
  })

  it('takes the accent from the game document, not from the chrome', async () => {
    const store = useGameStore()
    await store.load('emberfall')

    // The one thing the mockups hardcode that must not be copied: a second game has to look
    // like itself.
    expect(document.documentElement.style.getPropertyValue('--accent')).toBe('#c0392b')
  })

  it('falls back to a neutral accent when the game declares none', async () => {
    vi.mocked(api.compiled).mockResolvedValue({ ...compiled, ui: {} } as never)

    const store = useGameStore()
    await store.load('emberfall')

    expect(document.documentElement.style.getPropertyValue('--accent')).toBe('#5b8cae')
  })

  it('splits lint so errors can block and warnings cannot', async () => {
    const store = useGameStore()
    await store.load('emberfall')

    expect(store.lintErrors).toHaveLength(1)
    expect(store.lintWarnings).toHaveLength(1)
  })

  it('exposes the game vocabularies the editor builds its controls from', async () => {
    const store = useGameStore()
    await store.load('emberfall')

    expect(store.traits).toEqual(['beast', 'ember'])
    expect(store.factionColor('ember')).toBe('#c0392b')
    expect(store.factionColor('nobody')).toBeUndefined()
    expect(store.factionColor(null)).toBeUndefined()
  })

  it('does not refetch a game it is already showing', async () => {
    const store = useGameStore()
    await store.load('emberfall')
    await store.load('emberfall')

    expect(api.compiled).toHaveBeenCalledTimes(1)
  })

  it('holds no compiled bundle for a game with no version yet', async () => {
    vi.mocked(api.game).mockResolvedValue({ ...summary, version: null } as never)

    const store = useGameStore()
    await store.load('emberfall')

    expect(store.compiled).toBeNull()
    expect(store.lint).toEqual([])
    expect(api.compiled).not.toHaveBeenCalled()
  })

  it('clears the loading flag even when the load fails', async () => {
    vi.mocked(api.game).mockRejectedValue(new Error('offline'))

    const store = useGameStore()
    await expect(store.load('emberfall')).rejects.toThrow('offline')
    expect(store.loading).toBe(false)
  })
})
