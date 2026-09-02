import { setActivePinia, createPinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useCardsStore } from './cards'

vi.mock('@/api/client', () => ({ api: { cards: vi.fn() } }))

const { api } = await import('@/api/client')

const card = (over: Record<string, unknown>) => ({
  id: 'x',
  code: 'EMB-001',
  name: 'Scout',
  type: 'character',
  faction: 'ember',
  cost: 2,
  traits: [],
  keywords: [],
  status: 'draft',
  setId: 's1',
  abilityCount: 0,
  ...over,
})

describe('cards store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    vi.mocked(api.cards).mockResolvedValue({
      data: [
        card({ code: 'EMB-001' }),
        card({ code: 'EMB-002', type: 'spell', faction: 'ash' }),
        card({ code: 'EMB-003', type: 'spell', faction: null }),
      ],
      meta: { page: 1, perPage: 200, total: 3 },
    } as never)
  })

  it('toggles a facet value on and off', () => {
    const store = useCardsStore()

    store.toggle('type', 'character')
    store.toggle('type', 'spell')
    expect(store.filters.type).toEqual(['character', 'spell'])

    store.toggle('type', 'character')
    expect(store.filters.type).toEqual(['spell'])
  })

  it('ignores a toggle on a scalar filter', () => {
    const store = useCardsStore()
    store.filters.q = 'ember'

    store.toggle('q', 'anything')

    expect(store.filters.q).toBe('ember')
  })

  it('renders the active filter as chips, which is also the shareable query', () => {
    const store = useCardsStore()
    store.toggle('type', 'character')
    store.filters.q = 'scout'

    expect(store.chips).toEqual([
      { key: 'type', label: 'type:character', value: 'character' },
      { key: 'q', label: 'q=scout', value: 'scout' },
    ])
  })

  it('clears back to an empty filter rather than a partly cleared one', () => {
    const store = useCardsStore()
    store.toggle('type', 'character')
    store.toggle('traits', 'beast')
    store.filters.costMin = 2

    store.clear()

    expect(store.chips).toEqual([])
    expect(store.filters.costMin).toBeUndefined()
  })

  it('sends the filter to the server rather than filtering locally', async () => {
    const store = useCardsStore()
    store.toggle('faction', 'ember')

    await store.load('emberfall')

    expect(api.cards).toHaveBeenCalledWith('emberfall', expect.objectContaining({ faction: ['ember'] }))
    expect(store.total).toBe(3)
  })

  it('counts facets from what came back, skipping cards that have no value', async () => {
    const store = useCardsStore()
    await store.load('emberfall')

    expect(store.counts('type')).toEqual({ character: 1, spell: 2 })
    expect(store.counts('faction')).toEqual({ ember: 1, ash: 1 })
  })

  it('clears the loading flag even when the load fails', async () => {
    vi.mocked(api.cards).mockRejectedValue(new Error('offline'))

    const store = useCardsStore()
    await expect(store.load('emberfall')).rejects.toThrow('offline')
    expect(store.loading).toBe(false)
  })
})
