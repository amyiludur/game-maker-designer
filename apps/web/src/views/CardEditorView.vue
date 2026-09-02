<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import { ApiError_, api } from '@/api/client'
import AbilityNode from '@/components/editor/AbilityNode.vue'
import AttributeForm from '@/components/editor/AttributeForm.vue'
import type { CardDetail } from '@/api/types'
import { useGameStore } from '@/stores/game'

const props = defineProps<{ game: string; card: string }>()

const games = useGameStore()

const detail = ref<CardDetail | null>(null)
const document_ = ref<Record<string, unknown>>({})
const saving = ref(false)
const saved = ref<string | null>(null)
const violations = ref<{ pointer: string; message: string }[]>([])
const tab = ref<'lint' | 'json' | 'revisions'>('lint')
const showDepthBadges = ref(true)

const type = computed(() => (document_.value.type as string | undefined) ?? null)
const compiledType = computed(() => (type.value ? games.compiled?.cardTypes[type.value] : undefined))
const attributes = computed({
  get: () => (document_.value.attributes as Record<string, unknown>) ?? {},
  set: (value) => {
    document_.value = { ...document_.value, attributes: value }
  },
})

const vocabularies = computed<Record<string, string[]>>(() => ({
  traits: games.traits,
  rarities: games.compiled?.vocabularies.rarities ?? [],
}))

const abilities = computed(() => (document_.value.abilities as unknown[]) ?? [])

watch(
  () => props.card,
  async (code) => {
    detail.value = await api.card(code)
    document_.value = structuredClone(detail.value.document)
    violations.value = []
    saved.value = null
  },
  { immediate: true },
)

async function save(): Promise<void> {
  saving.value = true
  violations.value = []
  try {
    detail.value = await api.saveCard(props.card, document_.value)
    saved.value = new Date().toLocaleTimeString()
  } catch (error) {
    if (error instanceof ApiError_) violations.value = error.violations
    else throw error
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div v-if="detail" class="editor">
    <section class="form-column">
      <header class="subhead">
        <input v-model="document_.name" class="title" />
        <span class="spacer" />
        <span v-if="saved" class="mono muted">saved {{ saved }} · rev {{ detail.revisions.length }}</span>
        <button class="primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
      </header>

      <p class="meta mono">
        {{ detail.code }} · {{ detail.type }}
        <span v-if="detail.faction">
          · <span class="swatch" :style="{ background: games.factionColor(detail.faction) }" />{{ detail.faction }}
        </span>
      </p>

      <h2 class="label section">Attributes <span class="muted">from cardTypes.{{ type }}</span></h2>
      <AttributeForm
        v-if="compiledType"
        v-model="attributes"
        :fields="compiledType.fields"
        :vocabularies="vocabularies"
      />
      <p v-else class="muted">This card's type is not in the compiled bundle.</p>

      <h2 class="label section">Abilities</h2>
      <p v-if="abilities.length === 0" class="muted">No abilities. Its behaviour is whatever its keywords grant.</p>
      <article v-for="(ability, index) in abilities" :key="index" class="ability">
        <header>
          <span class="id mono">{{ (ability as Record<string, string>).id }}</span>
          <span class="kind mono">
            {{ (ability as Record<string, string>).kind }} · {{ (ability as Record<string, string>).speed }}
          </span>
          <span class="spacer" />
          <label class="toggle mono">
            <input v-model="showDepthBadges" type="checkbox" /> depth
          </label>
        </header>
        <AbilityNode :node="ability" :show-depth-badges="showDepthBadges" />
        <p v-if="(ability as Record<string, string>).text" class="generated">
          {{ (ability as Record<string, string>).text }}
        </p>
      </article>
    </section>

    <aside class="context">
      <nav class="tabs">
        <button v-for="name in (['lint', 'json', 'revisions'] as const)" :key="name" :class="{ on: tab === name }" @click="tab = name">
          {{ name }}
        </button>
      </nav>

      <div v-if="tab === 'lint'" class="pane">
        <div v-if="violations.length" class="panel error">
          <h3 class="label">Rejected</h3>
          <!-- The pointer is why this can sit next to the field rather than at the top. -->
          <p v-for="violation in violations" :key="violation.pointer" class="violation">
            <span class="mono pointer">{{ violation.pointer }}</span>
            {{ violation.message }}
          </p>
        </div>
        <div v-else class="panel ok">
          <h3 class="label">Valid</h3>
          <p class="muted">This card matches the schema its type declares.</p>
        </div>

        <div v-if="games.lint.length" class="panel">
          <h3 class="label">Game lint</h3>
          <p v-for="(finding, index) in games.lint.slice(0, 8)" :key="index" class="finding">
            <span class="mono" :class="finding.severity">{{ finding.severity === 'error' ? '◆' : '▲' }}</span>
            {{ finding.message }}
          </p>
        </div>
      </div>

      <pre v-else-if="tab === 'json'" class="json mono">{{ JSON.stringify(document_, null, 2) }}</pre>

      <ul v-else class="revisions">
        <li v-for="revision in [...detail.revisions].reverse()" :key="revision.revision">
          <span class="mono">rev {{ revision.revision }}</span>
          <span class="muted">{{ revision.message ?? '—' }}</span>
        </li>
      </ul>
    </aside>
  </div>
</template>

<style scoped>
.editor {
  display: flex;
  height: 100%;
  min-height: 0;
}

.form-column {
  flex: 1;
  min-width: 0;
  padding: var(--gap-6) var(--gap-8);
  overflow: auto;
}

.subhead {
  display: flex;
  align-items: center;
  gap: var(--gap-4);
  height: 40px;
}

.title {
  font-size: 21px;
  font-weight: 600;
  background: none;
  border: 0;
  border-bottom: 1px solid transparent;
  padding: 0;
  flex: 0 1 auto;
  min-width: 0;
}

.title:hover {
  border-bottom-color: var(--border-default);
}

.title:focus {
  outline: none;
  border-bottom-color: var(--info);
}

.spacer {
  flex: 1;
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
  opacity: 0.6;
}

.meta {
  margin: 0 0 var(--gap-7);
  font-size: 10px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--text-3);
}

.swatch {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 2px;
  margin-right: 4px;
}

.section {
  margin: var(--gap-8) 0 var(--gap-5);
}

.ability {
  background: var(--surface-3);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-control);
  margin-bottom: var(--gap-5);
  overflow: hidden;
}

