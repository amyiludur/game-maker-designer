import { createRouter, createWebHistory } from 'vue-router'

/**
 * Routes, per doc 11.
 *
 * The play table and the card editor are separate chunks: the table pulls in the animation
 * and board code, and someone browsing cards should not pay for it.
 */
export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', name: 'dashboard', component: () => import('@/views/DashboardView.vue') },
    {
      path: '/g/:game/cards',
      name: 'cards',
      component: () => import('@/views/CardBrowserView.vue'),
      props: true,
    },
    {
      path: '/g/:game/cards/:card',
      name: 'card',
      component: () => import('@/views/CardEditorView.vue'),
      props: true,
    },
    {
      path: '/g/:game/decks',
      name: 'decks',
      component: () => import('@/views/DeckBuilderView.vue'),
      props: true,
    },
    {
      path: '/g/:game/play',
      name: 'play',
      component: () => import('@/views/PlaySetupView.vue'),
      props: true,
    },
    {
      path: '/m/:match',
      name: 'match',
      component: () => import('@/views/PlayTableView.vue'),
      // Named apart from the store the view holds, which is also called `match`.
      props: (route) => ({ matchId: route.params.match }),
    },
  ],
})
