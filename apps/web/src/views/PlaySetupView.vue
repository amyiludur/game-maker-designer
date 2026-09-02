<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

import { api } from '@/api/client'
import { useGameStore } from '@/stores/game'
import { useMatchStore } from '@/stores/match'

const props = defineProps<{ game: string }>()

const games = useGameStore()
const match = useMatchStore()
const router = useRouter()

const decks = ref<Awaited<ReturnType<typeof api.decks>>>([])
const bots = ref<Awaited<ReturnType<typeof api.botProfiles>>>([])
const seats = ref<(string | null)[]>([null, null])
const opponent = ref<string | null>(null)
const seed = ref<number | null>(null)
const starting = ref(false)
const problem = ref<string | null>(null)

const playable = computed(() => bots.value.filter((bot) => bot.implemented))

onMounted(async () => {
  ;[decks.value, bots.value] = await Promise.all([api.decks(props.game), api.botProfiles(props.game)])

  // Two different decks and a bot in the other seat, so the common case — deal me a game —
  // is one click.
  seats.value = [decks.value[0]?.headVersionId ?? null, decks.value[1]?.headVersionId ?? null]
  opponent.value = playable.value[0]?.id ?? null
})

async function start(): Promise<void> {
  const versionId = games.current?.version?.id
  if (versionId === undefined) {
    problem.value = 'This game needs a published version before a match can start.'
    return
  }

  if (seats.value.some((deck) => deck === null)) {
    problem.value = 'Both seats need a deck.'
    return
  }

  starting.value = true
  try {
    const envelope = await api.createMatch({
      gameVersionId: versionId,
      mode: opponent.value === null ? 'hotseat' : 'solo',
      seed: seed.value ?? undefined,
      seats: seats.value.map((deckVersionId, seat) => ({
        seat,
        deckVersionId: deckVersionId ?? undefined,
        // Seat 1 only: hotseat is both seats human, solo is a bot in the other one.
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
      A match is pinned to one game version, one deck per seat and a seed. Supply the seed and the same match
      deals the same cards — which is how a bug report becomes a regression test.
    </p>

    <label v-for="(_, seat) in seats" :key="seat" class="field">
      <span class="label">Seat {{ seat }} <span class="muted">deck</span></span>
      <select v-model="seats[seat]" :data-seat="seat">
        <option :value="null">—</option>
        <option v-for="deck in decks" :key="deck.id" :value="deck.headVersionId">
          {{ deck.name }} · {{ deck.cardCount }} cards{{ deck.valid === false ? ' · illegal' : '' }}
        </option>
      </select>
    </label>

    <label class="field">
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
