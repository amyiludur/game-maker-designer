<script setup lang="ts">
import type { SystemEntity } from '@/stores/system'

/**
 * List and detail over one collection of the system document.
 *
 * Ten of the editor's tabs are the same shape — a list of things with ids on the left, the
 * selected one's fields on the right — so they are one component. What differs between a
 * zone and a win condition is the detail form, which is the slot.
 *
 * Reordering is offered only where order is meaningful. A game's zones are a set; its phases
 * are a sequence, and moving `combat` above `action` changes the game.
 */
withDefaults(
  defineProps<{
    entries: SystemEntity[]
    selected: string | null
    noun: string
    reorderable?: boolean
    removable?: boolean
    empty?: string
  }>(),
  { reorderable: false, removable: true, empty: 'Nothing here yet.' },
)

const emit = defineEmits<{
  select: [id: string]
  add: []
  remove: [id: string]
  move: [from: number, to: number]
}>()
</script>

<template>
  <div class="entity-list">
    <aside class="list">
      <ul>
        <li v-for="(entry, index) in entries" :key="entry.id">
          <button class="row" :class="{ on: entry.id === selected }" @click="emit('select', entry.id)">
            <span class="name">{{ entry.name ?? entry.id }}</span>
            <span class="id mono">{{ entry.id }}</span>
            <slot name="row" :entry="entry" />
          </button>
          <span v-if="reorderable" class="order">
            <button
              class="nudge mono"
              :disabled="index === 0"
              title="Move up"
              @click="emit('move', index, index - 1)"
            >
              ↑
            </button>
            <button
              class="nudge mono"
              :disabled="index === entries.length - 1"
              title="Move down"
              @click="emit('move', index, index + 1)"
            >
              ↓
            </button>
          </span>
        </li>
      </ul>

      <button class="add mono" @click="emit('add')">+ {{ noun }}</button>
    </aside>

    <section class="detail">
      <template v-if="selected !== null">
        <header class="detail-head">
          <slot name="title" />
          <span class="spacer" />
          <button v-if="removable" class="danger mono" @click="emit('remove', selected)">remove</button>
        </header>
        <slot />
      </template>
      <p v-else class="empty muted">{{ empty }}</p>
    </section>
  </div>
</template>

<style scoped>
.entity-list {
  display: flex;
  height: 100%;
  min-height: 0;
}

.list {
  width: 208px;
  flex: 0 0 208px;
  border-right: 1px solid var(--border-subtle);
  padding: var(--gap-5);
  overflow: auto;
}

.list ul {
  list-style: none;
  margin: 0 0 var(--gap-4);
  padding: 0;
}

.list li {
  display: flex;
  align-items: center;
  gap: var(--gap-2);
}

.row {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: baseline;
  gap: var(--gap-3);
  padding: var(--gap-3) var(--gap-4);
  background: none;
  border: 0;
  border-radius: var(--radius-control);
  color: var(--text-2);
  cursor: pointer;
  text-align: left;
  font-size: 11.5px;
}

.row:hover {
  background: var(--surface-4);
}

.row.on {
  background: var(--surface-6);
  color: var(--text-1);
}

.name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.id {
  margin-left: auto;
  font-size: 9px;
  color: var(--text-4);
}

.order {
  display: flex;
  flex-direction: column;
}

.nudge {
  height: 12px;
  width: 14px;
  padding: 0;
  background: none;
  border: 0;
  color: var(--text-4);
  cursor: pointer;
  font-size: 9px;
  line-height: 1;
}

.nudge:hover:not(:disabled) {
  color: var(--text-1);
}

.nudge:disabled {
  opacity: 0.25;
  cursor: default;
}

.add {
  width: 100%;
  height: var(--control);
  background: none;
  border: 1px dashed var(--border-strong);
  border-radius: var(--radius-control);
  color: var(--text-3);
  cursor: pointer;
  font-size: 10.5px;
}

.add:hover {
  border-color: var(--border-hover);
  color: var(--text-1);
}

.detail {
  flex: 1;
  min-width: 0;
  padding: var(--gap-6) var(--gap-7);
  overflow: auto;
}

.detail-head {
  display: flex;
  align-items: center;
  gap: var(--gap-4);
  margin-bottom: var(--gap-6);
}

.spacer {
  flex: 1;
}

.danger {
  height: var(--control-sm);
  padding: 0 var(--gap-4);
  background: none;
  border: 1px solid var(--error-border);
  border-radius: var(--radius-control);
  color: var(--error-text);
  cursor: pointer;
  font-size: 10px;
}

.danger:hover {
  background: var(--error-surface);
}

.empty {
  margin: 0;
  font-size: 11.5px;
}
</style>
