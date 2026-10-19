<template>
  <main v-if="state" class="desk" :style="{ '--agency': agencyColor(state.counter.service.code) }">
    <header class="bar">
      <div class="who">
        <AgencyBadge class="badge" :code="state.counter.service.code" :name="state.counter.service.name" />
        <h1>{{ state.counter.service.name }}</h1>
        <span>{{ state.counter.spoken_name || state.counter.name }}</span>
      </div>
      <div class="bar-right">
        <img class="seal" :src="'/logos/r1mc.png'" alt="Region 1 Medical Center" />
        <span class="served"><strong>{{ state.served_today }}</strong> served today</span>
        <button class="logout" type="button" @click="logout">Log out</button>
      </div>
    </header>

    <div class="layout">
      <!-- Current patient -->
      <section class="current" :class="state.current?.lane">
        <p class="caption">Now serving</p>

        <template v-if="state.current">
          <div class="code">{{ state.current.code }}</div>
          <p class="meta">
            <span class="lane-tag">{{ laneLabel[state.current.lane] }}</span>
            <span>{{ statusText }}</span>
            <span v-if="state.current.recall_count">Recalled {{ state.current.recall_count }} of {{ state.max_recalls }}</span>
          </p>

          <div class="current-actions">
            <button type="button" :disabled="busy || recallsUsed" @click="act('recall')">
              <kbd>F1</kbd> Recall
            </button>
            <button type="button" :disabled="busy || state.current.status !== 'called'" @click="act('serve')">
              <kbd>F5</kbd> Patient arrived
            </button>
            <button type="button" class="done" :disabled="busy" @click="act('done')">
              <kbd>F6</kbd> Done
            </button>
            <button type="button" class="noshow" :disabled="busy" @click="act('no-show')">
              <kbd>F7</kbd> No-show
            </button>
          </div>
        </template>

        <div v-else class="empty">
          <p>No patient at this window.</p>
          <p v-if="totalWaiting">Press <kbd>F2</kbd> to call the next patient.</p>
          <p v-else>No one is waiting right now.</p>
        </div>
      </section>

      <!-- Calling -->
      <aside class="calling">
        <button type="button" class="call-next" :disabled="busy || !totalWaiting" @click="callNext()">
          <span><kbd>F2</kbd> Call next</span>
          <small>{{ totalWaiting ? `${totalWaiting} waiting` : 'No one waiting' }}</small>
        </button>

        <div class="lane-calls">
          <button type="button" class="lane fast" :disabled="busy || !state.waiting.fast" @click="callNext('fast')">
            <span><kbd>F3</kbd> Call fast lane</span>
            <b>{{ state.waiting.fast }}</b>
          </button>
          <button type="button" class="lane priority" :disabled="busy || !state.waiting.priority" @click="callNext('priority')">
            <span><kbd>F4</kbd> Call priority</span>
            <b>{{ state.waiting.priority }}</b>
          </button>
          <div class="lane regular">
            <span>Regular waiting</span>
            <b>{{ state.waiting.regular }}</b>
          </div>
        </div>

        <p class="hint">
          Call next takes turns: 2 fast lane, 1 priority, 1 regular. Calling the next patient finishes the current one.
        </p>
      </aside>
    </div>

    <p v-if="message" class="toast" :class="messageKind" role="status">{{ message }}</p>
  </main>

  <main v-else class="loading">{{ loadError || 'Loading…' }}</main>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, ensureCsrf, errorMessage } from '../lib/api'
import { agencyColor } from '../lib/agency'
import AgencyBadge from '../components/AgencyBadge.vue'

const laneLabel = { fast: 'Fast lane', priority: 'Priority', regular: 'Regular' }

const router = useRouter()
const state = ref(null)
const busy = ref(false)
const message = ref('')
const messageKind = ref('info')
const loadError = ref('')
const now = ref(Date.now())
let pollTimer, clockTimer, toastTimer


