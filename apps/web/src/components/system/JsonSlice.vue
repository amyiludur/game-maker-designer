<script setup lang="ts">
import { ref, watch } from 'vue'

/**
 * The JSON behind whatever tab you are standing on, editable.
 *
 * Every tab has one (doc 12), and it is the same object the form above it is editing — not a
 * second representation that has to be kept in step. A designer who knows the format goes
 * faster here, and everything the form editors do not cover yet is reachable rather than
 * locked away.
 *
 * Invalid text is kept, not discarded: the document is only updated while the text parses,
 * so a half-typed edit costs you nothing but the indicator turning red.
 */
const props = defineProps<{ value: unknown; readonly?: boolean }>()
const emit = defineEmits<{ update: [value: unknown] }>()

const text = ref(render(props.value))
const valid = ref(true)

watch(
  () => props.value,
  (next) => {
    // Only when the document really differs from what this text says, or every keystroke
    // would be reformatted under the cursor.
    if (!same(next, text.value)) {
      text.value = render(next)
      valid.value = true
    }
  },
  { deep: true },
)

function render(value: unknown): string {
  return JSON.stringify(value ?? null, null, 2)
}

function same(value: unknown, source: string): boolean {
  try {
    return JSON.stringify(JSON.parse(source)) === JSON.stringify(value)
  } catch {
    return false
  }
}

function onInput(event: Event): void {
  text.value = (event.target as HTMLTextAreaElement).value
  try {
    const parsed = JSON.parse(text.value)
    valid.value = true
    emit('update', parsed)
  } catch {
    valid.value = false
  }
}
</script>

<template>
  <div class="json">
    <header>
      <span class="mono muted">JSON</span>
      <span class="spacer" />
      <!-- A glyph as well as a colour: validity is never colour alone. -->
      <span class="state mono" :class="valid ? 'ok' : 'bad'">
        {{ valid ? '✓ parses' : '◆ not valid JSON — nothing is being applied' }}
      </span>
    </header>
    <textarea
      :value="text"
      :readonly="readonly"
      spellcheck="false"
      class="mono"
      :class="{ bad: !valid }"
      @input="onInput"
    />
  </div>
</template>

<style scoped>
.json {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

header {
  display: flex;
  align-items: center;
  gap: var(--gap-4);
  height: 24px;
  font-size: 9.5px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.spacer {
  flex: 1;
}

.state.ok {
  color: var(--ok-text);
}

.state.bad {
  color: var(--error-text);
}

textarea {
  flex: 1;
  min-height: 240px;
  width: 100%;
  background: var(--surface-0);
  border: 1px solid var(--border-default);
  border-radius: var(--radius-control);
  padding: var(--gap-4);
  color: var(--text-2);
  font-size: 11px;
  line-height: 1.5;
  resize: vertical;
  tab-size: 2;
}

textarea:focus {
  outline: none;
  border-color: var(--info);
}

textarea.bad {
  border-color: var(--error-border);
}
</style>
