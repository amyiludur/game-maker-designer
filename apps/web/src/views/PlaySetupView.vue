<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

import { api } from '@/api/client'
import type { ScenarioSummary } from '@/api/types'
import { useGameStore } from '@/stores/game'
import { useMatchStore } from '@/stores/match'

const props = defineProps<{ game: string }>()

const games = useGameStore()
const match = useMatchStore()
const router = useRouter()

const decks = ref<Awaited<ReturnType<typeof api.decks>>>([])
const bots = ref<Awaited<ReturnType<typeof api.botProfiles>>>([])
const scenarios = ref<ScenarioSummary[]>([])
const seats = ref<(string | null)[]>([null, null])
const opponent = ref<string | null>(null)
const scenario = ref<string | null>(null)
const seed = ref<number | null>(null)
const starting = ref(false)
const problem = ref<string | null>(null)

const playable = computed(() => bots.value.filter((bot) => bot.implemented))

/**
 * A game with scenarios is played against one, not against another seat.
 *
 * The shape is read from the data rather than from a flag: a game that declares an
 * adversary has scenarios to fight, and one that does not has an opponent's chair.
 */
const cooperative = computed(() => scenarios.value.length > 0)
const chosenScenario = computed(() => scenarios.value.find((s) => s.id === scenario.value) ?? null)

/** The seats a table may have: the game's range, narrowed by the scenario's own. */
const seatRange = computed(() => {
  const players = games.compiled?.players
  const min = Math.max(players?.min ?? 1, chosenScenario.value?.players.min ?? 1)
  const max = Math.min(players?.max ?? 2, chosenScenario.value?.players.max ?? 99)
  return { min, max: Math.max(min, max) }
})

function resize(count: number): void {
  const next: (string | null)[] = []
  for (let seat = 0; seat < count; seat++) {
    next.push(seats.value[seat] ?? decks.value[seat % Math.max(1, decks.value.length)]?.headVersionId ?? null)
  }
  seats.value = next
}

onMounted(async () => {
  ;[decks.value, bots.value, scenarios.value] = await Promise.all([
    api.decks(props.game),
    api.botProfiles(props.game),
    api.scenarios(props.game),
  ])

  scenario.value = scenarios.value[0]?.id ?? null

  // Two different decks and a bot in the other seat, so the common case — deal me a game —
  // is one click. A cooperative table starts at the smallest it can be, which is the one a
  // designer reaches for when checking a change.
  resize(cooperative.value ? seatRange.value.min : 2)
  opponent.value = cooperative.value ? null : (playable.value[0]?.id ?? null)
})

async function start(): Promise<void> {
  const versionId = games.current?.version?.id
  if (versionId === undefined) {
    problem.value = 'This game needs a published version before a match can start.'
    return
  }

  if (seats.value.some((deck) => deck === null)) {
    problem.value = 'Every seat needs a deck.'
    return
  }

  if (cooperative.value && scenario.value === null) {
    problem.value = 'A cooperative match needs a scenario to play against.'
    return
  }

  starting.value = true
  try {
    const envelope = await api.createMatch({
      gameVersionId: versionId,
      mode: opponent.value === null ? 'hotseat' : 'solo',
      seed: seed.value ?? undefined,
      scenarioId: scenario.value ?? undefined,
      seats: seats.value.map((deckVersionId, seat) => ({
        seat,
        deckVersionId: deckVersionId ?? undefined,
        // Seat 1 only: hotseat is both seats human, solo is a bot in the other one. A
        // cooperative table has no opposing seat — the adversary is a script, not an agent.
        botProfileId: seat === 1 && opponent.value !== null ? opponent.value : undefined,
      })),
    })
    match.absorb(envelope)
    await router.push(`/m/${envelope.match.id}`)
  } catch (error) {
    problem.value = error instanceof Error ? error.message : String(error)
  } finally {
    starting.value = false
  }
}
</script>

