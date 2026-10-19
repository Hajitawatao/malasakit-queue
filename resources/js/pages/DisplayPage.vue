<template>
  <main class="tv" :class="{ 'no-rail': !showRail, dark: theme === 'dark' }">
    <header class="top">
      <img class="seal" :src="'/logos/r1mc.png'" alt="" @error="hideOnError" />
      <div class="org">
        <span class="hospital">Region 1 Medical Center</span>
        <span class="center">Malasakit Center</span>
      </div>
      <div class="clock">
        <span class="time">{{ time }}</span>
        <span class="date">{{ date }}</span>
      </div>
    </header>

    <div class="body">
      <!-- Boards: any number of services, any number of windows each -->
      <section class="boards" :style="{ '--cols': grid.cols, '--grid-rows': grid.rows }">
        <article
          v-for="s in services"
          :key="s.id"
          class="board"
          :style="{ '--agency': agencyColor(s.code), '--rows': Math.max(1, s.windows.length) }"
        >
          <header class="board-head">
            <AgencyBadge class="badge" :code="s.code" :logo="s.logo" :name="s.name" />
            <h2>{{ s.name }}</h2>
            <span class="waiting"><strong>{{ s.waiting_total }}</strong> naghihintay</span>
          </header>

          <ul class="windows">
            <li
              v-for="w in s.windows"
              :key="w.id"
              :class="['window', w.lane, { idle: !w.code, fresh: isFresh(s.code, w.code) }]"
            >
              <span class="w-name">{{ w.name }}</span>
              <span class="w-code">{{ w.code || 'Bukas' }}</span>
              <span class="w-state">
                <template v-if="w.code">
                  <span v-if="w.lane !== 'regular'" class="lane-tag">{{ LANES[w.lane].label }}</span>
                  {{ w.status === 'serving' ? 'Serving' : 'Calling' }}
                </template>
                <template v-else>Open · waiting for next call</template>
              </span>
            </li>
          </ul>

          <footer class="next">
            <span class="next-label">Susunod</span>
            <ol v-if="s.next.length" class="chips">
              <li v-for="c in s.next" :key="c" :class="laneOf(c)">{{ c }}</li>
            </ol>
            <span v-else class="none">Walang naghihintay</span>
          </footer>
        </article>
      </section>

      <aside v-if="showRail" class="recent">
        <h3>Recent calls</h3>
        <ol v-if="snapshot.recent?.length">
          <li
            v-for="(r, i) in snapshot.recent"
            :key="r.code + r.service.code + r.time"
            :class="{ latest: i === 0 }"
            :style="{ '--agency': agencyColor(r.service.code) }"
          >
            <span class="r-code">{{ r.code }}</span>
            <span class="r-where"><b>{{ r.service.name }}</b><i>{{ r.counter }} · {{ r.time }}</i></span>
          </li>
        </ol>
        <p v-else class="none">No calls yet today</p>
      </aside>
    </div>

    <footer class="legend">
      <ul>
        <li class="fast"><b>F</b> Fast lane · Senior, PWD, buntis</li>
        <li class="priority"><b>P</b> Priority · May referral</li>
        <li class="regular"><b>R</b> Regular</li>
      </ul>
      <p>Hintayin po ang pagtawag ng inyong numero.</p>
    </footer>

    <!-- Full-screen call announcement (shown together with the chime) -->
    <div v-if="flash" class="flash" :style="{ '--agency': agencyColor(flash.service.code) }">
      <AgencyBadge class="flash-badge" :code="flash.service.code" :name="flash.service.name" />
      <p class="flash-lead">Now calling</p>
      <div class="flash-code">{{ flash.code }}</div>
      <p class="flash-where">{{ flash.service.name }} · {{ flash.counter?.name }}</p>
      <p class="flash-tl">Pumunta po sa {{ flash.service.name }}, {{ flash.counter?.name }}</p>
    </div>

    <button v-if="!started" class="start" type="button" @click="start">
      <span>Tap anywhere to start the display and voice</span>
      <small>{{ voiceLabel }}</small>
      <small v-if="snapshot.voice_installed && snapshot.voice_installed !== 'browser' && !snapshot.voice_worker" class="hint">
        The VOICE window is not running on the server PC. Run start.bat again to use the {{ snapshot.voice_installed }} voice.
      </small>
      <small v-else-if="snapshot.voice_engine === 'browser'" class="hint">
        For a better voice, run install-kokoro.bat on the server PC.
      </small>
    </button>
    <button v-if="started" class="test-voice" type="button" @click="testVoice">
      Test voice<template v-if="snapshot.voice_worker === false && snapshot.voice_installed !== 'browser'"> · voice worker off</template>
    </button>
    <div v-if="offline" class="offline">Cannot reach the queue server. Retrying…</div>
  </main>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { api } from '../lib/api'