.ability > header {
  display: flex;
  align-items: center;
  gap: var(--gap-4);
  height: 28px;
  padding: 0 var(--gap-5);
  background: var(--surface-5);
}

.ability .id {
  font-size: 10px;
  background: var(--surface-0);
  border-radius: var(--radius-chip);
  padding: 1px var(--gap-3);
}

.ability .kind {
  font-size: 9.5px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--text-3);
}

.toggle {
  font-size: 9px;
  color: var(--text-4);
  display: flex;
  align-items: center;
  gap: 3px;
}

.generated {
  margin: 0;
  padding: var(--gap-4) var(--gap-5);
  font-family: var(--font-card);
  font-size: 13.5px;
  background: var(--surface-0);
  border-left: 2px solid var(--accent);
  color: var(--text-2);
}

.context {
  width: 296px;
  flex: 0 0 296px;
  background: var(--surface-3);
  border-left: 1px solid var(--border-subtle);
  display: flex;
  flex-direction: column;
}

.tabs {
  display: flex;
  height: 28px;
  border-bottom: 1px solid var(--border-subtle);
}

.tabs button {
  flex: 1;
  background: none;
  border: 0;
  border-bottom: 2px solid transparent;
  color: var(--text-3);
  cursor: pointer;
  font-size: 10.5px;
}

.tabs button.on {
  color: var(--text-1);
  border-bottom-color: var(--accent);
}

.pane,
.json,
.revisions {
  padding: var(--gap-6);
  overflow: auto;
  margin: 0;
}

.panel {
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-control);
  padding: var(--gap-5);
  margin-bottom: var(--gap-5);
}

.panel.error {
  background: var(--error-surface);
  border-color: var(--error-border);
}

.panel.ok {
  background: var(--ok-surface);
  border-color: var(--ok-border);
}

.violation,
.finding {
  margin: var(--gap-3) 0 0;
  font-size: 11px;
  color: var(--text-2);
}

.pointer {
  color: var(--error-text);
  font-size: 10px;
  margin-right: var(--gap-3);
}

.finding .error {
  color: var(--error);
}

.finding .warning {
  color: var(--warn-text);
}

.json {
  font-size: 10.5px;
  color: var(--text-3);
  white-space: pre-wrap;
}

.revisions {
  list-style: none;
}

.revisions li {
  display: flex;
  gap: var(--gap-4);
  padding: var(--gap-3) 0;
  border-bottom: 1px solid var(--border-faint);
  font-size: 11px;
}
</style>
