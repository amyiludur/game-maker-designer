<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'

import LeftRail from './LeftRail.vue'
import TopBar from './TopBar.vue'

const route = useRoute()

// The play table takes the whole window: a board is the one screen where chrome competes
// with the thing you are looking at.
const bare = computed(() => route.name === 'match')
</script>

<template>
  <div v-if="bare" class="bare"><slot /></div>
  <div v-else class="shell">
    <TopBar />
    <div class="body">
      <LeftRail />
      <main class="content"><slot /></main>
    </div>
  </div>
</template>

<style scoped>
.shell {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: var(--surface-2);
}

.body {
  display: flex;
  flex: 1;
  min-height: 0;
}

.content {
  flex: 1;
  min-width: 0;
  overflow: auto;
}

.bare {
  height: 100%;
}
</style>
