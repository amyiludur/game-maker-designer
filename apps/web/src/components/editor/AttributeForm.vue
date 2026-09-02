<script setup lang="ts">
import type { FormField } from '@/api/types'

/**
 * The card editor's form, built entirely from the compiled descriptor.
 *
 * There is no per-game code here and there must never be: a game whose cards have `thwart`
 * and `scheme` instead of `attack` and `health` gets the right form because the compiler
 * told this component what the fields are. Hardcoding a field name would quietly turn a
 * multi-game platform into a single-game one.
 */
defineProps<{ fields: FormField[]; vocabularies: Record<string, string[]> }>()

const model = defineModel<Record<string, unknown>>({ required: true })

function toggleTag(field: FormField, tag: string): void {
  const current = Array.isArray(model.value[field.id]) ? [...(model.value[field.id] as string[])] : []
  const at = current.indexOf(tag)
  if (at === -1) current.push(tag)
  else current.splice(at, 1)
  model.value = { ...model.value, [field.id]: current }
}

function set(field: FormField, value: unknown): void {
  model.value = { ...model.value, [field.id]: value }
}
</script>

<template>
  <div class="form">
    <div v-for="field in fields" :key="field.id" class="field">
      <label class="label" :for="`f-${field.id}`">
        {{ field.name }}
        <!-- The constraint lives next to the field, not in a validation error later. -->
        <span v-if="field.constraint" class="constraint">{{ field.constraint }}</span>
        <span v-if="field.required" class="required" title="required">*</span>
      </label>

      <div v-if="field.type === 'integer' || field.type === 'decimal'" class="stepper">
        <input
          :id="`f-${field.id}`"
          type="number"
          :min="field.min"
          :max="field.max"
          :value="model[field.id] ?? ''"
          @input="set(field, ($event.target as HTMLInputElement).valueAsNumber)"
        />
      </div>

      <select
        v-else-if="field.type === 'enum'"
        :id="`f-${field.id}`"
        :value="model[field.id] ?? ''"
        @change="set(field, ($event.target as HTMLSelectElement).value)"
      >
        <option value="">—</option>
        <option v-for="option in field.options ?? []" :key="option" :value="option">{{ option }}</option>
      </select>

      <div v-else-if="field.type === 'tagList'" class="tags">
        <!-- Unused vocabulary members are shown as an add affordance rather than hidden:
             the controlled list is the point, and a designer should see what is available. -->
        <button
          v-for="tag in vocabularies[field.vocabulary ?? ''] ?? []"
          :key="tag"
          type="button"
          class="tag"
          :class="{ on: ((model[field.id] as string[]) ?? []).includes(tag) }"
          @click="toggleTag(field, tag)"
        >
          {{ tag }}
        </button>
      </div>

      <label v-else-if="field.type === 'boolean'" class="checkbox">
        <input
          :id="`f-${field.id}`"
          type="checkbox"
          :checked="Boolean(model[field.id])"
          @change="set(field, ($event.target as HTMLInputElement).checked)"
        />
        <span class="muted">{{ field.help ?? 'yes' }}</span>
      </label>

      <textarea
        v-else-if="field.type === 'text'"
        :id="`f-${field.id}`"
        rows="3"
        :value="(model[field.id] as string) ?? ''"
        @input="set(field, ($event.target as HTMLTextAreaElement).value)"
      />

      <input
        v-else
        :id="`f-${field.id}`"
        type="text"
        :value="(model[field.id] as string) ?? ''"
        @input="set(field, ($event.target as HTMLInputElement).value)"
      />

      <p v-if="field.perPlayer" class="hint mono">per player — multiplied by the number of seats</p>
    </div>
  </div>
</template>

<style scoped>
.form {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: var(--gap-6);
}

.field {
  display: flex;
  flex-direction: column;
  gap: var(--gap-3);
}

.label {
  display: flex;
  align-items: baseline;
  gap: var(--gap-3);
}

.constraint {
  font-size: 9px;
  color: var(--text-4);
  letter-spacing: 0.06em;
}

.required {
  color: var(--warn-text);
}

input[type='text'],
input[type='number'],
select,
textarea {
  height: var(--control);
  background: var(--surface-0);
  border: 1px solid var(--border-default);
  border-radius: var(--radius-control);
  padding: 0 var(--gap-4);
  width: 100%;
}

textarea {
  height: auto;
  padding: var(--gap-3) var(--gap-4);
  resize: vertical;
}

input:focus,
select:focus,
textarea:focus {
  border-color: var(--info);
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

.checkbox {
  display: flex;
  align-items: center;
  gap: var(--gap-3);
  height: var(--control);
}

.hint {
  margin: 0;
  font-size: 9px;
  color: var(--text-4);
}
</style>
