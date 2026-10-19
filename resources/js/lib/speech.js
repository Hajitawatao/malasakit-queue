/**
 * Call announcer for the TV.
 *
 * 1. Server voice file (preferred): the voice worker renders each call with
 *    ElevenLabs, Kokoro or Piper. The call arrives with an `audio` URL and we
 *    just play it.
 * 2. Browser voice (fallback, when no file is ready): Web Speech API,
 *    restricted to FEMALE voices, one straight English line.
 *
 * Announcements are queued, so two windows calling at once never overlap.
 * The browser blocks sound until someone taps the page once (or the browser
 * is started with --autoplay-policy=no-user-gesture-required).
 */

const chime = new Audio('/sounds/chime.wav')
const player = new Audio()
const queue = []
let busy = false
let browserVoice = null

// Female voices across Edge (Natural), Windows (SAPI), Chrome and macOS
const FEMALE = /(rosa|jenny|aria|michelle|ana\b|sonia|libby|natasha|clara|emma|ava|sara|zira|hazel|susan|linda|heera|catherine|female|samantha|karen|moira|tessa|serena|victoria|fiona|google uk english female|google us english)/i
const MALE = /(david|mark|guy|james|ryan|brian|christopher|eric|roger|steffan|george|richard|william|thomas|daniel|alex|fred|male\b)/i
const isNatural = (v) => /natural|neural|online/i.test(v.name)

function pickBrowserVoice() {
  const english = speechSynthesis.getVoices().filter((v) => v.lang.toLowerCase().startsWith('en'))
  const female = english.filter((v) => FEMALE.test(v.name) && !MALE.test(v.name))
  const rank = (v) =>
    (isNatural(v) ? 4 : 0) + (v.lang.toLowerCase() === 'en-ph' ? 2 : 0) + (v.lang.toLowerCase() === 'en-us' ? 1 : 0)
  browserVoice = female.sort((a, b) => rank(b) - rank(a))[0] || english.find((v) => !MALE.test(v.name)) || null
}
speechSynthesis.onvoiceschanged = pickBrowserVoice
pickBrowserVoice()

export function browserVoiceName() {
  pickBrowserVoice()
  return browserVoice?.name ?? null
}

/** "F012" -> "F 12" so it is read straight: "F twelve". */
export function speakableCode(code) {
  const m = /^([A-Z])0*(\d+)$/.exec(code ?? '')
  return m ? `${m[1]} ${m[2]}` : code
}

function fallbackText(t) {
  const where = [t.service?.name, t.counter?.name].filter(Boolean).join(', ')
  return `Now calling number ${speakableCode(t.code)}. Please proceed to ${where}.`
}

function speakBrowser(text) {
  return new Promise((resolve) => {
    const u = new SpeechSynthesisUtterance(text)
    if (browserVoice) {
      u.voice = browserVoice
      u.lang = browserVoice.lang
    } else {
      u.lang = 'en-US'
    }
    u.rate = 0.92
    u.pitch = 1
    u.onend = resolve
    u.onerror = resolve
    setTimeout(resolve, 15000) // some browsers never fire onend
    speechSynthesis.speak(u)
  })
}

function playFile(src) {
  return new Promise((resolve, reject) => {
    player.src = src
    player.onended = () => resolve()
    player.onerror = () => reject(new Error('audio failed'))
    player.play().catch(reject)
    setTimeout(resolve, 20000)
  })
}

async function playChime() {
  try {
    chime.currentTime = 0
    await chime.play()
    await new Promise((r) => setTimeout(r, 1100))
  } catch {}
}

async function say(t, onStart) {
  try { onStart?.() } catch {}
  await playChime()
  if (t.audio) {
    try {
      await playFile(t.audio)
      return
    } catch {
      // fall through to the browser voice
    }
  }
  pickBrowserVoice()
  await speakBrowser(t.speech || fallbackText(t))
}

async function drain() {
  if (busy) return
  busy = true
  while (queue.length) {
    const { call, onStart } = queue.shift()
    await say(call, onStart)
    await new Promise((r) => setTimeout(r, 700))
  }
  busy = false
}

/** Queue a call. onStart runs when this call's chime starts (used to show the flash). */
export function announce(call, onStart) {
  queue.push({ call, onStart })
  drain()
}

/** Call on the first tap so the browser allows sound. */
export function unlockAudio() {
  chime.play().then(() => chime.pause()).catch(() => {})
  player.play().catch(() => {})
  speechSynthesis.speak(new SpeechSynthesisUtterance(' '))
}
