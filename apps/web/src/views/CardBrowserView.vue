<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { api } from '@/api/client'
import CardThumb from '@/components/card/CardThumb.vue'
import { useCardsStore } from '@/stores/cards'
import { useGameStore } from '@/stores/game'

const props = defineProps<{ game: string }>()

const cards = useCardsStore()
const games = useGameStore()
const router = useRouter()
const route = useRoute()

const view = ref<'grid' | 'table'>('table')
const completeness = ref<Awaited<ReturnType<typeof api.completeness>> | null>(null)

async function refresh(): Promise<void> {
  await cards.load(props.game)
}

onMounted(async () => {
  // The URL is read first, so a pasted link opens the view it was copied from.
  cards.fromQuery(route.query)
  await refresh()

  const set = (await api.game(props.game)).sets[0]
  if (set !== undefined) completeness.value = await api.completeness(props.game, set.code)
})

watch(
  () => cards.filters,
  async () => {
    // `replace`, not `push`: ticking six facets should not mean six presses of Back.
    await router.replace({ query: cards.toQuery() })
    await refresh()
  },
  { deep: true },
)

const typeCounts = computed(() => cards.counts('type'))
const factionCounts = computed(() => cards.counts('faction'))

function open(code: string): void {
  void router.push(`/g/${props.game}/cards/${code}`)
}

const adding = ref(false)

/**
 * A new card, straight into the editor.
 *
 * The type is asked for and nothing else: the server drafts a document that satisfies that
 * card type's compiled schema — required attributes at their minimums, a free code — because
 * a new card that fails validation the moment it exists is not a starting point.
 */
async function create(type: string): Promise<void> {
  adding.value = true
  try {
    const card = await api.createCard(props.game, { type })
    await router.push(`/g/${props.game}/cards/${card.code}`)
  } finally {
    adding.value = false
  }
}
</script>

