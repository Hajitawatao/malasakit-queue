<template>
  <main class="login">
    <section class="brand">
      <img class="seal" :src="'/logos/r1mc.png'" alt="" />
      <p class="hospital">Region 1 Medical Center</p>
      <h1>Malasakit Center<br />Queue</h1>
      <ul class="agencies">
        <li v-for="(a, code) in AGENCY" :key="code" :style="{ '--agency': a.color }">
          <AgencyBadge class="agency" :code="code" />
        </li>
      </ul>
    </section>

    <section class="panel">
      <form @submit.prevent="submit">
        <h2>Window login</h2>
        <p class="sub">Sign in with your window's account.</p>

        <label>
          Username
          <input v-model="username" autocomplete="username" placeholder="e.g. dswd1" required autofocus />
        </label>
        <label>
          Password
          <input v-model="password" type="password" autocomplete="current-password" required />
        </label>

        <button :disabled="busy">{{ busy ? 'Signing in…' : 'Sign in' }}</button>
        <p v-if="error" class="error" role="alert">{{ error }}</p>
      </form>

      <nav class="other">
        <a href="/display">TV display</a>
      </nav>
    </section>
  </main>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, ensureCsrf, errorMessage } from '../lib/api'
import { AGENCY } from '../lib/agency'
import AgencyBadge from '../components/AgencyBadge.vue'

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
    await api.post('/login', { username: username.value.trim(), password: password.value })
    router.push('/counter')
  } catch (e) {
    error.value = e?.response?.status === 422 ? 'Wrong username or password.' : errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.login {
  min-height: 100vh;
  display: grid;
  grid-template-columns: minmax(320px, 5fr) 6fr;
  background: var(--paper);
}
@media (max-width: 820px) { .login { grid-template-columns: 1fr; } .brand { padding: 32px; } }

.brand {
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding: 56px;
  background: var(--r1-navy);
  color: #fff;
  border-right: 8px solid var(--r1-gold);
}
.seal { width: 120px; height: 120px; border-radius: 50%; background: #fff; padding: 3px; }
.hospital { margin: 28px 0 0; font-size: 20px; color: var(--muted-on-dark); }
h1 { margin: 4px 0 0; font: 700 64px/0.95 var(--display); }
.agencies { list-style: none; margin: 40px 0 0; padding: 0; display: flex; gap: 14px; }
.agency { width: 64px; box-shadow: 0 0 0 3px var(--agency); }

.panel { display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 40px 24px; }
form { width: min(400px, 100%); display: grid; gap: 18px; }
h2 { margin: 0; font: 700 40px var(--display); }
.sub { margin: -10px 0 6px; color: var(--muted); font-size: 17px; }
label { display: grid; gap: 6px; font-weight: 600; font-size: 15px; }
input {
  padding: 14px 16px;
  font: 500 18px var(--text);
  border: 2px solid var(--line);
  border-radius: 10px;
  background: var(--card);
  color: var(--ink);
}
input:focus { border-color: var(--r1-navy); outline: none; }
button {
  margin-top: 6px;
  padding: 15px;
  font: 700 22px var(--display);
  border: 0;
  border-radius: 10px;
  background: var(--r1-navy);
  color: #fff;
  cursor: pointer;
}
button:disabled { opacity: 0.6; }
.error { margin: 0; color: var(--fast); font-weight: 600; }
.other { margin-top: 28px; font-size: 15px; }
.other a { color: var(--muted); }
</style>
