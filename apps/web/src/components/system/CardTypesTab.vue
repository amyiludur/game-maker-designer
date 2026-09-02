<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import EntityList from '@/components/system/EntityList.vue'
import FieldRow from '@/components/system/FieldRow.vue'
import { useSystemStore } from '@/stores/system'
import type { SystemEntity } from '@/stores/system'

/**
 * Card types — the schema builder.
 *
 * What a card *is* in this game is decided here: the attributes declared on a type become the
 * card editor's form, the per-type JSON Schema the server validates against, and the columns
 * the browser can sort by. Nothing else in the platform has that reach, which is why the
 * impact panel beside it matters most on this tab.
 *
 * The preview is not decoration: `showOnCard` is the only field whose meaning is a position,
 * and a position is not readable as a string.
 */
const props = defineProps<{ zones: { value: string; label: string }[] }>()

const system = useSystemStore()

interface Attribute extends SystemEntity {
  type?: string
  required?: boolean
  min?: number | null
  max?: number | null
  options?: string[]
  vocabulary?: string
  showOnCard?: string | boolean
}

const types = computed(() => system.list('cardTypes'))
const selected = ref<string | null>(types.value[0]?.id ?? null)
const current = computed(() => types.value.find((type) => type.id === selected.value) ?? null)

const attributes = computed<Attribute[]>(() =>
  Array.isArray(current.value?.attributes) ? (current.value?.attributes as Attribute[]) : [],
)

/**
 * The ability-slot cap, narrowed here rather than in the template.
 *
 * A cast with an inline object type cannot live in a template expression — the `>` closing
 * `{ max?: number }` closes the tag as far as any HTML parser is concerned, and the build
 * fails on markup that looks fine.
 */
const abilitySlotMax = computed<number | null>(() => {
  const slots = current.value?.abilitySlots
  if (typeof slots !== 'object' || slots === null) return null

  const max = (slots as { max?: unknown }).max
  return typeof max === 'number' ? max : null
})

watch(types, (next) => {
  if (!next.some((type) => type.id === selected.value)) selected.value = next[0]?.id ?? null
})

const SLOTS = ['top-left', 'top-right', 'type-line', 'center', 'bottom-left', 'bottom-right']
const TYPES = ['integer', 'decimal', 'string', 'text', 'boolean', 'enum', 'tagList', 'reference']

/** Which attribute, if any, is printed in each position of the card face. */
const preview = computed(() => {
  const slots: Record<string, string[]> = {}
  for (const slot of SLOTS) slots[slot] = []
  for (const attribute of attributes.value) {
    const where = typeof attribute.showOnCard === 'string' ? attribute.showOnCard : null
    if (where !== null && slots[where] !== undefined) slots[where].push(attribute.name ?? attribute.id)
  }
  return slots
})

function patch(changes: Record<string, unknown>): void {
  if (selected.value === null) return
  const id = changes.id ?? selected.value
  system.patch('cardTypes', selected.value, changes)
  selected.value = String(id)
}

function setAttributes(next: Attribute[]): void {
  patch({ attributes: next })
}

function patchAttribute(index: number, changes: Record<string, unknown>): void {
  setAttributes(
    attributes.value.map((attribute, at) => {
      if (at !== index) return attribute
      const merged = { ...attribute, ...changes }
      for (const [key, value] of Object.entries(changes)) {
        if (value === '' || value === null || value === undefined) delete merged[key]
      }
      return merged
    }),
  )
}

function addAttribute(): void {
  const taken = new Set(attributes.value.map((attribute) => attribute.id))
  let id = 'attribute'
  for (let n = 2; taken.has(id); n++) id = `attribute_${n}`

  setAttributes([...attributes.value, { id, name: 'Attribute', type: 'integer' }])
}

function removeAttribute(index: number): void {
  setAttributes(attributes.value.filter((_, at) => at !== index))
}