const totalWaiting = computed(() => {
  const w = state.value?.waiting ?? {}
  return (w.fast ?? 0) + (w.priority ?? 0) + (w.regular ?? 0)
})
const recallsUsed = computed(() => (state.value?.current?.recall_count ?? 0) >= (state.value?.max_recalls ?? 3))

const statusText = computed(() => {
  const c = state.value?.current
  if (!c?.called_at) return ''
  const mins = Math.max(0, Math.floor((now.value - new Date(c.called_at).getTime()) / 60000))
  const ago = mins === 0 ? 'just now' : `${mins} min ago`
  return c.status === 'serving' ? `Serving, called ${ago}` : `Called ${ago}, waiting to arrive`
})

function toast(text, kind = 'info') {
  message.value = text
  messageKind.value = kind
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => (message.value = ''), 4000)
}

async function load() {
  state.value = (await api.get('/counter/state')).data
}

async function run(fn) {
  if (busy.value) return
  busy.value = true
  try {
    state.value = (await fn()).data
  } catch (e) {
    const status = e?.response?.status
    if (status === 401 || status === 419) return router.push('/login')
    toast(e?.response?.data?.errors?.ticket?.[0] || errorMessage(e), status === 404 ? 'info' : 'error')
    await load().catch(() => {})
  } finally {
    busy.value = false
  }
}

const callNext = (lane = null) => run(() => api.post('/counter/call-next', { lane }))
const act = (action) => run(() => api.post(`/counter/tickets/${state.value.current.id}/${action}`))

async function logout() {
  await api.post('/logout').catch(() => {})
  router.push('/login')
}

// Keyboard shortcuts (same keys as the old system)
function onKey(e) {
  if (!state.value || busy.value) return
  const c = state.value.current
  const map = {
    F1: () => c && !recallsUsed.value && act('recall'),
    F2: () => totalWaiting.value && callNext(),
    F3: () => state.value.waiting.fast && callNext('fast'),
    F4: () => state.value.waiting.priority && callNext('priority'),
    F5: () => c?.status === 'called' && act('serve'),
    F6: () => c && act('done'),
    F7: () => c && act('no-show'),
  }
  if (map[e.key]) {
    e.preventDefault()
    map[e.key]()
  }
}

onMounted(async () => {
  try {
    await ensureCsrf()
    await load()
  } catch (e) {
    if ([401, 419].includes(e?.response?.status)) return router.push('/login')
    loadError.value = errorMessage(e)
  }
  pollTimer = setInterval(() => { if (!busy.value) load().catch(() => {}) }, 3000)
  clockTimer = setInterval(() => (now.value = Date.now()), 15000)
  window.addEventListener('keydown', onKey)
})

onUnmounted(() => {
  window.removeEventListener('keydown', onKey)
  clearInterval(pollTimer)
  clearInterval(clockTimer)
  clearTimeout(toastTimer)
})
</script>

<style scoped>
.desk {
  --soft: var(--paper);

  min-height: 100vh;
  background: var(--soft);
  color: var(--ink);
  font-family: var(--text);
  display: flex;
  flex-direction: column;
}

.bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px 28px;
  background: var(--agency);
  color: #fff;
}
.who { display: flex; align-items: center; gap: 14px; }
.badge { width: 46px; }
.who h1 { margin: 0; font: 700 34px/1 var(--display); }
.who span { font-size: 20px; font-weight: 600; opacity: 0.9; }
.bar-right { display: flex; align-items: center; gap: 20px; }
.seal { width: 40px; height: 40px; border-radius: 50%; background: #fff; padding: 1px; order: 3; }
.served { font-size: 16px; opacity: 0.9; }
.served strong { font-size: 20px; }
.logout {
  font: 600 14px 'Barlow', sans-serif;
  padding: 8px 14px;
  border-radius: 8px;
  border: 1px solid rgba(255, 255, 255, 0.6);
  background: transparent;
  color: #fff;
  cursor: pointer;
}

.layout {
  flex: 1;
  display: grid;
  grid-template-columns: minmax(0, 1.6fr) minmax(320px, 1fr);
  gap: 20px;
  padding: 20px 28px 28px;
  align-items: start;
}
@media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }

