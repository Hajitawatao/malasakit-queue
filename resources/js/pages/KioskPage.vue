<template>
  <main class="kiosk">
    <header class="kiosk-head">
      <h1>MALASAKIT CENTER</h1>
      <p>Pindutin ang serbisyong kailangan ninyo</p>
    </header>

    <section class="tiles">
      <button v-for="s in services" :key="s.id" class="tile" @click="selected = s">
        <img v-if="s.logo" :src="s.logo" :alt="s.name" @error="(e) => (e.target.style.display = 'none')" />
        <span>{{ s.name }}</span>
      </button>
    </section>

    <!-- Pagpili ng lane -->
    <div v-if="selected && !issued" class="overlay" @click.self="selected = null">
      <div class="modal">
        <h2>{{ selected.name }}</h2>
        <div class="lanes">
          <button v-for="l in lanes" :key="l.value" :class="['lane', l.value]" :disabled="busy" @click="issue(l.value)">
            <strong>{{ l.label }}</strong>
            <small>{{ l.hint }}</small>
            <em>Naghihintay: {{ selected.waiting?.[l.value] ?? 0 }}</em>
          </button>
        </div>
        <p v-if="error" class="error">{{ error }}</p>
        <button class="cancel" @click="selected = null">Bumalik</button>
      </div>
    </div>

    <!-- Kumpirmasyon matapos mag-print -->
    <div v-if="issued" class="overlay">
      <div class="modal done">
        <p>Ang inyong numero</p>
        <div class="big">{{ issued.ticket.code }}</div>
        <p>{{ issued.ticket.service.name }} · {{ issued.ticket.lane_label }}</p>
        <p class="small">Kunin po ang inyong ticket sa ibaba.</p>
      </div>
    </div>

    <TicketPrint :ticket="issued?.ticket" :hospital="issued?.hospital" />
  </main>
</template>

<script setup>
import { nextTick, onMounted, onUnmounted, ref } from 'vue'
import { api, ensureCsrf, errorMessage } from '../lib/api'
import TicketPrint from '../components/TicketPrint.vue'

const lanes = [
  { value: 'fast', label: 'Fast Lane', hint: 'Senior Citizen · PWD · Buntis' },
  { value: 'priority', label: 'Priority', hint: 'May referral / endorsement' },
  { value: 'regular', label: 'Regular', hint: 'Lahat ng iba pa' },
]

const services = ref([])
const selected = ref(null)
const issued = ref(null)
const busy = ref(false)
const error = ref('')
let timer

async function load() {
  try {
    services.value = (await api.get('/kiosk/services')).data
    if (selected.value) selected.value = services.value.find((s) => s.id === selected.value.id)
  } catch {}
}

async function issue(lane) {
  busy.value = true
  error.value = ''
  try {
    const { data } = await api.post('/kiosk/tickets', { service_id: selected.value.id, lane })
    issued.value = data
    await nextTick()
    window.print() // silent kapag Chrome --kiosk-printing (tingnan ang README)
    setTimeout(reset, 5000)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

function reset() {
  issued.value = null
  selected.value = null
  load()
}

onMounted(async () => {
  await ensureCsrf()
  load()
  timer = setInterval(load, 10000)
})
onUnmounted(() => clearInterval(timer))
</script>

<style scoped>
.kiosk { min-height: 100vh; background: #eaf2fb; font-family: system-ui, sans-serif; padding: 24px; user-select: none; }
.kiosk-head { text-align: center; color: #0b3d91; margin-bottom: 24px; }
.kiosk-head h1 { font-size: 44px; margin: 0; letter-spacing: 2px; }
.kiosk-head p { font-size: 22px; margin: 6px 0 0; }
.tiles { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; height: calc(100vh - 170px); }
.tile { background: #fff; border: 3px solid #b8cfee; border-radius: 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 16px; font-size: 36px; font-weight: 800; color: #0b3d91; cursor: pointer; }
.tile:active { transform: scale(.98); background: #dbe8f8; }
.tile img { max-height: 140px; max-width: 60%; object-fit: contain; }
.overlay { position: fixed; inset: 0; background: rgba(0,0,0,.5); display: grid; place-items: center; }
.modal { background: #fff; border-radius: 20px; padding: 32px; width: min(900px, 92vw); text-align: center; }
.modal h2 { font-size: 36px; color: #0b3d91; margin: 0 0 20px; }
.lanes { display: grid; gap: 16px; }
.lane { display: grid; gap: 4px; padding: 22px; border-radius: 16px; border: 0; color: #fff; cursor: pointer; }
.lane strong { font-size: 32px; }
.lane small { font-size: 18px; opacity: .9; }
.lane em { font-style: normal; font-size: 16px; opacity: .85; }
.lane.fast { background: #c62828; }
.lane.priority { background: #ef6c00; }
.lane.regular { background: #1565c0; }
.lane:disabled { opacity: .6; }
.cancel { margin-top: 20px; font-size: 20px; padding: 12px 32px; border-radius: 12px; border: 2px solid #999; background: #fff; }
.error { color: #c62828; font-size: 18px; }
.done .big { font-size: 120px; font-weight: 900; color: #0b3d91; line-height: 1; }
.done p { font-size: 24px; margin: 8px 0; }
.done .small { font-size: 18px; color: #555; }
</style>
