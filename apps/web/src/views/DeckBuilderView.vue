<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import { api } from '@/api/client'
import type { DeckDocument, DeckLegality } from '@/api/types'

type DeckEntry = DeckDocument['cards'][number]

const props = defineProps<{ game: string }>()

const decks = ref<Awaited<ReturnType<typeof api.decks>>>([])
const selected = ref<{ id: string; name: string; document: Record<string, unknown>; legality: DeckLegality } | null>(null)

onMounted(async () => {
  decks.value = await api.decks(props.game)
  const first = decks.value[0]
  if (first !== undefined) await open(first.id)
})

async function open(id: string): Promise<void> {
  selected.value = await api.deck(id)
}

// `DeckEntry` comes from the deck schema itself, so a change to what a deck entry is
// reaches this list as a type error rather than as a wrong render.
const entries = computed<DeckEntry[]>(() => {
  const cards = selected.value?.document.cards
  return Array.isArray(cards) ? (cards as DeckEntry[]) : []
})

function tenths(value: number): string {
  return (value / 10).toFixed(1)
}
</script>

<template>
  <div class="builder">
    <aside class="list">
      <h2 class="label">Decks</h2>
      <button
        v-for="deck in decks"
        :key="deck.id"
        class="deck"
        :class="{ on: selected?.id === deck.id }"
        @click="open(deck.id)"
      >
        <span>{{ deck.name }}</span>
        <span class="mono muted">{{ deck.cardCount }}</span>
        <!-- Legality carries a glyph as well as a colour. -->
        <span class="mono" :class="deck.valid ? 'ok' : 'bad'">{{ deck.valid ? '✓' : '✗' }}</span>
      </button>
    </aside>

    <section v-if="selected" class="detail">
      <h1>{{ selected.name }}</h1>
      <ul class="cards">
        <li v-for="entry in entries" :key="entry.code">
          <span class="mono count">{{ entry.count }}×</span>
          <span class="mono">{{ entry.code }}</span>
        </li>
      </ul>
    </section>

    <aside v-if="selected" class="analysis">
      <h2 class="label">Legality</h2>
      <div v-if="selected.legality.valid" class="panel ok">
        <p>✓ Legal for this game version.</p>
      </div>
      <div v-else class="panel error">
        <!-- The game's own sentence, because a designer wrote it for their players and it
             reads better than anything generated. -->
        <p v-for="violation in selected.legality.violations" :key="violation.constraint">
          ✗ {{ violation.message }}
          <span v-if="violation.cards?.length" class="mono muted">{{ violation.cards.join(', ') }}</span>
        </p>
      </div>

      <h2 class="label section">Shape</h2>
      <dl class="stats">
        <dt>Cards</dt><dd class="mono">{{ selected.legality.stats.total }}</dd>
        <dt>Average cost</dt><dd class="mono">{{ tenths(selected.legality.stats.averageCostTenths) }}</dd>
      </dl>

      <h3 class="label">Curve</h3>
      <div class="curve">
        <div v-for="(n, cost) in selected.legality.stats.curve" :key="cost" class="col">
          <span class="fill" :style="{ height: `${n * 10}px` }" />
          <span class="mono tick">{{ cost }}</span>
        </div>
      </div>

      <h3 class="label">By type</h3>
      <dl class="stats">
        <template v-for="(n, type) in selected.legality.stats.byType" :key="type">
          <dt>{{ type }}</dt><dd class="mono">{{ n }}</dd>
        </template>
      </dl>
    </aside>
  </div>
</template>

<style scoped>
.builder {
  display: flex;
  height: 100%;
  min-height: 0;
}

.list {
  width: 220px;
  flex: 0 0 220px;
  background: var(--surface-3);
  border-right: 1px solid var(--border-subtle);
  padding: var(--gap-6);
}

.deck {
  display: flex;
  align-items: center;
  gap: var(--gap-4);
  width: 100%;
  height: var(--row);
  padding: 0 var(--gap-4);
  background: none;
  border: 0;
  border-radius: var(--radius-control);
  color: var(--text-2);
  cursor: pointer;
  text-align: left;
}

.deck:hover {
  background: var(--surface-4);
}

.deck.on {
  background: var(--surface-6);
  color: var(--text-1);
}

.deck span:first-child {
  flex: 1;
}

.ok {
  color: var(--ok);
}

.bad {
  color: var(--error);
}

.detail {
  flex: 1;
  min-width: 0;
  padding: var(--gap-6) var(--gap-8);
  overflow: auto;
}

h1 {
  font-size: 17px;
  margin: 0 0 var(--gap-6);
}

.cards {
  list-style: none;
  padding: 0;
  margin: 0;
  columns: 2;
}

.cards li {
  display: flex;
  gap: var(--gap-4);
  height: 26px;
  align-items: center;
  border-bottom: 1px solid var(--border-faint);
  font-size: 11.5px;
}

.count {
  width: 24px;
  color: var(--text-3);
}

.analysis {
  width: 306px;
  flex: 0 0 306px;
  background: var(--surface-3);
  border-left: 1px solid var(--border-subtle);
  padding: var(--gap-6);
  overflow: auto;
}

.panel {
  border-radius: var(--radius-control);
  padding: var(--gap-5);
  margin-top: var(--gap-4);
  font-size: 11.5px;
}

.panel.ok {
  background: var(--ok-surface);
  border: 1px solid var(--ok-border);
  color: var(--ok-text);
}

.panel.error {
  background: var(--error-surface);
  border: 1px solid var(--error-border);
  color: var(--error-text);
}

.panel p {
  margin: 0 0 var(--gap-3);
}

.section {
  margin-top: var(--gap-8);
}

.stats {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: var(--gap-2) var(--gap-5);
  margin: var(--gap-4) 0;
  font-size: 11.5px;
}

.stats dt {
  color: var(--text-3);
}

.stats dd {
  margin: 0;
  text-align: right;
}

.curve {
  display: flex;
  align-items: flex-end;
  gap: var(--gap-3);
  height: 70px;
  margin: var(--gap-4) 0;
}

.col {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-end;
  gap: 2px;
}

.fill {
  width: 100%;
  background: var(--info);
  border-radius: 2px 2px 0 0;
}

.tick {
  font-size: 9px;
  color: var(--text-4);
}
</style>