<template>
  <div class="browser">
    <aside class="facets">
      <section>
        <h2 class="label">Type</h2>
        <label v-for="type in games.cardTypes" :key="type.id" class="facet">
          <input
            type="checkbox"
            :checked="cards.filters.type.includes(type.id)"
            @change="cards.toggle('type', type.id)"
          />
          <span>{{ type.name }}</span>
          <span class="count mono">{{ typeCounts[type.id] ?? 0 }}</span>
        </label>
      </section>

      <section v-if="games.factions.length">
        <h2 class="label">Faction</h2>
        <label v-for="faction in games.factions" :key="faction.id" class="facet">
          <input
            type="checkbox"
            :checked="cards.filters.faction.includes(faction.id)"
            @change="cards.toggle('faction', faction.id)"
          />
          <span class="swatch" :style="{ background: faction.color }" />
          <span>{{ faction.name }}</span>
          <span class="count mono">{{ factionCounts[faction.id] ?? 0 }}</span>
        </label>
      </section>

      <section v-if="games.traits.length">
        <h2 class="label">Traits</h2>
        <label v-for="trait in games.traits" :key="trait" class="facet">
          <input
            type="checkbox"
            :checked="cards.filters.traits.includes(trait)"
            @change="cards.toggle('traits', trait)"
          />
          <span>{{ trait }}</span>
        </label>
      </section>

      <section>
        <h2 class="label">Cost</h2>
        <div class="range">
          <input v-model.number="cards.filters.costMin" type="number" min="0" placeholder="min" />
          <span class="muted">–</span>
          <input v-model.number="cards.filters.costMax" type="number" min="0" placeholder="max" />
        </div>
      </section>
    </aside>

    <section class="main">
      <header class="toolbar">
        <input v-model="cards.filters.q" class="search" type="search" placeholder="Search cards…" />
        <div class="chips">
          <!-- The filter is the URL: a filtered view is something designers send each other. -->
          <span v-for="chip in cards.chips" :key="chip.label" class="chip mono">{{ chip.label }}</span>
          <button v-if="cards.chips.length" class="ghost mono" @click="cards.clear()">clear</button>
        </div>
        <span class="spacer" />
        <span class="count mono">{{ cards.total }} cards</span>

        <select
          class="new mono"
          :disabled="adding || games.cardTypes.length === 0"
          @change="create(($event.target as HTMLSelectElement).value)"
        >
          <option value="">+ new card</option>
          <option v-for="type in games.cardTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
        </select>
        <div class="toggle">
          <button :class="{ on: view === 'table' }" @click="view = 'table'">Table</button>
          <button :class="{ on: view === 'grid' }" @click="view = 'grid'">Grid</button>
        </div>
      </header>

      <div v-if="view === 'grid'" class="grid">
        <CardThumb v-for="card in cards.cards" :key="card.id" v-bind="card" @click="open(card.code)" />
      </div>

      <table v-else class="table">
        <thead>
          <tr>
            <th class="mono">Code</th>
            <th>Name</th>
            <th>Type</th>
            <th>Faction</th>
            <th class="num">Cost</th>
            <th>Traits</th>
            <th>Keywords</th>
            <th class="num">Ab</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="card in cards.cards" :key="card.id" :data-card="card.code" @click="open(card.code)">
            <td class="mono muted">{{ card.code }}</td>
            <td class="name">{{ card.name }}</td>
            <td>{{ card.type }}</td>
            <td>
              <span
                v-if="card.faction"
                class="swatch"
                :style="{ background: games.factionColor(card.faction) }"
              />
              {{ card.faction }}
            </td>
            <td class="num">{{ card.cost }}</td>
            <td class="muted">{{ card.traits.join(', ') }}</td>
            <td class="muted">{{ card.keywords.join(', ') }}</td>
            <td class="num muted">{{ card.abilityCount || '' }}</td>
            <td class="mono muted">{{ card.status }}</td>
          </tr>
        </tbody>
      </table>

      <p v-if="!cards.loading && cards.cards.length === 0" class="empty">
        Nothing matches these filters. <button class="ghost" @click="cards.clear()">Clear them</button>
      </p>
    </section>

    <aside v-if="completeness" class="completeness">
      <h2 class="label">{{ completeness.set.name }}</h2>
      <div v-for="row in completeness.byType" :key="row.type" class="bar-row">
        <span class="bar-label">{{ row.type }}</span>
        <div class="bar">
          <span
            class="fill"
            :style="{
              width: row.planned ? `${Math.min(100, (row.authored / row.planned) * 100)}%` : '100%',
              background: row.planned && row.authored < row.planned ? 'var(--warn)' : 'var(--ok)',
            }"
          />
        </div>
        <span class="mono count"
          >{{ row.authored }}<span v-if="row.planned">/{{ row.planned }}</span></span
        >
      </div>

      <h2 class="label" style="margin-top: 16px">By cost</h2>
      <div class="curve">
        <div v-for="(n, cost) in completeness.byCost" :key="cost" class="col">
          <span class="col-fill" :style="{ height: `${n * 12}px` }" />
          <span class="mono col-label">{{ cost }}</span>
        </div>
      </div>

      <template v-if="completeness.goals.length">
        <h2 class="label" style="margin-top: 16px">Goals</h2>
        <ul class="goals">
          <li v-for="goal in completeness.goals" :key="goal">{{ goal }}</li>
        </ul>
      </template>
    </aside>
  </div>
</template>

<style scoped>
.browser {
  display: flex;
  height: 100%;
  min-height: 0;
}

.facets {
  width: 184px;
  flex: 0 0 184px;
  background: var(--surface-3);
  border-right: 1px solid var(--border-subtle);
  padding: var(--gap-6);
  overflow: auto;
}

.facets section + section {
  margin-top: var(--gap-7);
}

.facet {
  display: flex;
  align-items: center;
  gap: var(--gap-3);
  padding: 3px 0;
  font-size: 11.5px;
  color: var(--text-2);
  cursor: pointer;
}

.facet .count {
  margin-left: auto;
  color: var(--text-4);
  font-size: 10px;
}

.swatch {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 2px;
  flex: 0 0 auto;
}

.range {
  display: flex;
  align-items: center;
  gap: var(--gap-3);
}

