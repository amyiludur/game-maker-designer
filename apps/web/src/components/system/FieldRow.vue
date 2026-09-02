<script setup lang="ts">
/**
 * One labelled control in a system-editor detail panel.
 *
 * Every tab is a form over a slice of the same document, so the label, the hint and the
 * spacing are decided once here rather than fourteen times — and a field's constraint sits
 * beside its label, where the card editor already puts it.
 */
withDefaults(
  defineProps<{
    label: string
    hint?: string
    type?: 'text' | 'number' | 'textarea' | 'select' | 'checkbox'
    options?: { value: string; label: string }[]
    placeholder?: string
    min?: number
    max?: number
    disabled?: boolean
  }>(),
  {
    type: 'text',
    hint: undefined,
    options: () => [],
    placeholder: '',
    min: undefined,
    max: undefined,
    disabled: false,
  },
)

const model = defineModel<string | number | boolean | null | undefined>()
</script>

<template>
  <label class="field">
    <span class="label">
      {{ label }}
      <span v-if="hint" class="hint mono">{{ hint }}</span>
    </span>

    <input
      v-if="type === 'number'"
      type="number"
      :min="min"
      :max="max"
      :disabled="disabled"
      :value="model ?? ''"
      @input="
        model =
          ($event.target as HTMLInputElement).value === ''
            ? null
            : ($event.target as HTMLInputElement).valueAsNumber
      "
    />

    <textarea
      v-else-if="type === 'textarea'"
      rows="3"
      :placeholder="placeholder"
      :disabled="disabled"
      :value="(model as string) ?? ''"
      @input="model = ($event.target as HTMLTextAreaElement).value"
    />

    <select
      v-else-if="type === 'select'"
      :disabled="disabled"
      :value="(model as string) ?? ''"
      @change="model = ($event.target as HTMLSelectElement).value"
    >
      <option v-for="option in options" :key="option.value" :value="option.value">
        {{ option.label }}
      </option>
    </select>

    <span v-else-if="type === 'checkbox'" class="checkbox">
      <input
        type="checkbox"
        :disabled="disabled"
        :checked="Boolean(model)"
        @change="model = ($event.target as HTMLInputElement).checked"
      />
      <span class="muted">{{ placeholder || 'yes' }}</span>
    </span>

    <input
      v-else
      type="text"
      :placeholder="placeholder"
      :disabled="disabled"
      :value="(model as string) ?? ''"
      @input="model = ($event.target as HTMLInputElement).value"
    />
  </label>
</template>

<style scoped>
.field {
  display: flex;
  flex-direction: column;
  gap: var(--gap-3);
  min-width: 0;
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

input[type='text'],
input[type='number'],
select,
textarea {
  height: var(--control);
  width: 100%;
  background: var(--surface-0);
  border: 1px solid var(--border-default);
  border-radius: var(--radius-control);
  padding: 0 var(--gap-4);
  color: inherit;
  font: inherit;
}

textarea {
  height: auto;
  padding: var(--gap-3) var(--gap-4);
  resize: vertical;
}

input:focus,
select:focus,
textarea:focus {
  outline: none;
  border-color: var(--info);
}

input:disabled,
select:disabled,
textarea:disabled {
  opacity: 0.55;
}

.checkbox {
  display: flex;
  align-items: center;
  gap: var(--gap-3);
  height: var(--control);
}
</style>