function moveAttribute(index: number, to: number): void {
  if (to < 0 || to >= attributes.value.length) return
  const next = [...attributes.value]
  next.splice(to, 0, ...next.splice(index, 1))
  setAttributes(next)
}

function toggleIn(key: 'playableTo' | 'modifiableAttributes', value: string): void {
  if (current.value === null) return
  const held = current.value[key]
  const list = Array.isArray(held) ? [...(held as string[])] : []
  const at = list.indexOf(value)
  if (at === -1) list.push(value)
  else list.splice(at, 1)
  patch({ [key]: list })
}

function holds(key: 'playableTo' | 'modifiableAttributes', value: string): boolean {
  const held = current.value?.[key]
  return Array.isArray(held) && (held as string[]).includes(value)
}

function addType(): void {
  const id = system.freeId('cardTypes', 'card_type')
  system.add('cardTypes', {
    id,
    name: 'Card Type',
    playableTo: [],
    attributes: [
      { id: 'cost', name: 'Cost', type: 'integer', min: 0, required: true, showOnCard: 'top-left' },
    ],
  } as SystemEntity)
  selected.value = id
}

function removeType(id: string): void {
  system.remove('cardTypes', id)
  selected.value = types.value[0]?.id ?? null
}

/** An enum's options are typed as a list, which reads better as one comma-separated line. */
function optionsText(attribute: Attribute): string {
  return Array.isArray(attribute.options) ? attribute.options.join(', ') : ''
}

function setOptions(index: number, text: string): void {
  const options = String(text)
    .split(',')
    .map((option) => option.trim())
    .filter((option) => option !== '')
  patchAttribute(index, { options: options.length > 0 ? options : undefined })
}
</script>

