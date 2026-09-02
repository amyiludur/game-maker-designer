<script setup lang="ts">
import { computed } from 'vue'

import { useGameStore } from '@/stores/game'

const props = defineProps<{
  code: string
  name: string | null
  type: string | null
  faction: string | null
  cost: number | null
  traits?: string[]
  selected?: boolean
}>()

const games = useGameStore()

// The faction colour comes from the game document, never from a chrome token, and is only
// ever used as a fill behind a shape — never as text on a dark surface.
const stripe = computed(() => games.factionColor(props.faction) ?? 'var(--border-default)')
</script>

<template>
  <article class="thumb" :class="{ selected }">
    <header>
      <span v-if="cost !== null" class="cost" :style="{ background: stripe }">{{ cost }}</span>
      <span class="name">{{ name ?? code }}</span>
    </header>
    <p class="meta mono">{{ code }} · {{ type }}</p>
    <p v-if="traits?.length" class="traits mono">{{ traits.join(' · ') }}</p>
    <span class="stripe" :style="{ background: stripe }" />
  </article>
</template>

<style scoped>
.thumb {
  position: relative;
  background: var(--surface-3);
  border: 1px solid var(--border-default);
  border-radius: var(--radius-card);
  padding: var(--gap-4) var(--gap-5) var(--gap-5);
  overflow: hidden;
  cursor: pointer;
}

.thumb:hover {
  border-color: var(--border-hover);
}

.thumb.selected {
  border-color: var(--accent);
  background: var(--surface-4);
}

header {
  display: flex;
  align-items: center;
  gap: var(--gap-4);
}

.cost {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  color: #fff;
  font-size: 11px;
  font-weight: 600;
  flex: 0 0 auto;
}

.name {
  font-size: 12.5px;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.meta,
.traits {
  margin: var(--gap-3) 0 0;
  font-size: 9.5px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--text-3);
}

.traits {
  color: var(--text-4);
}

.stripe {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 2px;
}
</style>