.range input {
  width: 100%;
  height: var(--control-sm);
  background: var(--surface-0);
  border: 1px solid var(--border-default);
  border-radius: var(--radius-control);
  padding: 0 var(--gap-3);
  font-size: 11px;
}

.main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.toolbar {
  display: flex;
  align-items: center;
  gap: var(--gap-4);
  padding: var(--gap-5) var(--gap-6);
  border-bottom: 1px solid var(--border-subtle);
  background: var(--surface-1);
}

.search {
  width: 220px;
  height: var(--control);
  background: var(--surface-0);
  border: 1px solid var(--border-default);
  border-radius: var(--radius-control);
  padding: 0 var(--gap-4);
}

.chips {
  display: flex;
  gap: var(--gap-3);
  flex-wrap: wrap;
}

.chip {
  font-size: 10px;
  padding: 2px var(--gap-3);
  background: var(--surface-6);
  border-radius: var(--radius-chip);
  color: var(--text-2);
}

.spacer {
  flex: 1;
}

.toggle {
  display: flex;
  border: 1px solid var(--border-default);
  border-radius: var(--radius-control);
  overflow: hidden;
}

.toggle button {
  height: var(--control);
  padding: 0 var(--gap-5);
  background: var(--surface-3);
  border: 0;
  color: var(--text-3);
  cursor: pointer;
  font-size: 11px;
}

.toggle button.on {
  background: var(--surface-6);
  color: var(--text-1);
}

.ghost {
  background: none;
  border: 0;
  color: var(--text-3);
  cursor: pointer;
  font-size: 10px;
  text-decoration: underline;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: var(--gap-5);
  padding: var(--gap-6);
  overflow: auto;
}

.table {
  width: 100%;
  border-collapse: collapse;
  font-size: 11.5px;
}

.table th {
  position: sticky;
  top: 0;
  height: 26px;
  padding: 0 var(--gap-5);
  text-align: left;
  background: var(--surface-4);
  border-bottom: 1px solid var(--border-default);
  font-size: 9.5px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--text-3);
  font-weight: 500;
}

.table td {
  height: var(--row);
  padding: 0 var(--gap-5);
  border-bottom: 1px solid var(--border-faint);
  color: var(--text-2);
}

.table tbody tr {
  cursor: pointer;
}

.table tbody tr:hover td {
  background: var(--surface-3);
}

.table .name {
  color: var(--text-1);
  font-weight: 500;
}

.num {
  text-align: right;
}

.empty {
  padding: var(--gap-8);
  color: var(--text-3);
}

.completeness {
  width: 250px;
  flex: 0 0 250px;
  background: var(--surface-3);
  border-left: 1px solid var(--border-subtle);
  padding: var(--gap-6);
  overflow: auto;
}

.bar-row {
  display: flex;
  align-items: center;
  gap: var(--gap-4);
  margin-top: var(--gap-4);
  font-size: 11px;
}

.bar-label {
  width: 68px;
  color: var(--text-2);
}

.bar {
  flex: 1;
  height: 6px;
  background: var(--surface-0);
  border-radius: 3px;
  overflow: hidden;
}

.fill {
  display: block;
  height: 100%;
}

.curve {
  display: flex;
  align-items: flex-end;
  gap: var(--gap-3);
  height: 70px;
  margin-top: var(--gap-4);
}

.col {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-end;
  gap: var(--gap-2);
}

.col-fill {
  width: 100%;
  background: var(--info);
  border-radius: 2px 2px 0 0;
}

.col-label {
  font-size: 9px;
  color: var(--text-4);
}

.goals {
  margin: var(--gap-4) 0 0;
  padding-left: 16px;
  font-size: 11px;
  color: var(--text-3);
}

.new {
  height: 24px;
  background: var(--surface-0);
  border: 1px solid var(--border-default);
  border-radius: var(--radius-control);
  color: var(--text-2);
  padding: 0 var(--gap-3);
  font-size: 11px;
  cursor: pointer;
}

.new:disabled {
  opacity: 0.4;
  cursor: default;
}
</style>
