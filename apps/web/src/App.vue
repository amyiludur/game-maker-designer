<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'

import AppShell from '@/components/shell/AppShell.vue'
import { useGameStore } from '@/stores/game'

const route = useRoute()
const games = useGameStore()

const slug = computed(() => (route.params.game as string | undefined) ?? null)

onMounted(() => void games.loadGames())

watch(
  slug,
  (value) => {
    if (value !== null) void games.load(value)
  },
  { immediate: true },
)
</script>

<template>
  <AppShell>
    <RouterView />
  </AppShell>
</template>
