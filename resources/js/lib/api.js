import axios from 'axios'

// Kiosk device key: open /kiosk?key=XXXX once and it is remembered in this browser.
const params = new URLSearchParams(location.search)
if (params.get('key')) {
  try { localStorage.setItem('device_key', params.get('key')) } catch {}
}
let deviceKey = ''
try { deviceKey = localStorage.getItem('device_key') || '' } catch {}

/*
 * CSRF: the token is sent in the X-CSRF-TOKEN header, taken from this app's own page
 * (<meta name="csrf-token">). We deliberately do NOT use the XSRF-TOKEN cookie:
 * browsers share cookies between ports, so other Laravel systems on the same PC
 * (localhost:8080, :8150 ...) overwrite it and every POST fails with
 * "CSRF token mismatch".
 */
let csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || ''

export async function refreshCsrf() {
  const { data } = await axios.get('/csrf-token', { withCredentials: true, headers: { Accept: 'application/json' } })
  csrfToken = data.token
  return csrfToken
}

export const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  withXSRFToken: false,
  xsrfCookieName: 'MALASAKIT-UNUSED', // never read another app's XSRF-TOKEN cookie
  headers: { 'X-Device-Key': deviceKey, Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  if (csrfToken) config.headers['X-CSRF-TOKEN'] = csrfToken
  return config
})

// Token expired or changed (e.g. after logout or a long idle): get a fresh one and retry once.
api.interceptors.response.use(
  (res) => {
    if (res.data?.csrf_token) csrfToken = res.data.csrf_token
    return res
  },
  async (error) => {
    const config = error.config
    if (error.response?.status === 419 && config && !config._csrfRetried) {
      config._csrfRetried = true
      await refreshCsrf()
      config.headers['X-CSRF-TOKEN'] = csrfToken
      return api.request(config)
    }
    return Promise.reject(error)
  },
)

/** Kept for the pages that call it: makes sure we hold a valid token for this session. */
export function ensureCsrf() {
  return refreshCsrf().catch(() => csrfToken)
}

export function errorMessage(e) {
  if (e?.response?.status === 419) return 'Your session expired. Refresh the page (F5) and try again.'
  return e?.response?.data?.message || 'Cannot reach the queue server. Try again.'
}
