<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import AbilityNode from '@/components/editor/AbilityNode.vue'
import EntityList from '@/components/system/EntityList.vue'
import FieldRow from '@/components/system/FieldRow.vue'
import JsonSlice from '@/components/system/JsonSlice.vue'
import type { FieldSpec, OptionSource, TabSpec } from '@/components/system/specs'
import { useSystemStore } from '@/stores/system'
import type { SystemEntity } from '@/stores/system'

/**
 * Any collection of the system document, edited from its description.
 *
 * Zones, resources, counters, keywords, actions, state checks, win conditions, board rows and
 * rulebook sections are all this component; what makes them different is the spec.
 *
 * Effect and expression trees get the ability builder's node renderer beside a JSON editor:
 * reading a `for_each_player → gain_resource → min(add(round(),1),8)` tree as raw JSON is
 * how a designer loses track of what a step actually does.
 */
const props = defineProps<{
  spec: TabSpec
  options: Record<OptionSource, { value: string; label: string }[]>
}>()

const system = useSystemStore()

const entries = computed(() => system.list(props.spec.path))
const selected = ref<string | null>(entries.value[0]?.id ?? null)

watch(
  () => props.spec.id,
  () => {
    selected.value = entries.value[0]?.id ?? null
  },
)

const current = computed<SystemEntity | null>(
  () => entries.value.find((entry) => entry.id === selected.value) ?? null,
)

function optionsFor(field: FieldSpec): { value: string; label: string }[] {
  const base = field.source ? props.options[field.source] : (field.options ?? [])
  // A select can always be cleared: an optional field that cannot be unset is a trap.
  return field.type === 'select' && !base.some((option) => option.value === '')
    ? [{ value: '', label: '—' }, ...base]
    : base
}

/** Read a dotted key inside an entry: `outcome.loser`. */
/**
 * A dotted path out of an entity, narrowed to what a field can hold.
 *
 * Typed here rather than cast at the call site: a cast in a template expression writes a
 * `|` into the markup, which the Vue linter reads as a deprecated filter, and an inline
 * object type would write a `>` that closes the tag. Both have bitten this codebase already.
 */
function read(entry: SystemEntity, key: string): string | number | boolean | null {
  let node: unknown = entry
  for (const part of key.split('.')) {
    if (node === null || typeof node !== 'object') return null
    node = (node as Record<string, unknown>)[part]
  }

  if (typeof node === 'string' || typeof node === 'number' || typeof node === 'boolean') {
    return node
  }
  return null
}

function write(key: string, value: unknown): void {
  if (current.value === null) return

  const parts = key.split('.')
  const next = structuredClone(current.value) as Record<string, unknown>

  let node = next
  for (const part of parts.slice(0, -1)) {
    if (node[part] === null || typeof node[part] !== 'object') node[part] = {}
    node = node[part] as Record<string, unknown>
  }

  // `split` always yields at least one element, so the guard is for the type rather than
  // for a case that can happen.
  const leaf = parts[parts.length - 1]
  if (leaf === undefined) return

  // An empty optional field is absent, not empty: `"icon": ""` would be a value, and the
  // schema and the compiler both read absence as "not set".
  if (value === '' || value === null || value === undefined) delete node[leaf]
  else node[leaf] = value

  replace(next as SystemEntity)
}

function toggleTag(key: string, tag: string): void {
  if (current.value === null) return
  const held = read(current.value, key)
  const list = Array.isArray(held) ? [...(held as string[])] : []
  const at = list.indexOf(tag)
  if (at === -1) list.push(tag)
  else list.splice(at, 1)
  write(key, list)
}

function has(key: string, tag: string): boolean {
  if (current.value === null) return false
  const held = read(current.value, key)
  return Array.isArray(held) && (held as string[]).includes(tag)
}

/** Replace the selected entry, following its id if the id itself was what changed. */
function replace(entry: SystemEntity): void {
  const id = selected.value
  if (id === null) return

  system.write(
    props.spec.path,
    entries.value.map((existing) => (existing.id === id ? entry : existing)),
  )
  selected.value = entry.id
}

function add(): void {
  const id = system.freeId(props.spec.path, props.spec.idStem)
  system.add(props.spec.path, { ...structuredClone(props.spec.blank), id } as SystemEntity)
  selected.value = id
}

function remove(id: string): void {
  system.remove(props.spec.path, id)
  selected.value = entries.value[0]?.id ?? null
}
</script>

<template>
  <EntityList
    :entries="entries"
    :selected="selected"
    :noun="spec.noun"
    :reorderable="spec.reorderable"
    :empty="`No ${spec.noun}s yet. Add one on the left.`"
    @select="selected = $event"
    @add="add"
    @remove="remove"
    @move="(from, to) => system.move(spec.path, from, to)"
  >
    <template #title>
      <h2 class="title">{{ current?.name ?? current?.id }}</h2>
    </template>

    <template v-if="current">
      <div class="fields">
        <template v-for="field in spec.fields" :key="field.key">
          <div v-if="field.type === 'tags'" class="field tags-field">
            <span class="label">
              {{ field.label }}
              <span v-if="field.hint" class="hint mono">{{ field.hint }}</span>
            </span>
            <div class="tags">
              <button
                v-for="option in optionsFor(field)"
                :key="option.value"
                type="button"
                class="tag"
                :class="{ on: has(field.key, option.value) }"
                @click="toggleTag(field.key, option.value)"
              >
                {{ option.label }}
              </button>
              <span v-if="optionsFor(field).length === 0" class="muted mono">nothing to choose from yet</span>
            </div>
          </div>

          <FieldRow
            v-else
            :model-value="read(current, field.key)"
            :label="field.label"
            :type="field.type"
            :hint="field.hint"
            :placeholder="field.placeholder"
            :options="optionsFor(field)"
            @update:model-value="write(field.key, $event)"
          />
        </template>
      </div>

      <section v-for="key in spec.scripts ?? []" :key="key" class="script">
        <h3 class="label">{{ key }}</h3>
        <AbilityNode
          v-if="read(current, key) !== undefined"
          :node="read(current, key)"
          :show-depth-badges="false"
        />
        <JsonSlice :value="read(current, key) ?? null" @update="write(key, $event)" />
      </section>
    </template>
  </EntityList>
</template>

<style scoped>
.title {
  margin: 0;
  font-size: 15px;
}

.fields {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(168px, 1fr));
  gap: var(--gap-5) var(--gap-6);
}

.field {
  display: flex;
  flex-direction: column;
  gap: var(--gap-3);
  grid-column: 1 / -1;
}

.label {
  display: flex;
  align-items: baseline;
  gap: var(--gap-3);
  font-size: 10px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--text-3);
}

.hint {
  font-size: 9px;
  color: var(--text-4);
  letter-spacing: 0.04em;
  text-transform: none;
}

.tags {
  display: flex;
  flex-wrap: wrap;
  gap: var(--gap-3);
}

.tag {
  height: 22px;
  padding: 0 var(--gap-4);
  background: var(--surface-0);
  border: 1px dashed var(--border-strong);
  border-radius: var(--radius-chip);
  color: var(--text-3);
  cursor: pointer;
  font-size: 11px;
}

.tag.on {
  background: var(--surface-6);
  border-style: solid;
  border-color: var(--border-hover);
  color: var(--text-1);
}

.script {
  margin-top: var(--gap-7);
  display: flex;
  flex-direction: column;
  gap: var(--gap-4);
}

.script h3 {
  margin: 0;
}
</style>
