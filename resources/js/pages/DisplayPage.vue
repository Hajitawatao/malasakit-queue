<template>
  <main class="tv">
    <header class="top">
      <div class="brand">
        <img class="brand-logo" :src="'/logos/r1mc.png'" alt="" @error="(e) => (e.target.style.display = 'none')" />
        <div class="brand-text">
        <span class="hospital">Region 1 Medical Center</span>
        <span class="center">Malasakit Center</span>
        </div>
      </div>
      <div class="clock">
        <span class="time">{{ time }}</span>
        <span class="date">{{ date }}</span>
      </div>
    </header>

    <div class="body">
      <section class="boards">
        <article
          v-for="s in snapshot.services"
          :key="s.id"
          class="board"
          :style="{ '--agency': agencyColor(s.code) }"
        >
          <header class="board-head">
            <span v-if="s.logo" class="badge"><img :src="s.logo" alt="" @error="(e) => (e.target.parentElement.style.display = 'none')" /></span>
            <h2>{{ s.name }}</h2>
          </header>

          <div class="now" :class="{ multi: s.now_serving.length > 1 }">
            <p class="caption">Now serving</p>
            <template v-if="s.now_serving.length">
              <div v-for="t in s.now_serving" :key="t.code" class="serving" :class="t.lane">
                <span class="code">{{ t.code }}</span>
                <span class="where">
                  <span class="window">{{ t.counter }}</span>
                  <span v-if="t.lane !== 'regular'" class="lane-tag">{{ laneLabel[t.lane] }}</span>
                </span>
              </div>
            </template>
            <p v-else class="idle">Waiting for the next call</p>
          </div>

          <div class="next">
            <p class="caption">Next in line</p>
            <ol v-if="s.next.length" class="chips">
              <li v-for="c in s.next" :key="c" :class="laneOf(c)">{{ c }}</li>
            </ol>
            <p v-else class="none">No one waiting</p>
          </div>

          <footer class="board-foot">
            <strong>{{ s.waiting_total }}</strong> waiting
          </footer>
        </article>
      </section>

      <aside class="recent">
        <h3>Recent calls</h3>
        <ol v-if="snapshot.recent?.length">
          <li
            v-for="(r, i) in snapshot.recent"
            :key="r.code + r.service.code + r.time"
            :class="{ latest: i === 0 }"
            :style="{ '--agency': agencyColor(r.service.code) }"
          >
            <span class="r-code">{{ r.code }}</span>
            <span class="r-where">
              <span>{{ r.service.name }}</span>
              <span class="r-window">{{ r.counter }}</span>
            </span>
            <span class="r-time">{{ r.time }}</span>
          </li>
        </ol>
        <p v-else class="none">No calls yet today</p>
      </aside>
    </div>

    <footer class="legend">
      <ul>
        <li class="fast"><b>F</b> Fast lane: senior citizen, PWD, buntis</li>
        <li class="priority"><b>P</b> Priority: may referral</li>
        <li class="regular"><b>R</b> Regular</li>
      </ul>
      <p>Hintayin po ang pagtawag ng inyong numero.</p>
    </footer>

    <!-- Full-screen call announcement -->
    <div v-if="flash" class="flash" :style="{ '--agency': agencyColor(flash.service.code) }">
      <span v-if="logoFor(flash.service.code)" class="flash-badge"><img :src="logoFor(flash.service.code)" alt="" /></span>
      <p class="flash-lead">Now calling</p>
      <div class="flash-code">{{ flash.code }}</div>
      <p class="flash-where">Please proceed to {{ flash.service.name }}, {{ flash.counter?.name }}</p>
      <p class="flash-tl">Pumunta po sa {{ flash.service.name }}, {{ flash.counter?.name }}</p>
    </div>

    <button v-if="!started" class="start" type="button" @click="start">
      <span>Tap anywhere to start the display and voice</span>
      <small>{{ voiceLabel }}</small>
      <small v-if="snapshot.voice_engine === 'browser'" class="hint">
        For a better voice, run setup-elevenlabs.bat or install-voice.bat on the server PC.
      </small>
    </button>
    <button v-if="started" class="test-voice" type="button" @click="testVoice">Test voice</button>
    <div v-if="offline" class="offline">Cannot reach the queue server. Retrying…</div>
  </main>
</template>

