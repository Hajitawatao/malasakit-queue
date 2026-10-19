<template>
  <main class="kiosk" @pointerdown="touched">
    <header class="top">
      <img class="seal" :src="'/logos/r1mc.png'" alt="" @error="hideOnError" />
      <div class="org">
        <span class="hospital">Region 1 Medical Center</span>
        <span class="center">Malasakit Center</span>
      </div>
      <div class="clock">{{ time }}</div>
    </header>

    <!-- Step indicator: this really is a sequence -->
    <ol class="steps" aria-label="Steps">
      <li :class="{ on: step === 1, done: step > 1 }"><b>1</b> Serbisyo <small>Service</small></li>
      <li :class="{ on: step === 2, done: step > 2 }"><b>2</b> Uri ng pila <small>Lane</small></li>
      <li :class="{ on: step === 3 }"><b>3</b> Ticket</li>
    </ol>

    <!-- 1. Service -->
    <section v-if="step === 1" class="stage">
      <h1>Anong serbisyo ang kailangan ninyo?<span>Which service do you need?</span></h1>
      <div class="services" :style="{ '--n': services.length || 3 }">
        <button
          v-for="s in services"
          :key="s.id"
          type="button"
          class="service"
          :style="{ '--agency': agencyColor(s.code) }"
          @click="pickService(s)"
        >
          <AgencyBadge class="badge" :code="s.code" :logo="s.logo" :name="s.name" />
          <span class="name">{{ s.name }}</span>
          <span class="desc">{{ AGENCY[s.code]?.tl }}<i>{{ AGENCY[s.code]?.en }}</i></span>
          <span class="load">
            <strong>{{ totalWaiting(s) }}</strong> naghihintay
            <em v-if="totalWaiting(s)">· ~{{ estimate(s, totalWaiting(s)) }} min</em>
          </span>
        </button>
      </div>
      <p v-if="!services.length" class="empty">{{ loadError || 'Loading services…' }}</p>
    </section>

    <!-- 2. Lane -->
    <section v-else-if="step === 2" class="stage" :style="{ '--agency': agencyColor(selected.code) }">
      <h1>
        <span class="picked"><AgencyBadge class="picked-badge" :code="selected.code" :logo="selected.logo" :name="selected.name" />{{ selected.name }}</span>
        Anong uri ng pila?<span>Which lane are you?</span>
      </h1>
      <button type="button" class="back" @click="reset">← Bumalik <small>Back</small></button>
      <div class="lanes">
        <button
          v-for="(l, key) in LANES"
          :key="key"
          type="button"
          :class="['lane', key]"
          :disabled="busy"
          @click="issue(key)"
        >
          <b class="letter">{{ l.letter }}</b>
          <span class="lane-text">
            <strong>{{ l.label }}</strong>
            <span>{{ l.tl }}</span>
          </span>
          <span class="lane-load">
            <strong>{{ selected.waiting?.[key] ?? 0 }}</strong>
            <span>naghihintay</span>
          </span>
        </button>
      </div>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
    </section>

    <!-- 3. Ticket -->
    <section v-else class="stage done-stage" :style="{ '--agency': agencyColor(issued.ticket.service.code) }">
      <div class="slot" aria-hidden="true"></div>
      <div class="paper" role="status">
        <img class="paper-seal" :src="'/logos/r1mc.png'" alt="" @error="hideOnError" />
        <p class="paper-service">{{ issued.ticket.service.name }}</p>
        <p class="paper-code">{{ issued.ticket.code }}</p>
        <p class="paper-lane">{{ issued.ticket.lane_label }}</p>
        <dl>
          <div><dt>Nauuna sa inyo</dt><dd>{{ issued.ticket.ahead }}</dd></div>
          <div v-if="issued.ticket.ahead"><dt>Tantiyang hintay</dt><dd>~{{ issued.ticket.estimated_minutes }} min</dd></div>
        </dl>
      </div>
      <p class="take">Kunin po ang inyong ticket sa ibaba.<span>Please take your ticket below and watch the TV for your number.</span></p>
    </section>

    <TicketPrint :ticket="issued?.ticket" :hospital="issued?.hospital" />
  </main>
</template>

<script setup>
import { nextTick, onMounted, onUnmounted, ref } from 'vue'
import { api, ensureCsrf, errorMessage } from '../lib/api'
import { AGENCY, LANES, agencyColor, hideOnError } from '../lib/agency'
import TicketPrint from '../components/TicketPrint.vue'
import AgencyBadge from '../components/AgencyBadge.vue'

const IDLE_MS = 30000 // back to the start if nobody touches the screen
const DONE_MS = 9000 // how long the ticket confirmation stays

const step = ref(1)
const services = ref([])
const selected = ref(null)
const issued = ref(null)
const busy = ref(false)
const error = ref('')
const loadError = ref('')
const time = ref('')
let pollTimer, idleTimer, clockTimer

