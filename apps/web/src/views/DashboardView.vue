<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

import { ApiError_, api } from '@/api/client'
import type { GameTemplate } from '@/api/types'
import { useGameStore } from '@/stores/game'

const games = useGameStore()
const router = useRouter()

const templates = ref<GameTemplate[]>([])
const creating = ref(false)
const open = ref(false)
const name = ref('')
const template = ref<string | null>(null)
const problem = ref<string | null>(null)

onMounted(async () => {
  templates.value = await api.gameTemplates()
  template.value = templates.value[0]?.id ?? null
})

/**
 * A new game starts from a template, not from nothing.
 *
 * An empty system document does not compile — no zones, no phases, no win condition — so
 * "create" would otherwise hand a designer something already broken. The templates are real
 * game documents that validate against the schemas like any other.
 */
async function create(): Promise<void> {
  if (name.value.trim() === '') return

  creating.value = true
  problem.value = null
  try {
    const game = await api.createGame({
      name: name.value.trim(),
      template: template.value ?? undefined,
    })

    await games.loadGames()
    await router.push(`/g/${game.slug}/system`)
  } catch (error) {
    problem.value = error instanceof ApiError_ ? error.message : String(error)
  } finally {
    creating.value = false
  }
}
</script>

<template>
  <div class="dashboard">
    <h1>Games</h1>
    <p class="muted intro">
      A game is data. Its zones, phases, resources, card types and win conditions live in one JSON document,
      and one deterministic kernel plays whatever that document says.
    </p>

    <div class="new">
      <button v-if="!open" class="primary" @click="open = true">New game</button>

      <form v-else class="form" @submit.prevent="create">
        <input v-model="name" placeholder="Name your game" autofocus />
        <select v-model="template">
          <option v-for="entry in templates" :key="entry.id" :value="entry.id">
            {{ entry.name }} — {{ entry.cardTypes }} card types, {{ entry.phases }} phases
          </option>
        </select>
        <button class="primary" type="submit" :disabled="creating || name.trim() === ''">
          {{ creating ? 'Creating…' : 'Create' }}
        </button>
        <button class="ghost" type="button" @click="open = false">Cancel</button>
      </form>

      <p v-if="problem" class="problem">{{ problem }}</p>
    </div>

    <div class="cards">
      <RouterLink v-for="game in games.games" :key="game.id" class="card" :to="`/g/${game.slug}/cards`">
        <h2>{{ game.name }}</h2>
        <p class="summary muted">{{ game.summary }}</p>
        <footer>
          <span class="mono">{{ game.cardCount }} cards</span>
          <span v-if="game.version" class="mono">v{{ game.version.semver }}</span>
          <span v-if="game.version?.lintErrors" class="mono lint">◆ {{ game.version.lintErrors }}</span>
        </footer>
      </RouterLink>
    </div>

    <p v-if="games.games.length === 0" class="muted">
      No games yet. Start one above, or import a worked example with
      <code class="mono">php artisan games:import ../../examples/emberfall</code>.
    </p>
  </div>
</template>

<style scoped>
.dashboard {
  padding: var(--gap-8) 24px;
  max-width: 900px;
}

h1 {
  font-size: 23px;
  margin: 0 0 var(--gap-4);
}

.intro {
  margin: 0 0 24px;
  max-width: 60ch;
}

.cards {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: var(--gap-6);
}

.card {
  display: block;
  background: var(--surface-3);
  border: 1px solid var(--border-default);
  border-radius: var(--radius-card);
  padding: var(--gap-6);
}

.card:hover {
  border-color: var(--border-hover);
}

.card h2 {
  margin: 0 0 var(--gap-3);
  font-size: 15px;
  color: var(--text-1);
}

.summary {
  margin: 0 0 var(--gap-6);
  font-size: 11.5px;
  min-height: 3em;
}

.card footer {
  display: flex;
  gap: var(--gap-5);
  font-size: 10px;
  color: var(--text-4);
}

.lint {
  color: var(--error);
}

.new {
  margin: var(--gap-7) 0;
}

.form {
  display: flex;
  align-items: center;
  gap: var(--gap-4);
  flex-wrap: wrap;
}

.form input,
.form select {
  height: var(--control);
  background: var(--surface-0);
  border: 1px solid var(--border-default);
  border-radius: var(--radius-control);
  color: var(--text-1);
  padding: 0 var(--gap-4);
}

.form input {
  min-width: 220px;
}

.primary {
  height: var(--control);
  padding: 0 var(--gap-6);
  background: var(--accent);
  color: var(--accent-contrast);
  border: 0;
  border-radius: var(--radius-control);
  cursor: pointer;
  font-weight: 600;
}

.primary:disabled {
  opacity: 0.5;
  cursor: default;
}

.ghost {
  height: var(--control);
  padding: 0 var(--gap-5);
  background: none;
  border: 1px solid var(--border-default);
  border-radius: var(--radius-control);
  color: var(--text-2);
  cursor: pointer;
}

.problem {
  margin-top: var(--gap-4);
  color: var(--warn-text);
  font-size: 11.5px;
}
</style>