<template>
  <div class="setup">
    <h1>New playtest</h1>
    <p class="muted">
      A match is pinned to one game version, one deck per seat{{ cooperative ? ', one scenario' : '' }} and a
      seed. Supply the seed and the same match deals the same cards — which is how a bug report becomes a
      regression test.
    </p>

    <label v-if="cooperative" class="field">
      <span class="label">Scenario</span>
      <select v-model="scenario" data-scenario>
        <option v-for="entry in scenarios" :key="entry.id" :value="entry.id">
          {{ entry.name }}{{ entry.difficulty ? ` · ${entry.difficulty}` : '' }}
        </option>
      </select>
      <span v-if="chosenScenario" class="hint mono">
        vs {{ chosenScenario.adversary }} · {{ chosenScenario.encounterSets.join(', ') }}
      </span>
    </label>

    <label v-if="cooperative && seatRange.max > seatRange.min" class="field">
      <span class="label">Watchers <span class="muted">at the table</span></span>
      <select
        :value="seats.length"
        data-players
        @change="resize(Number(($event.target as HTMLSelectElement).value))"
      >
        <option v-for="n in seatRange.max - seatRange.min + 1" :key="n" :value="seatRange.min + n - 1">
          {{ seatRange.min + n - 1 }}
        </option>
      </select>
      <!-- Player count is a difficulty dial in this format, not a convenience: a villain's
           health and a scheme's threshold are printed per player. -->
      <span class="hint mono">per-player attributes scale with this</span>
    </label>

    <label v-for="(_, seat) in seats" :key="seat" class="field">
      <span class="label">Seat {{ seat }} <span class="muted">deck</span></span>
      <select v-model="seats[seat]" :data-seat="seat">
        <option :value="null">—</option>
        <option v-for="deck in decks" :key="deck.id" :value="deck.headVersionId">
          {{ deck.name }} · {{ deck.cardCount }} cards{{ deck.valid === false ? ' · illegal' : '' }}
        </option>
      </select>
    </label>

    <label v-if="!cooperative" class="field">
      <span class="label">Opponent</span>
      <select v-model="opponent" data-opponent>
        <option :value="null">Hotseat — play both seats</option>
        <option v-for="bot in playable" :key="bot.id" :value="bot.id">{{ bot.name }} bot</option>
      </select>
      <!-- A bot whose strategy has no implementation yet is named rather than hidden, so an
           authored tuning does not look like it was lost. -->
      <span v-if="bots.length > playable.length" class="hint mono">
        {{ bots.length - playable.length }} authored profile(s) waiting on their strategy
      </span>
    </label>

    <label class="field">
      <span class="label">Seed <span class="muted">optional</span></span>
      <input v-model.number="seed" type="number" placeholder="leave blank to generate one" />
    </label>

    <button class="primary" :disabled="starting" @click="start">
      {{ starting ? 'Dealing…' : 'Start match' }}
    </button>

    <p v-if="problem" class="problem">{{ problem }}</p>
  </div>
</template>

<style scoped>
.setup {
  padding: var(--gap-8) 24px;
  max-width: 520px;
}

h1 {
  font-size: 17px;
  margin: 0 0 var(--gap-4);
}

.field {
  display: block;
  margin: var(--gap-8) 0;
}

.field input,
.field select {
  display: block;
  width: 100%;
  height: var(--control);
  margin-top: var(--gap-3);
  background: var(--surface-0);
  border: 1px solid var(--border-default);
  border-radius: var(--radius-control);
  padding: 0 var(--gap-4);
}

.primary {
  height: var(--control-lg);
  padding: 0 var(--gap-8);
  background: var(--accent);
  color: var(--accent-contrast);
  border: 0;
  border-radius: var(--radius-control);
  font-weight: 600;
  cursor: pointer;
}

.problem {
  color: var(--warn-text);
  font-size: 11.5px;
}

.hint {
  display: block;
  margin-top: var(--gap-3);
  font-size: 9px;
  color: var(--text-4);
}
</style>