const totalWaiting = (s) => Object.values(s.waiting ?? {}).reduce((a, b) => a + b, 0)
const estimate = (s, ahead) => Math.max(1, Math.ceil((ahead * (s.minutes_per_patient || 6)) / Math.max(1, s.windows_open || 1)))

async function load() {
  try {
    services.value = (await api.get('/kiosk/services')).data
    loadError.value = ''
    if (selected.value) selected.value = services.value.find((s) => s.id === selected.value.id) ?? selected.value
  } catch (e) {
    loadError.value = e?.response?.status === 401 ? 'This kiosk is not registered. Open it with the ?key= link.' : errorMessage(e)
  }
}

function pickService(s) {
  selected.value = s
  error.value = ''
  step.value = 2
  touched()
}

async function issue(lane) {
  busy.value = true
  error.value = ''
  try {
    const { data } = await api.post('/kiosk/tickets', { service_id: selected.value.id, lane })
    issued.value = data
    step.value = 3
    await nextTick()
    window.print() // silent with Chrome/Edge --kiosk-printing
    clearTimeout(idleTimer)
    idleTimer = setTimeout(reset, DONE_MS)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

function reset() {
  step.value = 1
  selected.value = null
  issued.value = null
  error.value = ''
  clearTimeout(idleTimer)
  load()
}

function touched() {
  if (step.value !== 2) return
  clearTimeout(idleTimer)
  idleTimer = setTimeout(reset, IDLE_MS)
}

function tick() {
  time.value = new Date().toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })
}

onMounted(async () => {
  tick()
  clockTimer = setInterval(tick, 15000)
  await ensureCsrf().catch(() => {})
  load()
  pollTimer = setInterval(load, 10000)
})
onUnmounted(() => {
  clearInterval(pollTimer)
  clearInterval(clockTimer)
  clearTimeout(idleTimer)
})
</script>

<style scoped>
.kiosk {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background: var(--paper);
  user-select: none;
  -webkit-tap-highlight-color: transparent;
}

