<script setup lang="ts">
import type { SystemEntity } from '@/stores/system'

/**
 * The round, drawn as it is played: phases across, steps down.
 *
 * This is the game's spine, and a list of nested JSON objects hides its shape completely.
 * Each step is badged with what it *is* — an automatic script, or a window in which players
 * may act — because "nothing would ever happen in this step" is the mistake the compiler
 * refuses and this board makes visible before you make it.
 */
defineProps<{ phases: SystemEntity[]; selected: string | null }>()

const emit = defineEmits<{
  select: [qualified: string]
  addPhase: []
  addStep: [phase: string]
  movePhase: [from: number, to: number]
  moveStep: [phase: string, from: number, to: number]
}>()

interface Step {
  id: string
  name?: string
  auto?: unknown[]
  window?: { type?: string; endOn?: string; actions?: string[] }
}

function steps(phase: SystemEntity): Step[] {
  return Array.isArray(phase.steps) ? (phase.steps as Step[]) : []
}

/** What the step does, in the fewest words that are still true. */
function summarise(step: Step): string {
  if (Array.isArray(step.auto)) {
    const ops = step.auto
      .map((node) => (typeof node === 'object' && node !== null ? (node as { op?: string }).op : undefined))
      .filter((op): op is string => typeof op === 'string')
    return ops.length > 0 ? ops.join(' · ') : 'no ops yet'
  }
  if (step.window !== undefined) {
    return [step.window.type, step.window.endOn].filter(Boolean).join(' · ') || 'window'
  }
  return 'neither auto nor window'
}

function kind(step: Step): 'auto' | 'window' | 'empty' {
  if (Array.isArray(step.auto)) return 'auto'
  if (step.window !== undefined) return 'window'
  return 'empty'
}
</script>

<template>
  <div class="board">
    <section v-for="(phase, index) in phases" :key="phase.id" class="phase">
      <header>
        <span class="phase-name">{{ phase.name ?? phase.id }}</span>
        <span class="phase-id mono">{{ phase.id }}</span>
        <span class="spacer" />
        <button
          class="nudge mono"
          :disabled="index === 0"
          title="Earlier"
          @click="emit('movePhase', index, index - 1)"
        >
          ←
        </button>
        <button
          class="nudge mono"
          :disabled="index === phases.length - 1"
          title="Later"
          @click="emit('movePhase', index, index + 1)"
        >
          →
        </button>
      </header>

      <ol>
        <li v-for="(step, position) in steps(phase)" :key="step.id">
          <button
            class="step"
            :class="{ on: selected === `${phase.id}.${step.id}` }"
            @click="emit('select', `${phase.id}.${step.id}`)"
          >
            <span class="step-head">
              <span class="number mono">{{ position + 1 }}</span>
              <span class="step-name">{{ step.name ?? step.id }}</span>
              <span class="badge mono" :class="kind(step)">{{ kind(step) }}</span>
            </span>
            <span class="ops mono">{{ summarise(step) }}</span>
          </button>
          <span class="order">
            <button
              class="nudge mono"
              :disabled="position === 0"
              title="Move up"
              @click="emit('moveStep', phase.id, position, position - 1)"
            >
              ↑
            </button>
            <button
              class="nudge mono"
              :disabled="position === steps(phase).length - 1"
              title="Move down"
              @click="emit('moveStep', phase.id, position, position + 1)"
            >
              ↓
            </button>
          </span>
        </li>
      </ol>

      <button class="add mono" @click="emit('addStep', phase.id)">+ step</button>
    </section>

    <button class="add-phase mono" @click="emit('addPhase')">+ phase</button>
  </div>
</template>

<style scoped>
.board {
  display: flex;
  gap: var(--gap-5);
  align-items: flex-start;
  padding: var(--gap-6);
  overflow-x: auto;
}

.phase {
  flex: 1 0 200px;
  min-width: 200px;
  background: var(--surface-3);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-card);
  overflow: hidden;
}

.phase > header {
  display: flex;
  align-items: baseline;
  gap: var(--gap-3);
  padding: var(--gap-3) var(--gap-4);
  background: var(--surface-5);
}

.phase-name {
  font-size: 12px;
  font-weight: 600;
}

.phase-id {
  font-size: 9px;
  color: var(--text-4);
}

.spacer {
  flex: 1;
}

ol {
  list-style: none;
  margin: 0;
  padding: var(--gap-4);
  display: flex;
  flex-direction: column;
  gap: var(--gap-3);
}

li {
  display: flex;
  align-items: stretch;
  gap: var(--gap-2);
}

.step {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: var(--gap-3) var(--gap-4);
  background: var(--surface-0);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-control);
  color: var(--text-2);
  cursor: pointer;
  text-align: left;
}

.step:hover {
  border-color: var(--border-hover);
}

.step.on {
  border-color: var(--accent);
  background: var(--surface-4);
  color: var(--text-1);
}

.step-head {
  display: flex;
  align-items: baseline;
  gap: var(--gap-3);
}

.number {
  font-size: 9px;
  color: var(--text-4);
}

.step-name {
  font-size: 11.5px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.badge {
  margin-left: auto;
  font-size: 8.5px;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  padding: 1px var(--gap-3);
  border-radius: var(--radius-chip);
}

.badge.auto {
  background: var(--ok-surface);
  color: var(--ok-text);
}

.badge.window {
  background: var(--info-surface);
  color: var(--info-text);
}

.badge.empty {
  background: var(--error-surface);
  color: var(--error-text);
}

.ops {
  font-size: 9px;
  color: var(--text-4);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.order {
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.nudge {
  height: 14px;
  width: 16px;
  padding: 0;
  background: none;
  border: 0;
  color: var(--text-4);
  cursor: pointer;
  font-size: 10px;
  line-height: 1;
}

.nudge:hover:not(:disabled) {
  color: var(--text-1);
}

.nudge:disabled {
  opacity: 0.25;
  cursor: default;
}

.add,
.add-phase {
  background: none;
  border: 1px dashed var(--border-strong);
  border-radius: var(--radius-control);
  color: var(--text-3);
  cursor: pointer;
  font-size: 10px;
}

.add {
  display: block;
  width: calc(100% - var(--gap-8));
  height: var(--control-sm);
  margin: 0 var(--gap-4) var(--gap-4);
}

.add-phase {
  flex: 0 0 92px;
  height: 64px;
}

.add:hover,
.add-phase:hover {
  border-color: var(--border-hover);
  color: var(--text-1);
}
</style>