<script setup>
import '@fontsource/barlow-condensed/600.css'
import '@fontsource/barlow-condensed/700.css'
import '@fontsource/barlow/500.css'
import '@fontsource/barlow/600.css'
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { api } from '../lib/api'
import { announce, browserVoiceName, unlockAudio } from '../lib/speech'

const POLL_MS = 2000

// Kulay ng bawat ahensya para makilala agad ang column mula sa malayo
const AGENCY = {
  DOH: '#0F7B5F',
  DSWD: '#2456B3',
  PHIC: '#4E9A2F',
  PAGCOR: '#B4161B',
}
const agencyColor = (code) => AGENCY[code] ?? '#3B5B7E'
const logoFor = (code) => snapshot.value.services.find((s) => s.code === code)?.logo ?? null

const laneLabel = { fast: 'Fast lane', priority: 'Priority', regular: 'Regular' }
const laneOf = (code) => ({ F: 'fast', P: 'priority' })[code?.[0]] ?? 'regular'

const snapshot = ref({ services: [], calls: [], recent: [], voice_engine: null })
const flash = ref(null)
const browserVoice = ref(null)
const voiceLabel = computed(() => {
  if (snapshot.value.voice_engine === 'elevenlabs') return 'Voice: ElevenLabs (online)'
  if (snapshot.value.voice_engine === 'piper') return 'Voice: natural female (offline, Piper)'
  return `Voice: ${browserVoice.value ?? 'browser default'} (browser)`
})

async function testVoice() {
  try {
    const { data } = await api.get('/display/test-voice')
    announce({ code: 'R001', service: { name: 'DSWD' }, counter: { name: 'Window 1' }, speech: data.speech, audio: data.audio })
  } catch {
    announce({ code: 'R001', service: { name: 'DSWD' }, counter: { name: 'Window 1' } })
  }
}
const started = ref(false)
const offline = ref(false)
const time = ref('')
const date = ref('')
const seen = new Set() // mga tawag na nabasa na (id@called_at)
let firstLoad = true
let pollTimer, clockTimer, flashTimer

async function refresh() {
  try {
    const data = (await api.get('/display')).data
    snapshot.value = data
    offline.value = false

    for (const call of data.calls) {
      if (seen.has(call.key)) continue
      seen.add(call.key)
      if (!firstLoad) onCalled(call) // huwag basahin ang mga lumang tawag sa unang load
    }
    firstLoad = false
    if (seen.size > 500) seen.clear()
  } catch {
    offline.value = true
  }
}

function onCalled(t) {
  flash.value = t
  clearTimeout(flashTimer)
  flashTimer = setTimeout(() => (flash.value = null), 9000)
  if (started.value) announce(t)
}

function start() {
  unlockAudio()
  started.value = true
  document.documentElement.requestFullscreen?.().catch(() => {})
}

function tick() {
  const now = new Date()
  time.value = now.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })
  date.value = now.toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })
}

onMounted(() => {
  browserVoice.value = browserVoiceName()
  speechSynthesis.addEventListener?.('voiceschanged', () => (browserVoice.value = browserVoiceName()))
  refresh()
  tick()
  clockTimer = setInterval(tick, 1000)
  pollTimer = setInterval(refresh, POLL_MS)
})

onUnmounted(() => {
  clearInterval(pollTimer)
  clearInterval(clockTimer)
  clearTimeout(flashTimer)
})
</script>

<style scoped>
/* Sized for a 1920x1080 TV; everything scales with the screen width. */
.tv {
  --ink: #0E2238;
  --ink-2: #16304D;
  --ink-3: #21405F;
  --paper: #F4F7FB;
  --muted: #9DB2C9;
  --sun: #F6C343;
  --fast: #E5484D;
  --priority: #F2A33A;
  --regular: #DDE6F0;

  min-height: 100vh;
  display: grid;
  grid-template-rows: auto 1fr auto;
  background: var(--ink);
  color: var(--paper);
  font-family: 'Barlow', system-ui, sans-serif;
  overflow: hidden;
}

