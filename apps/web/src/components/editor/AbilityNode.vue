<script setup lang="ts">
import { computed } from 'vue'

/**
 * One node of an effect or expression, rendered recursively.
 *
 * The same component draws a card's ability and a phase step's automatic script, because
 * they are the same language — which is exactly why the platform has one expression
 * evaluator rather than several.
 *
 * Nesting is expressed three ways at once, per the handoff: an indent, a left rule, and a
 * background step. One of the three would be enough to see; all three make it readable at
 * four levels deep, which is where these trees actually live.
 */
const props = withDefaults(defineProps<{ node: unknown; depth?: number; showDepthBadges?: boolean }>(), {
  depth: 0,
  showDepthBadges: true,
})

const isNode = computed(
  () => typeof props.node === 'object' && props.node !== null && !Array.isArray(props.node),
)
const op = computed(() => (isNode.value ? (props.node as Record<string, unknown>).op : undefined))

/** Everything but the op, split into leaves and branches so branches render below. */
const entries = computed(() => {
  if (!isNode.value) return []
  return Object.entries(props.node as Record<string, unknown>).filter(
    ([key]) => key !== 'op' && key !== 'text',
  )
})

const leaves = computed(() => entries.value.filter(([, value]) => !isBranch(value)))
const branches = computed(() => entries.value.filter(([, value]) => isBranch(value)))

function isBranch(value: unknown): boolean {
  return typeof value === 'object' && value !== null
}

function isSelector(value: unknown): boolean {
  return typeof value === 'string' && value.startsWith('$')
}

const background = computed(
  () => ['var(--surface-4)', '#0f1418', 'var(--surface-0)'][Math.min(props.depth, 2)],
)
</script>

<template>
  <div class="node" :style="{ background }">
    <div class="head">
      <span v-if="showDepthBadges && depth > 0" class="badge mono">L{{ depth }}</span>
      <span v-if="op" class="op mono">{{ op }}</span>
      <template v-for="[key, value] in leaves" :key="key">
        <span class="pair">
          <span class="key mono">{{ key }}</span>
          <!-- DSL selectors are drawn as references, not literals: $self is a pointer, and
               reading it as a string is how a designer misunderstands an ability. -->
          <span class="value" :class="{ selector: isSelector(value) }">{{ value }}</span>
        </span>
      </template>
    </div>

    <div v-for="[key, value] in branches" :key="key" class="branch">
      <span class="branch-key mono">{{ key }}</span>
      <template v-if="Array.isArray(value)">
        <AbilityNode
          v-for="(child, index) in value"
          :key="index"
          :node="child"
          :depth="depth + 1"
          :show-depth-badges="showDepthBadges"
        />
      </template>
      <AbilityNode v-else :node="value" :depth="depth + 1" :show-depth-badges="showDepthBadges" />
    </div>
  </div>
</template>

<style scoped>
.node {
  border-radius: var(--radius-chip);
  padding: var(--gap-3) var(--gap-4);
}

.head {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--gap-3);
  min-height: 20px;
}

.badge {
  font-size: 8.5px;
  color: var(--text-4);
  border: 1px solid var(--border-faint);
  border-radius: 2px;
  padding: 0 3px;
}

.op {
  font-size: 10.5px;
  font-weight: 600;
  color: var(--info-text);
  background: var(--surface-5);
  border-radius: var(--radius-chip);
  padding: 1px var(--gap-3);
}

.pair {
  display: inline-flex;
  align-items: baseline;
  gap: var(--gap-2);
  font-size: 11px;
}

.key {
  font-size: 9px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--text-4);
}

.value {
  color: var(--text-2);
}

.value.selector {
  color: var(--token-ref);
}

.branch {
  margin-top: var(--gap-3);
  margin-left: 6px;
  padding-left: var(--gap-4);
  border-left: 2px solid #2a3742;
  display: flex;
  flex-direction: column;
  gap: var(--gap-2);
}

.branch-key {
  font-size: 9px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--accent);
}
</style>
