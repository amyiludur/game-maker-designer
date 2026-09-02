<script setup lang="ts">
import { computed } from 'vue'

import type { ViewCard } from '@/api/types'

const props = defineProps<{ card: ViewCard; targetable?: boolean; dimmed?: boolean }>()

const damage = computed(() => props.card.counters?.damage ?? 0)

/** Which values are not what the card is printed as, so the delta can be shown beside them. */
const modified = computed(() => props.card.modified ?? {})

function delta(attribute: string): string | null {
  const entry = modified.value[attribute]
  if (entry === undefined) return null
  const from = Number(entry.printed ?? 0)
  const to = Number(entry.current ?? 0)
  if (!Number.isFinite(from) || !Number.isFinite(to) || from === to) return null
  return to > from ? `+${to - from}` : String(to - from)
}
</script>

<template>
  <article
    class="card"
    :class="{ exhausted: card.exhausted, targetable, dimmed, hidden: card.hidden }"
    :aria-label="
      card.hidden
        ? 'Face-down card'
        : `${card.name}, attack ${card.attributes?.attack ?? '—'}, health ${card.attributes?.health ?? '—'}`
    "
  >
    <template v-if="card.hidden">
      <span class="back" />
    </template>
    <template v-else>
      <header>
        <span class="name">{{ card.name }}</span>
        <!-- Exhausted is rotation *and* a badge: rotation alone is not a state a screen
             reader or a colourblind player can perceive. -->
        <span v-if="card.exhausted" class="badge mono">exhausted</span>
      </header>

      <footer class="stats">
        <span v-if="card.attributes?.attack !== undefined" class="stat">
          {{ card.attributes.attack }}
          <span v-if="delta('attack')" class="delta">{{ delta('attack') }}</span>
        </span>
        <span v-if="card.attributes?.health !== undefined" class="stat right">
          {{ card.attributes.health }}
          <span v-if="delta('health')" class="delta">{{ delta('health') }}</span>
        </span>
      </footer>

      <div v-if="damage > 0" class="pips" :aria-label="`${damage} damage`">
        <span v-for="n in Math.min(damage, 8)" :key="n" class="pip" />
        <span v-if="damage > 8" class="mono more">{{ damage }}</span>
      </div>
    </template>
  </article>
</template>

<style scoped>
.card {
  position: relative;
  /* Fixed, and never shrunk: a wide board scrolls rather than squeezing thirteen cards
     into the width of nine, which made the last one unreadable and the exhausted one a
     column of vertical letters. */
  flex: 0 0 88px;
  width: 88px;
  min-height: 108px;
  background: #191512;
  border: 1px solid #3a2a24;
  border-radius: var(--radius-face);
  padding: var(--gap-4);
  color: #f3e7dd;
  box-shadow: var(--shadow-in-play);
  display: flex;
  flex-direction: column;
  transition:
    transform 120ms ease,
    opacity 120ms ease;
}

.card.exhausted {
  transform: rotate(90deg);
}

.card.targetable {
  outline: 2px solid var(--warn-text);
  outline-offset: 2px;
  box-shadow: 0 0 12px -2px var(--warn-text);
}

.card.dimmed {
  opacity: 0.4;
}

.card.hidden {
  background: var(--surface-4);
  border-color: var(--border-default);
}

.back {
  flex: 1;
  border-radius: var(--radius-control);
  background: repeating-linear-gradient(135deg, var(--surface-5) 0 6px, var(--surface-4) 6px 12px);
}

.name {
  font-family: var(--font-card);
  font-size: 11px;
  font-weight: 600;
  line-height: 1.2;
}

.badge {
  display: block;
  font-size: 8px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--warn-text);
  margin-top: 2px;
}

.stats {
  margin-top: auto;
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  font-size: 13px;
  font-weight: 700;
}

.delta {
  font-size: 9px;
  color: var(--warn-text);
  margin-left: 2px;
  vertical-align: super;
}

.pips {
  position: absolute;
  right: 4px;
  bottom: 20px;
  display: flex;
  flex-wrap: wrap;
  gap: 2px;
  width: 22px;
  justify-content: flex-end;
}

.pip {
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: #c0392b;
}

.more {
  font-size: 8px;
}
</style>
