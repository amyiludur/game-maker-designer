<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import CardTypesTab from '@/components/system/CardTypesTab.vue'
import CollectionTab from '@/components/system/CollectionTab.vue'
import ImpactPanel from '@/components/system/ImpactPanel.vue'
import RoundBoard from '@/components/system/RoundBoard.vue'
import { COLLECTION_TABS } from '@/components/system/specs'
import type { OptionSource } from '@/components/system/specs'
import { useSystemStore } from '@/stores/system'
import type { SystemEntity } from '@/stores/system'

/**
 * The system editor — where a game's rules are defined rather than imported.
 *
 * Every tab edits one document held in the store, and nothing is written until Save. That is
 * deliberate: a system change can invalidate cards, decks and saved matches, so the editor
 * asks the server what it would cost (`Check impact`) while the change is still a proposal.
 *
 * The tabs are data. Card types and the round get their own components because a schema
 * builder and a phase graph are genuinely different shapes; the other nine are one component
 * driven by `COLLECTION_TABS`.
 */
const props = defineProps<{ game: string }>()

const system = useSystemStore()

const tab = ref('cardTypes')

watch(
  () => props.game,
  (slug) => void system.load(slug),
  { immediate: true },
)

/**
 * The vocabularies each tab's selects draw from — all of them from the document being
 * edited, so a zone added on one tab is selectable on the next without a save.
 */
const options = computed<Record<OptionSource, { value: string; label: string }[]>>(() => {
  const from = (path: string) =>
    system.list(path).map((entry) => ({ value: entry.id, label: entry.name ?? entry.id }))

  // A window is named `phase.step`, which is the id an action's `windows` actually holds.
  const steps: { value: string; label: string }[] = []
  for (const phase of system.list('round.phases')) {
    for (const step of Array.isArray(phase.steps) ? (phase.steps as SystemEntity[]) : []) {
      steps.push({
        value: `${phase.id}.${step.id}`,
        label: `${phase.name ?? phase.id} · ${step.name ?? step.id}`,
      })
    }
  }

  return {
    zones: from('zones'),
    cardTypes: from('cardTypes'),
    resources: from('resources'),
    counters: from('counters'),
    keywords: from('keywords'),
    steps,
  }
})

const phases = computed(() => system.list('round.phases'))
const selectedStep = ref<string | null>(null)

function addPhase(): void {
  system.add('round.phases', { id: system.freeId('round.phases', 'phase'), name: 'Phase', steps: [] })
}

function addStep(phase: string): void {
  const path = `round.phases.${phaseIndex(phase)}.steps`
  system.add(path, { id: system.freeId(path, 'step'), name: 'Step' })
}

function movePhase(from: number, to: number): void {
  system.move('round.phases', from, to)
}

function moveStep(phase: string, from: number, to: number): void {
  system.move(`round.phases.${phaseIndex(phase)}.steps`, from, to)
}

/** The store addresses nested collections by index, so a phase id has to become one. */
function phaseIndex(phase: string): number {
  return phases.value.findIndex((entry) => entry.id === phase)
}

async function save(): Promise<void> {
  await system.save()
}
</script>

<template>
  <div class="system">
    <header class="bar">
      <nav class="tabs">
        <button :class="{ on: tab === 'cardTypes' }" @click="tab = 'cardTypes'">Card types</button>
        <button :class="{ on: tab === 'round' }" @click="tab = 'round'">Round</button>
        <button
          v-for="spec in COLLECTION_TABS"
          :key="spec.id"
          :class="{ on: tab === spec.id }"
          @click="tab = spec.id"
        >
          {{ spec.label }}
        </button>
      </nav>

      <span class="spacer" />

      <!-- Published versions are frozen: replays reference them, so editing one in place
           would rewrite the past. -->
      <span v-if="!system.editable" class="frozen mono">{{ system.status }} — read only</span>
      <span v-else-if="system.dirty" class="dirty mono">unsaved</span>

      <button class="ghost" :disabled="!system.dirty || system.checking" @click="system.check()">
        {{ system.checking ? 'Checking…' : 'Check impact' }}
      </button>
      <button class="ghost" :disabled="!system.dirty" @click="system.revert()">Revert</button>
      <button class="primary" :disabled="!system.dirty || system.saving || !system.editable" @click="save">
        {{ system.saving ? 'Saving…' : 'Save' }}
      </button>
    </header>

    <div class="body">
      <main class="pane">
        <p v-if="system.loading" class="muted">Loading the system…</p>

        <CardTypesTab v-else-if="tab === 'cardTypes'" :zones="options.zones" />

        <RoundBoard
          v-else-if="tab === 'round'"
          :phases="phases"
          :selected="selectedStep"
          @select="selectedStep = $event"
          @add-phase="addPhase"
          @add-step="addStep"
          @move-phase="movePhase"
          @move-step="moveStep"
        />

        <template v-for="spec in COLLECTION_TABS" :key="spec.id">
          <CollectionTab v-if="tab === spec.id" :spec="spec" :options="options" />
        </template>
      </main>

      <ImpactPanel
        :report="system.report"
        :lint="system.lint"
        :checking="system.checking"
        :dirty="system.dirty"
        :violations="system.violations"
      />
    </div>
  </div>
</template>

<style scoped>
.system {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

.bar {
  display: flex;
  align-items: center;
  gap: var(--gap-4);
  height: 40px;
  flex: 0 0 auto;
  padding: 0 var(--gap-6);
  background: var(--surface-3);
  border-bottom: 1px solid var(--border-default);
}

.tabs {
  display: flex;
  gap: 2px;
  overflow-x: auto;
}

.tabs button {
  height: 26px;
  padding: 0 var(--gap-5);
  background: none;
  border: 0;
  border-radius: var(--radius-control);
  color: var(--text-3);
  cursor: pointer;
  white-space: nowrap;
  font-size: 11.5px;
}

.tabs button:hover {
  background: var(--surface-4);
  color: var(--text-2);
}

.tabs button.on {
  background: var(--surface-6);
  color: var(--text-1);
}

.spacer {
  flex: 1;
}

.dirty {
  font-size: 9px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--warn-text);
}

.frozen {
  font-size: 9px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--text-4);
}

.ghost {
  height: var(--control);
  padding: 0 var(--gap-5);
  background: none;
  border: 1px solid var(--border-default);
  border-radius: var(--radius-control);
  color: var(--text-2);
  cursor: pointer;
  font-size: 11.5px;
}

.ghost:disabled {
  opacity: 0.4;
  cursor: default;
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

.body {
  display: flex;
  flex: 1;
  min-height: 0;
}

.pane {
  flex: 1;
  min-width: 0;
  overflow: auto;
}

.muted {
  padding: var(--gap-8);
  color: var(--text-3);
}
</style>