import { announce, browserVoiceName, unlockAudio } from '../lib/speech'
import { LANES, agencyColor, hideOnError, laneOf } from '../lib/agency'
import AgencyBadge from '../components/AgencyBadge.vue'

const POLL_MS = 1500
const VOICE_WAIT_MS = 12000 // after this, speak with the browser voice instead

/*
 * One TV can show a subset of services: /display?services=DOH,DSWD
 * Hide the recent-calls rail on narrow or crowded screens: /display?rail=0
 * Dark theme: /display?theme=dark (light is the default)
 */
const params = new URLSearchParams(location.search)
const theme = params.get('theme') === 'dark' ? 'dark' : 'light'
const only = (params.get('services') || '').split(',').map((s) => s.trim().toUpperCase()).filter(Boolean)

const snapshot = ref({ services: [], calls: [], recent: [], voice_engine: null })
const flash = ref(null)
const started = ref(false)
const offline = ref(false)
const time = ref('')
const date = ref('')
const browserVoice = ref(null)
const freshCalls = ref(new Map()) // "DSWD:R027" -> timestamp, highlights a window for a few seconds

const services = computed(() =>
  snapshot.value.services.filter((s) => !only.length || only.includes(s.code)),
)
const showRail = computed(() => params.get('rail') !== '0' && services.value.length <= 6)

// Up to 4 boards per row; more services wrap into extra rows
const grid = computed(() => {
  const n = Math.max(1, services.value.length)
  const rows = Math.ceil(n / 4)
  return { rows, cols: Math.ceil(n / rows) }
})

const voiceLabel = computed(() => {
  const e = snapshot.value.voice_engine
  if (e === 'elevenlabs') return 'Voice: ElevenLabs (online)'
  if (e === 'kokoro') return 'Voice: Kokoro natural female (offline)'
  if (e === 'piper') return 'Voice: Piper female (offline)'
  return `Voice: ${browserVoice.value ?? 'browser default'} (browser)`
})

const isFresh = (service, code) => code && Date.now() - (freshCalls.value.get(`${service}:${code}`) ?? 0) < 12000

const seen = new Set() // calls already handled (id@called_at)
const pendingVoice = new Map() // calls waiting for their voice file: key -> { call, since }
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
      if (!firstLoad) pendingVoice.set(call.key, { call, since: Date.now() }) // skip old calls on first load
    }
    firstLoad = false
    if (seen.size > 500) seen.clear()

    // Announce each call as soon as its voice file is ready (usually right away,
    // because the voice worker prepares waiting tickets ahead of time)
    const byKey = new Map(data.calls.map((c) => [c.key, c]))
    for (const [key, item] of pendingVoice) {
      const latest = byKey.get(key) ?? item.call
      if (latest.audio || Date.now() - item.since > VOICE_WAIT_MS || data.voice_engine === 'browser') {
        pendingVoice.delete(key)
        onCalled(latest)
      }
    }
  } catch {
    offline.value = true
  }
}