<template>
  <EntityList
    :entries="types"
    :selected="selected"
    noun="card type"
    empty="No card types yet — a game needs at least one before a card can exist."
    @select="selected = $event"
    @add="addType"
    @remove="removeType"
  >
    <template #title>
      <h2 class="title">{{ current?.name ?? current?.id }}</h2>
    </template>

    <template v-if="current">
      <div class="head-grid">
        <div class="fields">
          <FieldRow
            :model-value="current.id"
            label="Id"
            hint="what a card's `type` names"
            @update:model-value="patch({ id: String($event ?? '') })"
          />
          <FieldRow
            :model-value="(current.name as string) ?? ''"
            label="Name"
            @update:model-value="patch({ name: $event })"
          />
          <FieldRow
            :model-value="Boolean(current.isIdentity)"
            label="Identity"
            type="checkbox"
            placeholder="the hero / commander of a deck"
            @update:model-value="patch({ isIdentity: $event })"
          />
          <FieldRow
            :model-value="Boolean(current.unique)"
            label="Unique"
            type="checkbox"
            placeholder="one copy in play at a time"
            @update:model-value="patch({ unique: $event })"
          />
          <FieldRow
            :model-value="Boolean(current.doubleSided)"
            label="Double sided"
            type="checkbox"
            placeholder="two faces, each with its own type"
            @update:model-value="patch({ doubleSided: $event })"
          />
          <FieldRow
            :model-value="abilitySlotMax"
            label="Ability slots"
            type="number"
            hint="design guide, not a rule"
            @update:model-value="patch({ abilitySlots: $event === null ? undefined : { max: $event } })"
          />
        </div>

        <!-- Where each attribute lands on the printed face. -->
        <figure class="preview">
          <div v-for="slot in SLOTS" :key="slot" class="slot" :class="slot">
            <span class="slot-name mono">{{ slot }}</span>
            <span v-for="name in preview[slot]" :key="name" class="slot-value">{{ name }}</span>
          </div>
        </figure>
      </div>

      <div class="tag-block">
        <span class="label">Playable to <span class="hint mono">zones a card of this type enters</span></span>
        <div class="tags">
          <button
            v-for="zone in props.zones"
            :key="zone.value"
            type="button"
            class="tag"
            :class="{ on: holds('playableTo', zone.value) }"
            @click="toggleIn('playableTo', zone.value)"
          >
            {{ zone.label }}
          </button>
        </div>
      </div>

      <div class="tag-block">
        <span class="label">
          Modifiable
          <span class="hint mono">attributes a continuous effect may change</span>
        </span>
        <div class="tags">
          <button
            v-for="attribute in attributes"
            :key="attribute.id"
            type="button"
            class="tag"
            :class="{ on: holds('modifiableAttributes', attribute.id) }"
            @click="toggleIn('modifiableAttributes', attribute.id)"
          >
            {{ attribute.name ?? attribute.id }}
          </button>
        </div>
      </div>

      <h3 class="label section">Attributes</h3>
      <table class="attributes">
        <thead>
          <tr>
            <th>Id</th>
            <th>Name</th>
            <th>Type</th>
            <th class="num">Min</th>
            <th class="num">Max</th>
            <th>Options / vocabulary</th>
            <th>On card</th>
            <th class="num">Req</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="(attribute, index) in attributes" :key="index">
            <td>
              <input
                :value="attribute.id"
                class="mono"
                @input="patchAttribute(index, { id: ($event.target as HTMLInputElement).value })"
              />
            </td>
            <td>
              <input
                :value="attribute.name ?? ''"
                @input="patchAttribute(index, { name: ($event.target as HTMLInputElement).value })"
              />
            </td>
            <td>
              <select
                :value="attribute.type ?? 'integer'"
                @change="patchAttribute(index, { type: ($event.target as HTMLSelectElement).value })"
              >
                <option v-for="type in TYPES" :key="type" :value="type">{{ type }}</option>
              </select>
            </td>
            <td>
              <input
                type="number"
                :value="attribute.min ?? ''"
                @input="
                  patchAttribute(index, {
                    min:
                      ($event.target as HTMLInputElement).value === ''
                        ? undefined
                        : ($event.target as HTMLInputElement).valueAsNumber,
                  })
                "
              />
            </td>
            <td>
              <input
                type="number"
                :value="attribute.max ?? ''"
                @input="
                  patchAttribute(index, {
                    max:
                      ($event.target as HTMLInputElement).value === ''
                        ? undefined
                        : ($event.target as HTMLInputElement).valueAsNumber,
                  })
                "
              />
            </td>
            <td>
              <input
                v-if="attribute.type === 'enum'"
                :value="optionsText(attribute)"
                placeholder="one, two, three"
                @input="setOptions(index, ($event.target as HTMLInputElement).value)"
              />
              <input
                v-else-if="attribute.type === 'tagList'"
                :value="attribute.vocabulary ?? ''"
                placeholder="traits"
                @input="patchAttribute(index, { vocabulary: ($event.target as HTMLInputElement).value })"
              />
              <span v-else class="muted mono">—</span>
            </td>
            <td>
              <select
                :value="typeof attribute.showOnCard === 'string' ? attribute.showOnCard : ''"
                @change="patchAttribute(index, { showOnCard: ($event.target as HTMLSelectElement).value })"
              >
                <option value="">not printed</option>
                <option v-for="slot in SLOTS" :key="slot" :value="slot">{{ slot }}</option>
              </select>
            </td>
            <td class="num">
              <input
                type="checkbox"
                :checked="Boolean(attribute.required)"
                @change="patchAttribute(index, { required: ($event.target as HTMLInputElement).checked })"
              />
            </td>
            <td class="controls">
              <button class="nudge mono" :disabled="index === 0" @click="moveAttribute(index, index - 1)">
                ↑
              </button>
              <button
                class="nudge mono"
                :disabled="index === attributes.length - 1"
                @click="moveAttribute(index, index + 1)"
              >
                ↓
              </button>
              <button class="nudge remove mono" title="Remove" @click="removeAttribute(index)">×</button>
            </td>
          </tr>
        </tbody>
      </table>

      <button class="add mono" @click="addAttribute">+ attribute</button>
    </template>
  </EntityList>