.caption { margin: 0; font-size: 16px; font-weight: 600; color: var(--muted); }

/* current patient */
.current {
  background: #fff;
  border-radius: 14px;
  border: 1px solid var(--line);
  border-left: 10px solid var(--regular);
  padding: 22px 28px;
  display: flex;
  flex-direction: column;
}
.current.fast { border-left-color: var(--fast); }
.current.priority { border-left-color: var(--priority); }
.code {
  font: 700 clamp(96px, 12vw, 170px)/0.95 var(--display);
  color: var(--agency);
  font-variant-numeric: tabular-nums;
  margin-top: 8px;
}
.meta { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin: 10px 0 0; font-size: 18px; color: var(--muted); }
.lane-tag {
  font-size: 15px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 6px;
  background: var(--soft);
  color: var(--ink);
}
.current.fast .lane-tag { background: var(--fast); color: #fff; }
.current.priority .lane-tag { background: var(--priority); color: #fff; }

.current-actions {
  margin-top: 32px;
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
}
@media (max-width: 1100px) { .current-actions { grid-template-columns: repeat(2, 1fr); } }

button { font-family: inherit; }
kbd {
  display: inline-block;
  min-width: 30px;
  padding: 2px 6px;
  margin-right: 6px;
  border-radius: 5px;
  font: 600 13px 'Barlow', sans-serif;
  text-align: center;
  background: rgba(14, 34, 56, 0.08);
  border: 1px solid rgba(14, 34, 56, 0.15);
}
.current-actions button {
  padding: 16px 12px;
  font-size: 18px;
  font-weight: 600;
  border-radius: 10px;
  border: 1px solid var(--line);
  background: var(--soft);
  color: var(--ink);
  cursor: pointer;
}
.current-actions .done { background: var(--ink); border-color: var(--ink); color: #fff; }
.current-actions .done kbd { background: rgba(255, 255, 255, 0.15); border-color: rgba(255, 255, 255, 0.3); }
.current-actions .noshow { color: var(--fast); }

.empty { padding: 28px 0 8px; font-size: 22px; color: var(--muted); }
.empty p { margin: 6px 0; }

/* calling */
.calling { display: flex; flex-direction: column; gap: 14px; }
.call-next {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
  padding: 26px 28px;
  border: 0;
  border-radius: 14px;
  background: var(--agency);
  color: #fff;
  cursor: pointer;
  text-align: left;
}
.call-next span { font: 700 40px/1 var(--display); }
.call-next small { font-size: 18px; opacity: 0.9; }
.call-next kbd { background: rgba(255, 255, 255, 0.18); border-color: rgba(255, 255, 255, 0.35); color: #fff; vertical-align: middle; }

.lane-calls { display: flex; flex-direction: column; gap: 10px; }
.lane {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px 18px;
  border-radius: 10px;
  border: 1px solid var(--line);
  border-left: 6px solid var(--regular);
  background: #fff;
  color: var(--ink);
  font-size: 18px;
  font-weight: 600;
}
button.lane { cursor: pointer; }
.lane.fast { border-left-color: var(--fast); }
.lane.priority { border-left-color: var(--priority); }
.lane b { font: 700 28px/1 var(--display); }
.lane.regular { color: var(--muted); }
.hint { margin: 4px 2px 0; font-size: 14px; color: var(--muted); line-height: 1.4; }

button:disabled { opacity: 0.4; cursor: not-allowed; }
button:focus-visible { outline: 3px solid var(--agency); outline-offset: 2px; }

.toast {
  position: fixed;
  left: 50%;
  bottom: 24px;
  transform: translateX(-50%);
  margin: 0;
  padding: 12px 20px;
  border-radius: 10px;
  font-size: 17px;
  font-weight: 600;
  background: var(--ink);
  color: #fff;
  box-shadow: 0 6px 20px rgba(14, 34, 56, 0.25);
}
.toast.error { background: var(--fast); }

.loading { min-height: 100vh; display: grid; place-items: center; font: 500 20px 'Barlow', sans-serif; color: #5B6F86; }
</style>
