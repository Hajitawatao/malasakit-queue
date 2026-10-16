/**
 * Voice announcer using the browser's Web Speech API.
 *
 * Best quality: open the TV page in MICROSOFT EDGE. Edge ships Microsoft's free
 * neural "Natural" voices (needs internet), including:
 *   - Filipino:            Microsoft Blessica / Angelo Online (Natural)
 *   - English Philippines: Microsoft Rosa / James Online (Natural)
 * Chrome on Windows only has the old robotic voices and no Filipino voice.
 *
 * - Announcements are queued, so two windows calling at once never overlap.
 * - The Tagalog line is only spoken when a real Filipino voice exists;
 *   an English voice reading Tagalog sounds wrong.
 * - The browser blocks sound until someone taps the page once
 *   (or Edge/Chrome is started with --autoplay-policy=no-user-gesture-required).
 */

const chime = new Audio('/sounds/chime.wav')
const queue = []
let busy = false
let englishVoice = null
let filipinoVoice = null

const isNatural = (v) => /natural|neural|online/i.test(v.name)

function pick(voices, langs) {
  for (const lang of langs) {
    const matches = voices.filter((v) => v.lang.toLowerCase().replace('_', '-').startsWith(lang))
    const natural = matches.find(isNatural)
    if (natural) return natural
    if (matches.length) return matches[0]
  }
  return null
}

function pickVoices() {
  const voices = speechSynthesis.getVoices()
  // Prefer Philippine English, then US/other English; female voices read clearer in a hall
  englishVoice =
    pick(voices.filter((v) => /rosa|jenny|aria|female/i.test(v.name)), ['en-ph', 'en-us', 'en']) ||
    pick(voices, ['en-ph', 'en-us', 'en-gb', 'en'])
  filipinoVoice = pick(voices, ['fil', 'tl'])
}
speechSynthesis.onvoiceschanged = pickVoices
pickVoices()

/** Which voices are in use (shown on the TV start screen). */
export function voiceInfo() {
  pickVoices()
  return {
    english: englishVoice?.name ?? null,
    filipino: filipinoVoice?.name ?? null,
    natural: Boolean(englishVoice && isNatural(englishVoice)),
  }
}

/** "F102" -> "F, 1, 0, 2" so it is read one character at a time. */
export function spellCode(code) {
  return code.split('').join(', ')
}

export function buildAnnouncement(t) {
  const code = spellCode(t.code)
  const where = [t.service?.name, t.counter?.name].filter(Boolean).join(', ')
  const lines = [{ text: `Now calling, number ${code}. Please proceed to ${where}.`, voice: englishVoice, lang: 'en-PH' }]
  if (filipinoVoice) {
    lines.push({ text: `Numero ${code}. Pumunta po sa ${where}.`, voice: filipinoVoice, lang: 'fil-PH' })
  }
  return lines
}

function speak({ text, voice, lang }) {
  return new Promise((resolve) => {
    const u = new SpeechSynthesisUtterance(text)
    if (voice) u.voice = voice
    u.lang = voice?.lang ?? lang
    u.rate = voice && isNatural(voice) ? 0.95 : 0.85
    u.onend = resolve
    u.onerror = resolve
    // Safety net: some browsers never fire onend for online voices
    setTimeout(resolve, 15000)
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
    await new Promise((r) => setTimeout(r, 700))
  }
  busy = false
}

export function announce(ticket) {
  pickVoices()
  queue.push(buildAnnouncement(ticket))
  drain()
}

/** Call on the first tap so the browser allows sound. */
export function unlockAudio() {
  chime.play().then(() => chime.pause()).catch(() => {})
  speechSynthesis.speak(new SpeechSynthesisUtterance(' '))
}

/** Plays a sample call so staff can hear the voice before the day starts. */
export function testVoice() {
  announce({ code: 'R001', service: { name: 'DSWD' }, counter: { name: 'Window 1' } })
}