</template>

<style scoped>
.title {
  margin: 0;
  font-size: 15px;
}

.head-grid {
  display: flex;
  gap: var(--gap-7);
  align-items: flex-start;
}

.fields {
  flex: 1;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: var(--gap-5) var(--gap-6);
}

.preview {
  flex: 0 0 172px;
  height: 240px;
  margin: 0;
  position: relative;
  background: var(--surface-0);
  border: 1px solid var(--border-default);
  border-radius: var(--radius-face);
  padding: var(--gap-4);
}

.slot {
  position: absolute;
  display: flex;
  flex-direction: column;
  gap: 1px;
  max-width: 46%;
}

.slot-name {
  font-size: 7.5px;
  color: var(--text-4);
  letter-spacing: 0.06em;
}

.slot-value {
  font-size: 10px;
  color: var(--text-1);
}

.slot.top-left {
  top: var(--gap-4);
  left: var(--gap-4);
}

.slot.top-right {
  top: var(--gap-4);
  right: var(--gap-4);
  text-align: right;
  align-items: flex-end;
}

.slot.type-line {
  top: 46%;
  left: var(--gap-4);
  right: var(--gap-4);
  max-width: none;
}

.slot.center {
  top: 62%;
  left: var(--gap-4);
  right: var(--gap-4);
  max-width: none;
}

.slot.bottom-left {
  bottom: var(--gap-4);
  left: var(--gap-4);
}

.slot.bottom-right {
  bottom: var(--gap-4);
  right: var(--gap-4);
  text-align: right;
  align-items: flex-end;
}

.tag-block {
  margin-top: var(--gap-6);
  display: flex;
  flex-direction: column;
  gap: var(--gap-3);
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

.section {
  margin: var(--gap-7) 0 var(--gap-4);
}

.attributes {
  width: 100%;
  border-collapse: collapse;
  font-size: 11px;
}

.attributes th {
  height: 22px;
  padding: 0 var(--gap-3);
  text-align: left;
  font-size: 9px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--text-4);
  font-weight: 500;
  border-bottom: 1px solid var(--border-default);
}

.attributes td {
  padding: 2px var(--gap-3);
  border-bottom: 1px solid var(--border-faint);
}

.attributes input[type='text'],
.attributes input:not([type]),
.attributes input[type='number'],
.attributes select {
  width: 100%;
  height: var(--control-sm);
  background: var(--surface-0);
  border: 1px solid transparent;
  border-radius: var(--radius-control);
  padding: 0 var(--gap-3);
  color: inherit;
  font: inherit;
  font-size: 11px;
}

.attributes input:hover,
.attributes select:hover {
  border-color: var(--border-default);
}

.attributes input:focus,
.attributes select:focus {
  outline: none;
  border-color: var(--info);
}

.num {
  text-align: right;
  width: 62px;
}

.controls {
  white-space: nowrap;
  width: 62px;
}

.nudge {
  height: 16px;
  width: 16px;
  padding: 0;
  background: none;
  border: 0;
  color: var(--text-4);
  cursor: pointer;
  font-size: 10px;
}

.nudge:hover:not(:disabled) {
  color: var(--text-1);
}

.nudge.remove:hover {
  color: var(--error-text);
}

.nudge:disabled {
  opacity: 0.25;
  cursor: default;
}

.add {
  margin-top: var(--gap-4);
  height: var(--control-sm);
  padding: 0 var(--gap-5);
  background: none;
  border: 1px dashed var(--border-strong);
  border-radius: var(--radius-control);
  color: var(--text-3);
  cursor: pointer;
  font-size: 10px;
}

.add:hover {
  border-color: var(--border-hover);
  color: var(--text-1);
}
</style>
