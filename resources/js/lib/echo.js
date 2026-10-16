import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

window.Pusher = Pusher

// Laravel Reverb (self-hosted websocket, gumagana sa LAN kahit walang internet).
// Ang host ay kinukuha sa address bar, kaya kahit magpalit ng IP ang server,
// hindi na kailangang i-rebuild ang frontend.
export const echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY,
  wsHost: window.location.hostname,
  wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 6001),
  wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 6001),
  forceTLS: false,
  enabledTransports: ['ws', 'wss'],
})
