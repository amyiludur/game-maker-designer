<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

import { api } from '@/api/client'
import { useGameStore } from '@/stores/game'
import { useMatchStore } from '@/stores/match'

const props = defineProps<{ game: string }>()

const games = useGameStore()
const match = useMatchStore()
const router = useRouter()

const decks = ref<Awaited<ReturnType<typeof api.decks>>>([])
const seed = ref<number | null>(null)
const starting = ref(false)
const problem = ref<string | null>(null)

onMounted(async () => {
  decks.value = await api.decks(props.game)
})

async function start(): Promise<void> {
  const versionId = games.current?.version?.id
  if (versionId === undefined || decks.value.length < 2) {
    problem.value = 'This game needs a version and two decks before a match can start.'
    return
  }

  starting.value = true
  try {
    const envelope = await api.createMatch({
      gameVersionId: versionId,
      mode: 'solo',
      seed: seed.value ?? undefined,
      seats: [
        { seat: 0, deckVersionId: undefined },
        { seat: 1, deckVersionId: undefined },
      ],
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
      A match is pinned to one game version, one deck per seat and a seed. Supply the seed and
      the same match deals the same cards — which is how a bug report becomes a regression test.
    </p>

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

.field input {
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
</style>
