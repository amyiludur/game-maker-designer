<script setup lang="ts">
import { useGameStore } from '@/stores/game'

const games = useGameStore()
</script>

<template>
  <div class="dashboard">
    <h1>Games</h1>
    <p class="muted intro">
      A game is data. Its zones, phases, resources, card types and win conditions live in one JSON document,
      and one deterministic kernel plays whatever that document says.
    </p>

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
      Nothing imported yet. Run <code class="mono">php artisan games:import emberfall</code>.
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
</style>
