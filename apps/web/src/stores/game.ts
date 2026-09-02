import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

import { api } from '@/api/client'
import type { CompiledBundle, GameSummary, LintFinding } from '@/api/types'

/**
 * The current game and its compiled bundle.
 *
 * The compiled bundle is the key object in the whole frontend: it is what makes the card
 * editor build its own forms, so a game with completely different attributes needs no change
 * here. Anything that hardcodes a card's fields has misunderstood the platform.
 */
export const useGameStore = defineStore('game', () => {
  const games = ref<GameSummary[]>([])
  const current = ref<GameSummary | null>(null)
  const compiled = ref<CompiledBundle | null>(null)
  const lint = ref<LintFinding[]>([])
  const loading = ref(false)

  const factions = computed(() => compiled.value?.vocabularies.factions ?? [])
  const traits = computed(() => compiled.value?.vocabularies.traits ?? [])
  const cardTypes = computed(() => Object.values(compiled.value?.cardTypes ?? {}))

  /** The colour a faction is drawn in. Game data, never a chrome token. */
  function factionColor(id: string | null | undefined): string | undefined {
    return factions.value.find((faction) => faction.id === id)?.color
  }

  async function loadGames(): Promise<void> {
    games.value = await api.games()
  }

  async function load(slug: string): Promise<void> {
    if (current.value?.slug === slug && compiled.value !== null) return

    loading.value = true
    try {
      const summary = await api.game(slug)
      current.value = summary

      const version = summary.version?.semver
      if (version === undefined) {
        compiled.value = null
        lint.value = []
        return
      }

      compiled.value = await api.compiled(slug, version)
      lint.value = (await api.lint(slug, version)).findings ?? []

      // The accent is the game's, not the application's: the whole point of a
      // multi-game platform is that Emberfall does not look like Warden's Hollow.
      applyAccent(compiled.value.ui?.theme?.accent)
    } finally {
      loading.value = false
    }
  }

  function applyAccent(accent: string | undefined): void {
    if (typeof document === 'undefined') return
    document.documentElement.style.setProperty('--accent', accent ?? '#5b8cae')
  }

  const lintErrors = computed(() => lint.value.filter((finding) => finding.severity === 'error'))
  const lintWarnings = computed(() => lint.value.filter((finding) => finding.severity === 'warning'))

  return {
    games,
    current,
    compiled,
    lint,
    lintErrors,
    lintWarnings,
    loading,
    factions,
    traits,
    cardTypes,
    factionColor,
    loadGames,
    load,
  }
})
