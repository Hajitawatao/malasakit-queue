import { createRouter, createWebHistory } from 'vue-router'
import { api } from './lib/api'

const routes = [
  { path: '/', redirect: '/kiosk' },
  { path: '/kiosk', component: () => import('./pages/KioskPage.vue') },      // touchscreen
  { path: '/display', component: () => import('./pages/DisplayPage.vue') },  // TV
  { path: '/login', component: () => import('./pages/LoginPage.vue') },
  { path: '/counter', component: () => import('./pages/CounterPage.vue'), meta: { auth: true } }, // PC ng staff
]

const router = createRouter({ history: createWebHistory(), routes })

router.beforeEach(async (to) => {
  if (!to.meta.auth) return true
  try {
    await api.get('/me')
    return true
  } catch {
    return '/login'
  }
})

export default router