/* header */
.top {
  display: flex;
  align-items: center;
  gap: 18px;
  padding: 18px 32px;
  background: var(--r1-navy);
  color: #fff;
  border-bottom: 6px solid var(--r1-gold);
}
.seal { width: 64px; height: 64px; border-radius: 50%; background: #fff; padding: 2px; }
.org { display: flex; flex-direction: column; line-height: 1.05; }
.hospital { font-size: 18px; opacity: 0.85; }
.center { font: 700 34px var(--display); }
.clock { margin-left: auto; font: 600 34px var(--display); font-variant-numeric: tabular-nums; }

/* steps */
.steps {
  list-style: none;
  margin: 0;
  padding: 16px 32px 0;
  display: flex;
  gap: 28px;
  font-size: 18px;
  font-weight: 600;
  color: var(--muted);
}
.steps li { display: flex; align-items: center; gap: 8px; }
.steps b {
  display: grid;
  place-items: center;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  border: 2px solid var(--line);
  font: 700 17px var(--display);
}
.steps small { font-weight: 500; opacity: 0.75; }
.steps li.on { color: var(--ink); }
.steps li.on b { background: var(--r1-navy); border-color: var(--r1-navy); color: #fff; }
.steps li.done b { background: var(--r1-green); border-color: var(--r1-green); color: #fff; }

.stage { flex: 1; display: flex; flex-direction: column; padding: 18px 32px 32px; }
h1 {
  margin: 0 0 22px;
  font: 700 44px/1.05 var(--display);
  color: var(--ink);
}
h1 > span:not(.picked) { display: block; font: 500 24px var(--text); color: var(--muted); margin-top: 4px; }

/* 1. services */
.services {
  flex: 1;
  display: grid;
  grid-template-columns: repeat(var(--n), minmax(0, 1fr));
  gap: 22px;
}
.service {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 10px;
  padding: 30px 28px 26px;
  text-align: left;
  border: 0;
  border-radius: 18px;
  background: var(--card);
  border-top: 14px solid var(--agency);
  box-shadow: 0 1px 0 var(--line), 0 10px 24px -18px rgba(14, 24, 56, 0.45);
  cursor: pointer;
  transition: transform 0.12s ease;
}
.service:active { transform: scale(0.985); background: color-mix(in srgb, var(--agency) 6%, #fff); }
.badge { width: 112px; box-shadow: 0 0 0 3px color-mix(in srgb, var(--agency) 35%, #fff); }
.name { font: 700 52px/1 var(--display); color: var(--agency); margin-top: 6px; }
.desc { font-size: 22px; font-weight: 600; color: var(--ink); line-height: 1.25; }
.desc i { display: block; font-style: normal; font-weight: 400; font-size: 18px; color: var(--muted); }
.load {
  margin-top: auto;
  padding-top: 14px;
  width: 100%;
  border-top: 1px solid var(--line);
  font-size: 20px;
  color: var(--muted);
}
.load strong { font: 700 30px var(--display); color: var(--ink); }
.load em { font-style: normal; }
.empty { font-size: 22px; color: var(--muted); }

/* 2. lanes */
.picked {
  display: inline-flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 10px;
  padding: 6px 18px 6px 6px;
  border-radius: 999px;
  background: color-mix(in srgb, var(--agency) 12%, #fff);
  color: var(--agency);
  font: 700 28px var(--display);
}
.picked-badge { width: 44px; }
.lanes { display: grid; gap: 16px; }
.lane {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 24px;
  padding: 16px 28px;
  border: 0;
  border-radius: 16px;
  background: var(--card);
  border-left: 14px solid var(--regular);
  box-shadow: 0 1px 0 var(--line), 0 10px 24px -18px rgba(14, 24, 56, 0.45);
  text-align: left;
  cursor: pointer;
}
.lane.fast { border-left-color: var(--fast); }
.lane.priority { border-left-color: var(--priority); }
.lane:active { transform: scale(0.99); }
.lane:disabled { opacity: 0.6; }
.letter {
  display: grid;
  place-items: center;
  width: 76px;
  height: 76px;
  border-radius: 14px;
  font: 700 52px var(--display);
  color: #fff;
  background: var(--regular);
}
.lane.fast .letter { background: var(--fast); }
.lane.priority .letter { background: var(--priority); }
.lane-text { display: flex; flex-direction: column; }
.lane-text strong { font: 700 42px/1 var(--display); }
.lane-text span { font-size: 22px; color: var(--muted); margin-top: 4px; }
.lane-load { display: flex; flex-direction: column; align-items: flex-end; color: var(--muted); font-size: 17px; }
.lane-load strong { font: 700 44px/1 var(--display); color: var(--ink); }
.stage:has(.lanes) { position: relative; }
.stage:has(.lanes) .back { position: absolute; top: 18px; right: 32px; }
.back {
  align-self: flex-start;
  padding: 14px 26px;
  border-radius: 12px;
  border: 2px solid var(--line);
  background: var(--card);
  font-size: 22px;
  font-weight: 600;
  color: var(--ink);
  cursor: pointer;
}
.back small { font-weight: 400; color: var(--muted); }
.error { color: var(--fast); font-size: 20px; font-weight: 600; }

/* 3. ticket: the one animated moment (paper slides out of the slot) */
.done-stage { align-items: center; justify-content: flex-start; padding-top: 10px; }
.slot { width: 460px; height: 18px; border-radius: 10px; background: var(--ink); }
.paper {
  width: 400px;
  margin-top: -6px;
  padding: 26px 28px 22px;
  background: #fff;
  text-align: center;
  box-shadow: 0 18px 36px -24px rgba(14, 24, 56, 0.6);
  border-top: 10px solid var(--agency);
  clip-path: polygon(0 0, 100% 0, 100% 100%, 96% 98%, 92% 100%, 88% 98%, 84% 100%, 80% 98%, 76% 100%, 72% 98%, 68% 100%, 64% 98%, 60% 100%, 56% 98%, 52% 100%, 48% 98%, 44% 100%, 40% 98%, 36% 100%, 32% 98%, 28% 100%, 24% 98%, 20% 100%, 16% 98%, 12% 100%, 8% 98%, 4% 100%, 0 98%);
  animation: print-out 1.1s cubic-bezier(0.2, 0.7, 0.2, 1) both;
  transform-origin: top center;
}
@keyframes print-out {
  from { transform: translateY(-100%); clip-path: inset(0 0 100% 0); }
  to { transform: none; }
}
.paper-seal { width: 56px; height: 56px; }
.paper p { margin: 0; }
.paper-service { font: 700 30px var(--display); color: var(--agency); margin-top: 6px !important; }
.paper-code { font: 700 130px/0.95 var(--display); color: var(--ink); font-variant-numeric: tabular-nums; }
.paper-lane { font-size: 20px; font-weight: 600; color: var(--muted); }
.paper dl { margin: 16px 0 0; padding-top: 12px; border-top: 2px dashed var(--line); display: flex; justify-content: center; gap: 30px; }
.paper dt { font-size: 15px; color: var(--muted); }
.paper dd { margin: 0; font: 700 30px var(--display); }
.take { margin: 24px 0 0; font: 700 32px var(--display); text-align: center; }
.take span { display: block; font: 500 20px var(--text); color: var(--muted); margin-top: 4px; }

@media (max-width: 900px) {
  .services { grid-template-columns: 1fr; }
  .name { font-size: 40px; }
}
</style>
