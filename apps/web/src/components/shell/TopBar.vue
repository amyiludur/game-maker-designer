<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'

import { useGameStore } from '@/stores/game'

const games = useGameStore()
const route = useRoute()

const version = computed(() => games.current?.version ?? null)

const breadcrumb = computed(() => {
  const parts: string[] = []
  if (games.current !== null) parts.push(games.current.slug)
  if (typeof route.name === 'string' && route.name !== 'dashboard') parts.push(route.name)
  if (typeof route.params.card === 'string') parts.push(route.params.card)
  return parts.join(' / ')
})
</script>

<template>
  <header class="topbar">
    <RouterLink to="/" class="mark" :style="{ background: 'var(--accent)' }" aria-label="Dashboard" />
    <span class="name">{{ games.current?.name ?? 'Game Maker Designer' }}</span>

    <span v-if="version" class="version mono">
      <!-- A draft carries a dot as well as a word: state is never colour alone. -->
      <span class="dot" :class="version.status" />
      v{{ version.semver }} <span class="muted">{{ version.status }}</span>
    </span>

    <span class="divider" />
    <span class="crumb mono">{{ breadcrumb }}</span>

    <span class="spacer" />

    <!-- Lint badges carry a glyph as well as a colour, for the same reason. -->
    <RouterLink
      v-if="games.lintErrors.length > 0"
      class="badge error"
      :to="`/g/${games.current?.slug}/cards`"
      :title="`${games.lintErrors.length} lint error(s)`"
    >◆ {{ games.lintErrors.length }}</RouterLink>
    <span v-if="games.lintWarnings.length > 0" class="badge warn">▲ {{ games.lintWarnings.length }}</span>
  </header>
</template>

<style scoped>
.topbar {
  display: flex;
  align-items: center;
  gap: var(--gap-4);
  height: var(--topbar);
  padding: 0 var(--gap-6);
  background: var(--surface-4);
  border-bottom: 1px solid var(--border-default);
  flex: 0 0 auto;
}

.mark {
  width: 16px;
  height: 16px;
  border-radius: var(--radius-control);
}

.name {
  font-size: 13.5px;
  font-weight: 600;
}

.version {
  display: inline-flex;
  align-items: center;
  gap: var(--gap-3);
  height: 24px;
  padding: 0 var(--gap-4);
  border-radius: 12px;
  background: var(--surface-0);
  border: 1px solid var(--border-default);
  font-size: 10.5px;
  color: var(--text-2);
}

.dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--ok);
}

.dot.draft {
  background: var(--warn);
}

.divider {
  width: 1px;
  height: 18px;
  background: var(--border-default);
}

.crumb {
  font-size: 11px;
  color: var(--text-3);
}

.spacer {
  flex: 1;
}

.badge {
  font-family: var(--font-mono);
  font-size: 10.5px;
  padding: 2px var(--gap-3);
  border-radius: var(--radius-chip);
}

.badge.error {
  color: var(--error);
  background: var(--error-surface);
  border: 1px solid var(--error-border);
}

.badge.warn {
  color: var(--warn-text);
  background: var(--warn-surface);
  border: 1px solid var(--warn-border);
}
</style>
