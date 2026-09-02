import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { api } from '@/api/client'
import type { CardSummary } from '@/api/types'

export interface CardFilters {
  type: string[]
  faction: string[]
  status: string[]
  traits: string[]
  keywords: string[]
  costMin?: number
  costMax?: number
  q?: string
}

const empty = (): CardFilters => ({ type: [], faction: [], status: [], traits: [], keywords: [] })

/**
 * The card index and its facets.
 *
 * Filters live here rather than in the component because they are also the URL: a filtered
 * view is a thing designers share with each other, so it has to survive being pasted into
 * a message.
 */
export const useCardsStore = defineStore('cards', () => {
  const cards = ref<CardSummary[]>([])
  const total = ref(0)
  const loading = ref(false)
  const filters = ref<CardFilters>(empty())
  const selection = ref<Set<string>>(new Set())

  const active = computed(() =>
    Object.entries(filters.value).filter(([, value]) =>
      Array.isArray(value) ? value.length > 0 : value !== undefined && value !== '',
    ),
  )

  /** The filter as chips, which is also what the shareable query string encodes. */
  const chips = computed(() =>
    active.value.flatMap(([key, value]) =>
      Array.isArray(value)
        ? value.map((item) => ({ key, label: `${key}:${item}`, value: item }))
        : [{ key, label: `${key}=${String(value)}`, value: String(value) }],
    ),
  )

  async function load(game: string): Promise<void> {
    loading.value = true
    try {
      const page = await api.cards(game, { ...filters.value, perPage: 200 })
      cards.value = page.data
      total.value = page.meta.total
    } finally {
      loading.value = false
    }
  }

  function toggle(key: keyof CardFilters, value: string): void {
    const current = filters.value[key]
    if (!Array.isArray(current)) return
    const at = current.indexOf(value)
    if (at === -1) current.push(value)
    else current.splice(at, 1)
  }

  function clear(): void {
    filters.value = empty()
  }

  /** Facet counts, computed from what came back rather than asked for separately. */
  function counts(field: 'type' | 'faction' | 'status'): Record<string, number> {
    const key = field === 'type' ? 'type' : field
    const tally: Record<string, number> = {}
    for (const card of cards.value) {
      const value = (card as unknown as Record<string, string | null>)[key]
      if (value === null || value === undefined) continue
      tally[value] = (tally[value] ?? 0) + 1
    }
    return tally
  }

  return { cards, total, loading, filters, selection, chips, load, toggle, clear, counts }
})
