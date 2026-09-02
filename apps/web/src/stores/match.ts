import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { ApiError_, api } from '@/api/client'
import type { GameEvent, LegalAction, MatchEnvelope, PlayerView, ViewCard } from '@/api/types'

/**
 * A thin mirror of the server.
 *
 * It holds the view, the legal actions, the pending choice and the version — and it computes
 * none of them. Any temptation to work out here whether a card is playable is a bug: the
 * client rendering the wrong thing is a display problem, but the client *deciding* the wrong
 * thing would make the game wrong (ADR-0002).
 */
export const useMatchStore = defineStore('match', () => {
  const matchId = ref<string | null>(null)
  const side = ref('p0')
  const view = ref<PlayerView | null>(null)
  const legalActions = ref<LegalAction[]>([])
  const version = ref(0)
  const stateHash = ref('')
  const status = ref('lobby')
  const actionCount = ref(0)
  const pending = ref(false)
  const problem = ref<string | null>(null)

  /** Events waiting to be animated. The list is the script; we are told, not asked to guess. */
  const eventQueue = ref<GameEvent[]>([])

  const pendingChoice = computed(() => view.value?.pendingChoice ?? null)
  const isOver = computed(() => view.value?.result != null)
  const round = computed(() => view.value?.round ?? 0)
  const step = computed(() => `${view.value?.phase ?? ''}.${view.value?.step ?? ''}`)

  /** Which cards this side may currently choose, so the board can ring them. */
  const targetable = computed(() => {
    const choice = pendingChoice.value
    if (choice !== null) return new Set(choice.options?.cards ?? [])

    const ids = new Set<string>()
    for (const action of legalActions.value) {
      for (const value of Object.values(action.params)) ids.add(value)
    }
    return ids
  })

  function absorb(envelope: MatchEnvelope): void {
    matchId.value = envelope.match.id
    version.value = envelope.version
    stateHash.value = envelope.stateHash
    status.value = envelope.match.status
    actionCount.value = envelope.match.actionCount
    view.value = envelope.view
    legalActions.value = envelope.legalActions
    if (envelope.events.length > 0) eventQueue.value.push(...envelope.events)
    problem.value = null
  }

  async function open(id: string, asSide = 'p0'): Promise<void> {
    side.value = asSide
    absorb(await api.match(id, asSide))
  }

  async function act(action: LegalAction): Promise<void> {
    if (matchId.value === null || pending.value) return
    pending.value = true
    try {
      absorb(
        await api.act(matchId.value, {
          side: side.value,
          actionId: action.actionId,
          params: action.params,
          expectedVersion: version.value,
        }),
      )
    } catch (error) {
      handle(error)
    } finally {
      pending.value = false
    }
  }

  async function choose(selection: string[]): Promise<void> {
    const choice = pendingChoice.value
    if (matchId.value === null || choice === null) return
    pending.value = true
    try {
      absorb(
        await api.choose(matchId.value, {
          side: side.value,
          choiceId: choice.id,
          selection,
          expectedVersion: version.value,
        }),
      )
    } catch (error) {
      handle(error)
    } finally {
      pending.value = false
    }
  }

  async function undo(): Promise<void> {
    if (matchId.value === null || actionCount.value === 0) return
    absorb(await api.undo(matchId.value, Math.max(0, actionCount.value - 1)))
  }

  function handle(error: unknown): void {
    if (error instanceof ApiError_ && error.isStale && error.data !== undefined) {
      // Someone else moved the game on. Take what came back rather than arguing with it.
      absorb(error.data as MatchEnvelope)
      problem.value = 'The board had moved on — showing the current position.'
      return
    }
    problem.value = error instanceof Error ? error.message : String(error)
  }

  function drain(): GameEvent[] {
    const events = eventQueue.value
    eventQueue.value = []
    return events
  }

  function card(zone: string, id: string): ViewCard | undefined {
    return view.value?.zones[zone]?.find((entry) => entry.id === id)
  }

  return {
    matchId,
    side,
    view,
    legalActions,
    version,
    stateHash,
    status,
    actionCount,
    pending,
    problem,
    eventQueue,
    pendingChoice,
    isOver,
    round,
    step,
    targetable,
    absorb,
    open,
    act,
    choose,
    undo,
    drain,
    card,
  }
})
