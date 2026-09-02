<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'

import { useGameStore } from '@/stores/game'

const games = useGameStore()
const route = useRoute()

/**
 * Two-letter monograms stand in for icons, exactly as the design does — the handoff calls
 * them placeholders, and inventing icons would be inventing design rather than building it.
 */
const items = computed(() => {
  const slug = games.current?.slug
  return [
    { key: 'SY', label: 'System', to: slug ? `/g/${slug}/system` : '/', name: 'system' },
    { key: 'CA', label: 'Cards', to: slug ? `/g/${slug}/cards` : '/', name: 'cards' },
    { key: 'DE', label: 'Decks', to: slug ? `/g/${slug}/decks` : '/', name: 'decks' },
    { key: 'PL', label: 'Playtest', to: slug ? `/g/${slug}/play` : '/', name: 'play' },
  ]
})
</script>

<template>
  <nav class="rail" aria-label="Sections">
    <RouterLink
      v-for="item in items"
      :key="item.key"
      :to="item.to"
      class="item mono"
      :class="{ active: route.name === item.name }"
      :title="item.label"
    >
      {{ item.key }}
      <!-- Active is a fill *and* an inset bar, so it does not depend on colour alone. -->
      <span v-if="route.name === item.name" class="marker" />
    </RouterLink>
  </nav>
</template>

<style scoped>
.rail {
  width: var(--rail);
  flex: 0 0 var(--rail);
  background: var(--surface-3);
  border-right: 1px solid var(--border-subtle);
  padding: var(--gap-4) var(--gap-3);
  display: flex;
  flex-direction: column;
  gap: var(--gap-2);
}

.item {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 34px;
  border-radius: var(--radius-control);
  font-size: 10px;
  letter-spacing: 0.08em;
  color: var(--text-3);
}

.item:hover {
  background: var(--surface-4);
  color: var(--text-2);
}

.item.active {
  background: var(--surface-6);
  color: var(--text-1);
}

.marker {
  position: absolute;
  left: 0;
  top: 6px;
  bottom: 6px;
  width: 2px;
  background: var(--accent);
  border-radius: 1px;
}
</style>
