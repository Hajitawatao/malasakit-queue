<template>
  <main v-if="state" class="ws" :style="{ '--agency': agencyColor(state.counter.service.code) }">
    <!-- Top bar -->
    <header class="top">
      <div class="id">
        <img class="seal" :src="'/logos/r1mc.png'" alt="Region 1 Medical Center" />
        <div class="id-text">
          <small>Malasakit Center · Window console</small>
          <strong>{{ state.counter.service.name }} <i>/</i> {{ state.counter.spoken_name || state.counter.name }}</strong>
        </div>
      </div>

      <dl class="stats">
        <div><dt>Served</dt><dd>{{ state.served_today }}</dd></div>
        <div><dt>Avg / patient</dt><dd>{{ state.avg_minutes ? `${state.avg_minutes}m` : '–' }}</dd></div>
        <div><dt>Waiting</dt><dd>{{ totalWaiting }}</dd></div>
        <div :class="{ warn: state.out.length }"><dt>Out</dt><dd>{{ state.out.length }}</dd></div>
      </dl>

      <div class="top-right">
        <span class="live" :class="{ off: offline }">{{ offline ? 'Offline' : 'Live' }}</span>
        <span class="clock">{{ clock }}</span>
        <button class="ghost" type="button" @click="logout">Log out</button>
      </div>
    </header>

    <div class="grid">
      <!-- Current patient -->
      <section class="panel current" :class="state.current?.lane">
        <header class="panel-head">
          <h2>At this window</h2>
        </header>

        <template v-if="state.current">
          <div class="patient">
            <div class="code">{{ state.current.code }}</div>
            <div class="patient-side">
              <span class="lane-tag" :class="state.current.lane">{{ laneLabel[state.current.lane] }}</span>
              <div class="timer">
                <small>{{ state.current.status === 'serving' ? 'Serving for' : 'Called' }}</small>
                <b>{{ elapsed }}</b>
              </div>
              <span v-if="state.current.recall_count" class="recalls">
                Recalled {{ state.current.recall_count }}/{{ state.max_recalls }}
              </span>
            </div>
          </div>

          <ol class="steps">
            <li class="on">Called</li>
            <li :class="{ on: state.current.status === 'serving' }">Arrived</li>
            <li>Done</li>
          </ol>

          <p v-if="state.current.status === 'called' && overdue" class="nudge">
            Not here yet? Recall, or press <kbd>F7</kbd> <b>Out</b>. The number stays on the list and can be called again.
          </p>

          <div class="actions">
            <button type="button" :disabled="busy || recallsUsed" @click="act('recall')">
              <kbd>F1</kbd> Recall
            </button>
            <button type="button" :disabled="busy || state.current.status !== 'called'" @click="act('serve')">
              <kbd>F5</kbd> Arrived
            </button>
            <button type="button" class="primary" :disabled="busy" @click="act('done')">
              <kbd>F6</kbd> Done
            </button>
            <button type="button" class="out" :disabled="busy" @click="act('out')">
              <kbd>F7</kbd> Out when called
            </button>
          </div>
        </template>

        <div v-else class="empty">
          <div class="code idle">– – –</div>
          <p>Window is free.</p>
          <p class="muted" v-if="totalWaiting">Press <kbd>F2</kbd> to call the next patient.</p>
          <p class="muted" v-else>No one is waiting right now.</p>
        </div>
      </section>

      <!-- Calling -->
      <section class="panel calling">
        <button type="button" class="call-next" :disabled="busy || !totalWaiting" @click="callNext()">
          <span><kbd>F2</kbd> Call next</span>
          <small>{{ nextUp ? `Next up: ${nextUp.code}` : 'No one waiting' }}</small>
        </button>

        <div class="lanes">
          <button type="button" class="lane fast" :disabled="busy || !state.waiting.fast" @click="callNext('fast')">
            <span><kbd>F3</kbd> Fast lane</span><b>{{ state.waiting.fast }}</b>
          </button>
          <button type="button" class="lane priority" :disabled="busy || !state.waiting.priority" @click="callNext('priority')">
            <span><kbd>F4</kbd> Priority</span><b>{{ state.waiting.priority }}</b>
          </button>
          <div class="lane regular"><span>Regular</span><b>{{ state.waiting.regular }}</b></div>
        </div>
        <p class="hint">Call next takes turns 2 fast : 1 priority : 1 regular, and finishes the current patient.</p>
      </section>

      <!-- Out when called -->
      <section class="panel outs">
        <header class="panel-head">
          <h2>Out when called <span class="count">{{ state.out.length }}</span></h2>
          <small v-if="state.out.length">Patient came back? <kbd>F8</kbd> calls the newest.</small>
        </header>
        <ul v-if="state.out.length" class="out-list">
          <li v-for="t in state.out" :key="t.id">
            <span class="dot" :class="t.lane"></span>
            <b class="mono">{{ t.code }}</b>
            <span class="muted">{{ t.window }} · {{ ago(t.out_at) }}</span>
            <button type="button" class="small" :disabled="busy" @click="callBack(t)">Call again</button>
          </li>
        </ul>
        <p v-else class="muted pad">Nobody is out. Patients who miss their call appear here.</p>
      </section>

      <!-- Queue -->
      <section class="panel queue">
        <header class="panel-head">
          <h2>Waiting line <span class="count">{{ totalWaiting }}</span></h2>
          <small v-if="totalWaiting > state.queue.length">Showing the first {{ state.queue.length }}</small>
        </header>
        <table v-if="state.queue.length">
          <thead><tr><th>#</th><th>Ticket</th><th>Lane</th><th class="r">Waiting</th></tr></thead>
          <tbody>
            <tr v-for="(t, i) in state.queue" :key="t.id">
              <td class="muted">{{ i + 1 }}</td>
              <td><b class="mono">{{ t.code }}</b></td>
              <td><span class="dot" :class="t.lane"></span>{{ laneLabel[t.lane] }}</td>
              <td class="r mono">{{ ago(t.issued_at, true) }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="muted pad">The line is empty.</p>
      </section>
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

const laneLabel = { fast: 'Fast lane', priority: 'Priority', regular: 'Regular' }

const router = useRouter()
const state = ref(null)
const busy = ref(false)
const offline = ref(false)
const message = ref('')
const messageKind = ref('info')
const loadError = ref('')
const now = ref(Date.now())
let pollTimer, clockTimer, toastTimer

const totalWaiting = computed(() => {
  const w = state.value?.waiting ?? {}
  return (w.fast ?? 0) + (w.priority ?? 0) + (w.regular ?? 0)
})
const nextUp = computed(() => state.value?.queue?.[0] ?? null)
const recallsUsed = computed(() => (state.value?.current?.recall_count ?? 0) >= (state.value?.max_recalls ?? 3))

const clock = computed(() =>
  new Date(now.value).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', second: '2-digit' }),
)

const elapsedSecs = computed(() => {
  const c = state.value?.current
  if (!c?.called_at) return 0
  return Math.max(0, Math.floor((now.value - new Date(c.called_at).getTime()) / 1000))
})
const elapsed = computed(() => {
  const s = elapsedSecs.value
  return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`
})
// After 2 minutes of "called" with no arrival, suggest Out
const overdue = computed(() => elapsedSecs.value >= 120)

function ago(iso, short = false) {
  if (!iso) return ''
  const m = Math.max(0, Math.floor((now.value - new Date(iso).getTime()) / 60000))
  if (short) return m < 60 ? `${m}m` : `${Math.floor(m / 60)}h ${m % 60}m`
  return m === 0 ? 'just now' : `${m} min ago`
}

function toast(text, kind = 'info') {
  message.value = text
  messageKind.value = kind
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => (message.value = ''), 4000)
}

async function load() {
  try {
    state.value = (await api.get('/counter/state')).data
    offline.value = false
  } catch (e) {
    if (!e?.response) offline.value = true
    throw e
  }
}

async function run(fn, okText) {
  if (busy.value) return
  busy.value = true
  try {
    state.value = (await fn()).data
    if (okText) toast(okText)
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
const act = (action) => {
  const code = state.value.current.code
  run(() => api.post(`/counter/tickets/${state.value.current.id}/${action}`),
    action === 'out' ? `${code} moved to Out when called.` : null)
}
const callBack = (t) => run(() => api.post(`/counter/tickets/${t.id}/call-back`), `Calling ${t.code} again.`)

async function logout() {
  await api.post('/logout').catch(() => {})
  router.push('/login')
}

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
    F7: () => c && act('out'),
    F8: () => state.value.out[0] && callBack(state.value.out[0]),
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
  clockTimer = setInterval(() => (now.value = Date.now()), 1000)
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
.ws {
  --bg: #eef1f6;
  --panel: #fff;
  --line: #dde3ec;
  --mute: #607089;
  --mono: 'Barlow Condensed', ui-monospace, monospace;

  min-height: 100vh;
  background: var(--bg);
  color: var(--ink);
  display: flex;
  flex-direction: column;
}

/* ---------- top bar ---------- */
.top {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 24px;
  padding: 10px 24px;
  background: var(--r1-navy);
  color: #fff;
  border-bottom: 4px solid var(--agency);
}
.id { display: flex; align-items: center; gap: 12px; }
.seal { width: 42px; height: 42px; border-radius: 50%; background: #fff; padding: 1px; }
.id-text { display: flex; flex-direction: column; line-height: 1.1; }
.id-text small { font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted-on-dark); }
.id-text strong { font: 700 26px/1.1 var(--display); }
.id-text i { font-style: normal; color: var(--muted-on-dark); margin: 0 4px; }

.stats { display: flex; justify-content: center; gap: 4px; margin: 0; }
.stats div {
  min-width: 96px;
  padding: 6px 14px;
  border-left: 1px solid rgba(255, 255, 255, 0.14);
}
.stats div:first-child { border-left: 0; }
.stats dt { font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted-on-dark); }
.stats dd { margin: 0; font: 700 28px/1 var(--display); font-variant-numeric: tabular-nums; }
.stats .warn dd { color: var(--r1-gold); }

.top-right { display: flex; align-items: center; gap: 16px; }
.live { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; }
.live::before { content: ''; width: 9px; height: 9px; border-radius: 50%; background: var(--r1-green); box-shadow: 0 0 0 0 rgba(122, 181, 29, 0.6); animation: ping 2s infinite; }
.live.off { color: #ffb4b4; }
.live.off::before { background: var(--r1-red); animation: none; }
@keyframes ping { 70% { box-shadow: 0 0 0 7px rgba(122, 181, 29, 0); } 100% { box-shadow: 0 0 0 0 rgba(122, 181, 29, 0); } }
.clock { font: 600 22px/1 var(--display); font-variant-numeric: tabular-nums; min-width: 110px; text-align: right; }
.ghost { font: 600 14px var(--text); padding: 8px 14px; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.4); background: transparent; color: #fff; cursor: pointer; }
.ghost:hover { background: rgba(255, 255, 255, 0.08); }

/* ---------- layout ---------- */
.grid {
  flex: 1;
  display: grid;
  grid-template-columns: minmax(0, 1.55fr) minmax(340px, 1fr);
  grid-template-areas: 'current calling' 'current outs' 'queue outs';
  grid-template-rows: auto 1fr auto;
  gap: 16px;
  padding: 16px 24px 24px;
}
.current { grid-area: current; }
.calling { grid-area: calling; }
.outs { grid-area: outs; }
.queue { grid-area: queue; }
@media (max-width: 1000px) {
  .grid { grid-template-columns: 1fr; grid-template-areas: 'current' 'calling' 'outs' 'queue'; grid-template-rows: auto; }
  .stats { display: none; }
  .top { grid-template-columns: 1fr auto; }
}

.panel { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 18px 20px; min-width: 0; }
.panel-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.panel-head h2 { margin: 0; font-size: 13px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--mute); display: flex; align-items: center; gap: 8px; }
.panel-head small { font-size: 13px; color: var(--mute); }
.count { font: 700 15px/1 var(--display); padding: 3px 8px; border-radius: 999px; background: var(--bg); color: var(--ink); letter-spacing: 0; }
.badge { width: 34px; }
.muted { color: var(--mute); }
.pad { margin: 6px 0; font-size: 15px; }
.mono { font-family: var(--mono); font-variant-numeric: tabular-nums; letter-spacing: 0.02em; }

/* ---------- current ---------- */
.current { border-top: 6px solid var(--regular); display: flex; flex-direction: column; }
.current.fast { border-top-color: var(--fast); }
.current.priority { border-top-color: var(--priority); }

.patient { display: flex; align-items: flex-end; gap: 28px; flex-wrap: wrap; }
.code { font: 700 clamp(110px, 13vw, 190px)/0.85 var(--display); color: var(--agency); font-variant-numeric: tabular-nums; letter-spacing: -0.01em; }
.code.idle { color: #c7d0dd; }
.patient-side { display: flex; flex-direction: column; gap: 10px; padding-bottom: 8px; }
.lane-tag { align-self: flex-start; font-size: 14px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; padding: 4px 10px; border-radius: 6px; background: var(--regular); color: #fff; }
.lane-tag.fast { background: var(--fast); }
.lane-tag.priority { background: var(--priority); }
.timer small { display: block; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--mute); }
.timer b { font: 700 44px/1 var(--display); font-variant-numeric: tabular-nums; }
.recalls { font-size: 14px; font-weight: 600; color: var(--priority); }

.steps { list-style: none; display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin: 22px 0 0; padding: 0; counter-reset: s; }
.steps li { counter-increment: s; font-size: 13px; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: var(--mute); padding-top: 8px; border-top: 4px solid var(--line); }
.steps li::before { content: counter(s) ' · '; }
.steps li.on { color: var(--ink); border-top-color: var(--agency); }

.nudge { margin: 16px 0 0; padding: 10px 14px; border-radius: 8px; background: #fff6e5; border: 1px solid #f6d79a; font-size: 15px; color: #6b4a07; }

.actions { margin-top: auto; padding-top: 22px; display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
@media (max-width: 1250px) { .actions { grid-template-columns: repeat(2, 1fr); } }
.actions button { padding: 16px 10px; font-size: 17px; font-weight: 600; border-radius: 10px; border: 1px solid var(--line); background: var(--bg); color: var(--ink); cursor: pointer; }
.actions button:hover:not(:disabled) { border-color: #b9c4d4; }
.actions .primary { background: var(--ink); border-color: var(--ink); color: #fff; }
.actions .primary kbd { background: rgba(255, 255, 255, 0.15); border-color: rgba(255, 255, 255, 0.3); }
.actions .out { background: #fff; border-color: var(--priority); color: #9a5a00; }

.empty { padding: 8px 0; font-size: 21px; }
.empty p { margin: 8px 0 0; }

kbd { display: inline-block; min-width: 28px; padding: 1px 6px; margin-right: 6px; border-radius: 5px; font: 600 12px var(--text); text-align: center; background: rgba(14, 34, 56, 0.07); border: 1px solid rgba(14, 34, 56, 0.16); vertical-align: 1px; }

/* ---------- calling ---------- */
.calling { display: flex; flex-direction: column; gap: 12px; }
.call-next { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; padding: 22px 24px; border: 0; border-radius: 10px; background: var(--agency); color: #fff; cursor: pointer; text-align: left; }
.call-next:hover:not(:disabled) { filter: brightness(1.06); }
.call-next span { font: 700 40px/1 var(--display); }
.call-next small { font-size: 17px; opacity: 0.92; }
.call-next kbd { background: rgba(255, 255, 255, 0.18); border-color: rgba(255, 255, 255, 0.35); color: #fff; vertical-align: 6px; }
.lanes { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.lane { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; padding: 12px 14px; border-radius: 10px; border: 1px solid var(--line); border-top: 4px solid var(--regular); background: #fff; color: var(--ink); font-size: 14px; font-weight: 600; text-align: left; }
button.lane { cursor: pointer; }
button.lane:hover:not(:disabled) { background: var(--bg); }
.lane.fast { border-top-color: var(--fast); }
.lane.priority { border-top-color: var(--priority); }
.lane.regular { color: var(--mute); }
.lane b { font: 700 32px/1 var(--display); color: var(--ink); }
.hint { margin: 0; font-size: 13px; color: var(--mute); line-height: 1.4; }

/* ---------- lists ---------- */
.dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; background: var(--regular); margin-right: 8px; vertical-align: 1px; }
.dot.fast { background: var(--fast); }
.dot.priority { background: var(--priority); }

.out-list { list-style: none; margin: 0; padding: 0; max-height: 46vh; overflow: auto; }
.out-list li { display: grid; grid-template-columns: auto auto 1fr auto; align-items: center; gap: 4px 6px; padding: 10px 2px; border-top: 1px solid var(--line); }
.out-list li:first-child { border-top: 0; }
.out-list b { font-size: 26px; margin-right: 10px; }
.out-list .muted { font-size: 14px; }
.small { font: 600 14px var(--text); padding: 8px 12px; border-radius: 8px; border: 1px solid var(--agency); background: #fff; color: var(--agency); cursor: pointer; }
.small:hover:not(:disabled) { background: var(--agency); color: #fff; }

table { width: 100%; border-collapse: collapse; font-size: 15px; }
th { text-align: left; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--mute); font-weight: 700; padding: 0 8px 8px; border-bottom: 1px solid var(--line); }
td { padding: 8px; border-bottom: 1px solid #edf1f6; }
td b { font-size: 21px; }
tbody tr:first-child { background: #f6f9fc; }
.r { text-align: right; }

button:disabled { opacity: 0.4; cursor: not-allowed; }
button:focus-visible { outline: 3px solid var(--r1-gold); outline-offset: 2px; }

.toast { position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%); margin: 0; padding: 12px 20px; border-radius: 10px; font-size: 16px; font-weight: 600; background: var(--ink); color: #fff; box-shadow: 0 6px 20px rgba(14, 34, 56, 0.25); }
.toast.error { background: var(--fast); }

.loading { min-height: 100vh; display: grid; place-items: center; font: 500 20px var(--text); color: #5b6f86; }
</style>