function showFlash(t) {
  flash.value = t
  freshCalls.value = new Map(freshCalls.value).set(`${t.service.code}:${t.code}`, Date.now())
  clearTimeout(flashTimer)
  flashTimer = setTimeout(() => (flash.value = null), 8000)
}

function onCalled(t) {
  if (only.length && !only.includes(t.service?.code)) return // another TV handles it
  if (started.value) announce(t, () => showFlash(t))
  else showFlash(t)
}

async function testVoice() {
  try {
    const { data } = await api.get('/display/test-voice')
    announce({ code: 'R001', service: { code: 'DSWD', name: 'DSWD' }, counter: { name: 'Window 1' }, speech: data.speech, audio: data.audio })
  } catch {
    announce({ code: 'R001', service: { code: 'DSWD', name: 'DSWD' }, counter: { name: 'Window 1' } })
  }
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
/*
 * Fills any screen (16:9, ultrawide, portrait). Sizes come from the viewport and,
 * inside each board, from the board's own size (container queries), so 3 services
 * with 1 window each and 10 services with 4 windows each both stay readable.
 */
.tv {
  /* Light theme (default) */
  --tv-bg: #e8ecf2;
  --tv-card: #ffffff;
  --tv-text: var(--ink);
  --tv-muted: #5b6b86;
  --tv-line: #e1e6ee;
  --tv-chip: #eef1f6;
  --tv-num: var(--ink);
  --tv-idle: #c3ccda;
  --tv-latest-bg: color-mix(in srgb, var(--agency) 12%, #fff);
  --tv-latest-num: var(--agency);
  --tv-fresh: #fff0bf;
  --tv-shadow: 0 1px 0 #d9dfea, 0 10px 22px -18px rgba(14, 24, 56, 0.5);

  height: 100vh;
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  background: var(--tv-bg);
  color: var(--tv-text);
  overflow: hidden;
}

/* Dark theme: /display?theme=dark */
.tv.dark {
  --tv-bg: var(--ink);
  --tv-card: var(--ink-2);
  --tv-text: #fff;
  --tv-muted: var(--muted-on-dark);
  --tv-line: var(--ink-3);
  --tv-chip: var(--ink-3);
  --tv-num: var(--r1-gold);
  --tv-idle: var(--ink-3);
  --tv-latest-bg: color-mix(in srgb, var(--agency) 38%, var(--ink-3));
  --tv-latest-num: var(--r1-gold);
  --tv-fresh: rgba(244, 180, 0, 0.18);
  --tv-shadow: none;
}

/* header (navy in both themes: R1MC identity) */
.top {
  color: #fff;
  display: flex;
  align-items: center;
  gap: 1vw;
  padding: 1vh 1.6vw;
  background: var(--r1-navy);
  border-bottom: 0.5vh solid var(--r1-gold);
}
.seal { width: 6vh; height: 6vh; border-radius: 50%; background: #fff; padding: 0.15vh; }
.org { display: flex; flex-direction: column; line-height: 1.02; }
.hospital { font-size: 1.9vh; color: var(--muted-on-dark); }
.center { font: 700 4vh var(--display); }
.clock { margin-left: auto; display: flex; flex-direction: column; align-items: flex-end; line-height: 1.02; }
.time { font: 600 4.4vh var(--display); font-variant-numeric: tabular-nums; }
.date { font-size: 1.8vh; color: var(--muted-on-dark); }

/* main */
.body {
  display: grid;
  grid-template-columns: minmax(0, 1fr) clamp(240px, 18vw, 400px);
  gap: 1vw;
  padding: 1.2vh 1.2vw;
  min-height: 0;
}
.tv.no-rail .body { grid-template-columns: minmax(0, 1fr); }
.boards {
  display: grid;
  grid-template-columns: repeat(var(--cols), minmax(0, 1fr));
  grid-template-rows: repeat(var(--grid-rows), minmax(0, 1fr));
  gap: 1vw;
  min-height: 0;
}

/* a service board */
.board {
  container-type: size;
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  background: var(--tv-card);
  box-shadow: var(--tv-shadow);
  border-radius: 1vh;
  overflow: hidden;
  border-top: 1vh solid var(--agency);
}
.board-head {
  display: flex;
  align-items: center;
  gap: 2.2cqw;
  padding: 2.4cqh 3cqw;
  background: var(--agency);
  color: #fff;
}
.badge { width: clamp(28px, 11cqw, 76px); }
.board-head h2 { margin: 0; font: 700 clamp(18px, 9cqw, 64px) / 1 var(--display); min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.waiting { margin-left: auto; text-align: right; font-size: clamp(11px, 3cqw, 20px); color: rgba(255, 255, 255, 0.85); line-height: 1.1; white-space: nowrap; }
.waiting strong { display: block; font: 700 clamp(18px, 7cqw, 46px) / 1 var(--display); color: #fff; }

/* windows: one row each, the number fills the row */
.windows {
  list-style: none;
  margin: 0;
  padding: 1.4cqh 3cqw;
  display: grid;
  grid-template-rows: repeat(var(--rows), minmax(0, 1fr));
  gap: 1.4cqh;
  min-height: 0;
}
.window {
  display: grid;
  grid-template-columns: 1fr auto;
  grid-template-rows: auto 1fr;
  align-items: center;
  padding: 0 0 0 2.6cqw;
  border-left: max(4px, 1.4cqw) solid var(--regular);
  border-radius: 0 0.6cqw 0.6cqw 0;
  min-height: 0;
}
.window.fast { border-left-color: var(--fast); }
.window.priority { border-left-color: var(--priority); }
.window.idle { border-left-color: var(--tv-idle); }
.w-name { grid-column: 1 / -1; font: 600 clamp(12px, min(5cqw, calc(9cqh / var(--rows))), 30px) var(--text); color: var(--tv-muted); }
.w-code {
  font: 700 clamp(28px, min(30cqw, calc(52cqh / var(--rows))), 320px) / 0.9 var(--display);
  color: var(--tv-num);
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.01em;
}
.window.idle .w-code { color: var(--tv-idle); font-size: clamp(20px, min(14cqw, calc(26cqh / var(--rows))), 120px); }
.w-state {
  justify-self: end;
  align-self: end;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.6cqh;
  font-size: clamp(11px, min(3.6cqw, calc(7cqh / var(--rows))), 24px);
  color: var(--tv-muted);
  padding-bottom: 1cqh;
}
.lane-tag { padding: 0.2em 0.6em; border-radius: 0.4em; background: var(--fast); color: #fff; font-weight: 600; }
.window.priority .lane-tag { background: var(--priority); color: var(--ink); }
.window.fresh .w-code { animation: called 1s ease-in-out 4; }
@keyframes called { 50% { background: var(--tv-fresh); } }

/* next in line */
.next {
  display: flex;
  align-items: center;
  gap: 2cqw;
  padding: 2cqh 3cqw;
  border-top: 1px solid var(--tv-line);
  min-width: 0;
}
.next-label { font-size: clamp(11px, 3.2cqw, 20px); color: var(--tv-muted); white-space: nowrap; }
.chips { list-style: none; margin: 0; padding: 0; display: flex; gap: 1.4cqw; overflow: hidden; flex-wrap: nowrap; min-width: 0; }
.chips li {
  flex: none;
  font: 600 clamp(14px, 5.4cqw, 36px) / 1.2 var(--display);
  padding: 0 0.4em;
  border-radius: 0.2em;
  background: var(--tv-chip);
  border-left: 0.18em solid var(--regular);
  font-variant-numeric: tabular-nums;
}
.chips li.fast { border-color: var(--fast); }
.chips li.priority { border-color: var(--priority); }
.none { font-size: clamp(11px, 3.4cqw, 22px); color: var(--tv-muted); }

/* recent calls */
.recent {
  background: var(--tv-card);
  box-shadow: var(--tv-shadow);
  border-radius: 1vh;
  padding: 1.6vh 1vw;
  display: flex;
  flex-direction: column;
  min-height: 0;
  overflow: hidden;
}
.recent h3 { margin: 0 0 1vh; font: 700 3vh var(--display); }
.recent ol { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.9vh; }
.recent li {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: center;
  gap: 0.8vw;
  padding: 0.9vh 0.7vw;
  border-radius: 0.6vh;
  background: var(--tv-chip);
  border-left: 0.6vh solid var(--agency);
}
.recent li.latest { background: var(--tv-latest-bg); }
.r-code { font: 700 3.3vh / 1 var(--display); font-variant-numeric: tabular-nums; }
.recent li.latest .r-code { color: var(--tv-latest-num); }
.r-where { display: flex; flex-direction: column; line-height: 1.25; min-width: 0; }
.r-where b, .r-where i { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.r-where b { font-size: 1.9vh; font-weight: 600; }
.r-where i { font-style: normal; font-size: 1.6vh; color: var(--tv-muted); font-variant-numeric: tabular-nums; }

/* legend */
.legend {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 2vw;
  padding: 1vh 1.6vw;
  background: var(--tv-card);
  border-top: 1px solid var(--tv-line);
  font-size: 1.9vh;
}
.legend ul { list-style: none; margin: 0; padding: 0; display: flex; gap: 2.4vw; }
.legend li { display: flex; align-items: center; gap: 0.6vw; color: var(--tv-muted); }
.legend b {
  display: inline-grid;
  place-items: center;
  width: 3vh;
  height: 3vh;
  border-radius: 0.5vh;
  font: 700 2vh var(--display);
  background: var(--regular);
  color: #fff;
}
.legend .fast b { background: var(--fast); }
.legend .priority b { background: var(--priority); color: var(--ink); }
.legend p { margin: 0; font-weight: 600; }

/* call announcement */
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
  animation: flash-in 0.45s ease-out;
}
.flash-badge { width: 15vh; margin-bottom: 2vh; }
.flash-lead { margin: 0; font-size: 4.4vh; font-weight: 600; opacity: 0.9; }
.flash-code { font: 700 min(42vh, 26vw) / 0.9 var(--display); color: var(--r1-gold); text-shadow: 0 0.6vh 0 rgba(0, 0, 0, 0.18); font-variant-numeric: tabular-nums; }
.flash-where { margin: 2vh 0 0; font: 700 6.4vh var(--display); }
.flash-tl { margin: 0.6vh 0 0; font-size: 3.8vh; opacity: 0.9; }
@keyframes flash-in { from { opacity: 0; transform: scale(1.03); } to { opacity: 1; transform: none; } }

.start {
  position: fixed;
  inset: 0;
  z-index: 20;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1.6vh;
  border: 0;
  background: rgba(14, 24, 56, 0.94);
  color: #fff;
  font: 600 4vh var(--text);
  cursor: pointer;
}
.start small { font-size: 2.2vh; font-weight: 500; color: var(--muted-on-dark); }
.start .hint { color: var(--r1-gold); }
.test-voice {
  position: fixed;
  right: 0.8vw;
  bottom: 6vh;
  z-index: 5;
  border: 0;
  padding: 0.4vh 0.8vw;
  font: 500 1.4vh var(--text);
  background: transparent;
  color: var(--tv-idle);
  cursor: pointer;
}
.test-voice:hover, .test-voice:focus-visible { color: var(--tv-muted); }
.offline {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 30;
  padding: 1vh;
  text-align: center;
  font-size: 2.2vh;
  font-weight: 600;
  background: var(--fast);
}
</style>