/* ---------- header ---------- */
.top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1.2vw 2vw 1vw;
}
.brand { display: flex; align-items: center; gap: 1vw; }
.brand-logo { width: 4.4vw; height: 4.4vw; object-fit: contain; background: #fff; border-radius: 50%; padding: 0.15vw; }
.brand-text { display: flex; flex-direction: column; line-height: 1.05; }
.hospital { font-size: 1.25vw; color: var(--muted); font-weight: 500; }
.center {
  font-family: 'Barlow Condensed', sans-serif;
  font-weight: 700;
  font-size: 2.6vw;
  letter-spacing: 0.01em;
}
.clock { display: flex; flex-direction: column; align-items: flex-end; line-height: 1.05; }
.time {
  font-family: 'Barlow Condensed', sans-serif;
  font-weight: 600;
  font-size: 2.8vw;
  font-variant-numeric: tabular-nums;
}
.date { font-size: 1.15vw; color: var(--muted); }

/* ---------- main area ---------- */
.body {
  display: grid;
  grid-template-columns: 1fr 19vw;
  gap: 1vw;
  padding: 0 2vw 1vw;
  min-height: 0;
}
.boards {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1vw;
  min-height: 0;
}

.board {
  display: flex;
  flex-direction: column;
  background: var(--ink-2);
  border-radius: 0.6vw;
  overflow: hidden;
  border-top: 0.55vw solid var(--agency);
}
.board-head {
  display: flex;
  align-items: center;
  gap: 0.7vw;
  padding: 0.9vw 1.1vw;
  background: color-mix(in srgb, var(--agency) 28%, var(--ink-2));
}
.badge {
  flex: none;
  display: grid;
  place-items: center;
  width: 3.2vw;
  height: 3.2vw;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 0 0 0.15vw color-mix(in srgb, var(--agency) 60%, #fff);
}
.badge img { width: 78%; height: 78%; object-fit: contain; }
.board-head h2 {
  margin: 0;
  font-family: 'Barlow Condensed', sans-serif;
  font-weight: 700;
  font-size: 2.1vw;
  line-height: 1;
}

.caption {
  margin: 0 0 0.3vw;
  font-size: 1.05vw;
  font-weight: 600;
  color: var(--muted);
}

.now { padding: 1.2vw 1.1vw 0.8vw; flex: 1; display: flex; flex-direction: column; gap: 0.8vw; }
.now .caption { margin-bottom: auto; }
.now > :last-child { margin-bottom: auto; }
.serving {
  display: flex;
  flex-direction: column;
  border-left: 0.45vw solid var(--regular);
  padding-left: 0.9vw;
}
.serving.fast { border-color: var(--fast); }
.serving.priority { border-color: var(--priority); }
.serving .code {
  font-family: 'Barlow Condensed', sans-serif;
  font-weight: 700;
  font-size: 7.4vw;
  line-height: 0.92;
  letter-spacing: 0.01em;
  color: var(--sun);
  font-variant-numeric: tabular-nums;
}
.now.multi .serving .code { font-size: 4.6vw; }
.where { display: flex; align-items: baseline; gap: 0.7vw; flex-wrap: wrap; }
.window { font-size: 1.7vw; font-weight: 600; }
.lane-tag {
  font-size: 1vw;
  font-weight: 600;
  padding: 0.1vw 0.5vw;
  border-radius: 0.3vw;
  background: var(--fast);
  color: #fff;
}
.serving.priority .lane-tag { background: var(--priority); color: var(--ink); }
.idle { margin: 0.6vw 0 0; font-size: 1.5vw; color: var(--muted); }

.next { padding: 0.8vw 1.1vw; border-top: 1px solid var(--ink-3); }
.chips { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 0.45vw; }
.chips li {
  font-family: 'Barlow Condensed', sans-serif;
  font-weight: 600;
  font-size: 1.75vw;
  padding: 0.1vw 0.6vw;
  border-radius: 0.3vw;
  background: var(--ink-3);
  border-left: 0.3vw solid var(--regular);
  font-variant-numeric: tabular-nums;
}
.chips li.fast { border-color: var(--fast); }
.chips li.priority { border-color: var(--priority); }
.none { margin: 0; font-size: 1.2vw; color: var(--muted); }

.board-foot {
  padding: 0.7vw 1.1vw;
  font-size: 1.2vw;
  color: var(--muted);
  background: color-mix(in srgb, var(--ink) 40%, var(--ink-2));
}
.board-foot strong { color: var(--paper); font-size: 1.5vw; }

/* ---------- recent calls ---------- */
.recent {
  background: var(--ink-2);
  border-radius: 0.6vw;
  padding: 1vw;
  display: flex;
  flex-direction: column;
  min-height: 0;
}
.recent h3 {
  margin: 0 0 0.6vw;
  font-family: 'Barlow Condensed', sans-serif;
  font-weight: 700;
  font-size: 1.8vw;
}
.recent ol { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.5vw; }
.recent li {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 0.7vw;
  padding: 0.55vw 0.7vw;
  border-radius: 0.4vw;
  background: var(--ink-3);
  border-left: 0.4vw solid var(--agency);
}
.recent li.latest { background: color-mix(in srgb, var(--agency) 35%, var(--ink-3)); }
.r-code {
  font-family: 'Barlow Condensed', sans-serif;
  font-weight: 700;
  font-size: 2.2vw;
  line-height: 1;
  min-width: 4.6vw;
  font-variant-numeric: tabular-nums;
}
.recent li.latest .r-code { color: var(--sun); }
.r-where { display: flex; flex-direction: column; font-size: 1vw; line-height: 1.2; }
.r-window { color: var(--muted); }
.r-time { font-size: 0.95vw; color: var(--muted); font-variant-numeric: tabular-nums; }

/* ---------- legend ---------- */
.legend {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.8vw 2vw;
  background: var(--ink-2);
  font-size: 1.15vw;
}
.legend ul { list-style: none; margin: 0; padding: 0; display: flex; gap: 2vw; }
.legend li { display: flex; align-items: center; gap: 0.5vw; color: var(--muted); }
.legend b {
  display: inline-grid;
  place-items: center;
  width: 1.7vw;
  height: 1.7vw;
  border-radius: 0.3vw;
  font-family: 'Barlow Condensed', sans-serif;
  font-size: 1.2vw;
  color: var(--ink);
  background: var(--regular);
}
.legend .fast b { background: var(--fast); color: #fff; }
.legend .priority b { background: var(--priority); }
.legend p { margin: 0; font-weight: 600; }

/* ---------- call announcement ---------- */
.flash {
  position: fixed;
  inset: 0;
  z-index: 10;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  background: var(--agency);
  color: #fff;
  animation: flash-in 0.5s ease-out;
}
.flash-badge {
  display: grid;
  place-items: center;
  width: 9vw;
  height: 9vw;
  margin-bottom: 1.2vw;
  border-radius: 50%;
  background: #fff;
}
.flash-badge img { width: 78%; height: 78%; object-fit: contain; }
.flash-lead { margin: 0; font-size: 2.6vw; font-weight: 600; opacity: 0.9; }
.flash-code {
  font-family: 'Barlow Condensed', sans-serif;
  font-weight: 700;
  font-size: 24vw;
  line-height: 0.9;
  color: var(--sun);
  text-shadow: 0 0.4vw 0 rgba(0, 0, 0, 0.18);
  font-variant-numeric: tabular-nums;
}
.flash-where { margin: 1.2vw 0 0; font-size: 3.4vw; font-weight: 600; }
.flash-tl { margin: 0.4vw 0 0; font-size: 2.4vw; opacity: 0.9; }
@keyframes flash-in {
  from { opacity: 0; transform: scale(1.04); }
  to { opacity: 1; transform: none; }
}

.start {
  position: fixed;
  inset: 0;
  z-index: 20;
  border: 0;
  background: rgba(14, 34, 56, 0.92);
  color: var(--paper);
  font: 600 2.4vw 'Barlow', sans-serif;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1vw;
}
.start small { font-size: 1.2vw; font-weight: 500; color: var(--muted); }
.start .hint { color: var(--sun); }
.test-voice {
  position: fixed;
  right: 0.6vw;
  bottom: 3.6vw;
  z-index: 5;
  border: 0;
  border-radius: 0.3vw;
  padding: 0.3vw 0.7vw;
  font: 500 0.8vw 'Barlow', sans-serif;
  background: transparent;
  color: var(--ink-3);
  cursor: pointer;
}
.test-voice:hover, .test-voice:focus-visible { color: var(--muted); outline: 1px solid var(--muted); }
.start:focus-visible { outline: 0.3vw solid var(--sun); outline-offset: -1vw; }
.offline {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 30;
  padding: 0.6vw;
  text-align: center;
  font-size: 1.3vw;
  font-weight: 600;
  background: var(--fast);
  color: #fff;
}

@media (prefers-reduced-motion: reduce) {
  .flash { animation: none; }
}
</style>
