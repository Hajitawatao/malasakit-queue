/**
 * Voice announcer gamit ang Web Speech API ng browser (libre, offline sa Windows/Chrome).
 *
 * - Naka-pila ang mga announcement: kapag sabay tumawag ang DSWD at PAGCOR,
 *   hindi magsasalpukan ang boses; isa-isa silang babasahin.
 * - Kailangan ng isang click sa TV page bago payagan ng Chrome ang tunog
 *   (autoplay policy), kaya may "I-tap para simulan" overlay ang Display page.
 */

const chime = new Audio('/sounds/chime.wav')
const queue = []
let busy = false
let voice = null

function pickVoice() {
  const voices = speechSynthesis.getVoices()
  // Filipino voice kung meron (bihira sa Windows), kung wala English (PH/US)
  voice =
    voices.find((v) => v.lang === 'fil-PH') ||
    voices.find((v) => v.lang === 'en-PH') ||
    voices.find((v) => v.lang.startsWith('en') && /female|zira|aria|jenny/i.test(v.name)) ||
    voices.find((v) => v.lang.startsWith('en')) ||
    null
}
speechSynthesis.onvoiceschanged = pickVoice
pickVoice()

/** "F102" -> "F, 1, 0, 2" para isa-isang basahin, hindi "F one hundred two" */
export function spellCode(code) {
  return code.split('').join(', ')
}

export function buildAnnouncement(t) {
  const code = spellCode(t.code)
  const where = `${t.service.name}, ${t.counter?.name ?? ''}`
  return [
    `Number ${code}. Please proceed to ${where}.`,
    `Numero ${code}. Pumunta po sa ${where}.`,
  ]
}

function speak(text) {
  return new Promise((resolve) => {
    const u = new SpeechSynthesisUtterance(text)
    if (voice) u.voice = voice
    u.rate = 0.9
    u.onend = resolve
    u.onerror = resolve
    speechSynthesis.speak(u)
  })
}

async function playChime() {
  try {
    chime.currentTime = 0
    await chime.play()
    await new Promise((r) => setTimeout(r, 1200))
  } catch {}
}

async function drain() {
  if (busy) return
  busy = true
  while (queue.length) {
    const lines = queue.shift()
    await playChime()
    for (const line of lines) await speak(line)
    await new Promise((r) => setTimeout(r, 600))
  }
  busy = false
}

export function announce(ticket) {
  queue.push(buildAnnouncement(ticket))
  drain()
}

/** Tawagin sa unang click para ma-unlock ang audio. */
export function unlockAudio() {
  chime.play().then(() => chime.pause()).catch(() => {})
  speechSynthesis.speak(new SpeechSynthesisUtterance(' '))
}
