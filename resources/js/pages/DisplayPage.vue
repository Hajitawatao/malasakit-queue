<template>
  <main class="display">
    <header class="bar">
      <h1>REGION 1 MEDICAL CENTER · MALASAKIT</h1>
      <span class="clock">{{ clock }}</span>
    </header>

    <!-- Malaking flash ng kakatawag lang -->
    <div v-if="flash" class="flash">
      <div class="flash-code">{{ flash.code }}</div>
      <div class="flash-where">{{ flash.service.name }} — {{ flash.counter?.name }}</div>
    </div>

    <section class="grid">
      <article v-for="s in snapshot.services" :key="s.id" class="col">
        <h2>{{ s.name }}</h2>
        <div class="label">NOW SERVING</div>
        <div v-if="s.now_serving.length" class="serving">
          <div v-for="t in s.now_serving" :key="t.code" :class="['now', t.lane]">
            <span class="code">{{ t.code }}</span>
            <span class="win">{{ t.counter }}</span>
          </div>
        </div>
        <div v-else class="idle">—</div>
        <div class="label">SUSUNOD</div>
        <div class="next">
          <span v-for="c in s.next" :key="c">{{ c }}</span>
        </div>
        <div class="total">Naghihintay: {{ s.waiting_total }}</div>
      </article>
    </section>

    <div v-if="!started" class="start" @click="start">
      I-tap para simulan ang display at boses
    </div>
    <div v-if="offline" class="offline">Nawalan ng koneksyon sa server…</div>
  </main>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { api } from '../lib/api'
import { echo } from '../lib/echo'
import { announce, unlockAudio } from '../lib/speech'

const snapshot = ref({ services: [] })
const flash = ref(null)
const started = ref(false)
const offline = ref(false)
const clock = ref('')
let pollTimer, clockTimer, flashTimer

async function refresh() {
  try {
    snapshot.value = (await api.get('/display')).data
    offline.value = false
  } catch {
    offline.value = true
  }
}

function onCalled(t) {
  refresh()
  flash.value = t
  clearTimeout(flashTimer)
  flashTimer = setTimeout(() => (flash.value = null), 8000)
  if (started.value) announce(t)
}

function start() {
  unlockAudio()
  started.value = true
  document.documentElement.requestFullscreen?.().catch(() => {})
}

function tick() {
  clock.value = new Date().toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'medium' })
}

onMounted(() => {
  refresh()
  tick()
  clockTimer = setInterval(tick, 1000)
  pollTimer = setInterval(refresh, 15000) // fallback kung naputol ang websocket
  echo.channel('queue.display')
    .listen('.ticket.called', onCalled)
    .listen('.queue.updated', refresh)
})

onUnmounted(() => {
  clearInterval(pollTimer)
  clearInterval(clockTimer)
  echo.leave('queue.display')
})
</script>

<style scoped>
.display { min-height: 100vh; background: #0d1b2a; color: #fff; font-family: system-ui, sans-serif; display: flex; flex-direction: column; }
.bar { display: flex; justify-content: space-between; align-items: center; padding: 14px 28px; background: #e8eef7; color: #0b3d91; }
.bar h1 { margin: 0; font-size: 34px; letter-spacing: 1px; }
.clock { font-size: 24px; font-weight: 600; }
.grid { flex: 1; display: grid; grid-template-columns: repeat(4, 1fr); gap: 2px; background: #22364d; }
.col { background: #0d1b2a; padding: 20px; display: flex; flex-direction: column; }
.col h2 { margin: 0 0 12px; font-size: 32px; color: #ffd54f; text-align: center; }
.label { font-size: 16px; letter-spacing: 3px; color: #8fa7c4; margin-top: 12px; }
.serving { display: grid; gap: 8px; margin-top: 6px; }
.now { background: #16324f; border-left: 10px solid #1e88e5; border-radius: 10px; padding: 10px 14px; display: flex; justify-content: space-between; align-items: baseline; }
.now.fast { border-color: #e53935; }
.now.priority { border-color: #fb8c00; }
.now .code { font-size: 64px; font-weight: 900; color: #7CFC7C; }
.now .win { font-size: 20px; color: #cfd8e3; }
.idle { font-size: 48px; color: #456; }
.next { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.next span { background: #1b2f45; padding: 6px 12px; border-radius: 8px; font-size: 26px; font-weight: 700; }
.total { margin-top: auto; font-size: 18px; color: #8fa7c4; }
.flash { position: fixed; inset: 0; background: rgba(13,27,42,.94); display: grid; place-content: center; text-align: center; z-index: 10; animation: pulse 1s ease-in-out 3; }
.flash-code { font-size: 260px; font-weight: 900; color: #7CFC7C; line-height: 1; }
.flash-where { font-size: 56px; color: #ffd54f; }
@keyframes pulse { 50% { opacity: .6; } }
.start { position: fixed; inset: 0; background: rgba(0,0,0,.85); display: grid; place-items: center; font-size: 40px; cursor: pointer; z-index: 20; }
.offline { position: fixed; bottom: 0; left: 0; right: 0; background: #c62828; text-align: center; padding: 8px; font-size: 20px; }
</style>
