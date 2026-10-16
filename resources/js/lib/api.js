import axios from 'axios'

// Kiosk device key: buksan minsan ang /kiosk?key=XXXX, ise-save na sa browser.
const params = new URLSearchParams(location.search)
if (params.get('key')) {
  try { localStorage.setItem('device_key', params.get('key')) } catch {}
}
let deviceKey = ''
try { deviceKey = localStorage.getItem('device_key') || '' } catch {}

export const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  withXSRFToken: true,
  headers: { 'X-Device-Key': deviceKey, Accept: 'application/json' },
})

// Sanctum: kailangan ng XSRF cookie bago mag-POST mula sa SPA
export function ensureCsrf() {
  return axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

export function errorMessage(e) {
  return e?.response?.data?.message || 'May problema sa koneksyon. Subukan ulit.'
}
