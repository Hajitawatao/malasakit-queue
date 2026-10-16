<template>
  <main class="counter" v-if="state">
    <header>
      <div>
        <h1>{{ state.counter.service.name }}</h1>
        <span>{{ state.counter.name }}</span>
      </div>
      <button class="logout" @click="logout">Logout</button>
    </header>

    <section class="now">
      <div class="label">NOW SERVING</div>
      <div class="code">{{ state.current?.code ?? '—' }}</div>
      <div v-if="state.current" class="sub">
        {{ laneLabel[state.current.lane] }} · {{ state.current.status === 'serving' ? 'Ini-interview' : 'Tinawag' }}
        · Recall {{ state.current.recall_count }}/{{ state.max_recalls }}
      </div>
    </section>

    <section class="actions">
      <button class="primary" :disabled="busy" @click="callNext()">
        F2 · Call Next
      </button>
      <button class="fast" :disabled="busy || !state.waiting.fast" @click="callNext('fast')">
        F3 · Fast Lane ({{ state.waiting.fast }})
      </button>
      <button class="priority" :disabled="busy || !state.waiting.priority" @click="callNext('priority')">
        F4 · Priority ({{ state.waiting.priority }})
      </button>
      <button :disabled="busy || !state.current" @click="act('recall')">F1 · Recall</button>
      <button :disabled="busy || state.current?.status !== 'called'" @click="act('serve')">F5 · Dumating na</button>
      <button :disabled="busy || !state.current" @click="act('done')">F6 · Tapos</button>
      <button class="danger" :disabled="busy || !state.current" @click="act('no-show')">F7 · No-show</button>
    </section>

    <footer>
      <span>Regular: <b>{{ state.waiting.regular }}</b></span>
      <span>Fast Lane: <b>{{ state.waiting.fast }}</b></span>
      <span>Priority: <b>{{ state.waiting.priority }}</b></span>
      <span>Na-serve ngayon: <b>{{ state.served_today }}</b></span>
    </footer>

    <p v-if="message" class="msg">{{ message }}</p>
  </main>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, ensureCsrf, errorMessage } from '../lib/api'

const router = useRouter()
const state = ref(null)
const busy = ref(false)
const message = ref('')
const laneLabel = { fast: 'Fast Lane', priority: 'Priority', regular: 'Regular' }
let pollTimer

async function load() {
  state.value = (await api.get('/counter/state')).data
}

async function run(fn) {
  busy.value = true
  message.value = ''
  try {
    state.value = (await fn()).data
  } catch (e) {
    message.value = errorMessage(e)
    await load()
  } finally {
    busy.value = false
  }
}

const callNext = (lane = null) => run(() => api.post('/counter/call-next', { lane }))
const act = (action) => run(() => api.post(`/counter/tickets/${state.value.current.id}/${action}`))

async function logout() {
  await api.post('/logout')
  router.push('/login')
}

// Keyboard shortcuts, gaya ng lumang system (F1 Recall, F2 Call Next, F3 Fast Lane)
function onKey(e) {
  if (busy.value || !state.value) return
  const map = {
    F1: () => state.value.current && act('recall'),
    F2: () => callNext(),
    F3: () => callNext('fast'),
    F4: () => callNext('priority'),
    F5: () => state.value.current?.status === 'called' && act('serve'),
    F6: () => state.value.current && act('done'),
    F7: () => state.value.current && act('no-show'),
  }
  if (map[e.key]) {
    e.preventDefault()
    map[e.key]()
  }
}

onMounted(async () => {
  await ensureCsrf()
  await load()
  // live na bilang ng naghihintay (bawat 3 segundo)
  pollTimer = setInterval(() => { if (!busy.value) load().catch(() => {}) }, 3000)
  window.addEventListener('keydown', onKey)
})

onUnmounted(() => {
  window.removeEventListener('keydown', onKey)
  clearInterval(pollTimer)
})
</script>

<style scoped>
.counter { min-height: 100vh; background: #f4f7fb; font-family: system-ui, sans-serif; padding: 20px; display: flex; flex-direction: column; gap: 16px; }
header { display: flex; justify-content: space-between; align-items: center; }
header h1 { margin: 0; color: #0b3d91; font-size: 28px; }
header span { color: #567; }
.logout { background: none; border: 1px solid #99a; padding: 8px 14px; border-radius: 8px; }
.now { background: #fff; border-radius: 16px; padding: 20px; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,.05); }
.label { letter-spacing: 3px; color: #789; }
.code { font-size: 110px; font-weight: 900; color: #0b3d91; line-height: 1.1; }
.sub { color: #567; font-size: 18px; }
.actions { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
.actions button { padding: 18px; font-size: 18px; font-weight: 700; border-radius: 12px; border: 1px solid #c5d3e6; background: #fff; cursor: pointer; }
.actions button:disabled { opacity: .45; cursor: not-allowed; }
.actions .primary { background: #fdd835; border-color: #f9a825; grid-column: 1 / -1; font-size: 26px; }
.actions .fast { background: #ffebee; border-color: #e57373; }
.actions .priority { background: #fff3e0; border-color: #ffb74d; }
.actions .danger { color: #c62828; }
footer { display: flex; gap: 24px; flex-wrap: wrap; color: #456; font-size: 16px; }
.msg { color: #c62828; font-size: 18px; }
</style>
