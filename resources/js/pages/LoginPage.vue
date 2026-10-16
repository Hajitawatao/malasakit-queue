<template>
  <main class="login">
    <form @submit.prevent="submit">
      <h1>Counter Login</h1>
      <input v-model="username" placeholder="Username (hal. dswd1)" autocomplete="username" required />
      <input v-model="password" type="password" placeholder="Password" autocomplete="current-password" required />
      <button :disabled="busy">Mag-login</button>
      <p v-if="error" class="error">{{ error }}</p>
    </form>
  </main>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, ensureCsrf, errorMessage } from '../lib/api'

const router = useRouter()
const username = ref('')
const password = ref('')
const busy = ref(false)
const error = ref('')

async function submit() {
  busy.value = true
  error.value = ''
  try {
    await ensureCsrf()
    await api.post('/login', { username: username.value, password: password.value })
    router.push('/counter')
  } catch (e) {
    error.value = e?.response?.data?.errors?.username?.[0] || errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.login { min-height: 100vh; display: grid; place-items: center; background: #eaf2fb; font-family: system-ui, sans-serif; }
form { background: #fff; padding: 32px; border-radius: 16px; width: 340px; display: grid; gap: 12px; box-shadow: 0 6px 24px rgba(0,0,0,.08); }
h1 { margin: 0 0 8px; color: #0b3d91; font-size: 24px; }
input { padding: 12px; font-size: 16px; border: 1px solid #c5d3e6; border-radius: 8px; }
button { padding: 12px; font-size: 16px; font-weight: 700; background: #0b3d91; color: #fff; border: 0; border-radius: 8px; }
.error { color: #c62828; margin: 0; }
</style>
